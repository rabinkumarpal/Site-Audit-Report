<?php
/**
 * Contact details, social profiles, analytics tools and signs of activity.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report\Modules;

use Site_Audit_Report\Check;
use Site_Audit_Report\Crawler;
use Site_Audit_Report\Module;
use Site_Audit_Report\Scan_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Marketing presence checks.
 */
final class Presence implements Module {

	const CATEGORY = 'presence';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'presence';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Looking for social profiles, analytics and recent activity', 'site-audit-report' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Scan_Context $context Context.
	 */
	public function run( Scan_Context $context ) {
		if ( ! $context->readable() ) {
			return Check::all_not_checked(
				self::CATEGORY,
				array(
					'contact'   => array( __( 'Contact details', 'site-audit-report' ), 3 ),
					'social'    => array( __( 'Social profiles', 'site-audit-report' ), 2 ),
					'analytics' => array( __( 'Analytics tools', 'site-audit-report' ), 3 ),
					'activity'  => array( __( 'Signs of recent activity', 'site-audit-report' ), 2 ),
				),
				$context->unreadable_reason()
			);
		}

		return array(
			$this->contact( $context ),
			$this->social( $context ),
			$this->analytics( $context ),
			$this->activity( $context ),
		);
	}

	/**
	 * Contact details.
	 *
	 * @param Scan_Context $context Context.
	 * @return array<string,mixed>
	 */
	private function contact( Scan_Context $context ) {
		$label = __( 'Contact details', 'site-audit-report' );
		$tel   = 0;
		$mail  = 0;
		$forms = 0;
		foreach ( $context->pages() as $page ) {
			$tel   += count( $page['tel_links'] );
			$mail  += $page['mail_links'];
			$forms += $page['forms'];
		}
		$home_tel = count( $context->home()['tel_links'] );
		$found    = array();
		if ( $tel ) {
			$found[] = __( 'clickable phone number', 'site-audit-report' );
		}
		if ( $mail ) {
			$found[] = __( 'email link', 'site-audit-report' );
		}
		if ( $forms ) {
			$found[] = __( 'form', 'site-audit-report' );
		}
		if ( ! $found ) {
			return Check::make(
				Check::FAIL,
				self::CATEGORY,
				'contact',
				$label,
				3,
				__( 'No clickable phone number, email link or form was found on the pages checked.', 'site-audit-report' ),
				array(
					'fix'    => __( 'Make it easy to get in touch: add a click-to-call number, an email link or a short form on the home and contact pages.', 'site-audit-report' ),
					'effort' => 'low',
				)
			);
		}
		/* translators: %s: list of contact methods. */
		$evidence = sprintf( __( 'Found: %s.', 'site-audit-report' ), implode( ', ', $found ) );
		if ( ! $tel && ! $home_tel ) {
			return Check::make(
				Check::WARN,
				self::CATEGORY,
				'contact',
				$label,
				3,
				$evidence . ' ' . __( 'There is no click-to-call phone link.', 'site-audit-report' ),
				array(
					'fix'    => __( 'If you take calls, add a tel: link so phone visitors can call in one tap.', 'site-audit-report' ),
					'effort' => 'low',
				)
			);
		}
		return Check::make( Check::PASS, self::CATEGORY, 'contact', $label, 3, $evidence );
	}

	/**
	 * Social profiles.
	 *
	 * @param Scan_Context $context Context.
	 * @return array<string,mixed>
	 */
	private function social( Scan_Context $context ) {
		$label    = __( 'Social profiles', 'site-audit-report' );
		$profiles = array();
		foreach ( $context->pages() as $page ) {
			$profiles = array_merge( $page['social'], $profiles );
		}
		$items = array();
		foreach ( $profiles as $name => $url ) {
			$items[] = array(
				'text' => $name . ': ' . $url,
				'url'  => $url,
			);
		}
		if ( ! $items ) {
			return Check::make(
				Check::WARN,
				self::CATEGORY,
				'social',
				$label,
				2,
				__( 'No links to social profiles were found. They may exist without being linked from the site.', 'site-audit-report' ),
				array(
					'fix'    => __( 'Link to the profiles you keep active, and list them under sameAs in your schema.', 'site-audit-report' ),
					'effort' => 'low',
				)
			);
		}
		return Check::make(
			Check::PASS,
			self::CATEGORY,
			'social',
			$label,
			2,
			/* translators: %d: number of networks. */
			sprintf( _n( 'Links to %d social network found. Activity on the profiles was not checked.', 'Links to %d social networks found. Activity on the profiles was not checked.', count( $items ), 'site-audit-report' ), count( $items ) ),
			array( 'items' => $items )
		);
	}

	/**
	 * Analytics tools.
	 *
	 * @param Scan_Context $context Context.
	 * @return array<string,mixed>
	 */
	private function analytics( Scan_Context $context ) {
		$label = __( 'Analytics tools', 'site-audit-report' );
		$tools = array();
		foreach ( $context->pages() as $page ) {
			$tools = array_merge( $tools, $page['trackers'] );
		}
		$tools = array_values( array_unique( $tools ) );

		$note = __( 'Tools loaded only after cookie consent, or from inside a tag manager, are not visible to this scan.', 'site-audit-report' );

		if ( ! $tools ) {
			return Check::make(
				Check::WARN,
				self::CATEGORY,
				'analytics',
				$label,
				3,
				__( 'No analytics tool was found in the page source.', 'site-audit-report' ) . ' ' . $note,
				array(
					'fix'    => __( 'Set up analytics (Google Analytics 4 or a privacy-friendly alternative) and track form submissions and calls, so you can see what brings in leads.', 'site-audit-report' ),
					'effort' => 'low',
				)
			);
		}
		$retired = in_array( 'Universal Analytics (retired)', $tools, true ) && ! in_array( 'Google Analytics 4', $tools, true ) && ! in_array( 'Google Tag Manager', $tools, true );
		/* translators: %s: tool names. */
		$evidence = sprintf( __( 'Detected: %s.', 'site-audit-report' ), implode( ', ', $tools ) ) . ' ' . $note;
		if ( $retired ) {
			return Check::make(
				Check::WARN,
				self::CATEGORY,
				'analytics',
				$label,
				3,
				$evidence,
				array(
					'fix'    => __( 'Universal Analytics stopped collecting data in 2023. Move to Google Analytics 4 or another current tool.', 'site-audit-report' ),
					'effort' => 'low',
				)
			);
		}
		return Check::make( Check::PASS, self::CATEGORY, 'analytics', $label, 3, $evidence );
	}

	/**
	 * Signs of recent activity.
	 *
	 * @param Scan_Context $context Context.
	 * @return array<string,mixed>
	 */
	private function activity( Scan_Context $context ) {
		$label = __( 'Signs of recent activity', 'site-audit-report' );
		$data  = $context->data;
		$dates = array();
		$items = array();

		$sitemap_date = (string) ( $data['sitemap']['newest'] ?? '' );
		if ( '' !== $sitemap_date ) {
			$dates[] = $sitemap_date;
			/* translators: %s: date. */
			$items[] = sprintf( __( 'Newest sitemap date: %s', 'site-audit-report' ), $sitemap_date );
		}
		$feed_date = Crawler::newest( array( (string) $data['feed_date'] ) );
		if ( '' !== $feed_date ) {
			$dates[] = $feed_date;
			/* translators: %s: date. */
			$items[] = sprintf( __( 'Newest feed item: %s', 'site-audit-report' ), $feed_date );
		}
		$page_dates = array();
		foreach ( $context->pages() as $page ) {
			$page_dates = array_merge( $page_dates, $page['dates'] );
		}
		$page_date = Crawler::newest( $page_dates );
		if ( '' !== $page_date ) {
			$dates[] = $page_date;
			/* translators: %s: date. */
			$items[] = sprintf( __( 'Newest date on a page: %s', 'site-audit-report' ), $page_date );
		}
		$year = (int) ( $context->home()['copyright_year'] ?? 0 );
		if ( $year ) {
			/* translators: %d: year. */
			$items[] = sprintf( __( 'Copyright year in the footer: %d', 'site-audit-report' ), $year );
		}

		$newest = Crawler::newest( $dates );
		if ( '' === $newest ) {
			return Check::not_checked( self::CATEGORY, 'activity', $label, 2, __( 'The site publishes no dates in its sitemap, feed or page data, so recent activity could not be measured.', 'site-audit-report' ) );
		}

		$days = (int) floor( ( time() - strtotime( $newest ) ) / DAY_IN_SECONDS );
		/* translators: 1: date, 2: number of days. */
		$evidence = sprintf( __( 'Most recent update signal: %1$s (%2$d days before the scan).', 'site-audit-report' ), $newest, max( 0, $days ) );
		$extra    = array( 'items' => $items );

		if ( $days <= 180 ) {
			return Check::make( Check::PASS, self::CATEGORY, 'activity', $label, 2, $evidence, $extra );
		}
		$extra['fix']    = __( 'Publish or update something useful every month or two, such as a case study, service update or answer to a common question.', 'site-audit-report' );
		$extra['effort'] = 'medium';
		return Check::make( $days <= 365 ? Check::WARN : Check::FAIL, self::CATEGORY, 'activity', $label, 2, $evidence, $extra );
	}
}
