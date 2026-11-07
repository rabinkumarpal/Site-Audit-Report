<?php
/**
 * Data shared between scan modules.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Scan context.
 */
final class Scan_Context {

	/**
	 * The audit record.
	 *
	 * @var array<string,mixed>
	 */
	public array $audit;

	/**
	 * What the crawler found.
	 *
	 * @var array<string,mixed>
	 */
	public array $data;

	/**
	 * Guarded HTTP client.
	 *
	 * @var Http_Client
	 */
	public Http_Client $http;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $audit Audit.
	 * @param array<string,mixed> $data  Crawl data.
	 * @param Http_Client         $http  Client.
	 */
	public function __construct( array $audit, array $data, Http_Client $http ) {
		$this->audit = $audit;
		$this->data  = $data;
		$this->http  = $http;
	}

	/**
	 * Whether the scanner could read the site's HTML.
	 *
	 * @return bool
	 */
	public function readable() {
		return ! empty( $this->data['readable'] );
	}

	/**
	 * Why the site's HTML could not be read.
	 *
	 * @return string
	 */
	public function unreadable_reason() {
		return (string) ( $this->data['unreadable_reason'] ?? __( 'The website could not be read.', 'site-audit-report' ) );
	}

	/**
	 * Parsed summaries of the sampled pages.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function pages() {
		return (array) ( $this->data['pages'] ?? array() );
	}

	/**
	 * The parsed home page.
	 *
	 * @return array<string,mixed>
	 */
	public function home() {
		$pages = $this->pages();
		return $pages[0] ?? array();
	}
}
