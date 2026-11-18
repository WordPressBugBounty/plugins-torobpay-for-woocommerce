<?php
/**
 * QestX (qestx). Surcharge and minimum order amount only.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TOROBPAY_QESTX_PLUGIN_TEXT_DOMAIN', 'qestx' );

/**
 * Whether QestX is loaded (runtime signals only).
 *
 * The main file defines QESTX_VERSION and qestx() before checkout rules run.
 *
 * @return bool
 */
function torobpay_qestx_plugin_active() {
	if ( class_exists( 'Qestx\\Plugin', false ) ) {
		return true;
	}

	if ( function_exists( 'qestx' ) || function_exists( 'qestx_current_adjustment' ) ) {
		return true;
	}

	if ( defined( 'QESTX_SLUG' ) && TOROBPAY_QESTX_PLUGIN_TEXT_DOMAIN === QESTX_SLUG ) {
		return true;
	}

	return defined( 'QESTX_VERSION' );
}

/**
 * Display name for QestX.
 *
 * @return string
 */
function torobpay_qestx_plugin_name() {
	return __( 'QestX', 'torobpay-for-woocommerce' );
}

/**
 * QestX amount types that raise the price (کارمزد).
 *
 * Discount types stay available on TorobPay.
 *
 * @return string[]
 */
function torobpay_qestx_surcharge_amount_types() {
	return array(
		'fee_percent',
		'fee_fixed',
		'fee_gross_up_percent',
	);
}

/**
 * Whether a QestX amount type is a surcharge.
 *
 * @param string $type QestX amount_type.
 * @return bool
 */
function torobpay_qestx_is_surcharge_type( $type ) {
	return in_array( (string) $type, torobpay_qestx_surcharge_amount_types(), true );
}

/**
 * Turn a QestX surcharge rule into a no-fee rule.
 *
 * Discount rules are returned unchanged. amount_min / amount_max on a
 * surcharge are part of that fee, so they are cleared with it.
 *
 * @param array $rule Gateway, product, or category rule.
 * @return array
 */
function torobpay_qestx_neutralize_surcharge_rule( $rule ) {
	if ( ! is_array( $rule ) ) {
		return $rule;
	}

	$type = isset( $rule['amount_type'] ) ? (string) $rule['amount_type'] : '';
	if ( ! torobpay_qestx_is_surcharge_type( $type ) ) {
		return $rule;
	}

	$rule['amount_type'] = 'none';
	$rule['amount']      = 0;
	$rule['amount_min']  = '';
	$rule['amount_max']  = '';

	return $rule;
}

/**
 * Drop TorobPay’s QestX surcharge and minimum order amount.
 *
 * order_max, discounts, coupons, and titles stay on the rule. QestX looks
 * up rules with sanitize_key(), which lowercases WC_Gateway_TorobPay.
 *
 * @param array  $rule       Gateway rule.
 * @param string $gateway_id Payment method ID.
 * @return array
 */
function torobpay_qestx_filter_gateway_rule( $rule, $gateway_id ) {
	if ( ! is_array( $rule ) || ! torobpay_gateway_fee_is_torobpay_gateway_id( $gateway_id ) ) {
		return $rule;
	}

	$rule['order_min'] = '';

	return torobpay_qestx_neutralize_surcharge_rule( $rule );
}
add_filter( 'qestx_resolved_gateway_rule', 'torobpay_qestx_filter_gateway_rule', 100, 2 );

/**
 * Drop a product or category surcharge that QestX resolved for TorobPay.
 *
 * @param array      $rule       Resolved fee rule.
 * @param int|string $product_id Product ID, unused.
 * @param string     $gateway_id Payment method ID.
 * @return array
 */
function torobpay_qestx_filter_fee_rule( $rule, $product_id, $gateway_id ) {
	unset( $product_id );

	if ( ! is_array( $rule ) || ! torobpay_gateway_fee_is_torobpay_gateway_id( $gateway_id ) ) {
		return $rule;
	}

	return torobpay_qestx_neutralize_surcharge_rule( $rule );
}
add_filter( 'qestx_resolved_fee_rule', 'torobpay_qestx_filter_fee_rule', 100, 3 );

/**
 * Force a positive QestX cart adjustment on TorobPay to zero.
 *
 * Backup for the rule filters. Negative amounts (discounts) pass through.
 * QestX applies this inside calculate_cart_adjustment() before add_fee().
 *
 * @param float  $amount     Calculated fee. Negative is a discount.
 * @param string $gateway_id Payment method ID.
 * @return float
 */
function torobpay_qestx_zero_positive_fee_amount( $amount, $gateway_id ) {
	if ( ! torobpay_gateway_fee_is_torobpay_gateway_id( $gateway_id ) ) {
		return $amount;
	}

	if ( (float) $amount > 0 ) {
		return 0.0;
	}

	return $amount;
}
add_filter( 'qestx_computed_fee_amount', 'torobpay_qestx_zero_positive_fee_amount', 100, 2 );

/**
 * Last QestX cart adjustment, when the runtime is booted.
 *
 * @return array|null
 */
function torobpay_qestx_last_adjustment() {
	if ( ! function_exists( 'qestx' ) ) {
		return null;
	}

	$plugin = qestx();
	if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'get_runtime' ) ) {
		return null;
	}

	$runtime = $plugin->get_runtime();
	if ( ! is_object( $runtime ) || ! method_exists( $runtime, 'get_last_adjustment' ) ) {
		return null;
	}

	$adjustment = $runtime->get_last_adjustment();

	return is_array( $adjustment ) ? $adjustment : null;
}

/**
 * Positive fee labels from one QestX adjustment.
 *
 * The main line is included only when its amount is positive. Rule-engine
 * add_fee lines are included the same way. Discount lines stay off the list.
 *
 * @param array|null $adjustment QestX last adjustment.
 * @return string[]
 */
function torobpay_qestx_positive_labels_from_adjustment( $adjustment ) {
	if ( ! is_array( $adjustment ) ) {
		return array();
	}

	$gateway_id = isset( $adjustment['gateway_id'] ) ? (string) $adjustment['gateway_id'] : '';
	if ( ! torobpay_gateway_fee_is_torobpay_gateway_id( $gateway_id ) ) {
		return array();
	}

	$labels = array();
	$amount = isset( $adjustment['amount'] ) ? (float) $adjustment['amount'] : 0.0;
	$label  = isset( $adjustment['label'] ) ? (string) $adjustment['label'] : '';
	if ( $amount > 0 && '' !== $label ) {
		$labels[] = $label;
	}

	$lines = isset( $adjustment['rule_engine_lines'] ) && is_array( $adjustment['rule_engine_lines'] )
		? $adjustment['rule_engine_lines']
		: array();

	foreach ( $lines as $line ) {
		if ( ! is_array( $line ) ) {
			continue;
		}

		$line_amount = isset( $line['amount'] ) ? (float) $line['amount'] : 0.0;
		$line_label  = isset( $line['label'] ) ? (string) $line['label'] : '';
		if ( $line_amount <= 0 || '' === $line_label ) {
			continue;
		}

		$labels[] = $line_label;
	}

	return array_values( array_unique( $labels ) );
}

/**
 * Positive QestX fee labels for the current TorobPay calculation.
 *
 * @return string[]
 */
function torobpay_qestx_positive_fee_labels() {
	if ( ! torobpay_qestx_plugin_active() ) {
		return array();
	}

	return torobpay_qestx_positive_labels_from_adjustment( torobpay_qestx_last_adjustment() );
}

/**
 * Whether a cart fee matches a positive QestX label list.
 *
 * @param object   $fee    Cart fee object from WC_Cart::get_fees().
 * @param string[] $labels Fee names to remove.
 * @return bool
 */
function torobpay_qestx_fee_is_listed_surcharge( $fee, $labels ) {
	if ( ! is_object( $fee ) || ! isset( $fee->name ) || ! is_array( $labels ) ) {
		return false;
	}

	$amount = isset( $fee->amount ) ? (float) $fee->amount : 0.0;
	if ( $amount <= 0 ) {
		return false;
	}

	return in_array( (string) $fee->name, $labels, true );
}

/**
 * Whether a cart fee is a QestX surcharge on TorobPay.
 *
 * Covers rule-engine add_fee rows, which are separate from the main
 * qestx_computed_fee_amount filter. Negative rows stay on the cart.
 *
 * @param object $fee Cart fee object from WC_Cart::get_fees().
 * @return bool
 */
function torobpay_qestx_is_unauthorized_surcharge_fee( $fee ) {
	return torobpay_qestx_fee_is_listed_surcharge( $fee, torobpay_qestx_positive_fee_labels() );
}

/**
 * Remove QestX’s checkout surcharge sentence from a gateway description.
 *
 * The discount sentence and a leftover [badge] stay. QestX appends
 * "این روش پرداخت %s کارمزد دارد." when the net adjustment is positive.
 *
 * @param string $text Gateway description.
 * @return string
 */
function torobpay_qestx_strip_surcharge_sentence( $text ) {
	$text = (string) $text;
	if ( '' === $text || false === strpos( $text, 'کارمزد دارد' ) ) {
		return $text;
	}

	$stripped = preg_replace( '/این روش پرداخت\s+.*?کارمزد دارد\./u', '', $text );
	if ( ! is_string( $stripped ) ) {
		return $text;
	}

	$stripped = preg_replace( '/[ \t]{2,}/', ' ', $stripped );

	return is_string( $stripped ) ? trim( $stripped ) : $text;
}

/**
 * Remove the QestX surcharge sentence from TorobPay after QestX writes it.
 *
 * QestX sets the description on woocommerce_available_payment_gateways at
 * priority 999. Discount copy and badges stay.
 *
 * @param array $gateways Available gateways.
 * @return array
 */
function torobpay_qestx_strip_gateway_surcharge_descriptions( $gateways ) {
	if ( ! torobpay_qestx_plugin_active() || ! is_array( $gateways ) ) {
		return $gateways;
	}

	foreach ( $gateways as $gateway_id => $gateway ) {
		if ( ! torobpay_gateway_fee_is_torobpay_gateway_id( $gateway_id ) || ! is_object( $gateway ) ) {
			continue;
		}

		if ( isset( $gateway->description ) ) {
			$gateway->description = torobpay_qestx_strip_surcharge_sentence( (string) $gateway->description );
		}
	}

	return $gateways;
}
add_filter( 'woocommerce_available_payment_gateways', 'torobpay_qestx_strip_gateway_surcharge_descriptions', 1000 );

/**
 * Remove a QestX surcharge sentence already stored on the TorobPay description.
 *
 * @param string $description Gateway description.
 * @param string $gateway_id  Payment method ID.
 * @return string
 */
function torobpay_qestx_strip_torobpay_surcharge_description( $description, $gateway_id ) {
	if ( ! torobpay_qestx_plugin_active() || ! torobpay_gateway_fee_is_torobpay_gateway_id( $gateway_id ) ) {
		return $description;
	}

	return torobpay_qestx_strip_surcharge_sentence( $description );
}
add_filter( 'woocommerce_gateway_description', 'torobpay_qestx_strip_torobpay_surcharge_description', 20, 2 );

/**
 * Hide a positive QestX blocks note on TorobPay.
 *
 * QestX adds the note from qestxPaymentMethods. A positive amount is a
 * surcharge (or a rule-engine add_fee). Zeroing it keeps a negative discount
 * note. Their script reads the setting on each DOM update, so the wrapper
 * has to run before that script.
 *
 * @param array  $dependencies Script dependencies.
 * @param string $handle       Script handle being registered.
 * @return array
 */
function torobpay_qestx_hide_blocks_surcharge_note( $dependencies, $handle ) {
	unset( $handle );

	if ( ! torobpay_qestx_plugin_active() ) {
		return $dependencies;
	}

	$script_handle = 'qestx-checkout-blocks-payment-methods';
	if ( ! wp_script_is( $script_handle, 'registered' ) ) {
		return $dependencies;
	}

	static $added = false;
	if ( $added ) {
		return $dependencies;
	}

	$ids = wp_json_encode( torobpay_gateway_fee_torobpay_gateway_ids() );
	if ( ! is_string( $ids ) ) {
		return $dependencies;
	}
	$added = true;

	$js = 'window.wc=window.wc||{};window.wc.wcSettings=window.wc.wcSettings||{};'
		. '(function(settings,ids){if(!settings||typeof settings.getSetting!=="function"||settings.__torobpayQestxWrapped){return;}'
		. 'var orig=settings.getSetting;settings.__torobpayQestxWrapped=true;settings.getSetting=function(name,fallback){'
		. 'var value=orig.call(this,name,fallback);if(name!=="qestxPaymentMethods"||!value||typeof value!=="object"){return value;}'
		. 'Object.keys(value).forEach(function(key){var row=value[key];if(!row||!(Number(row.amount)>0)){return;}'
		. 'var matched=ids.some(function(id){return String(key).toLowerCase()===String(id).toLowerCase();});'
		. 'if(matched){value[key]=Object.assign({},row,{amount:0});}});return value;};})(window.wc.wcSettings,' . $ids . ');';

	wp_add_inline_script( $script_handle, $js, 'before' );

	return $dependencies;
}
add_filter( 'woocommerce_blocks_register_script_dependencies', 'torobpay_qestx_hide_blocks_surcharge_note', 20, 2 );

/**
 * Warning copy when QestX is active.
 *
 * @return string HTML-safe body (no outer wrapper).
 */
function torobpay_qestx_warning_notice_body() {
	$plugin_name = torobpay_qestx_plugin_name();
	$plugin_slug = TOROBPAY_QESTX_PLUGIN_TEXT_DOMAIN;

	$lines   = array();
	$lines[] = sprintf(
		/* translators: 1: plugin name, 2: plugin text domain / slug */
		__( 'Plugin "%1$s" (<code>%2$s</code>) is active.', 'torobpay-for-woocommerce' ),
		esc_html( $plugin_name ),
		esc_html( $plugin_slug )
	);
	$lines[] = __( 'Under TorobPay terms, charging a fee on the TorobPay gateway is not allowed.', 'torobpay-for-woocommerce' );
	$lines[] = __( 'TorobPay automatically removes the surcharge/fee on its payment method and keeps TorobPay available below QestX’s minimum order amount. Discounts, maximum order limits, and fees on other gateways are not changed.', 'torobpay-for-woocommerce' );

	return '<p>' . implode( '</p><p>', $lines ) . '</p>';
}

torobpay_register_blocked_fee_plugin(
	array(
		'id'          => 'qestx_surcharge_terms_warning',
		'dom_id'      => 'torobpay-qestx-terms-notice',
		'priority'    => 18,
		'active'      => 'torobpay_qestx_plugin_active',
		'notice_body' => 'torobpay_qestx_warning_notice_body',
		'fee_line'    => 'torobpay_qestx_is_unauthorized_surcharge_fee',
	)
);
