<?php
/**
 * Serves private report links. They work with any permalink setting and do not
 * load the theme, so no theme or plugin can change a report or see its link.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Report route.
 */
final class Report_Route {

	const PARAM = 'sar_report';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( self::class, 'maybe_serve' ), 20 );
	}

	/**
	 * The private link to a report.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function url( $token ) {
		return add_query_arg( self::PARAM, rawurlencode( $token ), home_url( '/' ) );
	}

	/**
	 * Serves the report if this request is for one.
	 *
	 * @return void
	 */
	public static function maybe_serve() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The unguessable token in the link is the authorisation.
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}
		$token    = sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) );
		$download = ! empty( $_GET['sar_download'] );
		// phpcs:enable

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );

		$audit = Audits::by_token( $token );
		$nonce = bin2hex( random_bytes( 16 ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A header, and the nonce is hex from random_bytes().
		header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src https: data:; script-src 'nonce-{$nonce}'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'" );
		header( 'Content-Type: text/html; charset=utf-8' );

		if ( ! $audit ) {
			status_header( 404 );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts by the renderer.
			echo Report_Renderer::not_found_document();
			exit;
		}

		if ( $download && 'complete' === $audit['status'] ) {
			$name = 'website-audit-' . sanitize_file_name( str_replace( '.', '-', (string) $audit['domain'] ) ) . '.html';
			header( 'Content-Disposition: attachment; filename="' . $name . '"' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts by the renderer.
			echo Report_Renderer::document( $audit, array( 'mode' => 'download' ) );
			exit;
		}

		if ( in_array( $audit['status'], array( 'queued', 'running' ), true ) ) {
			Queue::nudge();
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts by the renderer.
		echo Report_Renderer::document(
			$audit,
			array(
				'mode'  => 'web',
				'nonce' => $nonce,
			)
		);
		exit;
	}
}
