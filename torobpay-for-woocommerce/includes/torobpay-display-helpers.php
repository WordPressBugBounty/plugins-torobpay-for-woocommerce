<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** TorobPay storefront display helpers. */

/**
 * Hue, saturation, and lightness bounds for the green color sliders.
 *
 * Hue 96 is the brand green #9CE76A, so that color stays exact.
 *
 * @return array{hue:int,saturationMin:int,saturationMax:int,lightnessMin:int,lightnessMax:int}
 */
function torobpay_green_color_limits() {
	return array(
		'hue'           => 96,
		'saturationMin' => 35,
		'saturationMax' => 100,
		'lightnessMin'  => 8,
		'lightnessMax'  => 97,
	);
}

/**
 * Legacy hex defaults for the color options the green slider owns.
 *
 * @return array<string, string>
 */
function torobpay_green_color_options() {
	return array(
		'torobpay_widget_background_color' => '#ffffff',
		'torobpay_widget_border_color'     => '#ebecf2',
		'torobpay_widget_text_color'       => '#1a1c23',
		'torobpay_badge_background_color'  => '#9CE76A',
		'torobpay_badge_border_color'      => '#9CE76A',
		'torobpay_badge_text_color'        => '#003D01',
		'torobpay_marquee_bg_color'        => '#ffffff',
		'torobpay_marquee_text_color'      => '#003D01',
	);
}

/**
 * Normalize a hex color to #rrggbb, or return an empty string.
 *
 * @param mixed $color Raw color.
 * @return string
 */
function torobpay_normalize_hex_color( $color ) {
	$color = trim( (string) $color );
	if ( '' === $color ) {
		return '';
	}
	if ( '#' !== $color[0] ) {
		$color = '#' . $color;
	}
	$sanitized = sanitize_hex_color( $color );
	if ( ! is_string( $sanitized ) || '' === $sanitized ) {
		return '';
	}
	$hex = strtolower( ltrim( $sanitized, '#' ) );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
		return '';
	}
	return '#' . $hex;
}

/**
 * @param string $hex Normalized #rrggbb.
 * @return array{0:int,1:int,2:int}
 */
function torobpay_hex_to_rgb( $hex ) {
	$hex = ltrim( (string) $hex, '#' );
	return array(
		hexdec( substr( $hex, 0, 2 ) ),
		hexdec( substr( $hex, 2, 2 ) ),
		hexdec( substr( $hex, 4, 2 ) ),
	);
}

/**
 * @param int $red   Red channel, 0–255.
 * @param int $green Green channel, 0–255.
 * @param int $blue  Blue channel, 0–255.
 * @return array{h:float,s:float,l:float} Hue in degrees, saturation and lightness in percent.
 */
function torobpay_rgb_to_hsl( $red, $green, $blue ) {
	$r   = $red / 255;
	$g   = $green / 255;
	$b   = $blue / 255;
	$max = max( $r, $g, $b );
	$min = min( $r, $g, $b );
	$l   = ( $max + $min ) / 2;
	$d   = $max - $min;
	$h   = 0.0;
	$s   = 0.0;
	if ( $d > 0 ) {
		$s = $d / ( 1 - abs( ( 2 * $l ) - 1 ) );
		if ( $max === $r ) {
			$h = fmod( ( $g - $b ) / $d, 6 );
		} elseif ( $max === $g ) {
			$h = ( ( $b - $r ) / $d ) + 2;
		} else {
			$h = ( ( $r - $g ) / $d ) + 4;
		}
		$h *= 60;
		if ( $h < 0 ) {
			$h += 360;
		}
	}
	return array(
		'h' => $h,
		's' => $s * 100,
		'l' => $l * 100,
	);
}

/**
 * @param float $hue        Hue in degrees.
 * @param float $saturation Saturation percent.
 * @param float $lightness  Lightness percent.
 * @return string #rrggbb.
 */
function torobpay_hsl_to_hex( $hue, $saturation, $lightness ) {
	$s  = $saturation / 100;
	$l  = $lightness / 100;
	$c  = ( 1 - abs( ( 2 * $l ) - 1 ) ) * $s;
	$hp = fmod( fmod( $hue, 360 ) + 360, 360 ) / 60;
	$x  = $c * ( 1 - abs( fmod( $hp, 2 ) - 1 ) );
	$r  = 0.0;
	$g  = 0.0;
	$b  = 0.0;
	if ( $hp < 1 ) {
		$r = $c;
		$g = $x;
	} elseif ( $hp < 2 ) {
		$r = $x;
		$g = $c;
	} elseif ( $hp < 3 ) {
		$g = $c;
		$b = $x;
	} elseif ( $hp < 4 ) {
		$g = $x;
		$b = $c;
	} elseif ( $hp < 5 ) {
		$r = $x;
		$b = $c;
	} else {
		$r = $c;
		$b = $x;
	}
	$m       = $l - ( $c / 2 );
	$channel = static function ( $value ) use ( $m ) {
		$n = (int) round( ( $value + $m ) * 255 );
		if ( $n < 0 ) {
			return 0;
		}
		if ( $n > 255 ) {
			return 255;
		}
		return $n;
	};
	return sprintf( '#%02x%02x%02x', $channel( $r ), $channel( $g ), $channel( $b ) );
}

/**
 * Project one hex onto the green slider, without the stability loop.
 *
 * @param string $hex Normalized #rrggbb.
 * @return string
 */
function torobpay_project_hex_onto_green( $hex ) {
	$limits = torobpay_green_color_limits();
	$rgb    = torobpay_hex_to_rgb( $hex );
	$hsl    = torobpay_rgb_to_hsl( $rgb[0], $rgb[1], $rgb[2] );
	$s      = (int) round( $hsl['s'] );
	$l      = (int) round( $hsl['l'] );
	$s      = min( $limits['saturationMax'], max( $limits['saturationMin'], $s ) );
	$l      = min( $limits['lightnessMax'], max( $limits['lightnessMin'], $l ) );
	return torobpay_hsl_to_hex( (float) $limits['hue'], $s, $l );
}

/**
 * Lock a color to the TorobPay green hue, keeping lightness and clamping saturation.
 *
 * Invalid colors use $fallback, which is itself projected onto the same range.
 *
 * @param mixed  $color    Raw color.
 * @param string $fallback Fallback hex when $color is not a hex color.
 * @return string #rrggbb.
 */
function torobpay_clamp_hex_to_green( $color, $fallback = '#9CE76A' ) {
	$hex = torobpay_normalize_hex_color( $color );
	if ( '' === $hex ) {
		$hex = torobpay_normalize_hex_color( $fallback );
	}
	if ( '' === $hex ) {
		$hex = '#9ce76a';
	}
	$current = $hex;
	for ( $i = 0; $i < 4; $i++ ) {
		$next = torobpay_project_hex_onto_green( $current );
		if ( $next === $current ) {
			return $current;
		}
		$current = $next;
	}
	return $current;
}

/**
 * Slider position for a color after it has been clamped.
 *
 * @param mixed  $color    Raw color.
 * @param string $fallback Fallback hex.
 * @return array{hex:string,saturation:int,lightness:int}
 */
function torobpay_green_slider_state( $color, $fallback = '#9CE76A' ) {
	$limits = torobpay_green_color_limits();
	$hex    = torobpay_clamp_hex_to_green( $color, $fallback );
	$rgb    = torobpay_hex_to_rgb( $hex );
	$hsl    = torobpay_rgb_to_hsl( $rgb[0], $rgb[1], $rgb[2] );
	$s      = (int) round( $hsl['s'] );
	$l      = (int) round( $hsl['l'] );
	$s      = min( $limits['saturationMax'], max( $limits['saturationMin'], $s ) );
	$l      = min( $limits['lightnessMax'], max( $limits['lightnessMin'], $l ) );
	return array(
		'hex'        => $hex,
		'saturation' => $s,
		'lightness'  => $l,
	);
}

/**
 * Read one green color option, clamping legacy or unset values.
 *
 * @param string $option_name Option name from torobpay_green_color_options().
 * @return string
 */
function torobpay_read_green_color_option( $option_name ) {
	$legacy   = torobpay_green_color_options();
	$fallback = isset( $legacy[ $option_name ] ) ? $legacy[ $option_name ] : '#9CE76A';
	return torobpay_clamp_hex_to_green( get_option( $option_name, $fallback ), $fallback );
}

/**
 * Store one posted color on the green slider.
 *
 * @param string $option_name Option name.
 * @param mixed  $raw         Posted value.
 * @return void
 */
function torobpay_save_green_color_option( $option_name, $raw ) {
	$legacy   = torobpay_green_color_options();
	$fallback = isset( $legacy[ $option_name ] ) ? $legacy[ $option_name ] : '#9CE76A';
	update_option( $option_name, torobpay_clamp_hex_to_green( $raw, $fallback ) );
}

/**
 * Lightness and saturation sliders locked to the TorobPay green.
 *
 * @param string $id    Field id and input name. The hidden input keeps this id.
 * @param mixed  $value Stored or default color.
 * @return void
 */
function torobpay_render_green_color_control( $id, $value ) {
	$limits        = torobpay_green_color_limits();
	$resolved      = torobpay_green_slider_state( $value );
	$lightness_id  = $id . '_lightness';
	$saturation_id = $id . '_saturation';
	$direction     = is_rtl() ? 'left' : 'right';
	$light_track   = sprintf(
		'linear-gradient(to %1$s, hsl(%2$d, %3$d%%, %4$d%%), hsl(%2$d, %3$d%%, %5$d%%))',
		$direction,
		(int) $limits['hue'],
		(int) $resolved['saturation'],
		(int) $limits['lightnessMin'],
		(int) $limits['lightnessMax']
	);
	$sat_track     = sprintf(
		'linear-gradient(to %1$s, hsl(%2$d, %3$d%%, %4$d%%), hsl(%2$d, %5$d%%, %4$d%%))',
		$direction,
		(int) $limits['hue'],
		(int) $limits['saturationMin'],
		(int) $resolved['lightness'],
		(int) $limits['saturationMax']
	);
	?>
	<div class="torobpay-green-color">
		<span class="torobpay-green-color__swatch" style="background-color: <?php echo esc_attr( $resolved['hex'] ); ?>" aria-hidden="true"></span>
		<div class="torobpay-green-color__sliders">
			<label class="torobpay-green-color__slider" for="<?php echo esc_attr( $lightness_id ); ?>">
				<span><?php esc_html_e( 'Lightness', 'torobpay-for-woocommerce' ); ?></span>
				<input type="range"
					id="<?php echo esc_attr( $lightness_id ); ?>"
					class="torobpay-green-color__lightness"
					min="<?php echo esc_attr( (string) $limits['lightnessMin'] ); ?>"
					max="<?php echo esc_attr( (string) $limits['lightnessMax'] ); ?>"
					step="1"
					value="<?php echo esc_attr( (string) $resolved['lightness'] ); ?>"
					style="--torobpay-green-track: <?php echo esc_attr( $light_track ); ?>">
			</label>
			<label class="torobpay-green-color__slider" for="<?php echo esc_attr( $saturation_id ); ?>">
				<span><?php esc_html_e( 'Saturation', 'torobpay-for-woocommerce' ); ?></span>
				<input type="range"
					id="<?php echo esc_attr( $saturation_id ); ?>"
					class="torobpay-green-color__saturation"
					min="<?php echo esc_attr( (string) $limits['saturationMin'] ); ?>"
					max="<?php echo esc_attr( (string) $limits['saturationMax'] ); ?>"
					step="1"
					value="<?php echo esc_attr( (string) $resolved['saturation'] ); ?>"
					style="--torobpay-green-track: <?php echo esc_attr( $sat_track ); ?>">
			</label>
		</div>
		<input type="hidden"
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( $id ); ?>"
			value="<?php echo esc_attr( $resolved['hex'] ); ?>">
	</div>
	<?php
}

function torobpay_widget_layout_presets() {
	$pale      = torobpay_clamp_hex_to_green( '#ffffff' );
	$line      = torobpay_clamp_hex_to_green( '#ebecf2' );
	$ink       = torobpay_clamp_hex_to_green( '#1a1c23' );
	$mint      = torobpay_clamp_hex_to_green( '#f3fce9' );
	$brand     = torobpay_clamp_hex_to_green( '#9CE76A' );
	$brand_ink = torobpay_clamp_hex_to_green( '#003D01' );

	return array(
		'classic' => array(
			'label'       => __( 'Classic', 'torobpay-for-woocommerce' ),
			'description' => __( 'Standard card with logo beside text', 'torobpay-for-woocommerce' ),
			'swatch'      => array(
				'bg'     => $pale,
				'border' => $line,
				'text'   => $ink,
			),
			'defaults'    => array(
				'background_color'  => $pale,
				'border_color'      => $line,
				'text_color'        => $ink,
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
				'bg'          => $mint,
				'border'      => $brand,
				'text'        => $brand_ink,
				'accent'      => '#9CE76A',
				'accent_dark' => '#003D01',
			),
			'defaults'    => array(
				'background_color'  => $mint,
				'border_color'      => $brand,
				'text_color'        => $brand_ink,
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
				'bg'          => $pale,
				'border'      => $brand,
				'text'        => $brand_ink,
				'accent'      => '#9CE76A',
				'accent_dark' => '#003D01',
			),
			'defaults'    => array(
				'background_color'  => $pale,
				'border_color'      => $brand,
				'text_color'        => $brand_ink,
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
				'bg'          => $brand,
				'border'      => $brand,
				'text'        => $brand_ink,
				'accent'      => '#9CE76A',
				'accent_dark' => '#003D01',
			),
			'defaults'    => array(
				'background_color'  => $brand,
				'border_color'      => $brand,
				'text_color'        => $brand_ink,
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

	$option_name = 'torobpay_widget_' . $setting_name;
	$colors      = torobpay_green_color_options();
	if ( isset( $colors[ $option_name ] ) ) {
		return torobpay_read_green_color_option( $option_name );
	}

	$default = isset( $defaults[ $setting_name ] ) ? $defaults[ $setting_name ] : '';
	$value   = get_option( $option_name, $default );

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

/**
 * Move saved widget, badge, and marquee colors onto the green slider.
 *
 * Each saved color keeps its lightness. Hue is locked to the brand green and
 * saturation is pulled into the slider range, so a light background stays light
 * and dark text stays dark. Options that were never saved stay unset.
 *
 * @return void
 */
function torobpay_maybe_migrate_green_colors() {
	if ( get_option( 'torobpay_green_colors_migrated' ) ) {
		return;
	}

	foreach ( torobpay_green_color_options() as $option => $fallback ) {
		$stored = get_option( $option, null );
		if ( ! is_string( $stored ) ) {
			continue;
		}
		$clamped = torobpay_clamp_hex_to_green( $stored, $fallback );
		if ( strtolower( (string) $stored ) !== strtolower( $clamped ) ) {
			update_option( $option, $clamped );
		}
	}

	update_option( 'torobpay_green_colors_migrated', 1 );
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
		'border_color'           => '#9CE76A',
		'text_color'             => '#003D01',
		'border_radius'          => 8,
		'font_size'              => 12,
		'font_weight'            => '600',
		'logo_height'            => 24,
		'compatibility_mode'     => 'no',
	);

	$option_name = 'torobpay_badge_' . $setting_name;
	$colors      = torobpay_green_color_options();
	if ( isset( $colors[ $option_name ] ) ) {
		return torobpay_read_green_color_option( $option_name );
	}

	$default = isset( $defaults[ $setting_name ] ) ? $defaults[ $setting_name ] : '';
	return get_option( $option_name, $default );
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

	$option_name = 'torobpay_marquee_' . $setting_name;
	$colors      = torobpay_green_color_options();
	if ( isset( $colors[ $option_name ] ) ) {
		return torobpay_read_green_color_option( $option_name );
	}

	$default = isset( $defaults[ $setting_name ] ) ? $defaults[ $setting_name ] : '';
	return get_option( $option_name, $default );
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
		'data-show-price'                 => true,
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
