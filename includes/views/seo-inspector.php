<?php
/**
 * SEO Inspector markup. The script fills in the report.
 *
 * Variables: $uid, $examples, $style, $cta_url, $cta_text, $compact.
 *
 * @package Site_Audit_Report
 */

namespace Site_Audit_Report;

defined( 'ABSPATH' ) || exit;

$sar_tabs = array(
	'insights' => __( 'Insights', 'site-audit-report' ),
	'meta'     => __( 'Meta', 'site-audit-report' ),
	'headers'  => __( 'Headers', 'site-audit-report' ),
	'images'   => __( 'Images', 'site-audit-report' ),
	'links'    => __( 'Links', 'site-audit-report' ),
	'schema'   => __( 'Schema', 'site-audit-report' ),
	'a11y'     => __( 'Accessibility', 'site-audit-report' ),
);
?>
<div class="sar-rabin sar-seo<?php echo $compact ? ' sar-seo--compact' : ''; ?>" id="<?php echo esc_attr( $uid ); ?>" data-state="idle"<?php echo $style ? ' style="' . esc_attr( $style ) . '"' : ''; ?>>

	<section class="rabin-hero" id="seo-scan">
		<div class="rabin-wrap rabin-hero__grid">
			<div class="rabin-hero__copy">
				<p class="rabin-pill"><span class="rabin-pill__tag"><?php esc_html_e( 'Free', 'site-audit-report' ); ?></span><span><?php esc_html_e( 'On-page SEO inspector · any URL · instant', 'site-audit-report' ); ?></span></p>
				<h1 class="rabin-h1"><?php echo wp_kses( __( 'See your page the way <em>Google does.</em>', 'site-audit-report' ), array( 'em' => array() ) ); ?></h1>
				<p class="rabin-lead"><?php echo wp_kses( __( 'Paste any page address. In a few seconds you get its <strong>title and meta tags, heading outline, images, links and structured data</strong>, with every problem flagged and the fix spelled out.', 'site-audit-report' ), array( 'strong' => array() ) ); ?></p>

				<form class="rabin-plate" novalidate>
					<label class="rabin-sr" for="<?php echo esc_attr( $uid ); ?>-url"><?php esc_html_e( 'Page address', 'site-audit-report' ); ?></label>
					<span class="rabin-plate__scheme" aria-hidden="true">https://</span>
					<input id="<?php echo esc_attr( $uid ); ?>-url" class="rabin-plate__input" name="url" type="text" inputmode="url" autocomplete="url" autocapitalize="none" spellcheck="false" maxlength="2048" placeholder="yourwebsite.com/any-page" required>
					<button class="rabin-btn rabin-plate__go" type="submit"><span><?php esc_html_e( 'Inspect page', 'site-audit-report' ); ?></span><span aria-hidden="true">↗</span></button>
				</form>
				<div class="seo-vs" hidden>
					<span class="seo-vs__tag" aria-hidden="true">VS</span>
					<label class="rabin-sr" for="<?php echo esc_attr( $uid ); ?>-vs"><?php esc_html_e( 'Competitor page address', 'site-audit-report' ); ?></label>
					<input id="<?php echo esc_attr( $uid ); ?>-vs" class="seo-vs__input" type="text" inputmode="url" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="2048" placeholder="<?php esc_attr_e( 'competitor.com/their-page', 'site-audit-report' ); ?>">
					<button type="button" class="seo-vs__x" data-action="vs-off" aria-label="<?php esc_attr_e( 'Remove competitor', 'site-audit-report' ); ?>">×</button>
				</div>
				<button type="button" class="seo-vs-toggle" data-action="vs-on"><span aria-hidden="true">+</span> <?php esc_html_e( 'Compare with a competitor', 'site-audit-report' ); ?></button>
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
				<p class="rabin-fine"><span class="rabin-fine__dot" aria-hidden="true"></span><?php esc_html_e( 'Reads one public page, its robots.txt and sitemap. Nothing is stored.', 'site-audit-report' ); ?></p>
			</div>

			<div class="rabin-console" aria-live="polite">
				<div class="rabin-console__glow" aria-hidden="true"></div>
				<div class="rabin-console__top">
					<span class="rabin-status"><span class="rabin-dot"></span><span class="rabin-mono rabin-mono--glow"><?php esc_html_e( 'SEO snapshot', 'site-audit-report' ); ?></span></span>
					<span class="rabin-badge" data-bind="badge"><?php esc_html_e( 'Idle', 'site-audit-report' ); ?></span>
				</div>
				<div class="seo-score">
					<div class="seo-ring" data-bind="ring" style="--p:0">
						<svg viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="52" class="seo-ring__track"/><circle cx="60" cy="60" r="52" class="seo-ring__bar" pathLength="100"/></svg>
						<div class="seo-ring__num"><b data-bind="score">—</b><span><?php esc_html_e( 'score', 'site-audit-report' ); ?></span></div>
					</div>
					<div class="seo-score__side">
						<span class="rabin-mono rabin-mono--dim"><?php esc_html_e( 'Page', 'site-audit-report' ); ?></span>
						<strong class="rabin-console__domain seo-target" data-bind="target"><?php esc_html_e( 'yourwebsite.com', 'site-audit-report' ); ?></strong>
						<div class="seo-counts">
							<span class="seo-count seo-count--fail"><b data-bind="nFail">0</b> <?php esc_html_e( 'errors', 'site-audit-report' ); ?></span>
							<span class="seo-count seo-count--warn"><b data-bind="nWarn">0</b> <?php esc_html_e( 'warnings', 'site-audit-report' ); ?></span>
							<span class="seo-count seo-count--pass"><b data-bind="nPass">0</b> <?php esc_html_e( 'passed', 'site-audit-report' ); ?></span>
						</div>
					</div>
				</div>
				<p class="rabin-console__stage rabin-mono" data-bind="stage"><?php esc_html_e( 'Waiting for an address…', 'site-audit-report' ); ?></p>
				<dl class="rabin-spec">
					<div><dt><?php esc_html_e( 'Status', 'site-audit-report' ); ?></dt><dd data-spec="status">—</dd></div>
					<div><dt><?php esc_html_e( 'Response', 'site-audit-report' ); ?></dt><dd data-spec="time">—</dd></div>
					<div><dt><?php esc_html_e( 'Words', 'site-audit-report' ); ?></dt><dd data-spec="words">—</dd></div>
					<div><dt><?php esc_html_e( 'H1 / H2 / H3', 'site-audit-report' ); ?></dt><dd data-spec="heads">—</dd></div>
					<div><dt><?php esc_html_e( 'Images', 'site-audit-report' ); ?></dt><dd data-spec="images">—</dd></div>
					<div><dt><?php esc_html_e( 'Links in / out', 'site-audit-report' ); ?></dt><dd data-spec="links">—</dd></div>
				</dl>
				<?php if ( $compact && is_admin() ) : ?>
					<a class="rabin-console__next seo-queue" href="#" data-bind="queueFull" hidden><span><?php esc_html_e( 'Queue a full audit of this site', 'site-audit-report' ); ?></span><span aria-hidden="true">→</span></a>
				<?php endif; ?>
				<a class="rabin-console__next" href="#seo-report" data-bind="toReport" hidden><span><?php esc_html_e( 'Open the full breakdown', 'site-audit-report' ); ?></span><span aria-hidden="true">↓</span></a>
			</div>
		</div>
	</section>

	<section class="rabin-sec rabin-sec--white" id="seo-report">
		<div class="rabin-wrap">
			<div class="rabin-head">
				<div>
					<p class="rabin-eyebrow"><?php esc_html_e( '[ On-page report ]', 'site-audit-report' ); ?></p>
					<h2 class="rabin-h2"><?php echo wp_kses( __( 'Six tabs. <em>Every tag on the page.</em>', 'site-audit-report' ), array( 'em' => array() ) ); ?></h2>
				</div>
				<p class="rabin-side" data-bind="reportSide"><?php esc_html_e( 'Insights sums up what to fix first. The other tabs show the raw evidence: every meta tag, heading, image, link and schema block we found.', 'site-audit-report' ); ?></p>
			</div>

			<div class="rabin-empty" data-bind="empty">
				<span class="rabin-empty__icon" aria-hidden="true">⌕</span>
				<div>
					<h3 class="rabin-h3"><?php esc_html_e( 'Your breakdown appears here.', 'site-audit-report' ); ?></h3>
					<p class="rabin-body"><?php esc_html_e( 'Inspect a page at the top to fill in all six tabs.', 'site-audit-report' ); ?></p>
				</div>
				<a class="rabin-btn rabin-btn--ghost" href="#seo-scan"><?php esc_html_e( 'Inspect a page', 'site-audit-report' ); ?></a>
			</div>

			<div class="seo-report" data-bind="report" hidden>
				<div class="seo-compare" data-bind="compare" hidden></div>
				<div class="seo-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Report sections', 'site-audit-report' ); ?>">
					<?php foreach ( $sar_tabs as $sar_id => $sar_label ) : ?>
						<button type="button" class="seo-tab" role="tab" id="<?php echo esc_attr( $uid . '-tab-' . $sar_id ); ?>" aria-controls="<?php echo esc_attr( $uid . '-panel-' . $sar_id ); ?>" aria-selected="<?php echo 'insights' === $sar_id ? 'true' : 'false'; ?>" data-tab="<?php echo esc_attr( $sar_id ); ?>" tabindex="<?php echo 'insights' === $sar_id ? '0' : '-1'; ?>"><span><?php echo esc_html( $sar_label ); ?></span><span class="seo-tab__n" data-count="<?php echo esc_attr( $sar_id ); ?>"></span></button>
					<?php endforeach; ?>
				</div>
				<?php foreach ( $sar_tabs as $sar_id => $sar_label ) : ?>
					<div class="seo-panel" role="tabpanel" id="<?php echo esc_attr( $uid . '-panel-' . $sar_id ); ?>" aria-labelledby="<?php echo esc_attr( $uid . '-tab-' . $sar_id ); ?>" data-panel="<?php echo esc_attr( $sar_id ); ?>" tabindex="0"<?php echo 'insights' === $sar_id ? '' : ' hidden'; ?>></div>
				<?php endforeach; ?>
			</div>

			<?php if ( '' !== $cta_url ) : ?>
				<div class="seo-cta" data-bind="cta" hidden>
					<div>
						<p class="rabin-mono rabin-mono--glow"><?php esc_html_e( '[ One page down ]', 'site-audit-report' ); ?></p>
						<h3 class="seo-cta__title"><?php echo wp_kses( __( 'Want this for <em>every page</em>, plus speed, security and local?', 'site-audit-report' ), array( 'em' => array() ) ); ?></h3>
					</div>
					<a class="rabin-btn rabin-btn--glow" href="<?php echo esc_url( $cta_url ); ?>"><span><?php echo esc_html( $cta_text ); ?></span><span aria-hidden="true">↗</span></a>
				</div>
			<?php endif; ?>
		</div>
	</section>
</div>
