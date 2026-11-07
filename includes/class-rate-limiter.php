<?php
/**
 * Fixed-window rate limits stored in the database, so they hold across
 * processes and do not depend on an object cache.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Rate limiter.
 */
final class Rate_Limiter {

	/**
	 * Counts one hit and reports whether it is within the limit.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $limit  Allowed hits per window.
	 * @param int    $window Window length in seconds.
	 * @return bool True when allowed.
	 */
	public static function hit( $bucket, $limit, $window ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sar_rate_limits';
		$key   = substr( $window . ':' . $bucket, 0, 190 );
		$start = (int) ( floor( time() / $window ) * $window );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Counter table; the upsert is what makes this atomic.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (bucket, window_start, hits) VALUES (%s, %d, 1) ON DUPLICATE KEY UPDATE hits = hits + 1",
				$key,
				$start
			)
		);
		$hits = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT hits FROM {$table} WHERE bucket = %s AND window_start = %d", $key, $start )
		);
		// phpcs:enable

		return $hits <= $limit;
	}

	/**
	 * Hashes a value so raw IPs and emails are not kept in the counter table.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function hash( $value ) {
		return hash_hmac( 'sha256', strtolower( (string) $value ), wp_salt( 'auth' ) );
	}

	/**
	 * The visitor's IP. Proxy headers count only when an admin has opted in,
	 * because anyone can send them when no proxy is in front of the site.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$header = (string) Settings::get( 'ip_header' );
		if ( '' !== $header && ! empty( $_SERVER[ $header ] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an IP below.
			$candidate = trim( explode( ',', wp_unslash( $_SERVER[ $header ] ) )[0] );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an IP below.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
	}

	/**
	 * Removes expired counters.
	 *
	 * @return void
	 */
	public static function purge() {
		global $wpdb;
		$table = $wpdb->prefix . 'sar_rate_limits';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Housekeeping on the plugin's own table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE window_start < %d", time() - 2 * DAY_IN_SECONDS ) );
	}
}
