<?php
/**
 * WordPress health: core, theme and plugin versions the site shows publicly,
 * matched against known vulnerabilities, plus passive hardening checks.
 *
 * Every request is a plain GET of a public address. Nothing logs in, posts
 * or changes anything on the scanned site.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report\Modules;

use Site_Audit_Report\Check;
use Site_Audit_Report\Module;
use Site_Audit_Report\Scan_Context;
use Site_Audit_Report\Vuln_Db;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress module.
 */
final class Wordpress implements Module {

	const CATEGORY    = 'wordpress';
	const MAX_PLUGINS = 25;
	const TIME_BUDGET = 60;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'wordpress';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Inspecting WordPress, plugins and themes', 'site-audit-report' );
	}

	/**
	 * The scored checks, for "not checked" fallbacks.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function catalog() {
		return array(
			'wp_core'         => array( __( 'WordPress core version', 'site-audit-report' ), 5 ),
			'wp_plugins'      => array( __( 'Plugin vulnerabilities', 'site-audit-report' ), 5 ),
			'wp_theme'        => array( __( 'Theme vulnerabilities', 'site-audit-report' ), 4 ),
			'wp_kev'          => array( __( 'Actively exploited vulnerabilities (CISA list)', 'site-audit-report' ), 5 ),
			'wp_version_leak' => array( __( 'Version number on show', 'site-audit-report' ), 1 ),
			'wp_users'        => array( __( 'Usernames listed publicly', 'site-audit-report' ), 2 ),
			'wp_xmlrpc'       => array( __( 'XML-RPC open', 'site-audit-report' ), 1 ),
			'wp_readme'       => array( __( 'readme.html exposed', 'site-audit-report' ), 1 ),
			'wp_listing'      => array( __( 'Uploads folder listing', 'site-audit-report' ), 3 ),
			'wp_debug_log'    => array( __( 'Public debug log', 'site-audit-report' ), 4 ),
			'wp_login'        => array( __( 'Login page address', 'site-audit-report' ), 1 ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Scan_Context $context Context.
	 */
	public function run( Scan_Context $context ) {
		$started = time();
		$http    = $context->http;
		$origin  = self::origin( (string) ( $context->data['final_url'] ?? $context->audit['site_url'] ) );

		if ( ! $context->readable() ) {
			return array_merge(
				array( $this->detect_check( array( 'is_wp' => null ), $context->unreadable_reason() ) ),
				Check::all_not_checked( self::CATEGORY, self::catalog(), $context->unreadable_reason() )
			);
		}

		$home    = $http->fetch( $origin . '/', array( 'timeout' => 15 ) );
		$html    = (string) ( $home['body'] ?? '' );
		$headers = (array) ( $home['headers'] ?? array() );

		$info = array(
			'is_wp'      => false,
			'server'     => self::server( $headers ),
			'cdn'        => self::cdn( $headers ),
			'core'       => array(
				'version' => '',
				'source'  => '',
				'latest'  => '',
			),
			'themes'     => array(),
			'components' => array(),
			'hardening'  => array(),
		);

		$info['is_wp'] = (bool) preg_match( '#/wp-(content|includes)/|<meta[^>]+generator[^>]+WordPress|/wp-json/#i', $html )
			|| false !== stripos( (string) ( $headers['link'] ?? '' ), 'api.w.org' );

		if ( ! $info['is_wp'] ) {
			$reason = __( 'This site does not appear to run WordPress, so the WordPress checks do not apply.', 'site-audit-report' );
			return array_merge(
				array( $this->detect_check( $info, $reason ) ),
				Check::all_not_checked( self::CATEGORY, self::catalog(), $reason )
			);
		}

		// Core version.
		$generator = '';
		if ( preg_match( '#<meta[^>]+name=["\']generator["\'][^>]+content=["\']WordPress\s+([0-9.]+)#i', $html, $m ) ) {
			$generator            = $m[1];
			$info['core']['version'] = $m[1];
			$info['core']['source']  = 'generator';
		}
		if ( '' === $info['core']['version'] ) {
			$feed = $http->fetch( $origin . '/feed/', array( 'max_bytes' => 32768 ) );
			if ( preg_match( '#<generator>https?://wordpress\.org/\?v=([0-9.]+)</generator>#i', (string) ( $feed['body'] ?? '' ), $m ) ) {
				$info['core']['version'] = $m[1];
				$info['core']['source']  = 'feed';
			}
		}
		if ( '' === $info['core']['version'] && preg_match( '#/wp-includes/[^"\'\s>]+\?(?:[^"\'\s>]*&(?:amp;)?)?ver=([0-9]+\.[0-9.]+)#i', $html, $m ) ) {
			$info['core']['version'] = $m[1];
			$info['core']['source']  = 'asset';
		}
		$info['core']['latest'] = Vuln_Db::latest( 'core' );

		// Plugins and themes referenced by the home page.
		$assets = self::assets( $html );

		foreach ( array_slice( $assets['themes'], 0, 2, true ) as $slug => $vers ) {
			$style = $http->fetch( $origin . '/wp-content/themes/' . $slug . '/style.css', array( 'max_bytes' => 8192, 'timeout' => 6, 'range' => true ) );
			$body  = ! empty( $style['ok'] ) ? (string) $style['body'] : '';
			$name  = preg_match( '/Theme Name:\s*(.+)/i', $body, $m ) ? trim( $m[1] ) : self::nice( $slug );
			$ver   = preg_match( '/^\s*\*?\s*Version:\s*([0-9][0-9a-z.\-]*)/im', $body, $m ) ? $m[1] : '';
			$src   = '' !== $ver ? 'style.css' : '';
			if ( '' === $ver ) {
				$ver = self::best_ver( $vers, $info['core']['version'] );
				$src = '' !== $ver ? 'asset' : '';
			}
			$info['themes'][] = $this->component( 'theme', $slug, $name, $ver, $src );
		}

		// Plugins: those the home page loads, plus those that announce REST API
		// routes. The REST index finds plugins that load nothing on the home page.
		$rest    = self::rest_plugins( $http, $origin );
		$plugins = array();
		foreach ( $assets['plugins'] as $slug => $vers ) {
			$plugins[ $slug ] = array(
				'vers'   => $vers,
				'by'     => array( 'page' ),
				'verify' => false,
			);
		}
		foreach ( $rest['plugins'] as $slug => $meta ) {
			if ( isset( $assets['themes'][ $slug ] ) ) {
				continue; // A theme's own routes, such as bricks/v1.
			}
			if ( isset( $plugins[ $slug ] ) ) {
				$plugins[ $slug ]['by'][] = 'rest';
			} else {
				$plugins[ $slug ] = array(
					'vers'   => array(),
					'by'     => array( 'rest' ),
					'verify' => ! $meta['known'],
				);
			}
		}
		$info['rest'] = array(
			'open'       => $rest['open'],
			'namespaces' => count( $rest['namespaces'] ),
		);

		foreach ( array_slice( $plugins, 0, self::MAX_PLUGINS, true ) as $slug => $meta ) {
			$name = self::nice( $slug );
			$ver  = '';
			$src  = '';
			$body = '';
			if ( time() - $started < self::TIME_BUDGET ) {
				$readme = $http->fetch( $origin . '/wp-content/plugins/' . $slug . '/readme.txt', array( 'max_bytes' => 16384, 'timeout' => 6, 'range' => true ) );
				$body   = ! empty( $readme['ok'] ) && ! preg_match( '#<html#i', substr( (string) $readme['body'], 0, 500 ) ) ? (string) $readme['body'] : '';
				if ( preg_match( '/^===\s*(.+?)\s*===/m', $body, $m ) ) {
					$name = self::short_name( $m[1] );
				}
				if ( preg_match( '/^\s*Stable tag:\s*([0-9][0-9a-z.\-]*)/im', $body, $m ) ) {
					$ver = $m[1];
					$src = 'readme.txt';
				}
			}
			// A guessed slug from an unknown REST namespace counts only if its readme exists.
			if ( $meta['verify'] && ! preg_match( '/^===.+===/m', $body ) ) {
				continue;
			}
			if ( '' === $ver ) {
				$ver = self::best_ver( $meta['vers'], $info['core']['version'] );
				$src = '' !== $ver ? 'asset' : '';
			}
			$info['components'][] = $this->component( 'plugin', $slug, $name, $ver, $src, $meta['by'] );
		}

		// Hardening probes.
		$info['hardening'] = $this->hardening( $context, $origin, $generator, $html );

		$vuln = $this->vuln_checks( $info );
		return array_merge(
			array( $this->detect_check( $info, '' ) ),
			$vuln,
			$this->hardening_checks( $info['hardening'], $generator )
		);
	}

	/**
	 * Looks a component up and returns its report row.
	 *
	 * @param string $type    plugin|theme.
	 * @param string $slug    Slug.
	 * @param string $name    Display name.
	 * @param string $version Version seen.
	 * @param string   $source  Where the version came from.
	 * @param string[] $by      How it was found: page, rest.
	 * @return array<string,mixed>
	 */
	private function component( $type, $slug, $name, $version, $source, array $by = array( 'page' ) ) {
		$found  = Vuln_Db::lookup( $type, $slug, $version );
		$latest = Vuln_Db::latest( $type, $slug );
		return array(
			'type'     => $type,
			'slug'     => $slug,
			'name'     => mb_substr( wp_strip_all_tags( $name ), 0, 80 ),
			'version'  => $version,
			'source'   => $source,
			'found_by' => array_values( array_unique( $by ) ),
			'latest'   => $latest,
			'outdated' => '' !== $version && '' !== $latest && version_compare( $version, $latest, '<' ),
			'checked'  => $found['checked'],
			'known'    => $found['known'],
			'matches'  => array_slice( $found['matches'], 0, 8 ),
			'count'    => count( $found['matches'] ),
		);
	}

	/**
	 * The "what we found" check that carries the structured inspection data.
	 *
	 * @param array<string,mixed> $info   Inspection.
	 * @param string              $reason Why WordPress checks did not run, if they did not.
	 * @return array<string,mixed>
	 */
	private function detect_check( array $info, $reason ) {
		if ( true === ( $info['is_wp'] ?? null ) ) {
			$evidence = sprintf(
				/* translators: 1: number of plugins, 2: number of themes. */
				__( 'WordPress detected. The home page shows %1$d plugins and %2$d themes.', 'site-audit-report' ),
				count( $info['components'] ),
				count( $info['themes'] )
			);
		} else {
			$evidence = $reason;
		}
		return Check::make( Check::INFO, self::CATEGORY, 'wp_detected', __( 'What the site shows the public', 'site-audit-report' ), 1, $evidence, array( 'data' => $info ) );
	}

	/**
	 * Core, plugin, theme and CISA checks.
	 *
	 * @param array<string,mixed> $info Inspection.
	 * @return array<int,array<string,mixed>>
	 */
	private function vuln_checks( array &$info ) {
		$cat    = self::catalog();
		$checks = array();

		// Core.
		$core = $info['core'];
		if ( '' === $core['version'] ) {
			$checks[] = Check::make( Check::PASS, self::CATEGORY, 'wp_core', $cat['wp_core'][0], $cat['wp_core'][1], __( 'The WordPress version is not shown publicly, which is good practice. Make sure it is kept up to date.', 'site-audit-report' ) );
			$core_row = null;
		} else {
			$found    = Vuln_Db::lookup( 'core', '', $core['version'] );
			$core_row = array(
				'type'     => 'core',
				'slug'     => 'wordpress',
				'name'     => 'WordPress',
				'version'  => $core['version'],
				'source'   => $core['source'],
				'latest'   => $core['latest'],
				'outdated' => '' !== $core['latest'] && version_compare( $core['version'], $core['latest'], '<' ),
				'checked'  => $found['checked'],
				'known'    => $found['known'],
				'matches'  => array_slice( $found['matches'], 0, 8 ),
				'count'    => count( $found['matches'] ),
			);
			$info['core']['row'] = $core_row;
			$checks[] = $this->component_check( 'wp_core', $cat['wp_core'], array( $core_row ) );
		}

		$checks[] = $info['components']
			? $this->component_check( 'wp_plugins', $cat['wp_plugins'], $info['components'] )
			: Check::make( Check::PASS, self::CATEGORY, 'wp_plugins', $cat['wp_plugins'][0], $cat['wp_plugins'][1], __( 'No plugins are visible in the home page HTML.', 'site-audit-report' ) );

		$checks[] = $info['themes']
			? $this->component_check( 'wp_theme', $cat['wp_theme'], $info['themes'] )
			: Check::not_checked( self::CATEGORY, 'wp_theme', $cat['wp_theme'][0], $cat['wp_theme'][1], __( 'The active theme could not be identified from the home page.', 'site-audit-report' ) );

		// CISA Known Exploited Vulnerabilities.
		$rows  = array_merge( $core_row ? array( $core_row ) : array(), $info['themes'], $info['components'] );
		$kev   = array();
		$any   = false;
		foreach ( $rows as $row ) {
			$any = $any || $row['checked'];
			foreach ( $row['matches'] as $match ) {
				if ( ! empty( $match['kev'] ) ) {
					$kev[] = $row['name'] . ' ' . $row['version'] . ': ' . implode( ', ', $match['cves'] );
				}
			}
		}
		if ( ! $any ) {
			$checks[] = Check::not_checked( self::CATEGORY, 'wp_kev', $cat['wp_kev'][0], $cat['wp_kev'][1], __( 'The vulnerability database could not be reached.', 'site-audit-report' ) );
		} elseif ( $kev ) {
			$checks[] = Check::make(
				Check::FAIL,
				self::CATEGORY,
				'wp_kev',
				$cat['wp_kev'][0],
				$cat['wp_kev'][1],
				__( 'At least one version seen matches a vulnerability that the US agency CISA lists as exploited by attackers in the wild.', 'site-audit-report' ),
				array(
					'fix'    => __( 'Update the items below today. Attackers are already using these holes.', 'site-audit-report' ),
					'effort' => 'low',
					'items'  => array_values( array_unique( $kev ) ),
					'score'  => 0,
				)
			);
		} else {
			$checks[] = Check::make( Check::PASS, self::CATEGORY, 'wp_kev', $cat['wp_kev'][0], $cat['wp_kev'][1], __( 'None of the versions seen are on the CISA list of exploited vulnerabilities.', 'site-audit-report' ) );
		}

		return $checks;
	}

	/**
	 * Builds one scored check from a set of component rows.
	 *
	 * @param string                         $id   Check ID.
	 * @param array{0:string,1:int}          $meta Label and weight.
	 * @param array<int,array<string,mixed>> $rows Components.
	 * @return array<string,mixed>
	 */
	private function component_check( $id, array $meta, array $rows ) {
		$worst    = 0;
		$kev      = false;
		$vuln     = 0;
		$outdated = 0;
		$checked  = 0;
		$hidden   = 0;
		$items    = array();
		foreach ( $rows as $row ) {
			$checked += $row['checked'] ? 1 : 0;
			$hidden  += '' === $row['version'] ? 1 : 0;
			if ( $row['outdated'] ) {
				++$outdated;
			}
			if ( $row['count'] ) {
				++$vuln;
				$top   = $row['matches'][0];
				$worst = max( $worst, Vuln_Db::SEVERITY_RANK[ $top['severity'] ] ?? 0 );
				$kev   = $kev || ! empty( $top['kev'] );
				$items[] = sprintf(
					/* translators: 1: name, 2: version, 3: number of issues, 4: severity, 5: fixed version note. */
					__( '%1$s %2$s: %3$d known issue(s), worst %4$s%5$s', 'site-audit-report' ),
					$row['name'],
					$row['version'],
					$row['count'],
					$top['severity'],
					$top['fixed'] ? sprintf( /* translators: %s: version. */ __( ', fixed in %s', 'site-audit-report' ), $top['fixed'] ) : ''
				);
			} elseif ( $row['outdated'] ) {
				/* translators: 1: name, 2: version seen, 3: newest version. */
				$items[] = sprintf( __( '%1$s %2$s: update available (%3$s)', 'site-audit-report' ), $row['name'], $row['version'], $row['latest'] );
			}
		}

		if ( ! $checked ) {
			return Check::not_checked( self::CATEGORY, $id, $meta[0], $meta[1], __( 'The vulnerability database could not be reached.', 'site-audit-report' ) );
		}

		if ( $vuln ) {
			$score = $kev ? 0 : array(
				4 => 0,
				3 => 15,
				2 => 50,
				1 => 75,
				0 => 60,
			)[ $worst ];
			return Check::make(
				$worst >= 3 || $kev ? Check::FAIL : Check::WARN,
				self::CATEGORY,
				$id,
				$meta[0],
				$meta[1],
				/* translators: %d: number of items. */
				sprintf( _n( '%d item matches a known vulnerability for the version seen.', '%d items match a known vulnerability for the version seen.', $vuln, 'site-audit-report' ), $vuln ),
				array(
					'fix'    => __( 'Update each item to its fixed version (or remove it if unused), on a staging copy first.', 'site-audit-report' ),
					'effort' => 'low',
					'items'  => $items,
					'score'  => $score,
				)
			);
		}
		if ( $outdated ) {
			return Check::make(
				Check::WARN,
				self::CATEGORY,
				$id,
				$meta[0],
				$meta[1],
				/* translators: %d: number of items. */
				sprintf( _n( 'No known vulnerabilities, but %d item is behind its latest release.', 'No known vulnerabilities, but %d items are behind their latest release.', $outdated, 'site-audit-report' ), $outdated ),
				array(
					'fix'    => __( 'Apply the pending updates so the next published hole does not catch you out.', 'site-audit-report' ),
					'effort' => 'low',
					'items'  => $items,
					'score'  => 80,
				)
			);
		}
		$note = $hidden
			/* translators: %d: number of items. */
			? sprintf( __( 'No known vulnerabilities for the versions seen. %d item hides its version, so it could not be matched.', 'site-audit-report' ), $hidden )
			: __( 'No known vulnerabilities for the versions seen.', 'site-audit-report' );
		return Check::make( Check::PASS, self::CATEGORY, $id, $meta[0], $meta[1], $note );
	}

	/**
	 * Passive probes of well-known WordPress addresses.
	 *
	 * @param Scan_Context $context   Context.
	 * @param string       $origin    Origin.
	 * @param string       $generator Version from the generator tag.
	 * @param string       $html      Home page HTML.
	 * @return array<string,mixed>
	 */
	private function hardening( Scan_Context $context, $origin, $generator, $html ) {
		$http = $context->http;
		$out  = array();

		$users = $http->fetch( $origin . '/wp-json/wp/v2/users?per_page=10', array( 'max_bytes' => 65536, 'timeout' => 8 ) );
		$list  = ! empty( $users['ok'] ) ? json_decode( (string) $users['body'], true ) : null;
		$names = array();
		if ( is_array( $list ) ) {
			foreach ( $list as $user ) {
				if ( is_array( $user ) && ! empty( $user['slug'] ) ) {
					$slug    = (string) $user['slug'];
					$names[] = mb_substr( $slug, 0, 1 ) . str_repeat( '•', max( 2, min( 6, mb_strlen( $slug ) - 1 ) ) );
				}
			}
		}
		$out['users'] = array(
			'checked' => empty( $users['error'] ),
			'open'    => (bool) $names,
			'names'   => $names,
		);

		$xml           = $http->fetch( $origin . '/xmlrpc.php', array( 'max_bytes' => 4096, 'timeout' => 6, 'follow' => false ) );
		$out['xmlrpc'] = array(
			'checked' => empty( $xml['error'] ),
			'open'    => false !== stripos( (string) ( $xml['body'] ?? '' ), 'XML-RPC server accepts POST requests only' ),
		);

		$readme        = $http->fetch( $origin . '/readme.html', array( 'max_bytes' => 8192, 'timeout' => 6, 'follow' => false, 'range' => true ) );
		$out['readme'] = array(
			'checked' => empty( $readme['error'] ),
			'open'    => ! empty( $readme['ok'] ) && false !== stripos( (string) $readme['body'], 'WordPress' ) && false !== stripos( (string) $readme['body'], 'readme' ),
		);

		$listing        = $http->fetch( $origin . '/wp-content/uploads/', array( 'max_bytes' => 16384, 'timeout' => 6, 'follow' => false ) );
		$out['listing'] = array(
			'checked' => empty( $listing['error'] ),
			'open'    => ! empty( $listing['ok'] ) && (bool) preg_match( '#<title>\s*Index of /#i', (string) $listing['body'] ),
		);

		$debug        = $http->fetch( $origin . '/wp-content/debug.log', array( 'max_bytes' => 4096, 'timeout' => 8, 'follow' => false, 'range' => true ) );
		$type         = (string) ( $debug['headers']['content-type'] ?? '' );
		$out['debug'] = array(
			'checked' => empty( $debug['error'] ),
			'open'    => ! empty( $debug['ok'] ) && false === stripos( $type, 'html' ) && (bool) preg_match( '/PHP (Warning|Notice|Fatal|Deprecated|Parse)|\[\d{2}-[A-Za-z]{3}-\d{4}/', (string) $debug['body'] ),
		);

		$login        = $http->fetch( $origin . '/wp-login.php', array( 'max_bytes' => 32768, 'timeout' => 6, 'follow' => false ) );
		$out['login'] = array(
			'checked' => empty( $login['error'] ),
			'open'    => ! empty( $login['ok'] ) && false !== stripos( (string) $login['body'], 'user_login' ),
		);

		$out['generator'] = '' !== $generator;
		return $out;
	}

	/**
	 * Hardening results as checks.
	 *
	 * @param array<string,mixed> $h         Probe results.
	 * @param string              $generator Version from the generator tag.
	 * @return array<int,array<string,mixed>>
	 */
	private function hardening_checks( array $h, $generator ) {
		$cat = self::catalog();
		$out = array();

		$out[] = $h['generator']
			? Check::make(
				Check::WARN,
				self::CATEGORY,
				'wp_version_leak',
				$cat['wp_version_leak'][0],
				$cat['wp_version_leak'][1],
				/* translators: %s: version. */
				sprintf( __( 'The page source announces "WordPress %s" to anyone, including bots looking for old versions.', 'site-audit-report' ), $generator ),
				array(
					'fix'    => __( 'Remove the generator tag (remove_action( \'wp_head\', \'wp_generator\' ) or a security plugin).', 'site-audit-report' ),
					'effort' => 'low',
				)
			)
			: Check::make( Check::PASS, self::CATEGORY, 'wp_version_leak', $cat['wp_version_leak'][0], $cat['wp_version_leak'][1], __( 'No version number in the generator tag.', 'site-audit-report' ) );

		$probes = array(
			'wp_users'     => array(
				'users',
				Check::WARN,
				__( 'The REST API lists author usernames, which gives attackers half of every login.', 'site-audit-report' ),
				__( 'Restrict the /wp/v2/users endpoint to logged-in users.', 'site-audit-report' ),
				__( 'Usernames are not listed through the REST API.', 'site-audit-report' ),
			),
			'wp_xmlrpc'    => array(
				'xmlrpc',
				Check::WARN,
				__( 'xmlrpc.php answers requests. It is a common route for password-guessing and pingback abuse.', 'site-audit-report' ),
				__( 'Turn off XML-RPC unless the Jetpack or mobile app needs it.', 'site-audit-report' ),
				__( 'xmlrpc.php does not answer.', 'site-audit-report' ),
			),
			'wp_readme'    => array(
				'readme',
				Check::WARN,
				__( 'The default readme.html is public and helps fingerprint the install.', 'site-audit-report' ),
				__( 'Delete readme.html from the site root, or block it at the server.', 'site-audit-report' ),
				__( 'readme.html is not public.', 'site-audit-report' ),
			),
			'wp_listing'   => array(
				'listing',
				Check::FAIL,
				__( 'Anyone can browse the uploads folder file by file, including files you never linked to.', 'site-audit-report' ),
				__( 'Turn off directory listing (Options -Indexes, or an empty index.php).', 'site-audit-report' ),
				__( 'The uploads folder cannot be browsed.', 'site-audit-report' ),
			),
			'wp_debug_log' => array(
				'debug',
				Check::FAIL,
				__( 'wp-content/debug.log is public. It can leak file paths, queries and sometimes personal data.', 'site-audit-report' ),
				__( 'Delete the log, set WP_DEBUG_LOG to a private path and turn WP_DEBUG off in production.', 'site-audit-report' ),
				__( 'No public debug log.', 'site-audit-report' ),
			),
		);
		foreach ( $probes as $id => $p ) {
			$r = (array) ( $h[ $p[0] ] ?? array() );
			if ( empty( $r['checked'] ) ) {
				$out[] = Check::not_checked( self::CATEGORY, $id, $cat[ $id ][0], $cat[ $id ][1], __( 'The address did not give a clear answer.', 'site-audit-report' ) );
			} elseif ( ! empty( $r['open'] ) ) {
				$out[] = Check::make(
					$p[1],
					self::CATEGORY,
					$id,
					$cat[ $id ][0],
					$cat[ $id ][1],
					$p[2],
					array(
						'fix'    => $p[3],
						'effort' => 'low',
						'items'  => (array) ( $r['names'] ?? array() ),
					)
				);
			} else {
				$out[] = Check::make( Check::PASS, self::CATEGORY, $id, $cat[ $id ][0], $cat[ $id ][1], $p[4] );
			}
		}

		$login = (array) ( $h['login'] ?? array() );
		$out[] = Check::make(
			Check::INFO,
			self::CATEGORY,
			'wp_login',
			$cat['wp_login'][0],
			$cat['wp_login'][1],
			! empty( $login['open'] )
				? __( 'The login page is at the default /wp-login.php. That is normal, but it needs rate limiting and two-factor sign-in.', 'site-audit-report' )
				: __( 'The login page is not at the default address, or is protected.', 'site-audit-report' )
		);

		return $out;
	}

	/**
	 * REST namespaces that map to a plugin slug. Keys are the first path segment.
	 *
	 * @return array<string,string>
	 */
	public static function namespace_map() {
		$map = array(
			'wc'                => 'woocommerce',
			'wc-admin'          => 'woocommerce',
			'wc-analytics'      => 'woocommerce',
			'wc-admin-email'    => 'woocommerce',
			'wc-telemetry'      => 'woocommerce',
			'wc-push-notifications' => 'woocommerce',
			'wccom-site'        => 'woocommerce',
			'jetpack'           => 'jetpack',
			'my-jetpack'        => 'jetpack',
			'videopress'        => 'jetpack',
			'jetpack-boost'     => 'jetpack-boost',
			'yoast'             => 'wordpress-seo',
			'rankmath'          => 'seo-by-rank-math',
			'aioseo'            => 'all-in-one-seo-pack',
			'contact-form-7'    => 'contact-form-7',
			'elementor'         => 'elementor',
			'elementor-pro'     => 'elementor-pro',
			'akismet'           => 'akismet',
			'wordfence'         => 'wordfence',
			'litespeed'         => 'litespeed-cache',
			'redirection'       => 'redirection',
			'wpforms'           => 'wpforms-lite',
			'gf'                => 'gravityforms',
			'ninja-forms'       => 'ninja-forms',
			'nf-submissions'    => 'ninja-forms',
			'mailpoet'          => 'mailpoet',
			'wp-statistics'     => 'wp-statistics',
			'wp-rocket'         => 'wp-rocket',
			'jet-engine'        => 'jet-engine',
			'jet-elements'      => 'jet-elements',
			'regenerate-thumbnails' => 'regenerate-thumbnails',
			'sg-security'       => 'sg-security',
			'siteground-optimizer' => 'sg-cachepress',
			'wpml'              => 'sitepress-multilingual-cms',
			'tribe'             => 'the-events-calendar',
			'buddypress'        => 'buddypress',
			'bbp'               => 'bbpress',
			'learnpress'        => 'learnpress',
			'lp'                => 'learnpress',
			'tutor'             => 'tutor',
			'llms'              => 'lifterlms',
			'mc4wp'             => 'mailchimp-for-wp',
			'updraftplus'       => 'updraftplus',
			'smush'             => 'wp-smushit',
			'ithemes-security'  => 'better-wp-security',
			'solid-security'    => 'better-wp-security',
			'surecart'          => 'surecart',
			'suremails'         => 'suremails',
			'uagb'              => 'ultimate-addons-for-gutenberg',
			'kb-mailerlite'     => 'kadence-blocks',
			'kadence-blocks'    => 'kadence-blocks',
			'generateblocks'    => 'generateblocks',
			'complianz'         => 'complianz-gdpr',
			'cky'               => 'cookie-law-info',
			'fluent-auth'       => 'fluent-security',
			'fluentform'        => 'fluentform',
			'fluent-crm'        => 'fluent-crm',
			'fluent-smtp'       => 'fluent-smtp',
			'wp-mail-smtp'      => 'wp-mail-smtp',
			'forminator'        => 'forminator',
			'give-api'          => 'give',
			'edd'               => 'easy-digital-downloads',
			'pmpro'             => 'paid-memberships-pro',
			'ultimate-member'   => 'ultimate-member',
			'wp-job-manager'    => 'wp-job-manager',
			'flying-press'      => 'flying-press',
			'two-factor'        => 'two-factor',
			'block-visibility'  => 'block-visibility',
			'wpcode'            => 'insert-headers-and-footers',
			'search-filter'     => 'search-filter',
			'filebird'          => 'filebird',
			'wpdatatables'      => 'wpdatatables',
			'mailchimp-for-woocommerce' => 'mailchimp-for-woocommerce',
		);
		/**
		 * Filters the REST namespace to plugin slug map.
		 *
		 * @param array $map First namespace segment => plugin slug.
		 */
		return (array) apply_filters( 'sar_rest_namespace_map', $map );
	}

	/**
	 * Plugins announced by the public REST API index (/wp-json/).
	 *
	 * @param \Site_Audit_Report\Http_Client $http   Client.
	 * @param string                         $origin Origin.
	 * @return array{open:bool,namespaces:string[],plugins:array<string,array{known:bool,ns:string}>}
	 */
	private static function rest_plugins( $http, $origin ) {
		$out = array(
			'open'       => false,
			'namespaces' => array(),
			'plugins'    => array(),
		);
		foreach ( array( $origin . '/wp-json/', $origin . '/?rest_route=/' ) as $url ) {
			$res = $http->fetch( $url, array( 'max_bytes' => 1048576, 'timeout' => 10 ) );
			// The namespaces list sits near the top, so a truncated body is still enough.
			if ( ! empty( $res['ok'] ) && preg_match( '/"namespaces"\s*:\s*\[(.*?)\]/s', (string) $res['body'], $m ) ) {
				$out['open']       = true;
				$out['namespaces'] = array_slice( array_map( 'stripslashes', (array) json_decode( '[' . $m[1] . ']', true ) ), 0, 200 );
				break;
			}
		}
		$core = array( 'oembed', 'wp', 'wp-site-health', 'wp-block-editor', 'wp-abilities', 'batch', 'mcp', 'wpcom', 'wporg', 'idea' );
		$map  = self::namespace_map();
		$new  = 0;
		foreach ( $out['namespaces'] as $ns ) {
			$first = strtolower( (string) strtok( (string) $ns, '/' ) );
			if ( '' === $first || in_array( $first, $core, true ) || 0 === strpos( $first, 'wporg' ) ) {
				continue;
			}
			if ( isset( $map[ $first ] ) ) {
				$out['plugins'][ $map[ $first ] ] = array(
					'known' => true,
					'ns'    => $ns,
				);
				continue;
			}
			// An unknown namespace is often the plugin slug itself; the caller confirms it through readme.txt.
			$slug = sanitize_key( preg_replace( '/[-_]?v\d+$/', '', $first ) );
			if ( '' !== $slug && ! isset( $out['plugins'][ $slug ] ) && $new < 8 ) {
				++$new;
				$out['plugins'][ $slug ] = array(
					'known' => false,
					'ns'    => $ns,
				);
			}
		}
		return $out;
	}

	/**
	 * Plugin and theme slugs with the ?ver= values seen for each.
	 *
	 * @param string $html HTML.
	 * @return array{plugins:array<string,string[]>,themes:array<string,string[]>}
	 */
	public static function assets( $html ) {
		$out = array(
			'plugins' => array(),
			'themes'  => array(),
		);
		$html = str_replace( array( '\\/', '&#038;', '&amp;' ), array( '/', '&', '&' ), $html );
		if ( preg_match_all( '#/wp-content/(plugins|themes)/([a-z0-9][a-z0-9_.\-]{0,80})/([^"\'\s<>)]*)#i', $html, $all, PREG_SET_ORDER ) ) {
			foreach ( $all as $m ) {
				$bucket = strtolower( $m[1] );
				$slug   = strtolower( $m[2] );
				if ( ! isset( $out[ $bucket ][ $slug ] ) ) {
					$out[ $bucket ][ $slug ] = array();
				}
				if ( preg_match( '/[?&]ver=([0-9][0-9a-z.\-]{0,20})/i', $m[3], $v ) ) {
					$out[ $bucket ][ $slug ][] = $v[1];
				}
			}
		}
		return $out;
	}

	/**
	 * The most likely component version from ?ver= values. A value equal to
	 * the core version usually just means the asset was versioned by core.
	 *
	 * @param string[] $vers Values.
	 * @param string   $core Core version.
	 * @return string
	 */
	private static function best_ver( array $vers, $core ) {
		$counts = array_count_values( array_filter( $vers, static fn( $v ) => $v !== $core && preg_match( '/^\d+(\.\d+){1,3}$/', $v ) ) );
		if ( ! $counts ) {
			return '';
		}
		arsort( $counts );
		return (string) array_key_first( $counts );
	}

	/**
	 * Hosting server name.
	 *
	 * @param array<string,string> $headers Headers.
	 * @return string
	 */
	private static function server( array $headers ) {
		$server = trim( (string) ( $headers['server'] ?? '' ) );
		if ( '' === $server && ! empty( $headers['x-powered-by'] ) ) {
			$server = (string) $headers['x-powered-by'];
		}
		return mb_substr( $server, 0, 40 );
	}

	/**
	 * CDN or edge network, from response headers.
	 *
	 * @param array<string,string> $headers Headers.
	 * @return string
	 */
	private static function cdn( array $headers ) {
		$map = array(
			'cf-ray'           => 'Cloudflare',
			'x-sucuri-id'      => 'Sucuri',
			'x-amz-cf-id'      => 'Amazon CloudFront',
			'x-vercel-id'      => 'Vercel',
			'x-kinsta-cache'   => 'Kinsta CDN',
			'x-fastly-request-id' => 'Fastly',
			'x-akamai-transformed' => 'Akamai',
			'x-bunny-cache'    => 'Bunny CDN',
			'x-qc-pop'         => 'QUIC.cloud',
			'x-wpe-request-id' => 'WP Engine',
		);
		foreach ( $map as $header => $name ) {
			if ( isset( $headers[ $header ] ) ) {
				return $name;
			}
		}
		$server = strtolower( (string) ( $headers['server'] ?? '' ) );
		foreach ( array( 'cloudflare' => 'Cloudflare', 'bunnycdn' => 'Bunny CDN', 'akamai' => 'Akamai', 'sucuri' => 'Sucuri' ) as $needle => $name ) {
			if ( false !== strpos( $server, $needle ) ) {
				return $name;
			}
		}
		if ( ! empty( $headers['x-served-by'] ) && false !== stripos( (string) $headers['x-served-by'], 'cache' ) ) {
			return 'Fastly';
		}
		return '';
	}

	/**
	 * Scheme and host.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function origin( $url ) {
		$parts = wp_parse_url( $url );
		$port  = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . $port;
	}

	/**
	 * A plugin's product name without its marketing tagline
	 * ("Yoast SEO - Advanced SEO with…" becomes "Yoast SEO").
	 *
	 * @param string $name Name from readme.txt.
	 * @return string
	 */
	public static function short_name( $name ) {
		$name = trim( html_entity_decode( (string) $name, ENT_QUOTES ) );
		if ( mb_strlen( $name ) > 28 ) {
			$parts = preg_split( '/\s+[-–—|:]\s+/u', $name, 2 );
			if ( isset( $parts[0] ) && mb_strlen( $parts[0] ) >= 3 ) {
				$name = $parts[0];
			}
		}
		return mb_substr( $name, 0, 60 );
	}

	/**
	 * A readable name from a slug.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function nice( $slug ) {
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}
}
