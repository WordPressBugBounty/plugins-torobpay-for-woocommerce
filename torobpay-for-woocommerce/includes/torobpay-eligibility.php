<?php
/**
 * Shared eligibility helper for TorobPay WooCommerce gateway.
 *
 * This centralises the logic so both classic checkout filters and
 * WooCommerce Blocks integration call the same code — keeping behaviour
 * consistent and API-call count to a minimum (one per request).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once __DIR__ . '/torobpay-error-codes.php';

/**
 * Option holding merchant-level eligibility failures, keyed by error code.
 *
 * Shape: array<code, array{first_seen:int, last_seen:int}>.
 * The merchant-facing copy comes from the error catalog, not from this option.
 */
if ( ! defined( 'TOROBPAY_ELIGIBILITY_ERROR_OPTION' ) ) {
	define( 'TOROBPAY_ELIGIBILITY_ERROR_OPTION', 'torobpay_eligibility_error' );
}

/**
 * Fixed Rial amount for the manual contract check. The eligibility minimum, so
 * a healthy contract answers 200 instead of 1011.
 */
if ( ! defined( 'TOROBPAY_ELIGIBILITY_CONTRACT_PROBE_AMOUNT' ) ) {
	define( 'TOROBPAY_ELIGIBILITY_CONTRACT_PROBE_AMOUNT', 200000 );
}

/**
 * How long a stored eligibility error may go without a refresh before the next
 * failure rewrites it. Keeps a busy checkout from writing the option on every
 * page load while still keeping `last_seen` roughly current.
 */
if ( ! defined( 'TOROBPAY_ELIGIBILITY_ERROR_REFRESH' ) ) {
	define( 'TOROBPAY_ELIGIBILITY_ERROR_REFRESH', HOUR_IN_SECONDS );
}

/**
 * Check merchant + amount eligibility via TorobPay API.
 *
 * @param float       $amount    Raw cart or order amount.
 * @param string|null $currency  Optional currency; defaults to store currency.
 *
 * @return array {
 *   @type bool       $eligible     Whether merchant is eligible for the given amount.
 *   @type string      $title        Localised title message to show customers (may be empty).
 *   @type string      $description  Localised description (may be empty).
 *   @type WP_Error|null $wp_error   Error object if API call failed, otherwise null.
 *   @type mixed       $raw_response Full raw API response when available.
 * }
 */
function torobpay_resolve_eligibility( $amount, $currency = null ) {
	// Mid-probe stub: calculate_totals() can re-enter available-gateways /
	// Blocks is_active; never start another eligibility HTTP call or probe.
	if ( function_exists( 'torobpay_is_payment_price_parity_probing' )
		&& torobpay_is_payment_price_parity_probing() ) {
		return [
			'eligible'     => true,
			'title'        => '',
			'description'  => '',
			'wp_error'     => null,
			'raw_response' => null,
		];
	}

	// Skip external request in admin / CLI / zero-amount contexts.
	if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || floatval( $amount ) <= 0 ) {
		return [
			'eligible'     => true,
			'title'        => '',
			'description'  => '',
			'wp_error'     => null,
			'raw_response' => null,
		];
	}

	$request_uri   = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$is_blocks_api = defined( 'REST_REQUEST' ) && REST_REQUEST && $request_uri && strpos( $request_uri, '/wc/store/' ) !== false;

	// Thank-you / order-received is still is_checkout() in WooCommerce, but
	// payment methods are not shown there — skip the eligibility API.
	$is_thankyou = ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
		|| ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) );

	if ( $is_thankyou || ( ! is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) && ! $is_blocks_api ) ) {
		return [
			'eligible'     => true,
			'title'        => '',
			'description'  => '',
			'wp_error'     => null,
			'raw_response' => null,
		];
	}

	$currency     = $currency ?: get_woocommerce_currency();
	$final_amount = torobpay_get_amount_by_currency( $amount, $currency );

	// Request-level cache to avoid duplicate external calls within the same page load.
	static $cache = [];
	$cache_key    = $currency . '|' . $final_amount;
	if ( isset( $cache[ $cache_key ] ) ) {
		return $cache[ $cache_key ];
	}

	// Perform API request.
	$api_response = ( new TorobPayAPI() )->is_merchant_eligible( $final_amount );

	// Error handling.
	if ( is_wp_error( $api_response ) ) {
		$result = [
			'eligible'     => false,
			'title'        => '',
			'description'  => '',
			'wp_error'     => $api_response,
			'raw_response' => null,
		];
		// Request-scoped only — do not persist a negative cache across requests.
		$cache[ $cache_key ] = $result;
		return $result;
	}

	torobpay_record_eligibility_api_error( $api_response );

	$resp_arr    = $api_response['response'] ?? [];
	$eligible    = ! empty( $resp_arr['eligible'] );
	$title       = $resp_arr['title_message'] ?? '';
	$description = $resp_arr['description'] ?? '';

	$result = [
		'eligible'     => $eligible,
		'title'        => $title,
		'description'  => $description,
		'wp_error'     => null,
		'raw_response' => $resp_arr,
	];

	$cache[ $cache_key ] = $result;

	return $result;
}

/**
 * Persist a merchant-level eligibility failure, or clear one the response has
 * actually ruled out.
 *
 * Eligibility checks the contract before the amount, so:
 *   - 200 clears every stored eligibility error
 *   - 1011, and 400/1000 ("no amount found"), mean the contract check passed,
 *     so only 1100 is dropped
 *   - 1099 and 1100 are stored side by side; a later 1099 does not drop 1100,
 *     because the contract was not re-checked
 *   - 401/1000, a 1000 with no HTTP status, a body with no code, a transport
 *     error or a 5xx leave 1100 in place
 *
 * @param  mixed $api_response Decoded eligibility response, or WP_Error.
 * @return void
 */
function torobpay_record_eligibility_api_error( $api_response ) {
	if ( ! is_array( $api_response ) ) {
		return;
	}

	if ( ! empty( $api_response['successful'] ) ) {
		torobpay_clear_eligibility_api_error();
		return;
	}

	$code = torobpay_extract_api_error_code( $api_response );

	// 1011 and 400/1000 are only returned after the contract check has passed.
	// 401/1000 is "not authenticated" and says nothing about the contract.
	$status = torobpay_extract_api_http_status( $api_response );
	if ( '1011' === $code || ( '1000' === $code && 400 === $status ) ) {
		torobpay_forget_eligibility_error_codes( array( '1100' ) );
		return;
	}

	if ( '' === $code ) {
		return;
	}

	$definition = torobpay_get_api_error_definition( 'eligibility', $code );
	if ( ! $definition || empty( $definition['notify'] ) ) {
		return;
	}

	$entries = torobpay_get_eligibility_error_entries();
	$now     = time();

	if ( isset( $entries[ $code ] )
		&& ( $now - (int) $entries[ $code ]['last_seen'] ) < TOROBPAY_ELIGIBILITY_ERROR_REFRESH ) {
		return;
	}

	$first_seen = isset( $entries[ $code ]['first_seen'] ) ? (int) $entries[ $code ]['first_seen'] : $now;

	$entries[ $code ] = array(
		'first_seen' => $first_seen,
		'last_seen'  => $now,
	);

	torobpay_save_eligibility_error_entries( $entries );
}

/**
 * Stored eligibility failures, keyed by code.
 *
 * @return array<string,array{first_seen:int,last_seen:int}>
 */
function torobpay_get_eligibility_error_entries() {
	$stored = get_option( TOROBPAY_ELIGIBILITY_ERROR_OPTION );
	if ( ! is_array( $stored ) || empty( $stored ) ) {
		return array();
	}

	$entries = array();
	foreach ( $stored as $code => $row ) {
		$code = torobpay_normalize_api_error_code( $code );
		if ( '' === $code || ! is_array( $row ) ) {
			continue;
		}
		$entries[ $code ] = array(
			'first_seen' => isset( $row['first_seen'] ) ? (int) $row['first_seen'] : time(),
			'last_seen'  => isset( $row['last_seen'] ) ? (int) $row['last_seen'] : time(),
		);
	}

	return $entries;
}

/**
 * @param  array<string,array{first_seen:int,last_seen:int}> $entries
 * @return void
 */
function torobpay_save_eligibility_error_entries( array $entries ) {
	if ( empty( $entries ) ) {
		torobpay_clear_eligibility_api_error();
		return;
	}

	update_option( TOROBPAY_ELIGIBILITY_ERROR_OPTION, $entries, false );
}

/**
 * Drop specific stored eligibility codes and leave the rest in place.
 *
 * @param  string[] $codes
 * @return void
 */
function torobpay_forget_eligibility_error_codes( array $codes ) {
	$entries = torobpay_get_eligibility_error_entries();
	$changed = false;

	foreach ( $codes as $code ) {
		$code = torobpay_normalize_api_error_code( $code );
		if ( '' !== $code && isset( $entries[ $code ] ) ) {
			unset( $entries[ $code ] );
			$changed = true;
		}
	}

	if ( $changed ) {
		torobpay_save_eligibility_error_entries( $entries );
	}
}

/**
 * Drop every stored eligibility failure.
 *
 * @return void
 */
function torobpay_clear_eligibility_api_error() {
	if ( get_option( TOROBPAY_ELIGIBILITY_ERROR_OPTION ) ) {
		delete_option( TOROBPAY_ELIGIBILITY_ERROR_OPTION );
	}
}

/**
 * Ask eligibility once, with a known-valid amount, and apply the usual clear rules.
 *
 * Called from the contract notice's Check again button. A 200 clears every
 * stored eligibility error. 1011 and 400/1000 clear only 1100. 1099, 401/1000,
 * transport errors and 5xx leave 1100 in place.
 *
 * @return array{cleared:bool,message:string}
 */
function torobpay_probe_eligibility_contract() {
	$response = torobpay_request_eligibility_contract_probe();
	if ( null === $response || ! is_array( $response ) ) {
		return array(
			'cleared' => false,
			'message' => __( 'TorobPay could not be reached. Try again.', 'torobpay-for-woocommerce' ),
		);
	}

	torobpay_record_eligibility_api_error( $response );

	$entries = torobpay_get_eligibility_error_entries();
	if ( ! isset( $entries['1100'] ) ) {
		return array(
			'cleared' => true,
			'message' => '',
		);
	}

	$code = torobpay_extract_api_error_code( $response );
	if ( '1100' === $code ) {
		$message = __( 'The contract is still inactive.', 'torobpay-for-woocommerce' );
	} elseif ( '1099' === $code ) {
		$message = __( 'The account is inactive, so the contract was not re-checked.', 'torobpay-for-woocommerce' );
	} else {
		$message = __( 'The contract could not be confirmed.', 'torobpay-for-woocommerce' );
	}

	return array(
		'cleared' => false,
		'message' => $message,
	);
}

/**
 * Eligibility response for the manual contract check, or null when it cannot be sent.
 *
 * `torobpay_eligibility_contract_probe_response` may replace the HTTP call.
 * Returning null falls through to TorobPayAPI.
 *
 * @return mixed|null
 */
function torobpay_request_eligibility_contract_probe() {
	if ( function_exists( 'apply_filters' ) ) {
		$filtered = apply_filters( 'torobpay_eligibility_contract_probe_response', null );
		if ( null !== $filtered ) {
			return $filtered;
		}
	}

	if ( ! class_exists( 'TorobPayAPI' ) ) {
		return null;
	}

	return ( new TorobPayAPI() )->is_merchant_eligible( TOROBPAY_ELIGIBILITY_CONTRACT_PROBE_AMOUNT );
}

