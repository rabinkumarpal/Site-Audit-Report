<?php
/**
 * Connects stored leads to WordPress's personal data export and erase tools.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy tools.
 */
final class Privacy {

	const PAGE_SIZE = 50;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'add_eraser' ) );
		add_action( 'admin_init', array( self::class, 'policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array<string,mixed> $exporters Exporters.
	 * @return array<string,mixed>
	 */
	public static function add_exporter( $exporters ) {
		$exporters['site-audit-report'] = array(
			'exporter_friendly_name' => __( 'Site Audit Report', 'site-audit-report' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array<string,mixed> $erasers Erasers.
	 * @return array<string,mixed>
	 */
	public static function add_eraser( $erasers ) {
		$erasers['site-audit-report'] = array(
			'eraser_friendly_name' => __( 'Site Audit Report', 'site-audit-report' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exports a person's audits.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array<string,mixed>
	 */
	public static function export( $email, $page = 1 ) {
		$rows = Audits::by_email( $email, self::PAGE_SIZE, ( (int) $page - 1 ) * self::PAGE_SIZE );
		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'site-audit-report',
				'group_label' => __( 'Website audits', 'site-audit-report' ),
				'item_id'     => 'sar-audit-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Website', 'site-audit-report' ),
						'value' => $row['site_url'],
					),
					array(
						'name'  => __( 'Business name', 'site-audit-report' ),
						'value' => $row['business_name'],
					),
					array(
						'name'  => __( 'Email', 'site-audit-report' ),
						'value' => $row['email'],
					),
					array(
						'name'  => __( 'Requested on', 'site-audit-report' ),
						'value' => $row['created_at'],
					),
					array(
						'name'  => __( 'Consent given on', 'site-audit-report' ),
						'value' => (string) $row['consent_at'],
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Erases a person's audits.
	 *
	 * @param string $email Email.
	 * @return array<string,mixed>
	 */
	public static function erase( $email ) {
		$rows = Audits::by_email( $email, self::PAGE_SIZE, 0 );
		foreach ( $rows as $row ) {
			Audits::delete( (int) $row['id'] );
		}
		return array(
			'items_removed'  => count( $rows ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Suggests wording for the site's privacy policy.
	 *
	 * @return void
	 */
	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$days = (int) Settings::get( 'retention_days' );
		$text = '<p>' . esc_html__( 'When you request a website audit, we store the website address, business name and email address you give us, the time you gave consent, and a one-way hash of your IP address that is used to prevent abuse. We then read public pages of that website and may send its address to Google PageSpeed Insights and its business name to Google Places.', 'site-audit-report' ) . '</p>';
		if ( $days > 0 ) {
			/* translators: %d: number of days. */
			$text .= '<p>' . esc_html( sprintf( __( 'Audit records are deleted automatically after %d days.', 'site-audit-report' ), $days ) ) . '</p>';
		}
		wp_add_privacy_policy_content( __( 'Site Audit Report', 'site-audit-report' ), wp_kses_post( $text ) );
	}
}
