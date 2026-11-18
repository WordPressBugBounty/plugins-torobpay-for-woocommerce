<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TorobPay PDP Widget - Unified Controller
 *
 * Single entry point for rendering the widget on product pages.
 * Handles both simple and variable products with consistent placement.
 */

// PDP widget: register hook only when credentials are present in settings (no network call).
// Full bearer-token auth is checked lazily inside torobpay_render_pdp_widget() itself.
if ( function_exists( 'torobpay_is_configured' ) && torobpay_is_configured() ) {
	$torobpay_widget_hook     = torobpay_get_widget_setting( 'display_hook' );
	$torobpay_widget_priority = (int) torobpay_get_widget_setting( 'display_priority' );
	if ( ! empty( $torobpay_widget_hook ) ) {
		add_action( $torobpay_widget_hook, 'torobpay_render_pdp_widget', $torobpay_widget_priority );
	}
	add_action( 'elementor/widgets/widgets_registered', 'torobpay_register_elementor_compatibility' );
}

// PLP badge and marquee: determine rendering mode from settings alone (no network call).
// Auth (bearer token) is checked lazily inside each callback.
$torobpay_badge_compat_mode   = torobpay_get_badge_setting( 'compatibility_mode' ) === 'yes';
$torobpay_marquee_compat_mode = function_exists( 'torobpay_marquee_compatibility_mode' ) && torobpay_marquee_compatibility_mode() === 'yes';

// JetEngine / JetWooBuilder (Crocoblock) render product grids from custom
// templates that bypass the WooCommerce loop hooks, so the server-side badge
// never prints there. When such a builder is active we auto-enable the
// JavaScript badge-injection fallback site-wide, in addition to the normal
// hook. The injector de-dupes against any badge the hook already printed, so
// products are never badged twice.
$torobpay_jetengine_active = function_exists( 'torobpay_is_jetengine_active' ) && torobpay_is_jetengine_active();

if ( $torobpay_badge_compat_mode || $torobpay_marquee_compat_mode || $torobpay_jetengine_active ) {
	// Use JavaScript injection when a compatibility mode is enabled or a builder
	// that bypasses the loop hooks (JetEngine) is detected.
	add_action( 'wp_enqueue_scripts', 'torobpay_enqueue_badge_compatibility_script' );
}

if ( ! $torobpay_badge_compat_mode ) {
	// Left on alongside the JetEngine fallback: the JS injector skips any card
	// that already carries a server-side badge, so products are never doubled.
	add_action( 'woocommerce_before_shop_loop_item', 'torobpay_product_list_badge', 10 );
}

/**
 * Build TorobPay widget HTML (reusable)
 * Args:
 * - variation_id (int) optional, defaults 0
 * - show_price (bool) when true shows installment price line, otherwise shows eligibility message
 * - formatted_price (string) price string already formatted with wc_price (required if show_price=true)
 * - logo (string) optional, defaults to torobpay_get_pdp_logo()
 */
function torobpay_get_widget_html( $args = [] ) {
	$defaults = [
		'variation_id'    => 0,
		'show_price'      => false,
		'formatted_price' => '',
		'logo'            => torobpay_get_pdp_logo(),
	];
	$args     = wp_parse_args( $args, $defaults );

	$variation_id    = intval( $args['variation_id'] );
	$logo            = esc_url( $args['logo'] );
	$show_price      = (bool) $args['show_price'];
	$formatted_price = $args['formatted_price'];

	$logo_animation          = torobpay_get_widget_setting( 'logo_animation' );
	$layout                  = torobpay_get_widget_setting( 'layout' );
	$widget_status_indicator = '';
	$promo_html              = function_exists( 'torobpay_get_pdp_promo_widget_html' )
		? torobpay_get_pdp_promo_widget_html()
		: '';

	$promo_after  = '';
	$root_classes = [ 'torobpay-widget', 'torobpay-widget--' . $layout ];
	if ( '' !== $promo_html ) {
		$root_classes[] = 'has-torobpay-promo';
		if ( function_exists( 'torobpay_pdp_promo_mark_needed' ) ) {
			torobpay_pdp_promo_mark_needed();
		}
		if ( 'marquee' === torobpay_get_pdp_promo_style() ) {
			$root_classes[] = 'torobpay-promo-marquee';
			$promo_after    = $promo_html;
			$promo_html     = '';
		}
	}
	$media_classes = [ 'torobpay-widget-media' ];
	$image_width   = 60;
	$image_height  = 60;

	$image_classes = [ 'torobpay-widget-image' ];

	$valid_image_animations = [ 'pulse', 'wobble', 'bounce', 'ping' ];
	if ( in_array( $logo_animation, $valid_image_animations, true ) ) {
		$image_classes[] = 'torobpay-logo-' . $logo_animation;
	} elseif ( $logo_animation === 'light-sweep' ) {
		$media_classes[] = 'torobpay-logo-light-sweep';
	} elseif ( $logo_animation === 'status-dot' ) {
		$widget_status_indicator = '<div class="torobpay-widget-status-indicator"><span class="torobpay-status-heartbeat"></span><span class="torobpay-status-dot"></span></div>';
	}

	$root_class_attr  = esc_attr( implode( ' ', array_map( 'sanitize_html_class', $root_classes ) ) );
	$media_class_attr = esc_attr( implode( ' ', array_map( 'sanitize_html_class', $media_classes ) ) );
	$image_class_attr = esc_attr( implode( ' ', array_map( 'sanitize_html_class', $image_classes ) ) );

	if ( $show_price && ! empty( $formatted_price ) ) {
		$title = sprintf(
			/* translators: %s: formatted installment price HTML from wc_price() */
			__( 'Each installment with TorobPay: %s', 'torobpay-for-woocommerce' ),
			wp_kses( $formatted_price, torobpay_kses_allowed_html() )
		);
	} else {
		$title = esc_html__( 'Installments based on TorobPay credit', 'torobpay-for-woocommerce' );
	}

	return sprintf(
		'<div class="%1$s" data-variation-id="%2$d">%3$s'
		. '<div class="%4$s"><img src="%5$s" alt="%6$s" class="%7$s" width="%8$d" height="%9$d" /></div>'
		. '<div class="torobpay-widget-content">'
			. '<div class="torobpay-widget-title">%10$s</div>'
			. '<div class="torobpay-widget-description">%11$s</div>'
			. '%12$s'
		. '</div>'
		. '%13$s'
		. '</div>',
		$root_class_attr,
		$variation_id,
		$widget_status_indicator,
		$media_class_attr,
		esc_url( $logo ),
		esc_attr__( 'TorobPay', 'torobpay-for-woocommerce' ),
		$image_class_attr,
		$image_width,
		$image_height,
		$title,
		esc_html__( '4 installments. No fee, checks, or guarantor.', 'torobpay-for-woocommerce' ),
		$promo_html,
		$promo_after
	);
}

add_action( 'wp_ajax_torobpay_get_variation_widget', 'torobpay_ajax_get_variation_widget' );
add_action( 'wp_ajax_nopriv_torobpay_get_variation_widget', 'torobpay_ajax_get_variation_widget' );

function torobpay_ajax_get_variation_widget() {
	check_ajax_referer( 'torobpay_variation_widget', 'nonce' );

	if ( empty( $_POST['product_id'] ) ) {
		wp_send_json_error( array( 'message' => 'missing_params' ), 400 );
	}

	$product_id   = absint( wp_unslash( $_POST['product_id'] ) );
	$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;
	$quantity     = isset( $_POST['quantity'] ) ? absint( wp_unslash( $_POST['quantity'] ) ) : 1;
	if ( $quantity < 1 ) {
		$quantity = 1;
	}

	$widget_variation_id = 0;

	if ( $variation_id > 0 ) {
		$product = wc_get_product( $variation_id );

		if ( ! $product || ! $product->is_type( 'variation' ) ) {
			wp_send_json_error( array( 'message' => 'invalid_variation' ), 404 );
		}

		// Ensure the variation belongs to the requested parent product.
		if ( (int) $product->get_parent_id() !== $product_id ) {
			wp_send_json_error( array( 'message' => 'variation_mismatch' ), 400 );
		}

		$widget_variation_id = $variation_id;
	} else {
		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_type( 'simple' ) || ! $product->is_purchasable() ) {
			wp_send_json_error( array( 'message' => 'invalid_product' ), 404 );
		}
	}

	$unit_price = wc_get_price_to_display( $product );
	$price      = $unit_price * $quantity;
	$validation = torobpay_validate_pdp_amount( $price );
	if ( ! $product->is_in_stock() || ! $validation['valid'] ) {
		wp_send_json_error( array( 'message' => 'unavailable' ), 400 );
	}

	$installment_price = $price / 4;
	$formatted_price   = wc_price( $installment_price );
	$html              = torobpay_get_widget_html(
		[
			'variation_id'    => $widget_variation_id,
			'show_price'      => $validation['is_under_threshold'],
			'formatted_price' => $formatted_price,
		]
	);

	wp_send_json_success( [ 'html' => wp_kses( $html, torobpay_kses_allowed_html() ) ] );
}

/**
 * Unified PDP Widget Controller
 *
 * Single entry point that handles both simple and variable products.
 * Uses a static flag for reliable duplicate prevention across all contexts.
 * Includes context detection to prevent rendering in product loops.
 */
function torobpay_render_pdp_widget() {
	static $rendered = false;
	if ( $rendered ) {
		return;
	}

	if ( ! function_exists( 'torobpay_has_pdp' ) || ! torobpay_has_pdp() ) {
		return;
	}

	if ( ! is_singular( 'product' ) ) {
		return;
	}

	global $product;

	if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
		return;
	}

	if ( $product->is_type( 'variable' ) ) {
		torobpay_render_variable_widget( $product );
		$rendered = true;
	} elseif ( $product->is_type( 'simple' ) ) {
		torobpay_render_simple_widget( $product );
		$rendered = true;
	}
}

/**
 * Initial PDP quantity for first-paint widget HTML.
 * Uses WooCommerce min purchase qty so server render matches the qty input default.
 *
 * @param WC_Product $product Product or variation.
 * @return int Quantity >= 1.
 */
function torobpay_get_pdp_initial_quantity( $product ) {
	$qty = 1;
	if ( $product && is_a( $product, 'WC_Product' ) && method_exists( $product, 'get_min_purchase_quantity' ) ) {
		$qty = (int) $product->get_min_purchase_quantity();
	}
	return $qty > 0 ? $qty : 1;
}

/**
 * Pass PDP widget config to JS. HTML is JSON-encoded via wp_add_inline_script
 * so Plugin Check does not see raw markup in wp_localize_script.
 *
 * @param array $data Config keys consumed by assets/js/variation-widget.js.
 */
function torobpay_localize_pdp_variation_widget( $data ) {
	if ( isset( $data['fallbackHtml'] ) ) {
		$data['fallbackHtml'] = wp_kses( $data['fallbackHtml'], torobpay_kses_allowed_html() );
	}

	wp_add_inline_script(
		'torobpay-variation-widget',
		'window.torobpayVariationWidget = ' . wp_json_encode( $data ) . ';',
		'before'
	);
}

/**
 * Render widget for variable products
 *
 * Enqueues JS for variation changes and renders initial widget
 * using the minimum price variation.
 */
function torobpay_render_variable_widget( $product ) {
	wp_enqueue_script(
		'torobpay-variation-widget',
		plugins_url( 'assets/js/variation-widget.js', TOROBPAY_PLUGIN_FILE ),
		[ 'jquery', 'wc-add-to-cart-variation' ],
		TOROBPAY_VERSION,
		true
	);

	$min_unit_price   = null;
	$min_variation_id = null;
	$min_variation    = null;
	$variation_ids    = method_exists( $product, 'get_children' ) ? $product->get_children() : [];

	foreach ( $variation_ids as $vid ) {
		$variation = wc_get_product( $vid );
		if ( ! $variation || ! $variation->is_purchasable() || ! $variation->is_in_stock() ) {
			continue;
		}
		$v_price = wc_get_price_to_display( $variation );
		if ( $v_price <= 0 ) {
			continue;
		}
		if ( $min_unit_price === null || $v_price < $min_unit_price ) {
			$min_unit_price   = $v_price;
			$min_variation_id = $vid;
			$min_variation    = $variation;
		}
	}

	// Qty input usually follows the parent min; the preview prices a specific
	// variation — use the stricter of parent vs that variation's min.
	$parent_qty    = torobpay_get_pdp_initial_quantity( $product );
	$variation_qty = $min_variation
		? torobpay_get_pdp_initial_quantity( $min_variation )
		: $parent_qty;
	$quantity      = max( $parent_qty, $variation_qty );

	torobpay_localize_pdp_variation_widget(
		[
			'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
			'nonce'              => wp_create_nonce( 'torobpay_variation_widget' ),
			'productId'          => (int) $product->get_id(),
			'productType'        => 'variable',
			'initialQuantity'    => $quantity,
			'previewVariationId' => (int) ( $min_variation_id ?: 0 ),
			'fallbackHtml'       => torobpay_get_widget_html(
				[
					'variation_id' => (int) ( $min_variation_id ?: 0 ),
					'show_price'   => false,
				]
			),
		]
	);

	$initial_html = '';
	if ( $min_unit_price !== null ) {
		$total      = $min_unit_price * $quantity;
		$validation = torobpay_validate_pdp_amount( $total );
		if ( $validation['valid'] ) {
			$initial_html = torobpay_get_widget_html(
				[
					'variation_id'    => $min_variation_id ?: 0,
					'show_price'      => $validation['is_under_threshold'],
					'formatted_price' => wc_price( $total / 4 ),
				]
			);
		}
	}

	echo '<div id="torobpay-variation-widget-container">' . wp_kses( $initial_html, torobpay_kses_allowed_html() ) . '</div>';
	torobpay_inject_pdp_styles();
}

/**
 * Render widget for simple products
 */
function torobpay_render_simple_widget( $product ) {
	if ( ! $product->is_in_stock() ) {
		return;
	}

	$quantity   = torobpay_get_pdp_initial_quantity( $product );
	$unit_price = wc_get_price_to_display( $product );
	$price      = $unit_price * $quantity;
	$validation = torobpay_validate_pdp_amount( $price );

	if ( ! $validation['valid'] ) {
		return;
	}

	wp_enqueue_script(
		'torobpay-variation-widget',
		plugins_url( 'assets/js/variation-widget.js', TOROBPAY_PLUGIN_FILE ),
		[ 'jquery' ],
		TOROBPAY_VERSION,
		true
	);

	torobpay_localize_pdp_variation_widget(
		[
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'torobpay_variation_widget' ),
			'productId'       => (int) $product->get_id(),
			'productType'     => 'simple',
			'initialQuantity' => $quantity,
			'fallbackHtml'    => torobpay_get_widget_html(
				[
					'variation_id' => 0,
					'show_price'   => false,
				]
			),
		]
	);

	$html = torobpay_get_widget_html(
		[
			'variation_id'    => 0,
			'show_price'      => $validation['is_under_threshold'],
			'formatted_price' => wc_price( $price / 4 ),
		]
	);

	echo '<div id="torobpay-variation-widget-container">' . wp_kses( $html, torobpay_kses_allowed_html() ) . '</div>';
	torobpay_inject_pdp_styles();
}

/**
 * Elementor Compatibility
 *
 * Handles cases where Elementor renders the product page differently
 * and the standard WooCommerce hooks may not fire.
 */
function torobpay_register_elementor_compatibility() {
	if ( ! did_action( 'elementor/loaded' ) ) {
		return;
	}

	add_action(
		'elementor/frontend/widget/before_render',
		function ( $widget ) {
			if ( ! is_product() || ! function_exists( 'torobpay_has_pdp' ) || ! torobpay_has_pdp() ) {
				return;
			}

			$widget_name    = $widget->get_name();
			$target_widgets = [ 'woocommerce-product-add-to-cart', 'wc-add-to-cart' ];

			if ( in_array( $widget_name, $target_widgets, true ) ) {
				add_action(
					'elementor/widget/after_render',
					function ( $after_widget ) use ( $widget ) {
						if ( $after_widget->get_id() === $widget->get_id() ) {
							torobpay_render_pdp_widget();
						}
					}
				);
			}
		}
	);

	// Fallback: also hook after product summary if add-to-cart widget is missing
	add_action( 'woocommerce_after_single_product_summary', 'torobpay_render_pdp_widget', 15 );
}

add_shortcode( 'torobpay_widget', 'torobpay_widget_shortcode' );

/**
 * Shortcode to display TorobPay widget based on product type
 * Note: this shortcode is usable even if widget isn't enabled in the settings
 * Usage: [torobpay_widget]
 *
 * @return string HTML output of the widget or empty string if conditions not met
 */
function torobpay_widget_shortcode() {
	global $product;

	$torobpay_product = $product;

	if ( ! $torobpay_product ) {
		$torobpay_product = wc_get_product( get_the_ID() );
		if ( ! $torobpay_product ) {
			return '';
		}
	}

	ob_start();

	if ( $torobpay_product->is_type( 'variable' ) ) {
		torobpay_render_variable_widget( $torobpay_product );
	} elseif ( $torobpay_product->is_type( 'simple' ) ) {
		torobpay_render_simple_widget( $torobpay_product );
	}

	return wp_kses( ob_get_clean(), torobpay_kses_allowed_html() );
}

function torobpay_product_list_badge() {
	if ( ! function_exists( 'torobpay_has_plp' ) || ! torobpay_has_plp() ) {
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

	torobpay_enqueue_product_badge_styles();

	echo wp_kses( torobpay_get_product_badge_html( $badge_data ), torobpay_kses_allowed_html() );
}

function torobpay_prepare_product_badge_data( $product ) {
	if ( ! is_a( $product, 'WC_Product' ) ) {
		return false;
	}

	$product_id   = $product->get_id();
	$variation_id = 0;
	$base_price   = null;
	$is_variable  = $product->is_type( 'variable' );
	$is_variation = $product->is_type( 'variation' );

	if ( ! $product->is_in_stock() && ! $is_variable ) {
		return false;
	}

	if ( $is_variable ) {
		$min_price        = null;
		$min_variation_id = null;
		$variation_ids    = method_exists( $product, 'get_children' ) ? $product->get_children() : [];

		if ( ! empty( $variation_ids ) ) {
			foreach ( $variation_ids as $vid ) {
				$variation = wc_get_product( $vid );
				if ( ! is_a( $variation, 'WC_Product' ) || ! $variation->is_purchasable() || ! $variation->is_in_stock() ) {
					continue;
				}

				$variation_price = wc_get_price_to_display( $variation );
				if ( $variation_price <= 0 ) {
					continue;
				}

				if ( $min_price === null || $variation_price < $min_price ) {
					$min_price        = $variation_price;
					$min_variation_id = $variation->get_id();
				}
			}
		}

		if ( $min_price === null ) {
			return false;
		}

		$variation_id = $min_variation_id ?: 0;
		$base_price   = $min_price;
	} elseif ( $is_variation || $product->is_type( 'simple' ) ) {
		if ( ! $product->is_in_stock() || ! $product->is_purchasable() ) {
			return false;
		}

		$base_price = wc_get_price_to_display( $product );
		if ( $base_price <= 0 ) {
			return false;
		}

		if ( $is_variation ) {
			$variation_id = $product->get_id();
		}
	} else {
		return false;
	}

	$validation = torobpay_validate_pdp_amount( $base_price );
	if ( ! $validation['valid'] ) {
		return false;
	}

	$installment_amount    = $base_price / 4;
	$formatted_installment = wc_price( $installment_amount );

	return [
		'product_id'            => $product_id,
		'variation_id'          => $variation_id,
		'show_price'            => (bool) $validation['is_under_threshold'],
		'formatted_installment' => $formatted_installment,
	];
}

function torobpay_get_product_badge_html( $badge_data ) {
	$defaults = [
		'product_id'            => 0,
		'variation_id'          => 0,
		'show_price'            => false,
		'formatted_installment' => '',
	];

	$args = wp_parse_args( $badge_data, $defaults );

	$product_id   = (int) $args['product_id'];
	$variation_id = (int) $args['variation_id'];
	$show_price   = (bool) $args['show_price'];
	$installment  = $args['formatted_installment'];

	$badge_logo_height_r = torobpay_get_responsive_setting( 'torobpay_badge_logo_height', 24 );
	$badge_logo_height   = (int) $badge_logo_height_r['base'];
	$logo                = esc_url( plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) );
	$logo_width          = $badge_logo_height;

	if ( $show_price ) {
		$installment_html = wp_kses( $installment, torobpay_kses_allowed_html() );
		/* translators: %s: formatted installment price HTML */
		$text             = sprintf( __( 'Each installment %s', 'torobpay-for-woocommerce' ), $installment_html );
	} else {
		$text = __( 'Installment payment', 'torobpay-for-woocommerce' );
	}

	$text      = apply_filters( 'torobpay_product_badge_text', $text, $args );
	$text_attr = wp_strip_all_tags( $text );

	$attributes = sprintf( 'data-product-id="%d"', $product_id );
	if ( $variation_id ) {
		$attributes .= sprintf( ' data-variation-id="%d"', $variation_id );
	}

	return apply_filters(
		'torobpay_product_badge_html',
		sprintf(
			'<div class="torobpay-product-badge" %s aria-label="%s"><div class="torobpay-product-badge-inner"><img src="%s" alt="%s" class="torobpay-product-badge-icon" width="%d" height="%d" /><span class="torobpay-product-badge-text">%s</span></div></div>',
			$attributes,
			esc_attr( $text_attr ),
			$logo,
			esc_attr( __( 'TorobPay installment payment', 'torobpay-for-woocommerce' ) ),
			$logo_width,
			$badge_logo_height,
			wp_kses( $text, torobpay_kses_allowed_html() )
		),
		$args
	);
}

function torobpay_enqueue_product_badge_styles() {
	static $styles_enqueued = false;

	if ( $styles_enqueued ) {
		return;
	}

	torobpay_inject_pdp_styles();

	$position_horizontal    = torobpay_sanitize_css_side( torobpay_get_badge_setting( 'position_horizontal' ), 'horizontal' );
	$position_vertical      = torobpay_sanitize_css_side( torobpay_get_badge_setting( 'position_vertical' ), 'vertical' );
	$offset_horizontal_r    = torobpay_get_responsive_setting( 'torobpay_badge_offset_horizontal', 10 );
	$offset_vertical_r      = torobpay_get_responsive_setting( 'torobpay_badge_offset_vertical', 10 );
	$offset_horizontal_unit = torobpay_get_badge_setting( 'offset_horizontal_unit' );
	$offset_vertical_unit   = torobpay_get_badge_setting( 'offset_vertical_unit' );
	$background_color       = torobpay_get_badge_setting( 'background_color' );
	$text_color             = torobpay_get_badge_setting( 'text_color' );
	$border_radius_r        = torobpay_get_responsive_setting( 'torobpay_badge_border_radius', 8 );
	$font_size_r            = torobpay_get_responsive_setting( 'torobpay_badge_font_size', 12 );
	$font_weight            = torobpay_get_badge_setting( 'font_weight' );
	$logo_height_r          = torobpay_get_responsive_setting( 'torobpay_badge_logo_height', 24 );

	$opposite_horizontal = ( 'right' === $position_horizontal ) ? 'left' : 'right';
	$opposite_vertical   = ( 'top' === $position_vertical ) ? 'bottom' : 'top';

	$device_int = static function ( $v ) { return '' === $v ? '' : (int) $v; };
	$badge_custom_styles = sprintf(
		'.torobpay-product-badge { %1$s: inherit; %2$s: inherit; }
		.torobpay-product-badge-inner { background-color: %3$s; }
		.torobpay-product-badge-text,
		.torobpay-product-badge-text .amount,
		.torobpay-product-badge-text .woocommerce-Price-amount { color: %4$s; font-weight: %5$s; }',
		$opposite_horizontal,
		$opposite_vertical,
		torobpay_esc_css_color( $background_color, '#9CE76A' ),
		torobpay_esc_css_color( $text_color, '#003D01' ),
		torobpay_esc_css_font_weight( $font_weight, '600' )
	);
	$badge_custom_styles .= torobpay_responsive_style(
		'.torobpay-product-badge',
		array(
			array( 'prop' => $position_horizontal, 'base' => (int) $offset_horizontal_r['base'], 'tablet' => $device_int( $offset_horizontal_r['tablet'] ), 'mobile' => $device_int( $offset_horizontal_r['mobile'] ), 'unit' => $offset_horizontal_unit ),
			array( 'prop' => $position_vertical, 'base' => (int) $offset_vertical_r['base'], 'tablet' => $device_int( $offset_vertical_r['tablet'] ), 'mobile' => $device_int( $offset_vertical_r['mobile'] ), 'unit' => $offset_vertical_unit ),
		)
	);
	$badge_custom_styles .= torobpay_responsive_style(
		'.torobpay-product-badge-inner',
		array(
			array( 'prop' => 'border-radius', 'base' => (int) $border_radius_r['base'], 'tablet' => $device_int( $border_radius_r['tablet'] ), 'mobile' => $device_int( $border_radius_r['mobile'] ), 'unit' => 'px' ),
		)
	);
	$badge_custom_styles .= torobpay_responsive_style(
		'.torobpay-product-badge-icon',
		array(
			array( 'prop' => 'height', 'base' => (int) $logo_height_r['base'], 'tablet' => $device_int( $logo_height_r['tablet'] ), 'mobile' => $device_int( $logo_height_r['mobile'] ), 'unit' => 'px !important' ),
			array( 'prop' => 'width', 'base' => (int) $logo_height_r['base'], 'tablet' => $device_int( $logo_height_r['tablet'] ), 'mobile' => $device_int( $logo_height_r['mobile'] ), 'unit' => 'px !important' ),
		)
	);
	$badge_custom_styles .= torobpay_responsive_style(
		'.torobpay-product-badge-text',
		array(
			array( 'prop' => 'font-size', 'base' => (int) $font_size_r['base'], 'tablet' => $device_int( $font_size_r['tablet'] ), 'mobile' => $device_int( $font_size_r['mobile'] ), 'unit' => 'px' ),
		)
	);

	if ( wp_style_is( 'torobpay-widget-style', 'enqueued' ) ) {
		wp_add_inline_style( 'torobpay-widget-style', wp_strip_all_tags( $badge_custom_styles ) );
	}

	$styles_enqueued = true;
}

function torobpay_validate_pdp_amount( $amount ) {
	$amount     = torobpay_get_amount_by_currency( $amount );
	$min_amount = 50000;        // 50,000 Rial
	$max_amount = 5000000000;    // 5,000,000,000 Rial
	$threshold  = 500000000;       // 500,000,000 Rial

	$result = [
		'valid'              => $amount >= $min_amount && $amount <= $max_amount,
		'is_under_threshold' => $amount < $threshold,
	];

	return $result;
}

function torobpay_get_pdp_logo() {
	return apply_filters( 'torobpay_logo', plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) );
}

function torobpay_inject_pdp_styles() {
	if ( ! wp_style_is( 'torobpay-widget-style', 'registered' ) ) {
		wp_register_style(
			'torobpay-widget-style',
			plugins_url( 'assets/css/widget.css', TOROBPAY_PLUGIN_FILE ),
			array(),
			TOROBPAY_VERSION
		);
	}

	wp_enqueue_style( 'torobpay-widget-style' );

	$background_color  = torobpay_get_widget_setting( 'background_color' );
	$border_color      = torobpay_get_widget_setting( 'border_color' );
	$text_color        = torobpay_get_widget_setting( 'text_color' );
	$title_font_weight = torobpay_get_widget_setting( 'title_font_weight' );
	$desc_font_weight  = torobpay_get_widget_setting( 'desc_font_weight' );
	$border_size_r     = torobpay_get_responsive_setting( 'torobpay_widget_border_size', 1 );
	$border_radius_r   = torobpay_get_responsive_setting( 'torobpay_widget_border_radius', 16 );
	$title_font_size_r = torobpay_get_responsive_setting( 'torobpay_widget_title_font_size', 14 );
	$desc_font_size_r  = torobpay_get_responsive_setting( 'torobpay_widget_desc_font_size', 12 );
	$accent_colors     = torobpay_get_widget_layout_accent_colors();
	$accent            = $accent_colors['accent'];
	$accent_dark       = $accent_colors['accent_dark'];

	$custom_styles = sprintf(
		'.torobpay-widget {
			--torobpay-widget-bg: %1$s;
			--torobpay-widget-border: %2$s;
			--torobpay-widget-text: %3$s;
			--torobpay-widget-accent: %4$s;
			--torobpay-widget-accent-dark: %5$s;
			background-color: var(--torobpay-widget-bg);
			border-color: var(--torobpay-widget-border);
		}
		.torobpay-widget-content { color: var(--torobpay-widget-text); }
		.torobpay-widget-title { font-weight: %6$s; color: var(--torobpay-widget-text); }
		.torobpay-widget-description { font-weight: %7$s; color: var(--torobpay-widget-text); }
		.torobpay-widget .amount,
		.torobpay-widget .woocommerce-Price-amount { color: inherit; font-weight: inherit; }',
		torobpay_esc_css_color( $background_color, '#ffffff' ),
		torobpay_esc_css_color( $border_color, '#ebecf2' ),
		torobpay_esc_css_color( $text_color, '#1a1c23' ),
		torobpay_esc_css_color( $accent, '#9CE76A' ),
		torobpay_esc_css_color( $accent_dark, '#003D01' ),
		torobpay_esc_css_font_weight( $title_font_weight, '700' ),
		torobpay_esc_css_font_weight( $desc_font_weight, '400' )
	);

	$device_int       = static function ( $v ) { return '' === $v ? '' : (int) $v; };
	$custom_styles   .= torobpay_responsive_style(
		'.torobpay-widget',
		array(
			array( 'prop' => 'border-width', 'base' => (int) $border_size_r['base'], 'tablet' => $device_int( $border_size_r['tablet'] ), 'mobile' => $device_int( $border_size_r['mobile'] ), 'unit' => 'px' ),
			array( 'prop' => 'border-radius', 'base' => (int) $border_radius_r['base'], 'tablet' => $device_int( $border_radius_r['tablet'] ), 'mobile' => $device_int( $border_radius_r['mobile'] ), 'unit' => 'px' ),
			array( 'prop' => '--torobpay-widget-border-width', 'base' => (int) $border_size_r['base'], 'tablet' => $device_int( $border_size_r['tablet'] ), 'mobile' => $device_int( $border_size_r['mobile'] ), 'unit' => 'px' ),
			array( 'prop' => '--torobpay-widget-radius', 'base' => (int) $border_radius_r['base'], 'tablet' => $device_int( $border_radius_r['tablet'] ), 'mobile' => $device_int( $border_radius_r['mobile'] ), 'unit' => 'px' ),
		)
	);
	$custom_styles   .= torobpay_responsive_style(
		'.torobpay-widget-title',
		array(
			array( 'prop' => 'font-size', 'base' => (int) $title_font_size_r['base'], 'tablet' => $device_int( $title_font_size_r['tablet'] ), 'mobile' => $device_int( $title_font_size_r['mobile'] ), 'unit' => 'px' ),
		)
	);
	$custom_styles   .= torobpay_responsive_style(
		'.torobpay-widget-description',
		array(
			array( 'prop' => 'font-size', 'base' => (int) $desc_font_size_r['base'], 'tablet' => $device_int( $desc_font_size_r['tablet'] ), 'mobile' => $device_int( $desc_font_size_r['mobile'] ), 'unit' => 'px' ),
		)
	);

	wp_add_inline_style( 'torobpay-widget-style', wp_strip_all_tags( $custom_styles ) );
}

/**
 * Enqueue badge compatibility mode script
 */
function torobpay_enqueue_badge_compatibility_script() {
	$is_woo_archive = is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() || is_post_type_archive( 'product' );

	// JetEngine / JetWooBuilder grids can appear on ANY page (regular pages,
	// custom archives, the home page), not just WooCommerce archives, so when
	// such a builder is active we load the injector site-wide on the front end.
	$jetengine_active = function_exists( 'torobpay_is_jetengine_active' ) && torobpay_is_jetengine_active();

	if ( ! $is_woo_archive && ! $jetengine_active ) {
		return;
	}

	// the JetEngine fallback is the only reason we got here but the badge and
	$badge_enabled   = function_exists( 'torobpay_has_plp' ) && torobpay_has_plp();
	$marquee_enabled = function_exists( 'torobpay_has_marquee' ) && torobpay_has_marquee();
	if ( ! $badge_enabled && ! $marquee_enabled ) {
		return;
	}

	torobpay_enqueue_product_badge_styles();

	wp_enqueue_script(
		'torobpay-badge-compatibility',
		plugins_url( 'assets/js/badge-compatibility.js', TOROBPAY_PLUGIN_FILE ),
		array( 'jquery', 'wp-i18n' ),
		TOROBPAY_VERSION,
		true
	);
	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'torobpay-badge-compatibility', 'torobpay-for-woocommerce', dirname( TOROBPAY_PLUGIN_FILE ) . '/languages' );
	}

	// Localize script with config
	wp_localize_script(
		'torobpay-badge-compatibility',
		'torobpayBadgeCompat',
		array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'torobpay_badge_compat' ),
			'badgeEnabled' => $badge_enabled,
			'logo'         => plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ),
		)
	);
}

/**
 * AJAX handler for badge compatibility mode - returns badge data for multiple products
 */
add_action( 'wp_ajax_torobpay_get_badge_data', 'torobpay_ajax_get_badge_data' );
add_action( 'wp_ajax_nopriv_torobpay_get_badge_data', 'torobpay_ajax_get_badge_data' );

function torobpay_ajax_get_badge_data() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'torobpay_badge_compat' ) ) {
		wp_send_json_error( 'Invalid nonce' );
		return;
	}

	$product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['product_ids'] ) ) : array();

	if ( empty( $product_ids ) ) {
		wp_send_json_success( array() );
		return;
	}

	$badge_data = array();

	foreach ( $product_ids as $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			continue;
		}

		$data = torobpay_prepare_product_badge_data( $product );

		if ( $data ) {
			// For plain text, we need to decode HTML entities to get clean text
			$plain_price = html_entity_decode( wp_strip_all_tags( $data['formatted_installment'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			$badge_data[ $product_id ] = array(
				'valid'          => true,
				'showPrice'      => $data['show_price'],
				'formattedPrice' => $data['formatted_installment'],
				'plainPrice'     => $plain_price,
			);
		}
	}

	wp_send_json_success( $badge_data );
}
