<?php
/**
 * Notification provider: no gateway credentials entered yet.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

torobpay_register_notification_provider( 'torobpay_missing_credentials_notification_provider' );

/**
 * Prompt merchants to register when no gateway credentials have been entered.
 *
 * Shown only when all four credential fields are empty.
 *
 * @return array
 */
function torobpay_missing_credentials_notification_provider() {
	if ( ! function_exists( 'torobpay_credentials_are_empty' ) || ! torobpay_credentials_are_empty() ) {
		return array();
	}

	return array(
		array(
			'id'             => 'missing_credentials',
			'type'           => 'missing_credentials',
			'priority'       => 3,
			'title'          => __( 'Set up your TorobPay account', 'torobpay-for-woocommerce' ),
			'body'           => esc_html__( 'If you do not have a TorobPay account yet, you can register using this link.', 'torobpay-for-woocommerce' ),
			'action_url'     => torobpay_get_merchant_registration_url(),
			'action_label'   => __( 'Register', 'torobpay-for-woocommerce' ),
			'action_new_tab' => true,
			'dismissible'    => true,
			'badge'          => 1,
			'tier'           => 'notice',
		),
	);
}
