<?php
/**
 * Background processing. A form request only queues a scan; workers run through
 * WP-Cron and a signed non-blocking loopback, whichever arrives first.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Scan queue.
 */
final class Queue {

	const HOOK           = 'sar_run_queue';
	const WORKER_SECONDS = 25;
	const STALE_MINUTES  = 5;
	const MAX_ATTEMPTS   = 3;

	/**
	 * How many scans may run at once. Each one holds a PHP worker for as long as
	 * it runs, and small hosts have very few, so the default keeps the site responsive.
	 *
	 * @return int
	 */
	private static function max_concurrent() {
		/**
		 * Filters how many scans may run at once.
		 *
		 * @param int $max Default 1.
		 */
		return max( 1, (int) apply_filters( 'sar_max_concurrent_scans', 1 ) );
	}

	/**
	 * Starts a worker without blocking the current request.
	 *
	 * @return void
	 */
	public static function dispatch() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
		self::schedule_fallback();
		self::loopback();
	}

	/**
	 * Schedules a later check, so a scan whose worker died still gets picked up
	 * when nobody is waiting on the page. WordPress ignores duplicates of this event.
	 *
	 * @return void
	 */
	private static function schedule_fallback() {
		wp_schedule_single_event( time() + ( self::STALE_MINUTES + 1 ) * MINUTE_IN_SECONDS, self::HOOK, array( 'fallback' ) );
	}

	/**
	 * Makes the signed loopback call.
	 *
	 * @return void
	 */
	private static function loopback() {
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.5,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => 'sar_worker',
					'key'    => self::worker_key(),
				),
			)
		);
	}

	/**
	 * The secret that authorises a loopback worker.
	 *
	 * @return string
	 */
	private static function worker_key() {
		return hash_hmac( 'sha256', 'sar_worker', wp_salt( 'nonce' ) );
	}

	/**
	 * Handles the loopback request.
	 *
	 * @return void
	 */
	public static function handle_loopback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Authorised by comparing an HMAC, because no user session exists.
		$key = isset( $_POST['key'] ) ? (string) wp_unslash( $_POST['key'] ) : '';
		if ( ! hash_equals( self::worker_key(), $key ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		self::run();
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Worker body.
	 *
	 * @return void
	 */
	public static function run() {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Some hosts disable this; the scan resumes in a new worker if so.
			@set_time_limit( 180 );
		}

		self::recover_stale();

		$deadline = time() + self::WORKER_SECONDS;
		while ( time() < $deadline ) {
			if ( Audits::count_running() >= self::max_concurrent() ) {
				self::schedule_fallback();
				return;
			}
			$audit = Audits::claim_next();
			if ( ! $audit ) {
				return;
			}
			Scanner::process( $audit, $deadline );
		}

		if ( Audits::count_queued() > 0 ) {
			self::dispatch();
		}
	}

	/**
	 * Returns scans with dead workers to the queue, or fails them after repeated tries.
	 *
	 * @return void
	 */
	public static function recover_stale() {
		foreach ( Audits::stale_running( self::STALE_MINUTES ) as $stale ) {
			$attempts = (int) $stale['attempts'] + 1;
			if ( $attempts >= self::MAX_ATTEMPTS ) {
				Scanner::fail( (int) $stale['id'], __( 'The scan could not be completed after several tries.', 'site-audit-report' ) );
			} else {
				Audits::update(
					(int) $stale['id'],
					array(
						'status'    => 'queued',
						'attempts'  => $attempts,
						'locked_at' => null,
					)
				);
			}
		}
	}

	/**
	 * Called while someone is waiting on a result, in case the first start was missed.
	 *
	 * @return void
	 */
	public static function nudge() {
		self::recover_stale();
		if ( Audits::count_queued() > 0 && Audits::count_running() < self::max_concurrent() ) {
			self::dispatch();
		}
	}

	/**
	 * WP-CLI: processes the queue until empty.
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-audit-report run-queue
	 *
	 * @return void
	 */
	public static function cli_run() {
		$passes = 0;
		do {
			self::recover_stale();
			$audit = Audits::claim_next();
			if ( $audit ) {
				Scanner::process( $audit, time() + 600 );
				++$passes;
			}
		} while ( $audit && $passes < 100 );
		\WP_CLI::success( sprintf( 'Processed %d scan run(s).', $passes ) );
	}
}
