<?php
/**
 * Merchant-triggered reconciliation (Action Needed probe). Remote status is
 * authoritative; only VERIFY→settle may auto-heal.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function torobpay_set_action_required( $order, $code, $reason = '' ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$was_flagged   = $order->get_meta( '_torobpay_action_required' ) === 'yes';
	$previous_code = (string) $order->get_meta( '_torobpay_action_code' );

	$order->update_meta_data( '_torobpay_action_required', 'yes' );
	$order->update_meta_data( '_torobpay_action_code', (string) $code );
	$order->update_meta_data( '_torobpay_action_reason', (string) $reason );
	$order->update_meta_data( '_torobpay_action_time', time() );

	if ( ! $was_flagged || $previous_code !== (string) $code ) {
		$order->delete_meta_data( '_torobpay_action_dismissed' );
		$order->delete_meta_data( '_torobpay_action_dismissed_time' );
	}

	$order->save_meta_data();

	if ( ! $was_flagged || $previous_code !== (string) $code ) {
		$note_text = $reason !== '' ? $reason : torobpay_get_action_meta( (string) $code )['description'];
		if ( $note_text !== '' ) {
			$order->add_order_note( '<span class="torobpay-notice">' . esc_html__( 'TorobPay: ', 'torobpay-for-woocommerce' ) . $note_text . '</span>' );
		}
	}
}

function torobpay_clear_action_required( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	if ( $order->get_meta( '_torobpay_action_required' ) !== 'yes' ) {
		return;
	}

	$order->delete_meta_data( '_torobpay_action_required' );
	$order->delete_meta_data( '_torobpay_action_code' );
	$order->delete_meta_data( '_torobpay_action_reason' );
	$order->delete_meta_data( '_torobpay_action_time' );
	$order->delete_meta_data( '_torobpay_action_dismissed' );
	$order->delete_meta_data( '_torobpay_action_dismissed_time' );
	$order->delete_meta_data( '_torobpay_reconcile_attempts' );
	$order->delete_meta_data( '_torobpay_last_modal_probe' );
	$order->save_meta_data();
}

function torobpay_dismiss_action_required( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	if ( $order->get_meta( '_torobpay_action_required' ) !== 'yes' ) {
		return;
	}

	$order->update_meta_data( '_torobpay_action_dismissed', 'yes' );
	$order->update_meta_data( '_torobpay_action_dismissed_time', time() );
	$order->save_meta_data();
}

function torobpay_order_action_dismissed( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	return $order instanceof WC_Order && $order->get_meta( '_torobpay_action_dismissed' ) === 'yes';
}

/**
 * @param string $status Remote TorobPay status.
 * @return bool
 */
function torobpay_remote_status_is_paid( $status ) {
	return in_array( strtoupper( (string) $status ), array( 'VERIFY', 'SETTLE' ), true );
}

/**
 * @param WC_Order $order Order.
 * @return bool
 */
function torobpay_order_wc_is_paid_for_reconcile( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	$paid_statuses  = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
	$total          = (float) $order->get_total();
	$fully_refunded = $total > 0 && (float) $order->get_total_refunded() >= $total;

	return in_array( $order->get_status(), $paid_statuses, true ) && ! $fully_refunded;
}

/**
 * WC refund rows that were not created via TorobPay (any order status).
 *
 * @param WC_Order|int $order Order object or ID.
 * @return bool
 */
function torobpay_order_has_unconfirmed_local_refunds( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	foreach ( $order->get_refunds() as $refund ) {
		if ( ! $refund instanceof WC_Order_Refund ) {
			continue;
		}
		if ( (float) $refund->get_amount() <= 0 ) {
			continue;
		}
		if ( ! function_exists( 'torobpay_refund_is_torobpay_confirmed' ) || ! torobpay_refund_is_torobpay_confirmed( $refund ) ) {
			return true;
		}
	}

	return false;
}

/**
 * WC line-item / order-refund UI rows that were not created via TorobPay.
 * Only applies while the order is still in a paid WC status (processing/completed).
 *
 * @param WC_Order $order
 * @return bool
 */
function torobpay_order_has_wc_line_item_refunds_without_torobpay( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	$paid_statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
	if ( ! in_array( $order->get_status(), $paid_statuses, true ) ) {
		return false;
	}

	return torobpay_order_has_unconfirmed_local_refunds( $order );
}

/**
 * WC order-refund UI rows exist while TorobPay still reports a paid remote status.
 *
 * @param WC_Order $order
 * @param string   $remote_status Optional; falls back to cached status.
 * @return bool
 */
function torobpay_order_has_unsynced_local_refunds( $order, $remote_status = '' ) {
	if ( ! torobpay_order_has_wc_line_item_refunds_without_torobpay( $order ) ) {
		return false;
	}

	if ( '' === $remote_status && function_exists( 'torobpay_get_cached_payment_status' ) ) {
		$remote_status = torobpay_get_cached_payment_status( $order );
	}

	return torobpay_remote_status_is_paid( $remote_status );
}

function torobpay_flag_local_refund_divergence( $order, $remote_status = '' ) {
	if ( ! torobpay_order_has_unsynced_local_refunds( $order, $remote_status ) ) {
		return false;
	}

	// 1054 lock outranks local-refund advice.
	if ( $order instanceof WC_Order && $order->get_meta( '_torobpay_remote_locked' ) === 'yes' ) {
		torobpay_set_action_required(
			$order,
			'remote_locked',
			torobpay_get_remote_locked_action_reason( $order )
		);
		return true;
	}

	torobpay_set_action_required( $order, 'local_refund_divergence' );

	return true;
}

/**
 * Remote still paid but WC status was downgraded (cancel/refund/fail dropdown).
 *
 * @param WC_Order $order
 * @param string   $remote_status
 * @return bool True when a downgrade divergence was flagged or cleared.
 */
function torobpay_flag_remote_wc_status_downgrade_divergence( $order, $remote_status ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	if ( ! torobpay_remote_status_is_paid( $remote_status ) ) {
		return false;
	}

	if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) {
		return false;
	}

	$token = torobpay_get_order_payment_token( $order );
	if ( torobpay_has_confirmed_cancellation( $order, $token ) ) {
		torobpay_clear_action_required( $order );
		return true;
	}

	$code = torobpay_get_downgrade_action_code( $order );
	torobpay_set_action_required(
		$order,
		$code,
		( 'remote_locked' === $code )
			? torobpay_get_remote_locked_action_reason( $order )
			: sprintf(
				/* translators: %s: WooCommerce order status */
				__( 'Order status is "%s" but payment is still active in TorobPay.', 'torobpay-for-woocommerce' ),
				torobpay_wc_status_label( $order->get_status() )
			)
	);

	return true;
}

function torobpay_get_settle_divergence_code( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return 'settle_divergence';
	}

	if ( $order->get_meta( '_torobpay_editing_items' ) === 'yes' && $order->has_status( 'on-hold' ) ) {
		return 'settle_editing_on_hold';
	}

	if ( $order->has_status( 'on-hold' ) ) {
		return 'settle_on_hold';
	}

	return 'settle_divergence';
}

/**
 * Downgrade action code: remote_locked (1054) or manual_downgrade.
 *
 * @param WC_Order|int $order Order object or ID.
 * @return string
 */
function torobpay_get_downgrade_action_code( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	if ( $order instanceof WC_Order && $order->get_meta( '_torobpay_remote_locked' ) === 'yes' ) {
		return 'remote_locked';
	}

	return 'manual_downgrade';
}

/**
 * Extra remote_locked reason when unconfirmed local WC refunds exist.
 *
 * @param WC_Order|int $order Order object or ID.
 * @return string
 */
function torobpay_get_remote_locked_action_reason( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order instanceof WC_Order || ! torobpay_order_has_unconfirmed_local_refunds( $order ) ) {
		return '';
	}

	return __( 'The TorobPay edit/cancel window has expired and this payment can no longer be changed. First remove refunds recorded via the WooCommerce Refund button, then manually restore the order status to a paid status (e.g. Processing or Completed) to align with TorobPay.', 'torobpay-for-woocommerce' );
}

/** Flag divergences from a known remote status (no API calls). */
function torobpay_flag_divergence_from_status( $order, $status ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		return;
	}

	$status  = strtoupper( (string) $status );
	$wc_paid = torobpay_order_wc_is_paid_for_reconcile( $order );

	switch ( $status ) {
		case 'SETTLE':
			if ( torobpay_flag_remote_wc_status_downgrade_divergence( $order, $status ) ) {
				break;
			}
			if ( torobpay_flag_local_refund_divergence( $order, $status ) ) {
				break;
			}
			if ( $wc_paid ) {
				torobpay_clear_action_required( $order );
			} elseif ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) {
				torobpay_set_action_required( $order, torobpay_get_settle_divergence_code( $order ) );
			}
			break;

		case 'VERIFY':
			if ( torobpay_flag_remote_wc_status_downgrade_divergence( $order, $status ) ) {
				break;
			}
			if ( torobpay_flag_local_refund_divergence( $order, $status ) ) {
				break;
			}
			torobpay_set_action_required( $order, 'settle_pending' );
			break;

		case 'REVERT':
			if ( $wc_paid ) {
				torobpay_set_action_required( $order, 'revert_divergence' );
			} else {
				torobpay_clear_action_required( $order );
			}
			break;

		case 'PENDING':
			if ( $wc_paid ) {
				torobpay_set_action_required( $order, 'pending_divergence' );
			}
			break;
	}
}

function torobpay_order_needs_action( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	return $order instanceof WC_Order && $order->get_meta( '_torobpay_action_required' ) === 'yes';
}

function torobpay_get_action_meta( $code = '' ) {
	$map = array(
		'settle_pending'     => array(
			'label'       => __( 'Incomplete settlement', 'torobpay-for-woocommerce' ),
			'description' => __( 'Payment is confirmed but final settlement has not completed. TorobPay usually completes this automatically; opening this alert also triggers one more attempt to complete the operation.', 'torobpay-for-woocommerce' ),
			'severity'    => 'warning',
			'actions'     => array( 'dismiss' ),
		),
		'manual_downgrade'   => array(
			'label'       => __( 'Unconfirmed cancellation', 'torobpay-for-woocommerce' ),
			'description' => __( 'Order cancel/refund was recorded but payment cancellation was not confirmed in TorobPay. If funds were received, use Refund; if payment bounced, use Mark as failed.', 'torobpay-for-woocommerce' ),
			'severity'    => 'critical',
			'actions'     => array( 'refund', 'fail', 'dismiss' ),
		),
		'remote_locked'      => array(
			'label'       => __( 'Order locked in TorobPay', 'torobpay-for-woocommerce' ),
			'description' => __( 'The TorobPay edit/cancel window has expired and this payment can no longer be changed. Manually restore the WooCommerce order status to a paid status (e.g. Processing or Completed) to align with TorobPay.', 'torobpay-for-woocommerce' ),
			'severity'    => 'critical',
			'actions'     => array( 'dismiss' ),
		),
		'unconfirmed_paid'   => array(
			'label'       => __( 'Unconfirmed payment', 'torobpay-for-woocommerce' ),
			'description' => __( 'The order is marked paid in WooCommerce but the latest TorobPay status does not confirm it. Opening this alert refreshes status from TorobPay; manually correct the order status if needed.', 'torobpay-for-woocommerce' ),
			'severity'    => 'critical',
			'actions'     => array( 'dismiss' ),
		),
		'revert_divergence'  => array(
			'label'       => __( 'Refund mismatch', 'torobpay-for-woocommerce' ),
			'description' => __( 'TorobPay recorded this payment as cancelled/failed. If no funds were received, mark the order as Failed.', 'torobpay-for-woocommerce' ),
			'severity'    => 'critical',
			'actions'     => array( 'fail', 'dismiss' ),
		),
		'pending_divergence' => array(
			'label'       => __( 'Pending mismatch', 'torobpay-for-woocommerce' ),
			'description' => __( 'The order is marked paid in WooCommerce but TorobPay is still Pending. Opening this alert refreshes status from TorobPay; manually correct the order status if needed.', 'torobpay-for-woocommerce' ),
			'severity'    => 'critical',
			'actions'     => array( 'dismiss' ),
		),
		'settle_divergence'  => array(
			'label'       => __( 'Settlement mismatch', 'torobpay-for-woocommerce' ),
			'description' => __( 'Payment is settled in TorobPay but the order is not paid in WooCommerce. Manually change the order status to Processing or Completed. If you are editing items, you may ignore this message; a local WooCommerce refund can also cause this report.', 'torobpay-for-woocommerce' ),
			'severity'    => 'warning',
			'actions'     => array( 'dismiss' ),
		),
		'settle_editing_on_hold' => array(
			'label'       => __( 'Incomplete item edit', 'torobpay-for-woocommerce' ),
			'description' => __( 'Payment is settled in TorobPay and the order is temporarily On hold for item editing. Resume editing and save/cancel so the order status can be restored.', 'torobpay-for-woocommerce' ),
			'severity'    => 'warning',
			'actions'     => array( 'resume_edit', 'dismiss' ),
		),
		'settle_on_hold'    => array(
			'label'       => __( 'Paid order on hold', 'torobpay-for-woocommerce' ),
			'description' => __( 'Payment is settled in TorobPay but the WooCommerce order is still On hold. If this hold is not intentional, change the order status to Processing or Completed.', 'torobpay-for-woocommerce' ),
			'severity'    => 'warning',
			'actions'     => array( 'dismiss' ),
		),
		'local_refund_divergence' => array(
			'label'       => __( 'Item refund without TorobPay', 'torobpay-for-woocommerce' ),
			'description' => __( 'An amount or items were refunded via the WooCommerce Refund button (on the order page), but the TorobPay payment is unchanged. Remove those refunds and use the TorobPay Refund button instead.', 'torobpay-for-woocommerce' ),
			'severity'    => 'critical',
			'actions'     => array( 'dismiss' ),
		),
	);

	$default = array(
		'label'       => __( 'Needs review', 'torobpay-for-woocommerce' ),
		'description' => __( 'This order payment status needs review. Opening this alert reads status from TorobPay; manually correct the order status if needed.', 'torobpay-for-woocommerce' ),
		'severity'    => 'warning',
		'actions'     => array( 'dismiss' ),
	);

	if ( '' === $code ) {
		return $map;
	}

	return isset( $map[ $code ] ) ? $map[ $code ] : $default;
}

function torobpay_get_payment_status_meta( $status ) {
	$status = (string) $status;

	switch ( strtoupper( $status ) ) {
		case 'PENDING':
			return array(
				'text'       => __( 'Awaiting payment', 'torobpay-for-woocommerce' ),
				'css_class'  => 'status-pending',
				'is_success' => false,
			);
		case 'VERIFY':
			return array(
				'text'       => __( 'Verified (awaiting settlement)', 'torobpay-for-woocommerce' ),
				'css_class'  => 'status-success',
				'is_success' => true,
			);
		case 'SETTLE':
			return array(
				'text'       => __( 'Settled (successful payment)', 'torobpay-for-woocommerce' ),
				'css_class'  => 'status-success',
				'is_success' => true,
			);
		case 'REVERT':
			return array(
				'text'       => __( 'Cancelled/Failed', 'torobpay-for-woocommerce' ),
				'css_class'  => 'status-failed',
				'is_success' => false,
			);
		case 'TOKEN_MISSING':
			return array(
				'text'       => __( 'Payment token not found', 'torobpay-for-woocommerce' ),
				'css_class'  => 'status-failed',
				'is_success' => false,
			);
		default:
			return array(
				'text'       => '' !== $status ? $status : __( 'Unknown', 'torobpay-for-woocommerce' ),
				'css_class'  => 'status-unknown',
				'is_success' => false,
			);
	}
}

function torobpay_payment_status_label( $status ) {
	$meta = torobpay_get_payment_status_meta( $status );

	return $meta['text'];
}

function torobpay_wc_status_label( $status ) {
	if ( function_exists( 'wc_get_order_status_name' ) ) {
		return wc_get_order_status_name( $status );
	}

	return (string) $status;
}

function torobpay_fetch_remote_status( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return null;
	}

	$token = torobpay_get_order_payment_token( $order, true );
	if ( ! $token ) {
		return null;
	}

	$api          = new TorobPayAPI();
	$expected_txn = (string) ( $order->get_meta( '_torobpay_transaction_id' ) ?: $order->get_meta( '_transactionId' ) );

	$check = static function ( $candidate_token, $expected ) use ( $api ) {
		$resp = $api->status( $candidate_token );
		if ( is_wp_error( $resp ) || ! is_array( $resp ) || empty( $resp['successful'] ) || ! isset( $resp['response']['status'] ) ) {
			return null;
		}
		$api_txn = isset( $resp['response']['transactionId'] ) ? (string) $resp['response']['transactionId'] : '';
		if ( $expected !== '' && $api_txn !== '' && $api_txn !== $expected ) {
			return null;
		}
		return array(
			'status'         => strtoupper( (string) $resp['response']['status'] ),
			'transaction_id' => $api_txn,
			'token'          => $candidate_token,
		);
	};

	$result = $check( $token, $expected_txn );
	if ( is_array( $result ) && function_exists( 'torobpay_set_token_history_status' ) ) {
		torobpay_set_token_history_status( $order, $token, $result['status'] );
	}

	$history   = function_exists( 'torobpay_get_token_history' ) ? torobpay_get_token_history( $order ) : array();
	$preferred = function_exists( 'torobpay_prefer_paid_history_over_remote' )
		? torobpay_prefer_paid_history_over_remote( $result, $history )
		: $result;

	if ( is_array( $preferred ) && ( ! is_array( $result ) || (string) ( $result['token'] ?? '' ) !== (string) ( $preferred['token'] ?? '' ) ) ) {
		$entry = array(
			'token'          => (string) $preferred['token'],
			'transaction_id' => (string) ( $preferred['transaction_id'] ?? '' ),
			'status'         => (string) $preferred['status'],
		);
		if ( function_exists( 'torobpay_promote_paid_history_entry' ) ) {
			torobpay_promote_paid_history_entry( $order, $entry );
		}
		return $preferred;
	}

	return is_array( $result ) ? $result : null;
}

function torobpay_reconcile_order( $order, $context = '' ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return '';
	}

	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		return '';
	}

	if ( ! torobpay_is_torobpay_payment_method( $order->get_payment_method() ) ) {
		return '';
	}

	$remote = torobpay_fetch_remote_status( $order );
	if ( ! $remote ) {
		return '';
	}

	$order = torobpay_get_fresh_order( $order->get_id() ) ?: $order;

	$status  = $remote['status'];
	$token   = $remote['token'];
	$txn     = $remote['transaction_id'] ?: (string) $order->get_meta( '_torobpay_transaction_id' );
	$wc_paid = $order->is_paid() || in_array( $order->get_status(), array( 'processing', 'completed' ), true );
	$api     = new TorobPayAPI();

	switch ( $status ) {
		case 'SETTLE':
			// Suggestive only — do not auto-change WC status.
			torobpay_cache_payment_status( $order, 'SETTLE', $txn );
			if ( torobpay_flag_remote_wc_status_downgrade_divergence( $order, 'SETTLE' ) ) {
				break;
			}
			if ( torobpay_flag_local_refund_divergence( $order, 'SETTLE' ) ) {
				break;
			}
			$wc_paid = torobpay_order_wc_is_paid_for_reconcile( $order );
			if ( $wc_paid ) {
				torobpay_clear_action_required( $order );
			} elseif ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) {
				torobpay_set_action_required( $order, torobpay_get_settle_divergence_code( $order ) );
			}
			break;

		case 'VERIFY':
			// Only auto-heal path: attempt settle after VERIFY.
			$verified = torobpay_handle_status_response( $order, 'VERIFY', $api, $token, $order->get_status() );
			$order    = torobpay_get_fresh_order( $order->get_id() ) ?: $order;
			$now_paid = torobpay_order_wc_is_paid_for_reconcile( $order );

			if ( torobpay_flag_remote_wc_status_downgrade_divergence( $order, 'VERIFY' ) ) {
				torobpay_cache_payment_status( $order, 'VERIFY', $txn );
				break;
			}
			if ( torobpay_flag_local_refund_divergence( $order, 'VERIFY' ) ) {
				torobpay_cache_payment_status( $order, 'VERIFY', $txn );
				break;
			}

			if ( $verified && $now_paid ) {
				torobpay_cache_payment_status( $order, 'SETTLE', $txn );
				torobpay_clear_action_required( $order );
			} else {
				torobpay_cache_payment_status( $order, 'VERIFY', $txn );
				if ( ! $now_paid && ! $order->has_status( 'on-hold' ) ) {
					$order->update_status(
						'on-hold',
						__( 'TorobPay: Payment was confirmed but final settlement did not complete; the order was set to "On hold" pending review. ', 'torobpay-for-woocommerce' )
					);
				}
				torobpay_set_action_required( $order, 'settle_pending' );
			}
			break;

		case 'REVERT':
			torobpay_cache_payment_status( $order, 'REVERT', $txn );
			if ( $wc_paid ) {
				torobpay_set_action_required( $order, 'revert_divergence' );
			} else {
				torobpay_clear_action_required( $order );
			}
			break;

		case 'PENDING':
			torobpay_cache_payment_status( $order, 'PENDING', $txn );
			if ( $wc_paid ) {
				torobpay_set_action_required( $order, 'pending_divergence' );
			}
			break;
	}

	torobpay_debug_log(
		sprintf(
			'TorobPay reconcile: order #%d remote=%s context=%s',
				$order->get_id(),
				$status,
				$context ?: 'n/a'
		)
	);

	return $status;
}

/** Review WC status changes from cached TorobPay state (API sync via Action Needed probe). */
function torobpay_review_status_change( $order_id, $from_status, $to_status, $order ) {
	static $processing = array();
	if ( ! empty( $processing[ $order_id ] ) ) {
		return;
	}

	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
	}
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		return;
	}

	if ( ! torobpay_is_torobpay_payment_method( $order->get_payment_method() ) ) {
		return;
	}

	$processing[ $order_id ] = true;

	// HPOS/hold-stock regression: paid → pending is never intentional.
	if ( $to_status === 'pending' && in_array( $from_status, array( 'processing', 'completed' ), true ) ) {
		$fresh = torobpay_get_fresh_order( $order_id ) ?: $order;
		if ( torobpay_is_order_paid_for_actions( $fresh ) ) {
			$fresh->update_status(
				$from_status,
				sprintf(
					/* translators: %s: restored WooCommerce order status */
					__( 'TorobPay: A paid order was incorrectly changed to "Pending payment" and was automatically restored to "%s".', 'torobpay-for-woocommerce' ),
					torobpay_wc_status_label( $from_status )
				)
			);
			unset( $processing[ $order_id ] );
			return;
		}
	}

	if ( in_array( $to_status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
		$token = torobpay_get_order_payment_token( $order );

		if ( torobpay_has_confirmed_cancellation( $order, $token ) ) {
			torobpay_clear_action_required( $order );
			unset( $processing[ $order_id ] );
			return;
		}

		if ( in_array( $to_status, array( 'cancelled', 'refunded' ), true )
			&& torobpay_order_was_paid_before_downgrade( $order, $from_status ) ) {
			torobpay_cancel_order( $order_id, $from_status );

			$fresh     = torobpay_get_fresh_order( $order_id ) ?: $order;
			$confirmed = torobpay_has_confirmed_cancellation(
				$fresh,
				torobpay_get_order_payment_token( $fresh )
			);

			if ( $confirmed ) {
				torobpay_clear_action_required( $fresh );
				unset( $processing[ $order_id ] );
				return;
			}

			$code = torobpay_get_downgrade_action_code( $fresh );
			torobpay_set_action_required(
				$fresh,
				$code,
				( 'remote_locked' === $code )
					? torobpay_get_remote_locked_action_reason( $fresh )
					: sprintf(
						/* translators: %s: WooCommerce order status */
					__( 'Order status changed to "%s" but payment is still active in TorobPay.', 'torobpay-for-woocommerce' ),
						torobpay_wc_status_label( $to_status )
					)
			);
			unset( $processing[ $order_id ] );
			return;
		}

		if ( torobpay_order_was_paid_before_downgrade( $order, $from_status ) ) {
			$code = torobpay_get_downgrade_action_code( $order );
			torobpay_set_action_required(
				$order,
				$code,
				( 'remote_locked' === $code )
					? torobpay_get_remote_locked_action_reason( $order )
					: sprintf(
						/* translators: %s: WooCommerce order status */
					__( 'Order status changed to "%s" but payment is still active in TorobPay.', 'torobpay-for-woocommerce' ),
						torobpay_wc_status_label( $to_status )
					)
			);
		}
		unset( $processing[ $order_id ] );
		return;
	}

	if ( in_array( $to_status, array( 'processing', 'completed' ), true ) ) {
		$cached = torobpay_get_cached_payment_status( $order );
		if ( in_array( $cached, array( 'PENDING', 'REVERT' ), true ) ) {
			torobpay_set_action_required(
				$order,
				'unconfirmed_paid',
				sprintf(
					/* translators: 1: WooCommerce order status, 2: TorobPay payment status */
					__( 'Order changed to "%1$s" but the latest TorobPay payment status is "%2$s".', 'torobpay-for-woocommerce' ),
					torobpay_wc_status_label( $to_status ),
					torobpay_payment_status_label( $cached )
				)
			);
		} elseif ( '' !== $cached ) {
			torobpay_flag_divergence_from_status( $order, $cached );
		}
		unset( $processing[ $order_id ] );
		return;
	}

	unset( $processing[ $order_id ] );
}
add_action( 'woocommerce_order_status_changed', 'torobpay_review_status_change', PHP_INT_MAX, 4 );

/**
 * Flag orders when a merchant uses the WooCommerce order-refund UI (line items /
 * shipping) while TorobPay still shows a paid remote status.
 *
 * @param int                  $refund_id
 * @param array<string, mixed> $args
 * @return void
 */
function torobpay_review_manual_refund( $refund_id, $args ) {
	$order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
	if ( $order_id <= 0 ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		return;
	}

	if ( ! torobpay_is_torobpay_payment_method( $order->get_payment_method() ) ) {
		return;
	}

	$fresh = torobpay_get_fresh_order( $order_id ) ?: $order;
	torobpay_flag_local_refund_divergence( $fresh );
}
add_action( 'woocommerce_order_refund_created', 'torobpay_review_manual_refund', 10, 2 );

/**
 * Re-evaluate divergence when a merchant removes a manual WC refund.
 *
 * @param int $refund_id
 * @param int $order_id
 * @return void
 */
function torobpay_review_manual_refund_removed( $refund_id, $order_id ) {
	$order = wc_get_order( (int) $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		return;
	}

	if ( ! torobpay_is_torobpay_payment_method( $order->get_payment_method() ) ) {
		return;
	}

	$fresh = torobpay_get_fresh_order( (int) $order_id ) ?: $order;
	if ( (float) $fresh->get_total_refunded() > 0 ) {
		torobpay_flag_local_refund_divergence( $fresh );
		return;
	}

	$cached = function_exists( 'torobpay_get_cached_payment_status' ) ? torobpay_get_cached_payment_status( $fresh ) : '';
	if ( function_exists( 'torobpay_flag_divergence_from_status' ) && '' !== $cached ) {
		torobpay_flag_divergence_from_status( $fresh, $cached );
	}
}
add_action( 'woocommerce_refund_deleted', 'torobpay_review_manual_refund_removed', 10, 2 );
