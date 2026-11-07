<?php
/**
 * Renders reports. Everything from a scanned site, Google or a visitor is treated
 * as hostile and escaped at the point of output.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Report renderer.
 */
final class Report_Renderer {

	/**
	 * A complete standalone HTML document for a report.
	 *
	 * @param array<string,mixed> $audit   Audit.
	 * @param array<string,mixed> $options mode (web|download), nonce.
	 * @return string
	 */
	public static function document( array $audit, array $options = array() ) {
		$mode   = (string) ( $options['mode'] ?? 'web' );
		$status = (string) $audit['status'];
		$title  = sprintf(
			/* translators: %s: domain name. */
			__( 'Website audit: %s', 'site-audit-report' ),
			(string) $audit['domain']
		);

		$head = '';
		if ( 'web' === $mode && in_array( $status, array( 'queued', 'running' ), true ) ) {
			$head .= '<meta http-equiv="refresh" content="6">';
		}
		if ( 'download' === $mode ) {
			$head .= '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src https: data:">';
		}

		return self::wrap( $title, $head, self::body( $audit, $options ), (string) ( $options['nonce'] ?? '' ) );
	}

	/**
	 * The page shown for an unknown link.
	 *
	 * @return string
	 */
	public static function not_found_document() {
		$body  = '<div class="sar-report"><main class="sar-main"><section class="sar-card">';
		$body .= '<h1>' . esc_html__( 'Report not found', 'site-audit-report' ) . '</h1>';
		$body .= '<p>' . esc_html__( 'This link is not valid or the report has been deleted. Reports are kept for a limited time.', 'site-audit-report' ) . '</p>';
		$body .= '</section></main></div>';
		return self::wrap( __( 'Report not found', 'site-audit-report' ), '', $body, '' );
	}

	/**
	 * The report itself, without the surrounding document. Also used in the admin.
	 *
	 * @param array<string,mixed> $audit   Audit.
	 * @param array<string,mixed> $options mode (web|download|admin).
	 * @return string
	 */
	public static function body( array $audit, array $options = array() ) {
		$mode   = (string) ( $options['mode'] ?? 'web' );
		$result = json_decode( (string) $audit['result'], true );
		$result = is_array( $result ) ? $result : array();

		$out  = '<div class="sar-report" style="' . esc_attr( Brand::css_variables() ) . '">';
		$out .= self::header( $audit, $mode );
		$out .= '<main class="sar-main">';

		if ( 'complete' === $audit['status'] && $result ) {
			$out .= self::summary( $audit, $result );
			$out .= self::fixes( $result );
			$out .= self::cta( $mode );
			$out .= self::not_checked( $result );
			$out .= self::details( $result );
			$out .= self::pages( $result );
			$out .= self::method( $result );
			if ( 'admin' !== $mode ) {
				$out .= self::cta( $mode, true );
			}
		} elseif ( 'failed' === $audit['status'] ) {
			$out .= '<section class="sar-card"><h1>' . esc_html__( 'The audit could not be completed', 'site-audit-report' ) . '</h1>';
			$out .= '<p>' . esc_html( $audit['error'] ? $audit['error'] : __( 'The scan stopped before it could finish.', 'site-audit-report' ) ) . '</p></section>';
		} else {
			$out .= self::progress( $audit, $result );
		}

		$out .= '</main></div>';
		return $out;
	}

	/**
	 * Document shell.
	 *
	 * @param string $title Title.
	 * @param string $head  Trusted extra head markup.
	 * @param string $body  Escaped body.
	 * @param string $nonce CSP nonce.
	 * @return string
	 */
	private static function wrap( $title, $head, $body, $nonce ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file, inlined so the page and the download need no other request.
		$css = (string) file_get_contents( SAR_DIR . 'assets/css/report.css' );

		$script = '';
		if ( '' !== $nonce ) {
			$script = '<script nonce="' . esc_attr( $nonce ) . '">document.querySelectorAll("[data-sar-print]").forEach(function(b){b.hidden=false;b.addEventListener("click",function(){window.print();});});</script>';
		}

		return '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">'
			. '<meta name="referrer" content="no-referrer"><title>' . esc_html( $title ) . '</title>' . $head
			. '<style>' . $css . '</style></head><body class="sar-body">' . $body . $script . '</body></html>';
	}

	/**
	 * Report header with branding and actions.
	 *
	 * @param array<string,mixed> $audit Audit.
	 * @param string              $mode  Mode.
	 * @return string
	 */
	private static function header( array $audit, $mode ) {
		$brand = Settings::brand_name();
		$logo  = (string) Settings::get( 'logo_url' );

		$out = '<header class="sar-top"><div class="sar-top__inner"><div class="sar-brand">';
		if ( '' !== $logo ) {
			$out .= '<img src="' . esc_url( $logo, array( 'http', 'https' ) ) . '" alt="' . esc_attr( $brand ) . '">';
		} else {
			$out .= '<span class="sar-brand__name">' . esc_html( $brand ) . '</span>';
		}
		$out .= '</div>';

		if ( 'complete' === $audit['status'] && 'download' !== $mode ) {
			$out .= '<div class="sar-actions">';
			$out .= '<a class="sar-btn" href="' . esc_url( add_query_arg( 'sar_download', '1', Report_Route::url( (string) $audit['token'] ) ) ) . '">' . esc_html__( 'Download report', 'site-audit-report' ) . '</a>';
			if ( 'web' === $mode ) {
				$out .= '<button type="button" class="sar-btn sar-btn--plain" data-sar-print hidden>' . esc_html__( 'Print or save as PDF', 'site-audit-report' ) . '</button>';
			}
			$out .= '</div>';
		}
		return $out . '</div></header>';
	}

	/**
	 * Score summary and categories.
	 *
	 * @param array<string,mixed> $audit  Audit.
	 * @param array<string,mixed> $result Report data.
	 * @return string
	 */
	private static function summary( array $audit, array $result ) {
		$score = $result['score'];
		$date  = wp_date( get_option( 'date_format' ), strtotime( (string) $result['generated_at'] ) );

		$out  = '<section class="sar-card sar-summary"><div class="sar-summary__intro">';
		$out .= '<p class="sar-eyebrow">' . esc_html__( 'Website audit report', 'site-audit-report' ) . '</p>';
		$out .= '<h1>' . esc_html( (string) $audit['domain'] ) . '</h1>';

		$meta = array();
		if ( '' !== (string) $audit['business_name'] ) {
			$meta[] = (string) $audit['business_name'];
		}
		$meta[] = (string) $date;
		$out   .= '<p class="sar-meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';

		$not_checked = (int) $result['total'] - (int) $result['measured'];
		if ( null === $score ) {
			$out .= '<p>' . esc_html__( 'No overall score is given, because too little of the site could be measured.', 'site-audit-report' ) . '</p>';
			if ( ! empty( $result['unreadable_reason'] ) ) {
				$out .= '<p>' . esc_html( (string) $result['unreadable_reason'] ) . '</p>';
			}
		} else {
			$out .= '<p>' . esc_html(
				$not_checked
					? sprintf(
						/* translators: 1: checks measured, 2: checks planned. */
						__( 'This score is based on the %1$d of %2$d checks we could measure. The rest are not counted, and are listed below.', 'site-audit-report' ),
						(int) $result['measured'],
						(int) $result['total']
					)
					: sprintf(
						/* translators: %d: checks measured. */
						__( 'This score is based on all %d checks.', 'site-audit-report' ),
						(int) $result['measured']
					)
			) . '</p>';
		}
		$out .= '</div>';

		if ( null !== $score ) {
			$out .= '<div class="sar-score sar-score--' . esc_attr( self::band( (int) $score ) ) . '">';
			$out .= '<span class="sar-score__value">' . esc_html( (string) (int) $score ) . '</span>';
			$out .= '<span class="sar-score__of">' . esc_html__( 'out of 100', 'site-audit-report' ) . '</span>';
			$out .= '<span class="sar-score__label">' . esc_html( self::band_label( (int) $score ) ) . '</span></div>';
		}
		$out .= '</section>';

		$out .= '<section class="sar-card" aria-labelledby="sar-cat-h"><h2 id="sar-cat-h">' . esc_html__( 'Scores by area', 'site-audit-report' ) . '</h2>';
		$out .= self::category_list( (array) $result['categories'] );
		$out .= '</section>';

		return $out;
	}

	/**
	 * Category scores.
	 *
	 * @param array<string,array<string,mixed>> $categories Categories.
	 * @return string
	 */
	private static function category_list( array $categories ) {
		$out = '<ul class="sar-cats">';
		foreach ( $categories as $category ) {
			if ( empty( $category['total'] ) ) {
				continue;
			}
			$score = $category['score'];
			$out  .= '<li class="sar-cat"><div class="sar-cat__row"><span class="sar-cat__label">' . esc_html( (string) $category['label'] ) . '</span>';
			if ( null === $score ) {
				$out .= '<span class="sar-pill sar-pill--not_checked">' . esc_html__( 'Not checked', 'site-audit-report' ) . '</span></div>';
			} else {
				$out .= '<span class="sar-cat__score">' . esc_html( (string) (int) $score ) . '<span class="sar-sr"> ' . esc_html__( 'out of 100', 'site-audit-report' ) . '</span></span></div>';
				$out .= '<div class="sar-bar" aria-hidden="true"><span class="sar-bar__fill sar-bar__fill--' . esc_attr( self::band( (int) $score ) ) . '" style="width:' . esc_attr( (string) (int) $score ) . '%"></span></div>';
			}
			if ( ! empty( $category['description'] ) ) {
				$out .= '<p class="sar-cat__desc">' . esc_html( (string) $category['description'] ) . '</p>';
			}
			if ( null !== $score && (int) $category['measured'] < (int) $category['total'] ) {
				$out .= '<p class="sar-cat__desc">' . esc_html(
					sprintf(
						/* translators: 1: checks measured, 2: checks planned. */
						__( 'Based on %1$d of %2$d checks.', 'site-audit-report' ),
						(int) $category['measured'],
						(int) $category['total']
					)
				) . '</p>';
			}
			$out .= '</li>';
		}
		return $out . '</ul>';
	}

	/**
	 * Prioritised fixes.
	 *
	 * @param array<string,mixed> $result Report data.
	 * @return string
	 */
	private static function fixes( array $result ) {
		$fixes = array_slice( (array) $result['fixes'], 0, 10 );
		$out   = '<section class="sar-card" aria-labelledby="sar-fix-h"><h2 id="sar-fix-h">' . esc_html__( 'What to fix first', 'site-audit-report' ) . '</h2>';
		if ( ! $fixes ) {
			return $out . '<p>' . esc_html__( 'None of the checks we could measure need fixing.', 'site-audit-report' ) . '</p></section>';
		}
		$out .= '<p>' . esc_html__( 'Ordered by how much each fix should help, with the easier fix first when two are close.', 'site-audit-report' ) . '</p><ol class="sar-fixes">';
		foreach ( $fixes as $fix ) {
			$out .= '<li class="sar-fix"><div class="sar-fix__head"><h3>' . esc_html( (string) $fix['label'] ) . '</h3>';
			$out .= '<span class="sar-pill sar-pill--priority-' . esc_attr( (string) $fix['priority'] ) . '">' . esc_html( self::priority_label( (string) $fix['priority'] ) ) . '</span>';
			$out .= '<span class="sar-pill sar-pill--plain">' . esc_html( self::effort_label( (string) $fix['effort'] ) ) . '</span></div>';
			$out .= '<p class="sar-fix__found">' . esc_html( (string) $fix['evidence'] ) . '</p>';
			$out .= '<p><strong>' . esc_html__( 'What to do:', 'site-audit-report' ) . '</strong> ' . esc_html( (string) $fix['fix'] ) . '</p></li>';
		}
		return $out . '</ol></section>';
	}

	/**
	 * What could not be checked.
	 *
	 * @param array<string,mixed> $result Report data.
	 * @return string
	 */
	private static function not_checked( array $result ) {
		$rows = array();
		foreach ( (array) $result['checks'] as $check ) {
			if ( Check::NOT_CHECKED === $check['status'] ) {
				$rows[] = $check;
			}
		}
		$out = '<section class="sar-card" aria-labelledby="sar-nc-h"><h2 id="sar-nc-h">' . esc_html__( 'What could not be checked', 'site-audit-report' ) . '</h2>';
		if ( ! $rows ) {
			return $out . '<p>' . esc_html__( 'Every planned check ran.', 'site-audit-report' ) . '</p></section>';
		}
		$out .= '<p>' . esc_html__( 'These items are not part of the score. We show them so you know the limits of this report; they are not problems we found.', 'site-audit-report' ) . '</p><ul class="sar-nc">';
		foreach ( $rows as $row ) {
			$out .= '<li><strong>' . esc_html( (string) $row['label'] ) . '</strong> <span>' . esc_html( (string) $row['evidence'] ) . '</span></li>';
		}
		return $out . '</ul></section>';
	}

	/**
	 * Every check, grouped by area.
	 *
	 * @param array<string,mixed> $result Report data.
	 * @return string
	 */
	private static function details( array $result ) {
		$out = '';
		foreach ( (array) $result['categories'] as $id => $category ) {
			$checks = array_filter(
				(array) $result['checks'],
				static function ( $check ) use ( $id ) {
					return $check['category'] === $id && Check::NOT_CHECKED !== $check['status'];
				}
			);
			if ( ! $checks ) {
				continue;
			}
			$heading = 'sar-det-' . sanitize_html_class( (string) $id );
			$out    .= '<section class="sar-card" aria-labelledby="' . esc_attr( $heading ) . '"><h2 id="' . esc_attr( $heading ) . '">' . esc_html( (string) $category['label'] ) . '</h2><ul class="sar-checks">';
			foreach ( $checks as $check ) {
				$out .= self::check_row( $check );
			}
			$out .= '</ul></section>';
		}
		return $out;
	}

	/**
	 * One check.
	 *
	 * @param array<string,mixed> $check Check.
	 * @return string
	 */
	private static function check_row( array $check ) {
		$out  = '<li class="sar-check"><div class="sar-check__head"><span class="sar-pill sar-pill--' . esc_attr( (string) $check['status'] ) . '">' . esc_html( self::status_label( (string) $check['status'] ) ) . '</span>';
		$out .= '<h3>' . esc_html( (string) $check['label'] ) . '</h3></div>';
		$out .= '<p>' . esc_html( (string) $check['evidence'] ) . '</p>';

		if ( '' !== (string) $check['fix'] && Check::PASS !== $check['status'] ) {
			$out .= '<p><strong>' . esc_html__( 'What to do:', 'site-audit-report' ) . '</strong> ' . esc_html( (string) $check['fix'] ) . '</p>';
		}
		if ( ! empty( $check['items'] ) ) {
			$out .= '<ul class="sar-items">';
			foreach ( (array) $check['items'] as $item ) {
				$out .= '<li>' . self::item( $item ) . '</li>';
			}
			$out .= '</ul>';
		}
		return $out . '</li>';
	}

	/**
	 * One evidence item. Only http(s) links become clickable.
	 *
	 * @param mixed $item String, or array with text and url.
	 * @return string
	 */
	private static function item( $item ) {
		if ( is_array( $item ) ) {
			$text = (string) ( $item['text'] ?? '' );
			$url  = (string) ( $item['url'] ?? '' );
			if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
				return '<a href="' . esc_url( $url, array( 'http', 'https' ) ) . '" target="_blank" rel="nofollow noopener noreferrer">' . esc_html( $text ) . '</a>';
			}
			return esc_html( $text );
		}
		return esc_html( (string) $item );
	}

	/**
	 * Pages sampled.
	 *
	 * @param array<string,mixed> $result Report data.
	 * @return string
	 */
	private static function pages( array $result ) {
		if ( empty( $result['pages'] ) ) {
			return '';
		}
		$out = '<section class="sar-card" aria-labelledby="sar-pages-h"><h2 id="sar-pages-h">' . esc_html__( 'Pages checked', 'site-audit-report' ) . '</h2><ul class="sar-pages">';
		foreach ( (array) $result['pages'] as $page ) {
			$out .= '<li class="sar-page">' . self::item(
				array(
					'text' => $page['url'],
					'url'  => $page['url'],
				)
			);
			$out .= '<dl>';
			$out .= '<dt>' . esc_html__( 'Title', 'site-audit-report' ) . '</dt><dd>' . esc_html( '' !== (string) $page['title'] ? (string) $page['title'] : __( 'None', 'site-audit-report' ) ) . '</dd>';
			$out .= '<dt>' . esc_html__( 'Description', 'site-audit-report' ) . '</dt><dd>' . esc_html( '' !== (string) $page['description'] ? (string) $page['description'] : __( 'None', 'site-audit-report' ) ) . '</dd>';
			$out .= '<dt>' . esc_html__( 'H1', 'site-audit-report' ) . '</dt><dd>' . esc_html( '' !== (string) $page['h1'] ? (string) $page['h1'] : __( 'None', 'site-audit-report' ) ) . '</dd>';
			$out .= '<dt>' . esc_html__( 'Words', 'site-audit-report' ) . '</dt><dd>' . esc_html( (string) (int) $page['words'] ) . '</dd>';
			$out .= '</dl></li>';
		}
		return $out . '</ul></section>';
	}

	/**
	 * How the report was made.
	 *
	 * @param array<string,mixed> $result Report data.
	 * @return string
	 */
	private static function method( array $result ) {
		$out = '<section class="sar-card sar-method" aria-labelledby="sar-method-h"><h2 id="sar-method-h">' . esc_html__( 'How this report was made', 'site-audit-report' ) . '</h2><ul>';
		foreach ( (array) $result['limitations'] as $limitation ) {
			$out .= '<li>' . esc_html( (string) $limitation ) . '</li>';
		}
		$out .= '<li>' . esc_html__( 'Scores are guidance from automated checks, not a guarantee of search rankings or security. Speed data comes from Google PageSpeed Insights, and business profile data, when shown, comes from Google.', 'site-audit-report' ) . '</li>';
		return $out . '</ul></section>';
	}

	/**
	 * The call to action, if one is configured.
	 *
	 * @param string $mode   Mode.
	 * @param bool   $footer Whether this is the second, shorter placement.
	 * @return string
	 */
	private static function cta( $mode, $footer = false ) {
		$url   = (string) Settings::get( 'cta_url' );
		$label = (string) Settings::get( 'cta_label' );
		if ( '' === $url || '' === $label || 'admin' === $mode ) {
			return '';
		}
		$heading = (string) Settings::get( 'cta_heading' );
		$text    = (string) Settings::get( 'cta_text' );

		$out = '<section class="sar-card sar-cta">';
		if ( '' !== $heading ) {
			$out .= '<h2>' . esc_html( $heading ) . '</h2>';
		}
		if ( '' !== $text && ! $footer ) {
			$out .= '<p>' . esc_html( $text ) . '</p>';
		}
		$out .= '<a class="sar-btn sar-btn--primary" href="' . esc_url( $url, array( 'http', 'https' ) ) . '">' . esc_html( $label ) . '</a>';
		return $out . '</section>';
	}

	/**
	 * The page shown while a scan runs.
	 *
	 * @param array<string,mixed> $audit  Audit.
	 * @param array<string,mixed> $result Partial report data.
	 * @return string
	 */
	private static function progress( array $audit, array $result ) {
		$out  = '<section class="sar-card"><p class="sar-eyebrow">' . esc_html__( 'Audit in progress', 'site-audit-report' ) . '</p>';
		$out .= '<h1>' . esc_html( (string) $audit['domain'] ) . '</h1>';
		$out .= '<p>' . esc_html( $audit['stage'] ? (string) $audit['stage'] : __( 'Waiting to start', 'site-audit-report' ) ) . '</p>';
		$out .= '<progress max="100" value="' . esc_attr( (string) (int) $audit['progress'] ) . '" aria-label="' . esc_attr__( 'Audit progress', 'site-audit-report' ) . '"></progress>';
		$out .= '<p class="sar-meta">' . esc_html__( 'This page updates by itself. A full audit usually takes a few minutes, and you can close this page and return to the same link.', 'site-audit-report' ) . '</p></section>';

		if ( ! empty( $result['checks'] ) && isset( $result['score'] ) ) {
			$out .= '<section class="sar-card sar-summary"><div class="sar-summary__intro"><h2>' . esc_html__( 'Early score', 'site-audit-report' ) . '</h2>';
			$out .= '<p>' . esc_html__( 'Based only on the checks finished so far. It can change as the remaining tests finish.', 'site-audit-report' ) . '</p></div>';
			$out .= '<div class="sar-score sar-score--' . esc_attr( self::band( (int) $result['score'] ) ) . '"><span class="sar-score__value">' . esc_html( (string) (int) $result['score'] ) . '</span>';
			$out .= '<span class="sar-score__of">' . esc_html__( 'out of 100', 'site-audit-report' ) . '</span></div></section>';
		}
		return $out;
	}

	/**
	 * Score band.
	 *
	 * @param int $score Score.
	 * @return string
	 */
	private static function band( $score ) {
		if ( $score >= 80 ) {
			return 'good';
		}
		return $score >= 50 ? 'fair' : 'poor';
	}

	/**
	 * Words for a score band.
	 *
	 * @param int $score Score.
	 * @return string
	 */
	private static function band_label( $score ) {
		if ( $score >= 80 ) {
			return __( 'In good shape', 'site-audit-report' );
		}
		return $score >= 50 ? __( 'Room to improve', 'site-audit-report' ) : __( 'Needs attention', 'site-audit-report' );
	}

	/**
	 * Words for a check status. Colour is never the only signal.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_label( $status ) {
		switch ( $status ) {
			case Check::PASS:
				return __( 'Good', 'site-audit-report' );
			case Check::WARN:
				return __( 'Improve', 'site-audit-report' );
			case Check::FAIL:
				return __( 'Problem', 'site-audit-report' );
			case Check::INFO:
				return __( 'Note', 'site-audit-report' );
			default:
				return __( 'Not checked', 'site-audit-report' );
		}
	}

	/**
	 * Words for a priority.
	 *
	 * @param string $priority Priority.
	 * @return string
	 */
	private static function priority_label( $priority ) {
		switch ( $priority ) {
			case 'high':
				return __( 'High priority', 'site-audit-report' );
			case 'medium':
				return __( 'Medium priority', 'site-audit-report' );
			default:
				return __( 'Low priority', 'site-audit-report' );
		}
	}

	/**
	 * Words for effort.
	 *
	 * @param string $effort Effort.
	 * @return string
	 */
	private static function effort_label( $effort ) {
		switch ( $effort ) {
			case 'low':
				return __( 'Quick fix', 'site-audit-report' );
			case 'high':
				return __( 'Larger job', 'site-audit-report' );
			default:
				return __( 'Moderate effort', 'site-audit-report' );
		}
	}
}
