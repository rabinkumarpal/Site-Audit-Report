<?php
/**
 * Admin area: audit list, audit detail, actions, CSV export and settings.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report\Admin;

use Site_Audit_Report\Audits;
use Site_Audit_Report\Plugin;
use Site_Audit_Report\Queue;
use Site_Audit_Report\Report_Renderer;
use Site_Audit_Report\Report_Route;
use Site_Audit_Report\Settings;
use Site_Audit_Report\Url_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens.
 */
final class Admin {

	const SLUG          = 'site-audit-report';
	const SETTINGS_SLUG = 'site-audit-report-settings';
	const GROUP         = 'sar_settings_group';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_notices', array( self::class, 'requirements_notice' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( self::class, 'capability' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SAR_FILE ), array( self::class, 'action_links' ) );

		foreach ( array( 'rerun', 'delete', 'save_lead', 'create', 'export', 'bulk' ) as $action ) {
			add_action( 'admin_post_sar_' . $action, array( self::class, 'handle_' . $action ) );
		}
	}

	/**
	 * The capability that gives access. Filter it to open the tool to a wider team.
	 *
	 * @return string
	 */
	public static function capability() {
		return (string) apply_filters( 'sar_capability', 'manage_options' );
	}

	/**
	 * Adds the menu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page( __( 'Site Audits', 'site-audit-report' ), __( 'Site Audits', 'site-audit-report' ), self::capability(), self::SLUG, array( self::class, 'render_audits' ), 'dashicons-chart-area', 58 );
		add_submenu_page( self::SLUG, __( 'Audits and leads', 'site-audit-report' ), __( 'Audits and leads', 'site-audit-report' ), self::capability(), self::SLUG, array( self::class, 'render_audits' ) );
		add_submenu_page( self::SLUG, __( 'Audit settings', 'site-audit-report' ), __( 'Settings', 'site-audit-report' ), self::capability(), self::SETTINGS_SLUG, array( self::class, 'render_settings' ) );
	}

	/**
	 * Registers the settings option.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
			)
		);
	}

	/**
	 * Loads assets on the plugin's own screens only.
	 *
	 * @param string $hook Screen.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'site-audit-report', SAR_URL . 'assets/css/report.css', array(), SAR_VERSION );
		wp_enqueue_style( 'site-audit-admin', SAR_URL . 'assets/css/admin.css', array(), SAR_VERSION );
		if ( false !== strpos( $hook, self::SETTINGS_SLUG ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'site-audit-admin', SAR_URL . 'assets/js/admin.js', array(), SAR_VERSION, true );
		}
	}

	/**
	 * Warns when the server cannot run scans.
	 *
	 * @return void
	 */
	public static function requirements_notice() {
		$missing = Plugin::missing_requirements();
		if ( $missing && current_user_can( self::capability() ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: PHP extension names. */
						__( 'Site Audit Report cannot scan websites until these PHP extensions are enabled: %s.', 'site-audit-report' ),
						implode( ', ', $missing )
					)
				)
			);
		}
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ) . '">' . esc_html__( 'Settings', 'site-audit-report' ) . '</a>' );
		return $links;
	}

	/**
	 * Audit list or, with ?audit=ID, one audit.
	 *
	 * @return void
	 */
	public static function render_audits() {
		self::require_access();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen selection.
		$id     = isset( $_GET['audit'] ) ? absint( $_GET['audit'] ) : 0;
		$notice = isset( $_GET['sar_notice'] ) ? sanitize_key( wp_unslash( $_GET['sar_notice'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap sar-admin">';
		self::notice( $notice );

		if ( $id ) {
			$audit = Audits::get( $id );
			if ( $audit ) {
				self::render_detail( $audit );
			} else {
				echo '<h1>' . esc_html__( 'Audit not found', 'site-audit-report' ) . '</h1>';
			}
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	/**
	 * The list screen.
	 *
	 * @return void
	 */
	private static function render_list() {
		$table = new Audits_Table();
		$table->prepare_items();

		$export = wp_nonce_url( admin_url( 'admin-post.php?action=sar_export' ), 'sar_export' );
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Audits and leads', 'site-audit-report' ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'site-audit-report' ); ?></a>
		<hr class="wp-header-end">

		<details class="sar-admin-new">
			<summary><?php esc_html_e( 'Run a new audit', 'site-audit-report' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sar_create">
				<?php wp_nonce_field( 'sar_create' ); ?>
				<p><label><?php esc_html_e( 'Website address', 'site-audit-report' ); ?> <input type="text" name="sar_url" required class="regular-text" placeholder="example.com"></label></p>
				<p><label><?php esc_html_e( 'Business name', 'site-audit-report' ); ?> <input type="text" name="sar_business" class="regular-text"></label></p>
				<p class="description"><?php esc_html_e( 'Team audits skip the public rate limits. No email is sent.', 'site-audit-report' ); ?></p>
				<?php submit_button( __( 'Queue audit', 'site-audit-report' ), 'primary', 'submit', false ); ?>
			</form>
		</details>

		<?php $table->views(); ?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<?php $table->search_box( __( 'Search audits', 'site-audit-report' ), 'sar-search' ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sar_bulk">
			<?php
			wp_nonce_field( 'sar_bulk', 'sar_bulk_nonce' );
			$table->display();
			?>
		</form>
		<?php
	}

	/**
	 * The detail screen.
	 *
	 * @param array<string,mixed> $audit Audit.
	 * @return void
	 */
	private static function render_detail( array $audit ) {
		$id   = (int) $audit['id'];
		$back = admin_url( 'admin.php?page=' . self::SLUG );
		$link = Report_Route::url( (string) $audit['token'] );
		?>
		<h1 class="wp-heading-inline"><?php echo esc_html( (string) $audit['domain'] ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Back to audits', 'site-audit-report' ); ?></a>
		<hr class="wp-header-end">

		<div class="sar-admin-grid">
			<div class="sar-admin-panel">
				<h2><?php esc_html_e( 'Lead', 'site-audit-report' ); ?></h2>
				<dl class="sar-admin-dl">
					<dt><?php esc_html_e( 'Business', 'site-audit-report' ); ?></dt><dd><?php echo esc_html( '' !== (string) $audit['business_name'] ? (string) $audit['business_name'] : '—' ); ?></dd>
					<dt><?php esc_html_e( 'Email', 'site-audit-report' ); ?></dt>
					<dd>
						<?php if ( is_email( $audit['email'] ) ) : ?>
							<a href="<?php echo esc_url( 'mailto:' . $audit['email'] ); ?>"><?php echo esc_html( (string) $audit['email'] ); ?></a>
						<?php else : ?>
							—
						<?php endif; ?>
					</dd>
					<dt><?php esc_html_e( 'Website', 'site-audit-report' ); ?></dt><dd><a href="<?php echo esc_url( (string) $audit['site_url'], array( 'http', 'https' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $audit['site_url'] ); ?></a></dd>
					<dt><?php esc_html_e( 'Requested', 'site-audit-report' ); ?></dt><dd><?php echo esc_html( get_date_from_gmt( (string) $audit['created_at'] ) ); ?></dd>
					<dt><?php esc_html_e( 'Consent', 'site-audit-report' ); ?></dt><dd><?php echo esc_html( $audit['consent_at'] ? get_date_from_gmt( (string) $audit['consent_at'] ) . ': ' . $audit['consent_text'] : __( 'Created by the team, no consent recorded', 'site-audit-report' ) ); ?></dd>
					<?php if ( null !== $audit['previous_score'] ) : ?>
						<dt><?php esc_html_e( 'Score before re-run', 'site-audit-report' ); ?></dt><dd><?php echo esc_html( (string) (int) $audit['previous_score'] ); ?></dd>
					<?php endif; ?>
					<dt><?php esc_html_e( 'Private report link', 'site-audit-report' ); ?></dt><dd><input type="text" readonly class="large-text code" value="<?php echo esc_url( $link ); ?>" aria-label="<?php esc_attr_e( 'Private report link', 'site-audit-report' ); ?>"></dd>
				</dl>
				<p class="sar-admin-actions">
					<a class="button" href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open public report', 'site-audit-report' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sar_rerun&audit=' . $id ), 'sar_rerun_' . $id ) ); ?>"><?php esc_html_e( 'Re-run scan', 'site-audit-report' ); ?></a>
					<a class="button sar-link-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sar_delete&audit=' . $id ), 'sar_delete_' . $id ) ); ?>"><?php esc_html_e( 'Delete', 'site-audit-report' ); ?></a>
				</p>
			</div>

			<form class="sar-admin-panel" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<h2><?php esc_html_e( 'Notes', 'site-audit-report' ); ?></h2>
				<input type="hidden" name="action" value="sar_save_lead">
				<input type="hidden" name="audit" value="<?php echo esc_attr( (string) $id ); ?>">
				<?php wp_nonce_field( 'sar_save_lead_' . $id ); ?>
				<p>
					<label for="sar-lead-status"><?php esc_html_e( 'Lead status', 'site-audit-report' ); ?></label><br>
					<select id="sar-lead-status" name="lead_status">
						<?php foreach ( Audits_Table::lead_statuses() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $audit['lead_status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label for="sar-notes"><?php esc_html_e( 'Team notes (never shown on the report)', 'site-audit-report' ); ?></label><br>
					<textarea id="sar-notes" name="notes" rows="8" class="large-text"><?php echo esc_textarea( (string) $audit['notes'] ); ?></textarea>
				</p>
				<?php submit_button( __( 'Save', 'site-audit-report' ), 'primary', 'submit', false ); ?>
			</form>
		</div>

		<div class="sar-admin-report">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The renderer escapes every value it outputs.
			echo Report_Renderer::body( $audit, array( 'mode' => 'admin' ) );
			?>
		</div>
		<?php
	}

	/**
	 * Settings screen.
	 *
	 * @return void
	 */
	public static function render_settings() {
		self::require_access();
		$s    = Settings::get();
		$name = Settings::OPTION;

		$text   = static function ( $key, $label, $help = '', $type = 'text' ) use ( $s, $name ) {
			printf(
				'<tr><th scope="row"><label for="sar-%1$s">%2$s</label></th><td><input id="sar-%1$s" type="%6$s" name="%3$s[%1$s]" value="%4$s" class="regular-text">%5$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $name ),
				esc_attr( (string) $s[ $key ] ),
				$help ? '<p class="description">' . esc_html( $help ) . '</p>' : '',
				esc_attr( $type )
			);
		};
		$area   = static function ( $key, $label, $help = '' ) use ( $s, $name ) {
			printf(
				'<tr><th scope="row"><label for="sar-%1$s">%2$s</label></th><td><textarea id="sar-%1$s" name="%3$s[%1$s]" rows="3" class="large-text">%4$s</textarea>%5$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $name ),
				esc_textarea( (string) $s[ $key ] ),
				$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
			);
		};
		$number = static function ( $key, $label, $min, $max, $help = '' ) use ( $s, $name ) {
			printf(
				'<tr><th scope="row"><label for="sar-%1$s">%2$s</label></th><td><input id="sar-%1$s" type="number" min="%5$d" max="%6$d" name="%3$s[%1$s]" value="%4$d" class="small-text">%7$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $name ),
				(int) $s[ $key ],
				(int) $min,
				(int) $max,
				$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
			);
		};
		$secret = static function ( $key, $label, $help_html ) use ( $s, $name ) {
			$has = '' !== (string) $s[ $key ];
			printf(
				'<tr><th scope="row"><label for="sar-%1$s">%2$s</label></th><td><input id="sar-%1$s" type="password" name="%3$s[%1$s]" value="" class="regular-text" autocomplete="new-password" placeholder="%4$s">',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $name ),
				esc_attr( $has ? __( 'Key saved. Leave blank to keep it.', 'site-audit-report' ) : '' )
			);
			if ( $has ) {
				printf(
					'<br><label><input type="checkbox" name="%1$s[%2$s_clear]" value="1"> %3$s</label>',
					esc_attr( $name ),
					esc_attr( $key ),
					esc_html__( 'Remove this key', 'site-audit-report' )
				);
			}
			echo '<p class="description">' . wp_kses_post( $help_html ) . '</p></td></tr>';
		};
		?>
		<div class="wrap sar-admin">
			<h1><?php esc_html_e( 'Audit settings', 'site-audit-report' ); ?></h1>
			<?php settings_errors(); ?>
			<p>
				<?php
				printf(
					/* translators: %s: shortcode. */
					esc_html__( 'Add the form to any page with the "Site Audit Form" block or the shortcode %s.', 'site-audit-report' ),
					'<code>[site_audit_form]</code>'
				);
				?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'Branding', 'site-audit-report' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$text( 'brand_name', __( 'Name on reports', 'site-audit-report' ), __( 'Leave blank to use the site title.', 'site-audit-report' ) );
					?>
					<tr>
						<th scope="row"><label for="sar-logo_url"><?php esc_html_e( 'Logo address', 'site-audit-report' ); ?></label></th>
						<td>
							<input id="sar-logo_url" type="url" name="<?php echo esc_attr( $name ); ?>[logo_url]" value="<?php echo esc_attr( (string) $s['logo_url'] ); ?>" class="regular-text">
							<button type="button" class="button" id="sar-logo-pick"><?php esc_html_e( 'Choose image', 'site-audit-report' ); ?></button>
							<p class="description"><?php esc_html_e( 'Use an https address. Reports load the logo from here.', 'site-audit-report' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sar-accent_color"><?php esc_html_e( 'Accent colour', 'site-audit-report' ); ?></label></th>
						<td>
							<input id="sar-accent_color" type="color" name="<?php echo esc_attr( $name ); ?>[accent_color]" value="<?php echo esc_attr( (string) $s['accent_color'] ); ?>">
							<p class="description"><?php esc_html_e( 'Text colours adjust automatically so it stays readable.', 'site-audit-report' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Call to action on reports', 'site-audit-report' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$text( 'cta_heading', __( 'Heading', 'site-audit-report' ) );
					$area( 'cta_text', __( 'Text', 'site-audit-report' ) );
					$text( 'cta_label', __( 'Button text', 'site-audit-report' ) );
					$text( 'cta_url', __( 'Button link', 'site-audit-report' ), __( 'The call to action appears only when both the button text and link are set.', 'site-audit-report' ), 'url' );
					?>
				</table>

				<h2><?php esc_html_e( 'Form', 'site-audit-report' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$text( 'form_heading', __( 'Heading', 'site-audit-report' ) );
					$area( 'form_intro', __( 'Introduction', 'site-audit-report' ) );
					$text( 'form_button', __( 'Button text', 'site-audit-report' ) );
					$area( 'consent_text', __( 'Consent line', 'site-audit-report' ), __( 'Leave blank to use the default wording. Each lead\'s record keeps the exact wording they agreed to.', 'site-audit-report' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Google API keys (optional)', 'site-audit-report' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$secret(
						'psi_api_key',
						__( 'PageSpeed Insights key', 'site-audit-report' ),
						sprintf(
							/* translators: %s: link to Google's guide. */
							__( 'Without a key the speed tests use Google\'s small shared quota and may be marked "not checked". %s', 'site-audit-report' ),
							'<a href="https://developers.google.com/speed/docs/insights/v5/get-started" target="_blank" rel="noopener">' . __( 'Get a key', 'site-audit-report' ) . '</a>'
						)
					);
					$secret(
						'places_api_key',
						__( 'Places API key', 'site-audit-report' ),
						sprintf(
							/* translators: %s: link to Google's guide. */
							__( 'Without a key the rating, reviews and name, address and phone checks are marked "not checked". Enable "Places API (New)" for the key. Google may bill for this API. %s', 'site-audit-report' ),
							'<a href="https://developers.google.com/maps/documentation/places/web-service/get-api-key" target="_blank" rel="noopener">' . __( 'Get a key', 'site-audit-report' ) . '</a>'
						)
					);
					?>
				</table>

				<h2><?php esc_html_e( 'Limits', 'site-audit-report' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$number( 'limit_ip_hour', __( 'Audits per visitor per hour', 'site-audit-report' ), 1, 1000 );
					$number( 'limit_domain_day', __( 'Audits per website per day', 'site-audit-report' ), 1, 1000 );
					$number( 'limit_email_day', __( 'Audits per email per day', 'site-audit-report' ), 1, 1000 );
					$number( 'limit_global_day', __( 'Total public audits per day', 'site-audit-report' ), 1, 100000 );
					$number( 'max_pages', __( 'Pages read per scan', 'site-audit-report' ), 1, 10 );
					$number( 'max_links', __( 'Links checked per scan', 'site-audit-report' ), 0, 50, __( 'Set to 0 to turn off link checking.', 'site-audit-report' ) );
					?>
					<tr>
						<th scope="row"><label for="sar-ip_header"><?php esc_html_e( 'Visitor IP source', 'site-audit-report' ); ?></label></th>
						<td>
							<select id="sar-ip_header" name="<?php echo esc_attr( $name ); ?>[ip_header]">
								<?php
								foreach (
									array(
										''               => __( 'Direct connection (safest)', 'site-audit-report' ),
										'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP (Cloudflare)',
										'HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For',
										'HTTP_X_REAL_IP' => 'X-Real-IP',
									) as $value => $label
								) {
									printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $s['ip_header'], $value, false ), esc_html( $label ) );
								}
								?>
							</select>
							<p class="description"><?php esc_html_e( 'Choose a header only if your site is really behind that proxy. Otherwise visitors can fake it and avoid the per-visitor limit.', 'site-audit-report' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Data and email', 'site-audit-report' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$number( 'retention_days', __( 'Delete audits after (days)', 'site-audit-report' ), 0, 3650, __( 'Audits and leads older than this are deleted daily. Set to 0 to keep them until you delete them.', 'site-audit-report' ) );
					?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email the report', 'site-audit-report' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[email_lead]" value="1" <?php checked( ! empty( $s['email_lead'] ) ); ?>> <?php esc_html_e( 'Send the private report link to the person who asked for it', 'site-audit-report' ); ?></label></td>
					</tr>
					<?php
					$text( 'notify_email', __( 'New lead alerts', 'site-audit-report' ), __( 'Optional. Each finished audit from the form is sent to this address.', 'site-audit-report' ), 'email' );
					?>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Re-runs a scan.
	 *
	 * @return void
	 */
	public static function handle_rerun() {
		$id    = self::guarded_id( 'sar_rerun_' );
		$audit = Audits::get( $id );
		if ( $audit && ! in_array( $audit['status'], array( 'queued', 'running' ), true ) ) {
			Audits::update(
				$id,
				array(
					'status'         => 'queued',
					'stage'          => '',
					'progress'       => 0,
					'state'          => null,
					'result'         => null,
					'previous_score' => $audit['score'],
					'score'          => null,
					'error'          => '',
					'attempts'       => 0,
					'locked_at'      => null,
					'completed_at'   => null,
				)
			);
			Queue::dispatch();
		}
		self::redirect(
			array(
				'audit'      => $id,
				'sar_notice' => 'rerun',
			)
		);
	}

	/**
	 * Deletes an audit.
	 *
	 * @return void
	 */
	public static function handle_delete() {
		$id = self::guarded_id( 'sar_delete_' );
		Audits::delete( $id );
		self::redirect( array( 'sar_notice' => 'deleted' ) );
	}

	/**
	 * Saves notes and lead status.
	 *
	 * @return void
	 */
	public static function handle_save_lead() {
		self::require_access();
		$id = isset( $_POST['audit'] ) ? absint( $_POST['audit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified on the next line, whose action includes this ID.
		check_admin_referer( 'sar_save_lead_' . $id );

		$status = isset( $_POST['lead_status'] ) ? sanitize_key( wp_unslash( $_POST['lead_status'] ) ) : 'new';
		Audits::update(
			$id,
			array(
				'lead_status' => array_key_exists( $status, Audits_Table::lead_statuses() ) ? $status : 'new',
				'notes'       => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
			)
		);
		self::redirect(
			array(
				'audit'      => $id,
				'sar_notice' => 'saved',
			)
		);
	}

	/**
	 * Creates a team audit.
	 *
	 * @return void
	 */
	public static function handle_create() {
		self::require_access();
		check_admin_referer( 'sar_create' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by Url_Guard.
		$raw = isset( $_POST['sar_url'] ) ? (string) wp_unslash( $_POST['sar_url'] ) : '';
		$url = Url_Guard::normalize_input( $raw );
		$url = is_wp_error( $url ) ? $url : Url_Guard::validate( $url );
		if ( is_wp_error( $url ) ) {
			self::redirect( array( 'sar_notice' => 'invalid_url' ) );
		}

		$audit = Audits::create(
			array(
				'site_url'      => $url['url'],
				'domain'        => Url_Guard::domain_key( $url['url'] ),
				'business_name' => isset( $_POST['sar_business'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['sar_business'] ) ), 0, 191 ) : '',
				'source'        => 'admin',
			)
		);
		Queue::dispatch();
		self::redirect(
			array(
				'audit'      => $audit ? (int) $audit['id'] : 0,
				'sar_notice' => 'created',
			)
		);
	}

	/**
	 * Bulk delete.
	 *
	 * @return void
	 */
	public static function handle_bulk() {
		self::require_access();
		check_admin_referer( 'sar_bulk', 'sar_bulk_nonce' );

		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		if ( '' === $action || '-1' === $action ) {
			$action = isset( $_POST['bulk_action2'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action2'] ) ) : '';
		}
		$ids = isset( $_POST['audit_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['audit_ids'] ) ) : array();

		if ( 'delete' === $action ) {
			foreach ( $ids as $id ) {
				Audits::delete( $id );
			}
			self::redirect( array( 'sar_notice' => 'deleted' ) );
		}
		self::redirect( array() );
	}

	/**
	 * Streams the CSV export.
	 *
	 * @return void
	 */
	public static function handle_export() {
		self::require_access();
		check_admin_referer( 'sar_export' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="site-audits-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Output stream.
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Requested (UTC)', 'Website', 'Domain', 'Business', 'Email', 'Source', 'Scan status', 'Score', 'Lead status', 'Consent (UTC)', 'Notes', 'Report link' ), ',', '"', '\\' );

		$offset = 0;
		do {
			$rows    = Audits::query(
				array(
					'limit'  => 500,
					'offset' => $offset,
				)
			);
			foreach ( $rows as $row ) {
				fputcsv(
					$out,
					array_map(
						array( self::class, 'csv_cell' ),
						array(
							$row['created_at'],
							$row['site_url'],
							$row['domain'],
							$row['business_name'],
							$row['email'],
							$row['source'],
							$row['status'],
							null === $row['score'] ? '' : (int) $row['score'],
							$row['lead_status'],
							(string) $row['consent_at'],
							(string) $row['notes'],
							Report_Route::url( (string) $row['token'] ),
						)
					),
					',',
					'"',
					'\\'
				);
			}
			$fetched = count( $rows );
			$offset += 500;
		} while ( 500 === $fetched );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Output stream.
		fclose( $out );
		exit;
	}

	/**
	 * Stops visitor-supplied text running as a formula when a CSV is opened in a spreadsheet.
	 *
	 * @param mixed $value Cell.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Stops the request unless the user has access.
	 *
	 * @return void
	 */
	private static function require_access() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'site-audit-report' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Checks access and the nonce for a GET action on one audit.
	 *
	 * @param string $prefix Nonce action prefix.
	 * @return int Audit ID.
	 */
	private static function guarded_id( $prefix ) {
		self::require_access();
		$id = isset( $_GET['audit'] ) ? absint( $_GET['audit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line, whose action includes this ID.
		check_admin_referer( $prefix . $id );
		return $id;
	}

	/**
	 * Redirects back to the audits screen.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return void
	 */
	private static function redirect( array $args ) {
		$args = array_merge( array( 'page' => self::SLUG ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Shows the result of the last action.
	 *
	 * @param string $code Code.
	 * @return void
	 */
	private static function notice( $code ) {
		$messages = array(
			'rerun'       => array( 'success', __( 'The scan is queued again. Refresh this page to see the result.', 'site-audit-report' ) ),
			'deleted'     => array( 'success', __( 'Deleted.', 'site-audit-report' ) ),
			'saved'       => array( 'success', __( 'Saved.', 'site-audit-report' ) ),
			'created'     => array( 'success', __( 'The audit is queued. Refresh this page to see the result.', 'site-audit-report' ) ),
			'invalid_url' => array( 'error', __( 'That address cannot be scanned. Please enter the public address of a website.', 'site-audit-report' ) ),
		);
		if ( isset( $messages[ $code ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ) );
		}
	}
}
