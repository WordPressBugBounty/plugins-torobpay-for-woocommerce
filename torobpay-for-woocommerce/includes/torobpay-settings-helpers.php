<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function torobpay_get_order_amount_in_iranian_rial( WC_Order $order ) {
	return torobpay_get_amount_by_currency( $order->get_total(), $order->get_currency() );
}

/**
 * Multiplier that converts a store-currency amount into Iranian rial.
 *
 * @param string|null $currency Store currency code. Null uses the WooCommerce currency.
 * @return int
 */
function torobpay_get_currency_amount_factor( $currency = null ) {
	if ( null === $currency ) {
		$currency = get_woocommerce_currency();
	}

	$currency_lower = strtolower( (string) $currency );

	$toman_variants = array(
		'irt',
		'toman',
		'iran toman',
		'iranian toman',
		'iran-toman',
		'iranian-toman',
		'iran_toman',
		'iranian_toman',
		__( 'Toman', 'torobpay-for-woocommerce' ),
		__( 'Iranian Toman', 'torobpay-for-woocommerce' ),
	);

	if ( in_array( $currency_lower, $toman_variants, true ) ) {
		return 10;
	}
	if ( 'irht' === $currency_lower ) {
		return 10000;
	}
	if ( 'irhr' === $currency_lower ) {
		return 1000;
	}

	return 1;
}

function torobpay_get_amount_by_currency( $amount, $currency = null ) {
	$amount = floatval( $amount ) * torobpay_get_currency_amount_factor( $currency );

	// intval/floor would silently under-charge the merchant.
	return (int) ceil( $amount );
}

function torobpay_has_comission() {
	return false;
}

function torobpay_get_settings( $refresh = false ) {
	static $settings = null;
	if ( $refresh ) {
		$settings = null;
	}
	if ( $settings === null ) {
		$settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WooCommerce Settings API key: woocommerce_{gateway_id}_settings.
	}
	return $settings;
}

/**
 * Whether "Require mobile number" is on for an enabled gateway.
 *
 * A missing mobile_enabled key stays on, matching the checkbox default.
 * Reads the option directly so a settings save in the same request is visible.
 *
 * @return bool
 */
function torobpay_should_require_checkout_phone() {
	$settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WooCommerce Settings API key: woocommerce_{gateway_id}_settings.
	if ( ! is_array( $settings ) ) {
		return false;
	}
	if ( ! isset( $settings['enabled'] ) || 'yes' !== $settings['enabled'] ) {
		return false;
	}
	if ( ! array_key_exists( 'mobile_enabled', $settings ) ) {
		return true;
	}
	return 'yes' === $settings['mobile_enabled'];
}

/**
 * Force WooCommerce's checkout phone visibility to required.
 *
 * Classic checkout and block checkout both read woocommerce_checkout_phone_field.
 * Marking billing_phone required in woocommerce_checkout_fields still leaves the
 * classic asterisk and the block "optional" label on the optional visibility.
 *
 * wp-admin keeps the saved WooCommerce value. Checkout AJAX still needs the override.
 *
 * @param mixed $value Saved visibility: required, optional, hidden, or empty.
 * @return mixed
 */
function torobpay_checkout_phone_field_visibility( $value ) {
	$is_admin_screen = function_exists( 'is_admin' ) && is_admin()
		&& ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() );
	if ( $is_admin_screen || ! torobpay_should_require_checkout_phone() ) {
		return $value;
	}
	return 'required';
}

function torobpay_has_credentials() {
	$torobpay_settings = torobpay_get_settings();

	if ( ! is_array( $torobpay_settings ) ) {
		return false;
	}

	return ! empty( $torobpay_settings['client_id'] ) &&
			! empty( $torobpay_settings['client_secret'] ) &&
			! empty( $torobpay_settings['client_username'] ) &&
			! empty( $torobpay_settings['client_password'] );
}

/**
 * Whether all four gateway credential fields are empty.
 *
 * @return bool
 */
function torobpay_credentials_are_empty() {
	$torobpay_settings = torobpay_get_settings();

	if ( ! is_array( $torobpay_settings ) ) {
		return true;
	}

	return empty( $torobpay_settings['client_id'] )
		&& empty( $torobpay_settings['client_secret'] )
		&& empty( $torobpay_settings['client_username'] )
		&& empty( $torobpay_settings['client_password'] );
}

function torobpay_is_configured() {
	$torobpay_settings = torobpay_get_settings();

	if ( ! is_array( $torobpay_settings ) ) {
		return false;
	}

	$is_enabled = isset( $torobpay_settings['enabled'] ) && $torobpay_settings['enabled'] === 'yes';

	return $is_enabled && torobpay_has_credentials();
}

/** Persistent flag is primary; cached bearer token backfills upgraded sites. */
function torobpay_has_cached_auth_state() {
	if ( get_option( 'torobpay_auth_ok' ) ) {
		return true;
	}

	if ( ! get_transient( 'torobpay_bearer_token' ) ) {
		return false;
	}

	update_option( 'torobpay_auth_ok', true );
	return true;
}

/**
 * Read a yes/no display toggle from its dedicated option.
 *
 * @param string $option_name Option name.
 * @param string $default     Default when the option is unset ('yes' or 'no').
 * @return string 'yes' or 'no'
 */
function torobpay_get_display_toggle_setting( $option_name, $default = 'no' ) {
	static $display_settings = array();

	if ( array_key_exists( $option_name, $display_settings ) ) {
		return $display_settings[ $option_name ];
	}

	$option_value                     = get_option( $option_name, $default );
	$display_settings[ $option_name ] = $option_value === 'yes' ? 'yes' : 'no';

	return $display_settings[ $option_name ];
}

function torobpay_has_pdp() {
	if ( ! torobpay_is_configured() || ! torobpay_has_cached_auth_state() ) {
		return false;
	}

	return torobpay_get_display_toggle_setting( 'torobpay_widget_enabled', 'yes' ) === 'yes';
}

function torobpay_has_plp() {
	if ( ! torobpay_is_configured() || ! torobpay_has_cached_auth_state() ) {
		return false;
	}

	return torobpay_get_display_toggle_setting( 'torobpay_badge_enabled', 'yes' ) === 'yes';
}

function torobpay_has_marquee() {
	if ( ! torobpay_is_configured() || ! torobpay_has_cached_auth_state() ) {
		return false;
	}

	return torobpay_get_display_toggle_setting( 'torobpay_marquee_enabled', 'no' ) === 'yes';
}

/** Jet/Crocoblock grids bypass loop hooks, so enable the JS badge fallback. */
function torobpay_is_jetengine_active() {
	$active = class_exists( 'Jet_Engine' )
		|| function_exists( 'jet_engine' )
		|| defined( 'JET_ENGINE_VERSION' )
		|| class_exists( 'Jet_Woo_Builder' )
		|| function_exists( 'jet_woo_builder' )
		|| defined( 'JET_WOO_BUILDER_VERSION' );	return (bool) apply_filters( 'torobpay_is_jetengine_active', $active );
}
