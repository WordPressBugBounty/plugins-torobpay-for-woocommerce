<?php
/**
 * TorobPay Payment Method Integration for WooCommerce Checkout Block
 *
 * This class provides integration between the TorobPay payment gateway and
 * the WooCommerce Cart & Checkout Blocks system (FSE).
 *
 * @package TorobPay_WooCommerce_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * TorobPay_Blocks_Support class.
 *
 * Extends AbstractPaymentMethodType to integrate TorobPay with WooCommerce Checkout Block
 */
final class TorobPay_Blocks_Support extends AbstractPaymentMethodType {
	/**
	 * Payment method name/id/slug.
	 * Must match the ID used in the WC_Gateway_TorobPay class
	 *
	 * @var string
	 */
	protected $name = 'WC_Gateway_TorobPay';

	/**
	 * Cached result of the eligibility API call so that we don't hit the
	 * endpoint multiple times within the same request lifecycle.
	 *
	 * @var mixed|null
	 */
	protected $eligibility_response = null;

	/**
	 * Initializes the payment method.
	 * Loads settings from WooCommerce options.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', [] );
	}

	/**
	 * Returns if this payment method is active.
	 *
	 * @return boolean True if enabled in settings, false otherwise
	 */
	public function is_active() {
		// First check if the gateway itself is enabled in the settings.
		if ( empty( $this->settings['enabled'] ) || 'yes' !== $this->settings['enabled'] ) {
			return false;
		}

		/*
		 * Determine the payable amount.
		 * – During normal checkout we use the cart total.
		 * – On the order-pay page we fall back to order total if cart is empty.
		 */
		$amount = 0;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$amount = WC()->cart->get_total( 'edit' );
		}

		// Fallback: order-pay context (not thank-you — eligibility is skipped there).
		if ( ! $amount && function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) && isset( $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only order key for eligibility display.
			$order_key = sanitize_text_field( wp_unslash( $_GET['key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only order key for eligibility display.
			$order_id  = wc_get_order_id_by_order_key( $order_key );
			if ( $order_id ) {
				$order  = wc_get_order( $order_id );
				$amount = $order ? $order->get_total() : 0;
			}
		}

		$currency = get_woocommerce_currency();

		// Shared helper ensures single responsibility and DRY.
		if ( ! function_exists( 'torobpay_resolve_eligibility' ) ) {
			require_once dirname( TOROBPAY_PLUGIN_FILE ) . '/includes/torobpay-eligibility.php';
		}

		$eligibility                = torobpay_resolve_eligibility( $amount, $currency );
		$this->eligibility_response = $eligibility['raw_response'];

		// If API errored or merchant/amount not eligible, gateway is inactive.
		if ( $eligibility['wp_error'] || ! $eligibility['eligible'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array Script handles
	 */
	public function get_payment_method_script_handles() {
		$asset_path = dirname( TOROBPAY_PLUGIN_FILE ) . '/assets/js/blocks/torobpay-blocks.asset.php';
		$version    = TOROBPAY_VERSION;

		if ( file_exists( $asset_path ) ) {
			$asset        = require $asset_path;
			$version      = $asset['version'] ?? $version;
			$dependencies = $asset['dependencies'] ?? [];
		} else {
			// Fallback dependencies if asset file doesn't exist
			$dependencies = [ 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-components', 'wp-polyfill', 'wp-i18n' ];
		}

		// Register the script
		wp_register_script(
			'torobpay-blocks',
			plugins_url( 'assets/js/blocks/torobpay-blocks.js', TOROBPAY_PLUGIN_FILE ),
			$dependencies,
			$version,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				'torobpay-blocks',
				'torobpay-for-woocommerce',
				dirname( TOROBPAY_PLUGIN_FILE ) . '/languages'
			);
		}

		return [ 'torobpay-blocks' ];
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array Payment method data
	 */
	public function get_payment_method_data() {
		if ( is_null( $this->eligibility_response ) ) {
			// Trigger is_active() to populate the cache; we don't care about the return value here.
			$this->is_active();
		}

		// Default title/description - description will be overridden by API response
		$title       = $this->get_setting( 'title', __( 'Pay in installments with TorobPay', 'torobpay-for-woocommerce' ) );
		$description = __( 'Installments based on TorobPay credit', 'torobpay-for-woocommerce' ); // Default fallback

		// If eligibility call succeeded, prefer API-provided messages (keeps behaviour in sync with classic checkout).
		if ( $this->eligibility_response && isset( $this->eligibility_response['title_message'] ) ) {
			$api_resp = $this->eligibility_response;
			if ( ! empty( $api_resp['title_message'] ) ) {
				$title = $api_resp['title_message'];
			}
			if ( ! empty( $api_resp['description'] ) ) {
				$description = $api_resp['description'];
			}
		}

		return [
			'title'          => $title,
			'description'    => $description,
			'supports'       => [
				'showSavedCards' => false,
				'showSaveOption' => false,
				'features'       => $this->get_supported_features(),
			],
			'icon'           => apply_filters( 'torobpay_logo', plugins_url( '/assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) ),
			'mobile_enabled' => $this->get_setting( 'mobile_enabled', 'yes' ),
			'postal_enabled' => $this->get_setting( 'postal_enabled', 'no' ),
			'gatewayId'      => 'WC_Gateway_TorobPay',
			// Enable React-based settings UI compatibility
			'settingsUrl'    => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=WC_Gateway_TorobPay' ),
		];
	}

	/**
	 * Returns an array of supported features.
	 *
	 * @return array Supported features
	 */
	public function get_supported_features() {
		return [ 'products' ];
	}

	/**
	 * Returns an array of script handles to be enqueued for the admin.
	 *
	 * @return array Script handles for admin
	 */
	public function get_payment_method_script_handles_for_admin() {
		return $this->get_payment_method_script_handles();
	}
}
