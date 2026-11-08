<?php
/**
 * Lead priority: a 0–100 score per audit so the team knows who to call first.
 *
 * Intent (did they book, did they leave an email), need (how much their site
 * needs fixing), urgency (exploited or new vulnerabilities) and freshness
 * (how recently they came in). Every score comes with its reasons.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Lead priority.
 */
final class Lead_Priority {

	/**
	 * Registers hooks that change a lead's priority.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'sar_audit_completed', static fn( $audit ) => self::recalc( (int) $audit['id'] ), 20 );
		add_action(
			'sar_service_booked',
			static function ( $booking ) {
				if ( ! empty( $booking['audit']['id'] ) ) {
					self::recalc( (int) $booking['audit']['id'] );
				}
			},
			20
		);
		add_action(
			'sar_watch_alerts',
			static function ( $ids ) {
				foreach ( (array) $ids as $id ) {
					$alert = Vuln_Watch::alert( (int) $id );
					if ( $alert ) {
						self::recalc( (int) $alert['audit_id'] );
					}
				}
			},
			20
		);
	}

	/**
	 * Tier for a score.
	 *
	 * @param int|null $priority Score.
	 * @return string hot|warm|cool|closed
	 */
	public static function tier( $priority ) {
		if ( null === $priority ) {
			return 'cool';
		}
		if ( $priority >= 70 ) {
			return 'hot';
		}
		return $priority >= 40 ? 'warm' : ( 0 === (int) $priority ? 'closed' : 'cool' );
	}

	/**
	 * Works out an audit's priority.
	 *
	 * @param array<string,mixed> $audit      Audit, including result.
	 * @param int                 $new_alerts New vulnerability-watch alerts for it.
	 * @return array{0:int,1:string[]} Score and reasons, most important first.
	 */
	public static function compute( array $audit, $new_alerts = 0 ) {
		$lead = (string) $audit['lead_status'];
		if ( in_array( $lead, array( 'won', 'lost' ), true ) ) {
			return array( 0, array( 'won' === $lead ? __( 'Won', 'site-audit-report' ) : __( 'Lost', 'site-audit-report' ) ) );
		}
		$why   = array();
		$score = 0;
		$email = is_email( (string) $audit['email'] );

		// Intent: what they did.
		$intent = array(
			'booked'    => array( 40, __( 'Booked a fix', 'site-audit-report' ) ),
			'qualified' => array( 28, __( 'Qualified', 'site-audit-report' ) ),
			'contacted' => array( 12, __( 'In conversation', 'site-audit-report' ) ),
		);
		if ( isset( $intent[ $lead ] ) ) {
			$score += $intent[ $lead ][0];
			$why[]  = array( $intent[ $lead ][0], $intent[ $lead ][1] );
		} elseif ( $email && 'admin' !== $audit['source'] ) {
			$score += 20;
			$why[]  = array( 20, __( 'Asked for a report', 'site-audit-report' ) );
		} elseif ( 'admin' === $audit['source'] ) {
			$score += 5;
		}

		// Need: how much their site needs work.
		if ( null !== $audit['score'] && '' !== (string) $audit['score'] ) {
			$need   = (int) round( ( 100 - (int) $audit['score'] ) * 0.25 );
			$score += $need;
			if ( $need >= 10 ) {
				/* translators: %d: score. */
				$why[] = array( $need, sprintf( __( 'Low score (%d)', 'site-audit-report' ), (int) $audit['score'] ) );
			}
		}

		// Urgency: security problems that give a reason to call today.
		$result = json_decode( (string) ( $audit['result'] ?? '' ), true );
		$checks = array();
		foreach ( (array) ( is_array( $result ) ? ( $result['checks'] ?? array() ) : array() ) as $c ) {
			$checks[ $c['id'] ] = $c['status'];
		}
		if ( $new_alerts ) {
			$score += 22;
			/* translators: %d: alerts. */
			$why[] = array( 22, sprintf( _n( '%d new vulnerability alert', '%d new vulnerability alerts', $new_alerts, 'site-audit-report' ), $new_alerts ) );
		}
		if ( 'fail' === ( $checks['wp_kev'] ?? '' ) ) {
			$score += 15;
			$why[]  = array( 15, __( 'Actively exploited hole', 'site-audit-report' ) );
		} elseif ( in_array( 'fail', array( $checks['wp_plugins'] ?? '', $checks['wp_core'] ?? '', $checks['wp_theme'] ?? '' ), true ) ) {
			$score += 10;
			$why[]  = array( 10, __( 'High-risk vulnerability', 'site-audit-report' ) );
		}
		if ( 'fail' === ( $checks['https'] ?? '' ) || 'fail' === ( $checks['wp_debug_log'] ?? '' ) ) {
			$score += 5;
			$why[]  = array( 5, __( 'Visible security problem', 'site-audit-report' ) );
		}

		// Freshness: people are easiest to reach while the audit is on their mind.
		$age = ( time() - strtotime( (string) $audit['created_at'] . ' UTC' ) ) / DAY_IN_SECONDS;
		if ( $age <= 2 ) {
			$score += 15;
			$why[]  = array( 15, __( 'Came in in the last 2 days', 'site-audit-report' ) );
		} elseif ( $age <= 7 ) {
			$score += 10;
			$why[]  = array( 10, __( 'Came in this week', 'site-audit-report' ) );
		} elseif ( $age <= 30 ) {
			$score += 4;
		}

		// No way to reach them: keep it visible, but never at the top.
		if ( ! $email ) {
			$score = min( $score, 35 );
			$why[] = array( -1, __( 'No contact details', 'site-audit-report' ) );
		}

		usort( $why, static fn( $a, $b ) => $b[0] <=> $a[0] );
		return array( max( 1, min( 100, $score ) ), array_map( static fn( $w ) => $w[1], $why ) );
	}

	/**
	 * Stores one audit's priority.
	 *
	 * @param int $id Audit ID.
	 * @return int|null The new priority.
	 */
	public static function recalc( $id ) {
		global $wpdb;
		$audit = Audits::get( (int) $id );
		if ( ! $audit || 'complete' !== $audit['status'] ) {
			return null;
		}
		$alerts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sar_alerts WHERE audit_id = %d AND status = 'new'", (int) $id ) );
		list( $priority, $why ) = self::compute( $audit, $alerts );
		$wpdb->update(
			$wpdb->prefix . 'sar_audits',
			array(
				'priority'     => $priority,
				'priority_why' => mb_substr( implode( ' · ', array_slice( $why, 0, 4 ) ), 0, 255 ),
			),
			array( 'id' => (int) $id )
		);
		return $priority;
	}

	/**
	 * Recalculates every completed audit (freshness changes daily).
	 *
	 * @return int Audits updated.
	 */
	public static function recalculate_all() {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}sar_audits WHERE status = 'complete' ORDER BY id DESC LIMIT 2000" );
		foreach ( $ids as $id ) {
			self::recalc( (int) $id );
		}
		return count( $ids );
	}

	/**
	 * The leads to call next: open, reachable, highest priority first.
	 *
	 * @param int $limit How many.
	 * @return array<int,array<string,mixed>>
	 */
	public static function call_next( $limit = 5 ) {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, domain, business_name, email, score, lead_status, source, priority, priority_why, created_at FROM {$wpdb->prefix}sar_audits WHERE status = 'complete' AND priority IS NOT NULL AND priority > 0 AND email <> '' AND lead_status NOT IN ('won','lost') ORDER BY priority DESC, created_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}
}
