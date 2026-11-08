<?php
/**
 * The public audit form: shortcode, block, submission and status polling.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Public form.
 */
final class Public_Form {

	const NONCE_ACTION  = 'sar_submit';
	const HONEYPOT      = 'sar_company_site';
	const ATTEMPT_LIMIT = 30;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( 'site_audit_form', array( self::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'register_assets' ) );
		add_action( 'init', array( self::class, 'register_block' ) );

		foreach ( array( 'sar_submit', 'sar_status', 'sar_nonce' ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( self::class, 'ajax_' . substr( $action, 4 ) ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( self::class, 'ajax_' . substr( $action, 4 ) ) );
		}
		add_action( 'admin_post_sar_submit', array( self::class, 'post_submit' ) );
		add_action( 'admin_post_nopriv_sar_submit', array( self::class, 'post_submit' ) );
	}

	/**
	 * Registers front-end files.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style( 'site-audit-form', SAR_URL . 'assets/css/form.css', array(), SAR_VERSION );
		wp_register_script( 'site-audit-form', SAR_URL . 'assets/js/form.js', array(), SAR_VERSION, true );
	}

	/**
	 * Registers the block.
	 *
	 * @return void
	 */
	public static function register_block() {
		register_block_type(
			SAR_DIR . 'blocks/audit-form',
			array( 'render_callback' => array( self::class, 'render_block' ) )
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_block( $attributes ) {
		return self::render(
			array(
				'heading' => (string) ( $attributes['heading'] ?? '' ),
				'intro'   => (string) ( $attributes['intro'] ?? '' ),
				'button'  => (string) ( $attributes['button'] ?? '' ),
			)
		);
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		return self::render(
			shortcode_atts(
				array(
					'heading' => '',
					'intro'   => '',
					'button'  => '',
				),
				(array) $atts,
				'site_audit_form'
			)
		);
	}

	/**
	 * Renders the form. It works without JavaScript; the script adds the live result.
	 *
	 * @param array<string,string> $args heading, intro, button.
	 * @return string
	 */
	public static function render( array $args = array() ) {
		wp_enqueue_style( 'site-audit-form' );
		wp_enqueue_script( 'site-audit-form' );
		wp_localize_script(
			'site-audit-form',
			'sarForm',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'working'    => __( 'Starting your audit…', 'site-audit-report' ),
					'early'      => __( 'Early score', 'site-audit-report' ),
					'final'      => __( 'Your score', 'site-audit-report' ),
					'outOf'      => __( 'out of 100', 'site-audit-report' ),
					'notChecked' => __( 'Not checked', 'site-audit-report' ),
					'stillGoing' => __( 'We are still running the slower tests. Your full report will be ready at this link:', 'site-audit-report' ),
					'ready'      => __( 'Your full report is ready.', 'site-audit-report' ),
					'open'       => __( 'Open your full report', 'site-audit-report' ),
					'topFixes'   => __( 'Top fixes', 'site-audit-report' ),
					'noScore'    => __( 'We could not give this site a score, because it could not be read.', 'site-audit-report' ),
					'failed'     => __( 'The audit could not be completed.', 'site-audit-report' ),
					'error'      => __( 'Something went wrong. Please try again.', 'site-audit-report' ),
					'slow'       => __( 'This is taking longer than usual. You can keep this link and check back later:', 'site-audit-report' ),
				),
			)
		);

		$heading = '' !== (string) ( $args['heading'] ?? '' ) ? $args['heading'] : (string) Settings::get( 'form_heading' );
		$intro   = '' !== (string) ( $args['intro'] ?? '' ) ? $args['intro'] : (string) Settings::get( 'form_intro' );
		$button  = '' !== (string) ( $args['button'] ?? '' ) ? $args['button'] : (string) Settings::get( 'form_button' );

		$heading = '' !== $heading ? $heading : __( 'Get a free website audit', 'site-audit-report' );
		$intro   = '' !== $intro ? $intro : __( 'We will check your site\'s search basics, speed, security and online presence, then show you what to fix first.', 'site-audit-report' );
		$button  = '' !== $button ? $button : __( 'Run my audit', 'site-audit-report' );

		$uid     = wp_unique_id( 'sar-' );
		$privacy = get_privacy_policy_url();
		$error   = self::flash_error();

		ob_start();
		?>
		<div class="sar-form-wrap" style="<?php echo esc_attr( Brand::css_variables() ); ?>">
			<form class="sar-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
				<h2 class="sar-form__heading"><?php echo esc_html( $heading ); ?></h2>
				<p class="sar-form__intro"><?php echo esc_html( $intro ); ?></p>

				<input type="hidden" name="action" value="sar_submit">
				<input type="hidden" name="sar_nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
				<input type="hidden" name="sar_return" value="<?php echo esc_url( self::current_url() ); ?>">

				<div class="sar-field">
					<label for="<?php echo esc_attr( $uid ); ?>-url"><?php esc_html_e( 'Website address', 'site-audit-report' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-url" name="sar_url" type="text" inputmode="url" autocomplete="url" autocapitalize="none" spellcheck="false" required maxlength="2048" placeholder="example.com">
				</div>
				<div class="sar-field">
					<label for="<?php echo esc_attr( $uid ); ?>-name"><?php esc_html_e( 'Business name', 'site-audit-report' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-name" name="sar_business" type="text" autocomplete="organization" required maxlength="191">
				</div>
				<div class="sar-field">
					<label for="<?php echo esc_attr( $uid ); ?>-email"><?php esc_html_e( 'Email', 'site-audit-report' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-email" name="sar_email" type="email" autocomplete="email" required maxlength="191">
				</div>

				<div class="sar-hp" aria-hidden="true">
					<label for="<?php echo esc_attr( $uid ); ?>-hp"><?php esc_html_e( 'Leave this empty', 'site-audit-report' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-hp" name="<?php echo esc_attr( self::HONEYPOT ); ?>" type="text" tabindex="-1" autocomplete="off">
				</div>

				<div class="sar-consent">
					<input id="<?php echo esc_attr( $uid ); ?>-consent" name="sar_consent" type="checkbox" value="1" required>
					<label for="<?php echo esc_attr( $uid ); ?>-consent">
						<?php echo esc_html( Settings::consent_text() ); ?>
						<?php if ( $privacy ) : ?>
							<a href="<?php echo esc_url( $privacy ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy policy', 'site-audit-report' ); ?></a>
						<?php endif; ?>
					</label>
				</div>

				<button class="sar-button" type="submit"><?php echo esc_html( $button ); ?></button>
				<p class="sar-form__message" role="alert"><?php echo esc_html( $error ); ?></p>
			</form>
			<div class="sar-result" hidden tabindex="-1"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Handles a JavaScript submission.
	 *
	 * @return void
	 */
	public static function ajax_submit() {
		nocache_headers();
		$result = self::process_submission();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				(int) ( $result->get_error_data()['status'] ?? 400 )
			);
		}
		wp_send_json_success(
			array(
				'token'     => $result['token'],
				'reportUrl' => Report_Route::url( $result['token'] ),
			)
		);
	}

	/**
	 * Handles a submission without JavaScript.
	 *
	 * @return void
	 */
	public static function post_submit() {
		$result = self::process_submission();
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only used as a same-site redirect target, through wp_safe_redirect().
			$return = isset( $_POST['sar_return'] ) ? esc_url_raw( wp_unslash( $_POST['sar_return'] ) ) : home_url( '/' );
			wp_safe_redirect( add_query_arg( 'sar_error', rawurlencode( $result->get_error_code() ), $return ), 303 );
			exit;
		}
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Same site.
		wp_safe_redirect( Report_Route::url( $result['token'] ), 303 );
		exit;
	}

	/**
	 * Returns a fresh nonce, so forms on cached pages keep working.
	 *
	 * @return void
	 */
	public static function ajax_nonce() {
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( self::NONCE_ACTION ) ) );
	}

	/**
	 * Reports scan progress to the form. The unguessable token is the authorisation.
	 *
	 * @return void
	 */
	public static function ajax_status() {
		nocache_headers();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only and validated as 64 hex characters by by_token().
		$token = isset( $_REQUEST['token'] ) ? (string) wp_unslash( $_REQUEST['token'] ) : '';
		$audit = Audits::by_token( $token );
		if ( ! $audit ) {
			wp_send_json_error( array( 'message' => __( 'Report not found.', 'site-audit-report' ) ), 404 );
		}

		if ( in_array( $audit['status'], array( 'queued', 'running' ), true ) ) {
			Queue::nudge();
		}

		$result = json_decode( (string) $audit['result'], true );
		$result = is_array( $result ) ? $result : array();

		$categories = array();
		foreach ( (array) ( $result['categories'] ?? array() ) as $category ) {
			if ( $category['total'] > 0 ) {
				$categories[] = array(
					'label' => $category['label'],
					'score' => $category['score'],
				);
			}
		}

		$fixes = array();
		foreach ( array_slice( (array) ( $result['fixes'] ?? array() ), 0, 3 ) as $fix ) {
			$fixes[] = $fix['label'];
		}

		wp_send_json_success(
			array(
				'status'     => $audit['status'],
				'stage'      => $audit['stage'] ? $audit['stage'] : ( 'queued' === $audit['status'] ? __( 'Waiting to start', 'site-audit-report' ) : '' ),
				'progress'   => (int) $audit['progress'],
				'score'      => isset( $result['score'] ) ? (int) $result['score'] : null,
				'hasChecks'  => ! empty( $result['checks'] ),
				'categories' => $categories,
				'fixes'      => $fixes,
				'error'      => $audit['error'],
			)
		);
	}

	/**
	 * Validates a submission and queues the scan. No scanning happens here.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private static function process_submission() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitised or validated where it is read below.
		$nonce = isset( $_POST['sar_nonce'] ) ? (string) wp_unslash( $_POST['sar_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return self::error( 'sar_bad_nonce', 403 );
		}

		$missing = Plugin::missing_requirements();
		if ( $missing ) {
			return self::error( 'sar_unavailable', 503 );
		}

		$ip_hash = Rate_Limiter::hash( Rate_Limiter::client_ip() );

		// Counts every attempt, which bounds the DNS lookups validation can trigger.
		if ( ! Rate_Limiter::hit( 'attempt:' . $ip_hash, self::ATTEMPT_LIMIT, HOUR_IN_SECONDS ) ) {
			return self::error( 'sar_rate_ip', 429 );
		}

		if ( ! empty( $_POST[ self::HONEYPOT ] ) ) {
			return self::error( 'sar_rejected', 400 );
		}

		$raw_url  = isset( $_POST['sar_url'] ) ? (string) wp_unslash( $_POST['sar_url'] ) : '';
		$business = isset( $_POST['sar_business'] ) ? sanitize_text_field( wp_unslash( $_POST['sar_business'] ) ) : '';
		$email    = isset( $_POST['sar_email'] ) ? sanitize_email( wp_unslash( $_POST['sar_email'] ) ) : '';
		// phpcs:enable

		$url = Url_Guard::normalize_input( $raw_url );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		if ( '' === $business || mb_strlen( $business ) > 191 ) {
			return self::error( 'sar_business', 400 );
		}
		if ( ! is_email( $email ) || strlen( $email ) > 191 ) {
			return self::error( 'sar_email', 400 );
		}
		if ( empty( $_POST['sar_consent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			return self::error( 'sar_consent', 400 );
		}

		$checked = Url_Guard::validate( $url );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$domain = Url_Guard::domain_key( $checked['url'] );

		// Cheapest for an abuser to hit comes first, so a flood from one IP cannot
		// use up the quota of a domain or email it is targeting.
		if ( ! Rate_Limiter::hit( 'ip:' . $ip_hash, (int) Settings::get( 'limit_ip_hour' ), HOUR_IN_SECONDS ) ) {
			return self::error( 'sar_rate_ip', 429 );
		}
		if ( ! Rate_Limiter::hit( 'email:' . Rate_Limiter::hash( $email ), (int) Settings::get( 'limit_email_day' ), DAY_IN_SECONDS ) ) {
			return self::error( 'sar_rate_email', 429 );
		}
		if ( ! Rate_Limiter::hit( 'domain:' . $domain, (int) Settings::get( 'limit_domain_day' ), DAY_IN_SECONDS ) ) {
			return self::error( 'sar_rate_domain', 429 );
		}
		if ( ! Rate_Limiter::hit( 'global', (int) Settings::get( 'limit_global_day' ), DAY_IN_SECONDS ) ) {
			return self::error( 'sar_rate_global', 429 );
		}

		$audit = Audits::create(
			array(
				'site_url'      => $checked['url'],
				'domain'        => $domain,
				'business_name' => $business,
				'email'         => $email,
				'source'        => 'form',
				'consent_text'  => Settings::consent_text(),
				'consent_at'    => gmdate( 'Y-m-d H:i:s' ),
				'ip_hash'       => $ip_hash,
			)
		);
		if ( ! $audit ) {
			return self::error( 'sar_unavailable', 500 );
		}

		Queue::dispatch();

		return $audit;
	}

	/**
	 * Builds a visitor-facing error.
	 *
	 * @param string $code   Code.
	 * @param int    $status HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $status ) {
		return new WP_Error( $code, self::error_message( $code ), array( 'status' => $status ) );
	}

	/**
	 * The words for an error code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	private static function error_message( $code ) {
		$messages = array(
			'sar_bad_nonce'   => __( 'This form has expired. Please reload the page and try again.', 'site-audit-report' ),
			'sar_unavailable' => __( 'Audits are unavailable right now. Please try again later.', 'site-audit-report' ),
			'sar_rate_ip'     => __( 'You have requested several audits recently. Please try again in an hour.', 'site-audit-report' ),
			'sar_rate_email'  => __( 'This email address has requested several audits today. Please try again tomorrow.', 'site-audit-report' ),
			'sar_rate_domain' => __( 'This website has been audited several times today. Please try again tomorrow.', 'site-audit-report' ),
			'sar_rate_global' => __( 'We have reached our limit of audits for today. Please try again tomorrow.', 'site-audit-report' ),
			'sar_rejected'    => __( 'Your request could not be processed.', 'site-audit-report' ),
			'sar_business'    => __( 'Please enter your business name.', 'site-audit-report' ),
			'sar_email'       => __( 'Please enter a valid email address.', 'site-audit-report' ),
			'sar_consent'     => __( 'Please tick the box to agree before we run the audit.', 'site-audit-report' ),
			'sar_url_empty'   => __( 'Please enter a website address.', 'site-audit-report' ),
			'sar_url_invalid' => __( 'That does not look like a valid website address.', 'site-audit-report' ),
			'sar_url_scheme'  => __( 'Only http and https website addresses can be scanned.', 'site-audit-report' ),
			'sar_url_blocked' => __( 'That address is on a local or private network (such as localhost, or a staging site that is not public), so it cannot be scanned from here. Please enter the public address of a website.', 'site-audit-report' ),
			'sar_dns_failed'  => __( 'That website address could not be found.', 'site-audit-report' ),
		);
		return $messages[ $code ] ?? __( 'Your request could not be processed.', 'site-audit-report' );
	}

	/**
	 * The error to show after a redirect from a submission without JavaScript.
	 *
	 * @return string
	 */
	private static function flash_error() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects from a fixed list of messages.
		$code = isset( $_GET['sar_error'] ) ? sanitize_key( wp_unslash( $_GET['sar_error'] ) ) : '';
		return '' !== $code ? self::error_message( $code ) : '';
	}

	/**
	 * The current page's address without the form's own error parameter.
	 *
	 * @return string
	 */
	private static function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return remove_query_arg( 'sar_error', home_url( $uri ) );
	}
}
