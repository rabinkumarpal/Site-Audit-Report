<?php
/**
 * Change watch screen.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report\Admin;

use Site_Audit_Report\Audits;
use Site_Audit_Report\Monitors;

defined( 'ABSPATH' ) || exit;

/**
 * Change watch screen.
 */
final class Monitors_Page {

	const SLUG = 'site-audit-report-changes';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( self::class, 'menu' ), 14 );
		add_action( 'admin_post_sar_monitor_create', array( self::class, 'handle_create' ) );
		add_action( 'admin_post_sar_monitor_action', array( self::class, 'handle_action' ) );
	}

	/**
	 * Adds the submenu, with a count of sites that changed this week.
	 *
	 * @return void
	 */
	public static function menu() {
		$n     = Monitors::recent_alerts();
		$badge = $n ? ' <span class="awaiting-mod"><span class="pending-count">' . (int) $n . '</span></span>' : '';
		add_submenu_page( Admin::SLUG, __( 'Change watch', 'site-audit-report' ), __( 'Change watch', 'site-audit-report' ) . $badge, Admin::capability(), self::SLUG, array( self::class, 'render' ), 4 );
	}

	/**
	 * Permission and nonce.
	 *
	 * @param string $nonce Action.
	 * @return void
	 */
	private static function guard( $nonce ) {
		if ( ! current_user_can( Admin::capability() ) || ! check_admin_referer( $nonce ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'site-audit-report' ), 403 );
		}
	}

	/**
	 * Back to the screen.
	 *
	 * @param array<string,mixed> $args Args.
	 * @return void
	 */
	private static function back( array $args ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Adds a site and records its baseline.
	 *
	 * @return void
	 */
	public static function handle_create() {
		self::guard( 'sar_monitor_create' );
		$url      = isset( $_POST['site_url'] ) ? sanitize_text_field( wp_unslash( $_POST['site_url'] ) ) : '';
		$audit_id = isset( $_POST['audit_id'] ) ? absint( $_POST['audit_id'] ) : 0;
		$label    = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		if ( $audit_id && '' === $url ) {
			$audit = Audits::get( $audit_id );
			$url   = $audit ? (string) $audit['site_url'] : '';
			$label = '' !== $label ? $label : (string) ( $audit['business_name'] ?? '' );
		}
		$m = Monitors::create( $url, $audit_id, $label );
		if ( is_wp_error( $m ) ) {
			self::back( array( 'msg' => 'error', 'err' => rawurlencode( $m->get_error_message() ) ) );
		}
		Monitors::check( $m );
		self::back( array( 'msg' => 'created', 'm' => (int) $m['id'] ) );
	}

	/**
	 * Check now, pause, resume, delete, or check every site.
	 *
	 * @return void
	 */
	public static function handle_action() {
		self::guard( 'sar_monitor_action' );
		$id = isset( $_POST['m'] ) ? absint( $_POST['m'] ) : 0;
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'all' === $do ) {
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 300 );
			}
			$digest = array();
			foreach ( Monitors::all() as $m ) {
				if ( (int) $m['active'] ) {
					$changes = Monitors::check( $m );
					if ( array_filter( $changes, static fn( $c ) => in_array( $c['severity'], array( 'critical', 'warning' ), true ) ) ) {
						$digest[ $m['domain'] ] = $changes;
					}
				}
			}
			if ( $digest ) {
				Monitors::notify( $digest );
			}
			self::back( array( 'msg' => 'all', 'n' => count( $digest ) ) );
		}
		$m = Monitors::get( $id );
		if ( ! $m ) {
			self::back( array( 'msg' => 'missing' ) );
		}
		if ( 'check' === $do ) {
			Monitors::check( $m );
		} elseif ( 'pause' === $do || 'resume' === $do ) {
			Monitors::update( $id, array( 'active' => 'resume' === $do ? 1 : 0 ) );
		} elseif ( 'delete' === $do ) {
			Monitors::delete( $id );
		}
		self::back( array( 'msg' => $do, 'm' => $id ) );
	}

	/**
	 * One-button form.
	 *
	 * @param int    $id      Monitor.
	 * @param string $do      Action.
	 * @param string $label   Text.
	 * @param string $class   Class.
	 * @param string $confirm Confirmation.
	 * @return void
	 */
	private static function button( $id, $do, $label, $class = 'button', $confirm = '' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sar-inline-form"<?php echo $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : ''; ?>>
			<input type="hidden" name="action" value="sar_monitor_action">
			<input type="hidden" name="m" value="<?php echo (int) $id; ?>">
			<input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>">
			<?php wp_nonce_field( 'sar_monitor_action' ); ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * The screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Admin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'site-audit-report' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only state.
		$msg     = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		$err     = isset( $_GET['err'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['err'] ) ) ) : '';
		$focus   = isset( $_GET['m'] ) ? absint( $_GET['m'] ) : 0;
		$prefill = isset( $_GET['audit'] ) ? absint( $_GET['audit'] ) : 0;
		$changed = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0;
		// phpcs:enable
		$monitors = Monitors::all();
		$audits   = Audits::query( array( 'status' => 'complete', 'limit' => 200 ) );
		$seen     = array();
		$sev      = array(
			'critical' => __( 'Urgent', 'site-audit-report' ),
			'warning'  => __( 'Check', 'site-audit-report' ),
			'info'     => __( 'Note', 'site-audit-report' ),
			'good'     => __( 'Good', 'site-audit-report' ),
		);
		$notices  = array(
			'created' => array( 'success', __( 'Site added. Its baseline is recorded; changes are reported from the next check (weekly, or use Check now).', 'site-audit-report' ) ),
			'check'   => array( 'success', __( 'Site checked.', 'site-audit-report' ) ),
			/* translators: %d: sites. */
			'all'     => array( 'success', sprintf( __( 'All sites checked. %d had notable changes; the digest has been emailed.', 'site-audit-report' ), $changed ) ),
			'pause'   => array( 'info', __( 'Watching paused for this site.', 'site-audit-report' ) ),
			'resume'  => array( 'success', __( 'Watching resumed.', 'site-audit-report' ) ),
			'delete'  => array( 'info', __( 'Site removed from the watch.', 'site-audit-report' ) ),
			'missing' => array( 'error', __( 'That site is no longer watched.', 'site-audit-report' ) ),
			'error'   => array( 'error', $err ),
		);
		?>
		<div class="wrap sar-admin sar-changes">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Change watch', 'site-audit-report' ); ?></h1>
			<?php if ( $monitors ) : ?>
				<?php self::button( 0, 'all', __( 'Check all now', 'site-audit-report' ), 'page-title-action' ); ?>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php if ( isset( $notices[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notices[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $msg ][1] ); ?></p></div>
			<?php endif; ?>
			<p class="sar-guide__lead"><?php esc_html_e( 'Sites you look after are fingerprinted every week and compared with the week before: reachability, HTTPS and certificate expiry, noindex and robots.txt, title, description, H1 and canonical, sitemap, analytics, structured data, security headers, and WordPress, theme and plugin versions. Urgent and notable changes are emailed to the team in one digest.', 'site-audit-report' ); ?></p>

			<details class="sar-card sar-badges__new" <?php echo ( ! $monitors || $prefill ) ? 'open' : ''; ?>>
				<summary><strong><?php esc_html_e( 'Watch a site', 'site-audit-report' ); ?></strong></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sar-badges__form">
					<input type="hidden" name="action" value="sar_monitor_create">
					<?php wp_nonce_field( 'sar_monitor_create' ); ?>
					<label><span><?php esc_html_e( 'From an audit', 'site-audit-report' ); ?></span>
						<select name="audit_id">
							<option value="0"><?php esc_html_e( '— or type an address —', 'site-audit-report' ); ?></option>
							<?php foreach ( $audits as $a ) : ?>
								<?php
								if ( isset( $seen[ $a['domain'] ] ) ) {
									continue;
								}
								$seen[ $a['domain'] ] = true;
								?>
								<option value="<?php echo (int) $a['id']; ?>" <?php selected( $prefill, (int) $a['id'] ); ?>><?php echo esc_html( $a['domain'] . ( $a['business_name'] ? ' · ' . $a['business_name'] : '' ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><span><?php esc_html_e( 'Website address', 'site-audit-report' ); ?></span><input type="text" name="site_url" class="regular-text" placeholder="clientsite.com"></label>
					<label><span><?php esc_html_e( 'Client name (optional)', 'site-audit-report' ); ?></span><input type="text" name="label" class="regular-text"></label>
					<p class="description"><?php esc_html_e( 'Adding a site records its baseline now, which takes up to a minute.', 'site-audit-report' ); ?></p>
					<?php submit_button( __( 'Start watching', 'site-audit-report' ), 'primary', 'submit', false ); ?>
				</form>
			</details>

			<?php if ( ! $monitors ) : ?>
				<div class="sar-card sar-watch__empty">
					<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
					<div>
						<h2><?php esc_html_e( 'No sites watched yet', 'site-audit-report' ); ?></h2>
						<p><?php esc_html_e( 'Add your clients\' sites. When someone ticks "Discourage search engines", a plugin disappears or the certificate is about to lapse, you will know within a week, usually before the client does.', 'site-audit-report' ); ?></p>
					</div>
				</div>
			<?php endif; ?>

			<?php foreach ( $monitors as $m ) : ?>
				<?php
				$snap    = (array) json_decode( (string) $m['snapshot'], true );
				$log     = (array) json_decode( (string) $m['changes'], true );
				$recent  = array_filter( $log, static fn( $c ) => strtotime( $c['at'] . ' UTC' ) >= time() - 7 * DAY_IN_SECONDS );
				$worst   = 'good';
				foreach ( $recent as $c ) {
					if ( Monitors::severities()[ $c['severity'] ] < Monitors::severities()[ $worst ] ) {
						$worst = $c['severity'];
					}
				}
				$state   = ! $m['checked_at'] ? 'pending' : ( empty( $snap['readable'] ) ? 'critical' : ( $recent ? $worst : 'quiet' ) );
				$wp      = (array) ( $snap['wp'] ?? array() );
				$cert    = ! empty( $snap['cert'] ) ? (int) floor( ( (int) $snap['cert'] - time() ) / DAY_IN_SECONDS ) : null;
				$by_date = array();
				foreach ( array_slice( $log, 0, 40 ) as $c ) {
					$by_date[ substr( $c['at'], 0, 10 ) ][] = $c;
				}
				?>
				<section class="sar-card sar-monitor sar-monitor--<?php echo esc_attr( $state ); ?><?php echo $focus === (int) $m['id'] ? ' is-focus' : ''; ?>">
					<div class="sar-badge__top">
						<div>
							<h2><?php echo esc_html( $m['domain'] ); ?><?php echo $m['label'] ? ' <small>· ' . esc_html( $m['label'] ) . '</small>' : ''; ?> <?php if ( ! (int) $m['active'] ) : ?><span class="sar-sev"><?php esc_html_e( 'Paused', 'site-audit-report' ); ?></span><?php endif; ?></h2>
							<p class="sar-badge__meta">
								<span class="sar-badge__dot"></span>
								<?php
								$words = array(
									'pending'  => __( 'Baseline not recorded yet', 'site-audit-report' ),
									'critical' => empty( $snap['readable'] ) && $m['checked_at'] ? __( 'Site not reachable', 'site-audit-report' ) : __( 'Urgent change this week', 'site-audit-report' ),
									'warning'  => __( 'Changes to check this week', 'site-audit-report' ),
									'info'     => __( 'Minor changes this week', 'site-audit-report' ),
									'good'     => __( 'Improvements this week', 'site-audit-report' ),
									'quiet'    => __( 'No changes this week', 'site-audit-report' ),
								);
								echo esc_html( $words[ $state ] );
								?>
								· <?php echo $m['checked_at'] ? esc_html( sprintf( /* translators: %s: time. */ __( 'checked %s ago', 'site-audit-report' ), human_time_diff( strtotime( $m['checked_at'] . ' UTC' ) ) ) ) : ''; ?>
							</p>
						</div>
						<div class="sar-admin-actions">
							<?php
							self::button( (int) $m['id'], 'check', __( 'Check now', 'site-audit-report' ) );
							self::button( (int) $m['id'], (int) $m['active'] ? 'pause' : 'resume', (int) $m['active'] ? __( 'Pause', 'site-audit-report' ) : __( 'Resume', 'site-audit-report' ) );
							self::button( (int) $m['id'], 'delete', __( 'Remove', 'site-audit-report' ), 'button sar-link-danger', __( 'Stop watching this site and delete its history?', 'site-audit-report' ) );
							?>
						</div>
					</div>

					<?php if ( ! empty( $snap['readable'] ) || isset( $snap['title'] ) ) : ?>
						<dl class="sar-monitor__facts">
							<div><dt><?php esc_html_e( 'Title', 'site-audit-report' ); ?></dt><dd><?php echo esc_html( (string) ( $snap['title'] ?? '—' ) ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Indexable', 'site-audit-report' ); ?></dt><dd><?php echo ! empty( $snap['noindex'] ) || ! empty( $snap['robots_all'] ) ? '<span class="sar-bad">' . esc_html__( 'No', 'site-audit-report' ) . '</span>' : esc_html__( 'Yes', 'site-audit-report' ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Certificate', 'site-audit-report' ); ?></dt><dd><?php echo null === $cert ? '—' : ( $cert < 14 ? '<span class="sar-bad">' : '<span>' ) . esc_html( sprintf( /* translators: %d: days. */ _n( '%d day left', '%d days left', $cert, 'site-audit-report' ), $cert ) ) . '</span>'; ?></dd></div>
							<div><dt>WordPress</dt><dd><?php echo $wp ? esc_html( ( $wp['core'] ? $wp['core'] : __( 'version hidden', 'site-audit-report' ) ) . ' · ' . sprintf( /* translators: %d: plugins. */ _n( '%d plugin seen', '%d plugins seen', count( (array) $wp['plugins'] ), 'site-audit-report' ), count( (array) $wp['plugins'] ) ) ) : esc_html__( 'Not WordPress', 'site-audit-report' ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Analytics', 'site-audit-report' ); ?></dt><dd><?php echo esc_html( ! empty( $snap['trackers'] ) ? implode( ', ', (array) $snap['trackers'] ) : __( 'None seen', 'site-audit-report' ) ); ?></dd></div>
						</dl>
					<?php endif; ?>

					<?php if ( $by_date ) : ?>
						<details class="sar-monitor__log" <?php echo $recent ? 'open' : ''; ?>>
							<summary><?php echo esc_html( sprintf( /* translators: %d: count. */ _n( 'History · %d change', 'History · %d changes', count( $log ), 'site-audit-report' ), count( $log ) ) ); ?></summary>
							<?php foreach ( $by_date as $date => $items ) : ?>
								<div class="sar-monitor__day">
									<p class="sar-monitor__date"><?php echo esc_html( mysql2date( 'j M Y', $date ) ); ?></p>
									<ul>
										<?php foreach ( $items as $c ) : ?>
											<li>
												<span class="sar-chg sar-chg--<?php echo esc_attr( $c['severity'] ); ?>"><?php echo esc_html( $sev[ $c['severity'] ] ?? $c['severity'] ); ?></span>
												<span><?php echo esc_html( $c['label'] ); ?>
												<?php if ( '' !== $c['before'] || '' !== $c['after'] ) : ?>
													<br><small><del><?php echo esc_html( '' !== $c['before'] ? $c['before'] : '—' ); ?></del> → <ins><?php echo esc_html( '' !== $c['after'] ? $c['after'] : '—' ); ?></ins></small>
												<?php endif; ?>
												</span>
											</li>
										<?php endforeach; ?>
									</ul>
								</div>
							<?php endforeach; ?>
						</details>
					<?php elseif ( $m['checked_at'] ) : ?>
						<p class="description"><?php esc_html_e( 'Baseline recorded. Nothing has changed since.', 'site-audit-report' ); ?></p>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
