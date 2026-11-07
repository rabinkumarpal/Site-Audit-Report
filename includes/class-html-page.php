<?php
/**
 * Turns fetched HTML into a plain summary of what the checks need.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

use DOMDocument;
use DOMElement;
use DOMXPath;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM extension property names.

/**
 * HTML parser. Output is untrusted page content and must be escaped when shown.
 */
final class Html_Page {

	const MAX_LINKS = 400;

	/**
	 * Client-side tools recognisable from a page's source.
	 */
	const TRACKERS = array(
		'Google Analytics 4'            => '/gtag\/js\?id=G-|[\'"]G-[A-Z0-9]{6,}[\'"]/',
		'Universal Analytics (retired)' => '/google-analytics\.com\/analytics\.js|[\'"]UA-\d{4,}-\d+[\'"]/',
		'Google Tag Manager'            => '/googletagmanager\.com\/gtm\.js|[\'"]GTM-[A-Z0-9]{4,}[\'"]/',
		'Google Ads'                    => '/gtag\/js\?id=AW-|[\'"]AW-\d{6,}[\'"]|googleadservices\.com/',
		'Meta Pixel'                    => '/connect\.facebook\.net\/[^"\']*fbevents\.js/',
		'LinkedIn Insight'              => '/snap\.licdn\.com/',
		'TikTok Pixel'                  => '/analytics\.tiktok\.com/',
		'Microsoft Clarity'             => '/clarity\.ms\/tag/',
		'Microsoft Advertising'         => '/bat\.bing\.com/',
		'Hotjar'                        => '/static\.hotjar\.com/',
		'Plausible'                     => '/plausible\.io\/js/',
		'Fathom'                        => '/cdn\.usefathom\.com/',
		'Matomo'                        => '/matomo\.(js|php)|piwik\.js/',
		'Mixpanel'                      => '/cdn\.mxpnl\.com|mixpanel\.com\/libs/',
		'Segment'                       => '/cdn\.segment\.com/',
		'HubSpot'                       => '/js\.hs-scripts\.com|js\.hs-analytics\.net/',
	);

	/**
	 * Cookie consent tools recognisable from a page's source.
	 */
	const CONSENT_TOOLS = array(
		'Cookiebot'     => '/consent\.cookiebot\.com/',
		'OneTrust'      => '/cdn\.cookielaw\.org|onetrust/i',
		'CookieYes'     => '/cdn-cookieyes\.com|cookieyes/i',
		'Complianz'     => '/complianz/i',
		'Termly'        => '/app\.termly\.io/',
		'iubenda'       => '/iubenda\.com/',
		'Osano'         => '/osano\.com/',
		'Cookie Notice' => '/cookie-notice|cookie-law-info|moove_gdpr/i',
	);

	/**
	 * Social networks by host.
	 */
	const SOCIAL = array(
		'facebook.com'  => 'Facebook',
		'instagram.com' => 'Instagram',
		'linkedin.com'  => 'LinkedIn',
		'x.com'         => 'X',
		'twitter.com'   => 'X',
		'youtube.com'   => 'YouTube',
		'tiktok.com'    => 'TikTok',
		'pinterest.com' => 'Pinterest',
		'threads.net'   => 'Threads',
	);

	/**
	 * Parses a page.
	 *
	 * @param string $html HTML.
	 * @param string $url  The page's final URL.
	 * @return array<string,mixed>
	 */
	public static function parse( $html, $url ) {
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// The prefix makes libxml read the document as UTF-8. LIBXML_NONET blocks network access while parsing.
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new DOMXPath( $dom );
		$base  = self::base_url( $xpath, $url );

		$page = array(
			'url'                   => $url,
			'title'                 => '',
			'title_count'           => 0,
			'description'           => '',
			'description_count'     => 0,
			'robots'                => '',
			'canonical'             => '',
			'canonical_count'       => 0,
			'lang'                  => '',
			'viewport'              => '',
			'h1'                    => array(),
			'heading_counts'        => array(),
			'heading_skips'         => 0,
			'images_total'          => 0,
			'images_no_alt'         => 0,
			'images_no_alt_samples' => array(),
			'word_count'            => 0,
			'internal_links'        => array(),
			'external_links'        => array(),
			'schema_types'          => array(),
			'schema_errors'         => 0,
			'business'              => array(),
			'og'                    => array(),
			'twitter_card'          => '',
			'tel_links'             => array(),
			'mail_links'            => 0,
			'forms'                 => 0,
			'feed'                  => '',
			'mixed_content'         => array(),
			'social'                => array(),
			'trackers'              => array(),
			'consent_tools'         => array(),
			'dates'                 => array(),
			'copyright_year'        => 0,
			'js_shell'              => false,
			'text'                  => '',
		);

		self::read_head( $xpath, $base, $page );
		self::read_headings( $xpath, $page );
		self::read_images( $xpath, $base, $page );
		self::read_links( $xpath, $base, $page );
		self::read_schema( $xpath, $page );
		self::read_dates( $xpath, $page );
		self::detect_tools( $html, $page );
		self::read_text( $dom, $xpath, $page );

		return $page;
	}

	/**
	 * Reads the title and meta tags.
	 *
	 * @param DOMXPath            $xpath XPath.
	 * @param string              $base  Base URL.
	 * @param array<string,mixed> $page  Page summary.
	 * @return void
	 */
	private static function read_head( DOMXPath $xpath, $base, array &$page ) {
		$titles              = $xpath->query( '//head/title | //title[not(ancestor::svg)]' );
		$page['title_count'] = $titles ? $titles->length : 0;
		if ( $page['title_count'] ) {
			$page['title'] = self::clean( $titles->item( 0 )->textContent, 300 );
		}

		$html = $xpath->query( '//html' )->item( 0 );
		if ( $html instanceof DOMElement ) {
			$page['lang'] = self::clean( $html->getAttribute( 'lang' ), 20 );
		}

		foreach ( $xpath->query( '//meta' ) as $meta ) {
			if ( ! $meta instanceof DOMElement ) {
				continue;
			}
			$name    = strtolower( $meta->getAttribute( 'name' ) );
			$prop    = strtolower( $meta->getAttribute( 'property' ) );
			$content = self::clean( $meta->getAttribute( 'content' ), 500 );

			if ( 'description' === $name ) {
				++$page['description_count'];
				if ( '' === $page['description'] ) {
					$page['description'] = $content;
				}
			} elseif ( 'robots' === $name ) {
				$page['robots'] = trim( $page['robots'] . ' ' . strtolower( $content ) );
			} elseif ( 'viewport' === $name ) {
				$page['viewport'] = $content;
			} elseif ( 'twitter:card' === $name ) {
				$page['twitter_card'] = $content;
			}
			if ( 0 === strpos( $prop, 'og:' ) && '' !== $content ) {
				$page['og'][ substr( $prop, 3 ) ] = $content;
			}
			if ( in_array( $prop, array( 'article:modified_time', 'article:published_time', 'og:updated_time' ), true ) ) {
				$page['dates'][] = $content;
			}
		}

		foreach ( $xpath->query( '//link[@rel]' ) as $link ) {
			if ( ! $link instanceof DOMElement ) {
				continue;
			}
			$rel  = strtolower( $link->getAttribute( 'rel' ) );
			$href = trim( $link->getAttribute( 'href' ) );
			if ( 'canonical' === $rel ) {
				++$page['canonical_count'];
				if ( '' === $page['canonical'] ) {
					$page['canonical'] = self::absolute( $href, $base );
				}
			} elseif ( 'alternate' === $rel && false !== stripos( $link->getAttribute( 'type' ), 'rss' ) && '' === $page['feed'] ) {
				$page['feed'] = self::absolute( $href, $base );
			}
		}
	}

	/**
	 * Reads headings and spots skipped levels.
	 *
	 * @param DOMXPath            $xpath XPath.
	 * @param array<string,mixed> $page  Page summary.
	 * @return void
	 */
	private static function read_headings( DOMXPath $xpath, array &$page ) {
		$last = 0;
		foreach ( $xpath->query( '//h1|//h2|//h3|//h4|//h5|//h6' ) as $heading ) {
			$level                                  = (int) substr( $heading->nodeName, 1 );
			$page['heading_counts'][ 'h' . $level ] = ( $page['heading_counts'][ 'h' . $level ] ?? 0 ) + 1;
			if ( 1 === $level && count( $page['h1'] ) < 5 ) {
				$page['h1'][] = self::clean( $heading->textContent, 200 );
			}
			if ( $last && $level > $last + 1 ) {
				++$page['heading_skips'];
			}
			$last = $level;
		}
	}

	/**
	 * Reads images: missing alt attributes and insecure sources.
	 *
	 * @param DOMXPath            $xpath XPath.
	 * @param string              $base  Base URL.
	 * @param array<string,mixed> $page  Page summary.
	 * @return void
	 */
	private static function read_images( DOMXPath $xpath, $base, array &$page ) {
		foreach ( $xpath->query( '//img' ) as $img ) {
			if ( ! $img instanceof DOMElement ) {
				continue;
			}
			++$page['images_total'];
			if ( ! $img->hasAttribute( 'alt' ) && 'presentation' !== strtolower( $img->getAttribute( 'role' ) ) && 'true' !== $img->getAttribute( 'aria-hidden' ) ) {
				++$page['images_no_alt'];
				if ( count( $page['images_no_alt_samples'] ) < 5 ) {
					$src = self::absolute( trim( $img->getAttribute( 'src' ) ), $base );
					if ( '' !== $src ) {
						$page['images_no_alt_samples'][] = $src;
					}
				}
			}
		}

		if ( 0 === strpos( $page['url'], 'https://' ) ) {
			foreach ( $xpath->query( '//img[@src]|//script[@src]|//iframe[@src]|//video[@src]|//audio[@src]|//source[@src]|//link[@rel="stylesheet"][@href]' ) as $node ) {
				if ( ! $node instanceof DOMElement ) {
					continue;
				}
				$src = trim( $node->getAttribute( $node->hasAttribute( 'src' ) ? 'src' : 'href' ) );
				if ( 0 === stripos( $src, 'http://' ) && count( $page['mixed_content'] ) < 10 ) {
					$page['mixed_content'][] = self::clean( $src, 300 );
				}
			}
		}
	}

	/**
	 * Reads links and sorts them into internal, external, social and contact.
	 *
	 * @param DOMXPath            $xpath XPath.
	 * @param string              $base  Base URL.
	 * @param array<string,mixed> $page  Page summary.
	 * @return void
	 */
	private static function read_links( DOMXPath $xpath, $base, array &$page ) {
		$own_domain = Url_Guard::domain_key( $page['url'] );
		$seen       = array();

		foreach ( $xpath->query( '//a[@href]' ) as $anchor ) {
			if ( ! $anchor instanceof DOMElement ) {
				continue;
			}
			$href = trim( $anchor->getAttribute( 'href' ) );
			if ( 0 === stripos( $href, 'tel:' ) ) {
				if ( count( $page['tel_links'] ) < 10 ) {
					$page['tel_links'][] = self::clean( rawurldecode( substr( $href, 4 ) ), 40 );
				}
				continue;
			}
			if ( 0 === stripos( $href, 'mailto:' ) ) {
				++$page['mail_links'];
				continue;
			}

			$absolute = self::absolute( $href, $base );
			if ( '' === $absolute ) {
				continue;
			}
			$absolute = strtok( $absolute, '#' );
			if ( isset( $seen[ $absolute ] ) || count( $seen ) >= self::MAX_LINKS ) {
				continue;
			}
			$seen[ $absolute ] = true;

			$entry = array(
				'url'  => $absolute,
				'text' => self::clean( $anchor->textContent, 80 ),
			);

			if ( Url_Guard::domain_key( $absolute ) === $own_domain ) {
				$page['internal_links'][] = $entry;
			} else {
				$page['external_links'][] = $entry;
				self::note_social( $absolute, $page );
			}
		}

		$page['forms'] = $xpath->query( '//form' )->length;
	}

	/**
	 * Records a link if it points at a social profile rather than a share button.
	 *
	 * @param string              $url  URL.
	 * @param array<string,mixed> $page Page summary.
	 * @return void
	 */
	private static function note_social( $url, array &$page ) {
		$host = Url_Guard::domain_key( $url );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		foreach ( self::SOCIAL as $domain => $name ) {
			if ( $host !== $domain && ! str_ends_with( $host, '.' . $domain ) ) {
				continue;
			}
			if ( '' === trim( $path, '/' ) || preg_match( '#/(sharer|share|shareArticle|intent|embed|plugins|dialog|watch)\b#i', $path ) ) {
				return;
			}
			if ( ! isset( $page['social'][ $name ] ) ) {
				$page['social'][ $name ] = $url;
			}
			return;
		}
	}

	/**
	 * Reads JSON-LD and microdata.
	 *
	 * @param DOMXPath            $xpath XPath.
	 * @param array<string,mixed> $page  Page summary.
	 * @return void
	 */
	private static function read_schema( DOMXPath $xpath, array &$page ) {
		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $script ) {
			$json = trim( (string) $script->textContent );
			if ( strlen( $json ) > 300000 ) {
				continue;
			}
			$decoded = json_decode( $json, true, 32 );
			if ( ! is_array( $decoded ) ) {
				++$page['schema_errors'];
				continue;
			}
			self::walk_schema( $decoded, $page, 0 );
		}

		foreach ( $xpath->query( '//*[@itemtype]' ) as $node ) {
			if ( $node instanceof DOMElement ) {
				$type = basename( (string) wp_parse_url( $node->getAttribute( 'itemtype' ), PHP_URL_PATH ) );
				if ( '' !== $type ) {
					$page['schema_types'][ self::clean( $type, 60 ) ] = true;
				}
			}
		}
	}

	/**
	 * Walks a JSON-LD structure.
	 *
	 * @param array<mixed>        $node  JSON-LD node.
	 * @param array<string,mixed> $page  Page summary.
	 * @param int                 $depth Depth.
	 * @return void
	 */
	private static function walk_schema( array $node, array &$page, $depth ) {
		if ( $depth > 8 ) {
			return;
		}
		if ( isset( $node['@type'] ) ) {
			foreach ( (array) $node['@type'] as $type ) {
				if ( is_string( $type ) && count( $page['schema_types'] ) < 30 ) {
					$page['schema_types'][ self::clean( $type, 60 ) ] = true;
				}
			}
			self::note_business( $node, $page );
		}
		foreach ( array( 'datePublished', 'dateModified' ) as $key ) {
			if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) && count( $page['dates'] ) < 20 ) {
				$page['dates'][] = self::clean( $node[ $key ], 40 );
			}
		}
		if ( isset( $node['sameAs'] ) ) {
			foreach ( (array) $node['sameAs'] as $same ) {
				if ( is_string( $same ) && 0 === stripos( $same, 'http' ) ) {
					self::note_social( $same, $page );
				}
			}
		}
		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				self::walk_schema( $value, $page, $depth + 1 );
			}
		}
	}

	/**
	 * Keeps the name, phone and address a site publishes about itself.
	 *
	 * @param array<mixed>        $node JSON-LD node.
	 * @param array<string,mixed> $page Page summary.
	 * @return void
	 */
	private static function note_business( array $node, array &$page ) {
		$types = implode( ' ', array_filter( (array) $node['@type'], 'is_string' ) );
		if ( ! preg_match( '/Organization|Business|Store|Restaurant|Dentist|Physician|Attorney|Agency|Hotel|Office|Corporation/i', $types ) ) {
			return;
		}
		if ( isset( $node['name'] ) && is_string( $node['name'] ) && empty( $page['business']['name'] ) ) {
			$page['business']['name'] = self::clean( $node['name'], 200 );
		}
		if ( isset( $node['telephone'] ) && is_string( $node['telephone'] ) && empty( $page['business']['telephone'] ) ) {
			$page['business']['telephone'] = self::clean( $node['telephone'], 40 );
		}
		if ( isset( $node['address'] ) && empty( $page['business']['address'] ) ) {
			$address = $node['address'];
			if ( isset( $address[0] ) && is_array( $address[0] ) ) {
				$address = $address[0];
			}
			if ( is_string( $address ) ) {
				$page['business']['address'] = self::clean( $address, 300 );
			} elseif ( is_array( $address ) ) {
				$pieces = array();
				foreach ( array( 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode' ) as $key ) {
					if ( isset( $address[ $key ] ) && is_scalar( $address[ $key ] ) ) {
						$pieces[] = (string) $address[ $key ];
					}
				}
				$page['business']['address'] = self::clean( implode( ', ', $pieces ), 300 );
				if ( isset( $address['addressLocality'] ) && is_string( $address['addressLocality'] ) ) {
					$page['business']['locality'] = self::clean( $address['addressLocality'], 80 );
				}
			}
		}
	}

	/**
	 * Reads visible publishing dates.
	 *
	 * @param DOMXPath            $xpath XPath.
	 * @param array<string,mixed> $page  Page summary.
	 * @return void
	 */
	private static function read_dates( DOMXPath $xpath, array &$page ) {
		foreach ( $xpath->query( '//time[@datetime]' ) as $time ) {
			if ( $time instanceof DOMElement && count( $page['dates'] ) < 20 ) {
				$page['dates'][] = self::clean( $time->getAttribute( 'datetime' ), 40 );
			}
		}
	}

	/**
	 * Detects tracking and consent tools from the page source.
	 *
	 * @param string              $html HTML.
	 * @param array<string,mixed> $page Page summary.
	 * @return void
	 */
	private static function detect_tools( $html, array &$page ) {
		foreach ( self::TRACKERS as $name => $pattern ) {
			if ( preg_match( $pattern, $html ) ) {
				$page['trackers'][] = $name;
			}
		}
		foreach ( self::CONSENT_TOOLS as $name => $pattern ) {
			if ( preg_match( $pattern, $html ) ) {
				$page['consent_tools'][] = $name;
			}
		}
	}

	/**
	 * Reads visible text. Scripts and styles are removed first.
	 *
	 * @param DOMDocument         $dom   Document.
	 * @param DOMXPath            $xpath XPath.
	 * @param array<string,mixed> $page  Page summary.
	 * @return void
	 */
	private static function read_text( DOMDocument $dom, DOMXPath $xpath, array &$page ) {
		$scripts = $xpath->query( '//script[not(@type="application/ld+json")]' )->length;

		$remove = array();
		foreach ( $xpath->query( '//script|//style|//noscript|//template|//svg' ) as $node ) {
			$remove[] = $node;
		}
		foreach ( $remove as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}

		$body = $xpath->query( '//body' )->item( 0 );
		$text = $body ? (string) $body->textContent : '';
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		$page['word_count'] = count( (array) preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) );
		$page['js_shell']   = $page['word_count'] < 60 && $scripts >= 3;
		$page['text']       = mb_substr( $text, 0, 30000 );

		if ( preg_match_all( '/(?:©|copyright|\(c\))[^0-9]{0,30}(?:20\d{2}\s*[-–—]\s*)?(20\d{2})/iu', $page['text'], $matches ) ) {
			$page['copyright_year'] = (int) max( $matches[1] );
		}

		$page['schema_types'] = array_keys( $page['schema_types'] );
		$page['trackers']     = array_values( array_unique( $page['trackers'] ) );
	}

	/**
	 * The base URL for relative links.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @param string   $url   Page URL.
	 * @return string
	 */
	private static function base_url( DOMXPath $xpath, $url ) {
		$base = $xpath->query( '//base[@href]' )->item( 0 );
		if ( $base instanceof DOMElement ) {
			$candidate = self::absolute( trim( $base->getAttribute( 'href' ) ), $url );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}
		return $url;
	}

	/**
	 * Makes a link absolute. Anything other than http(s) is dropped, which is
	 * what keeps javascript: and data: URLs out of reports.
	 *
	 * @param string $href Link.
	 * @param string $base Base URL.
	 * @return string
	 */
	public static function absolute( $href, $base ) {
		$href = trim( $href );
		if ( '' === $href || '#' === $href[0] || strlen( $href ) > 1500 ) {
			return '';
		}
		$absolute = \WP_Http::make_absolute_url( $href, $base );
		return preg_match( '#^https?://[^/\s]+#i', $absolute ) ? $absolute : '';
	}

	/**
	 * Normalises whitespace and length. This is not output escaping.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	private static function clean( $text, $max ) {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
		return mb_substr( $text, 0, $max );
	}
}
