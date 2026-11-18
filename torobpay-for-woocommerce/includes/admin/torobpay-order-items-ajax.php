<?php
/**
 * TorobPay admin "Edit & Sync items" AJAX handlers.
 *
 * Powers the order-edit modal that lets merchants unlock, preview taxes for,
 * edit, add/search products on, and re-sync a TorobPay order's line items,
 * silently toggling the order to on-hold while editing and reverting afterward.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keep edit-sync tax behavior aligned with the original order.
 *
 * Orders that were created without tax should stay tax-free in the modal
 * preview and after saving edited items.
 */
function torobpay_order_should_recalculate_taxes( $order ) {
	return is_object( $order )
		&& method_exists( $order, 'get_total_tax' )
		&& floatval( $order->get_total_tax() ) > 0;
}

/**
 * Validate an edited order total against TorobPay's update rule.
 *
 * TorobPay rejects updates whose payable amount is not strictly lower than the
 * amount it already holds (error 1012, and 1014 when a discount removal pushes
 * the total back up). Mirror that rule locally so the merchant gets a clear
 * message before any API call is made.
 *
 * @param float $new_total      Recalculated order total after the edit.
 * @param float $original_total Order total before the edit.
 * @return string|null Merchant-facing message, or null when the total is acceptable.
 */
function torobpay_edit_total_violation_message( $new_total, $original_total ) {
	$new_total      = floatval( $new_total );
	$original_total = floatval( $original_total );

	if ( $new_total <= 0 ) {
		return sprintf(
			/* translators: %s: new order amount */
			__( 'New amount (%s) must be greater than zero.', 'torobpay-for-woocommerce' ),
			wc_format_decimal( $new_total, 0 )
		);
	}

	if ( $original_total > 0 && $new_total >= $original_total ) {
		return sprintf(
			/* translators: 1: new order amount, 2: original order amount */
			__( 'New amount (%1$s) must be less than the original order amount (%2$s). Removing a discount is only possible if other changes lower the total by more than the discount.', 'torobpay-for-woocommerce' ),
			wc_format_decimal( $new_total, 0 ),
			wc_format_decimal( $original_total, 0 )
		);
	}

	return null;
}

/**
 * Parse the edit-modal `items` payload.
 *
 * Prefer JSON (avoids nested form-POST mangling / WAF false positives when
 * adding products with `product_id` + `name`). Fall back to classic arrays.
 *
 * @return array
 */
function torobpay_parse_edit_items_payload() {
	if ( ! isset( $_POST['items'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies nonce.
		return array();
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
	$raw = wp_unslash( $_POST['items'] );

	if ( is_string( $raw ) ) {
		$decoded = json_decode( $raw, true );
		$items   = is_array( $decoded ) ? $decoded : array();
	} elseif ( is_array( $raw ) ) {
		$items = $raw;
	} else {
		$items = array();
	}

	// Sanitize every row immediately so downstream hooks never see raw input.
	$sanitized = array();
	foreach ( $items as $item ) {
		$sanitized[] = torobpay_normalize_edited_item( $item );
	}

	return $sanitized;
}

/**
 * Normalize one edited line from the modal into safe scalars.
 *
 * @param mixed $edited
 * @return array{item_id:int,quantity:int,unit_price:float,product_id:int,name:string}
 */
function torobpay_normalize_edited_item( $edited ) {
	$edited = is_array( $edited ) ? $edited : array();

	return array(
		'item_id'    => isset( $edited['item_id'] ) ? absint( $edited['item_id'] ) : 0,
		'quantity'   => isset( $edited['quantity'] ) ? absint( torobpay_parse_amount( $edited['quantity'] ) ) : 0,
		'unit_price' => isset( $edited['unit_price'] ) ? torobpay_parse_amount( $edited['unit_price'] ) : 0.0,
		'product_id' => isset( $edited['product_id'] ) ? absint( $edited['product_id'] ) : 0,
		'name'       => isset( $edited['name'] ) ? sanitize_text_field( (string) $edited['name'] ) : '',
	);
}

/**
 * Read an optional money field from the edit-modal POST body.
 *
 * An empty value means the field was not sent. A typed zero is 0.
 *
 * @param string $key POST key.
 * @return float|null
 */
function torobpay_posted_amount( $key ) {
	if ( ! isset( $_POST[ $key ] ) || $_POST[ $key ] === '' ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies nonce.
		return null;
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
	$raw = wp_unslash( $_POST[ $key ] );
	return torobpay_parse_amount( $raw );
}

/**
 * Snapshot order line items before edit-sync mutations so we can roll back if
 * the TorobPay update API fails.
 *
 * @param WC_Order $order
 * @return array
 */
function torobpay_capture_order_items_snapshot( $order ) {
	$snapshot = array( 'items' => array() );

	foreach ( array( 'line_item', 'shipping', 'fee', 'coupon' ) as $item_type ) {
		foreach ( $order->get_items( $item_type ) as $item ) {
			$snapshot['items'][] = torobpay_snapshot_single_order_item( $item );
		}
	}

	return $snapshot;
}

/**
 * @param WC_Order_Item $item
 * @return array
 */
function torobpay_snapshot_single_order_item( $item ) {
	$data = $item->get_data();
	unset( $data['id'], $data['order_id'] );

	$meta = array();
	foreach ( $item->get_meta_data() as $meta_entry ) {
		$meta[] = array(
			'key'   => $meta_entry->key,
			'value' => $meta_entry->value,
		);
	}

	return array(
		'type' => $item->get_type(),
		'data' => $data,
		'meta' => $meta,
	);
}

/**
 * Restore a previously captured order-items snapshot.
 *
 * @param WC_Order $order
 * @param array    $snapshot
 * @return void
 */
function torobpay_restore_order_items_snapshot( $order, $snapshot ) {
	if ( empty( $snapshot['items'] ) || ! is_array( $snapshot['items'] ) ) {
		return;
	}

	foreach ( array( 'line_item', 'shipping', 'fee', 'coupon', 'tax' ) as $item_type ) {
		foreach ( $order->get_items( $item_type ) as $item_id => $item ) {
			$order->remove_item( $item_id );
		}
	}

	foreach ( $snapshot['items'] as $item_snapshot ) {
		$item = torobpay_create_order_item_from_snapshot( $item_snapshot );
		if ( $item ) {
			$order->add_item( $item );
		}
	}

	$order->calculate_totals( torobpay_order_should_recalculate_taxes( $order ) );
	$order->save();
}

/**
 * @param array $snapshot
 * @return WC_Order_Item|null
 */
function torobpay_create_order_item_from_snapshot( $snapshot ) {
	$class_map = array(
		'line_item' => 'WC_Order_Item_Product',
		'shipping'  => 'WC_Order_Item_Shipping',
		'fee'       => 'WC_Order_Item_Fee',
		'coupon'    => 'WC_Order_Item_Coupon',
	);

	if ( empty( $snapshot['type'] ) || ! isset( $class_map[ $snapshot['type'] ] ) ) {
		return null;
	}

	$class = $class_map[ $snapshot['type'] ];
	$item  = new $class();

	if ( ! empty( $snapshot['data'] ) && is_array( $snapshot['data'] ) ) {
		$item->set_props( $snapshot['data'] );
	}

	if ( ! empty( $snapshot['meta'] ) && is_array( $snapshot['meta'] ) ) {
		foreach ( $snapshot['meta'] as $meta_entry ) {
			if ( empty( $meta_entry['key'] ) ) {
				continue;
			}
			$item->add_meta_data( $meta_entry['key'], $meta_entry['value'], true );
		}
	}

	return $item;
}

/**
 * Named-lock key for one order's edit session.
 *
 * GET_LOCK names are limited to 64 characters. The hash includes the table
 * prefix and blog id so two sites on one database do not share a lock.
 *
 * @param int $order_id Order ID.
 * @return string
 */
function torobpay_order_edit_lock_name( $order_id ) {
	global $wpdb;

	$prefix = ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ) ? (string) $wpdb->prefix : 'wp_';
	$blog   = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

	return 'tp_ed_' . md5( $prefix . '|' . $blog . '|' . absint( $order_id ) );
}

/**
 * Lock owners created by this request.
 *
 * @return array<int,array{type:string,token:string}>
 */
function &torobpay_order_edit_lock_owners() {
	static $owners = array();
	return $owners;
}

/**
 * Run GET_LOCK or RELEASE_LOCK.
 *
 * @param string $function GET_LOCK or RELEASE_LOCK.
 * @param string $name     Lock name.
 * @param int    $timeout  Seconds to wait. Used only for GET_LOCK.
 * @return bool|null True on 1, false on 0, null when the statement is unavailable.
 */
function torobpay_mysql_named_lock( $function, $name, $timeout = 0 ) {
	global $wpdb;

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
		return null;
	}

	if ( 'GET_LOCK' === $function ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session named lock. Must not be cached.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, (int) $timeout ) );
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session named lock. Must not be cached.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	if ( null === $result || '' === $result ) {
		return null;
	}

	return '1' === (string) $result;
}

/**
 * How long a fallback option lock may block after the request that took it died.
 *
 * @return int
 */
function torobpay_order_edit_option_lock_ttl() {
	return 90;
}

/**
 * @param int $order_id Order ID.
 * @return string
 */
function torobpay_order_edit_option_lock_key( $order_id ) {
	return 'torobpay_edit_lock_' . absint( $order_id );
}

/**
 * Acquire the per-order edit lock.
 *
 * The overlapping request does not wait. GET_LOCK is released when this
 * request's database connection closes, including after a fatal error.
 * When GET_LOCK is unavailable, an option is used and expires after the TTL
 * if this request never releases it.
 *
 * @param int $order_id Order ID.
 * @return bool
 */
function torobpay_acquire_order_edit_lock( $order_id ) {
	$order_id = absint( $order_id );
	if ( ! $order_id ) {
		return false;
	}

	$owners = &torobpay_order_edit_lock_owners();
	if ( isset( $owners[ $order_id ] ) ) {
		return true;
	}

	$name = torobpay_order_edit_lock_name( $order_id );
	$got  = torobpay_mysql_named_lock( 'GET_LOCK', $name, 0 );
	if ( true === $got ) {
		$owners[ $order_id ] = array(
			'type'  => 'mysql',
			'token' => '',
		);
		return true;
	}
	if ( false === $got ) {
		return false;
	}

	$key   = torobpay_order_edit_option_lock_key( $order_id );
	$token = bin2hex( random_bytes( 8 ) );
	$value = time() . '|' . $token;
	if ( add_option( $key, $value, '', false ) ) {
		$owners[ $order_id ] = array(
			'type'  => 'option',
			'token' => $value,
		);
		return true;
	}

	$existing = (string) get_option( $key, '' );
	$started  = (int) $existing;
	if ( $started > 0 && ( time() - $started ) < torobpay_order_edit_option_lock_ttl() ) {
		return false;
	}

	delete_option( $key );
	if ( ! add_option( $key, $value, '', false ) ) {
		return false;
	}

	$owners[ $order_id ] = array(
		'type'  => 'option',
		'token' => $value,
	);
	return true;
}

/**
 * Release the edit lock held by this request.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function torobpay_release_order_edit_lock( $order_id ) {
	$order_id = absint( $order_id );
	$owners   = &torobpay_order_edit_lock_owners();
	if ( ! isset( $owners[ $order_id ] ) ) {
		return;
	}

	$owner = $owners[ $order_id ];
	unset( $owners[ $order_id ] );

	if ( 'mysql' === $owner['type'] ) {
		torobpay_mysql_named_lock( 'RELEASE_LOCK', torobpay_order_edit_lock_name( $order_id ) );
		return;
	}

	$key = torobpay_order_edit_option_lock_key( $order_id );
	if ( (string) get_option( $key, '' ) === (string) $owner['token'] ) {
		delete_option( $key );
	}
}

/**
 * Take the edit lock, or send the busy error.
 *
 * Released on shutdown. The AJAX handlers exit, and exit does not run finally.
 *
 * @param int $order_id Order ID.
 * @return bool False when the caller should return.
 */
function torobpay_require_order_edit_lock( $order_id ) {
	if ( torobpay_acquire_order_edit_lock( $order_id ) ) {
		$order_id = absint( $order_id );
		register_shutdown_function(
			static function () use ( $order_id ) {
				torobpay_release_order_edit_lock( $order_id );
			}
		);
		return true;
	}

	wp_send_json_error(
		array(
			'message' => __( 'This order is already being updated. Wait a moment and try again.', 'torobpay-for-woocommerce' ),
		)
	);
	return false;
}

/**
 * AJAX handler: Unlock order items for editing (silently change to on-hold)
 * Returns current order items for the edit modal
 */
add_action( 'wp_ajax_torobpay_unlock_order_items', 'torobpay_ajax_unlock_order_items' );
function torobpay_ajax_unlock_order_items() {
	check_ajax_referer( 'torobpay-edit-sync-items', 'security' );

	if ( ! current_user_can( 'edit_shop_orders' ) || empty( $_POST['order_id'] ) ) {
		wp_send_json_error( array( 'message' => __( 'An error occurred.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order_id = absint( $_POST['order_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
	if ( ! torobpay_require_order_edit_lock( $order_id ) ) {
		return;
	}

	$order = torobpay_get_fresh_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order_payment_method = $order->get_payment_method();
	if ( ! torobpay_is_torobpay_payment_method( $order_payment_method ) ) {
		wp_send_json_error( array( 'message' => __( 'This order does not belong to TorobPay.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		wp_send_json_error( array( 'message' => __( 'This order is a marketplace vendor sub-order and has no independent payment token.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$already_editing = $order->get_meta( '_torobpay_editing_items' ) === 'yes';

	if ( $already_editing ) {
		// Resumed after browser crash — order is already on-hold with flag set.
		// Just retrieve the stored original status and skip the status change.
		$original_status = $order->get_meta( '_torobpay_pre_edit_status' ) ?: $order->get_status();
	} else {
		if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded', 'checkout-draft' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Order status is not suitable for editing.', 'torobpay-for-woocommerce' ) ) );
			return;
		}

		// pre-flight request; direct AJAX calls still need the same gate here.
		if ( ! torobpay_has_settled_payment_for_actions( $order ) ) {
			$preflight_result = torobpay_run_action_preflight( $order );
			if ( ! $preflight_result['success'] ) {
				wp_send_json_error( array( 'message' => $preflight_result['message'] ) );
				return;
			}
		}

		$original_status = $order->get_status();
		$order->update_meta_data( '_torobpay_editing_items', 'yes' );
		$order->update_meta_data( '_torobpay_pre_edit_status', $original_status );
		$order->save();

		// Silently change to on-hold (the flag prevents torobpay_order_status_update_items from firing)
		$order->set_status( 'on-hold', __( 'TorobPay: Status temporarily changed for item editing.', 'torobpay-for-woocommerce' ) );
		$order->save();
	}

	$items = array();
	foreach ( $order->get_items() as $item_id => $item ) {
		$product = $item->get_product();
		$items[] = array(
			'item_id'      => $item_id,
			'product_id'   => $item->get_product_id(),
			'variation_id' => $item->get_variation_id(),
			'name'         => $item->get_name(),
			'quantity'     => $item->get_quantity(),
			'total'        => wc_format_decimal( $item->get_total(), 0 ),
			'subtotal'     => wc_format_decimal( $item->get_subtotal(), 0 ),
			'unit_price'   => $item->get_quantity() > 0 ? wc_format_decimal( $item->get_subtotal() / $item->get_quantity(), 0 ) : 0,
			'sku'          => $product ? $product->get_sku() : '',
		);
	}

	// Compute effective discount (coupon discount + any TorobPay override fee)
	$override_discount = 0.0;
	foreach ( $order->get_items( 'fee' ) as $fee_item ) {
		if ( $fee_item->get_meta( '_torobpay_fee_type' ) === 'discount_override' ) {
			$override_discount += abs( floatval( $fee_item->get_total() ) );
		}
	}
	$effective_discount        = floatval( $order->get_discount_total() ) + $override_discount;
	$tax_recalculation_enabled = torobpay_order_should_recalculate_taxes( $order );

	wp_send_json_success(
		array(
			'items'           => $items,
			'original_status' => $original_status,
			'currency'        => $order->get_currency(),
			'order_total'     => wc_format_decimal( $order->get_total(), 0 ),
			'discount_total'  => wc_format_decimal( $effective_discount, 0 ),
			'shipping_total'  => wc_format_decimal( $order->get_shipping_total(), 0 ),
			'tax_total'       => wc_format_decimal( $order->get_total_tax(), 0 ),
			'tax_enabled'     => $tax_recalculation_enabled,
			'resumed'         => $already_editing,
		)
	);
}

/**
 * AJAX handler: Tax preview for Edit & Sync modal
 * Calculates taxes server-side using WooCommerce rules based on the provided
 * in-memory edited items, discount and shipping. Does NOT persist changes.
 */
add_action( 'wp_ajax_torobpay_calc_tax_preview', 'torobpay_ajax_calc_tax_preview' );
function torobpay_ajax_calc_tax_preview() {
	check_ajax_referer( 'torobpay-edit-sync-items', 'security' );

	if ( ! current_user_can( 'edit_shop_orders' ) || empty( $_POST['order_id'] ) ) {
		wp_send_json_error( array( 'message' => __( 'An error occurred.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order_id = (int) $_POST['order_id'];
	$order    = wc_get_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order_payment_method = $order->get_payment_method();
	if ( ! torobpay_is_torobpay_payment_method( $order_payment_method ) ) {
		wp_send_json_error( array( 'message' => __( 'This order does not belong to TorobPay.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		wp_send_json_error( array( 'message' => __( 'This order is a marketplace vendor sub-order and has no independent payment token.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	try {
		$should_recalculate_taxes = torobpay_order_should_recalculate_taxes( $order );

		$edited_items = torobpay_parse_edit_items_payload();
		$new_discount = torobpay_posted_amount( 'discount' );
		$new_shipping = torobpay_posted_amount( 'shipping' );

		// Build a temporary preview order to avoid mutating the real order.
		// WC_Order::calculate_totals() persists the order, so override save() here.
		$preview = new class() extends WC_Order {
			public function save() {
				return $this->get_id();
			}
		};

		// Copy essential context so WooCommerce tax engine uses the same location
		if ( method_exists( $preview, 'set_currency' ) ) {
			$preview->set_currency( $order->get_currency() );
		}
		$preview->set_billing_country( $order->get_billing_country() );
		$preview->set_billing_state( $order->get_billing_state() );
		$preview->set_billing_postcode( $order->get_billing_postcode() );
		$preview->set_shipping_country( $order->get_shipping_country() );
		$preview->set_shipping_state( $order->get_shipping_state() );
		$preview->set_shipping_postcode( $order->get_shipping_postcode() );

		// Map original items by id so we can retain product references
		$original_items = array();
		foreach ( $order->get_items() as $item_id => $it ) {
			$original_items[ $item_id ] = $it;
		}

		// Add edited items to preview (skip zero-quantity items)
		foreach ( $edited_items as $raw_edited ) {
			$edited     = torobpay_normalize_edited_item( $raw_edited );
			$item_id    = $edited['item_id'];
			$qty        = $edited['quantity'];
			$unit_price = $edited['unit_price'];
			if ( $qty <= 0 ) {
				continue;
			}

			$name         = $edited['name'];
			$product_id   = 0;
			$variation_id = 0;
			$product_obj  = null;

			if ( $item_id && isset( $original_items[ $item_id ] ) ) {
				$orig         = $original_items[ $item_id ];
				$product_id   = $orig->get_product_id();
				$variation_id = $orig->get_variation_id();
				$product_obj  = $orig->get_product();
				if ( empty( $name ) ) {
					$name = $orig->get_name();
				}
			}

			// New products from the add-product UI (not yet on the order).
			if ( ! $product_id && $edited['product_id'] ) {
				$product_obj = wc_get_product( $edited['product_id'] );
				if ( $product_obj ) {
					if ( $product_obj->is_type( 'variation' ) ) {
						$product_id   = $product_obj->get_parent_id();
						$variation_id = $product_obj->get_id();
					} else {
						$product_id = $product_obj->get_id();
					}
					if ( empty( $name ) ) {
						$name = $product_obj->get_name();
					}
				}
			}

			$line_total = $unit_price * $qty;

			$order_item = new WC_Order_Item_Product();
			if ( $product_obj ) {
				// Prefer set_product() so variations get parent/variation IDs + tax class.
				$order_item->set_product( $product_obj );
			} elseif ( $product_id ) {
				$order_item->set_product_id( $product_id );
				if ( $variation_id ) {
					$order_item->set_variation_id( $variation_id );
				}
			}
			$order_item->set_name( $name ?: __( 'Product', 'torobpay-for-woocommerce' ) );
			$order_item->set_quantity( $qty );
			$order_item->set_subtotal( $unit_price * $qty );
			$order_item->set_total( $line_total );
			$preview->add_item( $order_item );
		}

		// Shipping: if new_shipping provided, use it; otherwise copy original shipping
		if ( $new_shipping !== null && $new_shipping >= 0 ) {
			if ( $new_shipping > 0 ) {
				$shipping_item = new WC_Order_Item_Shipping();
				$shipping_item->set_name( __( 'Shipping', 'torobpay-for-woocommerce' ) );
				$shipping_item->set_total( $new_shipping );
				$preview->add_item( $shipping_item );
			}
		} else {
			foreach ( $order->get_items( 'shipping' ) as $sh ) {
				$shipping_item = new WC_Order_Item_Shipping();
				$shipping_item->set_name( $sh->get_name() );
				$shipping_item->set_total( $sh->get_total() );
				$preview->add_item( $shipping_item );
			}
		}

		// Apply the requested discount directly as a single non-taxable negative fee.
		// We do NOT copy or recalculate the original order's coupons here: the "discount"
		// field in the modal represents the TOTAL desired discount (overriding everything),
		// so preview just uses that value as-is.
		if ( $new_discount !== null && $new_discount > 0 ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( __( 'Discount', 'torobpay-for-woocommerce' ) );
			$fee->set_total( -floatval( $new_discount ) );
			$fee->set_tax_status( 'none' );
			$preview->add_item( $fee );
		}

		$preview->calculate_totals( $should_recalculate_taxes );

		$new_subtotal = 0.0;
		foreach ( $preview->get_items() as $it ) {
			$new_subtotal += floatval( $it->get_subtotal() );
		}

		$tax_total          = floatval( $preview->get_total_tax() );
		$new_total          = floatval( $preview->get_total() );
		$effective_discount = $new_discount !== null ? floatval( $new_discount ) : 0.0;

		wp_send_json_success(
			array(
				'discount_total_raw' => round( $effective_discount, 2 ),
				'discount_total'     => wc_format_decimal( $effective_discount, 0 ),
				'tax_total_raw'      => round( $tax_total, 2 ),
				'tax_total'          => wc_format_decimal( $tax_total, 0 ),
				'subtotal_raw'       => round( $new_subtotal, 2 ),
				'subtotal'           => wc_format_decimal( $new_subtotal, 0 ),
				'total_raw'          => round( $new_total, 2 ),
				'total'              => wc_format_decimal( $new_total, 0 ),
				'currency'           => $preview->get_currency() ? $preview->get_currency() : $order->get_currency(),
				'tax_enabled'        => $should_recalculate_taxes,
			)
		);
	} catch ( Throwable $e ) {
		torobpay_debug_log( 'TorobPay calc_tax_preview failed for order ' . $order_id . ': ' . $e->getMessage() );
		wp_send_json_error(
			array(
				'message' => __( 'Error calculating tax preview. Please try again.', 'torobpay-for-woocommerce' ),
			)
		);
	}
}

/**
 * AJAX handler: Save edited items via WooCommerce and sync to TorobPay
 * Uses WC order item methods to ensure WooCommerce processes all internal operations
 */
add_action( 'wp_ajax_torobpay_save_and_sync_items', 'torobpay_ajax_save_and_sync_items' );
function torobpay_ajax_save_and_sync_items() {
	check_ajax_referer( 'torobpay-edit-sync-items', 'security' );

	if ( ! current_user_can( 'edit_shop_orders' ) || empty( $_POST['order_id'] ) ) {
		wp_send_json_error( array( 'message' => __( 'An error occurred.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order_id = absint( $_POST['order_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
	if ( ! torobpay_require_order_edit_lock( $order_id ) ) {
		return;
	}

	$order = torobpay_get_fresh_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		wp_send_json_error( array( 'message' => __( 'This order is a marketplace vendor sub-order and has no independent payment token.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( $order->get_meta( '_torobpay_editing_items' ) !== 'yes' ) {
		wp_send_json_error(
			array(
				'message'       => __( 'This order was already saved. Your changes were not applied.', 'torobpay-for-woocommerce' ),
				'reload_editor' => true,
				'reload_label'  => __( 'Reload editor', 'torobpay-for-woocommerce' ),
			)
		);
		return;
	}

	$original_status = $order->get_meta( '_torobpay_pre_edit_status' );
	if ( ! $original_status ) {
		// Fallback: should not happen — log and use current status rather than hardcoding 'processing'
		torobpay_debug_log( 'TorobPay: _torobpay_pre_edit_status missing for order ' . $order_id . ' during save_and_sync' );
		$original_status = $order->get_status();
	}

	// Capture the current effective discount BEFORE any mutations so we can
	// detect whether the user actually changed the discount field.
	// Effective discount = WooCommerce coupon discount + any existing TorobPay override fee.
	$original_coupon_discount = floatval( $order->get_discount_total() );
	$original_override        = 0.0;
	$existing_override_fee_id = null;
	foreach ( $order->get_items( 'fee' ) as $fee_id => $fee_item ) {
		if ( $fee_item->get_meta( '_torobpay_fee_type' ) === 'discount_override' ) {
			$existing_override_fee_id = $fee_id;
			$original_override        = abs( floatval( $fee_item->get_total() ) );
			break;
		}
	}
	$original_effective_discount = $original_coupon_discount + $original_override;

	// TorobPay only accepts updates that lower the payable amount (1012/1014).
	// Remember the pre-edit total so the rule is enforced server-side too.
	$original_total = floatval( $order->get_total() );

	$edited_items = torobpay_parse_edit_items_payload();
	if ( empty( $edited_items ) ) {
		// No changes or empty — cancel and revert
		torobpay_cancel_edit_mode( $order, $original_status );
		wp_send_json_error( array( 'message' => __( 'Invalid item data.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order_snapshot = null;
	$remote_synced  = false;

	try {
		// Capture pre-edit state so WooCommerce can be rolled back if TorobPay rejects the update.
		$order_snapshot = torobpay_capture_order_items_snapshot( $order );

		$current_items   = $order->get_items();
		$items_to_remove = array();
		$items_changed   = false;
		$added_products  = 0;

		foreach ( $edited_items as $raw_edited ) {
			$edited  = torobpay_normalize_edited_item( $raw_edited );
			$item_id = $edited['item_id'];
			$new_qty = $edited['quantity'];

			if ( ! $item_id || ! isset( $current_items[ $item_id ] ) ) {
				continue;
			}

			$item = $current_items[ $item_id ];

			if ( $new_qty === 0 ) {
				$items_to_remove[] = $item_id;
				$items_changed     = true;
			} else {
				$old_qty        = $item->get_quantity();
				$old_unit_price = $old_qty > 0 ? floatval( $item->get_subtotal() ) / $old_qty : 0.0;
				$new_unit_price = $edited['unit_price'];
				if ( $new_unit_price < 0 ) {
					$new_unit_price = 0.0;
				}

				if ( $new_qty !== $old_qty || abs( $new_unit_price - $old_unit_price ) > 0.01 ) {
					$item->set_quantity( $new_qty );
					$item->set_subtotal( $new_unit_price * $new_qty );
					$item->set_total( $new_unit_price * $new_qty );
					$item->save();
					$items_changed = true;
				}
			}

			unset( $current_items[ $item_id ] );
		}

		// Any items not in the edited list should be removed
		foreach ( $current_items as $item_id => $item ) {
			$items_to_remove[] = $item_id;
			$items_changed     = true;
		}

		foreach ( $items_to_remove as $item_id ) {
			$order->remove_item( $item_id );
		}

		foreach ( $edited_items as $raw_edited ) {
			$edited = torobpay_normalize_edited_item( $raw_edited );
			if ( $edited['item_id'] ) {
				continue; // already handled in the edit loop above
			}
			$new_product_id = $edited['product_id'];
			$new_qty        = $edited['quantity'];
			$new_unit_price = max( 0.0, $edited['unit_price'] );
			if ( ! $new_product_id || $new_qty <= 0 ) {
				continue;
			}
			$product = wc_get_product( $new_product_id );
			if ( ! $product || $product->is_type( 'variable' ) ) {
				continue;
			}
			$new_item = new WC_Order_Item_Product();
			$new_item->set_product( $product );
			if ( $edited['name'] !== '' ) {
				$new_item->set_name( $edited['name'] );
			}
			$new_item->set_quantity( $new_qty );
			$new_item->set_subtotal( $new_unit_price * $new_qty );
			$new_item->set_total( $new_unit_price * $new_qty );
			$order->add_item( $new_item );
			$items_changed = true;
			++$added_products;
		}

		// If the modal asked to add products but none could be resolved, fail loudly
		// instead of silently removing the old lines and syncing an empty cart.
		$requested_new = 0;
		foreach ( $edited_items as $raw_edited ) {
			$edited = torobpay_normalize_edited_item( $raw_edited );
			if ( ! $edited['item_id'] && $edited['product_id'] && $edited['quantity'] > 0 ) {
				++$requested_new;
			}
		}
		if ( $requested_new > 0 && $added_products < 1 ) {
			torobpay_restore_order_items_snapshot( $order, $order_snapshot );
			wp_send_json_error(
				array(
					'message'       => __( 'Could not add the new product. Please select the product again.', 'torobpay-for-woocommerce' ),
					'refresh_modal' => true,
				)
			);
			return;
		}

		$new_shipping = torobpay_posted_amount( 'shipping' );
		if ( $new_shipping !== null && $new_shipping >= 0 ) {
			$shipping_items = $order->get_items( 'shipping' );
			if ( ! empty( $shipping_items ) ) {
				$shipping_item = reset( $shipping_items );
				if ( abs( floatval( $shipping_item->get_total() ) - $new_shipping ) > 0.01 ) {
					$shipping_item->set_total( $new_shipping );
					$shipping_item->save();
					$items_changed = true;
				}
			} elseif ( $new_shipping > 0 ) {
				$shipping_item_new = new WC_Order_Item_Shipping();
				$shipping_item_new->set_name( __( 'Shipping', 'torobpay-for-woocommerce' ) );
				$shipping_item_new->set_total( $new_shipping );
				$order->add_item( $shipping_item_new );
				$items_changed = true;
			}
		}

		// The discount field represents the TOTAL desired discount from all sources.
		// Strategy: if the desired discount differs from the original effective discount,
		// we take full ownership — remove existing coupon lines and any previous override fee,
		// then store the new total as a single negative override fee.
		// This makes the discount field fully predictable: whatever value the merchant sets
		// is exactly what gets applied, regardless of any existing coupons.
		$new_discount = torobpay_posted_amount( 'discount' );
		if ( $new_discount !== null && $new_discount >= 0 ) {
			if ( abs( $new_discount - $original_effective_discount ) > 0.01 ) {
				foreach ( $order->get_items( 'coupon' ) as $coupon_item_id => $coupon_item ) {
					$order->remove_item( $coupon_item_id );
				}
				if ( $existing_override_fee_id ) {
					$order->remove_item( $existing_override_fee_id );
				}
				if ( $new_discount > 0 ) {
					$fee = new WC_Order_Item_Fee();
					$fee->set_name( __( 'Discount', 'torobpay-for-woocommerce' ) );
					$fee->set_total( -floatval( $new_discount ) );
					$fee->set_tax_status( 'none' );
					$fee->add_meta_data( '_torobpay_fee_type', 'discount_override', true );
					$order->add_item( $fee );
				}
				$items_changed = true;
			}
		}

		// Manual tax override via modal removed — taxes will be recalculated
		// by WooCommerce based on items, shipping and discount.

		if ( ! $items_changed ) {
			// No actual changes — just revert status
			torobpay_cancel_edit_mode( $order, $original_status );
			wp_send_json_success( array( 'message' => __( 'No item changes were made.', 'torobpay-for-woocommerce' ) ) );
			return;
		}

		// Preserve zero-tax orders; only re-run taxes when the original order had them.
		$order->calculate_totals( torobpay_order_should_recalculate_taxes( $order ) );

		// Enforce the amount rule on the real recalculated total before touching
		// TorobPay. The modal checks the same thing, but the client can be stale or
		// bypassed; a rejected update here costs a round-trip and a confusing note.
		$new_total        = floatval( $order->get_total() );
		$amount_violation = torobpay_edit_total_violation_message( $new_total, $original_total );
		if ( null !== $amount_violation ) {
			torobpay_restore_order_items_snapshot( $order, $order_snapshot );
			wp_send_json_error(
				array(
					'message'       => $amount_violation,
					'refresh_modal' => true,
				)
			);
			return;
		}

		$order->save();

		// Sync while the order is still in the temporary on-hold/editing state so API
		// failures can be reported to the modal instead of hidden by the status hook.
		$sync_result = torobpay_sync_order_items_to_torobpay( $order_id, 'on-hold', $original_status, $order, true );
		if ( is_wp_error( $sync_result ) ) {
			torobpay_restore_order_items_snapshot( $order, $order_snapshot );
			$order->add_order_note(
				'<span class="torobpay-notice">' . esc_html__( 'TorobPay: Item update failed; WooCommerce changes were rolled back.', 'torobpay-for-woocommerce' ) . '</span>'
			);

			wp_send_json_error(
				array(
					'message'       => __( 'Sending to TorobPay failed and WooCommerce changes were rolled back: ', 'torobpay-for-woocommerce' ) . $sync_result->get_error_message(),
					'refresh_modal' => true,
				)
			);
			return;
		}

		// From here WooCommerce and TorobPay both hold the new cart — never roll
		// WC back to the pre-edit snapshot on a later failure.
		$remote_synced = true;

		// Notify other plugins only after TorobPay accepts the update. Guard against
		// third-party fatals so a listener cannot turn a successful sync into the
		// generic browser "connection error" toast.
		try {
			do_action( 'woocommerce_saved_order_items', $order_id, $edited_items ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core action.
		} catch ( Throwable $hook_error ) {
			torobpay_debug_log( 'TorobPay woocommerce_saved_order_items listener failed for order ' . $order_id . ': ' . $hook_error->getMessage() );
		}

		$order->delete_meta_data( '_torobpay_editing_items' );
		$order->delete_meta_data( '_torobpay_pre_edit_status' );
		$order->save();

		// Revert status back to original without firing a duplicate API update; the
		// remote sync has already completed and stored the latest items hash.
		remove_action( 'woocommerce_order_status_changed', 'torobpay_order_status_update_items', 99 );
		try {
			$order->set_status( $original_status, __( 'TorobPay: Items were edited and status was restored.', 'torobpay-for-woocommerce' ) );
			$order->save();
		} finally {
			add_action( 'woocommerce_order_status_changed', 'torobpay_order_status_update_items', 99, 4 );
		}

		wp_send_json_success( array( 'message' => __( 'Items were edited successfully and sent to TorobPay.', 'torobpay-for-woocommerce' ) ) );
	} catch ( Throwable $e ) {
		torobpay_debug_log( 'TorobPay save_and_sync_items failed for order ' . $order_id . ': ' . $e->getMessage() );

		if ( $remote_synced ) {
			// TorobPay already accepted the cart — keep WC items as synced and only
			// best-effort clear the edit-session flags so we do not diverge again.
			try {
				$order->delete_meta_data( '_torobpay_editing_items' );
				$order->delete_meta_data( '_torobpay_pre_edit_status' );
				if ( $original_status && $order->get_status() === 'on-hold' ) {
					remove_action( 'woocommerce_order_status_changed', 'torobpay_order_status_update_items', 99 );
					try {
						$order->set_status( $original_status, __( 'TorobPay: Items were synced with TorobPay; local finalization was incomplete.', 'torobpay-for-woocommerce' ) );
					} finally {
						add_action( 'woocommerce_order_status_changed', 'torobpay_order_status_update_items', 99, 4 );
					}
				}
				$order->save();
			} catch ( Throwable $finalize_error ) {
				torobpay_debug_log( 'TorobPay post-sync finalize failed for order ' . $order_id . ': ' . $finalize_error->getMessage() );
			}

			wp_send_json_error(
				array(
					'message'       => __( 'Items were saved in TorobPay, but WooCommerce finalization did not complete. Please refresh the page.', 'torobpay-for-woocommerce' ),
					'refresh_modal' => true,
				)
			);
			return;
		}

		if ( null !== $order_snapshot ) {
			torobpay_restore_order_items_snapshot( $order, $order_snapshot );
		}
		wp_send_json_error(
			array(
				'message'       => __( 'Error saving items. Changes were rolled back; please try again.', 'torobpay-for-woocommerce' ),
				'refresh_modal' => true,
			)
		);
	}
}

/**
 * AJAX handler: Cancel edit mode and revert order status
 * Called when user closes the modal without saving
 */
add_action( 'wp_ajax_torobpay_cancel_edit_items', 'torobpay_ajax_cancel_edit_items' );
function torobpay_ajax_cancel_edit_items() {

	check_ajax_referer( 'torobpay-edit-sync-items', 'security' );

	if ( ! current_user_can( 'edit_shop_orders' ) || empty( $_POST['order_id'] ) ) {
		wp_send_json_error( array( 'message' => __( 'An error occurred.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order_id = absint( $_POST['order_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
	if ( ! torobpay_require_order_edit_lock( $order_id ) ) {
		return;
	}

	$order = torobpay_get_fresh_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		wp_send_json_error( array( 'message' => __( 'This order is a marketplace vendor sub-order and has no independent payment token.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( $order->get_meta( '_torobpay_editing_items' ) !== 'yes' ) {
		wp_send_json_success( array( 'message' => __( 'The order was not in edit mode.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$original_status = $order->get_meta( '_torobpay_pre_edit_status' );
	if ( ! $original_status ) {
		torobpay_debug_log( 'TorobPay: _torobpay_pre_edit_status missing for order ' . $order->get_id() . ' during cancel_edit' );
		$original_status = $order->get_status();
	}

	torobpay_cancel_edit_mode( $order, $original_status );
	wp_send_json_success( array( 'message' => __( 'Edit cancelled.', 'torobpay-for-woocommerce' ) ) );
}

/**
 * AJAX handler: Search WooCommerce products for the Edit & Sync modal add-product UI
 */
add_action( 'wp_ajax_torobpay_search_products', 'torobpay_ajax_search_products' );
function torobpay_ajax_search_products() {
	check_ajax_referer( 'torobpay-edit-sync-items', 'security' );

	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
	if ( mb_strlen( $search ) < 2 ) {
		wp_send_json_success( array( 'products' => array() ) );
		return;
	}

	$data_store = WC_Data_Store::load( 'product' );
	$ids        = $data_store->search_products( $search, '', true, false, 15 );

	$products = array();
	foreach ( $ids as $product_id ) {
		$product = wc_get_product( $product_id );
		// Variable parents are not valid order line items — only simples/variations/etc.
		if ( ! $product || ! $product->is_purchasable() || $product->is_type( 'variable' ) ) {
			continue;
		}
		$products[] = array(
			'id'    => $product_id,
			'name'  => $product->get_name(),
			'sku'   => $product->get_sku(),
			'price' => floatval( $product->get_price() ),
		);
	}

	wp_send_json_success( array( 'products' => $products ) );
}

/**
 * Helper: Cancel edit mode and silently revert order status
 */
function torobpay_cancel_edit_mode( $order, $original_status ) {
	$order->delete_meta_data( '_torobpay_editing_items' );
	$order->delete_meta_data( '_torobpay_pre_edit_status' );
	$order->save();

	if ( $order->get_status() === 'on-hold' ) {
		$order->set_status( $original_status, __( 'TorobPay: Item editing was cancelled and status was restored.', 'torobpay-for-woocommerce' ) );
		$order->save();
	}
}
