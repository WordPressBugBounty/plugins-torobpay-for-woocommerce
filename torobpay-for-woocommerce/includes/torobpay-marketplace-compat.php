<?php
/**
 * Marketplace sub-order compatibility (Dokan, MultiVendorX).
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function torobpay_compat_resolve_order( $order ) {
	if ( is_numeric( $order ) ) {
		$order = wc_get_order( $order );
	}
	return $order instanceof WC_Order ? $order : false;
}

function torobpay_is_dokan_active() {
	return function_exists( 'dokan' ) || class_exists( 'WeDevs_Dokan' );
}

function torobpay_is_multivendorx_active() {
	return class_exists( 'MultiVendorX' ) || function_exists( 'mvx' ) || function_exists( 'wcmp' );
}

function torobpay_is_dokan_sub_order( $order ) {
	$order = torobpay_compat_resolve_order( $order );
	if ( ! $order || ! torobpay_is_dokan_active() ) {
		return false;
	}

	if ( function_exists( 'dokan_is_sub_order' ) && $order->get_id() && dokan_is_sub_order( $order->get_id() ) ) {
		return true;
	}

	return $order->get_meta( '_dokan_sub_order', true ) === 'yes';
}

function torobpay_is_mvx_sub_order( $order ) {
	$order = torobpay_compat_resolve_order( $order );
	if ( ! $order || ! torobpay_is_multivendorx_active() ) {
		return false;
	}

	if ( function_exists( 'is_mvx_vendor_order' ) && $order->get_id() ) {
		return (bool) is_mvx_vendor_order( $order->get_id() );
	}

	return $order->get_parent_id() > 0;
}

/**
 * Plugin-agnostic sub-order signal: core never gives a `shop_order` a parent,
 * so a parented one came from a marketplace splitter, whichever plugin it is.
 * Token presence is not used: a parent whose token was lost is still ours.
 */
function torobpay_is_generic_sub_order( $order ) {
	$order = torobpay_compat_resolve_order( $order );
	if ( ! $order || (int) $order->get_parent_id() <= 0 ) {
		return false;
	}

	$type = method_exists( $order, 'get_type' ) ? (string) $order->get_type() : 'shop_order';

	return 'shop_order' === $type;
}

function torobpay_is_marketplace_sub_order( $order ) {
	$order = torobpay_compat_resolve_order( $order );
	if ( ! $order ) {
		return false;
	}

	if ( torobpay_is_dokan_sub_order( $order ) || torobpay_is_mvx_sub_order( $order ) || torobpay_is_generic_sub_order( $order ) ) {
		return true;
	}

	/** @see torobpay_should_bypass_order for final override */
	return (bool) apply_filters( 'torobpay_is_marketplace_sub_order', false, $order );
}

function torobpay_get_marketplace_orders_query_exclusions() {
	$exclusions = array();

	if ( torobpay_is_dokan_active() ) {
		$exclusions['meta_query'][] = array(
			'relation' => 'OR',
			array(
				'key'     => '_dokan_sub_order',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_dokan_sub_order',
				'value'   => 'yes',
				'compare' => '!=',
			),
		);
	}

	// Plugin-agnostic: sub-orders always carry a parent (see torobpay_is_generic_sub_order).
	$exclusions['parent'] = 0;

	return apply_filters( 'torobpay_marketplace_orders_query_exclusions', $exclusions );
}

function torobpay_merge_marketplace_orders_query_exclusions( array $args ) {
	$exclusions = torobpay_get_marketplace_orders_query_exclusions();

	if ( isset( $exclusions['parent'] ) ) {
		$args['parent'] = $exclusions['parent'];
	}

	// meta_query is HPOS-only since WC 9.2; on CPT it triggers doing_it_wrong and is ignored.
	if ( ! empty( $exclusions['meta_query'] ) && function_exists( 'torobpay_orders_use_hpos' ) && torobpay_orders_use_hpos() ) {
		if ( ! isset( $args['meta_query'] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Marketplace order exclusion clauses for TorobPay admin lists.
			$args['meta_query'] = array();
		}
		foreach ( $exclusions['meta_query'] as $clause ) {
			$args['meta_query'][] = $clause;
		}
	}

	return $args;
}

function torobpay_should_bypass_order( $order, $context = '' ) {
	$order = torobpay_compat_resolve_order( $order );
	if ( ! $order ) {
		return false;
	}

	$bypass = torobpay_is_marketplace_sub_order( $order );
	$bypass = (bool) apply_filters( 'torobpay_should_bypass_order', $bypass, $order, $context );

	if ( $bypass && '' !== $context ) {
		torobpay_debug_log( sprintf( 'TorobPay Debug: Bypassing marketplace sub-order #%d in %s', $order->get_id(), $context ) );
	}

	return $bypass;
}
