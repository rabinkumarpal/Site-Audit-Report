<?php
/**
 * Speed, Core Web Vitals and accessibility from Google PageSpeed Insights.
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
 * PageSpeed Insights for one device type.
 */
final class Pagespeed implements Module {

	const CATEGORY = 'performance';
	const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * Either mobile or desktop.
	 *
	 * @var string
	 */
	private string $strategy;

	/**
	 * Constructor.
	 *
	 * @param string $strategy mobile or desktop.
	 */
	public function __construct( $strategy ) {
		$this->strategy = 'desktop' === $strategy ? 'desktop' : 'mobile';
	}

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'pagespeed_' . $this->strategy;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'mobile' === $this->strategy
			? __( 'Measuring speed on mobile with Google PageSpeed Insights', 'site-audit-report' )
			: __( 'Measuring speed on desktop with Google PageSpeed Insights', 'site-audit-report' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Scan_Context $context Context.
	 */
	public function run( Scan_Context $context ) {
		$url      = (string) ( $context->data['final_url'] ?? $context->audit['site_url'] );
		$response = $this->request( $url );

		if ( is_string( $response ) ) {
			return $this->all_not_checked( $response );
		}

		$categories = (array) ( $response['lighthouseResult']['categories'] ?? array() );
		$audits     = (array) ( $response['lighthouseResult']['audits'] ?? array() );

		if ( ! isset( $categories['performance']['score'] ) ) {
			return $this->all_not_checked( __( 'PageSpeed Insights did not return a performance measurement for this site.', 'site-audit-report' ) );
		}

		$checks   = array();
		$checks[] = $this->performance( $categories, $audits );
		if ( 'mobile' === $this->strategy ) {
			$checks[] = $this->field_vitals( $response );
			$checks[] = $this->accessibility( $categories, $audits );
			$checks[] = $this->best_practices( $categories );
		}
		return $checks;
	}

	/**
	 * Calls the API.
	 *
	 * @param string $url Site URL.
	 * @return array<string,mixed>|string Decoded response, or a reason it failed.
	 */
	private function request( $url ) {
		$key   = (string) Settings::get( 'psi_api_key' );
		$query = 'url=' . rawurlencode( $url ) . '&strategy=' . $this->strategy;
		foreach ( array( 'performance', 'accessibility', 'best-practices' ) as $category ) {
			$query .= '&category=' . $category;
		}
		if ( '' !== $key ) {
			$query .= '&key=' . rawurlencode( $key );
		}

		$response = wp_remote_get(
			self::ENDPOINT . '?' . $query,
			array(
				'timeout'             => 90,
				'limit_response_size' => 16 * MB_IN_BYTES,
			)
		);

		// Messages are written here rather than passed on, so a key can never reach a report.
		if ( is_wp_error( $response ) ) {
			return __( 'Google PageSpeed Insights did not respond in time.', 'site-audit-report' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $code ) {
			return '' === $key
				? __( 'Google\'s shared quota for PageSpeed Insights was used up, and no API key is set for this tool. Adding a free key to the settings removes this limit.', 'site-audit-report' )
				: __( 'The daily quota on this tool\'s PageSpeed Insights API key was used up.', 'site-audit-report' );
		}
		if ( in_array( $code, array( 400, 401, 403 ), true ) && '' !== $key && false !== stripos( (string) wp_remote_retrieve_body( $response ), 'api key' ) ) {
			return __( 'Google rejected the PageSpeed Insights API key set for this tool.', 'site-audit-report' );
		}
		if ( 200 !== $code ) {
			return __( 'PageSpeed Insights could not load the site. Google\'s test may have been blocked or timed out.', 'site-audit-report' );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : __( 'PageSpeed Insights returned an unreadable response.', 'site-audit-report' );
	}

	/**
	 * Marks this module's checks as not measured.
	 *
	 * @param string $reason Reason.
	 * @return array<int,array<string,mixed>>
	 */
	private function all_not_checked( $reason ) {
		$catalog = array( $this->perf_id() => array( $this->perf_label(), 'mobile' === $this->strategy ? 5 : 3 ) );
		if ( 'mobile' === $this->strategy ) {
			$catalog['core_web_vitals'] = array( __( 'Core Web Vitals (real visitors)', 'site-audit-report' ), 4 );
			$catalog['accessibility']   = array( __( 'Accessibility score', 'site-audit-report' ), 3 );
			$catalog['best_practices']  = array( __( 'Best-practices score', 'site-audit-report' ), 1 );
		}
		return Check::all_not_checked( self::CATEGORY, $catalog, $reason );
	}

	/**
	 * Check ID for the speed score.
	 *
	 * @return string
	 */
	private function perf_id() {
		return 'performance_' . $this->strategy;
	}

	/**
	 * Label for the speed score.
	 *
	 * @return string
	 */
	private function perf_label() {
		return 'mobile' === $this->strategy ? __( 'Mobile speed score', 'site-audit-report' ) : __( 'Desktop speed score', 'site-audit-report' );
	}

	/**
	 * Lighthouse speed score with lab metrics and top opportunities.
	 *
	 * @param array<string,mixed> $categories Lighthouse categories.
	 * @param array<string,mixed> $audits     Lighthouse audits.
	 * @return array<string,mixed>
	 */
	private function performance( array $categories, array $audits ) {
		$score = (int) round( 100 * (float) $categories['performance']['score'] );

		$items = array();
		foreach (
			array(
				'first-contentful-paint'   => __( 'First Contentful Paint', 'site-audit-report' ),
				'largest-contentful-paint' => __( 'Largest Contentful Paint', 'site-audit-report' ),
				'total-blocking-time'      => __( 'Total Blocking Time', 'site-audit-report' ),
				'cumulative-layout-shift'  => __( 'Cumulative Layout Shift', 'site-audit-report' ),
				'speed-index'              => __( 'Speed Index', 'site-audit-report' ),
			) as $id => $name
		) {
			if ( ! empty( $audits[ $id ]['displayValue'] ) ) {
				$items[] = $name . ': ' . wp_strip_all_tags( (string) $audits[ $id ]['displayValue'] );
			}
		}

		$fixes = array();
		foreach ( $audits as $audit ) {
			if ( ! is_array( $audit ) || ! isset( $audit['score'] ) || $audit['score'] >= 0.9 || empty( $audit['title'] ) ) {
				continue;
			}
			if ( 'opportunity' === ( $audit['details']['type'] ?? '' ) ) {
				$fixes[ wp_strip_all_tags( (string) $audit['title'] ) ] = (float) ( $audit['details']['overallSavingsMs'] ?? 0 );
			}
		}
		arsort( $fixes );
		foreach ( array_slice( array_keys( $fixes ), 0, 5 ) as $title ) {
			/* translators: %s: name of a suggestion from Google. */
			$items[] = sprintf( __( 'Google suggests: %s', 'site-audit-report' ), $title );
		}

		/* translators: 1: score, 2: device type (mobile or desktop). */
		$evidence = sprintf( __( 'Google lab test score: %1$d out of 100 on %2$s.', 'site-audit-report' ), $score, $this->strategy );
		$extra    = array(
			'score' => $score,
			'items' => $items,
		);
		if ( $score < 90 ) {
			$extra['fix']    = __( 'Compress and correctly size images, remove unused scripts and plugins, and turn on caching. Start with the suggestions listed here.', 'site-audit-report' );
			$extra['effort'] = 'high';
		}
		return Check::make(
			$score >= 90 ? Check::PASS : ( $score >= 50 ? Check::WARN : Check::FAIL ),
			self::CATEGORY,
			$this->perf_id(),
			$this->perf_label(),
			'mobile' === $this->strategy ? 5 : 3,
			$evidence,
			$extra
		);
	}

	/**
	 * Core Web Vitals from real visitors, when Google has enough data.
	 *
	 * @param array<string,mixed> $response API response.
	 * @return array<string,mixed>
	 */
	private function field_vitals( array $response ) {
		$label   = __( 'Core Web Vitals (real visitors)', 'site-audit-report' );
		$field   = (array) ( $response['loadingExperience'] ?? array() );
		$metrics = (array) ( $field['metrics'] ?? array() );

		$map = array(
			'LARGEST_CONTENTFUL_PAINT_MS'   => array( __( 'Largest Contentful Paint', 'site-audit-report' ), 'ms' ),
			'INTERACTION_TO_NEXT_PAINT'     => array( __( 'Interaction to Next Paint', 'site-audit-report' ), 'ms' ),
			'CUMULATIVE_LAYOUT_SHIFT_SCORE' => array( __( 'Cumulative Layout Shift', 'site-audit-report' ), 'cls' ),
		);

		$items  = array();
		$rating = array();
		foreach ( $map as $key => $meta ) {
			if ( ! isset( $metrics[ $key ]['category'], $metrics[ $key ]['percentile'] ) ) {
				continue;
			}
			$value    = (float) $metrics[ $key ]['percentile'];
			$display  = 'cls' === $meta[1] ? number_format( $value / 100, 2 ) : number_format( $value / 1000, 1 ) . ' s';
			$category = (string) $metrics[ $key ]['category'];
			$rating[] = $category;
			$items[]  = $meta[0] . ': ' . $display . ' (' . self::rating_label( $category ) . ')';
		}

		if ( ! $rating ) {
			return Check::not_checked( self::CATEGORY, 'core_web_vitals', $label, 4, __( 'Google does not have enough real-visitor data for this site. This is common for smaller sites and is not a fault.', 'site-audit-report' ) );
		}

		$note = ! empty( $field['origin_fallback'] ) ? __( 'These figures cover the whole site, not just the home page.', 'site-audit-report' ) : __( 'These figures are for the home page.', 'site-audit-report' );

		if ( in_array( 'SLOW', $rating, true ) ) {
			$status = Check::FAIL;
		} elseif ( in_array( 'AVERAGE', $rating, true ) ) {
			$status = Check::WARN;
		} else {
			$status = Check::PASS;
		}
		$extra = array( 'items' => $items );
		if ( Check::PASS !== $status ) {
			$extra['fix']    = __( 'Improve the slower metrics first, because Google uses these real-visitor figures as a ranking signal.', 'site-audit-report' );
			$extra['effort'] = 'high';
		}
		return Check::make( $status, self::CATEGORY, 'core_web_vitals', $label, 4, __( 'Measured from real Chrome users over the last 28 days.', 'site-audit-report' ) . ' ' . $note, $extra );
	}

	/**
	 * Words for a Chrome UX Report rating.
	 *
	 * @param string $category Rating.
	 * @return string
	 */
	private static function rating_label( $category ) {
		switch ( $category ) {
			case 'FAST':
				return __( 'good', 'site-audit-report' );
			case 'AVERAGE':
				return __( 'needs improvement', 'site-audit-report' );
			case 'SLOW':
				return __( 'poor', 'site-audit-report' );
			default:
				return __( 'unrated', 'site-audit-report' );
		}
	}

	/**
	 * Lighthouse accessibility score.
	 *
	 * @param array<string,mixed> $categories Lighthouse categories.
	 * @param array<string,mixed> $audits     Lighthouse audits.
	 * @return array<string,mixed>
	 */
	private function accessibility( array $categories, array $audits ) {
		$label = __( 'Accessibility score', 'site-audit-report' );
		if ( ! isset( $categories['accessibility']['score'] ) ) {
			return Check::not_checked( self::CATEGORY, 'accessibility', $label, 3, __( 'PageSpeed Insights did not return an accessibility score.', 'site-audit-report' ) );
		}
		$score = (int) round( 100 * (float) $categories['accessibility']['score'] );

		$items = array();
		foreach ( (array) ( $categories['accessibility']['auditRefs'] ?? array() ) as $ref ) {
			$audit = $audits[ $ref['id'] ?? '' ] ?? null;
			if ( is_array( $audit ) && isset( $audit['score'] ) && 0 === (int) $audit['score'] && 1 > (float) $audit['score'] && ! empty( $audit['title'] ) && count( $items ) < 6 ) {
				$items[] = wp_strip_all_tags( (string) $audit['title'] );
			}
		}

		/* translators: %d: score. */
		$evidence = sprintf( __( 'Google automated test score: %d out of 100. Automated tests catch only some accessibility problems.', 'site-audit-report' ), $score );
		$extra    = array(
			'score' => $score,
			'items' => $items,
		);
		if ( $score < 90 ) {
			$extra['fix']    = __( 'Fix the failed items listed here, then test with a keyboard and a screen reader.', 'site-audit-report' );
			$extra['effort'] = 'medium';
		}
		return Check::make( $score >= 90 ? Check::PASS : ( $score >= 50 ? Check::WARN : Check::FAIL ), self::CATEGORY, 'accessibility', $label, 3, $evidence, $extra );
	}

	/**
	 * Lighthouse best-practices score.
	 *
	 * @param array<string,mixed> $categories Lighthouse categories.
	 * @return array<string,mixed>
	 */
	private function best_practices( array $categories ) {
		$label = __( 'Best-practices score', 'site-audit-report' );
		if ( ! isset( $categories['best-practices']['score'] ) ) {
			return Check::not_checked( self::CATEGORY, 'best_practices', $label, 1, __( 'PageSpeed Insights did not return a best-practices score.', 'site-audit-report' ) );
		}
		$score = (int) round( 100 * (float) $categories['best-practices']['score'] );
		/* translators: %d: score. */
		$evidence = sprintf( __( 'Google automated test score: %d out of 100.', 'site-audit-report' ), $score );
		$extra    = array( 'score' => $score );
		if ( $score < 90 ) {
			$extra['fix']    = __( 'Run the site through PageSpeed Insights to see the full list of best-practice items to correct.', 'site-audit-report' );
			$extra['effort'] = 'medium';
		}
		return Check::make( $score >= 90 ? Check::PASS : ( $score >= 50 ? Check::WARN : Check::FAIL ), self::CATEGORY, 'best_practices', $label, 1, $evidence, $extra );
	}
}
