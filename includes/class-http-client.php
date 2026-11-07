<?php
/**
 * Fetches scanned URLs safely: every hop is validated and pinned to the checked IP.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Guarded HTTP client with a per-scan budget.
 */
final class Http_Client {

	const MAX_REDIRECTS     = 5;
	const DEFAULT_MAX_BYTES = 2097152;
	const DEFAULT_TIMEOUT   = 10;

	/**
	 * Requests used in this scan.
	 *
	 * @var int
	 */
	public int $requests = 0;

	/**
	 * Bytes kept in this scan.
	 *
	 * @var int
	 */
	public int $bytes = 0;

	/**
	 * Request budget for the whole scan.
	 *
	 * @var int
	 */
	private int $max_requests;

	/**
	 * Constructor.
	 *
	 * @param int $max_requests Request budget.
	 * @param int $requests     Requests already used.
	 * @param int $bytes        Bytes already used.
	 */
	public function __construct( int $max_requests = 150, int $requests = 0, int $bytes = 0 ) {
		$this->max_requests = $max_requests;
		$this->requests     = $requests;
		$this->bytes        = $bytes;
	}

	/**
	 * Identifies the scanner to the sites it visits.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return 'SiteAuditReport/' . SAR_VERSION . ' (website audit; +' . home_url( '/' ) . ')';
	}

	/**
	 * Requests a URL and follows redirects by hand so each hop is checked.
	 *
	 * @param string              $url  URL.
	 * @param array<string,mixed> $args method, timeout, max_bytes, follow, sslverify.
	 * @return array<string,mixed> ok, code, headers, body, url, chain, error, truncated.
	 */
	public function fetch( $url, array $args = array() ) {
		$args = array_merge(
			array(
				'method'    => 'GET',
				'timeout'   => self::DEFAULT_TIMEOUT,
				'max_bytes' => self::DEFAULT_MAX_BYTES,
				'follow'    => true,
				'sslverify' => true,
			),
			$args
		);

		$chain   = array();
		$current = $url;

		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$checked = Url_Guard::validate( $current );
			if ( is_wp_error( $checked ) ) {
				return self::failure( $checked->get_error_code(), $current, $chain );
			}
			if ( $this->requests >= $this->max_requests ) {
				return self::failure( 'sar_budget', $current, $chain );
			}

			$chain[] = $checked['url'];
			++$this->requests;

			$response = $this->request_pinned( $checked, $args );
			if ( is_wp_error( $response ) ) {
				$error = self::classify_error( $response );
				return self::failure( $error, $current, $chain );
			}

			$code    = (int) wp_remote_retrieve_response_code( $response );
			$headers = self::headers( $response );
			$body    = (string) wp_remote_retrieve_body( $response );

			$this->bytes += strlen( $body );

			$location = $headers['location'] ?? '';
			if ( $args['follow'] && $code >= 300 && $code < 400 && '' !== $location ) {
				$current = \WP_Http::make_absolute_url( $location, $checked['url'] );
				continue;
			}

			return array(
				'ok'        => $code >= 200 && $code < 300,
				'code'      => $code,
				'headers'   => $headers,
				'body'      => $body,
				'url'       => $checked['url'],
				'chain'     => $chain,
				'error'     => '',
				'truncated' => strlen( $body ) >= (int) $args['max_bytes'],
			);
		}

		return self::failure( 'sar_too_many_redirects', $current, $chain );
	}

	/**
	 * Whether a response looks like a firewall or bot wall, not the site's real answer.
	 *
	 * @param array<string,mixed> $response Fetch result.
	 * @return bool
	 */
	public static function looks_blocked( array $response ) {
		$code = (int) ( $response['code'] ?? 0 );
		if ( in_array( $code, array( 401, 403, 406, 429, 451 ), true ) ) {
			return true;
		}
		$headers = $response['headers'] ?? array();
		if ( ! empty( $headers['cf-mitigated'] ) || ! empty( $headers['x-amzn-waf-action'] ) ) {
			return true;
		}
		return 503 === $code && false !== stripos( (string) ( $response['body'] ?? '' ), 'cf-browser-verification' );
	}

	/**
	 * Performs one request connected only to the checked IP.
	 *
	 * @param array<string,mixed> $checked Result from Url_Guard::validate().
	 * @param array<string,mixed> $args    Options.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function request_pinned( array $checked, array $args ) {
		$pin = static function ( &$handle ) use ( $checked ) {
			$ip = false !== strpos( $checked['ip'], ':' ) ? '[' . $checked['ip'] . ']' : $checked['ip'];
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Pinning needs cURL options the HTTP API does not expose.
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $checked['host'] . ':' . $checked['port'] . ':' . $ip ) );
			curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
			curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
			curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 5 );
			// phpcs:enable
		};

		add_action( 'http_api_curl', $pin, 99 );

		$response = wp_safe_remote_request(
			$checked['url'],
			array(
				'method'              => $args['method'],
				'timeout'             => $args['timeout'],
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'sslverify'           => $args['sslverify'],
				'user-agent'          => self::user_agent(),
				'limit_response_size' => $args['max_bytes'],
				'decompress'          => false,
				'headers'             => array(
					'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Encoding' => 'identity',
				),
			)
		);

		remove_action( 'http_api_curl', $pin, 99 );

		return $response;
	}

	/**
	 * Flattens headers to lower-case names and single string values.
	 *
	 * @param array<string,mixed> $response WordPress response.
	 * @return array<string,string>
	 */
	private static function headers( array $response ) {
		$raw = wp_remote_retrieve_headers( $response );
		$raw = is_object( $raw ) && method_exists( $raw, 'getAll' ) ? $raw->getAll() : (array) $raw;

		$headers = array();
		foreach ( $raw as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}
		return $headers;
	}

	/**
	 * Maps a transport error to a stable code. Raw messages stay out of reports.
	 *
	 * @param \WP_Error $error Error.
	 * @return string
	 */
	private static function classify_error( \WP_Error $error ) {
		$message = strtolower( $error->get_error_message() );
		if ( false !== strpos( $message, 'ssl' ) || false !== strpos( $message, 'certificate' ) ) {
			return 'sar_tls';
		}
		if ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' ) ) {
			return 'sar_timeout';
		}
		if ( false !== strpos( $message, 'resolve' ) ) {
			return 'sar_dns_failed';
		}
		if ( 'http_request_not_executed' === $error->get_error_code() ) {
			return 'sar_url_blocked';
		}
		return 'sar_connect_failed';
	}

	/**
	 * Builds a failed fetch result.
	 *
	 * @param string   $code  Error code.
	 * @param string   $url   URL.
	 * @param string[] $chain Visited URLs.
	 * @return array<string,mixed>
	 */
	private static function failure( $code, $url, array $chain ) {
		return array(
			'ok'        => false,
			'code'      => 0,
			'headers'   => array(),
			'body'      => '',
			'url'       => $url,
			'chain'     => $chain,
			'error'     => $code,
			'truncated' => false,
		);
	}
}
