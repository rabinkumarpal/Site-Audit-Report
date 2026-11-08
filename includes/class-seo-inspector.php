<?php
/**
 * SEO Inspector: an instant, single-page SEO breakdown in six tabs
 * (Insights, Meta, Headers, Images, Links, Schema).
 *
 * It is separate from the full audit: one page, read on demand, nothing stored.
 * Requests go through the guarded HTTP client, so private networks stay blocked.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

use DOMDocument;
use DOMElement;
use DOMXPath;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * SEO Inspector.
 */
final class Seo_Inspector {

	const NONCE_ACTION = 'sar_seo';
	const PARAM        = 'inspect';
	const MAX_IMAGES   = 300;
	const MAX_LINKS    = 500;
	const CACHE_TTL    = 10 * MINUTE_IN_SECONDS;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( 'site_audit_seo', array( self::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'register_assets' ) );
		add_action( 'wp_ajax_sar_seo_inspect', array( self::class, 'ajax_inspect' ) );
		add_action( 'wp_ajax_nopriv_sar_seo_inspect', array( self::class, 'ajax_inspect' ) );
	}

	/**
	 * Registers front-end files. The inspector shares the Security Check's base styles.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style( 'site-audit-seo', SAR_URL . 'assets/css/seo.css', array( 'site-audit-service' ), SAR_VERSION );
		wp_register_script( 'site-audit-seo', SAR_URL . 'assets/js/seo.js', array(), SAR_VERSION, true );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'examples'  => 'wordpress.org',
				'offset'    => '',
				'cta_url'   => '',
				'cta_label' => '',
				'compact'   => '',
			),
			(array) $atts,
			'site_audit_seo'
		);

		wp_enqueue_style( 'site-audit-seo' );
		wp_enqueue_script( 'site-audit-seo' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only pre-fills the address box.
		$prefill = isset( $_GET[ self::PARAM ] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ), 0, 2048 ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only pre-fills the competitor box.
		$prefill_vs = isset( $_GET['vs'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['vs'] ) ), 0, 2048 ) : '';

		wp_localize_script(
			'site-audit-seo',
			'sarSeo',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'param'   => self::PARAM,
				'prefill' => $prefill,
				'prefillVs' => $prefill_vs,
				'home'    => home_url( '/' ),
				'newUrl'  => ! empty( $atts['compact'] ) && is_admin() ? admin_url( 'admin.php?page=site-audit-report&new=' ) : '',
			)
		);

		$uid      = wp_unique_id( 'sarseo-' );
		$examples = array_filter( array_map( 'trim', explode( ',', (string) $atts['examples'] ) ) );
		$offset   = '' !== (string) $atts['offset'] ? absint( $atts['offset'] ) : null;
		$style    = null !== $offset ? '--bay-rail-top:' . ( $offset + 26 ) . 'px' : '';
		$cta_url  = (string) $atts['cta_url'];
		$compact  = ! empty( $atts['compact'] );
		$cta_text = '' !== (string) $atts['cta_label'] ? (string) $atts['cta_label'] : __( 'Get the full site audit', 'site-audit-report' );

		ob_start();
		include SAR_DIR . 'includes/views/seo-inspector.php';
		return (string) ob_get_clean();
	}

	/**
	 * AJAX: inspect one page.
	 *
	 * @return void
	 */
	public static function ajax_inspect() {
		nocache_headers();
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below.
		$nonce = isset( $_POST['nonce'] ) ? (string) wp_unslash( $_POST['nonce'] ) : '';
		$raw   = isset( $_POST['url'] ) ? (string) wp_unslash( $_POST['url'] ) : '';
		// phpcs:enable
		$fail = static function ( $code, $message, $status, $extra = array() ) {
			wp_send_json_error( array_merge( array( 'code' => $code, 'message' => $message, 'nonce' => wp_create_nonce( self::NONCE_ACTION ) ), $extra ), $status );
		};

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$fail( 'sar_bad_nonce', __( 'This page has expired. Please try again.', 'site-audit-report' ), 403 );
		}
		if ( Plugin::missing_requirements() ) {
			$fail( 'sar_unavailable', __( 'The inspector is unavailable right now.', 'site-audit-report' ), 503 );
		}

		$ip_hash = Rate_Limiter::hash( Rate_Limiter::client_ip() );
		if ( ! Rate_Limiter::hit( 'seo:' . $ip_hash, 40, HOUR_IN_SECONDS ) ) {
			$fail( 'sar_rate_ip', __( 'You have inspected a lot of pages this hour. Please try again later.', 'site-audit-report' ), 429 );
		}

		$url = Url_Guard::normalize_input( $raw );
		if ( is_wp_error( $url ) ) {
			$fail( $url->get_error_code(), $url->get_error_message(), 400 );
		}
		$checked = Url_Guard::validate( $url );
		if ( is_wp_error( $checked ) ) {
			$fail( $checked->get_error_code(), $checked->get_error_message(), 400 );
		}

		$key    = 'sar_seo2_' . md5( $checked['url'] );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			wp_send_json_success( $cached );
		}

		if ( ! Rate_Limiter::hit( 'seo_global', 2000, DAY_IN_SECONDS ) ) {
			$fail( 'sar_rate_global', __( 'The inspector has reached its daily limit. Please try again tomorrow.', 'site-audit-report' ), 429 );
		}

		$report = self::inspect( $checked['url'] );
		if ( is_wp_error( $report ) ) {
			$fail( $report->get_error_code(), $report->get_error_message(), 422 );
		}
		set_transient( $key, $report, self::CACHE_TTL );
		wp_send_json_success( $report );
	}

	/**
	 * Fetches and analyses a page.
	 *
	 * @param string $url Validated URL.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function inspect( $url ) {
		$http  = new Http_Client( 12 );
		$start = microtime( true );
		$res   = $http->fetch( $url, array( 'timeout' => 15 ) );
		$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( ! empty( $res['error'] ) ) {
			return new WP_Error( $res['error'], Crawler::error_message( (string) $res['error'] ) );
		}
		if ( Http_Client::looks_blocked( $res ) ) {
			/* translators: %d: HTTP status. */
			return new WP_Error( 'sar_blocked', sprintf( __( 'The site refused our inspector (HTTP %d), probably through a firewall or bot protection.', 'site-audit-report' ), (int) $res['code'] ) );
		}
		$type = (string) ( $res['headers']['content-type'] ?? '' );
		if ( '' !== $type && ! preg_match( '#text/html|application/xhtml#i', $type ) ) {
			return new WP_Error( 'sar_not_html', __( 'That address did not return an HTML page.', 'site-audit-report' ) );
		}

		$final  = (string) $res['url'];
		$html   = (string) $res['body'];
		$origin = Crawler::origin( $final );

		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$xpath = new DOMXPath( $dom );

		// Line breaks become spaces, so "styling<br>and" reads as two words.
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'br' ) ) as $br ) {
			$br->parentNode->replaceChild( $dom->createTextNode( ' ' ), $br );
		}

		$base = $final;
		$tag  = $xpath->query( '//base[@href]' )->item( 0 );
		if ( $tag instanceof DOMElement ) {
			$candidate = Html_Page::absolute( $tag->getAttribute( 'href' ), $final );
			$base      = '' !== $candidate ? $candidate : $final;
		}

		$robots  = Crawler::read_robots( $origin, $http );
		$sitemap = self::sitemap( $origin, $robots, $http );

		$meta     = self::meta( $xpath, $base, $final, $res['headers'] );
		$headings = self::headings( $xpath );
		$images   = self::images( $xpath, $base );
		$links    = self::links( $xpath, $base, $final );
		$schema   = self::schema( $xpath );
		$a11y     = A11y::analyze( $xpath ); // Before words(), which strips scripts and templates.
		$words    = self::words( $dom, $xpath );

		$meta['robots_txt'] = array(
			'found'    => ! empty( $robots['found'] ),
			'allowed'  => empty( $robots['found'] ) || Crawler::robots_allows( $final, $robots ),
			'sitemaps' => array_values( (array) ( $robots['sitemaps'] ?? array() ) ),
			'url'      => $origin . '/robots.txt',
		);
		$meta['sitemap']    = $sitemap;

		$page = array(
			'url'       => $url,
			'final_url' => $final,
			'host'      => (string) wp_parse_url( $final, PHP_URL_HOST ),
			'status'    => (int) $res['code'],
			'redirects' => max( 0, count( (array) $res['chain'] ) - 1 ),
			'chain'     => array_values( (array) $res['chain'] ),
			'time_ms'   => $ms,
			'size_kb'   => round( strlen( $html ) / 1024, 1 ),
			'https'     => 0 === strpos( $final, 'https://' ),
			'server'    => mb_substr( (string) ( $res['headers']['server'] ?? '' ), 0, 40 ),
			'words'     => $words,
			'at'        => wp_date( 'j M Y, H:i' ),
		);

		$insights = self::insights( $page, $meta, $headings, $images, $links, $schema );
		$counts   = array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0 );
		foreach ( $insights as $item ) {
			++$counts[ $item['status'] ];
		}
		$scored = $counts['pass'] + $counts['warn'] + $counts['fail'];

		return array(
			'page'     => $page,
			'score'    => $scored ? (int) round( 100 * ( $counts['pass'] + 0.5 * $counts['warn'] ) / $scored ) : null,
			'counts'   => $counts,
			'insights' => $insights,
			'meta'     => $meta,
			'headings' => $headings,
			'images'   => $images,
			'links'    => $links,
			'schema'   => $schema,
			'a11y'     => $a11y,
		);
	}

	/**
	 * Title, meta tags, social tags and alternates.
	 *
	 * @param DOMXPath             $xpath   XPath.
	 * @param string               $base    Base URL.
	 * @param string               $final   Final URL.
	 * @param array<string,string> $headers Response headers.
	 * @return array<string,mixed>
	 */
	private static function meta( DOMXPath $xpath, $base, $final, array $headers ) {
		$titles = $xpath->query( '//title[not(ancestor::svg)]' );
		$title  = $titles->length ? self::clean( $titles->item( 0 )->textContent, 500 ) : '';

		$named = array();
		$og    = array();
		$tw    = array();
		foreach ( $xpath->query( '//meta' ) as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$name    = strtolower( trim( $node->getAttribute( 'name' ) ) );
			$prop    = strtolower( trim( $node->getAttribute( 'property' ) ) );
			$content = self::clean( $node->getAttribute( 'content' ), 600 );
			if ( '' !== $prop && 0 === strpos( $prop, 'og:' ) && ! isset( $og[ $prop ] ) ) {
				$og[ $prop ] = $content;
			} elseif ( 0 === strpos( $name, 'twitter:' ) && ! isset( $tw[ $name ] ) ) {
				$tw[ $name ] = $content;
			} elseif ( 0 === strpos( $prop, 'twitter:' ) && ! isset( $tw[ $prop ] ) ) {
				$tw[ $prop ] = $content;
			} elseif ( '' !== $name ) {
				$named[ $name ][] = $content;
			}
			if ( $node->hasAttribute( 'charset' ) ) {
				$named['charset'][] = strtoupper( $node->getAttribute( 'charset' ) );
			}
		}

		$canonical = '';
		$canon_n   = 0;
		$hreflang  = array();
		$favicon   = '';
		foreach ( $xpath->query( '//link[@rel]' ) as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$rel  = strtolower( trim( $node->getAttribute( 'rel' ) ) );
			$href = Html_Page::absolute( $node->getAttribute( 'href' ), $base );
			if ( 'canonical' === $rel ) {
				++$canon_n;
				$canonical = '' === $canonical ? $href : $canonical;
			} elseif ( 'alternate' === $rel && $node->hasAttribute( 'hreflang' ) && count( $hreflang ) < 50 ) {
				$hreflang[] = array(
					'lang' => self::clean( $node->getAttribute( 'hreflang' ), 20 ),
					'href' => $href,
				);
			} elseif ( '' === $favicon && preg_match( '/(^|\s)icon(\s|$)/', $rel ) ) {
				$favicon = $href;
			}
		}

		$html_el = $xpath->query( '//html' )->item( 0 );
		$lang    = $html_el instanceof DOMElement ? self::clean( $html_el->getAttribute( 'lang' ), 20 ) : '';

		$strip = static function ( $u ) {
			return untrailingslashit( strtolower( (string) preg_replace( '/[?#].*$/', '', (string) $u ) ) );
		};

		return array(
			'title'          => $title,
			'title_len'      => mb_strlen( $title ),
			'title_count'    => $titles->length,
			'description'    => (string) ( $named['description'][0] ?? '' ),
			'desc_len'       => mb_strlen( (string) ( $named['description'][0] ?? '' ) ),
			'desc_count'     => count( (array) ( $named['description'] ?? array() ) ),
			'url'            => $final,
			'url_len'        => mb_strlen( $final ),
			'canonical'      => $canonical,
			'canonical_n'    => $canon_n,
			'canonical_self' => '' !== $canonical && $strip( $canonical ) === $strip( $final ),
			'robots'         => implode( ', ', (array) ( $named['robots'] ?? array() ) ),
			'x_robots'       => self::clean( (string) ( $headers['x-robots-tag'] ?? '' ), 200 ),
			'keywords'       => (string) ( $named['keywords'][0] ?? '' ),
			'author'         => (string) ( $named['author'][0] ?? '' ),
			'publisher'      => (string) ( $named['generator'][0] ?? '' ),
			'lang'           => $lang,
			'charset'        => (string) ( $named['charset'][0] ?? '' ),
			'viewport'       => (string) ( $named['viewport'][0] ?? '' ),
			'favicon'        => $favicon,
			'og'             => $og,
			'twitter'        => $tw,
			'hreflang'       => $hreflang,
		);
	}

	/**
	 * Headings in document order, with flags.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>
	 */
	private static function headings( DOMXPath $xpath ) {
		$counts = array_fill_keys( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), 0 );
		$list   = array();
		$prev   = 0;
		$skips  = 0;
		$long   = 0;
		$empty  = 0;
		foreach ( $xpath->query( '//body//h1|//body//h2|//body//h3|//body//h4|//body//h5|//body//h6' ) as $node ) {
			$level = (int) substr( $node->nodeName, 1 );
			++$counts[ 'h' . $level ];
			$text  = self::clean( $node->textContent, 300 );
			$flags = array();
			if ( '' === $text ) {
				$flags[] = 'empty';
				++$empty;
			} elseif ( mb_strlen( $text ) > 70 ) {
				$flags[] = 'long';
				++$long;
			}
			if ( 1 === $level && $counts['h1'] > 1 ) {
				$flags[] = 'multi_h1';
			}
			$from = $prev;
			if ( $prev && $level > $prev + 1 ) {
				$flags[] = 'skip';
				++$skips;
			}
			$prev = $level;
			if ( count( $list ) < 400 ) {
				$list[] = array(
					'level' => $level,
					'text'  => $text,
					'flags' => $flags,
					'from'  => $from,
				);
			}
		}
		return array(
			'counts' => $counts,
			'list'   => $list,
			'skips'  => $skips,
			'long'   => $long,
			'empty'  => $empty,
		);
	}

	/**
	 * Images and their alt text.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @param string   $base  Base URL.
	 * @return array<string,mixed>
	 */
	private static function images( DOMXPath $xpath, $base ) {
		$list  = array();
		$stats = array( 'total' => 0, 'missing_alt' => 0, 'empty_alt' => 0, 'no_dims' => 0, 'lazy' => 0 );
		$seen  = array();
		foreach ( $xpath->query( '//body//img' ) as $img ) {
			if ( ! $img instanceof DOMElement ) {
				continue;
			}
			$src = trim( $img->getAttribute( 'src' ) );
			if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
				foreach ( array( 'data-src', 'data-lazy-src', 'data-original' ) as $attr ) {
					if ( $img->hasAttribute( $attr ) ) {
						$src = trim( $img->getAttribute( $attr ) );
						break;
					}
				}
			}
			$abs = Html_Page::absolute( $src, $base );
			++$stats['total'];
			$has_alt = $img->hasAttribute( 'alt' );
			$alt     = self::clean( $img->getAttribute( 'alt' ), 200 );
			if ( ! $has_alt ) {
				++$stats['missing_alt'];
			} elseif ( '' === $alt ) {
				++$stats['empty_alt'];
			}
			$w = $img->getAttribute( 'width' );
			$h = $img->getAttribute( 'height' );
			if ( '' === $w || '' === $h ) {
				++$stats['no_dims'];
			}
			$lazy = 'lazy' === strtolower( $img->getAttribute( 'loading' ) ) || $img->hasAttribute( 'data-src' ) || $img->hasAttribute( 'data-lazy-src' );
			$stats['lazy'] += $lazy ? 1 : 0;

			if ( count( $list ) < self::MAX_IMAGES ) {
				$path   = (string) wp_parse_url( $abs, PHP_URL_PATH );
				$list[] = array(
					'src'     => $abs,
					'alt'     => $alt,
					'has_alt' => $has_alt,
					'width'   => self::clean( $w, 10 ),
					'height'  => self::clean( $h, 10 ),
					'lazy'    => $lazy,
					'ext'     => strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ),
					'dupe'    => isset( $seen[ $abs ] ),
				);
				$seen[ $abs ] = true;
			}
		}
		$stats['unique'] = count( $seen );
		$stats['list']   = $list;
		return $stats;
	}

	/**
	 * Links on the page.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @param string   $base  Base URL.
	 * @param string   $final Page URL.
	 * @return array<string,mixed>
	 */
	private static function links( DOMXPath $xpath, $base, $final ) {
		$host  = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $final, PHP_URL_HOST ) ) );
		$stats = array( 'total' => 0, 'internal' => 0, 'external' => 0, 'other' => 0, 'nofollow' => 0, 'no_text' => 0 );
		$list  = array();
		$uniq  = array();
		foreach ( $xpath->query( '//body//a[@href]' ) as $a ) {
			if ( ! $a instanceof DOMElement ) {
				continue;
			}
			$raw  = trim( $a->getAttribute( 'href' ) );
			$rel  = strtolower( self::clean( $a->getAttribute( 'rel' ), 60 ) );
			$text = self::clean( $a->textContent, 120 );
			if ( '' === $text ) {
				foreach ( $a->getElementsByTagName( 'img' ) as $img ) {
					$text = self::clean( $img->getAttribute( 'alt' ), 120 );
					if ( '' !== $text ) {
						$text = '[img] ' . $text;
						break;
					}
				}
			}
			if ( '' === $text ) {
				$text = self::clean( $a->getAttribute( 'aria-label' ) ?: $a->getAttribute( 'title' ), 120 );
			}

			if ( preg_match( '/^(mailto|tel|sms|whatsapp):/i', $raw ) ) {
				$type = 'other';
				$href = mb_substr( $raw, 0, 200 );
			} else {
				$href = Html_Page::absolute( $raw, $base );
				if ( '' === $href ) {
					continue; // Anchors, javascript: and similar.
				}
				$lhost = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) ) );
				$type  = $lhost === $host ? 'internal' : 'external';
			}
			++$stats['total'];
			++$stats[ $type ];
			$nofollow = (bool) preg_match( '/\b(nofollow|ugc|sponsored)\b/', $rel );
			$stats['nofollow'] += $nofollow ? 1 : 0;
			$stats['no_text']  += '' === $text ? 1 : 0;
			$uniq[ $href ]      = true;
			if ( count( $list ) < self::MAX_LINKS ) {
				$list[] = array(
					'href'     => $href,
					'text'     => $text,
					'type'     => $type,
					'rel'      => $rel,
					'nofollow' => $nofollow,
					'blank'    => '_blank' === $a->getAttribute( 'target' ),
				);
			}
		}
		$stats['unique'] = count( $uniq );
		$stats['list']   = $list;
		return $stats;
	}

	/**
	 * JSON-LD and microdata.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array<string,mixed>
	 */
	private static function schema( DOMXPath $xpath ) {
		$blocks = array();
		$types  = array();
		$bad    = 0;
		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $script ) {
			$raw  = trim( (string) $script->textContent );
			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) ) {
				++$bad;
				$blocks[] = array( 'valid' => false, 'types' => array(), 'json' => mb_substr( $raw, 0, 4000 ) );
				continue;
			}
			$found = array();
			self::collect_types( $data, $found, 0 );
			$types  = array_merge( $types, $found );
			$pretty = (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( count( $blocks ) < 20 ) {
				$blocks[] = array(
					'valid' => true,
					'types' => array_values( array_unique( $found ) ),
					'json'  => strlen( $pretty ) > 30000 ? substr( $pretty, 0, 30000 ) . "\n…" : $pretty,
				);
			}
		}
		$micro = array();
		foreach ( $xpath->query( '//*[@itemtype]' ) as $node ) {
			if ( $node instanceof DOMElement ) {
				$micro[] = basename( (string) wp_parse_url( trim( $node->getAttribute( 'itemtype' ) ), PHP_URL_PATH ) );
			}
		}
		return array(
			'blocks'    => $blocks,
			'types'     => array_values( array_unique( array_filter( $types ) ) ),
			'invalid'   => $bad,
			'microdata' => array_values( array_unique( array_filter( $micro ) ) ),
			'rdfa'      => $xpath->query( '//*[@typeof]' )->length,
		);
	}

	/**
	 * Walks JSON-LD for @type values.
	 *
	 * @param mixed    $node  Node.
	 * @param string[] $found Types.
	 * @param int      $depth Depth.
	 * @return void
	 */
	private static function collect_types( $node, array &$found, $depth ) {
		if ( ! is_array( $node ) || $depth > 8 ) {
			return;
		}
		if ( isset( $node['@type'] ) ) {
			foreach ( (array) $node['@type'] as $type ) {
				if ( is_string( $type ) ) {
					$found[] = mb_substr( $type, 0, 60 );
				}
			}
		}
		foreach ( $node as $child ) {
			self::collect_types( $child, $found, $depth + 1 );
		}
	}

	/**
	 * Visible word count.
	 *
	 * @param DOMDocument $dom   Document.
	 * @param DOMXPath    $xpath XPath.
	 * @return int
	 */
	private static function words( DOMDocument $dom, DOMXPath $xpath ) {
		foreach ( iterator_to_array( $xpath->query( '//script|//style|//noscript|//template|//svg' ) ) as $node ) {
			$node->parentNode->removeChild( $node );
		}
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		$text = $body ? (string) $body->textContent : '';
		return count( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) );
	}

	/**
	 * Finds the XML sitemap.
	 *
	 * @param string              $origin Origin.
	 * @param array<string,mixed> $robots Robots data.
	 * @param Http_Client         $http   Client.
	 * @return array{found:bool,url:string}
	 */
	private static function sitemap( $origin, array $robots, Http_Client $http ) {
		$candidates = array_slice( (array) ( $robots['sitemaps'] ?? array() ), 0, 1 );
		$candidates = $candidates ? $candidates : array( $origin . '/sitemap.xml', $origin . '/wp-sitemap.xml' );
		foreach ( $candidates as $candidate ) {
			$res = $http->fetch( $candidate, array( 'max_bytes' => 4096, 'timeout' => 8 ) );
			if ( ! empty( $res['ok'] ) && preg_match( '/<(urlset|sitemapindex)\b/i', (string) $res['body'] ) ) {
				return array( 'found' => true, 'url' => (string) $res['url'] );
			}
		}
		return array( 'found' => false, 'url' => '' );
	}

	/**
	 * The Insights tab: every check with a verdict.
	 *
	 * @param array<string,mixed> $p Page.
	 * @param array<string,mixed> $m Meta.
	 * @param array<string,mixed> $h Headings.
	 * @param array<string,mixed> $i Images.
	 * @param array<string,mixed> $l Links.
	 * @param array<string,mixed> $s Schema.
	 * @return array<int,array<string,string>>
	 */
	private static function insights( array $p, array $m, array $h, array $i, array $l, array $s ) {
		$out = array();
		$add = static function ( $group, $status, $label, $detail ) use ( &$out ) {
			$out[] = compact( 'group', 'status', 'label', 'detail' );
		};

		// Indexing.
		$add( 'indexing', 200 === $p['status'] ? 'pass' : 'fail', __( 'HTTP status', 'site-audit-report' ), 200 === $p['status'] ? __( 'The page answers 200 OK.', 'site-audit-report' ) : sprintf( /* translators: %d: status. */ __( 'The page answers HTTP %d.', 'site-audit-report' ), $p['status'] ) );
		$noindex = false !== stripos( $m['robots'] . ' ' . $m['x_robots'], 'noindex' );
		$add( 'indexing', $noindex ? 'fail' : 'pass', __( 'Indexable', 'site-audit-report' ), $noindex ? __( 'A noindex rule tells search engines to leave this page out.', 'site-audit-report' ) : __( 'No noindex rule in meta robots or X-Robots-Tag.', 'site-audit-report' ) );
		if ( $m['robots_txt']['found'] ) {
			$add( 'indexing', $m['robots_txt']['allowed'] ? 'pass' : 'fail', 'robots.txt', $m['robots_txt']['allowed'] ? __( 'robots.txt allows crawling this page.', 'site-audit-report' ) : __( 'robots.txt blocks crawlers from this page.', 'site-audit-report' ) );
		} else {
			$add( 'indexing', 'warn', 'robots.txt', __( 'No robots.txt found. It is optional, but it is where you point crawlers at your sitemap.', 'site-audit-report' ) );
		}
		$add( 'indexing', $m['sitemap']['found'] ? 'pass' : 'warn', __( 'XML sitemap', 'site-audit-report' ), $m['sitemap']['found'] ? $m['sitemap']['url'] : __( 'No XML sitemap at the usual addresses or in robots.txt.', 'site-audit-report' ) );
		if ( '' === $m['canonical'] ) {
			$add( 'indexing', 'warn', __( 'Canonical', 'site-audit-report' ), __( 'No canonical link. Add one so duplicates (tracking parameters, http/https) consolidate to this URL.', 'site-audit-report' ) );
		} elseif ( $m['canonical_n'] > 1 ) {
			$add( 'indexing', 'fail', __( 'Canonical', 'site-audit-report' ), __( 'More than one canonical link. Search engines may ignore all of them.', 'site-audit-report' ) );
		} else {
			$add( 'indexing', $m['canonical_self'] ? 'pass' : 'info', __( 'Canonical', 'site-audit-report' ), $m['canonical_self'] ? __( 'Points to this page.', 'site-audit-report' ) : sprintf( /* translators: %s: URL. */ __( 'Points to another URL: %s', 'site-audit-report' ), $m['canonical'] ) );
		}
		$add( 'indexing', $p['https'] ? 'pass' : 'fail', 'HTTPS', $p['https'] ? __( 'Served over HTTPS.', 'site-audit-report' ) : __( 'Served over plain HTTP.', 'site-audit-report' ) );
		if ( $p['redirects'] ) {
			$add( 'indexing', $p['redirects'] > 1 ? 'warn' : 'info', __( 'Redirects', 'site-audit-report' ), sprintf( /* translators: %d: hops. */ _n( '%d redirect before the page loads.', '%d redirects before the page loads.', $p['redirects'], 'site-audit-report' ), $p['redirects'] ) );
		}

		// Meta.
		$tl = $m['title_len'];
		if ( 0 === $tl ) {
			$add( 'meta', 'fail', __( 'Title', 'site-audit-report' ), __( 'The page has no title.', 'site-audit-report' ) );
		} else {
			$add( 'meta', $tl >= 30 && $tl <= 65 ? 'pass' : 'warn', __( 'Title length', 'site-audit-report' ), sprintf( /* translators: %d: characters. */ __( '%d characters. Aim for 30 to 65 so it is not cut off in results.', 'site-audit-report' ), $tl ) );
		}
		if ( $m['title_count'] > 1 ) {
			$add( 'meta', 'warn', __( 'Duplicate title tags', 'site-audit-report' ), sprintf( /* translators: %d: count. */ __( '%d title tags. Keep one.', 'site-audit-report' ), $m['title_count'] ) );
		}
		$dl = $m['desc_len'];
		if ( 0 === $dl ) {
			$add( 'meta', 'fail', __( 'Meta description', 'site-audit-report' ), __( 'No meta description. Google will pick text from the page instead.', 'site-audit-report' ) );
		} else {
			$add( 'meta', $dl >= 70 && $dl <= 160 ? 'pass' : 'warn', __( 'Description length', 'site-audit-report' ), sprintf( /* translators: %d: characters. */ __( '%d characters. Aim for 70 to 160.', 'site-audit-report' ), $dl ) );
		}
		$add( 'meta', '' !== $m['lang'] ? 'pass' : 'warn', __( 'Language', 'site-audit-report' ), '' !== $m['lang'] ? sprintf( /* translators: %s: language. */ __( 'lang="%s"', 'site-audit-report' ), $m['lang'] ) : __( 'No lang attribute on the html tag.', 'site-audit-report' ) );
		$add( 'meta', '' !== $m['viewport'] ? 'pass' : 'fail', __( 'Mobile viewport', 'site-audit-report' ), '' !== $m['viewport'] ? $m['viewport'] : __( 'No viewport tag, so phones show a zoomed-out desktop page.', 'site-audit-report' ) );
		$og_missing = array_diff( array( 'og:title', 'og:description', 'og:image' ), array_keys( array_filter( $m['og'] ) ) );
		$add( 'meta', $og_missing ? 'warn' : 'pass', __( 'Open Graph', 'site-audit-report' ), $og_missing ? sprintf( /* translators: %s: tags. */ __( 'Missing %s, so shared links look plain.', 'site-audit-report' ), implode( ', ', $og_missing ) ) : __( 'Title, description and image set for sharing.', 'site-audit-report' ) );
		$add( 'meta', ! empty( $m['twitter']['twitter:card'] ) ? 'pass' : 'info', __( 'X / Twitter card', 'site-audit-report' ), ! empty( $m['twitter']['twitter:card'] ) ? $m['twitter']['twitter:card'] : __( 'No twitter:card. X falls back to Open Graph.', 'site-audit-report' ) );
		$add( 'meta', '' !== $m['favicon'] ? 'pass' : 'warn', __( 'Favicon', 'site-audit-report' ), '' !== $m['favicon'] ? __( 'Declared in the head.', 'site-audit-report' ) : __( 'No icon link in the head. Google shows favicons in mobile results.', 'site-audit-report' ) );

		// Headings.
		$h1 = $h['counts']['h1'];
		$add( 'content', 1 === $h1 ? 'pass' : ( 0 === $h1 ? 'fail' : 'warn' ), 'H1', 1 === $h1 ? __( 'Exactly one H1.', 'site-audit-report' ) : sprintf( /* translators: %d: count. */ _n( '%d H1 found. Use exactly one.', '%d H1s found. Use exactly one.', $h1, 'site-audit-report' ), $h1 ) );
		$add( 'content', $h['skips'] ? 'warn' : 'pass', __( 'Heading order', 'site-audit-report' ), $h['skips'] ? sprintf( /* translators: %d: count. */ _n( '%d heading skips a level.', '%d headings skip a level.', $h['skips'], 'site-audit-report' ), $h['skips'] ) : __( 'Headings step down one level at a time.', 'site-audit-report' ) );
		if ( $h['long'] || $h['empty'] ) {
			$add( 'content', 'warn', __( 'Heading length', 'site-audit-report' ), sprintf( /* translators: 1: long, 2: empty. */ __( '%1$d over 70 characters, %2$d empty.', 'site-audit-report' ), $h['long'], $h['empty'] ) );
		}
		$add( 'content', $p['words'] >= 300 ? 'pass' : 'warn', __( 'Word count', 'site-audit-report' ), sprintf( /* translators: %d: words. */ __( '%d words of visible text.', 'site-audit-report' ), $p['words'] ) );

		// Images.
		if ( $i['total'] ) {
			$share = $i['missing_alt'] / $i['total'];
			$add( 'media', 0 === $i['missing_alt'] ? 'pass' : ( $share > .3 ? 'fail' : 'warn' ), __( 'Image alt text', 'site-audit-report' ), 0 === $i['missing_alt'] ? __( 'Every image has an alt attribute.', 'site-audit-report' ) : sprintf( /* translators: 1: missing, 2: total. */ __( '%1$d of %2$d images have no alt attribute.', 'site-audit-report' ), $i['missing_alt'], $i['total'] ) );
			$add( 'media', $i['no_dims'] ? 'warn' : 'pass', __( 'Image dimensions', 'site-audit-report' ), $i['no_dims'] ? sprintf( /* translators: %d: count. */ __( '%d images lack width and height, which can shift the layout as they load.', 'site-audit-report' ), $i['no_dims'] ) : __( 'All images declare width and height.', 'site-audit-report' ) );
		}

		// Links.
		$add( 'links', $l['internal'] >= 3 ? 'pass' : 'warn', __( 'Internal links', 'site-audit-report' ), sprintf( /* translators: %d: count. */ __( '%d links to other pages on this site.', 'site-audit-report' ), $l['internal'] ) );
		if ( $l['no_text'] ) {
			$add( 'links', 'warn', __( 'Links without text', 'site-audit-report' ), sprintf( /* translators: %d: count. */ __( '%d links have no anchor text, alt text or label.', 'site-audit-report' ), $l['no_text'] ) );
		}

		// Schema.
		$add( 'schema', $s['types'] || $s['microdata'] ? 'pass' : 'warn', __( 'Structured data', 'site-audit-report' ), $s['types'] || $s['microdata'] ? implode( ', ', array_slice( array_merge( $s['types'], $s['microdata'] ), 0, 8 ) ) : __( 'No schema.org markup found.', 'site-audit-report' ) );
		if ( $s['invalid'] ) {
			$add( 'schema', 'fail', __( 'Invalid JSON-LD', 'site-audit-report' ), sprintf( /* translators: %d: count. */ _n( '%d JSON-LD block cannot be parsed.', '%d JSON-LD blocks cannot be parsed.', $s['invalid'], 'site-audit-report' ), $s['invalid'] ) );
		}

		// Performance basics.
		$add( 'speed', $p['time_ms'] <= 1000 ? 'pass' : ( $p['time_ms'] <= 2500 ? 'warn' : 'fail' ), __( 'Server response', 'site-audit-report' ), sprintf( /* translators: %d: ms. */ __( 'HTML arrived in %d ms (from our server).', 'site-audit-report' ), $p['time_ms'] ) );
		$add( 'speed', $p['size_kb'] <= 500 ? 'pass' : 'warn', __( 'HTML size', 'site-audit-report' ), sprintf( /* translators: %s: KB. */ __( '%s KB of HTML.', 'site-audit-report' ), number_format_i18n( $p['size_kb'], 1 ) ) );

		return $out;
	}

	/**
	 * Normalises whitespace and length.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	private static function clean( $text, $max ) {
		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) ), 0, $max );
	}
}
