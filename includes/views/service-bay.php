<?php
/**
 * Service Bay markup. The script fills it in; without JavaScript the
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
<div class="sar-bay" id="<?php echo esc_attr( $uid ); ?>" data-state="idle"<?php echo $style ? ' style="' . esc_attr( $style ) . '"' : ''; ?>>

	<!-- 01 + 02 · Scan and live inspection -->
	<section class="bay-hero" id="bay-scan" aria-labelledby="<?php echo esc_attr( $uid ); ?>-title">
		<div class="bay-wrap bay-hero__grid">
			<div class="bay-hero__copy">
				<p class="bay-pill"><span class="bay-pill__tag"><?php esc_html_e( 'Free', 'site-audit-report' ); ?></span><span><?php esc_html_e( 'WordPress health check · 20 seconds · no login', 'site-audit-report' ); ?></span></p>
				<h1 class="bay-h1" id="<?php echo esc_attr( $uid ); ?>-title"><?php echo wp_kses( __( 'Is your site one plugin away from a <em>breach?</em>', 'site-audit-report' ), array( 'em' => array() ) ); ?></h1>
				<p class="bay-lead"><?php echo wp_kses( __( 'Most hacked WordPress sites get in through a plugin nobody updated. Enter your address and we will read the <strong>core, theme and plugin versions your site shows the public</strong>, match them against known vulnerabilities and the CISA list of holes attackers are using right now, and price the fix.', 'site-audit-report' ), array( 'strong' => array() ) ); ?></p>

				<form class="bay-plate" novalidate>
					<label class="bay-sr" for="<?php echo esc_attr( $uid ); ?>-url"><?php esc_html_e( 'Your website address', 'site-audit-report' ); ?></label>
					<span class="bay-plate__scheme" aria-hidden="true">https://</span>
					<input id="<?php echo esc_attr( $uid ); ?>-url" class="bay-plate__input" name="url" type="text" inputmode="url" autocomplete="url" autocapitalize="none" spellcheck="false" maxlength="2048" placeholder="yourwebsite.com" required>
					<input class="bay-hp" type="text" name="<?php echo esc_attr( Service_Bay::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" aria-hidden="true">
					<button class="bay-btn bay-plate__go" type="submit"><span><?php esc_html_e( 'Run the check', 'site-audit-report' ); ?></span><span aria-hidden="true">↗</span></button>
				</form>
				<p class="bay-plate__msg" role="alert"></p>

				<div class="bay-try">
					<span class="bay-mono"><?php esc_html_e( 'Try:', 'site-audit-report' ); ?></span>
					<?php foreach ( $examples as $example ) : ?>
						<button type="button" class="bay-chip" data-try="<?php echo esc_attr( $example ); ?>"><?php echo esc_html( $example ); ?></button>
					<?php endforeach; ?>
					<button type="button" class="bay-chip" data-try="__self"><?php esc_html_e( 'this site', 'site-audit-report' ); ?></button>
				</div>
				<p class="bay-fine"><span class="bay-fine__dot" aria-hidden="true"></span><?php esc_html_e( 'Passive, public checks only. We never log in, never post anything and never change your site.', 'site-audit-report' ); ?></p>
			</div>

			<!-- Inspection console -->
			<div class="bay-console" aria-live="polite">
				<div class="bay-console__glow" aria-hidden="true"></div>
				<div class="bay-console__top">
					<span class="bay-status"><span class="bay-dot"></span><span class="bay-mono bay-mono--glow"><?php esc_html_e( 'Inspection console', 'site-audit-report' ); ?></span></span>
					<span class="bay-badge" data-bind="badge"><?php esc_html_e( 'Idle', 'site-audit-report' ); ?></span>
				</div>
				<div class="bay-console__target">
					<span class="bay-mono bay-mono--dim"><?php esc_html_e( 'Target', 'site-audit-report' ); ?></span>
					<strong class="bay-console__domain" data-bind="domain"><?php esc_html_e( 'yourwebsite.com', 'site-audit-report' ); ?></strong>
				</div>
				<div class="bay-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?php esc_attr_e( 'Inspection progress', 'site-audit-report' ); ?>">
					<span class="bay-meter__fill" data-bind="meter"></span>
				</div>
				<p class="bay-console__stage bay-mono" data-bind="stage"><?php esc_html_e( 'Waiting for an address…', 'site-audit-report' ); ?></p>

				<dl class="bay-spec">
					<div><dt><?php esc_html_e( 'Server', 'site-audit-report' ); ?></dt><dd data-spec="server">—</dd></div>
					<div><dt><?php esc_html_e( 'CDN', 'site-audit-report' ); ?></dt><dd data-spec="cdn">—</dd></div>
					<div><dt><?php esc_html_e( 'WordPress', 'site-audit-report' ); ?></dt><dd data-spec="core">—</dd></div>
					<div><dt><?php esc_html_e( 'Theme', 'site-audit-report' ); ?></dt><dd data-spec="theme">—</dd></div>
					<div><dt><?php esc_html_e( 'Plugins seen', 'site-audit-report' ); ?></dt><dd data-spec="plugins">—</dd></div>
					<div><dt><?php esc_html_e( 'HTTPS', 'site-audit-report' ); ?></dt><dd data-spec="https">—</dd></div>
				</dl>

				<div class="bay-board">
					<div class="bay-board__head">
						<span class="bay-mono bay-mono--glow"><?php esc_html_e( 'Check board', 'site-audit-report' ); ?></span>
						<span class="bay-mono bay-mono--dim" data-bind="boardCount"><?php esc_html_e( 'Passive', 'site-audit-report' ); ?></span>
					</div>
					<ol class="bay-board__list" data-bind="board"></ol>
				</div>
				<a class="bay-console__next" href="#bay-report" data-bind="toReport" hidden><span><?php esc_html_e( 'Read the risk report', 'site-audit-report' ); ?></span><span aria-hidden="true">↓</span></a>
			</div>
		</div>
	</section>

	<!-- Step rail -->
	<nav class="bay-rail" aria-label="<?php esc_attr_e( 'Steps', 'site-audit-report' ); ?>">
		<div class="bay-rail__inner">
			<?php
			$sar_steps = array(
				'bay-scan'   => __( 'Scan', 'site-audit-report' ),
				'bay-scan#c' => __( 'Inspection', 'site-audit-report' ),
				'bay-report' => __( 'Risk report', 'site-audit-report' ),
				'bay-plan'   => __( 'Fix plan', 'site-audit-report' ),
				'bay-book'   => __( 'Book', 'site-audit-report' ),
			);
			$sar_i     = 0;
			foreach ( $sar_steps as $sar_target => $sar_label ) :
				++$sar_i;
				?>
				<a class="bay-rail__step" href="#<?php echo esc_attr( strtok( $sar_target, '#' ) ); ?>" data-step="<?php echo (int) $sar_i; ?>"><span class="bay-rail__num"><?php echo esc_html( sprintf( '%02d', $sar_i ) ); ?></span><span><?php echo esc_html( $sar_label ); ?></span></a>
			<?php endforeach; ?>
		</div>
	</nav>

	<!-- 03 · Risk report -->
	<section class="bay-sec bay-sec--white" id="bay-report">
		<div class="bay-wrap">
			<div class="bay-head">
				<div>
					<p class="bay-eyebrow"><?php esc_html_e( '[ Step 03 · Risk report ]', 'site-audit-report' ); ?></p>
					<h2 class="bay-h2"><?php echo wp_kses( __( 'What the public can see, <em>and what it means.</em>', 'site-audit-report' ), array( 'em' => array() ) ); ?></h2>
				</div>
				<p class="bay-side"><?php esc_html_e( 'Every line below comes from what your site shows anyone who asks. A match means the version seen has a published hole, not that you have been broken into.', 'site-audit-report' ); ?></p>
			</div>

			<div class="bay-empty" data-bind="reportEmpty">
				<span class="bay-empty__icon" aria-hidden="true">⌁</span>
				<div>
					<h3 class="bay-h3"><?php esc_html_e( 'Your report prints here.', 'site-audit-report' ); ?></h3>
					<p class="bay-body"><?php esc_html_e( 'Run a check at the top. The sheet fills in as soon as the inspection finishes.', 'site-audit-report' ); ?></p>
				</div>
				<a class="bay-btn bay-btn--ghost" href="#bay-scan"><?php esc_html_e( 'Start the check', 'site-audit-report' ); ?></a>
			</div>

			<div class="bay-report" data-bind="report" hidden>
				<div class="bay-verdict" data-bind="verdict">
					<div class="bay-verdict__main">
						<p class="bay-mono bay-mono--glow" data-bind="verdictKicker"></p>
						<h3 class="bay-verdict__title" data-bind="verdictTitle"></h3>
						<p class="bay-verdict__text" data-bind="verdictText"></p>
					</div>
					<div class="bay-tiles" data-bind="tiles"></div>
				</div>

				<div class="bay-sheet">
					<div class="bay-sheet__head">
						<h3 class="bay-h3"><?php esc_html_e( 'Core, theme and plugins', 'site-audit-report' ); ?></h3>
						<span class="bay-mono" data-bind="sheetMeta"></span>
					</div>
					<div class="bay-rows" data-bind="rows"></div>
				</div>

				<div class="bay-sheet" data-bind="issuesWrap">
					<div class="bay-sheet__head">
						<h3 class="bay-h3"><?php esc_html_e( 'Hardening and HTTPS', 'site-audit-report' ); ?></h3>
						<span class="bay-mono" data-bind="issuesMeta"></span>
					</div>
					<div class="bay-issues" data-bind="issues"></div>
				</div>

				<div class="bay-share">
					<div>
						<p class="bay-mono"><?php esc_html_e( 'Your report lives here', 'site-audit-report' ); ?></p>
						<code class="bay-share__url" data-bind="shareUrl"></code>
					</div>
					<button type="button" class="bay-btn bay-btn--ghost" data-action="copy"><?php esc_html_e( 'Copy link', 'site-audit-report' ); ?></button>
				</div>
				<p class="bay-method"><?php echo wp_kses( __( '<strong>How we read it:</strong> versions come from the page source, readme.txt and style.css files your site serves publicly. They are matched against <a href="https://www.wpvulnerability.net/" target="_blank" rel="noopener">WPVulnerability.net</a> and the <a href="https://www.cisa.gov/known-exploited-vulnerabilities-catalog" target="_blank" rel="noopener">CISA Known Exploited Vulnerabilities</a> list. Hidden versions cannot be matched, and premium plugins may be missing from public databases.', 'site-audit-report' ), array( 'strong' => array(), 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></p>
				<a class="bay-btn" href="#bay-plan"><span><?php esc_html_e( 'See the fix plan', 'site-audit-report' ); ?></span><span aria-hidden="true">↓</span></a>
			</div>
		</div>
	</section>

	<!-- 04 · Fix plan -->
	<section class="bay-sec bay-sec--canvas" id="bay-plan">
		<div class="bay-wrap">
			<div class="bay-head">
				<div>
					<p class="bay-eyebrow"><?php esc_html_e( '[ Step 04 · Fix plan ]', 'site-audit-report' ); ?></p>
					<h2 class="bay-h2"><?php echo wp_kses( __( 'Your plan is pre-loaded. <em>Tune it.</em>', 'site-audit-report' ), array( 'em' => array() ) ); ?></h2>
				</div>
				<p class="bay-side" data-bind="planIntro"><?php esc_html_e( 'Pick the services you want. Once your report is ready we pre-select what it asks for, and you can add or remove anything. The price follows.', 'site-audit-report' ); ?></p>
			</div>

			<div class="bay-plan">
				<div class="bay-tray" data-bind="tray"></div>
				<aside class="bay-ticket">
					<div class="bay-console__glow" aria-hidden="true"></div>
					<div class="bay-console__top">
						<span class="bay-status"><span class="bay-dot"></span><span class="bay-mono bay-mono--glow"><?php esc_html_e( 'Your plan', 'site-audit-report' ); ?></span></span>
						<span class="bay-badge" data-bind="ticketCount">0</span>
					</div>
					<ul class="bay-ticket__lines" data-bind="ticketLines"></ul>
					<div class="bay-ticket__total">
						<span class="bay-ticket__amount" data-bind="ticketTotal"><?php echo esc_html( $sar_currency . '0' ); ?></span>
						<span class="bay-mono bay-mono--dim"><?php echo esc_html( sprintf( /* translators: %s: period. */ __( 'per %s', 'site-audit-report' ), $sar_period ) ); ?></span>
					</div>
					<p class="bay-ticket__note"><?php esc_html_e( 'The first cycle patches everything on your report. No lock-in: cancel with one email.', 'site-audit-report' ); ?></p>
					<a class="bay-btn bay-btn--glow" href="#bay-book"><span><?php esc_html_e( 'Book this plan', 'site-audit-report' ); ?></span><span aria-hidden="true">↗</span></a>
				</aside>
			</div>
		</div>
	</section>

	<!-- 05 · Book -->
	<section class="bay-sec bay-sec--white" id="bay-book">
		<div class="bay-wrap bay-book">
			<div>
				<p class="bay-eyebrow"><?php esc_html_e( '[ Step 05 · Book ]', 'site-audit-report' ); ?></p>
				<h2 class="bay-h2"><?php echo wp_kses( __( 'Book the fix. <em>We take it from here.</em>', 'site-audit-report' ), array( 'em' => array() ) ); ?></h2>
				<ol class="bay-next">
					<li><span class="bay-next__num">01</span><div><h3 class="bay-h3"><?php esc_html_e( 'We read your report', 'site-audit-report' ); ?></h3><p class="bay-body"><?php esc_html_e( 'A senior engineer reviews the findings the same working day. Anything critical is patched first, often before we speak.', 'site-audit-report' ); ?></p></div></li>
					<li><span class="bay-next__num">02</span><div><h3 class="bay-h3"><?php esc_html_e( 'A 15-minute call', 'site-audit-report' ); ?></h3><p class="bay-body"><?php esc_html_e( 'At a time you pick. We confirm access, agree the plan and answer questions. No sales script.', 'site-audit-report' ); ?></p></div></li>
					<li><span class="bay-next__num">03</span><div><h3 class="bay-h3"><?php esc_html_e( 'Fixed within a week', 'site-audit-report' ); ?></h3><p class="bay-body"><?php esc_html_e( 'Staging first, then live. You get the report again, all green, and a written log of what changed.', 'site-audit-report' ); ?></p></div></li>
				</ol>
			</div>

			<div class="bay-formcard">
				<form class="bay-form" novalidate>
					<h3 class="bay-h3"><?php esc_html_e( 'Your details', 'site-audit-report' ); ?></h3>
					<div class="bay-form__grid">
						<label class="bay-field"><span><?php esc_html_e( 'Your name', 'site-audit-report' ); ?></span><input name="name" type="text" autocomplete="name" maxlength="120" required></label>
						<label class="bay-field"><span><?php esc_html_e( 'Email', 'site-audit-report' ); ?></span><input name="email" type="email" autocomplete="email" maxlength="191" required></label>
						<label class="bay-field"><span><?php esc_html_e( 'Website', 'site-audit-report' ); ?></span><input name="website" type="text" inputmode="url" autocomplete="url" maxlength="2048" required></label>
						<label class="bay-field"><span><?php esc_html_e( 'Phone or WhatsApp', 'site-audit-report' ); ?></span><input name="phone" type="tel" autocomplete="tel" maxlength="40"></label>
						<label class="bay-field bay-field--wide"><span><?php esc_html_e( 'Anything we should know?', 'site-audit-report' ); ?></span><textarea name="notes" rows="3" maxlength="2000"></textarea></label>
					</div>
					<input class="bay-hp" type="text" name="<?php echo esc_attr( Service_Bay::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" aria-hidden="true">
					<p class="bay-attached"><span class="bay-mono"><?php esc_html_e( 'Attached', 'site-audit-report' ); ?></span> <span data-bind="attached"><?php esc_html_e( 'No services picked yet', 'site-audit-report' ); ?></span></p>
					<label class="bay-consent"><input type="checkbox" name="consent" value="1" required><span><?php echo esc_html( Service_Bay::consent_text() ); ?><?php if ( $privacy ) : ?> <a href="<?php echo esc_url( $privacy ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy policy', 'site-audit-report' ); ?></a><?php endif; ?></span></label>
					<button class="bay-btn bay-btn--block" type="submit"><span><?php esc_html_e( 'Book the service', 'site-audit-report' ); ?></span><span aria-hidden="true">↗</span></button>
					<p class="bay-form__msg" role="alert"></p>
				</form>
				<div class="bay-booked" hidden tabindex="-1">
					<span class="bay-booked__tick" aria-hidden="true">✓</span>
					<h3 class="bay-h2 bay-h2--sm"><?php echo wp_kses( __( 'Booked in. <em>Thank you.</em>', 'site-audit-report' ), array( 'em' => array() ) ); ?></h3>
					<p class="bay-body" data-bind="bookedText"></p>
					<ul class="bay-booked__list" data-bind="bookedList"></ul>
					<a class="bay-btn" data-bind="bookedCal" href="#" target="_blank" rel="noopener" hidden><span><?php esc_html_e( 'Pick a call time now', 'site-audit-report' ); ?></span><span aria-hidden="true">↗</span></a>
				</div>
			</div>
		</div>
	</section>
</div>
