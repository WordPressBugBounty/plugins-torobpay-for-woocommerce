<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** TorobPay order payment state helpers. */

function torobpay_invalidate_order_cache( $order_id ) {
	if ( function_exists( 'clean_post_cache' ) ) {
		clean_post_cache( $order_id );
	}

	if ( class_exists( 'WC_Cache_Helper' ) && method_exists( 'WC_Cache_Helper', 'invalidate_cache_group' ) ) {
		WC_Cache_Helper::invalidate_cache_group( 'order_' . $order_id );
	}

	// HPOS OrderCache (WC 7.4+, default since WC 9.x)
	if ( function_exists( 'wc_get_container' ) ) {
		try {
			$order_cache = wc_get_container()->get(
				\Automattic\WooCommerce\Caching\OrderCache::class
			);
			$order_cache->remove( $order_id );
		} catch ( \Exception $e ) {
		}
	}

	wp_cache_delete( $order_id, 'orders' );
	wp_cache_delete( $order_id, 'order-items' );
	wp_cache_delete( 'order-items-' . $order_id, 'orders' );
}

function torobpay_get_fresh_order( $order_id ) {
	torobpay_invalidate_order_cache( $order_id );
	$order = wc_get_order( $order_id );

	if ( $order && method_exists( $order, 'read_meta_data' ) ) {
		$order->read_meta_data( true );
	}

	return $order;
}
function torobpay_get_cached_payment_status( $order ) {
	if ( ! $order || ! method_exists( $order, 'get_meta' ) ) {
		return '';
	}

	return strtoupper( (string) ( $order->get_meta( '_torobpay_verification_status' ) ?: $order->get_meta( '_torobpay_cached_status' ) ) );
}

function torobpay_cached_status_age( $order ) {
	if ( ! $order || ! method_exists( $order, 'get_meta' ) ) {
		return null;
	}

	$timestamp = (int) ( $order->get_meta( '_torobpay_cached_status_time' ) ?: $order->get_meta( '_torobpay_verification_timestamp' ) );
	if ( $timestamp <= 0 ) {
		return null;
	}

	return max( 0, time() - $timestamp );
}

function torobpay_cache_payment_status( $order, $status, $transaction_id = '' ) {
	if ( ! $order || ! method_exists( $order, 'update_meta_data' ) ) {
		return;
	}

	$status = strtoupper( (string) $status );
	if ( $status === '' ) {
		return;
	}

	$timestamp = time();

	$order->update_meta_data( '_torobpay_cached_status', $status );
	$order->update_meta_data( '_torobpay_cached_status_time', $timestamp );
	$order->update_meta_data( '_torobpay_verification_status', $status );
	$order->update_meta_data( '_torobpay_verification_timestamp', $timestamp );

	if ( ! empty( $transaction_id ) ) {
		$order->update_meta_data( '_torobpay_api_transaction_id', $transaction_id );
		if ( ! $order->get_meta( '_torobpay_transaction_id' ) ) {
			$order->update_meta_data( '_torobpay_transaction_id', $transaction_id );
		}
	}

	$order->save_meta_data();
}

function torobpay_reset_payment_status_cache( $order, $status = '', $transaction_id = '' ) {
	if ( ! $order || ! method_exists( $order, 'delete_meta_data' ) ) {
		return;
	}

	$order->delete_meta_data( '_torobpay_verification_status' );
	$order->delete_meta_data( '_torobpay_verification_timestamp' );
	$order->delete_meta_data( '_torobpay_verification_hash' );
	$order->delete_meta_data( '_torobpay_api_transaction_id' );
	$order->delete_meta_data( '_torobpay_cached_status' );
	$order->delete_meta_data( '_torobpay_cached_status_time' );

	if ( $status !== '' ) {
		torobpay_cache_payment_status( $order, $status, $transaction_id );
		return;
	}

	$order->save_meta_data();
}

/** Only writer of `_torobpay_cancelled_api`; flag is bound to the active payment token. */
function torobpay_sync_confirmed_cancellation_flag( $order, $status, $transaction_id = '', $payment_token = '' ) {
	if ( ! $order || ! method_exists( $order, 'delete_meta_data' ) ) {
		return false;
	}

	$status        = strtoupper( (string) $status );
	$payment_token = (string) $payment_token;

	if ( $status === 'REVERT' ) {
		if ( $payment_token === '' ) {
			$payment_token = (string) torobpay_get_order_payment_token( $order );
		}

		$order->delete_meta_data( '_torobpay_verified_by_api' );
		$order->delete_meta_data( '_torobpay_settle_handled' );
		$order->update_meta_data( '_torobpay_cancelled_api', 'yes' );
		if ( $payment_token !== '' ) {
			$order->update_meta_data( '_torobpay_cancelled_token', $payment_token );
		}
		torobpay_reset_payment_status_cache( $order, 'REVERT', $transaction_id );
		torobpay_set_token_history_status( $order, $payment_token, 'REVERT' );
		return true;
	}

	$order->delete_meta_data( '_torobpay_cancelled_api' );
	$order->delete_meta_data( '_torobpay_cancelled_token' );

	if ( $status !== '' ) {
		torobpay_reset_payment_status_cache( $order, $status, $transaction_id );
	} else {
		$order->save_meta_data();
	}

	return false;
}

/** Untokened legacy cancel flags are treated as stale. */
function torobpay_has_confirmed_cancellation( $order, $payment_token = null ) {
	if ( ! $order || ! method_exists( $order, 'get_meta' ) ) {
		return false;
	}

	if ( $order->get_meta( '_torobpay_cancelled_api', true ) !== 'yes' ) {
		return false;
	}

	$cancelled_token = (string) $order->get_meta( '_torobpay_cancelled_token', true );
	if ( $cancelled_token === '' ) {
		return false;
	}

	if ( $payment_token === null ) {
		$payment_token = torobpay_get_order_payment_token( $order );
	}

	$payment_token = (string) $payment_token;

	return $payment_token !== '' && hash_equals( $cancelled_token, $payment_token );
}

/**
 * Clear token-scoped cancel/cache state when a new payment token is issued.
 *
 * Cached status becomes PENDING (new unpaid session).
 */
function torobpay_reset_state_for_new_payment_token( $order, $new_payment_token, $previous_payment_token = '' ) {
	if ( ! $order || ! method_exists( $order, 'delete_meta_data' ) ) {
		return;
	}

	$new_payment_token      = (string) $new_payment_token;
	$previous_payment_token = (string) $previous_payment_token;

	if ( $new_payment_token === '' || $previous_payment_token === '' || hash_equals( $new_payment_token, $previous_payment_token ) ) {
		return;
	}

	$order->delete_meta_data( '_torobpay_cancelled_api' );
	$order->delete_meta_data( '_torobpay_cancelled_token' );
	$order->delete_meta_data( '_torobpay_remote_locked' );
	$order->delete_meta_data( '_torobpay_verification_hash' );
	$order->delete_meta_data( '_torobpay_api_transaction_id' );
	$order->delete_meta_data( '_torobpay_last_status_recheck' );
	// Flags describe the current token. The previous token keeps its own history status.
	$order->delete_meta_data( '_torobpay_verified_by_api' );
	$order->delete_meta_data( '_torobpay_settle_handled' );

	// New token is unpaid (API PENDING). Replaces FAILED/REVERT from the previous session.
	torobpay_cache_payment_status( $order, 'PENDING' );
}

/**
 * API status values stored on a token-history entry.
 *
 * @param mixed $status Raw status.
 * @return string PENDING|VERIFY|SETTLE|REVERT, or empty when not one of those.
 */
function torobpay_normalize_history_status( $status ) {
	$status = strtoupper( (string) $status );
	if ( ! in_array( $status, array( 'PENDING', 'VERIFY', 'SETTLE', 'REVERT' ), true ) ) {
		return '';
	}

	return $status;
}

/**
 * @param mixed $order Order object.
 * @return array<int, array<string, mixed>>
 */
function torobpay_get_token_history( $order ) {
	if ( ! $order || ! method_exists( $order, 'get_meta' ) ) {
		return array();
	}

	$history = $order->get_meta( '_torobpay_token_history' );
	return is_array( $history ) ? $history : array();
}

/**
 * Newest issued token, paid or not.
 *
 * @param mixed $order Order object.
 * @return array<string, mixed>|null
 */
function torobpay_latest_token_history_entry( $order ) {
	$history = torobpay_get_token_history( $order );
	if ( empty( $history ) ) {
		return null;
	}

	$latest = $history[ count( $history ) - 1 ];
	return is_array( $latest ) ? $latest : null;
}

/**
 * Stored API status of the newest history entry. Empty when that entry predates status tracking.
 *
 * @param mixed $order Order object.
 * @return string
 */
function torobpay_latest_token_history_status( $order ) {
	$entry = torobpay_latest_token_history_entry( $order );
	if ( ! is_array( $entry ) || ! array_key_exists( 'status', $entry ) ) {
		return '';
	}

	return torobpay_normalize_history_status( $entry['status'] );
}

/**
 * Record an API status on every history entry for this token. Older entries are left as they are.
 *
 * @param mixed  $order  Order object.
 * @param string $token  Payment token.
 * @param string $status API status.
 * @return bool True when an entry was updated.
 */
function torobpay_set_token_history_status( $order, $token, $status ) {
	if ( ! $order || ! method_exists( $order, 'update_meta_data' ) ) {
		return false;
	}

	$status = torobpay_normalize_history_status( $status );
	$token  = (string) $token;
	if ( '' === $status || '' === $token ) {
		return false;
	}

	$history = torobpay_get_token_history( $order );
	$updated = false;
	foreach ( $history as $index => $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['token'] ) ) {
			continue;
		}
		if ( (string) $entry['token'] !== $token ) {
			continue;
		}
		$history[ $index ]['status'] = $status;
		$updated                     = true;
	}

	if ( ! $updated ) {
		return false;
	}

	$order->update_meta_data( '_torobpay_token_history', $history );
	if ( method_exists( $order, 'save_meta_data' ) ) {
		$order->save_meta_data();
	}

	return true;
}

/**
 * SETTLE wins over VERIFY. A later entry wins over an earlier one of the same rank.
 *
 * @param array<int, array<string, mixed>> $history Token history.
 * @return array<string, mixed>|null
 */
function torobpay_find_best_paid_history_entry( $history ) {
	if ( ! is_array( $history ) ) {
		return null;
	}

	$best      = null;
	$best_rank = 0;
	foreach ( $history as $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['token'] ) ) {
			continue;
		}
		$status = isset( $entry['status'] ) ? torobpay_normalize_history_status( $entry['status'] ) : '';
		$rank   = ( 'SETTLE' === $status ) ? 2 : ( ( 'VERIFY' === $status ) ? 1 : 0 );
		if ( $rank > $best_rank || ( $rank > 0 && $rank === $best_rank ) ) {
			$best      = $entry;
			$best_rank = $rank;
		}
	}

	return $best;
}

/**
 * Make a paid history entry the current token and mirror it onto the order-level flags.
 *
 * @param mixed                $order Order object.
 * @param array<string, mixed> $entry History entry with token and status.
 * @return bool
 */
function torobpay_promote_paid_history_entry( $order, $entry ) {
	if ( ! $order || ! method_exists( $order, 'update_meta_data' ) || ! is_array( $entry ) || empty( $entry['token'] ) ) {
		return false;
	}

	$status = isset( $entry['status'] ) ? torobpay_normalize_history_status( $entry['status'] ) : '';
	if ( ! in_array( $status, array( 'VERIFY', 'SETTLE' ), true ) ) {
		return false;
	}

	$token = (string) $entry['token'];
	$txn   = isset( $entry['transaction_id'] ) ? (string) $entry['transaction_id'] : '';

	$order->update_meta_data( '_order_torobpay_token', $token );
	if ( '' !== $txn ) {
		$order->update_meta_data( '_torobpay_transaction_id', $txn );
	}

	if ( 'SETTLE' === $status ) {
		$order->update_meta_data( '_torobpay_settle_handled', 'yes' );
		$order->update_meta_data( '_torobpay_verified_by_api', 'yes' );
	} else {
		$order->delete_meta_data( '_torobpay_settle_handled' );
		$order->update_meta_data( '_torobpay_verified_by_api', 'yes' );
	}

	torobpay_cache_payment_status( $order, $status, $txn );
	if ( method_exists( $order, 'save' ) ) {
		$order->save();
	}

	return true;
}

/**
 * What a repeat checkout should do from stored history, before any HTTP call.
 *
 * @param array<int, array<string, mixed>> $history Token history.
 * @return string already_settled|needs_settle|mint|needs_status
 */
function torobpay_mint_action_for_history( $history ) {
	$paid = torobpay_find_best_paid_history_entry( $history );
	if ( is_array( $paid ) ) {
		$status = torobpay_normalize_history_status( $paid['status'] );
		return ( 'SETTLE' === $status ) ? 'already_settled' : 'needs_settle';
	}

	if ( ! is_array( $history ) || empty( $history ) ) {
		return 'mint';
	}

	$latest = $history[ count( $history ) - 1 ];
	if ( ! is_array( $latest ) || ! array_key_exists( 'status', $latest ) ) {
		return 'needs_status';
	}

	return 'mint';
}

/**
 * Prefer a stored SETTLE, then VERIFY, when the live current-token status is not already paid.
 *
 * @param array<string, mixed>|null        $remote  Live status result, or null.
 * @param array<int, array<string, mixed>> $history Token history.
 * @return array<string, mixed>|null
 */
function torobpay_prefer_paid_history_over_remote( $remote, $history ) {
	$remote_status = ( is_array( $remote ) && isset( $remote['status'] ) ) ? torobpay_normalize_history_status( $remote['status'] ) : '';
	if ( in_array( $remote_status, array( 'VERIFY', 'SETTLE' ), true ) ) {
		return $remote;
	}

	$paid = torobpay_find_best_paid_history_entry( $history );
	if ( ! is_array( $paid ) ) {
		return $remote;
	}

	return array(
		'status'         => torobpay_normalize_history_status( $paid['status'] ),
		'transaction_id' => isset( $paid['transaction_id'] ) ? (string) $paid['transaction_id'] : '',
		'token'          => (string) $paid['token'],
	);
}

/**
 * Pending and failed orders can still be marked paid. Cancelled and closed orders cannot.
 *
 * @param mixed $order Order object.
 * @return bool
 */
function torobpay_order_can_heal_paid_token( $order ) {
	return $order && method_exists( $order, 'has_status' ) && $order->has_status( array( 'pending', 'failed' ) );
}

function torobpay_mark_refund_torobpay_confirmed( $refund ) {
	if ( ! $refund instanceof WC_Order_Refund ) {
		$refund = wc_get_order( $refund );
	}
	if ( ! $refund instanceof WC_Order_Refund ) {
		return;
	}

	$refund->update_meta_data( '_torobpay_confirmed_refund', 'yes' );
	$refund->save();
}

function torobpay_refund_is_torobpay_confirmed( $refund ) {
	if ( ! $refund instanceof WC_Order_Refund ) {
		$refund = wc_get_order( $refund );
	}
	if ( ! $refund instanceof WC_Order_Refund ) {
		return false;
	}

	if ( $refund->get_meta( '_torobpay_confirmed_refund' ) === 'yes' ) {
		return true;
	}

	$reason = (string) $refund->get_reason();

	// Match current locale msgid and legacy Persian reason stored by older installs.
	$prefixes = array_unique(
		array(
			__( 'TorobPay refund', 'torobpay-for-woocommerce' ),
			'TorobPay refund',
			'بازگشت وجه ترب‌پی',
		)
	);

	foreach ( $prefixes as $prefix ) {
		if ( '' !== $prefix && 0 === strpos( $reason, $prefix ) ) {
			return true;
		}
	}

	return false;
}

/** Uses $from_status because $order may already reflect cancelled/refunded when the hook runs. */
function torobpay_order_was_paid_before_downgrade( $order, $from_status ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	$cached_status = function_exists( 'torobpay_get_cached_payment_status' ) ? torobpay_get_cached_payment_status( $order ) : '';
	if ( in_array( $cached_status, array( 'VERIFY', 'SETTLE' ), true ) ) {
		return true;
	}

	if ( $order->get_meta( '_torobpay_settle_handled', true ) === 'yes' || $order->get_meta( '_torobpay_verified_by_api', true ) === 'yes' ) {
		return true;
	}

	if ( $order->get_date_paid() ) {
		return true;
	}

	return in_array( (string) $from_status, array( 'processing', 'completed' ), true );
}

/** Cached remote status is payment truth; legacy flags are a last-resort empty-cache fallback only. */
function torobpay_is_order_paid_for_actions( $order ) {
	if ( ! $order || ! method_exists( $order, 'get_status' ) ) {
		return false;
	}

	$cached_status = torobpay_get_cached_payment_status( $order );
	if ( $cached_status !== '' ) {
		return in_array( $cached_status, array( 'VERIFY', 'SETTLE' ), true );
	}

	// Legacy empty-cache fallback: durable paid flags survive refund/cancel.
	if ( in_array( $order->get_status(), array( 'refunded', 'cancelled', 'failed' ), true ) ) {
		return false;
	}

	if ( $order->get_meta( '_torobpay_settle_handled', true ) === 'yes' || $order->get_meta( '_torobpay_verified_by_api', true ) === 'yes' ) {
		return true;
	}

	if ( $order->get_date_paid() ) {
		return true;
	}

	return in_array( $order->get_status(), array( 'processing', 'completed' ), true );
}

/** Short-circuit for edit/refund actions; VERIFY must still be settled first. */
function torobpay_has_settled_payment_for_actions( $order ) {
	if ( ! $order || ! method_exists( $order, 'get_meta' ) ) {
		return false;
	}

	$cached_status = torobpay_get_cached_payment_status( $order );
	if ( $cached_status === 'SETTLE' ) {
		return true;
	}

	return $order->get_meta( '_torobpay_settle_handled', true ) === 'yes';
}

function torobpay_run_action_preflight( $order ) {
	if ( ! $order || ! method_exists( $order, 'get_payment_method' ) ) {
		return array(
			'success'    => false,
			'message'    => __( 'Order not found', 'torobpay-for-woocommerce' ),
			'raw_status' => '',
		);
	}

	$order_payment_method = $order->get_payment_method();
	if ( ! torobpay_is_torobpay_payment_method( $order_payment_method ) ) {
		return array(
			'success'    => false,
			'message'    => __( 'This order is not a TorobPay order', 'torobpay-for-woocommerce' ),
			'raw_status' => '',
		);
	}

	$payment_token = torobpay_get_order_payment_token( $order );
	if ( ! $payment_token ) {
		return array(
			'success'    => false,
			'message'    => __( 'Payment token was not found for this order', 'torobpay-for-woocommerce' ),
			'raw_status' => '',
		);
	}

	// Fresh SETTLE (<20s) can skip a live status round-trip.
	$cached_status = torobpay_get_cached_payment_status( $order );
	$cache_age     = torobpay_cached_status_age( $order );
	if ( 'SETTLE' === $cached_status
		&& null !== $cache_age
		&& $cache_age < 20
		&& 'yes' === $order->get_meta( '_torobpay_settle_handled', true ) ) {
		return array(
			'success'    => true,
			'message'    => '',
			'raw_status' => 'SETTLE',
		);
	}

	$api      = new TorobPayAPI();
	$response = $api->status( $payment_token );

	if ( is_wp_error( $response ) || ! isset( $response['successful'] ) || ! $response['successful'] ) {
		return array(
			'success'    => false,
			'message'    => __( 'Error checking payment status. Please try again.', 'torobpay-for-woocommerce' ),
			'raw_status' => '',
		);
	}

	$status         = isset( $response['response']['status'] ) ? strtoupper( (string) $response['response']['status'] ) : '';
	$transaction_id = isset( $response['response']['transactionId'] ) ? (string) $response['response']['transactionId'] : '';

	torobpay_sync_confirmed_cancellation_flag( $order, $status, $transaction_id );

	switch ( $status ) {
		case 'VERIFY':
			if ( ! torobpay_process_verification( $order, $api, $payment_token, $order->get_status() ) ) {
				return array(
					'success'    => false,
					'message'    => __( 'Settlement with TorobPay failed. Please try again.', 'torobpay-for-woocommerce' ),
					'raw_status' => $status,
				);
			}

			torobpay_cache_payment_status(
				$order,
				'SETTLE',
				$transaction_id ?: (string) $order->get_meta( '_torobpay_transaction_id' )
			);

			return array(
				'success'    => true,
				'message'    => '',
				'raw_status' => 'SETTLE',
			);

		case 'SETTLE':
			if ( ! torobpay_handle_status_response( $order, 'SETTLE', $api, $payment_token, $order->get_status() ) ) {
				return array(
					'success'    => false,
					'message'    => __( 'Error finalizing payment status. Please try again.', 'torobpay-for-woocommerce' ),
					'raw_status' => $status,
				);
			}

			torobpay_cache_payment_status(
				$order,
				'SETTLE',
				$transaction_id ?: (string) $order->get_meta( '_torobpay_transaction_id' )
			);

			return array(
				'success'    => true,
				'message'    => '',
				'raw_status' => 'SETTLE',
			);

		case 'PENDING':
			return array(
				'success'    => false,
				'message'    => __( 'This order is not paid yet and cannot be edited or refunded.', 'torobpay-for-woocommerce' ),
				'raw_status' => $status,
			);

		case 'REVERT':
			return array(
				'success'    => false,
				'message'    => __( 'This order is cancelled or failed and cannot be edited or refunded.', 'torobpay-for-woocommerce' ),
				'raw_status' => $status,
			);

		default:
			return array(
				'success'    => false,
				'message'    => __( 'Payment status is unknown. Please contact support.', 'torobpay-for-woocommerce' ),
				'raw_status' => $status,
			);
	}
}

/** Remote cancel primitive; runs before any local refund is created. */
function torobpay_cancel_order( $order_id, $from_status ) {
	$order = torobpay_get_fresh_order( $order_id );
	if ( ! $order ) {
		return true; // do not block when order is not available
	}

	$order_payment_method = $order->get_payment_method();
	if ( ! torobpay_is_torobpay_payment_method( $order_payment_method ) ) {
		return true;
	}

	// Sub-orders have no token; don't note or strip payment meta on them.
	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		return true;
	}

	$order_payment_token = torobpay_get_order_payment_token( $order );
	if ( ! $order_payment_token ) {
		$order->delete_meta_data( '_torobpay_verified_by_api' );
		$order->delete_meta_data( '_torobpay_settle_handled' );
		$order->delete_meta_data( '_torobpay_success_note_added' );
		$order->delete_meta_data( '_torobpay_settle_failed_note_added' );

		$order->add_order_note( '<span class="torobpay-notice">' . esc_html__( 'TorobPay: Payment token was not found for this order. Cancel/refund was not performed in TorobPay.', 'torobpay-for-woocommerce' ) . '</span>' );
		$order->save();
		return true;
	}

	$torobPayAPI = new TorobPayAPI();

	if ( torobpay_has_confirmed_cancellation( $order, $order_payment_token ) ) {
		$order->add_order_note(
			'<span class="torobpay-notice">'
			. esc_html__( 'TorobPay: Order cancellation', 'torobpay-for-woocommerce' )
			. ' <strong>'
			. esc_html__( 'was already performed and a duplicate was prevented', 'torobpay-for-woocommerce' )
			. '</strong>.</span>'
		);
		return true;
	}
	if ( 'pending' === $from_status ) {
		$already_settled = $order->get_meta( '_torobpay_settle_handled', true ) === 'yes';
		$already_noted   = $order->get_meta( '_torobpay_success_note_added', true ) === 'yes';

		if ( ! $already_settled && ! $already_noted ) {
			torobpay_rescue_payment_status( $order, $order_id, $from_status );
		} else {
			torobpay_debug_log(
				sprintf(
					'TorobPay Debug: Skipping rescue for order #%d - payment was already settled/finalized before this operation (settle_handled: %s, success_note: %s)',
					$order_id,
					$already_settled ? 'yes' : 'no',
					$already_noted ? 'yes' : 'no'
				)
			);
		}
		return true; // Hook checks meta flags for final status decision
	}

	$response = torobpay_retry_api_call(
		function () use ( $torobPayAPI, $order_payment_token ) {
			return $torobPayAPI->cancel( $order_payment_token );
		},
		3,
		'Cancel',
		$order_id
	);

	$api_success = ! is_wp_error( $response ) &&
					is_array( $response ) &&
					isset( $response['successful'] ) &&
					$response['successful'];

	if ( $api_success ) {
		torobpay_sync_confirmed_cancellation_flag(
			$order,
			'REVERT',
			(string) ( $order->get_meta( '_torobpay_transaction_id' ) ?: '' ),
			$order_payment_token
		);
		torobpay_handle_response( $order, $response );
		$order->save();
		return true;
	}

	torobpay_handle_response( $order, $response );
	return false;
}

/** Surface settled-in-TorobPay / unchanged-in-WC mismatches as Action Needed. */
function torobpay_flag_stalled_paid_finalize( $order, $from_status ) {
	if ( ! $order instanceof WC_Order || '' === (string) $from_status ) {
		return;
	}

	$fresh_order = torobpay_get_fresh_order( $order->get_id() ) ?: $order;
	$to_status   = $fresh_order->get_status();

	if ( $to_status !== (string) $from_status ) {
		return;
	}
	if ( function_exists( 'torobpay_flag_remote_wc_status_downgrade_divergence' )
		&& torobpay_flag_remote_wc_status_downgrade_divergence( $fresh_order, 'SETTLE' ) ) {
		return;
	}

	if ( ! function_exists( 'torobpay_set_action_required' ) ) {
		return;
	}

	$status_label = function_exists( 'torobpay_wc_status_label' ) ? torobpay_wc_status_label( $to_status ) : $to_status;
	$code         = in_array( $to_status, array( 'cancelled', 'refunded', 'failed' ), true )
		? ( function_exists( 'torobpay_get_downgrade_action_code' ) ? torobpay_get_downgrade_action_code( $fresh_order ) : 'manual_downgrade' )
		: ( function_exists( 'torobpay_get_settle_divergence_code' ) ? torobpay_get_settle_divergence_code( $fresh_order ) : 'settle_divergence' );

	torobpay_set_action_required(
		$fresh_order,
		$code,
		( 'remote_locked' === $code )
			? ( function_exists( 'torobpay_get_remote_locked_action_reason' ) ? torobpay_get_remote_locked_action_reason( $fresh_order ) : '' )
			: sprintf(
				/* translators: %s: WooCommerce order status label */
				__( 'Payment was settled in TorobPay but WooCommerce did not change the order status from "%s".', 'torobpay-for-woocommerce' ),
				$status_label
			)
	);
}

/**
 * Mark a settled TorobPay order as paid in WooCommerce.
 *
 * Success is determined by a fresh is_paid() postcondition, not by
 * payment_complete()'s return value (which can be true while status stays pending).
 *
 * @param WC_Order $order          Order to finalize.
 * @param string   $transaction_id Optional transaction id for payment_complete().
 * @return bool True when WooCommerce considers the order paid; false otherwise.
 */
function torobpay_finalize_paid_order( $order, $transaction_id = '' ) {
	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	$order_payment_method = $order->get_payment_method();
	if ( ! torobpay_is_torobpay_payment_method( $order_payment_method ) ) {
		return false;
	}

	$from_status = $order->get_status();

	// On-hold handling:
	// - edit-items on-hold: never call payment_complete() (even if also stalled).
	// - stalled-finalize on-hold: allow payment_complete() retry via rescue/probe.
	// - any other on-hold: respect manual hold.
	if ( $order->has_status( 'on-hold' ) ) {
		$is_edit_hold    = $order->get_meta( '_torobpay_editing_items' ) === 'yes';
		$is_stalled_hold = $order->get_meta( '_torobpay_stalled_finalize' ) === 'yes';

		if ( $is_edit_hold || ! $is_stalled_hold ) {
			torobpay_flag_stalled_paid_finalize( $order, $from_status );
			return false;
		}
	}

	if ( $order->is_paid() ) {
		if ( 'yes' === $order->get_meta( '_torobpay_stalled_finalize', true ) ) {
			$order->delete_meta_data( '_torobpay_stalled_finalize' );
			$order->save_meta_data();
		}
		if ( function_exists( 'torobpay_clear_action_required' ) ) {
			torobpay_clear_action_required( $order );
		}
		return true;
	}

	$payment_complete_result = $order->payment_complete( $transaction_id ?: '' );
	torobpay_debug_log(
		sprintf(
			'TorobPay: payment_complete() returned %s for order #%d (pre-status: %s)',
			$payment_complete_result ? 'true' : 'false',
			$order->get_id(),
			$from_status
		)
	);

	$fresh = torobpay_get_fresh_order( $order->get_id() ) ?: $order;
	if ( $fresh->is_paid() ) {
		if ( $order !== $fresh && $order->get_status() !== $fresh->get_status() ) {
			$order->set_status( $fresh->get_status(), '', false );
		}
		if ( 'yes' === $order->get_meta( '_torobpay_stalled_finalize', true ) ) {
			$order->delete_meta_data( '_torobpay_stalled_finalize' );
			$order->save_meta_data();
		}
		if ( function_exists( 'torobpay_clear_action_required' ) ) {
			torobpay_clear_action_required( $order );
		}
		return true;
	}

	// Still unpaid after payment_complete — protect pending orders from hold-stock cancel.
	$db_status = $fresh->get_status();
	if ( 'pending' === $db_status ) {
		if ( $order->get_status() !== 'pending' ) {
			$order->set_status( 'pending', '', false );
		}
		$order->update_meta_data( '_torobpay_stalled_finalize', 'yes' );
		$order->update_status(
			'on-hold',
			'<span class="torobpay-notice">' . esc_html__( 'TorobPay: Payment was settled in TorobPay but WooCommerce did not mark the order as paid. The order was placed On hold to prevent automatic cancellation. Please change the status to Processing or Completed.', 'torobpay-for-woocommerce' ) . '</span>'
		);
		if ( function_exists( 'torobpay_set_action_required' ) ) {
			// Use settle_on_hold with an accurate reason (do not pass post-remediation
			// status into torobpay_flag_stalled_paid_finalize — that claims WC never
			// left on-hold and duplicates a misleading Action Needed note).
			torobpay_set_action_required(
				$order,
				'settle_on_hold',
				__( 'Payment was settled in TorobPay but WooCommerce did not move the order from Pending payment to a paid status. The order was placed On hold to prevent automatic cancellation.', 'torobpay-for-woocommerce' )
			);
		}
	} else {
		if ( $order !== $fresh && $order->get_status() !== $db_status ) {
			$order->set_status( $db_status, '', false );
		}
		torobpay_flag_stalled_paid_finalize( $order, $from_status );
	}

	return false;
}

function torobpay_rescue_payment_status( $order, $order_id, $from_status ) {
	static $rescue_attempted = array();
	if ( isset( $rescue_attempted[ $order_id ] ) ) {
		return false;
	}
	$rescue_attempted[ $order_id ] = true;
	$order = torobpay_get_fresh_order( $order_id );
	if ( ! $order ) {
		torobpay_debug_log( 'TorobPay Debug: RESCUE aborted - Order not found: ' . $order_id );
		return false;
	}
	$order_payment_token = torobpay_get_order_payment_token( $order, true );

	if ( ! $order_payment_token ) {
		torobpay_debug_log( 'TorobPay Debug: RESCUE aborted - No payment token for order ' . $order_id );
		return false;
	}

	$order_transaction_id = $order->get_meta( '_torobpay_transaction_id' );
	if ( empty( $order_transaction_id ) ) {
		$order_transaction_id = $order->get_meta( '_transactionId' );
	}

	if ( empty( $order_transaction_id ) ) {
		torobpay_debug_log( 'TorobPay Debug: RESCUE aborted - No transaction ID for order ' . $order_id . ', cannot validate API response ownership' );
		return false;
	}

	$torobPayAPI     = new TorobPayAPI();
	$status_response = $torobPayAPI->status( $order_payment_token );

	$primary_check_ok = ! is_wp_error( $status_response ) && is_array( $status_response ) &&
		isset( $status_response['successful'] ) && $status_response['successful'] &&
		isset( $status_response['response']['status'] );

	if ( $primary_check_ok ) {
		$api_transaction_id = isset( $status_response['response']['transactionId'] ) ? $status_response['response']['transactionId'] : null;
		if ( $api_transaction_id && $api_transaction_id !== $order_transaction_id ) {
			torobpay_debug_log(
				sprintf(
					'TorobPay SECURITY: RESCUE blocked - Transaction ID mismatch! Order #%d expected "%s" but API returned "%s". Payment token: %s',
					$order_id,
					$order_transaction_id,
					$api_transaction_id,
					$order_payment_token
				)
			);
			$primary_check_ok = false;
		}
	}

	if ( $primary_check_ok ) {
		$response_status = $status_response['response']['status'];
		if ( in_array( $response_status, array( 'VERIFY', 'SETTLE', 'PENDING' ), true ) ) {
			$result = torobpay_handle_status_response( $order, $response_status, $torobPayAPI, $order_payment_token, $from_status );
			torobpay_debug_log( 'TorobPay Debug: RESCUE completed with result: ' . ( $result ? 'SUCCESS' : 'FAILURE' ) . ' for order ' . $order_id );
			return $result;
		}
	}

	// Token history fallback when the active token was overwritten.
	$token_history = $order->get_meta( '_torobpay_token_history' );
	if ( is_array( $token_history ) && count( $token_history ) > 0 ) {
		foreach ( $token_history as $entry ) {
			$hist_token = $entry['token'];
			if ( $hist_token === $order_payment_token ) {
				continue;
			}

			$hist_response = $torobPayAPI->status( $hist_token );
			if ( ! is_wp_error( $hist_response ) && is_array( $hist_response ) &&
				isset( $hist_response['successful'] ) && $hist_response['successful'] &&
				isset( $hist_response['response']['status'] )
			) {
				$hist_status = $hist_response['response']['status'];
				if ( in_array( $hist_status, array( 'VERIFY', 'SETTLE', 'PENDING' ), true ) ) {
					$order->update_meta_data( '_order_torobpay_token', $hist_token );
					if ( ! empty( $entry['transaction_id'] ) ) {
						$order->update_meta_data( '_torobpay_transaction_id', $entry['transaction_id'] );
					}
					$order->save();

					torobpay_debug_log( 'TorobPay RESCUE: Recovered paid token ' . substr( $hist_token, 0, 8 ) . '... (' . $hist_status . ') for order ' . $order_id );

					return torobpay_handle_status_response( $order, $hist_status, $torobPayAPI, $hist_token, $from_status );
				}
			}
		}
	}
	if ( $primary_check_ok ) {
		return torobpay_handle_status_response( $order, $status_response['response']['status'], $torobPayAPI, $order_payment_token, $from_status );
	}

	torobpay_debug_log( 'TorobPay RESCUE: No paid token found for order ' . $order_id );
	return false;
}

function torobpay_handle_status_response( $order, $status, $torobPayAPI, $token, $from_status ) {
	$order_token = torobpay_get_order_payment_token( $order, true );
	if ( $token && $order_token && $token !== $order_token ) {
		$token_in_history = false;
		$token_history    = $order->get_meta( '_torobpay_token_history' );
		if ( is_array( $token_history ) ) {
			foreach ( $token_history as $entry ) {
				if ( isset( $entry['token'] ) && $entry['token'] === $token ) {
					$token_in_history = true;
					break;
				}
			}
		}

		if ( ! $token_in_history ) {
			torobpay_debug_log(
				sprintf(
					'TorobPay SECURITY: Token mismatch! Order #%d has "%s" but processing "%s"',
					$order->get_id(),
					$order_token,
					$token
				)
			);
			return false;
		}
	}

	$transaction_id = (string) (
		$order->get_meta( '_torobpay_api_transaction_id' ) ?:
		$order->get_meta( '_torobpay_transaction_id' ) ?:
		$order->get_meta( '_transactionId' )
	);

	switch ( $status ) {
		case 'REVERT':
			torobpay_sync_confirmed_cancellation_flag( $order, 'REVERT', $transaction_id, $token );
			return false;

		case 'VERIFY':
			torobpay_sync_confirmed_cancellation_flag( $order, 'VERIFY', $transaction_id, $token );
			$order->update_meta_data( '_torobpay_verified_by_api', 'yes' );
			$order->save();
			torobpay_set_token_history_status( $order, (string) $token, 'VERIFY' );
			$verified = torobpay_process_verification( $order, $torobPayAPI, $token, $from_status );

			if ( $verified ) {
				torobpay_set_token_history_status(
					$order,
					(string) $token,
					'SETTLE'
				);
			}

			return $verified;

		case 'SETTLE':
			torobpay_sync_confirmed_cancellation_flag( $order, 'SETTLE', $transaction_id, $token );
			$order->update_meta_data( '_torobpay_settle_handled', 'yes' );
			$order->update_meta_data( '_torobpay_verified_by_api', 'yes' );
			$order->save();
			torobpay_set_token_history_status( $order, (string) $token, 'SETTLE' );
			torobpay_cache_payment_status(
				$order,
				'SETTLE',
				(string) ( $order->get_meta( '_torobpay_transaction_id' ) ?: $transaction_id )
			);

			torobpay_finalize_paid_order( $order, (string) $order->get_meta( '_torobpay_transaction_id' ) );
			$refreshed = torobpay_get_fresh_order( $order->get_id() );
			if ( $refreshed ) {
				$order = $refreshed;
			}

			$transaction_id = $order->get_meta( '_torobpay_transaction_id' );
			$payment_token  = torobpay_get_order_payment_token( $order );

			if ( 'yes' !== $order->get_meta( '_torobpay_success_note_added', true ) ) {
				$note  = '<span class="torobpay-success">' . esc_html__( 'TorobPay: Transaction was successful.', 'torobpay-for-woocommerce' ) . '</span><br>';
				$note .= esc_html__( 'Transaction ID:', 'torobpay-for-woocommerce' ) . ' ' . esc_html( $transaction_id );
				$note .= '<br>' . esc_html__( 'Payment token:', 'torobpay-for-woocommerce' ) . ' ' . esc_html( $payment_token );
				$order->add_order_note( $note );

				$order->update_meta_data( '_torobpay_success_note_added', 'yes' );
			}

			$order->delete_meta_data( '_torobpay_settle_failed_note_added' );
			$order->save();
			return true;

		case 'PENDING':
			torobpay_sync_confirmed_cancellation_flag( $order, 'PENDING', $transaction_id, $token );
			$pending_processed = torobpay_process_pending( $order, $torobPayAPI, $token );

			if ( $pending_processed ) {
				torobpay_reset_payment_status_cache(
					$order,
					'SETTLE',
					(string) ( $order->get_meta( '_torobpay_transaction_id' ) ?: $transaction_id )
				);
			}

			return $pending_processed;

		default:
			if ( torobpay_has_confirmed_cancellation( $order, $token ) ) {
				torobpay_sync_confirmed_cancellation_flag( $order, '', '' );
			}
			return false;
	}
}

function torobpay_process_verification( $order, $torobPayAPI, $token, $from_status = 'pending' ) {
	$order_payment_method = $order->get_payment_method();
	if ( ! torobpay_is_torobpay_payment_method( $order_payment_method ) ) {
		return false;
	}

	$settle_response = torobpay_retry_api_call(
		function () use ( $torobPayAPI, $token ) {
			return $torobPayAPI->settle( $token );
		},
		3,
		'Settle',
		$order->get_id()
	);

	if ( isset( $settle_response['successful'] ) && $settle_response['successful'] ) {
		$order->update_meta_data( '_torobpay_settle_handled', 'yes' );
		$order->update_meta_data( '_torobpay_verified_by_api', 'yes' );
		$order->save();
		torobpay_set_token_history_status( $order, (string) $token, 'SETTLE' );
		// Cache SETTLE before payment_complete() so the status hook does not
		// read the mint-time PENDING value. Legacy _transactionId is left to the
		// note below; orders that old get an API transaction id on the next status fetch.
		torobpay_cache_payment_status(
			$order,
			'SETTLE',
			(string) ( $order->get_meta( '_torobpay_transaction_id' ) ?: '' )
		);

		torobpay_finalize_paid_order( $order, (string) ( $order->get_meta( '_torobpay_transaction_id' ) ?: '' ) );
		$refreshed = torobpay_get_fresh_order( $order->get_id() );
		if ( $refreshed ) {
			$order = $refreshed;
		}

		$transaction_id = $order->get_meta( '_torobpay_transaction_id' );
		$payment_token  = $token;

		if ( empty( $transaction_id ) ) {
			$transaction_id = $order->get_meta( '_transactionId' );
			if ( ! empty( $transaction_id ) ) {
				$order->update_meta_data( '_torobpay_transaction_id', $transaction_id );
				$order->save();
			}
		}

		if ( empty( $transaction_id ) ) {
			$transaction_id = $order->get_meta( 'transactionId' );
		}

		if ( 'yes' !== $order->get_meta( '_torobpay_success_note_added', true ) ) {
			$note  = '<span class="torobpay-success">' . esc_html__( 'TorobPay: Transaction was successful.', 'torobpay-for-woocommerce' ) . '</span><br>';
			$note .= esc_html__( 'Transaction ID:', 'torobpay-for-woocommerce' ) . ' ' . esc_html( $transaction_id );
			$note .= '<br>' . esc_html__( 'Payment token:', 'torobpay-for-woocommerce' ) . ' ' . esc_html( $payment_token );
			$order->add_order_note( $note );
			$order->update_meta_data( '_torobpay_success_note_added', 'yes' );
		}

		$order->delete_meta_data( '_torobpay_settle_failed_note_added' );
		$order->save();

		return true;
	} else {
		if ( 'yes' !== $order->get_meta( '_torobpay_settle_failed_note_added', true ) ) {
			$order->add_order_note( '<span class="torobpay-notice">' . esc_html__( 'TorobPay: Settlement failed. Please check the payment status in the TorobPay panel.', 'torobpay-for-woocommerce' ) . '</span>' );
			$order->update_meta_data( '_torobpay_settle_failed_note_added', 'yes' );
			$order->save();
		}
		return false;
	}
}

function torobpay_process_pending( $order, $torobPayAPI, $token ) {
	$order_payment_method = $order->get_payment_method();
	if ( ! torobpay_is_torobpay_payment_method( $order_payment_method ) ) {
		return false;
	}

	$verify_response = $torobPayAPI->verify( $token );
	if ( isset( $verify_response['successful'] ) && $verify_response['successful'] ) {
		return torobpay_process_verification( $order, $torobPayAPI, $token );
	} else {
		return false;
	}
}

/**
 * Resolve an order's TorobPay payment token.
 *
 * Canonical key first. The pre-2025-07-23 keys are unprefixed, so another
 * gateway may own them; they are only read on orders created before that date,
 * and a hit is migrated to the canonical key so this runs once per order.
 *
 * `_torobpay_token_history` is deliberately not consulted here: it records every
 * token issued, including abandoned retries. Mint and reconcile promote a paid
 * entry explicitly; this lookup must keep returning only the current token.
 *
 * @param WC_Order $order        Order object.
 * @param bool     $bypass_cache Reload first; HPOS OrderCache survives clean_post_cache().
 * @return string|false Token, or false when the order has none.
 */
function torobpay_get_order_payment_token( $order, $bypass_cache = false ) {
	if ( ! $order || ! method_exists( $order, 'get_meta' ) ) {
		return false;
	}

	if ( $bypass_cache && method_exists( $order, 'get_id' ) && $order->get_id() ) {
		$fresh_order = torobpay_get_fresh_order( $order->get_id() );
		if ( $fresh_order ) {
			$order = $fresh_order;
		}
	}

	$order_payment_token = $order->get_meta( '_order_torobpay_token' );
	if ( $order_payment_token ) {
		return $order_payment_token;
	}

	$created   = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
	$timestamp = 0;
	if ( $created ) {
		$timestamp = method_exists( $created, 'getTimestamp' )
			? (int) $created->getTimestamp()
			: (int) strtotime( (string) $created );
	}

	// Unknown or post-cutoff date: the generic keys are not ours, so never read them.
	if ( $timestamp > 0 && $timestamp < strtotime( '2025-07-23' ) ) {
		foreach ( array( '_paymentToken', 'paymentToken', '_order_spp_token', 'order_spp_token' ) as $meta_key ) {
			$legacy_token = $order->get_meta( $meta_key );
			if ( ! empty( $legacy_token ) ) {
				torobpay_debug_log( sprintf( 'TorobPay: order #%d token migrated from legacy meta %s.', $order->get_id(), $meta_key ) );
				$order->update_meta_data( '_order_torobpay_token', $legacy_token );
				$order->save();

				return $legacy_token;
			}
		}
	}

	// history_entries splits the cause: >0 means only the canonical key was lost
	// (rescue can still find the paid token); 0 means the meta save never landed.
	$history = $order->get_meta( '_torobpay_token_history' );
	torobpay_debug_log(
		sprintf(
			'TorobPay: no payment token for order #%d (status=%s, created=%s, history_entries=%d, hpos=%s).',
			$order->get_id(),
			method_exists( $order, 'get_status' ) ? $order->get_status() : 'unknown',
			$timestamp > 0 ? gmdate( 'Y-m-d H:i:s', $timestamp ) : 'unknown',
			is_array( $history ) ? count( $history ) : 0,
			( function_exists( 'torobpay_orders_use_hpos' ) && torobpay_orders_use_hpos() ) ? 'yes' : 'no'
		)
	);

	return false;
}
