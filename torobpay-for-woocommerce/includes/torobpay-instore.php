<?php
/**
 * In-store (POS) selling for TorobPay.
 *
 * Merchants create a guest WooCommerce order from the TorobPay orders screen,
 * then show the customer a QR of the TorobPay `paymentPageUrl`.
 *
 * Deferred (not in this pass):
 * - SMS of the payment link
 * - WooCommerce customer-note / invoice email of the payment link
 * - Live customer lookup as the merchant types phone
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse GET /api/online/features/v1/list into a cacheable payload.
 *
 * @param mixed $response API array or WP_Error.
 * @return array{is_online_instore_order_enabled:bool,is_order_campaign_enabled:bool}|null
 */
function torobpay_parse_online_features_response( $response ) {
	if ( is_wp_error( $response ) || ! is_array( $response ) ) {
		return null;
	}

	if ( empty( $response['successful'] ) || ! isset( $response['response'] ) || ! is_array( $response['response'] ) ) {
		return null;
	}

	return array(
		'is_online_instore_order_enabled' => true === ( $response['response']['is_online_instore_order_enabled'] ?? false ),
		'is_order_campaign_enabled'       => true === ( $response['response']['is_order_campaign_enabled'] ?? false ),
	);
}

/**
 * Whether this merchant may use in-store payments.
 *
 * @param bool $force_refresh Skip the cache and re-fetch from TorobPay.
 * @return bool
 */
function torobpay_merchant_has_instore_payments( $force_refresh = false ) {
	$cached    = get_transient( 'torobpay_online_features' );
	$has_cache = is_array( $cached ) && array_key_exists( 'is_online_instore_order_enabled', $cached );

	if ( ! $force_refresh && $has_cache ) {
		return (bool) apply_filters( 'torobpay_merchant_has_instore_payments', (bool) $cached['is_online_instore_order_enabled'] );
	}

	if ( function_exists( 'torobpay_has_credentials' ) && torobpay_has_credentials() && class_exists( 'TorobPayAPI' ) ) {
		$api    = new TorobPayAPI();
		$parsed = torobpay_parse_online_features_response( $api->get_merchant_features() );
		if ( is_array( $parsed ) ) {
			set_transient( 'torobpay_online_features', $parsed, HOUR_IN_SECONDS );
			return (bool) apply_filters( 'torobpay_merchant_has_instore_payments', (bool) $parsed['is_online_instore_order_enabled'] );
		}
	}

	$enabled = $has_cache ? (bool) $cached['is_online_instore_order_enabled'] : false;
	return (bool) apply_filters( 'torobpay_merchant_has_instore_payments', $enabled );
}

/**
 * Whether an order was created via the in-store POS flow.
 *
 * @param mixed $order Order object.
 * @return bool
 */
function torobpay_is_instore_order( $order ) {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return false;
	}

	if ( 'yes' === $order->get_meta( '_torobpay_instore' ) ) {
		return true;
	}

	if ( method_exists( $order, 'get_created_via' ) && 'torobpay_instore' === $order->get_created_via() ) {
		return true;
	}

	return false;
}

/**
 * Merchant-facing in-store vs online label for an order.
 *
 * @param mixed $order Order object.
 * @return string
 */
function torobpay_get_order_channel_label( $order ) {
	if ( torobpay_is_instore_order( $order ) ) {
		return __( 'In-store', 'torobpay-for-woocommerce' );
	}

	return __( 'Online', 'torobpay-for-woocommerce' );
}

require_once __DIR__ . '/torobpay-instore-watch.php';
require_once __DIR__ . '/torobpay-instore-catalog.php';

/**
 * Merchant-facing copy for in-store payment watch states.
 *
 * @return array<string,string>
 */
function torobpay_instore_watch_messages() {
	return array(
		'pending' => __( 'Waiting for the customer to pay…', 'torobpay-for-woocommerce' ),
		'paid'    => __( 'Payment received. This in-store order is complete.', 'torobpay-for-woocommerce' ),
		'failed'  => __( 'Payment was not completed. You can retry and show a new QR code.', 'torobpay-for-woocommerce' ),
		'closed'  => __( 'This order can no longer accept payment.', 'torobpay-for-woocommerce' ),
	);
}

/**
 * Fixed thank-you copy for paid in-store orders.
 *
 * @return string
 */
function torobpay_get_instore_success_message() {
	return __( 'Your in-store purchase was paid successfully.', 'torobpay-for-woocommerce' );
}

/**
 * Fixed copy for failed in-store payments.
 *
 * @return string
 */
function torobpay_get_instore_failed_message() {
	return __( 'Your in-store payment was not completed. You can try again.', 'torobpay-for-woocommerce' );
}

/**
 * Plugin-owned storefront URL for an in-store payment result (not checkout).
 *
 * @param object $order  Order.
 * @param string $result success|failed.
 * @return string
 */
function torobpay_get_instore_result_url( $order, $result = 'failed' ) {
	$result = sanitize_key( (string) $result );
	if ( ! in_array( $result, array( 'success', 'failed' ), true ) ) {
		$result = 'failed';
	}

	$order_id = ( is_object( $order ) && method_exists( $order, 'get_id' ) ) ? (int) $order->get_id() : 0;
	$args     = array(
		'torobpay_instore_result' => $result,
		'wc_order'                => $order_id,
	);

	if ( is_object( $order ) && method_exists( $order, 'get_order_key' ) ) {
		$key = (string) $order->get_order_key();
		if ( $key !== '' ) {
			$args['key'] = $key;
		}
	}

	$base = function_exists( 'home_url' ) ? home_url( '/' ) : '/';
	return function_exists( 'add_query_arg' ) ? add_query_arg( $args, $base ) : $base;
}

/**
 * Customer-facing URL after a TorobPay return.
 *
 * In-store orders always use the plugin result page so checkout/thank-you
 * cannot rebuild a cart or add shipping.
 *
 * @param object|null $order    Order, if known.
 * @param string      $status   success|failed|cancelled.
 * @param string      $fallback URL when the order is not in-store.
 * @return string
 */
function torobpay_get_payment_result_redirect_url( $order, $status, $fallback = '' ) {
	if ( $order && torobpay_is_instore_order( $order ) ) {
		return torobpay_get_instore_result_url( $order, 'success' === $status ? 'success' : 'failed' );
	}

	return $fallback;
}

/**
 * Where to send the customer after a failed or cancelled TorobPay return.
 *
 * In-store orders must not go to checkout: that rebuilds a cart and can add shipping.
 *
 * @param object|null $order Order, if known.
 * @return string
 */
function torobpay_get_failed_payment_redirect_url( $order = null ) {
	$fallback = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : ( function_exists( 'home_url' ) ? home_url( '/' ) : '/' );

	return torobpay_get_payment_result_redirect_url( $order, 'failed', $fallback );
}

/**
 * Paid thank-you notice: in-store copy when the order is an in-store sale.
 *
 * @param object $order           Order.
 * @param string $existing_notice Stored notice, if any.
 * @return string
 */
function torobpay_get_paid_thankyou_notice( $order, $existing_notice = '' ) {
	$existing_notice = is_string( $existing_notice ) ? trim( $existing_notice ) : '';

	if ( torobpay_is_instore_order( $order ) ) {
		return torobpay_get_instore_success_message();
	}

	if ( $existing_notice !== '' ) {
		return $existing_notice;
	}

	return __( 'Payment completed successfully.', 'torobpay-for-woocommerce' );
}

/**
 * Force paid in-store orders to completed (goods already handed over).
 *
 * @param string   $status   Proposed status.
 * @param int      $order_id Order ID.
 * @param mixed    $order    Order object when provided.
 * @return string
 */
function torobpay_instore_payment_complete_status( $status, $order_id, $order = null ) {
	if ( ! $order && function_exists( 'wc_get_order' ) ) {
		$order = wc_get_order( $order_id );
	}

	if ( $order && torobpay_is_instore_order( $order ) ) {
		return 'completed';
	}

	return $status;
}

/**
 * In-store orders have no shipping address.
 *
 * @param bool  $needs Whether a shipping address is needed.
 * @param mixed $order Order.
 * @return bool
 */
function torobpay_instore_needs_shipping_address( $needs, $order = null ) {
	if ( $order && torobpay_is_instore_order( $order ) ) {
		return false;
	}

	return $needs;
}

/**
 * Split an optional in-store customer name into billing first/last name.
 *
 * @param string $name Full name as entered by the merchant.
 * @return array{first_name:string,last_name:string}
 */
function torobpay_parse_instore_customer_name( $name ) {
	$name = trim( (string) $name );
	if ( $name === '' ) {
		return array(
			'first_name' => '',
			'last_name'  => '',
		);
	}

	$parts = preg_split( '/\s+/u', $name, 2 );
	if ( ! is_array( $parts ) || empty( $parts[0] ) ) {
		return array(
			'first_name' => '',
			'last_name'  => '',
		);
	}

	return array(
		'first_name' => $parts[0],
		'last_name'  => isset( $parts[1] ) ? $parts[1] : '',
	);
}

/**
 * Parse a merchant-typed in-store total in shop currency.
 *
 * Same rules as torobpay_parse_amount(), except a non-positive total is 0.
 *
 * @param mixed $raw Posted amount.
 * @return float 0 when empty, invalid, or not positive.
 */
function torobpay_parse_instore_custom_amount( $raw ) {
	$amount = torobpay_parse_amount( $raw );
	return $amount > 0 ? $amount : 0.0;
}

/**
 * Whether an order line is an amount-only in-store sale (no catalog product).
 *
 * @param mixed $item Order item.
 * @return bool
 */
function torobpay_is_instore_custom_amount_item( $item ) {
	if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
		return false;
	}

	return 'yes' === $item->get_meta( '_torobpay_instore_custom_amount' );
}

/**
 * Add a non-catalog line that carries the merchant-entered payable total.
 *
 * @param object $order  Order.
 * @param float  $amount Shop-currency amount.
 * @return void
 */
function torobpay_add_instore_custom_amount_item( $order, $amount ) {
	if ( ! $order || ! method_exists( $order, 'add_item' ) || ! class_exists( 'WC_Order_Item_Product' ) ) {
		return;
	}

	$amount = (float) $amount;
	if ( $amount <= 0 ) {
		return;
	}

	$item = new WC_Order_Item_Product();
	$item->set_name( __( 'In-store sale', 'torobpay-for-woocommerce' ) );
	$item->set_quantity( 1 );
	$item->set_subtotal( $amount );
	$item->set_total( $amount );
	if ( method_exists( $item, 'set_tax_status' ) ) {
		$item->set_tax_status( 'none' );
	}
	$item->add_meta_data( '_torobpay_instore_custom_amount', 'yes', true );
	$order->add_item( $item );

	if ( method_exists( $order, 'update_meta_data' ) ) {
		$order->update_meta_data( '_torobpay_instore_custom_amount', 'yes' );
	}
}

/**
 * Point WooCommerce's thank-you/return URL at the in-store result page.
 *
 * @param string      $url   Default return URL.
 * @param object|null $order Order.
 * @return string
 */
function torobpay_filter_instore_return_url( $url, $order = null ) {
	if ( ! $order || ! torobpay_is_instore_order( $order ) ) {
		return $url;
	}

	$state = torobpay_get_instore_payment_watch_state( $order );

	return torobpay_get_instore_result_url( $order, 'paid' === $state ? 'success' : 'failed' );
}

add_filter( 'woocommerce_payment_complete_order_status', 'torobpay_instore_payment_complete_status', 10, 3 );
add_filter( 'woocommerce_order_needs_shipping_address', 'torobpay_instore_needs_shipping_address', 10, 2 );
add_filter( 'woocommerce_get_return_url', 'torobpay_filter_instore_return_url', 10, 2 );
