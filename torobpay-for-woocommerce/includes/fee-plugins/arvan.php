<?php
/**
 * Arvan Pay (arvan-extra-fees). Surcharge on TorobPay only.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TOROBPAY_ARVAN_PLUGIN_TEXT_DOMAIN', 'arvan-extra-fees' );

define( 'TOROBPAY_ARVAN_FEES_OPTION', 'arvan_wc_gateway_fees' );

/**
 * Whether Arvan Pay is loaded (runtime signals only).
 *
 * The fee callback is defined when the settings file has booted, which is
 * when cart fees and coupon blocks can run.
 *
 * @return bool
 */
function torobpay_arvan_plugin_active() {
	if ( function_exists( 'arvan_add_payment_gateway_fee_to_cart' ) ) {
		return true;
	}

	if ( function_exists( 'arvan_render_payment_gateway_fees_page' ) ) {
		return true;
	}

	return function_exists( 'arvan_output_gateway_badges_data' );
}

/**
 * Display name for Arvan Pay.
 *
 * @return string
 */
function torobpay_arvan_plugin_name() {
	return __( 'Arvan Pay', 'torobpay-for-woocommerce' );
}

/**
 * Whether an Arvan rule is turned on.
 *
 * A missing flag is treated as active. Arvan stores an explicit false when
 * the merchant disables the card.
 *
 * @param array $rule Gateway rule.
 * @return bool
 */
function torobpay_arvan_rule_is_active( $rule ) {
	if ( ! is_array( $rule ) || ! array_key_exists( 'is_active', $rule ) ) {
		return true;
	}

	$active = $rule['is_active'];

	return ! in_array( $active, array( false, 0, '0', 'no', '' ), true );
}

/**
 * Stored Arvan gateway rules, keyed by "{gateway_id}:{role}".
 *
 * @return array
 */
function torobpay_arvan_stored_rules() {
	if ( ! function_exists( 'get_option' ) ) {
		return array();
	}

	$rules = get_option( TOROBPAY_ARVAN_FEES_OPTION, array() );

	return is_array( $rules ) ? $rules : array();
}

/**
 * Rate strings Arvan may write into a fee title for one amount.
 *
 * Percentage titles on checkout use "10 %". The gateway badge uses "10%".
 * Both are generated so either spelling matches.
 *
 * @param float|int|string $value    Rate or fixed amount.
 * @param string           $fee_type Arvan fee_type. "percentage" or "fixed".
 * @return string[]
 */
function torobpay_arvan_rate_texts( $value, $fee_type ) {
	$number = (float) $value;
	if ( $number <= 0 ) {
		return array();
	}

	$formatted = rtrim( rtrim( number_format( $number, 2, '.', '' ), '0' ), '.' );
	if ( '' === $formatted ) {
		return array();
	}

	if ( 'percentage' === (string) $fee_type ) {
		return array( $formatted . ' %', $formatted . '%' );
	}

	return array( $formatted );
}

/**
 * Replace Arvan's Persian title tokens.
 *
 * @param string $template      Fee title template.
 * @param string $gateway_title Payment method title.
 * @param string $rate_text     Rendered rate.
 * @return string
 */
function torobpay_arvan_apply_title_tokens( $template, $gateway_title, $rate_text ) {
	return strtr(
		(string) $template,
		array(
			'{نام_درگاه}' => (string) $gateway_title,
			'{نوع}'       => 'کارمزد',
			'{نرخ}'       => (string) $rate_text,
			'{درصد}'      => (string) $rate_text,
			'{مبلغ}'      => (string) $rate_text,
		)
	);
}

/**
 * Cart fee names an active TorobPay surcharge rule can produce.
 *
 * Discount rules are ignored. Both the base rate and the over-cap rate are
 * included, because Arvan swaps {نرخ} when the cart crosses fee_max_amount.
 *
 * @param array  $rule          One Arvan rule.
 * @param string $gateway_title Payment method title shown to the customer.
 * @return string[]
 */
function torobpay_arvan_fee_names_for_rule( $rule, $gateway_title ) {
	if ( ! is_array( $rule ) || ! torobpay_arvan_rule_is_active( $rule ) ) {
		return array();
	}

	if ( 'fee' !== ( isset( $rule['adjustment_type'] ) ? (string) $rule['adjustment_type'] : '' ) ) {
		return array();
	}

	$gateway_id = isset( $rule['gateway_id'] ) ? (string) $rule['gateway_id'] : '';
	if ( ! torobpay_gateway_fee_is_torobpay_gateway_id( $gateway_id ) ) {
		return array();
	}

	$template = isset( $rule['fee_title'] ) ? trim( (string) $rule['fee_title'] ) : '';
	if ( '' === $template ) {
		return array();
	}

	$fee_type = isset( $rule['fee_type'] ) ? (string) $rule['fee_type'] : 'percentage';
	$rates    = torobpay_arvan_rate_texts( isset( $rule['fee_value'] ) ? $rule['fee_value'] : 0, $fee_type );

	if ( isset( $rule['fee_value_over'] ) && '' !== $rule['fee_value_over'] && null !== $rule['fee_value_over'] ) {
		$rates = array_merge( $rates, torobpay_arvan_rate_texts( $rule['fee_value_over'], $fee_type ) );
	}

	if ( empty( $rates ) ) {
		$rates = array( '' );
	}

	$names = array();
	foreach ( array_unique( $rates ) as $rate_text ) {
		$name = trim( torobpay_arvan_apply_title_tokens( $template, $gateway_title, $rate_text ) );
		if ( '' !== $name ) {
			$names[] = $name;
		}
	}

	return array_values( array_unique( $names ) );
}

/**
 * Customer-facing title for one gateway, when WooCommerce is booted.
 *
 * @param string $gateway_id Payment method ID.
 * @return string
 */
function torobpay_arvan_gateway_title( $gateway_id ) {
	if ( ! function_exists( 'WC' ) || ! WC() || ! is_callable( array( WC(), 'payment_gateways' ) ) ) {
		return '';
	}

	$controller = WC()->payment_gateways();
	if ( ! is_object( $controller ) || ! is_callable( array( $controller, 'payment_gateways' ) ) ) {
		return '';
	}

	$gateways = $controller->payment_gateways();
	if ( ! is_array( $gateways ) ) {
		return '';
	}

	foreach ( $gateways as $id => $gateway ) {
		if ( 0 !== strcasecmp( (string) $id, (string) $gateway_id ) || ! is_object( $gateway ) ) {
			continue;
		}

		if ( ! is_callable( array( $gateway, 'get_title' ) ) ) {
			return '';
		}

		$title = (string) $gateway->get_title();
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$title = wp_strip_all_tags( $title );
		}

		return trim( $title );
	}

	return '';
}

/**
 * Positive Arvan fee names currently configured for TorobPay.
 *
 * @return string[]
 */
function torobpay_arvan_positive_fee_names() {
	if ( ! torobpay_arvan_plugin_active() ) {
		return array();
	}

	$names = array();
	foreach ( torobpay_arvan_stored_rules() as $rule ) {
		if ( ! is_array( $rule ) ) {
			continue;
		}

		$gateway_id = isset( $rule['gateway_id'] ) ? (string) $rule['gateway_id'] : '';
		$names      = array_merge(
			$names,
			torobpay_arvan_fee_names_for_rule( $rule, torobpay_arvan_gateway_title( $gateway_id ) )
		);
	}

	return array_values( array_unique( $names ) );
}

/**
 * Whether a cart fee is an Arvan surcharge on TorobPay.
 *
 * Positive lines only. Arvan's own discount (negative) lines stay.
 *
 * @param object $fee Cart fee object from WC_Cart::get_fees().
 * @return bool
 */
function torobpay_arvan_is_unauthorized_surcharge_fee( $fee ) {
	if ( ! is_object( $fee ) || ! isset( $fee->name ) ) {
		return false;
	}

	$amount = isset( $fee->amount ) ? (float) $fee->amount : 0.0;
	if ( $amount <= 0 ) {
		return false;
	}

	return in_array( (string) $fee->name, torobpay_arvan_positive_fee_names(), true );
}

/**
 * Remove Arvan's red surcharge badge from the TorobPay payment method.
 *
 * Arvan prints the badge from a footer script at priority 25, then again on
 * updated_checkout. Discount badges are left in place.
 *
 * @return void
 */
function torobpay_arvan_hide_fee_badges() {
	if ( ! torobpay_arvan_plugin_active() ) {
		return;
	}

	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}

	$ids = wp_json_encode( torobpay_gateway_fee_torobpay_gateway_ids() );
	if ( ! is_string( $ids ) ) {
		return;
	}

	$js = 'jQuery(function($){var ids=' . $ids . ';'
		. 'function strip(){$("input[name=\\"payment_method\\"]").each(function(){'
		. 'var id=String(this.value||"");'
		. 'var matched=ids.some(function(known){return id.toLowerCase()===String(known).toLowerCase();});'
		. 'if(!matched){return;}'
		. '$(this).closest(".wc_payment_method").find(".arvan-badge-front.fee").remove();'
		. '});}'
		. 'strip();$(document.body).on("updated_checkout",strip);});';

	echo '<script>' . $js . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded gateway ids and a fixed script.
}
add_action( 'wp_footer', 'torobpay_arvan_hide_fee_badges', 30 );

/**
 * Warning copy when Arvan Pay is active.
 *
 * @return string HTML-safe body (no outer wrapper).
 */
function torobpay_arvan_warning_notice_body() {
	$plugin_name = torobpay_arvan_plugin_name();
	$plugin_slug = TOROBPAY_ARVAN_PLUGIN_TEXT_DOMAIN;

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

torobpay_register_blocked_fee_plugin(
	array(
		'id'          => 'arvan_surcharge_terms_warning',
		'dom_id'      => 'torobpay-arvan-terms-notice',
		'priority'    => 19,
		'active'      => 'torobpay_arvan_plugin_active',
		'notice_body' => 'torobpay_arvan_warning_notice_body',
		'fee_line'    => 'torobpay_arvan_is_unauthorized_surcharge_fee',
	)
);
