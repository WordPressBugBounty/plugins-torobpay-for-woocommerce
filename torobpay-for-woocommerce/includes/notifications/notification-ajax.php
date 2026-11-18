<?php
/**
 * TorobPay Notification Center — AJAX handlers.
 *
 * Dismiss, restore, and re-check the contract notice. Each response includes
 * the re-rendered panel so the modal can swap its contents without a reload.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_torobpay_dismiss_notification', 'torobpay_ajax_dismiss_notification' );
/**
 * Dismiss a notification for the current user.
 *
 * @return void
 */
function torobpay_ajax_dismiss_notification() {
	check_ajax_referer( 'torobpay-dismiss-notification', 'security' );

	$notification_id = torobpay_get_notification_id_from_request();
	if ( null === $notification_id ) {
		return;
	}

	torobpay_dismiss_notification( $notification_id );

	torobpay_send_notification_panel_response();
}

add_action( 'wp_ajax_torobpay_recheck_eligibility_contract', 'torobpay_ajax_recheck_eligibility_contract' );
/**
 * Re-check the merchant contract from the eligibility notice.
 *
 * One eligibility call with a known-valid amount. The panel is re-rendered
 * so a cleared notice disappears without a reload.
 *
 * @return void
 */
function torobpay_ajax_recheck_eligibility_contract() {
	check_ajax_referer( 'torobpay-dismiss-notification', 'security' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$result = torobpay_probe_eligibility_contract();

	torobpay_send_notification_panel_response(
		array(
			'message' => isset( $result['message'] ) ? $result['message'] : '',
		)
	);
}

add_action( 'wp_ajax_torobpay_undismiss_notification', 'torobpay_ajax_undismiss_notification' );
/**
 * Restore a previously dismissed notification for the current user.
 *
 * @return void
 */
function torobpay_ajax_undismiss_notification() {
	check_ajax_referer( 'torobpay-dismiss-notification', 'security' );

	$notification_id = torobpay_get_notification_id_from_request();
	if ( null === $notification_id ) {
		return;
	}

	torobpay_undismiss_notification( $notification_id );

	torobpay_send_notification_panel_response();
}

/**
 * Validate the capability and notification id of a dismiss/restore request.
 *
 * Sends the JSON error response itself, so callers only need to bail on null.
 *
 * @return string|null Notification id, or null when the request was rejected.
 */
function torobpay_get_notification_id_from_request() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'torobpay-for-woocommerce' ) ) );
		return null;
	}

	$notification_id = isset( $_POST['notification_id'] )
		? sanitize_text_field( wp_unslash( $_POST['notification_id'] ) )
		: '';

	if ( empty( $notification_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid notification ID.', 'torobpay-for-woocommerce' ) ) );
		return null;
	}

	return $notification_id;
}

/**
 * Send the refreshed badge, tier and panel markup.
 *
 * @param  array $extra Extra fields merged into the JSON payload.
 * @return void
 */
function torobpay_send_notification_panel_response( $extra = array() ) {
	ob_start();
	torobpay_render_notification_panel_body();
	$panel_html = ob_get_clean();

	wp_send_json_success(
		array_merge(
			array(
				'badge_count' => torobpay_get_notification_badge_count(),
				'tier'        => torobpay_get_notification_tier(),
				'panel_html'  => $panel_html,
			),
			is_array( $extra ) ? $extra : array()
		)
	);
}
