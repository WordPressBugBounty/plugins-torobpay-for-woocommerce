<?php
/**
 * Notification provider: eligibility endpoint failures.
 *
 * The eligibility call decides whether TorobPay appears at checkout, so a
 * merchant-level rejection there silently hides the gateway from every shopper.
 * torobpay_record_eligibility_api_error() stores each such rejection and
 * this provider surfaces it with the copy from the error catalog.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__, 2 ) . '/torobpay-eligibility.php';

torobpay_register_notification_provider( 'torobpay_eligibility_error_notification_provider' );

/**
 * Surface each stored merchant-level eligibility rejection.
 *
 * @return array
 */
function torobpay_eligibility_error_notification_provider() {
	$entries = torobpay_get_eligibility_error_entries();
	if ( empty( $entries ) ) {
		return array();
	}

	$items = array();

	foreach ( array_keys( $entries ) as $code ) {
		// Numeric codes are stored as int array keys.
		$code       = torobpay_normalize_api_error_code( $code );
		$definition = torobpay_get_api_error_definition( 'eligibility', $code );
		if ( ! $definition || empty( $definition['notify'] ) ) {
			continue;
		}

		$body = esc_html( $definition['message'] ) . '<p>'
			. esc_html__( 'Until this is resolved, TorobPay is not offered at checkout.', 'torobpay-for-woocommerce' )
			. '</p>';

		$action = torobpay_get_api_error_action( $definition['action'] );

		$item = array(
			'id'          => 'eligibility_error_' . $code,
			'type'        => 'eligibility_error',
			'priority'    => ( '1100' === $code ) ? 1 : 2,
			'title'       => $definition['title'],
			'body'        => $body,
			'dismissible' => false,
			'badge'       => 1,
			'tier'        => $definition['tier'],
		);

		if ( $action ) {
			$item['action_url']     = $action['url'];
			$item['action_label']   = $action['label'];
			$item['action_new_tab'] = $action['new_tab'];
		}

		// Only the contract notice can be re-checked on demand. 1099 means the
		// contract was not examined, so a fresh eligibility call is the 1100 button.
		if ( '1100' === $code ) {
			$item['recheck'] = true;
		}

		$items[] = $item;
	}

	return $items;
}
