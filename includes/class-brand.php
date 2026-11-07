<?php
/**
 * Derives readable colours from the configured accent colour.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Brand colour helpers.
 */
final class Brand {

	/**
	 * CSS custom properties, chosen so any accent colour stays readable.
	 *
	 * @return string Declarations, safe to place inside a style attribute or rule.
	 */
	public static function css_variables() {
		$accent = sanitize_hex_color( (string) Settings::get( 'accent_color' ) );
		$accent = $accent ? $accent : '#1d4ed8';

		$on_accent = self::contrast( $accent, '#ffffff' ) >= self::contrast( $accent, '#111827' ) ? '#ffffff' : '#111827';

		return sprintf(
			'--sar-accent:%1$s;--sar-on-accent:%2$s;--sar-accent-text:%3$s;',
			$accent,
			$on_accent,
			self::readable_on_white( $accent )
		);
	}

	/**
	 * Darkens a colour until it reads as text on white (WCAG AA, 4.5:1).
	 *
	 * @param string $hex Colour.
	 * @return string
	 */
	public static function readable_on_white( $hex ) {
		$rgb = self::rgb( $hex );
		for ( $i = 0; $i < 40 && self::contrast( self::hex( $rgb ), '#ffffff' ) < 4.5; $i++ ) {
			$rgb = array_map(
				static function ( $channel ) {
					return (int) floor( $channel * 0.92 );
				},
				$rgb
			);
		}
		return self::hex( $rgb );
	}

	/**
	 * WCAG contrast ratio between two colours.
	 *
	 * @param string $a First colour.
	 * @param string $b Second colour.
	 * @return float
	 */
	public static function contrast( $a, $b ) {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Relative luminance.
	 *
	 * @param string $hex Colour.
	 * @return float
	 */
	private static function luminance( $hex ) {
		$channels = array_map(
			static function ( $channel ) {
				$value = $channel / 255;
				return $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
			},
			self::rgb( $hex )
		);
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * Splits a hex colour into channels.
	 *
	 * @param string $hex Colour.
	 * @return int[]
	 */
	private static function rgb( $hex ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return array( (int) hexdec( substr( $hex, 0, 2 ) ), (int) hexdec( substr( $hex, 2, 2 ) ), (int) hexdec( substr( $hex, 4, 2 ) ) );
	}

	/**
	 * Joins channels into a hex colour.
	 *
	 * @param int[] $rgb Channels.
	 * @return string
	 */
	private static function hex( $rgb ) {
		return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
	}
}
