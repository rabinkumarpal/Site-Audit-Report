<?php
/**
 * Accessibility section of the full audit, from the home page's HTML.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report\Modules;

use DOMDocument;
use DOMXPath;
use Site_Audit_Report\A11y;
use Site_Audit_Report\Check;
use Site_Audit_Report\Module;
use Site_Audit_Report\Scan_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Accessibility module.
 */
final class Accessibility implements Module {

	const CATEGORY = 'accessibility';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'accessibility';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Checking accessibility basics', 'site-audit-report' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Scan_Context $context Context.
	 */
	public function run( Scan_Context $context ) {
		if ( ! $context->readable() ) {
			return array( Check::not_checked( self::CATEGORY, 'a11y', __( 'Accessibility basics', 'site-audit-report' ), 3, $context->unreadable_reason() ) );
		}
		$res = $context->http->fetch( (string) $context->data['final_url'], array( 'timeout' => 15 ) );
		if ( empty( $res['ok'] ) ) {
			return array( Check::not_checked( self::CATEGORY, 'a11y', __( 'Accessibility basics', 'site-audit-report' ), 3, __( 'The home page could not be read a second time.', 'site-audit-report' ) ) );
		}
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . (string) $res['body'], LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$result = A11y::analyze( new DOMXPath( $dom ) );
		$checks = array();
		foreach ( $result['checks'] as $row ) {
			$checks[] = Check::make(
				$row['status'],
				self::CATEGORY,
				'a11y_' . $row['id'],
				$row['label'] . ' (WCAG ' . $row['wcag'] . ')',
				(int) $row['weight'],
				$row['detail'],
				array(
					'fix'    => $row['fix'],
					'effort' => 'low',
					'items'  => $row['samples'],
				)
			);
		}
		$checks[] = Check::make( Check::INFO, self::CATEGORY, 'a11y_limits', __( 'What this section cannot see', 'site-audit-report' ), 1, $result['limits'] );
		return $checks;
	}
}
