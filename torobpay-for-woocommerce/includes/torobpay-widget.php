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
 * Widget markup. show_price false is the price-less copy.
 *
 * @param array $args variation_id, show_price, formatted_price, logo.
 * @return string
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
	} elseif ( $logo_animation === 'elastic-spin' ) {
		$media_classes[] = 'torobpay-logo-elastic';
		$image_classes[] = 'torobpay-logo-elastic-spin';
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
		'<div class="%1$s" data-variation-id="%2$d" data-show-price="%14$d">%3$s'
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
		$promo_after,
		$show_price ? 1 : 0
	);
}

/**
 * Product-page widget for simple and variable products. Once per request.
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
 * Min purchase quantity for the first paint, or 1.
 *
 * @param WC_Product|null $product Product or variation.
 * @return int
 */
function torobpay_get_pdp_initial_quantity( $product ) {
	$qty = 1;
	if ( $product && is_a( $product, 'WC_Product' ) && method_exists( $product, 'get_min_purchase_quantity' ) ) {
		$qty = (int) $product->get_min_purchase_quantity();
	}
	return $qty > 0 ? $qty : 1;
}

/**
 * Store-currency total for the first paint. Variations use the higher of the
 * parent and variation minimum quantities, matching the PDP widget.
 *
 * @param float|int|string $unit_price Displayed unit price.
 * @param WC_Product|null  $product    Simple product, or the parent of a variation.
 * @param WC_Product|null  $variation  Variation when $product is its parent.
 * @return float
 */
function torobpay_get_pdp_initial_line_total( $unit_price, $product = null, $variation = null ) {
	$quantity = torobpay_get_pdp_initial_quantity( $product );
	if ( $variation ) {
		$quantity = max( $quantity, torobpay_get_pdp_initial_quantity( $variation ) );
	}
	return (float) $unit_price * $quantity;
}

/** @return array{bounds: array<int, array>, badges: array<int, array|false>} */
function &torobpay_storefront_price_cache() {
	static $cache = array(
		'bounds' => array(),
		'badges' => array(),
	);
	return $cache;
}

/** Reset storefront bounds and badge cache. */
function torobpay_reset_storefront_price_cache() {
	$cache           = &torobpay_storefront_price_cache();
	$cache['bounds'] = array();
	$cache['badges'] = array();
}

/**
 * Shown qty on the PDP widget: min purchase qty when live preview is on, else 1.
 *
 * @param int $stored_quantity Minimum purchase quantity.
 * @return int
 */
function torobpay_pdp_shown_quantity( $stored_quantity ) {
	if ( ! function_exists( 'torobpay_live_price_preview_enabled' ) || ! torobpay_live_price_preview_enabled() ) {
		return 1;
	}
	$stored_quantity = (int) $stored_quantity;
	return $stored_quantity > 0 ? $stored_quantity : 1;
}

/**
 * Installment for the PDP first paint.
 *
 * @param float|int|string $unit_price Displayed unit price.
 * @param int              $stored_quantity Minimum purchase quantity.
 * @return array{show_price: bool, formatted_installment: string}
 */
function torobpay_get_pdp_shown_installment( $unit_price, $stored_quantity ) {
	$total      = (float) $unit_price * torobpay_pdp_shown_quantity( $stored_quantity );
	$validation = torobpay_validate_pdp_amount( $total );
	$show_price = $validation['valid'] && $validation['is_under_threshold'];

	return [
		'show_price'            => $show_price,
		'formatted_installment' => $show_price ? wc_price( $total / 4 ) : '',
	];
}

/**
 * Cheapest and dearest in-stock variations of a variable product.
 *
 * Cheapest is the lowest displayed unit price. Dearest is the highest starting
 * line total. The block stays hidden when that dearest total is under the minimum.
 *
 * @param WC_Product $product Variable product.
 * @return array{cheapest: array|null, dearest: array|null}
 */
function torobpay_get_variable_pdp_bounds( $product ) {
	$cache      = &torobpay_storefront_price_cache();
	$product_id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
	if ( $product_id && array_key_exists( $product_id, $cache['bounds'] ) ) {
		return $cache['bounds'][ $product_id ];
	}

	$rows     = torobpay_collect_variation_bound_rows( $product );
	$cheapest = null;
	$dearest  = null;

	foreach ( $rows as $row ) {
		$unit_price = (float) $row['unit_price'];
		$quantity   = max( 1, (int) $row['quantity'] );
		$line_total = (float) $unit_price * $quantity;
		$entry      = [
			'id'         => (int) $row['id'],
			'unit_price' => $unit_price,
			'quantity'   => $quantity,
			'line_total' => $line_total,
			'validation' => torobpay_validate_pdp_amount( $line_total ),
		];

		if ( null === $cheapest || $unit_price < $cheapest['unit_price'] ) {
			$cheapest = $entry;
		}
		if ( null === $dearest || $line_total > $dearest['line_total'] ) {
			$dearest = $entry;
		}
	}

	$result = [
		'cheapest' => $cheapest,
		'dearest'  => $dearest,
	];
	if ( $product_id ) {
		$cache['bounds'][ $product_id ] = $result;
	}
	return $result;
}

/**
 * In-stock variation rows from the price map when possible, else each child.
 *
 * @param WC_Product $product Variable product.
 * @return array<int, array{id: int, unit_price: float, quantity: int}>
 */
function torobpay_collect_variation_bound_rows( $product ) {
	$from_prices = torobpay_variation_rows_from_price_map( $product );
	if ( null !== $from_prices ) {
		return $from_prices;
	}
	return torobpay_variation_rows_from_objects( $product );
}

/**
 * Whether a plugin can change is_purchasable() for the current visitor.
 *
 * The price map does not apply these filters. Dokan uses the first one to
 * mark a vendor's own product unpurchasable.
 *
 * @return bool
 */
function torobpay_purchasable_filter_is_active() {
	if ( ! function_exists( 'has_filter' ) ) {
		return false;
	}

	return (bool) ( has_filter( 'woocommerce_is_purchasable' ) || has_filter( 'woocommerce_variation_is_purchasable' ) );
}

/**
 * @param WC_Product $product Variable product.
 * @return array<int, array{id: int, unit_price: float, quantity: int}>|null
 */
function torobpay_variation_rows_from_price_map( $product ) {
	if ( ! is_object( $product ) || ! method_exists( $product, 'get_variation_prices' ) ) {
		return null;
	}

	$map = $product->get_variation_prices( true );
	if ( ! is_array( $map ) || ! isset( $map['price'] ) || ! is_array( $map['price'] ) ) {
		return null;
	}
	if ( ! $map['price'] ) {
		return array();
	}

	$stock = torobpay_query_variation_stock_and_min_qty( array_keys( $map['price'] ) );
	if ( null === $stock ) {
		return null;
	}

	$parent_qty             = torobpay_get_pdp_initial_quantity( $product );
	$use_qty_filter         = function_exists( 'has_filter' ) && has_filter( 'woocommerce_quantity_input_min' );
	$use_purchasable_filter = torobpay_purchasable_filter_is_active();
	$load_variation         = ( $use_qty_filter || $use_purchasable_filter ) && function_exists( 'wc_get_product' );
	$rows                   = array();

	foreach ( $map['price'] as $variation_id => $unit_price ) {
		$variation_id = (int) $variation_id;
		if ( ! isset( $stock[ $variation_id ] ) || empty( $stock[ $variation_id ]['in_stock'] ) ) {
			continue;
		}

		$unit_price = (float) $unit_price;
		if ( $unit_price <= 0 ) {
			continue;
		}

		$min_qty = isset( $stock[ $variation_id ]['min_qty'] ) ? (int) $stock[ $variation_id ]['min_qty'] : 1;
		if ( $load_variation ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! is_a( $variation, 'WC_Product' ) ) {
				continue;
			}
			// Dokan filters woocommerce_is_purchasable and does not hook quantity.
			if ( $use_purchasable_filter && ! $variation->is_purchasable() ) {
				continue;
			}
			if ( $use_qty_filter ) {
				$min_qty = torobpay_get_pdp_initial_quantity( $variation );
			}
		}

		$rows[] = array(
			'id'         => $variation_id,
			'unit_price' => $unit_price,
			'quantity'   => max( $parent_qty, $min_qty > 0 ? $min_qty : 1 ),
		);
	}

	return $rows;
}

/**
 * Stock and min qty for variation ids. Null when incomplete; caller loads objects.
 *
 * @param array<int, int|string> $ids Variation ids.
 * @return array<int, array{in_stock: bool, min_qty: int}>|null
 */
function torobpay_query_variation_stock_and_min_qty( array $ids ) {
	global $wpdb;

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ) {
		return null;
	}

	$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	if ( ! $ids ) {
		return array();
	}

	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$lookup       = $wpdb->prefix . 'wc_product_meta_lookup';
	$stock_sql    = "SELECT product_id, stock_status FROM {$lookup} WHERE product_id IN ({$placeholders})";
	$stock_rows   = $wpdb->get_results( $wpdb->prepare( $stock_sql, ...$ids ), 'ARRAY_A' );
	if ( ! is_array( $stock_rows ) ) {
		return null;
	}

	$found = array();
	foreach ( $stock_rows as $row ) {
		if ( ! is_array( $row ) || ! isset( $row['product_id'] ) ) {
			continue;
		}
		$id            = (int) $row['product_id'];
		$status        = isset( $row['stock_status'] ) ? (string) $row['stock_status'] : '';
		$found[ $id ]  = array(
			'in_stock' => 'outofstock' !== $status,
			'min_qty'  => 1,
		);
	}
	if ( count( $found ) < count( $ids ) ) {
		return null;
	}

	$meta_keys          = array( 'variation_minimum_allowed_quantity', 'minimum_allowed_quantity', 'min_quantity', '_min_qty' );
	$meta_placeholders  = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
	$postmeta           = isset( $wpdb->postmeta ) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
	$meta_sql           = "SELECT post_id, meta_key, meta_value FROM {$postmeta} WHERE post_id IN ({$placeholders}) AND meta_key IN ({$meta_placeholders})";
	$meta_rows          = $wpdb->get_results( $wpdb->prepare( $meta_sql, ...array_merge( $ids, $meta_keys ) ), 'ARRAY_A' );
	if ( ! is_array( $meta_rows ) ) {
		return $found;
	}

	$priority = array_flip( $meta_keys );
	$best     = array();
	foreach ( $meta_rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$id  = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
		$key = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';
		if ( ! isset( $found[ $id ] ) || ! isset( $priority[ $key ] ) ) {
			continue;
		}
		$qty = (int) $row['meta_value'];
		if ( $qty < 1 ) {
			continue;
		}
		if ( ! isset( $best[ $id ] ) || $priority[ $key ] < $best[ $id ]['priority'] ) {
			$best[ $id ] = array(
				'priority' => $priority[ $key ],
				'qty'      => $qty,
			);
		}
	}
	foreach ( $best as $id => $pick ) {
		$found[ $id ]['min_qty'] = $pick['qty'];
	}

	return $found;
}

/**
 * Variation rows by loading each child. Used when the price map is unavailable.
 *
 * @param WC_Product $product Variable product.
 * @return array<int, array{id: int, unit_price: float, quantity: int}>
 */
function torobpay_variation_rows_from_objects( $product ) {
	$rows          = array();
	$variation_ids = is_object( $product ) && method_exists( $product, 'get_children' ) ? $product->get_children() : array();

	foreach ( $variation_ids as $vid ) {
		$variation = wc_get_product( $vid );
		if ( ! $variation || ! is_a( $variation, 'WC_Product' ) || ! $variation->is_purchasable() || ! $variation->is_in_stock() ) {
			continue;
		}

		$unit_price = wc_get_price_to_display( $variation );
		if ( $unit_price <= 0 ) {
			continue;
		}

		$quantity = max(
			torobpay_get_pdp_initial_quantity( $product ),
			torobpay_get_pdp_initial_quantity( $variation )
		);
		$rows[]   = array(
			'id'         => (int) $variation->get_id(),
			'unit_price' => (float) $unit_price,
			'quantity'   => $quantity,
		);
	}

	return $rows;
}

/**
 * Widget config for the PDP script. HTML is JSON-encoded, not localized raw.
 *
 * @param array $data Keys for assets/js/variation-widget.js.
 */
function torobpay_localize_pdp_variation_widget( $data ) {
	wp_add_inline_script(
		'torobpay-variation-widget',
		'window.torobpayVariationWidget = ' . wp_json_encode( $data ) . ';',
		'before'
	);
}

/**
 * Variable widget. Omitted when every in-stock variation's starting total is
 * under 200,000 rial, so a later quantity change cannot inject it. Otherwise
 * the first paint is the cheapest variation: its installment when that total
 * qualifies, or the price-less copy when it does not.
 */
function torobpay_render_variable_widget( $product ) {
	$bounds = torobpay_get_variable_pdp_bounds( $product );
	if ( ! $bounds['dearest'] || ! $bounds['dearest']['validation']['valid'] ) {
		return;
	}

	$cheapest    = $bounds['cheapest'];
	$shown_qty   = torobpay_pdp_shown_quantity( $cheapest['quantity'] );
	$shown_total = (float) $cheapest['unit_price'] * $shown_qty;
	$validation  = torobpay_validate_pdp_amount( $shown_total );

	wp_enqueue_script(
		'torobpay-variation-widget',
		plugins_url( 'assets/js/variation-widget.js', TOROBPAY_PLUGIN_FILE ),
		[ 'jquery', 'wc-add-to-cart-variation' ],
		TOROBPAY_VERSION,
		true
	);

	torobpay_localize_pdp_variation_widget(
		array_merge(
			torobpay_get_pdp_widget_client_config( $cheapest['unit_price'] ),
			[
				'productId'          => (int) $product->get_id(),
				'productType'        => 'variable',
				'initialQuantity'    => $shown_qty,
				'previewVariationId' => (int) $cheapest['id'],
			]
		)
	);

	if ( $validation['valid'] ) {
		$initial_html = torobpay_get_widget_html(
			[
				'variation_id'    => $cheapest['id'],
				'show_price'      => $validation['is_under_threshold'],
				'formatted_price' => wc_price( $shown_total / 4 ),
			]
		);
	} else {
		$initial_html = torobpay_get_widget_html(
			[
				'variation_id' => (int) $cheapest['id'],
				'show_price'   => false,
			]
		);
	}

	echo '<div id="torobpay-variation-widget-container">' . wp_kses( $initial_html, torobpay_kses_allowed_html() ) . '</div>';
	torobpay_inject_pdp_styles();
}

/**
 * Simple widget. Omitted when the starting total is under 200,000 rial,
 * so a later quantity change cannot inject it.
 */
function torobpay_render_simple_widget( $product ) {
	if ( ! $product->is_in_stock() ) {
		return;
	}

	$unit_price = wc_get_price_to_display( $product );
	$price      = torobpay_get_pdp_initial_line_total( $unit_price, $product );
	$gate       = torobpay_validate_pdp_amount( $price );

	if ( ! $gate['valid'] ) {
		return;
	}

	$quantity   = torobpay_pdp_shown_quantity( torobpay_get_pdp_initial_quantity( $product ) );
	$shown      = (float) $unit_price * $quantity;
	$validation = torobpay_validate_pdp_amount( $shown );

	wp_enqueue_script(
		'torobpay-variation-widget',
		plugins_url( 'assets/js/variation-widget.js', TOROBPAY_PLUGIN_FILE ),
		[ 'jquery' ],
		TOROBPAY_VERSION,
		true
	);

	torobpay_localize_pdp_variation_widget(
		array_merge(
			torobpay_get_pdp_widget_client_config( $unit_price ),
			[
				'productId'       => (int) $product->get_id(),
				'productType'     => 'simple',
				'initialQuantity' => $quantity,
			]
		)
	);

	if ( $validation['valid'] ) {
		$html = torobpay_get_widget_html(
			[
				'variation_id'    => 0,
				'show_price'      => $validation['is_under_threshold'],
				'formatted_price' => wc_price( $shown / 4 ),
			]
		);
	} else {
		$html = torobpay_get_widget_html(
			[
				'variation_id' => 0,
				'show_price'   => false,
			]
		);
	}

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

	$cache      = &torobpay_storefront_price_cache();
	$product_id = (int) $product->get_id();
	if ( $product_id && array_key_exists( $product_id, $cache['badges'] ) ) {
		return $cache['badges'][ $product_id ];
	}

	$data = torobpay_build_product_badge_data( $product );
	if ( $product_id ) {
		$cache['badges'][ $product_id ] = $data;
	}
	return $data;
}

/**
 * @param WC_Product $product Product.
 * @return array|false
 */
function torobpay_build_product_badge_data( $product ) {
	if ( ! is_a( $product, 'WC_Product' ) ) {
		return false;
	}

	$product_id         = $product->get_id();
	$variation_id       = 0;
	$base_price         = null;
	$quantity_product   = $product;
	$quantity_variation = null;
	$is_variable        = $product->is_type( 'variable' );
	$is_variation       = $product->is_type( 'variation' );

	if ( ! $product->is_in_stock() && ! $is_variable ) {
		return false;
	}

	if ( $is_variable ) {
		$bounds = torobpay_get_variable_pdp_bounds( $product );
		if ( ! $bounds['dearest'] || ! $bounds['dearest']['validation']['valid'] ) {
			return false;
		}

		$cheapest = $bounds['cheapest'];
		$shown    = torobpay_get_pdp_shown_installment( $cheapest['unit_price'], $cheapest['quantity'] );

		return [
			'product_id'            => $product_id,
			'variation_id'          => $cheapest['id'],
			'show_price'            => $shown['show_price'],
			'formatted_installment' => $shown['formatted_installment'],
		];
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
			$parent       = wc_get_product( (int) $product->get_parent_id() );
			if ( $parent ) {
				$quantity_product   = $parent;
				$quantity_variation = $product;
			}
		}
	} else {
		return false;
	}

	$line_total = torobpay_get_pdp_initial_line_total( $base_price, $quantity_product, $quantity_variation );
	$gate       = torobpay_validate_pdp_amount( $line_total );
	if ( ! $gate['valid'] ) {
		return false;
	}

	$stored_quantity = torobpay_get_pdp_initial_quantity( $quantity_product );
	if ( $quantity_variation ) {
		$stored_quantity = max( $stored_quantity, torobpay_get_pdp_initial_quantity( $quantity_variation ) );
	}
	$shown = torobpay_get_pdp_shown_installment( $base_price, $stored_quantity );

	return [
		'product_id'            => $product_id,
		'variation_id'          => $variation_id,
		'show_price'            => $shown['show_price'],
		'formatted_installment' => $shown['formatted_installment'],
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
	$border_color           = torobpay_get_badge_setting( 'border_color' );
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
		.torobpay-product-badge-inner { background-color: %3$s; border-color: %4$s; }
		.torobpay-product-badge-text,
		.torobpay-product-badge-text .amount,
		.torobpay-product-badge-text .woocommerce-Price-amount { color: %5$s; font-weight: %6$s; }',
		$opposite_horizontal,
		$opposite_vertical,
		torobpay_esc_css_color( $background_color, '#9CE76A' ),
		torobpay_esc_css_color( $border_color, '#9CE76A' ),
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

/**
 * Unit price, currency factor, and display threshold for the PDP script.
 *
 * @param float|int|string|null $unit_price Displayed unit price in store currency.
 * @return array
 */
function torobpay_get_pdp_widget_client_config( $unit_price ) {
	$unit_price = is_numeric( $unit_price ) ? (float) $unit_price : 0.0;
	$symbol     = function_exists( 'get_woocommerce_currency_symbol' ) ? (string) get_woocommerce_currency_symbol() : '';

	return [
		'unitPrice'       => $unit_price > 0 ? $unit_price : 0,
		'currencyFactor'  => (int) torobpay_get_currency_amount_factor(),
		'threshold'       => (int) torobpay_get_pdp_display_threshold(),
		'minAmount'       => 200000,
		'livePreview'     => function_exists( 'torobpay_live_price_preview_enabled' ) && torobpay_live_price_preview_enabled(),
		'pricedTitle'     => __( 'Each installment with TorobPay: %s', 'torobpay-for-woocommerce' ),
		'plainTitle'      => __( 'Installments based on TorobPay credit', 'torobpay-for-woocommerce' ),
		'currency'        => array(
			'decimals'          => function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 0,
			'decimalSeparator'  => function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : '.',
			'thousandSeparator' => function_exists( 'wc_get_price_thousand_separator' ) ? (string) wc_get_price_thousand_separator() : ',',
			'symbol'            => html_entity_decode( $symbol, ENT_QUOTES, 'UTF-8' ),
			'format'            => function_exists( 'get_woocommerce_price_format' ) ? (string) get_woocommerce_price_format() : '%1$s%2$s',
			'trimZeros'         => function_exists( 'apply_filters' ) ? (bool) apply_filters( 'woocommerce_price_trim_zeros', false ) : false,
		),
	];
}

/**
 * Rial total at or above which the installment amount is omitted. The widget stays.
 *
 * @return int
 */
function torobpay_get_pdp_display_threshold() {
	return 5000000000;
}

/**
 * valid means at least 200,000 Rial. is_under_threshold keeps the installment amount.
 *
 * @param float|int|string $amount Store-currency amount.
 * @return array
 */
function torobpay_validate_pdp_amount( $amount ) {
	$amount     = torobpay_get_amount_by_currency( $amount );
	$min_amount = 200000; // 200,000 Rial
	$threshold  = torobpay_get_pdp_display_threshold();

	return [
		'valid'              => $amount >= $min_amount,
		'is_under_threshold' => $amount < $threshold,
	];
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

	$product_ids = isset( $_POST['product_ids'] )
		? array_slice( array_unique( array_map( 'absint', (array) wp_unslash( $_POST['product_ids'] ) ) ), 0, 50 )
		: array();

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
