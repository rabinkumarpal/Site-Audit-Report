<?php
/**
 * Proof pages screen.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report\Admin;

use Site_Audit_Report\Proofs;

defined( 'ABSPATH' ) || exit;

/**
 * Proof pages screen.
 */
final class Proofs_Page {

	const SLUG = 'site-audit-report-proofs';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( self::class, 'menu' ), 15 );
		add_action( 'admin_post_sar_proof_create', array( self::class, 'handle_create' ) );
		add_action( 'admin_post_sar_proof_action', array( self::class, 'handle_action' ) );
		add_action(
			'admin_enqueue_scripts',
			static function ( $hook ) {
				if ( false !== strpos( $hook, self::SLUG ) ) {
					wp_enqueue_script( 'site-audit-admin-guide', SAR_URL . 'assets/js/admin-guide.js', array(), SAR_VERSION, true );
				}
			}
		);
	}

	/**
	 * Adds the submenu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_submenu_page( Admin::SLUG, __( 'Proof pages', 'site-audit-report' ), __( 'Proof pages', 'site-audit-report' ), Admin::capability(), self::SLUG, array( self::class, 'render' ), 5 );
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
	 * Creates a proof page.
	 *
	 * @return void
	 */
	public static function handle_create() {
		self::guard( 'sar_proof_create' );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised in Proofs::create() or validated by pattern.
		$before = isset( $_POST['before'] ) ? sanitize_key( wp_unslash( $_POST['before'] ) ) : '';
		$after  = isset( $_POST['after'] ) ? sanitize_key( wp_unslash( $_POST['after'] ) ) : '';
		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		if ( empty( $_POST['consent'] ) ) {
			self::back( array( 'domain' => $domain, 'msg' => 'consent' ) );
		}
		$proof = Proofs::create(
			$before,
			$after,
			array(
				'title'        => isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '',
				'client_label' => isset( $_POST['client_label'] ) ? wp_unslash( $_POST['client_label'] ) : '',
				'anonymize'    => ! empty( $_POST['anonymize'] ),
				'quote'        => isset( $_POST['quote'] ) ? wp_unslash( $_POST['quote'] ) : '',
				'quote_by'     => isset( $_POST['quote_by'] ) ? wp_unslash( $_POST['quote_by'] ) : '',
				'indexable'    => ! empty( $_POST['indexable'] ),
			)
		);
		// phpcs:enable
		if ( is_wp_error( $proof ) ) {
			self::back( array( 'domain' => $domain, 'msg' => 'error', 'err' => rawurlencode( $proof->get_error_message() ) ) );
		}
		self::back( array( 'msg' => 'created', 'p' => (int) $proof['id'] ) );
	}

	/**
	 * Publish, unpublish or delete.
	 *
	 * @return void
	 */
	public static function handle_action() {
		self::guard( 'sar_proof_action' );
		$id = isset( $_POST['p'] ) ? absint( $_POST['p'] ) : 0;
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'delete' === $do ) {
			Proofs::delete( $id );
		} elseif ( in_array( $do, array( 'publish', 'unpublish' ), true ) ) {
			Proofs::update( $id, array( 'active' => 'publish' === $do ? 1 : 0 ) );
		}
		self::back( array( 'msg' => $do, 'p' => $id ) );
	}

	/**
	 * One-button form.
	 *
	 * @param int    $id      Proof.
	 * @param string $do      Action.
	 * @param string $label   Text.
	 * @param string $class   Class.
	 * @param string $confirm Confirmation.
	 * @return void
	 */
	private static function button( $id, $do, $label, $class = 'button', $confirm = '' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sar-inline-form"<?php echo $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : ''; ?>>
			<input type="hidden" name="action" value="sar_proof_action">
			<input type="hidden" name="p" value="<?php echo (int) $id; ?>">
			<input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>">
			<?php wp_nonce_field( 'sar_proof_action' ); ?>
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
		$msg    = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		$err    = isset( $_GET['err'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['err'] ) ) ) : '';
		$domain = isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( $_GET['domain'] ) ) : '';
		$focus  = isset( $_GET['p'] ) ? absint( $_GET['p'] ) : 0;
		// phpcs:enable
		$eligible = Proofs::eligible_domains();
		$snaps    = '' !== $domain ? Proofs::snapshots( $domain ) : array();
		$proofs   = Proofs::all();
		$notices  = array(
			'created'   => array( 'success', __( 'Proof page created. Copy the link below to share it.', 'site-audit-report' ) ),
			'publish'   => array( 'success', __( 'Proof page published.', 'site-audit-report' ) ),
			'unpublish' => array( 'info', __( 'Proof page unpublished. Its link now shows "not found" to visitors.', 'site-audit-report' ) ),
			'delete'    => array( 'info', __( 'Proof page deleted.', 'site-audit-report' ) ),
			'consent'   => array( 'error', __( 'Please confirm the client agreed to publish this.', 'site-audit-report' ) ),
			'error'     => array( 'error', $err ),
		);
		$preview  = null;
		if ( count( $snaps ) >= 2 ) {
			$b       = Proofs::load( $snaps[0]['ref'] );
			$a       = Proofs::load( $snaps[ count( $snaps ) - 1 ]['ref'] );
			$preview = $b && $a ? Proofs::compare( $b['result'], $a['result'] ) : null;
		}
		?>
		<div class="wrap sar-admin sar-proofs">
			<h1><?php esc_html_e( 'Proof pages', 'site-audit-report' ); ?></h1>
			<?php if ( isset( $notices[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notices[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $msg ][1] ); ?></p></div>
			<?php endif; ?>
			<p class="sar-guide__lead"><?php esc_html_e( 'Turn two audits of the same site into a public before-and-after case study: the score change, every area that improved and every issue fixed. The numbers are frozen when you create it. Only improvements are shown publicly, and you can hide the website address.', 'site-audit-report' ); ?></p>

			<div class="sar-card sar-proofs__new">
				<h2><?php esc_html_e( 'Create a proof page', 'site-audit-report' ); ?></h2>
				<?php if ( ! $eligible ) : ?>
					<p><?php esc_html_e( 'You need two audits of the same website. Open an audit and use "Re-run" after the work is done: the plugin keeps the earlier result as the "before".', 'site-audit-report' ); ?></p>
				<?php else : ?>
					<form method="get" class="sar-proofs__pick">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
						<label><span><?php esc_html_e( '1. Website', 'site-audit-report' ); ?></span>
							<select name="domain" onchange="this.form.submit()">
								<option value=""><?php esc_html_e( '— choose —', 'site-audit-report' ); ?></option>
								<?php foreach ( $eligible as $d => $n ) : ?>
									<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $domain, $d ); ?>><?php echo esc_html( sprintf( /* translators: 1: domain, 2: results. */ __( '%1$s (%2$d results)', 'site-audit-report' ), $d, $n ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<noscript><?php submit_button( __( 'Choose', 'site-audit-report' ), 'secondary', '', false ); ?></noscript>
					</form>

					<?php if ( count( $snaps ) >= 2 ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sar-badges__form sar-proofs__form">
							<input type="hidden" name="action" value="sar_proof_create">
							<input type="hidden" name="domain" value="<?php echo esc_attr( $domain ); ?>">
							<?php wp_nonce_field( 'sar_proof_create' ); ?>
							<div class="sar-proofs__pair">
								<?php foreach ( array( 'before' => array( __( '2. Before', 'site-audit-report' ), 0 ), 'after' => array( __( '3. After', 'site-audit-report' ), count( $snaps ) - 1 ) ) as $field => $meta ) : ?>
									<label><span><?php echo esc_html( $meta[0] ); ?></span>
										<select name="<?php echo esc_attr( $field ); ?>">
											<?php foreach ( $snaps as $i => $snap ) : ?>
												<option value="<?php echo esc_attr( $snap['ref'] ); ?>" <?php selected( $i, $meta[1] ); ?>><?php echo esc_html( mysql2date( 'j M Y, H:i', get_date_from_gmt( $snap['at'] ) ) . ' · ' . ( null === $snap['score'] ? __( 'no score', 'site-audit-report' ) : sprintf( /* translators: %d: score. */ __( 'score %d', 'site-audit-report' ), $snap['score'] ) ) . ' · ' . Audits_Table::source_label( $snap['source'] ) ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
								<?php endforeach; ?>
							</div>
							<?php if ( $preview ) : ?>
								<div class="sar-proofs__preview">
									<strong><?php esc_html_e( 'Preview (oldest vs newest):', 'site-audit-report' ); ?></strong>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: before, 2: after, 3: fixed, 4: regressions, 5: open. */
											__( 'score %1$s → %2$s · %3$d fixed · %4$d got worse (private) · %5$d still open (private)', 'site-audit-report' ),
											null === $preview['before']['score'] ? '—' : $preview['before']['score'],
											null === $preview['after']['score'] ? '—' : $preview['after']['score'],
											count( $preview['fixed'] ),
											count( $preview['regressions'] ),
											$preview['open']
										)
									);
									?>
									<?php if ( $preview['regressions'] ) : ?>
										<br><small><?php echo esc_html( __( 'Got worse:', 'site-audit-report' ) . ' ' . implode( ', ', array_slice( $preview['regressions'], 0, 8 ) ) ); ?></small>
									<?php endif; ?>
								</div>
							<?php endif; ?>
							<label><span><?php esc_html_e( 'Title (optional)', 'site-audit-report' ); ?></span><input type="text" name="title" class="regular-text" placeholder="<?php esc_attr_e( 'Defaults to “Client name: before and after”', 'site-audit-report' ); ?>"></label>
							<label><span><?php esc_html_e( 'Client name shown (optional)', 'site-audit-report' ); ?></span><input type="text" name="client_label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. A dental clinic in Kolkata', 'site-audit-report' ); ?>"></label>
							<label class="sar-check-row"><input type="checkbox" name="anonymize" value="1"> <?php esc_html_e( 'Hide the website address (show only the client name above)', 'site-audit-report' ); ?></label>
							<label><span><?php esc_html_e( 'Client quote (optional)', 'site-audit-report' ); ?></span><textarea name="quote" rows="3" class="large-text"></textarea></label>
							<label><span><?php esc_html_e( 'Quote by (optional)', 'site-audit-report' ); ?></span><input type="text" name="quote_by" class="regular-text" placeholder="<?php esc_attr_e( 'Name, role', 'site-audit-report' ); ?>"></label>
							<label class="sar-check-row"><input type="checkbox" name="indexable" value="1"> <?php esc_html_e( 'Let search engines index this page', 'site-audit-report' ); ?></label>
							<label class="sar-check-row sar-check-row--must"><input type="checkbox" name="consent" value="1" required> <?php esc_html_e( 'The client agreed to publish this case study', 'site-audit-report' ); ?></label>
							<?php submit_button( __( 'Create proof page', 'site-audit-report' ), 'primary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<?php foreach ( $proofs as $p ) : ?>
				<?php
				$d   = (array) json_decode( (string) $p['data'], true );
				$url = Proofs::url( (string) $p['token'] );
				?>
				<section class="sar-card sar-proof<?php echo $focus === (int) $p['id'] ? ' is-focus' : ''; ?><?php echo (int) $p['active'] ? '' : ' is-off'; ?>">
					<div class="sar-badge__top">
						<div>
							<h2><?php echo esc_html( '' !== (string) $p['title'] ? $p['title'] : Proofs::display_name( $p ) ); ?><?php if ( ! (int) $p['active'] ) : ?> <span class="sar-sev"><?php esc_html_e( 'Unpublished', 'site-audit-report' ); ?></span><?php endif; ?></h2>
							<p class="sar-badge__meta">
								<?php echo esc_html( $p['domain'] ); ?><?php echo (int) $p['anonymize'] ? ' · ' . esc_html__( 'address hidden', 'site-audit-report' ) : ''; ?>
								· <?php echo esc_html( sprintf( /* translators: %d: views. */ _n( '%d view', '%d views', (int) $p['views'], 'site-audit-report' ), (int) $p['views'] ) ); ?>
								· <?php echo (int) $p['indexable'] ? esc_html__( 'indexable', 'site-audit-report' ) : esc_html__( 'not indexed', 'site-audit-report' ); ?>
							</p>
						</div>
						<div class="sar-proof__delta">
							<span><?php echo esc_html( null === ( $d['before']['score'] ?? null ) ? '—' : (string) $d['before']['score'] ); ?></span>
							<span aria-hidden="true">→</span>
							<b><?php echo esc_html( null === ( $d['after']['score'] ?? null ) ? '—' : (string) $d['after']['score'] ); ?></b>
							<small><?php echo esc_html( sprintf( /* translators: %d: fixed. */ __( '%d fixed', 'site-audit-report' ), count( (array) ( $d['fixed'] ?? array() ) ) ) ); ?></small>
						</div>
					</div>
					<div class="sar-code"><code><?php echo esc_html( $url ); ?></code><button type="button" class="button button-small" data-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copy link', 'site-audit-report' ); ?></button></div>
					<div class="sar-admin-actions">
						<a class="button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'site-audit-report' ); ?> ↗</a>
						<?php
						self::button( (int) $p['id'], (int) $p['active'] ? 'unpublish' : 'publish', (int) $p['active'] ? __( 'Unpublish', 'site-audit-report' ) : __( 'Publish', 'site-audit-report' ) );
						self::button( (int) $p['id'], 'delete', __( 'Delete', 'site-audit-report' ), 'button sar-link-danger', __( 'Delete this proof page? Its link will stop working.', 'site-audit-report' ) );
						?>
					</div>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
