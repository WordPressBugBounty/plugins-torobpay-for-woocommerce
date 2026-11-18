<?php
/**
 * Notification provider: TorobPay authentication failures.
 *
 * Reads the state written by TorobPayAPI::get_request_bearer_token(); the copy
 * itself comes from the error catalog in includes/torobpay-error-codes.php.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

torobpay_register_notification_provider( 'torobpay_auth_error_notification_provider' );

/**
 * Surface the last authentication failure.
 *
 * @return array
 */
function torobpay_auth_error_notification_provider() {
	if ( ! function_exists( 'torobpay_is_configured' ) || ! torobpay_is_configured() ) {
		return array();
	}

	$auth_error = get_option( 'torobpay_auth_error' );
	if ( empty( $auth_error ) || ! is_array( $auth_error ) ) {
		return array();
	}

	$error_code = isset( $auth_error['errorCode'] ) ? (int) $auth_error['errorCode'] : 0;
	$title      = ! empty( $auth_error['notification_title'] ) ? $auth_error['notification_title'] : __( 'TorobPay authentication error', 'torobpay-for-woocommerce' );
	$message    = ! empty( $auth_error['friendly_message'] ) ? $auth_error['friendly_message'] : __( 'Error connecting to TorobPay. Please check the settings.', 'torobpay-for-woocommerce' );

	if ( empty( $auth_error['friendly_message'] ) && ! empty( $auth_error['message'] ) ) {
		$message .= ' (' . $auth_error['message'] . ')';
	}

	$action = torobpay_get_api_error_action( isset( $auth_error['action'] ) ? $auth_error['action'] : 'settings' );
	if ( ! $action ) {
		$action = torobpay_get_api_error_action( 'settings' );
	}

	return array(
		array(
			'id'             => $error_code > 0 ? 'auth_error_' . $error_code : 'auth_error_generic',
			'type'           => 'auth_error',
			'priority'       => 2,
			'title'          => $title,
			'body'           => $message,
			'action_url'     => $action['url'],
			'action_label'   => $action['label'],
			'action_new_tab' => $action['new_tab'],
			'dismissible'    => true,
			'badge'          => 1,
			'tier'           => ! empty( $auth_error['tier'] ) ? $auth_error['tier'] : 'critical',
		),
	);
}
