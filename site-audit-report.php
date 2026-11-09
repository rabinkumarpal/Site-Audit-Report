<?php
/**
 * Plugin Name:       Site Audit Report
 * Plugin URI:        https://rabinpal.com
 * Description:       Scan any public website and business, then produce a scored audit report with prioritised fixes. Includes a lead form (shortcode and block), a WordPress vulnerability Security Check with a priced fix plan and booking, private report links and an admin workspace.
 * Version:           1.6.2
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * Author:            Rabin Pal
 * Author URI:        https://rabinpal.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       site-audit-report
 *
 * @package Site_Audit_Report
 */

defined( 'ABSPATH' ) || exit;

define( 'SAR_VERSION', '1.6.2' );
define( 'SAR_FILE', __FILE__ );
define( 'SAR_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAR_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Site_Audit_Report\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$parts    = explode( '\\', strtolower( str_replace( '_', '-', $relative ) ) );
		$file     = array_pop( $parts );
		$dir      = SAR_DIR . 'includes/' . ( $parts ? implode( '/', $parts ) . '/' : '' );
		foreach ( array( 'class-', 'interface-' ) as $type ) {
			if ( is_readable( $dir . $type . $file . '.php' ) ) {
				require_once $dir . $type . $file . '.php';
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'Site_Audit_Report\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Site_Audit_Report\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Site_Audit_Report\\Plugin', 'boot' ) );
