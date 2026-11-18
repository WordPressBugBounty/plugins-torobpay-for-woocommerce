<?php
/**
 * Notification provider: credit campaign opt-in.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

torobpay_register_notification_provider( 'torobpay_campaign_notification_provider' );

/**
 * Prompt merchants to join the credit campaign while sharing is off.
 *
 * Single rule: shown whenever the campaign is disabled, hidden whenever it
 * is enabled. Dismissible from the notification center.
 *
 * @return array
 */
function torobpay_campaign_notification_provider() {
	if ( ! function_exists( 'torobpay_is_campaign_enabled' ) || torobpay_is_campaign_enabled() ) {
		return array();
	}

	return array(
		array(
			'id'           => 'campaign_not_enabled',
			'type'         => 'news',
			'priority'     => 18,
			'title'        => __( 'Join TorobPay campaign', 'torobpay-for-woocommerce' ),
			'body'         => esc_html__( 'TorobPay sends SMS to customers who are eligible to receive credit. TorobPay covers the cost of these messages.', 'torobpay-for-woocommerce' ),
			'action_url'   => admin_url( 'admin.php?page=torobpay-campaign' ),
			'action_label' => __( 'Join campaign', 'torobpay-for-woocommerce' ),
			'dismissible'  => true,
			'badge'        => 1,
			'tier'         => 'notice',
		),
	);
}
