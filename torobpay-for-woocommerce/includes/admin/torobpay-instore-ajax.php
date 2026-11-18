<?php
/**
 * AJAX handlers for in-store (POS) order create, preview, search, and QR reshow.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability and feature-flag gate shared by in-store AJAX handlers.
 *
 * Call after the handler's nonce check.
 *
 * @param bool $force_refresh Re-fetch shop features (create order and Show QR).
 * @return bool
 */
function torobpay_instore_ajax_authorize( $force_refresh = false ) {
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'torobpay-for-woocommerce' ) ) );
		return false;
	}

	if ( ! function_exists( 'torobpay_merchant_has_instore_payments' ) || ! torobpay_merchant_has_instore_payments( $force_refresh ) ) {
		wp_send_json_error(
			array(
				'message'         => __( 'In-store selling is not available for this shop.', 'torobpay-for-woocommerce' ),
				'instore_enabled' => false,
			)
		);
		return false;
	}

	return true;
}

/**
 * Parse the in-store items JSON/array payload.
 *
 * @return array<int, array{product_id:int,quantity:int}>
 */
function torobpay_parse_instore_items_payload() {
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

	$sanitized = array();
	foreach ( $items as $item ) {
		$item       = is_array( $item ) ? $item : array();
		$product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
		$quantity   = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;
		if ( $product_id <= 0 || $quantity <= 0 ) {
			continue;
		}
		$sanitized[] = array(
			'product_id' => $product_id,
			'quantity'   => $quantity,
		);
	}

	return function_exists( 'torobpay_aggregate_instore_items' )
		? torobpay_aggregate_instore_items( $sanitized )
		: $sanitized;
}

/**
 * Read optional coupon from the request.
 *
 * @return array{coupon:string}
 */
function torobpay_parse_instore_pricing_fields() {
	$coupon_raw = isset( $_POST['coupon'] ) ? wp_unslash( $_POST['coupon'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below; caller verifies nonce.
	if ( function_exists( 'wc_format_coupon_code' ) ) {
		$coupon = wc_format_coupon_code( $coupon_raw );
	} else {
		$coupon = sanitize_text_field( $coupon_raw );
	}

	return array(
		'coupon' => is_string( $coupon ) ? $coupon : '',
	);
}

/**
 * Stop a WooCommerce order item from writing to the database.
 *
 * WC_Data::save() is a no-op when the data store is empty, so later
 * $item->save() calls from add_product(), coupons, and tax lines cannot
 * insert orphaned rows with order_id 0.
 *
 * @param object $item Order item.
 * @return void
 */
function torobpay_instore_preview_detach_item_data_store( $item ) {
	if ( ! is_object( $item ) || ! class_exists( 'WC_Data', false ) || ! $item instanceof WC_Data ) {
		return;
	}

	try {
		$property = new ReflectionProperty( 'WC_Data', 'data_store' );
		$property->setAccessible( true );
		$property->setValue( $item, null );
	} catch ( ReflectionException $e ) {
		return;
	}
}

/**
 * In-memory WC_Order for in-store totals preview. Never persists the order
 * or its line items.
 *
 * Overriding WC_Order::save() is not enough: WooCommerce add_product()
 * (and coupon/tax lines) call save() on each item with order id 0.
 *
 * @return WC_Order
 */
function torobpay_instore_new_preview_order() {
	return new class() extends WC_Order {
		/**
		 * Next in-memory item id. Coupons key discounts by item id.
		 *
		 * @var int
		 */
		private $preview_item_id = 0;

		/**
		 * Never persist the preview order.
		 *
		 * @return int
		 */
		public function save() {
			return $this->get_id();
		}

		/**
		 * Never persist items via the order data store.
		 *
		 * @return void
		 */
		protected function save_items() {}

		/**
		 * Clear in-memory items only. Parent may DELETE ... WHERE order_id = 0.
		 *
		 * @param string|null $type Item type.
		 * @return void
		 */
		public function remove_order_items( $type = null ) {
			if ( ! empty( $type ) ) {
				$group = $this->type_to_group( $type );
				if ( $group ) {
					unset( $this->items[ $group ] );
				}
			} else {
				$this->items = array();
			}
			$this->items_to_delete = array();
			if ( property_exists( $this, 'item_types_to_bulk_delete' ) ) {
				$this->item_types_to_bulk_delete = array();
			}
			if ( property_exists( $this, 'bulk_delete_all_items_pending' ) ) {
				$this->bulk_delete_all_items_pending = false;
			}
		}

		/**
		 * Keep items in memory with unique ids and no data store.
		 *
		 * @param WC_Order_Item $item Order item.
		 * @return false|void
		 */
		public function add_item( $item ) {
			if ( $item instanceof WC_Order_Item ) {
				if ( ! $item->get_id() ) {
					$item->set_id( ++$this->preview_item_id );
				}
				$item->set_order_id( 0 );
				torobpay_instore_preview_detach_item_data_store( $item );
			}
			return parent::add_item( $item );
		}

		/**
		 * Add a product line without WC_Abstract_Order::add_product()'s item save().
		 *
		 * @param WC_Product $product Product.
		 * @param int        $qty     Quantity.
		 * @param array      $args    Unused; kept for signature compatibility.
		 * @return int In-memory item id.
		 */
		public function add_product( $product, $qty = 1, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			if ( ! $product ) {
				return 0;
			}

			$qty  = max( 1, (int) $qty );
			$item = new WC_Order_Item_Product();
			$item->set_product( $product );
			$item->set_quantity( $qty );

			$total = function_exists( 'wc_get_price_excluding_tax' )
				? wc_get_price_excluding_tax(
					$product,
					array(
						'qty'   => $qty,
						'order' => $this,
					)
				)
				: ( (float) $product->get_price() * $qty );

			$item->set_subtotal( $total );
			$item->set_total( $total );

			if ( method_exists( $item, 'set_backorder_meta' ) ) {
				$item->set_backorder_meta();
			}

			$this->add_item( $item );
			return $item->get_id();
		}
	};
}

/**
 * Build a non-persisting preview order for totals.
 *
 * Line taxes follow each product's WooCommerce tax class and tax status.
 *
 * @param array  $items  Parsed items.
 * @param string $coupon Coupon code.
 * @return array|\WP_Error
 */
function torobpay_instore_build_preview_totals( $items, $coupon ) {
	if ( empty( $items ) ) {
		return array(
			'subtotal' => 0,
			'discount' => 0,
			'tax'      => 0,
			'total'    => 0,
		);
	}

	if ( function_exists( 'torobpay_aggregate_instore_items' ) ) {
		$items = torobpay_aggregate_instore_items( $items );
	}

	$preview = torobpay_instore_new_preview_order();

	if ( method_exists( $preview, 'set_currency' ) ) {
		$preview->set_currency( get_woocommerce_currency() );
	}

	$country = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_base_country() : '';
	if ( $country ) {
		$preview->set_billing_country( $country );
		$preview->set_shipping_country( $country );
	}

	foreach ( $items as $row ) {
		$product = wc_get_product( $row['product_id'] );
		if ( ! $product || ! $product->is_purchasable() || $product->is_type( 'variable' ) ) {
			return new WP_Error( 'torobpay_instore_bad_product', __( 'One of the selected products is not available.', 'torobpay-for-woocommerce' ) );
		}

		$sellable = torobpay_get_product_instore_sellable( $product );
		if ( empty( $sellable['ok'] ) ) {
			return new WP_Error(
				'torobpay_instore_no_stock',
				sprintf(
					/* translators: %s: product name */
					__( '%s is not available for in-store sale.', 'torobpay-for-woocommerce' ),
					$product->get_name()
				)
			);
		}

		if ( null !== $sellable['qty'] && $row['quantity'] > (int) $sellable['qty'] ) {
			return new WP_Error(
				'torobpay_instore_qty',
				sprintf(
					/* translators: 1: product name, 2: available quantity */
					__( '%1$s only has %2$d available for in-store sale.', 'torobpay-for-woocommerce' ),
					$product->get_name(),
					(int) $sellable['qty']
				)
			);
		}

		$preview->add_product( $product, $row['quantity'] );
	}

	if ( $coupon !== '' ) {
		$applied = $preview->apply_coupon( $coupon );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}
		if ( false === $applied ) {
			return new WP_Error( 'torobpay_instore_coupon', __( 'The coupon could not be applied.', 'torobpay-for-woocommerce' ) );
		}
	}

	$preview->calculate_totals( true );

	return array(
		'subtotal' => (float) $preview->get_subtotal(),
		'discount' => (float) $preview->get_discount_total(),
		'tax'      => (float) $preview->get_total_tax(),
		'total'    => (float) $preview->get_total(),
	);
}

add_action( 'wp_ajax_torobpay_search_instore_products', 'torobpay_ajax_search_instore_products' );
/**
 * Search catalog products that can be sold in-store.
 *
 * @return void
 */
function torobpay_ajax_search_instore_products() {
	check_ajax_referer( 'torobpay-instore-order', 'security' );
	if ( ! torobpay_instore_ajax_authorize() ) {
		return;
	}

	$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
	if ( function_exists( 'mb_strlen' ) ? mb_strlen( $search ) < 2 : strlen( $search ) < 2 ) {
		wp_send_json_success( array( 'products' => array() ) );
		return;
	}

	$data_store = WC_Data_Store::load( 'product' );
	$ids        = $data_store->search_products( $search, '', true, false, 15 );

	$products = array();
	foreach ( $ids as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() || $product->is_type( 'variable' ) ) {
			continue;
		}

		$sellable = torobpay_get_product_instore_sellable( $product );
		if ( empty( $sellable['ok'] ) ) {
			continue;
		}

		$price      = floatval( $product->get_price() );
		$products[] = array(
			'id'              => (int) $product_id,
			'name'            => $product->get_name(),
			'sku'             => $product->get_sku(),
			'price'           => $price,
			'price_formatted' => function_exists( 'wc_price' )
				? html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES, 'UTF-8' )
				: (string) $price,
			'sellable_qty'    => $sellable['qty'],
		);
	}

	wp_send_json_success( array( 'products' => $products ) );
}

add_action( 'wp_ajax_torobpay_preview_instore_order', 'torobpay_ajax_preview_instore_order' );
/**
 * Preview in-store order totals without creating an order.
 *
 * @return void
 */
function torobpay_ajax_preview_instore_order() {
	check_ajax_referer( 'torobpay-instore-order', 'security' );
	if ( ! torobpay_instore_ajax_authorize() ) {
		return;
	}

	$items  = torobpay_parse_instore_items_payload();
	$fields = torobpay_parse_instore_pricing_fields();
	$result = torobpay_instore_build_preview_totals( $items, $fields['coupon'] );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		return;
	}

	wp_send_json_success( $result );
}

add_action( 'wp_ajax_torobpay_create_instore_order', 'torobpay_ajax_create_instore_order' );
/**
 * Create a pending in-store order and return paymentPageUrl.
 *
 * @return void
 */
function torobpay_ajax_create_instore_order() {
	check_ajax_referer( 'torobpay-instore-order', 'security' );
	if ( ! torobpay_instore_ajax_authorize( true ) ) {
		return;
	}

	$phone_raw = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
	$phone     = function_exists( 'torobpay_mobile' ) ? torobpay_mobile( $phone_raw ) : false;
	if ( ! $phone ) {
		wp_send_json_error( array( 'message' => __( 'Enter a valid mobile number.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$name_raw = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$names    = function_exists( 'torobpay_parse_instore_customer_name' )
		? torobpay_parse_instore_customer_name( $name_raw )
		: array(
			'first_name' => $name_raw,
			'last_name'  => '',
		);

	$items         = torobpay_parse_instore_items_payload();
	$amount_raw    = isset( $_POST['amount'] ) ? wp_unslash( $_POST['amount'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed below; nonce checked above.
	$custom_amount = function_exists( 'torobpay_parse_instore_custom_amount' )
		? torobpay_parse_instore_custom_amount( $amount_raw )
		: 0.0;

	if ( empty( $items ) && $custom_amount <= 0 ) {
		wp_send_json_error( array( 'message' => __( 'Add at least one product or enter a total amount.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$fields = torobpay_parse_instore_pricing_fields();
	if ( ! empty( $items ) ) {
		$preview = torobpay_instore_build_preview_totals( $items, $fields['coupon'] );
		if ( is_wp_error( $preview ) ) {
			wp_send_json_error( array( 'message' => $preview->get_error_message() ) );
			return;
		}
	}

	$order = wc_create_order(
		array(
			'status'      => 'pending',
			'customer_id' => 0,
			'created_via' => 'torobpay_instore',
		)
	);

	if ( is_wp_error( $order ) || ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Could not create the order.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order->set_payment_method( 'WC_Gateway_TorobPay' );
	$order->set_payment_method_title( __( 'TorobPay', 'torobpay-for-woocommerce' ) );
	$order->set_billing_phone( $phone_raw );
	if ( $names['first_name'] !== '' ) {
		$order->set_billing_first_name( $names['first_name'] );
	}
	if ( $names['last_name'] !== '' ) {
		$order->set_billing_last_name( $names['last_name'] );
	}

	$country = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_base_country() : '';
	if ( $country ) {
		$order->set_billing_country( $country );
		$order->set_shipping_country( $country );
	}

	$order->update_meta_data( '_torobpay_instore', 'yes' );

	if ( ! empty( $items ) ) {
		foreach ( $items as $row ) {
			$product = wc_get_product( $row['product_id'] );
			if ( ! $product ) {
				$order->delete( true );
				wp_send_json_error( array( 'message' => __( 'One of the selected products is not available.', 'torobpay-for-woocommerce' ) ) );
				return;
			}
			$order->add_product( $product, $row['quantity'] );
		}

		if ( $fields['coupon'] !== '' ) {
			$applied = $order->apply_coupon( $fields['coupon'] );
			if ( is_wp_error( $applied ) || false === $applied ) {
				$order->delete( true );
				$message = is_wp_error( $applied ) ? $applied->get_error_message() : __( 'The coupon could not be applied.', 'torobpay-for-woocommerce' );
				wp_send_json_error( array( 'message' => $message ) );
				return;
			}
		}
	} else {
		torobpay_add_instore_custom_amount_item( $order, $custom_amount );
		if ( ! $order->get_items() ) {
			$order->delete( true );
			wp_send_json_error( array( 'message' => __( 'Could not create the order.', 'torobpay-for-woocommerce' ) ) );
			return;
		}
	}

	$order->calculate_totals( ! empty( $items ) );
	if ( empty( $items ) ) {
		$order->set_total( $custom_amount );
	}
	$order->save();

	$prepared = torobpay_prepare_instore_order_stock( $order );
	if ( is_wp_error( $prepared ) ) {
		$order->delete( true );
		wp_send_json_error( array( 'message' => $prepared->get_error_message() ) );
		return;
	}

	$payment_url = torobpay_request_instore_payment_url( $order );
	if ( is_wp_error( $payment_url ) ) {
		wp_send_json_error(
			array(
				'message'   => $payment_url->get_error_message(),
				'order_id'  => $order->get_id(),
				'can_retry' => true,
			)
		);
		return;
	}

	wp_send_json_success(
		array(
			'order_id'          => $order->get_id(),
			'payment_page_url'  => $payment_url,
			'total'             => $order->get_formatted_order_total(),
		)
	);
}

add_action( 'wp_ajax_torobpay_get_instore_payment_qr', 'torobpay_ajax_get_instore_payment_qr' );
/**
 * Pre-flight TorobPay status, then return or mint a paymentPageUrl.
 *
 * @return void
 */
function torobpay_ajax_get_instore_payment_qr() {
	check_ajax_referer( 'torobpay-instore-order', 'security' );
	if ( ! torobpay_instore_ajax_authorize( true ) ) {
		return;
	}

	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	$order    = $order_id && function_exists( 'torobpay_get_fresh_order' )
		? torobpay_get_fresh_order( $order_id )
		: ( $order_id ? wc_get_order( $order_id ) : false );

	if ( ! $order || ! torobpay_is_instore_order( $order ) ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( ! torobpay_is_torobpay_payment_method( $order->get_payment_method() ) ) {
		wp_send_json_error( array( 'message' => __( 'This order does not belong to TorobPay.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$resolved = torobpay_refresh_instore_payment_watch_state( $order );
	$order    = $resolved['order'];
	$state    = $resolved['state'];
	$messages = torobpay_instore_watch_messages();
	$payload  = array(
		'order_id'         => $order->get_id(),
		'state'            => $state,
		'message'          => isset( $messages[ $state ] ) ? $messages[ $state ] : '',
		'can_retry'        => ( 'failed' === $state ) && torobpay_instore_order_allows_payment_qr( $order ),
		'order_status'     => $order->get_status(),
		'total'            => $order->get_formatted_order_total(),
		'payment_page_url' => '',
	);

	if ( in_array( $state, array( 'paid', 'closed' ), true ) ) {
		wp_send_json_success( $payload );
		return;
	}

	$payment_url = torobpay_resolve_instore_payment_page_url( $order, $state );
	if ( is_wp_error( $payment_url ) ) {
		wp_send_json_error( array( 'message' => $payment_url->get_error_message() ) );
		return;
	}

	$payload['payment_page_url'] = $payment_url;
	$payload['state']            = 'pending';
	$payload['message']          = $messages['pending'];
	$payload['can_retry']        = false;
	wp_send_json_success( $payload );
}

add_action( 'wp_ajax_torobpay_poll_instore_payment', 'torobpay_ajax_poll_instore_payment' );
/**
 * Poll WooCommerce for in-store payment success or failure.
 *
 * Local only. A missed callback never VERIFYs, so TorobPay status stays PENDING
 * until it auto-cancels; a remote poll cannot detect a successful payment.
 * Capture is recorded on the order by the customer return or the show-QR
 * preflight. Only state=OK is success. state=FAILED, or no state, caches FAILED so this poll can show retry.
 *
 * @return void
 */
function torobpay_ajax_poll_instore_payment() {
	check_ajax_referer( 'torobpay-instore-order', 'security' );
	if ( ! torobpay_instore_ajax_authorize() ) {
		return;
	}

	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	$order    = $order_id && function_exists( 'torobpay_get_fresh_order' )
		? torobpay_get_fresh_order( $order_id )
		: ( $order_id ? wc_get_order( $order_id ) : false );

	if ( ! $order || ! torobpay_is_instore_order( $order ) ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( ! torobpay_is_torobpay_payment_method( $order->get_payment_method() ) ) {
		wp_send_json_error( array( 'message' => __( 'This order does not belong to TorobPay.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$state    = torobpay_get_instore_payment_watch_state( $order );
	$messages = torobpay_instore_watch_messages();

	wp_send_json_success(
		array(
			'state'        => $state,
			'message'      => isset( $messages[ $state ] ) ? $messages[ $state ] : '',
			'can_retry'    => ( 'failed' === $state ) && torobpay_instore_order_allows_payment_qr( $order ),
			'order_status' => $order->get_status(),
		)
	);
}
