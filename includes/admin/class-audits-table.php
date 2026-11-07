<?php
/**
 * The audits and leads table.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report\Admin;

use Site_Audit_Report\Audits;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table.
 */
final class Audits_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'audit',
				'plural'   => 'audits',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Lead statuses.
	 *
	 * @return array<string,string>
	 */
	public static function lead_statuses() {
		return array(
			'new'       => __( 'New', 'site-audit-report' ),
			'contacted' => __( 'Contacted', 'site-audit-report' ),
			'qualified' => __( 'Qualified', 'site-audit-report' ),
			'won'       => __( 'Won', 'site-audit-report' ),
			'lost'      => __( 'Lost', 'site-audit-report' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox">',
			'domain'      => __( 'Website', 'site-audit-report' ),
			'business'    => __( 'Business', 'site-audit-report' ),
			'email'       => __( 'Email', 'site-audit-report' ),
			'score'       => __( 'Score', 'site-audit-report' ),
			'status'      => __( 'Scan', 'site-audit-report' ),
			'lead_status' => __( 'Lead', 'site-audit-report' ),
			'created_at'  => __( 'Requested', 'site-audit-report' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_sortable_columns() {
		return array(
			'domain'      => array( 'domain', false ),
			'business'    => array( 'business_name', false ),
			'email'       => array( 'email', false ),
			'score'       => array( 'score', false ),
			'status'      => array( 'status', false ),
			'lead_status' => array( 'lead_status', false ),
			'created_at'  => array( 'created_at', true ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'site-audit-report' ) );
	}

	/**
	 * Uses plugin-specific names, because the form posts to admin-post.php.
	 *
	 * @param string $which Position.
	 * @return void
	 */
	protected function bulk_actions( $which = '' ) {
		$name = 'top' === $which ? 'bulk_action' : 'bulk_action2';
		$id   = 'sar-' . $name;
		printf( '<label for="%1$s" class="screen-reader-text">%2$s</label>', esc_attr( $id ), esc_html__( 'Select bulk action', 'site-audit-report' ) );
		printf( '<select name="%1$s" id="%2$s"><option value="">%3$s</option><option value="delete">%4$s</option></select> ', esc_attr( $name ), esc_attr( $id ), esc_html__( 'Bulk actions', 'site-audit-report' ), esc_html__( 'Delete', 'site-audit-report' ) );
		submit_button( __( 'Apply', 'site-audit-report' ), 'action', '', false );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_views() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$base    = admin_url( 'admin.php?page=' . Admin::SLUG );

		$views = array(
			'' => array( __( 'All', 'site-audit-report' ), Audits::count() ),
		);
		foreach (
			array(
				'complete' => __( 'Complete', 'site-audit-report' ),
				'running'  => __( 'Running', 'site-audit-report' ),
				'queued'   => __( 'Queued', 'site-audit-report' ),
				'failed'   => __( 'Failed', 'site-audit-report' ),
			) as $key => $label
		) {
			$views[ $key ] = array( $label, Audits::count( array( 'status' => $key ) ) );
		}

		$links = array();
		foreach ( $views as $key => $view ) {
			$url                                 = '' === $key ? $base : add_query_arg( 'status', $key, $base );
			$links[ '' === $key ? 'all' : $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( $url ),
				$current === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $view[0] ),
				(int) $view[1]
			);
		}
		return $links;
	}

	/**
	 * {@inheritDoc}
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only table state; values are checked against allow-lists in Audits::query().
		$args = array(
			'status'  => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'orderby' => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at',
			'order'   => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
		);
		// phpcs:enable

		$per_page = 20;
		$total    = Audits::count( $args );

		$this->items = Audits::query(
			array_merge(
				$args,
				array(
					'limit'  => $per_page,
					'offset' => ( $this->get_pagenum() - 1 ) * $per_page,
				)
			)
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'domain' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="sar-audit-%1$d">%2$s</label><input id="sar-audit-%1$d" type="checkbox" name="audit_ids[]" value="%1$d">',
			(int) $item['id'],
			esc_html( sprintf( /* translators: %s: domain. */ __( 'Select %s', 'site-audit-report' ), (string) $item['domain'] ) )
		);
	}

	/**
	 * Website column with row actions.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	protected function column_domain( $item ) {
		$id   = (int) $item['id'];
		$view = admin_url( 'admin.php?page=' . Admin::SLUG . '&audit=' . $id );

		$actions = array(
			'view'   => '<a href="' . esc_url( $view ) . '">' . esc_html__( 'View', 'site-audit-report' ) . '</a>',
			'rerun'  => '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sar_rerun&audit=' . $id ), 'sar_rerun_' . $id ) ) . '">' . esc_html__( 'Re-run', 'site-audit-report' ) . '</a>',
			'delete' => '<a class="submitdelete" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sar_delete&audit=' . $id ), 'sar_delete_' . $id ) ) . '">' . esc_html__( 'Delete', 'site-audit-report' ) . '</a>',
		);

		return '<strong><a class="row-title" href="' . esc_url( $view ) . '">' . esc_html( (string) $item['domain'] ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/**
	 * Business column.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	protected function column_business( $item ) {
		return esc_html( (string) $item['business_name'] );
	}

	/**
	 * Email column.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	protected function column_email( $item ) {
		return esc_html( (string) $item['email'] );
	}

	/**
	 * Score column.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	protected function column_score( $item ) {
		return null === $item['score'] ? esc_html__( 'No score', 'site-audit-report' ) : '<strong>' . esc_html( (string) (int) $item['score'] ) . '</strong>';
	}

	/**
	 * Scan status column.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	protected function column_status( $item ) {
		$labels = array(
			'queued'   => __( 'Queued', 'site-audit-report' ),
			'running'  => __( 'Running', 'site-audit-report' ),
			'complete' => __( 'Complete', 'site-audit-report' ),
			'failed'   => __( 'Failed', 'site-audit-report' ),
		);
		return esc_html( $labels[ $item['status'] ] ?? (string) $item['status'] );
	}

	/**
	 * Lead status column.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	protected function column_lead_status( $item ) {
		$labels = self::lead_statuses();
		return esc_html( $labels[ $item['lead_status'] ] ?? (string) $item['lead_status'] );
	}

	/**
	 * Date column.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	protected function column_created_at( $item ) {
		return esc_html( get_date_from_gmt( (string) $item['created_at'] ) );
	}

	/**
	 * Shown when there are no rows.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No audits yet.', 'site-audit-report' );
	}
}
