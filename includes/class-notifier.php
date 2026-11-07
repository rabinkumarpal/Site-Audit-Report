<?php
/**
 * Emails sent when an audit finishes.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Notifications.
 */
final class Notifier {

	/**
	 * Sends the report link to the lead and the new-lead note to the site team.
	 *
	 * @param array<string,mixed> $audit Audit.
	 * @return void
	 */
	public static function audit_complete( array $audit ) {
		$brand  = Settings::brand_name();
		$link   = Report_Route::url( (string) $audit['token'] );
		$domain = (string) $audit['domain'];

		$is_first_run = empty( $audit['email_sent_at'] ) && 'form' === $audit['source'];

		// The email holds only validated values and no free text from the form,
		// so the form cannot be used to send someone a message of a stranger's choosing.
		if ( $is_first_run && Settings::get( 'email_lead' ) && is_email( $audit['email'] ) ) {
			/* translators: %s: domain name. */
			$subject = sprintf( __( 'Your website audit for %s', 'site-audit-report' ), $domain );
			$body    = sprintf(
				/* translators: 1: domain name, 2: private report link, 3: business or site name. */
				__( "Your audit of %1\$s is ready:\n\n%2\$s\n\nThis private link is for you, so please share it only with people you trust.\n\nIf you did not request this audit, you can ignore this email.\n\n%3\$s", 'site-audit-report' ),
				$domain,
				$link,
				$brand
			);
			wp_mail( $audit['email'], $subject, $body );
			Audits::update( (int) $audit['id'], array( 'email_sent_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		}

		$notify = (string) Settings::get( 'notify_email' );
		if ( $is_first_run && '' !== $notify && is_email( $notify ) ) {
			/* translators: %s: domain name. */
			$subject = sprintf( __( 'New website audit lead: %s', 'site-audit-report' ), $domain );
			$body    = sprintf(
				/* translators: 1: domain, 2: business name, 3: email, 4: score, 5: admin link. */
				__( "Website: %1\$s\nBusiness: %2\$s\nEmail: %3\$s\nScore: %4\$s\n\nOpen in admin: %5\$s", 'site-audit-report' ),
				$domain,
				(string) $audit['business_name'],
				(string) $audit['email'],
				null === $audit['score'] ? __( 'not scored', 'site-audit-report' ) : (string) (int) $audit['score'],
				admin_url( 'admin.php?page=site-audit-report&audit=' . (int) $audit['id'] )
			);
			wp_mail( $notify, $subject, $body );
		}
	}
}
