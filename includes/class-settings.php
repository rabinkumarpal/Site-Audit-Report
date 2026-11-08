<?php
/**
 * Plugin settings: defaults, access and sanitising.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Settings store.
 */
final class Settings {

	const OPTION = 'sar_settings';

	/**
	 * Default values. Nothing here names a particular agency or site.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'brand_name'       => '',
			'logo_url'         => '',
			'accent_color'     => '#1d4ed8',
			'cta_heading'      => '',
			'cta_text'         => '',
			'cta_label'        => '',
			'cta_url'          => '',
			'form_heading'     => '',
			'form_intro'       => '',
			'form_button'      => '',
			'consent_text'     => '',
			'psi_api_key'      => '',
			'places_api_key'   => '',
			'limit_ip_hour'    => 5,
			'limit_domain_day' => 3,
			'limit_email_day'  => 5,
			'limit_global_day' => 200,
			'max_pages'        => 5,
			'max_links'        => 25,
			'retention_days'   => 180,
			'ip_header'        => '',
			'email_lead'       => 1,
			'notify_email'     => '',
			'service_currency' => '₹',
			'service_period'   => 'month',
			'price_patch'      => 1999,
			'price_updates'    => 1499,
			'price_harden'     => 999,
			'price_ssl'        => 499,
			'price_monitor'    => 699,
			'price_backup'     => 799,
			'service_book_url' => '',
			'watch_enabled'    => 1,
			'watch_email_leads' => 0,
		);
	}

	/**
	 * Reads settings.
	 *
	 * @param string|null $key Setting name, or null for all.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$stored = get_option( self::OPTION, array() );
		$all    = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		if ( null === $key ) {
			return $all;
		}
		return $all[ $key ] ?? null;
	}

	/**
	 * The name shown on forms, reports and emails.
	 *
	 * @return string
	 */
	public static function brand_name() {
		$name = (string) self::get( 'brand_name' );
		return '' !== $name ? $name : get_bloginfo( 'name' );
	}

	/**
	 * The consent line shown on the form.
	 *
	 * @return string
	 */
	public static function consent_text() {
		$text = (string) self::get( 'consent_text' );
		if ( '' !== $text ) {
			return $text;
		}
		/* translators: %s: business or site name. */
		return sprintf( __( 'I am allowed to request an audit of this website, and I agree that %s may store my details, email me the report and contact me about it.', 'site-audit-report' ), self::brand_name() );
	}

	/**
	 * Sanitises the settings form.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = self::get();
		$clean   = array();

		foreach ( array( 'brand_name', 'cta_heading', 'cta_label', 'form_heading', 'form_button' ) as $key ) {
			$clean[ $key ] = sanitize_text_field( (string) ( $input[ $key ] ?? '' ) );
		}
		foreach ( array( 'cta_text', 'form_intro', 'consent_text' ) as $key ) {
			$clean[ $key ] = sanitize_textarea_field( (string) ( $input[ $key ] ?? '' ) );
		}
		foreach ( array( 'logo_url', 'cta_url' ) as $key ) {
			$clean[ $key ] = esc_url_raw( trim( (string) ( $input[ $key ] ?? '' ) ), array( 'http', 'https' ) );
		}

		$color                 = sanitize_hex_color( (string) ( $input['accent_color'] ?? '' ) );
		$clean['accent_color'] = $color ? $color : self::defaults()['accent_color'];

		foreach ( array( 'psi_api_key', 'places_api_key' ) as $key ) {
			$value = trim( sanitize_text_field( (string) ( $input[ $key ] ?? '' ) ) );
			if ( ! empty( $input[ $key . '_clear' ] ) ) {
				$clean[ $key ] = '';
			} else {
				$clean[ $key ] = '' !== $value ? $value : (string) $current[ $key ];
			}
		}

		$ranges = array(
			'limit_ip_hour'    => array( 1, 1000 ),
			'limit_domain_day' => array( 1, 1000 ),
			'limit_email_day'  => array( 1, 1000 ),
			'limit_global_day' => array( 1, 100000 ),
			'max_pages'        => array( 1, 10 ),
			'max_links'        => array( 0, 50 ),
			'retention_days'   => array( 0, 3650 ),
		);
		foreach ( $ranges as $key => $range ) {
			$value         = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : (int) $current[ $key ];
			$clean[ $key ] = max( $range[0], min( $range[1], $value ) );
		}

		$header             = (string) ( $input['ip_header'] ?? '' );
		$clean['ip_header'] = in_array( $header, array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ), true ) ? $header : '';

		$clean['service_currency'] = mb_substr( sanitize_text_field( (string) ( $input['service_currency'] ?? '₹' ) ), 0, 5 );
		$clean['service_period']   = sanitize_text_field( (string) ( $input['service_period'] ?? 'month' ) );
		$clean['service_book_url'] = esc_url_raw( trim( (string) ( $input['service_book_url'] ?? '' ) ), array( 'http', 'https' ) );
		foreach ( array( 'patch', 'updates', 'harden', 'ssl', 'monitor', 'backup' ) as $tool ) {
			$key           = 'price_' . $tool;
			$clean[ $key ] = isset( $input[ $key ] ) ? min( 10000000, absint( $input[ $key ] ) ) : (int) $current[ $key ];
		}

		$clean['watch_enabled']     = empty( $input['watch_enabled'] ) ? 0 : 1;
		$clean['watch_email_leads'] = empty( $input['watch_email_leads'] ) ? 0 : 1;

		$clean['email_lead']   = empty( $input['email_lead'] ) ? 0 : 1;
		$clean['notify_email'] = sanitize_email( (string) ( $input['notify_email'] ?? '' ) );

		return $clean;
	}
}
