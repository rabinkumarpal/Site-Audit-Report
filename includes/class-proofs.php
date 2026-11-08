<?php
/**
 * Before-and-after proof pages: a public case study built from two audit runs
 * of the same site. The numbers are frozen when the page is created, so it
 * never changes after a later re-run or retention clean-up.
 *
 * Only improvements are shown publicly. Regressions and open issues stay in
 * the admin, and a page can hide the client's domain.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Proof pages.
 */
final class Proofs {

	const PARAM = 'sar_proof';

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
		return $wpdb->prefix . 'sar_proofs';
	}

	/**
	 * Public address.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function url( $token ) {
		return add_query_arg( self::PARAM, $token, home_url( '/' ) );
	}

	/* ------------------------------------------------------------ Snapshots */

	/**
	 * Every stored result for a domain: each finished run, plus the run before
	 * each re-run. Oldest first.
	 *
	 * @param string $domain Domain key.
	 * @return array<int,array{ref:string,at:string,score:int|null,audit:int,source:string}>
	 */
	public static function snapshots( $domain ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, source, score, completed_at, previous_score, previous_at, previous_result IS NOT NULL has_prev FROM {$wpdb->prefix}sar_audits WHERE domain = %s AND status = 'complete' ORDER BY id ASC", $domain ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			if ( (int) $r['has_prev'] && $r['previous_at'] ) {
				$out[] = array(
					'ref'    => 'p' . $r['id'],
					'at'     => (string) $r['previous_at'],
					'score'  => null === $r['previous_score'] ? null : (int) $r['previous_score'],
					'audit'  => (int) $r['id'],
					'source' => (string) $r['source'],
				);
			}
			$out[] = array(
				'ref'    => 'a' . $r['id'],
				'at'     => (string) $r['completed_at'],
				'score'  => null === $r['score'] ? null : (int) $r['score'],
				'audit'  => (int) $r['id'],
				'source' => (string) $r['source'],
			);
		}
		usort( $out, static fn( $a, $b ) => strcmp( $a['at'], $b['at'] ) );
		return $out;
	}

	/**
	 * Domains that have at least two results to compare.
	 *
	 * @return array<string,int> Domain => number of results.
	 */
	public static function eligible_domains() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		$rows = $wpdb->get_results( "SELECT domain, COUNT(*) + SUM(previous_result IS NOT NULL) n FROM {$wpdb->prefix}sar_audits WHERE status = 'complete' GROUP BY domain HAVING n >= 2 ORDER BY MAX(id) DESC LIMIT 200", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['domain'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Loads a snapshot by reference ("a12" is audit 12's result, "p12" its result before the last re-run).
	 *
	 * @param string $ref Reference.
	 * @return array{result:array<string,mixed>,at:string,audit:array<string,mixed>}|null
	 */
	public static function load( $ref ) {
		if ( ! preg_match( '/^([ap])(\d+)$/', (string) $ref, $m ) ) {
			return null;
		}
		$audit = Audits::get( (int) $m[2] );
		if ( ! $audit ) {
			return null;
		}
		$json   = 'a' === $m[1] ? $audit['result'] : $audit['previous_result'];
		$result = json_decode( (string) $json, true );
		if ( ! is_array( $result ) ) {
			return null;
		}
		return array(
			'result' => $result,
			'at'     => (string) ( 'a' === $m[1] ? $audit['completed_at'] : $audit['previous_at'] ),
			'audit'  => $audit,
		);
	}

	/**
	 * Compares two results.
	 *
	 * @param array<string,mixed> $before Earlier result.
	 * @param array<string,mixed> $after  Later result.
	 * @return array<string,mixed>
	 */
	public static function compare( array $before, array $after ) {
		$defs = Scoring::categories();
		$cats = array();
		foreach ( array_unique( array_merge( array_keys( (array) ( $before['categories'] ?? array() ) ), array_keys( (array) ( $after['categories'] ?? array() ) ) ) ) as $id ) {
			$b = $before['categories'][ $id ]['score'] ?? null;
			$a = $after['categories'][ $id ]['score'] ?? null;
			if ( null === $b && null === $a ) {
				continue;
			}
			$cats[ $id ] = array(
				'label'  => (string) ( $defs[ $id ]['label'] ?? $after['categories'][ $id ]['label'] ?? $before['categories'][ $id ]['label'] ?? $id ),
				'before' => null === $b ? null : (int) $b,
				'after'  => null === $a ? null : (int) $a,
			);
		}

		$index = static function ( array $result ) {
			$out = array();
			foreach ( (array) ( $result['checks'] ?? array() ) as $c ) {
				$out[ $c['id'] ] = $c;
			}
			return $out;
		};
		$bc = $index( $before );
		$ac = $index( $after );

		$fixed       = array();
		$regressions = array();
		foreach ( $ac as $id => $c ) {
			if ( ! isset( $bc[ $id ] ) ) {
				continue;
			}
			$was = $bc[ $id ]['status'];
			$now = $c['status'];
			if ( in_array( $was, array( 'warn', 'fail' ), true ) && ( 'pass' === $now || ( 'fail' === $was && 'warn' === $now ) ) ) {
				$fixed[] = array(
					'label'    => (string) $c['label'],
					'category' => (string) ( $cats[ $c['category'] ]['label'] ?? $c['category'] ),
					'cat'      => (string) $c['category'],
					'was'      => $was,
					'now'      => $now,
				);
			} elseif ( 'pass' === $was && in_array( $now, array( 'warn', 'fail' ), true ) ) {
				$regressions[] = (string) $c['label'];
			}
		}
		$open = 0;
		foreach ( $ac as $c ) {
			$open += in_array( $c['status'], array( 'warn', 'fail' ), true ) ? 1 : 0;
		}

		return array(
			'before'      => array(
				'score' => isset( $before['score'] ) ? (int) $before['score'] : null,
				'at'    => (string) ( $before['generated_at'] ?? '' ),
			),
			'after'       => array(
				'score' => isset( $after['score'] ) ? (int) $after['score'] : null,
				'at'    => (string) ( $after['generated_at'] ?? '' ),
			),
			'categories'  => $cats,
			'fixed'       => $fixed,
			'regressions' => $regressions,
			'open'        => $open,
		);
	}

	/* -------------------------------------------------------------- Records */

	/**
	 * Creates a proof page from two snapshots.
	 *
	 * @param string              $before_ref Earlier snapshot.
	 * @param string              $after_ref  Later snapshot.
	 * @param array<string,mixed> $fields     title, client_label, anonymize, quote, quote_by, indexable.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function create( $before_ref, $after_ref, array $fields ) {
		global $wpdb;
		$b = self::load( $before_ref );
		$a = self::load( $after_ref );
		if ( ! $b || ! $a ) {
			return new \WP_Error( 'sar_proof_missing', __( 'One of the chosen results could not be found.', 'site-audit-report' ) );
		}
		if ( $b['audit']['domain'] !== $a['audit']['domain'] ) {
			return new \WP_Error( 'sar_proof_domain', __( 'Both results must be for the same website.', 'site-audit-report' ) );
		}
		if ( strcmp( $b['at'], $a['at'] ) >= 0 ) {
			return new \WP_Error( 'sar_proof_order', __( 'The "before" result must be older than the "after" result.', 'site-audit-report' ) );
		}
		$data           = self::compare( $b['result'], $a['result'] );
		$data['before']['at'] = $b['at'];
		$data['after']['at']  = $a['at'];
		$data['refs']   = array( $before_ref, $after_ref );

		$token = bin2hex( random_bytes( 12 ) );
		$wpdb->insert(
			self::table(),
			array(
				'token'        => $token,
				'audit_id'     => (int) $a['audit']['id'],
				'domain'       => (string) $a['audit']['domain'],
				'title'        => mb_substr( sanitize_text_field( (string) ( $fields['title'] ?? '' ) ), 0, 191 ),
				'client_label' => mb_substr( sanitize_text_field( (string) ( $fields['client_label'] ?? '' ) ), 0, 191 ),
				'anonymize'    => empty( $fields['anonymize'] ) ? 0 : 1,
				'quote'        => sanitize_textarea_field( (string) ( $fields['quote'] ?? '' ) ),
				'quote_by'     => mb_substr( sanitize_text_field( (string) ( $fields['quote_by'] ?? '' ) ), 0, 191 ),
				'indexable'    => empty( $fields['indexable'] ) ? 0 : 1,
				'data'         => wp_json_encode( $data ),
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return self::by_token( $token );
	}

	/**
	 * By token.
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
	 * All proof pages.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		return (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 200', ARRAY_A );
	}

	/**
	 * Updates a proof page.
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
	 * Deletes a proof page.
	 *
	 * @param int $id ID.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * The name shown publicly.
	 *
	 * @param array<string,mixed> $proof Proof.
	 * @return string
	 */
	public static function display_name( array $proof ) {
		if ( (int) $proof['anonymize'] ) {
			return '' !== (string) $proof['client_label'] ? (string) $proof['client_label'] : __( 'A client website', 'site-audit-report' );
		}
		return '' !== (string) $proof['client_label'] ? (string) $proof['client_label'] : (string) $proof['domain'];
	}

	/* --------------------------------------------------------------- Public */

	/**
	 * Serves a proof page.
	 *
	 * @return void
	 */
	public static function maybe_serve() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public, read-only.
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as hex by by_token().
		$proof = self::by_token( strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) wp_unslash( $_GET[ self::PARAM ] ) ) ) );
		if ( $proof && ! (int) $proof['active'] && ! current_user_can( 'manage_options' ) ) {
			$proof = null;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src https: data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'" );
		header( 'Content-Type: text/html; charset=utf-8' );
		if ( ! $proof ) {
			status_header( 404 );
			header( 'X-Robots-Tag: noindex' );
		} else {
			header( 'X-Robots-Tag: ' . ( (int) $proof['indexable'] ? 'index, follow' : 'noindex, follow' ) );
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET views = views + 1 WHERE id = %d', (int) $proof['id'] ) );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		echo self::page( $proof );
		exit;
	}

	/**
	 * The public case-study page. Only improvements are shown.
	 *
	 * @param array<string,mixed>|null $proof Proof.
	 * @return string
	 */
	public static function page( $proof ) {
		$brand = Settings::brand_name();
		$logo  = (string) Settings::get( 'logo_url' );
		$cta   = (string) Settings::get( 'cta_url' );
		$cta   = '' !== $cta ? $cta : home_url( '/' );
		$data  = $proof ? (array) json_decode( (string) $proof['data'], true ) : array();
		$name  = $proof ? self::display_name( $proof ) : '';
		$b     = $data['before']['score'] ?? null;
		$a     = $data['after']['score'] ?? null;
		$delta = null !== $a && null !== $b ? $a - $b : null;
		$title = $proof && '' !== (string) $proof['title'] ? (string) $proof['title'] : ( $proof ? sprintf( /* translators: %s: client. */ __( '%s: before and after', 'site-audit-report' ), $name ) : __( 'Case study not found', 'site-audit-report' ) );
		$fixed = (array) ( $data['fixed'] ?? array() );
		$by    = array();
		foreach ( $fixed as $f ) {
			$by[ $f['category'] ][] = $f['label'];
		}
		$days  = $proof ? max( 1, (int) round( ( strtotime( $data['after']['at'] . ' UTC' ) - strtotime( $data['before']['at'] . ' UTC' ) ) / DAY_IN_SECONDS ) ) : 0;
		$cats  = array_filter( (array) ( $data['categories'] ?? array() ), static fn( $c ) => null !== $c['before'] && null !== $c['after'] );
		$up    = count( array_filter( $cats, static fn( $c ) => $c['after'] > $c['before'] ) );

		ob_start();
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="<?php echo $proof && (int) $proof['indexable'] ? 'index, follow' : 'noindex, follow'; ?>">
<title><?php echo esc_html( $title . ' — ' . $brand ); ?></title>
<?php if ( $proof && null !== $delta ) : ?>
<meta name="description" content="<?php echo esc_attr( sprintf( /* translators: 1: before, 2: after, 3: fixed, 4: days. */ __( 'Website score from %1$d to %2$d, %3$d issues fixed in %4$d days.', 'site-audit-report' ), $b, $a, count( $fixed ), $days ) ); ?>">
<?php endif; ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@1&family=JetBrains+Mono:wght@500;700&family=Plus+Jakarta+Sans:wght@400;600;800&display=swap">
<style>
:root{--canvas:#F7F8F5;--paper:#fff;--line:#D5E0DA;--ink:#091E15;--slate:#475E52;--pine:#072418;--glow:#6EE7B7;--deep:#0E9F6E;--emerald:#10B981}
*{box-sizing:border-box}html,body{margin:0}body{background:var(--canvas);color:var(--ink);font:16px/1.6 "Plus Jakarta Sans",system-ui,sans-serif;-webkit-font-smoothing:antialiased;background-image:linear-gradient(to right,rgba(9,30,21,.04) 1px,transparent 1px),linear-gradient(to bottom,rgba(9,30,21,.04) 1px,transparent 1px);background-size:64px 64px}
.wrap{max-width:960px;margin:0 auto;padding:40px 20px 64px}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:36px;font-weight:800;color:var(--ink);text-decoration:none}.brand img{height:32px}
.kick{margin:0 0 12px;font:500 12px/1.4 "JetBrains Mono",monospace;letter-spacing:.1em;text-transform:uppercase;color:var(--deep)}
h1{margin:0 0 12px;font-size:48px;line-height:1.04;font-weight:800;letter-spacing:-.03em}h1 em{font-family:"Instrument Serif",serif;font-weight:400;color:var(--deep)}
.lead{margin:0 0 32px;max-width:640px;font-size:18px;color:var(--slate)}
.hero{position:relative;overflow:hidden;display:grid;grid-template-columns:1fr auto 1fr;gap:24px;align-items:center;padding:36px;border-radius:24px;background:var(--pine);color:#D1FAE5;box-shadow:0 30px 60px -20px rgba(4,23,15,.35)}
.hero:after{content:"";position:absolute;inset:0;background:radial-gradient(circle at 85% 20%,rgba(16,185,129,.35),transparent 50%);pointer-events:none}.hero>*{position:relative}
.score span{display:block;font:500 11px/1.4 "JetBrains Mono",monospace;letter-spacing:.1em;text-transform:uppercase;color:rgba(209,250,229,.6)}
.score b{display:block;font:700 72px/1 "JetBrains Mono",monospace;color:#fff;letter-spacing:-.04em}.score small{font:500 13px/1.4 "JetBrains Mono",monospace;color:rgba(209,250,229,.6)}
.score--before b{color:rgba(255,255,255,.45)}.score--after{text-align:right}.score--after b{color:var(--glow)}
.delta{display:flex;flex-direction:column;align-items:center;gap:6px}.delta b{padding:8px 16px;border-radius:999px;background:rgba(110,231,183,.16);color:var(--glow);font:700 22px/1 "JetBrains Mono",monospace}.delta span{font-size:30px;color:rgba(255,255,255,.4)}
.facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:20px 0}
.fact{padding:18px 20px;border:1px solid var(--line);border-radius:18px;background:var(--paper)}.fact b{display:block;font-size:32px;font-weight:800;letter-spacing:-.02em}.fact span{font-size:13px;color:var(--slate)}
.box{margin-top:20px;padding:28px;border:1px solid var(--line);border-radius:22px;background:var(--paper)}
.box h2{margin:0 0 18px;font-size:22px;letter-spacing:-.01em}
.bar{display:grid;grid-template-columns:200px 1fr 90px;gap:14px;align-items:center;padding:8px 0;border-top:1px dashed var(--line)}.bar:first-of-type{border-top:0}
.bar__name{font-weight:600;font-size:14.5px}.bar__track{position:relative;height:12px;border-radius:999px;background:#EBF2EE;overflow:hidden}
.bar__b,.bar__a{position:absolute;inset:0 auto 0 0;border-radius:999px}.bar__b{background:#C9D6CF}.bar__a{background:linear-gradient(90deg,var(--emerald),var(--glow));mix-blend-mode:multiply}
.bar__num{font:600 13px/1 "JetBrains Mono",monospace;text-align:right;color:var(--slate)}.bar__num b{color:var(--deep)}
.legend{display:flex;gap:16px;margin:0 0 12px;font-size:12.5px;color:var(--slate)}.legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:-1px}
.cols{columns:2;column-gap:28px}.grp{break-inside:avoid;margin-bottom:16px}.grp h3{margin:0 0 6px;font:600 11px/1.4 "JetBrains Mono",monospace;letter-spacing:.08em;text-transform:uppercase;color:var(--deep)}
.grp ul{margin:0;padding:0;list-style:none}.grp li{display:flex;gap:8px;padding:4px 0;font-size:14.5px}.grp li:before{content:"✓";flex:0 0 18px;height:18px;border-radius:6px;display:grid;place-items:center;background:#D1FAE5;color:var(--deep);font-size:10px;font-weight:800;margin-top:3px}
blockquote{margin:20px 0 0;padding:28px 32px;border-radius:22px;background:var(--paper);border:1px solid var(--line);font-family:"Instrument Serif",serif;font-style:italic;font-size:26px;line-height:1.35}
blockquote cite{display:block;margin-top:12px;font:600 13px/1.4 "Plus Jakarta Sans",sans-serif;font-style:normal;color:var(--slate)}
.cta{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:16px;margin-top:24px;padding:28px 32px;border-radius:22px;background:var(--pine)}.cta p{margin:0;max-width:520px;font-size:22px;font-weight:800;color:#fff;line-height:1.2}
.cta a{display:inline-flex;gap:10px;padding:14px 22px;border-radius:999px;background:var(--glow);color:#04170F;font-weight:700;text-decoration:none}
.note{margin-top:20px;font-size:12.5px;color:var(--slate)}
@media(max-width:700px){h1{font-size:32px}.hero{grid-template-columns:1fr 1fr;padding:24px}.delta{grid-column:1/-1;grid-row:2;flex-direction:row}.score b{font-size:48px}.facts{grid-template-columns:1fr}.bar{grid-template-columns:1fr 70px}.bar__track{grid-column:1/-1;grid-row:2}.cols{columns:1}}
</style>
</head>
<body>
<main class="wrap">
	<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php if ( '' !== $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt=""><?php endif; ?><span><?php echo esc_html( $brand ); ?></span></a>
	<?php if ( ! $proof ) : ?>
		<h1><?php esc_html_e( 'Case study not found', 'site-audit-report' ); ?></h1>
		<p class="lead"><?php esc_html_e( 'This page may have been unpublished.', 'site-audit-report' ); ?></p>
	<?php else : ?>
		<p class="kick"><?php esc_html_e( '[ Before and after ]', 'site-audit-report' ); ?></p>
		<h1><?php echo esc_html( $title ); ?></h1>
		<p class="lead">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: client, 2: before date, 3: after date. */
					__( '%1$s was audited on %2$s and again on %3$s, using the same independent checks both times.', 'site-audit-report' ),
					$name,
					wp_date( 'j M Y', strtotime( $data['before']['at'] . ' UTC' ) ),
					wp_date( 'j M Y', strtotime( $data['after']['at'] . ' UTC' ) )
				)
			);
			?>
		</p>

		<?php if ( null !== $delta ) : ?>
			<section class="hero" aria-label="<?php esc_attr_e( 'Overall score', 'site-audit-report' ); ?>">
				<div class="score score--before"><span><?php esc_html_e( 'Before', 'site-audit-report' ); ?></span><b><?php echo (int) $b; ?></b><small><?php echo esc_html( wp_date( 'M Y', strtotime( $data['before']['at'] . ' UTC' ) ) ); ?></small></div>
				<div class="delta"><span aria-hidden="true">→</span><b><?php echo esc_html( ( $delta >= 0 ? '+' : '' ) . $delta ); ?></b></div>
				<div class="score score--after"><span><?php esc_html_e( 'After', 'site-audit-report' ); ?></span><b><?php echo (int) $a; ?></b><small><?php echo esc_html( wp_date( 'M Y', strtotime( $data['after']['at'] . ' UTC' ) ) ); ?></small></div>
			</section>
		<?php endif; ?>

		<div class="facts">
			<div class="fact"><b><?php echo count( $fixed ); ?></b><span><?php esc_html_e( 'issues fixed', 'site-audit-report' ); ?></span></div>
			<div class="fact"><b><?php echo (int) $up; ?></b><span><?php echo esc_html( sprintf( /* translators: %d: total. */ __( 'of %d areas improved', 'site-audit-report' ), count( $cats ) ) ); ?></span></div>
			<div class="fact"><b><?php echo (int) $days; ?></b><span><?php esc_html_e( 'days between the two audits', 'site-audit-report' ); ?></span></div>
		</div>

		<?php if ( $cats ) : ?>
			<section class="box">
				<h2><?php esc_html_e( 'Score by area', 'site-audit-report' ); ?></h2>
				<p class="legend"><span><i style="background:#C9D6CF"></i><?php esc_html_e( 'Before', 'site-audit-report' ); ?></span><span><i style="background:#10B981"></i><?php esc_html_e( 'After', 'site-audit-report' ); ?></span></p>
				<?php foreach ( $cats as $c ) : ?>
					<div class="bar">
						<span class="bar__name"><?php echo esc_html( $c['label'] ); ?></span>
						<span class="bar__track" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: before, 2: after. */ __( 'From %1$d to %2$d', 'site-audit-report' ), $c['before'], $c['after'] ) ); ?>"><span class="bar__b" style="width:<?php echo (int) $c['before']; ?>%"></span><span class="bar__a" style="width:<?php echo (int) $c['after']; ?>%"></span></span>
						<span class="bar__num"><?php echo (int) $c['before']; ?> → <b><?php echo (int) $c['after']; ?></b></span>
					</div>
				<?php endforeach; ?>
			</section>
		<?php endif; ?>

		<?php if ( $by ) : ?>
			<section class="box">
				<h2><?php esc_html_e( 'What was fixed', 'site-audit-report' ); ?></h2>
				<div class="cols">
					<?php foreach ( $by as $cat => $labels ) : ?>
						<div class="grp"><h3><?php echo esc_html( $cat ); ?></h3><ul><?php foreach ( array_slice( $labels, 0, 15 ) as $l ) : ?><li><?php echo esc_html( $l ); ?></li><?php endforeach; ?></ul></div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( '' !== trim( (string) $proof['quote'] ) ) : ?>
			<blockquote>“<?php echo esc_html( (string) $proof['quote'] ); ?>”<?php if ( '' !== (string) $proof['quote_by'] ) : ?><cite>— <?php echo esc_html( (string) $proof['quote_by'] ); ?></cite><?php endif; ?></blockquote>
		<?php endif; ?>

		<section class="cta">
			<p><?php esc_html_e( 'Want the same audit for your website? It is free.', 'site-audit-report' ); ?></p>
			<a href="<?php echo esc_url( $cta ); ?>"><?php esc_html_e( 'Get my free audit', 'site-audit-report' ); ?> ↗</a>
		</section>
		<p class="note"><?php echo esc_html( sprintf( /* translators: %s: brand. */ __( 'Scores from %s website audits: passive checks of public pages, the same method both times. Results vary between sites.', 'site-audit-report' ), $brand ) ); ?></p>
	<?php endif; ?>
</main>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
