<?php
/**
 * Database tables, activation and scheduled maintenance.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Handles install, upgrade and daily housekeeping.
 */
final class Installer {

	const DB_VERSION       = '5';
	const MAINTENANCE_HOOK = 'sar_daily_maintenance';

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		$was = get_option( 'sar_db_version' );
		update_option( 'sar_db_version', self::DB_VERSION, false );
		if ( $was && version_compare( (string) $was, '2', '<' ) ) {
			// Start watching sites that were scanned before the watch existed.
			Vuln_Watch::backfill();
		}
		if ( $was && version_compare( (string) $was, '5', '<' ) ) {
			// Score existing leads so "Call next" is useful straight away.
			Lead_Priority::recalculate_all();
		}
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		if ( ! wp_next_scheduled( self::MAINTENANCE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::MAINTENANCE_HOOK );
		}
	}

	/**
	 * Deactivation callback. Stored data stays until the plugin is deleted.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::MAINTENANCE_HOOK );
		wp_unschedule_hook( Queue::HOOK );
	}

	/**
	 * Runs table changes after a plugin update.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'sar_db_version' ) !== self::DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Creates or updates the plugin's tables.
	 *
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$audits  = $wpdb->prefix . 'sar_audits';
		$limits  = $wpdb->prefix . 'sar_rate_limits';
		$inv     = $wpdb->prefix . 'sar_inventory';
		$alerts  = $wpdb->prefix . 'sar_alerts';
		$badges  = $wpdb->prefix . 'sar_badges';
		$mon     = $wpdb->prefix . 'sar_monitors';
		$proofs  = $wpdb->prefix . 'sar_proofs';

		dbDelta(
			"CREATE TABLE {$audits} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token char(64) NOT NULL,
				site_url varchar(2048) NOT NULL,
				domain varchar(190) NOT NULL,
				business_name varchar(191) NOT NULL DEFAULT '',
				email varchar(191) NOT NULL DEFAULT '',
				source varchar(20) NOT NULL DEFAULT 'form',
				status varchar(20) NOT NULL DEFAULT 'queued',
				stage varchar(40) NOT NULL DEFAULT '',
				progress tinyint(3) unsigned NOT NULL DEFAULT 0,
				state longtext NULL,
				result longtext NULL,
				score tinyint(3) unsigned NULL,
				previous_score tinyint(3) unsigned NULL,
				previous_result longtext NULL,
				previous_at datetime NULL,
				priority tinyint(3) unsigned NULL,
				priority_why varchar(255) NOT NULL DEFAULT '',
				lead_status varchar(20) NOT NULL DEFAULT 'new',
				notes longtext NULL,
				consent_text text NULL,
				consent_at datetime NULL,
				ip_hash char(64) NOT NULL DEFAULT '',
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				error varchar(255) NOT NULL DEFAULT '',
				locked_at datetime NULL,
				email_sent_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				completed_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token (token),
				KEY domain (domain),
				KEY email (email),
				KEY status (status),
				KEY created_at (created_at),
				KEY priority (priority)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$limits} (
				bucket varchar(190) NOT NULL,
				window_start int(10) unsigned NOT NULL,
				hits int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (bucket,window_start),
				KEY window_start (window_start)
			) {$charset};"
		);

		// What each scanned WordPress site showed: re-matched daily against new vulnerabilities.
		dbDelta(
			"CREATE TABLE {$inv} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				audit_id bigint(20) unsigned NOT NULL,
				domain varchar(190) NOT NULL,
				type varchar(10) NOT NULL,
				slug varchar(100) NOT NULL,
				name varchar(120) NOT NULL DEFAULT '',
				version varchar(40) NOT NULL,
				known longtext NULL,
				kev text NULL,
				created_at datetime NOT NULL,
				checked_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY audit_item (audit_id,type,slug),
				KEY domain (domain),
				KEY lookup (type,slug,version),
				KEY checked_at (checked_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$alerts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				audit_id bigint(20) unsigned NOT NULL,
				domain varchar(190) NOT NULL,
				type varchar(10) NOT NULL,
				slug varchar(100) NOT NULL,
				name varchar(120) NOT NULL DEFAULT '',
				version varchar(40) NOT NULL,
				vuln_id varchar(64) NOT NULL,
				vuln_name varchar(191) NOT NULL DEFAULT '',
				severity varchar(10) NOT NULL DEFAULT 'unknown',
				score decimal(3,1) NULL,
				kev tinyint(1) NOT NULL DEFAULT 0,
				cves varchar(255) NOT NULL DEFAULT '',
				fixed varchar(40) NOT NULL DEFAULT '',
				link varchar(500) NOT NULL DEFAULT '',
				published date NULL,
				reason varchar(10) NOT NULL DEFAULT 'new',
				status varchar(20) NOT NULL DEFAULT 'new',
				notified_at datetime NULL,
				lead_emailed_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY alert (audit_id,type,slug,vuln_id,reason),
				KEY status (status),
				KEY vuln_id (vuln_id)
			) {$charset};"
		);

		// Trust badges: a public, self-checking "secured by" badge per client site.
		dbDelta(
			"CREATE TABLE {$badges} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token char(24) NOT NULL,
				audit_id bigint(20) unsigned NOT NULL DEFAULT 0,
				site_url varchar(2048) NOT NULL,
				domain varchar(190) NOT NULL,
				label varchar(80) NOT NULL DEFAULT '',
				active tinyint(1) NOT NULL DEFAULT 1,
				state varchar(10) NOT NULL DEFAULT 'pending',
				issues longtext NULL,
				history longtext NULL,
				views bigint(20) unsigned NOT NULL DEFAULT 0,
				checked_at datetime NULL,
				verified_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token (token),
				KEY domain (domain),
				KEY checked_at (checked_at)
			) {$charset};"
		);

		// Change watch: a weekly fingerprint per client site and the history of what changed.
		dbDelta(
			"CREATE TABLE {$mon} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				site_url varchar(2048) NOT NULL,
				domain varchar(190) NOT NULL,
				audit_id bigint(20) unsigned NOT NULL DEFAULT 0,
				label varchar(120) NOT NULL DEFAULT '',
				active tinyint(1) NOT NULL DEFAULT 1,
				snapshot longtext NULL,
				changes longtext NULL,
				checked_at datetime NULL,
				last_change_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY domain (domain),
				KEY checked_at (checked_at)
			) {$charset};"
		);

		// Before-and-after proof pages. The data is a frozen copy, so a published case study never changes.
		dbDelta(
			"CREATE TABLE {$proofs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token char(24) NOT NULL,
				audit_id bigint(20) unsigned NOT NULL DEFAULT 0,
				domain varchar(190) NOT NULL,
				title varchar(191) NOT NULL DEFAULT '',
				client_label varchar(191) NOT NULL DEFAULT '',
				anonymize tinyint(1) NOT NULL DEFAULT 0,
				quote text NULL,
				quote_by varchar(191) NOT NULL DEFAULT '',
				indexable tinyint(1) NOT NULL DEFAULT 0,
				active tinyint(1) NOT NULL DEFAULT 1,
				data longtext NULL,
				views bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token (token),
				KEY domain (domain)
			) {$charset};"
		);
	}

	/**
	 * Daily housekeeping: retention, old rate-limit rows, stuck scans.
	 *
	 * @return void
	 */
	public static function maintenance() {
		$days = (int) Settings::get( 'retention_days' );
		if ( $days > 0 ) {
			Audits::delete_older_than( $days );
		}
		Rate_Limiter::purge();
		Queue::nudge();
		Vuln_Watch::run();
		Badges::run_due();
		Monitors::run_due();
		Lead_Priority::recalculate_all();
	}
}
