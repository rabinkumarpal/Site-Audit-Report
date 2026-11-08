<?php
/**
 * Vulnerability watch screen.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report\Admin;

use Site_Audit_Report\Settings;
use Site_Audit_Report\Vuln_Watch;

defined( 'ABSPATH' ) || exit;

/**
 * Watch screen.
 */
final class Watch {

	const SLUG = 'site-audit-report-watch';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( self::class, 'menu' ), 12 );
		add_action( 'admin_post_sar_watch_run', array( self::class, 'handle_run' ) );
		add_action( 'admin_post_sar_watch_status', array( self::class, 'handle_status' ) );
	}

	/**
	 * Adds the submenu with a count of new alerts.
	 *
	 * @return void
	 */
	public static function menu() {
		$new   = Vuln_Watch::counts()['new'];
		$badge = $new ? ' <span class="awaiting-mod count-' . (int) $new . '"><span class="pending-count">' . (int) $new . '</span></span>' : '';
		add_submenu_page( Admin::SLUG, __( 'Vulnerability watch', 'site-audit-report' ), __( 'Vulnerability watch', 'site-audit-report' ) . $badge, Admin::capability(), self::SLUG, array( self::class, 'render' ), 2 );
	}

	/**
	 * "Check now".
	 *
	 * @return void
	 */
	public static function handle_run() {
		if ( ! current_user_can( Admin::capability() ) || ! check_admin_referer( 'sar_watch_run' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'site-audit-report' ), 403 );
		}
		$result = Vuln_Watch::run( true );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'ran' => (int) $result['alerts'], 'checked' => (int) $result['checked'] ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Marks alerts as contacted, dismissed or new.
	 *
	 * @return void
	 */
	public static function handle_status() {
		if ( ! current_user_can( Admin::capability() ) || ! check_admin_referer( 'sar_watch_status' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'site-audit-report' ), 403 );
		}
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$ids    = isset( $_POST['ids'] ) ? array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) ) : array();
		foreach ( array_filter( $ids ) as $id ) {
			Vuln_Watch::set_status( $id, $status );
		}
		$back = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : 'new';
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'view' => $back ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * A small form button that changes status.
	 *
	 * @param int[]  $ids    Alert IDs.
	 * @param string $status Target status.
	 * @param string $label  Button text.
	 * @param string $view   Current view.
	 * @param string $class  Button class.
	 * @return void
	 */
	private static function status_button( array $ids, $status, $label, $view, $class = 'button' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sar-inline-form">
			<input type="hidden" name="action" value="sar_watch_status">
			<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
			<input type="hidden" name="ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>">
			<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
			<?php wp_nonce_field( 'sar_watch_status' ); ?>
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view state.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'new';
		$view = in_array( $view, array( 'new', 'contacted', 'dismissed', 'all' ), true ) ? $view : 'new';
		$ran  = isset( $_GET['ran'] ) ? absint( $_GET['ran'] ) : null;
		$chk  = isset( $_GET['checked'] ) ? absint( $_GET['checked'] ) : 0;
		// phpcs:enable

		$counts  = Vuln_Watch::counts();
		$watched = Vuln_Watch::watched();
		$last    = (array) get_option( Vuln_Watch::LAST_RUN, array() );
		$alerts  = Vuln_Watch::alerts( 'all' === $view ? '' : $view );
		$base    = admin_url( 'admin.php?page=' . self::SLUG );
		$audits  = admin_url( 'admin.php?page=' . Admin::SLUG );
		$leads   = Audits_Table::lead_statuses();
		$enabled = (bool) Settings::get( 'watch_enabled' );

		// Group by vulnerability, so one disclosure shows every exposed lead together.
		$groups = array();
		foreach ( $alerts as $a ) {
			$key = $a['vuln_id'] . '|' . $a['reason'] . '|' . $a['slug'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'a'    => $a,
					'rows' => array(),
				);
			}
			$groups[ $key ]['rows'][] = $a;
		}
		?>
		<div class="wrap sar-admin sar-watch">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Vulnerability watch', 'site-audit-report' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sar-inline-form">
				<input type="hidden" name="action" value="sar_watch_run">
				<?php wp_nonce_field( 'sar_watch_run' ); ?>
				<button type="submit" class="page-title-action" <?php disabled( ! $enabled ); ?>><?php esc_html_e( 'Check now', 'site-audit-report' ); ?></button>
			</form>
			<hr class="wp-header-end">

			<?php if ( null !== $ran ) : ?>
				<div class="notice notice-<?php echo $ran ? 'warning' : 'success'; ?> is-dismissible"><p>
					<?php
					echo esc_html(
						$ran
							/* translators: 1: alerts, 2: versions checked. */
							? sprintf( __( '%1$d new alert(s) found after checking %2$d versions. The team digest has been emailed.', 'site-audit-report' ), $ran, $chk )
							/* translators: %d: versions checked. */
							: sprintf( __( 'Checked %d versions. Nothing new since the last check.', 'site-audit-report' ), $chk )
					);
					?>
				</p></div>
			<?php endif; ?>
			<?php if ( ! $enabled ) : ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'The watch is turned off in Settings > Vulnerability watch.', 'site-audit-report' ); ?></p></div>
			<?php endif; ?>

			<p class="sar-guide__lead"><?php esc_html_e( 'Every day, the versions each scanned WordPress site showed are re-checked against newly published vulnerabilities and the CISA exploited list. No site is scanned again. When something new turns up, the exposed leads appear here, ready to contact.', 'site-audit-report' ); ?></p>

			<div class="sar-watch__stats">
				<div><b><?php echo (int) $watched['sites']; ?></b><span><?php esc_html_e( 'Sites watched', 'site-audit-report' ); ?></span></div>
				<div><b><?php echo (int) $watched['items']; ?></b><span><?php esc_html_e( 'Versions watched', 'site-audit-report' ); ?></span></div>
				<div class="<?php echo $counts['new'] ? 'is-hot' : ''; ?>"><b><?php echo (int) $counts['new']; ?></b><span><?php esc_html_e( 'New alerts', 'site-audit-report' ); ?></span></div>
				<div><b><?php echo ! empty( $last['at'] ) ? esc_html( human_time_diff( (int) $last['at'] ) ) : '—'; ?></b><span><?php echo ! empty( $last['at'] ) ? esc_html__( 'Since last check', 'site-audit-report' ) : esc_html__( 'Not run yet', 'site-audit-report' ); ?></span></div>
			</div>

			<ul class="subsubsub">
				<?php
				$tabs = array(
					'new'       => array( __( 'New', 'site-audit-report' ), $counts['new'] ),
					'contacted' => array( __( 'Contacted', 'site-audit-report' ), $counts['contacted'] ),
					'dismissed' => array( __( 'Dismissed', 'site-audit-report' ), $counts['dismissed'] ),
					'all'       => array( __( 'All', 'site-audit-report' ), array_sum( $counts ) ),
				);
				$i    = 0;
				foreach ( $tabs as $key => $tab ) :
					?>
					<li><a href="<?php echo esc_url( add_query_arg( 'view', $key, $base ) ); ?>" <?php echo $view === $key ? 'class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $tab[0] ); ?> <span class="count">(<?php echo (int) $tab[1]; ?>)</span></a><?php echo ++$i < count( $tabs ) ? ' |' : ''; ?></li>
				<?php endforeach; ?>
			</ul>
			<br class="clear">

			<?php if ( ! $groups ) : ?>
				<div class="sar-card sar-watch__empty">
					<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
					<div>
						<h2><?php echo 'new' === $view ? esc_html__( 'No new alerts. Your leads are as safe as when you scanned them.', 'site-audit-report' ) : esc_html__( 'Nothing here.', 'site-audit-report' ); ?></h2>
						<p><?php esc_html_e( 'Alerts appear when a vulnerability is published for a version one of your scanned sites was running. Each completed WordPress audit or security check is added to the watch automatically.', 'site-audit-report' ); ?></p>
					</div>
				</div>
			<?php endif; ?>

			<?php foreach ( $groups as $group ) : ?>
				<?php
				$a   = $group['a'];
				$ids = array_map( static fn( $r ) => (int) $r['id'], $group['rows'] );
				?>
				<section class="sar-card sar-alert sar-alert--<?php echo esc_attr( $a['severity'] ); ?>">
					<header class="sar-alert__head">
						<div>
							<p class="sar-alert__tags">
								<span class="sar-sev sar-sev--<?php echo esc_attr( $a['severity'] ); ?>"><?php echo esc_html( strtoupper( $a['severity'] ) . ( null !== $a['score'] ? ' ' . $a['score'] : '' ) ); ?></span>
								<?php if ( (int) $a['kev'] ) : ?>
									<span class="sar-sev sar-sev--kev"><?php esc_html_e( 'Exploited in the wild', 'site-audit-report' ); ?></span>
								<?php endif; ?>
								<span class="sar-reason"><?php echo 'kev' === $a['reason'] ? esc_html__( 'Newly added to the CISA exploited list', 'site-audit-report' ) : esc_html__( 'Newly published', 'site-audit-report' ); ?><?php echo $a['published'] ? ' · ' . esc_html( mysql2date( 'j M Y', $a['published'] ) ) : ''; ?></span>
							</p>
							<h2><?php echo esc_html( $a['vuln_name'] ); ?></h2>
							<p class="sar-alert__what">
								<strong><?php echo esc_html( $a['name'] ); ?></strong>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: type, 2: fix. */
										__( '(%1$s) · %2$s', 'site-audit-report' ),
										$a['type'],
										$a['fixed'] ? sprintf( /* translators: %s: version. */ __( 'fixed in %s', 'site-audit-report' ), $a['fixed'] ) : __( 'no fix released yet', 'site-audit-report' )
									)
								);
								?>
								<?php foreach ( array_filter( explode( ',', (string) $a['cves'] ) ) as $cve ) : ?>
									· <a href="<?php echo esc_url( 'https://www.cve.org/CVERecord?id=' . rawurlencode( $cve ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $cve ); ?></a>
								<?php endforeach; ?>
								<?php if ( $a['link'] ) : ?>
									· <a href="<?php echo esc_url( $a['link'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Advisory', 'site-audit-report' ); ?> ↗</a>
								<?php endif; ?>
							</p>
						</div>
						<div class="sar-alert__count"><b><?php echo count( $group['rows'] ); ?></b><span><?php echo esc_html( _n( 'site exposed', 'sites exposed', count( $group['rows'] ), 'site-audit-report' ) ); ?></span></div>
					</header>
					<table class="widefat striped">
						<thead><tr>
							<th><?php esc_html_e( 'Website', 'site-audit-report' ); ?></th>
							<th><?php esc_html_e( 'Version seen', 'site-audit-report' ); ?></th>
							<th><?php esc_html_e( 'Lead', 'site-audit-report' ); ?></th>
							<th><?php esc_html_e( 'Contact', 'site-audit-report' ); ?></th>
							<th><?php esc_html_e( 'Status', 'site-audit-report' ); ?></th>
							<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'site-audit-report' ); ?></span></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $group['rows'] as $r ) : ?>
								<tr>
									<td><a href="<?php echo esc_url( add_query_arg( 'audit', (int) $r['audit_id'], $audits ) ); ?>"><strong><?php echo esc_html( $r['domain'] ); ?></strong></a><br><span class="sar-source sar-source--<?php echo esc_attr( (string) $r['source'] ); ?>"><?php echo esc_html( Audits_Table::source_label( (string) $r['source'] ) ); ?></span></td>
									<td><code><?php echo esc_html( $r['version'] ); ?></code></td>
									<td><?php echo esc_html( $leads[ $r['lead_status'] ] ?? (string) $r['lead_status'] ); ?><?php echo $r['business_name'] ? '<br>' . esc_html( $r['business_name'] ) : ''; ?></td>
									<td>
										<?php if ( is_email( (string) $r['email'] ) ) : ?>
											<a href="<?php echo esc_url( 'mailto:' . $r['email'] . '?subject=' . rawurlencode( sprintf( 'Security issue on %s', $r['domain'] ) ) ); ?>"><?php echo esc_html( $r['email'] ); ?></a>
											<?php if ( $r['lead_emailed_at'] ) : ?><br><small><?php esc_html_e( 'Notice emailed', 'site-audit-report' ); ?></small><?php endif; ?>
										<?php else : ?>
											<em><?php esc_html_e( 'No contact details', 'site-audit-report' ); ?></em>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( ucfirst( $r['status'] ) ); ?></td>
									<td class="sar-alert__actions">
										<?php
										if ( 'contacted' !== $r['status'] ) {
											self::status_button( array( (int) $r['id'] ), 'contacted', __( 'Contacted', 'site-audit-report' ), $view, 'button button-small' );
										}
										if ( 'dismissed' !== $r['status'] ) {
											self::status_button( array( (int) $r['id'] ), 'dismissed', __( 'Dismiss', 'site-audit-report' ), $view, 'button-link' );
										} else {
											self::status_button( array( (int) $r['id'] ), 'new', __( 'Restore', 'site-audit-report' ), $view, 'button-link' );
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( 'new' === $view && count( $ids ) > 1 ) : ?>
						<div class="sar-alert__bulk"><?php self::status_button( $ids, 'contacted', __( 'Mark all as contacted', 'site-audit-report' ), $view ); ?></div>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>

			<p class="description"><?php esc_html_e( 'Versions are as seen at each site\'s last scan; some sites may have updated since. Re-run the audit to confirm before you call.', 'site-audit-report' ); ?></p>
		</div>
		<?php
	}
}
