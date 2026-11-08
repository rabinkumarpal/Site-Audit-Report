<?php
/**
 * Change watch: a weekly fingerprint of client sites, compared with the last
 * one, so the team hears about a new noindex, a changed title, a removed
 * plugin or an expiring certificate before the client does.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Change watch.
 */
final class Monitors {

	const RECHECK_DAYS = 7;
	const TIME_BUDGET  = 120;
	const MAX_EVENTS   = 80;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sar_monitors';
	}

	/**
	 * Severity order, worst first.
	 *
	 * @return array<string,int>
	 */
	public static function severities() {
		return array(
			'critical' => 0,
			'warning'  => 1,
			'info'     => 2,
			'good'     => 3,
		);
	}

	/* -------------------------------------------------------------- Records */

	/**
	 * Adds a site and records its baseline.
	 *
	 * @param string $url      Site address.
	 * @param int    $audit_id Related audit, or 0.
	 * @param string $label    Note, such as the client's name.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function create( $url, $audit_id = 0, $label = '' ) {
		global $wpdb;
		$url = Url_Guard::normalize_input( $url );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$checked = Url_Guard::validate( $url );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$domain = Url_Guard::domain_key( $checked['url'] );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE domain = %s', $domain ) );
		if ( $exists ) {
			return new \WP_Error( 'sar_exists', __( 'That site is already being watched.', 'site-audit-report' ) );
		}
		$wpdb->insert(
			self::table(),
			array(
				'site_url'   => $checked['url'],
				'domain'     => $domain,
				'audit_id'   => (int) $audit_id,
				'label'      => mb_substr( sanitize_text_field( $label ), 0, 120 ),
				'changes'    => '[]',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * One monitor.
	 *
	 * @param int $id ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	/**
	 * All monitors, most recently changed first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		return (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY COALESCE(last_change_at, created_at) DESC, id DESC LIMIT 500', ARRAY_A );
	}

	/**
	 * Updates a monitor.
	 *
	 * @param int                 $id   ID.
	 * @param array<string,mixed> $data Columns.
	 * @return void
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Deletes a monitor.
	 *
	 * @param int $id ID.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Monitors with changes worth a look in the last week.
	 *
	 * @return int
	 */
	public static function recent_alerts() {
		$n     = 0;
		$since = time() - 7 * DAY_IN_SECONDS;
		foreach ( self::all() as $m ) {
			foreach ( (array) json_decode( (string) $m['changes'], true ) as $c ) {
				if ( strtotime( $c['at'] . ' UTC' ) >= $since && in_array( $c['severity'], array( 'critical', 'warning' ), true ) ) {
					++$n;
					break;
				}
			}
		}
		return $n;
	}

	/* --------------------------------------------------------- Fingerprint */

	/**
	 * Reads the facts that are worth comparing week to week.
	 *
	 * @param string $url Site address.
	 * @return array<string,mixed>
	 */
	public static function snapshot( $url ) {
		$http  = new Http_Client( 80 );
		$crawl = Crawler::crawl( $url, $http, 1 );
		$snap  = array(
			'readable'  => ! empty( $crawl['readable'] ),
			'reason'    => (string) ( $crawl['unreadable_reason'] ?? '' ),
			'code'      => (int) ( $crawl['home_code'] ?? 0 ),
			'final_url' => (string) ( $crawl['final_url'] ?? $url ),
			'https'     => ! empty( $crawl['tls']['https_works'] ),
		);
		if ( ! $snap['readable'] ) {
			return $snap;
		}
		$page    = (array) ( $crawl['pages'][0] ?? array() );
		$headers = (array) ( $crawl['home_headers'] ?? array() );
		$robots  = (array) ( $crawl['robots'] ?? array() );
		$sitemap = (array) ( $crawl['sitemap'] ?? array() );

		$snap += array(
			'title'       => (string) ( $page['title'] ?? '' ),
			'description' => (string) ( $page['description'] ?? '' ),
			'h1'          => (string) ( $page['h1'][0] ?? '' ),
			'canonical'   => (string) ( $page['canonical'] ?? '' ),
			'noindex'     => false !== stripos( (string) ( $page['robots'] ?? '' ) . ' ' . (string) ( $headers['x-robots-tag'] ?? '' ), 'noindex' ),
			'lang'        => (string) ( $page['lang'] ?? '' ),
			'words'       => (int) ( $page['word_count'] ?? 0 ),
			'schema'      => array_values( array_unique( (array) ( $page['schema_types'] ?? array() ) ) ),
			'trackers'    => array_values( array_unique( (array) ( $page['trackers'] ?? array() ) ) ),
			'headers'     => array_values( array_intersect( array( 'strict-transport-security', 'content-security-policy', 'x-content-type-options', 'x-frame-options', 'referrer-policy', 'permissions-policy' ), array_keys( $headers ) ) ),
			'robots_all'  => ! empty( $robots['blocks_all'] ),
			'sitemap'     => ! empty( $sitemap['found'] ),
			'sitemap_n'   => (int) ( $sitemap['url_count'] ?? 0 ),
			'cert'        => 0,
			'wp'          => null,
		);
		if ( $snap['https'] ) {
			$snap['cert'] = ( new Modules\Security() )->read_certificate_expiry( $snap['final_url'] );
		}

		// WordPress versions, from the same inspection the Security Check uses.
		$ctx = new Scan_Context(
			array(
				'id'       => 0,
				'site_url' => $url,
				'domain'   => Url_Guard::domain_key( $url ),
				'source'   => 'monitor',
			),
			$crawl,
			$http
		);
		foreach ( ( new Modules\Wordpress() )->run( $ctx ) as $check ) {
			if ( 'wp_detected' === $check['id'] && ! empty( $check['data']['is_wp'] ) ) {
				$data    = $check['data'];
				$plugins = array();
				foreach ( (array) $data['components'] as $c ) {
					$plugins[ $c['slug'] ] = array( (string) $c['name'], (string) $c['version'] );
				}
				$themes = array();
				foreach ( (array) $data['themes'] as $t ) {
					$themes[ $t['slug'] ] = array( (string) $t['name'], (string) $t['version'] );
				}
				$snap['wp'] = array(
					'core'    => (string) ( $data['core']['version'] ?? '' ),
					'themes'  => $themes,
					'plugins' => $plugins,
				);
			}
		}
		return $snap;
	}

	/**
	 * What changed between two fingerprints.
	 *
	 * @param array<string,mixed> $old Previous.
	 * @param array<string,mixed> $new Current.
	 * @return array<int,array<string,string>> severity, label, before, after.
	 */
	public static function diff( array $old, array $new ) {
		$out = array();
		$add = static function ( $severity, $label, $before = '', $after = '' ) use ( &$out ) {
			$out[] = array(
				'severity' => $severity,
				'label'    => $label,
				'before'   => mb_substr( (string) $before, 0, 300 ),
				'after'    => mb_substr( (string) $after, 0, 300 ),
			);
		};

		// Reachability first: if the site is down, nothing else can be compared.
		if ( ! empty( $old['readable'] ) && empty( $new['readable'] ) ) {
			$add( 'critical', __( 'Site not reachable', 'site-audit-report' ), '', $new['reason'] );
			return $out;
		}
		if ( empty( $old['readable'] ) && ! empty( $new['readable'] ) && isset( $old['readable'] ) ) {
			$add( 'good', __( 'Site is reachable again', 'site-audit-report' ), $old['reason'] ?? '', '' );
		}
		if ( empty( $new['readable'] ) || empty( $old['readable'] ) ) {
			return $out;
		}

		if ( $old['https'] && ! $new['https'] ) {
			$add( 'critical', __( 'HTTPS stopped working', 'site-audit-report' ) );
		}
		if ( $old['final_url'] !== $new['final_url'] ) {
			$add( 'warning', __( 'Home page address changed', 'site-audit-report' ), $old['final_url'], $new['final_url'] );
		}
		if ( ! $old['noindex'] && $new['noindex'] ) {
			$add( 'critical', __( 'Home page set to noindex — it will drop out of Google', 'site-audit-report' ) );
		} elseif ( $old['noindex'] && ! $new['noindex'] ) {
			$add( 'good', __( 'noindex removed from the home page', 'site-audit-report' ) );
		}
		if ( ! $old['robots_all'] && $new['robots_all'] ) {
			$add( 'critical', __( 'robots.txt now blocks the whole site', 'site-audit-report' ) );
		} elseif ( $old['robots_all'] && ! $new['robots_all'] ) {
			$add( 'good', __( 'robots.txt no longer blocks the site', 'site-audit-report' ) );
		}

		// Certificate.
		$days = $new['cert'] ? (int) floor( ( $new['cert'] - time() ) / DAY_IN_SECONDS ) : null;
		if ( null !== $days && $days < 14 ) {
			/* translators: %d: days. */
			$add( 'critical', $days < 0 ? __( 'Certificate has expired', 'site-audit-report' ) : sprintf( _n( 'Certificate expires in %d day', 'Certificate expires in %d days', $days, 'site-audit-report' ), $days ), $old['cert'] ? gmdate( 'Y-m-d', $old['cert'] ) : '', gmdate( 'Y-m-d', $new['cert'] ) );
		} elseif ( $old['cert'] && $new['cert'] && $new['cert'] > $old['cert'] + DAY_IN_SECONDS ) {
			$add( 'good', __( 'Certificate renewed', 'site-audit-report' ), gmdate( 'Y-m-d', $old['cert'] ), gmdate( 'Y-m-d', $new['cert'] ) );
		}

		// Search appearance.
		foreach (
			array(
				'title'       => __( 'Home page title changed', 'site-audit-report' ),
				'description' => __( 'Meta description changed', 'site-audit-report' ),
				'h1'          => __( 'Main heading (H1) changed', 'site-audit-report' ),
			) as $key => $label
		) {
			if ( $old[ $key ] !== $new[ $key ] ) {
				$add( '' === $new[ $key ] ? 'warning' : 'info', '' === $new[ $key ] ? $label . ' — ' . __( 'now empty', 'site-audit-report' ) : $label, $old[ $key ], $new[ $key ] );
			}
		}
		if ( $old['canonical'] !== $new['canonical'] ) {
			$other = '' !== $new['canonical'] && Url_Guard::domain_key( $new['canonical'] ) !== Url_Guard::domain_key( $new['final_url'] );
			$add( $other ? 'critical' : 'warning', $other ? __( 'Canonical now points to another website', 'site-audit-report' ) : __( 'Canonical link changed', 'site-audit-report' ), $old['canonical'], $new['canonical'] );
		}
		if ( $old['lang'] !== $new['lang'] ) {
			$add( 'info', __( 'Page language changed', 'site-audit-report' ), $old['lang'], $new['lang'] );
		}
		if ( $old['words'] > 100 && $new['words'] < $old['words'] * 0.7 ) {
			/* translators: %d: percent. */
			$add( 'warning', sprintf( __( 'Home page text dropped by %d%%', 'site-audit-report' ), (int) round( 100 - 100 * $new['words'] / max( 1, $old['words'] ) ) ), $old['words'] . ' words', $new['words'] . ' words' );
		}
		if ( $old['sitemap'] && ! $new['sitemap'] ) {
			$add( 'warning', __( 'XML sitemap disappeared', 'site-audit-report' ) );
		} elseif ( $old['sitemap_n'] > 20 && $new['sitemap_n'] && abs( $new['sitemap_n'] - $old['sitemap_n'] ) > 0.2 * $old['sitemap_n'] ) {
			$add( 'info', __( 'Number of URLs in the sitemap changed a lot', 'site-audit-report' ), (string) $old['sitemap_n'], (string) $new['sitemap_n'] );
		}

		// Tracking and markup.
		foreach ( array_diff( $old['trackers'], $new['trackers'] ) as $t ) {
			/* translators: %s: tool. */
			$add( 'warning', sprintf( __( '%s no longer detected — analytics may have stopped', 'site-audit-report' ), $t ) );
		}
		foreach ( array_diff( $new['trackers'], $old['trackers'] ) as $t ) {
			/* translators: %s: tool. */
			$add( 'info', sprintf( __( '%s added', 'site-audit-report' ), $t ) );
		}
		if ( array_diff( $old['schema'], $new['schema'] ) ) {
			$add( 'warning', __( 'Structured data removed', 'site-audit-report' ), implode( ', ', array_diff( $old['schema'], $new['schema'] ) ), '' );
		}
		foreach ( array_diff( $old['headers'], $new['headers'] ) as $h ) {
			/* translators: %s: header. */
			$add( 'warning', sprintf( __( 'Security header removed: %s', 'site-audit-report' ), $h ) );
		}
		foreach ( array_diff( $new['headers'], $old['headers'] ) as $h ) {
			/* translators: %s: header. */
			$add( 'good', sprintf( __( 'Security header added: %s', 'site-audit-report' ), $h ) );
		}

		// WordPress.
		$ow = (array) ( $old['wp'] ?? array() );
		$nw = (array) ( $new['wp'] ?? array() );
		if ( $ow && $nw ) {
			if ( $ow['core'] && $nw['core'] && $ow['core'] !== $nw['core'] ) {
				$up = version_compare( $nw['core'], $ow['core'], '>' );
				$add( $up ? 'good' : 'warning', $up ? __( 'WordPress updated', 'site-audit-report' ) : __( 'WordPress version went down', 'site-audit-report' ), $ow['core'], $nw['core'] );
			}
			foreach ( array( 'themes' => __( 'Theme', 'site-audit-report' ), 'plugins' => __( 'Plugin', 'site-audit-report' ) ) as $key => $noun ) {
				$o = (array) $ow[ $key ];
				$n = (array) $nw[ $key ];
				foreach ( array_diff_key( $n, $o ) as $item ) {
					/* translators: 1: Theme or Plugin, 2: name. */
					$add( 'themes' === $key ? 'warning' : 'info', sprintf( __( '%1$s added: %2$s', 'site-audit-report' ), $noun, $item[0] ), '', $item[1] );
				}
				foreach ( array_diff_key( $o, $n ) as $item ) {
					/* translators: 1: Theme or Plugin, 2: name. */
					$add( 'themes' === $key ? 'warning' : 'info', sprintf( __( '%1$s no longer seen: %2$s', 'site-audit-report' ), $noun, $item[0] ), $item[1], '' );
				}
				foreach ( array_intersect_key( $n, $o ) as $slug => $item ) {
					$before = (string) $o[ $slug ][1];
					$after  = (string) $item[1];
					if ( '' !== $before && '' !== $after && $before !== $after ) {
						$up = version_compare( $after, $before, '>' );
						/* translators: 1: Theme or Plugin, 2: name. */
						$add( $up ? 'good' : 'warning', sprintf( $up ? __( '%1$s updated: %2$s', 'site-audit-report' ) : __( '%1$s version went down: %2$s', 'site-audit-report' ), $noun, $item[0] ), $before, $after );
					}
				}
			}
		} elseif ( $ow && ! $nw ) {
			$add( 'warning', __( 'WordPress no longer detected — the site may have been rebuilt or moved', 'site-audit-report' ) );
		}

		$order = self::severities();
		usort( $out, static fn( $a, $b ) => $order[ $a['severity'] ] <=> $order[ $b['severity'] ] );
		return $out;
	}

	/* ---------------------------------------------------------------- Runs */

	/**
	 * Checks one site now.
	 *
	 * @param array<string,mixed> $monitor Monitor.
	 * @return array<int,array<string,string>> New changes (empty for a baseline).
	 */
	public static function check( array $monitor ) {
		$old = json_decode( (string) $monitor['snapshot'], true );
		try {
			$new = self::snapshot( (string) $monitor['site_url'] );
		} catch ( Throwable $error ) {
			$new = array(
				'readable' => false,
				'reason'   => __( 'The check stopped with an error.', 'site-audit-report' ),
			);
		}
		$now     = gmdate( 'Y-m-d H:i:s' );
		$changes = is_array( $old ) ? self::diff( $old, $new ) : array();

		// While a site is down, keep the last good fingerprint to compare against when it returns.
		$store = $new;
		if ( empty( $new['readable'] ) && is_array( $old ) && ! empty( $old['readable'] ) ) {
			$store             = $old;
			$store['readable'] = false;
			$store['reason']   = $new['reason'] ?? '';
		}

		$log = (array) json_decode( (string) $monitor['changes'], true );
		foreach ( array_reverse( $changes ) as $c ) {
			array_unshift( $log, array_merge( array( 'at' => $now ), $c ) );
		}
		$data = array(
			'snapshot'   => wp_json_encode( $store ),
			'changes'    => wp_json_encode( array_slice( $log, 0, self::MAX_EVENTS ) ),
			'checked_at' => $now,
		);
		if ( $changes ) {
			$data['last_change_at'] = $now;
		}
		self::update( (int) $monitor['id'], $data );
		return $changes;
	}

	/**
	 * Checks sites that are due and emails one digest. Runs from the daily task.
	 *
	 * @param bool $notify Send the digest.
	 * @return array{checked:int,changed:int}
	 */
	public static function run_due( $notify = true ) {
		global $wpdb;
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 180 );
		}
		$started = time();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		$due    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE active = 1 AND ( checked_at IS NULL OR checked_at < %s ) ORDER BY checked_at ASC LIMIT 15', gmdate( 'Y-m-d H:i:s', time() - self::RECHECK_DAYS * DAY_IN_SECONDS + HOUR_IN_SECONDS ) ), ARRAY_A );
		$digest = array();
		$n      = 0;
		foreach ( (array) $due as $m ) {
			if ( time() - $started > self::TIME_BUDGET ) {
				break;
			}
			++$n;
			$changes = self::check( $m );
			$worth   = array_filter( $changes, static fn( $c ) => in_array( $c['severity'], array( 'critical', 'warning' ), true ) );
			if ( $worth ) {
				$digest[ $m['domain'] ] = $changes;
			}
		}
		if ( $digest && $notify ) {
			self::notify( $digest );
		}
		return array(
			'checked' => $n,
			'changed' => count( $digest ),
		);
	}

	/**
	 * One email covering every site with notable changes.
	 *
	 * @param array<string,array<int,array<string,string>>> $digest Domain => changes.
	 * @return void
	 */
	public static function notify( array $digest ) {
		$to    = (string) Settings::get( 'notify_email' );
		$to    = is_email( $to ) ? $to : (string) get_option( 'admin_email' );
		$marks = array(
			'critical' => '!!',
			'warning'  => '! ',
			'info'     => '· ',
			'good'     => '✓ ',
		);
		$crit  = 0;
		$lines = array();
		foreach ( $digest as $domain => $changes ) {
			$lines[] = strtoupper( $domain );
			foreach ( $changes as $c ) {
				$crit   += 'critical' === $c['severity'] ? 1 : 0;
				$lines[] = '  ' . $marks[ $c['severity'] ] . ' ' . $c['label'] . ( '' !== $c['before'] || '' !== $c['after'] ? ': ' . ( '' !== $c['before'] ? '"' . $c['before'] . '"' : '—' ) . ' → ' . ( '' !== $c['after'] ? '"' . $c['after'] . '"' : '—' ) : '' );
			}
			$lines[] = '';
		}
		$subject = sprintf(
			/* translators: 1: sites, 2: critical note. */
			_n( 'Change watch: %1$d client site changed%2$s', 'Change watch: %1$d client sites changed%2$s', count( $digest ), 'site-audit-report' ),
			count( $digest ),
			/* translators: %d: count. */
			$crit ? ' ' . sprintf( _n( '(%d urgent)', '(%d urgent)', $crit, 'site-audit-report' ), $crit ) : ''
		);
		$body = __( 'This week\'s check found these changes on sites you watch:', 'site-audit-report' ) . "\n\n" . implode( "\n", $lines ) . "\n" . __( 'Full history:', 'site-audit-report' ) . ' ' . admin_url( 'admin.php?page=site-audit-report-changes' );
		wp_mail( $to, $subject, $body );
	}
}
