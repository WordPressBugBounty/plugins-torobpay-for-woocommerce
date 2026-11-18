<?php
/**
 * Notification provider: orders left mid item-edit.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

torobpay_register_notification_provider( 'torobpay_unfinished_edit_notification_provider' );

/**
 * Orders still holding the TorobPay edit lock.
 *
 * @return array
 */
function torobpay_unfinished_edit_notification_provider() {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}

	$ids = wc_get_orders(
		array(
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin notification lookup by TorobPay edit-lock meta.
			'meta_key'     => '_torobpay_editing_items',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Admin notification lookup by TorobPay edit-lock meta.
			'meta_value'   => 'yes',
			'meta_compare' => '=',
			'return'       => 'ids',
			'limit'        => -1,
		)
	);

	$count = count( $ids );
	if ( 0 === $count ) {
		return array();
	}

	$body = 1 === $count
		? __( 'One order has incomplete item editing and is on hold.', 'torobpay-for-woocommerce' )
		/* translators: %d: number of orders with incomplete item edits */
		: sprintf( __( '%d order(s) have incomplete item edits and are on hold.', 'torobpay-for-woocommerce' ), $count );

	return array(
		array(
			'id'           => 'unfinished_edit',
			'type'         => 'unfinished_edit',
			'priority'     => 5,
			/* translators: %d: number of orders with incomplete item edits */
			'title'        => sprintf( __( 'Incomplete edit (%d order(s))', 'torobpay-for-woocommerce' ), $count ),
			'body'         => $body,
			'action_url'   => admin_url( 'admin.php?page=torobpay-orders&torobpay_status=EDITING' ),
			'action_label' => __( 'View orders', 'torobpay-for-woocommerce' ),
			'dismissible'  => false,
			'badge'        => $count,
			'tier'         => 'notice',
		),
	);
}
