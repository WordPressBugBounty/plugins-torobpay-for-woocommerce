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
	 * Normalized eligibility result for this request, including description_type.
	 *
	 * @var array<string,mixed>|null
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
		 * Cart total during checkout. On order-pay, the order being paid,
		 * even when the cart still has items.
		 */
		if ( ! function_exists( 'torobpay_checkout_payable' ) ) {
			require_once dirname( TOROBPAY_PLUGIN_FILE ) . '/includes/torobpay-utils.php';
		}
		$payable  = torobpay_checkout_payable();
		$amount   = $payable['amount'];
		$currency = null !== $payable['currency'] ? $payable['currency'] : get_woocommerce_currency();

		// Shared helper ensures single responsibility and DRY.
		if ( ! function_exists( 'torobpay_resolve_eligibility' ) ) {
			require_once dirname( TOROBPAY_PLUGIN_FILE ) . '/includes/torobpay-eligibility.php';
		}

		$eligibility                = torobpay_resolve_eligibility( $amount, $currency );
		$this->eligibility_response = $eligibility;

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
			$dependencies = [ 'wc-blocks-registry', 'wc-settings', 'wc-blocks-data-store', 'wp-element', 'wp-data', 'wp-components', 'wp-polyfill', 'wp-i18n' ];
		}

		// An unregistered handle makes WordPress skip this file entirely, and the
		// checkout block then waits forever for TorobPay to register.
		$dependencies = array_values(
			array_filter(
				$dependencies,
				static function ( $handle ) {
					return function_exists( 'wp_script_is' ) && wp_script_is( $handle, 'registered' );
				}
			)
		);

		if ( function_exists( 'torobpay_enqueue_checkout_offer_style' ) ) {
			torobpay_enqueue_checkout_offer_style();
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
		$title            = $this->get_setting( 'title', __( 'Pay in installments with TorobPay', 'torobpay-for-woocommerce' ) );
		$description      = __( 'Installments based on TorobPay credit', 'torobpay-for-woocommerce' ); // Default fallback
		$description_type = 'installment';

		// If eligibility call succeeded, prefer API-provided messages (keeps behaviour in sync with classic checkout).
		if ( is_array( $this->eligibility_response ) && ! empty( $this->eligibility_response['raw_response'] ) ) {
			$api_resp = $this->eligibility_response;
			if ( ! empty( $api_resp['title'] ) ) {
				$title = $api_resp['title'];
			}
			if ( ! empty( $api_resp['description'] ) ) {
				$description = $api_resp['description'];
			}
			if ( ! empty( $api_resp['description_type'] ) ) {
				$description_type = function_exists( 'torobpay_normalize_description_type' )
					? torobpay_normalize_description_type( $api_resp['description_type'] )
					: (string) $api_resp['description_type'];
			}
		}

		return [
			'title'           => $title,
			'description'     => $description,
			'descriptionType' => $description_type,
			'supports'        => [
				'showSavedCards' => false,
				'showSaveOption' => false,
				'features'       => $this->get_supported_features(),
			],
			'icon'            => apply_filters( 'torobpay_logo', plugins_url( '/assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) ),
			'postal_enabled'  => $this->get_setting( 'postal_enabled', 'no' ),
			'gatewayId'       => 'WC_Gateway_TorobPay',
			// Enable React-based settings UI compatibility
			'settingsUrl'     => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=WC_Gateway_TorobPay' ),
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
