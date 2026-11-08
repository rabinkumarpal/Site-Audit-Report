<?php
/**
 * Extra admin screens: the SEO Inspector inside wp-admin, the Guide and the
 * dashboard widget.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report\Admin;

use Site_Audit_Report\Audits;
use Site_Audit_Report\Plugin;
use Site_Audit_Report\Seo_Inspector;
use Site_Audit_Report\Service_Bay;
use Site_Audit_Report\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Tools screens.
 */
final class Tools {

	const SEO_SLUG   = 'site-audit-report-seo';
	const GUIDE_SLUG = 'site-audit-report-guide';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( self::class, 'menu' ), 11 );
		add_action( 'wp_dashboard_setup', array( self::class, 'dashboard_setup' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/**
	 * Adds the submenu pages, after the list and before Settings.
	 *
	 * @return void
	 */
	public static function menu() {
		add_submenu_page( Admin::SLUG, __( 'SEO Inspector', 'site-audit-report' ), __( 'SEO Inspector', 'site-audit-report' ), Admin::capability(), self::SEO_SLUG, array( self::class, 'render_seo' ), 1 );
		add_submenu_page( Admin::SLUG, __( 'Site Audit Report guide', 'site-audit-report' ), __( 'Guide', 'site-audit-report' ), Admin::capability(), self::GUIDE_SLUG, array( self::class, 'render_guide' ) );
	}

	/**
	 * Loads the shared front-end styles on the tool screens and the dashboard.
	 *
	 * @param string $hook Screen.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( 'index.php' === $hook || false !== strpos( $hook, Admin::SLUG ) ) {
			wp_enqueue_style( 'site-audit-admin', SAR_URL . 'assets/css/admin.css', array(), SAR_VERSION );
		}
		if ( false !== strpos( $hook, self::GUIDE_SLUG ) ) {
			wp_enqueue_script( 'site-audit-admin-guide', SAR_URL . 'assets/js/admin-guide.js', array(), SAR_VERSION, true );
		}
	}

	/**
	 * Stops the screen for users without access.
	 *
	 * @return void
	 */
	private static function require_access() {
		if ( ! current_user_can( Admin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'site-audit-report' ), 403 );
		}
	}

	/* ------------------------------------------------------------------ SEO */

	/**
	 * The SEO Inspector, in compact mode.
	 *
	 * @return void
	 */
	public static function render_seo() {
		self::require_access();
		?>
		<div class="wrap sar-admin sar-admin-seo">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'SEO Inspector', 'site-audit-report' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::GUIDE_SLUG . '#seo' ) ); ?>"><?php esc_html_e( 'How it works', 'site-audit-report' ); ?></a>
			<hr class="wp-header-end">
			<p class="description"><?php esc_html_e( 'Check any single page: meta tags, headings, images, links and structured data. Results are not stored. Use "Queue a full audit" to add the site to Audits and leads.', 'site-audit-report' ); ?></p>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The view escapes its own output.
			echo Seo_Inspector::shortcode(
				array(
					'examples' => wp_parse_url( home_url(), PHP_URL_HOST ),
					'offset'   => '6',
					'compact'  => '1',
				)
			);
			?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- Guide */

	/**
	 * Published pages and templates that use a shortcode, including Bricks data.
	 *
	 * @param string $tag Shortcode tag.
	 * @return array<int,array{title:string,view:string,edit:string}>
	 */
	public static function pages_using( $tag ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '[' . $tag ) . '%';
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bricks_page_content_2'
				WHERE p.post_status = 'publish' AND p.post_type NOT IN ('revision','nav_menu_item') AND ( p.post_content LIKE %s OR m.meta_value LIKE %s ) LIMIT 10",
				$like,
				$like
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$out[] = array(
				'title' => get_the_title( (int) $id ),
				'view'  => (string) get_permalink( (int) $id ),
				'edit'  => (string) get_edit_post_link( (int) $id, 'raw' ),
			);
		}
		return $out;
	}

	/**
	 * Setup items with their state.
	 *
	 * @return array<int,array{ok:bool|null,label:string,text:string,url:string}>
	 */
	private static function checklist() {
		$s        = Settings::get();
		$settings = admin_url( 'admin.php?page=' . Admin::SETTINGS_SLUG );
		$missing  = Plugin::missing_requirements();
		$prices   = array_sum( array_map( static fn( $t ) => (int) $t['price'], Service_Bay::tools() ) );
		$cron     = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		return array(
			array(
				'ok'    => ! $missing,
				'label' => __( 'Server can scan', 'site-audit-report' ),
				/* translators: %s: PHP extensions. */
				'text'  => $missing ? sprintf( __( 'Enable these PHP extensions: %s.', 'site-audit-report' ), implode( ', ', $missing ) ) : __( 'The DOM and cURL extensions are available.', 'site-audit-report' ),
				'url'   => '',
			),
			array(
				'ok'    => '' !== (string) $s['brand_name'] || '' !== (string) $s['logo_url'],
				'label' => __( 'Branding', 'site-audit-report' ),
				'text'  => __( 'Your name, logo and accent colour appear on reports and emails.', 'site-audit-report' ),
				'url'   => $settings,
			),
			array(
				'ok'    => '' !== (string) $s['psi_api_key'],
				'label' => __( 'PageSpeed Insights key', 'site-audit-report' ),
				'text'  => __( 'Free. Without it the speed section of full audits is often "not checked".', 'site-audit-report' ),
				'url'   => $settings,
			),
			array(
				'ok'    => '' !== (string) $s['places_api_key'] ? true : null,
				'label' => __( 'Places API key (optional)', 'site-audit-report' ),
				'text'  => __( 'Adds Google rating, reviews and name/address/phone checks. Google may bill for it.', 'site-audit-report' ),
				'url'   => $settings,
			),
			array(
				'ok'    => is_email( (string) $s['notify_email'] ) ? true : false,
				'label' => __( 'Lead alerts email', 'site-audit-report' ),
				'text'  => is_email( (string) $s['notify_email'] )
					? __( 'New form leads and Security Check bookings are emailed here.', 'site-audit-report' )
					/* translators: %s: email. */
					: sprintf( __( 'Not set. Bookings go to the site admin email (%s) and form leads are not emailed.', 'site-audit-report' ), (string) get_option( 'admin_email' ) ),
				'url'   => $settings,
			),
			array(
				'ok'    => $prices > 0,
				'label' => __( 'Security Check prices', 'site-audit-report' ),
				/* translators: %s: currency. */
				'text'  => sprintf( __( 'Prices in %s fill the fix plan. Set any to 0 to show it as free.', 'site-audit-report' ), (string) $s['service_currency'] ),
				'url'   => $settings,
			),
			array(
				'ok'    => (bool) get_privacy_policy_url(),
				'label' => __( 'Privacy policy page', 'site-audit-report' ),
				'text'  => __( 'Linked from the consent boxes. Suggested wording is under Settings > Privacy > Policy Guide.', 'site-audit-report' ),
				'url'   => admin_url( 'options-privacy.php' ),
			),
			array(
				'ok'    => $cron ? null : true,
				'label' => __( 'Background scans', 'site-audit-report' ),
				'text'  => $cron
					? __( 'WP-Cron is off. Scans still start through a loopback request; add a real cron job that runs "wp site-audit-report run-queue" as a fallback.', 'site-audit-report' )
					: __( 'Scans start through a loopback request with WP-Cron as the fallback.', 'site-audit-report' ),
				'url'   => '',
			),
		);
	}

	/**
	 * The guide.
	 *
	 * @return void
	 */
	public static function render_guide() {
		self::require_access();
		$audits   = admin_url( 'admin.php?page=' . Admin::SLUG );
		$settings = admin_url( 'admin.php?page=' . Admin::SETTINGS_SLUG );
		$seo      = admin_url( 'admin.php?page=' . self::SEO_SLUG );

		$tools = array(
			array(
				'id'      => 'audit',
				'icon'    => 'dashicons-analytics',
				'name'    => __( 'Full website audit', 'site-audit-report' ),
				'tag'     => 'site_audit_form',
				'what'    => __( 'A lead form. Visitors enter their website, business name and email; a background scan checks on-page SEO, crawling and links, security, WordPress health, marketing presence, speed and local reputation, and emails a private report link.', 'site-audit-report' ),
				'attrs'   => array(
					'heading' => __( 'Form heading.', 'site-audit-report' ),
					'intro'   => __( 'Text under the heading.', 'site-audit-report' ),
					'button'  => __( 'Button label.', 'site-audit-report' ),
				),
				'results' => __( 'Audits and leads (source "Form"). Each row opens the full report, notes, lead status and re-run. Also available as the "Site Audit Form" block.', 'site-audit-report' ),
				'time'    => __( '1 to 3 minutes', 'site-audit-report' ),
				'stored'  => __( 'Yes, with the lead details', 'site-audit-report' ),
			),
			array(
				'id'      => 'bay',
				'icon'    => 'dashicons-shield',
				'name'    => __( 'WordPress Security Check', 'site-audit-report' ),
				'tag'     => 'site_audit_security',
				'what'    => __( 'Visitors enter only an address. A short inspection reads the WordPress core, theme and plugin versions the site shows, matches them against known vulnerabilities and the CISA exploited list, then pre-loads a priced fix plan they can book.', 'site-audit-report' ),
				'attrs'   => array(
					'examples' => __( 'Comma-separated example sites for the "Try" chips.', 'site-audit-report' ),
					'offset'   => __( 'Height in px of a fixed site header, so the sticky step rail clears it.', 'site-audit-report' ),
				),
				'results' => __( 'Audits and leads (source "Security Check"). Bookings set the lead to "Booked a service", put the chosen plan and phone in Notes, and email the lead alerts address.', 'site-audit-report' ),
				'time'    => __( '20 to 40 seconds', 'site-audit-report' ),
				'stored'  => __( 'Yes; contact details only after booking', 'site-audit-report' ),
			),
			array(
				'id'      => 'seo',
				'icon'    => 'dashicons-search',
				'name'    => __( 'SEO Inspector (single page)', 'site-audit-report' ),
				'tag'     => 'site_audit_seo',
				'what'    => __( 'An instant on-page report for any one URL in six tabs: Insights, Meta, Headers, Images, Links and Schema, with a score, filters, copy and CSV export. Add ?inspect=URL to the page address to run it straight away.', 'site-audit-report' ),
				'attrs'   => array(
					'examples'  => __( 'Example sites for the "Try" chips.', 'site-audit-report' ),
					'offset'    => __( 'Fixed header height in px.', 'site-audit-report' ),
					'cta_url'   => __( 'Optional call-to-action link under the report.', 'site-audit-report' ),
					'cta_label' => __( 'Its button text.', 'site-audit-report' ),
				),
				'results' => __( 'Not stored. Use it on the front end, or here in wp-admin under Site Audits > SEO Inspector, where "Queue a full audit" adds the site to Audits and leads.', 'site-audit-report' ),
				'time'    => __( '2 to 6 seconds', 'site-audit-report' ),
				'stored'  => __( 'No (cached 10 minutes)', 'site-audit-report' ),
			),
		);
		?>
		<div class="wrap sar-admin sar-guide">
			<h1><?php esc_html_e( 'Site Audit Report guide', 'site-audit-report' ); ?> <span class="sar-guide__ver">v<?php echo esc_html( SAR_VERSION ); ?></span></h1>
			<p class="sar-guide__lead"><?php esc_html_e( 'Three tools, one plugin. Put a tool on a page with its shortcode, and its results land in the places below.', 'site-audit-report' ); ?></p>

			<nav class="sar-guide__nav">
				<a href="#setup"><?php esc_html_e( 'Setup checklist', 'site-audit-report' ); ?></a>
				<a href="#tools"><?php esc_html_e( 'The three tools', 'site-audit-report' ); ?></a>
				<a href="#results"><?php esc_html_e( 'Where results go', 'site-audit-report' ); ?></a>
				<a href="#leads"><?php esc_html_e( 'Working your leads', 'site-audit-report' ); ?></a>
				<a href="#limits"><?php esc_html_e( 'Limits and privacy', 'site-audit-report' ); ?></a>
				<a href="#dev"><?php esc_html_e( 'For developers', 'site-audit-report' ); ?></a>
			</nav>

			<h2 id="setup"><?php esc_html_e( 'Setup checklist', 'site-audit-report' ); ?></h2>
			<div class="sar-card">
				<ul class="sar-check">
					<?php foreach ( self::checklist() as $item ) : ?>
						<?php $state = true === $item['ok'] ? 'ok' : ( null === $item['ok'] ? 'opt' : 'todo' ); ?>
						<li class="sar-check--<?php echo esc_attr( $state ); ?>">
							<span class="sar-check__icon" aria-hidden="true"><?php echo 'ok' === $state ? '✓' : ( 'opt' === $state ? '○' : '!' ); ?></span>
							<div>
								<strong><?php echo esc_html( $item['label'] ); ?></strong>
								<span class="screen-reader-text"><?php echo esc_html( 'ok' === $state ? __( 'Done', 'site-audit-report' ) : ( 'opt' === $state ? __( 'Optional', 'site-audit-report' ) : __( 'To do', 'site-audit-report' ) ) ); ?></span>
								<p><?php echo esc_html( $item['text'] ); ?></p>
							</div>
							<?php if ( $item['url'] && 'ok' !== $state ) : ?>
								<a class="button" href="<?php echo esc_url( $item['url'] ); ?>"><?php esc_html_e( 'Set up', 'site-audit-report' ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<h2 id="tools"><?php esc_html_e( 'The three tools', 'site-audit-report' ); ?></h2>
			<div class="sar-tools">
				<?php foreach ( $tools as $tool ) : ?>
					<?php $pages = 'site_audit_security' === $tool['tag'] ? array_merge( self::pages_using( 'site_audit_security' ), self::pages_using( 'site_audit_service' ) ) : self::pages_using( $tool['tag'] ); ?>
					<section class="sar-card sar-tool" id="<?php echo esc_attr( $tool['id'] ); ?>">
						<header class="sar-tool__head">
							<span class="dashicons <?php echo esc_attr( $tool['icon'] ); ?>" aria-hidden="true"></span>
							<h3><?php echo esc_html( $tool['name'] ); ?></h3>
						</header>
						<p><?php echo esc_html( $tool['what'] ); ?></p>
						<div class="sar-code">
							<code>[<?php echo esc_html( $tool['tag'] ); ?>]</code>
							<button type="button" class="button button-small" data-copy="[<?php echo esc_attr( $tool['tag'] ); ?>]"><?php esc_html_e( 'Copy', 'site-audit-report' ); ?></button>
						</div>
						<table class="widefat striped sar-attrs">
							<thead><tr><th><?php esc_html_e( 'Attribute', 'site-audit-report' ); ?></th><th><?php esc_html_e( 'What it does', 'site-audit-report' ); ?></th></tr></thead>
							<tbody>
								<?php foreach ( $tool['attrs'] as $attr => $desc ) : ?>
									<tr><td><code><?php echo esc_html( $attr ); ?>="…"</code></td><td><?php echo esc_html( $desc ); ?></td></tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<dl class="sar-admin-dl sar-tool__facts">
							<dt><?php esc_html_e( 'Takes', 'site-audit-report' ); ?></dt><dd><?php echo esc_html( $tool['time'] ); ?></dd>
							<dt><?php esc_html_e( 'Stored', 'site-audit-report' ); ?></dt><dd><?php echo esc_html( $tool['stored'] ); ?></dd>
							<dt><?php esc_html_e( 'Results', 'site-audit-report' ); ?></dt><dd><?php echo esc_html( $tool['results'] ); ?></dd>
							<dt><?php esc_html_e( 'Used on', 'site-audit-report' ); ?></dt>
							<dd>
								<?php if ( $pages ) : ?>
									<?php foreach ( $pages as $page ) : ?>
										<span class="sar-page"><a href="<?php echo esc_url( $page['view'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $page['title'] ); ?> ↗</a><?php if ( $page['edit'] ) : ?> · <a href="<?php echo esc_url( $page['edit'] ); ?>"><?php esc_html_e( 'edit', 'site-audit-report' ); ?></a><?php endif; ?></span>
									<?php endforeach; ?>
								<?php else : ?>
									<em><?php esc_html_e( 'Not on any published page yet.', 'site-audit-report' ); ?></em>
								<?php endif; ?>
							</dd>
						</dl>
						<?php if ( 'seo' === $tool['id'] ) : ?>
							<p><a class="button button-primary" href="<?php echo esc_url( $seo ); ?>"><?php esc_html_e( 'Open the SEO Inspector', 'site-audit-report' ); ?></a></p>
						<?php elseif ( 'bay' === $tool['id'] ) : ?>
							<p><a class="button" href="<?php echo esc_url( add_query_arg( 'source', 'service', $audits ) ); ?>"><?php esc_html_e( 'See security checks', 'site-audit-report' ); ?></a> <a class="button" href="<?php echo esc_url( add_query_arg( 'lead_status', 'booked', $audits ) ); ?>"><?php esc_html_e( 'See bookings', 'site-audit-report' ); ?></a></p>
						<?php else : ?>
							<p><a class="button" href="<?php echo esc_url( add_query_arg( 'source', 'form', $audits ) ); ?>"><?php esc_html_e( 'See form leads', 'site-audit-report' ); ?></a></p>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			</div>

			<h2 id="results"><?php esc_html_e( 'Where results go', 'site-audit-report' ); ?></h2>
			<div class="sar-card">
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'You want to…', 'site-audit-report' ); ?></th><th><?php esc_html_e( 'Go to', 'site-audit-report' ); ?></th></tr></thead>
					<tbody>
						<tr><td><?php esc_html_e( 'See every stored audit and lead', 'site-audit-report' ); ?></td><td><a href="<?php echo esc_url( $audits ); ?>"><?php esc_html_e( 'Site Audits > Audits and leads', 'site-audit-report' ); ?></a></td></tr>
						<tr><td><?php esc_html_e( 'Only security checks, form leads or audits your team ran', 'site-audit-report' ); ?></td><td><?php esc_html_e( 'Audits and leads > Source filter (or the Source column)', 'site-audit-report' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'See who booked a fix and what plan they picked', 'site-audit-report' ); ?></td><td><a href="<?php echo esc_url( add_query_arg( 'lead_status', 'booked', $audits ) ); ?>"><?php esc_html_e( 'Lead filter "Booked a service"', 'site-audit-report' ); ?></a> · <?php esc_html_e( 'open the row, then Notes', 'site-audit-report' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Open, download or re-run a report', 'site-audit-report' ); ?></td><td><?php esc_html_e( 'Click the website name; or hover a row for View / Re-run / Inspect SEO', 'site-audit-report' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'See which leads are exposed to a newly published vulnerability', 'site-audit-report' ); ?></td><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Watch::SLUG ) ); ?>"><?php esc_html_e( 'Site Audits > Vulnerability watch', 'site-audit-report' ); ?></a> · <?php esc_html_e( 'checked daily; the team gets an email digest', 'site-audit-report' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Give a client a self-checking "Secured by" badge for their site', 'site-audit-report' ); ?></td><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Badges_Page::SLUG ) ); ?>"><?php esc_html_e( 'Site Audits > Trust badges', 'site-audit-report' ); ?></a> · <?php esc_html_e( 'or "Create badge" on any audit row; re-checked weekly', 'site-audit-report' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Check one page\'s SEO right now', 'site-audit-report' ); ?></td><td><a href="<?php echo esc_url( $seo ); ?>"><?php esc_html_e( 'Site Audits > SEO Inspector', 'site-audit-report' ); ?></a></td></tr>
						<tr><td><?php esc_html_e( 'Run a full audit yourself (no rate limits, no email)', 'site-audit-report' ); ?></td><td><?php esc_html_e( 'Audits and leads > Run a new audit', 'site-audit-report' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Get everything into a spreadsheet', 'site-audit-report' ); ?></td><td><?php esc_html_e( 'Audits and leads > Export CSV', 'site-audit-report' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'A quick overview', 'site-audit-report' ); ?></td><td><a href="<?php echo esc_url( admin_url() ); ?>"><?php esc_html_e( 'Dashboard > Site Audits widget', 'site-audit-report' ); ?></a></td></tr>
					</tbody>
				</table>
			</div>

			<h2 id="leads"><?php esc_html_e( 'Working your leads', 'site-audit-report' ); ?></h2>
			<div class="sar-card sar-steps">
				<ol>
					<li><strong><?php esc_html_e( 'Triage daily.', 'site-audit-report' ); ?></strong> <?php esc_html_e( 'Filter Lead = "Booked a service" first; these people asked to be called. Then "New" form leads with low scores, which have the most to gain.', 'site-audit-report' ); ?></li>
					<li><strong><?php esc_html_e( 'Read before you call.', 'site-audit-report' ); ?></strong> <?php esc_html_e( 'Open the report and note the two or three highest-priority fixes. For Security Check leads, the risk report and chosen plan are in the row.', 'site-audit-report' ); ?></li>
					<li><strong><?php esc_html_e( 'Update the status.', 'site-audit-report' ); ?></strong> <?php esc_html_e( 'Move each lead through Contacted, Qualified, Won or Lost and keep notes on the row, so the team sees the same picture.', 'site-audit-report' ); ?></li>
					<li><strong><?php esc_html_e( 'Act on watch alerts.', 'site-audit-report' ); ?></strong> <?php esc_html_e( 'When Vulnerability watch flags a lead, it is the best reason to call: a specific, new, public problem on their site. Re-run their check to confirm, then reach out.', 'site-audit-report' ); ?></li>
					<li><strong><?php esc_html_e( 'Re-run after the work.', 'site-audit-report' ); ?></strong> <?php esc_html_e( 'Re-run the audit when fixes are live. The report shows the new score next to the previous one, which makes a good case study.', 'site-audit-report' ); ?></li>
				</ol>
			</div>

			<h2 id="limits"><?php esc_html_e( 'Limits and privacy', 'site-audit-report' ); ?></h2>
			<div class="sar-card">
				<ul class="ul-disc">
					<li>
						<?php
						printf(
							/* translators: 1: per hour, 2: per site per day, 3: total per day. */
							esc_html__( 'Public full audits: %1$d per visitor per hour, %2$d per website per day, %3$d in total per day (Settings > Limits). The Security Check allows twice the per-visitor and per-site numbers; the SEO Inspector allows 40 per visitor per hour.', 'site-audit-report' ),
							(int) Settings::get( 'limit_ip_hour' ),
							(int) Settings::get( 'limit_domain_day' ),
							(int) Settings::get( 'limit_global_day' )
						);
						?>
					</li>
					<li><?php esc_html_e( 'Every check is passive: plain GET requests to public addresses. Nothing logs in, posts forms or changes the scanned site. Private and internal network addresses are always refused.', 'site-audit-report' ); ?></li>
					<li><?php esc_html_e( 'Scans read the HTML a server sends, so content added later by JavaScript is not seen (the Google speed tests are the exception).', 'site-audit-report' ); ?></li>
					<li>
						<?php
						printf(
							/* translators: %d: days. */
							esc_html__( 'Stored audits and leads are deleted after %d days (0 keeps them). The SEO Inspector stores nothing.', 'site-audit-report' ),
							(int) Settings::get( 'retention_days' )
						);
						?>
					</li>
					<li><?php esc_html_e( 'Vulnerability data comes from WPVulnerability.net and the CISA Known Exploited Vulnerabilities list. A match means "version seen has a published hole", not a confirmed break-in.', 'site-audit-report' ); ?></li>
				</ul>
				<p><a class="button" href="<?php echo esc_url( $settings ); ?>"><?php esc_html_e( 'Open Settings', 'site-audit-report' ); ?></a></p>
			</div>

			<h2 id="dev"><?php esc_html_e( 'For developers', 'site-audit-report' ); ?></h2>
			<div class="sar-card">
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Hook', 'site-audit-report' ); ?></th><th><?php esc_html_e( 'Use it to', 'site-audit-report' ); ?></th></tr></thead>
					<tbody>
						<tr><td><code>sar_modules</code></td><td><?php esc_html_e( 'Add or remove sections of the full audit (implement Site_Audit_Report\Module).', 'site-audit-report' ); ?></td></tr>
						<tr><td><code>sar_service_modules</code></td><td><?php esc_html_e( 'Change what the Security Check inspection runs.', 'site-audit-report' ); ?></td></tr>
						<tr><td><code>sar_service_tools</code></td><td><?php esc_html_e( 'Change the fix-plan services, descriptions or prices.', 'site-audit-report' ); ?></td></tr>
						<tr><td><code>sar_service_booked</code></td><td><?php esc_html_e( 'Send bookings to a CRM, Slack or calendar.', 'site-audit-report' ); ?></td></tr>
						<tr><td><code>sar_watch_alerts</code></td><td><?php esc_html_e( 'Receive new vulnerability-watch alert IDs (send them to a CRM or Slack).', 'site-audit-report' ); ?></td></tr>
						<tr><td><code>sar_rest_namespace_map</code></td><td><?php esc_html_e( 'Teach the scanner which REST API namespace belongs to which plugin.', 'site-audit-report' ); ?></td></tr>
						<tr><td><code>sar_audit_completed</code></td><td><?php esc_html_e( 'React when any audit finishes.', 'site-audit-report' ); ?></td></tr>
						<tr><td><code>sar_categories</code></td><td><?php esc_html_e( 'Change report categories and their weights.', 'site-audit-report' ); ?></td></tr>
						<tr><td><code>sar_capability</code></td><td><?php esc_html_e( 'Open these screens to a wider team (default manage_options).', 'site-audit-report' ); ?></td></tr>
						<tr><td><code>wp site-audit-report run-queue</code></td><td><?php esc_html_e( 'WP-CLI: process queued audits from a real cron job.', 'site-audit-report' ); ?></td></tr>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Restyle the Security Check and SEO Inspector by overriding the --bay-* CSS variables on .sar-bay.', 'site-audit-report' ); ?></p>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------ Dashboard */

	/**
	 * Adds the dashboard widget for users with access.
	 *
	 * @return void
	 */
	public static function dashboard_setup() {
		if ( current_user_can( Admin::capability() ) ) {
			wp_add_dashboard_widget( 'sar_dashboard', __( 'Site Audits', 'site-audit-report' ), array( self::class, 'render_widget' ) );
		}
	}

	/**
	 * The dashboard widget.
	 *
	 * @return void
	 */
	public static function render_widget() {
		global $wpdb;
		$since  = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$audits = admin_url( 'admin.php?page=' . Admin::SLUG );
		$avg    = $wpdb->get_var( $wpdb->prepare( "SELECT ROUND(AVG(score)) FROM {$wpdb->prefix}sar_audits WHERE status = 'complete' AND score IS NOT NULL AND created_at >= %s", $since ) );
		$tiles  = array(
			array( __( 'Audits', 'site-audit-report' ), Audits::count( array( 'since' => $since ) ), $audits ),
			array( __( 'Health checks', 'site-audit-report' ), Audits::count( array( 'since' => $since, 'source' => 'service' ) ), add_query_arg( 'source', 'service', $audits ) ),
			array( __( 'Bookings', 'site-audit-report' ), Audits::count( array( 'since' => $since, 'lead_status' => 'booked' ) ), add_query_arg( 'lead_status', 'booked', $audits ) ),
			array( __( 'New alerts', 'site-audit-report' ), \Site_Audit_Report\Vuln_Watch::counts()['new'], admin_url( 'admin.php?page=' . Watch::SLUG ) ),
			array( __( 'Avg score', 'site-audit-report' ), null === $avg ? '—' : (int) $avg, '' ),
		);
		$recent = Audits::query( array( 'limit' => 5 ) );
		$leads  = Audits_Table::lead_statuses();
		?>
		<div class="sar-widget">
			<p class="sar-widget__period"><?php esc_html_e( 'Last 30 days', 'site-audit-report' ); ?></p>
			<div class="sar-widget__tiles">
				<?php foreach ( $tiles as $tile ) : ?>
					<?php $tag = $tile[2] ? 'a' : 'div'; ?>
					<<?php echo esc_html( $tag ); ?> class="sar-widget__tile"<?php echo $tile[2] ? ' href="' . esc_url( $tile[2] ) . '"' : ''; ?>>
						<b><?php echo esc_html( (string) $tile[1] ); ?></b><span><?php echo esc_html( $tile[0] ); ?></span>
					</<?php echo esc_html( $tag ); ?>>
				<?php endforeach; ?>
			</div>
			<?php if ( $recent ) : ?>
				<table class="sar-widget__table">
					<tbody>
						<?php foreach ( $recent as $row ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( add_query_arg( 'audit', (int) $row['id'], $audits ) ); ?>"><?php echo esc_html( (string) $row['domain'] ); ?></a><br><span class="sar-source sar-source--<?php echo esc_attr( (string) $row['source'] ); ?>"><?php echo esc_html( Audits_Table::source_label( (string) $row['source'] ) ); ?></span></td>
								<td class="sar-widget__score"><?php echo null === $row['score'] ? '—' : (int) $row['score']; ?></td>
								<td><?php echo esc_html( $leads[ $row['lead_status'] ] ?? (string) $row['lead_status'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No audits yet. Add a tool to a page to start collecting them.', 'site-audit-report' ); ?></p>
			<?php endif; ?>
			<p class="sar-widget__links">
				<a href="<?php echo esc_url( $audits ); ?>"><?php esc_html_e( 'All audits', 'site-audit-report' ); ?></a> ·
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SEO_SLUG ) ); ?>"><?php esc_html_e( 'SEO Inspector', 'site-audit-report' ); ?></a> ·
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::GUIDE_SLUG ) ); ?>"><?php esc_html_e( 'Guide', 'site-audit-report' ); ?></a>
			</p>
		</div>
		<?php
	}
}
