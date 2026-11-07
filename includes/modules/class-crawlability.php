<?php
/**
 * Whether search engines are allowed to find and read the site.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report\Modules;

use Site_Audit_Report\Check;
use Site_Audit_Report\Module;
use Site_Audit_Report\Scan_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Crawlability checks.
 */
final class Crawlability implements Module {

	const CATEGORY = 'technical';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'crawlability';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Checking robots.txt and the sitemap', 'site-audit-report' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Scan_Context $context Context.
	 */
	public function run( Scan_Context $context ) {
		$data   = $context->data;
		$robots = (array) ( $data['robots'] ?? array() );
		$checks = array();

		$checks[] = $this->robots( $robots, $context );
		$checks[] = $this->sitemap( $data, $robots, $context );

		if ( ! $context->readable() ) {
			$checks[] = Check::not_checked( self::CATEGORY, 'noindex', __( 'Indexing allowed', 'site-audit-report' ), 5, $context->unreadable_reason() );
			return $checks;
		}

		$checks[] = $this->indexing( $data, $context );
		$checks[] = $this->redirects( $data );
		$checks[] = $this->page_errors( $data );

		return $checks;
	}

	/**
	 * Robots.txt.
	 *
	 * @param array<string,mixed> $robots  Robots data.
	 * @param Scan_Context        $context Context.
	 * @return array<string,mixed>
	 */
	private function robots( array $robots, Scan_Context $context ) {
		$label = __( 'robots.txt', 'site-audit-report' );
		if ( empty( $robots['checked'] ) ) {
			return Check::not_checked( self::CATEGORY, 'robots', $label, 3, $context->readable() ? __( 'The robots.txt address could not be read by our scanner.', 'site-audit-report' ) : $context->unreadable_reason() );
		}
		if ( ! empty( $robots['blocks_all'] ) ) {
			return Check::make(
				Check::FAIL,
				self::CATEGORY,
				'robots',
				$label,
				5,
				__( 'robots.txt tells all search engines not to crawl any page ("Disallow: /").', 'site-audit-report' ),
				array(
					'fix'    => __( 'Remove "Disallow: /" unless the site is meant to stay out of search. This is a common leftover from a site under development.', 'site-audit-report' ),
					'effort' => 'low',
				)
			);
		}
		if ( empty( $robots['found'] ) ) {
			return Check::make(
				Check::WARN,
				self::CATEGORY,
				'robots',
				$label,
				3,
				/* translators: %d: HTTP status code. */
				sprintf( __( 'No robots.txt file was found (HTTP %d). Search engines will still crawl the site.', 'site-audit-report' ), (int) ( $robots['code'] ?? 0 ) ),
				array(
					'fix'    => __( 'Add a simple robots.txt that allows crawling and lists your sitemap.', 'site-audit-report' ),
					'effort' => 'low',
				)
			);
		}
		return Check::make( Check::PASS, self::CATEGORY, 'robots', $label, 3, __( 'robots.txt was found and does not block the whole site.', 'site-audit-report' ) );
	}

	/**
	 * Sitemap.
	 *
	 * @param array<string,mixed> $data    Crawl data.
	 * @param array<string,mixed> $robots  Robots data.
	 * @param Scan_Context        $context Context.
	 * @return array<string,mixed>
	 */
	private function sitemap( array $data, array $robots, Scan_Context $context ) {
		$label   = __( 'XML sitemap', 'site-audit-report' );
		$sitemap = (array) ( $data['sitemap'] ?? array() );
		if ( ! $sitemap || ( ! $context->readable() && empty( $sitemap['found'] ) ) ) {
			return Check::not_checked( self::CATEGORY, 'sitemap', $label, 3, $context->unreadable_reason() );
		}
		if ( empty( $sitemap['found'] ) ) {
			return Check::make(
				Check::FAIL,
				self::CATEGORY,
				'sitemap',
				$label,
				3,
				__( 'No sitemap was found in robots.txt or at the usual addresses.', 'site-audit-report' ),
				array(
					'fix'    => __( 'Create an XML sitemap, list it in robots.txt and submit it in Google Search Console.', 'site-audit-report' ),
					'effort' => 'low',
				)
			);
		}
		if ( empty( $robots['sitemaps'] ) ) {
			return Check::make(
				Check::WARN,
				self::CATEGORY,
				'sitemap',
				$label,
				3,
				__( 'A sitemap exists but robots.txt does not list it.', 'site-audit-report' ),
				array(
					'fix'    => __( 'Add a "Sitemap:" line to robots.txt with the sitemap\'s full address.', 'site-audit-report' ),
					'effort' => 'low',
					'items'  => array(
						array(
							'text' => $sitemap['url'],
							'url'  => $sitemap['url'],
						),
					),
				)
			);
		}
		return Check::make(
			Check::PASS,
			self::CATEGORY,
			'sitemap',
			$label,
			3,
			__( 'A sitemap was found and is listed in robots.txt.', 'site-audit-report' ),
			array(
				'items' => array(
					array(
						'text' => $sitemap['url'],
						'url'  => $sitemap['url'],
					),
				),
			)
		);
	}

	/**
	 * Noindex signals.
	 *
	 * @param array<string,mixed> $data    Crawl data.
	 * @param Scan_Context        $context Context.
	 * @return array<string,mixed>
	 */
	private function indexing( array $data, Scan_Context $context ) {
		$label   = __( 'Indexing allowed', 'site-audit-report' );
		$blocked = array();
		foreach ( $context->pages() as $page ) {
			if ( false !== strpos( $page['robots'], 'noindex' ) ) {
				$blocked[] = array(
					'text' => $page['url'],
					'url'  => $page['url'],
				);
			}
		}
		$header_noindex = false !== stripos( (string) ( $data['home_headers']['x-robots-tag'] ?? '' ), 'noindex' );
		if ( $header_noindex || ! empty( $blocked ) ) {
			$home_blocked = $header_noindex || ( $blocked && $blocked[0]['url'] === $context->home()['url'] );
			return Check::make(
				$home_blocked ? Check::FAIL : Check::WARN,
				self::CATEGORY,
				'noindex',
				$label,
				5,
				$home_blocked ? __( 'The home page tells search engines not to index it.', 'site-audit-report' ) : __( 'Some pages tell search engines not to index them. That is fine for private pages but a problem for pages meant to rank.', 'site-audit-report' ),
				array(
					'fix'    => __( 'Remove the noindex directive from every page that should appear in search.', 'site-audit-report' ),
					'effort' => 'low',
					'items'  => $blocked,
				)
			);
		}
		return Check::make( Check::PASS, self::CATEGORY, 'noindex', $label, 5, __( 'None of the pages checked block indexing.', 'site-audit-report' ) );
	}

	/**
	 * Redirect chains on the way to the home page.
	 *
	 * @param array<string,mixed> $data Crawl data.
	 * @return array<string,mixed>
	 */
	private function redirects( array $data ) {
		$label = __( 'Redirects to the home page', 'site-audit-report' );
		$hops  = max( 0, count( (array) $data['home_chain'] ) - 1 );
		if ( $hops <= 1 ) {
			/* translators: %d: number of redirects. */
			return Check::make( Check::PASS, self::CATEGORY, 'redirects', $label, 1, sprintf( _n( '%d redirect before the home page.', '%d redirects before the home page.', $hops, 'site-audit-report' ), $hops ) );
		}
		$items = array();
		foreach ( (array) $data['home_chain'] as $url ) {
			$items[] = array(
				'text' => $url,
				'url'  => $url,
			);
		}
		return Check::make(
			Check::WARN,
			self::CATEGORY,
			'redirects',
			$label,
			1,
			/* translators: %d: number of redirects. */
			sprintf( __( 'The home page takes %d redirects to reach.', 'site-audit-report' ), $hops ),
			array(
				'fix'    => __( 'Redirect straight to the final address in a single step.', 'site-audit-report' ),
				'effort' => 'low',
				'items'  => $items,
			)
		);
	}

	/**
	 * Errors on sampled pages.
	 *
	 * @param array<string,mixed> $data Crawl data.
	 * @return array<string,mixed>
	 */
	private function page_errors( array $data ) {
		$label  = __( 'Sampled pages load', 'site-audit-report' );
		$errors = (array) $data['page_errors'];
		if ( ! $errors ) {
			/* translators: %d: number of pages. */
			return Check::make( Check::PASS, self::CATEGORY, 'page_errors', $label, 2, sprintf( __( 'Pages read without an error: %d.', 'site-audit-report' ), count( (array) $data['pages'] ) ) );
		}
		$items = array();
		foreach ( $errors as $error ) {
			$items[] = array(
				'text' => $error['url'] . ( $error['code'] ? ' (HTTP ' . (int) $error['code'] . ')' : '' ),
				'url'  => $error['url'],
			);
		}
		return Check::make(
			Check::FAIL,
			self::CATEGORY,
			'page_errors',
			$label,
			2,
			/* translators: %d: number of pages. */
			sprintf( _n( '%d page linked from the site did not load.', '%d pages linked from the site did not load.', count( $errors ), 'site-audit-report' ), count( $errors ) ),
			array(
				'fix'    => __( 'Restore these pages, redirect them, or remove links to them.', 'site-audit-report' ),
				'effort' => 'medium',
				'items'  => $items,
			)
		);
	}
}
