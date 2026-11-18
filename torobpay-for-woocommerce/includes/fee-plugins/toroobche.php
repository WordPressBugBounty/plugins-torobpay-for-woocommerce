<?php
/**
 * Toroobche gateway fee plugin.
 *
 * 1.1.4 ships as wc-payment-adjuster (WC_Payment_Adjuster). Older builds
 * used wc-custom-percentage-gateway-fee.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TOROBPAY_GATEWAY_FEE_PLUGIN_TEXT_DOMAIN', 'wc-custom-percentage-gateway-fee' );

/**
 * Fee line name used by Toroobche in fee (کارمزد) mode.
 *
 * @return string
 */
function torobpay_gateway_fee_line_name() {
	return 'کارمزد درگاه پرداخت';
}

/**
 * Cart-fee id Toroobche 1.1.4 sets on both surcharges and discounts.
 *
 * @return string
 */
function torobpay_gateway_fee_adjustment_id() {
	return 'wc_payment_adjuster_gateway_adjustment';
}

/**
 * Whether the current Toroobche build is loaded.
 *
 * @return bool
 */
function torobpay_gateway_fee_current_plugin_active() {
	if ( class_exists( 'WC_Payment_Adjuster', false ) ) {
		return true;
	}

	if ( function_exists( 'wc_payment_adjuster' ) ) {
		return true;
	}

	return function_exists( 'did_action' ) && (bool) did_action( 'wc_payment_adjuster_loaded' );
}

/**
 * Whether an older Toroobche build is loaded.
 *
 * @return bool
 */
function torobpay_gateway_fee_legacy_plugin_active() {
	if ( class_exists( 'WC_Custom_Percentage_Gateway_Fee', false ) ) {
		return true;
	}

	if ( function_exists( 'wc_custom_percentage_gateway_fee' ) ) {
		return true;
	}

	return function_exists( 'did_action' ) && (bool) did_action( 'wc_custom_percentage_gateway_fee_loaded' );
}

/**
 * Whether the known gateway fee plugin is active (runtime signals only).
 *
 * @return bool
 */
function torobpay_gateway_fee_plugin_active() {
	return torobpay_gateway_fee_current_plugin_active() || torobpay_gateway_fee_legacy_plugin_active();
}

/**
 * Text domain to show in the admin notice for the build that is loaded.
 *
 * @return string
 */
function torobpay_gateway_fee_plugin_slug() {
	if ( torobpay_gateway_fee_current_plugin_active() ) {
		return 'wc-payment-adjuster';
	}

	return TOROBPAY_GATEWAY_FEE_PLUGIN_TEXT_DOMAIN;
}

/**
 * Drop invisible marks from a checkout fee title.
 *
 * Toroobche 1.1.4 appends U+200C to the TorobPay fee name so an exact title
 * comparison misses it. The character is invisible in the cart.
 *
 * @param string $name Fee name.
 * @return string
 */
function torobpay_gateway_fee_normalize_name( $name ) {
	$name = trim( (string) $name );
	if ( '' === $name ) {
		return '';
	}

	$invisible = '/[\x{00AD}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u';
	$stripped  = preg_replace( $invisible, '', $name );

	return is_string( $stripped ) ? trim( $stripped ) : $name;
}

/**
 * Positive fee titles Toroobche may put on a TorobPay surcharge.
 *
 * The default title is always included. A saved custom title counts only
 * while the current build is loaded, so a leftover option does not remove
 * an unrelated fee.
 *
 * @return string[]
 */
function torobpay_gateway_fee_positive_names() {
	$names = array( torobpay_gateway_fee_line_name() );

	if ( ! torobpay_gateway_fee_current_plugin_active() || ! function_exists( 'get_option' ) ) {
		return $names;
	}

	$option = get_option( 'wc_payment_adjuster', array() );
	if ( ! is_array( $option ) || ! isset( $option['custom_fee_name'] ) || ! is_string( $option['custom_fee_name'] ) ) {
		return $names;
	}

	$custom = torobpay_gateway_fee_normalize_name( $option['custom_fee_name'] );
	if ( '' !== $custom && ! in_array( $custom, $names, true ) ) {
		$names[] = $custom;
	}

	return $names;
}

/**
 * Display name for the known gateway fee plugin.
 *
 * @return string
 */
function torobpay_gateway_fee_plugin_name() {
	return __( 'WooCommerce gateway fee & discount plugin — Torbache', 'torobpay-for-woocommerce' );
}

/**
 * Whether a cart fee is an unauthorized positive gateway fee on TorobPay.
 *
 * Matches a positive amount on Toroobche’s stable fee id, its default title,
 * or the configured title. Invisible marks in the title are ignored.
 * Discount (negative) fees are left alone, including discounts that share
 * the same fee id.
 *
 * @param object $fee Cart fee object from WC_Cart::get_fees().
 * @return bool
 */
function torobpay_gateway_fee_is_unauthorized_fee_line( $fee ) {
	if ( ! is_object( $fee ) ) {
		return false;
	}

	$amount = isset( $fee->amount ) ? (float) $fee->amount : 0.0;
	if ( $amount <= 0 ) {
		return false;
	}

	if ( isset( $fee->id ) && torobpay_gateway_fee_adjustment_id() === (string) $fee->id ) {
		return true;
	}

	if ( ! isset( $fee->name ) ) {
		return false;
	}

	$name = torobpay_gateway_fee_normalize_name( $fee->name );
	if ( '' === $name ) {
		return false;
	}

	return in_array( $name, torobpay_gateway_fee_positive_names(), true );
}

/**
 * Warning copy when the gateway fee plugin is active.
 *
 * @return string HTML-safe body (no outer wrapper).
 */
function torobpay_gateway_fee_warning_notice_body() {
	$plugin_name = torobpay_gateway_fee_plugin_name();
	$plugin_slug = torobpay_gateway_fee_plugin_slug();

	$lines   = array();
	$lines[] = sprintf(
		/* translators: 1: plugin name, 2: plugin text domain / slug */
		__( 'Plugin "%1$s" (<code>%2$s</code>) is active.', 'torobpay-for-woocommerce' ),
		esc_html( $plugin_name ),
		esc_html( $plugin_slug )
	);
	$lines[] = __( 'Under TorobPay terms, charging a fee on the TorobPay gateway is not allowed.', 'torobpay-for-woocommerce' );
	$lines[] = __( 'TorobPay automatically blocks gateway fees on its payment method. Discounts and fees on other gateways are not changed.', 'torobpay-for-woocommerce' );
	$lines[] = __( 'Despite the similar name (Torbache), this plugin has no affiliation with TorobPay.', 'torobpay-for-woocommerce' );

	return '<p>' . implode( '</p><p>', $lines ) . '</p>';
}

torobpay_register_blocked_fee_plugin(
	array(
		'id'          => 'gateway_fee_terms_warning',
		'dom_id'      => 'torobpay-gateway-fee-terms-notice',
		'priority'    => 15,
		'active'      => 'torobpay_gateway_fee_plugin_active',
		'notice_body' => 'torobpay_gateway_fee_warning_notice_body',
		'fee_line'    => 'torobpay_gateway_fee_is_unauthorized_fee_line',
	)
);
