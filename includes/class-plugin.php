<?php
/**
 * Wires the plugin's parts to WordPress.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class Plugin {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		Installer::maybe_upgrade();

		add_action( Queue::HOOK, array( Queue::class, 'run' ) );
		add_action( 'wp_ajax_nopriv_sar_worker', array( Queue::class, 'handle_loopback' ) );
		add_action( 'wp_ajax_sar_worker', array( Queue::class, 'handle_loopback' ) );
		add_action( Installer::MAINTENANCE_HOOK, array( Installer::class, 'maintenance' ) );

		Public_Form::register();
		Service_Bay::register();
		Seo_Inspector::register();
		Vuln_Watch::register();
		Report_Route::register();
		Privacy::register();

		if ( is_admin() ) {
			Admin\Admin::register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'site-audit-report run-queue', array( Queue::class, 'cli_run' ) );
		}
	}

	/**
	 * Names the server features scanning cannot work without.
	 *
	 * @return string[] Missing PHP extensions.
	 */
	public static function missing_requirements() {
		$missing = array();
		if ( ! class_exists( '\DOMDocument' ) ) {
			$missing[] = 'dom';
		}
		if ( ! function_exists( 'curl_init' ) ) {
			$missing[] = 'curl';
		}
		return $missing;
	}
}
