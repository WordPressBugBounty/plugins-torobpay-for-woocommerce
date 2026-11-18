<?php
/**
 * Admin UI: "Slider" tab content for the TorobPay visual settings page.
 *
 * Included by torobpay_slider_render_tab(). Runs inside the settings <form>
 * (which already prints the page nonce) on the torobpay-widget page.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$torobpay_slider_placements    = torobpay_slider_get_placements();
$torobpay_preview_placement    = $torobpay_slider_placements[0];
$torobpay_preview_initial_html = torobpay_slider_get_html(
	$torobpay_preview_placement,
	array(
		'slider_id'      => 'torobpay-slider-preview',
		'preview_device' => 'desktop',
	)
);
?>
<div id="slider-settings" class="tab-content" role="tabpanel" aria-labelledby="torobpay-nav-slider-settings">

	<div class="slider-preview-section" id="torobpay-slider-preview-section">
		<h2><?php esc_html_e( 'Slider preview', 'torobpay-for-woocommerce' ); ?><button type="button" class="torobpay-preview-toggle" onclick="torobpayTogglePreview(this)" title="<?php echo esc_attr__( 'Shrink/expand preview', 'torobpay-for-woocommerce' ); ?>"><span class="dashicons dashicons-minus"></span></button>
		</h2>
		<div class="torobpay-slider-preview-controls">
			<label class="torobpay-slider-preview-placement-label"><?php esc_html_e( 'Display location:', 'torobpay-for-woocommerce' ); ?><select id="torobpay-slider-preview-placement">
					<?php foreach ( $torobpay_slider_placements as $torobpay_p_index => $torobpay_p ) : ?>
						<option value="<?php echo esc_attr( $torobpay_p_index ); ?>"><?php echo esc_html( torobpay_slider_get_hook_label( $torobpay_p['hook'] ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="button" class="torobpay-slider-preview-playback" id="torobpay-slider-preview-playback" aria-pressed="true" aria-label="<?php echo esc_attr__( 'Continue preview', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Continue preview', 'torobpay-for-woocommerce' ); ?>">
				<span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
			</button>
		</div>
		<div class="torobpay-slider-preview-container">
			<div class="torobpay-slider-preview-frame">
				<div id="torobpay-slider-preview-host"><?php echo wp_kses( $torobpay_preview_initial_html, torobpay_kses_allowed_html() ); ?></div>
			</div>
			<p class="torobpay-slider-preview-empty" id="torobpay-slider-preview-empty" <?php echo '' !== $torobpay_preview_initial_html ? 'hidden' : ''; ?>><?php esc_html_e( 'No slide is selected for this display location.', 'torobpay-for-woocommerce' ); ?></p>
		</div>
		<button type="button" class="torobpay-slider-preview-resize-handle" aria-label="<?php echo esc_attr__( 'Resize preview', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Resize preview', 'torobpay-for-woocommerce' ); ?>"></button>
	</div>

	<?php torobpay_slider_render_slides_fields(); ?>

	<h2 class="section-title torobpay-slides-section" data-section-key="slider-placements">
		<span class="dashicons dashicons-location"></span><?php esc_html_e( 'Manage display locations', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span>
	</h2>
	<div class="torobpay-slides-section-content">
	<div class="torobpay-slider-placements-shell">
		<div class="torobpay-slider-placement-tab-bar torobpay-tab-bar">
			<div class="torobpay-slider-placement-tab-strip">
				<div class="torobpay-slider-placement-nav nav-tab-wrapper" id="torobpay-slider-placement-nav" role="tablist" aria-label="<?php echo esc_attr__( 'Slider display locations', 'torobpay-for-woocommerce' ); ?>"></div>
				<button type="button" class="button torobpay-slider-add-placement-tab" id="torobpay-slider-add-placement" title="<?php echo esc_attr__( 'Add display location', 'torobpay-for-woocommerce' ); ?>" aria-label="<?php echo esc_attr__( 'Add display location', 'torobpay-for-woocommerce' ); ?>">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
				</button>
			</div>
			<div class="torobpay-slider-placement-tab-overflow torobpay-tab-overflow" hidden>
				<button
					type="button"
					class="torobpay-tab-overflow-trigger"
					id="torobpay-slider-placement-overflow-trigger"
					aria-expanded="false"
					aria-controls="torobpay-slider-placement-overflow-menu"
					aria-label="<?php echo esc_attr__( 'More locations', 'torobpay-for-woocommerce' ); ?>"
					title="<?php echo esc_attr__( 'More locations', 'torobpay-for-woocommerce' ); ?>"
					hidden>
					<span class="dashicons dashicons-ellipsis" aria-hidden="true"></span>
					<span class="torobpay-tab-overflow-count" aria-hidden="true"></span>
				</button>
				<div id="torobpay-slider-placement-overflow-menu" class="torobpay-tab-overflow-menu" role="menu" hidden></div>
			</div>
		</div>

		<p class="torobpay-slider-placements-shell-note"><?php esc_html_e( 'You can show the slider in multiple locations (hooks) at once. Each location has independent settings.', 'torobpay-for-woocommerce' ); ?></p>

		<div id="torobpay-slider-placements-repeater" class="torobpay-slider-placements-panels">
			<?php
			foreach ( $torobpay_slider_placements as $torobpay_index => $torobpay_placement ) {
				torobpay_slider_render_placement_row( $torobpay_index, $torobpay_placement, 0 === (int) $torobpay_index );
			}
			?>
		</div>
	</div>

	<script type="text/html" id="torobpay-slider-placement-template">
		<?php torobpay_slider_render_placement_row( '__INDEX__', torobpay_slider_default_placement(), false ); ?>
	</script>
	</div>

	<div class="torobpay-settings-note torobpay-slider-shortcode-guide">
		<h2 class="torobpay-slider-shortcode-guide-title">
			<span class="dashicons dashicons-shortcode" aria-hidden="true"></span><?php esc_html_e( 'Using the slider elsewhere', 'torobpay-for-woocommerce' ); ?></h2>

		<div class="torobpay-shortcode-guide-grid">
			<div class="torobpay-shortcode-card">
				<h3 class="torobpay-shortcode-card-title"><?php esc_html_e( 'Full slider', 'torobpay-for-woocommerce' ); ?></h3>
				<p class="torobpay-shortcode-card-desc"><?php esc_html_e( 'To show the full slider on a page or in a template:', 'torobpay-for-woocommerce' ); ?></p>
				<code class="torobpay-shortcode-snippet" dir="ltr">[torobpay_slider]</code>
				<p class="torobpay-shortcode-card-note"><?php esc_html_e( 'Uses global slider defaults and works independently of the automatic locations above.', 'torobpay-for-woocommerce' ); ?></p>
			</div>

			<div class="torobpay-shortcode-card">
				<h3 class="torobpay-shortcode-card-title"><?php esc_html_e( 'Single slide', 'torobpay-for-woocommerce' ); ?></h3>
				<p class="torobpay-shortcode-card-desc"><?php esc_html_e( 'To show', 'torobpay-for-woocommerce' ); ?><strong><?php esc_html_e( 'one slide', 'torobpay-for-woocommerce' ); ?></strong><?php esc_html_e( 'inside other sliders (like Elementor or Smart Slider), copy that slide\'s shortcode from the icon', 'torobpay-for-woocommerce' ); ?><span class="dashicons dashicons-shortcode torobpay-shortcode-inline-icon" aria-hidden="true"></span><?php esc_html_e( 'next to the slide name and place it in an empty slide.', 'torobpay-for-woocommerce' ); ?></p>
				<code class="torobpay-shortcode-snippet" dir="ltr">[torobpay_slide id="1"]</code>
				<p class="torobpay-shortcode-card-note"><?php esc_html_e( 'Fixed TorobPay slide:', 'torobpay-for-woocommerce' ); ?><code class="torobpay-shortcode-inline">[torobpay_slide id="torobpay"]</code></p>
			</div>
		</div>

		<div class="torobpay-shortcode-attrs">
			<h3 class="torobpay-shortcode-attrs-title"><?php esc_html_e( 'Parameters for', 'torobpay-for-woocommerce' ); ?><code class="torobpay-shortcode-inline">[torobpay_slide]</code></h3>
			<ul class="torobpay-shortcode-attr-list">
				<li><code>id</code> — <?php esc_html_e( 'Slide ID', 'torobpay-for-woocommerce' ); ?> <span class="torobpay-shortcode-attr-required"><?php esc_html_e( '(required)', 'torobpay-for-woocommerce' ); ?></span></li>
				<li><code>height</code> — <?php esc_html_e( 'Height; default', 'torobpay-for-woocommerce' ); ?> <code>220px</code><?php esc_html_e( ' — e.g.', 'torobpay-for-woocommerce' ); ?> <code>320px</code>, <code>50vh</code>, <code>100%</code> <?php esc_html_e( 'only inside a container (e.g. div) with a defined height', 'torobpay-for-woocommerce' ); ?></li>
				<li><code>class</code> — <?php esc_html_e( 'Custom CSS class — e.g.', 'torobpay-for-woocommerce' ); ?> <code>my-slide</code></li>
				<li><code>radius</code> — <?php esc_html_e( 'Corner radius in px; default', 'torobpay-for-woocommerce' ); ?> <code>14</code></li>
			</ul>
			<code class="torobpay-shortcode-snippet torobpay-shortcode-snippet--example" dir="ltr">[torobpay_slide id="1" height="320" class="my-slide" radius="14"]</code>
		</div>
	</div>
</div>
