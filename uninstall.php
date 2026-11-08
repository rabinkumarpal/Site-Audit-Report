<?php
/**
 * Removes every table, option, transient and scheduled event the plugin created.
 *
 * @package Site_Audit_Report
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the plugin's data from the current site.
 *
 * @return void
 */
function sar_uninstall_site() {
	global $wpdb;

	foreach ( array( 'sar_audits', 'sar_rate_limits', 'sar_inventory', 'sar_alerts' ) as $table ) {
		$name = $wpdb->prefix . $table;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names cannot be placeholders.
		$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
	}

	delete_option( 'sar_settings' );
	delete_option( 'sar_db_version' );
	delete_option( 'sar_kev_cves' );
	delete_option( 'sar_watch_last' );

	wp_unschedule_hook( 'sar_run_queue' );
	wp_unschedule_hook( 'sar_daily_maintenance' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
		switch_to_blog( $site_id );
		sar_uninstall_site();
		restore_current_blog();
	}
} else {
	sar_uninstall_site();
}
