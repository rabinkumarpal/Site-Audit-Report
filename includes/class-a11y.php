<?php
/**
 * Accessibility quick check from a page's HTML: names, labels, alt text,
 * landmarks, headings, zoom, focus order and more, each mapped to WCAG.
 *
 * It reads markup only. Colour contrast, keyboard traps and focus visibility
 * need a real browser, and the report says so.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

use DOMElement;
use DOMNode;
use DOMXPath;

defined( 'ABSPATH' ) || exit;

/**
 * Accessibility checks.
 */
final class A11y {

	const MAX_SAMPLES = 8;

	/**
	 * Runs every check.
	 *
	 * @param DOMXPath $xpath XPath over the page.
	 * @return array{score:int|null,counts:array<string,int>,checks:array<int,array<string,mixed>>,limits:string}
	 */
	public static function analyze( DOMXPath $xpath ) {
		$checks = array(
			self::lang( $xpath ),
			self::title( $xpath ),
			self::img_alt( $xpath ),
			self::link_names( $xpath ),
			self::button_names( $xpath ),
			self::form_labels( $xpath ),
			self::zoom( $xpath ),
			self::aria_hidden_focus( $xpath ),
			self::main_landmark( $xpath ),
			self::headings( $xpath ),
			self::skip_link( $xpath ),
			self::vague_links( $xpath ),
			self::iframe_titles( $xpath ),
			self::tabindex( $xpath ),
			self::autoplay( $xpath ),
			self::duplicate_ids( $xpath ),
			self::tables( $xpath ),
		);
		$checks = array_values( array_filter( $checks ) );

		$counts = array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0 );
		$earned = 0.0;
		$total  = 0.0;
		foreach ( $checks as $check ) {
			++$counts[ $check['status'] ];
			if ( 'info' !== $check['status'] ) {
				$total  += $check['weight'];
				$earned += $check['weight'] * ( 'pass' === $check['status'] ? 1 : ( 'warn' === $check['status'] ? .5 : 0 ) );
			}
		}
		usort(
			$checks,
			static function ( $a, $b ) {
				$order = array( 'fail' => 0, 'warn' => 1, 'info' => 2, 'pass' => 3 );
				return array( $order[ $a['status'] ], -$a['weight'] ) <=> array( $order[ $b['status'] ], -$b['weight'] );
			}
		);

		return array(
			'score'  => $total > 0 ? (int) round( 100 * $earned / $total ) : null,
			'counts' => $counts,
			'checks' => $checks,
			'limits' => __( 'This reads the page\'s HTML. Colour contrast, keyboard traps and visible focus need a real browser: test them with a keyboard and a contrast checker, or run Google Lighthouse.', 'site-audit-report' ),
		);
	}

	/**
	 * Builds a result row.
	 *
	 * @param string   $id      ID.
	 * @param string   $label   What was checked.
	 * @param string   $wcag    WCAG success criterion.
	 * @param int      $weight  1 to 5.
	 * @param string   $status  pass|warn|fail|info.
	 * @param string   $detail  What was found.
	 * @param string   $fix     How to fix.
	 * @param string[] $samples Offending elements.
	 * @param int      $count   How many.
	 * @return array<string,mixed>
	 */
	private static function row( $id, $label, $wcag, $weight, $status, $detail, $fix = '', array $samples = array(), $count = 0 ) {
		return array(
			'id'      => $id,
			'label'   => $label,
			'wcag'    => $wcag,
			'weight'  => $weight,
			'status'  => $status,
			'detail'  => $detail,
			'fix'     => 'pass' === $status ? '' : $fix,
			'samples' => array_slice( $samples, 0, self::MAX_SAMPLES ),
			'count'   => $count ? $count : count( $samples ),
		);
	}

	/* ------------------------------------------------------------- Helpers */

	/**
	 * A short, readable description of an element for the report.
	 *
	 * @param DOMElement $el Element.
	 * @return string
	 */
	public static function describe( DOMElement $el ) {
		$out = '<' . strtolower( $el->nodeName );
		foreach ( array( 'id', 'class', 'type', 'name', 'href', 'src', 'role', 'placeholder', 'tabindex' ) as $attr ) {
			if ( $el->hasAttribute( $attr ) ) {
				$value = trim( (string) preg_replace( '/\s+/', ' ', $el->getAttribute( $attr ) ) );
				if ( 'class' === $attr ) {
					$value = implode( ' ', array_slice( explode( ' ', $value ), 0, 3 ) );
				}
				$out .= ' ' . $attr . '="' . mb_substr( $value, 0, 60 ) . '"';
			}
		}
		$out .= '>';
		$text = self::text( $el );
		if ( '' !== $text ) {
			$out .= mb_substr( $text, 0, 50 ) . ( mb_strlen( $text ) > 50 ? '…' : '' );
		}
		return mb_substr( $out, 0, 220 );
	}

	/**
	 * Visible text of an element, normalised.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private static function text( DOMNode $node ) {
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $node->textContent ) );
	}

	/**
	 * The accessible name, following the common rules: aria-labelledby,
	 * aria-label, content (including image alt text), then title.
	 *
	 * @param DOMElement $el    Element.
	 * @param DOMXPath   $xpath XPath.
	 * @return string
	 */
	public static function accessible_name( DOMElement $el, DOMXPath $xpath ) {
		$ids = trim( $el->getAttribute( 'aria-labelledby' ) );
		if ( '' !== $ids ) {
			$parts = array();
			foreach ( preg_split( '/\s+/', $ids ) as $id ) {
				$ref = $xpath->query( '//*[@id=' . self::xq( $id ) . ']' )->item( 0 );
				if ( $ref ) {
					$parts[] = self::text( $ref );
				}
			}
			$name = trim( implode( ' ', $parts ) );
			if ( '' !== $name ) {
				return $name;
			}
		}
		$label = trim( $el->getAttribute( 'aria-label' ) );
		if ( '' !== $label ) {
			return $label;
		}
		$text = self::text( $el );
		if ( '' !== $text ) {
			return $text;
		}
		foreach ( $xpath->query( './/img[@alt] | .//*[@aria-label] | .//svg/title', $el ) as $inner ) {
			$value = $inner instanceof DOMElement ? trim( $inner->hasAttribute( 'alt' ) ? $inner->getAttribute( 'alt' ) : ( $inner->hasAttribute( 'aria-label' ) ? $inner->getAttribute( 'aria-label' ) : $inner->textContent ) ) : '';
			if ( '' !== $value ) {
				return $value;
			}
		}
		return trim( $el->getAttribute( 'title' ) );
	}

	/**
	 * Whether an element is hidden from assistive technology or the page.
	 *
	 * @param DOMElement $el Element.
	 * @return bool
	 */
	private static function hidden( DOMElement $el ) {
		for ( $node = $el; $node instanceof DOMElement; $node = $node->parentNode ) {
			if ( 'true' === strtolower( $node->getAttribute( 'aria-hidden' ) ) || $node->hasAttribute( 'hidden' ) || preg_match( '/display\s*:\s*none|visibility\s*:\s*hidden/i', $node->getAttribute( 'style' ) ) ) {
				return true;
			}
			if ( in_array( strtolower( $node->nodeName ), array( 'template', 'noscript' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Quotes a string for XPath.
	 *
	 * @param string $s Value.
	 * @return string
	 */
	private static function xq( $s ) {
		if ( false === strpos( $s, "'" ) ) {
			return "'" . $s . "'";
		}
		return 'concat(\'' . str_replace( "'", "',\"'\",'", $s ) . '\')';
	}

	/* -------------------------------------------------------------- Checks */

	/**
	 * Page language.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>
	 */
	private static function lang( DOMXPath $xpath ) {
		$html = $xpath->query( '//html' )->item( 0 );
		$lang = $html instanceof DOMElement ? trim( $html->getAttribute( 'lang' ) ) : '';
		$ok   = (bool) preg_match( '/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $lang );
		return self::row(
			'lang',
			__( 'Page language', 'site-audit-report' ),
			'3.1.1',
			3,
			$ok ? 'pass' : 'fail',
			$ok ? sprintf( /* translators: %s: lang. */ __( 'lang="%s" lets screen readers use the right voice.', 'site-audit-report' ), $lang ) : ( '' === $lang ? __( 'The html tag has no lang attribute, so screen readers may read the page in the wrong language.', 'site-audit-report' ) : sprintf( /* translators: %s: lang. */ __( 'lang="%s" is not a valid language code.', 'site-audit-report' ), $lang ) ),
			__( 'Add lang="en" (or your language) to the html tag.', 'site-audit-report' )
		);
	}

	/**
	 * Document title.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>
	 */
	private static function title( DOMXPath $xpath ) {
		$t  = $xpath->query( '//title[not(ancestor::svg)]' )->item( 0 );
		$ok = $t && '' !== self::text( $t );
		return self::row(
			'title',
			__( 'Page title', 'site-audit-report' ),
			'2.4.2',
			2,
			$ok ? 'pass' : 'fail',
			$ok ? __( 'The page has a title, which is the first thing a screen reader announces.', 'site-audit-report' ) : __( 'The page has no title.', 'site-audit-report' ),
			__( 'Give every page a unique, descriptive title.', 'site-audit-report' )
		);
	}

	/**
	 * Images without alt.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>
	 */
	private static function img_alt( DOMXPath $xpath ) {
		$bad   = array();
		$total = 0;
		foreach ( $xpath->query( '//body//img' ) as $img ) {
			if ( ! $img instanceof DOMElement || self::hidden( $img ) || in_array( strtolower( $img->getAttribute( 'role' ) ), array( 'presentation', 'none' ), true ) ) {
				continue;
			}
			++$total;
			if ( ! $img->hasAttribute( 'alt' ) && '' === trim( $img->getAttribute( 'aria-label' ) ) && '' === trim( $img->getAttribute( 'aria-labelledby' ) ) ) {
				$bad[] = self::describe( $img );
			}
		}
		if ( ! $total ) {
			return self::row( 'img_alt', __( 'Image text alternatives', 'site-audit-report' ), '1.1.1', 5, 'pass', __( 'No images to check.', 'site-audit-report' ) );
		}
		return self::row(
			'img_alt',
			__( 'Image text alternatives', 'site-audit-report' ),
			'1.1.1',
			5,
			$bad ? 'fail' : 'pass',
			$bad ? sprintf( /* translators: 1: count, 2: total. */ __( '%1$d of %2$d images have no alt attribute. Screen readers may read out the file name instead.', 'site-audit-report' ), count( $bad ), $total ) : sprintf( /* translators: %d: total. */ __( 'All %d images have an alt attribute.', 'site-audit-report' ), $total ),
			__( 'Describe what each image shows or does in its alt text. Use alt="" for purely decorative images.', 'site-audit-report' ),
			$bad
		);
	}

	/**
	 * Links without a name.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>
	 */
	private static function link_names( DOMXPath $xpath ) {
		$bad   = array();
		$total = 0;
		foreach ( $xpath->query( '//body//a[@href]' ) as $a ) {
			if ( ! $a instanceof DOMElement || self::hidden( $a ) ) {
				continue;
			}
			++$total;
			if ( '' === self::accessible_name( $a, $xpath ) ) {
				$bad[] = self::describe( $a );
			}
		}
		return self::row(
			'link_name',
			__( 'Links have a name', 'site-audit-report' ),
			'2.4.4',
			5,
			$bad ? 'fail' : 'pass',
			$bad ? sprintf( /* translators: 1: count, 2: total. */ __( '%1$d of %2$d links have no text, label or image alt, so a screen reader just says "link".', 'site-audit-report' ), count( $bad ), $total ) : sprintf( /* translators: %d: total. */ __( 'All %d links have an accessible name.', 'site-audit-report' ), $total ),
			__( 'Give icon links an aria-label (for example aria-label="Instagram"), or alt text on the image inside.', 'site-audit-report' ),
			$bad
		);
	}

	/**
	 * Buttons without a name.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>
	 */
	private static function button_names( DOMXPath $xpath ) {
		$bad   = array();
		$total = 0;
		foreach ( $xpath->query( '//body//button | //body//*[@role="button"] | //body//input[@type="submit" or @type="button" or @type="reset" or @type="image"]' ) as $b ) {
			if ( ! $b instanceof DOMElement || self::hidden( $b ) ) {
				continue;
			}
			++$total;
			$name = 'input' === strtolower( $b->nodeName ) ? trim( $b->getAttribute( 'value' ) . $b->getAttribute( 'alt' ) . $b->getAttribute( 'aria-label' ) . $b->getAttribute( 'title' ) ) : self::accessible_name( $b, $xpath );
			if ( 'input' === strtolower( $b->nodeName ) && '' === $name && in_array( strtolower( $b->getAttribute( 'type' ) ), array( 'submit', 'reset' ), true ) ) {
				$name = 'default'; // Browsers supply "Submit" and "Reset".
			}
			if ( '' === $name ) {
				$bad[] = self::describe( $b );
			}
		}
		if ( ! $total ) {
			return null;
		}
		return self::row(
			'button_name',
			__( 'Buttons have a name', 'site-audit-report' ),
			'4.1.2',
			5,
			$bad ? 'fail' : 'pass',
			$bad ? sprintf( /* translators: 1: count, 2: total. */ __( '%1$d of %2$d buttons have no name, typically icon-only menu, close or search buttons.', 'site-audit-report' ), count( $bad ), $total ) : sprintf( /* translators: %d: total. */ __( 'All %d buttons have an accessible name.', 'site-audit-report' ), $total ),
			__( 'Add visible text or an aria-label such as aria-label="Open menu".', 'site-audit-report' ),
			$bad
		);
	}

	/**
	 * Form fields without a label.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>|null
	 */
	private static function form_labels( DOMXPath $xpath ) {
		$bad         = array();
		$placeholder = 0;
		$total       = 0;
		$fields      = $xpath->query( '//body//input[not(@type) or not(contains(" hidden submit button reset image ", concat(" ", @type, " ")))] | //body//select | //body//textarea' );
		foreach ( $fields as $f ) {
			if ( ! $f instanceof DOMElement || self::hidden( $f ) ) {
				continue;
			}
			++$total;
			$named = '' !== trim( $f->getAttribute( 'aria-label' ) ) || '' !== trim( $f->getAttribute( 'aria-labelledby' ) ) || '' !== trim( $f->getAttribute( 'title' ) );
			$id    = trim( $f->getAttribute( 'id' ) );
			if ( ! $named && '' !== $id ) {
				$label = $xpath->query( '//label[@for=' . self::xq( $id ) . ']' )->item( 0 );
				$named = $label && '' !== self::text( $label );
			}
			if ( ! $named ) {
				for ( $p = $f->parentNode; $p instanceof DOMElement; $p = $p->parentNode ) {
					if ( 'label' === strtolower( $p->nodeName ) ) {
						$named = '' !== self::text( $p );
						break;
					}
				}
			}
			if ( ! $named ) {
				$bad[] = self::describe( $f );
				$placeholder += '' !== trim( $f->getAttribute( 'placeholder' ) ) ? 1 : 0;
			}
		}
		if ( ! $total ) {
			return null;
		}
		$detail = $bad
			/* translators: 1: count, 2: total. */
			? sprintf( __( '%1$d of %2$d form fields have no label.', 'site-audit-report' ), count( $bad ), $total ) . ( $placeholder ? ' ' . sprintf( /* translators: %d: count. */ _n( '%d relies on placeholder text, which disappears while typing and is not a label.', '%d rely on placeholder text, which disappears while typing and is not a label.', $placeholder, 'site-audit-report' ), $placeholder ) : '' )
			/* translators: %d: total. */
			: sprintf( __( 'All %d form fields are labelled.', 'site-audit-report' ), $total );
		return self::row( 'form_labels', __( 'Form fields have labels', 'site-audit-report' ), '1.3.1', 5, $bad ? 'fail' : 'pass', $detail, __( 'Connect a <label for="…"> to each field, or add aria-label when a visible label is not possible.', 'site-audit-report' ), $bad );
	}

	/**
	 * Zoom allowed.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>
	 */
	private static function zoom( DOMXPath $xpath ) {
		$vp      = $xpath->query( '//meta[@name="viewport"]' )->item( 0 );
		$content = $vp instanceof DOMElement ? strtolower( $vp->getAttribute( 'content' ) ) : '';
		$blocked = (bool) preg_match( '/user-scalable\s*=\s*(no|0)/', $content );
		if ( preg_match( '/maximum-scale\s*=\s*([\d.]+)/', $content, $m ) && (float) $m[1] < 2 ) {
			$blocked = true;
		}
		return self::row(
			'zoom',
			__( 'Pinch-zoom allowed', 'site-audit-report' ),
			'1.4.4',
			4,
			$blocked ? 'fail' : 'pass',
			$blocked ? sprintf( /* translators: %s: viewport. */ __( 'The viewport stops people zooming in on phones: "%s".', 'site-audit-report' ), $content ) : __( 'Visitors can zoom the page.', 'site-audit-report' ),
			__( 'Remove user-scalable=no and any maximum-scale below 2 from the viewport tag.', 'site-audit-report' )
		);
	}

	/**
	 * Focusable elements hidden from screen readers.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>
	 */
	private static function aria_hidden_focus( DOMXPath $xpath ) {
		$bad = array();
		foreach ( $xpath->query( '//*[@aria-hidden="true"]//*[self::a[@href] or self::button or self::input or self::select or self::textarea or @tabindex]' ) as $el ) {
			if ( $el instanceof DOMElement && '-1' !== trim( $el->getAttribute( 'tabindex' ) ) && ! $el->hasAttribute( 'disabled' ) && 'hidden' !== strtolower( $el->getAttribute( 'type' ) ) ) {
				$bad[] = self::describe( $el );
			}
		}
		return self::row(
			'aria_hidden_focus',
			__( 'No focusable items hidden from screen readers', 'site-audit-report' ),
			'4.1.2',
			4,
			$bad ? 'fail' : 'pass',
			$bad ? sprintf( /* translators: %d: count. */ __( '%d links or controls sit inside aria-hidden="true", so keyboard users can reach them but screen readers stay silent.', 'site-audit-report' ), count( $bad ) ) : __( 'Nothing focusable is hidden with aria-hidden.', 'site-audit-report' ),
			__( 'Add tabindex="-1" to those items, or remove aria-hidden from the container.', 'site-audit-report' ),
			$bad
		);
	}

	/**
	 * Main landmark.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>
	 */
	private static function main_landmark( DOMXPath $xpath ) {
		$main = $xpath->query( '//main | //*[@role="main"]' )->length;
		$nav  = $xpath->query( '//nav | //*[@role="navigation"]' )->length;
		$ok   = $main >= 1;
		return self::row(
			'landmarks',
			__( 'Page landmarks', 'site-audit-report' ),
			'1.3.1',
			3,
			$ok ? ( 1 === $main ? 'pass' : 'warn' ) : 'warn',
			$ok
				? ( 1 === $main
					/* translators: %d: nav count. */
					? sprintf( __( 'One main region and %d navigation region(s), so screen-reader users can jump straight to the content.', 'site-audit-report' ), $nav )
					/* translators: %d: count. */
					: sprintf( __( '%d main regions. There should be exactly one.', 'site-audit-report' ), $main ) )
				: __( 'No <main> region, so screen-reader users cannot jump straight to the content.', 'site-audit-report' ),
			__( 'Wrap the page content in one <main> element and menus in <nav>.', 'site-audit-report' )
		);
	}

	/**
	 * Headings.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>
	 */
	private static function headings( DOMXPath $xpath ) {
		$h1    = 0;
		$prev  = 0;
		$skips = array();
		$empty = array();
		foreach ( $xpath->query( '//body//h1|//body//h2|//body//h3|//body//h4|//body//h5|//body//h6' ) as $h ) {
			if ( ! $h instanceof DOMElement || self::hidden( $h ) ) {
				continue;
			}
			$level = (int) substr( $h->nodeName, 1 );
			$h1   += 1 === $level ? 1 : 0;
			if ( '' === self::accessible_name( $h, $xpath ) ) {
				$empty[] = self::describe( $h );
			}
			if ( $prev && $level > $prev + 1 ) {
				$skips[] = 'H' . $prev . ' → ' . self::describe( $h );
			}
			$prev = $level;
		}
		$problems = array();
		if ( 1 !== $h1 ) {
			/* translators: %d: count. */
			$problems[] = sprintf( _n( '%d H1 (there should be one)', '%d H1s (there should be one)', $h1, 'site-audit-report' ), $h1 );
		}
		if ( $skips ) {
			/* translators: %d: count. */
			$problems[] = sprintf( __( '%d skipped levels', 'site-audit-report' ), count( $skips ) );
		}
		if ( $empty ) {
			/* translators: %d: count. */
			$problems[] = sprintf( __( '%d empty headings', 'site-audit-report' ), count( $empty ) );
		}
		return self::row(
			'headings',
			__( 'Heading structure', 'site-audit-report' ),
			'1.3.1',
			3,
			$problems ? ( $empty || 0 === $h1 ? 'fail' : 'warn' ) : 'pass',
			$problems ? implode( '; ', $problems ) . '. ' . __( 'Screen-reader users navigate by headings.', 'site-audit-report' ) : __( 'One H1 and headings step down one level at a time.', 'site-audit-report' ),
			__( 'Use one H1, step down one level at a time, and never leave a heading empty. Style text with CSS rather than choosing heading levels for their size.', 'site-audit-report' ),
			array_merge( $empty, $skips )
		);
	}

	/**
	 * Skip link.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>
	 */
	private static function skip_link( DOMXPath $xpath ) {
		$found = false;
		$i     = 0;
		foreach ( $xpath->query( '//body//a[@href]' ) as $a ) {
			if ( ++$i > 6 ) {
				break;
			}
			$href = $a instanceof DOMElement ? trim( $a->getAttribute( 'href' ) ) : '';
			if ( strlen( $href ) > 1 && '#' === $href[0] && $xpath->query( '//*[@id=' . self::xq( substr( $href, 1 ) ) . ']' )->length ) {
				$found = true;
				break;
			}
		}
		return self::row(
			'skip_link',
			__( 'Skip to content link', 'site-audit-report' ),
			'2.4.1',
			2,
			$found ? 'pass' : 'warn',
			$found ? __( 'Keyboard users can skip past the menu to the content.', 'site-audit-report' ) : __( 'No "skip to content" link near the top, so keyboard users tab through the whole menu on every page.', 'site-audit-report' ),
			__( 'Add a link such as <a href="#main" class="skip-link">Skip to content</a> as the first item in the body; it can stay hidden until focused.', 'site-audit-report' )
		);
	}

	/**
	 * Vague link text.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>
	 */
	private static function vague_links( DOMXPath $xpath ) {
		$vague = array( 'click here', 'here', 'read more', 'more', 'learn more', 'link', 'this', 'details', 'continue', 'go', 'view', 'view more', 'see more', 'more info', 'info' );
		$bad   = array();
		$words = array();
		foreach ( $xpath->query( '//body//a[@href]' ) as $a ) {
			if ( ! $a instanceof DOMElement || self::hidden( $a ) || '' !== trim( $a->getAttribute( 'aria-label' ) ) || '' !== trim( $a->getAttribute( 'aria-labelledby' ) ) ) {
				continue;
			}
			$text = strtolower( trim( (string) preg_replace( '/[^\p{L}\p{N} ]+/u', '', self::text( $a ) ) ) );
			if ( in_array( $text, $vague, true ) ) {
				$bad[]          = self::describe( $a );
				$words[ $text ] = true;
			}
		}
		return self::row(
			'vague_links',
			__( 'Descriptive link text', 'site-audit-report' ),
			'2.4.4',
			2,
			count( $bad ) > 2 ? 'warn' : 'pass',
			$bad ? sprintf( /* translators: 1: count, 2: words. */ __( '%1$d links just say "%2$s". Out of context, as in a screen reader\'s link list, they all sound the same.', 'site-audit-report' ), count( $bad ), implode( '", "', array_keys( $words ) ) ) : __( 'Link text describes where links go.', 'site-audit-report' ),
			__( 'Say where the link goes ("Read the case study"), or add an aria-label that does.', 'site-audit-report' ),
			$bad
		);
	}

	/**
	 * Iframe titles.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>|null
	 */
	private static function iframe_titles( DOMXPath $xpath ) {
		$bad   = array();
		$total = 0;
		foreach ( $xpath->query( '//body//iframe' ) as $f ) {
			if ( ! $f instanceof DOMElement || self::hidden( $f ) ) {
				continue;
			}
			++$total;
			if ( '' === trim( $f->getAttribute( 'title' ) . $f->getAttribute( 'aria-label' ) ) ) {
				$bad[] = self::describe( $f );
			}
		}
		if ( ! $total ) {
			return null;
		}
		return self::row( 'iframe_title', __( 'Embedded frames have titles', 'site-audit-report' ), '4.1.2', 2, $bad ? 'warn' : 'pass', $bad ? sprintf( /* translators: 1: count, 2: total. */ __( '%1$d of %2$d embeds (maps, videos, forms) have no title.', 'site-audit-report' ), count( $bad ), $total ) : __( 'Every embed has a title.', 'site-audit-report' ), __( 'Add title="Map of our office" (or similar) to each iframe.', 'site-audit-report' ), $bad );
	}

	/**
	 * Positive tabindex.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>
	 */
	private static function tabindex( DOMXPath $xpath ) {
		$bad = array();
		foreach ( $xpath->query( '//*[@tabindex]' ) as $el ) {
			if ( $el instanceof DOMElement && (int) $el->getAttribute( 'tabindex' ) > 0 ) {
				$bad[] = self::describe( $el );
			}
		}
		return self::row( 'tabindex', __( 'Natural keyboard order', 'site-audit-report' ), '2.4.3', 2, $bad ? 'warn' : 'pass', $bad ? sprintf( /* translators: %d: count. */ __( '%d elements use a positive tabindex, which makes the keyboard jump around the page.', 'site-audit-report' ), count( $bad ) ) : __( 'No positive tabindex values.', 'site-audit-report' ), __( 'Use tabindex="0" or "-1" only, and order the HTML the way it should be read.', 'site-audit-report' ), $bad );
	}

	/**
	 * Autoplaying sound.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>|null
	 */
	private static function autoplay( DOMXPath $xpath ) {
		$media = $xpath->query( '//video | //audio' );
		if ( ! $media->length ) {
			return null;
		}
		$bad = array();
		foreach ( $media as $m ) {
			if ( $m instanceof DOMElement && $m->hasAttribute( 'autoplay' ) && ! $m->hasAttribute( 'muted' ) ) {
				$bad[] = self::describe( $m );
			}
		}
		return self::row( 'autoplay', __( 'No sound playing on its own', 'site-audit-report' ), '1.4.2', 3, $bad ? 'fail' : 'pass', $bad ? __( 'Video or audio starts with sound by itself, which drowns out screen readers.', 'site-audit-report' ) : __( 'No media autoplays with sound.', 'site-audit-report' ), __( 'Add muted to autoplaying video, or let visitors press play.', 'site-audit-report' ), $bad );
	}

	/**
	 * Duplicate IDs that labels or ARIA point at.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>
	 */
	private static function duplicate_ids( DOMXPath $xpath ) {
		$seen = array();
		foreach ( $xpath->query( '//*[@id]' ) as $el ) {
			$id = $el instanceof DOMElement ? $el->getAttribute( 'id' ) : '';
			if ( '' !== $id ) {
				$seen[ $id ] = ( $seen[ $id ] ?? 0 ) + 1;
			}
		}
		$dupes = array_keys( array_filter( $seen, static fn( $n ) => $n > 1 ) );
		$refs  = array();
		foreach ( $xpath->query( '//label[@for] | //*[@aria-labelledby] | //*[@aria-describedby] | //*[@aria-controls]' ) as $el ) {
			if ( $el instanceof DOMElement ) {
				foreach ( array( 'for', 'aria-labelledby', 'aria-describedby', 'aria-controls' ) as $attr ) {
					foreach ( preg_split( '/\s+/', trim( $el->getAttribute( $attr ) ) ) as $ref ) {
						if ( '' !== $ref ) {
							$refs[ $ref ] = true;
						}
					}
				}
			}
		}
		$broken = array_values( array_filter( $dupes, static fn( $d ) => isset( $refs[ $d ] ) ) );
		return self::row(
			'duplicate_ids',
			__( 'Unique IDs for labels and ARIA', 'site-audit-report' ),
			'1.3.1',
			2,
			$broken ? 'warn' : 'pass',
			$broken ? sprintf( /* translators: %d: count. */ __( '%d IDs used by labels or ARIA appear more than once, so the wrong element may be announced.', 'site-audit-report' ), count( $broken ) ) : ( $dupes ? sprintf( /* translators: %d: count. */ __( '%d duplicate IDs, but none are used by labels or ARIA.', 'site-audit-report' ), count( $dupes ) ) : __( 'IDs are unique.', 'site-audit-report' ) ),
			__( 'Make every id unique on the page, especially those referenced by labels and aria attributes.', 'site-audit-report' ),
			array_map( static fn( $d ) => 'id="' . $d . '"', $broken )
		);
	}

	/**
	 * Data tables with headers.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>|null
	 */
	private static function tables( DOMXPath $xpath ) {
		$bad   = array();
		$total = 0;
		foreach ( $xpath->query( '//body//table' ) as $t ) {
			if ( ! $t instanceof DOMElement || in_array( strtolower( $t->getAttribute( 'role' ) ), array( 'presentation', 'none' ), true ) || $xpath->query( './/tr', $t )->length < 2 ) {
				continue;
			}
			++$total;
			if ( ! $xpath->query( './/th', $t )->length ) {
				$bad[] = self::describe( $t );
			}
		}
		if ( ! $total ) {
			return null;
		}
		return self::row( 'tables', __( 'Data tables have headers', 'site-audit-report' ), '1.3.1', 2, $bad ? 'warn' : 'pass', $bad ? sprintf( /* translators: 1: count, 2: total. */ __( '%1$d of %2$d tables have no header cells, so screen readers cannot say which column a value belongs to.', 'site-audit-report' ), count( $bad ), $total ) : __( 'Tables use header cells.', 'site-audit-report' ), __( 'Use <th> for header cells, or role="presentation" on layout tables.', 'site-audit-report' ), $bad );
	}
}
