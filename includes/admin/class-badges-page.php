<?php
/**
 * Trust badges screen.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report\Admin;

use Site_Audit_Report\Audits;
use Site_Audit_Report\Badges;

defined( 'ABSPATH' ) || exit;

/**
 * Badges screen.
 */
final class Badges_Page {

	const SLUG = 'site-audit-report-badges';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( self::class, 'menu' ), 13 );
		add_action( 'admin_post_sar_badge_create', array( self::class, 'handle_create' ) );
		add_action( 'admin_post_sar_badge_action', array( self::class, 'handle_action' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/**
	 * Adds the submenu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_submenu_page( Admin::SLUG, __( 'Trust badges', 'site-audit-report' ), __( 'Trust badges', 'site-audit-report' ), Admin::capability(), self::SLUG, array( self::class, 'render' ), 3 );
	}

	/**
	 * Copy buttons reuse the guide script.
	 *
	 * @param string $hook Screen.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false !== strpos( $hook, self::SLUG ) ) {
			wp_enqueue_script( 'site-audit-admin-guide', SAR_URL . 'assets/js/admin-guide.js', array(), SAR_VERSION, true );
		}
	}

	/**
	 * Stops users without access.
	 *
	 * @param string $nonce Nonce action.
	 * @return void
	 */
	private static function guard( $nonce ) {
		if ( ! current_user_can( Admin::capability() ) || ! check_admin_referer( $nonce ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'site-audit-report' ), 403 );
		}
	}

	/**
	 * Back to the screen with a message.
	 *
	 * @param array<string,mixed> $args Query args.
	 * @return void
	 */
	private static function back( array $args ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Creates a badge and runs its first check straight away.
	 *
	 * @return void
	 */
	public static function handle_create() {
		self::guard( 'sar_badge_create' );
		$url      = isset( $_POST['site_url'] ) ? sanitize_text_field( wp_unslash( $_POST['site_url'] ) ) : '';
		$audit_id = isset( $_POST['audit_id'] ) ? absint( $_POST['audit_id'] ) : 0;
		$label    = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		if ( $audit_id && '' === $url ) {
			$audit = Audits::get( $audit_id );
			$url   = $audit ? (string) $audit['site_url'] : '';
		}
		$badge = Badges::create( $url, $audit_id, $label );
		if ( is_wp_error( $badge ) ) {
			self::back( array( 'msg' => 'error', 'err' => rawurlencode( $badge->get_error_message() ) ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}
		Badges::check( $badge, false );
		self::back( array( 'msg' => 'created', 'badge' => (int) $badge['id'] ) );
	}

	/**
	 * Check now, pause, resume or delete.
	 *
	 * @return void
	 */
	public static function handle_action() {
		self::guard( 'sar_badge_action' );
		$id     = isset( $_POST['badge'] ) ? absint( $_POST['badge'] ) : 0;
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$badge  = Badges::get( $id );
		if ( ! $badge ) {
			self::back( array( 'msg' => 'missing' ) );
		}
		switch ( $do ) {
			case 'check':
				if ( function_exists( 'set_time_limit' ) ) {
					set_time_limit( 120 );
				}
				Badges::check( $badge, true );
				break;
			case 'pause':
				Badges::update( $id, array( 'active' => 0 ) );
				break;
			case 'resume':
				Badges::update( $id, array( 'active' => 1 ) );
				break;
			case 'delete':
				Badges::delete( $id );
				break;
		}
		self::back( array( 'msg' => $do, 'badge' => $id ) );
	}

	/**
	 * A one-button form.
	 *
	 * @param int    $id    Badge.
	 * @param string $do    Action.
	 * @param string $label Text.
	 * @param string $class Class.
	 * @param string $confirm Confirmation text.
	 * @return void
	 */
	private static function button( $id, $do, $label, $class = 'button', $confirm = '' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sar-inline-form"<?php echo $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : ''; ?>>
			<input type="hidden" name="action" value="sar_badge_action">
			<input type="hidden" name="badge" value="<?php echo (int) $id; ?>">
			<input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>">
			<?php wp_nonce_field( 'sar_badge_action' ); ?>
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen state.
		$msg     = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		$err     = isset( $_GET['err'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['err'] ) ) ) : '';
		$focus   = isset( $_GET['badge'] ) ? absint( $_GET['badge'] ) : 0;
		$prefill = isset( $_GET['audit'] ) ? absint( $_GET['audit'] ) : 0;
		// phpcs:enable

		$badges = Badges::all();
		$audits = Audits::query(
			array(
				'status' => 'complete',
				'limit'  => 200,
			)
		);
		$seen   = array();
		$states = array(
			'verified' => __( 'Verified', 'site-audit-report' ),
			'review'   => __( 'Under review', 'site-audit-report' ),
			'unknown'  => __( 'Could not check', 'site-audit-report' ),
			'pending'  => __( 'Not checked yet', 'site-audit-report' ),
		);
		$notices = array(
			'created' => array( 'success', __( 'Badge created and checked. Copy the embed code below into the client\'s site.', 'site-audit-report' ) ),
			'check'   => array( 'success', __( 'Badge re-checked.', 'site-audit-report' ) ),
			'pause'   => array( 'info', __( 'Badge paused. It now shows "Not verified" everywhere it is embedded.', 'site-audit-report' ) ),
			'resume'  => array( 'success', __( 'Badge resumed.', 'site-audit-report' ) ),
			'delete'  => array( 'info', __( 'Badge deleted. Any copies still embedded show "Not verified".', 'site-audit-report' ) ),
			'missing' => array( 'error', __( 'That badge no longer exists.', 'site-audit-report' ) ),
			'error'   => array( 'error', $err ),
		);
		?>
		<div class="wrap sar-admin sar-badges">
			<h1><?php esc_html_e( 'Trust badges', 'site-audit-report' ); ?></h1>
			<?php if ( isset( $notices[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notices[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $msg ][1] ); ?></p></div>
			<?php endif; ?>
			<p class="sar-guide__lead"><?php esc_html_e( 'Give a client a "Secured by" badge for their site. It links to a public verification page, and the site is re-checked every week: HTTPS and certificate, high-risk or actively exploited vulnerabilities, public debug logs and folder listings. If a check fails, the badge turns amber ("Under review") and you get an email with the reasons. The public never sees the reasons.', 'site-audit-report' ); ?></p>

			<details class="sar-card sar-badges__new" <?php echo ( ! $badges || $prefill ) ? 'open' : ''; ?>>
				<summary><strong><?php esc_html_e( 'Create a badge', 'site-audit-report' ); ?></strong></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sar-badges__form">
					<input type="hidden" name="action" value="sar_badge_create">
					<?php wp_nonce_field( 'sar_badge_create' ); ?>
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
					<label><span><?php esc_html_e( 'Badge text (optional)', 'site-audit-report' ); ?></span><input type="text" name="label" class="regular-text" maxlength="40" placeholder="<?php echo esc_attr( Badges::label( array() ) ); ?>"></label>
					<p class="description"><?php esc_html_e( 'Creating a badge runs its first check now, which takes up to a minute.', 'site-audit-report' ); ?></p>
					<?php submit_button( __( 'Create and check', 'site-audit-report' ), 'primary', 'submit', false ); ?>
				</form>
			</details>

			<?php if ( ! $badges ) : ?>
				<div class="sar-card sar-watch__empty">
					<span class="dashicons dashicons-awards" aria-hidden="true"></span>
					<div>
						<h2><?php esc_html_e( 'No badges yet', 'site-audit-report' ); ?></h2>
						<p><?php esc_html_e( 'Badges suit clients whose site you look after. They show visitors the site is checked, give you a link back from every client site, and turn into a renewal conversation the day something needs fixing.', 'site-audit-report' ); ?></p>
					</div>
				</div>
			<?php endif; ?>

			<?php foreach ( $badges as $b ) : ?>
				<?php
				$public = Badges::public_state( $b );
				$issues = (array) json_decode( (string) $b['issues'], true );
				$embed  = Badges::embed_code( $b );
				?>
				<section class="sar-card sar-badge sar-badge--<?php echo esc_attr( $public ); ?><?php echo $focus === (int) $b['id'] ? ' is-focus' : ''; ?>" id="badge-<?php echo (int) $b['id']; ?>">
					<div class="sar-badge__top">
						<div>
							<h2><?php echo esc_html( $b['domain'] ); ?> <?php if ( ! (int) $b['active'] ) : ?><span class="sar-sev"><?php esc_html_e( 'Paused', 'site-audit-report' ); ?></span><?php endif; ?></h2>
							<p class="sar-badge__meta">
								<span class="sar-badge__dot"></span><?php echo esc_html( $states[ $b['state'] ] ?? $b['state'] ); ?>
								· <?php echo $b['checked_at'] ? esc_html( sprintf( /* translators: %s: time. */ __( 'checked %s ago', 'site-audit-report' ), human_time_diff( strtotime( $b['checked_at'] . ' UTC' ) ) ) ) : esc_html__( 'never checked', 'site-audit-report' ); ?>
								· <?php echo esc_html( sprintf( /* translators: %d: views. */ _n( '%d verification view', '%d verification views', (int) $b['views'], 'site-audit-report' ), (int) $b['views'] ) ); ?>
								<?php if ( (int) $b['audit_id'] ) : ?>
									· <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::SLUG . '&audit=' . (int) $b['audit_id'] ) ); ?>"><?php esc_html_e( 'audit', 'site-audit-report' ); ?></a>
								<?php endif; ?>
							</p>
						</div>
						<div class="sar-badge__preview">
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from escaped parts. ?>
							<?php echo Badges::svg( $b, $public ); ?>
						</div>
					</div>

					<?php if ( 'verified' !== $b['state'] && $issues ) : ?>
						<div class="sar-badge__issues">
							<strong><?php esc_html_e( 'Why (private):', 'site-audit-report' ); ?></strong>
							<ul>
								<?php foreach ( $issues as $issue ) : ?>
									<li><b><?php echo esc_html( (string) $issue['label'] ); ?></b> — <?php echo esc_html( (string) $issue['evidence'] ); ?><?php echo ! empty( $issue['items'] ) ? ' (' . esc_html( (string) $issue['items'] ) . ')' : ''; ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>

					<div class="sar-code sar-badge__embed">
						<code><?php echo esc_html( $embed ); ?></code>
						<button type="button" class="button button-small" data-copy="<?php echo esc_attr( $embed ); ?>"><?php esc_html_e( 'Copy embed code', 'site-audit-report' ); ?></button>
					</div>

					<div class="sar-admin-actions">
						<a class="button" href="<?php echo esc_url( Badges::verify_url( (string) $b['token'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open public page', 'site-audit-report' ); ?> ↗</a>
						<?php self::button( (int) $b['id'], 'check', __( 'Check now', 'site-audit-report' ) ); ?>
						<?php
						if ( (int) $b['active'] ) {
							self::button( (int) $b['id'], 'pause', __( 'Pause', 'site-audit-report' ) );
						} else {
							self::button( (int) $b['id'], 'resume', __( 'Resume', 'site-audit-report' ) );
						}
						self::button( (int) $b['id'], 'delete', __( 'Delete', 'site-audit-report' ), 'button sar-link-danger', __( 'Delete this badge? Copies already embedded will show "Not verified".', 'site-audit-report' ) );
						?>
					</div>
				</section>
			<?php endforeach; ?>

			<p class="description"><?php esc_html_e( 'A badge copied onto a different website shows "Not verified" there. Badges not verified for 14 days also show "Not verified" until the next clean check.', 'site-audit-report' ); ?></p>
		</div>
		<?php
	}
}
