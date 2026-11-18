<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TorobPay Product Marquee Bar
 *
 * Renders a scrolling glassmorphism marquee bar on the bottom of product
 * thumbnails in product listing pages (PLP).
 *
 * Supports both hook-based and JS compatibility modes, mirroring the badge
 * rendering architecture:
 *  - Hook-based mode  : PHP outputs the HTML, a tiny JS repositioner moves it
 *                       inside the image container after DOM ready.
 *  - Compat mode      : badge-compatibility.js handles injection; this file
 *                       only appends marquee config to its localized script.
 */

// Auth (bearer token) is checked lazily inside torobpay_product_list_marquee()
// and torobpay_enqueue_marquee_compat_config().
$torobpay_marquee_compat_mode = function_exists( 'torobpay_marquee_compatibility_mode' )
	? torobpay_marquee_compatibility_mode()
	: 'no';

if ( $torobpay_marquee_compat_mode === 'yes' ) {
	// Compat mode: inject marquee config into the already-enqueued badge
	// compat script. Priority 11 runs after the script is enqueued (priority 10).
	add_action( 'wp_enqueue_scripts', 'torobpay_enqueue_marquee_compat_config', 11 );
} else {
	// Hook-based mode: PHP renders the HTML, JS repositions it.
	// Priority 11 runs after the badge renderer (priority 10).
	add_action( 'woocommerce_before_shop_loop_item', 'torobpay_product_list_marquee', 11 );
}

/**
 * Hook-based marquee renderer.
 */
function torobpay_product_list_marquee() {
	if ( ! function_exists( 'torobpay_has_marquee' ) || ! torobpay_has_marquee() ) {
		return;
	}

	global $product;

	if ( ! is_a( $product, 'WC_Product' ) ) {
		return;
	}

	$badge_data = torobpay_prepare_product_badge_data( $product );
	if ( ! $badge_data ) {
		return;
	}

	torobpay_enqueue_marquee_styles();
	torobpay_enqueue_marquee_repositioner();

	echo wp_kses( torobpay_get_product_marquee_html( $badge_data ), torobpay_kses_allowed_html() );
}

/**
 * Build the marquee bar HTML for a product.
 *
 * @param array $badge_data Same shape as returned by torobpay_prepare_product_badge_data().
 * @return string HTML
 */
function torobpay_get_product_marquee_html( $badge_data ) {
	$defaults = [
		'product_id'            => 0,
		'show_price'            => false,
		'formatted_installment' => '',
	];

	$args        = wp_parse_args( $badge_data, $defaults );
	$product_id  = (int) $args['product_id'];
	$show_price  = (bool) $args['show_price'];
	$installment = $args['formatted_installment'];
	$slogan      = esc_html( torobpay_get_marquee_setting( 'slogan' ) );
	$logo        = esc_url( plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) );
	$icon_size   = (int) torobpay_get_marquee_setting( 'icon_size' );

	if ( $show_price ) {
		/* translators: %s: formatted installment price HTML */
		$price_text = sprintf( __( 'Each installment %s', 'torobpay-for-woocommerce' ), wp_kses( $installment, torobpay_kses_allowed_html() ) );
	} else {
		$price_text = __( 'Installment payment', 'torobpay-for-woocommerce' );
	}

	$price_text = apply_filters( 'torobpay_marquee_price_text', $price_text, $args );

	// 4 copies + keyframe translateX(-25%) = always shifts by exactly 1 copy width,
	// guaranteeing a gapless loop regardless of container width.
	$single_item = sprintf(
		'<span class="torobpay-product-marquee-item">'
		. '<img src="%s" alt="" class="torobpay-product-marquee-logo" width="%d" height="%d" />'
		. '%s'
		. '<span class="torobpay-product-marquee-sep">•</span>'
		. '%s'
		. '&nbsp;&nbsp;&nbsp;'
		. '</span>',
		$logo,
		$icon_size,
		$icon_size,
		wp_kses( $price_text, torobpay_kses_allowed_html() ),
		$slogan
	);

	$track_items = $single_item;
	for ( $i = 1; $i < 4; $i++ ) {
		// Copies after the first are aria-hidden (screen-reader friendly)
		$track_items .= str_replace(
			'class="torobpay-product-marquee-item"',
			'class="torobpay-product-marquee-item" aria-hidden="true"',
			$single_item
		);
	}

	$track = '<div class="torobpay-product-marquee-track">' . $track_items . '</div>';

	return apply_filters(
		'torobpay_product_marquee_html',
		sprintf(
			'<div class="torobpay-product-marquee" data-product-id="%d">%s</div>',
			$product_id,
			$track
		),
		$args
	);
}

/**
 * Enqueue marquee CSS and inject dynamic inline styles.
 */
function torobpay_enqueue_marquee_styles() {
	static $done = false;
	if ( $done ) {
		return;
	}

	if ( function_exists( 'torobpay_inject_pdp_styles' ) ) {
		torobpay_inject_pdp_styles();
	}

	wp_enqueue_style(
		'torobpay-marquee-style',
		plugins_url( 'assets/css/marquee.css', TOROBPAY_PLUGIN_FILE ),
		[ 'torobpay-widget-style' ],
		TOROBPAY_VERSION
	);

	// Inject all dynamic marquee styles from independent settings.
	$bg_rgba       = torobpay_hex_to_rgba( torobpay_get_marquee_setting( 'bg_color' ), torobpay_get_marquee_setting( 'bg_opacity' ) );
	$text_color    = torobpay_get_marquee_setting( 'text_color' );
	$font_size_r   = torobpay_get_responsive_setting( 'torobpay_marquee_font_size', 11 );
	$font_weight   = torobpay_get_marquee_setting( 'font_weight' );
	$height_r      = torobpay_get_responsive_setting( 'torobpay_marquee_height', 26 );
	$height_unit   = torobpay_get_marquee_setting( 'height_unit' ) === '%' ? '%' : 'px';
	$speed         = (int) torobpay_get_marquee_setting( 'scroll_speed' );
	$position      = torobpay_get_marquee_setting( 'position' ) === 'top' ? 'top' : 'bottom';
	$gap_r         = torobpay_get_responsive_setting( 'torobpay_marquee_gap', 0 );
	$gap_unit      = torobpay_get_marquee_setting( 'gap_unit' ) === '%' ? '%' : 'px';
	$icon_size_r   = torobpay_get_responsive_setting( 'torobpay_marquee_icon_size', 14 );
	$z_index       = (int) torobpay_get_marquee_setting( 'z_index' );
	$v_align_raw   = torobpay_get_marquee_setting( 'vertical_align' );
	$v_padding     = (float) torobpay_get_marquee_setting( 'vertical_padding' );
	$v_pad_unit    = torobpay_get_marquee_setting( 'vertical_padding_unit' );
	$v_pad_unit    = in_array( $v_pad_unit, [ 'px', '%', 'rem' ], true ) ? $v_pad_unit : 'px';
	$r_tl_r        = torobpay_get_responsive_setting( 'torobpay_marquee_radius_tl', 0 );
	$r_tr_r        = torobpay_get_responsive_setting( 'torobpay_marquee_radius_tr', 0 );
	$r_br_r        = torobpay_get_responsive_setting( 'torobpay_marquee_radius_br', 0 );
	$r_bl_r        = torobpay_get_responsive_setting( 'torobpay_marquee_radius_bl', 0 );
	$align_map     = [
		'top'    => 'flex-start',
		'middle' => 'center',
		'bottom' => 'flex-end',
	];
	$align_value   = isset( $align_map[ $v_align_raw ] ) ? $align_map[ $v_align_raw ] : 'center';
	$v_pad_unit    = torobpay_sanitize_css_unit( $v_pad_unit, 'px' );
	if ( $v_align_raw === 'top' ) {
		$padding_rule = sprintf( 'padding-top: %s%s;', (float) $v_padding, $v_pad_unit );
	} elseif ( $v_align_raw === 'bottom' ) {
		$padding_rule = sprintf( 'padding-bottom: %s%s;', (float) $v_padding, $v_pad_unit );
	} else {
		$padding_rule = sprintf( 'padding: %s%s 0;', (float) $v_padding, $v_pad_unit );
	}
	$border_rule = ( $position === 'top' )
		? 'border-bottom: 1px solid rgba(255,255,255,0.35);'
		: 'border-top: 1px solid rgba(255,255,255,0.35);';
	$device_int = static function ( $v ) { return '' === $v ? '' : (int) $v; };
	$inline      = sprintf(
		'.torobpay-product-marquee { background: %1$s; z-index: %2$d; %3$s }
		.torobpay-product-marquee-track { animation-duration: %4$ds; align-items: %5$s; box-sizing: border-box; %6$s }
		.torobpay-product-marquee-item,
		.torobpay-product-marquee-item .amount,
		.torobpay-product-marquee-item .woocommerce-Price-amount { color: %7$s; font-weight: %8$s; }
		@keyframes torobpay-marquee-scroll {
			from { transform: translateX(-25%%); }
			to   { transform: translateX(0); }
		}',
		esc_attr( $bg_rgba ),
		$z_index,
		$border_rule,
		$speed,
		esc_attr( $align_value ),
		$padding_rule,
		torobpay_esc_css_color( $text_color, '#003D01' ),
		torobpay_esc_css_font_weight( $font_weight, '600' )
	);
	$inline .= torobpay_responsive_style(
		'.torobpay-product-marquee',
		array(
			array( 'prop' => 'height', 'base' => (int) $height_r['base'], 'tablet' => $device_int( $height_r['tablet'] ), 'mobile' => $device_int( $height_r['mobile'] ), 'unit' => $height_unit ),
			array( 'prop' => $position, 'base' => (int) $gap_r['base'], 'tablet' => $device_int( $gap_r['tablet'] ), 'mobile' => $device_int( $gap_r['mobile'] ), 'unit' => $gap_unit ),
		)
	);

	// Per-device border-radius shorthand (combines four independent corners).
	$radius_eff = static function ( $r, $device ) {
		return ( '' !== $r[ $device ] && null !== $r[ $device ] ) ? (int) $r[ $device ] : (int) $r['base'];
	};
	$radius_shorthand = static function ( $device ) use ( $radius_eff, $r_tl_r, $r_tr_r, $r_br_r, $r_bl_r ) {
		return sprintf(
			'%dpx %dpx %dpx %dpx',
			$radius_eff( $r_tl_r, $device ),
			$radius_eff( $r_tr_r, $device ),
			$radius_eff( $r_br_r, $device ),
			$radius_eff( $r_bl_r, $device )
		);
	};
	$bp        = torobpay_get_breakpoints();
	$inline   .= '.torobpay-product-marquee{border-radius:' . $radius_shorthand( 'base' ) . ';}';
	if ( '' !== $r_tl_r['tablet'] || '' !== $r_tr_r['tablet'] || '' !== $r_br_r['tablet'] || '' !== $r_bl_r['tablet'] ) {
		$inline .= '@media (max-width:' . (int) $bp['tablet'] . 'px){.torobpay-product-marquee{border-radius:' . $radius_shorthand( 'tablet' ) . ';}}';
	}
	if ( '' !== $r_tl_r['mobile'] || '' !== $r_tr_r['mobile'] || '' !== $r_br_r['mobile'] || '' !== $r_bl_r['mobile'] ) {
		$inline .= '@media (max-width:' . (int) $bp['mobile'] . 'px){.torobpay-product-marquee{border-radius:' . $radius_shorthand( 'mobile' ) . ';}}';
	}
	$inline .= torobpay_responsive_style(
		'.torobpay-product-marquee-item',
		array(
			array( 'prop' => 'font-size', 'base' => (int) $font_size_r['base'], 'tablet' => $device_int( $font_size_r['tablet'] ), 'mobile' => $device_int( $font_size_r['mobile'] ), 'unit' => 'px' ),
		)
	);
	$inline .= torobpay_responsive_style(
		'.torobpay-product-marquee-logo',
		array(
			array( 'prop' => 'height', 'base' => (int) $icon_size_r['base'], 'tablet' => $device_int( $icon_size_r['tablet'] ), 'mobile' => $device_int( $icon_size_r['mobile'] ), 'unit' => 'px !important' ),
		)
	);
	$inline .= '.torobpay-product-marquee-logo { width: auto !important; }';
	wp_add_inline_style( 'torobpay-marquee-style', wp_strip_all_tags( $inline ) );

	$done = true;
}

/**
 * Enqueue the JS repositioner that moves server-rendered marquee elements
 * inside their product image containers (hook-based mode only).
 */
function torobpay_enqueue_marquee_repositioner() {
	static $done = false;
	if ( $done ) {
		return;
	}

	wp_enqueue_script(
		'torobpay-marquee-repositioner',
		plugins_url( 'assets/js/marquee-repositioner.js', TOROBPAY_PLUGIN_FILE ),
		[ 'jquery' ],
		TOROBPAY_VERSION,
		true
	);

	$done = true;
}

/**
 * In JS compatibility mode: append marquee config to the already-localized
 * badge compat script by injecting an inline script before it.
 *
 * 'before' position ensures properties are set before badge-compatibility.js
 * captures the config object via `const config = torobpayBadgeCompat || {}`.
 *
 * Priority 11 fires after torobpay_enqueue_badge_compatibility_script (default priority 10).
 */
function torobpay_enqueue_marquee_compat_config() {
	if ( ! function_exists( 'torobpay_has_marquee' ) || ! torobpay_has_marquee() ) {
		return;
	}

	if ( ! wp_script_is( 'torobpay-badge-compatibility', 'enqueued' ) ) {
		return;
	}

	torobpay_enqueue_marquee_styles();

	$slogan    = esc_js( torobpay_get_marquee_setting( 'slogan' ) );
	$icon_size = (int) torobpay_get_marquee_setting( 'icon_size' );

	$inline = "
if ( typeof torobpayBadgeCompat !== 'undefined' ) {
	torobpayBadgeCompat.marqueeEnabled  = true;
	torobpayBadgeCompat.marqueeSlogan   = '{$slogan}';
	torobpayBadgeCompat.marqueeIconSize = {$icon_size};
}
";

	wp_add_inline_script( 'torobpay-badge-compatibility', $inline, 'before' );
}
