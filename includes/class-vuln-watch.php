<?php
/**
 * Vulnerability watch: re-checks the WordPress core, theme and plugin versions
 * every scanned site showed against newly published vulnerabilities, without
 * scanning the sites again.
 *
 * When a new hole is published for a version a lead was running, or a known
 * hole is added to the CISA exploited list, an alert is recorded, the team gets
 * a digest, and (if turned on) the lead gets a short heads-up.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Vulnerability watch.
 */
final class Vuln_Watch {

	const LAST_RUN    = 'sar_watch_last';
	const TIME_BUDGET = 50;
	const MAX_GROUPS  = 400;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'sar_audit_completed', array( self::class, 'index_audit' ), 10, 2 );
	}

	/**
	 * Table names.
	 *
	 * @param string $which inventory|alerts.
	 * @return string
	 */
	public static function table( $which ) {
		global $wpdb;
		return $wpdb->prefix . ( 'alerts' === $which ? 'sar_alerts' : 'sar_inventory' );
	}

	/**
	 * Records what a finished audit saw. Only the newest audit of a domain is watched.
	 *
	 * @param array<string,mixed>      $audit  Audit.
	 * @param array<string,mixed>|null $result Report data, or null to read it from the audit.
	 * @return int Rows stored.
	 */
	public static function index_audit( $audit, $result = null ) {
		global $wpdb;
		$audit  = (array) $audit;
		$result = is_array( $result ) ? $result : json_decode( (string) ( $audit['result'] ?? '' ), true );
		if ( ! is_array( $result ) ) {
			return 0;
		}
		$info = array();
		foreach ( (array) ( $result['checks'] ?? array() ) as $check ) {
			if ( 'wp_detected' === ( $check['id'] ?? '' ) ) {
				$info = (array) ( $check['data'] ?? array() );
			}
		}
		if ( empty( $info['is_wp'] ) ) {
			return 0;
		}

		$rows = array();
		if ( ! empty( $info['core']['version'] ) ) {
			$rows[] = array(
				'type'    => 'core',
				'slug'    => 'wordpress',
				'name'    => 'WordPress',
				'version' => (string) $info['core']['version'],
			);
		}
		foreach ( array_merge( (array) ( $info['themes'] ?? array() ), (array) ( $info['components'] ?? array() ) ) as $item ) {
			if ( '' !== (string) ( $item['version'] ?? '' ) ) {
				$rows[] = array(
					'type'    => (string) $item['type'],
					'slug'    => (string) $item['slug'],
					'name'    => (string) $item['name'],
					'version' => (string) $item['version'],
				);
			}
		}

		$domain = (string) $audit['domain'];
		$wpdb->delete( self::table( 'inventory' ), array( 'domain' => $domain ) );

		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $rows as $row ) {
			// Everything known today is the baseline; only later disclosures raise alerts.
			$found = Vuln_Db::lookup( $row['type'], $row['slug'], $row['version'] );
			$wpdb->insert(
				self::table( 'inventory' ),
				array(
					'audit_id'   => (int) $audit['id'],
					'domain'     => $domain,
					'type'       => substr( $row['type'], 0, 10 ),
					'slug'       => substr( $row['slug'], 0, 100 ),
					'name'       => mb_substr( $row['name'], 0, 120 ),
					'version'    => substr( $row['version'], 0, 40 ),
					'known'      => wp_json_encode( array_values( array_column( $found['matches'], 'id' ) ) ),
					'kev'        => wp_json_encode( array_values( array_column( array_filter( $found['matches'], static fn( $m ) => ! empty( $m['kev'] ) ), 'id' ) ) ),
					'created_at' => $now,
					'checked_at' => $found['checked'] ? $now : null,
				)
			);
		}
		return count( $rows );
	}

	/**
	 * Indexes the newest completed audit of every domain that is not watched yet.
	 *
	 * @return int Domains indexed.
	 */
	public static function backfill() {
		global $wpdb;
		$audits = $wpdb->prefix . 'sar_audits';
		$inv    = self::table( 'inventory' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names only.
		$ids = $wpdb->get_col( "SELECT MAX(a.id) FROM {$audits} a LEFT JOIN {$inv} i ON i.domain = a.domain WHERE a.status = 'complete' AND i.id IS NULL GROUP BY a.domain LIMIT 500" );
		$n   = 0;
		foreach ( $ids as $id ) {
			$audit = Audits::get( (int) $id );
			if ( $audit && self::index_audit( $audit ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * The daily check.
	 *
	 * @param bool $notify Send emails for new alerts.
	 * @return array{checked:int,alerts:int,sites:int}
	 */
	public static function run( $notify = true ) {
		global $wpdb;
		if ( ! Settings::get( 'watch_enabled' ) ) {
			return array( 'checked' => 0, 'alerts' => 0, 'sites' => 0 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}
		self::backfill();

		$inv     = self::table( 'inventory' );
		$started = time();
		// Oldest checks first, so a run that stops early carries on next time.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		$groups = $wpdb->get_results( $wpdb->prepare( "SELECT type, slug, version, MIN(COALESCE(checked_at,'1970-01-01')) oldest FROM {$inv} GROUP BY type, slug, version ORDER BY oldest ASC LIMIT %d", self::MAX_GROUPS ), ARRAY_A );

		$checked = 0;
		$new     = array();
		$now     = gmdate( 'Y-m-d H:i:s' );
		foreach ( $groups as $group ) {
			if ( time() - $started > self::TIME_BUDGET ) {
				break;
			}
			$found = Vuln_Db::lookup( $group['type'], $group['slug'], $group['version'] );
			if ( ! $found['checked'] ) {
				continue;
			}
			++$checked;
			$by_id = array();
			foreach ( $found['matches'] as $match ) {
				if ( isset( $by_id[ $match['id'] ] ) ) {
					// Same record listed twice: keep the CISA flag if either copy has it.
					$by_id[ $match['id'] ]['kev'] = ! empty( $by_id[ $match['id'] ]['kev'] ) || ! empty( $match['kev'] );
				} else {
					$by_id[ $match['id'] ] = $match;
				}
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$inv} WHERE type = %s AND slug = %s AND version = %s", $group['type'], $group['slug'], $group['version'] ), ARRAY_A );
			foreach ( $rows as $row ) {
				$known = (array) json_decode( (string) $row['known'], true );
				$kev   = (array) json_decode( (string) $row['kev'], true );
				foreach ( $by_id as $id => $match ) {
					$reason = '';
					if ( ! in_array( $id, $known, true ) ) {
						$reason = 'new';
					} elseif ( ! empty( $match['kev'] ) && ! in_array( $id, $kev, true ) ) {
						$reason = 'kev';
					}
					if ( '' !== $reason && self::add_alert( $row, $match, $reason ) ) {
						$new[] = (int) $wpdb->insert_id;
					}
				}
				$wpdb->update(
					$inv,
					array(
						'known'      => wp_json_encode( array_values( array_unique( array_merge( $known, array_keys( $by_id ) ) ) ) ),
						'kev'        => wp_json_encode( array_values( array_unique( array_merge( $kev, array_keys( array_filter( $by_id, static fn( $m ) => ! empty( $m['kev'] ) ) ) ) ) ) ),
						'checked_at' => $now,
					),
					array( 'id' => (int) $row['id'] )
				);
			}
		}

		$sites = $new ? count( array_unique( array_map( static fn( $id ) => self::alert( $id )['domain'] ?? '', $new ) ) ) : 0;
		update_option(
			self::LAST_RUN,
			array(
				'at'      => time(),
				'checked' => $checked,
				'alerts'  => count( $new ),
				'sites'   => $sites,
			),
			false
		);

		if ( $new && $notify ) {
			self::notify_team( $new );
			if ( Settings::get( 'watch_email_leads' ) ) {
				self::notify_leads( $new );
			}
		}

		/**
		 * Fires after the vulnerability watch finds new alerts.
		 *
		 * @param int[] $alert_ids New alert IDs.
		 */
		if ( $new ) {
			do_action( 'sar_watch_alerts', $new );
		}

		return array(
			'checked' => $checked,
			'alerts'  => count( $new ),
			'sites'   => $sites,
		);
	}

	/**
	 * Stores one alert.
	 *
	 * @param array<string,mixed> $row    Inventory row.
	 * @param array<string,mixed> $match  Vulnerability.
	 * @param string              $reason new|kev.
	 * @return bool Whether it was new.
	 */
	private static function add_alert( array $row, array $match, $reason ) {
		global $wpdb;
		$published = (string) ( $match['published'] ?? '' );
		// The unique key makes a repeat insert fail quietly.
		$wpdb->suppress_errors( true );
		$ok = $wpdb->insert(
			self::table( 'alerts' ),
			array(
				'audit_id'   => (int) $row['audit_id'],
				'domain'     => (string) $row['domain'],
				'type'       => (string) $row['type'],
				'slug'       => (string) $row['slug'],
				'name'       => (string) $row['name'],
				'version'    => (string) $row['version'],
				'vuln_id'    => substr( (string) $match['id'], 0, 64 ),
				'vuln_name'  => mb_substr( (string) $match['name'], 0, 191 ),
				'severity'   => substr( (string) $match['severity'], 0, 10 ),
				'score'      => isset( $match['score'] ) ? (float) $match['score'] : null,
				'kev'        => ! empty( $match['kev'] ) ? 1 : 0,
				'cves'       => substr( implode( ',', (array) $match['cves'] ), 0, 255 ),
				'fixed'      => substr( (string) ( $match['fixed'] ?? '' ), 0, 40 ),
				'link'       => esc_url_raw( (string) ( $match['link'] ?? '' ) ),
				'published'  => preg_match( '/^\d{4}-\d\d-\d\d$/', $published ) ? $published : null,
				'reason'     => $reason,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$wpdb->suppress_errors( false );
		return (bool) $ok;
	}

	/**
	 * One alert.
	 *
	 * @param int $id ID.
	 * @return array<string,mixed>|null
	 */
	public static function alert( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'alerts' ) . ' WHERE id = %d', $id ), ARRAY_A );
	}

	/**
	 * Alerts with their lead, newest and worst first.
	 *
	 * @param string $status new|contacted|dismissed|'' for all.
	 * @return array<int,array<string,mixed>>
	 */
	public static function alerts( $status = 'new' ) {
		global $wpdb;
		$alerts = self::table( 'alerts' );
		$audits = $wpdb->prefix . 'sar_audits';
		$where  = '' !== $status ? $wpdb->prepare( 'WHERE x.status = %s', $status ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names and a prepared clause.
		return (array) $wpdb->get_results( "SELECT x.*, a.business_name, a.email, a.lead_status, a.source, a.token, a.consent_at FROM {$alerts} x LEFT JOIN {$audits} a ON a.id = x.audit_id {$where} ORDER BY x.kev DESC, FIELD(x.severity,'critical','high','medium','low','unknown'), x.created_at DESC LIMIT 500", ARRAY_A );
	}

	/**
	 * Alert counts by status.
	 *
	 * @return array<string,int>
	 */
	public static function counts() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		$rows = $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . self::table( 'alerts' ) . ' GROUP BY status', ARRAY_A );
		$out  = array( 'new' => 0, 'contacted' => 0, 'dismissed' => 0 );
		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * What is being watched.
	 *
	 * @return array{sites:int,items:int}
	 */
	public static function watched() {
		global $wpdb;
		$inv = self::table( 'inventory' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		$row = $wpdb->get_row( "SELECT COUNT(DISTINCT domain) sites, COUNT(*) items FROM {$inv}", ARRAY_A );
		return array(
			'sites' => (int) ( $row['sites'] ?? 0 ),
			'items' => (int) ( $row['items'] ?? 0 ),
		);
	}

	/**
	 * Sets an alert's status.
	 *
	 * @param int    $id     ID.
	 * @param string $status new|contacted|dismissed.
	 * @return void
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		if ( in_array( $status, array( 'new', 'contacted', 'dismissed' ), true ) ) {
			$wpdb->update( self::table( 'alerts' ), array( 'status' => $status ), array( 'id' => (int) $id ) );
		}
	}

	/**
	 * One digest email to the team.
	 *
	 * @param int[] $ids New alert IDs.
	 * @return void
	 */
	private static function notify_team( array $ids ) {
		global $wpdb;
		$to = (string) Settings::get( 'notify_email' );
		$to = is_email( $to ) ? $to : (string) get_option( 'admin_email' );

		$groups = array();
		foreach ( $ids as $id ) {
			$a = self::alert( $id );
			if ( $a ) {
				$key                          = $a['vuln_id'] . '|' . $a['reason'];
				$groups[ $key ]['alert']      = $a;
				$groups[ $key ]['domains'][]  = $a['domain'];
			}
		}
		$sites = count( array_unique( array_merge( ...array_map( static fn( $g ) => $g['domains'], array_values( $groups ) ) ) ) );

		$lines = array();
		foreach ( $groups as $group ) {
			$a       = $group['alert'];
			$lines[] = sprintf(
				'%s %s — %s (%s%s)%s',
				$a['name'],
				$a['version'],
				$a['vuln_name'],
				strtoupper( $a['severity'] ),
				$a['kev'] ? ', EXPLOITED IN THE WILD' : '',
				'kev' === $a['reason'] ? ' [now on the CISA list]' : ''
			);
			$lines[] = '   ' . implode( ', ', array_unique( $group['domains'] ) );
			$lines[] = '   ' . ( $a['fixed'] ? 'Fixed in ' . $a['fixed'] : 'No fix released yet' ) . ( $a['cves'] ? ' · ' . $a['cves'] : '' );
			$lines[] = '';
		}

		/* translators: 1: number of sites, 2: number of issues. */
		$subject = sprintf( _n( 'Vulnerability watch: %1$d of your leads is exposed to %2$d new issue(s)', 'Vulnerability watch: %1$d of your leads are exposed to %2$d new issue(s)', $sites, 'site-audit-report' ), $sites, count( $groups ) );
		$body    = __( 'New vulnerabilities were published (or added to the CISA exploited list) for versions your scanned sites were running:', 'site-audit-report' ) . "\n\n"
			. implode( "\n", $lines ) . "\n"
			. __( 'Open the alerts, see who to contact and mark them as done:', 'site-audit-report' ) . "\n"
			. admin_url( 'admin.php?page=site-audit-report-watch' ) . "\n\n"
			. __( 'Versions are as seen at each site\'s last scan; some may have updated since.', 'site-audit-report' );
		wp_mail( $to, $subject, $body );

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name and generated placeholders.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'alerts' ) . " SET notified_at = %s WHERE id IN ({$placeholders})", array_merge( array( gmdate( 'Y-m-d H:i:s' ) ), $ids ) ) );
	}

	/**
	 * A short heads-up to each lead who agreed to be contacted. One email per lead per run.
	 *
	 * @param int[] $ids New alert IDs.
	 * @return void
	 */
	private static function notify_leads( array $ids ) {
		global $wpdb;
		$by_audit = array();
		foreach ( $ids as $id ) {
			$a = self::alert( $id );
			if ( $a ) {
				$by_audit[ (int) $a['audit_id'] ][] = $a;
			}
		}
		$page = Admin\Tools::pages_using( 'site_audit_security' );
		$page = $page ? $page[0]['view'] : ( Admin\Tools::pages_using( 'site_audit_service' )[0]['view'] ?? home_url( '/' ) );
		$brand = Settings::brand_name();

		foreach ( $by_audit as $audit_id => $alerts ) {
			$audit = Audits::get( $audit_id );
			// Only leads who gave contact consent, and never twice for the same alert.
			if ( ! $audit || ! is_email( (string) $audit['email'] ) || empty( $audit['consent_at'] ) ) {
				continue;
			}
			$alerts = array_filter( $alerts, static fn( $a ) => empty( $a['lead_emailed_at'] ) );
			if ( ! $alerts ) {
				continue;
			}
			$items = array();
			foreach ( $alerts as $a ) {
				$items[] = sprintf( '- %s %s: %s (%s)%s', $a['name'], $a['version'], $a['vuln_name'], $a['severity'], $a['fixed'] ? ', fixed in ' . $a['fixed'] : '' );
			}
			$link = 'service' === $audit['source'] ? add_query_arg( Service_Bay::PARAM, $audit['token'], $page ) : $page;
			/* translators: %s: domain. */
			$subject = sprintf( __( 'Security notice for %s', 'site-audit-report' ), $audit['domain'] );
			$body    = sprintf(
				/* translators: 1: name, 2: domain, 3: date, 4: list, 5: link, 6: brand. */
				__( "Hi %1\$s,\n\nWhen we checked %2\$s on %3\$s it showed software versions that have since had security issues published:\n\n%4\$s\n\nIf you have updated since then, you can ignore this. If not, updating is the fix. You can re-run the free check or book the fix here:\n%5\$s\n\n%6\$s\n\nYou are getting this because you asked us to contact you about your website. Reply to stop these notices.", 'site-audit-report' ),
				'' !== (string) $audit['business_name'] ? $audit['business_name'] : __( 'there', 'site-audit-report' ),
				$audit['domain'],
				mysql2date( 'j M Y', (string) ( $audit['completed_at'] ?? $audit['created_at'] ) ),
				implode( "\n", $items ),
				$link,
				$brand
			);
			if ( wp_mail( (string) $audit['email'], $subject, $body ) ) {
				foreach ( $alerts as $a ) {
					$wpdb->update( self::table( 'alerts' ), array( 'lead_emailed_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $a['id'] ) );
				}
			}
		}
	}
}
