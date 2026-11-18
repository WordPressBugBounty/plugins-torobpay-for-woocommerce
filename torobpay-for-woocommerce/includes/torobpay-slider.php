<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TorobPay Promo Slider
 *
 * A rotating promotional carousel made of:
 *   - Mandatory, brand-locked BNPL hero slide (fixed text, cannot be edited or
 *     removed) that encourages shoppers to buy with TorobPay instalments.
 *   - Optional merchant-added slides with editable heading / subtext / CTA,
 *     a predefined text position, an optional background image and a branded
 *     background preset (so every slide stays on-brand).
 *
 * The feature is fully self-contained: it plugs into the shared TorobPay visual
 * settings page through the `torobpay_visual_settings_*` extension hooks (the
 * same pattern as other visual modules), owns its own option
 * namespace, registers its own admin tab, and renders + enqueues its own
 * front-end assets — without bloating includes/widget-settings-page.php.
 *
 * Placement      : one or more WooCommerce/WP action hooks (or custom hook names),
 *                  each with its own display settings, plus the [torobpay_slider]
 *                  and per-slide [torobpay_slide] shortcodes.
 * Per-device     : independent desktop / tablet / mobile visibility toggles
 *                  (the slider can never be hidden on every device at once via
 *                  the settings — merchants can still hide it with custom CSS).
 * Backgrounds    : reuses the topbar's animated background engine
 *                  (perlin / rain / float / aurora).
 *
 * Settings namespace : torobpay_slider_*
 * Breakpoints        : torobpay_get_breakpoints() (shared, in includes/torobpay-admin-responsive.php)
 */

/**
 * Whether the promo slider should render on the current request.
 *
 * @return bool
 */
function torobpay_has_slider() {
	if ( ! function_exists( 'torobpay_is_configured' ) || ! torobpay_is_configured() ) {
		return false;
	}
	if ( function_exists( 'torobpay_has_cached_auth_state' ) && ! torobpay_has_cached_auth_state() ) {
		return false;
	}
	if ( function_exists( 'torobpay_should_hide_for_wc_coming_soon' ) && torobpay_should_hide_for_wc_coming_soon() ) {
		return false;
	}

	return get_option( 'torobpay_slider_enabled', 'no' ) === 'yes';
}

/**
 * Read a slider setting with its default fallback.
 *
 * @param string $name Setting key without the 'torobpay_slider_' prefix.
 * @return mixed
 */
function torobpay_get_slider_setting( $name ) {
	$defaults = array(
		'enabled'          => 'no',
		'show_desktop'     => 'yes',
		'show_tablet'      => 'yes',
		'show_mobile'      => 'yes',
		'display_hook'     => 'woocommerce_before_main_content',
		'display_priority' => 10,
		'autoplay'         => 'yes',
		'arrows'           => 'yes',
		'dots'             => 'yes',
		'pause_hover'      => 'yes',
		'radius'           => 14,
	);

	$default = isset( $defaults[ $name ] ) ? $defaults[ $name ] : '';
	return get_option( 'torobpay_slider_' . $name, $default );
}

/**
 * The branded background presets selectable for each slide.
 *
 * Every preset is on-brand (TorobPay mint #9CE76A / forest #003D01 family)
 * so merchant slides can never drift away from the brand palette. The
 * `dark` flag flips the slide to light text for contrast.
 *
 * @return array<string,array{label:string,dark:bool}>
 */
function torobpay_slider_bg_presets() {
	return array(
		'mint'            => array( 'label' => __( 'Light green', 'torobpay-for-woocommerce' ), 'dark' => false ),
		'forest'          => array( 'label' => __( 'Dark green', 'torobpay-for-woocommerce' ), 'dark' => true ),
		'mint-gradient'   => array( 'label' => __( 'Light green gradient', 'torobpay-for-woocommerce' ), 'dark' => false ),
		'forest-gradient' => array( 'label' => __( 'Dark green gradient', 'torobpay-for-woocommerce' ), 'dark' => true ),
		'soft'            => array( 'label' => __( 'Soft light', 'torobpay-for-woocommerce' ), 'dark' => false ),
		'duo'             => array( 'label' => __( 'Duo', 'torobpay-for-woocommerce' ), 'dark' => true ),
	);
}

/**
 * Predefined text positions (3×3 grid), keyed as vertical-horizontal.
 *
 * Horizontal labels follow the RTL reading order used in the admin UI.
 *
 * @return array<string,string>
 */
function torobpay_slider_text_positions() {
	return array(
		'top-right'      => __( 'Top right', 'torobpay-for-woocommerce' ),
		'top-center'     => __( 'Top center', 'torobpay-for-woocommerce' ),
		'top-left'       => __( 'Top left', 'torobpay-for-woocommerce' ),
		'middle-right'   => __( 'Middle right', 'torobpay-for-woocommerce' ),
		'middle-center'  => __( 'Center', 'torobpay-for-woocommerce' ),
		'middle-left'    => __( 'Middle left', 'torobpay-for-woocommerce' ),
		'bottom-right'   => __( 'Bottom right', 'torobpay-for-woocommerce' ),
		'bottom-center'  => __( 'Bottom center', 'torobpay-for-woocommerce' ),
		'bottom-left'    => __( 'Bottom left', 'torobpay-for-woocommerce' ),
	);
}

/**
 * Preset focal points for custom-slide background photos (maps to CSS object-position).
 *
 * @return array<string,string>
 */
function torobpay_slider_image_object_positions() {
	return array(
		'center'        => __( 'Center', 'torobpay-for-woocommerce' ),
		'top'           => __( 'Top', 'torobpay-for-woocommerce' ),
		'bottom'        => __( 'Bottom', 'torobpay-for-woocommerce' ),
		'left'          => __( 'Left', 'torobpay-for-woocommerce' ),
		'right'         => __( 'Right', 'torobpay-for-woocommerce' ),
		'top-left'      => __( 'Top left', 'torobpay-for-woocommerce' ),
		'top-center'    => __( 'Top center', 'torobpay-for-woocommerce' ),
		'top-right'     => __( 'Top right', 'torobpay-for-woocommerce' ),
		'center-left'   => __( 'Middle left', 'torobpay-for-woocommerce' ),
		'center-right'  => __( 'Middle right', 'torobpay-for-woocommerce' ),
		'bottom-left'   => __( 'Bottom left', 'torobpay-for-woocommerce' ),
		'bottom-center' => __( 'Bottom center', 'torobpay-for-woocommerce' ),
		'bottom-right'  => __( 'Bottom right', 'torobpay-for-woocommerce' ),
	);
}

/**
 * Resolve a stored object-position key to a CSS object-position value.
 *
 * @param string $key Stored position key.
 * @return string
 */
function torobpay_slider_image_object_position_css( $key ) {
	$key = sanitize_key( (string) $key );
	if ( ! array_key_exists( $key, torobpay_slider_image_object_positions() ) ) {
		return 'center';
	}

	return str_replace( '-', ' ', $key );
}

/**
 * Per-device (responsive) numeric typography/size controls owned by each slide.
 *
 * Each key is stored per-device as {key}, {key}_tablet and {key}_mobile (an
 * empty value means "inherit": fall back to the desktop value, or to the
 * stylesheet default when desktop is empty too). They drive per-slide,
 * per-device inline CSS overrides for the TorobPay brand mark, the heading /
 * subtext typography and the animated background scale (icons up, aurora down).
 *
 * The same keys apply to the bespoke first "hero" slide, where they target
 * the hero logo + the hero title lines instead (see the front-end CSS
 * builder). `subtext_*` is meaningless on the hero slide.
 *
 * @return array<string,array<string,mixed>>
 */
function torobpay_slider_slide_size_fields() {
	return array(
		'brand_size'     => array(
			'min'                  => 8,
			'max'                  => 240,
			'unit'                 => __( 'px', 'torobpay-for-woocommerce' ),
			'step'                 => 1,
			'label'                => __( 'TorobPay icon size', 'torobpay-for-woocommerce' ),
			'tip'                  => __( 'TorobPay logo size on this slide (empty = default).', 'torobpay-for-woocommerce' ),
			'default'              => 28,
			'hero_skip_placeholder' => true,
		),
		'heading_size'   => array(
			'min'                  => 8,
			'max'                  => 80,
			'unit'                 => __( 'px', 'torobpay-for-woocommerce' ),
			'step'                 => 1,
			'label'                => __( 'Title font size', 'torobpay-for-woocommerce' ),
			'tip'                  => __( 'Title font size (empty = default).', 'torobpay-for-woocommerce' ),
			'default'              => 30,
			'hero_skip_placeholder' => true,
		),
		'heading_weight' => array(
			'type'    => 'weight',
			'label'   => __( 'Title font weight', 'torobpay-for-woocommerce' ),
			'tip'     => __( 'Title font weight (empty = default).', 'torobpay-for-woocommerce' ),
			'default' => 800,
		),
		'subtext_size'   => array(
			'min'     => 8,
			'max'     => 60,
			'unit'    => __( 'px', 'torobpay-for-woocommerce' ),
			'step'    => 1,
			'label'   => __( 'Description font size', 'torobpay-for-woocommerce' ),
			'tip'     => __( 'Description font size (empty = default).', 'torobpay-for-woocommerce' ),
			'default' => 16,
		),
		'subtext_weight' => array(
			'type'    => 'weight',
			'label'   => __( 'Description font weight', 'torobpay-for-woocommerce' ),
			'tip'     => __( 'Description font weight (empty = default).', 'torobpay-for-woocommerce' ),
			'default' => 500,
		),
		'bg_icon_size'   => array(
			'min'   => 25,
			'max'   => 400,
			'unit'  => __( 'Percent', 'torobpay-for-woocommerce' ),
			'step'  => 5,
			'label' => __( 'Background icon size', 'torobpay-for-woocommerce' ),
			'tip'   => __( 'Decorative icon scale (percent). Empty = slide default. Lower = smaller, higher = larger.', 'torobpay-for-woocommerce' ),
		),
		'bg_aurora_scale' => array(
			'min'   => 25,
			'max'   => 200,
			'unit'  => __( 'Percent', 'torobpay-for-woocommerce' ),
			'step'  => 5,
			'label' => __( 'Aurora blob size', 'torobpay-for-woocommerce' ),
			'tip'   => __( 'Aurora light blob scale (percent). Empty = slide default. Lower = smaller, higher = larger.', 'torobpay-for-woocommerce' ),
		),
	);
}

/**
 * Background animations with icon-sized decorative elements.
 *
 * @return string[]
 */
function torobpay_slider_icon_bg_animations() {
	return array( 'perlin', 'rain', 'float', 'clover' );
}

/**
 * Canvas-driven background animations (density + speed apply).
 *
 * @return string[]
 */
function torobpay_slider_canvas_bg_animations() {
	return array( 'perlin', 'rain', 'float' );
}

/**
 * Whether a per-slide background sub-control applies to the chosen animation.
 *
 * @param string $control      density | speed | icon_scale | aurora_scale
 * @param string $bg_animation Slide background animation key.
 * @return bool
 */
function torobpay_slider_bg_control_visible( $control, $bg_animation ) {
	if ( 'none' === $bg_animation || '' === $bg_animation ) {
		return false;
	}

	switch ( $control ) {
		case 'density':
		case 'speed':
			return in_array( $bg_animation, torobpay_slider_canvas_bg_animations(), true );
		case 'icon_scale':
			return in_array( $bg_animation, torobpay_slider_icon_bg_animations(), true );
		case 'custom_icon':
			return in_array( $bg_animation, torobpay_slider_canvas_bg_animations(), true );
		case 'aurora_scale':
			return 'aurora' === $bg_animation;
	}

	return false;
}

/**
 * Allowed font-weight values for per-slide typography controls.
 *
 * @return array<string,string>
 */
function torobpay_slider_font_weight_choices() {
	return array(
		'300' => __( 'Light (300)', 'torobpay-for-woocommerce' ),
		'400' => __( 'Regular (400)', 'torobpay-for-woocommerce' ),
		'500' => __( 'Medium (500)', 'torobpay-for-woocommerce' ),
		'600' => __( 'Semi-bold (600)', 'torobpay-for-woocommerce' ),
		'700' => __( 'Bold (700)', 'torobpay-for-woocommerce' ),
		'800' => __( 'Extra bold (800)', 'torobpay-for-woocommerce' ),
	);
}

/**
 * Placeholder text for an empty per-slide size/scale field (stylesheet default).
 *
 * @param string               $key      Field key.
 * @param array<string,mixed>  $settings Slide settings.
 * @param array<string,mixed>  $cfg      Field config from torobpay_slider_slide_size_fields().
 * @param bool                 $is_hero  Whether this is the hero slide.
 * @return string
 */
function torobpay_slider_slide_field_placeholder( $key, $settings, $cfg, $is_hero = false ) {
	if ( $is_hero && ! empty( $cfg['hero_skip_placeholder'] ) ) {
		return '';
	}

	if ( 'bg_icon_size' === $key ) {
		$bg_animation = isset( $settings['bg_animation'] ) ? (string) $settings['bg_animation'] : '';
		return (string) torobpay_slider_bg_icon_scale_default( $bg_animation );
	}

	if ( 'bg_aurora_scale' === $key ) {
		return (string) torobpay_slider_bg_aurora_scale_default();
	}

	if ( isset( $cfg['default'] ) && '' !== (string) $cfg['default'] ) {
		return (string) $cfg['default'];
	}

	return '';
}

/**
 * Default icon-background scale (percent) for slider slides.
 *
 * @param string $bg_animation Slide background animation key.
 * @return int
 */
function torobpay_slider_bg_icon_scale_default( $bg_animation = '' ) {
	return 'clover' === $bg_animation ? 100 : 200;
}

/**
 * Default aurora-background scale (percent) for slider slides.
 *
 * @return int
 */
function torobpay_slider_bg_aurora_scale_default() {
	return 55;
}

/**
 * Resolve a per-device background-scale trio with inheritance + slider default.
 *
 * Empty desktop falls back to $default_percent; empty tablet/mobile inherit
 * the previous breakpoint in the chain (desktop → tablet → mobile).
 *
 * @param array  $slide           Slide settings.
 * @param string $key             Setting key (e.g. bg_icon_size).
 * @param int    $default_percent Fallback when desktop is empty.
 * @return array{base:int,tablet:int,mobile:int}
 */
function torobpay_slider_resolved_bg_scale_trio( $slide, $key, $default_percent ) {
	$base = isset( $slide[ $key ] ) ? trim( (string) $slide[ $key ] ) : '';
	if ( '' === $base ) {
		$base = (int) $default_percent;
	} else {
		$base = (int) $base;
	}

	$tablet = isset( $slide[ $key . '_tablet' ] ) ? trim( (string) $slide[ $key . '_tablet' ] ) : '';
	if ( '' === $tablet ) {
		$tablet = $base;
	} else {
		$tablet = (int) $tablet;
	}

	$mobile = isset( $slide[ $key . '_mobile' ] ) ? trim( (string) $slide[ $key . '_mobile' ] ) : '';
	if ( '' === $mobile ) {
		$mobile = $tablet;
	} else {
		$mobile = (int) $mobile;
	}

	return array(
		'base'   => $base,
		'tablet' => $tablet,
		'mobile' => $mobile,
	);
}

/**
 * Convert a percent scale (100 = 1×) to a unitless CSS/JS multiplier string.
 *
 * @param int $percent Scale in percent.
 * @return string
 */
function torobpay_slider_bg_scale_unitless( $percent ) {
	return (string) round( (int) $percent / 100, 3 );
}

/**
 * Default per-slide settings, shared by mandatory and custom slides.
 *
 * Every slide owns its own appearance/behaviour: transition, background
 * animation, branded preset, text position, display duration and per-device
 * visibility. Image fields are only meaningful for custom slides.
 *
 * @return array<string,mixed>
 */
function torobpay_slider_slide_setting_defaults() {
	$defaults = array(
		'text_position'     => 'middle-right',
		'bg_preset'         => 'mint',
		'transition'        => 'slide',
		'bg_animation'      => 'aurora',
		'bg_density'        => 'medium',
		'bg_speed'          => 5,
		'duration'          => 5,
		'bg_custom_icon'        => '',
		'bg_custom_icon_id'     => 0,
		'image_object_position' => 'center',
		'show_desktop'          => 'yes',
		'show_tablet'       => 'yes',
		'show_mobile'       => 'yes',
	);

	// Per-device typography/size overrides default to '' (inherit).
	foreach ( array_keys( torobpay_slider_slide_size_fields() ) as $key ) {
		$defaults[ $key ]             = '';
		$defaults[ $key . '_tablet' ] = '';
		$defaults[ $key . '_mobile' ] = '';
	}

	return $defaults;
}

/**
 * Stored sentinel when the shop name is intentionally omitted from the slide.
 *
 * @return string
 */
function torobpay_slider_shop_name_hidden_value() {
	return '__none__';
}

/**
 * Whether the stored shop name value means "hide the shop name".
 *
 * @param string $value Raw stored shop_name value.
 * @return bool
 */
function torobpay_slider_shop_name_is_hidden( $value ) {
	return torobpay_slider_shop_name_hidden_value() === (string) $value;
}

/**
 * Whether the shop name differs from the default site title.
 *
 * @param string $raw_shop_name Stored shop_name value.
 * @param string $site_default  WordPress site title.
 * @return bool
 */
function torobpay_slider_shop_name_is_edited( $raw_shop_name, $site_default = '' ) {
	if ( torobpay_slider_shop_name_is_hidden( $raw_shop_name ) ) {
		return true;
	}

	$trimmed = trim( (string) $raw_shop_name );
	if ( '' === $trimmed ) {
		return false;
	}

	if ( '' === $site_default ) {
		$site_default = trim( (string) get_bloginfo( 'name' ) );
	}

	return $trimmed !== $site_default;
}

/**
 * Resolve the shop name shown in the mandatory hero slide.
 *
 * Uses the optional per-slide override when set; otherwise falls back to the
 * WordPress site title. The hidden sentinel removes the shop name entirely.
 *
 * @param array<string,mixed> $settings Sanitized mandatory slide settings.
 * @return string
 */
function torobpay_slider_resolve_shop_name( $settings = array() ) {
	if ( is_array( $settings ) && isset( $settings['shop_name'] ) ) {
		$raw = (string) $settings['shop_name'];
		if ( torobpay_slider_shop_name_is_hidden( $raw ) ) {
			return '';
		}

		$custom = trim( $raw );
		if ( '' !== $custom ) {
			return $custom;
		}
	}

	return trim( (string) get_bloginfo( 'name' ) );
}

/**
 * Build the brand-locked hero slide record from a settings array.
 *
 * Shared by torobpay_slider_get_mandatory_slides() (reads the saved
 * `torobpay_slider_mandatory` option) and the admin live preview, which
 * instead passes freshly sanitized, unsaved settings straight from POST.
 *
 * @param array<string,mixed> $settings Sanitized per-slide settings overrides.
 * @return array<string,mixed>
 */
function torobpay_slider_build_mandatory_from_settings( $settings ) {
	$merged = array_merge(
		torobpay_slider_slide_setting_defaults(),
		array(
			'text_position' => 'middle-center',
			'bg_preset'     => 'mint-gradient',
			'bg_animation'  => 'clover',
		),
		is_array( $settings ) ? $settings : array()
	);

	$merged['show_desktop'] = 'yes';
	$merged['show_tablet']  = 'yes';
	$merged['show_mobile']  = 'yes';

	$shop_name = torobpay_slider_resolve_shop_name( $merged );
	$heading   = '' !== $shop_name
		? sprintf(
			/* translators: %s: shop or store name */
			__( 'Buy in installments from %s with TorobPay', 'torobpay-for-woocommerce' ),
			$shop_name
		)
		: __( 'Buy in installments with TorobPay', 'torobpay-for-woocommerce' );

	return array_merge(
		$merged,
		array(
			'type'      => 'mandatory',
			'index'     => 0,
			'heading'   => $heading,
			'subtext'   => __( '4 installments, no fees, no guarantor, no checks', 'torobpay-for-woocommerce' ),
			'cta_label' => '',
			'cta_url'   => '',
			'image'     => '',
			'image_id'  => 0,
		)
	);
}

/**
 * The brand-locked BNPL hero slide.
 *
 * Always renders first with fixed Persian copy that cannot be edited or
 * removed. Per-slide settings (position, preset, transition, background
 * animation, duration, hero badges) are editable and stored in the
 * `torobpay_slider_mandatory` option. A background image is never
 * permitted on this slide.
 *
 * @return array<int,array<string,mixed>>
 */
function torobpay_slider_get_mandatory_slides() {
	$overrides = get_option( 'torobpay_slider_mandatory', array() );
	$settings  = ( is_array( $overrides ) && ! empty( $overrides ) )
		? torobpay_slider_sanitize_mandatory( $overrides )
		: array();

	$slides = array( torobpay_slider_build_mandatory_from_settings( $settings ) );

	/**
	 * Allow fine-tuning the mandatory slide's copy/branding (NOT removal).
	 */
	$slides = apply_filters( 'torobpay_slider_mandatory_slides', $slides );
	if ( ! is_array( $slides ) ) {
		return array();
	}

	return $slides;
}

/**
 * Sanitize a stored slide id (positive integer, like a post ID).
 *
 * @param mixed $id Raw slide id.
 * @return string Empty when invalid.
 */
function torobpay_slider_sanitize_slide_id( $id ) {
	$id = absint( $id );
	return $id > 0 ? (string) $id : '';
}

/**
 * Generate the next unused numeric slide id.
 *
 * @param string[] $reserved Ids already assigned in the current batch (migration).
 * @return string
 */
function torobpay_slider_generate_slide_id( $reserved = array() ) {
	$max = 0;

	$slides = get_option( 'torobpay_slider_slides', array() );
	if ( is_array( $slides ) ) {
		foreach ( $slides as $slide ) {
			if ( ! is_array( $slide ) || ! isset( $slide['slide_id'] ) ) {
				continue;
			}
			$id = torobpay_slider_sanitize_slide_id( $slide['slide_id'] );
			if ( '' !== $id ) {
				$max = max( $max, (int) $id );
			}
		}
	}

	if ( is_array( $reserved ) ) {
		foreach ( $reserved as $id ) {
			$id = torobpay_slider_sanitize_slide_id( $id );
			if ( '' !== $id ) {
				$max = max( $max, (int) $id );
			}
		}
	}

	return (string) ( $max + 1 );
}

/**
 * Shortcode id for the brand-locked hero slide.
 *
 * @return string
 */
function torobpay_slider_mandatory_slide_id() {
	return 'torobpay';
}

/**
 * Build the [torobpay_slide] shortcode for admin display / copy.
 *
 * @param string $slide_id Numeric slide id or the torobpay alias.
 * @return string
 */
function torobpay_slider_get_slide_shortcode_string( $slide_id ) {
	$slide_id = trim( (string) $slide_id );
	if ( '' === $slide_id ) {
		return '';
	}

	return '[torobpay_slide id="' . $slide_id . '"]';
}

/**
 * Find one slide by stable id.
 *
 * @param string $id Numeric slide id or the torobpay alias.
 * @return array{slide:array<string,mixed>,index:int}|null
 */
function torobpay_slider_resolve_slide( $id = '' ) {
	$all = torobpay_slider_get_all_slides();
	if ( empty( $all ) ) {
		return null;
	}

	$id = strtolower( trim( (string) $id ) );
	if ( '' === $id ) {
		return null;
	}

	if ( torobpay_slider_mandatory_slide_id() === $id ) {
		return array(
			'slide' => $all[0],
			'index' => 0,
		);
	}

	$sanitized = torobpay_slider_sanitize_slide_id( $id );
	if ( '' === $sanitized ) {
		return null;
	}

	foreach ( $all as $i => $slide ) {
		$stored = isset( $slide['slide_id'] ) ? torobpay_slider_sanitize_slide_id( $slide['slide_id'] ) : '';
		if ( '' !== $stored && $stored === $sanitized ) {
			return array(
				'slide' => $slide,
				'index' => (int) $i,
			);
		}
	}

	return null;
}

/**
 * Read the merchant-added custom slides.
 *
 * @return array<int,array<string,mixed>>
 */
function torobpay_slider_get_custom_slides() {
	$slides = get_option( 'torobpay_slider_slides', array() );
	if ( ! is_array( $slides ) ) {
		return array();
	}

	$defaults      = torobpay_slider_slide_setting_defaults();
	$clean         = array();
	$needs_persist = false;
	$to_store      = array();
	$assigned_ids  = array();

	foreach ( $slides as $slide ) {
		if ( ! is_array( $slide ) ) {
			continue;
		}
		// Merge defaults so older/partial records always expose every key.
		$merged = array_merge( $defaults, $slide, array( 'type' => 'custom' ) );
		if ( ! isset( $merged['placements'] ) || ! is_array( $merged['placements'] ) ) {
			$merged['placements'] = array();
		}

		$stored_id = isset( $merged['slide_id'] ) ? torobpay_slider_sanitize_slide_id( $merged['slide_id'] ) : '';
		if ( '' === $stored_id ) {
			$stored_id     = torobpay_slider_generate_slide_id( $assigned_ids );
			$merged['slide_id'] = $stored_id;
			$needs_persist = true;
		} else {
			$merged['slide_id'] = $stored_id;
		}

		$assigned_ids[] = $stored_id;
		$clean[]          = $merged;

		$record = $slide;
		$record['slide_id'] = $stored_id;
		$to_store[]         = $record;
	}

	if ( $needs_persist && ! empty( $to_store ) ) {
		update_option( 'torobpay_slider_slides', $to_store );
	}

	return $clean;
}

/**
 * Sanitize the per-slide settings portion (shared by mandatory + custom).
 *
 * @param mixed $raw         Raw settings array.
 * @param bool  $allow_image Whether a background image is permitted.
 * @return array<string,mixed>
 */
function torobpay_slider_sanitize_slide_settings( $raw, $allow_image = true ) {
	$defaults = torobpay_slider_slide_setting_defaults();
	if ( ! is_array( $raw ) ) {
		return $defaults;
	}

	$presets     = array_keys( torobpay_slider_bg_presets() );
	$positions   = array_keys( torobpay_slider_text_positions() );
	$transitions = array( 'slide', 'fade', 'zoom', 'flip' );
	$bg_anims    = array( 'none', 'aurora', 'clover', 'perlin', 'rain', 'float' );
	$densities   = array( 'low', 'medium', 'high', 'dense' );

	$out = $defaults;

	$pos                  = isset( $raw['text_position'] ) ? sanitize_key( wp_unslash( $raw['text_position'] ) ) : $defaults['text_position'];
	$out['text_position'] = in_array( $pos, $positions, true ) ? $pos : $defaults['text_position'];

	$preset           = isset( $raw['bg_preset'] ) ? sanitize_key( wp_unslash( $raw['bg_preset'] ) ) : $defaults['bg_preset'];
	$out['bg_preset'] = in_array( $preset, $presets, true ) ? $preset : $defaults['bg_preset'];

	$transition        = isset( $raw['transition'] ) ? sanitize_key( wp_unslash( $raw['transition'] ) ) : $defaults['transition'];
	$out['transition'] = in_array( $transition, $transitions, true ) ? $transition : $defaults['transition'];

	$bg_anim             = isset( $raw['bg_animation'] ) ? sanitize_key( wp_unslash( $raw['bg_animation'] ) ) : $defaults['bg_animation'];
	$out['bg_animation'] = in_array( $bg_anim, $bg_anims, true ) ? $bg_anim : $defaults['bg_animation'];

	$bg_density        = isset( $raw['bg_density'] ) ? sanitize_key( wp_unslash( $raw['bg_density'] ) ) : $defaults['bg_density'];
	$out['bg_density'] = in_array( $bg_density, $densities, true ) ? $bg_density : $defaults['bg_density'];

	$out['bg_speed'] = isset( $raw['bg_speed'] ) ? max( 1, min( 10, absint( $raw['bg_speed'] ) ) ) : $defaults['bg_speed'];
	$out['duration'] = isset( $raw['duration'] ) ? max( 2, min( 30, absint( $raw['duration'] ) ) ) : $defaults['duration'];

	foreach ( array( 'show_desktop', 'show_tablet', 'show_mobile' ) as $vis ) {
		$out[ $vis ] = ( isset( $raw[ $vis ] ) && 'yes' === $raw[ $vis ] ) ? 'yes' : 'no';
	}

	// Per-device typography/size overrides ('' = inherit; otherwise clamped).
	$weight_keys = array_keys( torobpay_slider_font_weight_choices() );
	foreach ( torobpay_slider_slide_size_fields() as $key => $cfg ) {
		$is_weight = isset( $cfg['type'] ) && 'weight' === $cfg['type'];
		foreach ( array( '', '_tablet', '_mobile' ) as $suffix ) {
			$field   = $key . $suffix;
			$raw_val = isset( $raw[ $field ] ) ? trim( (string) wp_unslash( $raw[ $field ] ) ) : '';
			if ( '' === $raw_val ) {
				$out[ $field ] = '';
			} elseif ( $is_weight ) {
				$val           = (string) absint( $raw_val );
				$out[ $field ] = in_array( $val, $weight_keys, true ) ? $val : '';
			} else {
				$out[ $field ] = max( (int) $cfg['min'], min( (int) $cfg['max'], absint( $raw_val ) ) );
			}
		}
	}

	if ( $allow_image ) {
		$out['image']    = isset( $raw['image'] ) ? esc_url_raw( wp_unslash( $raw['image'] ) ) : '';
		$out['image_id'] = isset( $raw['image_id'] ) ? absint( $raw['image_id'] ) : 0;

		$img_pos_keys = array_keys( torobpay_slider_image_object_positions() );
		$img_pos      = isset( $raw['image_object_position'] ) ? sanitize_key( wp_unslash( $raw['image_object_position'] ) ) : $defaults['image_object_position'];
		$out['image_object_position'] = in_array( $img_pos, $img_pos_keys, true ) ? $img_pos : $defaults['image_object_position'];
	} else {
		$out['image']                 = '';
		$out['image_id']              = 0;
		$out['image_object_position'] = $defaults['image_object_position'];
	}

	list( $out['bg_custom_icon'], $out['bg_custom_icon_id'] ) = torobpay_sanitize_bg_custom_icon(
		isset( $raw['bg_custom_icon'] ) ? wp_unslash( $raw['bg_custom_icon'] ) : '',
		isset( $raw['bg_custom_icon_id'] ) ? $raw['bg_custom_icon_id'] : 0
	);

	return $out;
}

/**
 * Sanitize the mandatory hero slide's setting overrides.
 *
 * Text/image content is never stored — only editable settings. Device
 * visibility is forced to 'yes' when the slide is loaded.
 *
 * @param mixed $raw Raw $_POST['torobpay_slider_mandatory'] value.
 * @return array<string,mixed>
 */
function torobpay_slider_sanitize_mandatory( $raw ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$sanitized = torobpay_slider_sanitize_slide_settings( $raw, false );
	unset( $sanitized['show_desktop'], $sanitized['show_tablet'], $sanitized['show_mobile'] );

	$shop_name = isset( $raw['shop_name'] ) ? sanitize_text_field( wp_unslash( $raw['shop_name'] ) ) : '';
	if ( function_exists( 'mb_substr' ) ) {
		$shop_name = mb_substr( $shop_name, 0, 100 );
	} else {
		$shop_name = substr( $shop_name, 0, 100 );
	}
	$sanitized['shop_name'] = $shop_name;

	return $sanitized;
}

/**
 * Sanitize placement hook names assigned to a custom slide.
 *
 * @param mixed                  $raw         Raw placements value (array of hook names).
 * @param array<int,string>|null $valid_hooks Deprecated; assignments may reference deleted placement rows.
 * @return array<int,string>
 */
function torobpay_slider_sanitize_slide_placements( $raw, $valid_hooks = null ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}
	unset( $valid_hooks );

	$out = array();

	foreach ( $raw as $hook ) {
		$hook = torobpay_slider_sanitize_hook_name( (string) $hook );
		if ( '' === $hook || in_array( $hook, $out, true ) ) {
			continue;
		}
		$out[] = $hook;
	}

	return $out;
}

/**
 * Sanitize the raw POSTed custom-slide repeater into a clean storable array.
 *
 * @param mixed              $raw         Raw $_POST['torobpay_slider_slides'] value.
 * @param array<int,string>  $valid_hooks Deprecated; retained for backwards-compatible calls.
 * @return array<int,array<string,mixed>>
 */
function torobpay_slider_sanitize_slides( $raw, $valid_hooks = array() ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}
	unset( $valid_hooks );

	$clean = array();
	foreach ( $raw as $slide ) {
		if ( ! is_array( $slide ) ) {
			continue;
		}

		$heading   = isset( $slide['heading'] ) ? sanitize_text_field( wp_unslash( $slide['heading'] ) ) : '';
		$subtext   = isset( $slide['subtext'] ) ? sanitize_textarea_field( wp_unslash( $slide['subtext'] ) ) : '';
		$cta_label = isset( $slide['cta_label'] ) ? sanitize_text_field( wp_unslash( $slide['cta_label'] ) ) : '';
		$cta_url   = isset( $slide['cta_url'] ) ? esc_url_raw( wp_unslash( $slide['cta_url'] ) ) : '';

		$settings = torobpay_slider_sanitize_slide_settings( $slide, true );
		$placements = torobpay_slider_sanitize_slide_placements( isset( $slide['placements'] ) ? $slide['placements'] : array() );

		// Drop entirely empty slides (no text, no image).
		if ( '' === $heading && '' === $subtext && '' === $settings['image'] ) {
			continue;
		}

		$slide_id = isset( $slide['slide_id'] ) ? torobpay_slider_sanitize_slide_id( $slide['slide_id'] ) : '';
		if ( '' === $slide_id ) {
			$slide_id = torobpay_slider_generate_slide_id(
				array_column( $clean, 'slide_id' )
			);
		}

		$clean[] = array_merge(
			$settings,
			array(
				'slide_id'   => $slide_id,
				'heading'    => $heading,
				'subtext'    => $subtext,
				'cta_label'  => $cta_label,
				'cta_url'    => $cta_url,
				'placements' => $placements,
			)
		);
	}

	return $clean;
}

/**
 * Return slides visible for a rendering context.
 *
 * @param string|null $placement_hook Hook name for automatic placements, or null for shortcode (all slides).
 * @return array<int,array<string,mixed>>
 */
function torobpay_slider_get_slides_for_context( $placement_hook = null ) {
	$mandatory = torobpay_slider_get_mandatory_slides();
	$custom    = torobpay_slider_get_custom_slides();

	if ( null === $placement_hook ) {
		return array_merge( $mandatory, $custom );
	}

	$placement_hook = (string) $placement_hook;
	if ( '' === $placement_hook ) {
		return $mandatory;
	}

	$filtered = array();
	foreach ( $custom as $slide ) {
		$placements = isset( $slide['placements'] ) && is_array( $slide['placements'] ) ? $slide['placements'] : array();
		if ( in_array( $placement_hook, $placements, true ) ) {
			$filtered[] = $slide;
		}
	}

	return array_merge( $mandatory, $filtered );
}

/**
 * Mirrors torobpay_slider_get_slides_for_context() for the admin live
 * preview, but reads the mandatory + custom slides from freshly sanitized
 * (unsaved) draft arrays instead of get_option().
 *
 * @param string               $placement_hook Hook name of the previewed placement.
 * @param array<string,mixed>  $mandatory_slide Draft mandatory slide record.
 * @param array<int,array>     $custom_slides   Draft custom slides.
 * @return array<int,array<string,mixed>>
 */
function torobpay_slider_get_slides_for_preview( $placement_hook, $mandatory_slide, $custom_slides ) {
	$placement_hook = (string) $placement_hook;
	if ( '' === $placement_hook ) {
		return array( $mandatory_slide );
	}

	$filtered = array();
	foreach ( $custom_slides as $slide ) {
		$placements = isset( $slide['placements'] ) && is_array( $slide['placements'] ) ? $slide['placements'] : array();
		if ( in_array( $placement_hook, $placements, true ) ) {
			$filtered[] = $slide;
		}
	}

	return array_merge( array( $mandatory_slide ), $filtered );
}

/**
 * The full ordered slide list: brand-locked mandatory slide first, then
 * the merchant's custom slides.
 *
 * @return array<int,array<string,mixed>>
 */
function torobpay_slider_get_all_slides() {
	return array_merge(
		torobpay_slider_get_mandatory_slides(),
		torobpay_slider_get_custom_slides()
	);
}

/**
 * Curated placement hooks offered in the settings dropdown.
 *
 * @return array<string,string>
 */
function torobpay_slider_get_hook_choices() {
	return array(
		'woocommerce_before_main_content'   => __( 'Start of shop content (default)', 'torobpay-for-woocommerce' ),
		'woocommerce_after_main_content'    => __( 'End of shop content', 'torobpay-for-woocommerce' ),
		'woocommerce_before_shop_loop'      => __( 'Above product list', 'torobpay-for-woocommerce' ),
		'woocommerce_after_shop_loop'       => __( 'Below product list', 'torobpay-for-woocommerce' ),
		'woocommerce_before_single_product' => __( 'Above product page', 'torobpay-for-woocommerce' ),
		'woocommerce_after_single_product'  => __( 'Below product page', 'torobpay-for-woocommerce' ),
		'woocommerce_before_cart'           => __( 'Above cart page', 'torobpay-for-woocommerce' ),
		'woocommerce_before_checkout_form'  => __( 'Above checkout page', 'torobpay-for-woocommerce' ),
		'wp_body_open'                      => __( 'Start of site body (all pages)', 'torobpay-for-woocommerce' ),
	);
}

/**
 * Baseline placement schema merged with hook-specific presets.
 *
 * @param string $hook Hook name used to pick recommended defaults.
 * @return array<string,mixed>
 */
function torobpay_slider_default_placement( $hook = 'woocommerce_before_main_content' ) {
	$base = array(
		'enabled'          => 'no',
		'hook'             => 'woocommerce_before_main_content',
		'priority'         => 10,
		'show_desktop'     => 'yes',
		'show_tablet'      => 'yes',
		'show_mobile'      => 'yes',
		'autoplay'         => 'yes',
		'arrows'           => 'yes',
		'dots'             => 'yes',
		'pause_hover'      => 'yes',
		'height'           => 420,
		'height_unit'      => 'px',
		'height_tablet'    => '',
		'height_mobile'    => '',
		'max_width'        => 1200,
		'max_width_unit'   => 'px',
		'max_width_tablet' => '',
		'max_width_mobile' => '',
		'radius'           => 14,
	);

	return array_merge( $base, torobpay_slider_get_placement_preset( $hook ) );
}

/**
 * Fresh-install / reset defaults: three WooCommerce hooks enabled.
 *
 * The slider master toggle stays off; these placements are pre-configured so
 * merchants only need to enable the feature.
 *
 * @return array<int,array<string,mixed>>
 */
function torobpay_slider_get_default_placements() {
	$hooks = array(
		'woocommerce_before_single_product',
		'woocommerce_before_shop_loop',
		'woocommerce_before_cart',
	);

	$placements = array();
	foreach ( $hooks as $hook ) {
		$placements[] = torobpay_slider_sanitize_placement(
			array_merge(
				torobpay_slider_default_placement( $hook ),
				array(
					'hook'    => $hook,
					'enabled' => 'yes',
				)
			)
		);
	}

	return $placements;
}

/**
 * Whether pre-placements slider options exist (single-hook era).
 *
 * @return bool
 */
function torobpay_slider_has_legacy_storage() {
	$legacy_keys = array(
		'torobpay_slider_display_hook',
		'torobpay_slider_height',
		'torobpay_slider_show_desktop',
		'torobpay_slider_max_width',
		'torobpay_slider_autoplay',
	);

	foreach ( $legacy_keys as $key ) {
		if ( false !== get_option( $key, false ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Recommended per-hook defaults so merchants need less tuning.
 *
 * @return array<string,array<string,mixed>>
 */
function torobpay_slider_get_placement_presets() {
	return array(
		'woocommerce_before_main_content'   => array(
			'hook'      => 'woocommerce_before_main_content',
			'height'    => 420,
			'max_width' => 1200,
			'arrows'    => 'yes',
			'dots'      => 'yes',
			'autoplay'  => 'yes',
		),
		'woocommerce_before_shop_loop'      => array(
			'hook'      => 'woocommerce_before_shop_loop',
			'enabled'   => 'yes',
			'height'    => 300,
			'max_width' => 1200,
			'arrows'    => 'no',
			'dots'      => 'yes',
			'autoplay'  => 'yes',
		),
		'woocommerce_after_shop_loop'       => array(
			'hook'      => 'woocommerce_after_shop_loop',
			'height'    => 280,
			'max_width' => 1200,
			'arrows'    => 'no',
			'dots'      => 'yes',
			'autoplay'  => 'yes',
		),
		'woocommerce_before_single_product' => array(
			'hook'      => 'woocommerce_before_single_product',
			'enabled'   => 'yes',
			'height'    => 320,
			'max_width' => 1100,
			'arrows'    => 'no',
			'dots'      => 'yes',
			'autoplay'  => 'yes',
		),
		'woocommerce_after_single_product'  => array(
			'hook'      => 'woocommerce_after_single_product',
			'height'    => 280,
			'max_width' => 1100,
			'arrows'    => 'no',
			'dots'      => 'yes',
			'autoplay'  => 'yes',
		),
		'woocommerce_before_cart'           => array(
			'hook'        => 'woocommerce_before_cart',
			'enabled'     => 'yes',
			'height'      => 260,
			'max_width'   => 1000,
			'arrows'      => 'no',
			'dots'        => 'yes',
			'autoplay'    => 'yes',
			'pause_hover' => 'yes',
		),
		'woocommerce_before_checkout_form'  => array(
			'hook'        => 'woocommerce_before_checkout_form',
			'height'      => 240,
			'max_width'   => 960,
			'arrows'      => 'no',
			'dots'        => 'yes',
			'autoplay'    => 'yes',
			'pause_hover' => 'yes',
		),
		'woocommerce_after_main_content'    => array(
			'hook'      => 'woocommerce_after_main_content',
			'height'    => 260,
			'max_width' => 1200,
			'arrows'    => 'no',
			'dots'      => 'yes',
			'autoplay'  => 'yes',
		),
		'wp_body_open'                      => array(
			'hook'      => 'wp_body_open',
			'enabled'   => 'no',
			'height'    => 220,
			'max_width' => 1200,
			'arrows'    => 'no',
			'dots'      => 'no',
			'autoplay'  => 'yes',
		),
	);
}

/**
 * Recommended defaults for one hook (custom hooks inherit the hero preset).
 *
 * @param string $hook Hook name.
 * @return array<string,mixed>
 */
function torobpay_slider_get_placement_preset( $hook ) {
	$presets    = torobpay_slider_get_placement_presets();
	$predefined = array_keys( torobpay_slider_get_hook_choices() );

	if ( isset( $presets[ $hook ] ) ) {
		return $presets[ $hook ];
	}

	if ( in_array( $hook, $predefined, true ) ) {
		return array( 'hook' => $hook );
	}

	// Custom theme hooks: balanced hero-like defaults.
	return array_merge(
		$presets['woocommerce_before_main_content'],
		array( 'hook' => $hook )
	);
}

/**
 * Sanitize a WordPress action hook name.
 *
 * @param string $hook Raw hook name.
 * @return string
 */
function torobpay_slider_sanitize_hook_name( $hook ) {
	$hook = preg_replace( '/[^a-zA-Z0-9_\-]/', '', sanitize_text_field( (string) $hook ) );
	return is_string( $hook ) ? $hook : '';
}

/**
 * Normalize a checkbox value to yes/no.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function torobpay_slider_sanitize_yes_no( $value ) {
	return ( 'yes' === $value || '1' === $value || 1 === $value || true === $value ) ? 'yes' : 'no';
}

/**
 * Allowed CSS units for a placement dimension field.
 *
 * @param string $dimension height|max_width.
 * @return array<string,string>
 */
function torobpay_slider_placement_unit_options( $dimension ) {
	if ( 'height' === $dimension ) {
		return array(
			'px' => 'px',
			'%'  => '%',
			'vh' => 'vh',
		);
	}

	return array(
		'px' => 'px',
		'%'  => '%',
	);
}

/**
 * Sanitize a placement dimension unit.
 *
 * @param mixed  $unit      Raw unit.
 * @param string $dimension height|max_width.
 * @return string
 */
function torobpay_slider_sanitize_placement_unit( $unit, $dimension ) {
	$allowed = array_keys( torobpay_slider_placement_unit_options( $dimension ) );
	$unit    = sanitize_text_field( (string) $unit );
	return in_array( $unit, $allowed, true ) ? $unit : 'px';
}

/**
 * Min/max clamps for a placement dimension value.
 *
 * @param string $dimension height|max_width.
 * @param string $unit      Sanitized CSS unit.
 * @return array{min:int,max:int}
 */
function torobpay_slider_placement_dimension_bounds( $dimension, $unit ) {
	if ( 'height' === $dimension ) {
		if ( in_array( $unit, array( '%', 'vh' ), true ) ) {
			return array(
				'min' => 10,
				'max' => 100,
			);
		}

		return array(
			'min' => 80,
			'max' => 800,
		);
	}

	if ( '%' === $unit ) {
		return array(
			'min' => 10,
			'max' => 100,
		);
	}

	return array(
		'min' => 200,
		'max' => 2000,
	);
}

/**
 * Sanitize one placement dimension value (base or per-device override).
 *
 * @param mixed  $raw       Raw value.
 * @param string $dimension height|max_width.
 * @param string $unit      Sanitized CSS unit.
 * @param int    $default   Fallback when base is absent.
 * @return string|int
 */
function torobpay_slider_sanitize_placement_dimension_value( $raw, $dimension, $unit, $default ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}

	$bounds = torobpay_slider_placement_dimension_bounds( $dimension, $unit );
	return max( $bounds['min'], min( $bounds['max'], absint( $raw ) ) );
}

/**
 * Sanitize one placement record from POST/admin storage.
 *
 * @param array<string,mixed> $raw Raw placement.
 * @return array<string,mixed>
 */
function torobpay_slider_sanitize_placement( $raw ) {
	if ( ! is_array( $raw ) ) {
		$raw = array();
	}

	$predefined   = array_keys( torobpay_slider_get_hook_choices() );
	$selected     = isset( $raw['hook_select'] ) ? sanitize_text_field( (string) $raw['hook_select'] ) : ( isset( $raw['hook'] ) ? sanitize_text_field( (string) $raw['hook'] ) : 'woocommerce_before_main_content' );
	$custom_hook  = isset( $raw['hook_custom'] ) ? torobpay_slider_sanitize_hook_name( $raw['hook_custom'] ) : '';
	$resolved_hook = $selected;

	if ( 'custom' === $selected ) {
		$resolved_hook = '' !== $custom_hook ? $custom_hook : 'woocommerce_before_main_content';
	} elseif ( ! in_array( $selected, $predefined, true ) ) {
		$resolved_hook = torobpay_slider_sanitize_hook_name( $selected );
		if ( '' === $resolved_hook ) {
			$resolved_hook = 'woocommerce_before_main_content';
		}
	}

	$defaults = torobpay_slider_default_placement( $resolved_hook );

	$height_unit    = torobpay_slider_sanitize_placement_unit( isset( $raw['height_unit'] ) ? $raw['height_unit'] : $defaults['height_unit'], 'height' );
	$max_width_unit = torobpay_slider_sanitize_placement_unit( isset( $raw['max_width_unit'] ) ? $raw['max_width_unit'] : $defaults['max_width_unit'], 'max_width' );

	$height_base = torobpay_slider_sanitize_placement_dimension_value(
		isset( $raw['height'] ) ? $raw['height'] : $defaults['height'],
		'height',
		$height_unit,
		(int) $defaults['height']
	);
	if ( '' === $height_base ) {
		$height_bounds = torobpay_slider_placement_dimension_bounds( 'height', $height_unit );
		$height_base   = max( $height_bounds['min'], min( $height_bounds['max'], absint( $defaults['height'] ) ) );
	}

	$max_width_base = torobpay_slider_sanitize_placement_dimension_value(
		isset( $raw['max_width'] ) ? $raw['max_width'] : $defaults['max_width'],
		'max_width',
		$max_width_unit,
		(int) $defaults['max_width']
	);
	if ( '' === $max_width_base ) {
		$max_width_bounds = torobpay_slider_placement_dimension_bounds( 'max_width', $max_width_unit );
		$max_width_base   = max( $max_width_bounds['min'], min( $max_width_bounds['max'], absint( $defaults['max_width'] ) ) );
	}

	$placement = array(
		'enabled'          => torobpay_slider_sanitize_yes_no( isset( $raw['enabled'] ) ? $raw['enabled'] : $defaults['enabled'] ),
		'hook'             => $resolved_hook,
		'priority'         => isset( $raw['priority'] ) ? max( 1, min( 200, absint( $raw['priority'] ) ) ) : (int) $defaults['priority'],
		'show_desktop'     => torobpay_slider_sanitize_yes_no( isset( $raw['show_desktop'] ) ? $raw['show_desktop'] : $defaults['show_desktop'] ),
		'show_tablet'      => torobpay_slider_sanitize_yes_no( isset( $raw['show_tablet'] ) ? $raw['show_tablet'] : $defaults['show_tablet'] ),
		'show_mobile'      => torobpay_slider_sanitize_yes_no( isset( $raw['show_mobile'] ) ? $raw['show_mobile'] : $defaults['show_mobile'] ),
		'autoplay'         => torobpay_slider_sanitize_yes_no( isset( $raw['autoplay'] ) ? $raw['autoplay'] : $defaults['autoplay'] ),
		'arrows'           => torobpay_slider_sanitize_yes_no( isset( $raw['arrows'] ) ? $raw['arrows'] : $defaults['arrows'] ),
		'dots'             => torobpay_slider_sanitize_yes_no( isset( $raw['dots'] ) ? $raw['dots'] : $defaults['dots'] ),
		'pause_hover'      => torobpay_slider_sanitize_yes_no( isset( $raw['pause_hover'] ) ? $raw['pause_hover'] : $defaults['pause_hover'] ),
		'height'           => $height_base,
		'height_unit'      => $height_unit,
		'height_tablet'    => '',
		'height_mobile'    => '',
		'max_width'        => $max_width_base,
		'max_width_unit'   => $max_width_unit,
		'max_width_tablet' => '',
		'max_width_mobile' => '',
		'radius'           => isset( $raw['radius'] ) ? max( 0, min( 60, absint( $raw['radius'] ) ) ) : (int) $defaults['radius'],
	);

	foreach ( array( 'height', 'max_width' ) as $dim_key ) {
		$unit = 'height' === $dim_key ? $height_unit : $max_width_unit;
		foreach ( array( 'tablet', 'mobile' ) as $device ) {
			$field = $dim_key . '_' . $device;
			$raw_v = isset( $raw[ $field ] ) ? $raw[ $field ] : '';
			$san   = torobpay_slider_sanitize_placement_dimension_value( $raw_v, $dim_key, $unit, 0 );
			$placement[ $field ] = '' === $san ? '' : (string) $san;
		}
	}

	return $placement;
}

/**
 * Sanitize the full placements array.
 *
 * @param mixed $raw Raw stored/POST value.
 * @return array<int,array<string,mixed>>
 */
function torobpay_slider_sanitize_placements( $raw ) {
	if ( ! is_array( $raw ) ) {
		return torobpay_slider_get_default_placements();
	}

	$out   = array();
	$seen  = array();
	foreach ( $raw as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$placement = torobpay_slider_sanitize_placement( $item );
		$hook      = isset( $placement['hook'] ) ? (string) $placement['hook'] : '';
		if ( '' === $hook || isset( $seen[ $hook ] ) ) {
			continue;
		}
		$seen[ $hook ] = true;
		$out[]         = $placement;
	}

	if ( empty( $out ) ) {
		return torobpay_slider_get_default_placements();
	}

	return array_values( $out );
}

/**
 * Build the placements array from legacy single-hook options.
 *
 * @return array<int,array<string,mixed>>
 */
function torobpay_slider_build_placements_from_legacy() {
	if ( ! torobpay_slider_has_legacy_storage() ) {
		return torobpay_slider_get_default_placements();
	}

	$hook     = (string) torobpay_get_slider_setting( 'display_hook' );
	$defaults = torobpay_slider_default_placement( $hook );

	$placement = array_merge(
		$defaults,
		array(
			'enabled'          => 'yes',
			'hook'             => '' !== $hook ? $hook : 'woocommerce_before_main_content',
			'priority'         => (int) torobpay_get_slider_setting( 'display_priority' ),
			'show_desktop'     => (string) torobpay_get_slider_setting( 'show_desktop' ),
			'show_tablet'      => (string) torobpay_get_slider_setting( 'show_tablet' ),
			'show_mobile'      => (string) torobpay_get_slider_setting( 'show_mobile' ),
			'autoplay'         => (string) torobpay_get_slider_setting( 'autoplay' ),
			'arrows'           => (string) torobpay_get_slider_setting( 'arrows' ),
			'dots'             => (string) torobpay_get_slider_setting( 'dots' ),
			'pause_hover'      => (string) torobpay_get_slider_setting( 'pause_hover' ),
			'height'           => (int) get_option( 'torobpay_slider_height', 420 ),
			'height_unit'      => (string) get_option( 'torobpay_slider_height_unit', 'px' ),
			'height_tablet'    => (string) get_option( 'torobpay_slider_height_tablet', '' ),
			'height_mobile'    => (string) get_option( 'torobpay_slider_height_mobile', '' ),
			'max_width'        => (int) get_option( 'torobpay_slider_max_width', 1200 ),
			'max_width_unit'   => (string) get_option( 'torobpay_slider_max_width_unit', 'px' ),
			'max_width_tablet' => (string) get_option( 'torobpay_slider_max_width_tablet', '' ),
			'max_width_mobile' => (string) get_option( 'torobpay_slider_max_width_mobile', '' ),
			'radius'           => (int) torobpay_get_slider_setting( 'radius' ),
		)
	);

	return array( torobpay_slider_sanitize_placement( $placement ) );
}

/**
 * Read all slider placements, migrating legacy storage when needed.
 *
 * @return array<int,array<string,mixed>>
 */
function torobpay_slider_get_placements() {
	$stored = get_option( 'torobpay_slider_placements', null );

	if ( null === $stored ) {
		$migrated = torobpay_slider_build_placements_from_legacy();
		update_option( 'torobpay_slider_placements', $migrated );
		return $migrated;
	}

	return torobpay_slider_sanitize_placements( $stored );
}

/**
 * Default settings used by the [torobpay_slider] shortcode.
 *
 * @return array<string,mixed>
 */
function torobpay_slider_get_shortcode_defaults() {
	return torobpay_slider_default_placement( 'woocommerce_before_main_content' );
}

/**
 * Read a responsive trio from a placement or legacy global options.
 *
 * @param array<string,mixed>|null $placement Placement context or null for shortcode globals.
 * @param string                   $key       Base key (height|max_width).
 * @param int                      $default   Fallback base value.
 * @return array{base:mixed,tablet:string,mobile:string}
 */
function torobpay_slider_get_responsive_from_context( $placement, $key, $default ) {
	if ( is_array( $placement ) ) {
		return array(
			'base'   => isset( $placement[ $key ] ) ? $placement[ $key ] : $default,
			'tablet' => isset( $placement[ $key . '_tablet' ] ) ? $placement[ $key . '_tablet' ] : '',
			'mobile' => isset( $placement[ $key . '_mobile' ] ) ? $placement[ $key . '_mobile' ] : '',
		);
	}

	return torobpay_get_responsive_setting( 'torobpay_slider_' . $key, $default );
}

/**
 * Read a scalar slider setting from placement context or legacy globals.
 *
 * @param array<string,mixed>|null $placement Placement context.
 * @param string                   $key       Setting key.
 * @param mixed                    $fallback  Optional fallback when neither source has it.
 * @return mixed
 */
function torobpay_slider_get_context_setting( $placement, $key, $fallback = null ) {
	if ( is_array( $placement ) && array_key_exists( $key, $placement ) ) {
		return $placement[ $key ];
	}

	if ( null === $placement ) {
		$shortcode_defaults = torobpay_slider_get_shortcode_defaults();
		if ( array_key_exists( $key, $shortcode_defaults ) ) {
			return $shortcode_defaults[ $key ];
		}
	}

	$legacy = torobpay_get_slider_setting( $key );
	return null !== $fallback ? $legacy : $legacy;
}

/**
 * Human-readable label for a hook name in the admin UI.
 *
 * @param string $hook Hook name.
 * @return string
 */
function torobpay_slider_get_hook_label( $hook ) {
	$choices = torobpay_slider_get_hook_choices();
	if ( isset( $choices[ $hook ] ) ) {
		return $choices[ $hook ];
	}
	return $hook;
}

add_filter( 'torobpay_visual_settings_tabs', 'torobpay_slider_register_tab' );
/**
 * Register the "Slider" tab in the visual settings page navigation.
 *
 * @param array $tabs Existing extra tabs.
 * @return array
 */
function torobpay_slider_register_tab( $tabs ) {
	$tabs[] = array(
		'id'    => 'slider-settings',
		'icon'  => 'dashicons-images-alt2',
		'label' => __( 'Slider', 'torobpay-for-woocommerce' ),
	);

	return $tabs;
}

/**
 * Whether a placement is configured to show on at least one device.
 *
 * @param array<string,mixed> $placement Sanitized placement.
 * @return bool
 */
function torobpay_slider_placement_has_device_visibility( $placement ) {
	return 'yes' === ( $placement['show_desktop'] ?? 'yes' )
		|| 'yes' === ( $placement['show_tablet'] ?? 'yes' )
		|| 'yes' === ( $placement['show_mobile'] ?? 'yes' );
}

/**
 * Reset per-device visibility when every enabled placement is hidden everywhere.
 *
 * Saving with all devices unchecked auto-disables the slider but keeps the
 * hidden visibility flags. Re-enabling the slider must restore defaults so it
 * can actually render again.
 *
 * @return bool True when placements were updated.
 */
function torobpay_slider_reset_visibility_if_none_visible() {
	$placements = torobpay_slider_get_placements();
	$has_visible = false;

	foreach ( $placements as $placement ) {
		if ( 'yes' !== ( $placement['enabled'] ?? 'yes' ) ) {
			continue;
		}
		if ( torobpay_slider_placement_has_device_visibility( $placement ) ) {
			$has_visible = true;
			break;
		}
	}

	if ( $has_visible ) {
		return false;
	}

	foreach ( $placements as $index => $placement ) {
		$placements[ $index ]['show_desktop'] = 'yes';
		$placements[ $index ]['show_tablet']  = 'yes';
		$placements[ $index ]['show_mobile']  = 'yes';
	}

	update_option( 'torobpay_slider_placements', array_values( $placements ) );

	$first = $placements[0];
	update_option( 'torobpay_slider_show_desktop', $first['show_desktop'] );
	update_option( 'torobpay_slider_show_tablet', $first['show_tablet'] );
	update_option( 'torobpay_slider_show_mobile', $first['show_mobile'] );

	return true;
}

add_action( 'torobpay_visual_settings_save', 'torobpay_slider_save_settings' );
/**
 * Persist the slider settings.
 *
 * Fires from torobpay_widget_settings_page() only AFTER the page nonce has been
 * verified; we re-check the capability here as defense-in-depth.
 */
function torobpay_slider_save_settings() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$raw_placements = isset( $_POST['torobpay_slider_placements'] ) ? wp_unslash( $_POST['torobpay_slider_placements'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- Parent widget settings page verifies nonce before calling this.
	$placements     = torobpay_slider_sanitize_placements( $raw_placements );
	update_option( 'torobpay_slider_placements', $placements );

	$raw_custom = isset( $_POST['torobpay_slider_slides'] ) ? wp_unslash( $_POST['torobpay_slider_slides'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parent widget settings page verifies nonce; sanitized field-by-field in helper.
	update_option( 'torobpay_slider_slides', torobpay_slider_sanitize_slides( $raw_custom ) );

	$raw_mandatory = isset( $_POST['torobpay_slider_mandatory'] ) ? wp_unslash( $_POST['torobpay_slider_mandatory'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parent widget settings page verifies nonce; sanitized field-by-field in helper.
	update_option( 'torobpay_slider_mandatory', torobpay_slider_sanitize_mandatory( $raw_mandatory ) );

	$first = $placements[0];
	update_option( 'torobpay_slider_show_desktop', $first['show_desktop'] );
	update_option( 'torobpay_slider_show_tablet', $first['show_tablet'] );
	update_option( 'torobpay_slider_show_mobile', $first['show_mobile'] );
	update_option( 'torobpay_slider_display_hook', $first['hook'] );
	update_option( 'torobpay_slider_display_priority', (int) $first['priority'] );
	update_option( 'torobpay_slider_autoplay', $first['autoplay'] );
	update_option( 'torobpay_slider_arrows', $first['arrows'] );
	update_option( 'torobpay_slider_dots', $first['dots'] );
	update_option( 'torobpay_slider_pause_hover', $first['pause_hover'] );
	update_option( 'torobpay_slider_height', (int) $first['height'] );
	update_option( 'torobpay_slider_height_unit', $first['height_unit'] );
	update_option( 'torobpay_slider_height_tablet', $first['height_tablet'] );
	update_option( 'torobpay_slider_height_mobile', $first['height_mobile'] );
	update_option( 'torobpay_slider_max_width', (int) $first['max_width'] );
	update_option( 'torobpay_slider_max_width_unit', $first['max_width_unit'] );
	update_option( 'torobpay_slider_max_width_tablet', $first['max_width_tablet'] );
	update_option( 'torobpay_slider_max_width_mobile', $first['max_width_mobile'] );
	update_option( 'torobpay_slider_radius', (int) $first['radius'] );

	$has_visible = false;
	foreach ( $placements as $placement ) {
		if ( 'yes' !== $placement['enabled'] ) {
			continue;
		}
		if ( torobpay_slider_placement_has_device_visibility( $placement ) ) {
			$has_visible = true;
			break;
		}
	}
	if ( ! $has_visible ) {
		update_option( 'torobpay_slider_enabled', 'no' );
	}
}

add_action( 'torobpay_visual_settings_reset', 'torobpay_slider_reset_settings' );
/**
 * Reset the slider to its defaults.
 */
function torobpay_slider_reset_settings() {
	$default_placements = torobpay_slider_get_default_placements();
	update_option( 'torobpay_slider_placements', $default_placements );

	$first = $default_placements[0];
	update_option( 'torobpay_slider_show_desktop', $first['show_desktop'] );
	update_option( 'torobpay_slider_show_tablet', $first['show_tablet'] );
	update_option( 'torobpay_slider_show_mobile', $first['show_mobile'] );
	update_option( 'torobpay_slider_display_hook', $first['hook'] );
	update_option( 'torobpay_slider_display_priority', (int) $first['priority'] );
	update_option( 'torobpay_slider_autoplay', $first['autoplay'] );
	update_option( 'torobpay_slider_arrows', $first['arrows'] );
	update_option( 'torobpay_slider_dots', $first['dots'] );
	update_option( 'torobpay_slider_pause_hover', $first['pause_hover'] );
	update_option( 'torobpay_slider_height', (int) $first['height'] );
	update_option( 'torobpay_slider_height_unit', $first['height_unit'] );
	update_option( 'torobpay_slider_height_tablet', $first['height_tablet'] );
	update_option( 'torobpay_slider_height_mobile', $first['height_mobile'] );
	update_option( 'torobpay_slider_max_width', (int) $first['max_width'] );
	update_option( 'torobpay_slider_max_width_unit', $first['max_width_unit'] );
	update_option( 'torobpay_slider_max_width_tablet', $first['max_width_tablet'] );
	update_option( 'torobpay_slider_max_width_mobile', $first['max_width_mobile'] );
	update_option( 'torobpay_slider_radius', (int) $first['radius'] );
	update_option( 'torobpay_slider_slides', array() );
	update_option( 'torobpay_slider_mandatory', array() );
	// Note: the master enable lives in the shared display card and is reset there.
}

add_action( 'wp_ajax_torobpay_slider_preview', 'torobpay_ajax_slider_preview' );
/**
 * AJAX handler: live preview of the slider tab's unsaved form state.
 *
 * Sanitizes the same POSTed repeater fields torobpay_slider_save_settings()
 * would persist, but never writes to the database — it only builds the HTML
 * for one placement/device combination via the shared front-end pipeline.
 */
function torobpay_ajax_slider_preview() {
	check_ajax_referer( 'torobpay_slider_preview', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized', 'torobpay-for-woocommerce' ) ), 403 );
	}

	$device = isset( $_POST['preview_device'] ) ? sanitize_key( wp_unslash( $_POST['preview_device'] ) ) : 'desktop';
	if ( ! in_array( $device, array( 'desktop', 'tablet', 'mobile' ), true ) ) {
		$device = 'desktop';
	}

	$raw_placements = isset( $_POST['torobpay_slider_placements'] ) ? wp_unslash( $_POST['torobpay_slider_placements'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$placements     = torobpay_slider_sanitize_placements( $raw_placements );

	$index     = isset( $_POST['placement_index'] ) ? absint( $_POST['placement_index'] ) : 0;
	$placement = isset( $placements[ $index ] ) ? $placements[ $index ] : $placements[0];

	$raw_mandatory      = isset( $_POST['torobpay_slider_mandatory'] ) ? wp_unslash( $_POST['torobpay_slider_mandatory'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field in helper.
	$mandatory_settings = torobpay_slider_sanitize_mandatory( $raw_mandatory );
	$mandatory_slide    = torobpay_slider_build_mandatory_from_settings( $mandatory_settings );

	$raw_custom = isset( $_POST['torobpay_slider_slides'] ) ? wp_unslash( $_POST['torobpay_slider_slides'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field in helper.
	$custom     = torobpay_slider_sanitize_slides( $raw_custom );

	$hook   = isset( $placement['hook'] ) ? (string) $placement['hook'] : '';
	$slides = torobpay_slider_get_slides_for_preview( $hook, $mandatory_slide, $custom );

	$device_key = 'show_' . $device;
	if ( 'yes' !== ( $placement[ $device_key ] ?? 'yes' ) ) {
		wp_send_json_success(
			array(
				'html'    => '',
				'message' => __( 'This display location is disabled for this device.', 'torobpay-for-woocommerce' ),
			)
		);
	}

	$html = torobpay_slider_get_html(
		$placement,
		array(
			'slides'         => $slides,
			'slider_id'      => 'torobpay-slider-preview',
			'preview_device' => $device,
		)
	);

	if ( '' === $html ) {
		wp_send_json_success(
			array(
				'html'    => '',
				'message' => __( 'No slide is selected for this display location.', 'torobpay-for-woocommerce' ),
			)
		);
	}

	wp_send_json_success(
		array(
			'html'    => $html,
			'message' => '',
		)
	);
}

add_action( 'torobpay_visual_settings_tab_content', 'torobpay_slider_render_tab' );
/**
 * Render the "Slider" settings tab panel.
 */
function torobpay_slider_render_tab() {
	require __DIR__ . '/slider-settings-fields.php';
}

/**
 * Compact responsive numeric field inside a placement repeater row.
 *
 * @param string               $base      Field name base.
 * @param string               $key       Setting key (height|max_width).
 * @param array<string,mixed>  $placement Placement data.
 * @param array<string,mixed>  $cfg       Field config.
 */
function torobpay_slider_render_placement_responsive_field( $base, $key, $placement, $cfg ) {
	$bp      = torobpay_get_breakpoints();
	$desktop = isset( $placement[ $key ] ) ? (string) $placement[ $key ] : '';
	$tablet  = isset( $placement[ $key . '_tablet' ] ) ? (string) $placement[ $key . '_tablet' ] : '';
	$mobile  = isset( $placement[ $key . '_mobile' ] ) ? (string) $placement[ $key . '_mobile' ] : '';
	$inherit = '' !== $desktop ? $desktop : (string) $cfg['default'];

	$unit_field   = $key . '_unit';
	$unit_options = torobpay_slider_placement_unit_options( $key );
	$unit_value   = isset( $placement[ $unit_field ] )
		? torobpay_slider_sanitize_placement_unit( $placement[ $unit_field ], $key )
		: 'px';
	$bounds       = torobpay_slider_placement_dimension_bounds( $key, $unit_value );

	$devices = array(
		'desktop' => array(
			'field' => $base . '[' . $key . ']',
			'icon'  => 'dashicons-desktop',
			'title' => __( 'Desktop (default for all screens)', 'torobpay-for-woocommerce' ),
			'value' => $desktop,
			'ph'    => (string) $cfg['default'],
		),
		'tablet'  => array(
			'field' => $base . '[' . $key . '_tablet]',
			'icon'  => 'dashicons-tablet',
			'title' => __( 'Tablet (empty = inherit from desktop)', 'torobpay-for-woocommerce' ),
			'value' => $tablet,
			'ph'    => $inherit,
		),
		'mobile'  => array(
			'field' => $base . '[' . $key . '_mobile]',
			'icon'  => 'dashicons-smartphone',
			'title' => __( 'Mobile (empty = inherit from desktop)', 'torobpay-for-woocommerce' ),
			'value' => $mobile,
			'ph'    => $inherit,
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
		<span class="torobpay-responsive-field torobpay-slider-placement-dimension-field" data-active-device="desktop"
			data-dimension="<?php echo esc_attr( $key ); ?>"
			data-field-name="<?php echo esc_attr( $base . '[' . $key . ']' ); ?>"
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
					min="<?php echo esc_attr( (int) $bounds['min'] ); ?>"
					max="<?php echo esc_attr( (int) $bounds['max'] ); ?>"
					step="1"
					class="torobpay-rf-input"
					data-device="<?php echo esc_attr( $device_key ); ?>"
					placeholder="<?php echo esc_attr( $d['ph'] ); ?>">
			<?php endforeach; ?>
			<select name="<?php echo esc_attr( $base . '[' . $unit_field . ']' ); ?>" class="torobpay-rf-unit-select torobpay-slider-placement-unit" style="width:auto;min-width:60px;">
				<?php foreach ( $unit_options as $u_val => $u_label ) : ?>
					<option value="<?php echo esc_attr( $u_val ); ?>" <?php selected( $unit_value, $u_val ); ?>><?php echo esc_html( $u_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</span>
	</p>
	<?php
}

/**
 * Render one placement repeater card.
 *
 * @param int|string           $index     Row index.
 * @param array<string,mixed>  $placement Placement data.
 * @param bool                 $active    Whether this panel is initially visible.
 */
function torobpay_slider_render_placement_row( $index, $placement, $active = false ) {
	$placement     = is_array( $placement ) ? $placement : array();
	$hook_choices  = torobpay_slider_get_hook_choices();
	$hook          = isset( $placement['hook'] ) ? (string) $placement['hook'] : 'woocommerce_before_main_content';
	$is_custom     = ! array_key_exists( $hook, $hook_choices );
	$hook_select   = $is_custom ? 'custom' : $hook;
	$title         = torobpay_slider_get_hook_label( $hook );
	$base          = 'torobpay_slider_placements[' . $index . ']';
	$enabled       = isset( $placement['enabled'] ) ? $placement['enabled'] : 'no';
	$priority      = isset( $placement['priority'] ) ? (int) $placement['priority'] : 10;
	$show_desktop  = isset( $placement['show_desktop'] ) ? $placement['show_desktop'] : 'yes';
	$show_tablet   = isset( $placement['show_tablet'] ) ? $placement['show_tablet'] : 'yes';
	$show_mobile   = isset( $placement['show_mobile'] ) ? $placement['show_mobile'] : 'yes';
	$autoplay      = isset( $placement['autoplay'] ) ? $placement['autoplay'] : 'yes';
	$arrows        = isset( $placement['arrows'] ) ? $placement['arrows'] : 'yes';
	$dots          = isset( $placement['dots'] ) ? $placement['dots'] : 'yes';
	$pause_hover   = isset( $placement['pause_hover'] ) ? $placement['pause_hover'] : 'yes';
	$radius        = isset( $placement['radius'] ) ? (int) $placement['radius'] : 14;
	$panel_class = 'torobpay-slider-placement-item';
	if ( $active ) {
		$panel_class .= ' is-active';
	}
	?>
	<div class="<?php echo esc_attr( $panel_class ); ?>" data-index="<?php echo esc_attr( $index ); ?>" role="tabpanel"<?php if ( ! $active ) { echo ' hidden'; } ?>>
		<div class="torobpay-slider-placement-toolbar">
			<h3 class="torobpay-slider-repeater-title torobpay-slider-placement-panel-title"><?php echo esc_html( $title ); ?></h3>
			<label class="torobpay-slider-placement-enabled">
				<input type="hidden" name="<?php echo esc_attr( $base ); ?>[enabled]" value="no">
				<input type="checkbox" name="<?php echo esc_attr( $base ); ?>[enabled]" value="yes" <?php checked( $enabled, 'yes' ); ?>><?php esc_html_e( 'Active', 'torobpay-for-woocommerce' ); ?></label>
			<button type="button" class="button-link torobpay-slider-remove torobpay-slider-placement-remove" title="<?php echo esc_attr__( 'Remove display location', 'torobpay-for-woocommerce' ); ?>"><span class="dashicons dashicons-trash"></span></button>
		</div>
		<div class="torobpay-slider-placement-grid">
				<p>
					<label><?php esc_html_e( 'Display location', 'torobpay-for-woocommerce' ); ?><span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Changing the display location applies suggested defaults automatically.', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Display location', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
					</label>
					<select class="torobpay-slider-placement-hook-select" name="<?php echo esc_attr( $base ); ?>[hook_select]">
						<?php foreach ( $hook_choices as $hook_name => $hook_label ) : ?>
							<option value="<?php echo esc_attr( $hook_name ); ?>" <?php selected( $hook_select, $hook_name ); ?>><?php echo esc_html( $hook_label ); ?></option>
						<?php endforeach; ?>
						<option value="custom" <?php selected( $hook_select, 'custom' ); ?>><?php esc_html_e( 'Custom…', 'torobpay-for-woocommerce' ); ?></option>
					</select>
				</p>
				<p class="torobpay-slider-placement-custom-hook" style="<?php echo esc_attr( $is_custom ? '' : 'display:none;' ); ?>">
					<label><?php esc_html_e( 'Custom hook', 'torobpay-for-woocommerce' ); ?><span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'For theme hooks, set dimensions appropriate to the placement.', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Custom hook', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
					</label>
					<input type="text"
						class="regular-text torobpay-slider-placement-hook-custom"
						name="<?php echo esc_attr( $base ); ?>[hook_custom]"
						value="<?php echo $is_custom ? esc_attr( $hook ) : ''; ?>"
						placeholder="<?php echo esc_attr__( 'Example: storefront_before_content', 'torobpay-for-woocommerce' ); ?>">
				</p>
				<p>
					<label><?php esc_html_e( 'Display priority', 'torobpay-for-woocommerce' ); ?><span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'WordPress hook priority (lower number runs earlier). If multiple plugins or elements share a hook, this number controls display order. Default: 10', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Display priority', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
					</label>
					<input type="number" name="<?php echo esc_attr( $base ); ?>[priority]" value="<?php echo esc_attr( $priority ); ?>" min="1" max="200" step="1" class="small-text">
				</p>
				<p>
					<label><?php esc_html_e( 'Show by device', 'torobpay-for-woocommerce' ); ?></label><br>
					<input type="hidden" name="<?php echo esc_attr( $base ); ?>[show_desktop]" value="no">
					<label class="torobpay-display-setting-label"><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[show_desktop]" value="yes" <?php checked( $show_desktop, 'yes' ); ?>> <span class="dashicons dashicons-desktop"></span><?php esc_html_e( 'Desktop', 'torobpay-for-woocommerce' ); ?></label><br>
					<input type="hidden" name="<?php echo esc_attr( $base ); ?>[show_tablet]" value="no">
					<label class="torobpay-display-setting-label"><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[show_tablet]" value="yes" <?php checked( $show_tablet, 'yes' ); ?>> <span class="dashicons dashicons-tablet"></span><?php esc_html_e( 'Tablet', 'torobpay-for-woocommerce' ); ?></label><br>
					<input type="hidden" name="<?php echo esc_attr( $base ); ?>[show_mobile]" value="no">
					<label class="torobpay-display-setting-label"><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[show_mobile]" value="yes" <?php checked( $show_mobile, 'yes' ); ?>> <span class="dashicons dashicons-smartphone"></span><?php esc_html_e( 'Mobile', 'torobpay-for-woocommerce' ); ?></label>
				</p>
				<p>
					<label><?php esc_html_e( 'Behavior', 'torobpay-for-woocommerce' ); ?></label><br>
					<input type="hidden" name="<?php echo esc_attr( $base ); ?>[autoplay]" value="no">
					<label class="torobpay-display-setting-label"><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[autoplay]" value="yes" <?php checked( $autoplay, 'yes' ); ?>><?php esc_html_e( 'Autoplay', 'torobpay-for-woocommerce' ); ?></label><br>
					<input type="hidden" name="<?php echo esc_attr( $base ); ?>[arrows]" value="no">
					<label class="torobpay-display-setting-label"><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[arrows]" value="yes" <?php checked( $arrows, 'yes' ); ?>><?php esc_html_e( 'Arrows', 'torobpay-for-woocommerce' ); ?></label><br>
					<input type="hidden" name="<?php echo esc_attr( $base ); ?>[dots]" value="no">
					<label class="torobpay-display-setting-label"><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[dots]" value="yes" <?php checked( $dots, 'yes' ); ?>><?php esc_html_e( 'Navigation dots', 'torobpay-for-woocommerce' ); ?></label><br>
					<input type="hidden" name="<?php echo esc_attr( $base ); ?>[pause_hover]" value="no">
					<label class="torobpay-display-setting-label"><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[pause_hover]" value="yes" <?php checked( $pause_hover, 'yes' ); ?>><?php esc_html_e( 'Pause on hover', 'torobpay-for-woocommerce' ); ?></label>
				</p>
				<?php
				torobpay_slider_render_placement_responsive_field(
					$base,
					'height',
					$placement,
					array(
						'label'   => __( 'Height', 'torobpay-for-woocommerce' ),
						'tip'     => __( 'Slider height — px, percent of parent (%), or viewport height (vh)', 'torobpay-for-woocommerce' ),
						'default' => 420,
					)
				);
				torobpay_slider_render_placement_responsive_field(
					$base,
					'max_width',
					$placement,
					array(
						'label'   => __( 'Max width', 'torobpay-for-woocommerce' ),
						'tip'     => __( 'Maximum slider width — px or percent of parent (%)', 'torobpay-for-woocommerce' ),
						'default' => 1200,
					)
				);
				?>
				<p>
					<label><?php esc_html_e( 'Corner border radius', 'torobpay-for-woocommerce' ); ?></label>
					<input type="number" name="<?php echo esc_attr( $base ); ?>[radius]" value="<?php echo esc_attr( $radius ); ?>" min="0" max="60" step="1" class="small-text torobpay-slider-placement-radius">
					<span class="torobpay-rf-unit"><?php esc_html_e( 'px', 'torobpay-for-woocommerce' ); ?></span>
				</p>
			</div>
	</div>
	<?php
}

require_once __DIR__ . '/slider-slides-page.php';

add_action( 'admin_enqueue_scripts', 'torobpay_slider_enqueue_admin_assets' );
/**
 * Enqueue the slider admin repeater script + the WP media library.
 *
 * @param string $hook Current admin page hook (unused).
 */
function torobpay_slider_enqueue_admin_assets( $hook ) {
	$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( 'torobpay-widget' !== $current_page ) {
		return;
	}

	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';

	wp_enqueue_media();
	torobpay_enqueue_admin_toast_assets();

	// Front-end slider assets, reused for the admin live preview.
	wp_enqueue_style( 'dashicons' );
	wp_enqueue_style(
		'torobpay-slider',
		plugins_url( 'assets/css/slider.css', TOROBPAY_PLUGIN_FILE ),
		array(),
		$version
	);
	wp_enqueue_script(
		'torobpay-slider',
		plugins_url( 'assets/js/torobpay-slider.js', TOROBPAY_PLUGIN_FILE ),
		array(),
		$version,
		true
	);
	if ( function_exists( 'torobpay_enqueue_topbar_bg_script' ) ) {
		torobpay_enqueue_topbar_bg_script();
	}

	wp_enqueue_script(
		'torobpay-slider-admin',
		plugins_url( 'assets/js/torobpay-slider-admin.js', TOROBPAY_PLUGIN_FILE ),
		array( 'jquery', 'jquery-ui-sortable', 'torobpay-widget-settings', 'torobpay-admin-toast', 'torobpay-slider', 'wp-i18n' ),
		$version,
		true
	);
	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'torobpay-slider-admin', 'torobpay-for-woocommerce', dirname( TOROBPAY_PLUGIN_FILE ) . '/languages' );
	}
	wp_localize_script(
		'torobpay-slider-admin',
		'torobpaySliderAdmin',
		array(
			'mediaTitle'          => __( 'Select slide image', 'torobpay-for-woocommerce' ),
			'mediaButton'       => __( 'Use this image', 'torobpay-for-woocommerce' ),
			'confirmDel'          => __( 'Delete this slide?', 'torobpay-for-woocommerce' ),
			'confirmDelPlacement' => __( 'Delete this display location?', 'torobpay-for-woocommerce' ),
			'duplicateHook'       => __( 'This display location (hook) is already selected. Please choose another.', 'torobpay-for-woocommerce' ),
			'noUnusedHook'        => __( 'All default locations are in use. To add a new one, choose Custom.', 'torobpay-for-woocommerce' ),
			'newSlide'            => __( 'New slide', 'torobpay-for-woocommerce' ),
			'newPlacement'        => __( 'New display location', 'torobpay-for-woocommerce' ),
			'copiedShortcode'     => __( 'Shortcode copied', 'torobpay-for-woocommerce' ),
			'placementPresets'    => torobpay_slider_get_placement_presets(),
			'defaultPlacement'    => torobpay_slider_default_placement(),
			'hookChoices'         => torobpay_slider_get_hook_choices(),
			'placementDimensionBounds' => array(
				'height'    => array(
					'px' => torobpay_slider_placement_dimension_bounds( 'height', 'px' ),
					'%'  => torobpay_slider_placement_dimension_bounds( 'height', '%' ),
					'vh' => torobpay_slider_placement_dimension_bounds( 'height', 'vh' ),
				),
				'max_width' => array(
					'px' => torobpay_slider_placement_dimension_bounds( 'max_width', 'px' ),
					'%'  => torobpay_slider_placement_dimension_bounds( 'max_width', '%' ),
				),
			),
			'shopNameHidden'      => torobpay_slider_shop_name_hidden_value(),
			'preview'             => array(
				'ajaxurl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'torobpay_slider_preview' ),
				'action'      => 'torobpay_slider_preview',
				'emptyText'   => __( 'No slide is selected for this display location.', 'torobpay-for-woocommerce' ),
				'breakpoints' => torobpay_get_breakpoints(),
			),
		)
	);
}

add_action( 'init', 'torobpay_slider_register_placements', 20 );
add_shortcode( 'torobpay_slider', 'torobpay_slider_shortcode' );
add_shortcode( 'torobpay_slide', 'torobpay_slider_slide_shortcode' );

/**
 * Register all enabled slider placements on their respective hooks.
 */
function torobpay_slider_register_placements() {
	$placements = torobpay_slider_get_placements();

	foreach ( $placements as $index => $placement ) {
		if ( 'yes' !== $placement['enabled'] ) {
			continue;
		}

		$hook = sanitize_text_field( (string) $placement['hook'] );
		if ( '' === $hook ) {
			continue;
		}

		$priority = (int) $placement['priority'];
		add_action(
			$hook,
			static function () use ( $placement, $index ) {
				torobpay_slider_render_placement( $placement, $index );
			},
			$priority
		);
	}
}

/**
 * Hook callback: echo one placement instance.
 *
 * @param array<string,mixed> $placement Sanitized placement settings.
 * @param int                 $index     Placement index.
 */
function torobpay_slider_render_placement( $placement, $index ) {
	static $rendered = array();

	$key = $index . ':' . $placement['hook'];
	if ( isset( $rendered[ $key ] ) ) {
		return;
	}

	if ( ! torobpay_has_slider() ) {
		return;
	}

	if ( 'no' === $placement['show_desktop'] && 'no' === $placement['show_tablet'] && 'no' === $placement['show_mobile'] ) {
		return;
	}

	$rendered[ $key ] = true;
	echo wp_kses( torobpay_slider_get_html( $placement ), torobpay_kses_allowed_html() );
}

/**
 * Shortcode: [torobpay_slider]
 *
 * Usable even when automatic display is disabled, as long as TorobPay is
 * configured and authenticated (mirrors the [torobpay_widget] behaviour).
 *
 * @return string
 */
function torobpay_slider_shortcode() {
	if ( ! function_exists( 'torobpay_is_configured' ) || ! torobpay_is_configured() ) {
		return '';
	}
	if ( function_exists( 'torobpay_has_cached_auth_state' ) && ! torobpay_has_cached_auth_state() ) {
		return '';
	}
	if ( function_exists( 'torobpay_should_hide_for_wc_coming_soon' ) && torobpay_should_hide_for_wc_coming_soon() ) {
		return '';
	}

	return wp_kses( torobpay_slider_get_html(), torobpay_kses_allowed_html() );
}

/**
 * Shortcode: [torobpay_slide id="…" index="…" height="…" class="…" radius="…"]
 *
 * Renders a single slide for embedding in third-party sliders. Usable even
 * when automatic display is disabled, as long as TorobPay is configured.
 *
 * @param array<string,string>|string $atts Shortcode attributes.
 * @return string
 */
function torobpay_slider_slide_shortcode( $atts ) {
	if ( ! function_exists( 'torobpay_is_configured' ) || ! torobpay_is_configured() ) {
		return '';
	}
	if ( function_exists( 'torobpay_has_cached_auth_state' ) && ! torobpay_has_cached_auth_state() ) {
		return '';
	}
	if ( function_exists( 'torobpay_should_hide_for_wc_coming_soon' ) && torobpay_should_hide_for_wc_coming_soon() ) {
		return '';
	}

	$atts = shortcode_atts(
		array(
			'id'     => '',
			'height' => '',
			'class'  => '',
			'radius' => '',
		),
		$atts,
		'torobpay_slide'
	);

	$id = trim( (string) $atts['id'] );

	$resolved = torobpay_slider_resolve_slide( $id );
	if ( null === $resolved ) {
		return '';
	}

	return wp_kses( torobpay_slider_get_embed_html( $resolved['slide'], $resolved['index'], $atts ), torobpay_kses_allowed_html() );
}

/**
 * Build the full slider markup (and enqueue its assets).
 *
 * @param array<string,mixed>|null $placement Optional placement-specific settings.
 * @param array<string,mixed>      $args      Optional overrides:
 *                                             - slides: pre-built slide list (skips the get_option() read; used by the admin preview).
 *                                             - slider_id: fixed element id instead of the auto-incrementing instance counter.
 *                                             - preview_device: 'desktop'|'tablet'|'mobile' to render flat (non-media-query) per-device CSS
 *                                               and filter slides by that device's visibility instead of "hidden on every device".
 * @return string
 */
function torobpay_slider_get_html( $placement = null, $args = array() ) {
	$args = array_merge(
		array(
			'slides'         => null,
			'slider_id'      => null,
			'preview_device' => null,
		),
		is_array( $args ) ? $args : array()
	);

	$preview_device = in_array( $args['preview_device'], array( 'desktop', 'tablet', 'mobile' ), true ) ? $args['preview_device'] : null;

	if ( is_array( $args['slides'] ) ) {
		$slides = $args['slides'];
	} else {
		$placement_hook = is_array( $placement ) && ! empty( $placement['hook'] ) ? (string) $placement['hook'] : null;
		$slides         = torobpay_slider_get_slides_for_context( $placement_hook );
	}

	// Drop slides invisible in this context: hidden on every device normally,
	// or specifically hidden on the previewed device tier when previewing one.
	$slides = array_values(
		array_filter(
			$slides,
			static function ( $slide ) use ( $preview_device ) {
				$d = isset( $slide['show_desktop'] ) ? $slide['show_desktop'] : 'yes';
				$t = isset( $slide['show_tablet'] ) ? $slide['show_tablet'] : 'yes';
				$m = isset( $slide['show_mobile'] ) ? $slide['show_mobile'] : 'yes';
				if ( null !== $preview_device ) {
					if ( 'mobile' === $preview_device ) {
						return 'yes' === $m;
					}
					if ( 'tablet' === $preview_device ) {
						return 'yes' === $t;
					}
					return 'yes' === $d;
				}
				return 'yes' === $d || 'yes' === $t || 'yes' === $m;
			}
		)
	);

	if ( empty( $slides ) ) {
		return '';
	}

	$autoplay    = 'yes' === torobpay_slider_get_context_setting( $placement, 'autoplay' );
	$arrows      = 'yes' === torobpay_slider_get_context_setting( $placement, 'arrows' );
	$dots        = 'yes' === torobpay_slider_get_context_setting( $placement, 'dots' );
	$pause_hover = 'yes' === torobpay_slider_get_context_setting( $placement, 'pause_hover' );

	torobpay_slider_enqueue_assets();

	// Enqueue the shared canvas background engine if any slide uses one.
	$needs_canvas = false;
	foreach ( $slides as $slide ) {
		$anim = isset( $slide['bg_animation'] ) ? $slide['bg_animation'] : 'none';
		if ( in_array( $anim, array( 'perlin', 'rain', 'float' ), true ) ) {
			$needs_canvas = true;
			break;
		}
	}
	if ( $needs_canvas && function_exists( 'torobpay_enqueue_topbar_bg_script' ) ) {
		torobpay_enqueue_topbar_bg_script();
	}

	if ( $args['slider_id'] ) {
		$slider_id = sanitize_html_class( (string) $args['slider_id'] );
		if ( '' === $slider_id ) {
			$slider_id = 'torobpay-slider-preview';
		}
	} else {
		// Per-slider unique id so multiple instances (hook + shortcode) coexist.
		static $instance = 0;
		++$instance;
		$slider_id = 'torobpay-slider-' . $instance;
	}

	$slides_html = '';
	foreach ( array_values( $slides ) as $index => $slide ) {
		$slides_html .= torobpay_slider_render_slide( $slide, $index, $slider_id );
	}

	$dots_html = '';
	if ( $dots && count( $slides ) > 1 ) {
		$buttons = '';
		foreach ( array_values( $slides ) as $index => $slide ) {
			$buttons .= sprintf(
				'<button type="button" class="torobpay-slider-dot%s" data-slide-to="%d" aria-label="%s"></button>',
				0 === $index ? ' is-active' : '',
				(int) $index,
				esc_attr(
					sprintf(
						/* translators: %d: 1-based slide number */
						__( 'Slide %d', 'torobpay-for-woocommerce' ),
						(int) $index + 1
					)
				)
			);
		}
		$dots_html = '<div class="torobpay-slider-dots">' . $buttons . '</div>';
	}

	$arrows_html = '';
	if ( $arrows && count( $slides ) > 1 ) {
		$arrows_html =
			'<button type="button" class="torobpay-slider-arrow torobpay-slider-prev" aria-label="'
			. esc_attr__( 'Previous slide', 'torobpay-for-woocommerce' )
			. '"><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></button>'
			. '<button type="button" class="torobpay-slider-arrow torobpay-slider-next" aria-label="'
			. esc_attr__( 'Next slide', 'torobpay-for-woocommerce' )
			. '"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></button>';
	}

	$classes = 'torobpay-slider';
	if ( $arrows && count( $slides ) > 1 ) {
		// Extra horizontal padding so content never sits under the arrows.
		$classes .= ' torobpay-slider--has-arrows';
	}
	$classes = esc_attr( $classes );

	$inline_css = torobpay_slider_build_inline_css( $slider_id, $slides, $placement, $preview_device );

	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';
	$style_handle = $slider_id . '-css';
	wp_register_style( $style_handle, false, array( 'torobpay-slider' ), $version );
	wp_enqueue_style( $style_handle );
	wp_add_inline_style( $style_handle, wp_strip_all_tags( $inline_css ) );

	ob_start();
	wp_print_styles( $style_handle );
	$html = ob_get_clean();

	$html .= sprintf(
		'<div id="%s" class="%s" data-autoplay="%s" data-pause-hover="%s"%s>',
		esc_attr( $slider_id ),
		$classes,
		$autoplay ? '1' : '0',
		$pause_hover ? '1' : '0',
		'torobpay-slider-preview' === $slider_id ? ' data-initial-paused="1"' : ''
	);
	$html .= '<div class="torobpay-slider-viewport">';
	$html .= '<div class="torobpay-slider-track">' . $slides_html . '</div>';
	$html .= '</div>';
	$html .= $arrows_html;
	$html .= $dots_html;
	$html .= '</div>';

	return $html;
}

/**
 * Build standalone markup for one embedded slide (no carousel).
 *
 * @param array<string,mixed>        $slide Slide data.
 * @param int                        $index Zero-based index in the full slide list.
 * @param array<string,string>       $atts  Shortcode attributes.
 * @return string
 */
function torobpay_slider_get_embed_html( $slide, $index, $atts ) {
	$d = isset( $slide['show_desktop'] ) ? $slide['show_desktop'] : 'yes';
	$t = isset( $slide['show_tablet'] ) ? $slide['show_tablet'] : 'yes';
	$m = isset( $slide['show_mobile'] ) ? $slide['show_mobile'] : 'yes';
	if ( 'yes' !== $d && 'yes' !== $t && 'yes' !== $m ) {
		return '';
	}

	torobpay_slider_enqueue_embed_assets();

	$bg_animation = isset( $slide['bg_animation'] ) ? (string) $slide['bg_animation'] : 'none';
	if ( in_array( $bg_animation, array( 'perlin', 'rain', 'float' ), true ) && function_exists( 'torobpay_enqueue_topbar_bg_script' ) ) {
		torobpay_enqueue_topbar_bg_script();
	}

	static $instance = 0;
	++$instance;
	$embed_id = 'torobpay-slide-embed-' . $instance;

	$slide_html = torobpay_slider_render_slide( $slide, 0, $embed_id, true );
	$inline_css = torobpay_slider_build_embed_inline_css( $embed_id, $slide, $atts );

	$classes = 'torobpay-slide-embed';
	if ( ! empty( $atts['class'] ) ) {
		$extra = preg_split( '/\s+/', trim( (string) $atts['class'] ) );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $cls ) {
				$cls = sanitize_html_class( (string) $cls );
				if ( '' !== $cls ) {
					$classes .= ' ' . $cls;
				}
			}
		}
	}

	$version      = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';
	$style_handle = $embed_id . '-css';
	wp_register_style( $style_handle, false, array( 'torobpay-slider' ), $version );
	wp_enqueue_style( $style_handle );
	wp_add_inline_style( $style_handle, wp_strip_all_tags( $inline_css ) );

	ob_start();
	wp_print_styles( $style_handle );
	$html = ob_get_clean();

	$html .= sprintf(
		'<div id="%s" class="%s">',
		esc_attr( $embed_id ),
		esc_attr( $classes )
	);
	$html .= '<div class="torobpay-slide-embed-viewport">' . $slide_html . '</div>';
	$html .= '</div>';

	return $html;
}

/**
 * Parse the embed shortcode height attribute.
 *
 * @param string $raw Raw height value.
 * @return string Safe CSS length.
 */
function torobpay_slider_parse_embed_height( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		// Standalone shortcodes need a concrete height; 100% collapses when the
		// parent has no explicit height. Match the main slider CSS fallback.
		return '220px';
	}
	if ( is_numeric( $raw ) ) {
		return (string) max( 80, min( 800, (int) $raw ) ) . 'px';
	}
	if ( preg_match( '/^\d+(\.\d+)?(px|%|em|rem|vh)$/', $raw ) ) {
		return $raw;
	}

	return '220px';
}

/**
 * Build per-embed inline CSS (dimensions, radius, per-slide overrides).
 *
 * @param string               $embed_id Embed wrapper element id.
 * @param array<string,mixed>  $slide    Slide data.
 * @param array<string,string> $atts     Shortcode attributes.
 * @return string
 */
function torobpay_slider_build_embed_inline_css( $embed_id, $slide, $atts ) {
	$selector = '#' . $embed_id;
	$height   = torobpay_slider_parse_embed_height( isset( $atts['height'] ) ? (string) $atts['height'] : '' );
	$radius   = isset( $atts['radius'] ) && '' !== trim( (string) $atts['radius'] )
		? max( 0, min( 60, absint( $atts['radius'] ) ) )
		: 14;

	$css  = $selector . '{--torobpay-slider-h:' . $height . ';--torobpay-slider-radius:' . $radius . 'px;}';
	$css .= torobpay_slider_build_slide_size_css( $selector, array( $slide ) );

	$sid = $selector . '-s0';
	$bp  = torobpay_get_breakpoints();
	$sd  = isset( $slide['show_desktop'] ) ? $slide['show_desktop'] : 'yes';
	$st  = isset( $slide['show_tablet'] ) ? $slide['show_tablet'] : 'yes';
	$sm  = isset( $slide['show_mobile'] ) ? $slide['show_mobile'] : 'yes';

	if ( 'yes' !== $sd ) {
		$css .= '@media (min-width:' . ( (int) $bp['tablet'] + 1 ) . 'px){' . $sid . '{display:none !important;}}';
	}
	if ( 'yes' !== $st ) {
		$css .= '@media (max-width:' . (int) $bp['tablet'] . 'px) and (min-width:' . ( (int) $bp['mobile'] + 1 ) . 'px){' . $sid . '{display:none !important;}}';
	}
	if ( 'yes' !== $sm ) {
		$css .= '@media (max-width:' . (int) $bp['mobile'] . 'px){' . $sid . '{display:none !important;}}';
	}

	return $css;
}

/**
 * Build a single slide's HTML.
 *
 * Each slide owns its own transition, background animation and duration.
 *
 * @param array  $slide        Slide data (settings + content).
 * @param int    $index        Zero-based slide index.
 * @param string $slider_id    The parent slider/embed element id.
 * @param bool   $force_active Force the slide visible (embed mode).
 * @return string
 */
function torobpay_slider_render_slide( $slide, $index, $slider_id, $force_active = false ) {
	$presets     = torobpay_slider_bg_presets();
	$positions   = array_keys( torobpay_slider_text_positions() );
	$transitions = array( 'slide', 'fade', 'zoom', 'flip' );
	$bg_anims    = array( 'none', 'aurora', 'clover', 'perlin', 'rain', 'float' );

	$heading   = isset( $slide['heading'] ) ? (string) $slide['heading'] : '';
	$subtext   = isset( $slide['subtext'] ) ? (string) $slide['subtext'] : '';
	$cta_label = isset( $slide['cta_label'] ) ? (string) $slide['cta_label'] : '';
	$cta_url   = isset( $slide['cta_url'] ) ? (string) $slide['cta_url'] : '';
	$image     = isset( $slide['image'] ) ? (string) $slide['image'] : '';
	$position  = isset( $slide['text_position'] ) ? (string) $slide['text_position'] : 'middle-right';
	$preset    = isset( $slide['bg_preset'] ) ? (string) $slide['bg_preset'] : 'mint';

	$transition   = isset( $slide['transition'] ) ? (string) $slide['transition'] : 'slide';
	$bg_animation = isset( $slide['bg_animation'] ) ? (string) $slide['bg_animation'] : 'none';
	$bg_density   = isset( $slide['bg_density'] ) ? (string) $slide['bg_density'] : 'medium';
	$bg_speed     = isset( $slide['bg_speed'] ) ? max( 1, min( 10, (int) $slide['bg_speed'] ) ) : 5;
	$duration     = isset( $slide['duration'] ) ? max( 2, min( 30, (int) $slide['duration'] ) ) : 5;

	if ( ! isset( $presets[ $preset ] ) ) {
		$preset = 'mint';
	}
	if ( ! in_array( $position, $positions, true ) ) {
		$position = 'middle-right';
	}
	if ( ! in_array( $transition, $transitions, true ) ) {
		$transition = 'slide';
	}
	if ( ! in_array( $bg_animation, $bg_anims, true ) ) {
		$bg_animation = 'none';
	}

	$logo_url = esc_url( plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) );

	// The first brand-locked slide uses a bespoke "hero" layout (large logo +
	// orbiting badges on one side, branded title stack on the other) instead of
	// the generic heading/subtext content.
	$is_hero = isset( $slide['type'] ) && 'mandatory' === $slide['type'];

	$slide_id = $slider_id . '-s' . (int) $index;

	$slide_classes  = 'torobpay-slider-slide';
	$slide_classes .= ' torobpay-slider-slide--anim-' . sanitize_html_class( $transition );
	$slide_classes .= ' torobpay-slider-slide--' . sanitize_html_class( $preset );
	$slide_classes .= ' torobpay-slider-pos-' . sanitize_html_class( $position );
	if ( $is_hero ) {
		$slide_classes .= ' torobpay-slider-slide--hero';
	}
	if ( '' !== $image ) {
		$slide_classes .= ' torobpay-slider-slide--has-image';
	}
	if ( ! empty( $presets[ $preset ]['dark'] ) ) {
		$slide_classes .= ' torobpay-slider-slide--dark';
	}
	if ( 0 === (int) $index || $force_active ) {
		$slide_classes .= ' is-active';
	}

	$bg_attr = '';
	if ( 'none' !== $bg_animation ) {
		$bg_attr = sprintf(
			' data-bg-anim="%s" data-bg-density="%s" data-bg-speed="%s"',
			esc_attr( $bg_animation ),
			esc_attr( $bg_density ),
			esc_attr( (string) $bg_speed )
		);

		// Canvas backgrounds (perlin/rain/float) scale their icons in JS; pass the
		// per-device icon-size multiplier + breakpoints so the engine can pick the
		// right scale per viewport. Empty values use the slider icon default (200%).
		if ( in_array( $bg_animation, array( 'perlin', 'rain', 'float' ), true ) ) {
			$bp         = torobpay_get_breakpoints();
			$icon_scale = torobpay_slider_resolved_bg_scale_trio( $slide, 'bg_icon_size', torobpay_slider_bg_icon_scale_default( $bg_animation ) );
			$bg_attr   .= sprintf(
				' data-bg-icon-scale="%s" data-bg-icon-scale-tablet="%s" data-bg-icon-scale-mobile="%s" data-bp-tablet="%d" data-bp-mobile="%d"',
				esc_attr( torobpay_slider_bg_scale_unitless( $icon_scale['base'] ) ),
				esc_attr( torobpay_slider_bg_scale_unitless( $icon_scale['tablet'] ) ),
				esc_attr( torobpay_slider_bg_scale_unitless( $icon_scale['mobile'] ) ),
				(int) $bp['tablet'],
				(int) $bp['mobile']
			);

			$bg_custom_icon = isset( $slide['bg_custom_icon'] ) ? (string) $slide['bg_custom_icon'] : '';
			if ( '' !== $bg_custom_icon ) {
				$bg_attr .= ' data-bg-custom-icon="' . esc_attr( $bg_custom_icon ) . '"';
			}
		}
	}

	$style_attr   = '';
	$backdrop_html = '';
	if ( '' !== $image ) {
		$image_object_position = isset( $slide['image_object_position'] ) ? (string) $slide['image_object_position'] : 'center';
		$object_position_css   = torobpay_slider_image_object_position_css( $image_object_position );
		$photo_style           = ' style="object-position:' . esc_attr( $object_position_css ) . ';"';
		$backdrop_html         = '<div class="torobpay-slider-backdrop" aria-hidden="true">'
			. '<img class="torobpay-slider-photo" src="' . esc_url( $image ) . '" alt="" aria-hidden="true" decoding="async" loading="eager"' . $photo_style . ' />'
			. '</div>';
	}

	$dark_attr = ! empty( $presets[ $preset ]['dark'] ) ? ' data-bg-dark="1"' : '';

	$html  = '<div id="' . esc_attr( $slide_id ) . '" class="' . esc_attr( $slide_classes ) . '" data-duration="' . (int) ( $duration * 1000 ) . '"' . $bg_attr . $dark_attr . $style_attr . '>';
	$html .= $backdrop_html;

	// Decorative animated backdrop (behind the content). Aurora + clover are
	// CSS-only; canvas modes (perlin/rain/float) are populated by the JS engine.
	if ( 'none' !== $bg_animation ) {
		$html .= '<div class="torobpay-slider-bg" aria-hidden="true">';
		if ( 'aurora' === $bg_animation ) {
			$html .= '<span class="torobpay-slider-bg-blob"></span><span class="torobpay-slider-bg-blob"></span><span class="torobpay-slider-bg-blob"></span>';
		} elseif ( 'clover' === $bg_animation ) {
			$html .= torobpay_slider_clover_field_html( $slide_id );
		}
		$html .= '</div>';
	}

	// Foreground layer (content + overlay). Flip transitions target this wrapper
	// so decorative backgrounds stay flat during 3D rotation.
	$html .= '<div class="torobpay-slider-foreground">';

	// Image slides get a readability overlay.
	if ( '' !== $image ) {
		$html .= '<div class="torobpay-slider-overlay" aria-hidden="true"></div>';
	}

	if ( $is_hero ) {
		// Bespoke hero layout for the first brand-locked slide.
		$html .= torobpay_slider_render_hero( $logo_url, $slide );
	} else {
		$html .= '<div class="torobpay-slider-content">';

		// Brand mark — always present on every slide (branding is enforced).
		$html .= '<img src="' . $logo_url . '" alt="' . esc_attr__( 'TorobPay', 'torobpay-for-woocommerce' ) . '" class="torobpay-slider-brand" width="28" height="28" />';

		if ( '' !== $heading ) {
			$html .= '<div class="torobpay-slider-heading">' . esc_html( $heading ) . '</div>';
		}
		if ( '' !== $subtext ) {
			$html .= '<div class="torobpay-slider-subtext">' . esc_html( $subtext ) . '</div>';
		}
		if ( '' !== $cta_label && '' !== $cta_url ) {
			$html .= '<a class="torobpay-slider-cta" href="' . esc_url( $cta_url ) . '">' . esc_html( $cta_label ) . '</a>';
		}

		$html .= '</div>'; // .torobpay-slider-content
	}

	$html .= '</div>'; // .torobpay-slider-foreground

	$html .= '</div>'; // .torobpay-slider-slide

	return $html;
}

/**
 * Build the decorative "clover field" backdrop.
 *
 * Each instance uses the shared clover-shape.svg asset at a varied size and
 * opacity (see slider.css for placement). Opacity is applied on the wrapper.
 *
 * @param string $slide_id Unused; kept for backward compatibility.
 * @return string
 */
function torobpay_slider_clover_field_html( $slide_id = '' ) {
	$clover_url = esc_url( plugins_url( 'assets/images/clover-shape.svg', TOROBPAY_PLUGIN_FILE ) );
	$clovers    = array( 1, 2, 3, 4, 5 );

	$out = '';
	foreach ( $clovers as $n ) {
		$out .= '<span class="torobpay-slider-clover-wrap torobpay-slider-clover-wrap--' . (int) $n . '">';
		$out .= '<img class="torobpay-slider-clover" src="' . $clover_url . '" alt="" width="512" height="512" loading="lazy" decoding="async" aria-hidden="true" />';
		$out .= '</span>';
	}

	return $out;
}

/**
 * Build the bespoke "hero" content for the first brand-locked slide.
 *
 * Layout (RTL): the branded title stack ("خرید قسطی از {shop} با ترب‌پی" split
 * across two lines, beneath the 4-part progress mark) sits on the leading
 * (right) side, while a large TorobPay logo orbited by four capsule badges
 * (__( 'No fees', 'torobpay-for-woocommerce' ), __( '4 installments', 'torobpay-for-woocommerce' ), __( 'No checks', 'torobpay-for-woocommerce' ), __( 'No guarantor', 'torobpay-for-woocommerce' )) that it slightly overlaps —
 * each glowing in sequence — sits on the trailing (left) side.
 *
 * @param string               $logo_url Pre-escaped URL to the TorobPay logo SVG.
 * @param array<string,mixed>  $slide    Mandatory slide settings (optional shop_name override).
 * @return string
 */
function torobpay_slider_render_hero( $logo_url, $slide = array() ) {
	$parts_url = esc_url( plugins_url( 'assets/images/all-parts.svg', TOROBPAY_PLUGIN_FILE ) );
	$shop_name = torobpay_slider_resolve_shop_name( is_array( $slide ) ? $slide : array() );

	$badges = array(
		'top'    => __( 'No fees', 'torobpay-for-woocommerce' ),
		'right'  => __( '4 installments', 'torobpay-for-woocommerce' ),
		'left'   => __( 'No checks', 'torobpay-for-woocommerce' ),
		'bottom' => __( 'No guarantor', 'torobpay-for-woocommerce' ),
	);

	$html  = '<div class="torobpay-slider-content torobpay-slider-hero">';

	// Leading (right) column: 4-part progress mark stacked over the title.
	$html .= '<div class="torobpay-slider-hero-intro">';
	$html .= '<img src="' . $parts_url . '" alt="" class="torobpay-slider-hero-parts" aria-hidden="true" />';
	$html .= '<div class="torobpay-slider-hero-title">';
	if ( '' !== $shop_name ) {
		$html .= '<span class="torobpay-slider-hero-line">' . esc_html(
			sprintf(
				/* translators: %s: shop or store name */
				__( 'Buy in installments from %s', 'torobpay-for-woocommerce' ),
				$shop_name
			)
		) . '</span>';
	} else {
		$html .= '<span class="torobpay-slider-hero-line">' . esc_html__( 'Installment purchase', 'torobpay-for-woocommerce' ) . '</span>';
	}
	$html .= '<span class="torobpay-slider-hero-line">' . esc_html__( 'with TorobPay', 'torobpay-for-woocommerce' ) . '</span>';
	$html .= '</div>'; // .torobpay-slider-hero-title
	$html .= '</div>'; // .torobpay-slider-hero-intro

	// Trailing (left) column: badges orbiting the large logo.
	$html .= '<div class="torobpay-slider-hero-logo">';
	$i     = 0;
	foreach ( $badges as $pos => $label ) {
		$html .= sprintf(
			'<span class="torobpay-slider-hero-badge torobpay-slider-hero-badge--%s" style="--torobpay-badge-i:%d;">%s</span>',
			esc_attr( $pos ),
			(int) $i,
			esc_html( $label )
		);
		++$i;
	}
	$html .= '<img src="' . $logo_url . '" alt="' . esc_attr__( 'TorobPay', 'torobpay-for-woocommerce' ) . '" class="torobpay-slider-hero-mark" />';
	$html .= '</div>'; // .torobpay-slider-hero-logo

	$html .= '</div>'; // .torobpay-slider-content.torobpay-slider-hero

	return $html;
}

/**
 * Build the per-instance inline CSS (dimensions, radius, per-device visibility).
 *
 * @param string                   $slider_id      The slider element id.
 * @param array                    $slides         The rendered slides (for per-slide visibility CSS).
 * @param array<string,mixed>|null $placement      Optional placement-specific settings.
 * @param string|null              $preview_device Optional 'desktop'|'tablet'|'mobile': emit flat, single-device
 *                                                  rules (admin preview) instead of the real @media breakpoints.
 * @return string
 */
function torobpay_slider_build_inline_css( $slider_id, $slides = array(), $placement = null, $preview_device = null ) {
	$selector = '#' . $slider_id;

	$height_r       = torobpay_slider_get_responsive_from_context( $placement, 'height', 420 );
	$max_width_r    = torobpay_slider_get_responsive_from_context( $placement, 'max_width', 1200 );
	$height_unit    = is_array( $placement )
		? torobpay_slider_sanitize_placement_unit( $placement['height_unit'] ?? 'px', 'height' )
		: torobpay_slider_sanitize_placement_unit( get_option( 'torobpay_slider_height_unit', 'px' ), 'height' );
	$max_width_unit = is_array( $placement )
		? torobpay_slider_sanitize_placement_unit( $placement['max_width_unit'] ?? 'px', 'max_width' )
		: torobpay_slider_sanitize_placement_unit( get_option( 'torobpay_slider_max_width_unit', 'px' ), 'max_width' );
	$radius         = max( 0, min( 60, (int) torobpay_slider_get_context_setting( $placement, 'radius' ) ) );

	$device_int = static function ( $val ) {
		return ( '' === $val || null === $val ) ? '' : (int) $val;
	};

	$css  = torobpay_responsive_style(
		$selector,
		array(
			array(
				'prop'   => '--torobpay-slider-h',
				'base'   => (int) $height_r['base'],
				'tablet' => $device_int( $height_r['tablet'] ),
				'mobile' => $device_int( $height_r['mobile'] ),
				'unit'   => $height_unit,
			),
			array(
				'prop'   => '--torobpay-slider-maxw',
				'base'   => (int) $max_width_r['base'],
				'tablet' => $device_int( $max_width_r['tablet'] ),
				'mobile' => $device_int( $max_width_r['mobile'] ),
				'unit'   => $max_width_unit,
			),
		),
		$preview_device
	);
	$css .= $selector . '{--torobpay-slider-radius:' . $radius . 'px;}';

	// Per-device visibility (cannot be all-off; enforced on save). Skipped in
	// preview mode: the caller already filtered $slides (and the placement
	// itself) for the previewed device, so no extra hiding is needed here —
	// and it wouldn't apply anyway since preview device rules aren't tied to
	// the real @media breakpoints.
	if ( null === $preview_device ) {
		$bp           = torobpay_get_breakpoints();
		$show_desktop = 'yes' === torobpay_slider_get_context_setting( $placement, 'show_desktop' );
		$show_tablet  = 'yes' === torobpay_slider_get_context_setting( $placement, 'show_tablet' );
		$show_mobile  = 'yes' === torobpay_slider_get_context_setting( $placement, 'show_mobile' );

		if ( ! $show_desktop ) {
			$css .= '@media (min-width:' . ( (int) $bp['tablet'] + 1 ) . 'px){' . $selector . '{display:none !important;}}';
		}
		if ( ! $show_tablet ) {
			$css .= '@media (max-width:' . (int) $bp['tablet'] . 'px) and (min-width:' . ( (int) $bp['mobile'] + 1 ) . 'px){' . $selector . '{display:none !important;}}';
		}
		if ( ! $show_mobile ) {
			$css .= '@media (max-width:' . (int) $bp['mobile'] . 'px){' . $selector . '{display:none !important;}}';
		}

		// Per-slide visibility: hide individual slides on the devices they opt
		// out of. The carousel JS skips slides whose computed display is none.
		foreach ( array_values( $slides ) as $index => $slide ) {
			$sid = $selector . '-s' . (int) $index;
			$sd  = isset( $slide['show_desktop'] ) ? $slide['show_desktop'] : 'yes';
			$st  = isset( $slide['show_tablet'] ) ? $slide['show_tablet'] : 'yes';
			$sm  = isset( $slide['show_mobile'] ) ? $slide['show_mobile'] : 'yes';

			if ( 'yes' !== $sd ) {
				$css .= '@media (min-width:' . ( (int) $bp['tablet'] + 1 ) . 'px){' . $sid . '{display:none !important;}}';
			}
			if ( 'yes' !== $st ) {
				$css .= '@media (max-width:' . (int) $bp['tablet'] . 'px) and (min-width:' . ( (int) $bp['mobile'] + 1 ) . 'px){' . $sid . '{display:none !important;}}';
			}
			if ( 'yes' !== $sm ) {
				$css .= '@media (max-width:' . (int) $bp['mobile'] . 'px){' . $sid . '{display:none !important;}}';
			}
		}
	}

	// Per-slide, per-device typography / size overrides. Each value is empty by
	// default (inherit the stylesheet default). The hero (first brand-locked)
	// slide targets its bespoke logo + title elements instead of the generic
	// brand mark / heading / subtext.
	$css .= torobpay_slider_build_slide_size_css( $selector, $slides, $preview_device );

	return $css;
}

/**
 * Build the per-slide, per-device size/typography override CSS.
 *
 * @param string      $selector       The slider element selector (e.g. '#torobpay-slider-1').
 * @param array       $slides         The rendered slides (already filtered + ordered).
 * @param string|null $preview_device Optional 'desktop'|'tablet'|'mobile' for flat, single-device preview CSS.
 * @return string
 */
function torobpay_slider_build_slide_size_css( $selector, $slides, $preview_device = null ) {
	$css = '';

	foreach ( array_values( $slides ) as $index => $slide ) {
		$sid     = $selector . '-s' . (int) $index;
		$is_hero = isset( $slide['type'] ) && 'mandatory' === $slide['type'];

		// Read a per-device trio for one setting key off the slide.
		$rv = static function ( $key ) use ( $slide ) {
			return array(
				'base'   => isset( $slide[ $key ] ) ? $slide[ $key ] : '',
				'tablet' => isset( $slide[ $key . '_tablet' ] ) ? $slide[ $key . '_tablet' ] : '',
				'mobile' => isset( $slide[ $key . '_mobile' ] ) ? $slide[ $key . '_mobile' ] : '',
			);
		};

		// TorobPay brand mark size (hero: the large orbiting logo).
		$brand        = $rv( 'brand_size' );
		$brand_target = $is_hero ? ( $sid . ' .torobpay-slider-hero-mark' ) : ( $sid . ' .torobpay-slider-brand' );
		$css         .= torobpay_responsive_style(
			$brand_target,
			array(
				array( 'prop' => 'width', 'base' => $brand['base'], 'tablet' => $brand['tablet'], 'mobile' => $brand['mobile'], 'unit' => 'px' ),
				array( 'prop' => 'height', 'base' => $brand['base'], 'tablet' => $brand['tablet'], 'mobile' => $brand['mobile'], 'unit' => 'px' ),
			),
			$preview_device
		);

		// Heading typography (hero: the two-line title).
		$h_size         = $rv( 'heading_size' );
		$h_weight       = $rv( 'heading_weight' );
		$heading_target = $is_hero ? ( $sid . ' .torobpay-slider-hero-line' ) : ( $sid . ' .torobpay-slider-heading' );
		$css           .= torobpay_responsive_style(
			$heading_target,
			array(
				array( 'prop' => 'font-size', 'base' => $h_size['base'], 'tablet' => $h_size['tablet'], 'mobile' => $h_size['mobile'], 'unit' => 'px' ),
				array( 'prop' => 'font-weight', 'base' => $h_weight['base'], 'tablet' => $h_weight['tablet'], 'mobile' => $h_weight['mobile'], 'unit' => '' ),
			),
			$preview_device
		);

		// Subtext typography (not present on the hero slide).
		if ( ! $is_hero ) {
			$s_size   = $rv( 'subtext_size' );
			$s_weight = $rv( 'subtext_weight' );
			$css     .= torobpay_responsive_style(
				$sid . ' .torobpay-slider-subtext',
				array(
					array( 'prop' => 'font-size', 'base' => $s_size['base'], 'tablet' => $s_size['tablet'], 'mobile' => $s_size['mobile'], 'unit' => 'px' ),
					array( 'prop' => 'font-weight', 'base' => $s_weight['base'], 'tablet' => $s_weight['tablet'], 'mobile' => $s_weight['mobile'], 'unit' => '' ),
				),
				$preview_device
			);
		}

		$bg_animation = isset( $slide['bg_animation'] ) ? (string) $slide['bg_animation'] : 'none';

		// Icon background scale for clover (CSS) and canvas modes.
		if ( in_array( $bg_animation, torobpay_slider_icon_bg_animations(), true ) ) {
			$bg_icon = torobpay_slider_resolved_bg_scale_trio( $slide, 'bg_icon_size', torobpay_slider_bg_icon_scale_default( $bg_animation ) );
			$css    .= torobpay_responsive_style(
				$sid,
				array(
					array(
						'prop'   => '--torobpay-bg-icon-scale',
						'base'   => torobpay_slider_bg_scale_unitless( $bg_icon['base'] ),
						'tablet' => torobpay_slider_bg_scale_unitless( $bg_icon['tablet'] ),
						'mobile' => torobpay_slider_bg_scale_unitless( $bg_icon['mobile'] ),
						'unit'   => '',
					),
				),
				$preview_device
			);
		}

		// Aurora scale for CSS blob backgrounds.
		if ( 'aurora' === $bg_animation ) {
			$bg_aurora = torobpay_slider_resolved_bg_scale_trio( $slide, 'bg_aurora_scale', torobpay_slider_bg_aurora_scale_default() );
			$css      .= torobpay_responsive_style(
				$sid,
				array(
					array(
						'prop'   => '--torobpay-bg-aurora-scale',
						'base'   => torobpay_slider_bg_scale_unitless( $bg_aurora['base'] ),
						'tablet' => torobpay_slider_bg_scale_unitless( $bg_aurora['tablet'] ),
						'mobile' => torobpay_slider_bg_scale_unitless( $bg_aurora['mobile'] ),
						'unit'   => '',
					),
				),
				$preview_device
			);
		}
	}

	return $css;
}

/**
 * Enqueue the slider stylesheet + carousel script (once per page).
 */
function torobpay_slider_enqueue_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	torobpay_slider_enqueue_embed_assets();

	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';

	wp_enqueue_script(
		'torobpay-slider',
		plugins_url( 'assets/js/torobpay-slider.js', TOROBPAY_PLUGIN_FILE ),
		array(),
		$version,
		true
	);
}

/**
 * Enqueue slider styles without the carousel script (embed / per-slide mode).
 */
function torobpay_slider_enqueue_embed_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';

	wp_enqueue_style( 'dashicons' );
	wp_enqueue_style(
		'torobpay-slider',
		plugins_url( 'assets/css/slider.css', TOROBPAY_PLUGIN_FILE ),
		array(),
		$version
	);
}
