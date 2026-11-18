<?php
/**
 * Slide management UI fields for the TorobPay promo slider.
 *
 * Rendered inside the visual settings "Slider" tab (includes/slider-settings-fields.php).
 * Owns the slide repeater markup and per-slide field helpers; persistence is handled
 * by torobpay_slider_save_settings() on the shared settings form.
 *
 * Two kinds of slides are managed here:
 *   - Mandatory (brand-locked) hero slide: fixed Persian copy that cannot be
 *     edited or removed and never allows a background image; only its
 *     per-slide *settings* are editable (stored as overrides).
 *   - Custom slides: fully editable heading / subtext / CTA / background image
 *     plus the same per-slide settings and optional placement targeting.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode icon button for the slide repeater head.
 *
 * @param string $shortcode Full shortcode string.
 */
function torobpay_slider_render_slide_shortcode_toggle( $shortcode ) {
	if ( '' === trim( (string) $shortcode ) ) {
		return;
	}
	?>
	<button
		type="button"
		class="button-link torobpay-slide-shortcode-toggle"
		title="<?php echo esc_attr__( 'Shortcode', 'torobpay-for-woocommerce' ); ?>"
		aria-expanded="false"
		aria-label="<?php echo esc_attr__( 'Show shortcode', 'torobpay-for-woocommerce' ); ?>"
	>
		<span class="dashicons dashicons-shortcode" aria-hidden="true"></span>
	</button>
	<?php
}

/**
 * Collapsible shortcode panel (hidden until the head icon is clicked).
 *
 * @param string $shortcode Full shortcode string.
 */
function torobpay_slider_render_slide_shortcode_panel( $shortcode ) {
	if ( '' === trim( (string) $shortcode ) ) {
		return;
	}
	?>
	<div class="torobpay-slide-shortcode-panel" hidden>
		<span class="torobpay-slide-shortcode-row">
			<input type="text" class="torobpay-slide-shortcode-input regular-text" value="<?php echo esc_attr( $shortcode ); ?>" readonly dir="ltr">
			<button type="button" class="button button-secondary torobpay-slide-shortcode-copy" title="<?php echo esc_attr__( 'Copy shortcode', 'torobpay-for-woocommerce' ); ?>">
				<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Copy shortcode', 'torobpay-for-woocommerce' ); ?></span>
			</button>
		</span>
		<span class="description"><?php esc_html_e( 'You can use this shortcode in other slider plugins or place it as a banner on pages.', 'torobpay-for-woocommerce' ); ?></span>
	</div>
	<?php
}

/**
 * Render a per-device font-weight select for a slide setting.
 *
 * @param string               $base     Field name base.
 * @param string               $key      Setting key.
 * @param array<string,mixed>  $settings Current slide settings.
 * @param array<string,mixed>  $cfg      Field config.
 * @param bool                 $is_hero  Whether this is the hero slide.
 */
function torobpay_slider_render_slide_weight_field( $base, $key, $settings, $cfg, $is_hero = false ) {
	$bp           = torobpay_get_breakpoints();
	$desktop      = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
	$tablet       = isset( $settings[ $key . '_tablet' ] ) ? (string) $settings[ $key . '_tablet' ] : '';
	$mobile       = isset( $settings[ $key . '_mobile' ] ) ? (string) $settings[ $key . '_mobile' ] : '';
	$field_name   = $base . '[' . $key . ']';
	$default_ph   = torobpay_slider_slide_field_placeholder( $key, $settings, $cfg, $is_hero );
	$inherit_ph   = '' !== $desktop ? $desktop : $default_ph;
	$choices      = torobpay_slider_font_weight_choices();
	/* translators: %s: inherited or default font-weight value */
	$default_lbl  = '' !== $default_ph ? sprintf( __( 'Default (%s)', 'torobpay-for-woocommerce' ), $default_ph ) : __( 'Default', 'torobpay-for-woocommerce' );

	$devices = array(
		'desktop' => array(
			'field' => $base . '[' . $key . ']',
			'icon'  => 'dashicons-desktop',
			'title' => __( 'Desktop (default for all screens)', 'torobpay-for-woocommerce' ),
			'value' => $desktop,
			'ph'    => $default_lbl,
		),
		'tablet'  => array(
			'field' => $base . '[' . $key . '_tablet]',
			'icon'  => 'dashicons-tablet',
			'title' => __( 'Tablet (empty = inherit from desktop)', 'torobpay-for-woocommerce' ),
			'value' => $tablet,
			/* translators: %s: inherited or default font-weight value */
			'ph'    => '' !== $inherit_ph ? sprintf( __( 'Default (%s)', 'torobpay-for-woocommerce' ), $inherit_ph ) : __( 'Default', 'torobpay-for-woocommerce' ),
		),
		'mobile'  => array(
			'field' => $base . '[' . $key . '_mobile]',
			'icon'  => 'dashicons-smartphone',
			'title' => __( 'Mobile (empty = inherit from desktop)', 'torobpay-for-woocommerce' ),
			'value' => $mobile,
			/* translators: %s: inherited or default font-weight value */
			'ph'    => '' !== $inherit_ph ? sprintf( __( 'Default (%s)', 'torobpay-for-woocommerce' ), $inherit_ph ) : __( 'Default', 'torobpay-for-woocommerce' ),
		),
	);
	?>
	<p class="torobpay-slide-device-field">
		<label>
			<?php echo esc_html( $cfg['label'] ); ?>
			<?php if ( ! empty( $cfg['tip'] ) ) : ?>
				<span class="torobpay-tooltip" data-tip="<?php echo esc_attr( $cfg['tip'] ); ?>" title="<?php echo esc_attr( $cfg['label'] ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
			<?php endif; ?>
		</label>
		<span class="torobpay-responsive-field" data-active-device="desktop"
			data-field-name="<?php echo esc_attr( $field_name ); ?>"
			data-bp-tablet="<?php echo esc_attr( (int) $bp['tablet'] ); ?>"
			data-bp-mobile="<?php echo esc_attr( (int) $bp['mobile'] ); ?>">
			<span class="torobpay-rf-switcher" role="group">
				<?php foreach ( $devices as $device_key => $d ) : ?>
					<button type="button"
						class="torobpay-rf-btn<?php echo 'desktop' === $device_key ? ' is-active' : ''; ?>"
						data-device="<?php echo esc_attr( $device_key ); ?>"
						title="<?php echo esc_attr( $d['title'] ); ?>">
						<span class="dashicons <?php echo esc_attr( $d['icon'] ); ?>"></span>
					</button>
				<?php endforeach; ?>
			</span>
			<?php foreach ( $devices as $device_key => $d ) : ?>
				<select
					name="<?php echo esc_attr( $d['field'] ); ?>"
					class="torobpay-rf-input"
					data-device="<?php echo esc_attr( $device_key ); ?>">
					<option value=""><?php echo esc_html( $d['ph'] ); ?></option>
					<?php foreach ( $choices as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $d['value'], $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endforeach; ?>
		</span>
	</p>
	<?php
}

/**
 * Render a compact per-device (desktop/tablet/mobile) numeric control for a
 * single slide setting, sized to live inside a .torobpay-slide-settings grid.
 *
 * Reuses the shared .torobpay-responsive-field markup so the existing device
 * switcher CSS + JS (assets/js/widget-settings.js) drive it. Three inputs are
 * always in the DOM (one per device) so they always POST; an empty
 * tablet/mobile field inherits the desktop value.
 *
 * Posted fields: {base}[{key}], {base}[{key}_tablet], {base}[{key}_mobile].
 *
 * @param string $base     Field name base, e.g. "torobpay_slider_slides[0]".
 * @param string $key      Setting key (see torobpay_slider_slide_size_fields()).
 * @param array  $settings Current slide settings (merged with defaults).
 * @param array  $cfg      Field config (min, max, unit, step, label, tip).
 * @param bool   $bg_hide    Whether to start hidden (for background-only controls).
 * @param string $scale_kind 'icon' | 'aurora' | '' — which bg animation shows this control.
 * @param bool   $is_hero    Whether this is the hero slide.
 */
function torobpay_slider_render_slide_device_field( $base, $key, $settings, $cfg, $bg_hide = false, $scale_kind = '', $is_hero = false ) {
	$bp          = torobpay_get_breakpoints();
	$desktop    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
	$tablet     = isset( $settings[ $key . '_tablet' ] ) ? $settings[ $key . '_tablet' ] : '';
	$mobile     = isset( $settings[ $key . '_mobile' ] ) ? $settings[ $key . '_mobile' ] : '';
	$field_name = $base . '[' . $key . ']';

	$default_ph = torobpay_slider_slide_field_placeholder( $key, $settings, $cfg, $is_hero );
	$inherit_ph = '' !== (string) $desktop ? (string) $desktop : $default_ph;

	$devices = array(
		'desktop' => array(
			'field' => $base . '[' . $key . ']',
			'icon'  => 'dashicons-desktop',
			'title' => __( 'Desktop (default for all screens)', 'torobpay-for-woocommerce' ),
			'value' => $desktop,
			'ph'    => $default_ph,
		),
		'tablet'  => array(
			'field' => $base . '[' . $key . '_tablet]',
			'icon'  => 'dashicons-tablet',
			'title' => __( 'Tablet (empty = inherit from desktop)', 'torobpay-for-woocommerce' ),
			'value' => $tablet,
			'ph'    => $inherit_ph,
		),
		'mobile'  => array(
			'field' => $base . '[' . $key . '_mobile]',
			'icon'  => 'dashicons-smartphone',
			'title' => __( 'Mobile (empty = inherit from desktop)', 'torobpay-for-woocommerce' ),
			'value' => $mobile,
			'ph'    => $inherit_ph,
		),
	);

	$wrap_class = 'torobpay-slide-device-field';
	if ( $bg_hide ) {
		$wrap_class .= ' torobpay-slider-bg-control';
	}
	if ( 'icon' === $scale_kind ) {
		$wrap_class .= ' torobpay-slider-bg-icon-scale-control';
	} elseif ( 'aurora' === $scale_kind ) {
		$wrap_class .= ' torobpay-slider-bg-aurora-scale-control';
	}

	$bg_animation = isset( $settings['bg_animation'] ) ? (string) $settings['bg_animation'] : '';
	$hide         = false;
	if ( $bg_hide ) {
		$control = 'icon' === $scale_kind ? 'icon_scale' : 'aurora_scale';
		$hide    = ! torobpay_slider_bg_control_visible( $control, $bg_animation );
	}
	?>
	<p class="<?php echo esc_attr( $wrap_class ); ?>"<?php echo $hide ? ' style="' . esc_attr( 'display:none;' ) . '"' : ''; ?>>
		<label>
			<?php echo esc_html( $cfg['label'] ); ?>
			<?php if ( ! empty( $cfg['tip'] ) ) : ?>
				<span class="torobpay-tooltip" data-tip="<?php echo esc_attr( $cfg['tip'] ); ?>" title="<?php echo esc_attr( $cfg['label'] ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
			<?php endif; ?>
		</label>
		<span class="torobpay-responsive-field" data-active-device="desktop"
			data-field-name="<?php echo esc_attr( $field_name ); ?>"
			data-bp-tablet="<?php echo esc_attr( (int) $bp['tablet'] ); ?>"
			data-bp-mobile="<?php echo esc_attr( (int) $bp['mobile'] ); ?>">
			<span class="torobpay-rf-switcher" role="group">
				<?php foreach ( $devices as $device_key => $d ) : ?>
					<button type="button"
						class="torobpay-rf-btn<?php echo 'desktop' === $device_key ? ' is-active' : ''; ?>"
						data-device="<?php echo esc_attr( $device_key ); ?>"
						title="<?php echo esc_attr( $d['title'] ); ?>">
						<span class="dashicons <?php echo esc_attr( $d['icon'] ); ?>"></span>
					</button>
				<?php endforeach; ?>
			</span>
			<?php foreach ( $devices as $device_key => $d ) : ?>
				<input type="number"
					name="<?php echo esc_attr( $d['field'] ); ?>"
					value="<?php echo esc_attr( $d['value'] ); ?>"
					min="<?php echo esc_attr( $cfg['min'] ); ?>"
					max="<?php echo esc_attr( $cfg['max'] ); ?>"
					step="<?php echo esc_attr( $cfg['step'] ); ?>"
					class="small-text torobpay-rf-input"
					data-device="<?php echo esc_attr( $device_key ); ?>"
					<?php echo '' !== $d['ph'] ? 'placeholder="' . esc_attr( $d['ph'] ) . '"' : ''; ?>>
			<?php endforeach; ?>
			<span class="torobpay-rf-unit"><?php echo esc_html( $cfg['unit'] ); ?></span>
		</span>
	</p>
	<?php
}

/**
 * Optional PNG/SVG logo for canvas background animations (perlin/rain/float).
 *
 * @param string               $base         Field name base.
 * @param array<string,mixed>  $settings     Current slide settings.
 * @param string               $bg_animation Active background animation key.
 */
function torobpay_slider_render_bg_custom_icon_field( $base, $settings, $bg_animation ) {
	$bg_custom_icon    = isset( $settings['bg_custom_icon'] ) ? (string) $settings['bg_custom_icon'] : '';
	$bg_custom_icon_id = isset( $settings['bg_custom_icon_id'] ) ? (int) $settings['bg_custom_icon_id'] : 0;
	$icon_hidden = ! torobpay_slider_bg_control_visible( 'custom_icon', $bg_animation );
	?>
	<p class="torobpay-slider-bg-control torobpay-slider-bg-custom-icon-control"<?php echo $icon_hidden ? ' style="' . esc_attr( 'display:none;' ) . '"' : ''; ?>>
		<label><?php esc_html_e( 'Background logo', 'torobpay-for-woocommerce' ); ?><span class="torobpay-field-optional"><?php esc_html_e( '(optional)', 'torobpay-for-woocommerce' ); ?></span>
			<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'PNG or SVG only. Your store logo appears beside the TorobPay logo in the background. A square logo works best.', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Background logo', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
		</label>
		<span class="torobpay-slider-image-card torobpay-bg-icon-card<?php echo '' === $bg_custom_icon ? ' is-empty' : ''; ?>">
			<input type="hidden" class="torobpay-slider-bg-icon-url" name="<?php echo esc_attr( $base ); ?>[bg_custom_icon]" value="<?php echo esc_attr( $bg_custom_icon ); ?>">
			<input type="hidden" class="torobpay-slider-bg-icon-id" name="<?php echo esc_attr( $base ); ?>[bg_custom_icon_id]" value="<?php echo esc_attr( $bg_custom_icon_id ); ?>">
			<span class="torobpay-slider-image-card-preview">
				<?php if ( '' !== $bg_custom_icon ) : ?>
					<img class="torobpay-slider-bg-icon-preview" src="<?php echo esc_url( $bg_custom_icon ); ?>" alt="">
				<?php else : ?>
					<img class="torobpay-slider-bg-icon-preview" alt="">
				<?php endif; ?>
				<span class="torobpay-slider-image-placeholder" aria-hidden="true">
					<span class="dashicons dashicons-format-image"></span>
					<span><?php esc_html_e( 'No logo', 'torobpay-for-woocommerce' ); ?></span>
				</span>
			</span>
			<span class="torobpay-slider-image-card-actions">
				<button type="button" class="button button-secondary torobpay-slider-bg-icon-select">
					<span class="dashicons dashicons-upload"></span><?php esc_html_e( 'Select logo', 'torobpay-for-woocommerce' ); ?></button>
				<button type="button" class="button button-link torobpay-slider-bg-icon-remove"><?php esc_html_e( 'Remove logo', 'torobpay-for-woocommerce' ); ?></button>
			</span>
		</span>
	</p>
	<?php
}

/**
 * Render the shared per-slide settings controls.
 *
 * @param string $base                 Field name base, e.g. "torobpay_slider_slides[0]".
 * @param array  $settings             Current slide settings (merged with defaults).
 * @param bool   $allow_image          Whether to render the background-image picker.
 * @param bool   $allow_device_control Whether to render the device visibility controls (only for custom slides).
 * @param bool   $is_hero              Whether this is the first brand-locked "hero" slide (locks layout-driven controls).
 */
function torobpay_slider_render_slide_settings_fields( $base, $settings, $allow_image, $allow_device_control = true, $is_hero = false ) {
	$defaults  = torobpay_slider_slide_setting_defaults();
	$settings  = array_merge( $defaults, is_array( $settings ) ? $settings : array() );
	$presets   = torobpay_slider_bg_presets();
	$positions = torobpay_slider_text_positions();

	$transition   = (string) $settings['transition'];
	$position     = (string) $settings['text_position'];
	$preset       = (string) $settings['bg_preset'];
	$bg_animation = (string) $settings['bg_animation'];
	$bg_density   = (string) $settings['bg_density'];
	$bg_speed     = (int) $settings['bg_speed'];
	$duration     = (int) $settings['duration'];
	$show_desktop = isset( $settings['show_desktop'] ) ? $settings['show_desktop'] : 'yes';
	$show_tablet  = isset( $settings['show_tablet'] ) ? $settings['show_tablet'] : 'yes';
	$show_mobile  = isset( $settings['show_mobile'] ) ? $settings['show_mobile'] : 'yes';

	$transitions = array(
		'slide' => __( 'Slide', 'torobpay-for-woocommerce' ),
		'fade'  => __( 'Fade out', 'torobpay-for-woocommerce' ),
		'zoom'  => __( 'Zoom in', 'torobpay-for-woocommerce' ),
		'flip'  => __( 'Rotate', 'torobpay-for-woocommerce' ),
	);
	$bg_anims = array(
		'none'   => __( 'No animation', 'torobpay-for-woocommerce' ),
		'aurora' => __( 'Aurora', 'torobpay-for-woocommerce' ),
		'clover' => __( 'TorobPay four-part mark', 'torobpay-for-woocommerce' ),
		'perlin' => __( 'Noisy float (Perlin)', 'torobpay-for-woocommerce' ),
		'rain'   => __( 'Icon shower', 'torobpay-for-woocommerce' ),
		'float'  => __( 'Float upward', 'torobpay-for-woocommerce' ),
	);
	$densities = array(
		'low'    => __( 'Low', 'torobpay-for-woocommerce' ),
		'medium' => __( 'Medium', 'torobpay-for-woocommerce' ),
		'high'   => __( 'High', 'torobpay-for-woocommerce' ),
		'dense'  => __( 'Very high', 'torobpay-for-woocommerce' ),
	);

	$density_hidden = ! torobpay_slider_bg_control_visible( 'density', $bg_animation );
	$speed_hidden   = ! torobpay_slider_bg_control_visible( 'speed', $bg_animation );
	?>
	<div class="torobpay-slide-settings">
		<p>
			<label><?php esc_html_e( 'Transition effect', 'torobpay-for-woocommerce' ); ?></label>
			<select name="<?php echo esc_attr( $base ); ?>[transition]">
				<?php foreach ( $transitions as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $transition, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php if ( ! $is_hero ) : ?>
		<p>
			<label><?php esc_html_e( 'Text position', 'torobpay-for-woocommerce' ); ?></label>
			<select name="<?php echo esc_attr( $base ); ?>[text_position]">
				<?php foreach ( $positions as $pos_key => $pos_label ) : ?>
					<option value="<?php echo esc_attr( $pos_key ); ?>" <?php selected( $position, $pos_key ); ?>><?php echo esc_html( $pos_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php endif; ?>
		<p>
			<label><?php esc_html_e( 'Background preset', 'torobpay-for-woocommerce' ); ?></label>
			<select name="<?php echo esc_attr( $base ); ?>[bg_preset]">
				<?php foreach ( $presets as $preset_key => $preset_data ) : ?>
					<option value="<?php echo esc_attr( $preset_key ); ?>" <?php selected( $preset, $preset_key ); ?>><?php echo esc_html( $preset_data['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label><?php esc_html_e( 'Background animation', 'torobpay-for-woocommerce' ); ?></label>
			<select class="torobpay-slider-bg-anim" name="<?php echo esc_attr( $base ); ?>[bg_animation]">
				<?php foreach ( $bg_anims as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $bg_animation, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="torobpay-slider-bg-control torobpay-slider-bg-density-control"<?php echo $density_hidden ? ' style="' . esc_attr( 'display:none;' ) . '"' : ''; ?>>
			<label><?php esc_html_e( 'Background density', 'torobpay-for-woocommerce' ); ?></label>
			<select name="<?php echo esc_attr( $base ); ?>[bg_density]">
				<?php foreach ( $densities as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $bg_density, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="torobpay-slider-bg-control torobpay-slider-bg-speed-control"<?php echo $speed_hidden ? ' style="' . esc_attr( 'display:none;' ) . '"' : ''; ?>>
			<label><?php esc_html_e( 'Background speed', 'torobpay-for-woocommerce' ); ?></label>
			<input type="number" name="<?php echo esc_attr( $base ); ?>[bg_speed]" value="<?php echo esc_attr( $bg_speed ); ?>" min="1" max="10" step="1" class="small-text" placeholder="5">
		</p>
		<?php torobpay_slider_render_bg_custom_icon_field( $base, $settings, $bg_animation ); ?>
		<p>
			<label><?php esc_html_e( 'Display duration (seconds)', 'torobpay-for-woocommerce' ); ?></label>
			<input type="number" name="<?php echo esc_attr( $base ); ?>[duration]" value="<?php echo esc_attr( $duration ); ?>" min="2" max="30" step="1" class="small-text" placeholder="5">
		</p>
		<?php
		// Per-device typography / size controls. On the hero slide the subtext
		// controls are omitted (the hero layout has no subtext line).
		$size_fields = torobpay_slider_slide_size_fields();
		if ( $is_hero ) {
			unset( $size_fields['subtext_size'], $size_fields['subtext_weight'] );
		}
		foreach ( $size_fields as $size_key => $size_cfg ) {
			if ( isset( $size_cfg['type'] ) && 'weight' === $size_cfg['type'] ) {
				torobpay_slider_render_slide_weight_field( $base, $size_key, $settings, $size_cfg, $is_hero );
				continue;
			}

			$is_bg      = in_array( $size_key, array( 'bg_icon_size', 'bg_aurora_scale' ), true );
			$scale_kind = '';
			if ( 'bg_icon_size' === $size_key ) {
				$scale_kind = 'icon';
			} elseif ( 'bg_aurora_scale' === $size_key ) {
				$scale_kind = 'aurora';
			}
			torobpay_slider_render_slide_device_field( $base, $size_key, $settings, $size_cfg, $is_bg, $scale_kind, $is_hero );
		}
		?>
		<?php if ( $allow_device_control ) : ?>
		<p>
			<label><?php esc_html_e( 'Show by device', 'torobpay-for-woocommerce' ); ?></label>
			<span class="torobpay-slide-visibility">
				<input type="hidden" name="<?php echo esc_attr( $base ); ?>[show_desktop]" value="no">
				<label class="torobpay-display-setting-label"><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[show_desktop]" value="yes" <?php checked( $show_desktop, 'yes' ); ?>> <span class="dashicons dashicons-desktop"></span><?php esc_html_e( 'Desktop', 'torobpay-for-woocommerce' ); ?></label>
				<input type="hidden" name="<?php echo esc_attr( $base ); ?>[show_tablet]" value="no">
				<label class="torobpay-display-setting-label"><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[show_tablet]" value="yes" <?php checked( $show_tablet, 'yes' ); ?>> <span class="dashicons dashicons-tablet"></span><?php esc_html_e( 'Tablet', 'torobpay-for-woocommerce' ); ?></label>
				<input type="hidden" name="<?php echo esc_attr( $base ); ?>[show_mobile]" value="no">
				<label class="torobpay-display-setting-label"><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[show_mobile]" value="yes" <?php checked( $show_mobile, 'yes' ); ?>> <span class="dashicons dashicons-smartphone"></span><?php esc_html_e( 'Mobile', 'torobpay-for-woocommerce' ); ?></label>
			</span>
		</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render a multi-select of placement hooks for a custom slide.
 *
 * @param string               $base                 Field name base.
 * @param array<int,string>    $selected_placements  Selected hook names.
 * @param array<int,array>     $available_placements Placement records from settings.
 */
function torobpay_slider_render_slide_placements_field( $base, $selected_placements, $available_placements ) {
	$selected = array();
	if ( is_array( $selected_placements ) ) {
		foreach ( $selected_placements as $hook ) {
			$hook = torobpay_slider_sanitize_hook_name( (string) $hook );
			if ( '' !== $hook && ! in_array( $hook, $selected, true ) ) {
				$selected[] = $hook;
			}
		}
	}

	$available_hooks = array();
	$has_any         = false;
	?>
	<div class="torobpay-slider-field-group torobpay-slide-placements-field">
		<label><?php esc_html_e( 'Display locations', 'torobpay-for-woocommerce' ); ?><span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'The slide is shown only in selected locations. Without a selection, it is available only via shortcode.', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Display locations', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
		</label>
		<div class="torobpay-slide-placements-chips" data-name-base="<?php echo esc_attr( $base ); ?>">
			<?php
			foreach ( $available_placements as $placement ) {
				if ( ! is_array( $placement ) ) {
					continue;
				}
				$hook = isset( $placement['hook'] ) ? (string) $placement['hook'] : '';
				if ( '' === $hook ) {
					continue;
				}
				$hook = torobpay_slider_sanitize_hook_name( $hook );
				if ( '' === $hook || in_array( $hook, $available_hooks, true ) ) {
					continue;
				}
				$available_hooks[] = $hook;
				$has_any           = true;
				$label             = torobpay_slider_get_hook_label( $hook );
				$checked           = in_array( $hook, $selected, true );
				?>
				<label class="torobpay-placement-chip<?php echo $checked ? ' is-selected' : ''; ?>">
					<input type="checkbox" class="torobpay-placement-input" name="<?php echo esc_attr( $base ); ?>[placements][]" value="<?php echo esc_attr( $hook ); ?>" <?php checked( $checked ); ?>>
					<span class="torobpay-placement-chip-label"><?php echo esc_html( $label ); ?></span>
				</label>
				<?php
			}
			$orphaned = array_values( array_diff( $selected, $available_hooks ) );
			if ( ! empty( $orphaned ) ) {
				?>
				<div class="torobpay-slide-placements-orphaned">
					<span class="dashicons dashicons-warning" aria-hidden="true"></span>
					<div class="torobpay-slide-placements-orphaned-body">
						<p class="torobpay-slide-placements-orphaned-intro"><?php esc_html_e( 'This slide is still linked to removed display location(s):', 'torobpay-for-woocommerce' ); ?></p>
						<ul class="torobpay-slide-placements-orphan-items">
							<?php foreach ( $orphaned as $hook ) : ?>
								<li class="torobpay-slide-placements-orphan-item" data-hook="<?php echo esc_attr( $hook ); ?>">
									<input type="hidden" class="torobpay-placement-orphan-input" name="<?php echo esc_attr( $base ); ?>[placements][]" value="<?php echo esc_attr( $hook ); ?>">
									<span class="torobpay-slide-placements-orphan-label"><?php echo esc_html( torobpay_slider_get_hook_label( $hook ) ); ?></span>
									<button type="button" class="button-link torobpay-placement-orphan-remove" title="<?php echo esc_attr__( 'Remove link from this slide', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( 'Remove link', 'torobpay-for-woocommerce' ); ?></button>
								</li>
							<?php endforeach; ?>
						</ul>
						<p class="torobpay-slide-placements-orphaned-note"><?php esc_html_e( 'Recreating the same display location restores the previous link.', 'torobpay-for-woocommerce' ); ?></p>
					</div>
				</div>
				<?php
			}
			if ( ! $has_any ) {
				?>
				<p class="torobpay-slide-placements-empty"><?php esc_html_e( 'No display location defined yet. Add one under "Manage display locations" first.', 'torobpay-for-woocommerce' ); ?></p>
				<?php
			}
			?>
		</div>
	</div>
	<?php
}

/**
 * Render one editable custom-slide card in the repeater.
 *
 * @param int|string $index Row index (or the literal __INDEX__ placeholder).
 * @param array      $slide Existing slide data (empty for a new row).
 */
function torobpay_slider_render_custom_slide_row( $index, $slide ) {
	$slide = is_array( $slide ) ? $slide : array();

	$heading   = isset( $slide['heading'] ) ? (string) $slide['heading'] : '';
	$subtext   = isset( $slide['subtext'] ) ? (string) $slide['subtext'] : '';
	$cta_label = isset( $slide['cta_label'] ) ? (string) $slide['cta_label'] : '';
	$cta_url   = isset( $slide['cta_url'] ) ? (string) $slide['cta_url'] : '';
	$image     = isset( $slide['image'] ) ? (string) $slide['image'] : '';
	$image_id  = isset( $slide['image_id'] ) ? (int) $slide['image_id'] : 0;
	$image_object_position = isset( $slide['image_object_position'] ) ? sanitize_key( (string) $slide['image_object_position'] ) : 'center';
	$image_positions       = torobpay_slider_image_object_positions();
	if ( ! array_key_exists( $image_object_position, $image_positions ) ) {
		$image_object_position = 'center';
	}
	$placements = isset( $slide['placements'] ) && is_array( $slide['placements'] ) ? $slide['placements'] : array();
	$slide_id  = isset( $slide['slide_id'] ) ? torobpay_slider_sanitize_slide_id( $slide['slide_id'] ) : '';
	$shortcode = torobpay_slider_get_slide_shortcode_string( $slide_id );

	$base = 'torobpay_slider_slides[' . $index . ']';
	?>
	<div class="torobpay-slider-repeater-item" data-index="<?php echo esc_attr( $index ); ?>">
		<div class="torobpay-slider-repeater-head">
			<span class="dashicons dashicons-menu torobpay-slider-drag" title="<?php echo esc_attr__( 'Reorder', 'torobpay-for-woocommerce' ); ?>"></span>
			<strong class="torobpay-slider-repeater-title"><?php echo '' !== $heading ? esc_html( $heading ) : esc_html__( 'New slide', 'torobpay-for-woocommerce' ); ?></strong>
			<?php torobpay_slider_render_slide_shortcode_toggle( $shortcode ); ?>
			<button type="button" class="button-link torobpay-slider-remove" title="<?php echo esc_attr__( 'Delete slide', 'torobpay-for-woocommerce' ); ?>"><span class="dashicons dashicons-trash"></span></button>
		</div>
		<?php torobpay_slider_render_slide_shortcode_panel( $shortcode ); ?>
		<div class="torobpay-slider-repeater-body">
			<input type="hidden" name="<?php echo esc_attr( $base ); ?>[slide_id]" value="<?php echo esc_attr( $slide_id ); ?>" class="torobpay-slider-slide-id">
			<div class="torobpay-slider-slide-content">
				<div class="torobpay-slider-field-group">
					<label for="<?php echo esc_attr( $base ); ?>-heading"><?php esc_html_e( 'Title', 'torobpay-for-woocommerce' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $base ); ?>-heading" class="regular-text torobpay-slider-field-heading" name="<?php echo esc_attr( $base ); ?>[heading]" value="<?php echo esc_attr( $heading ); ?>" placeholder="<?php echo esc_attr__( 'e.g. Autumn special discount', 'torobpay-for-woocommerce' ); ?>">
				</div>
				<div class="torobpay-slider-field-group">
					<label for="<?php echo esc_attr( $base ); ?>-subtext"><?php esc_html_e( 'Description', 'torobpay-for-woocommerce' ); ?></label>
					<textarea id="<?php echo esc_attr( $base ); ?>-subtext" class="large-text" rows="2" name="<?php echo esc_attr( $base ); ?>[subtext]" placeholder="<?php echo esc_attr__( 'Short promotional text', 'torobpay-for-woocommerce' ); ?>"><?php echo esc_textarea( $subtext ); ?></textarea>
				</div>
				<div class="torobpay-slider-field-group torobpay-slider-field-group--cta">
					<label><?php esc_html_e( 'Call-to-action button', 'torobpay-for-woocommerce' ); ?><span class="torobpay-field-optional"><?php esc_html_e( '(optional)', 'torobpay-for-woocommerce' ); ?></span></label>
					<div class="torobpay-slider-cta-row">
						<input type="text" class="regular-text" name="<?php echo esc_attr( $base ); ?>[cta_label]" value="<?php echo esc_attr( $cta_label ); ?>" placeholder="<?php echo esc_attr__( 'Button text (e.g. View products)', 'torobpay-for-woocommerce' ); ?>">
						<input type="url" class="regular-text" name="<?php echo esc_attr( $base ); ?>[cta_url]" value="<?php echo esc_attr( $cta_url ); ?>" placeholder="https://example.com/shop" dir="ltr">
					</div>
				</div>
				<div class="torobpay-slider-field-group torobpay-slider-field-group--image">
					<label><?php esc_html_e( 'Background image', 'torobpay-for-woocommerce' ); ?><span class="torobpay-field-optional"><?php esc_html_e( '(optional)', 'torobpay-for-woocommerce' ); ?></span></label>
					<div class="torobpay-slider-image-card<?php echo '' === $image ? ' is-empty' : ''; ?>">
						<input type="hidden" class="torobpay-slider-image-url" name="<?php echo esc_attr( $base ); ?>[image]" value="<?php echo esc_attr( $image ); ?>">
						<input type="hidden" class="torobpay-slider-image-id" name="<?php echo esc_attr( $base ); ?>[image_id]" value="<?php echo esc_attr( $image_id ); ?>">
						<div class="torobpay-slider-image-card-preview">
							<?php if ( '' !== $image ) : ?>
								<img class="torobpay-slider-image-preview" src="<?php echo esc_url( $image ); ?>" alt="">
							<?php else : ?>
								<img class="torobpay-slider-image-preview" alt="">
							<?php endif; ?>
							<span class="torobpay-slider-image-placeholder" aria-hidden="true">
								<span class="dashicons dashicons-format-image"></span>
								<span><?php esc_html_e( 'No image', 'torobpay-for-woocommerce' ); ?></span>
							</span>
						</div>
						<div class="torobpay-slider-image-card-actions">
							<button type="button" class="button button-secondary torobpay-slider-image-select">
								<span class="dashicons dashicons-upload"></span><?php esc_html_e( 'Select image', 'torobpay-for-woocommerce' ); ?></button>
							<button type="button" class="button button-link torobpay-slider-image-remove"><?php esc_html_e( 'Remove image', 'torobpay-for-woocommerce' ); ?></button>
						</div>
						<div class="torobpay-slider-image-position-control">
							<label for="<?php echo esc_attr( $base ); ?>-image-object-position"><?php esc_html_e( 'Image position', 'torobpay-for-woocommerce' ); ?></label>
							<select
								id="<?php echo esc_attr( $base ); ?>-image-object-position"
								class="torobpay-slider-image-object-position"
								name="<?php echo esc_attr( $base ); ?>[image_object_position]"
							>
								<?php foreach ( $image_positions as $pos_key => $pos_label ) : ?>
									<option value="<?php echo esc_attr( $pos_key ); ?>" <?php selected( $image_object_position, $pos_key ); ?>><?php echo esc_html( $pos_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</div>
				<?php torobpay_slider_render_slide_placements_field( $base, $placements, torobpay_slider_get_placements() ); ?>
			</div>
			<div class="torobpay-slider-slide-settings-divider">
				<span><?php esc_html_e( 'Appearance and behavior settings', 'torobpay-for-woocommerce' ); ?></span>
			</div>
			<?php torobpay_slider_render_slide_settings_fields( $base, $slide, true, true ); ?>
		</div>
	</div>
	<?php
}

/**
 * Render the brand-locked copy preview with an inline-editable shop name.
 *
 * @param array<string,mixed> $slide Mandatory slide record.
 */
function torobpay_slider_render_mandatory_locked_text( $slide ) {
	$raw_shop_name  = isset( $slide['shop_name'] ) ? (string) $slide['shop_name'] : '';
	$site_default   = trim( (string) get_bloginfo( 'name' ) );
	$is_hidden      = torobpay_slider_shop_name_is_hidden( $raw_shop_name );
	$is_edited      = torobpay_slider_shop_name_is_edited( $raw_shop_name, $site_default );
	$stored_value   = $is_hidden ? torobpay_slider_shop_name_hidden_value() : trim( $raw_shop_name );
	$display_name   = $is_hidden ? '' : torobpay_slider_resolve_shop_name( $slide );
	$subtext        = isset( $slide['subtext'] ) ? (string) $slide['subtext'] : '';
	$hidden_class   = $is_hidden ? ' is-shop-name-hidden' : '';
	$edited_class   = $is_edited ? ' is-shop-name-edited' : '';
	$shop_name_tip  = __( 'Click the store name to edit it. Clearing the text removes the store name from the slide and shows "Buy in installments with TorobPay". Reset restores the WordPress site title.', 'torobpay-for-woocommerce' );
	?>
	<div
		class="torobpay-slider-locked-text<?php echo esc_attr( $hidden_class . $edited_class ); ?>"
		data-site-name="<?php echo esc_attr( $site_default ); ?>"
		data-shop-name-hidden="<?php echo esc_attr( torobpay_slider_shop_name_hidden_value() ); ?>"
	>
		<div class="torobpay-slider-locked-text-bar">
			<p class="torobpay-slider-locked-heading">
				<strong>
					<span class="torobpay-slider-locked-prefix"><?php esc_html_e( 'Installment purchase', 'torobpay-for-woocommerce' ); ?></span>
					<span class="torobpay-slider-locked-shop-segment">
						<span class="torobpay-slider-locked-shop-connector"><?php esc_html_e( ' from ', 'torobpay-for-woocommerce' ); ?></span>
						<button
							type="button"
							class="torobpay-slider-shop-name-trigger"
							aria-label="<?php echo esc_attr__( 'Edit store name', 'torobpay-for-woocommerce' ); ?>"
						>
							<span class="torobpay-slider-shop-name-label"><?php echo esc_html( $display_name ); ?></span>
							<span class="dashicons dashicons-edit" aria-hidden="true"></span>
						</button>
					</span>
					<span class="torobpay-slider-locked-suffix"><?php esc_html_e( 'with TorobPay', 'torobpay-for-woocommerce' ); ?></span>
					<button
						type="button"
						class="torobpay-slider-shop-name-reset"
						aria-label="<?php echo esc_attr__( 'Reset to site name', 'torobpay-for-woocommerce' ); ?>"
						<?php if ( ! $is_edited ) { echo ' hidden'; } ?>
					>
						<span class="dashicons dashicons-image-rotate" aria-hidden="true"></span>
					</button>
				</strong>
			</p>
			<div class="torobpay-slider-locked-text-actions">
				<span class="torobpay-tooltip torobpay-tooltip--bottom-left" data-tip="<?php echo esc_attr( $shop_name_tip ); ?>" title="<?php echo esc_attr__( 'Store name', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
			</div>
		</div>
		<?php if ( '' !== $subtext ) : ?>
			<span class="torobpay-slider-locked-subtext"><?php echo esc_html( $subtext ); ?></span>
		<?php endif; ?>
		<input
			type="hidden"
			name="torobpay_slider_mandatory[shop_name]"
			value="<?php echo esc_attr( $stored_value ); ?>"
		>
	</div>
	<?php
}

/**
 * Render mandatory + custom slide sections for the slider settings tab.
 */
function torobpay_slider_render_slides_fields() {
	$mandatory_slide = torobpay_slider_get_mandatory_slides()[0];
	$custom          = torobpay_slider_get_custom_slides();
	?>
	<h2 class="section-title torobpay-slides-section" data-section-key="slides-mandatory">
		<span class="dashicons dashicons-lock"></span><?php esc_html_e( 'Fixed slides', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span>
	</h2>
	<div class="torobpay-slides-section-content">
		<p class="torobpay-settings-note"><?php esc_html_e( 'This slide text is fixed and cannot be edited or deleted, but you can adjust its appearance and behavior. This slide appears in all display locations and via shortcode.', 'torobpay-for-woocommerce' ); ?></p>

		<div class="torobpay-slider-mandatory-list">
			<div class="torobpay-slider-repeater-item is-mandatory">
				<?php $mandatory_shortcode = torobpay_slider_get_slide_shortcode_string( torobpay_slider_mandatory_slide_id() ); ?>
				<div class="torobpay-slider-repeater-head">
					<span class="dashicons dashicons-lock"></span>
					<strong class="torobpay-slider-repeater-title"><?php echo esc_html( $mandatory_slide['heading'] ); ?></strong>
					<?php torobpay_slider_render_slide_shortcode_toggle( $mandatory_shortcode ); ?>
				</div>
				<?php torobpay_slider_render_slide_shortcode_panel( $mandatory_shortcode ); ?>
				<div class="torobpay-slider-repeater-body">
					<?php torobpay_slider_render_mandatory_locked_text( $mandatory_slide ); ?>
					<?php torobpay_slider_render_slide_settings_fields( 'torobpay_slider_mandatory', $mandatory_slide, false, false, true ); ?>
				</div>
			</div>
		</div>
	</div>

	<h2 class="section-title torobpay-slides-section" data-section-key="slides-custom">
		<span class="dashicons dashicons-plus-alt"></span><?php esc_html_e( 'Custom slides', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span>
	</h2>
	<div class="torobpay-slides-section-content">
		<p class="torobpay-settings-note"><?php esc_html_e( 'Build your own slides. For each slide you can choose which display locations (hooks) it appears in; without a location selected, it is shown only via shortcode.', 'torobpay-for-woocommerce' ); ?></p>

		<p class="torobpay-slider-add-slide-row">
			<button type="button" class="button button-secondary torobpay-slider-add-slide" data-position="start">
				<span class="dashicons dashicons-plus" style="vertical-align:middle; line-height:normal;"></span><?php esc_html_e( 'Add slide', 'torobpay-for-woocommerce' ); ?></button>
		</p>

		<div id="torobpay-slider-repeater" class="torobpay-slider-repeater">
			<?php
			foreach ( $custom as $index => $slide ) {
				torobpay_slider_render_custom_slide_row( $index, $slide );
			}
			?>
		</div>

		<p class="torobpay-slider-add-slide-row">
			<button type="button" class="button button-secondary torobpay-slider-add-slide" data-position="end">
				<span class="dashicons dashicons-plus" style="vertical-align:middle; line-height:normal;"></span><?php esc_html_e( 'Add slide', 'torobpay-for-woocommerce' ); ?></button>
		</p>
	</div>

	<script type="text/html" id="torobpay-slider-slide-template">
		<?php torobpay_slider_render_custom_slide_row( '__INDEX__', array() ); ?>
	</script>
	<?php
}

