<?php
/**
 * Runs a scan one step at a time, saving after each step so it can continue
 * in a later worker if its time runs out or the process dies.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Scan engine.
 */
final class Scanner {

	const MAX_REQUESTS = 150;
	const MAX_SECONDS  = 480;

	/**
	 * The modules for a scan, in order. Fast ones run first so an early score
	 * can be shown before the slower Google tests finish.
	 *
	 * @return Module[]
	 */
	public static function modules() {
		$modules = array(
			new Modules\Onpage(),
			new Modules\Crawlability(),
			new Modules\Security(),
			new Modules\Presence(),
			new Modules\Links(),
			new Modules\Places(),
			new Modules\Pagespeed( 'mobile' ),
			new Modules\Pagespeed( 'desktop' ),
		);

		/**
		 * Filters the scan modules. This is the extension point for paid data
		 * such as rankings, competitors, backlinks, AI visibility or AI summaries.
		 * Each item must implement Site_Audit_Report\Module.
		 *
		 * @param Module[] $modules Modules.
		 */
		$modules = apply_filters( 'sar_modules', $modules );

		$valid = array();
		foreach ( (array) $modules as $module ) {
			if ( $module instanceof Module ) {
				$valid[ $module->id() ] = $module;
			}
		}
		return $valid;
	}

	/**
	 * Advances a claimed audit until it is complete or the deadline passes.
	 *
	 * @param array<string,mixed> $audit    Audit.
	 * @param int                 $deadline Timestamp to stop at.
	 * @return void
	 */
	public static function process( array $audit, $deadline ) {
		$id    = (int) $audit['id'];
		$state = json_decode( (string) $audit['state'], true );
		$state = is_array( $state ) ? $state : array(
			'started'  => time(),
			'crawl'    => null,
			'done'     => array(),
			'checks'   => array(),
			'requests' => 0,
			'bytes'    => 0,
		);

		$missing = Plugin::missing_requirements();
		if ( $missing ) {
			/* translators: %s: PHP extension names. */
			self::fail( $id, sprintf( __( 'This server is missing PHP extensions the scanner needs: %s.', 'site-audit-report' ), implode( ', ', $missing ) ) );
			return;
		}

		$http = new Http_Client( self::MAX_REQUESTS, (int) $state['requests'], (int) $state['bytes'] );

		try {
			if ( null === $state['crawl'] ) {
				Audits::update(
					$id,
					array(
						'stage'     => __( 'Reading the website', 'site-audit-report' ),
						'progress'  => 3,
						'locked_at' => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$state['crawl'] = Crawler::crawl( (string) $audit['site_url'], $http );
				self::save( $audit, $state, $http );
			}

			$modules = self::modules();
			$total   = count( $modules );

			foreach ( $modules as $module_id => $module ) {
				if ( in_array( $module_id, $state['done'], true ) ) {
					continue;
				}

				if ( time() >= $deadline ) {
					Audits::update(
						$id,
						array(
							'status'    => 'queued',
							'locked_at' => null,
						)
					);
					return;
				}

				$over_time = time() - (int) $state['started'] > self::MAX_SECONDS;

				Audits::update(
					$id,
					array(
						'stage'     => self::short_label( $module ),
						'progress'  => (int) min( 95, 10 + 85 * count( $state['done'] ) / max( 1, $total ) ),
						'locked_at' => gmdate( 'Y-m-d H:i:s' ),
					)
				);

				if ( $over_time ) {
					$state['skipped_for_time'][] = $module->label();
				} else {
					$state['checks'] = array_merge( $state['checks'], self::run_module( $module, $audit, $state['crawl'], $http ) );
				}
				$state['done'][] = $module_id;
				self::save( $audit, $state, $http );
			}

			self::finish( $audit, $state, $http );
		} catch ( Throwable $error ) {
			// The message may hold a path or other internals, so only a generic note is stored.
			self::fail( $id, __( 'The scan stopped because of an unexpected error.', 'site-audit-report' ) );
		}
	}

	/**
	 * Runs one module. A failure affects only that module.
	 *
	 * @param Module              $module Module.
	 * @param array<string,mixed> $audit  Audit.
	 * @param array<string,mixed> $crawl  Crawl data.
	 * @param Http_Client         $http   Client.
	 * @return array<int,array<string,mixed>>
	 */
	private static function run_module( Module $module, array $audit, array $crawl, Http_Client $http ) {
		try {
			$checks = $module->run( new Scan_Context( $audit, $crawl, $http ) );
		} catch ( Throwable $error ) {
			$checks = array(
				Check::not_checked(
					'presence',
					$module->id() . '_error',
					$module->label(),
					1,
					__( 'This part of the scan could not be completed.', 'site-audit-report' )
				),
			);
		}

		$valid = array();
		foreach ( (array) $checks as $check ) {
			if ( is_array( $check ) && isset( $check['id'], $check['status'], $check['category'], $check['weight'] ) ) {
				$check['module'] = $module->id();
				$valid[]         = $check;
			}
		}
		return $valid;
	}

	/**
	 * Saves progress, including an early score that the form can show.
	 *
	 * @param array<string,mixed> $audit Audit.
	 * @param array<string,mixed> $state State.
	 * @param Http_Client         $http  Client.
	 * @return void
	 */
	private static function save( array $audit, array &$state, Http_Client $http ) {
		$state['requests'] = $http->requests;
		$state['bytes']    = $http->bytes;

		$result = self::build_result( $audit, $state, $http, true );

		Audits::update(
			(int) $audit['id'],
			array(
				'state'     => wp_json_encode( $state, JSON_INVALID_UTF8_SUBSTITUTE ),
				'result'    => wp_json_encode( $result, JSON_INVALID_UTF8_SUBSTITUTE ),
				'score'     => $result['score'],
				'locked_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Completes an audit and clears its working data.
	 *
	 * @param array<string,mixed> $audit Audit.
	 * @param array<string,mixed> $state State.
	 * @param Http_Client         $http  Client.
	 * @return void
	 */
	private static function finish( array $audit, array $state, Http_Client $http ) {
		$state['requests'] = $http->requests;
		$state['bytes']    = $http->bytes;

		$result = self::build_result( $audit, $state, $http, false );
		$now    = gmdate( 'Y-m-d H:i:s' );

		Audits::update(
			(int) $audit['id'],
			array(
				'status'       => 'complete',
				'stage'        => '',
				'progress'     => 100,
				'state'        => null,
				'result'       => wp_json_encode( $result, JSON_INVALID_UTF8_SUBSTITUTE ),
				'score'        => $result['score'],
				'error'        => '',
				'locked_at'    => null,
				'completed_at' => $now,
			)
		);

		$fresh = Audits::get( (int) $audit['id'] );
		if ( $fresh ) {
			Notifier::audit_complete( $fresh );

			/**
			 * Fires when an audit finishes.
			 *
			 * @param array $audit  Audit record.
			 * @param array $result Report data.
			 */
			do_action( 'sar_audit_completed', $fresh, $result );
		}
	}

	/**
	 * Builds report data from the current state.
	 *
	 * @param array<string,mixed> $audit   Audit.
	 * @param array<string,mixed> $state   State.
	 * @param Http_Client         $http    Client.
	 * @param bool                $partial Whether the scan is still running.
	 * @return array<string,mixed>
	 */
	private static function build_result( array $audit, array $state, Http_Client $http, $partial ) {
		$crawl = (array) $state['crawl'];

		$limitations = array();
		/* translators: %d: number of pages. */
		$limitations[] = sprintf( __( 'Page checks are based on a sample of %d pages, not the whole site.', 'site-audit-report' ), count( (array) ( $crawl['pages'] ?? array() ) ) );
		$limitations[] = __( 'The scan reads the HTML a server sends. Content added afterwards by JavaScript is not seen, except in the Google speed tests.', 'site-audit-report' );
		if ( ! empty( $crawl['truncated'] ) ) {
			$limitations[] = __( 'At least one page was larger than the 2 MB read limit, so only its first part was checked.', 'site-audit-report' );
		}
		if ( ! empty( $crawl['skipped_by_robots'] ) ) {
			/* translators: %d: number of pages. */
			$limitations[] = sprintf( __( 'Pages left out of the sample because robots.txt asks crawlers to avoid them: %d.', 'site-audit-report' ), (int) $crawl['skipped_by_robots'] );
		}
		if ( ! empty( $crawl['pages'][0]['js_shell'] ) ) {
			$limitations[] = __( 'The home page seems to build its content with JavaScript, so several page checks may understate what visitors see.', 'site-audit-report' );
		}
		if ( ! empty( $state['skipped_for_time'] ) ) {
			$limitations[] = __( 'The scan reached its time limit, so some sections did not run.', 'site-audit-report' );
		}

		return Scoring::build(
			$audit,
			(array) $state['checks'],
			$crawl,
			array(
				'partial'     => $partial,
				'limitations' => $limitations,
				'stats'       => array(
					'requests' => $http->requests,
					'seconds'  => time() - (int) $state['started'],
				),
			)
		);
	}

	/**
	 * Marks an audit as failed.
	 *
	 * @param int    $id      Audit ID.
	 * @param string $message Error.
	 * @return void
	 */
	public static function fail( $id, $message ) {
		Audits::update(
			$id,
			array(
				'status'    => 'failed',
				'stage'     => '',
				'state'     => null,
				'error'     => substr( $message, 0, 255 ),
				'locked_at' => null,
			)
		);
	}

	/**
	 * Stage text for progress displays.
	 *
	 * @param Module $module Module.
	 * @return string
	 */
	private static function short_label( Module $module ) {
		return mb_substr( $module->label(), 0, 40 );
	}
}
