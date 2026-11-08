<?php
/**
 * Trust badges: a "Secured by …" badge a client embeds on their site. It links
 * to a public verification page, and the plugin re-checks the site every week.
 *
 * The public page and image only ever say verified, under review or not
 * verified. The reasons stay private to the team, so a badge can never
 * advertise a client's open holes.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Trust badges.
 */
final class Badges {

	const PARAM        = 'sar_badge';
	const RECHECK_DAYS = 7;
	const STALE_DAYS   = 14;
	const TIME_BUDGET  = 100;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( self::class, 'maybe_serve' ), 20 );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sar_badges';
	}

	/**
	 * The text on the badge.
	 *
	 * @param array<string,mixed> $badge Badge.
	 * @return string
	 */
	public static function label( array $badge ) {
		if ( '' !== (string) ( $badge['label'] ?? '' ) ) {
			return (string) $badge['label'];
		}
		/* translators: %s: business name. */
		return sprintf( __( 'Secured by %s', 'site-audit-report' ), Settings::brand_name() );
	}

	/**
	 * Public verification page.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function verify_url( $token ) {
		return add_query_arg( self::PARAM, $token, home_url( '/' ) );
	}

	/**
	 * Badge image.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function image_url( $token ) {
		return add_query_arg(
			array(
				self::PARAM => $token,
				'format'    => 'svg',
			),
			home_url( '/' )
		);
	}

	/**
	 * The HTML a client pastes into their site.
	 *
	 * @param array<string,mixed> $badge Badge.
	 * @return string
	 */
	public static function embed_code( array $badge ) {
		$label = self::label( $badge );
		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener" title="%3$s"><img src="%2$s" alt="%3$s" height="32" loading="lazy" decoding="async" style="height:32px;width:auto;border:0"></a>',
			esc_url( self::verify_url( (string) $badge['token'] ) ),
			esc_url( self::image_url( (string) $badge['token'] ) ),
			esc_attr( $label . ' · ' . __( 'click to verify', 'site-audit-report' ) )
		);
	}

	/**
	 * Creates a badge.
	 *
	 * @param string $url      Site address.
	 * @param int    $audit_id Related audit, or 0.
	 * @param string $label    Custom badge text, or '' for the default.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function create( $url, $audit_id = 0, $label = '' ) {
		global $wpdb;
		$url = Url_Guard::normalize_input( $url );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$checked = Url_Guard::validate( $url );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$token = bin2hex( random_bytes( 12 ) );
		$wpdb->insert(
			self::table(),
			array(
				'token'      => $token,
				'audit_id'   => (int) $audit_id,
				'site_url'   => $checked['url'],
				'domain'     => Url_Guard::domain_key( $checked['url'] ),
				'label'      => mb_substr( sanitize_text_field( $label ), 0, 80 ),
				'history'    => '[]',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return self::by_token( $token );
	}

	/**
	 * A badge by token.
	 *
	 * @param string $token Token.
	 * @return array<string,mixed>|null
	 */
	public static function by_token( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{24}$/', (string) $token ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s', $token ), ARRAY_A );
	}

	/**
	 * A badge by ID.
	 *
	 * @param int $id ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	/**
	 * All badges, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		return (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 500', ARRAY_A );
	}

	/**
	 * Updates a badge.
	 *
	 * @param int                 $id   ID.
	 * @param array<string,mixed> $data Columns.
	 * @return void
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Deletes a badge. Its embed then shows "not verified".
	 *
	 * @param int $id ID.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * What the public sees: verified, review (amber) or none (grey).
	 *
	 * @param array<string,mixed>|null $badge Badge.
	 * @return string
	 */
	public static function public_state( $badge ) {
		if ( ! $badge || ! (int) $badge['active'] ) {
			return 'none';
		}
		if ( 'review' === $badge['state'] ) {
			return 'review';
		}
		if ( 'verified' === $badge['state'] && $badge['verified_at'] && strtotime( $badge['verified_at'] . ' UTC' ) > time() - self::STALE_DAYS * DAY_IN_SECONDS ) {
			return 'verified';
		}
		return 'none';
	}

	/* ---------------------------------------------------------------- Checks */

	/**
	 * Re-checks one site: HTTPS, certificate, and WordPress versions against
	 * high or critical and actively exploited vulnerabilities.
	 *
	 * @param array<string,mixed> $badge  Badge.
	 * @param bool                $notify Email the team when the state changes.
	 * @return array{state:string,issues:array<int,array<string,string>>}
	 */
	public static function check( array $badge, $notify = true ) {
		$issues = array();
		$state  = 'verified';
		try {
			$http  = new Http_Client( 80 );
			$crawl = Crawler::crawl( (string) $badge['site_url'], $http, 1 );
			$ctx   = new Scan_Context(
				array(
					'id'       => 0,
					'site_url' => (string) $badge['site_url'],
					'domain'   => (string) $badge['domain'],
					'source'   => 'badge',
				),
				$crawl,
				$http
			);
			if ( empty( $crawl['readable'] ) ) {
				$state    = 'unknown';
				$issues[] = array(
					'label'    => __( 'Site could not be read', 'site-audit-report' ),
					'evidence' => (string) ( $crawl['unreadable_reason'] ?? '' ),
				);
			} else {
				$checks = array_merge( ( new Modules\Security() )->run( $ctx ), ( new Modules\Wordpress() )->run( $ctx ) );
				// Only problems that matter to a visitor's trust change the badge.
				$blocking = array( 'https', 'http_redirect', 'cert_expiry', 'wp_kev', 'wp_core', 'wp_plugins', 'wp_theme', 'wp_debug_log', 'wp_listing' );
				foreach ( $checks as $check ) {
					if ( in_array( $check['id'], $blocking, true ) && Check::FAIL === $check['status'] ) {
						$issues[] = array(
							'label'    => (string) $check['label'],
							'evidence' => (string) $check['evidence'],
							'items'    => implode( '; ', array_slice( (array) $check['items'], 0, 6 ) ),
						);
					}
				}
				$state = $issues ? 'review' : 'verified';
			}
		} catch ( Throwable $error ) {
			$state  = 'unknown';
			$issues = array(
				array(
					'label'    => __( 'The check stopped with an error', 'site-audit-report' ),
					'evidence' => '',
				),
			);
		}

		$now     = gmdate( 'Y-m-d H:i:s' );
		$history = json_decode( (string) $badge['history'], true );
		$history = is_array( $history ) ? $history : array();
		array_unshift(
			$history,
			array(
				'at'    => $now,
				'state' => $state,
			)
		);
		$data = array(
			'state'      => $state,
			'issues'     => wp_json_encode( $issues ),
			'history'    => wp_json_encode( array_slice( $history, 0, 26 ) ),
			'checked_at' => $now,
		);
		if ( 'verified' === $state ) {
			$data['verified_at'] = $now;
		}
		self::update( (int) $badge['id'], $data );

		if ( $notify && $badge['state'] !== $state && 'pending' !== $badge['state'] ) {
			self::notify_change( $badge, $state, $issues );
		}

		return array(
			'state'  => $state,
			'issues' => $issues,
		);
	}

	/**
	 * Re-checks badges that are due. Runs from the daily maintenance task.
	 *
	 * @return int Badges checked.
	 */
	public static function run_due() {
		global $wpdb;
		$started = time();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		$due = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE active = 1 AND ( checked_at IS NULL OR checked_at < %s ) ORDER BY checked_at ASC LIMIT 20', gmdate( 'Y-m-d H:i:s', time() - self::RECHECK_DAYS * DAY_IN_SECONDS + HOUR_IN_SECONDS ) ), ARRAY_A );
		$n   = 0;
		foreach ( (array) $due as $badge ) {
			if ( time() - $started > self::TIME_BUDGET ) {
				break;
			}
			self::check( $badge, true );
			++$n;
		}
		return $n;
	}

	/**
	 * Tells the team a badge changed state.
	 *
	 * @param array<string,mixed>            $badge  Badge.
	 * @param string                         $state  New state.
	 * @param array<int,array<string,string>> $issues Reasons.
	 * @return void
	 */
	private static function notify_change( array $badge, $state, array $issues ) {
		$to = (string) Settings::get( 'notify_email' );
		$to = is_email( $to ) ? $to : (string) get_option( 'admin_email' );
		$words = array(
			'verified' => __( 'is verified again', 'site-audit-report' ),
			'review'   => __( 'turned amber (under review)', 'site-audit-report' ),
			'unknown'  => __( 'could not be checked', 'site-audit-report' ),
		);
		/* translators: 1: domain, 2: state words. */
		$subject = sprintf( __( 'Trust badge for %1$s %2$s', 'site-audit-report' ), $badge['domain'], $words[ $state ] ?? $state );
		$lines   = array();
		foreach ( $issues as $issue ) {
			$lines[] = '- ' . $issue['label'] . ': ' . $issue['evidence'] . ( ! empty( $issue['items'] ) ? ' (' . $issue['items'] . ')' : '' );
		}
		$body = $subject . ".\n\n"
			. ( $lines ? __( 'Reasons (private, never shown on the public page):', 'site-audit-report' ) . "\n" . implode( "\n", $lines ) . "\n\n" : '' )
			. __( 'Manage badges:', 'site-audit-report' ) . ' ' . admin_url( 'admin.php?page=site-audit-report-badges' ) . "\n"
			. __( 'Public page:', 'site-audit-report' ) . ' ' . self::verify_url( (string) $badge['token'] );
		wp_mail( $to, $subject, $body );
	}

	/* ---------------------------------------------------------- Public output */

	/**
	 * Serves the verification page or the badge image.
	 *
	 * @return void
	 */
	public static function maybe_serve() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public, read-only.
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}
		$token  = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) wp_unslash( $_GET[ self::PARAM ] ) ) );
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : '';
		// phpcs:enable
		$badge = self::by_token( $token );

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		header( 'X-Content-Type-Options: nosniff' );

		if ( 'svg' === $format ) {
			$state = self::public_state( $badge );
			// A badge copied onto another site shows "not verified" there.
			$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), PHP_URL_HOST ) : '';
			if ( $badge && '' !== $ref && ! self::host_allowed( $ref, $badge ) ) {
				$state = 'none';
			}
			header( 'Content-Type: image/svg+xml; charset=utf-8' );
			header( 'Cache-Control: public, max-age=3600' );
			header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'" );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
			echo self::svg( $badge, $state );
			exit;
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, follow' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src https: data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'" );
		header( 'Content-Type: text/html; charset=utf-8' );
		if ( ! $badge ) {
			status_header( 404 );
		} else {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET views = views + 1 WHERE id = %d', (int) $badge['id'] ) );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		echo self::page( $badge );
		exit;
	}

	/**
	 * Whether a page on this host may show the badge.
	 *
	 * @param string              $host  Referring host.
	 * @param array<string,mixed> $badge Badge.
	 * @return bool
	 */
	private static function host_allowed( $host, array $badge ) {
		$strip = static fn( $h ) => preg_replace( '/^www\./', '', strtolower( (string) $h ) );
		$host  = $strip( $host );
		$site  = $strip( wp_parse_url( (string) $badge['site_url'], PHP_URL_HOST ) );
		$own   = $strip( wp_parse_url( home_url(), PHP_URL_HOST ) );
		return $host === $site || str_ends_with( $host, '.' . $site ) || $host === $own;
	}

	/**
	 * The badge image.
	 *
	 * @param array<string,mixed>|null $badge Badge.
	 * @param string                   $state verified|review|none.
	 * @return string
	 */
	public static function svg( $badge, $state ) {
		$left  = $badge ? self::label( $badge ) : __( 'Security badge', 'site-audit-report' );
		$right = array(
			/* translators: %s: date. */
			'verified' => sprintf( __( 'Verified %s', 'site-audit-report' ), $badge && $badge['verified_at'] ? wp_date( 'j M Y', strtotime( $badge['verified_at'] . ' UTC' ) ) : '' ),
			'review'   => __( 'Under review', 'site-audit-report' ),
			'none'     => __( 'Not verified', 'site-audit-report' ),
		)[ $state ];
		$colors = array(
			'verified' => array( '#10B981', '#04170F' ),
			'review'   => array( '#F59E0B', '#1F1300' ),
			'none'     => array( '#9CA3AF', '#111827' ),
		)[ $state ];

		// Approximate text widths for an 12px sans-serif.
		$lw = (int) ceil( mb_strlen( $left ) * 6.9 ) + 46;
		$rw = (int) ceil( mb_strlen( $right ) * 6.6 ) + 24;
		$w  = $lw + $rw;
		$icon = 'verified' === $state
			? '<path d="M14 6l6 2.4v4.6c0 3.9-2.6 6.9-6 8-3.4-1.1-6-4.1-6-8V8.4z" fill="#6EE7B7"/><path d="M11.2 14.2l2 2 3.8-4.2" stroke="#04170F" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
			: '<path d="M14 6l6 2.4v4.6c0 3.9-2.6 6.9-6 8-3.4-1.1-6-4.1-6-8V8.4z" fill="none" stroke="#9CA3AF" stroke-width="1.6"/>';

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="32" viewBox="0 0 %1$d 32" role="img" aria-label="%2$s">'
			. '<title>%2$s</title>'
			. '<rect width="%1$d" height="32" rx="8" fill="#072418"/>'
			. '<path d="M%3$d 0h%4$d a8 8 0 0 1 8 8v16a8 8 0 0 1-8 8h-%4$dz" fill="%5$s"/>'
			. '%6$s'
			. '<g font-family="Plus Jakarta Sans,Segoe UI,Helvetica,Arial,sans-serif" font-size="12" font-weight="700">'
			. '<text x="28" y="20.5" fill="#FFFFFF">%7$s</text>'
			. '<text x="%8$d" y="20.5" fill="%9$s">%10$s</text>'
			. '</g></svg>',
			$w,
			esc_attr( $left . ': ' . $right ),
			$lw,
			$rw - 8,
			$colors[0],
			$icon,
			esc_html( $left ),
			$lw + 12,
			$colors[1],
			esc_html( $right )
		);
	}

	/**
	 * The public verification page. It never lists the reasons for "under review".
	 *
	 * @param array<string,mixed>|null $badge Badge.
	 * @return string
	 */
	public static function page( $badge ) {
		$state = self::public_state( $badge );
		$brand = Settings::brand_name();
		$logo  = (string) Settings::get( 'logo_url' );
		$cta   = (string) Settings::get( 'cta_url' );
		$cta   = '' !== $cta ? $cta : home_url( '/' );

		$copy = array(
			'verified' => array( __( 'Verified', 'site-audit-report' ), __( 'This website passed its latest independent security check.', 'site-audit-report' ) ),
			'review'   => array( __( 'Under review', 'site-audit-report' ), __( 'The latest check found something our team is looking at. This is routine; details are shared only with the site owner.', 'site-audit-report' ) ),
			'none'     => array( __( 'Not verified', 'site-audit-report' ), __( 'There is no current verification for this badge. It may have expired, been removed, or be shown on a different website.', 'site-audit-report' ) ),
		)[ $state ];

		$history = $badge ? (array) json_decode( (string) $badge['history'], true ) : array();
		$domain  = $badge ? (string) $badge['domain'] : '';
		$title   = $badge ? $domain . ' · ' . $copy[0] : $copy[0];

		ob_start();
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, follow">
<title><?php echo esc_html( $title . ' — ' . $brand ); ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@1&family=JetBrains+Mono:wght@500;700&family=Plus+Jakarta+Sans:wght@400;600;800&display=swap">
<style>
:root{--canvas:#F7F8F5;--paper:#fff;--line:#D5E0DA;--ink:#091E15;--slate:#475E52;--pine:#072418;--glow:#6EE7B7;--deep:#0E9F6E;--v:#10B981}
.review{--v:#F59E0B}.none{--v:#9CA3AF}
*{box-sizing:border-box}html,body{margin:0}body{background:var(--canvas);color:var(--ink);font:16px/1.6 "Plus Jakarta Sans",system-ui,sans-serif;-webkit-font-smoothing:antialiased;background-image:linear-gradient(to right,rgba(9,30,21,.04) 1px,transparent 1px),linear-gradient(to bottom,rgba(9,30,21,.04) 1px,transparent 1px);background-size:64px 64px;min-height:100vh}
.wrap{max-width:720px;margin:0 auto;padding:48px 20px 64px}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:32px;font-weight:800;color:var(--ink);text-decoration:none}.brand img{height:32px;width:auto}
.card{position:relative;overflow:hidden;padding:36px;border-radius:24px;background:var(--pine);color:#D1FAE5;box-shadow:0 30px 60px -20px rgba(4,23,15,.35)}
.card:before{content:"";position:absolute;inset:0 auto 0 0;width:6px;background:var(--v)}
.card:after{content:"";position:absolute;top:-120px;right:-120px;width:320px;height:320px;border-radius:50%;background:radial-gradient(circle,color-mix(in srgb,var(--v) 35%,transparent),transparent 65%)}
.card>*{position:relative}
.kick{margin:0;font:500 11px/1.4 "JetBrains Mono",monospace;letter-spacing:.1em;text-transform:uppercase;color:var(--glow)}
.state{display:flex;align-items:center;gap:14px;margin:14px 0 6px}
.seal{width:56px;height:56px;flex:0 0 56px;border-radius:16px;display:grid;place-items:center;background:color-mix(in srgb,var(--v) 18%,transparent);color:var(--v);font-size:28px;font-weight:800}
h1{margin:0;font-size:40px;line-height:1.05;font-weight:800;letter-spacing:-.025em;color:#fff}
h1 em{font-family:"Instrument Serif",serif;font-weight:400;color:var(--v)}
.domain{margin:4px 0 0;font:700 20px/1.3 "JetBrains Mono",monospace;color:#fff;overflow-wrap:anywhere}
.lead{margin:16px 0 0;color:rgba(209,250,229,.82)}
.meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1px;margin-top:24px;border-radius:14px;overflow:hidden;background:rgba(255,255,255,.08)}
.meta div{padding:12px 14px;background:var(--pine)}.meta span{display:block;font:500 10px/1.4 "JetBrains Mono",monospace;letter-spacing:.08em;text-transform:uppercase;color:rgba(209,250,229,.55)}.meta b{font:700 14px/1.4 "JetBrains Mono",monospace;color:var(--glow)}
.box{margin-top:20px;padding:24px 28px;border:1px solid var(--line);border-radius:20px;background:var(--paper)}
.box h2{margin:0 0 12px;font-size:17px}
.box ul{margin:0;padding:0;list-style:none}.box li{display:flex;gap:10px;padding:7px 0;border-top:1px dashed var(--line);font-size:14.5px;color:var(--slate)}.box li:first-child{border-top:0}
.box li:before{content:"✓";flex:0 0 20px;height:20px;border-radius:6px;display:grid;place-items:center;background:#D1FAE5;color:var(--deep);font-size:11px;font-weight:800}
.dots{display:flex;flex-wrap:wrap;gap:6px}.dot{width:14px;height:14px;border-radius:4px;background:#D1FAE5}.dot.review{background:#FEF3C7}.dot.unknown{background:#E5E7EB}
.note{margin:10px 0 0;font-size:13px;color:var(--slate)}
.foot{display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;align-items:center;margin-top:28px;font-size:13px;color:var(--slate)}
.foot a{color:var(--ink);font-weight:700}
@media(max-width:600px){.card{padding:24px}h1{font-size:30px}.meta{grid-template-columns:1fr}}
</style>
</head>
<body class="<?php echo esc_attr( $state ); ?>">
<main class="wrap">
	<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
		<?php if ( '' !== $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt=""><?php endif; ?>
		<span><?php echo esc_html( $brand ); ?></span>
	</a>
	<section class="card" aria-labelledby="h">
		<p class="kick"><?php esc_html_e( '[ Security verification ]', 'site-audit-report' ); ?></p>
		<div class="state">
			<span class="seal" aria-hidden="true"><?php echo 'verified' === $state ? '✓' : ( 'review' === $state ? '!' : '–' ); ?></span>
			<div>
				<h1 id="h"><?php echo esc_html( $copy[0] ); ?><?php echo 'verified' === $state ? ' <em>' . esc_html__( 'and watched.', 'site-audit-report' ) . '</em>' : ''; ?></h1>
				<?php if ( $badge ) : ?><p class="domain"><?php echo esc_html( $domain ); ?></p><?php endif; ?>
			</div>
		</div>
		<p class="lead"><?php echo esc_html( $copy[1] ); ?></p>
		<?php if ( $badge ) : ?>
			<div class="meta">
				<div><span><?php esc_html_e( 'Last verified', 'site-audit-report' ); ?></span><b><?php echo $badge['verified_at'] ? esc_html( wp_date( 'j M Y', strtotime( $badge['verified_at'] . ' UTC' ) ) ) : '—'; ?></b></div>
				<div><span><?php esc_html_e( 'Checked by', 'site-audit-report' ); ?></span><b><?php echo esc_html( $brand ); ?></b></div>
				<div><span><?php esc_html_e( 'Check frequency', 'site-audit-report' ); ?></span><b><?php esc_html_e( 'Every week', 'site-audit-report' ); ?></b></div>
				<div><span><?php esc_html_e( 'Watched since', 'site-audit-report' ); ?></span><b><?php echo esc_html( wp_date( 'M Y', strtotime( $badge['created_at'] . ' UTC' ) ) ); ?></b></div>
			</div>
		<?php endif; ?>
	</section>

	<?php if ( $badge ) : ?>
		<section class="box">
			<h2><?php esc_html_e( 'What is checked every week', 'site-audit-report' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'The site loads over HTTPS with a valid, unexpired certificate.', 'site-audit-report' ); ?></li>
				<li><?php esc_html_e( 'Software versions the site shows have no known high-risk vulnerabilities.', 'site-audit-report' ); ?></li>
				<li><?php esc_html_e( 'Nothing it runs is on the US CISA list of vulnerabilities attackers are actively using.', 'site-audit-report' ); ?></li>
				<li><?php esc_html_e( 'No public debug logs or browsable upload folders.', 'site-audit-report' ); ?></li>
			</ul>
			<?php if ( $history ) : ?>
				<h2 style="margin-top:20px"><?php esc_html_e( 'Recent checks', 'site-audit-report' ); ?></h2>
				<div class="dots" aria-label="<?php esc_attr_e( 'Recent check results, newest first', 'site-audit-report' ); ?>">
					<?php foreach ( array_slice( $history, 0, 26 ) as $h ) : ?>
						<span class="dot <?php echo esc_attr( (string) ( $h['state'] ?? '' ) ); ?>" title="<?php echo esc_attr( wp_date( 'j M Y', strtotime( ( $h['at'] ?? '' ) . ' UTC' ) ) . ' · ' . ( $h['state'] ?? '' ) ); ?>"></span>
					<?php endforeach; ?>
				</div>
				<p class="note"><?php esc_html_e( 'Passive checks of public information only. A verification is not a guarantee against every possible attack.', 'site-audit-report' ); ?></p>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<p class="foot">
		<span><?php echo esc_html( sprintf( /* translators: %s: brand. */ __( 'Verification by %s', 'site-audit-report' ), $brand ) ); ?></span>
		<a href="<?php echo esc_url( $cta ); ?>"><?php esc_html_e( 'Get your site verified', 'site-audit-report' ); ?> ↗</a>
	</p>
</main>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
