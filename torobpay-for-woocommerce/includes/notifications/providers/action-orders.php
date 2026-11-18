<?php
/**
 * Notification provider: orders flagged by the reconciliation engine.
 *
 * Counts the local `_torobpay_action_required` projection only — no API call
 * happens on page load.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

torobpay_register_notification_provider( 'torobpay_action_orders_notification_provider' );

/**
 * Orders needing merchant review.
 *
 * @return array
 */
function torobpay_action_orders_notification_provider() {
	$count = torobpay_get_action_needed_order_count();
	if ( 0 === $count ) {
		return array();
	}

	return array(
		array(
			'id'           => 'action_orders',
			'type'         => 'action_order',
			'priority'     => 2,
			'title'        => __( 'Orders needing review', 'torobpay-for-woocommerce' ),
			/* translators: %d: number of TorobPay orders needing review */
			'body'         => sprintf( __( '%d TorobPay order(s) need review or action (status mismatch or incomplete settlement/refund).', 'torobpay-for-woocommerce' ), $count ),
			'action_url'   => admin_url( 'admin.php?page=torobpay-orders&torobpay_status=ACTION_NEEDED' ),
			'action_label' => __( 'View orders', 'torobpay-for-woocommerce' ),
			'dismissible'  => true,
			'badge'        => $count,
			'tier'         => 'notice',
		),
	);
}

/**
 * Count TorobPay orders flagged with the `_torobpay_action_required`
 * projection by the reconciliation engine. Local meta query only — no API.
 *
 * @return int
 */
function torobpay_get_action_needed_order_count() {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return 0;
	}

	$order_ids = wc_get_orders(
		array(
			'limit'        => 100,
			'status'       => 'any',
			'return'       => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- HPOS/CPT-safe action-required count.
			'meta_key'     => '_torobpay_action_required',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- HPOS/CPT-safe action-required count.
			'meta_value'   => 'yes',
			'meta_compare' => '=',
		)
	);

	if ( ! is_array( $order_ids ) || empty( $order_ids ) ) {
		return 0;
	}

	// Dismissed orders stay flagged in the orders table but are muted from notices.
	$count = 0;
	foreach ( $order_ids as $order_id ) {
		if ( ! torobpay_order_action_dismissed( $order_id ) ) {
			++$count;
		}
	}

	return $count;
}
