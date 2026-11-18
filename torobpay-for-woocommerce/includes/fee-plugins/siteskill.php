<?php
/**
 * SiteSkill Pay Discount (siteskill-paydiscount).
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TOROBPAY_SSPD_PLUGIN_TEXT_DOMAIN', 'siteskill-paydiscount' );

/**
 * Whether SiteSkill Pay Discount is loaded (runtime signals only).
 *
 * @return bool
 */
function torobpay_sspd_plugin_active() {
	if ( class_exists( 'SiteSkill_Pay_Discount', false ) ) {
		return true;
	}

	if ( class_exists( 'SSPD_Discount', false ) ) {
		return true;
	}

	return defined( 'SSPD_SLUG' ) && TOROBPAY_SSPD_PLUGIN_TEXT_DOMAIN === SSPD_SLUG;
}

/**
 * Display name for SiteSkill Pay Discount.
 *
 * @return string
 */
function torobpay_sspd_plugin_name() {
	return __( 'SiteSkill Pay Discount', 'torobpay-for-woocommerce' );
}

/**
 * Whether a SiteSkill adjustment type is a surcharge (price increase).
 *
 * @param string $type SiteSkill type key.
 * @return bool
 */
function torobpay_sspd_is_surcharge_type( $type ) {
	$type = (string) $type;
	if ( class_exists( 'SSPD_Discount', false ) && is_callable( array( 'SSPD_Discount', 'is_surcharge' ) ) ) {
		return (bool) SSPD_Discount::is_surcharge( $type );
	}

	return in_array( $type, array( 'percent_add', 'fixed_add' ), true );
}

/**
 * Expected SiteSkill surcharge labels for TorobPay (custom or generated).
 *
 * Discount labels are not included. Uses SSPD’s last applied row when present,
 * then per-gateway settings, then the stored option as a fallback.
 *
 * @return string[]
 */
function torobpay_sspd_torobpay_surcharge_labels() {
	$labels = array();

	if ( class_exists( 'SSPD_Discount', false ) && is_callable( array( 'SSPD_Discount', 'get_last' ) ) ) {
		$last = SSPD_Discount::get_last();
		if ( is_array( $last )
			&& ! empty( $last['surcharge'] )
			&& isset( $last['label'] )
			&& '' !== (string) $last['label']
			&& torobpay_gateway_fee_is_torobpay_gateway_id( isset( $last['gateway'] ) ? (string) $last['gateway'] : '' )
		) {
			$labels[] = (string) $last['label'];
		}
	}

	if ( ! empty( $labels ) ) {
		return array_values( array_unique( $labels ) );
	}

	if ( class_exists( 'SSPD_Discount', false )
		&& is_callable( array( 'SSPD_Discount', 'get_label' ) )
		&& class_exists( 'SSPD_Settings', false )
		&& is_callable( array( 'SSPD_Settings', 'get_gateway' ) )
	) {
		foreach ( torobpay_gateway_fee_torobpay_gateway_ids() as $gateway_id ) {
			$row = SSPD_Settings::get_gateway( $gateway_id );
			if ( ! is_array( $row ) || 'yes' !== ( isset( $row['enabled'] ) ? $row['enabled'] : '' ) ) {
				continue;
			}

			$type = isset( $row['type'] ) ? (string) $row['type'] : '';
			if ( ! torobpay_sspd_is_surcharge_type( $type ) ) {
				continue;
			}

			$label = (string) SSPD_Discount::get_label( $gateway_id );
			if ( '' !== $label ) {
				$labels[] = $label;
			}
		}
	} else {
		$option_key = defined( 'SSPD_OPTION' ) ? SSPD_OPTION : 'sspd_settings';
		$settings   = function_exists( 'get_option' ) ? get_option( $option_key, array() ) : array();

		if ( is_array( $settings ) && isset( $settings['gateways'] ) && is_array( $settings['gateways'] ) ) {
			foreach ( torobpay_gateway_fee_torobpay_gateway_ids() as $gateway_id ) {
				if ( empty( $settings['gateways'][ $gateway_id ] ) || ! is_array( $settings['gateways'][ $gateway_id ] ) ) {
					continue;
				}

				$row = $settings['gateways'][ $gateway_id ];
				if ( 'yes' !== ( isset( $row['enabled'] ) ? $row['enabled'] : '' ) ) {
					continue;
				}

				$type = isset( $row['type'] ) ? (string) $row['type'] : '';
				if ( ! torobpay_sspd_is_surcharge_type( $type ) ) {
					continue;
				}

				$label = isset( $row['label'] ) ? trim( (string) $row['label'] ) : '';
				if ( '' !== $label ) {
					$labels[] = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $label ) : $label;
				}
			}
		}
	}

	return array_values( array_unique( $labels ) );
}

/**
 * Whether a cart fee is a SiteSkill surcharge applied to TorobPay.
 *
 * Positive (surcharge) fees only. SiteSkill discounts stay on the cart.
 *
 * @param object $fee Cart fee object from WC_Cart::get_fees().
 * @return bool
 */
function torobpay_sspd_is_unauthorized_surcharge_fee( $fee ) {
	if ( ! torobpay_sspd_plugin_active() ) {
		return false;
	}

	if ( ! is_object( $fee ) || ! isset( $fee->name ) ) {
		return false;
	}

	$amount = isset( $fee->amount ) ? (float) $fee->amount : 0.0;
	if ( $amount <= 0 ) {
		return false;
	}

	$name   = (string) $fee->name;
	$labels = torobpay_sspd_torobpay_surcharge_labels();

	return in_array( $name, $labels, true );
}

/**
 * Force SiteSkill’s calculated surcharge on TorobPay to zero.
 *
 * SiteSkill uses this value for the cart fee, checkout badge, and product-page
 * promo. Returning 0 hides those surcharge UI bits without touching discounts.
 *
 * @param float  $amount     Calculated amount (always positive).
 * @param string $gateway_id Payment method ID.
 * @param float  $base       Base amount used for the calculation.
 * @param array  $row        Per-gateway SiteSkill settings.
 * @return float
 */
function torobpay_sspd_zero_torobpay_surcharge_amount( $amount, $gateway_id, $base = 0, $row = array() ) {
	unset( $base );

	if ( ! torobpay_gateway_fee_is_torobpay_gateway_id( $gateway_id ) ) {
		return $amount;
	}

	$type = is_array( $row ) && isset( $row['type'] ) ? (string) $row['type'] : '';
	if ( ! torobpay_sspd_is_surcharge_type( $type ) ) {
		return $amount;
	}

	return 0;
}
add_filter( 'sspd_discount_amount', 'torobpay_sspd_zero_torobpay_surcharge_amount', 10, 4 );

/**
 * Drop TorobPay surcharge lines from SiteSkill product-page promo boxes.
 *
 * @param array $lines Promo lines.
 * @return array
 */
function torobpay_sspd_hide_torobpay_surcharge_product_boxes( $lines ) {
	if ( ! is_array( $lines ) ) {
		return $lines;
	}

	$kept = array();
	foreach ( $lines as $line ) {
		if ( ! is_array( $line ) ) {
			$kept[] = $line;
			continue;
		}

		$gateway_id = isset( $line['gateway'] ) ? (string) $line['gateway'] : '';
		if ( torobpay_gateway_fee_is_torobpay_gateway_id( $gateway_id ) && ! empty( $line['surcharge'] ) ) {
			continue;
		}

		$kept[] = $line;
	}

	return $kept;
}
add_filter( 'sspd_product_boxes', 'torobpay_sspd_hide_torobpay_surcharge_product_boxes' );

/**
 * Strip a SiteSkill surcharge badge already appended to the TorobPay title.
 *
 * Fallback if the amount filter did not run (older SiteSkill, cached HTML).
 *
 * @param string $title      Gateway title HTML.
 * @param string $gateway_id Payment method ID.
 * @return string
 */
function torobpay_sspd_strip_torobpay_surcharge_badge( $title, $gateway_id ) {
	if ( ! torobpay_gateway_fee_is_torobpay_gateway_id( $gateway_id ) ) {
		return $title;
	}

	$title = (string) $title;
	if ( false === strpos( $title, 'sspd-badge--up' ) ) {
		return $title;
	}

	$stripped = preg_replace( '/\s*<span class="sspd-badge sspd-badge--up">.*?<\/span>/su', '', $title );

	return is_string( $stripped ) ? $stripped : $title;
}
add_filter( 'woocommerce_gateway_title', 'torobpay_sspd_strip_torobpay_surcharge_badge', 30, 2 );

/**
 * Warning copy when SiteSkill Pay Discount is active.
 *
 * @return string HTML-safe body (no outer wrapper).
 */
function torobpay_sspd_warning_notice_body() {
	$plugin_name = torobpay_sspd_plugin_name();
	$plugin_slug = TOROBPAY_SSPD_PLUGIN_TEXT_DOMAIN;

	$lines   = array();
	$lines[] = sprintf(
		/* translators: 1: plugin name, 2: plugin text domain / slug */
		__( 'Plugin "%1$s" (<code>%2$s</code>) is active.', 'torobpay-for-woocommerce' ),
		esc_html( $plugin_name ),
		esc_html( $plugin_slug )
	);
	$lines[] = __( 'Under TorobPay terms, charging a fee on the TorobPay gateway is not allowed.', 'torobpay-for-woocommerce' );
	$lines[] = __( 'TorobPay automatically removes the surcharge/fee and its checkout badge or product-page promo on its payment method. Discounts and fees on other gateways are not changed.', 'torobpay-for-woocommerce' );

	return '<p>' . implode( '</p><p>', $lines ) . '</p>';
}

torobpay_register_blocked_fee_plugin(
	array(
		'id'          => 'sspd_surcharge_terms_warning',
		'dom_id'      => 'torobpay-sspd-terms-notice',
		'priority'    => 16,
		'active'      => 'torobpay_sspd_plugin_active',
		'notice_body' => 'torobpay_sspd_warning_notice_body',
		'fee_line'    => 'torobpay_sspd_is_unauthorized_surcharge_fee',
	)
);
