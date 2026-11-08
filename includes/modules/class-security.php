<?php
/**
 * HTTPS, security headers and privacy pages. Passive: reads public responses only.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report\Modules;

use Site_Audit_Report\Check;
use Site_Audit_Report\Crawler;
use Site_Audit_Report\Http_Client;
use Site_Audit_Report\Module;
use Site_Audit_Report\Scan_Context;
use Site_Audit_Report\Url_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Security and privacy checks.
 */
final class Security implements Module {

	const CATEGORY = 'security';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'security';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Checking HTTPS, security headers and privacy pages', 'site-audit-report' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Scan_Context $context Context.
	 */
	public function run( Scan_Context $context ) {
		$checks   = array();
		$checks[] = $this->https( $context );
		$checks[] = $this->http_redirect( $context );
		$checks[] = $this->certificate( $context );

		if ( ! $context->readable() ) {
			return array_merge(
				$checks,
				Check::all_not_checked(
					self::CATEGORY,
					array(
						'mixed_content' => array( __( 'Mixed content', 'site-audit-report' ), 3 ),
						'hsts'          => array( __( 'HSTS header', 'site-audit-report' ), 2 ),
						'csp'           => array( __( 'Content-Security-Policy header', 'site-audit-report' ), 1 ),
						'privacy_page'  => array( __( 'Privacy policy page', 'site-audit-report' ), 3 ),
						'cookie_page'   => array( __( 'Cookie information', 'site-audit-report' ), 2 ),
					),
					$context->unreadable_reason()
				)
			);
		}

		$checks[] = $this->mixed_content( $context );
		$checks   = array_merge( $checks, $this->headers( $context ) );
		$checks[] = $this->privacy_page( $context );
		$checks[] = $this->cookie_page( $context );

		return $checks;
	}

	/**
	 * HTTPS.
	 *
	 * @param Scan_Context $context Context.
	 * @return array<string,mixed>
	 */
	private function https( Scan_Context $context ) {
		$label = __( 'HTTPS', 'site-audit-report' );
		$tls   = (array) $context->data['tls'];
		if ( null === $tls['https_works'] ) {
			return Check::not_checked( self::CATEGORY, 'https', $label, 5, $context->unreadable_reason() );
		}
		if ( ! empty( $tls['cert_problem'] ) ) {
			return Check::make(
				Check::FAIL,
				self::CATEGORY,
				'https',
				$label,
				5,
				__( 'The site\'s security certificate failed verification. Visitors will probably see a browser warning.', 'site-audit-report' ),
				array(
					'fix'    => __( 'Renew or reinstall the certificate and make sure it covers this exact domain. Most hosts provide one free.', 'site-audit-report' ),
					'effort' => 'low',
				)
			);
		}
		if ( ! $tls['https_works'] ) {
			return Check::make(
				Check::FAIL,
				self::CATEGORY,
				'https',
				$label,
				5,
				__( 'The site is not served securely over HTTPS.', 'site-audit-report' ),
				array(
					'fix'    => __( 'Install a certificate and serve every page over HTTPS. Browsers label plain HTTP pages "Not secure".', 'site-audit-report' ),
					'effort' => 'low',
				)
			);
		}
		return Check::make( Check::PASS, self::CATEGORY, 'https', $label, 5, __( 'The site loads over HTTPS with a valid certificate.', 'site-audit-report' ) );
	}

	/**
	 * Whether plain HTTP redirects to HTTPS.
	 *
	 * @param Scan_Context $context Context.
	 * @return array<string,mixed>
	 */
	private function http_redirect( Scan_Context $context ) {
		$label = __( 'HTTP redirects to HTTPS', 'site-audit-report' );
		if ( empty( $context->data['tls']['https_works'] ) ) {
			return Check::not_checked( self::CATEGORY, 'http_redirect', $label, 4, __( 'Not applicable until HTTPS works.', 'site-audit-report' ) );
		}
		$origin   = Crawler::origin( (string) $context->data['final_url'] );
		$response = $context->http->fetch(
			(string) preg_replace( '#^https://#', 'http://', $origin ) . '/',
			array(
				'timeout'   => 8,
				'max_bytes' => 16384,
			)
		);
		// A bot wall or error page says nothing about whether the site redirects real visitors.
		if ( $response['error'] || Http_Client::looks_blocked( $response ) || (int) $response['code'] >= 400 ) {
			return Check::not_checked( self::CATEGORY, 'http_redirect', $label, 4, __( 'The plain HTTP address did not give a clear answer.', 'site-audit-report' ) );
		}
		if ( 0 === strpos( (string) $response['url'], 'https://' ) ) {
			return Check::make( Check::PASS, self::CATEGORY, 'http_redirect', $label, 4, __( 'Plain HTTP requests are sent to HTTPS.', 'site-audit-report' ) );
		}
		return Check::make(
			Check::FAIL,
			self::CATEGORY,
			'http_redirect',
			$label,
			4,
			__( 'The site still answers on plain HTTP without redirecting to HTTPS.', 'site-audit-report' ),
			array(
				'fix'    => __( 'Add a permanent (301) redirect from HTTP to HTTPS.', 'site-audit-report' ),
				'effort' => 'low',
			)
		);
	}

	/**
	 * Certificate expiry.
	 *
	 * @param Scan_Context $context Context.
	 * @return array<string,mixed>
	 */
	private function certificate( Scan_Context $context ) {
		$label = __( 'Certificate expiry', 'site-audit-report' );
		if ( empty( $context->data['tls']['https_works'] ) ) {
			return Check::not_checked( self::CATEGORY, 'cert_expiry', $label, 2, __( 'Not applicable until HTTPS works.', 'site-audit-report' ) );
		}
		$expires = $this->read_certificate_expiry( (string) $context->data['final_url'] );
		if ( ! $expires ) {
			return Check::not_checked( self::CATEGORY, 'cert_expiry', $label, 2, __( 'The certificate details could not be read from this server.', 'site-audit-report' ) );
		}
		$days = (int) floor( ( $expires - time() ) / DAY_IN_SECONDS );
		/* translators: 1: expiry date, 2: days left. */
		$evidence = sprintf( __( 'Expires on %1$s (%2$d days from the scan).', 'site-audit-report' ), gmdate( 'Y-m-d', $expires ), $days );
		if ( $days >= 14 ) {
			return Check::make( Check::PASS, self::CATEGORY, 'cert_expiry', $label, 2, $evidence );
		}
		return Check::make(
			$days < 0 ? Check::FAIL : Check::WARN,
			self::CATEGORY,
			'cert_expiry',
			$label,
			2,
			$evidence,
			array(
				'fix'    => __( 'Renew the certificate and turn on automatic renewal.', 'site-audit-report' ),
				'effort' => 'low',
			)
		);
	}

	/**
	 * Reads the certificate's expiry, connecting only to an address that passed the guard.
	 *
	 * @param string $url Site URL.
	 * @return int Timestamp, or 0.
	 */
	public function read_certificate_expiry( $url ) {
		if ( ! function_exists( 'openssl_x509_parse' ) || ! function_exists( 'stream_socket_client' ) ) {
			return 0;
		}
		$checked = Url_Guard::validate( $url );
		if ( is_wp_error( $checked ) ) {
			return 0;
		}
		$context = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'peer_name'         => $checked['host'],
					'SNI_enabled'       => true,
					'verify_peer'       => false,
					'verify_peer_name'  => false,
				),
			)
		);
		$address = false !== strpos( $checked['ip'], ':' ) ? '[' . $checked['ip'] . ']' : $checked['ip'];
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed connection is handled below.
		$socket = @stream_socket_client( 'ssl://' . $address . ':443', $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context );
		if ( ! $socket ) {
			return 0;
		}
		$params = stream_context_get_params( $socket );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Network socket, not a file.
		fclose( $socket );
		if ( empty( $params['options']['ssl']['peer_certificate'] ) ) {
			return 0;
		}
		$cert = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] );
		return (int) ( $cert['validTo_time_t'] ?? 0 );
	}

	/**
	 * Insecure resources on secure pages.
	 *
	 * @param Scan_Context $context Context.
	 * @return array<string,mixed>
	 */
	private function mixed_content( Scan_Context $context ) {
		$label = __( 'Mixed content', 'site-audit-report' );
		if ( empty( $context->data['tls']['https_works'] ) ) {
			return Check::not_checked( self::CATEGORY, 'mixed_content', $label, 3, __( 'Not applicable until HTTPS works.', 'site-audit-report' ) );
		}
		$found = array();
		foreach ( $context->pages() as $page ) {
			$found = array_merge( $found, $page['mixed_content'] );
		}
		$found = array_values( array_unique( $found ) );
		if ( ! $found ) {
			return Check::make( Check::PASS, self::CATEGORY, 'mixed_content', $label, 3, __( 'No insecure resources in the HTML of the pages checked.', 'site-audit-report' ) );
		}
		$items = array();
		foreach ( array_slice( $found, 0, 10 ) as $url ) {
			$items[] = array(
				'text' => $url,
				'url'  => $url,
			);
		}
		return Check::make(
			Check::FAIL,
			self::CATEGORY,
			'mixed_content',
			$label,
			3,
			/* translators: %d: number of resources. */
			sprintf( _n( '%d resource loads over insecure HTTP on a secure page.', '%d resources load over insecure HTTP on secure pages.', count( $found ), 'site-audit-report' ), count( $found ) ),
			array(
				'fix'    => __( 'Change these addresses to HTTPS. Browsers may block them or show a warning.', 'site-audit-report' ),
				'effort' => 'low',
				'items'  => $items,
			)
		);
	}

	/**
	 * Security headers.
	 *
	 * @param Scan_Context $context Context.
	 * @return array<int,array<string,mixed>>
	 */
	private function headers( Scan_Context $context ) {
		$headers = (array) $context->data['home_headers'];
		$csp     = (string) ( $headers['content-security-policy'] ?? '' );
		$list    = array(
			'hsts'               => array( __( 'HSTS header', 'site-audit-report' ), 2, ! empty( $headers['strict-transport-security'] ), __( 'Add a Strict-Transport-Security header once the whole site works over HTTPS.', 'site-audit-report' ) ),
			'csp'                => array( __( 'Content-Security-Policy header', 'site-audit-report' ), 1, '' !== $csp, __( 'Add a Content-Security-Policy, starting in report-only mode, to limit damage from injected scripts.', 'site-audit-report' ) ),
			'x_content_type'     => array( __( 'X-Content-Type-Options header', 'site-audit-report' ), 1, ! empty( $headers['x-content-type-options'] ), __( 'Send "X-Content-Type-Options: nosniff".', 'site-audit-report' ) ),
			'clickjacking'       => array( __( 'Clickjacking protection', 'site-audit-report' ), 1, ! empty( $headers['x-frame-options'] ) || false !== stripos( $csp, 'frame-ancestors' ), __( 'Send X-Frame-Options, or a frame-ancestors rule in your Content-Security-Policy.', 'site-audit-report' ) ),
			'referrer_policy'    => array( __( 'Referrer-Policy header', 'site-audit-report' ), 1, ! empty( $headers['referrer-policy'] ), __( 'Send "Referrer-Policy: strict-origin-when-cross-origin".', 'site-audit-report' ) ),
			'permissions_policy' => array( __( 'Permissions-Policy header', 'site-audit-report' ), 1, ! empty( $headers['permissions-policy'] ), __( 'Send a Permissions-Policy that turns off browser features the site does not use.', 'site-audit-report' ) ),
		);

		$checks = array();
		foreach ( $list as $id => $meta ) {
			if ( $meta[2] ) {
				$checks[] = Check::make( Check::PASS, self::CATEGORY, $id, $meta[0], $meta[1], __( 'Present on the home page.', 'site-audit-report' ) );
			} else {
				$checks[] = Check::make(
					Check::WARN,
					self::CATEGORY,
					$id,
					$meta[0],
					$meta[1],
					__( 'Not set on the home page.', 'site-audit-report' ),
					array(
						'fix'    => $meta[3],
						'effort' => 'low',
					)
				);
			}
		}
		return $checks;
	}

	/**
	 * Privacy policy.
	 *
	 * @param Scan_Context $context Context.
	 * @return array<string,mixed>
	 */
	private function privacy_page( Scan_Context $context ) {
		$label = __( 'Privacy policy page', 'site-audit-report' );
		$link  = $this->find_link( $context, '/privacy|datenschutz|confidentialit|privacidad/i' );
		if ( $link ) {
			return Check::make(
				Check::PASS,
				self::CATEGORY,
				'privacy_page',
				$label,
				3,
				__( 'A privacy policy is linked from the pages checked.', 'site-audit-report' ),
				array(
					'items' => array(
						array(
							'text' => $link,
							'url'  => $link,
						),
					),
				)
			);
		}
		return Check::make(
			Check::FAIL,
			self::CATEGORY,
			'privacy_page',
			$label,
			3,
			__( 'No link to a privacy policy was found on the pages checked.', 'site-audit-report' ),
			array(
				'fix'    => __( 'Publish a privacy policy and link to it from every page footer, especially if you collect details through forms.', 'site-audit-report' ),
				'effort' => 'medium',
			)
		);
	}

	/**
	 * Cookie policy or consent tool.
	 *
	 * @param Scan_Context $context Context.
	 * @return array<string,mixed>
	 */
	private function cookie_page( Scan_Context $context ) {
		$label = __( 'Cookie information', 'site-audit-report' );
		$link  = $this->find_link( $context, '/cookie/i' );
		$tools = array();
		foreach ( $context->pages() as $page ) {
			$tools = array_merge( $tools, $page['consent_tools'] );
		}
		$tools = array_values( array_unique( $tools ) );

		if ( $link || $tools ) {
			$parts = array();
			if ( $link ) {
				$parts[] = __( 'A cookie page is linked.', 'site-audit-report' );
			}
			if ( $tools ) {
				/* translators: %s: tool names. */
				$parts[] = sprintf( __( 'Consent tool detected: %s.', 'site-audit-report' ), implode( ', ', $tools ) );
			}
			return Check::make(
				Check::PASS,
				self::CATEGORY,
				'cookie_page',
				$label,
				2,
				implode( ' ', $parts ),
				array(
					'items' => $link ? array(
						array(
							'text' => $link,
							'url'  => $link,
						),
					) : array(),
				)
			);
		}
		return Check::make(
			Check::WARN,
			self::CATEGORY,
			'cookie_page',
			$label,
			2,
			__( 'No cookie page or known consent tool was found on the pages checked.', 'site-audit-report' ),
			array(
				'fix'    => __( 'If the site uses analytics or advertising cookies, explain them on a cookie page and add a consent banner where the law requires one.', 'site-audit-report' ),
				'effort' => 'medium',
			)
		);
	}

	/**
	 * Finds an internal or external link whose address or text matches.
	 *
	 * @param Scan_Context $context Context.
	 * @param string       $pattern Pattern.
	 * @return string URL, or empty.
	 */
	private function find_link( Scan_Context $context, $pattern ) {
		foreach ( $context->pages() as $page ) {
			foreach ( array_merge( $page['internal_links'], $page['external_links'] ) as $link ) {
				if ( preg_match( $pattern, (string) wp_parse_url( $link['url'], PHP_URL_PATH ) . ' ' . $link['text'] ) ) {
					return $link['url'];
				}
			}
		}
		return '';
	}
}
