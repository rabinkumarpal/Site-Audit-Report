<?php
/**
 * Looks up known vulnerabilities for WordPress core, plugins and themes.
 *
 * Sources: WPVulnerability.net (free, no key) and the CISA Known Exploited
 * Vulnerabilities catalogue. Both are fixed, trusted hosts, so they are called
 * with wp_safe_remote_get() and cached rather than through the scan's guarded
 * client and request budget.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Vulnerability lookups.
 */
final class Vuln_Db {

	const API      = 'https://www.wpvulnerability.net/';
	const KEV_URL  = 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json';
	const KEV_OPT  = 'sar_kev_cves';
	const CACHE_TT = 12 * HOUR_IN_SECONDS;

	/**
	 * Severity order, worst first.
	 */
	const SEVERITY_RANK = array(
		'critical' => 4,
		'high'     => 3,
		'medium'   => 2,
		'low'      => 1,
		'unknown'  => 0,
	);

	/**
	 * Raw vulnerability list for a component.
	 *
	 * @param string $type    core|plugin|theme.
	 * @param string $subject Slug, or the core version.
	 * @return array<int,array<string,mixed>>|null Null when the lookup failed.
	 */
	private static function fetch( $type, $subject ) {
		$subject = 'core' === $type ? preg_replace( '/[^0-9.]/', '', (string) $subject ) : sanitize_key( (string) $subject );
		if ( '' === $subject ) {
			return array();
		}
		// v2: records carry a stable id, which the vulnerability watch depends on.
		$key    = 'sar_vdb3_' . md5( $type . ':' . $subject );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_safe_remote_get(
			self::API . $type . '/' . rawurlencode( $subject ) . '/',
			array(
				'timeout'    => 8,
				'user-agent' => Http_Client::user_agent(),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) ) {
			return null;
		}
		$list = is_array( $json['data']['vulnerability'] ?? null ) ? $json['data']['vulnerability'] : array();

		// Keep only what reports need, so the cache stays small.
		$slim = array();
		foreach ( $list as $item ) {
			$slim[] = self::slim( (array) $item );
		}
		set_transient( $key, $slim, self::CACHE_TT );
		return $slim;
	}

	/**
	 * Reduces an API record to the fields reports use.
	 *
	 * @param array<string,mixed> $item Record.
	 * @return array<string,mixed>
	 */
	private static function slim( array $item ) {
		$cves  = array();
		$dates = array();
		$link  = '';
		$kind  = '';
		$desc  = '';
		foreach ( (array) ( $item['source'] ?? array() ) as $source ) {
			$id   = (string) ( $source['id'] ?? '' );
			$name = trim( preg_replace( '/\s+/', ' ', html_entity_decode( (string) ( $source['name'] ?? '' ), ENT_QUOTES ) ) );
			if ( preg_match( '/^CVE-\d{4}-\d+$/', $id ) ) {
				$cves[] = $id;
			}
			// The type of issue, e.g. "Unrestricted File Upload", without the source's own version claims.
			if ( '' === $kind && preg_match( '/\s-\s(.{6,140})$/', $name, $m ) ) {
				$kind = $m[1];
			} elseif ( '' === $kind && preg_match( '/vulnerable to\s+(.{6,140})$/i', $name, $m ) ) {
				$kind = ucfirst( rtrim( $m[1], '. ' ) );
			}
			if ( '' === $desc && ! empty( $source['description'] ) ) {
				$desc = trim( preg_replace( '/^\[[a-z]{2}\]\s*/', '', wp_strip_all_tags( html_entity_decode( (string) $source['description'], ENT_QUOTES ) ) ) );
				$desc = preg_replace( '/^(In\s+)?WordPress[^,]*?(before|through|prior to)[^,]*?,\s*/i', '', $desc );
				$parts = preg_split( '/(?<=[a-z0-9)])\.\s+/i', $desc );
				while ( count( $parts ) > 1 && preg_match( '/^(WordPress is (a|an|the) |This (has been|was|is) (patched|fixed)|Users? (are|is|should))/i', $parts[0] ) ) {
					array_shift( $parts );
				}
				$desc = (string) $parts[0];
				$desc = mb_strlen( $desc ) > 110 ? rtrim( mb_substr( $desc, 0, 108 ) ) . '…' : $desc;
				$desc = ucfirst( rtrim( $desc, '.' ) );
			}
			$date = (string) ( $source['date'] ?? '' );
			if ( preg_match( '/^(19|20)\d\d-\d\d-\d\d$/', $date ) && '0000' !== substr( $date, 0, 4 ) ) {
				$dates[] = $date;
			}
			if ( '' === $link && ! empty( $source['link'] ) ) {
				$link = (string) $source['link'];
			}
		}
		$range = trim( preg_replace( '/\s*\[[a-z0-9_.\-]+\]\s*/i', ' ', html_entity_decode( (string) ( $item['name'] ?? '' ), ENT_QUOTES ) ) );
		$title = '' !== $kind ? $kind : ( preg_match( '/[<>=]/', $range ) || '' === $desc ? $range : $desc );
		if ( preg_match( '/^[0-9.]+$/', $title ) ) {
			$title = '' !== $desc ? $desc : '';
		}
		sort( $dates );

		$impact   = (array) ( $item['impact'] ?? array() );
		$score    = $impact['cvss3']['score'] ?? ( $impact['cvss']['score'] ?? null );
		$severity = strtolower( (string) ( $impact['cvss3']['severity'] ?? ( $impact['cvss']['severity'] ?? '' ) ) );
		$severity = array(
			'c' => 'critical',
			'h' => 'high',
			'm' => 'medium',
			'l' => 'low',
		)[ $severity ] ?? $severity;
		if ( ! isset( self::SEVERITY_RANK[ $severity ] ) ) {
			$severity = null !== $score ? self::severity_from_score( (float) $score ) : 'unknown';
		}

		$op = (array) ( $item['operator'] ?? array() );
		return array(
			// Upstream uuids repeat across records, so the id also covers the CVEs and title.
			'id'        => md5( (string) ( $item['uuid'] ?? '' ) . '|' . implode( ',', $cves ) . '|' . $title . '|' . $range ),
			'name'      => mb_substr( '' !== $title ? $title : __( 'Security issue', 'site-audit-report' ), 0, 160 ),
			'range'     => preg_match( '/[<>=]/', $range ) ? mb_substr( $range, 0, 120 ) : '',
			'min'       => $op['min_version'] ?? null,
			'min_op'    => $op['min_operator'] ?? null,
			'max'       => $op['max_version'] ?? null,
			'max_op'    => $op['max_operator'] ?? null,
			'unfixed'   => ! empty( $op['unfixed'] ) && '0' !== (string) $op['unfixed'],
			'cves'      => array_values( array_unique( $cves ) ),
			'published' => $dates[0] ?? '',
			'link'      => $link,
			'score'     => null !== $score ? (float) $score : null,
			'severity'  => $severity,
		);
	}

	/**
	 * CVSS score to a severity word.
	 *
	 * @param float $score Score.
	 * @return string
	 */
	private static function severity_from_score( $score ) {
		if ( $score >= 9 ) {
			return 'critical';
		}
		if ( $score >= 7 ) {
			return 'high';
		}
		return $score >= 4 ? 'medium' : 'low';
	}

	/**
	 * Whether a version falls inside a record's affected range.
	 *
	 * @param string              $version Version seen.
	 * @param array<string,mixed> $vuln    Slim record.
	 * @return bool
	 */
	public static function affects( $version, array $vuln ) {
		$ops = array(
			'lt' => '<',
			'le' => '<=',
			'gt' => '>',
			'ge' => '>=',
			'eq' => '==',
		);
		if ( ! empty( $vuln['min'] ) && ! version_compare( $version, (string) $vuln['min'], $ops[ $vuln['min_op'] ] ?? '>=' ) ) {
			return false;
		}
		if ( ! empty( $vuln['max'] ) ) {
			return version_compare( $version, (string) $vuln['max'], $ops[ $vuln['max_op'] ] ?? '<=' );
		}
		return ! empty( $vuln['unfixed'] );
	}

	/**
	 * Vulnerabilities that match a component at a version.
	 *
	 * @param string $type    core|plugin|theme.
	 * @param string $slug    Slug (ignored for core).
	 * @param string $version Version seen, or '' when hidden.
	 * @return array{checked:bool,matches:array<int,array<string,mixed>>,known:int}
	 */
	public static function lookup( $type, $slug, $version ) {
		$list = self::fetch( $type, 'core' === $type ? $version : $slug );
		if ( null === $list ) {
			return array(
				'checked' => false,
				'matches' => array(),
				'known'   => 0,
			);
		}

		$matches = array();
		if ( '' !== $version ) {
			$kev = self::kev();
			foreach ( $list as $vuln ) {
				// The core endpoint already returns only what affects that version.
				if ( empty( $vuln['id'] ) ) {
					$vuln['id'] = md5( (string) $vuln['name'] . implode( ',', (array) $vuln['cves'] ) . (string) $vuln['max'] );
				}
				if ( 'core' === $type || self::affects( $version, $vuln ) ) {
					$vuln['kev']   = (bool) array_intersect( $vuln['cves'], $kev );
					$vuln['fixed'] = ( 'lt' === $vuln['max_op'] && ! empty( $vuln['max'] ) ) ? (string) $vuln['max'] : '';
					$vuln['days']  = $vuln['published'] ? max( 0, (int) floor( ( time() - strtotime( $vuln['published'] ) ) / DAY_IN_SECONDS ) ) : null;
					$matches[]     = $vuln;
				}
			}
			usort(
				$matches,
				static function ( $a, $b ) {
					return array( (int) $b['kev'], self::SEVERITY_RANK[ $b['severity'] ] ?? 0, $b['score'] ?? 0 )
						<=> array( (int) $a['kev'], self::SEVERITY_RANK[ $a['severity'] ] ?? 0, $a['score'] ?? 0 );
				}
			);
		}

		return array(
			'checked' => true,
			'matches' => $matches,
			'known'   => count( $list ),
		);
	}

	/**
	 * The newest release on WordPress.org, or '' when unknown (premium items, lookup failures).
	 *
	 * @param string $type core|plugin|theme.
	 * @param string $slug Slug (ignored for core).
	 * @return string
	 */
	public static function latest( $type, $slug = '' ) {
		$slug = sanitize_key( (string) $slug );
		$key  = 'sar_latest_' . md5( $type . ':' . $slug );
		$hit  = get_transient( $key );
		if ( false !== $hit ) {
			return (string) $hit;
		}
		if ( 'core' === $type ) {
			$url = 'https://api.wordpress.org/core/version-check/1.7/';
		} elseif ( 'theme' === $type ) {
			$url = 'https://api.wordpress.org/themes/info/1.2/?action=theme_information&request[slug]=' . rawurlencode( $slug );
		} else {
			$url = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=' . rawurlencode( $slug ) . '&request[fields][sections]=0';
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 6,
				'user-agent' => Http_Client::user_agent(),
			)
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$json    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$version = 'core' === $type ? (string) ( $json['offers'][0]['version'] ?? '' ) : (string) ( $json['version'] ?? '' );
		$version = preg_match( '/^\d+(\.\d+)*$/', $version ) ? $version : '';
		set_transient( $key, $version, self::CACHE_TT );
		return $version;
	}

	/**
	 * CVE IDs on the CISA Known Exploited Vulnerabilities list, refreshed daily.
	 *
	 * @return string[]
	 */
	public static function kev() {
		static $memo = null;
		if ( null !== $memo ) {
			return $memo;
		}
		$stored = get_option( self::KEV_OPT, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$fresh  = (int) ( $stored['at'] ?? 0 ) > time() - DAY_IN_SECONDS;

		if ( ! $fresh && ! get_transient( 'sar_kev_retry' ) ) {
			set_transient( 'sar_kev_retry', 1, HOUR_IN_SECONDS );
			$response = wp_safe_remote_get(
				self::KEV_URL,
				array(
					'timeout'    => 20,
					'user-agent' => Http_Client::user_agent(),
				)
			);
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
				if ( is_array( $json['vulnerabilities'] ?? null ) ) {
					$ids = array();
					foreach ( $json['vulnerabilities'] as $row ) {
						if ( ! empty( $row['cveID'] ) ) {
							$ids[] = (string) $row['cveID'];
						}
					}
					$stored = array(
						'at'   => time(),
						'cves' => $ids,
					);
					update_option( self::KEV_OPT, $stored, false );
				}
			}
		}
		$memo = array_values( (array) ( $stored['cves'] ?? array() ) );
		return $memo;
	}
}
