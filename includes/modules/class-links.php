<?php
/**
 * Checks a limited sample of links for dead ends.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report\Modules;

use Site_Audit_Report\Check;
use Site_Audit_Report\Module;
use Site_Audit_Report\Scan_Context;
use Site_Audit_Report\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Broken link sample.
 */
final class Links implements Module {

	const CATEGORY    = 'technical';
	const TIME_BUDGET = 40;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'links';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Checking a sample of links', 'site-audit-report' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Scan_Context $context Context.
	 */
	public function run( Scan_Context $context ) {
		$label = __( 'Broken links (sample)', 'site-audit-report' );

		if ( ! $context->readable() ) {
			return array( Check::not_checked( self::CATEGORY, 'broken_links', $label, 4, $context->unreadable_reason() ) );
		}

		$max = (int) Settings::get( 'max_links' );
		if ( 0 === $max ) {
			return array( Check::not_checked( self::CATEGORY, 'broken_links', $label, 4, __( 'Link checking is switched off in the settings.', 'site-audit-report' ) ) );
		}

		$links = array();
		foreach ( $context->pages() as $page ) {
			foreach ( array_merge( $page['internal_links'], $page['external_links'] ) as $link ) {
				$links[ $link['url'] ] = true;
			}
		}
		$total  = count( $links );
		$sample = array_slice( array_keys( $links ), 0, $max );

		$broken     = array();
		$unverified = 0;
		$checked    = 0;
		$started    = time();

		foreach ( $sample as $url ) {
			if ( time() - $started > self::TIME_BUDGET ) {
				break;
			}
			$outcome = $this->check_link( $url, $context );
			if ( null === $outcome ) {
				++$unverified;
				continue;
			}
			++$checked;
			if ( false !== $outcome ) {
				$broken[] = array(
					'text' => $url . ' (' . $outcome . ')',
					'url'  => $url,
				);
			}
		}

		if ( 0 === $checked ) {
			return array(
				Check::not_checked( self::CATEGORY, 'broken_links', $label, 4, $total ? __( 'None of the sampled links gave a clear answer, so nothing could be measured.', 'site-audit-report' ) : __( 'No links were found in the HTML of the pages checked.', 'site-audit-report' ) ),
			);
		}

		/* translators: 1: links checked, 2: links found, 3: links with no clear answer. */
		$coverage = sprintf( __( 'Checked %1$d of %2$d links found. %3$d more gave no clear answer (blocked or timed out) and are not counted.', 'site-audit-report' ), $checked, $total, $unverified );

		if ( ! $broken ) {
			return array( Check::make( Check::PASS, self::CATEGORY, 'broken_links', $label, 4, __( 'No broken links in the sample.', 'site-audit-report' ) . ' ' . $coverage ) );
		}

		$share = count( $broken ) / $checked;
		return array(
			Check::make(
				$share > 0.1 ? Check::FAIL : Check::WARN,
				self::CATEGORY,
				'broken_links',
				$label,
				4,
				/* translators: %d: number of links. */
				sprintf( _n( '%d broken link found.', '%d broken links found.', count( $broken ), 'site-audit-report' ), count( $broken ) ) . ' ' . $coverage,
				array(
					'fix'    => __( 'Update or remove these links. Because this is a sample, run a full crawl after fixing them.', 'site-audit-report' ),
					'effort' => 'medium',
					'items'  => array_slice( $broken, 0, 15 ),
				)
			),
		);
	}

	/**
	 * Checks one link.
	 *
	 * @param string       $url     URL.
	 * @param Scan_Context $context Context.
	 * @return string|false|null Reason if broken, false if fine, null if unclear.
	 */
	private function check_link( $url, Scan_Context $context ) {
		$options  = array(
			'method'    => 'HEAD',
			'timeout'   => 6,
			'max_bytes' => 4096,
		);
		$response = $context->http->fetch( $url, $options );

		// Many servers answer HEAD badly, so confirm anything that looks wrong with GET.
		if ( $response['code'] >= 400 || 'sar_connect_failed' === $response['error'] ) {
			$options['method']    = 'GET';
			$options['max_bytes'] = 16384;
			$response             = $context->http->fetch( $url, $options );
		}

		if ( 'sar_dns_failed' === $response['error'] ) {
			return __( 'domain not found', 'site-audit-report' );
		}
		if ( $response['error'] ) {
			return null;
		}

		$code = (int) $response['code'];
		if ( in_array( $code, array( 404, 410 ), true ) ) {
			return 'HTTP ' . $code;
		}
		// Bot walls (LinkedIn's 999, firewall 403s) and short-lived server errors do not prove a link is dead.
		if ( $code >= 400 ) {
			return null;
		}
		return false;
	}
}
