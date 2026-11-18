<?php
/**
 * In-store catalog availability, item aggregation, and unpaid stock hold.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catalog units available for an in-store (POS) line.
 *
 * Same rules as checkout: unmanaged or backorderable products are unlimited
 * (qty null). Held (reserved) stock is subtracted. Sold-individually caps at 1.
 *
 * @param object $product Product.
 * @return array{ok:bool,qty:int|null} qty null means unlimited.
 */
function torobpay_get_product_instore_sellable( $product ) {
	if ( ! is_object( $product ) || ! method_exists( $product, 'is_purchasable' ) || ! $product->is_purchasable() ) {
		return array(
			'ok'  => false,
			'qty' => 0,
		);
	}

	if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) ) {
		return array(
			'ok'  => false,
			'qty' => 0,
		);
	}

	$sold_individually = method_exists( $product, 'is_sold_individually' ) && $product->is_sold_individually();
	$managing          = method_exists( $product, 'managing_stock' ) && $product->managing_stock();
	$backorders        = method_exists( $product, 'backorders_allowed' ) && $product->backorders_allowed();
	$in_stock          = ! method_exists( $product, 'is_in_stock' ) || $product->is_in_stock();

	if ( ! $in_stock && ! $backorders ) {
		return array(
			'ok'  => false,
			'qty' => 0,
		);
	}

	$available = null;
	if ( $managing && ! $backorders ) {
		$available = method_exists( $product, 'get_stock_quantity' ) ? (int) $product->get_stock_quantity() : 0;
		if ( function_exists( 'wc_get_held_stock_quantity' ) ) {
			$available -= (int) wc_get_held_stock_quantity( $product );
		}
		$available = max( 0, $available );
	}

	if ( $sold_individually ) {
		if ( null !== $available && $available < 1 ) {
			return array(
				'ok'  => false,
				'qty' => 0,
			);
		}

		return array(
			'ok'  => true,
			'qty' => 1,
		);
	}

	if ( null === $available ) {
		return array(
			'ok'  => true,
			'qty' => null,
		);
	}

	return array(
		'ok'  => $available > 0,
		'qty' => $available,
	);
}

/**
 * Merge in-store payload rows that share a product ID.
 *
 * Preview and create validate stock per row; without this, two rows of the same
 * product can each pass the per-line cap while their combined quantity does not.
 *
 * @param array $items Parsed items.
 * @return array<int, array{product_id:int,quantity:int}>
 */
function torobpay_aggregate_instore_items( $items ) {
	if ( ! is_array( $items ) ) {
		return array();
	}

	$quantities = array();
	$order      = array();

	foreach ( $items as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$product_id = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
		$quantity   = isset( $row['quantity'] ) ? (int) $row['quantity'] : 0;
		if ( $product_id <= 0 || $quantity <= 0 ) {
			continue;
		}

		if ( ! isset( $quantities[ $product_id ] ) ) {
			$quantities[ $product_id ] = 0;
			$order[]                   = $product_id;
		}

		$quantities[ $product_id ] += $quantity;
	}

	$aggregated = array();
	foreach ( $order as $product_id ) {
		$aggregated[] = array(
			'product_id' => $product_id,
			'quantity'   => $quantities[ $product_id ],
		);
	}

	return $aggregated;
}

/**
 * Hold WooCommerce catalog stock for an unpaid in-store order.
 *
 * Same as checkout: `wc_reserve_stock_for_order()` follows Hold stock (minutes).
 * Unpaid in-store orders are not forced into WooCommerce's unpaid-cancel cron
 * (`created_via` is not checkout/store-api). The merchant cancels if needed.
 *
 * @param object $order Order.
 * @return true|\WP_Error
 */
function torobpay_prepare_instore_order_stock( $order ) {
	if ( ! $order ) {
		return new WP_Error( 'torobpay_instore_reserve', __( 'Could not reserve stock for this order.', 'torobpay-for-woocommerce' ) );
	}

	if ( ! function_exists( 'wc_reserve_stock_for_order' ) ) {
		return true;
	}

	try {
		wc_reserve_stock_for_order( $order );
	} catch ( Exception $e ) {
		if ( function_exists( 'wc_release_stock_for_order' ) ) {
			wc_release_stock_for_order( $order );
		}
		return new WP_Error( 'torobpay_instore_reserve', $e->getMessage() );
	}

	return true;
}
