<?php
/**
 * The extension point for audit sections.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * An audit section. Add one through the `sar_modules` filter; see readme.txt.
 */
interface Module {

	/**
	 * Stable, unique identifier.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Short label shown while the scan runs.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Runs the module. Long network work is fine: the queue saves after each
	 * module and picks up in a new worker when its time budget runs out.
	 *
	 * @param Scan_Context $context Crawled data and the guarded HTTP client.
	 * @return array<int,array<string,mixed>> Checks made with the Check class.
	 */
	public function run( Scan_Context $context );
}
