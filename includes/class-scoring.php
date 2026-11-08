<?php
/**
 * Turns checks into scores, priorities and the stored report.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Scoring. Checks that were not measured never affect a score.
 */
final class Scoring {

	/**
	 * Report categories.
	 *
	 * @return array<string,array{label:string,weight:int,description:string}>
	 */
	public static function categories() {
		$categories = array(
			'seo'         => array(
				'label'       => __( 'On-page SEO', 'site-audit-report' ),
				'weight'      => 25,
				'description' => __( 'Whether your pages clearly tell search engines what they are about.', 'site-audit-report' ),
			),
			'performance' => array(
				'label'       => __( 'Speed and accessibility', 'site-audit-report' ),
				'weight'      => 25,
				'description' => __( 'Measured by Google PageSpeed Insights on mobile and desktop.', 'site-audit-report' ),
			),
			'technical'   => array(
				'label'       => __( 'Crawling and links', 'site-audit-report' ),
				'weight'      => 15,
				'description' => __( 'Whether search engines can find your pages, and whether your links work.', 'site-audit-report' ),
			),
			'security'    => array(
				'label'       => __( 'Security and privacy', 'site-audit-report' ),
				'weight'      => 15,
				'description' => __( 'HTTPS, protective headers and the privacy pages visitors expect.', 'site-audit-report' ),
			),
			'wordpress'   => array(
				'label'       => __( 'WordPress health', 'site-audit-report' ),
				'weight'      => 15,
				'description' => __( 'Core, plugin and theme versions the site shows publicly, matched against known vulnerabilities, plus common WordPress hardening.', 'site-audit-report' ),
			),
			'accessibility' => array(
				'label'       => __( 'Accessibility', 'site-audit-report' ),
				'weight'      => 10,
				'description' => __( 'Whether people using screen readers, keyboards or zoom can use the page: labels, alt text, landmarks, headings and more.', 'site-audit-report' ),
			),
			'local'       => array(
				'label'       => __( 'Reputation and local', 'site-audit-report' ),
				'weight'      => 10,
				'description' => __( 'Your Google profile, reviews and whether your details match your website.', 'site-audit-report' ),
			),
			'presence'    => array(
				'label'       => __( 'Marketing presence', 'site-audit-report' ),
				'weight'      => 10,
				'description' => __( 'Contact options, social links, analytics and signs the site is kept up to date.', 'site-audit-report' ),
			),
		);

		/**
		 * Filters report categories. Add one here when a module needs its own section.
		 *
		 * @param array $categories Categories keyed by ID: label, weight, description.
		 */
		return (array) apply_filters( 'sar_categories', $categories );
	}

	/**
	 * Builds the report data.
	 *
	 * @param array<string,mixed>            $audit  Audit.
	 * @param array<int,array<string,mixed>> $checks Checks.
	 * @param array<string,mixed>            $crawl  Crawl data.
	 * @param array<string,mixed>            $meta   stats, partial, limitations.
	 * @return array<string,mixed>
	 */
	public static function build( array $audit, array $checks, array $crawl, array $meta ) {
		$definitions = self::categories();

		foreach ( $checks as &$check ) {
			$check['value'] = self::check_value( $check );
			if ( ! isset( $definitions[ $check['category'] ] ) ) {
				$definitions[ $check['category'] ] = array(
					'label'       => ucfirst( (string) $check['category'] ),
					'weight'      => 10,
					'description' => '',
				);
			}
		}
		unset( $check );

		$categories = array();
		foreach ( $definitions as $id => $definition ) {
			$earned   = 0.0;
			$possible = 0.0;
			$measured = 0;
			$total    = 0;
			foreach ( $checks as $check ) {
				if ( $check['category'] !== $id ) {
					continue;
				}
				++$total;
				if ( null !== $check['value'] ) {
					++$measured;
					$earned   += $check['value'] * $check['weight'];
					$possible += $check['weight'];
				}
			}
			$categories[ $id ] = array(
				'label'       => $definition['label'],
				'description' => $definition['description'],
				'weight'      => $definition['weight'],
				'score'       => $possible > 0 ? (int) round( $earned / $possible ) : null,
				'measured'    => $measured,
				'total'       => $total,
			);
		}

		$overall_earned = 0.0;
		$overall_weight = 0.0;
		$measured_all   = 0;
		$total_all      = 0;
		foreach ( $categories as $category ) {
			$measured_all += $category['measured'];
			$total_all    += $category['total'];
			if ( null !== $category['score'] ) {
				$overall_earned += $category['score'] * $category['weight'];
				$overall_weight += $category['weight'];
			}
		}

		// Without readable HTML, a score from one or two remaining checks would mislead.
		$enough = ! empty( $crawl['readable'] ) && $overall_weight > 0;

		$fixes = self::priorities( $checks );

		return array(
			'version'           => 1,
			'partial'           => ! empty( $meta['partial'] ),
			'generated_at'      => gmdate( 'c' ),
			'site_url'          => (string) $audit['site_url'],
			'final_url'         => (string) ( $crawl['final_url'] ?? $audit['site_url'] ),
			'domain'            => (string) $audit['domain'],
			'score'             => $enough ? (int) round( $overall_earned / $overall_weight ) : null,
			'measured'          => $measured_all,
			'total'             => $total_all,
			'readable'          => ! empty( $crawl['readable'] ),
			'unreadable_reason' => (string) ( $crawl['unreadable_reason'] ?? '' ),
			'categories'        => $categories,
			'checks'            => $checks,
			'fixes'             => $fixes,
			'limitations'       => (array) ( $meta['limitations'] ?? array() ),
			'pages'             => self::page_summary( $crawl ),
			'stats'             => (array) ( $meta['stats'] ?? array() ),
		);
	}

	/**
	 * A check's value from 0 to 100, or null if it was not measured.
	 *
	 * @param array<string,mixed> $check Check.
	 * @return int|null
	 */
	private static function check_value( array $check ) {
		switch ( $check['status'] ) {
			case Check::PASS:
			case Check::WARN:
			case Check::FAIL:
				if ( isset( $check['score'] ) && is_numeric( $check['score'] ) ) {
					return max( 0, min( 100, (int) $check['score'] ) );
				}
				if ( Check::PASS === $check['status'] ) {
					return 100;
				}
				return Check::WARN === $check['status'] ? 50 : 0;
			default:
				return null;
		}
	}

	/**
	 * Orders fixes by points recoverable, then by least effort.
	 *
	 * @param array<int,array<string,mixed>> $checks Checks.
	 * @return array<int,array<string,mixed>>
	 */
	private static function priorities( array $checks ) {
		$effort_rank = array(
			'low'    => 0,
			'medium' => 1,
			'high'   => 2,
		);
		$fixes       = array();
		foreach ( $checks as $check ) {
			if ( null === $check['value'] || '' === (string) $check['fix'] || $check['value'] >= 100 ) {
				continue;
			}
			$impact = $check['weight'] * ( 100 - $check['value'] ) / 100;
			if ( $impact >= 2.5 ) {
				$priority = 'high';
			} elseif ( $impact >= 1 ) {
				$priority = 'medium';
			} else {
				$priority = 'low';
			}
			$fixes[] = array(
				'id'       => $check['id'],
				'category' => $check['category'],
				'label'    => $check['label'],
				'status'   => $check['status'],
				'evidence' => $check['evidence'],
				'fix'      => $check['fix'],
				'effort'   => $check['effort'],
				'priority' => $priority,
				'impact'   => round( $impact, 2 ),
				'rank'     => $effort_rank[ $check['effort'] ] ?? 1,
			);
		}
		usort(
			$fixes,
			static function ( $a, $b ) {
				return array( $b['impact'], $a['rank'] ) <=> array( $a['impact'], $b['rank'] );
			}
		);
		return $fixes;
	}

	/**
	 * A compact per-page table for the report.
	 *
	 * @param array<string,mixed> $crawl Crawl data.
	 * @return array<int,array<string,mixed>>
	 */
	private static function page_summary( array $crawl ) {
		$rows = array();
		foreach ( (array) ( $crawl['pages'] ?? array() ) as $page ) {
			$rows[] = array(
				'url'         => $page['url'],
				'title'       => $page['title'],
				'description' => $page['description'],
				'h1'          => $page['h1'][0] ?? '',
				'words'       => $page['word_count'],
			);
		}
		return $rows;
	}
}
