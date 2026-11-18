<?php
/**
 * In-store payment watch state, QR URL mint/reuse, and gateway request.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Local in-store payment watch state (WooCommerce order + cached gateway status).
 *
 * Not a TorobPay status poll. Paid is WC after verify; failed is cached
 * FAILED/REVERT (or WC failed). WC stays pending until payment_complete or cancel.
 *
 * @param mixed $order Order object.
 * @return string pending|paid|failed|closed
 */
function torobpay_get_instore_payment_watch_state( $order ) {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) ) {
		return 'pending';
	}

	$status = (string) $order->get_status();
	if ( in_array( $status, array( 'refunded', 'cancelled' ), true ) ) {
		return 'closed';
	}

	$cached = '';
	if ( function_exists( 'torobpay_get_cached_payment_status' ) ) {
		$cached = strtoupper( (string) torobpay_get_cached_payment_status( $order ) );
	} elseif ( method_exists( $order, 'get_meta' ) ) {
		$cached = strtoupper( (string) $order->get_meta( '_torobpay_cached_status' ) );
	}

	$has_paid_flags = in_array( $cached, array( 'VERIFY', 'SETTLE' ), true );
	if ( method_exists( $order, 'get_meta' ) ) {
		// Successful VERIFY is payment: TorobPay auto-heals a failed settle.
		// WooCommerce may stay on-hold until that heal, matching the gateway
		// callback which still redirects as success after verify.
		$has_paid_flags = $has_paid_flags
			|| ( 'yes' === $order->get_meta( '_torobpay_settle_handled' ) )
			|| ( 'yes' === $order->get_meta( '_torobpay_verified_by_api' ) );
	}

	if ( in_array( $status, array( 'completed', 'processing' ), true )
		|| ( method_exists( $order, 'is_paid' ) && $order->is_paid() )
		|| ( method_exists( $order, 'get_date_paid' ) && $order->get_date_paid() )
		|| $has_paid_flags ) {
		return 'paid';
	}

	if ( 'failed' === $status || in_array( $cached, array( 'REVERT', 'FAILED' ), true ) ) {
		return 'failed';
	}

	return 'pending';
}

/**
 * Persist a failed callback without changing WooCommerce status.
 *
 * Only POST state=OK is a successful return. state=FAILED, or no state at all,
 * means the payment failed and that token is dead; in-store watch and retry
 * must mint a new URL. The order stays pending until payment_complete or cancel.
 * Writes through torobpay_cache_payment_status() so admin status, orders-table
 * filters, and paid-action checks stay in sync. Do not call this when callback
 * state is OK: a failed verify can still be W_FOR_VERIFY (API PENDING).
 *
 * @param mixed $order Order object.
 * @return void
 */
function torobpay_record_failed_gateway_return( $order ) {
	if ( ! is_object( $order ) || ! function_exists( 'torobpay_cache_payment_status' ) ) {
		return;
	}

	torobpay_cache_payment_status( $order, 'FAILED' );
}

/**
 * Whether the merchant may show or regenerate a payment QR for this order.
 *
 * Hidden after a successful capture (including later refunds) so a new TorobPay
 * payment cannot be started against a WooCommerce-refunded order.
 *
 * @param mixed $order Order object.
 * @return bool
 */
function torobpay_instore_order_allows_payment_qr( $order ) {
	if ( ! torobpay_is_instore_order( $order ) ) {
		return false;
	}

	$state = torobpay_get_instore_payment_watch_state( $order );
	return in_array( $state, array( 'pending', 'failed' ), true );
}

/**
 * Whether a new TorobPay payment URL should be minted for this watch state.
 *
 * Pending orders reuse a stored URL so a late QR click cannot start a second
 * charge while the customer is still paying. A failed return (state=FAILED or
 * no state; only state=OK is success) mints a new token; the previous URL is dead.
 *
 * @param string $state         Watch state.
 * @param string $existing_url Stored paymentPageUrl.
 * @return bool
 */
function torobpay_instore_should_request_new_payment_url( $state, $existing_url = '' ) {
	$state        = (string) $state;
	$existing_url = trim( (string) $existing_url );

	if ( 'failed' === $state ) {
		return true;
	}

	return ( 'pending' === $state && '' === $existing_url );
}

/**
 * Reuse a stored paymentPageUrl, or mint a new one when the watch state allows it.
 *
 * Pending orders keep the existing TorobPay session so a late QR click or customer
 * retry cannot start a second charge. Failed payments mint a fresh URL.
 *
 * @param object $order Order.
 * @param string $state Watch state from torobpay_get_instore_payment_watch_state().
 * @return string|\WP_Error
 */
function torobpay_resolve_instore_payment_page_url( $order, $state ) {
	$existing_url = ( is_object( $order ) && method_exists( $order, 'get_meta' ) )
		? trim( (string) $order->get_meta( '_torobpay_payment_page_url' ) )
		: '';

	if ( ! torobpay_instore_should_request_new_payment_url( $state, $existing_url ) ) {
		if ( '' === $existing_url || ! torobpay_instore_order_allows_payment_qr( $order ) ) {
			return new WP_Error(
				'torobpay_instore_no_payment_url',
				__( 'A new payment QR cannot be created for this order.', 'torobpay-for-woocommerce' )
			);
		}
		return $existing_url;
	}

	if ( ! torobpay_instore_order_allows_payment_qr( $order ) ) {
		return new WP_Error(
			'torobpay_instore_no_payment_url',
			__( 'A new payment QR cannot be created for this order.', 'torobpay-for-woocommerce' )
		);
	}

	return torobpay_request_instore_payment_url( $order );
}

/**
 * Refresh in-store watch state from WooCommerce, then optionally TorobPay.
 *
 * Result-page GET and admin QR polls stay local. Remote is only for show-QR
 * and retry (before minting a payment URL) so VERIFY/SETTLE can finish capture.
 *
 * @param object $order         Order.
 * @param bool   $check_remote  Whether to call TorobPay when the order is still pending.
 * @return array{order:object,state:string}
 */
function torobpay_refresh_instore_payment_watch_state( $order, $check_remote = true ) {
	$state = torobpay_get_instore_payment_watch_state( $order );
	if ( 'pending' !== $state || ! $check_remote || ! function_exists( 'torobpay_fetch_remote_status' ) ) {
		return array(
			'order' => $order,
			'state' => $state,
		);
	}

	$remote = torobpay_fetch_remote_status( $order );
	if ( ! is_array( $remote ) || empty( $remote['status'] ) ) {
		return array(
			'order' => $order,
			'state' => $state,
		);
	}

	$status            = strtoupper( (string) $remote['status'] );
	$txn               = isset( $remote['transaction_id'] ) ? (string) $remote['transaction_id'] : '';
	$token             = isset( $remote['token'] ) ? (string) $remote['token'] : '';
	$is_capture_status = in_array( $status, array( 'VERIFY', 'SETTLE' ), true );

	if ( $is_capture_status
		&& function_exists( 'torobpay_handle_status_response' )
		&& class_exists( 'TorobPayAPI' ) ) {
		if ( $txn && method_exists( $order, 'update_meta_data' ) ) {
			$order->update_meta_data( '_torobpay_api_transaction_id', $txn );
			if ( method_exists( $order, 'get_meta' ) && ! $order->get_meta( '_torobpay_transaction_id' ) ) {
				$order->update_meta_data( '_torobpay_transaction_id', $txn );
			}
			if ( method_exists( $order, 'save_meta_data' ) ) {
				$order->save_meta_data();
			} elseif ( method_exists( $order, 'save' ) ) {
				$order->save();
			}
		}

		$api = new TorobPayAPI();
		torobpay_handle_status_response( $order, $status, $api, $token, $order->get_status() );
		if ( function_exists( 'torobpay_get_fresh_order' ) && method_exists( $order, 'get_id' ) ) {
			$fresh = torobpay_get_fresh_order( $order->get_id() );
			if ( $fresh ) {
				$order = $fresh;
			}
		}
	} elseif ( ! $is_capture_status && function_exists( 'torobpay_cache_payment_status' ) ) {
		torobpay_cache_payment_status( $order, $status, $txn );
	}

	return array(
		'order' => $order,
		'state' => torobpay_get_instore_payment_watch_state( $order ),
	);
}

/**
 * Gateway instance for requesting a payment URL.
 *
 * @return object|null
 */
function torobpay_get_gateway_instance() {
	if ( ! function_exists( 'WC' ) || ! WC() || ! method_exists( WC(), 'payment_gateways' ) ) {
		return null;
	}

	$gateways_handler = WC()->payment_gateways();
	if ( ! $gateways_handler || ! method_exists( $gateways_handler, 'payment_gateways' ) ) {
		return null;
	}

	$gateways = $gateways_handler->payment_gateways();
	if ( isset( $gateways['WC_Gateway_TorobPay'] ) ) {
		return $gateways['WC_Gateway_TorobPay'];
	}

	return null;
}

/**
 * Request a TorobPay paymentPageUrl. Gateway order notes stay on the admin order screen.
 *
 * @param object $order Order.
 * @return string|\WP_Error
 */
function torobpay_request_instore_payment_url( $order ) {
	$gateway = torobpay_get_gateway_instance();
	if ( ! $gateway || ! method_exists( $gateway, 'request_gateway_payment_url' ) ) {
		return new WP_Error(
			'torobpay_no_gateway',
			__( 'TorobPay gateway is not available.', 'torobpay-for-woocommerce' )
		);
	}

	return $gateway->request_gateway_payment_url( $order );
}
