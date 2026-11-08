<?php
/**
 * The Service Bay: a WordPress vulnerability check that turns its findings
 * into a priced fix plan and a booking request.
 *
 * Flow: enter an address → live inspection → risk report → fix tray → book.
 * Starting a check needs only the address; contact details are asked for
 * only when the visitor books.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Service Bay.
 */
final class Service_Bay {

	const NONCE_ACTION = 'sar_service';
	const HONEYPOT     = 'sar_company_site';
	const PARAM        = 'bay';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( 'site_audit_service', array( self::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'register_assets' ) );
		foreach ( array( 'start', 'status', 'book' ) as $action ) {
			add_action( 'wp_ajax_sar_service_' . $action, array( self::class, 'ajax_' . $action ) );
			add_action( 'wp_ajax_nopriv_sar_service_' . $action, array( self::class, 'ajax_' . $action ) );
		}
	}

	/**
	 * Registers front-end files.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style( 'site-audit-service', SAR_URL . 'assets/css/service.css', array(), SAR_VERSION );
		wp_register_script( 'site-audit-service', SAR_URL . 'assets/js/service.js', array(), SAR_VERSION, true );
	}

	/**
	 * The services that can go in the fix tray.
	 *
	 * @return array<string,array{name:string,desc:string,price:int}>
	 */
	public static function tools() {
		$s     = Settings::get();
		$tools = array(
			'patch'   => array(
				'name' => __( 'Vulnerability patching', 'site-audit-report' ),
				'desc' => __( 'Every hole on your report closed, critical ones first, tested on staging before live.', 'site-audit-report' ),
			),
			'updates' => array(
				'name' => __( 'Managed updates', 'site-audit-report' ),
				'desc' => __( 'Core, plugins and theme kept current every week, with a rollback point before each run.', 'site-audit-report' ),
			),
			'harden'  => array(
				'name' => __( 'Hardening pack', 'site-audit-report' ),
				'desc' => __( 'XML-RPC, user listing, version leaks, security headers and login rate limits sorted.', 'site-audit-report' ),
			),
			'ssl'     => array(
				'name' => __( 'HTTPS and certificate care', 'site-audit-report' ),
				'desc' => __( 'Certificate renewals watched, HTTP sent to HTTPS, mixed content cleaned up.', 'site-audit-report' ),
			),
			'monitor' => array(
				'name' => __( 'Malware and uptime watch', 'site-audit-report' ),
				'desc' => __( 'Daily malware scan and one-minute uptime checks, with a person on call when it trips.', 'site-audit-report' ),
			),
			'backup'  => array(
				'name' => __( 'Daily off-site backups', 'site-audit-report' ),
				'desc' => __( '30 days of backups stored away from your host, and a restore drill every quarter.', 'site-audit-report' ),
			),
		);
		foreach ( $tools as $id => &$tool ) {
			$tool['price'] = (int) ( $s[ 'price_' . $id ] ?? 0 );
		}
		unset( $tool );

		/**
		 * Filters the Service Bay tray. Each item: name, desc, price.
		 *
		 * @param array $tools Tools keyed by ID.
		 */
		return (array) apply_filters( 'sar_service_tools', $tools );
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
				'examples' => 'wordpress.org',
				'offset'   => '',
			),
			(array) $atts,
			'site_audit_service'
		);

		wp_enqueue_style( 'site-audit-service' );
		wp_enqueue_script( 'site-audit-service' );

		$tools = array();
		foreach ( self::tools() as $id => $tool ) {
			$tools[] = array_merge( array( 'id' => $id ), $tool );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; validated as 64 hex characters.
		$token = isset( $_GET[ self::PARAM ] ) ? strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) wp_unslash( $_GET[ self::PARAM ] ) ) ) : '';

		wp_localize_script(
			'site-audit-service',
			'sarBay',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
				'token'    => 64 === strlen( $token ) ? $token : '',
				'param'    => self::PARAM,
				'home'     => wp_parse_url( home_url(), PHP_URL_HOST ),
				'tools'    => $tools,
				'currency' => (string) Settings::get( 'service_currency' ),
				'period'   => (string) Settings::get( 'service_period' ),
				'bookUrl'  => (string) Settings::get( 'service_book_url' ),
				'i18n'     => array(
					'error'      => __( 'Something went wrong. Please try again.', 'site-audit-report' ),
					'slow'       => __( 'This is taking longer than usual. Keep this page open, or copy the link and come back.', 'site-audit-report' ),
					'copied'     => __( 'Link copied', 'site-audit-report' ),
					'notWp'      => __( 'Not WordPress', 'site-audit-report' ),
					'hidden'     => __( 'hidden', 'site-audit-report' ),
					'none'       => __( 'none seen', 'site-audit-report' ),
					'waiting'    => __( 'waiting', 'site-audit-report' ),
					'perPeriod'  => __( 'per', 'site-audit-report' ),
					'tool'       => __( 'service', 'site-audit-report' ),
					'tools'      => __( 'services', 'site-audit-report' ),
					'recommend'  => __( 'Recommended', 'site-audit-report' ),
					'add'        => __( 'Add', 'site-audit-report' ),
					'remove'     => __( 'Remove', 'site-audit-report' ),
					'inTray'     => __( 'In plan', 'site-audit-report' ),
					'fixedIn'    => __( 'Fixed in', 'site-audit-report' ),
					'noFix'      => __( 'No fix released yet', 'site-audit-report' ),
					'daysPublic' => __( 'days public', 'site-audit-report' ),
					'exploited'  => __( 'Exploited in the wild', 'site-audit-report' ),
					'update'     => __( 'Update available', 'site-audit-report' ),
					'upToDate'   => __( 'No known issues', 'site-audit-report' ),
					'versionSrc' => __( 'version from', 'site-audit-report' ),
					'more'       => __( 'more', 'site-audit-report' ),
				),
			)
		);

		$uid      = wp_unique_id( 'sarbay-' );
		$examples = array_filter( array_map( 'trim', explode( ',', (string) $atts['examples'] ) ) );
		$privacy  = get_privacy_policy_url();
		// Space a fixed site header needs, so the sticky step rail and anchors clear it.
		$offset = '' !== (string) $atts['offset'] ? absint( $atts['offset'] ) : null;
		$style  = null !== $offset ? '--bay-rail-top:' . ( $offset + 26 ) . 'px' : '';

		ob_start();
		include SAR_DIR . 'includes/views/service-bay.php';
		return (string) ob_get_clean();
	}

	/**
	 * Starts an inspection. Only the address is needed.
	 *
	 * @return void
	 */
	public static function ajax_start() {
		nocache_headers();
		$result = self::start();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				),
				(int) ( $result->get_error_data()['status'] ?? 400 )
			);
		}
		wp_send_json_success( array( 'token' => $result['token'] ) );
	}

	/**
	 * Validates and queues a Service Bay inspection.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private static function start() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below.
		$nonce = isset( $_POST['nonce'] ) ? (string) wp_unslash( $_POST['nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return self::error( 'sar_bad_nonce', __( 'This page has expired. Please try again.', 'site-audit-report' ), 403 );
		}
		if ( Plugin::missing_requirements() ) {
			return self::error( 'sar_unavailable', __( 'Checks are unavailable right now. Please try again later.', 'site-audit-report' ), 503 );
		}

		$ip_hash = Rate_Limiter::hash( Rate_Limiter::client_ip() );
		if ( ! Rate_Limiter::hit( 'attempt:' . $ip_hash, Public_Form::ATTEMPT_LIMIT, HOUR_IN_SECONDS ) ) {
			return self::error( 'sar_rate_ip', __( 'You have run several checks recently. Please try again in an hour.', 'site-audit-report' ), 429 );
		}
		if ( ! empty( $_POST[ self::HONEYPOT ] ) ) {
			return self::error( 'sar_rejected', __( 'Your request could not be processed.', 'site-audit-report' ), 400 );
		}

		$raw = isset( $_POST['url'] ) ? (string) wp_unslash( $_POST['url'] ) : '';
		// phpcs:enable

		$url = Url_Guard::normalize_input( $raw );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$checked = Url_Guard::validate( $url );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$domain = Url_Guard::domain_key( $checked['url'] );

		// A check of the same site in the last 30 minutes is reused, which keeps
		// repeat visits fast and saves the target site from repeat traffic.
		$recent = self::recent( $domain );
		if ( $recent ) {
			return $recent;
		}

		if ( ! Rate_Limiter::hit( 'ip:' . $ip_hash, (int) Settings::get( 'limit_ip_hour' ) * 2, HOUR_IN_SECONDS ) ) {
			return self::error( 'sar_rate_ip', __( 'You have run several checks recently. Please try again in an hour.', 'site-audit-report' ), 429 );
		}
		if ( ! Rate_Limiter::hit( 'domain:' . $domain, (int) Settings::get( 'limit_domain_day' ) * 2, DAY_IN_SECONDS ) ) {
			return self::error( 'sar_rate_domain', __( 'This website has been checked several times today. Please try again tomorrow.', 'site-audit-report' ), 429 );
		}
		if ( ! Rate_Limiter::hit( 'global', (int) Settings::get( 'limit_global_day' ), DAY_IN_SECONDS ) ) {
			return self::error( 'sar_rate_global', __( 'We have reached our limit of checks for today. Please try again tomorrow.', 'site-audit-report' ), 429 );
		}

		$audit = Audits::create(
			array(
				'site_url' => $checked['url'],
				'domain'   => $domain,
				'source'   => 'service',
				'ip_hash'  => $ip_hash,
			)
		);
		if ( ! $audit ) {
			return self::error( 'sar_unavailable', __( 'Checks are unavailable right now. Please try again later.', 'site-audit-report' ), 500 );
		}
		Queue::dispatch();
		return $audit;
	}

	/**
	 * A recent Service Bay check of a domain that can be shown again.
	 *
	 * @param string $domain Domain key.
	 * @return array<string,mixed>|null
	 */
	private static function recent( $domain ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
				"SELECT * FROM {$wpdb->prefix}sar_audits WHERE domain = %s AND source = 'service' AND status IN ('queued','running','complete') AND created_at > %s ORDER BY id DESC LIMIT 1",
				$domain,
				gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS )
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Live status and, once ready, the full report data.
	 *
	 * @return void
	 */
	public static function ajax_status() {
		nocache_headers();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The unguessable token is the authorisation; by_token() validates it.
		$token = isset( $_REQUEST['token'] ) ? (string) wp_unslash( $_REQUEST['token'] ) : '';
		$audit = Audits::by_token( $token );
		if ( ! $audit || 'service' !== $audit['source'] ) {
			wp_send_json_error( array( 'message' => __( 'This report was not found. It may have expired.', 'site-audit-report' ) ), 404 );
		}
		if ( in_array( $audit['status'], array( 'queued', 'running' ), true ) ) {
			Queue::nudge();
		}
		wp_send_json_success( self::payload( $audit ) );
	}

	/**
	 * Shapes an audit for the Service Bay script.
	 *
	 * @param array<string,mixed> $audit Audit.
	 * @return array<string,mixed>
	 */
	public static function payload( array $audit ) {
		$result = json_decode( (string) $audit['result'], true );
		$result = is_array( $result ) ? $result : array();
		$checks = array();
		foreach ( (array) ( $result['checks'] ?? array() ) as $check ) {
			$checks[ $check['id'] ] = $check;
		}

		$info = (array) ( $checks['wp_detected']['data'] ?? array() );

		// Components, worst first.
		$rows = array();
		if ( ! empty( $info['core']['row'] ) ) {
			$rows[] = (array) $info['core']['row'];
		}
		foreach ( array_merge( (array) ( $info['themes'] ?? array() ), (array) ( $info['components'] ?? array() ) ) as $row ) {
			$rows[] = $row;
		}

		$counts = array(
			'critical' => 0,
			'high'     => 0,
			'medium'   => 0,
			'low'      => 0,
			'unknown'  => 0,
		);
		$kev     = 0;
		$oldest  = 0;
		$vulnerable = 0;
		$outdated   = 0;
		$hidden     = 0;
		foreach ( $rows as &$row ) {
			$row['worst'] = '';
			$row['kev']   = false;
			$row['fixed'] = '';
			foreach ( (array) $row['matches'] as $match ) {
				$sev = isset( $counts[ $match['severity'] ] ) ? $match['severity'] : 'unknown';
				++$counts[ $sev ];
				if ( ! empty( $match['kev'] ) ) {
					++$kev;
					$row['kev'] = true;
				}
				$oldest = max( $oldest, (int) ( $match['days'] ?? 0 ) );
				if ( '' === $row['worst'] || ( Vuln_Db::SEVERITY_RANK[ $sev ] ?? 0 ) > ( Vuln_Db::SEVERITY_RANK[ $row['worst'] ] ?? 0 ) ) {
					$row['worst'] = $sev;
				}
				if ( ! empty( $match['fixed'] ) && ( '' === $row['fixed'] || version_compare( $match['fixed'], $row['fixed'], '>' ) ) ) {
					$row['fixed'] = $match['fixed'];
				}
			}
			$vulnerable += $row['count'] ? 1 : 0;
			$outdated   += ! $row['count'] && ! empty( $row['outdated'] ) ? 1 : 0;
			$hidden     += '' === (string) $row['version'] ? 1 : 0;
		}
		unset( $row );
		usort(
			$rows,
			static function ( $a, $b ) {
				return array( (int) $b['kev'], Vuln_Db::SEVERITY_RANK[ $b['worst'] ] ?? -1, (int) $b['count'], (int) ! empty( $b['outdated'] ) )
					<=> array( (int) $a['kev'], Vuln_Db::SEVERITY_RANK[ $a['worst'] ] ?? -1, (int) $a['count'], (int) ! empty( $a['outdated'] ) );
			}
		);

		// The check board: WordPress checks plus the HTTPS basics.
		$board = array();
		$ids   = array_merge( array_keys( Modules\Wordpress::catalog() ), array( 'https', 'http_redirect', 'cert_expiry', 'hsts' ) );
		foreach ( $ids as $id ) {
			$check   = $checks[ $id ] ?? null;
			$board[] = array(
				'id'       => $id,
				'label'    => $check ? (string) $check['label'] : ( Modules\Wordpress::catalog()[ $id ][0] ?? self::board_label( $id ) ),
				'status'   => $check ? (string) $check['status'] : 'pending',
				'evidence' => $check ? (string) $check['evidence'] : '',
				'fix'      => $check ? (string) $check['fix'] : '',
				'items'    => $check ? array_slice( (array) $check['items'], 0, 6 ) : array(),
			);
		}

		$issues = array();
		foreach ( $board as $item ) {
			if ( in_array( $item['status'], array( 'warn', 'fail' ), true ) && ! in_array( $item['id'], array( 'wp_core', 'wp_plugins', 'wp_theme', 'wp_kev' ), true ) ) {
				$issues[] = $item;
			}
		}

		$security_bad = false;
		foreach ( (array) ( $result['checks'] ?? array() ) as $check ) {
			if ( 'security' === $check['category'] && in_array( $check['status'], array( 'warn', 'fail' ), true ) && in_array( $check['id'], array( 'https', 'http_redirect', 'cert_expiry', 'mixed_content' ), true ) ) {
				$security_bad = true;
			}
		}

		$is_wp    = $info['is_wp'] ?? null;
		$complete = 'complete' === $audit['status'];

		// Verdict.
		if ( ! $complete ) {
			$grade = 'pending';
		} elseif ( empty( $result['readable'] ) ) {
			$grade = 'unknown';
		} elseif ( $kev || $counts['critical'] ) {
			$grade = 'critical';
		} elseif ( $counts['high'] ) {
			$grade = 'high';
		} elseif ( $counts['medium'] || $counts['low'] || $counts['unknown'] ) {
			$grade = 'medium';
		} elseif ( $outdated || $issues || $security_bad ) {
			$grade = 'low';
		} else {
			$grade = 'clean';
		}

		return array(
			'status'     => (string) $audit['status'],
			'stage'      => (string) $audit['stage'],
			'progress'   => (int) $audit['progress'],
			'error'      => (string) $audit['error'],
			'token'      => (string) $audit['token'],
			'site'       => array(
				'url'    => (string) ( $result['final_url'] ?? $audit['site_url'] ),
				'domain' => (string) $audit['domain'],
				'at'     => $audit['completed_at'] ? mysql2date( 'j M Y, H:i', get_date_from_gmt( $audit['completed_at'] ) ) : '',
			),
			'readable'   => ! empty( $result['readable'] ),
			'unreadable' => (string) ( $result['unreadable_reason'] ?? '' ),
			'inspection' => array(
				'isWp'    => $is_wp,
				'server'  => (string) ( $info['server'] ?? '' ),
				'cdn'     => (string) ( $info['cdn'] ?? '' ),
				'core'    => (string) ( $info['core']['version'] ?? '' ),
				'theme'   => ! empty( $info['themes'][0] ) ? trim( $info['themes'][0]['name'] . ' ' . $info['themes'][0]['version'] ) : '',
				'plugins' => isset( $info['components'] ) ? count( (array) $info['components'] ) : null,
				'https'   => isset( $checks['https'] ) ? (string) $checks['https']['status'] : '',
			),
			'board'      => $board,
			'verdict'    => array(
				'grade'      => $grade,
				'counts'     => $counts,
				'kev'        => $kev,
				'oldest'     => $oldest,
				'vulnerable' => $vulnerable,
				'outdated'   => $outdated,
				'hidden'     => $hidden,
				'issues'     => count( $issues ),
			),
			'components' => $rows,
			'issues'     => $issues,
			'recommend'  => $complete ? self::recommend( $grade, $vulnerable, $outdated, $issues, $security_bad, $kev, $counts, $is_wp ) : array(),
		);
	}

	/**
	 * Which tray items the report asks for, with the reason.
	 *
	 * @param string                         $grade        Verdict.
	 * @param int                            $vulnerable   Components with matches.
	 * @param int                            $outdated     Components behind latest.
	 * @param array<int,array<string,mixed>> $issues       Hardening issues.
	 * @param bool                           $security_bad HTTPS problems.
	 * @param int                            $kev          Exploited matches.
	 * @param array<string,int>              $counts       Severity counts.
	 * @param bool|null                      $is_wp        WordPress or not.
	 * @return array<string,string> Tool ID => reason.
	 */
	private static function recommend( $grade, $vulnerable, $outdated, array $issues, $security_bad, $kev, array $counts, $is_wp ) {
		$out = array();
		if ( $vulnerable ) {
			/* translators: %d: number of items. */
			$out['patch'] = sprintf( _n( '%d item has a known hole', '%d items have known holes', $vulnerable, 'site-audit-report' ), $vulnerable );
		}
		if ( true === $is_wp ) {
			$out['updates'] = $outdated || $vulnerable
				/* translators: %d: number of items. */
				? sprintf( _n( '%d item is behind', '%d items are behind', $outdated + $vulnerable, 'site-audit-report' ), $outdated + $vulnerable )
				: __( 'Keeps it this way', 'site-audit-report' );
		}
		if ( $issues ) {
			/* translators: %d: number of issues. */
			$out['harden'] = sprintf( _n( '%d hardening gap', '%d hardening gaps', count( $issues ), 'site-audit-report' ), count( $issues ) );
		}
		if ( $security_bad ) {
			$out['ssl'] = __( 'HTTPS needs attention', 'site-audit-report' );
		}
		if ( $kev || $counts['critical'] ) {
			$out['monitor'] = $kev ? __( 'Attackers use these holes now', 'site-audit-report' ) : __( 'Critical issue on the sheet', 'site-audit-report' );
			$out['backup']  = __( 'A clean restore point first', 'site-audit-report' );
		} elseif ( $counts['high'] ) {
			$out['backup'] = __( 'A clean restore point first', 'site-audit-report' );
		}
		return array_intersect_key( $out, self::tools() );
	}

	/**
	 * Labels for security checks on the board.
	 *
	 * @param string $id Check ID.
	 * @return string
	 */
	private static function board_label( $id ) {
		$labels = array(
			'https'         => __( 'HTTPS', 'site-audit-report' ),
			'http_redirect' => __( 'HTTP sends visitors to HTTPS', 'site-audit-report' ),
			'cert_expiry'   => __( 'Certificate expiry', 'site-audit-report' ),
			'hsts'          => __( 'HSTS header', 'site-audit-report' ),
		);
		return $labels[ $id ] ?? $id;
	}

	/**
	 * Books the service. The site team gets an email; the visitor gets a confirmation on screen.
	 *
	 * @return void
	 */
	public static function ajax_book() {
		nocache_headers();
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitised below.
		$nonce = isset( $_POST['nonce'] ) ? (string) wp_unslash( $_POST['nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'This page has expired. Please reload and try again.', 'site-audit-report' ) ), 403 );
		}
		if ( ! empty( $_POST[ self::HONEYPOT ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Your request could not be processed.', 'site-audit-report' ) ), 400 );
		}
		$ip_hash = Rate_Limiter::hash( Rate_Limiter::client_ip() );
		if ( ! Rate_Limiter::hit( 'book:' . $ip_hash, 5, HOUR_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => __( 'Several bookings came from you recently. Please email us instead.', 'site-audit-report' ) ), 429 );
		}

		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone   = isset( $_POST['phone'] ) ? preg_replace( '/[^0-9+()\-\s]/', '', sanitize_text_field( wp_unslash( $_POST['phone'] ) ) ) : '';
		$website = isset( $_POST['website'] ) ? sanitize_text_field( wp_unslash( $_POST['website'] ) ) : '';
		$notes   = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$token   = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : '';
		$picked  = isset( $_POST['tools'] ) ? array_map( 'sanitize_key', explode( ',', (string) wp_unslash( $_POST['tools'] ) ) ) : array();
		$consent = ! empty( $_POST['consent'] );
		// phpcs:enable

		$errors = array();
		if ( '' === $name || mb_strlen( $name ) > 120 ) {
			$errors['name'] = __( 'Please enter your name.', 'site-audit-report' );
		}
		if ( ! is_email( $email ) || strlen( $email ) > 191 ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'site-audit-report' );
		}
		if ( '' === $website || mb_strlen( $website ) > 2048 ) {
			$errors['website'] = __( 'Please enter your website address.', 'site-audit-report' );
		}
		if ( ! $consent ) {
			$errors['consent'] = __( 'Please tick the box so we can contact you.', 'site-audit-report' );
		}
		if ( $errors ) {
			wp_send_json_error(
				array(
					'message' => reset( $errors ),
					'fields'  => $errors,
				),
				400
			);
		}

		$catalog = self::tools();
		$chosen  = array();
		$total   = 0;
		foreach ( array_unique( $picked ) as $id ) {
			if ( isset( $catalog[ $id ] ) ) {
				$chosen[ $id ] = $catalog[ $id ];
				$total        += (int) $catalog[ $id ]['price'];
			}
		}
		$currency = (string) Settings::get( 'service_currency' );
		$period   = (string) Settings::get( 'service_period' );
		$lines    = array();
		foreach ( $chosen as $tool ) {
			$lines[] = '- ' . $tool['name'] . ': ' . $currency . number_format_i18n( $tool['price'] );
		}
		/* translators: 1: currency, 2: amount, 3: period. */
		$total_line = sprintf( __( 'Total: %1$s%2$s per %3$s', 'site-audit-report' ), $currency, number_format_i18n( $total ), $period );

		$audit  = '' !== $token ? Audits::by_token( $token ) : null;
		$report = '';
		if ( $audit && 'service' === $audit['source'] ) {
			$report = add_query_arg( self::PARAM, $audit['token'], wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
			$note   = sprintf(
				"%s\n%s: %s\n%s\n%s\n%s",
				/* translators: %s: date. */
				sprintf( __( 'Service booked %s', 'site-audit-report' ), wp_date( 'j M Y H:i' ) ),
				__( 'Phone', 'site-audit-report' ),
				$phone,
				implode( "\n", $lines ),
				$total_line,
				$notes
			);
			Audits::update(
				(int) $audit['id'],
				array(
					'business_name' => mb_substr( $name, 0, 191 ),
					'email'         => $email,
					'lead_status'   => 'booked',
					'notes'         => trim( (string) $audit['notes'] . "\n\n" . $note ),
					'consent_text'  => self::consent_text(),
					'consent_at'    => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}

		$to = (string) Settings::get( 'notify_email' );
		$to = is_email( $to ) ? $to : (string) get_option( 'admin_email' );
		/* translators: %s: website. */
		$subject = sprintf( __( 'Service Bay booking: %s', 'site-audit-report' ), $website );
		$body    = implode(
			"\n",
			array_filter(
				array(
					__( 'Name', 'site-audit-report' ) . ': ' . $name,
					__( 'Email', 'site-audit-report' ) . ': ' . $email,
					__( 'Phone', 'site-audit-report' ) . ': ' . $phone,
					__( 'Website', 'site-audit-report' ) . ': ' . $website,
					'',
					__( 'Plan', 'site-audit-report' ) . ':',
					$lines ? implode( "\n", $lines ) : __( '(nothing selected)', 'site-audit-report' ),
					$total_line,
					'',
					$notes ? __( 'Notes', 'site-audit-report' ) . ":\n" . $notes : '',
					$report ? __( 'Report', 'site-audit-report' ) . ': ' . $report : '',
				),
				static fn( $line ) => null !== $line
			)
		);
		wp_mail( $to, $subject, $body, array( 'Reply-To: ' . $name . ' <' . $email . '>' ) );

		/**
		 * Fires after a Service Bay booking.
		 *
		 * @param array $booking name, email, phone, website, notes, tools, total, audit.
		 */
		do_action(
			'sar_service_booked',
			array(
				'name'    => $name,
				'email'   => $email,
				'phone'   => $phone,
				'website' => $website,
				'notes'   => $notes,
				'tools'   => array_keys( $chosen ),
				'total'   => $total,
				'audit'   => $audit,
			)
		);

		wp_send_json_success(
			array(
				'name'  => $name,
				'tools' => array_values( wp_list_pluck( $chosen, 'name' ) ),
				'total' => $total,
			)
		);
	}

	/**
	 * Consent line for the booking form.
	 *
	 * @return string
	 */
	public static function consent_text() {
		/* translators: %s: business name. */
		return sprintf( __( 'I am responsible for this website, and %s may store these details and contact me about this booking.', 'site-audit-report' ), Settings::brand_name() );
	}

	/**
	 * Builds an error.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
