<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/torobpay-error-codes.php';

/** TorobPay API response helpers. */

function torobpay_retry_api_call( $api_call, $max_attempts = 3, $operation_name = 'API call', $order_id = null ) {
	$attempts = 0;
	$response = null;

	while ( $attempts < $max_attempts ) {
		++$attempts;
		$response = $api_call();

		$got_valid_response = ! is_wp_error( $response ) &&
								is_array( $response ) &&
								isset( $response['successful'] );

		$order_info = $order_id ? " for order $order_id" : '';
		torobpay_debug_log(
			sprintf(
				'TorobPay Debug: %s attempt %d/%d%s: %s',
				$operation_name,
				$attempts,
				$max_attempts,
				$order_info,
				wp_json_encode( $response )
			)
		);

		if ( $got_valid_response ) {
			$status = isset( $response['successful'] ) && $response['successful'] ? 'succeeded' : 'returned business logic error';
			torobpay_debug_log( "TorobPay Debug: $operation_name $status$order_info" );
			break;
		} else {
			torobpay_debug_log( "TorobPay Debug: $operation_name communication failed (will retry if attempts remain)$order_info" );
		}
	}

	return $response;
}

function torobpay_api_response_is_successful( $response ) {
	return ! is_wp_error( $response )
		&& is_array( $response )
		&& isset( $response['successful'] )
		&& $response['successful'];
}

/**
 * Merchant-facing text for a failed API response.
 *
 * @param mixed       $response Decoded response, or WP_Error.
 * @param string|null $fallback Used when neither the catalog nor the API has text.
 * @param string      $context  Error-catalog context; see includes/torobpay-error-codes.php.
 * @return string
 */
function torobpay_get_api_response_error_message( $response, $fallback = null, $context = 'order' ) {
	if ( null === $fallback ) {
		$fallback = __( 'Unknown error', 'torobpay-for-woocommerce' );
	}

	if ( is_wp_error( $response ) ) {
		return $response->get_error_message();
	}

	if ( empty( $response ) || ! is_array( $response ) ) {
		return __( 'Invalid response from server', 'torobpay-for-woocommerce' );
	}

	return torobpay_get_api_error_message( $context, $response, $fallback );
}

function torobpay_handle_response( $order, $response, $type = 'cancel' ) {
	if ( ! $order || ! method_exists( $order, 'add_order_note' ) ) {
		return;
	}

	// 1054: settled payment past TorobPay's edit/cancel window — permanent lock.
	if ( '1054' === torobpay_extract_api_error_code( $response ) ) {
		$order->update_meta_data( '_torobpay_remote_locked', 'yes' );
		$order->save_meta_data();
	}

	$order->add_order_note( torobpay_prepare_message( $response, $type ) );
}

function torobpay_prepare_message( $response, $type ) {
	$order_type = $type == 'cancel' ? __( 'Cancel', 'torobpay-for-woocommerce' ) : __( 'Update', 'torobpay-for-woocommerce' );

	if ( $type == 'status' ) {
		$order_type = __( 'Check payment status', 'torobpay-for-woocommerce' );
	}

	$message = sprintf(
		'<span class="torobpay-notice">%s <strong style="color:green">%s</strong>.</span>',
		esc_html__( 'TorobPay:', 'torobpay-for-woocommerce' ),
		esc_html(
			sprintf(
				/* translators: %s: operation type label */
				__( '%s order operation completed successfully', 'torobpay-for-woocommerce' ),
				$order_type
			)
		)
	);

	if ( is_wp_error( $response ) ) {
		$message = sprintf(
			'<span class="torobpay-notice">%s <strong style="color:red">%s</strong></span>',
			esc_html__( 'TorobPay:', 'torobpay-for-woocommerce' ),
			esc_html(
				sprintf(
					/* translators: 1: operation type, 2: error message */
					__( '%1$s order operation failed: %2$s', 'torobpay-for-woocommerce' ),
					$order_type,
					$response->get_error_message()
				)
			)
		);
		return $message;
	}

	if ( empty( $response ) || ! is_array( $response ) ) {
		$message = sprintf(
			'<span class="torobpay-notice">%s <strong style="color:red">%s</strong></span>',
			esc_html__( 'TorobPay:', 'torobpay-for-woocommerce' ),
			esc_html(
				sprintf(
					/* translators: %s: operation type label */
					__( '%s order operation failed: Invalid response from server', 'torobpay-for-woocommerce' ),
					$order_type
				)
			)
		);
		return $message;
	}

	if ( ! isset( $response['successful'] ) || ! $response['successful'] ) {
		$response_error_message = torobpay_get_api_response_error_message( $response, __( 'Unknown error response from server', 'torobpay-for-woocommerce' ) );

		$message = sprintf(
			'<span class="torobpay-notice">%s <strong style="color:red">%s</strong></span>',
			esc_html__( 'TorobPay:', 'torobpay-for-woocommerce' ),
			esc_html(
				sprintf(
					/* translators: 1: operation type, 2: error message */
					__( '%1$s order operation failed: %2$s', 'torobpay-for-woocommerce' ),
					$order_type,
					$response_error_message
				)
			)
		);
		return $message;
	}

	return $message;
}
