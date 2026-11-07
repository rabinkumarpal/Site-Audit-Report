<?php
/**
 * Decides whether a URL may be requested (SSRF protection).
 *
 * There is deliberately no filter or setting that loosens these rules.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * URL and IP validation.
 */
final class Url_Guard {

	const MAX_URL_LENGTH = 2048;

	/**
	 * IPv4 ranges that never hold a public website.
	 */
	const BLOCKED_V4 = array(
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.31.196.0/24',
		'192.52.193.0/24',
		'192.88.99.0/24',
		'192.168.0.0/16',
		'192.175.48.0/24',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
	);

	/**
	 * Global-unicast IPv6 ranges that still must not be reached: documentation,
	 * and the tunnelling ranges (Teredo, 6to4) that can wrap a private IPv4.
	 */
	const BLOCKED_V6 = array(
		'2001::/32',
		'2001:db8::/32',
		'2002::/16',
	);

	/**
	 * Suffixes reserved for private or special-use names.
	 */
	const BLOCKED_SUFFIXES = array( 'localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan', 'home', 'corp', 'test', 'invalid', 'example', 'onion', 'arpa', 'home.arpa' );

	/**
	 * Turns what a visitor typed into an absolute URL, without any network use.
	 *
	 * @param string $raw Input.
	 * @return string|WP_Error
	 */
	public static function normalize_input( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return new WP_Error( 'sar_url_empty', __( 'Please enter a website address.', 'site-audit-report' ) );
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $raw ) ) {
			$raw = 'https://' . $raw;
		}
		$checked = self::check_syntax( $raw );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		return $checked['url'];
	}

	/**
	 * Full check of a URL, including DNS. Every address the host resolves to
	 * must be public. Returns the address to connect to, so the request can be
	 * pinned to it and a later DNS change (rebinding) cannot redirect it.
	 *
	 * @param string $url URL.
	 * @return array{url:string,scheme:string,host:string,port:int,ip:string}|WP_Error
	 */
	public static function validate( $url ) {
		$parts = self::check_syntax( $url );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}

		$host = $parts['host'];
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips = array( $host );
		} else {
			$ips = self::resolve( $host );
			if ( ! $ips ) {
				return new WP_Error( 'sar_dns_failed', __( 'That website address could not be found.', 'site-audit-report' ) );
			}
		}

		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return self::blocked();
			}
		}

		$parts['ip'] = $ips[0];
		return $parts;
	}

	/**
	 * Syntax-level checks.
	 *
	 * @param string $url URL.
	 * @return array{url:string,scheme:string,host:string,port:int}|WP_Error
	 */
	private static function check_syntax( $url ) {
		$url = (string) $url;
		if ( strlen( $url ) > self::MAX_URL_LENGTH || preg_match( '/[\s\\\\\x00-\x1f\x7f]/', $url ) ) {
			return new WP_Error( 'sar_url_invalid', __( 'That does not look like a valid website address.', 'site-audit-report' ) );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return new WP_Error( 'sar_url_invalid', __( 'That does not look like a valid website address.', 'site-audit-report' ) );
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'sar_url_scheme', __( 'Only http and https website addresses can be scanned.', 'site-audit-report' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return self::blocked();
		}

		$default = 'https' === $scheme ? 443 : 80;
		$port    = isset( $parts['port'] ) ? (int) $parts['port'] : $default;
		if ( ! in_array( $port, array( 80, 443 ), true ) ) {
			return self::blocked();
		}

		$host = rtrim( strtolower( $parts['host'] ), '.' );

		if ( '[' === substr( $host, 0, 1 ) ) {
			$host = trim( $host, '[]' );
			if ( ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) || ! self::is_public_ip( $host ) ) {
				return self::blocked();
			}
			$ascii_host = $host;
		} elseif ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			if ( ! self::is_public_ip( $host ) ) {
				return self::blocked();
			}
			$ascii_host = $host;
		} else {
			$ascii_host = self::to_ascii( $host );
			if ( '' === $ascii_host || ! preg_match( '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $ascii_host ) ) {
				return self::blocked();
			}
			// A last label that is numeric or hex is an obfuscated IP (127.1, 0x7f.1, 2130706433).
			$labels = explode( '.', $ascii_host );
			$last   = end( $labels );
			if ( preg_match( '/^(\d+|0x[0-9a-f]+)$/', $last ) ) {
				return self::blocked();
			}
			foreach ( self::BLOCKED_SUFFIXES as $suffix ) {
				if ( $ascii_host === $suffix || str_ends_with( $ascii_host, '.' . $suffix ) ) {
					return self::blocked();
				}
			}
		}

		$authority = false !== strpos( $ascii_host, ':' ) ? '[' . $ascii_host . ']' : $ascii_host;
		$clean     = $scheme . '://' . $authority . ( $port !== $default ? ':' . $port : '' );
		$clean    .= $parts['path'] ?? '/';
		$clean    .= isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		return array(
			'url'    => $clean,
			'scheme' => $scheme,
			'host'   => $ascii_host,
			'port'   => $port,
		);
	}

	/**
	 * Whether an address is safe to connect to.
	 *
	 * IPv6 is an allow-list: only global unicast space passes, which also rules
	 * out loopback, link-local, unique-local, multicast, IPv4-mapped and NAT64.
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	public static function is_public_ip( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			foreach ( self::BLOCKED_V4 as $range ) {
				if ( self::in_range( $ip, $range ) ) {
					return false;
				}
			}
			return true;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			if ( ! self::in_range( $ip, '2000::/3' ) ) {
				return false;
			}
			foreach ( self::BLOCKED_V6 as $range ) {
				if ( self::in_range( $ip, $range ) ) {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * The stored domain key for a host: lower case, without a leading www.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function domain_key( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = trim( rtrim( $host, '.' ), '[]' );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * All addresses for a host. IPv4 first, because it is the most likely to connect.
	 *
	 * @param string $host Host name.
	 * @return string[]
	 */
	private static function resolve( $host ) {
		$ips = array();

		$v4 = gethostbynamel( $host . '.' );
		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}

		if ( function_exists( 'dns_get_record' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup is a normal outcome here.
			$records = @dns_get_record( $host . '.', DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}

		/**
		 * Filters the resolved addresses. Results still go through the public-IP
		 * check, so this cannot be used to reach a private address.
		 *
		 * @param string[] $ips  Addresses.
		 * @param string   $host Host name.
		 */
		$ips = apply_filters( 'sar_resolved_ips', array_values( array_unique( $ips ) ), $host );

		return is_array( $ips ) ? array_values( $ips ) : array();
	}

	/**
	 * CIDR match that works for both address families.
	 *
	 * @param string $ip    Address.
	 * @param string $range CIDR range.
	 * @return bool
	 */
	private static function in_range( $ip, $range ) {
		list( $subnet, $bits ) = explode( '/', $range );

		$ip_bin     = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$bits  = (int) $bits;
		$bytes = (int) floor( $bits / 8 );
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}
		$remaining = $bits % 8;
		if ( 0 === $remaining ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $remaining ) ) & 0xff );
		return ( $ip_bin[ $bytes ] & $mask ) === ( $subnet_bin[ $bytes ] & $mask );
	}

	/**
	 * Converts an international host name to its ASCII form.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private static function to_ascii( $host ) {
		if ( ! preg_match( '/[^\x20-\x7e]/', $host ) ) {
			return $host;
		}
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			return '';
		}
		$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
		return is_string( $ascii ) ? strtolower( $ascii ) : '';
	}

	/**
	 * The error for an address that may not be scanned.
	 *
	 * @return WP_Error
	 */
	private static function blocked() {
		return new WP_Error( 'sar_url_blocked', __( 'That address cannot be scanned. Please enter the public address of a website.', 'site-audit-report' ) );
	}
}
