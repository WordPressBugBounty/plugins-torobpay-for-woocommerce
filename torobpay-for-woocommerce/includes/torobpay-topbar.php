<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TorobPay Sitewide Topbar
 *
 * Full-width banner via wp_body_open or [torobpay_topbar]. Coming-soon classic
 * themes still fire wp_body_open; torobpay_should_hide_for_wc_coming_soon()
 * keeps the bar off the landing page. Ticker: credit + 3 brand lines + optional
 * custom sentence. Spread: credit solo, then slots, then custom. Active discount
 * campaigns use a second track; the standard track stays for after expiry.
 * Logos are fixed on both sides.
 *
 * Settings: torobpay_topbar_* (height, font_size, font_weight, logo_size, speed, animation)
 * Brand colors: background #9CE76A, text #003D01
 */

add_action( 'init', 'torobpay_topbar_register_placement', 20 );
add_action( 'wp_enqueue_scripts', 'torobpay_maybe_enqueue_topbar_styles' );
add_shortcode( 'torobpay_topbar', 'torobpay_topbar_shortcode' );

/**
 * Enqueue topbar stylesheet early when the automatic bar will render.
 */
function torobpay_maybe_enqueue_topbar_styles() {
	if ( 'automatic' !== torobpay_get_topbar_placement() ) {
		return;
	}
	if ( ! function_exists( 'torobpay_has_topbar' ) || ! torobpay_has_topbar() ) {
		return;
	}
	torobpay_enqueue_topbar_styles();
}

/**
 * Topbar placement mode: automatic (wp_body_open) or manual (shortcode).
 *
 * @return string 'automatic'|'manual'
 */
function torobpay_get_topbar_placement() {
	$placement = get_option( 'torobpay_topbar_placement', 'automatic' );
	return in_array( $placement, array( 'automatic', 'manual' ), true ) ? $placement : 'automatic';
}

/**
 * Register automatic wp_body_open injection when placement is automatic.
 */
function torobpay_topbar_register_placement() {
	if ( 'automatic' === torobpay_get_topbar_placement() ) {
		add_action( 'wp_body_open', 'torobpay_topbar_render', 1 );
	}
}

/**
 * Hook callback: echo the topbar when automatic placement and display toggle are on.
 */
function torobpay_topbar_render() {
	if ( 'automatic' !== torobpay_get_topbar_placement() ) {
		return;
	}
	if ( ! function_exists( 'torobpay_has_topbar' ) || ! torobpay_has_topbar() ) {
		return;
	}
	torobpay_topbar_render_once( true );
}

/**
 * Shortcode: [torobpay_topbar]
 *
 * Renders a simple in-flow embed bar (no sticky/document positioning) so the
 * theme controls header stickiness. Usable even when automatic display is
 * disabled, as long as TorobPay is configured and authenticated.
 *
 * @return string
 */
function torobpay_topbar_shortcode() {
	if ( ! function_exists( 'torobpay_is_configured' ) || ! torobpay_is_configured() ) {
		return '';
	}
	if ( function_exists( 'torobpay_has_cached_auth_state' ) && ! torobpay_has_cached_auth_state() ) {
		return '';
	}
	if ( function_exists( 'torobpay_should_hide_for_wc_coming_soon' ) && torobpay_should_hide_for_wc_coming_soon() ) {
		return '';
	}

	return torobpay_topbar_render_once( false, true );
}

/**
 * Render the topbar at most once per request (shared by hook + shortcode).
 *
 * @param bool $echo  When true, echo HTML; otherwise return it.
 * @param bool $embed When true, render a simple in-flow bar (shortcode / theme header).
 * @return string
 */
function torobpay_topbar_render_once( $echo = true, $embed = false ) {
	static $rendered = false;
	if ( $rendered ) {
		return '';
	}

	$html = torobpay_get_topbar_html( $embed );
	if ( '' === $html ) {
		return '';
	}

	$rendered = true;
	if ( $echo ) {
		echo wp_kses( $html, torobpay_kses_allowed_html() );
	}
	return wp_kses( $html, torobpay_kses_allowed_html() );
}

/**
 * Build the topbar HTML and enqueue its stylesheet.
 *
 * @param bool $embed When true, skip sticky/document positioning so the theme controls layout.
 * @return string
 */
function torobpay_get_topbar_html( $embed = false ) {
	ob_start();

	$logo_url = esc_url( plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) );

	// Per-device (responsive) dimensions. An empty tablet/mobile value inherits
	// the base/desktop value; values are emitted as CSS custom properties below.
	$height_r       = torobpay_get_responsive_setting( 'torobpay_topbar_height', 44 );
	$font_size_r    = torobpay_get_responsive_setting( 'torobpay_topbar_font_size', 14 );
	$logo_size_r    = torobpay_get_responsive_setting( 'torobpay_topbar_logo_size', 24 );
	$ticker_width_r = torobpay_get_responsive_setting( 'torobpay_topbar_ticker_width', 250 );

	$logo_size   = (int) $logo_size_r['base'];
	$font_weight = torobpay_get_topbar_setting( 'font_weight' );
	$speed       = (int) torobpay_get_topbar_setting( 'speed' );
	$animation   = sanitize_key( (string) torobpay_get_topbar_setting( 'animation' ) );

	// Background animation: a decorative canvas/CSS layer behind the slogans.
	$bg_animation = sanitize_key( (string) torobpay_get_topbar_setting( 'bg_animation' ) );
	$allowed_bg_animations = array( 'none', 'perlin', 'rain', 'float', 'aurora' );
	if ( ! in_array( $bg_animation, $allowed_bg_animations, true ) ) {
		$bg_animation = 'none';
	}

	// Background-animation tuning: density (icon count) and speed.
	$bg_density = sanitize_key( (string) torobpay_get_topbar_setting( 'bg_density' ) );
	if ( ! in_array( $bg_density, array( 'low', 'medium', 'high', 'dense' ), true ) ) {
		$bg_density = 'medium';
	}
	$bg_speed = max( 1, min( 10, (int) torobpay_get_topbar_setting( 'bg_speed' ) ) );
	$bg_custom_icon    = (string) get_option( 'torobpay_topbar_bg_custom_icon', plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) );
	$bg_custom_icon_id = (int) get_option( 'torobpay_topbar_bg_custom_icon_id', 0 );

	// Position mode: normal (scrolls away with page) | sticky (admin-bar style:
	// fixed at the top and reserves a matching document gap). Embed/shortcode
	// output ignores position — the theme owns stickiness and stacking.
	$position = 'normal';
	$offset                 = 0;
	$z_index                = 0;
	$sticky_header_selector = '';
	if ( ! $embed ) {
		$position = sanitize_key( (string) torobpay_get_topbar_setting( 'position' ) );
		if ( ! in_array( $position, array( 'normal', 'sticky' ), true ) ) {
			$position = 'normal';
		}
		$offset  = max( 0, (int) torobpay_get_topbar_setting( 'sticky_offset' ) );
		$z_index = max( 0, min( 999999, (int) torobpay_get_topbar_setting( 'z_index' ) ) );
		if ( function_exists( 'torobpay_sanitize_css_selector_list' ) ) {
			$sticky_header_selector = torobpay_sanitize_css_selector_list( (string) torobpay_get_topbar_setting( 'sticky_header_selector' ) );
		}
	}

	// Whitelist the animation style; fall back to the default slide-up.
	$allowed_animations = array( 'slide-up', 'slide-down', 'fade', 'flip', 'zoom', 'blur', 'bounce', 'spread' );
	if ( ! in_array( $animation, $allowed_animations, true ) ) {
		$animation = 'slide-up';
	}

	// Standard-track order. Ticker prepends credit; Spread times credit separately.
	$credit_line = __( 'Initial credit of up to 30 million tomans', 'torobpay-for-woocommerce' );
	$slogans     = array(
		__( 'Buy in installments with TorobPay', 'torobpay-for-woocommerce' ),
		__( '4 installments, no fees', 'torobpay-for-woocommerce' ),
		__( 'No guarantor, no check', 'torobpay-for-woocommerce' ),
	);
	if ( 'spread' !== $animation ) {
		array_unshift( $slogans, $credit_line );
	}

	$custom_sentence = trim( (string) torobpay_get_topbar_setting( 'custom_sentence' ) );
	if ( '' !== $custom_sentence && function_exists( 'torobpay_pdp_promo_hides_topbar_custom_sentence' ) && torobpay_pdp_promo_hides_topbar_custom_sentence() ) {
		// Omit only while the campaign slide is on the top bar (see pdp-promo.js expiry).
		$custom_sentence = '';
	}
	if ( '' !== $custom_sentence && 'spread' !== $animation ) {
		$slogans[] = $custom_sentence;
	}

	torobpay_enqueue_topbar_styles();

	/*
	 * Build the topbar's per-device CSS as inline styles on the torobpay-topbar handle
	 * (instead of an inline style attribute) so tablet/mobile overrides can live inside
	 * @media queries. topbar.css consumes these custom properties for the live sizes.
	 */
	$device_int = static function ( $val ) {
		return ( '' === $val || null === $val ) ? '' : (int) $val;
	};

	$style_props = array(
		array( 'prop' => '--torobpay-topbar-fs', 'base' => (int) $font_size_r['base'], 'tablet' => $device_int( $font_size_r['tablet'] ), 'mobile' => $device_int( $font_size_r['mobile'] ), 'unit' => 'px' ),
		array( 'prop' => '--torobpay-topbar-logo', 'base' => (int) $logo_size_r['base'], 'tablet' => $device_int( $logo_size_r['tablet'] ), 'mobile' => $device_int( $logo_size_r['mobile'] ), 'unit' => 'px' ),
		array( 'prop' => '--torobpay-topbar-w', 'base' => (int) $ticker_width_r['base'], 'tablet' => $device_int( $ticker_width_r['tablet'] ), 'mobile' => $device_int( $ticker_width_r['mobile'] ), 'unit' => 'px' ),
		array( 'prop' => 'font-weight', 'base' => $font_weight ),
	);

	/*
	 * Use is_admin_bar_showing() (server-side) to get the correct admin bar height
	 * as a concrete px value. Setting it as a CSS variable on body.admin-bar fails
	 * because --torobpay-topbar-top is consumed on :root (html), and CSS custom
	 * properties inherit down the tree — a body-scoped override never reaches html.
	 * WP uses 32px desktop / 46px at ≤782px. At ≤600px the bar is position:absolute
	 * (scrolls away); torobpay-topbar-adminbar-scroll-js adjusts --torobpay-topbar-top live.
	 */
	$adminbar_h     = is_admin_bar_showing() ? 32 : 0;
	$adminbar_mob_h = is_admin_bar_showing() ? 46 : 0;
	$top_base       = $adminbar_h + $offset;
	$top_mob        = $adminbar_mob_h + $offset;

	// --torobpay-topbar-h stays responsive (overridden by media queries on :root).
	// --torobpay-topbar-reserve = top + height, re-evaluated by the browser as
	// height changes at breakpoints.
	$root_props = array(
		array( 'prop' => '--torobpay-topbar-h', 'base' => (int) $height_r['base'], 'tablet' => $device_int( $height_r['tablet'] ), 'mobile' => $device_int( $height_r['mobile'] ), 'unit' => 'px' ),
	);

	$inline_css = '';
	if ( $embed ) {
		$inline_css .= torobpay_responsive_style( ':root', $root_props );
		$inline_css .= torobpay_responsive_style( '#torobpay-topbar', $style_props );
	} else {
		$inline_css  = ':root{--torobpay-topbar-top:' . $top_base . 'px;--torobpay-topbar-reserve:calc(var(--torobpay-topbar-top) + var(--torobpay-topbar-h));--torobpay-topbar-z:' . $z_index . ';}';
		// Adjust top for the narrower WP admin bar on mobile (only when it is shown).
		if ( is_admin_bar_showing() && $top_mob !== $top_base ) {
			$inline_css .= '@media screen and (max-width:782px){:root{--torobpay-topbar-top:' . $top_mob . 'px;}}';
		}
		$inline_css .= torobpay_responsive_style( ':root', $root_props );
		$inline_css .= torobpay_responsive_style( '#torobpay-topbar', $style_props );

		if ( 'sticky' === $position ) {
			$inline_css .= 'html{margin-top:var(--torobpay-topbar-reserve) !important;}';
		}
	}

	/*
	 * At ≤600px WP switches #wpadminbar to position:absolute (scrolls with the page).
	 * Keep the sticky topbar flush below it while scrolling; once past, drop to offset only.
	 */
	$adminbar_scroll_js = '';
	if ( ! $embed && 'sticky' === $position && is_admin_bar_showing() ) {
		$adminbar_scroll_js = sprintf(
			'( function () {' . "\n" .
			'	var mq = window.matchMedia( \'(max-width: 600px)\' );' . "\n" .
			'	var topWithBar = %1$d;' . "\n" .
			'	var topBare    = %2$d;' . "\n" .
			'	var root       = document.documentElement;' . "\n" .
			'' . "\n" .
			'	function apply() {' . "\n" .
			'		if ( ! mq.matches ) {' . "\n" .
			'			root.style.removeProperty( \'--torobpay-topbar-top\' );' . "\n" .
			'			return;' . "\n" .
			'		}' . "\n" .
			'		var y   = window.scrollY || window.pageYOffset || 0;' . "\n" .
			'		var top = Math.max( topBare, topWithBar - y );' . "\n" .
			'		root.style.setProperty( \'--torobpay-topbar-top\', top + \'px\' );' . "\n" .
			'	}' . "\n" .
			'' . "\n" .
			'	var ticking = false;' . "\n" .
			'	function schedule() {' . "\n" .
			'		if ( ticking ) {' . "\n" .
			'			return;' . "\n" .
			'		}' . "\n" .
			'		ticking = true;' . "\n" .
			'		window.requestAnimationFrame( function () {' . "\n" .
			'			ticking = false;' . "\n" .
			'			apply();' . "\n" .
			'		} );' . "\n" .
			'	}' . "\n" .
			'' . "\n" .
			'	if ( mq.addEventListener ) {' . "\n" .
			'		mq.addEventListener( \'change\', schedule );' . "\n" .
			'	} else if ( mq.addListener ) {' . "\n" .
			'		mq.addListener( schedule );' . "\n" .
			'	}' . "\n" .
			'	window.addEventListener( \'scroll\', schedule, { passive: true } );' . "\n" .
			'	window.addEventListener( \'resize\', schedule );' . "\n" .
			'	schedule();' . "\n" .
			'}() );',
			(int) $top_mob,
			(int) $offset
		);
	}

	$sticky_header_js = '';
	if ( ! $embed && 'sticky' === $position && '' !== $sticky_header_selector ) {
		$selector_json    = wp_json_encode( $sticky_header_selector );
		$sticky_header_js =
			'( function () {' . "\n" .
			'	var selector = ' . $selector_json . ';' . "\n" .
			'	if ( ! selector ) {' . "\n" .
			'		return;' . "\n" .
			'	}' . "\n" .
			'' . "\n" .
			'	// previous: saves what the element\'s inline top was BEFORE we touched it.' . "\n" .
			'	// adjusted: all elements we have currently modified, so we can restore them' . "\n" .
			'	//           even when they no longer match the selector (e.g. .onSticky removed).' . "\n" .
			'	var previous = new WeakMap();' . "\n" .
			'	var adjusted  = new Set();' . "\n" .
			'' . "\n" .
			'	function adjust( el ) {' . "\n" .
			'		if ( ! previous.has( el ) ) {' . "\n" .
			'			previous.set( el, {' . "\n" .
			'				top:      el.style.getPropertyValue( \'top\' ),' . "\n" .
			'				priority: el.style.getPropertyPriority( \'top\' )' . "\n" .
			'			} );' . "\n" .
			'		}' . "\n" .
			'		el.style.setProperty( \'top\', \'var(--torobpay-topbar-reserve)\', \'important\' );' . "\n" .
			'		adjusted.add( el );' . "\n" .
			'	}' . "\n" .
			'' . "\n" .
			'	function restore( el ) {' . "\n" .
			'		var old = previous.get( el );' . "\n" .
			'		if ( ! old ) {' . "\n" .
			'			return;' . "\n" .
			'		}' . "\n" .
			'		if ( old.top ) {' . "\n" .
			'			el.style.setProperty( \'top\', old.top, old.priority );' . "\n" .
			'		} else {' . "\n" .
			'			el.style.removeProperty( \'top\' );' . "\n" .
			'		}' . "\n" .
			'		previous.delete( el );' . "\n" .
			'		adjusted.delete( el );' . "\n" .
			'	}' . "\n" .
			'' . "\n" .
			'	function update() {' . "\n" .
			'		// First: restore any previously adjusted elements that are no longer' . "\n" .
			'		// fixed/sticky (handles selectors like .onSticky that disappear when' . "\n" .
			'		// the theme removes the class — querySelectorAll would return nothing' . "\n" .
			'		// for them, so restore() would never be called otherwise).' . "\n" .
			'		adjusted.forEach( function ( el ) {' . "\n" .
			'			if ( ! el.isConnected ) {' . "\n" .
			'				restore( el );' . "\n" .
			'				return;' . "\n" .
			'			}' . "\n" .
			'			var pos = window.getComputedStyle( el ).position;' . "\n" .
			'			if ( pos !== \'fixed\' && pos !== \'sticky\' ) {' . "\n" .
			'				restore( el );' . "\n" .
			'			}' . "\n" .
			'		} );' . "\n" .
			'' . "\n" .
			'		// Then: adjust any currently matching elements that are fixed/sticky.' . "\n" .
			'		var nodes;' . "\n" .
			'		try {' . "\n" .
			'			nodes = document.querySelectorAll( selector );' . "\n" .
			'		} catch ( e ) {' . "\n" .
			'			return;' . "\n" .
			'		}' . "\n" .
			'		Array.prototype.forEach.call( nodes, function ( el ) {' . "\n" .
			'			var pos = window.getComputedStyle( el ).position;' . "\n" .
			'			if ( pos === \'fixed\' || pos === \'sticky\' ) {' . "\n" .
			'				adjust( el );' . "\n" .
			'			}' . "\n" .
			'		} );' . "\n" .
			'	}' . "\n" .
			'' . "\n" .
			'	var ticking = false;' . "\n" .
			'	function schedule() {' . "\n" .
			'		if ( ticking ) {' . "\n" .
			'			return;' . "\n" .
			'		}' . "\n" .
			'		ticking = true;' . "\n" .
			'		window.requestAnimationFrame( function () {' . "\n" .
			'			ticking = false;' . "\n" .
			'			update();' . "\n" .
			'		} );' . "\n" .
			'	}' . "\n" .
			'' . "\n" .
			'	// MutationObserver catches themes that add/remove a sticky class' . "\n" .
			'	// (e.g. .onSticky) without a scroll event — including mid-scroll page loads' . "\n" .
			'	// where the theme applies the class during its own DOMContentLoaded handler.' . "\n" .
			'	if ( window.MutationObserver ) {' . "\n" .
			'		var observer = new MutationObserver( schedule );' . "\n" .
			'		observer.observe( document.body, { subtree: true, attributes: true, attributeFilter: [ \'class\', \'style\' ] } );' . "\n" .
			'	}' . "\n" .
			'' . "\n" .
			'	window.addEventListener( \'load\', schedule );' . "\n" .
			'	window.addEventListener( \'resize\', schedule );' . "\n" .
			'	document.addEventListener( \'scroll\', schedule, { passive: true } );' . "\n" .
			'	schedule();' . "\n" .
			'	setTimeout( schedule, 250 );' . "\n" .
			'}() );';
	}

	$logo_img = sprintf(
		'<img src="%s" alt="" class="torobpay-topbar-logo" width="%d" height="%d" />',
		$logo_url,
		$logo_size,
		$logo_size
	);

	$campaign             = null;
	$campaign_button_html = '';
	$has_campaign_slide   = false;
	if ( function_exists( 'torobpay_is_pdp_promo_topbar_active' ) && torobpay_is_pdp_promo_topbar_active() && function_exists( 'torobpay_pdp_promo_topbar_sentence_html' ) ) {
		$campaign = torobpay_get_pdp_promo_campaign();
		if ( is_array( $campaign ) ) {
			$campaign_button_html = torobpay_pdp_promo_topbar_sentence_html( $campaign );
			$has_campaign_slide   = '' !== $campaign_button_html;
		}
	}
	if ( $has_campaign_slide && function_exists( 'torobpay_pdp_promo_mark_needed' ) ) {
		torobpay_pdp_promo_mark_needed();
	}

	// Standard ticker: equal slots in $speed. Campaign track: own delays (removed on expiry).
	$count               = count( $slogans );
	$cycle               = max( 1, $speed );
	$items_html          = '';
	$campaign_items_html = '';
	$campaign_schedule   = null;
	$format_seconds      = static function ( $seconds ) {
		return rtrim( rtrim( number_format( (float) $seconds, 3, '.', '' ), '0' ), '.' );
	};
	if ( $count > 0 ) {
		foreach ( $slogans as $index => $slogan ) {
			$item_style = sprintf(
				'animation-duration:%ss;animation-delay:%ss;',
				$format_seconds( $cycle ),
				$format_seconds( ( $cycle * $index ) / $count )
			);
			$item_class = 'torobpay-topbar-ticker-item';
			// Credit line is slot 0 on ticker; Spread uses its own solo.
			if ( 0 === $index && 'spread' !== $animation ) {
				$item_class .= ' torobpay-topbar-ticker-item--confetti torobpay-topbar-confetti-target';
			}
			$items_html .= sprintf(
				'<div class="%1$s" style="%2$s">%3$s</div>',
				esc_attr( $item_class ),
				esc_attr( $item_style ),
				esc_html( $slogan )
			) . "\n";
		}
	}
	if ( $has_campaign_slide && 'spread' !== $animation ) {
		$campaign_schedule = torobpay_topbar_campaign_beat_schedule(
			torobpay_topbar_campaign_ticker_beats( $credit_line ),
			$cycle
		);
		foreach ( $campaign_schedule['schedule'] as $row ) {
			$beat       = $row['beat'];
			$item_class = 'torobpay-topbar-ticker-item';
			if ( isset( $beat['type'] ) && 'campaign' === $beat['type'] ) {
				$item_class .= ' torobpay-topbar-ticker-item--campaign';
				$inner       = $campaign_button_html;
			} else {
				if ( ! empty( $beat['confetti'] ) ) {
					$item_class .= ' torobpay-topbar-ticker-item--confetti torobpay-topbar-confetti-target';
				}
				$inner = esc_html( isset( $beat['text'] ) ? $beat['text'] : '' );
			}
			$campaign_items_html .= sprintf(
				'<div class="%1$s" style="%2$s">%3$s</div>',
				esc_attr( $item_class ),
				esc_attr(
					sprintf(
						'animation-duration:%ss;animation-delay:%ss;',
						$format_seconds( $campaign_schedule['timing']['cycle'] ),
						$format_seconds( $row['delay'] )
					)
				),
				$inner
			) . "\n";
		}
	}

	$topbar_classes = 'torobpay-topbar--anim-' . $animation;
	if ( $has_campaign_slide ) {
		$topbar_classes .= ' torobpay-topbar--has-campaign';
	}
	if ( $count > 3 && 'spread' !== $animation ) {
		$topbar_classes .= ' torobpay-topbar--ticker-count-' . (int) $count;
	}
	if ( 'spread' === $animation && '' !== $custom_sentence ) {
		$topbar_classes .= ' torobpay-topbar--spread-has-custom';
	}
	if ( 'spread' === $animation && $has_campaign_slide ) {
		$topbar_classes .= ' torobpay-topbar--spread-has-campaign';
	}
	if ( $embed ) {
		$topbar_classes .= ' torobpay-topbar--embed';
	} elseif ( 'sticky' === $position ) {
		$topbar_classes .= ' torobpay-topbar--sticky';
	}
	if ( 'none' !== $bg_animation ) {
		$topbar_classes .= ' torobpay-topbar--has-bg torobpay-topbar--bg-' . $bg_animation;
		// Canvas-driven modes need their script; aurora is CSS-only.
		if ( in_array( $bg_animation, array( 'perlin', 'rain', 'float' ), true ) ) {
			torobpay_enqueue_topbar_bg_script();
		}
	}
	if ( is_array( $campaign_schedule ) && $campaign_schedule['timing']['cycle'] > 0 ) {
		$normal_pct   = ( $campaign_schedule['timing']['normal_slot'] / $campaign_schedule['timing']['cycle'] ) * 100;
		$campaign_pct = ( $campaign_schedule['timing']['campaign_slot'] / $campaign_schedule['timing']['cycle'] ) * 100;
		$inline_css  .= torobpay_topbar_campaign_ticker_css( $animation, $normal_pct, $campaign_pct, $campaign_schedule['timing']['cycle'] );
	}

	// $inline_css uses sanitized values: integers, a font-weight, and campaign keyframe rules.
	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';
	wp_register_style( 'torobpay-topbar-inline', false, array( 'torobpay-topbar' ), $version );
	wp_enqueue_style( 'torobpay-topbar-inline' );
	wp_add_inline_style( 'torobpay-topbar-inline', wp_strip_all_tags( $inline_css ) );
	wp_print_styles( 'torobpay-topbar-inline' );

	if ( '' !== $adminbar_scroll_js ) {
		wp_register_script( 'torobpay-topbar-adminbar-scroll', '', array(), $version, true );
		wp_enqueue_script( 'torobpay-topbar-adminbar-scroll' );
		wp_add_inline_script( 'torobpay-topbar-adminbar-scroll', $adminbar_scroll_js );
	}
	if ( '' !== $sticky_header_js ) {
		wp_register_script( 'torobpay-topbar-sticky-header', '', array(), $version, true );
		wp_enqueue_script( 'torobpay-topbar-sticky-header' );
		wp_add_inline_script( 'torobpay-topbar-sticky-header', $sticky_header_js );
	}
	$bg_density_attr     = '';
	$bg_speed_attr       = '';
	$bg_custom_icon_attr = '';
	if ( 'none' !== $bg_animation ) {
		$bg_density_attr = $bg_density;
		$bg_speed_attr   = (string) $bg_speed;
		if ( in_array( $bg_animation, array( 'perlin', 'rain', 'float' ), true ) && '' !== $bg_custom_icon ) {
			$bg_custom_icon_attr = $bg_custom_icon;
		}
	}
	echo '<div id="torobpay-topbar" class="' . esc_attr( $topbar_classes ) . '" data-bg-anim="' . esc_attr( $bg_animation ) . '"';
	if ( '' !== $bg_density_attr ) {
		echo ' data-bg-density="' . esc_attr( $bg_density_attr ) . '" data-bg-speed="' . esc_attr( $bg_speed_attr ) . '"';
	}
	if ( '' !== $bg_custom_icon_attr ) {
		echo ' data-bg-custom-icon="' . esc_attr( $bg_custom_icon_attr ) . '"';
	}
	echo '>';

	// Decorative animated backdrop (sits behind the slogans; aria-hidden + inert).
	if ( 'none' !== $bg_animation ) {
		echo '<div class="torobpay-topbar-bg" aria-hidden="true">';
		if ( 'aurora' === $bg_animation ) {
			echo '<span class="torobpay-topbar-bg-blob"></span><span class="torobpay-topbar-bg-blob"></span><span class="torobpay-topbar-bg-blob"></span>';
		}
		echo '</div>';
	}

	// Confetti host for the credit line (above backdrop, behind text).
	echo '<div class="torobpay-topbar-confetti" aria-hidden="true"></div>';
	torobpay_enqueue_topbar_confetti_script();

	echo '<div class="torobpay-topbar-inner">';

	if ( 'spread' === $animation ) {
		$standard_style = sprintf( '--torobpay-tb-spread-duration:%ss;', torobpay_topbar_css_num( $cycle ) );
		// Slightly longer cycle when the merchant custom sentence follows the three slots.
		$campaign_seconds = '' !== $custom_sentence ? $cycle * 1.15 : $cycle;
		$campaign_style   = sprintf( '--torobpay-tb-spread-duration:%ss;', torobpay_topbar_css_num( $campaign_seconds ) );
		if ( $has_campaign_slide && is_array( $campaign ) ) {
			$spread_parts  = torobpay_topbar_campaign_spread_parts( $campaign );
			$spread_button = function_exists( 'torobpay_pdp_promo_topbar_spread_button_html' ) ? torobpay_pdp_promo_topbar_spread_button_html() : '';
			echo '<div class="torobpay-topbar-campaign-seq">';
			echo torobpay_topbar_spread_stage_html(
				array(
					'logo_img'     => $logo_img,
					'title'        => $spread_parts['title'],
					'center'       => $spread_parts['center'],
					'left'         => $spread_button,
					'left_is_html' => true,
					'credit'       => $credit_line,
					'custom'       => $custom_sentence,
					'style'        => $campaign_style,
				)
			);
			echo '</div>';
			echo '<div class="torobpay-topbar-standard">';
		}
		echo torobpay_topbar_spread_stage_html(
			array(
				'logo_img' => $logo_img,
				'title'    => $slogans[0],
				'center'   => $slogans[1],
				'left'     => $slogans[2],
				'credit'   => $credit_line,
				'custom'   => $custom_sentence,
				'style'    => $standard_style,
			)
		);
		if ( $has_campaign_slide && is_array( $campaign ) ) {
			echo '</div>';
		}
		$spread_measure_js =
		'( function () {' . "\n" .
		'	var bar = document.getElementById( \'torobpay-topbar\' );' . "\n" .
		'	if ( ! bar ) {' . "\n" .
		'		return;' . "\n" .
		'	}' . "\n" .
		'	var mobileMq = window.matchMedia( \'(max-width: 849px)\' );' . "\n" .
		'' . "\n" .
		'	function currentStage() {' . "\n" .
		'		var campaign = bar.querySelector( \'.torobpay-topbar-campaign-seq .torobpay-topbar-spread-stage\' );' . "\n" .
		'		if ( campaign && bar.classList.contains( \'torobpay-topbar--has-campaign\' ) ) {' . "\n" .
		'			return campaign;' . "\n" .
		'		}' . "\n" .
		'		return bar.querySelector( \'.torobpay-topbar-standard .torobpay-topbar-spread-stage\' ) || bar.querySelector( \'.torobpay-topbar-spread-stage\' );' . "\n" .
		'	}' . "\n" .
		'' . "\n" .
		'	function measure() {' . "\n" .
		'		var stage = currentStage();' . "\n" .
		'		if ( ! stage ) {' . "\n" .
		'			return;' . "\n" .
		'		}' . "\n" .
		'		var centerSlot = stage.querySelector( \'.torobpay-topbar-spread-slot--center\' );' . "\n" .
		'		var rightGroup = stage.querySelector( \'.torobpay-topbar-spread-group--right\' );' . "\n" .
		'		var leftGroup  = stage.querySelector( \'.torobpay-topbar-spread-group--left\' );' . "\n" .
		'		if ( ! centerSlot || ! rightGroup || ! leftGroup ) {' . "\n" .
		'			return;' . "\n" .
		'		}' . "\n" .
		'' . "\n" .
		'		var title  = rightGroup.querySelector( \'.torobpay-topbar-spread-text--title\' );' . "\n" .
		'		var fee    = centerSlot.querySelector( \'.torobpay-topbar-spread-text--fee\' );' . "\n" .
		'		var middle = leftGroup.querySelector( \'.torobpay-topbar-spread-text--middle\' );' . "\n" .
		'' . "\n" .
		'		if ( ! title || ! fee || ! middle ) {' . "\n" .
		'			return;' . "\n" .
		'		}' . "\n" .
		'' . "\n" .
		'		stage.classList.add( \'torobpay-topbar-spread-measuring\' );' . "\n" .
		'' . "\n" .
		'		if ( mobileMq.matches ) {' . "\n" .
		'			stage.classList.remove( \'torobpay-topbar-spread-measuring\' );' . "\n" .
		'			return;' . "\n" .
		'		}' . "\n" .
		'' . "\n" .
		'		var centerRect  = centerSlot.getBoundingClientRect();' . "\n" .
		'		var centerX     = centerRect.left + ( centerRect.width / 2 );' . "\n" .
		'		var titleRect   = title.getBoundingClientRect();' . "\n" .
		'		var middleRect  = middle.getBoundingClientRect();' . "\n" .
		'		var titleCenter = titleRect.left + ( titleRect.width / 2 );' . "\n" .
		'		var middleCenter = middleRect.left + ( middleRect.width / 2 );' . "\n" .
		'' . "\n" .
		'		stage.style.setProperty( \'--torobpay-tb-spread-pull-right\', Math.max( 0, Math.round( titleCenter - centerX ) ) + \'px\' );' . "\n" .
		'		stage.style.setProperty( \'--torobpay-tb-spread-pull-left\', Math.max( 0, Math.round( centerX - middleCenter ) ) + \'px\' );' . "\n" .
		'' . "\n" .
		'		stage.classList.remove( \'torobpay-topbar-spread-measuring\' );' . "\n" .
		'	}' . "\n" .
		'' . "\n" .
		'	var ticking = false;' . "\n" .
		'	function schedule() {' . "\n" .
		'		if ( ticking ) {' . "\n" .
		'			return;' . "\n" .
		'		}' . "\n" .
		'		ticking = true;' . "\n" .
		'		window.requestAnimationFrame( function () {' . "\n" .
		'			ticking = false;' . "\n" .
		'			measure();' . "\n" .
		'		} );' . "\n" .
		'	}' . "\n" .
		'' . "\n" .
		'	window.addEventListener( \'load\', schedule );' . "\n" .
		'	window.addEventListener( \'resize\', schedule );' . "\n" .
		'	if ( mobileMq.addEventListener ) {' . "\n" .
		'		mobileMq.addEventListener( \'change\', schedule );' . "\n" .
		'	} else if ( mobileMq.addListener ) {' . "\n" .
		'		mobileMq.addListener( schedule );' . "\n" .
		'	}' . "\n" .
		'	if ( document.fonts && document.fonts.ready ) {' . "\n" .
		'		document.fonts.ready.then( schedule );' . "\n" .
		'	}' . "\n" .
		'	if ( window.MutationObserver ) {' . "\n" .
		'		new MutationObserver( schedule ).observe( bar, { attributes: true, attributeFilter: [ \'class\' ] } );' . "\n" .
		'	}' . "\n" .
		'	schedule();' . "\n" .
		'	window.setTimeout( schedule, 300 );' . "\n" .
		'}() );';
		wp_register_script( 'torobpay-topbar-spread-measure', '', array(), $version, true );
		wp_enqueue_script( 'torobpay-topbar-spread-measure' );
		wp_add_inline_script( 'torobpay-topbar-spread-measure', $spread_measure_js );
	} else {
		$echo_ticker = static function ( $html ) use ( $logo_img ) {
			$allowed = torobpay_kses_allowed_html();
			echo '<div class="torobpay-topbar-logo-wrap">' . wp_kses( $logo_img, $allowed ) . '</div>';
			echo '<div class="torobpay-topbar-ticker-wrap">';
			echo '<div class="torobpay-topbar-ticker-track">';
			echo wp_kses( $html, $allowed );
			echo '</div>';
			echo '</div>';
			echo '<div class="torobpay-topbar-logo-wrap" aria-hidden="true">' . wp_kses( $logo_img, $allowed ) . '</div>';
		};
		if ( $has_campaign_slide && '' !== $campaign_items_html ) {
			echo '<div class="torobpay-topbar-campaign-seq">';
			$echo_ticker( $campaign_items_html );
			echo '</div>';
			echo '<div class="torobpay-topbar-standard">';
			$echo_ticker( $items_html );
			echo '</div>';
		} else {
			$echo_ticker( $items_html );
		}
	}

	echo '</div>';
	echo '</div>';

	return ob_get_clean();
}

/**
 * One Spread stage (credit, three slots, optional custom).
 *
 * @param array $args logo_img, title, center, left, left_is_html, credit, custom, style.
 * @return string Escaped HTML.
 */
function torobpay_topbar_spread_stage_html( $args ) {
	$allowed   = torobpay_kses_allowed_html();
	$logo      = isset( $args['logo_img'] ) ? $args['logo_img'] : '';
	$left      = isset( $args['left'] ) ? $args['left'] : '';
	$custom    = isset( $args['custom'] ) ? (string) $args['custom'] : '';
	$style     = isset( $args['style'] ) ? (string) $args['style'] : '';
	$left_html = ! empty( $args['left_is_html'] ) ? wp_kses( $left, $allowed ) : esc_html( $left );

	$html  = '<div class="torobpay-topbar-spread-stage" style="' . esc_attr( $style ) . '">';
	$html .= '<span class="torobpay-topbar-spread-solo torobpay-topbar-spread-solo--credit torobpay-topbar-confetti-target">';
	$html .= '<span class="torobpay-topbar-spread-solo-logo" aria-hidden="true">' . wp_kses( $logo, $allowed ) . '</span>';
	$html .= '<span class="torobpay-topbar-spread-solo-text">' . esc_html( isset( $args['credit'] ) ? $args['credit'] : '' ) . '</span>';
	$html .= '<span class="torobpay-topbar-spread-solo-logo" aria-hidden="true">' . wp_kses( $logo, $allowed ) . '</span>';
	$html .= '</span>';
	$html .= '<div class="torobpay-topbar-spread-slot torobpay-topbar-spread-slot--right">';
	$html .= '<div class="torobpay-topbar-spread-group torobpay-topbar-spread-group--right">';
	$html .= '<div class="torobpay-topbar-spread-logo torobpay-topbar-spread-logo--end">' . wp_kses( $logo, $allowed ) . '</div>';
	$html .= '<span class="torobpay-topbar-spread-text torobpay-topbar-spread-text--title"><strong>' . esc_html( isset( $args['title'] ) ? $args['title'] : '' ) . '</strong></span>';
	$html .= '</div></div>';
	$html .= '<div class="torobpay-topbar-spread-slot torobpay-topbar-spread-slot--center">';
	$html .= '<span class="torobpay-topbar-spread-text torobpay-topbar-spread-text--fee">' . esc_html( isset( $args['center'] ) ? $args['center'] : '' ) . '</span>';
	$html .= '</div>';
	$html .= '<div class="torobpay-topbar-spread-slot torobpay-topbar-spread-slot--left">';
	$html .= '<div class="torobpay-topbar-spread-group torobpay-topbar-spread-group--left">';
	$html .= '<span class="torobpay-topbar-spread-text torobpay-topbar-spread-text--middle">' . $left_html . '</span>';
	$html .= '<div class="torobpay-topbar-spread-logo torobpay-topbar-spread-logo--start">' . wp_kses( $logo, $allowed ) . '</div>';
	$html .= '</div></div>';
	if ( '' !== $custom ) {
		$html .= '<span class="torobpay-topbar-spread-solo torobpay-topbar-spread-solo--custom">';
		$html .= '<span class="torobpay-topbar-spread-solo-logo" aria-hidden="true">' . wp_kses( $logo, $allowed ) . '</span>';
		$html .= '<span class="torobpay-topbar-spread-solo-text">' . esc_html( $custom ) . '</span>';
		$html .= '<span class="torobpay-topbar-spread-solo-logo" aria-hidden="true">' . wp_kses( $logo, $allowed ) . '</span>';
		$html .= '</span>';
	}
	$html .= '</div>';

	return $html;
}

/**
 * Campaign-track ticker beats (campaign, installments, credit).
 *
 * @param string $credit_line Credit sentence.
 * @return array<int, array<string, mixed>>
 */
function torobpay_topbar_campaign_ticker_beats( $credit_line ) {
	return array(
		array(
			'type' => 'campaign',
		),
		array(
			'type' => 'text',
			'text' => __( 'Buy in 4 installments with TorobPay', 'torobpay-for-woocommerce' ),
		),
		array(
			'type'     => 'text',
			'text'     => (string) $credit_line,
			'confetti' => true,
		),
	);
}

/**
 * Campaign Spread slot copy (installments title, campaign center, button label).
 *
 * @param array $campaign Sanitized campaign.
 * @return array{title:string,center:string,button:string}
 */
function torobpay_topbar_campaign_spread_parts( $campaign ) {
	$center = is_array( $campaign ) && function_exists( 'torobpay_pdp_promo_topbar_title' )
		? (string) torobpay_pdp_promo_topbar_title( $campaign )
		: '';

	return array(
		'title'  => __( 'Buy in 4 installments with TorobPay', 'torobpay-for-woocommerce' ),
		'center' => $center,
		'button' => __( 'Get discount code', 'torobpay-for-woocommerce' ),
	);
}

/**
 * Campaign ticker delays (campaign beat = 2× a text line).
 *
 * @param array<int, array<string, mixed>> $beats From torobpay_topbar_campaign_ticker_beats().
 * @param float                             $speed Text-line cycle length in seconds.
 * @return array{timing:array{normal_slot:float,campaign_slot:float,cycle:float},schedule:array<int, array{beat:array,delay:float,slot:float}>}
 */
function torobpay_topbar_campaign_beat_schedule( $beats, $speed ) {
	$text_count = 0;
	foreach ( $beats as $beat ) {
		if ( ! isset( $beat['type'] ) || 'campaign' !== $beat['type'] ) {
			++$text_count;
		}
	}
	$timing   = torobpay_topbar_campaign_timing( $text_count, $speed );
	$elapsed  = 0.0;
	$schedule = array();
	foreach ( $beats as $beat ) {
		$slot       = ( isset( $beat['type'] ) && 'campaign' === $beat['type'] ) ? $timing['campaign_slot'] : $timing['normal_slot'];
		$schedule[] = array(
			'beat'  => $beat,
			'delay' => $elapsed,
			'slot'  => $slot,
		);
		$elapsed   += $slot;
	}
	$timing['cycle'] = $elapsed;

	return array(
		'timing'   => $timing,
		'schedule' => $schedule,
	);
}

/**
 * Format a CSS number without trailing zeros.
 *
 * @param float $value Number.
 * @return string
 */
function torobpay_topbar_css_num( $value ) {
	$text = number_format( (float) $value, 3, '.', '' );
	return rtrim( rtrim( $text, '0' ), '.' );
}

/**
 * Campaign slot timing: normal = speed/count; campaign = 2× normal; cycle grows.
 *
 * @param int   $slogan_count Text beats on the bar (≥ 1).
 * @param float $speed        Base cycle length in seconds.
 * @return array{normal_slot:float,campaign_slot:float,cycle:float}
 */
function torobpay_topbar_campaign_timing( $slogan_count, $speed ) {
	$slogan_count = max( 1, (int) $slogan_count );
	$speed        = max( 1, (float) $speed );
	$normal       = $speed / $slogan_count;
	$campaign     = $normal * 2;

	return array(
		'normal_slot'   => $normal,
		'campaign_slot' => $campaign,
		'cycle'         => $speed + $campaign,
	);
}

/**
 * Keyframe stops for one ticker motion inside a slot that is $slot_pct of the cycle.
 *
 * Phase-in and phase-out stay about 0.4s. A long campaign hold does not stretch them.
 *
 * @param string $animation     Animation slug.
 * @param float  $slot_pct      Visible window as a percentage of the full cycle.
 * @param float  $cycle_seconds Full ticker cycle in seconds.
 * @return array<int, array{at:float,decl:string}>
 */
function torobpay_topbar_campaign_motion_stops( $animation, $slot_pct, $cycle_seconds ) {
	$motions = array(
		'slide-up'   => array(
			'in'     => 0.12,
			'out'    => 0.88,
			'frames' => array(
				array( 0, 'opacity:0;transform:translateY(100%);pointer-events:none;visibility:hidden' ),
				array( 0.12, 'opacity:1;transform:translateY(0);pointer-events:auto;visibility:visible' ),
				array( 0.88, 'opacity:1;transform:translateY(0);pointer-events:auto;visibility:visible' ),
				array( 1, 'opacity:0;transform:translateY(-100%);pointer-events:none;visibility:hidden' ),
			),
		),
		'slide-down' => array(
			'in'     => 0.12,
			'out'    => 0.88,
			'frames' => array(
				array( 0, 'opacity:0;transform:translateY(-100%);pointer-events:none;visibility:hidden' ),
				array( 0.12, 'opacity:1;transform:translateY(0);pointer-events:auto;visibility:visible' ),
				array( 0.88, 'opacity:1;transform:translateY(0);pointer-events:auto;visibility:visible' ),
				array( 1, 'opacity:0;transform:translateY(100%);pointer-events:none;visibility:hidden' ),
			),
		),
		'fade'       => array(
			'in'     => 0.2,
			'out'    => 0.8,
			'frames' => array(
				array( 0, 'opacity:0;transform:translateY(20%);pointer-events:none;visibility:hidden' ),
				array( 0.2, 'opacity:1;transform:translateY(0);pointer-events:auto;visibility:visible' ),
				array( 0.8, 'opacity:1;transform:translateY(0);pointer-events:auto;visibility:visible' ),
				array( 1, 'opacity:0;transform:translateY(-20%);pointer-events:none;visibility:hidden' ),
			),
		),
		'flip'       => array(
			'in'     => 0.16,
			'out'    => 0.84,
			'frames' => array(
				array( 0, 'opacity:0;transform:rotateX(-90deg);pointer-events:none;visibility:hidden' ),
				array( 0.16, 'opacity:1;transform:rotateX(0deg);pointer-events:auto;visibility:visible' ),
				array( 0.84, 'opacity:1;transform:rotateX(0deg);pointer-events:auto;visibility:visible' ),
				array( 1, 'opacity:0;transform:rotateX(90deg);pointer-events:none;visibility:hidden' ),
			),
		),
		'zoom'       => array(
			'in'     => 0.16,
			'out'    => 0.84,
			'frames' => array(
				array( 0, 'opacity:0;transform:scale(0.4);pointer-events:none;visibility:hidden' ),
				array( 0.16, 'opacity:1;transform:scale(1);pointer-events:auto;visibility:visible' ),
				array( 0.84, 'opacity:1;transform:scale(1);pointer-events:auto;visibility:visible' ),
				array( 1, 'opacity:0;transform:scale(1.6);pointer-events:none;visibility:hidden' ),
			),
		),
		'blur'       => array(
			'in'     => 0.2,
			'out'    => 0.8,
			'frames' => array(
				array( 0, 'opacity:0;filter:blur(8px);transform:scale(1.08);pointer-events:none;visibility:hidden' ),
				array( 0.2, 'opacity:1;filter:blur(0);transform:scale(1);pointer-events:auto;visibility:visible' ),
				array( 0.8, 'opacity:1;filter:blur(0);transform:scale(1);pointer-events:auto;visibility:visible' ),
				array( 1, 'opacity:0;filter:blur(8px);transform:scale(0.96);pointer-events:none;visibility:hidden' ),
			),
		),
		'bounce'     => array(
			'in'     => 0.56,
			'out'    => 0.88,
			'frames' => array(
				array( 0, 'opacity:0;transform:translateY(-130%);pointer-events:none;visibility:hidden' ),
				array( 0.12, 'opacity:1;pointer-events:auto;visibility:visible' ),
				array( 0.28, 'transform:translateY(12%)' ),
				array( 0.44, 'transform:translateY(-6%)' ),
				array( 0.56, 'transform:translateY(0)' ),
				array( 0.88, 'opacity:1;transform:translateY(0);pointer-events:auto;visibility:visible' ),
				array( 1, 'opacity:0;transform:translateY(130%);pointer-events:none;visibility:hidden' ),
			),
		),
	);

	$motion   = isset( $motions[ $animation ] ) ? $motions[ $animation ] : $motions['slide-up'];
	$slot_pct = (float) $slot_pct;
	$fade_pct = ( 0.4 / max( 0.001, (float) $cycle_seconds ) ) * 100;
	$fade_pct = min( $fade_pct, $slot_pct * 0.2 );
	$in       = (float) $motion['in'];
	$out      = (float) $motion['out'];
	$hold     = max( 0.001, $out - $in );
	$exit     = max( 0.001, 1 - $out );
	$stops    = array();
	$end_decl = 'opacity:0;pointer-events:none';
	foreach ( $motion['frames'] as $frame ) {
		$t        = (float) $frame[0];
		$end_decl = $frame[1];
		if ( $t <= 0 ) {
			$at = 0;
		} elseif ( $t >= 1 ) {
			$at = $slot_pct;
		} elseif ( $t <= $in ) {
			$at = ( $t / $in ) * $fade_pct;
		} elseif ( $t >= $out ) {
			$at = ( $slot_pct - $fade_pct ) + ( ( $t - $out ) / $exit ) * $fade_pct;
		} else {
			$at = $fade_pct + ( ( $t - $in ) / $hold ) * ( $slot_pct - ( 2 * $fade_pct ) );
		}
		$stops[] = array(
			'at'   => $at,
			'decl' => $end_decl,
		);
	}
	$stops[] = array(
		'at'   => 100,
		'decl' => $end_decl,
	);

	return $stops;
}

/**
 * Inline keyframes for unequal campaign ticker slots.
 *
 * @param string $animation     Active ticker animation.
 * @param float  $normal_pct    Normal slot % of cycle.
 * @param float  $campaign_pct  Campaign slot % of cycle.
 * @param float  $cycle_seconds Full ticker cycle in seconds.
 * @return string CSS.
 */
function torobpay_topbar_campaign_ticker_css( $animation, $normal_pct, $campaign_pct, $cycle_seconds ) {
	$animation = sanitize_key( (string) $animation );
	$allowed   = array( 'slide-up', 'slide-down', 'fade', 'flip', 'zoom', 'blur', 'bounce' );
	if ( ! in_array( $animation, $allowed, true ) ) {
		$animation = 'slide-up';
	}

	$css = '';
	foreach ( array(
		'torobpay-tb-campaign-slot' => $normal_pct,
		'torobpay-tb-campaign-long' => $campaign_pct,
	) as $name => $slot_pct ) {
		$css .= '@keyframes ' . $name . '{';
		foreach ( torobpay_topbar_campaign_motion_stops( $animation, $slot_pct, $cycle_seconds ) as $stop ) {
			$css .= torobpay_topbar_css_num( $stop['at'] ) . '%{' . $stop['decl'] . '}';
		}
		$css .= '}';
	}

	$scope = '#torobpay-topbar.torobpay-topbar--has-campaign.torobpay-topbar--anim-' . $animation;
	$css  .= $scope . ' .torobpay-topbar-ticker-item{animation-name:torobpay-tb-campaign-slot;}';
	$css  .= $scope . ' .torobpay-topbar-ticker-item--campaign{animation-name:torobpay-tb-campaign-long;}';

	return $css;
}

/**
 * Enqueue the topbar stylesheet (once per page load).
 */
function torobpay_enqueue_topbar_styles() {
	static $enqueued = false;
	if ( $enqueued ) {
		return;
	}
	$enqueued = true;

	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';
	wp_enqueue_style(
		'torobpay-topbar',
		plugins_url( 'assets/css/topbar.css', TOROBPAY_PLUGIN_FILE ),
		array(),
		$version
	);
}

/**
 * Enqueue the topbar confetti burst.
 *
 * On the topbar the script finds .torobpay-topbar-confetti-target and fills
 * .torobpay-topbar-confetti. Ticker and spread both mark the credit line.
 */
function torobpay_enqueue_topbar_confetti_script() {
	static $enqueued = false;
	if ( $enqueued ) {
		return;
	}
	$enqueued = true;

	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';
	wp_enqueue_script(
		'torobpay-topbar-confetti',
		plugins_url( 'assets/js/topbar-confetti.js', TOROBPAY_PLUGIN_FILE ),
		array(),
		$version,
		true
	);
}

/**
 * Enqueue the topbar background-animation engine (canvas modes only).
 *
 * Loaded in the footer; reads its configuration from the #torobpay-topbar
 * element's data attributes, so no inline data is required.
 */
function torobpay_enqueue_topbar_bg_script() {
	static $enqueued = false;
	if ( $enqueued ) {
		return;
	}
	$enqueued = true;

	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';
	wp_enqueue_script(
		'torobpay-topbar-bg',
		plugins_url( 'assets/js/topbar-background.js', TOROBPAY_PLUGIN_FILE ),
		array(),
		$version,
		true
	);
}
