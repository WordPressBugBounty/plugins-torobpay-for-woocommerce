<?php
/**
 * Karmozd Pay (karmozd-pay).
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TOROBPAY_KARMOZD_PLUGIN_TEXT_DOMAIN', 'karmozd-pay' );

/**
 * Whether Karmozd Pay is loaded (runtime signals only).
 *
 * One signal is enough. Older builds and 2.2 publish different class,
 * function, and constant names.
 *
 * @return bool
 */
function torobpay_karmozd_plugin_active() {
	$classes = array(
		'Karmozd_Pay_Frontend',
		'KP_Gateway_Frontend',
		'Karmozd_Pay_Admin',
		'KP_Gateway_Admin',
	);
	foreach ( $classes as $class_name ) {
		if ( class_exists( $class_name, false ) ) {
			return true;
		}
	}

	$functions = array(
		'karmozd_pay_core_init',
		'kp_bootstrap_licensed_init',
	);
	foreach ( $functions as $function_name ) {
		if ( function_exists( $function_name ) ) {
			return true;
		}
	}

	$constants = array(
		'KARMOZD_PAY_VERSION',
		'KP_CORE_VERSION',
		'KP_VERSION',
		'KARMOZD_PAY_PLUGIN_DIR',
	);
	foreach ( $constants as $constant_name ) {
		if ( defined( $constant_name ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Display name for Karmozd Pay.
 *
 * @return string
 */
function torobpay_karmozd_plugin_name() {
	return __( 'Karmozd Pay', 'torobpay-for-woocommerce' );
}

/**
 * Fee names Karmozd Pay used before the titles became configurable.
 *
 * @return string[]
 */
function torobpay_karmozd_legacy_positive_fee_names() {
	return array(
		'کارمزد درگاه پرداخت',
		'افزایش هزینه ارسال بابت درگاه',
	);
}

/**
 * Default checkout titles in Karmozd Pay 2.2.
 *
 * The plugin appends a zero-width space unless the title already contains
 * one. Comparison strips those marks, so the plain defaults are enough.
 *
 * @return string[]
 */
function torobpay_karmozd_default_positive_fee_names() {
	return array(
		'هزینه خدمات پرداخت',
		'خدمات ارسال ویژه درگاه',
	);
}

/**
 * Drop invisible marks Karmozd Pay mixes into a checkout fee title.
 *
 * @param string $name Fee name.
 * @return string
 */
function torobpay_karmozd_normalize_fee_name( $name ) {
	$name = trim( (string) $name );
	if ( '' === $name ) {
		return '';
	}

	$invisible = '/[\x{00AD}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u';
	$stripped  = preg_replace( $invisible, '', $name );
	if ( ! is_string( $stripped ) ) {
		return $name;
	}

	return trim( $stripped );
}

/**
 * Positive cart-fee names Karmozd Pay adds for a gateway surcharge.
 *
 * Covers the old fixed titles, the 2.2 defaults, the global options, and a
 * TorobPay-specific gateway label. Negative lines (تخفیف ویژه درگاه,
 * free-shipping discount) are not listed. Names are returned without
 * zero-width marks.
 *
 * @return string[]
 */
function torobpay_karmozd_positive_fee_names() {
	$names = array_merge(
		torobpay_karmozd_legacy_positive_fee_names(),
		torobpay_karmozd_default_positive_fee_names()
	);

	if ( function_exists( 'get_option' ) ) {
		$global_fee  = get_option( 'karmozd_pay_fee_label', '' );
		$global_ship = get_option( 'karmozd_pay_shipping_fee_label', '' );

		if ( is_string( $global_fee ) && '' !== trim( $global_fee ) ) {
			$names[] = $global_fee;
		}

		if ( is_string( $global_ship ) && '' !== trim( $global_ship ) ) {
			$names[] = $global_ship;
		}

		$settings = get_option( 'karmozd_pay_settings', array() );
		if ( is_array( $settings ) ) {
			foreach ( $settings as $gateway_id => $row ) {
				if ( ! is_array( $row ) || ! torobpay_gateway_fee_is_torobpay_gateway_id( (string) $gateway_id ) ) {
					continue;
				}

				if ( ! empty( $row['fee_label'] ) && is_string( $row['fee_label'] ) ) {
					$names[] = $row['fee_label'];
				}
			}
		}
	}

	$normalized = array();
	foreach ( $names as $name ) {
		$clean = torobpay_karmozd_normalize_fee_name( $name );
		if ( '' !== $clean ) {
			$normalized[] = $clean;
		}
	}

	return array_values( array_unique( $normalized ) );
}

/**
 * Whether a cart fee is a Karmozd Pay surcharge.
 *
 * Positive lines only. Karmozd discounts stay on the cart.
 *
 * @param object $fee Cart fee object from WC_Cart::get_fees().
 * @return bool
 */
function torobpay_karmozd_is_unauthorized_surcharge_fee( $fee ) {
	if ( ! torobpay_karmozd_plugin_active() ) {
		return false;
	}

	if ( ! is_object( $fee ) || ! isset( $fee->name ) ) {
		return false;
	}

	$amount = isset( $fee->amount ) ? (float) $fee->amount : 0.0;
	if ( $amount <= 0 ) {
		return false;
	}

	return in_array(
		torobpay_karmozd_normalize_fee_name( (string) $fee->name ),
		torobpay_karmozd_positive_fee_names(),
		true
	);
}

/**
 * Whether a Karmozd-adjusted cart price is higher than the stored product price.
 *
 * @param float $current Price currently set on the cart line.
 * @param float $base    Stored product price without Karmozd's adjustment.
 * @return bool
 */
function torobpay_karmozd_price_was_raised( $current, $base ) {
	$base = (float) $base;
	if ( $base <= 0 ) {
		return false;
	}

	return (float) $current > ( $base + 0.009 );
}

/**
 * Catalog price Karmozd Pay starts from on a cart line.
 *
 * set_price() changes only the active price. Regular and sale stay on that
 * same object, including when WooCommerce's product cache returns the cart
 * line itself from wc_get_product().
 *
 * A sale price is used only when it is a real markdown. Otherwise the regular
 * price is the base.
 *
 * @param float|string $regular Regular price.
 * @param float|string $sale    Sale price.
 * @return float
 */
function torobpay_karmozd_catalog_price( $regular, $sale ) {
	$regular = (float) $regular;
	$sale    = (float) $sale;

	if ( $sale > 0 && ( $regular <= 0 || $sale < $regular ) ) {
		return $sale;
	}

	return $regular;
}

/**
 * Restore one cart line when Karmozd Pay raised its active price.
 *
 * Either override flag marks a Karmozd price change. Lines without a flag,
 * and lines priced at or below the catalog price, stay as they are.
 *
 * @param object $product Cart line product.
 * @return void
 */
function torobpay_karmozd_restore_line_price( $product ) {
	if ( ! is_object( $product ) ) {
		return;
	}

	$legacy_override  = isset( $product->karmozd_pay_overridden ) && $product->karmozd_pay_overridden;
	$runtime_override = isset( $product->kp_runtime_price_overridden ) && $product->kp_runtime_price_overridden;
	if ( ! $legacy_override && ! $runtime_override ) {
		return;
	}

	if ( ! method_exists( $product, 'get_price' ) || ! method_exists( $product, 'set_price' ) ) {
		return;
	}

	if ( ! method_exists( $product, 'get_regular_price' ) ) {
		return;
	}

	$sale    = method_exists( $product, 'get_sale_price' ) ? $product->get_sale_price( 'edit' ) : 0;
	$base    = torobpay_karmozd_catalog_price( $product->get_regular_price( 'edit' ), $sale );
	$current = (float) $product->get_price( 'edit' );

	if ( torobpay_karmozd_price_was_raised( $current, $base ) ) {
		$product->set_price( $base );
	}
}

/**
 * Undo a Karmozd Pay price increase on TorobPay cart lines.
 *
 * Classic mode, the cart popup, and cash-discount mode can bake the surcharge
 * into item prices instead of a fee row. Run after Karmozd (priority 20) and
 * put the line back to its regular or sale price when the price went up.
 * Lower prices (discounts) stay.
 *
 * @param WC_Cart $cart Cart instance.
 * @return void
 */
function torobpay_karmozd_restore_raised_cart_prices( $cart ) {
	if ( torobpay_blocked_fee_removal_is_paused() ) {
		return;
	}

	if ( ! $cart instanceof WC_Cart ) {
		return;
	}

	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}

	if ( ! torobpay_karmozd_plugin_active() ) {
		return;
	}

	if ( ! function_exists( 'WC' ) || ! WC() ) {
		return;
	}

	$chosen = torobpay_gateway_fee_chosen_payment_method();
	if ( ! torobpay_gateway_fee_is_torobpay_gateway_id( $chosen ) ) {
		return;
	}

	foreach ( $cart->get_cart() as $item ) {
		if ( empty( $item['data'] ) || ! is_object( $item['data'] ) ) {
			continue;
		}

		torobpay_karmozd_restore_line_price( $item['data'] );
	}
}
add_action( 'woocommerce_before_calculate_totals', 'torobpay_karmozd_restore_raised_cart_prices', 30 );

/**
 * Warning copy when Karmozd Pay is active.
 *
 * @return string HTML-safe body (no outer wrapper).
 */
function torobpay_karmozd_warning_notice_body() {
	$plugin_name = torobpay_karmozd_plugin_name();
	$plugin_slug = TOROBPAY_KARMOZD_PLUGIN_TEXT_DOMAIN;

	$lines   = array();
	$lines[] = sprintf(
		/* translators: 1: plugin name, 2: plugin text domain / slug */
		__( 'Plugin "%1$s" (<code>%2$s</code>) is active.', 'torobpay-for-woocommerce' ),
		esc_html( $plugin_name ),
		esc_html( $plugin_slug )
	);
	$lines[] = __( 'Under TorobPay terms, charging a fee on the TorobPay gateway is not allowed.', 'torobpay-for-woocommerce' );
	$lines[] = __( 'TorobPay automatically removes the surcharge/fee on its payment method. Discounts and fees on other gateways are not changed.', 'torobpay-for-woocommerce' );

	return '<p>' . implode( '</p><p>', $lines ) . '</p>';
}

/**
 * Clear Karmozd Pay's cached chosen gateway so the next total uses the probed id.
 *
 * @return void
 */
function torobpay_payment_price_parity_reset_karmozd_gateway_cache() {
	if ( ! class_exists( 'KP_Gateway_Frontend', false ) ) {
		return;
	}

	$class = new ReflectionClass( 'KP_Gateway_Frontend' );
	if ( ! $class->hasProperty( 'cached_chosen_gateway' ) ) {
		return;
	}

	$property = $class->getProperty( 'cached_chosen_gateway' );
	if ( PHP_VERSION_ID < 80100 ) {
		$property->setAccessible( true );
	}
	$property->setValue( null, null );
}

/**
 * Save and replace Karmozd Pay's cart-popup gateway for this measurement.
 *
 * @param string $gateway_id Gateway ID being measured.
 * @return array Saved session value.
 */
function torobpay_payment_price_parity_pin_karmozd_gateway( $gateway_id ) {
	$state = array(
		'active' => false,
		'value'  => null,
	);

	if ( ! class_exists( 'KP_Gateway_Frontend', false ) ) {
		return $state;
	}

	if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
		return $state;
	}

	$state['active'] = true;
	$state['value']  = WC()->session->get( 'karmozd_pre_chosen_gateway' );
	WC()->session->set( 'karmozd_pre_chosen_gateway', (string) $gateway_id );

	return $state;
}

/**
 * Restore Karmozd Pay's cart-popup choice after one gateway probe.
 *
 * @param array $state Saved session value.
 * @return void
 */
function torobpay_payment_price_parity_restore_karmozd_gateway( $state ) {
	if ( ! is_array( $state ) || empty( $state['active'] ) ) {
		return;
	}

	if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
		return;
	}

	WC()->session->set( 'karmozd_pre_chosen_gateway', $state['value'] );
}

/**
 * Prepare Karmozd Pay before one parity gateway total.
 *
 * @param string $gateway_id Gateway ID being measured.
 * @return array Saved popup choice.
 */
function torobpay_karmozd_begin_price_probe( $gateway_id ) {
	$state = torobpay_payment_price_parity_pin_karmozd_gateway( $gateway_id );
	torobpay_payment_price_parity_reset_karmozd_gateway_cache();

	return $state;
}

/**
 * Restore Karmozd Pay after one parity gateway total.
 *
 * @param array $state Saved popup choice.
 * @return void
 */
function torobpay_karmozd_end_price_probe( $state ) {
	torobpay_payment_price_parity_restore_karmozd_gateway( $state );
	torobpay_payment_price_parity_reset_karmozd_gateway_cache();
}

if ( function_exists( 'torobpay_register_blocked_fee_plugin' ) ) {
	torobpay_register_blocked_fee_plugin(
		array(
			'id'          => 'karmozd_surcharge_terms_warning',
			'dom_id'      => 'torobpay-karmozd-terms-notice',
			'priority'    => 17,
			'active'      => 'torobpay_karmozd_plugin_active',
			'notice_body' => 'torobpay_karmozd_warning_notice_body',
			'fee_line'    => 'torobpay_karmozd_is_unauthorized_surcharge_fee',
		)
	);
}

if ( function_exists( 'torobpay_register_price_parity_gateway_probe' ) ) {
	torobpay_register_price_parity_gateway_probe(
		'torobpay_karmozd_begin_price_probe',
		'torobpay_karmozd_end_price_probe',
		'torobpay_payment_price_parity_reset_karmozd_gateway_cache'
	);
}

