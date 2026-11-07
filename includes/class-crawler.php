<?php
/**
 * First scan step: reads the home page, robots.txt, the sitemap and a page sample.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Crawler.
 */
final class Crawler {

	/**
	 * Crawls the site.
	 *
	 * @param string      $url  Submitted URL.
	 * @param Http_Client $http Client.
	 * @return array<string,mixed> Crawl data for Scan_Context.
	 */
	public static function crawl( $url, Http_Client $http ) {
		$data = array(
			'input_url'         => $url,
			'final_url'         => $url,
			'readable'          => false,
			'unreadable_reason' => '',
			'home_code'         => 0,
			'home_headers'      => array(),
			'home_chain'        => array(),
			'tls'               => array(
				'https_works'  => null,
				'cert_problem' => false,
			),
			'robots'            => array(),
			'sitemap'           => array(),
			'pages'             => array(),
			'page_errors'       => array(),
			'skipped_by_robots' => 0,
			'feed_date'         => '',
			'truncated'         => false,
		);

		$home                 = self::fetch_home( $url, $http, $data );
		$data['home_code']    = (int) $home['code'];
		$data['home_headers'] = self::keep_headers( $home['headers'] );
		$data['home_chain']   = $home['chain'];
		$data['final_url']    = $home['url'];

		$origin = self::origin( $home['url'] );
		if ( ! $home['error'] ) {
			$data['robots']  = self::read_robots( $origin, $http );
			$data['sitemap'] = self::read_sitemap( $origin, $data['robots'], $http );
		}

		if ( ! empty( $home['error'] ) ) {
			$data['unreadable_reason'] = self::error_message( (string) $home['error'] );
			return $data;
		}
		if ( Http_Client::looks_blocked( $home ) ) {
			/* translators: %d: HTTP status code. */
			$data['unreadable_reason'] = sprintf( __( 'The website refused our scanner (HTTP %d), probably through a firewall or bot protection. This says nothing about the site itself.', 'site-audit-report' ), $data['home_code'] );
			return $data;
		}
		if ( ! $home['ok'] ) {
			/* translators: %d: HTTP status code. */
			$data['unreadable_reason'] = sprintf( __( 'The website answered with an error (HTTP %d) instead of a page.', 'site-audit-report' ), $data['home_code'] );
			return $data;
		}
		if ( ! self::is_html( $home ) ) {
			$data['unreadable_reason'] = __( 'The address did not return an HTML page.', 'site-audit-report' );
			return $data;
		}

		$data['readable']  = true;
		$data['truncated'] = $home['truncated'];

		$page            = Html_Page::parse( $home['body'], $home['url'] );
		$data['pages'][] = $page;

		self::read_sample( $page, $data, $http );
		self::read_feed( $page, $data, $http );

		return $data;
	}

	/**
	 * Fetches the home page and works out the state of HTTPS along the way.
	 *
	 * @param string              $url  URL.
	 * @param Http_Client         $http Client.
	 * @param array<string,mixed> $data Crawl data.
	 * @return array<string,mixed>
	 */
	private static function fetch_home( $url, Http_Client $http, array &$data ) {
		$https_url = (string) preg_replace( '#^http://#i', 'https://', $url );
		$home      = $http->fetch( $https_url, array( 'timeout' => 20 ) );

		if ( 'sar_tls' === $home['error'] ) {
			$data['tls']['https_works']  = false;
			$data['tls']['cert_problem'] = true;
			// Nothing secret is sent, so the content can still be read to audit the rest of the site.
			$home = $http->fetch(
				$https_url,
				array(
					'timeout'   => 20,
					'sslverify' => false,
				)
			);
		} elseif ( $home['error'] && ! in_array( $home['error'], array( 'sar_url_blocked', 'sar_dns_failed' ), true ) ) {
			$data['tls']['https_works'] = false;
			$home                       = $http->fetch(
				(string) preg_replace( '#^https://#i', 'http://', $https_url ),
				array( 'timeout' => 20 )
			);
		} elseif ( ! $home['error'] ) {
			$data['tls']['https_works'] = 0 === strpos( $home['url'], 'https://' );
		}

		return $home;
	}

	/**
	 * Reads and parses robots.txt.
	 *
	 * @param string      $origin Origin.
	 * @param Http_Client $http   Client.
	 * @return array<string,mixed>
	 */
	private static function read_robots( $origin, Http_Client $http ) {
		$response = $http->fetch( $origin . '/robots.txt', array( 'max_bytes' => 512000 ) );
		$robots   = array(
			'checked'    => empty( $response['error'] ) && ! Http_Client::looks_blocked( $response ),
			'found'      => false,
			'code'       => (int) $response['code'],
			'disallow'   => array(),
			'allow'      => array(),
			'sitemaps'   => array(),
			'blocks_all' => false,
		);
		if ( ! $response['ok'] || false !== stripos( (string) ( $response['headers']['content-type'] ?? '' ), 'text/html' ) ) {
			return $robots;
		}

		$robots['found'] = true;
		$applies         = false;
		$in_agents       = false;

		foreach ( preg_split( '/\r\n|\r|\n/', $response['body'] ) as $line ) {
			$line = trim( (string) preg_replace( '/#.*/', '', (string) $line ) );
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $key, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$key                 = strtolower( $key );

			if ( 'sitemap' === $key ) {
				if ( preg_match( '#^https?://#i', $value ) && count( $robots['sitemaps'] ) < 5 ) {
					$robots['sitemaps'][] = $value;
				}
			} elseif ( 'user-agent' === $key ) {
				$applies   = ( $in_agents && $applies ) || '*' === $value;
				$in_agents = true;
			} else {
				$in_agents = false;
				if ( $applies && 'disallow' === $key && '' !== $value && count( $robots['disallow'] ) < 200 ) {
					$robots['disallow'][] = $value;
				} elseif ( $applies && 'allow' === $key && '' !== $value && count( $robots['allow'] ) < 200 ) {
					$robots['allow'][] = $value;
				}
			}
		}

		$robots['blocks_all'] = in_array( '/', $robots['disallow'], true ) && ! in_array( '/', $robots['allow'], true );
		return $robots;
	}

	/**
	 * Finds the sitemap and reads its size and most recent date.
	 *
	 * @param string              $origin Origin.
	 * @param array<string,mixed> $robots Robots data.
	 * @param Http_Client         $http   Client.
	 * @return array<string,mixed>
	 */
	private static function read_sitemap( $origin, array $robots, Http_Client $http ) {
		$candidates = array_merge(
			(array) ( $robots['sitemaps'] ?? array() ),
			array( $origin . '/sitemap.xml', $origin . '/sitemap_index.xml', $origin . '/wp-sitemap.xml' )
		);
		$sitemap    = array(
			'found'       => false,
			'url'         => '',
			'url_count'   => 0,
			'is_index'    => false,
			'newest'      => '',
			'sample'      => array(),
			'from_robots' => ! empty( $robots['sitemaps'] ),
		);

		foreach ( array_slice( array_unique( $candidates ), 0, 5 ) as $candidate ) {
			$response = $http->fetch( $candidate, array( 'max_bytes' => 2097152 ) );
			if ( ! $response['ok'] || ! preg_match( '/<(urlset|sitemapindex)[\s>]/i', substr( $response['body'], 0, 4096 ) ) ) {
				continue;
			}
			$sitemap['found'] = true;
			$sitemap['url']   = $response['url'];
			$body             = $response['body'];

			if ( preg_match( '/<sitemapindex[\s>]/i', substr( $body, 0, 4096 ) ) ) {
				$sitemap['is_index'] = true;
				$children            = self::xml_values( $body, 'loc' );
				$sitemap['newest']   = self::newest( self::xml_values( $body, 'lastmod' ) );
				$sitemap['children'] = count( $children );
				if ( $children ) {
					$child = $http->fetch( $children[0], array( 'max_bytes' => 2097152 ) );
					$body  = $child['ok'] ? $child['body'] : '';
				}
			}

			$locs                 = self::xml_values( $body, 'loc' );
			$sitemap['url_count'] = count( $locs );
			$sitemap['sample']    = array_slice( $locs, 0, 30 );
			$newest               = self::newest( self::xml_values( $body, 'lastmod' ) );
			if ( $newest > $sitemap['newest'] ) {
				$sitemap['newest'] = $newest;
			}
			break;
		}

		return $sitemap;
	}

	/**
	 * Fetches extra pages, starting with the ones most useful to an audit.
	 *
	 * @param array<string,mixed> $home Parsed home page.
	 * @param array<string,mixed> $data Crawl data.
	 * @param Http_Client         $http Client.
	 * @return void
	 */
	private static function read_sample( array $home, array &$data, Http_Client $http ) {
		$max = (int) Settings::get( 'max_pages' );

		$links = array();
		foreach ( $home['internal_links'] as $link ) {
			$links[ $link['url'] ] = self::page_rank( $link['url'], $link['text'] );
		}
		foreach ( (array) ( $data['sitemap']['sample'] ?? array() ) as $url ) {
			if ( ! isset( $links[ $url ] ) ) {
				$links[ $url ] = 1;
			}
		}
		arsort( $links );

		$seen = array(
			rtrim( $home['url'], '/' ) => true,
			rtrim( strtok( $data['input_url'], '#' ), '/' ) => true,
		);

		foreach ( array_keys( $links ) as $url ) {
			if ( count( $data['pages'] ) >= $max ) {
				break;
			}
			$key = rtrim( (string) $url, '/' );
			if ( isset( $seen[ $key ] ) || preg_match( '/\.(pdf|jpe?g|png|gif|webp|svg|zip|mp4|mp3|docx?|xlsx?|pptx?|css|js|xml)(\?|$)/i', (string) $url ) ) {
				continue;
			}
			$seen[ $key ] = true;

			if ( Url_Guard::domain_key( $url ) !== Url_Guard::domain_key( $home['url'] ) ) {
				continue;
			}
			if ( ! self::robots_allows( $url, $data['robots'] ) ) {
				++$data['skipped_by_robots'];
				continue;
			}

			$response = $http->fetch( $url );
			if ( ! $response['ok'] ) {
				if ( Http_Client::looks_blocked( $response ) ) {
					continue;
				}
				$data['page_errors'][] = array(
					'url'  => $url,
					'code' => (int) $response['code'],
				);
				continue;
			}
			if ( self::is_html( $response ) ) {
				$data['truncated'] = $data['truncated'] || $response['truncated'];
				$data['pages'][]   = Html_Page::parse( $response['body'], $response['url'] );
			}
		}
	}

	/**
	 * Scores how useful a page is to the sample.
	 *
	 * @param string $url  URL.
	 * @param string $text Link text.
	 * @return int
	 */
	private static function page_rank( $url, $text ) {
		$haystack = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) . ' ' . $text );
		if ( preg_match( '/contact|privacy|cookie/', $haystack ) ) {
			return 10;
		}
		if ( preg_match( '/about|service|product|pricing/', $haystack ) ) {
			return 6;
		}
		if ( preg_match( '/blog|news|article|insight/', $haystack ) ) {
			return 4;
		}
		return 2;
	}

	/**
	 * Applies robots.txt rules to a sampled page (longest match wins).
	 *
	 * @param string              $url    URL.
	 * @param array<string,mixed> $robots Robots data.
	 * @return bool
	 */
	private static function robots_allows( $url, array $robots ) {
		$parts = wp_parse_url( $url );
		$path  = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

		$best    = -1;
		$allowed = true;
		foreach ( array(
			'disallow' => false,
			'allow'    => true,
		) as $key => $verdict ) {
			foreach ( (array) ( $robots[ $key ] ?? array() ) as $rule ) {
				$pattern = '#^' . str_replace( array( '\*', '\$' ), array( '.*', '$' ), preg_quote( (string) $rule, '#' ) ) . '#';
				$length  = strlen( (string) $rule );
				if ( $length > $best && preg_match( $pattern, $path ) ) {
					$best    = $length;
					$allowed = $verdict;
				}
			}
		}
		return $allowed;
	}

	/**
	 * Reads the date of the newest item in the site's feed.
	 *
	 * @param array<string,mixed> $page Parsed page.
	 * @param array<string,mixed> $data Crawl data.
	 * @param Http_Client         $http Client.
	 * @return void
	 */
	private static function read_feed( array $page, array &$data, Http_Client $http ) {
		if ( '' === $page['feed'] ) {
			return;
		}
		$response = $http->fetch( $page['feed'], array( 'max_bytes' => 512000 ) );
		if ( $response['ok'] && preg_match( '/<(?:pubDate|updated|lastBuildDate)>([^<]{8,60})</i', $response['body'], $match ) ) {
			$data['feed_date'] = trim( $match[1] );
		}
	}

	/**
	 * Reads an XML element's values with a pattern. No XML parser means no
	 * entity-expansion or external-entity risk from a hostile sitemap.
	 *
	 * @param string $xml XML.
	 * @param string $tag Element name.
	 * @return string[]
	 */
	private static function xml_values( $xml, $tag ) {
		if ( ! preg_match_all( '#<(?:\w+:)?' . $tag . '>\s*(?:<!\[CDATA\[)?\s*([^<\]]{1,2000}?)\s*(?:\]\]>)?\s*</(?:\w+:)?' . $tag . '>#i', $xml, $matches ) ) {
			return array();
		}
		return $matches[1];
	}

	/**
	 * The newest date in a list, as Y-m-d.
	 *
	 * @param string[] $dates Dates.
	 * @return string
	 */
	public static function newest( array $dates ) {
		$newest = 0;
		$limit  = time() + DAY_IN_SECONDS;
		foreach ( $dates as $date ) {
			$time = strtotime( (string) $date );
			if ( $time && $time <= $limit && $time > $newest ) {
				$newest = $time;
			}
		}
		return $newest ? gmdate( 'Y-m-d', $newest ) : '';
	}

	/**
	 * Keeps only the headers the checks read.
	 *
	 * @param array<string,string> $headers Headers.
	 * @return array<string,string>
	 */
	private static function keep_headers( array $headers ) {
		$keep = array( 'strict-transport-security', 'content-security-policy', 'x-content-type-options', 'x-frame-options', 'referrer-policy', 'permissions-policy', 'x-robots-tag', 'content-type', 'server', 'last-modified' );
		return array_intersect_key( $headers, array_flip( $keep ) );
	}

	/**
	 * Whether a response is HTML.
	 *
	 * @param array<string,mixed> $response Response.
	 * @return bool
	 */
	private static function is_html( array $response ) {
		$type = (string) ( $response['headers']['content-type'] ?? '' );
		return '' === $type ? (bool) preg_match( '/<html|<!doctype html/i', substr( $response['body'], 0, 2048 ) ) : (bool) preg_match( '#text/html|application/xhtml#i', $type );
	}

	/**
	 * Scheme and host of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function origin( $url ) {
		$parts = wp_parse_url( $url );
		return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );
	}

	/**
	 * Turns an internal error code into words for a report.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function error_message( $code ) {
		switch ( $code ) {
			case 'sar_url_blocked':
				return __( 'The address leads to a private or restricted location, so it was not scanned.', 'site-audit-report' );
			case 'sar_dns_failed':
				return __( 'The website address could not be found in DNS.', 'site-audit-report' );
			case 'sar_timeout':
				return __( 'The website did not answer in time.', 'site-audit-report' );
			case 'sar_too_many_redirects':
				return __( 'The website redirected too many times.', 'site-audit-report' );
			case 'sar_budget':
				return __( 'The scan reached its request limit.', 'site-audit-report' );
			case 'sar_tls':
				return __( 'A secure connection to the website could not be made.', 'site-audit-report' );
			default:
				return __( 'The website could not be reached.', 'site-audit-report' );
		}
	}
}
