<?php
/**
 * Builds check results in the shape reports and scoring expect.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Check factory.
 */
final class Check {

	const PASS        = 'pass';
	const WARN        = 'warn';
	const FAIL        = 'fail';
	const INFO        = 'info';
	const NOT_CHECKED = 'not_checked';

	/**
	 * Creates a measured check.
	 *
	 * @param string              $status   One of the status constants.
	 * @param string              $category Category ID.
	 * @param string              $id       Check ID.
	 * @param string              $label    What was checked.
	 * @param int                 $weight   Importance from 1 to 5.
	 * @param string              $evidence What was found.
	 * @param array<string,mixed> $extra    fix, effort (low|medium|high), items, score (0-100).
	 * @return array<string,mixed>
	 */
	public static function make( $status, $category, $id, $label, $weight, $evidence, array $extra = array() ) {
		return array_merge(
			array(
				'id'       => $id,
				'category' => $category,
				'label'    => $label,
				'status'   => $status,
				'weight'   => $weight,
				'evidence' => $evidence,
				'fix'      => '',
				'effort'   => 'medium',
				'items'    => array(),
				'score'    => null,
			),
			$extra
		);
	}

	/**
	 * Creates a check that could not be measured. It never counts toward a score.
	 *
	 * @param string $category Category ID.
	 * @param string $id       Check ID.
	 * @param string $label    What would have been checked.
	 * @param int    $weight   Importance from 1 to 5.
	 * @param string $reason   Why it was not checked.
	 * @return array<string,mixed>
	 */
	public static function not_checked( $category, $id, $label, $weight, $reason ) {
		return self::make( self::NOT_CHECKED, $category, $id, $label, $weight, $reason );
	}

	/**
	 * Marks a list of id => [label, weight] checks as not checked.
	 *
	 * @param string              $category Category ID.
	 * @param array<string,array> $catalog  Checks.
	 * @param string              $reason   Reason.
	 * @return array<int,array<string,mixed>>
	 */
	public static function all_not_checked( $category, array $catalog, $reason ) {
		$checks = array();
		foreach ( $catalog as $id => $meta ) {
			$checks[] = self::not_checked( $category, $id, $meta[0], $meta[1], $reason );
		}
		return $checks;
	}
}
