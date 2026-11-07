<?php
/**
 * Search-engine basics on each sampled page.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report\Modules;

use Site_Audit_Report\Check;
use Site_Audit_Report\Module;
use Site_Audit_Report\Scan_Context;

defined( 'ABSPATH' ) || exit;

/**
 * On-page SEO checks.
 */
final class Onpage implements Module {

	const CATEGORY = 'seo';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'onpage';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Checking titles, headings and structured data', 'site-audit-report' );
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
					'title'       => array( __( 'Page titles', 'site-audit-report' ), 5 ),
					'description' => array( __( 'Meta descriptions', 'site-audit-report' ), 4 ),
					'h1'          => array( __( 'Main headings (H1)', 'site-audit-report' ), 4 ),
					'canonical'   => array( __( 'Canonical tags', 'site-audit-report' ), 3 ),
					'image_alt'   => array( __( 'Image alt text', 'site-audit-report' ), 3 ),
					'schema'      => array( __( 'Structured data (schema)', 'site-audit-report' ), 3 ),
					'viewport'    => array( __( 'Mobile viewport', 'site-audit-report' ), 3 ),
				),
				$context->unreadable_reason()
			);
		}

		$pages = $context->pages();
		return array(
			$this->titles( $pages ),
			$this->descriptions( $pages ),
			$this->h1( $pages ),
			$this->heading_order( $pages ),
			$this->canonical( $pages ),
			$this->image_alt( $pages ),
			$this->schema( $pages ),
			$this->viewport( $pages ),
			$this->language( $pages ),
			$this->social_preview( $pages ),
			$this->content_depth( $pages ),
		);
	}

	/**
	 * Page titles.
	 *
	 * @param array<int,array<string,mixed>> $pages Pages.
	 * @return array<string,mixed>
	 */
	private function titles( array $pages ) {
		$label   = __( 'Page titles', 'site-audit-report' );
		$missing = array();
		$long    = array();
		$short   = array();
		$values  = array();

		foreach ( $pages as $page ) {
			$length = mb_strlen( $page['title'] );
			if ( 0 === $length ) {
				$missing[] = $page['url'];
			} elseif ( $length > 65 ) {
				$long[] = $page['url'];
			} elseif ( $length < 15 ) {
				$short[] = $page['url'];
			}
			if ( $length ) {
				$values[ $page['title'] ][] = $page['url'];
			}
		}
		$duplicates = array_filter(
			$values,
			static function ( $urls ) {
				return count( $urls ) > 1;
			}
		);

		$home = $pages[0]['title'];
		/* translators: 1: home page title, 2: number of pages. */
		$summary = '' !== $home ? sprintf( __( 'Home page title: "%1$s". Pages checked: %2$d.', 'site-audit-report' ), $home, count( $pages ) ) : '';

		if ( $missing ) {
			return Check::make(
				Check::FAIL,
				self::CATEGORY,
				'title',
				$label,
				5,
				/* translators: %d: number of pages. */
				trim( $summary . ' ' . sprintf( _n( '%d page has no title.', '%d pages have no title.', count( $missing ), 'site-audit-report' ), count( $missing ) ) ),
				array(
					'fix'    => __( 'Give every page a unique title that says what the page offers, with the main search phrase near the start.', 'site-audit-report' ),
					'effort' => 'low',
					'items'  => self::url_items( $missing ),
				)
			);
		}
		if ( $long || $short || $duplicates ) {
			$notes = array();
			if ( $long ) {
				/* translators: %d: number of pages. */
				$notes[] = sprintf( _n( '%d title is over 65 characters and may be cut off in search results.', '%d titles are over 65 characters and may be cut off in search results.', count( $long ), 'site-audit-report' ), count( $long ) );
			}
			if ( $short ) {
				/* translators: %d: number of pages. */
				$notes[] = sprintf( _n( '%d title is under 15 characters.', '%d titles are under 15 characters.', count( $short ), 'site-audit-report' ), count( $short ) );
			}
			if ( $duplicates ) {
				/* translators: %d: number of titles. */
				$notes[] = sprintf( _n( '%d title is used on more than one page.', '%d titles are used on more than one page.', count( $duplicates ), 'site-audit-report' ), count( $duplicates ) );
			}
			return Check::make(
				Check::WARN,
				self::CATEGORY,
				'title',
				$label,
				5,
				trim( $summary . ' ' . implode( ' ', $notes ) ),
				array(
					'fix'    => __( 'Rewrite these titles so each is unique, about 30 to 60 characters long and leads with what the page offers.', 'site-audit-report' ),
					'effort' => 'low',
					'items'  => self::url_items( array_merge( $long, $short, ...array_values( $duplicates ) ) ),
				)
			);
		}
		return Check::make( Check::PASS, self::CATEGORY, 'title', $label, 5, $summary );
	}

	/**
	 * Meta descriptions.
	 *
	 * @param array<int,array<string,mixed>> $pages Pages.
	 * @return array<string,mixed>
	 */
	private function descriptions( array $pages ) {
		$label   = __( 'Meta descriptions', 'site-audit-report' );
		$missing = array();
		$off     = array();
		foreach ( $pages as $page ) {
			$length = mb_strlen( $page['description'] );
			if ( 0 === $length ) {
				$missing[] = $page['url'];
			} elseif ( $length < 50 || $length > 170 ) {
				$off[] = $page['url'];
			}
		}
		$fix = __( 'Write a unique description of about 120 to 160 characters for each page, ending with a reason to click.', 'site-audit-report' );

		if ( in_array( $pages[0]['url'], $missing, true ) ) {
			return Check::make(
				Check::FAIL,
				self::CATEGORY,
				'description',
				$label,
				4,
				/* translators: 1: pages missing a description, 2: pages checked. */
				sprintf( __( 'The home page has no meta description. Across the site sample, %1$d of %2$d pages have none.', 'site-audit-report' ), count( $missing ), count( $pages ) ),
				array(
					'fix'    => $fix,
					'effort' => 'low',
					'items'  => self::url_items( $missing ),
				)
			);
		}
		if ( $missing || $off ) {
			return Check::make(
				Check::WARN,
				self::CATEGORY,
				'description',
				$label,
				4,
				/* translators: 1: pages with no description, 2: pages with an unusual length. */
				sprintf( __( 'Pages with no description: %1$d. Pages with one shorter than 50 or longer than 170 characters: %2$d.', 'site-audit-report' ), count( $missing ), count( $off ) ),
				array(
					'fix'    => $fix,
					'effort' => 'low',
					'items'  => self::url_items( array_merge( $missing, $off ) ),
				)
			);
		}
		return Check::make(
			Check::PASS,
			self::CATEGORY,
			'description',
			$label,
			4,
			/* translators: %d: pages checked. */
			sprintf( __( 'Every page checked has a description of a suitable length. Pages checked: %d.', 'site-audit-report' ), count( $pages ) )
		);
	}

	/**
	 * H1 headings.
	 *
	 * @param array<int,array<string,mixed>> $pages Pages.
	 * @return array<string,mixed>
	 */
	private function h1( array $pages ) {
		$label    = __( 'Main headings (H1)', 'site-audit-report' );
		$none     = array();
		$multiple = array();
		foreach ( $pages as $page ) {
			$count = (int) ( $page['heading_counts']['h1'] ?? 0 );
			if ( 0 === $count ) {
				$none[] = $page['url'];
			} elseif ( $count > 1 ) {
				$multiple[] = $page['url'];
			}
		}
		$first = $pages[0]['h1'][0] ?? '';
		/* translators: %s: heading text. */
		$summary = '' !== $first ? sprintf( __( 'Home page H1: "%s".', 'site-audit-report' ), $first ) : __( 'The home page has no H1.', 'site-audit-report' );

		if ( $none ) {
			return Check::make(
				in_array( $pages[0]['url'], $none, true ) ? Check::FAIL : Check::WARN,
				self::CATEGORY,
				'h1',
				$label,
				4,
				/* translators: 1: summary sentence, 2: pages without a heading. */
				sprintf( __( '%1$s Pages with no H1: %2$d.', 'site-audit-report' ), $summary, count( $none ) ),
				array(
					'fix'    => __( 'Add one clear H1 to each page that states what the page is about. In many site builders this is just a change of heading level.', 'site-audit-report' ),
					'effort' => 'low',
					'items'  => self::url_items( $none ),
				)
			);
		}
		if ( $multiple ) {
			return Check::make(
				Check::WARN,
				self::CATEGORY,
				'h1',
				$label,
				4,
				/* translators: 1: summary sentence, 2: pages with more than one heading. */
				sprintf( __( '%1$s Pages with more than one H1: %2$d.', 'site-audit-report' ), $summary, count( $multiple ) ),
				array(
					'fix'    => __( 'Keep one H1 per page and change the rest to H2 or lower.', 'site-audit-report' ),
					'effort' => 'low',
					'items'  => self::url_items( $multiple ),
				)
			);
		}
		return Check::make( Check::PASS, self::CATEGORY, 'h1', $label, 4, $summary );
	}

	/**
	 * Heading hierarchy.
	 *
	 * @param array<int,array<string,mixed>> $pages Pages.
	 * @return array<string,mixed>
	 */
	private function heading_order( array $pages ) {
		$label = __( 'Heading order', 'site-audit-report' );
		$bad   = array();
		foreach ( $pages as $page ) {
			if ( $page['heading_skips'] > 0 ) {
				$bad[] = $page['url'];
			}
		}
		if ( $bad ) {
			return Check::make(
				Check::WARN,
				self::CATEGORY,
				'heading_order',
				$label,
				1,
				/* translators: %d: number of pages. */
				sprintf( __( 'Pages where headings skip a level (for example H2 to H4): %d.', 'site-audit-report' ), count( $bad ) ),
				array(
					'fix'    => __( 'Use heading levels in order so screen readers and search engines can follow the page outline.', 'site-audit-report' ),
					'effort' => 'low',
					'items'  => self::url_items( $bad ),
				)
			);
		}
		return Check::make( Check::PASS, self::CATEGORY, 'heading_order', $label, 1, __( 'Heading levels run in order on the pages checked.', 'site-audit-report' ) );
	}

	/**
	 * Canonical tags.
	 *
	 * @param array<int,array<string,mixed>> $pages Pages.
	 * @return array<string,mixed>
	 */
	private function canonical( array $pages ) {
		$label    = __( 'Canonical tags', 'site-audit-report' );
		$missing  = array();
		$multiple = array();
		$offsite  = array();
		foreach ( $pages as $page ) {
			if ( ! $page['canonical_count'] ) {
				$missing[] = $page['url'];
			} elseif ( $page['canonical_count'] > 1 ) {
				$multiple[] = $page['url'];
			} elseif ( \Site_Audit_Report\Url_Guard::domain_key( $page['canonical'] ) !== \Site_Audit_Report\Url_Guard::domain_key( $page['url'] ) ) {
				$offsite[] = $page['url'];
			}
		}
		if ( $offsite ) {
			return Check::make(
				Check::FAIL,
				self::CATEGORY,
				'canonical',
				$label,
				3,
				/* translators: %d: number of pages. */
				sprintf( __( 'Pages whose canonical tag points at another domain, which asks search engines to rank that domain instead: %d.', 'site-audit-report' ), count( $offsite ) ),
				array(
					'fix'    => __( 'Point each canonical tag at the page\'s own address, unless the content really does belong on the other domain.', 'site-audit-report' ),
					'effort' => 'low',
					'items'  => self::url_items( $offsite ),
				)
			);
		}
		if ( $missing || $multiple ) {
			return Check::make(
				Check::WARN,
				self::CATEGORY,
				'canonical',
				$label,
				3,
				/* translators: 1: pages with no canonical tag, 2: pages with several. */
				sprintf( __( 'Pages with no canonical tag: %1$d. Pages with more than one: %2$d.', 'site-audit-report' ), count( $missing ), count( $multiple ) ),
				array(
					'fix'    => __( 'Add one canonical tag to each page. Most SEO plugins do this once switched on.', 'site-audit-report' ),
					'effort' => 'low',
					'items'  => self::url_items( array_merge( $missing, $multiple ) ),
				)
			);
		}
		return Check::make( Check::PASS, self::CATEGORY, 'canonical', $label, 3, __( 'Each page checked has one canonical tag on its own domain.', 'site-audit-report' ) );
	}

	/**
	 * Image alt text.
	 *
	 * @param array<int,array<string,mixed>> $pages Pages.
	 * @return array<string,mixed>
	 */
	private function image_alt( array $pages ) {
		$label   = __( 'Image alt text', 'site-audit-report' );
		$total   = 0;
		$missing = 0;
		$samples = array();
		foreach ( $pages as $page ) {
			$total   += $page['images_total'];
			$missing += $page['images_no_alt'];
			$samples  = array_merge( $samples, $page['images_no_alt_samples'] );
		}
		if ( 0 === $total ) {
			return Check::make( Check::INFO, self::CATEGORY, 'image_alt', $label, 3, __( 'No images were found in the HTML of the pages checked, so there was nothing to measure. Images added by scripts are not seen.', 'site-audit-report' ) );
		}
		$share = (int) round( 100 * ( $total - $missing ) / $total );
		/* translators: 1: images missing alt text, 2: total images. */
		$evidence = sprintf( __( '%1$d of %2$d images have no alt attribute.', 'site-audit-report' ), $missing, $total );
		if ( 0 === $missing ) {
			return Check::make( Check::PASS, self::CATEGORY, 'image_alt', $label, 3, $evidence );
		}
		return Check::make(
			$share >= 80 ? Check::WARN : Check::FAIL,
			self::CATEGORY,
			'image_alt',
			$label,
			3,
			$evidence,
			array(
				'fix'    => __( 'Describe each meaningful image in its alt text. Use an empty alt attribute for images that are only decoration.', 'site-audit-report' ),
				'effort' => 'medium',
				'items'  => self::url_items( array_slice( array_unique( $samples ), 0, 8 ) ),
			)
		);
	}

	/**
	 * Structured data.
	 *
	 * @param array<int,array<string,mixed>> $pages Pages.
	 * @return array<string,mixed>
	 */
	private function schema( array $pages ) {
		$label  = __( 'Structured data (schema)', 'site-audit-report' );
		$types  = array();
		$errors = 0;
		foreach ( $pages as $page ) {
			$types   = array_merge( $types, $page['schema_types'] );
			$errors += $page['schema_errors'];
		}
		$types = array_values( array_unique( $types ) );

		if ( ! $types ) {
			return Check::make(
				Check::FAIL,
				self::CATEGORY,
				'schema',
				$label,
				3,
				$errors ? __( 'Structured data was found but could not be parsed.', 'site-audit-report' ) : __( 'No structured data was found on the pages checked.', 'site-audit-report' ),
				array(
					'fix'    => __( 'Add Organization or LocalBusiness schema with your name, address, phone and logo, so search engines and AI tools can identify the business.', 'site-audit-report' ),
					'effort' => 'medium',
				)
			);
		}
		/* translators: %s: list of schema types. */
		$evidence = sprintf( __( 'Found: %s.', 'site-audit-report' ), implode( ', ', array_slice( $types, 0, 12 ) ) );
		if ( $errors ) {
			/* translators: %d: number of blocks. */
			$evidence .= ' ' . sprintf( _n( '%d block could not be parsed.', '%d blocks could not be parsed.', $errors, 'site-audit-report' ), $errors );
			return Check::make(
				Check::WARN,
				self::CATEGORY,
				'schema',
				$label,
				3,
				$evidence,
				array(
					'fix'    => __( 'Correct the invalid JSON-LD blocks and test them with Google\'s Rich Results Test.', 'site-audit-report' ),
					'effort' => 'low',
				)
			);
		}
		return Check::make( Check::PASS, self::CATEGORY, 'schema', $label, 3, $evidence );
	}

	/**
	 * Mobile viewport.
	 *
	 * @param array<int,array<string,mixed>> $pages Pages.
	 * @return array<string,mixed>
	 */
	private function viewport( array $pages ) {
		$label = __( 'Mobile viewport', 'site-audit-report' );
		if ( false !== stripos( $pages[0]['viewport'], 'width=device-width' ) ) {
			return Check::make( Check::PASS, self::CATEGORY, 'viewport', $label, 3, __( 'The home page sets a mobile viewport.', 'site-audit-report' ) );
		}
		return Check::make(
			Check::FAIL,
			self::CATEGORY,
			'viewport',
			$label,
			3,
			__( 'The home page has no width=device-width viewport, so phones may show it zoomed out.', 'site-audit-report' ),
			array(
				'fix'    => __( 'Add <meta name="viewport" content="width=device-width, initial-scale=1"> to the page head.', 'site-audit-report' ),
				'effort' => 'low',
			)
		);
	}

	/**
	 * Page language.
	 *
	 * @param array<int,array<string,mixed>> $pages Pages.
	 * @return array<string,mixed>
	 */
	private function language( array $pages ) {
		$label = __( 'Page language', 'site-audit-report' );
		if ( '' !== $pages[0]['lang'] ) {
			/* translators: %s: language code. */
			return Check::make( Check::PASS, self::CATEGORY, 'lang', $label, 1, sprintf( __( 'The home page declares its language as %s.', 'site-audit-report' ), $pages[0]['lang'] ) );
		}
		return Check::make(
			Check::WARN,
			self::CATEGORY,
			'lang',
			$label,
			1,
			__( 'The home page does not declare a language.', 'site-audit-report' ),
			array(
				'fix'    => __( 'Add a lang attribute to the html tag, such as lang="en".', 'site-audit-report' ),
				'effort' => 'low',
			)
		);
	}

	/**
	 * Social sharing tags.
	 *
	 * @param array<int,array<string,mixed>> $pages Pages.
	 * @return array<string,mixed>
	 */
	private function social_preview( array $pages ) {
		$label   = __( 'Social sharing preview', 'site-audit-report' );
		$og      = $pages[0]['og'];
		$missing = array_diff( array( 'title', 'description', 'image' ), array_keys( $og ) );
		if ( ! $missing ) {
			return Check::make( Check::PASS, self::CATEGORY, 'og', $label, 2, __( 'The home page sets a title, description and image for social sharing.', 'site-audit-report' ) );
		}
		return Check::make(
			count( $missing ) === 3 ? Check::FAIL : Check::WARN,
			self::CATEGORY,
			'og',
			$label,
			2,
			/* translators: %s: missing tag names. */
			sprintf( __( 'The home page is missing Open Graph tags for: %s.', 'site-audit-report' ), implode( ', ', $missing ) ),
			array(
				'fix'    => __( 'Add Open Graph title, description and image tags so links look right when shared. Most SEO plugins can do this.', 'site-audit-report' ),
				'effort' => 'low',
			)
		);
	}

	/**
	 * Amount of readable text.
	 *
	 * @param array<int,array<string,mixed>> $pages Pages.
	 * @return array<string,mixed>
	 */
	private function content_depth( array $pages ) {
		$label = __( 'Home page content', 'site-audit-report' );
		$words = (int) $pages[0]['word_count'];
		if ( ! empty( $pages[0]['js_shell'] ) ) {
			return Check::not_checked( self::CATEGORY, 'content', $label, 2, __( 'The home page seems to build its content with JavaScript, and this scan reads only the HTML the server sends. Content could not be measured.', 'site-audit-report' ) );
		}
		/* translators: %d: number of words. */
		$evidence = sprintf( __( 'About %d words of readable text in the page HTML.', 'site-audit-report' ), $words );
		if ( $words >= 250 ) {
			return Check::make( Check::PASS, self::CATEGORY, 'content', $label, 2, $evidence );
		}
		return Check::make(
			$words >= 100 ? Check::WARN : Check::FAIL,
			self::CATEGORY,
			'content',
			$label,
			2,
			$evidence,
			array(
				'fix'    => __( 'Explain who you help, what you offer and where you work in plain text on the home page, so search engines have something to rank.', 'site-audit-report' ),
				'effort' => 'medium',
			)
		);
	}

	/**
	 * Turns URLs into report list items.
	 *
	 * @param string[] $urls URLs.
	 * @return array<int,array<string,string>>
	 */
	private static function url_items( array $urls ) {
		$items = array();
		foreach ( array_slice( array_values( array_unique( $urls ) ), 0, 10 ) as $url ) {
			$items[] = array(
				'text' => $url,
				'url'  => $url,
			);
		}
		return $items;
	}
}
