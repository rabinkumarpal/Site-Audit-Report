=== Site Audit Report ===
Contributors: rabinpal
Tags: seo audit, website audit, lead generation, pagespeed, site report
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.5.0
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

== WordPress Security Check ==

(Called "Service Bay" in early builds. The old `[site_audit_service]` shortcode still works.)

Add `[site_audit_security]` to a full-width page. Visitors enter only a website address and, in about 20 to 30 seconds, see:

1. **Inspection console**: server, CDN, WordPress version, theme, plugins seen, HTTPS, and a live board of passive checks.
2. **Risk report**: core, theme and plugin versions the site shows publicly, matched against WPVulnerability.net (free, no key) and the CISA Known Exploited Vulnerabilities list, with severity, CVE links, days public and the fixed version. Plus hardening checks: version leak, public usernames, XML-RPC, readme.html, uploads listing, public debug.log and login address.
3. **Fix plan**: services the report asks for are pre-selected with a reason. Visitors switch them on or off and the price follows.
4. **Booking**: name, email, website, phone and notes. The audit is marked "Booked a service" in Site Audits and the team gets an email (Notify email, or the site admin email).

The report has a private, shareable link (`?bay=<token>`). Checks of the same site within 30 minutes reuse the last result.

Shortcode attributes: `examples` (comma-separated example sites for the "Try" chips) and `offset` (height in px of a fixed site header, so the sticky step rail clears it).

Set prices, currency and an optional calendar link under Site Audits > Settings > Security Check. Restyle it by overriding the `--bay-*` CSS variables on `.sar-bay`. Developers can change the tray with the `sar_service_tools` filter, the inspection with `sar_service_modules`, and react to bookings with the `sar_service_booked` action.

Full audits now include a "WordPress health" section from the same module.

== SEO Inspector (single-page SEO report) ==

Add `[site_audit_seo]` to a full-width page. It is separate from the full audit: visitors paste any page address and get an instant on-page report in six tabs. Nothing is stored; results are cached for 10 minutes.

* **Insights**: every check with pass, warning or error, grouped (indexing, meta, content, images, links, schema, speed basics), with a 0 to 100 score.
* **Meta**: Google result and social share previews, title and description length bars, canonical, robots, X-Robots-Tag, language, viewport, robots.txt, sitemap, hreflang, Open Graph and X/Twitter tags.
* **Headers**: H1 to H6 counts and the full outline, flagging long (over 70 characters), empty, skipped-level and extra H1 headings. "Copy structure" copies the outline.
* **Images**: thumbnails with alt text, size and lazy-loading, filters for missing alt, and CSV export.
* **Links**: internal, external, nofollow and mail/tel links with anchor text, search, filters and CSV export.
* **Schema**: JSON-LD types and blocks (highlighted, copyable), microdata and RDFa, with links to Google's Rich Results test and the Schema.org validator.

Shortcode attributes: `examples`, `offset` (as for the Security Check), `cta_url` and `cta_label` (an optional call to action under the report). `?inspect=<url>` on the page runs a check straight away, so results can be shared. Visitors are limited to 40 inspections an hour.

== Vulnerability watch ==

Every WordPress site the plugin scans (full audits and security checks) is added to the watch: its core, theme and plugin versions are stored. Once a day the watch re-matches those versions against WPVulnerability.net and the CISA Known Exploited Vulnerabilities list. No site is scanned again.

* When a vulnerability is **newly published** for a version a site was running, or a known one is **newly added to the CISA exploited list**, an alert is recorded. Everything known at scan time is the baseline, so only real news raises alerts.
* Alerts appear under Site Audits > Vulnerability watch (with a count in the menu), grouped by vulnerability with every exposed lead, their contact details and Contacted / Dismiss buttons. "Check now" runs it on demand.
* The team gets one email digest per run (lead alerts email, or the site admin email).
* Optional: Settings > Vulnerability watch > Notify leads sends each affected lead a short security notice, only if they gave contact consent, once per issue. It is off by default.
* Developers can use the `sar_watch_alerts` action.

== Finding plugins through the REST API ==

Besides the files a home page loads, the WordPress check reads the public REST API index (`/wp-json/`). Plugins that register routes (WooCommerce, Yoast SEO, Jetpack, contact forms and many more) are found even when they load nothing on the home page, and their versions are read from readme.txt. Unknown routes only count if a matching plugin readme exists. Extend the namespace map with the `sar_rest_namespace_map` filter.

== Trust badges ==

Give a client a "Secured by <your name>" badge for their site under Site Audits > Trust badges (or "Create badge" on any audit row). Creating one runs its first check straight away; after that the site is re-checked every week.

* The client pastes one line of HTML (a linked image). It works on any website, WordPress or not.
* The badge shows **Verified <date>** (green), **Under review** (amber) or **Not verified** (grey). It turns grey if the badge is paused or deleted, has not been verified for 14 days, or is shown on a different website from the one it was issued for.
* Clicking it opens a public verification page with the status, the last verified date, what is checked and a strip of recent results.
* The weekly check covers HTTPS and the certificate, high or critical and actively exploited (CISA) vulnerabilities in the versions the site shows, a public debug log and a browsable uploads folder.
* The reasons for "Under review" are never shown publicly. The team gets them by email when a badge changes state, and on the Trust badges screen.

== Accessibility quick check ==

The SEO Inspector has an Accessibility tab, and full audits have an Accessibility section. Both read the page's HTML and check 17 things against WCAG: page language and title, image alt text, link and button names (including icon-only ones), form labels (and placeholder-only fields), pinch-zoom, focusable items hidden with aria-hidden, the main landmark, heading structure, a skip link, vague link text such as "read more", iframe titles, positive tabindex, autoplaying sound, duplicate IDs used by labels or ARIA, and data-table headers. Each finding shows its WCAG reference, the fix and the offending elements.

Colour contrast, keyboard traps and visible focus need a real browser, and the report says so.

== Change watch ==

Under Site Audits > Change watch (or "Watch changes" on any audit row), add the sites you look after. Adding one records a baseline; after that each site is fingerprinted weekly and compared with the week before.

* Urgent: site not reachable, HTTPS stopped working, certificate expiring within 14 days, noindex added, robots.txt blocking the site, canonical pointing to another website.
* Worth checking: home page address, title, description or H1 changed or emptied, text dropped by 30%, sitemap gone, analytics or structured data removed, security header removed, theme changed, WordPress or a plugin downgraded.
* Notes and good news: plugins added or removed, WordPress, theme and plugin updates, certificate renewed, headers added.

The team gets one email digest per run when urgent or notable changes are found. Each site keeps a dated history with before and after values. "Check all now" runs it on demand.

== You vs. a competitor ==

In the SEO Inspector (front end or wp-admin), click "+ Compare with a competitor" and enter their page. Both pages are inspected at the same time, then:

* A scoreboard shows both scores and who wins how many of 18 points: SEO and accessibility scores, errors, title and description length, a single H1, sections, words, images without alt, internal links, structured data, social tags, server response, HTML size, HTTPS, canonical, sitemap and indexability. Small differences in size, speed and counts count as level.
* "Where they beat you" lists each point they win with what to do about it. For structured data it names the schema types they use that you don't.
* "Where you lead" lists your advantages, which is useful in a sales call.
* The full side-by-side table, and a switch to show all seven tabs for either site.

The page address updates to `?inspect=<you>&vs=<them>`, so a comparison can be shared or bookmarked. If the competitor's page cannot be read, your own report still shows.

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

= 1.5.0 =
* New: You vs. a competitor in the SEO Inspector: 18-point scoreboard, "where they beat you" with fixes (including missing schema types), "where you lead", side-by-side table, detail switch and shareable links.

= 1.4.0 =
* New: Accessibility quick check (17 WCAG-mapped checks) as an SEO Inspector tab and a full-audit section.
* New: Change watch, weekly fingerprints of client sites with change history, severity and an email digest.
* Changed: plugin names in reports drop their marketing taglines.

= 1.3.0 =
* New: Trust badges, a self-checking "Secured by" badge with a public verification page, weekly re-checks, copy protection and private reasons.
* Fixed: very large files such as a 60 MB public debug.log no longer time out the check; file probes now use range requests.

= 1.2.0 =
* New: Vulnerability watch. Scanned sites' versions are re-checked daily against newly published vulnerabilities and the CISA exploited list, with an alerts screen, team digest and optional lead notices.
* New: the WordPress check finds plugins through the public REST API index, not only from home page files.
* Changed: vulnerability records carry a stable ID; the lookup cache was renewed.

= 1.1.0 =
* New: WordPress Security Check shortcode `[site_audit_security]`: WordPress vulnerability check, risk report, priced fix plan and booking.
* New: WordPress health module (core, theme and plugin versions vs WPVulnerability.net and CISA KEV, plus hardening checks), also used in full audits.
* New: "Booked a service" lead status and Security Check settings.
* New admin screens: Site Audits > SEO Inspector (runs the inspector in wp-admin, with "Queue a full audit"), Site Audits > Guide (live setup checklist, the three tools and their shortcodes, where results go, lead workflow, hooks) and a Site Audits dashboard widget.
* New: Source column and Source / Lead status filters in Audits and leads, plus an "Inspect SEO" row action.
* New: SEO Inspector shortcode `[site_audit_seo]`: instant single-page report with Insights, Meta, Headers, Images, Links and Schema tabs.

= 1.0.0 =
* First release.
