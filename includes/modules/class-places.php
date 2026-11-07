<?php
/**
 * Google Business Profile details through the Places API, and whether the
 * business's name, address and phone match the website.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report\Modules;

use Site_Audit_Report\Check;
use Site_Audit_Report\Module;
use Site_Audit_Report\Scan_Context;
use Site_Audit_Report\Settings;
use Site_Audit_Report\Url_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Places checks.
 */
final class Places implements Module {

	const CATEGORY = 'local';
	const ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'places';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Looking up the business profile on Google', 'site-audit-report' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Scan_Context $context Context.
	 */
	public function run( Scan_Context $context ) {
		$key = (string) Settings::get( 'places_api_key' );
		if ( '' === $key ) {
			return $this->not_checked( __( 'Not checked: no Google Places API key is set for this tool.', 'site-audit-report' ) );
		}
		if ( '' === trim( (string) $context->audit['business_name'] ) ) {
			return $this->not_checked( __( 'Not checked: no business name was given.', 'site-audit-report' ) );
		}

		$places = $this->search( $key, $context );
		if ( is_string( $places ) ) {
			return $this->not_checked( $places );
		}

		$place = $this->best_match( $places, $context );
		if ( ! $place ) {
			return $this->not_checked( __( 'No Google Business Profile could be matched to this website with confidence. A profile may still exist under another name or without a website link.', 'site-audit-report' ) );
		}

		return array(
			$this->profile( $place ),
			$this->rating( $place ),
			$this->reviews( $place ),
			$this->nap( $place, $context ),
		);
	}

	/**
	 * Marks everything as not checked.
	 *
	 * @param string $reason Reason.
	 * @return array<int,array<string,mixed>>
	 */
	private function not_checked( $reason ) {
		return Check::all_not_checked(
			self::CATEGORY,
			array(
				'profile' => array( __( 'Google Business Profile details', 'site-audit-report' ), 3 ),
				'rating'  => array( __( 'Google rating', 'site-audit-report' ), 3 ),
				'reviews' => array( __( 'Number of Google reviews', 'site-audit-report' ), 2 ),
				'nap'     => array( __( 'Name, address and phone match', 'site-audit-report' ), 3 ),
			),
			$reason
		);
	}

	/**
	 * Calls Places Text Search.
	 *
	 * @param string       $key     API key.
	 * @param Scan_Context $context Context.
	 * @return array<int,array<string,mixed>>|string Places, or the reason there are none.
	 */
	private function search( $key, Scan_Context $context ) {
		$query    = trim( (string) $context->audit['business_name'] );
		$business = (array) ( $context->home()['business'] ?? array() );
		if ( ! empty( $business['locality'] ) ) {
			$query .= ', ' . $business['locality'];
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'     => 'application/json',
					'X-Goog-Api-Key'   => $key,
					'X-Goog-FieldMask' => 'places.displayName,places.formattedAddress,places.nationalPhoneNumber,places.internationalPhoneNumber,places.rating,places.userRatingCount,places.websiteUri,places.googleMapsUri,places.businessStatus,places.regularOpeningHours.weekdayDescriptions,places.primaryTypeDisplayName',
				),
				'body'    => wp_json_encode(
					array(
						'textQuery'      => $query,
						'maxResultCount' => 5,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return __( 'Not checked: Google Places did not respond.', 'site-audit-report' );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $code ) {
			return __( 'Not checked: the Google Places quota for this tool was used up.', 'site-audit-report' );
		}
		if ( in_array( $code, array( 400, 401, 403 ), true ) ) {
			return __( 'Not checked: Google rejected the Places API key set for this tool. Check that the key is valid and has Places API (New) enabled.', 'site-audit-report' );
		}
		if ( 200 !== $code ) {
			return __( 'Not checked: Google Places returned an error.', 'site-audit-report' );
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded['places'] ?? null ) ? $decoded['places'] : array();
	}

	/**
	 * Picks a place only when it is provably this business. A wrong match would
	 * put another company's rating in the report, so no match beats a guess.
	 *
	 * @param array<int,array<string,mixed>> $places  Places.
	 * @param Scan_Context                   $context Context.
	 * @return array<string,mixed>|null
	 */
	private function best_match( array $places, Scan_Context $context ) {
		$domain = (string) $context->audit['domain'];
		foreach ( $places as $place ) {
			if ( ! empty( $place['websiteUri'] ) && Url_Guard::domain_key( (string) $place['websiteUri'] ) === $domain ) {
				return $place;
			}
		}

		$site_phones = $this->site_phones( $context );
		$name        = self::normalize_name( (string) $context->audit['business_name'] );
		foreach ( $places as $place ) {
			$same_name = self::normalize_name( (string) ( $place['displayName']['text'] ?? '' ) ) === $name;
			$phone     = self::phone_key( (string) ( $place['internationalPhoneNumber'] ?? $place['nationalPhoneNumber'] ?? '' ) );
			if ( $same_name && '' !== $phone && in_array( $phone, $site_phones, true ) ) {
				return $place;
			}
		}
		return null;
	}

	/**
	 * Profile completeness.
	 *
	 * @param array<string,mixed> $place Place.
	 * @return array<string,mixed>
	 */
	private function profile( array $place ) {
		$label = __( 'Google Business Profile details', 'site-audit-report' );

		$present = array(
			__( 'address', 'site-audit-report' )       => ! empty( $place['formattedAddress'] ),
			__( 'phone', 'site-audit-report' )         => ! empty( $place['nationalPhoneNumber'] ) || ! empty( $place['internationalPhoneNumber'] ),
			__( 'website', 'site-audit-report' )       => ! empty( $place['websiteUri'] ),
			__( 'opening hours', 'site-audit-report' ) => ! empty( $place['regularOpeningHours']['weekdayDescriptions'] ),
		);
		$missing = array_keys( array_filter( $present, static fn( $has ) => ! $has ) );

		$items = array();
		if ( ! empty( $place['displayName']['text'] ) ) {
			/* translators: %s: business name. */
			$items[] = sprintf( __( 'Name: %s', 'site-audit-report' ), $place['displayName']['text'] );
		}
		if ( ! empty( $place['primaryTypeDisplayName']['text'] ) ) {
			/* translators: %s: business category. */
			$items[] = sprintf( __( 'Category: %s', 'site-audit-report' ), $place['primaryTypeDisplayName']['text'] );
		}
		if ( ! empty( $place['formattedAddress'] ) ) {
			/* translators: %s: address. */
			$items[] = sprintf( __( 'Address: %s', 'site-audit-report' ), $place['formattedAddress'] );
		}
		$phone = $place['internationalPhoneNumber'] ?? $place['nationalPhoneNumber'] ?? '';
		if ( '' !== $phone ) {
			/* translators: %s: phone number. */
			$items[] = sprintf( __( 'Phone: %s', 'site-audit-report' ), $phone );
		}
		if ( ! empty( $place['googleMapsUri'] ) ) {
			$items[] = array(
				'text' => __( 'View on Google Maps', 'site-audit-report' ),
				'url'  => $place['googleMapsUri'],
			);
		}

		if ( isset( $place['businessStatus'] ) && 'OPERATIONAL' !== $place['businessStatus'] ) {
			return Check::make(
				Check::FAIL,
				self::CATEGORY,
				'profile',
				$label,
				3,
				__( 'Google lists this business as closed.', 'site-audit-report' ),
				array(
					'fix'    => __( 'If the business is open, correct its status in Google Business Profile.', 'site-audit-report' ),
					'effort' => 'low',
					'items'  => $items,
				)
			);
		}
		if ( $missing ) {
			return Check::make(
				Check::WARN,
				self::CATEGORY,
				'profile',
				$label,
				3,
				/* translators: %s: missing details. */
				sprintf( __( 'A profile was found, but Google shows no: %s.', 'site-audit-report' ), implode( ', ', $missing ) ),
				array(
					'fix'    => __( 'Fill in the missing details in Google Business Profile.', 'site-audit-report' ),
					'effort' => 'low',
					'items'  => $items,
				)
			);
		}
		return Check::make( Check::PASS, self::CATEGORY, 'profile', $label, 3, __( 'A profile was found with address, phone, website and opening hours.', 'site-audit-report' ), array( 'items' => $items ) );
	}

	/**
	 * Rating.
	 *
	 * @param array<string,mixed> $place Place.
	 * @return array<string,mixed>
	 */
	private function rating( array $place ) {
		$label = __( 'Google rating', 'site-audit-report' );
		if ( ! isset( $place['rating'] ) ) {
			return Check::not_checked( self::CATEGORY, 'rating', $label, 3, __( 'Google shows no rating for this profile yet.', 'site-audit-report' ) );
		}
		$rating = (float) $place['rating'];
		/* translators: %s: rating. */
		$evidence = sprintf( __( '%s out of 5 on Google.', 'site-audit-report' ), number_format( $rating, 1 ) );
		if ( $rating >= 4.5 ) {
			return Check::make( Check::PASS, self::CATEGORY, 'rating', $label, 3, $evidence );
		}
		return Check::make(
			$rating >= 4.0 ? Check::WARN : Check::FAIL,
			self::CATEGORY,
			'rating',
			$label,
			3,
			$evidence,
			array(
				'fix'    => __( 'Ask happy customers for reviews straight after each job, and reply to every negative review.', 'site-audit-report' ),
				'effort' => 'medium',
			)
		);
	}

	/**
	 * Review count.
	 *
	 * @param array<string,mixed> $place Place.
	 * @return array<string,mixed>
	 */
	private function reviews( array $place ) {
		$label = __( 'Number of Google reviews', 'site-audit-report' );
		$count = (int) ( $place['userRatingCount'] ?? 0 );
		/* translators: %d: number of reviews. */
		$evidence = sprintf( _n( '%d review on Google.', '%d reviews on Google.', $count, 'site-audit-report' ), $count );
		if ( $count >= 50 ) {
			return Check::make( Check::PASS, self::CATEGORY, 'reviews', $label, 2, $evidence );
		}
		return Check::make(
			$count >= 10 ? Check::WARN : Check::FAIL,
			self::CATEGORY,
			'reviews',
			$label,
			2,
			$evidence,
			array(
				'fix'    => __( 'Build a routine for asking for reviews, such as a short link sent by message after each job.', 'site-audit-report' ),
				'effort' => 'medium',
			)
		);
	}

	/**
	 * Compares name, address and phone on Google with the website.
	 *
	 * @param array<string,mixed> $place   Place.
	 * @param Scan_Context        $context Context.
	 * @return array<string,mixed>
	 */
	private function nap( array $place, Scan_Context $context ) {
		$label = __( 'Name, address and phone match', 'site-audit-report' );

		$text = '';
		foreach ( $context->pages() as $page ) {
			$text .= ' ' . (string) $page['text'];
			$text .= ' ' . implode( ' ', (array) ( $page['business'] ?? array() ) );
		}
		$text = strtolower( $text );

		$items   = array();
		$results = array();

		$name = (string) ( $place['displayName']['text'] ?? '' );
		if ( '' !== $name ) {
			$found     = false !== strpos( self::normalize_name( $text ), self::normalize_name( $name ) );
			$results[] = $found;
			/* translators: %s: business name. */
			$items[] = sprintf( $found ? __( 'Name matches: %s', 'site-audit-report' ) : __( 'Name not found on the website: %s', 'site-audit-report' ), $name );
		}

		$phone = (string) ( $place['internationalPhoneNumber'] ?? $place['nationalPhoneNumber'] ?? '' );
		if ( '' !== $phone ) {
			$key       = self::phone_key( $phone );
			$found     = '' !== $key && ( in_array( $key, $this->site_phones( $context ), true ) || false !== strpos( (string) preg_replace( '/\D/', '', $text ), $key ) );
			$results[] = $found;
			/* translators: %s: phone number. */
			$items[] = sprintf( $found ? __( 'Phone matches: %s', 'site-audit-report' ) : __( 'Phone not found on the website: %s', 'site-audit-report' ), $phone );
		}

		$address = (string) ( $place['formattedAddress'] ?? '' );
		if ( '' !== $address ) {
			$found     = self::address_found( $address, $text );
			$results[] = $found;
			/* translators: %s: address. */
			$items[] = sprintf( $found ? __( 'Address matches: %s', 'site-audit-report' ) : __( 'Address not found on the website: %s', 'site-audit-report' ), $address );
		}

		if ( ! $results || ! $context->readable() ) {
			return Check::not_checked( self::CATEGORY, 'nap', $label, 3, $context->readable() ? __( 'Google returned no name, address or phone to compare.', 'site-audit-report' ) : $context->unreadable_reason() );
		}

		$matched = count( array_filter( $results ) );
		/* translators: 1: details matched, 2: details compared, 3: pages searched. */
		$evidence = sprintf( __( '%1$d of %2$d details from Google were found on the website. Pages searched: %3$d.', 'site-audit-report' ), $matched, count( $results ), count( $context->pages() ) );

		if ( count( $results ) === $matched ) {
			return Check::make( Check::PASS, self::CATEGORY, 'nap', $label, 3, $evidence, array( 'items' => $items ) );
		}
		return Check::make(
			0 === $matched ? Check::FAIL : Check::WARN,
			self::CATEGORY,
			'nap',
			$label,
			3,
			$evidence,
			array(
				'fix'    => __( 'Show the same name, address and phone on your website and your Google profile, ideally in the footer and on the contact page.', 'site-audit-report' ),
				'effort' => 'low',
				'items'  => $items,
			)
		);
	}

	/**
	 * Phone numbers published on the site, reduced to comparison keys.
	 *
	 * @param Scan_Context $context Context.
	 * @return string[]
	 */
	private function site_phones( Scan_Context $context ) {
		$keys = array();
		foreach ( $context->pages() as $page ) {
			foreach ( (array) $page['tel_links'] as $tel ) {
				$keys[] = self::phone_key( (string) $tel );
			}
			if ( ! empty( $page['business']['telephone'] ) ) {
				$keys[] = self::phone_key( (string) $page['business']['telephone'] );
			}
		}
		return array_values( array_filter( array_unique( $keys ) ) );
	}

	/**
	 * Last ten digits, which ignores country codes and leading zeros.
	 *
	 * @param string $phone Phone number.
	 * @return string
	 */
	private static function phone_key( $phone ) {
		$digits = (string) preg_replace( '/\D/', '', $phone );
		return strlen( $digits ) >= 7 ? substr( $digits, -10 ) : '';
	}

	/**
	 * Letters and digits only.
	 *
	 * @param string $name Text.
	 * @return string
	 */
	private static function normalize_name( $name ) {
		$name = strtolower( $name );
		return (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', $name );
	}

	/**
	 * An address counts as found when its postal code and most of its words appear.
	 *
	 * @param string $address Address from Google.
	 * @param string $text    Lower-case website text.
	 * @return bool
	 */
	private static function address_found( $address, $text ) {
		$first = trim( explode( ',', strtolower( $address ) )[0] );
		if ( '' !== $first && mb_strlen( $first ) > 6 && false !== strpos( $text, $first ) ) {
			return true;
		}
		$tokens = array_filter(
			(array) preg_split( '/[\s,.\-\/]+/u', strtolower( $address ), -1, PREG_SPLIT_NO_EMPTY ),
			static fn( $token ) => mb_strlen( $token ) >= 3 || ctype_digit( $token )
		);
		if ( ! $tokens ) {
			return false;
		}
		$hits = 0;
		foreach ( $tokens as $token ) {
			if ( false !== strpos( $text, $token ) ) {
				++$hits;
			}
		}
		return $hits / count( $tokens ) >= 0.7;
	}
}
