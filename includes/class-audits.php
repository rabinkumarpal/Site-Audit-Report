<?php
/**
 * Database access for audit records.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- This class is the plugin's data layer for its own tables. Table names come from $wpdb->prefix, ORDER BY values from an allow-list, and every value goes through prepare().

/**
 * Audit repository.
 */
final class Audits {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sar_audits';
	}

	/**
	 * Creates an audit in the queue.
	 *
	 * @param array<string,mixed> $data Columns.
	 * @return array<string,mixed>|null The new record.
	 */
	public static function create( array $data ) {
		global $wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array_merge(
			$data,
			array(
				'token'      => bin2hex( random_bytes( 32 ) ),
				'status'     => 'queued',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return null;
		}
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Gets an audit by ID.
	 *
	 * @param int $id ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Gets an audit by its report token.
	 *
	 * @param string $token Token.
	 * @return array<string,mixed>|null
	 */
	public static function by_token( $token ) {
		global $wpdb;
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s', $token ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Updates an audit.
	 *
	 * @param int                 $id   ID.
	 * @param array<string,mixed> $data Columns. Null values are stored as NULL.
	 * @return void
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Deletes an audit.
	 *
	 * @param int $id ID.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Lists audits for the admin table and CSV export. Heavy columns are left out.
	 *
	 * @param array<string,mixed> $args status, search, orderby, order, limit, offset.
	 * @return array<int,array<string,mixed>>
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$args = array_merge(
			array(
				'status'  => '',
				'search'  => '',
				'orderby' => 'created_at',
				'order'   => 'DESC',
				'limit'   => 20,
				'offset'  => 0,
			),
			$args
		);

		$orderby = in_array( $args['orderby'], array( 'created_at', 'domain', 'score', 'status', 'lead_status', 'business_name', 'email' ), true ) ? $args['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		list( $where, $values ) = self::where( $args );

		$values[] = (int) $args['limit'];
		$values[] = (int) $args['offset'];

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, token, site_url, domain, business_name, email, source, status, stage, progress, score, previous_score, lead_status, notes, consent_at, error, created_at, completed_at FROM '
				. self::table() . " {$where} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d",
				$values
			),
			ARRAY_A
		);
	}

	/**
	 * Counts matching audits.
	 *
	 * @param array<string,mixed> $args status, search.
	 * @return int
	 */
	public static function count( array $args = array() ) {
		global $wpdb;
		list( $where, $values ) = self::where( $args );
		$sql                    = 'SELECT COUNT(*) FROM ' . self::table() . " {$where}";
		return (int) $wpdb->get_var( $values ? $wpdb->prepare( $sql, $values ) : $sql );
	}

	/**
	 * Builds a WHERE clause.
	 *
	 * @param array<string,mixed> $args status, search.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private static function where( array $args ) {
		global $wpdb;
		$clauses = array();
		$values  = array();
		if ( ! empty( $args['status'] ) ) {
			$clauses[] = 'status = %s';
			$values[]  = $args['status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$clauses[] = '(domain LIKE %s OR business_name LIKE %s OR email LIKE %s)';
			array_push( $values, $like, $like, $like );
		}
		return array( $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '', $values );
	}

	/**
	 * Takes the oldest queued audit. The conditional update is what stops two
	 * workers taking the same one.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function claim_next() {
		global $wpdb;
		for ( $try = 0; $try < 3; $try++ ) {
			$id = (int) $wpdb->get_var( 'SELECT id FROM ' . self::table() . " WHERE status = 'queued' ORDER BY updated_at ASC, id ASC LIMIT 1" );
			if ( ! $id ) {
				return null;
			}
			$now     = gmdate( 'Y-m-d H:i:s' );
			$claimed = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::table() . " SET status = 'running', locked_at = %s, updated_at = %s WHERE id = %d AND status = 'queued'",
					$now,
					$now,
					$id
				)
			);
			if ( $claimed ) {
				return self::get( $id );
			}
		}
		return null;
	}

	/**
	 * Counts audits being worked on right now.
	 *
	 * @return int
	 */
	public static function count_running() {
		return self::count( array( 'status' => 'running' ) );
	}

	/**
	 * Counts audits waiting for a worker.
	 *
	 * @return int
	 */
	public static function count_queued() {
		return self::count( array( 'status' => 'queued' ) );
	}

	/**
	 * Audits whose worker died.
	 *
	 * @param int $minutes Lock age.
	 * @return array<int,array<string,mixed>>
	 */
	public static function stale_running( $minutes ) {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, attempts FROM ' . self::table() . " WHERE status = 'running' AND locked_at < %s LIMIT 20",
				gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS )
			),
			ARRAY_A
		);
	}

	/**
	 * Deletes audits past the retention period, in batches.
	 *
	 * @param int $days Retention in days.
	 * @return int Rows removed.
	 */
	public static function delete_older_than( $days ) {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );
		$total  = 0;
		for ( $i = 0; $i < 50; $i++ ) {
			$removed = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s LIMIT 500', $cutoff ) );
			$total  += $removed;
			if ( $removed < 500 ) {
				break;
			}
		}
		return $total;
	}

	/**
	 * Finds audits by email for privacy requests.
	 *
	 * @param string $email  Email.
	 * @param int    $limit  Page size.
	 * @param int    $offset Offset.
	 * @return array<int,array<string,mixed>>
	 */
	public static function by_email( $email, $limit = 50, $offset = 0 ) {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, site_url, domain, business_name, email, notes, consent_at, created_at FROM ' . self::table() . ' WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d',
				$email,
				$limit,
				$offset
			),
			ARRAY_A
		);
	}
}
