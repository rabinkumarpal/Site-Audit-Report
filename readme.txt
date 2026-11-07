=== Site Audit Report ===
Contributors: rabinpal
Tags: seo audit, website audit, lead generation, pagespeed, site report
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scan any public website and business, then produce a scored audit report with prioritised fixes. Includes a lead form, private report links and a team workspace.

== Description ==

Visitors enter a website address, business name and email. A background worker scans the site, the form shows an early score, and the full report opens on a private link that can also be downloaded. Your team gets a list of audits and leads, notes, re-runs and CSV export.

The plugin stands alone. It needs no particular theme, page builder or other plugin.

**What it checks**

* On-page SEO: titles, descriptions, headings, canonical tags, image alt text, structured data, viewport, language, sharing tags and amount of content.
* Crawling and links: robots.txt, sitemap, noindex, redirects, and broken links on a limited sample.
* Security and privacy: HTTPS, certificate expiry, mixed content, security headers, privacy and cookie pages. Checks are passive and read public responses only.
* Marketing presence: contact options, social profiles, analytics tools and signs of recent activity.
* Speed and accessibility: Google PageSpeed Insights scores on mobile and desktop, and Core Web Vitals when Google has real-visitor data.
* Reputation and local: Google rating, review count and profile details, and whether name, address and phone match the website.

**Honest by design**

A check that could not be measured is marked "not checked", says why, and does not affect any score. If a site cannot be read at all (for example behind a firewall), the report gives no overall score. A Google rating is shown only when the profile can be tied to the website; a possible match is never shown as fact.

== Installation ==

1. In WordPress go to Plugins > Add New > Upload Plugin, choose the zip and activate it.
2. Go to Site Audits > Settings. Set your name, logo, accent colour and call to action.
3. Add the form to a page with the "Site Audit Form" block or the shortcode `[site_audit_form]`. The shortcode also accepts `heading`, `intro` and `button`.
4. Add a PageSpeed Insights key (next section). Without one, the speed section will usually say "not checked".
5. Test email delivery: submit the form with your own address and confirm the report link arrives. Delivery depends on your site's mail setup.
6. Mention the tool in your privacy policy. Suggested wording is under Settings > Privacy > Policy Guide.

== API keys ==

Both keys are optional and are stored in the WordPress options table. The scan works without them; those sections are marked "not checked".

**PageSpeed Insights (free)**

1. Open https://console.cloud.google.com/ and create or choose a project.
2. Go to APIs & Services > Library, find "PageSpeed Insights API" and enable it.
3. Go to APIs & Services > Credentials > Create credentials > API key.
4. Restrict the key to the PageSpeed Insights API.
5. Paste it in Site Audits > Settings.

Without a key, requests share Google's anonymous quota, which is often already used up. Google's own free quota for a key has been ample for most small sites; check the current figure in your Google Cloud console.

**Places API (may be billed)**

1. In the same project, enable "Places API (New)". Google requires a billing account for this API.
2. Create an API key and restrict it to Places API (New).
3. Set a budget alert. Each audit makes one Text Search request.
4. Paste it in Site Audits > Settings.

Google's terms limit how long some Places content may be stored, and require attribution. Reports credit Google and link to the Maps listing. Set your retention period with these terms in mind, and check them yourself before relying on this section commercially.

== Limits and safeguards ==

Each of these is a setting unless noted.

* Public audits: 5 per visitor per hour, 3 per website per day, 5 per email per day, 200 in total per day. Limits are not applied to audits your team starts in the admin.
* Each scan reads up to 5 pages (maximum 10) and tests up to 25 links (maximum 50).
* Fixed limits: 150 requests per scan, 2 MB per page, 10 to 20 seconds per request, 5 redirects, about 8 minutes per scan, ports 80 and 443 only.
* One scan runs at a time, because each holds a PHP worker. On a larger server use the `sar_max_concurrent_scans` filter.
* Audits and leads are deleted after 180 days. Set this under Data and email; 0 keeps them until you delete them.

**Requests to private networks are blocked.** Every address and every redirect is checked. All of a host's DNS answers must be public, and the connection is then pinned to the checked IP to stop DNS rebinding. Loopback, private, link-local, carrier-grade NAT, metadata and reserved ranges (IPv4 and IPv6) are refused, as are obfuscated IP formats and non-http schemes. No setting or filter turns this off. If your site sends outgoing requests through a proxy (`WP_PROXY_HOST`), pinning applies to the proxy connection rather than the target, so scanning should be reviewed before use there.

**Behind Cloudflare or another proxy?** All visitors can appear to share one IP, and the per-visitor limit would then hit everyone. Choose the matching header under Limits > Visitor IP source. Only do this if the proxy is really there, because otherwise visitors can fake the header.

**What it cannot see.** The scan reads the HTML a server sends, so content added later by JavaScript is missed (the Google tests do run JavaScript). Page checks cover a sample, not the whole site. Analytics loaded after consent or through a tag manager is not seen. Bot protection can block the scan, which the report states as such. PDF is through the browser's print dialog; the download is a self-contained HTML file.

== Background processing ==

The form request only queues a scan. Workers start through a signed non-blocking request to the site itself, with WP-Cron as a fallback, and resume where they left off if a host ends them early.

If your host blocks requests from a site to itself, or WP-Cron is disabled, scans can wait in the queue. Use a real cron job instead:

`* * * * * wp site-audit-report run-queue --path=/path/to/wordpress`

== Privacy ==

The plugin stores the website address, business name, email, consent time and exact consent wording. Raw IP addresses are not stored; a keyed one-way hash is used for rate limits. Website addresses are sent to Google PageSpeed Insights on every scan, and business names to Google Places when a Places key is set. WordPress's personal data export and erase tools cover audit records. Report links are unguessable and marked noindex, but anyone holding the link can open that report.

Deleting the plugin removes its tables, settings and scheduled events. Deactivating it keeps them.

== For developers ==

Add a section, such as rankings, competitors, backlinks, AI visibility or an AI summary, from a separate plugin. Checks you return are scored, prioritised and rendered automatically.

`
add_filter( 'sar_modules', function ( $modules ) {
	$modules[] = new My_Backlinks_Module();
	return $modules;
} );

class My_Backlinks_Module implements Site_Audit_Report\Module {
	public function id() { return 'my_backlinks'; }
	public function label() { return 'Checking backlinks'; }
	public function run( Site_Audit_Report\Scan_Context $context ) {
		if ( ! my_backlinks_key() ) {
			return array(
				Site_Audit_Report\Check::not_checked( 'seo', 'backlinks', 'Backlinks', 3, 'No data provider is connected.' ),
			);
		}
		return array(
			Site_Audit_Report\Check::make(
				Site_Audit_Report\Check::WARN, 'seo', 'backlinks', 'Backlinks', 3,
				'Found 12 referring domains.',
				array( 'fix' => 'Earn links from local directories.', 'effort' => 'high' )
			),
		);
	}
}
`

Filters: `sar_modules`, `sar_categories` (add a report section with its own weight), `sar_capability` (who can use the admin, default `manage_options`), `sar_max_concurrent_scans`, `sar_resolved_ips` (results still pass the public-IP check).

Actions: `sar_audit_completed` (audit record, report data).

Use `$context->http` for any request to an address taken from a scanned site. It applies the private-network protection; `wp_remote_get()` does not. Requests to a fixed vendor API can use the normal HTTP functions.

== Changelog ==

= 1.0.0 =
* First release.
