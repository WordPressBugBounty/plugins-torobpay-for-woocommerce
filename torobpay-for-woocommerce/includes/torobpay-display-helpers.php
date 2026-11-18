<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** TorobPay storefront display helpers. */

function torobpay_widget_layout_presets() {
	return array(
		'classic' => array(
			'label'       => __( 'Classic', 'torobpay-for-woocommerce' ),
			'description' => __( 'Standard card with logo beside text', 'torobpay-for-woocommerce' ),
			'swatch'      => array(
				'bg'     => '#ffffff',
				'border' => '#ebecf2',
				'text'   => '#1a1c23',
			),
			'defaults'    => array(
				'background_color'  => '#ffffff',
				'border_color'      => '#ebecf2',
				'text_color'        => '#1a1c23',
				'border_size'       => 1,
				'border_radius'     => 16,
				'title_font_size'   => 14,
				'title_font_weight' => '700',
				'desc_font_size'    => 12,
				'desc_font_weight'  => '400',
				'logo_animation'    => 'light-sweep',
			),
		),
		'mint'    => array(
			'label'       => __( 'Mint', 'torobpay-for-woocommerce' ),
			'description' => __( 'Soft green background with brand accent bar', 'torobpay-for-woocommerce' ),
			'swatch'      => array(
				'bg'          => '#f3fce9',
				'border'      => '#9CE76A',
				'text'        => '#003D01',
				'accent'      => '#9CE76A',
				'accent_dark' => '#003D01',
			),
			'defaults'    => array(
				'background_color'  => '#f3fce9',
				'border_color'      => '#9CE76A',
				'text_color'        => '#003D01',
				'border_size'       => 1,
				'border_radius'     => 16,
				'title_font_size'   => 15,
				'title_font_weight' => '700',
				'desc_font_size'    => 12,
				'desc_font_weight'  => '500',
				'logo_animation'    => 'ping',
			),
		),
		'stacked' => array(
			'label'       => __( 'Vertical', 'torobpay-for-woocommerce' ),
			'description' => __( 'Logo on top, text below, centered', 'torobpay-for-woocommerce' ),
			'swatch'      => array(
				'bg'          => '#ffffff',
				'border'      => '#9CE76A',
				'text'        => '#003D01',
				'accent'      => '#9CE76A',
				'accent_dark' => '#003D01',
			),
			'defaults'    => array(
				'background_color'  => '#ffffff',
				'border_color'      => '#9CE76A',
				'text_color'        => '#003D01',
				'border_size'       => 2,
				'border_radius'     => 20,
				'title_font_size'   => 15,
				'title_font_weight' => '700',
				'desc_font_size'    => 12,
				'desc_font_weight'  => '400',
				'logo_animation'    => 'pulse',
			),
		),
		'compact' => array(
			'label'       => __( 'Compact', 'torobpay-for-woocommerce' ),
			'description' => __( 'Narrow chip with small logo and tighter spacing', 'torobpay-for-woocommerce' ),
			'swatch'      => array(
				'bg'          => '#9CE76A',
				'border'      => '#9CE76A',
				'text'        => '#003D01',
				'accent'      => '#9CE76A',
				'accent_dark' => '#003D01',
			),
			'defaults'    => array(
				'background_color'  => '#9CE76A',
				'border_color'      => '#9CE76A',
				'text_color'        => '#003D01',
				'border_size'       => 0,
				'border_radius'     => 999,
				'title_font_size'   => 13,
				'title_font_weight' => '700',
				'desc_font_size'    => 11,
				'desc_font_weight'  => '500',
				'logo_animation'    => 'none',
			),
		),
	);
}

function torobpay_get_widget_layout_accent_colors( $layout = null ) {
	$layout  = null === $layout
		? torobpay_get_widget_setting( 'layout' )
		: torobpay_sanitize_widget_layout( $layout );
	$presets = torobpay_widget_layout_presets();
	$swatch  = isset( $presets[ $layout ]['swatch'] ) && is_array( $presets[ $layout ]['swatch'] )
		? $presets[ $layout ]['swatch']
		: array();

	$accent = ! empty( $swatch['accent'] ) ? $swatch['accent'] : '#9CE76A';
	$accent = sanitize_hex_color( $accent );
	if ( ! $accent ) {
		$accent = '#9CE76A';
	}

	$accent_dark = ! empty( $swatch['accent_dark'] )
		? $swatch['accent_dark']
		: ( ! empty( $swatch['text'] ) ? $swatch['text'] : '#003D01' );
	$accent_dark = sanitize_hex_color( $accent_dark );
	if ( ! $accent_dark ) {
		$accent_dark = '#003D01';
	}

	return array(
		'accent'      => $accent,
		'accent_dark' => $accent_dark,
	);
}

function torobpay_sanitize_widget_layout( $layout ) {
	$layout = sanitize_key( (string) $layout );
	if ( in_array( $layout, array( 'glass', 'split', 'forest' ), true ) ) {
		$layout = 'classic';
	}
	$presets = torobpay_widget_layout_presets();
	return isset( $presets[ $layout ] ) ? $layout : 'classic';
}

function torobpay_get_widget_setting( $setting_name ) {
	$defaults = array(
		'layout'            => 'classic',
		'background_color'  => '#ffffff',
		'border_color'      => '#ebecf2',
		'text_color'        => '#1a1c23',
		'border_size'       => 1,
		'border_radius'     => 16,
		'title_font_size'   => 14,
		'title_font_weight' => 'bold',
		'desc_font_size'    => 12,
		'desc_font_weight'  => '300',
		'logo_animation'    => 'light-sweep',
		'display_hook'      => 'woocommerce_after_add_to_cart_form',
		'display_priority'  => 9,
	);

	$default = isset( $defaults[ $setting_name ] ) ? $defaults[ $setting_name ] : '';
	$value   = get_option( 'torobpay_widget_' . $setting_name, $default );

	if ( 'layout' === $setting_name ) {
		return torobpay_sanitize_widget_layout( $value );
	}

	return $value;
}

/**
 * One-time: move the former default widget priority (30) to 9.
 *
 * Runs only when the widget is still on the default product-page hook and the
 * stored priority is still the former default, so custom placements stay put.
 *
 * @return void
 */
function torobpay_maybe_migrate_widget_display_priority() {
	if ( get_option( 'torobpay_widget_display_priority_migrated' ) ) {
		return;
	}

	$default_hook    = 'woocommerce_after_add_to_cart_form';
	$legacy_priority = 30;
	$new_priority    = 9;

	$hook = get_option( 'torobpay_widget_display_hook', false );
	if ( false === $hook || '' === $hook ) {
		$hook = $default_hook;
	}

	$priority = get_option( 'torobpay_widget_display_priority', false );
	if ( $default_hook === $hook && false !== $priority && '' !== $priority && $legacy_priority === (int) $priority ) {
		update_option( 'torobpay_widget_display_priority', $new_priority );
	}

	update_option( 'torobpay_widget_display_priority_migrated', 1 );
}

function torobpay_get_badge_setting( $setting_name ) {
	$defaults = array(
		'position_horizontal'    => 'right',
		'position_vertical'      => 'top',
		'offset_horizontal'      => 10,
		'offset_vertical'        => 10,
		'offset_horizontal_unit' => 'px',
		'offset_vertical_unit'   => 'px',
		'background_color'       => '#9CE76A',
		'text_color'             => '#003D01',
		'border_radius'          => 8,
		'font_size'              => 12,
		'font_weight'            => '600',
		'logo_height'            => 24,
		'compatibility_mode'     => 'no',
	);

	$default = isset( $defaults[ $setting_name ] ) ? $defaults[ $setting_name ] : '';
	return get_option( 'torobpay_badge_' . $setting_name, $default );
}

function torobpay_get_marquee_setting( $setting_name ) {
	// Keep translated copy out of this array: widget.php and marquee.php read
	// compatibility_mode while WooCommerce is still loading (before init), and
	// WordPress 6.7+ treats that as a doing-it-wrong for this text domain.
	$defaults = array(
		'bg_color'              => '#ffffff',
		'bg_opacity'            => 22,
		'text_color'            => '#003D01',
		'font_size'             => 11,
		'font_weight'           => '600',
		'height'                => 26,
		'height_unit'           => 'px',
		'scroll_speed'          => 14,
		'position'              => 'bottom',
		'gap'                   => 0,
		'gap_unit'              => 'px',
		'icon_size'             => 14,
		'z_index'               => 10,
		'vertical_align'        => 'middle',
		'vertical_padding'      => 0,
		'vertical_padding_unit' => 'px',
		'radius_tl'             => 0,
		'radius_tr'             => 0,
		'radius_br'             => 0,
		'radius_bl'             => 0,
		'compatibility_mode'    => 'no',
	);

	if ( 'slogan' === $setting_name ) {
		return __( 'Buy in installments with TorobPay — no fees', 'torobpay-for-woocommerce' );
	}

	$default = isset( $defaults[ $setting_name ] ) ? $defaults[ $setting_name ] : '';
	return get_option( 'torobpay_marquee_' . $setting_name, $default );
}

function torobpay_marquee_compatibility_mode() {
	return torobpay_get_marquee_setting( 'compatibility_mode' ) === 'yes' ? 'yes' : 'no';
}

function torobpay_hex_to_rgba( $hex, $opacity ) {
	$hex = trim( (string) $hex );
	if ( '' !== $hex && '#' !== $hex[0] ) {
		$hex = '#' . $hex;
	}
	$hex = sanitize_hex_color( $hex );
	if ( ! $hex ) {
		$hex = '#ffffff';
	}
	$hex = ltrim( $hex, '#' );
	if ( strlen( $hex ) === 3 ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	$r = hexdec( substr( $hex, 0, 2 ) );
	$g = hexdec( substr( $hex, 2, 2 ) );
	$b = hexdec( substr( $hex, 4, 2 ) );
	$a = round( max( 0, min( 100, (int) $opacity ) ) / 100, 2 );
	return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, $a );
}

/**
 * Escape a hex color for CSS output.
 *
 * @param string $color    Raw color.
 * @param string $fallback Fallback hex color.
 * @return string
 */
function torobpay_esc_css_color( $color, $fallback = '#000000' ) {
	$color = trim( (string) $color );
	if ( '' !== $color && '#' !== $color[0] ) {
		$color = '#' . $color;
	}
	$safe = sanitize_hex_color( $color );
	if ( ! $safe ) {
		$fallback = trim( (string) $fallback );
		if ( '' !== $fallback && '#' !== $fallback[0] ) {
			$fallback = '#' . $fallback;
		}
		$safe = sanitize_hex_color( $fallback );
		$safe = $safe ? $safe : '#000000';
	}
	return esc_attr( $safe );
}

/**
 * Escape a font-weight for CSS output.
 *
 * @param string $weight   Raw weight.
 * @param string $fallback Fallback weight.
 * @return string
 */
function torobpay_esc_css_font_weight( $weight, $fallback = '400' ) {
	$allowed = array( '100', '200', '300', '400', '500', '600', '700', '800', '900' );
	$weight  = strtolower( trim( (string) $weight ) );
	if ( 'bold' === $weight ) {
		$weight = '700';
	} elseif ( 'normal' === $weight ) {
		$weight = '400';
	}
	if ( ! in_array( $weight, $allowed, true ) ) {
		$weight = in_array( (string) $fallback, $allowed, true ) ? (string) $fallback : '400';
	}
	return esc_attr( $weight );
}

/**
 * Allow-list a CSS side used as a property name (left/right or top/bottom).
 *
 * @param string $side Raw side.
 * @param string $axis 'horizontal' or 'vertical'.
 * @return string
 */
function torobpay_sanitize_css_side( $side, $axis = 'horizontal' ) {
	$side = strtolower( trim( (string) $side ) );
	if ( 'vertical' === $axis ) {
		return in_array( $side, array( 'top', 'bottom' ), true ) ? $side : 'top';
	}
	return in_array( $side, array( 'left', 'right' ), true ) ? $side : 'right';
}

/**
 * Allow-list a CSS length unit (optionally with !important).
 *
 * @param string $unit     Raw unit.
 * @param string $fallback Fallback unit, or empty for unitless values.
 * @return string
 */
function torobpay_sanitize_css_unit( $unit, $fallback = 'px' ) {
	$unit      = strtolower( trim( (string) $unit ) );
	$important = false;
	if ( false !== strpos( $unit, 'important' ) ) {
		$important = true;
		$unit      = trim( str_replace( array( '!important', 'important' ), '', $unit ) );
	}
	if ( '' === $unit ) {
		return '';
	}
	$allowed = array( 'px', '%', 'em', 'rem', 'vh', 'vw', 's' );
	if ( ! in_array( $unit, $allowed, true ) ) {
		$unit = in_array( $fallback, $allowed, true ) ? $fallback : 'px';
	}
	return $important ? $unit . ' !important' : $unit;
}

/**
 * Allow-list a CSS property or plugin custom property.
 *
 * @param string $prop Raw property.
 * @return string Empty when the property is not allowed.
 */
function torobpay_sanitize_css_property( $prop ) {
	$prop = trim( (string) $prop );
	if ( preg_match( '/^--torobpay-[a-z0-9-]+$/', $prop ) ) {
		return $prop;
	}
	$allowed = array(
		'color',
		'background',
		'background-color',
		'border-color',
		'border-radius',
		'border-width',
		'font-weight',
		'font-size',
		'height',
		'width',
		'max-width',
		'top',
		'right',
		'bottom',
		'left',
		'z-index',
		'padding',
		'padding-top',
		'padding-bottom',
		'margin-top',
		'align-items',
		'animation-duration',
	);
	return in_array( $prop, $allowed, true ) ? $prop : '';
}

/**
 * Build one escaped CSS declaration, or an empty string when the value is skipped.
 *
 * @param string     $prop CSS property.
 * @param mixed      $val  Raw value.
 * @param string     $unit Raw unit.
 * @return string
 */
function torobpay_css_declaration( $prop, $val, $unit = '' ) {
	$prop = torobpay_sanitize_css_property( $prop );
	if ( '' === $prop || '' === $val || null === $val ) {
		return '';
	}

	if ( 'font-weight' === $prop ) {
		return $prop . ':' . torobpay_esc_css_font_weight( $val ) . ';';
	}

	if ( in_array( $prop, array( 'color', 'background', 'background-color', 'border-color' ), true ) ) {
		return $prop . ':' . torobpay_esc_css_color( $val ) . ';';
	}

	$unit = torobpay_sanitize_css_unit( $unit, '' );
	if ( ! is_numeric( $val ) ) {
		return '';
	}

	if ( (string) (int) $val === (string) $val || is_int( $val ) ) {
		$safe_val = (int) $val;
	} else {
		$safe_val = (float) $val;
	}

	return $prop . ':' . $safe_val . $unit . ';';
}

/** Mirror WooCommerce coming-soon visitor bypasses; no public WC wrapper exists. */
function torobpay_should_hide_for_wc_coming_soon() {
	if ( ! function_exists( 'wc_get_container' ) ) {
		return false;
	}

	try {
		$helper = wc_get_container()->get( \Automattic\WooCommerce\Internal\ComingSoon\ComingSoonHelper::class );
	} catch ( \Exception $e ) {
		return false;
	}

	if ( $helper->is_site_live() || ! $helper->is_current_page_coming_soon() ) {
		return false;
	}

	if ( class_exists( '\Automattic\WooCommerce\Admin\Features\Features' )
		&& ! \Automattic\WooCommerce\Admin\Features\Features::is_enabled( 'launch-your-store' ) ) {
		return false;
	}

	if ( current_user_can( 'manage_woocommerce' ) ) {
		return false;
	}

	if ( apply_filters( 'woocommerce_coming_soon_exclude', false ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.
		return false;
	}

	if ( 'yes' === get_option( 'woocommerce_private_link', 'no' ) ) {
		$share_key = get_option( 'woocommerce_share_key' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['woo-share'] ) && $share_key === $_GET['woo-share'] ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_COOKIE['woo-share'] ) && $share_key === $_COOKIE['woo-share'] ) {
			return false;
		}
	}

	return true;
}

function torobpay_has_topbar() {
	if ( ! torobpay_is_configured() || ! torobpay_has_cached_auth_state() ) {
		return false;
	}
	if ( torobpay_should_hide_for_wc_coming_soon() ) {
		return false;
	}

	return get_option( 'torobpay_topbar_enabled', 'no' ) === 'yes';
}

function torobpay_sanitize_bg_custom_icon( $url, $attachment_id ) {
	$url           = esc_url_raw( (string) $url );
	$attachment_id = absint( $attachment_id );

	if ( '' === $url ) {
		return array( '', 0 );
	}

	if ( 0 === $attachment_id ) {
		$bundled = esc_url_raw( plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) );
		if ( untrailingslashit( $url ) === untrailingslashit( $bundled ) ) {
			return array( $url, 0 );
		}

		return array( '', 0 );
	}

	$allowed_mimes = array( 'image/png', 'image/svg+xml' );
	$mime          = (string) get_post_mime_type( $attachment_id );
	$file          = get_attached_file( $attachment_id );
	$filetype      = ( is_string( $file ) && '' !== $file ) ? wp_check_filetype( $file ) : array( 'type' => '' );
	$detected_mime = isset( $filetype['type'] ) ? (string) $filetype['type'] : '';
	$ext_ok        = is_string( $file ) && preg_match( '/\.(png|svg)$/i', $file );

	if (
		! in_array( $mime, $allowed_mimes, true )
		&& ! in_array( $detected_mime, $allowed_mimes, true )
		&& ! $ext_ok
	) {
		return array( '', 0 );
	}

	$stored_url = wp_get_attachment_url( $attachment_id );
	if ( ! $stored_url ) {
		return array( '', 0 );
	}

	return array( esc_url_raw( $stored_url ), $attachment_id );
}

function torobpay_get_topbar_setting( $setting_name ) {
	$defaults = array(
		'height'        => 44,
		'font_size'     => 14,
		'font_weight'   => '700',
		'logo_size'     => 24,
		'speed'         => 8,
		'ticker_width'  => 250,
		'animation'     => 'slide-up',
		'bg_animation'  => 'none',
		'bg_density'    => 'medium',
		'bg_speed'      => 5,
		'position'      => 'normal',
		'sticky_offset' => 0,
		'sticky_header_selector' => '',
		'z_index'         => 9990,
		'custom_sentence' => '',
	);

	$default = isset( $defaults[ $setting_name ] ) ? $defaults[ $setting_name ] : '';
	return get_option( 'torobpay_topbar_' . $setting_name, $default );
}

/** Sanitize admin-provided selectors emitted directly in CSS. */
function torobpay_sanitize_css_selector_list( $selector ) {
	$selector = wp_strip_all_tags( (string) $selector );
	$selector = preg_replace( '/[^A-Za-z0-9_\-#.\s,]/', '', $selector );
	$selector = preg_replace( '/\s+/', ' ', $selector );

	$parts = array_filter(
		array_map(
			static function ( $part ) {
				return trim( $part, " \t\n\r\0\x0B," );
			},
			explode( ',', $selector )
		)
	);

	$parts = array_slice( $parts, 0, 10 );
	return substr( implode( ', ', $parts ), 0, 300 );
}

/**
 * CSS properties used in plugin style attributes (WP 5.8 safecss allow-list).
 *
 * @param string[] $styles Allowed CSS property names.
 * @return string[]
 */
function torobpay_safe_style_css( $styles ) {
	if ( ! is_array( $styles ) ) {
		$styles = array();
	}

	$styles[] = 'object-position';
	$styles[] = 'animation-duration';
	$styles[] = 'animation-delay';
	$styles[] = '--torobpay-badge-i';
	$styles[] = '--torobpay-tb-spread-duration';
	$styles[] = '--torobpay-widget-bg';
	$styles[] = '--torobpay-widget-border';
	$styles[] = '--torobpay-widget-text';
	$styles[] = '--torobpay-widget-accent';
	$styles[] = '--torobpay-widget-accent-dark';

	return $styles;
}
add_filter( 'safe_style_css', 'torobpay_safe_style_css' );

/**
 * Allowed HTML for late-escaping plugin markup with wp_kses().
 *
 * Starts from post tags and adds the attributes this plugin emits (including
 * data-* needed on WordPress 5.8, which does not allow arbitrary data-*).
 *
 * @return array<string, array<string, bool>>
 */
function torobpay_kses_allowed_html() {
	static $allowed = null;
	if ( null !== $allowed ) {
		return $allowed;
	}

	$allowed = wp_kses_allowed_html( 'post' );

	$extra_attrs = array(
		'class'                           => true,
		'id'                              => true,
		'style'                           => true,
		'title'                           => true,
		'dir'                             => true,
		'role'                            => true,
		'hidden'                          => true,
		'inert'                           => true,
		'tabindex'                        => true,
		'type'                            => true,
		'disabled'                        => true,
		'width'                           => true,
		'height'                          => true,
		'decoding'                        => true,
		'loading'                         => true,
		'aria-hidden'                     => true,
		'aria-label'                      => true,
		'aria-pressed'                    => true,
		'aria-checked'                    => true,
		'aria-expanded'                   => true,
		'aria-haspopup'                   => true,
		'aria-controls'                   => true,
		'aria-modal'                      => true,
		'aria-labelledby'                 => true,
		'aria-live'                       => true,
		'aria-atomic'                     => true,
		'datetime'                        => true,
		'data-expires-at'                 => true,
		'data-promo-code'                 => true,
		'data-torobpay-fetch-credentials' => true,
		'data-order-id'                   => true,
		'data-order-status'               => true,
		'data-tooltip'                    => true,
		'data-action-code'                => true,
		'data-action-reason'              => true,
		'data-product-id'                 => true,
		'data-variation-id'               => true,
		'data-bg-anim'                    => true,
		'data-bg-density'                 => true,
		'data-bg-speed'                   => true,
		'data-bg-custom-icon'             => true,
		'data-bg-icon-scale'              => true,
		'data-bg-icon-scale-tablet'       => true,
		'data-bg-icon-scale-mobile'       => true,
		'data-bg-dark'                    => true,
		'data-bp-tablet'                  => true,
		'data-bp-mobile'                  => true,
		'data-duration'                   => true,
		'data-autoplay'                   => true,
		'data-pause-hover'                => true,
		'data-initial-paused'             => true,
		'data-slide-to'                   => true,
		'data-tip'                        => true,
		'data-tab'                        => true,
		'data-layout'                     => true,
		'data-section-key'                => true,
		'data-device'                     => true,
		'data-index'                      => true,
		'data-notification-id'            => true,
		'data-nonce'                      => true,
		'data-count'                      => true,
		'data-tier'                       => true,
		'data-category'                   => true,
		'data-badge'                      => true,
		'data-jdp'                        => true,
		'data-jdp-max-date'               => true,
		'data-jdp-mode'                   => true,
		'data-jdp-target-value-input'     => true,
		'data-jdp-target-value-type'      => true,
		'data-jdp-only-date'              => true,
		'translate'                       => true,
	);

	foreach ( $allowed as $tag => $attrs ) {
		$allowed[ $tag ] = is_array( $attrs ) ? array_merge( $attrs, $extra_attrs ) : $extra_attrs;
	}

	$allowed['button'] = isset( $allowed['button'] ) && is_array( $allowed['button'] )
		? array_merge( $allowed['button'], $extra_attrs )
		: $extra_attrs;

	$allowed['bdi'] = isset( $allowed['bdi'] ) && is_array( $allowed['bdi'] )
		? array_merge( $allowed['bdi'], $extra_attrs )
		: $extra_attrs;

	$allowed['kbd'] = isset( $allowed['kbd'] ) && is_array( $allowed['kbd'] )
		? array_merge( $allowed['kbd'], $extra_attrs )
		: $extra_attrs;

	$allowed['time'] = isset( $allowed['time'] ) && is_array( $allowed['time'] )
		? array_merge( $allowed['time'], $extra_attrs )
		: $extra_attrs;

	$allowed['style'] = array(
		'id'    => true,
		'type'  => true,
		'media' => true,
	);

	$allowed['link'] = array(
		'rel'   => true,
		'id'    => true,
		'href'  => true,
		'media' => true,
		'type'  => true,
	);

	return $allowed;
}
