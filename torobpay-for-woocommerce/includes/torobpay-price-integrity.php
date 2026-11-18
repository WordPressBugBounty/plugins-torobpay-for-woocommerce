<?php
/**
 * Compare the rial amount sent when minting a payment token with the amount TorobPay stored.
 *
 * The token response `amount` is what the payment page shows. Redirect only when it is
 * the same integer that was sent. A missing or different value stops the payment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse a rial amount that can be compared exactly.
 *
 * Integers and digit strings are accepted. A whole-number float is accepted so a JSON
 * value such as 500000.0 still matches. Fractional values do not.
 *
 * @param mixed $amount Raw amount.
 * @return int|null
 */
function torobpay_price_integrity_parse_rial_amount( $amount ) {
	if ( is_int( $amount ) ) {
		return $amount;
	}

	if ( is_string( $amount ) && 1 === preg_match( '/^-?\d+$/', $amount ) ) {
		return (int) $amount;
	}

	if ( is_float( $amount ) && is_finite( $amount ) && floor( $amount ) === $amount ) {
		$as_int = (int) $amount;
		if ( (float) $as_int === $amount ) {
			return $as_int;
		}
	}

	return null;
}

/**
 * Whether two rial amounts are the same integer.
 *
 * @param mixed $expected Amount sent in the token request.
 * @param mixed $stored   Amount returned on the token response.
 * @return bool
 */
function torobpay_price_integrity_rial_amounts_match( $expected, $stored ) {
	$expected_rial = torobpay_price_integrity_parse_rial_amount( $expected );
	$stored_rial   = torobpay_price_integrity_parse_rial_amount( $stored );

	if ( null === $expected_rial || null === $stored_rial ) {
		return false;
	}

	return $expected_rial === $stored_rial;
}

/**
 * Order-note text for a token whose stored amount is not the amount we sent.
 *
 * @param mixed $expected Amount sent in the token request.
 * @param mixed $stored   Amount returned on the token response.
 * @return string
 */
function torobpay_price_integrity_mismatch_message( $expected, $stored ) {
	return sprintf(
		/* translators: 1: rial amount sent in the token request, 2: rial amount returned by the token API */
		__( 'TorobPay stopped this payment because the stored token amount did not match the amount sent. Sent: %1$s. Stored: %2$s.', 'torobpay-for-woocommerce' ),
		torobpay_price_integrity_format_rial_amount( $expected ),
		torobpay_price_integrity_format_rial_amount( $stored )
	);
}

/**
 * Display text for an amount in an integrity note.
 *
 * @param mixed $amount Raw amount.
 * @return string
 */
function torobpay_price_integrity_format_rial_amount( $amount ) {
	$parsed = torobpay_price_integrity_parse_rial_amount( $amount );
	if ( null === $parsed ) {
		return __( 'missing', 'torobpay-for-woocommerce' );
	}

	return (string) $parsed;
}
