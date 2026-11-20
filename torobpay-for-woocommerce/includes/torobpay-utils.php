<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write a debug message when WP_DEBUG is enabled.
 *
 * @param string $message Log message.
 * @return void
 */
function torobpay_debug_log( $message ) {
	if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
		return;
	}

	if ( ! is_string( $message ) ) {
		$message = wp_json_encode( $message );
	}

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated behind WP_DEBUG via torobpay_debug_log().
	error_log( $message );
}

function torobpay_mobile( $mobile ) {
	if ( null === $mobile || false === $mobile || '' === $mobile ) {
		return false;
	}

	$mobile = torobpay_num_to_en( (string) $mobile );
	if ( '' === $mobile ) {
		return false;
	}

	if ( preg_match( '/^\+980\d{10}$/', $mobile ) ) {
		return '+98' . substr( $mobile, 4 );
	}

	if ( preg_match( '/^980\d{10}$/', $mobile ) ) {
		return '+98' . substr( $mobile, 3 );
	}

	// Exactly one Iranian mobile: 98 plus 10 digits. A bare "+98" is not a number.
	if ( preg_match( '/^\+98\d{10}$/', $mobile ) ) {
		return $mobile;
	}

	if ( preg_match( '/^98\d{10}$/', $mobile ) ) {
		return '+' . $mobile;
	}

	if ( preg_match( "@^09@", $mobile ) && strlen( $mobile ) == 11 ) {
		return "+98" . substr( $mobile, 1 );
	}

	if ( preg_match( "@^9@", $mobile ) && strlen( $mobile ) == 10 ) {
		return "+98" . $mobile;
	}

	return false;
}

/**
 * Digits account mobile for a user, normalized to +98.
 *
 * Guests return false. A Digits value that does not normalize falls through
 * to Digits user meta, matching the payment-token lookup.
 *
 * @param int $user_id WordPress user id.
 * @return string|false
 */
function torobpay_digits_mobile( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return false;
	}

	$mobile = false;

	if ( function_exists( 'digits_get_mobile' ) ) {
		$digits_mobile = digits_get_mobile( $user_id );
		if ( $digits_mobile ) {
			$mobile = torobpay_mobile( $digits_mobile );
		}
	}

	if ( ! $mobile ) {
		$digits_phone = function_exists( 'get_user_meta' ) ? get_user_meta( $user_id, 'digits_phone', true ) : '';
		if ( $digits_phone ) {
			$mobile = torobpay_mobile( $digits_phone );
		}
		if ( ! $mobile && function_exists( 'get_user_meta' ) ) {
			$digt_countrycode = get_user_meta( $user_id, 'digt_countrycode', true );
			$digt_number      = get_user_meta( $user_id, 'digt_number', true );
			if ( $digt_countrycode && $digt_number ) {
				$mobile = torobpay_mobile( $digt_countrycode . $digt_number );
			}
		}
	}

	return $mobile ? $mobile : false;
}

/**
 * Classic checkout, pay-for-order, update_order_review, or a checkout Store API request.
 *
 * @return bool
 */
function torobpay_is_checkout_request() {
	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		return true;
	}

	if ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT ) {
		return true;
	}

	if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only WooCommerce AJAX route name.
	$wc_ajax = isset( $_GET['wc-ajax'] ) ? (string) $_GET['wc-ajax'] : '';
	if ( '' !== $wc_ajax && function_exists( 'wp_unslash' ) ) {
		$wc_ajax = wp_unslash( $wc_ajax );
	}
	if ( '' !== $wc_ajax && function_exists( 'sanitize_text_field' ) ) {
		$wc_ajax = sanitize_text_field( $wc_ajax );
	}

	if ( 'update_order_review' === $wc_ajax ) {
		return true;
	}

	return torobpay_is_store_api_checkout_request();
}

/**
 * Store API request made from the checkout page, including checkout itself.
 *
 * Cart updates from the checkout block come in as /wc/store/ requests whose
 * referer is the checkout page. Product-page cart updates are left out.
 *
 * @return bool
 */
function torobpay_is_store_api_checkout_request() {
	if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
		return false;
	}

	return torobpay_store_api_request_is_checkout();
}

/**
 * Whether the current URI is a checkout Store API call.
 *
 * Does not check REST_REQUEST, so tests can cover the route and referer rules.
 *
 * @return bool
 */
function torobpay_store_api_request_is_checkout() {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only a route-prefix check.
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	if ( function_exists( 'wp_unslash' ) ) {
		$uri = wp_unslash( $uri );
	}
	if ( false === strpos( $uri, '/wc/store/' ) ) {
		return false;
	}
	if ( false !== strpos( $uri, '/wc/store/v1/checkout' ) || false !== strpos( $uri, 'update-customer' ) ) {
		return true;
	}

	if ( false !== strpos( $uri, '/batch' ) && torobpay_store_api_batch_updates_customer() ) {
		return true;
	}

	if ( ! function_exists( 'wp_get_referer' ) || ! function_exists( 'wc_get_checkout_url' ) ) {
		return false;
	}

	$referer  = wp_get_referer();
	$checkout = wc_get_checkout_url();
	if ( ! is_string( $referer ) || '' === $referer || ! is_string( $checkout ) || '' === $checkout ) {
		return false;
	}

	$referer_path  = function_exists( 'wp_parse_url' ) ? wp_parse_url( $referer, PHP_URL_PATH ) : parse_url( $referer, PHP_URL_PATH );
	$checkout_path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $checkout, PHP_URL_PATH ) : parse_url( $checkout, PHP_URL_PATH );
	if ( ! is_string( $referer_path ) || ! is_string( $checkout_path ) ) {
		return false;
	}

	$referer_path  = rawurldecode( $referer_path );
	$checkout_path = rawurldecode( $checkout_path );
	if ( function_exists( 'untrailingslashit' ) ) {
		$referer_path  = untrailingslashit( $referer_path );
		$checkout_path = untrailingslashit( $checkout_path );
	}

	if ( $referer_path === $checkout_path ) {
		return true;
	}

	$prefix = $checkout_path . '/';
	return 0 === strpos( $referer_path, $prefix );
}

/**
 * Checkout block saves the address inside a Store API batch request.
 *
 * @return bool
 */
function torobpay_store_api_batch_updates_customer() {
	if ( ! function_exists( 'rest_get_server' ) ) {
		return false;
	}

	$server = rest_get_server();
	if ( ! is_object( $server ) || ! method_exists( $server, 'get_raw_data' ) ) {
		return false;
	}

	$raw = $server->get_raw_data();

	return is_string( $raw ) && false !== strpos( $raw, 'update-customer' );
}

/**
 * Normalize a checkout phone, ignoring spaces and separators.
 *
 * @param mixed $raw Phone as typed or stored.
 * @return string|false Normalized +98 number, or false.
 */
function torobpay_normalize_checkout_phone( $raw ) {
	if ( null === $raw || false === $raw || '' === $raw ) {
		return false;
	}

	$raw = torobpay_num_to_en( (string) $raw );
	$raw = preg_replace( '/[^\d+]/', '', $raw );
	if ( ! is_string( $raw ) || '' === $raw ) {
		return false;
	}

	return torobpay_mobile( $raw );
}

/**
 * Phone fields for GetPaymentToken.
 *
 * `mobile` is WooCommerce checkout billing phone.
 * `registration_phone_number` is the shop-account phone from Digits (logged-in only).
 * Guests send billing as `mobile` and an empty registration field — do not copy billing into registration.
 *
 * @param object $order WooCommerce order (must expose get_billing_phone() and get_customer_id()).
 * @return array{mobile: ?string, registration_phone_number: string}
 */
function torobpay_token_phone_fields( $order ) {
	$mobile             = torobpay_mobile( $order->get_billing_phone() );
	$registration_phone = torobpay_digits_mobile( (int) $order->get_customer_id() );

	return array(
		'mobile'                    => $mobile ? $mobile : null,
		'registration_phone_number' => $registration_phone ? $registration_phone : '',
	);
}

/**
 * Order being paid on the order-pay page.
 *
 * @return object|null
 */
function torobpay_order_pay_order() {
	if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
		return null;
	}
	if ( ! function_exists( 'wc_get_order' ) ) {
		return null;
	}

	$order_id = 0;
	if ( function_exists( 'get_query_var' ) ) {
		$order_id = absint( get_query_var( 'order-pay' ) );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only order key on the pay-for-order page.
	if ( ! $order_id && isset( $_GET['key'] ) && function_exists( 'wc_get_order_id_by_order_key' ) ) {
		$order_key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		$order_id  = absint( wc_get_order_id_by_order_key( $order_key ) );
	}
	if ( ! $order_id ) {
		return null;
	}

	$order = wc_get_order( $order_id );

	return is_object( $order ) ? $order : null;
}

/**
 * Amount and currency for an eligibility check.
 *
 * Order-pay uses the order being paid, including when the cart still has items.
 * Other checkout requests use the cart total. Currency is null off order-pay so
 * the caller uses the store currency.
 *
 * @return array{amount:float,currency:?string}
 */
function torobpay_checkout_payable() {
	$order = torobpay_order_pay_order();
	if ( is_object( $order ) && method_exists( $order, 'get_total' ) ) {
		$currency = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : '';

		return array(
			'amount'   => (float) $order->get_total(),
			'currency' => '' !== $currency ? $currency : null,
		);
	}

	$amount = 0.0;
	if ( function_exists( 'WC' ) ) {
		$wc = WC();
		if ( is_object( $wc ) && isset( $wc->cart ) && is_object( $wc->cart ) && method_exists( $wc->cart, 'get_total' ) ) {
			$amount = (float) $wc->cart->get_total( 'edit' );
		}
	}

	return array(
		'amount'   => $amount,
		'currency' => null,
	);
}

/**
 * Mobile for the eligibility phone_number query parameter.
 *
 * Empty when nothing normalizes. A phone on the checkout form wins, billing
 * before shipping. That covers classic post data and the block checkout
 * customer. Digits is used only when the form has no mobile.
 *
 * @return string Normalized +98 number, or ''.
 */
function torobpay_eligibility_phone_number() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Read-only checkout payload, same posture as fee stripping.
	if ( ! empty( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
		$posted = array();
		parse_str( wp_unslash( $_POST['post_data'] ), $posted ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- billing_phone is sanitized below.
		foreach ( array( 'billing_phone', 'shipping_phone' ) as $field ) {
			if ( empty( $posted[ $field ] ) || ! is_string( $posted[ $field ] ) ) {
				continue;
			}
			$typed = torobpay_normalize_checkout_phone( sanitize_text_field( $posted[ $field ] ) );
			if ( $typed ) {
				return $typed;
			}
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$order = torobpay_order_pay_order();
	if ( is_object( $order ) ) {
		foreach ( array( 'get_billing_phone', 'get_shipping_phone' ) as $method ) {
			if ( ! method_exists( $order, $method ) ) {
				continue;
			}
			$order_phone = torobpay_normalize_checkout_phone( $order->$method() );
			if ( $order_phone ) {
				return $order_phone;
			}
		}
	}

	$checkout_phone = torobpay_customer_checkout_phone();
	if ( '' !== $checkout_phone ) {
		return $checkout_phone;
	}

	$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$digits  = torobpay_digits_mobile( $user_id );

	return $digits ? $digits : '';
}

/**
 * Billing phone, then shipping phone, from the WooCommerce customer.
 *
 * Billing wins when both are set. The block copies the shipping phone onto
 * billing while "use same address for billing" is on. A separate billing
 * phone is the payer number.
 *
 * @return string
 */
function torobpay_customer_checkout_phone() {
	if ( ! function_exists( 'WC' ) ) {
		return '';
	}

	$wc       = WC();
	$customer = ( is_object( $wc ) && isset( $wc->customer ) ) ? $wc->customer : null;
	if ( ! is_object( $customer ) ) {
		return '';
	}

	foreach ( array( 'get_billing_phone', 'get_shipping_phone' ) as $method ) {
		if ( ! method_exists( $customer, $method ) ) {
			continue;
		}
		$phone = torobpay_normalize_checkout_phone( $customer->$method() );
		if ( $phone ) {
			return $phone;
		}
	}

	return '';
}

function torobpay_num_to_en( $string ) {
	if ( null === $string || false === $string ) {
		return '';
	}

	$string  = (string) $string;
	$persian = [ "۰", "۱", "۲", "۳", "۴", "۵", "۶", "۷", "۸", "۹" ];
	// U+0660–U+0669. Editors draw these digits right to left, so the glyphs look reversed.
	$arabic  = [ "\u{0660}", "\u{0661}", "\u{0662}", "\u{0663}", "\u{0664}", "\u{0665}", "\u{0666}", "\u{0667}", "\u{0668}", "\u{0669}" ];

	$num                  = range( 0, 9 );
	$convertedPersianNums = str_replace( $persian, $num, $string );
	return str_replace( $arabic, $num, $convertedPersianNums );
}

/**
 * Parse a merchant-typed amount.
 *
 * Accepts Persian and Arabic-Indic digits, grouped thousands, and a decimal
 * point. A trailing separator, as in "250.", keeps the digits already typed.
 * Empty, negative, and invalid input is 0.
 *
 * @param mixed $raw Amount.
 * @return float
 */
function torobpay_parse_amount( $raw ) {
	if ( is_int( $raw ) || is_float( $raw ) ) {
		$amount = (float) $raw;
		return $amount > 0 ? $amount : 0.0;
	}

	if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
		return 0.0;
	}

	$normalized = torobpay_num_to_en( (string) $raw );
	$normalized = str_replace(
		array( ',', '٬', '،', ' ', "'", "\xC2\xA0", "\xE2\x80\xAF" ),
		'',
		$normalized
	);
	$normalized = str_replace( '٫', '.', $normalized );
	if ( substr( $normalized, -1 ) === '.' ) {
		$normalized = substr( $normalized, 0, -1 );
	}
	if ( substr_count( $normalized, '.' ) > 1 ) {
		$normalized = str_replace( '.', '', $normalized );
	}

	if ( ! preg_match( '/^\d+(\.\d+)?$/', $normalized ) ) {
		return 0.0;
	}

	return (float) $normalized;
}

function torobpay_is_json( $string ) {
	if ( ! is_string( $string ) || '' === $string ) {
		return false;
	}

	json_decode( $string );
	return json_last_error() === JSON_ERROR_NONE;
}

/**
 * Whether a payment method ID is TorobPay (current or legacy).
 *
 * @param string $method Payment method ID.
 * @return bool
 */
function torobpay_is_torobpay_payment_method( $method ) {
	return in_array( (string) $method, array( 'WC_Gateway_TorobPay', 'torobpay' ), true );
}

/**
 * Whether the receipt/order-pay page should auto-redirect to TorobPay.
 *
 * Deprecated for checkout: process_payment() always requests a gateway URL.
 * This flag still controls the leftover woocommerce_receipt_* Pay button.
 */
function torobpay_direct_payment() {
	$torobpay_settings = torobpay_get_settings();
	return is_array( $torobpay_settings ) && isset( $torobpay_settings['direct_payment'] ) && $torobpay_settings['direct_payment'] === 'yes';
}

function torobpay_woo_active_notice() {
	$install_url = admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' );
	echo '<div class="notice notice-error is-dismissible">';
	echo '<p>';
	echo '<strong>' . esc_html__( 'TorobPay:', 'torobpay-for-woocommerce' ) . '</strong> ';
	echo esc_html__( 'This plugin requires WooCommerce and was deactivated until it is installed. ', 'torobpay-for-woocommerce' );
	printf(
		/* translators: %s: WooCommerce plugin install URL */
		wp_kses_post( __( 'Please <a href="%s">Install and activate WooCommerce</a>.', 'torobpay-for-woocommerce' ) ),
		esc_url( $install_url )
	);
	echo '</p>';
	echo '</div>';
}

/** Restore stock when status is manually downgraded without a WC refund object. */
function torobpay_restore_stock( $order ) {
	// Sub-orders share the parent's line items; restocking both over-restocks.
	if ( function_exists( 'torobpay_should_bypass_order' ) && torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		return false;
	}

	$order_id    = $order->get_id();
	$fresh_order = torobpay_get_fresh_order( $order_id );
	if ( ! $fresh_order ) {
		return false;
	}
	if ( $fresh_order->get_meta( '_torobpay_stock_restored', true ) === 'yes' ) {
		return false;
	}

	if ( ! $fresh_order->get_date_paid() ) {
		return false;
	}

	$order = $fresh_order;
	$refunds = $order->get_refunds();
	if ( ! empty( $refunds ) ) {
		$order->update_meta_data( '_torobpay_stock_restored', 'yes' );
		$order->save();
		return false;
	}

	$stock_reduced       = $order->get_meta( '_stock_reduced', true );
	$order_stock_reduced = $order->get_meta( '_order_stock_reduced', true );

	// Restock manual downgrades only when paid and stock-managed items exist.
	if ( ! $stock_reduced && ! $order_stock_reduced ) {
		$has_items_needing_stock = false;
		foreach ( $order->get_items() as $item ) {
			if ( $item->is_type( 'line_item' ) ) {
				$product = $item->get_product();
				if ( $product && $product->managing_stock() ) {
					$has_items_needing_stock = true;
					break;
				}
			}
		}
		if ( ! $order->get_date_paid() || ! $has_items_needing_stock ) {
			return false;
		}
	}

	$stock_restored = false;

	foreach ( $order->get_items() as $item ) {
		if ( $item->is_type( 'line_item' ) ) {
			$product = $item->get_product();

			if ( $product && $product->managing_stock() ) {
				$qty       = $item->get_quantity();
				$old_stock = $product->get_stock_quantity();

				$new_stock = wc_update_product_stock( $product, $qty, 'increase' );

				if ( ! is_wp_error( $new_stock ) && $new_stock !== $old_stock ) {
					$stock_restored = true;

					$product_name = $product->get_name();
					$sku          = $product->get_sku();
					$sku_text     = $sku ? ' (SKU: ' . esc_html( $sku ) . ')' : '';

					$note = '<span class="torobpay-notice">'
						. esc_html__( 'TorobPay: Product stock was restored:', 'torobpay-for-woocommerce' )
						. '<br> ' . esc_html( $product_name ) . $sku_text
						. '<br> ' . esc_html__( 'Restock amount:', 'torobpay-for-woocommerce' ) . ' ' . intval( $qty )
						. '<br> ' . esc_html__( 'Stock before:', 'torobpay-for-woocommerce' ) . ' ' . intval( $old_stock )
						. '<br> ' . esc_html__( 'New stock:', 'torobpay-for-woocommerce' ) . ' ' . intval( $new_stock )
						. '</span>';
					$order->add_order_note( $note );
				}
			}
		}
	}

	$order->update_meta_data( '_torobpay_stock_restored', 'yes' );
	$order->delete_meta_data( '_stock_reduced' );
	$order->delete_meta_data( '_order_stock_reduced' );
	$order->save();

	return $stock_restored;
}

function torobpay_plugin_asset_url( $relative_path ) {
	$relative_path = ltrim( (string) $relative_path, '/' );
	$plugin_file   = defined( 'TOROBPAY_PLUGIN_FILE' ) ? TOROBPAY_PLUGIN_FILE : dirname( __DIR__ ) . '/torobpay-for-woocommerce.php';
	$url           = plugins_url( $relative_path, $plugin_file );
	$file_path     = dirname( $plugin_file ) . '/' . $relative_path;

	if ( is_file( $file_path ) ) {
		return add_query_arg( 'v', (string) filemtime( $file_path ), $url );
	}

	$fallback_version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1';

	return add_query_arg( 'v', $fallback_version, $url );
}

/**
 * Whether the current admin screen belongs to TorobPay.
 *
 * Covers TorobPay menu/submenu pages and the WooCommerce TorobPay gateway
 * settings section.
 *
 * @return bool
 */
function torobpay_is_torobpay_admin_screen() {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return false;
	}

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 0 === strpos( $page, 'torobpay' ) ) {
		return true;
	}

	$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['page'], $_GET['tab'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		&& 'wc-settings' === $_GET['page'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		&& 'checkout' === $_GET['tab'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		&& 'wc_gateway_torobpay' === strtolower( $section ) ) {
		return true;
	}

	$screen = get_current_screen();
	if ( ! $screen ) {
		return false;
	}

	return false !== strpos( (string) $screen->id, 'torobpay' );
}

/**
 * Whether the current admin screen belongs to WooCommerce.
 *
 * Covers WC settings/status pages, products, and both legacy (`shop_order`)
 * and HPOS (`woocommerce_page_wc-orders`) order screens.
 *
 * @return bool
 */
function torobpay_is_woocommerce_admin_screen() {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return false;
	}

	$screen = get_current_screen();
	if ( ! $screen ) {
		return false;
	}

	if ( false !== strpos( (string) $screen->id, 'woocommerce' )
		|| false !== strpos( (string) $screen->id, 'shop_order' ) ) {
		return true;
	}

	$post_type = isset( $screen->post_type ) ? $screen->post_type : '';
	return in_array( $post_type, array( 'product', 'shop_order' ), true );
}

/**
 * Whether the current admin screen is the Plugins list (or related) screen.
 *
 * @return bool
 */
function torobpay_is_plugins_admin_screen() {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return false;
	}

	$screen = get_current_screen();
	if ( ! $screen ) {
		return false;
	}

	return in_array( $screen->id, array( 'plugins', 'plugins-network', 'plugin-install' ), true );
}

/**
 * Whether TorobPay may render a scoped admin notice on the current screen.
 *
 * Guideline 11: keep operational notices on TorobPay + WooCommerce screens.
 * Pass `$include_plugins` for notices that also belong on the Plugins list
 * (e.g. blocked gateway-fee / default-gateway conflict).
 *
 * @param bool $include_plugins Also allow Plugins admin screens.
 * @return bool
 */
function torobpay_should_render_scoped_admin_notice( $include_plugins = false ) {
	if ( torobpay_is_torobpay_admin_screen() || torobpay_is_woocommerce_admin_screen() ) {
		return true;
	}

	return $include_plugins && torobpay_is_plugins_admin_screen();
}

/**
 * Whether the current request may view TorobPay payment notices for an order.
 *
 * Accepts WooCommerce order key (`key` query arg), matching checkout session key,
 * or the logged-in customer who owns the order. Prevents leaking notices via
 * guessable `wc_order` IDs alone.
 *
 * @param WC_Order $order Order object.
 * @return bool
 */
function torobpay_can_view_order_payment_notice( $order ) {
	if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
		return false;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only order key from payment return URL.
	$provided_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$expected_key = (string) $order->get_order_key();
	if ( '' !== $provided_key && '' !== $expected_key && hash_equals( $expected_key, $provided_key ) ) {
		return true;
	}

	if ( function_exists( 'WC' ) && WC()->session ) {
		$session_key = (string) WC()->session->get( 'order_awaiting_payment_key' );
		if ( '' !== $session_key && '' !== $expected_key && hash_equals( $expected_key, $session_key ) ) {
			return true;
		}
	}

	$user_id = (int) $order->get_user_id();
	if ( $user_id > 0 && is_user_logged_in() && get_current_user_id() === $user_id ) {
		return true;
	}

	return false;
}

/**
 * Whether HPOS custom orders table is in use.
 *
 * @return bool
 */
function torobpay_orders_use_hpos() {
	static $enabled = null;
	if ( null !== $enabled ) {
		return $enabled;
	}

	$enabled = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

	return $enabled;
}

/**
 * Attach a single meta clause to wc_get_orders() args (HPOS meta_query; CPT meta_key).
 *
 * @param array $args   Existing wc_get_orders args.
 * @param array $clause Keys: key (required), value (optional), compare (optional).
 * @return array
 */
function torobpay_orders_apply_meta_clause( array $args, array $clause ) {
	if ( empty( $clause['key'] ) ) {
		return $args;
	}

	if ( torobpay_orders_use_hpos() ) {
		if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- HPOS order meta filter; CPT path uses meta_key below.
			$args['meta_query'] = array();
		}
		$args['meta_query'][] = $clause;
		return $args;
	}

	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- CPT-safe single meta clause for wc_get_orders().
	$args['meta_key'] = $clause['key'];
	if ( array_key_exists( 'value', $clause ) ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- CPT-safe single meta clause for wc_get_orders().
		$args['meta_value'] = $clause['value'];
	}
	if ( ! empty( $clause['compare'] ) ) {
		$args['meta_compare'] = $clause['compare'];
	}

	return $args;
}

/**
 * Normalize a Gregorian calendar date to padded Y-m-d.
 *
 * @param string $value Raw date string (Y-m-d or Y/m/d).
 * @return string|null
 */
function torobpay_normalize_ymd_date( $value ) {
	if ( ! is_string( $value ) ) {
		return null;
	}

	$value = str_replace( '/', '-', trim( $value ) );
	if ( ! preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $matches ) ) {
		return null;
	}

	$year  = (int) $matches[1];
	$month = (int) $matches[2];
	$day   = (int) $matches[3];
	if ( ! checkdate( $month, $day, $year ) ) {
		return null;
	}

	return sprintf( '%04d-%02d-%02d', $year, $month, $day );
}

/**
 * Parse a Gregorian date_range value ("Y-m-d" or "Y-m-d - Y-m-d").
 *
 * @param string $value Raw date_range request value.
 * @return array{0: string, 1: string}|null Inclusive from/to in Y-m-d, or null if invalid.
 */
function torobpay_parse_order_date_range( $value ) {
	$value = is_string( $value ) ? trim( $value ) : '';
	if ( '' === $value ) {
		return null;
	}

	$parts = array_values(
		array_filter(
			array_map( 'trim', explode( ' - ', $value ) ),
			'strlen'
		)
	);
	if ( count( $parts ) < 1 || count( $parts ) > 2 ) {
		return null;
	}

	$dates = array();
	foreach ( $parts as $part ) {
		$normalized = torobpay_normalize_ymd_date( $part );
		if ( ! $normalized ) {
			return null;
		}
		$dates[] = $normalized;
	}

	if ( 1 === count( $dates ) ) {
		return array( $dates[0], $dates[0] );
	}

	if ( $dates[0] > $dates[1] ) {
		return array( $dates[1], $dates[0] );
	}

	return array( $dates[0], $dates[1] );
}

/**
 * Apply an inclusive created-date range to wc_get_orders() args.
 *
 * @param array       $args       WooCommerce order query args.
 * @param string|null $date_range Gregorian range string.
 * @return array
 */
function torobpay_apply_order_date_range_query( array $args, $date_range ) {
	$parsed = torobpay_parse_order_date_range( $date_range );
	if ( ! $parsed ) {
		return $args;
	}

	$args['date_query'] = array(
		array(
			'after'     => $parsed[0] . ' 00:00:00',
			'before'    => $parsed[1] . ' 23:59:59',
			'inclusive' => true,
		),
	);
	unset( $args['date_created'] );

	return $args;
}

/**
 * Whitelist an orders-table sales-channel filter value.
 *
 * @param mixed $channel Raw request value.
 * @return string Empty, "instore", or "online".
 */
function torobpay_sanitize_order_channel( $channel ) {
	if ( ! is_string( $channel ) ) {
		return '';
	}

	$channel = strtolower( trim( $channel ) );
	if ( ! in_array( $channel, array( 'instore', 'online' ), true ) ) {
		return '';
	}

	return $channel;
}

/**
 * Restrict a wc_get_orders() query to in-store or online orders.
 *
 * In-store orders are created with created_via "torobpay_instore". That
 * argument is equality-only on both HPOS and the legacy posts datastore.
 * Online orders are everything else: field_query on HPOS, and a custom query
 * var on the posts datastore (see torobpay_filter_cpt_order_channel_query()).
 *
 * @param array     $args     WooCommerce order query args.
 * @param mixed     $channel  instore, online, or anything else (no filter).
 * @param bool|null $use_hpos Storage override. Null reads the current datastore.
 * @return array
 */
function torobpay_apply_order_channel_query( array $args, $channel, $use_hpos = null ) {
	$channel = torobpay_sanitize_order_channel( $channel );
	if ( 'instore' === $channel ) {
		$args['created_via'] = 'torobpay_instore';
		return $args;
	}

	if ( 'online' !== $channel ) {
		return $args;
	}

	if ( null === $use_hpos ) {
		$use_hpos = torobpay_orders_use_hpos();
	}

	if ( $use_hpos ) {
		if ( ! isset( $args['field_query'] ) || ! is_array( $args['field_query'] ) ) {
			$args['field_query'] = array();
		}
		$args['field_query'][] = array(
			'field'   => 'created_via',
			'value'   => 'torobpay_instore',
			'compare' => '!=',
		);
		return $args;
	}

	$args['torobpay_exclude_created_via'] = 'torobpay_instore';
	return $args;
}

/**
 * Map the legacy-posts channel exclusion onto _created_via meta.
 *
 * @param array $wp_query_args Query args for WP_Query.
 * @param array $query_vars    Original wc_get_orders() args.
 * @return array
 */
function torobpay_filter_cpt_order_channel_query( $wp_query_args, $query_vars ) {
	if ( ! is_array( $wp_query_args ) ) {
		$wp_query_args = array();
	}

	unset( $wp_query_args['torobpay_exclude_created_via'] );

	if ( ! is_array( $query_vars ) || empty( $query_vars['torobpay_exclude_created_via'] ) || 'torobpay_instore' !== $query_vars['torobpay_exclude_created_via'] ) {
		return $wp_query_args;
	}

	if ( ! isset( $wp_query_args['meta_query'] ) || ! is_array( $wp_query_args['meta_query'] ) ) {
		$wp_query_args['meta_query'] = array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- CPT created_via is post meta; HPOS uses field_query.
	}

	$wp_query_args['meta_query'][] = array(
		'key'     => '_created_via',
		'value'   => 'torobpay_instore',
		'compare' => '!=',
	);

	return $wp_query_args;
}
