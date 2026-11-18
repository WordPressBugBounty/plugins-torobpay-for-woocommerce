<?php
/**
 * Compare TorobPay's cart total with an unconfigured payment method.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var int Absolute IRR tolerance; gaps at or below this count as equal. */
define( 'TOROBPAY_PAYMENT_PRICE_PARITY_TOLERANCE_IRR', 10000 );

/** @var int Seconds to reuse a parity result for the same cart. */
define( 'TOROBPAY_PAYMENT_PRICE_PARITY_CACHE_TTL', 120 );

/**
 * Read or set the in-request parity probe flag.
 *
 * The flag stays out of $GLOBALS['torobpay_payment_price_parity_probing'].
 * Fee plugins that read that global skip their surcharge for both totals.
 *
 * @param bool|null $active Null reads the flag. True or false sets it.
 * @return bool
 */
function torobpay_payment_price_parity_reentry( $active = null ) {
	static $flag = false;

	if ( null !== $active ) {
		$flag = (bool) $active;
	}

	return $flag;
}

/**
 * Whether a payment-price-parity probe is running (avoids re-entry during calculate_totals).
 *
 * @return bool
 */
function torobpay_is_payment_price_parity_probing() {
	return torobpay_payment_price_parity_reentry();
}

/**
 * Gateway IDs that count as TorobPay.
 *
 * Uses the fee-blocker's list when that file is loaded.
 *
 * @return string[]
 */
function torobpay_payment_price_parity_torobpay_gateway_ids() {
	if ( function_exists( 'torobpay_gateway_fee_torobpay_gateway_ids' ) ) {
		$ids = torobpay_gateway_fee_torobpay_gateway_ids();
		if ( is_array( $ids ) && ! empty( $ids ) ) {
			return $ids;
		}
	}

	return array( 'WC_Gateway_TorobPay', 'torobpay' );
}

/**
 * Whether a gateway ID is TorobPay.
 *
 * @param string $gateway_id Gateway ID.
 * @return bool
 */
function torobpay_payment_price_parity_is_torobpay_gateway_id( $gateway_id ) {
	$gateway_id = (string) $gateway_id;
	if ( '' === $gateway_id ) {
		return false;
	}

	foreach ( torobpay_payment_price_parity_torobpay_gateway_ids() as $known_id ) {
		if ( 0 === strcasecmp( $gateway_id, (string) $known_id ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Registered payment gateways, keyed by id.
 *
 * Reads the gateway registry, not the methods currently drawn on checkout.
 *
 * @return array
 */
function torobpay_payment_price_parity_registered_gateways() {
	if ( ! function_exists( 'WC' ) ) {
		return array();
	}

	$wc = WC();
	if ( ! is_object( $wc ) || ! is_callable( array( $wc, 'payment_gateways' ) ) ) {
		return array();
	}

	$controller = $wc->payment_gateways();
	if ( ! is_object( $controller ) || ! is_callable( array( $controller, 'payment_gateways' ) ) ) {
		return array();
	}

	$gateways = $controller->payment_gateways();

	return is_array( $gateways ) ? $gateways : array();
}

/**
 * Point the checkout payment-method fields at one gateway.
 *
 * Writes the id even when the request did not send one, so a fee plugin that
 * reads POST instead of the session still prices this probe. The previous
 * values are restored afterwards. A missing field is removed again.
 *
 * @param string $gateway_id Gateway ID being measured.
 * @return array Saved fields for torobpay_payment_price_parity_restore_chosen_method().
 */
function torobpay_payment_price_parity_pin_chosen_method( $gateway_id ) {
	$gateway_id = (string) $gateway_id;
	$state      = array(
		'had_post_method'    => false,
		'post_method'        => null,
		'had_post_data'      => false,
		'post_data'          => null,
		'had_get_method'     => false,
		'get_method'         => null,
		'had_request_method' => false,
		'request_method'     => null,
	);

	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- replaced only while this gateway is calculated, then restored.
	$state['had_post_method'] = isset( $_POST['payment_method'] );
	if ( $state['had_post_method'] ) {
		$state['post_method'] = $_POST['payment_method'];
	}
	$_POST['payment_method'] = $gateway_id;

	if ( isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
		$state['had_post_data'] = true;
		$state['post_data']     = $_POST['post_data'];
		$parsed                 = array();
		$raw                    = function_exists( 'wp_unslash' ) ? wp_unslash( $_POST['post_data'] ) : $_POST['post_data'];
		parse_str( $raw, $parsed ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- rebuilt only for this probe.
		if ( ! is_array( $parsed ) ) {
			$parsed = array();
		}
		$parsed['payment_method'] = $gateway_id;
		$_POST['post_data']       = http_build_query( $parsed );
	}

	$state['had_get_method'] = isset( $_GET['payment_method'] );
	if ( $state['had_get_method'] ) {
		$state['get_method'] = $_GET['payment_method'];
	}
	$_GET['payment_method'] = $gateway_id;

	$state['had_request_method'] = isset( $_REQUEST['payment_method'] );
	if ( $state['had_request_method'] ) {
		$state['request_method'] = $_REQUEST['payment_method'];
	}
	$_REQUEST['payment_method'] = $gateway_id;
	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	return $state;
}

/**
 * Restore checkout payment-method fields after one gateway probe.
 *
 * @param array $state Saved fields.
 * @return void
 */
function torobpay_payment_price_parity_restore_chosen_method( $state ) {
	if ( ! is_array( $state ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- restoring the shopper's own request values.
	if ( ! empty( $state['had_post_method'] ) ) {
		$_POST['payment_method'] = $state['post_method'];
	} else {
		unset( $_POST['payment_method'] );
	}

	if ( ! empty( $state['had_post_data'] ) ) {
		$_POST['post_data'] = $state['post_data'];
	}

	if ( ! empty( $state['had_get_method'] ) ) {
		$_GET['payment_method'] = $state['get_method'];
	} else {
		unset( $_GET['payment_method'] );
	}

	if ( ! empty( $state['had_request_method'] ) ) {
		$_REQUEST['payment_method'] = $state['request_method'];
	} else {
		unset( $_REQUEST['payment_method'] );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing
}

/**
 * Cart total with this gateway selected, before TorobPay removes its surcharge.
 *
 * Fee removal and sale restoration stay paused while the probe flag is on.
 * The figure is the price the fee plugin would charge for this method.
 * Registered fee plugins may adjust their own saved gateway choice around
 * the calculation.
 *
 * @param string $gateway_id Gateway ID to simulate.
 * @return float
 */
function torobpay_payment_price_parity_cart_total_for_gateway( $gateway_id ) {
	$pinned = torobpay_payment_price_parity_pin_chosen_method( $gateway_id );
	$states = array();

	try {
		WC()->session->set( 'chosen_payment_method', $gateway_id );
		if ( function_exists( 'torobpay_price_parity_begin_gateway' ) ) {
			$states = torobpay_price_parity_begin_gateway( $gateway_id );
		}
		WC()->cart->calculate_totals();

		return (float) WC()->cart->get_total( 'edit' );
	} finally {
		if ( function_exists( 'torobpay_price_parity_end_gateway' ) ) {
			torobpay_price_parity_end_gateway( $states );
		}
		torobpay_payment_price_parity_restore_chosen_method( $pinned );
	}
}

/**
 * Request-only payment method id with no stored fee rule.
 *
 * @return string
 */
function torobpay_payment_price_parity_baseline_gateway_id() {
	static $gateway_id = null;

	if ( is_string( $gateway_id ) && '' !== $gateway_id ) {
		return $gateway_id;
	}

	$taken = array();
	foreach ( array_keys( torobpay_payment_price_parity_registered_gateways() ) as $known_id ) {
		$taken[ strtolower( (string) $known_id ) ] = true;
	}

	do {
		try {
			$gateway_id = 'b' . bin2hex( random_bytes( 8 ) );
		} catch ( Exception $e ) {
			unset( $e );
			$gateway_id = 'b' . md5( uniqid( 'torobpay', true ) );
		}
	} while ( isset( $taken[ strtolower( $gateway_id ) ] ) );

	return $gateway_id;
}

/**
 * Enabled TorobPay gateway id, or an empty string.
 *
 * @return string
 */
function torobpay_payment_price_parity_enabled_torobpay_id() {
	foreach ( torobpay_payment_price_parity_registered_gateways() as $gateway_id => $gateway ) {
		if ( ! is_object( $gateway ) || ! isset( $gateway->enabled ) || 'yes' !== $gateway->enabled ) {
			continue;
		}
		if ( torobpay_payment_price_parity_is_torobpay_gateway_id( $gateway_id ) ) {
			return (string) $gateway_id;
		}
	}

	return '';
}

/**
 * Build a cache key for the current cart and TorobPay gateway.
 *
 * The baseline id changes every request, so it is not part of the key.
 *
 * @param string $torobpay_id TorobPay gateway ID.
 * @return string
 */
function torobpay_payment_price_parity_cache_key( $torobpay_id ) {
	$cart_hash = '';
	if ( function_exists( 'WC' ) && WC() && WC()->cart ) {
		$cart_hash = (string) WC()->cart->get_cart_hash();
	}

	return 'torobpay_ppp_v5_' . md5( $cart_hash . '|' . (string) $torobpay_id );
}

/**
 * Classify TorobPay vs the baseline total (IRR) relative to tolerance.
 *
 * @param float $torobpay_irr TorobPay total in IRR.
 * @param float $baseline_irr Baseline total in IRR.
 * @param int   $tolerance    Absolute IRR tolerance.
 * @return string equal|surcharge|discount
 */
function torobpay_payment_price_parity_classify_diff( $torobpay_irr, $baseline_irr, $tolerance ) {
	$diff = $torobpay_irr - $baseline_irr;

	if ( abs( $diff ) <= $tolerance ) {
		return 'equal';
	}

	return $diff > 0 ? 'surcharge' : 'discount';
}

/**
 * Probe whether TorobPay's cart total differs from an unconfigured method.
 *
 * The baseline is a request-only gateway id with no fee rule. It is priced
 * first, so a plugin that raises TorobPay's line price and leaves it there
 * cannot leak that raise into the baseline. TorobPay higher than the baseline
 * is a surcharge. TorobPay lower is a discount. Both totals are taken before
 * TorobPay removes a surcharge. The shopper cart is recalculated afterwards
 * with removal turned back on.
 *
 * @return string One of: equal, surcharge, discount, unknown.
 */
function torobpay_get_payment_price_parity() {
	static $cached = null;

	if ( null !== $cached ) {
		return $cached;
	}

	if ( torobpay_is_payment_price_parity_probing() ) {
		return 'unknown';
	}

	if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart || ! WC()->session ) {
		$cached = 'unknown';
		return $cached;
	}

	if ( WC()->cart->is_empty() ) {
		$cached = 'unknown';
		return $cached;
	}

	$torobpay_id = torobpay_payment_price_parity_enabled_torobpay_id();
	if ( '' === $torobpay_id ) {
		$cached = 'unknown';
		return $cached;
	}

	$transient_key = torobpay_payment_price_parity_cache_key( $torobpay_id );
	$stored        = get_transient( $transient_key );
	$allowed       = array( 'equal', 'surcharge', 'discount', 'unknown' );
	if ( is_string( $stored ) && in_array( $stored, $allowed, true ) ) {
		$cached = $stored;
		return $cached;
	}

	$currency      = get_woocommerce_currency();
	$tolerance_irr = (int) TOROBPAY_PAYMENT_PRICE_PARITY_TOLERANCE_IRR;
	$original      = WC()->session->get( 'chosen_payment_method' );
	$baseline_id   = torobpay_payment_price_parity_baseline_gateway_id();

	// Fee plugins read this global and skip their surcharge while it is set.
	unset( $GLOBALS['torobpay_payment_price_parity_probing'] );
	torobpay_payment_price_parity_reentry( true );

	try {
		$baseline_total = torobpay_payment_price_parity_cart_total_for_gateway( $baseline_id );
		$torobpay_total = torobpay_payment_price_parity_cart_total_for_gateway( $torobpay_id );
		$torobpay_irr   = torobpay_get_amount_by_currency( $torobpay_total, $currency );
		$baseline_irr   = torobpay_get_amount_by_currency( $baseline_total, $currency );
		$cached         = torobpay_payment_price_parity_classify_diff( $torobpay_irr, $baseline_irr, $tolerance_irr );
	} catch ( Exception $e ) {
		$cached = 'unknown';
	} finally {
		// Publish the verdict before the shopper recalc. Gateway filtering can
		// call eligibility again, and that call must not start another probe.
		if ( null === $cached ) {
			$cached = 'unknown';
		}
		torobpay_payment_price_parity_reentry( false );
		unset( $GLOBALS['torobpay_payment_price_parity_probing'] );

		if ( null === $original || false === $original || '' === $original ) {
			WC()->session->set( 'chosen_payment_method', null );
		} else {
			WC()->session->set( 'chosen_payment_method', $original );
		}
		if ( function_exists( 'torobpay_price_parity_reset_gateway_probes' ) ) {
			torobpay_price_parity_reset_gateway_probes();
		}
		WC()->cart->calculate_totals();
	}

	if ( is_string( $cached ) && 'unknown' !== $cached ) {
		set_transient( $transient_key, $cached, (int) TOROBPAY_PAYMENT_PRICE_PARITY_CACHE_TTL );
	}

	return $cached;
}
