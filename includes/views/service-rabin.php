<?php
/**
 * Security Check markup. The script fills it in; without JavaScript the
 * visitor sees the intro and the booking details.
 *
 * Variables: $uid, $examples, $privacy, $tools, $style.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

$sar_currency = (string) Settings::get( 'service_currency' );
$sar_period   = (string) Settings::get( 'service_period' );
?>
<div class="sar-rabin" id="<?php echo esc_attr( $uid ); ?>" data-state="idle"<?php echo $style ? ' style="' . esc_attr( $style ) . '"' : ''; ?>>

	<!-- 01 + 02 · Scan and live inspection -->
	<section class="rabin-hero" id="rabin-scan" aria-labelledby="<?php echo esc_attr( $uid ); ?>-title">
		<div class="rabin-wrap rabin-hero__grid">
			<div class="rabin-hero__copy">
				<p class="rabin-pill"><span class="rabin-pill__tag"><?php esc_html_e( 'Free', 'site-audit-report' ); ?></span><span><?php esc_html_e( 'WordPress security check · 20 seconds · no login', 'site-audit-report' ); ?></span></p>
				<h1 class="rabin-h1" id="<?php echo esc_attr( $uid ); ?>-title"><?php echo wp_kses( __( 'Is your site one plugin away from a <em>breach?</em>', 'site-audit-report' ), array( 'em' => array() ) ); ?></h1>
				<p class="rabin-lead"><?php echo wp_kses( __( 'Most hacked WordPress sites get in through a plugin nobody updated. Enter your address and we will read the <strong>core, theme and plugin versions your site shows the public</strong>, match them against known vulnerabilities and the CISA list of holes attackers are using right now, and price the fix.', 'site-audit-report' ), array( 'strong' => array() ) ); ?></p>

				<form class="rabin-plate" novalidate>
					<label class="rabin-sr" for="<?php echo esc_attr( $uid ); ?>-url"><?php esc_html_e( 'Your website address', 'site-audit-report' ); ?></label>
					<span class="rabin-plate__scheme" aria-hidden="true">https://</span>
					<input id="<?php echo esc_attr( $uid ); ?>-url" class="rabin-plate__input" name="url" type="text" inputmode="url" autocomplete="url" autocapitalize="none" spellcheck="false" maxlength="2048" placeholder="yourwebsite.com" required>
					<input class="rabin-hp" type="text" name="<?php echo esc_attr( Service_Rabin::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" aria-hidden="true">
					<button class="rabin-btn rabin-plate__go" type="submit"><span><?php esc_html_e( 'Run the check', 'site-audit-report' ); ?></span><span aria-hidden="true">↗</span></button>
				</form>
				<p class="rabin-plate__msg" role="alert"></p>

				<div class="rabin-try">
					<span class="rabin-mono"><?php esc_html_e( 'Try:', 'site-audit-report' ); ?></span>
					<?php foreach ( $examples as $example ) : ?>
						<button type="button" class="rabin-chip" data-try="<?php echo esc_attr( $example ); ?>"><?php echo esc_html( $example ); ?></button>
					<?php endforeach; ?>
					<?php if ( Url_Guard::home_is_public() ) : // A local or private address would only be refused. ?>
						<button type="button" class="rabin-chip" data-try="__self"><?php esc_html_e( 'this site', 'site-audit-report' ); ?></button>
					<?php endif; ?>
				</div>
				<p class="rabin-fine"><span class="rabin-fine__dot" aria-hidden="true"></span><?php esc_html_e( 'Passive, public checks only. We never log in, never post anything and never change your site.', 'site-audit-report' ); ?></p>
			</div>

			<!-- Inspection console -->
			<div class="rabin-console" aria-live="polite">
				<div class="rabin-console__glow" aria-hidden="true"></div>
				<div class="rabin-console__top">
					<span class="rabin-status"><span class="rabin-dot"></span><span class="rabin-mono rabin-mono--glow"><?php esc_html_e( 'Inspection console', 'site-audit-report' ); ?></span></span>
					<span class="rabin-badge" data-bind="badge"><?php esc_html_e( 'Idle', 'site-audit-report' ); ?></span>
				</div>
				<div class="rabin-console__target">
					<span class="rabin-mono rabin-mono--dim"><?php esc_html_e( 'Target', 'site-audit-report' ); ?></span>
					<strong class="rabin-console__domain" data-bind="domain"><?php esc_html_e( 'yourwebsite.com', 'site-audit-report' ); ?></strong>
				</div>
				<div class="rabin-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?php esc_attr_e( 'Inspection progress', 'site-audit-report' ); ?>">
					<span class="rabin-meter__fill" data-bind="meter"></span>
				</div>
				<p class="rabin-console__stage rabin-mono" data-bind="stage"><?php esc_html_e( 'Waiting for an address…', 'site-audit-report' ); ?></p>

				<dl class="rabin-spec">
					<div><dt><?php esc_html_e( 'Server', 'site-audit-report' ); ?></dt><dd data-spec="server">—</dd></div>
					<div><dt><?php esc_html_e( 'CDN', 'site-audit-report' ); ?></dt><dd data-spec="cdn">—</dd></div>
					<div><dt><?php esc_html_e( 'WordPress', 'site-audit-report' ); ?></dt><dd data-spec="core">—</dd></div>
					<div><dt><?php esc_html_e( 'Theme', 'site-audit-report' ); ?></dt><dd data-spec="theme">—</dd></div>
					<div><dt><?php esc_html_e( 'Plugins seen', 'site-audit-report' ); ?></dt><dd data-spec="plugins">—</dd></div>
					<div><dt><?php esc_html_e( 'HTTPS', 'site-audit-report' ); ?></dt><dd data-spec="https">—</dd></div>
				</dl>

				<div class="rabin-board">
					<div class="rabin-board__head">
						<span class="rabin-mono rabin-mono--glow"><?php esc_html_e( 'Check board', 'site-audit-report' ); ?></span>
						<span class="rabin-mono rabin-mono--dim" data-bind="boardCount"><?php esc_html_e( 'Passive', 'site-audit-report' ); ?></span>
					</div>
					<ol class="rabin-board__list" data-bind="board"></ol>
				</div>
				<a class="rabin-console__next" href="#rabin-report" data-bind="toReport" hidden><span><?php esc_html_e( 'Read the risk report', 'site-audit-report' ); ?></span><span aria-hidden="true">↓</span></a>
			</div>
		</div>
	</section>

	<!-- Step rail -->
	<nav class="rabin-rail" aria-label="<?php esc_attr_e( 'Steps', 'site-audit-report' ); ?>">
		<div class="rabin-rail__inner">
			<?php
			$sar_steps = array(
				'rabin-scan'   => __( 'Scan', 'site-audit-report' ),
				'rabin-scan#c' => __( 'Inspection', 'site-audit-report' ),
				'rabin-report' => __( 'Risk report', 'site-audit-report' ),
				'rabin-plan'   => __( 'Fix plan', 'site-audit-report' ),
				'rabin-book'   => __( 'Book', 'site-audit-report' ),
			);
			$sar_i     = 0;
			foreach ( $sar_steps as $sar_target => $sar_label ) :
				++$sar_i;
				?>
				<a class="rabin-rail__step" href="#<?php echo esc_attr( strtok( $sar_target, '#' ) ); ?>" data-step="<?php echo (int) $sar_i; ?>"><span class="rabin-rail__num"><?php echo esc_html( sprintf( '%02d', $sar_i ) ); ?></span><span><?php echo esc_html( $sar_label ); ?></span></a>
			<?php endforeach; ?>
		</div>
	</nav>

	<!-- 03 · Risk report -->
	<section class="rabin-sec rabin-sec--white" id="rabin-report">
		<div class="rabin-wrap">
			<div class="rabin-head">
				<div>
					<p class="rabin-eyebrow"><?php esc_html_e( '[ Step 03 · Risk report ]', 'site-audit-report' ); ?></p>
					<h2 class="rabin-h2"><?php echo wp_kses( __( 'What the public can see, <em>and what it means.</em>', 'site-audit-report' ), array( 'em' => array() ) ); ?></h2>
				</div>
				<p class="rabin-side"><?php esc_html_e( 'Every line below comes from what your site shows anyone who asks. A match means the version seen has a published hole, not that you have been broken into.', 'site-audit-report' ); ?></p>
			</div>

			<div class="rabin-empty" data-bind="reportEmpty">
				<span class="rabin-empty__icon" aria-hidden="true">⌁</span>
				<div>
					<h3 class="rabin-h3"><?php esc_html_e( 'Your report prints here.', 'site-audit-report' ); ?></h3>
					<p class="rabin-body"><?php esc_html_e( 'Run a check at the top. The sheet fills in as soon as the inspection finishes.', 'site-audit-report' ); ?></p>
				</div>
				<a class="rabin-btn rabin-btn--ghost" href="#rabin-scan"><?php esc_html_e( 'Start the check', 'site-audit-report' ); ?></a>
			</div>

			<div class="rabin-report" data-bind="report" hidden>
				<div class="rabin-verdict" data-bind="verdict">
					<div class="rabin-verdict__main">
						<p class="rabin-mono rabin-mono--glow" data-bind="verdictKicker"></p>
						<h3 class="rabin-verdict__title" data-bind="verdictTitle"></h3>
						<p class="rabin-verdict__text" data-bind="verdictText"></p>
					</div>
					<div class="rabin-tiles" data-bind="tiles"></div>
				</div>

				<div class="rabin-sheet">
					<div class="rabin-sheet__head">
						<h3 class="rabin-h3"><?php esc_html_e( 'Core, theme and plugins', 'site-audit-report' ); ?></h3>
						<span class="rabin-mono" data-bind="sheetMeta"></span>
					</div>
					<div class="rabin-rows" data-bind="rows"></div>
				</div>

				<div class="rabin-sheet" data-bind="issuesWrap">
					<div class="rabin-sheet__head">
						<h3 class="rabin-h3"><?php esc_html_e( 'Hardening and HTTPS', 'site-audit-report' ); ?></h3>
						<span class="rabin-mono" data-bind="issuesMeta"></span>
					</div>
					<div class="rabin-issues" data-bind="issues"></div>
				</div>

				<div class="rabin-share">
					<div>
						<p class="rabin-mono"><?php esc_html_e( 'Your report lives here', 'site-audit-report' ); ?></p>
						<code class="rabin-share__url" data-bind="shareUrl"></code>
					</div>
					<button type="button" class="rabin-btn rabin-btn--ghost" data-action="copy"><?php esc_html_e( 'Copy link', 'site-audit-report' ); ?></button>
				</div>
				<p class="rabin-method"><?php echo wp_kses( __( '<strong>How we read it:</strong> versions come from the page source, readme.txt and style.css files your site serves publicly. They are matched against <a href="https://www.wpvulnerability.net/" target="_blank" rel="noopener">WPVulnerability.net</a> and the <a href="https://www.cisa.gov/known-exploited-vulnerabilities-catalog" target="_blank" rel="noopener">CISA Known Exploited Vulnerabilities</a> list. Hidden versions cannot be matched, and premium plugins may be missing from public databases.', 'site-audit-report' ), array( 'strong' => array(), 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></p>
				<a class="rabin-btn" href="#rabin-plan"><span><?php esc_html_e( 'See the fix plan', 'site-audit-report' ); ?></span><span aria-hidden="true">↓</span></a>
			</div>
		</div>
	</section>

	<!-- 04 · Fix plan -->
	<section class="rabin-sec rabin-sec--canvas" id="rabin-plan">
		<div class="rabin-wrap">
			<div class="rabin-head">
				<div>
					<p class="rabin-eyebrow"><?php esc_html_e( '[ Step 04 · Fix plan ]', 'site-audit-report' ); ?></p>
					<h2 class="rabin-h2"><?php echo wp_kses( __( 'Your plan is pre-loaded. <em>Tune it.</em>', 'site-audit-report' ), array( 'em' => array() ) ); ?></h2>
				</div>
				<p class="rabin-side" data-bind="planIntro"><?php esc_html_e( 'Pick the services you want. Once your report is ready we pre-select what it asks for, and you can add or remove anything. The price follows.', 'site-audit-report' ); ?></p>
			</div>

			<div class="rabin-plan">
				<div class="rabin-tray" data-bind="tray"></div>
				<aside class="rabin-ticket">
					<div class="rabin-console__glow" aria-hidden="true"></div>
					<div class="rabin-console__top">
						<span class="rabin-status"><span class="rabin-dot"></span><span class="rabin-mono rabin-mono--glow"><?php esc_html_e( 'Your plan', 'site-audit-report' ); ?></span></span>
						<span class="rabin-badge" data-bind="ticketCount">0</span>
					</div>
					<ul class="rabin-ticket__lines" data-bind="ticketLines"></ul>
					<div class="rabin-ticket__total">
						<span class="rabin-ticket__amount" data-bind="ticketTotal"><?php echo esc_html( $sar_currency . '0' ); ?></span>
						<span class="rabin-mono rabin-mono--dim"><?php echo esc_html( sprintf( /* translators: %s: period. */ __( 'per %s', 'site-audit-report' ), $sar_period ) ); ?></span>
					</div>
					<p class="rabin-ticket__note"><?php esc_html_e( 'The first cycle patches everything on your report. No lock-in: cancel with one email.', 'site-audit-report' ); ?></p>
					<a class="rabin-btn rabin-btn--glow" href="#rabin-book"><span><?php esc_html_e( 'Book this plan', 'site-audit-report' ); ?></span><span aria-hidden="true">↗</span></a>
				</aside>
			</div>
		</div>
	</section>

	<!-- 05 · Book -->
	<section class="rabin-sec rabin-sec--white" id="rabin-book">
		<div class="rabin-wrap rabin-book">
			<div>
				<p class="rabin-eyebrow"><?php esc_html_e( '[ Step 05 · Book ]', 'site-audit-report' ); ?></p>
				<h2 class="rabin-h2"><?php echo wp_kses( __( 'Book the fix. <em>We take it from here.</em>', 'site-audit-report' ), array( 'em' => array() ) ); ?></h2>
				<ol class="rabin-next">
					<li><span class="rabin-next__num">01</span><div><h3 class="rabin-h3"><?php esc_html_e( 'We read your report', 'site-audit-report' ); ?></h3><p class="rabin-body"><?php esc_html_e( 'A senior engineer reviews the findings the same working day. Anything critical is patched first, often before we speak.', 'site-audit-report' ); ?></p></div></li>
					<li><span class="rabin-next__num">02</span><div><h3 class="rabin-h3"><?php esc_html_e( 'A 15-minute call', 'site-audit-report' ); ?></h3><p class="rabin-body"><?php esc_html_e( 'At a time you pick. We confirm access, agree the plan and answer questions. No sales script.', 'site-audit-report' ); ?></p></div></li>
					<li><span class="rabin-next__num">03</span><div><h3 class="rabin-h3"><?php esc_html_e( 'Fixed within a week', 'site-audit-report' ); ?></h3><p class="rabin-body"><?php esc_html_e( 'Staging first, then live. You get the report again, all green, and a written log of what changed.', 'site-audit-report' ); ?></p></div></li>
				</ol>
			</div>

			<div class="rabin-formcard">
				<form class="rabin-form" novalidate>
					<h3 class="rabin-h3"><?php esc_html_e( 'Your details', 'site-audit-report' ); ?></h3>
					<div class="rabin-form__grid">
						<label class="rabin-field"><span><?php esc_html_e( 'Your name', 'site-audit-report' ); ?></span><input name="name" type="text" autocomplete="name" maxlength="120" required></label>
						<label class="rabin-field"><span><?php esc_html_e( 'Email', 'site-audit-report' ); ?></span><input name="email" type="email" autocomplete="email" maxlength="191" required></label>
						<label class="rabin-field"><span><?php esc_html_e( 'Website', 'site-audit-report' ); ?></span><input name="website" type="text" inputmode="url" autocomplete="url" maxlength="2048" required></label>
						<label class="rabin-field"><span><?php esc_html_e( 'Phone or WhatsApp', 'site-audit-report' ); ?></span><input name="phone" type="tel" autocomplete="tel" maxlength="40"></label>
						<label class="rabin-field rabin-field--wide"><span><?php esc_html_e( 'Anything we should know?', 'site-audit-report' ); ?></span><textarea name="notes" rows="3" maxlength="2000"></textarea></label>
					</div>
					<input class="rabin-hp" type="text" name="<?php echo esc_attr( Service_Rabin::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" aria-hidden="true">
					<p class="rabin-attached"><span class="rabin-mono"><?php esc_html_e( 'Attached', 'site-audit-report' ); ?></span> <span data-bind="attached"><?php esc_html_e( 'No services picked yet', 'site-audit-report' ); ?></span></p>
					<label class="rabin-consent"><input type="checkbox" name="consent" value="1" required><span><?php echo esc_html( Service_Rabin::consent_text() ); ?><?php if ( $privacy ) : ?> <a href="<?php echo esc_url( $privacy ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy policy', 'site-audit-report' ); ?></a><?php endif; ?></span></label>
					<button class="rabin-btn rabin-btn--block" type="submit"><span><?php esc_html_e( 'Book the service', 'site-audit-report' ); ?></span><span aria-hidden="true">↗</span></button>
					<p class="rabin-form__msg" role="alert"></p>
				</form>
				<div class="rabin-booked" hidden tabindex="-1">
					<span class="rabin-booked__tick" aria-hidden="true">✓</span>
					<h3 class="rabin-h2 rabin-h2--sm"><?php echo wp_kses( __( 'Booked in. <em>Thank you.</em>', 'site-audit-report' ), array( 'em' => array() ) ); ?></h3>
					<p class="rabin-body" data-bind="bookedText"></p>
					<ul class="rabin-booked__list" data-bind="bookedList"></ul>
					<a class="rabin-btn" data-bind="bookedCal" href="#" target="_blank" rel="noopener" hidden><span><?php esc_html_e( 'Pick a call time now', 'site-audit-report' ); ?></span><span aria-hidden="true">↗</span></a>
				</div>
			</div>
		</div>
	</section>
</div>
