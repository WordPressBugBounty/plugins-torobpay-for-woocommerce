<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/torobpay-error-codes.php';

/**
 * TorobPayAPI class.
 *
 * The parent class for the different requests.
 */
class TorobPayAPI {

	/**
	 * Send sales tax as separate item (US merchants).
	 *
	 * @var bool
	 */
	private $settings = false;

	/**
	 * Optional per-call HTTP timeout override (seconds).
	 *
	 * When set, nested requests (e.g. oauth while building Bearer headers)
	 * inherit the same budget — important for eligibility on checkout.
	 *
	 * @var int|null
	 */
	private $http_timeout = null;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$settings       = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', array() );
		$this->settings = is_array( $settings ) ? $settings : array();
	}

	public function get_settings() {
		return apply_filters( 'torobpay_settings', $this->settings );
	}

	/**
	 * Gets TorobPay API URL base.
	 */
	public function get_api_base_url() {
		/*
		 * If the plugin has just been activated the gateway settings may not be
		 * in the database yet.  Accessing them as an array when they are still
		 * false triggers a fatal TypeError on PHP 8+.  To avoid taking the
		 * whole admin down we fall back to TorobPay's public API base when the
		 * option is missing or malformed.
		 */

		// Make sure we have an array with the expected key.
		if ( ! is_array( $this->settings ) || empty( $this->settings['base_url'] ) ) {
			return 'https://cpg.torobpay.com/';
		}

		$base = $this->settings['base_url'];

		// Normalise so that the returned string always ends with a slash.
		return substr( $base, -1 ) === '/' ? $base : $base . '/';
	}

	/**
	 * Gets TorobPay API request basic token.
	 *
	 * @return array
	 */
	protected function get_request_basic_token() {
		// Gracefully handle cases where settings are missing or malformed.
		if ( ! is_array( $this->settings ) || empty( $this->settings['client_id'] ) || empty( $this->settings['client_secret'] ) ) {
			return array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			);
		}

		$token = 'Basic ' . base64_encode( $this->settings['client_id'] . ':' . str_replace( 'amp;', '', $this->settings['client_secret'] ) );
		return array(
			'Authorization' => $token,
			'Content-Type'  => 'application/x-www-form-urlencoded',
		);
	}

	/**
	 * Gets TorobPay API request bearer token.
	 *
	 * @return array|false
	 */
	protected function get_request_bearer_token() {
		$bearer_token = get_transient( 'torobpay_bearer_token' );
		if ( $bearer_token ) {
			// Backfill the persistent flag in case it was never written (e.g. first request
			// after plugin update). update_option() is a no-op when the value is unchanged.
			if ( ! get_option( 'torobpay_auth_ok' ) ) {
				update_option( 'torobpay_auth_ok', true );
			}
			return [
				'Authorization' => 'Bearer ' . $bearer_token,
				'Content-Type'  => 'application/json',
			];
		}

		if ( ! is_array( $this->settings ) || empty( $this->settings['client_username'] ) || empty( $this->settings['client_password'] ) ) {
			return false;
		}

		$response = $this->request(
			'api/online/v1/oauth/token',
			'POST',
			'Basic',
			[
				'grant_type' => 'password',
				'scope'      => 'online-merchant',
				'username'   => $this->settings['client_username'],
				'password'   => str_replace( 'amp;', '', $this->settings['client_password'] ?? '' ),
			]
		);

		if ( is_wp_error( $response ) ) {
			update_option(
				'torobpay_auth_error',
				[
					'type'      => 'wp_error',
					'message'   => $response->get_error_message(),
					'timestamp' => time(),
				]
			);
			delete_option( 'torobpay_auth_ok' );
			return false;
		}

		if ( is_array( $response ) && isset( $response['errorData']['errorCode'] ) ) {
			$error_code    = $response['errorData']['errorCode'];
			$error_message = isset( $response['errorData']['message'] ) ? $response['errorData']['message'] : '';

			$auth_error = [
				'type'      => 'api_error',
				'errorCode' => $error_code,
				'message'   => $error_message,
				'timestamp' => time(),
			];

			// Catalogued codes carry their own merchant-facing copy; see
			// includes/torobpay-error-codes.php.
			$definition = torobpay_get_api_error_definition( 'auth', $error_code );
			if ( $definition ) {
				$auth_error['notification_title'] = $definition['title'];
				$auth_error['friendly_message']   = $definition['message'];
				$auth_error['tier']               = $definition['tier'];
				$auth_error['action']             = $definition['action'];
			} else {
				$auth_error['notification_title'] = __( 'TorobPay authentication error', 'torobpay-for-woocommerce' );
				$auth_error['friendly_message']   = sprintf(
					/* translators: %s: API error message */
					__( 'Authentication error: %s', 'torobpay-for-woocommerce' ),
					$error_message
				);
			}

			update_option( 'torobpay_auth_error', $auth_error );
			delete_option( 'torobpay_auth_ok' );
			return false;
		}

		if ( ! is_array( $response ) || ! isset( $response['access_token'] ) ) {
			update_option(
				'torobpay_auth_error',
				[
					'type'      => 'invalid_response',
					'message'   => __( 'Invalid response. Access token was not received.', 'torobpay-for-woocommerce' ),
					'timestamp' => time(),
				]
			);
			delete_option( 'torobpay_auth_ok' );
			return false;
		}

		// Success - clear any previous auth errors and mark auth as OK
		delete_option( 'torobpay_auth_error' );
		if ( ! get_option( 'torobpay_auth_ok' ) ) {
			update_option( 'torobpay_auth_ok', true );
		}

		$bearer_token = $response['access_token'];
		$ttl          = isset( $response['expires_in'] ) ? $response['expires_in'] : 3300; // Default to 55 minutes if expires_in not present
		set_transient( 'torobpay_bearer_token', $bearer_token, $ttl );

		return array(
			'Authorization' => 'Bearer ' . $bearer_token,
			'Content-Type'  => 'application/json',
		);
	}

	/**
	 * Ensure bearer token is available (attempts to fetch if not cached)
	 * Public wrapper for get_request_bearer_token used for authentication checks
	 *
	 * @return bool True if bearer token exists or was successfully obtained
	 */
	public function ensure_bearer_token() {
		$headers = $this->get_request_bearer_token();
		return is_array( $headers ) && ! empty( $headers['Authorization'] );
	}

	/**
	 * @return array
	 */
	private function get_eligibility_request_context() {
		if ( ! function_exists( 'torobpay_get_payment_price_parity' ) ) {
			require_once dirname( TOROBPAY_PLUGIN_FILE ) . '/includes/torobpay-payment-price-parity.php';
		}

		$settings = is_array( $this->settings ) ? $this->settings : [];

		return [
			'widget_is_active'          => get_option( 'torobpay_widget_enabled', 'yes' ) === 'yes' ? 'true' : 'false',
			'badge_is_active'           => get_option( 'torobpay_badge_enabled', 'yes' ) === 'yes' ? 'true' : 'false',
			'marquee_bar_is_active'     => get_option( 'torobpay_marquee_enabled', 'yes' ) === 'yes' ? 'true' : 'false',
			'top_banner_is_active'      => get_option( 'torobpay_topbar_enabled', 'yes' ) === 'yes' ? 'true' : 'false',
			'slider_is_active'          => get_option( 'torobpay_slider_enabled', 'yes' ) === 'yes' ? 'true' : 'false',
			'payment_price_parity'      => torobpay_get_payment_price_parity(),
			'default_gateway_is_active' => isset( $settings['default_gateway'] ) && $settings['default_gateway'] === 'yes' ? 'true' : 'false',
		];
	}

	/**
	 * @param float $amount
	 * @param array $extra_params
	 *
	 * @return array|WP_Error
	 */
	public function is_merchant_eligible( $amount, $extra_params = [] ) {
		if ( ! is_array( $this->settings ) || empty( $this->settings['client_username'] ) || empty( $this->settings['client_password'] ) || empty( $this->settings['client_id'] ) || empty( $this->settings['client_secret'] ) || empty( $this->settings['base_url'] ) ) {
			return new WP_Error( '401', __( 'TorobPay payment gateway plugin settings error', 'torobpay-for-woocommerce' ) );
		}

		$args = array_merge(
			[ 'amount' => $amount ],
			is_array( $extra_params ) ? $extra_params : [],
			$this->get_eligibility_request_context()
		);

		// Short timeout (incl. nested oauth): must not block checkout during outages.
		$this->http_timeout = 5;
		try {
			$response = $this->request( 'api/online/offer/v1/eligible', 'GET', 'Bearer', $args );
		} finally {
			$this->http_timeout = null;
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'401',
				sprintf(
					/* translators: %s: underlying error message */
					__( 'Gateway error %s', 'torobpay-for-woocommerce' ),
					$response->get_error_message()
				)
			);
		} else {
			return $response;
		}
	}

	public function get_payment_token( WC_Order $order, $transactionId ) {
		$order_id = $order->get_id();
		$amount   = torobpay_get_order_amount_in_iranian_rial( $order );

		do_action( 'torobpay_gateway_payment', $order_id );

		$callback_base_url = WC()->api_request_url( strtolower( 'WC_Gateway_TorobPay' ) );
		$checkout_url      = wc_get_checkout_url();
		$checkout_scheme   = wp_parse_url( $checkout_url, PHP_URL_SCHEME );
		$callback_scheme   = wp_parse_url( $callback_base_url, PHP_URL_SCHEME );

		// Some shops run behind reverse proxies where callback base can be generated as http.
		if ( ! empty( $checkout_scheme ) && ! empty( $callback_scheme ) && strtolower( $checkout_scheme ) !== strtolower( $callback_scheme ) ) {
			$callback_base_url = set_url_scheme( $callback_base_url, $checkout_scheme );
		}

		$callBackUrl = add_query_arg(
			array(
				'wc_order'      => $order_id,
				'key'           => $order->get_order_key(),
				'transactionId' => $transactionId,
			),
			$callback_base_url
		);

		$data = array(
			"amount"               => $amount,
			"paymentMethodTypeDto" => 'INSTALLMENT',
			"returnURL"            => $callBackUrl,
			"transactionId"        => $transactionId,
		);

		if ( function_exists( 'torobpay_is_instore_order' ) && torobpay_is_instore_order( $order ) ) {
			$data['order_journey'] = 'INSTORE';
		}

		$phones = torobpay_token_phone_fields( $order );
		if ( ! empty( $phones['mobile'] ) ) {
			$data['mobile'] = $phones['mobile'];
		}
		$data['registration_phone_number'] = $phones['registration_phone_number'];

		// Customer full name
		$first_name                 = $order->get_shipping_first_name() ?: $order->get_billing_first_name();
		$last_name                  = $order->get_shipping_last_name() ?: $order->get_billing_last_name();
		$data['customer_full_name'] = ( $first_name || $last_name ) ? trim( $first_name . ' ' . $last_name ) : '';

		// Province and city as separate fields
		$data['province'] = $order->get_shipping_state() ?: $order->get_billing_state() ?: '';
		$data['city']     = $order->get_shipping_city() ?: $order->get_billing_city() ?: '';

		// Address (street only, without state/city)
		$address_parts   = array_filter(
			array(
				$order->get_shipping_address_1(),
				$order->get_shipping_address_2(),
			)
		);
		$data['address'] = ! empty( $address_parts ) ? implode( '، ', $address_parts ) : '';

		// Postal code
		$postcode           = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
		$data['postalCode'] = $postcode ?: '';

		$cart_list = $this->build_cart_list( $order );
		if ( is_wp_error( $cart_list ) ) {
			return $cart_list;
		}
		$data['cartList'][]     = $cart_list;
		$order_data             = $order->get_data();
		$data['discountAmount'] = ! empty( $order_data['discount_total'] ) ? torobpay_get_amount_by_currency( $order_data['discount_total'], $order->get_currency() ) : 0;

		$response = $this->request( 'api/online/payment/v1/token', 'POST', 'Bearer', $data );

		// Kept off the wire. The gateway compares it with response.amount before redirecting.
		if ( is_array( $response ) && ! empty( $response['successful'] ) ) {
			$response['_torobpay_requested_amount'] = (int) $amount;
		}

		return $response;
	}

	private function get_product_categories( $product_id ) {
		$terms = get_the_terms( $product_id, 'product_cat' );

		$commission_type = null;
		$category_name   = null;

		if ( is_wp_error( $terms ) ) {
			return [ $commission_type, $category_name ];
		}

		if ( empty( $terms ) ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return [ $commission_type, $category_name ];
			}
			$product_parent_id = $product->get_parent_id();
			if ( $product_parent_id ) {
				$terms = get_the_terms( $product_parent_id, 'product_cat' );
			}
		}

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return [ $commission_type, $category_name ];
		}

		$main_category       = null;
		$term_commision_type = '';
		$term_name           = '';

		foreach ( $terms as $term ) {
			if ( $term->parent == 0 ) {
				$main_category = $term;
			}
			$term_commision_type = get_term_meta( $term->term_id, 'trb_commission_type', true );
			$term_name           = $term->name;
		}

		$main_category_commision_type = '';
		if ( ! is_null( $main_category ) ) {
			$main_category_commision_type = get_term_meta( $main_category->term_id, 'trb_commission_type', true );
		}

		$commission_type = $term_commision_type ?: $main_category_commision_type ?: '';
		$category_name   = $term_name ?: ( $main_category ? $main_category->name : '' );

		return [ $commission_type, $category_name ];
	}

	/**
	 * Build TorobPay cart payload from order line items.
	 *
	 * @param WC_Order $order
	 * @return array|WP_Error
	 */
	private function build_cart_list( WC_Order $order ) {
		$order_currency = $order->get_currency();
		$cart_list      = [
			'cartId'      => $order->get_id(),
			'totalAmount' => torobpay_get_order_amount_in_iranian_rial( $order ),
		];
		$items          = array();

		/** @var WC_Order_Item_Product $item */
		foreach ( $order->get_items() as $item_id => $item ) {

			/** @var WC_Product|false|null $product */
			$product = $item->get_product();

			// Amount-only in-store sales have no catalog product. Send a synthetic
			// cart row so TorobPay still receives required cartItems.
			if ( ! $product && function_exists( 'torobpay_is_instore_custom_amount_item' ) && torobpay_is_instore_custom_amount_item( $item ) ) {
				$qty         = max( 1, (int) $item->get_quantity() );
				$item_params = array(
					'name'       => $item->get_name() ? $item->get_name() : __( 'In-store sale', 'torobpay-for-woocommerce' ),
					'count'      => $qty,
					'amount'     => torobpay_get_amount_by_currency( $item->get_subtotal() / $qty, $order_currency ),
					'id'         => 'instore-amount-' . $order->get_id() . '-' . $item_id,
					'category'   => __( 'In-store', 'torobpay-for-woocommerce' ),
					'product_id' => 'instore-amount-' . $order->get_id(),
					'page_url'   => '',
				);
				if ( torobpay_has_comission() ) {
					$item_params['commissionType'] = 0;
				}
				$items[] = $item_params;
				continue;
			}

			// Existing order lines can outlive their catalog product (deleted/trashed
			// after purchase). Fail loudly — never invent a fake cart row.
			if ( ! $product ) {
				$label = $item->get_name() ?: ( '#' . ( $item->get_product_id() ?: $item_id ) );
				return new WP_Error(
					'torobpay_missing_product',
					sprintf(
						/* translators: %s: product name or SKU */
						__( 'Product "%s" was not found in the store (deleted or inactive). Item sync is not possible.', 'torobpay-for-woocommerce' ),
						$label
					)
				);
			}

			list($commission_type, $category_name) = $this->get_product_categories( $product->get_id() );

			$parent_id  = $product->get_parent_id();
			$product_id = $parent_id ? $parent_id : $product->get_id();

			$item_params = array(
				"name"       => $item->get_name(),
				"count"      => $item->get_quantity(),
				"amount"     => torobpay_get_amount_by_currency( $item->get_subtotal() / max( 1, $item->get_quantity() ), $order_currency ),
				"id"         => $product->get_id(),
				"category"   => $category_name,
				"product_id" => $product_id,
				"page_url"   => get_permalink( $product_id ),
			);

			if ( torobpay_has_comission() ) {
				$item_params['commissionType'] = $commission_type;
			}

			$items[] = $item_params;
		}
		$cart_list['cartItems'] = $items;

		$order_data = $order->get_data();

		// Sum tax directly from WC_Order_Item_Tax rows — these are always up-to-date
		// because WC admin and our save handler both write here. The order-level
		// total_tax property on the in-memory object can be stale when read immediately
		// after a status-change hook (before WC recalculates its cached totals).
		$raw_tax = 0.0;
		foreach ( $order->get_items( 'tax' ) as $tax_item ) {
			$raw_tax += floatval( $tax_item->get_tax_total() ) + floatval( $tax_item->get_shipping_tax_total() );
		}

		$shipping_total    = floatval( $order_data['shipping_total'] );
		$has_shipping_line = count( $order->get_items( 'shipping' ) ) > 0;

		$cart_list['taxAmount']          = $raw_tax > 0 ? torobpay_get_amount_by_currency( $raw_tax, $order_currency ) : 0;
		$cart_list['shippingAmount']     = $shipping_total > 0 ? torobpay_get_amount_by_currency( $shipping_total, $order_currency ) : 0;
		$cart_list['isShipmentIncluded'] = $has_shipping_line || $shipping_total > 0;
		$cart_list['isTaxIncluded']      = $raw_tax > 0;

		return $cart_list;
	}

	public function verify( $order_torobpay_token ) {
		return $this->request( 'api/online/payment/v1/verify', 'POST', 'Bearer', [ 'paymentToken' => $order_torobpay_token ] );
	}

	/**
	 * Retrieve a list of orders from TorobPay API
	 *
	 * @param int $page Current page number
	 * @param int $page_size Number of items per page
	 * @return array|WP_Error API response with paginated order list
	 */
	public function list_orders_simple( $page = 1, $page_size = 20 ) {
		$params = array(
			'page'      => $page,
			'page_size' => $page_size,
		);

		return $this->request( 'api/online/payment/v1/list', 'GET', 'Bearer', $params );
	}

	/**
	 * Batch verify multiple payment tokens using the list endpoint
	 *
	 * @param array $payment_tokens Array of payment tokens to verify
	 * @return array Associative array with payment token as key and payment info as value
	 */
	public function batch_verify_payments( $payment_tokens ) {
		if ( empty( $payment_tokens ) ) {
			return array();
		}

		$payments_info = array();
		$page          = 1;
		$page_size     = 100; // Use larger page size for efficiency
		$found_tokens  = array();

		// Continue fetching until we find all our tokens or reach the end
		while ( count( $found_tokens ) < count( $payment_tokens ) ) {
			$response = $this->list_orders_simple( $page, $page_size );

			if ( is_wp_error( $response ) ) {
				break;
			}

			if ( ! isset( $response['successful'] ) || ! $response['successful'] ) {
				break;
			}

			if ( ! isset( $response['response']['results'] ) || empty( $response['response']['results'] ) ) {
				break;
			}

			$results = $response['response']['results'];

			foreach ( $results as $payment ) {
				$payment_token = $payment['paymentToken'];

				if ( in_array( $payment_token, $payment_tokens ) && ! isset( $found_tokens[ $payment_token ] ) ) {
					$payments_info[ $payment_token ] = $payment;
					$found_tokens[ $payment_token ]  = true;
				}
			}

			if ( ! isset( $response['response']['next'] ) || empty( $response['response']['next'] ) ) {
				break;
			}

			++$page;

			// Safety check to avoid infinite loop
			if ( $page > 100 ) {
				break;
			}
		}

		return $payments_info;
	}

	public function settle( $order_torobpay_token ) {
		return $this->request( 'api/online/payment/v1/settle', 'POST', 'Bearer', [ 'paymentToken' => $order_torobpay_token ] );
	}

	public function cancel( $paymentToken ) {
		return $this->request( 'api/online/payment/v1/cancel', 'POST', 'Bearer', [ 'paymentToken' => $paymentToken ] );
	}

	/**
	 * @param string   $paymentToken Payment token.
	 * @param int|null $timeout      Seconds. Null keeps the default request timeout.
	 * @return array|WP_Error
	 */
	public function status( $paymentToken, $timeout = null ) {
		return $this->request( 'api/online/payment/v1/status', 'GET', 'Bearer', [ 'paymentToken' => $paymentToken ], null, null, $timeout );
	}

	/**
	 * Retrieve latest WooCommerce plugin release information.
	 *
	 * Used by the hosted updater overlay. Unused in the WordPress.org package.
	 *
	 * @return array|WP_Error
	 */
	public function get_plugin_update() {
		return $this->request( 'core/woocommerce-plugin/latest/', 'GET', 'Bearer', [] );
	}

	public function update( WC_Order $order, string $paymentToken ) {
		$amount = torobpay_get_order_amount_in_iranian_rial( $order );
		$data   = array(
			"amount"               => $amount,
			"paymentMethodTypeDto" => 'INSTALLMENT',
			"paymentToken"         => $paymentToken,
		);

		$cart_list = $this->build_cart_list( $order );
		if ( is_wp_error( $cart_list ) ) {
			return $cart_list;
		}
		$data['cartList'][] = $cart_list;
		$order_data         = $order->get_data();

		$coupon_discount   = ! empty( $order_data['discount_total'] ) ? floatval( $order_data['discount_total'] ) : 0.0;
		$override_discount = 0.0;
		foreach ( $order->get_items( 'fee' ) as $fee_item ) {
			if ( $fee_item->get_meta( '_torobpay_fee_type' ) === 'discount_override' ) {
				$override_discount += abs( floatval( $fee_item->get_total() ) );
			}
		}
		$total_discount         = $coupon_discount + $override_discount;
		$data['discountAmount'] = $total_discount > 0 ? torobpay_get_amount_by_currency( $total_discount, $order->get_currency() ) : 0;

		return $this->request( 'api/online/payment/v1/update', 'POST', 'Bearer', $data );
	}

	/**
	 * Checks response for any error.
	 *
	 * @param object $response The response.
	 * @param array  $request_args The request args.
	 * @param string $request_url The request URL.
	 * @return object|array
	 */
	public function process_response( $response, $request_args = array(), $request_url = '' ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$is_json       = torobpay_is_json( $response_body );
		if ( ! $is_json ) {
			$code    = is_array( $response ) && isset( $response['response']['code'] ) ? $response['response']['code'] : $response_code;
			$message = is_array( $response ) && isset( $response['response']['message'] ) ? $response['response']['message'] : __( 'Invalid response from TorobPay API', 'torobpay-for-woocommerce' );
			return new \WP_Error( $code ? $code : 'invalid_response', $message );
		}

		$decoded = json_decode( $response_body, true );
		if ( is_array( $decoded ) ) {
			$decoded[ TOROBPAY_HTTP_STATUS_KEY ] = (int) $response_code;
		}

		return $decoded;
	}

	/**
	 * Custom remote request wrapper.
	 *
	 * @param string      $endpoint Endpoint path.
	 * @param string      $method   HTTP method.
	 * @param string      $token    Auth mode: Basic|Bearer.
	 * @param array       $args     Query/body args.
	 * @param WP_User|null $user    Unused; kept for BC.
	 * @param string|null $url      Full URL override.
	 * @param int|null    $timeout  Request timeout in seconds (default 10).
	 * @return array|WP_Error
	 */
	private function request( $endpoint, $method = 'GET', $token = 'Basic', $args = [], $user = null, $url = null, $timeout = null ) {
		if ( null === $timeout ) {
			$timeout = null !== $this->http_timeout ? $this->http_timeout : 10;
		}

		$request = array(
			'method'  => $method,
			'timeout' => max( 1, (int) $timeout ),
		);

		if ( ! $user ) {
			$user = wp_get_current_user();
		}

		if ( ! $url ) {
			$url = $this->get_api_base_url() . $endpoint;
		}

		$request['headers'] = $this->prepare_request_headers( $token );

		// Avoid a pointless remote call when auth could not be obtained (e.g. API outage).
		if ( 'Bearer' === $token && empty( $request['headers']['Authorization'] ) ) {
			return new \WP_Error( 'torobpay_auth_missing', __( 'TorobPay authentication is unavailable.', 'torobpay-for-woocommerce' ) );
		}
		if ( 'Basic' === $token && empty( $request['headers']['Authorization'] ) ) {
			return new \WP_Error( 'torobpay_auth_missing', __( 'TorobPay authentication credentials are incomplete.', 'torobpay-for-woocommerce' ) );
		}

		if ( 'GET' === $method ) {
			if ( ! empty( $args ) && is_array( $args ) ) {
				$url = add_query_arg( $args, $url );
			}
			// Never attach a body to GET requests.
		} else {
			if ( isset( $args['multipart'] ) && $args['multipart'] ) {
				// For multipart, use the pre-formatted body and headers
				$request['body']    = $args['body'];
				$request['headers'] = $args['headers'];
			} else {
				// Regular request handling
				if ( $token === 'Basic' ) {
					$request['body'] = http_build_query( $args );
				} else {
					$request['body'] = json_encode( $args );
				}
			}
		}

		$response = wp_remote_request( $url, $request );

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( $response_code && $response_code == 401 ) {
			delete_transient( 'torobpay_bearer_token' );
			$request['headers'] = $this->prepare_request_headers( $token );
			if ( empty( $request['headers']['Authorization'] ) ) {
				return new \WP_Error( 'torobpay_auth_missing', __( 'TorobPay authentication is unavailable.', 'torobpay-for-woocommerce' ) );
			}
			$response = wp_remote_request( $url, $request );
		}

		return $this->process_response( $response, $request, $url );
	}

	private function prepare_request_headers( $token ) {

		if ( $token === 'Basic' ) {
			$headers = $this->get_request_basic_token();
		} else {
			$headers = $this->get_request_bearer_token();
		}

		if ( is_wp_error( $headers ) || ! is_array( $headers ) ) {
			$headers = array();
		}

		if ( is_array( $this->settings ) && ! empty( $this->settings['client_id'] ) ) {
			$headers['user-agent'] = 'TorobPay, ' . $this->settings['client_id'];
		} else {
			$headers['user-agent'] = 'TorobPay';
		}

		if ( defined( 'TOROBPAY_VERSION' ) ) {
			$prefix = defined( 'TOROBPAY_VERSION_PREFIX' ) ? TOROBPAY_VERSION_PREFIX : 'd';
			$headers['X-TorobPay-Plugin-Version'] = $prefix . '_' . TOROBPAY_VERSION;
		}

		$shop_url = get_site_url();
		if ( $shop_url ) {
			$headers['X-TorobPay-Shop-URL'] = $shop_url;
		}

		if ( is_array( $this->settings ) ) {
			// UTM Torob auto-selection feature status
			$utm_enabled                       = isset( $this->settings['utm_torob_enabled'] ) && $this->settings['utm_torob_enabled'] === 'yes' ? 'true' : 'false';
			$headers['X-TorobPay-UTM-Enabled'] = $utm_enabled;

			// Independent of auto-select; always send true/false so the backend keys off the value.
			$utm_exclude_others                       = isset( $this->settings['utm_exclude_others'] ) && $this->settings['utm_exclude_others'] === 'yes' ? 'true' : 'false';
			$headers['X-TorobPay-UTM-Exclude-Others'] = $utm_exclude_others;

			$utm_medium_enabled                       = isset( $this->settings['utm_medium_enabled'] ) && $this->settings['utm_medium_enabled'] === 'yes' ? 'true' : 'false';
			$headers['X-TorobPay-UTM-Medium-Enabled'] = $utm_medium_enabled;

			$utm_medium_exclude_others                       = isset( $this->settings['utm_medium_exclude_others'] ) && $this->settings['utm_medium_exclude_others'] === 'yes' ? 'true' : 'false';
			$headers['X-TorobPay-UTM-Medium-Exclude-Others'] = $utm_medium_exclude_others;
		}

		return $headers;
	}

	/**
	 * Current merchant feature flags (order campaign, instore, and later features).
	 *
	 * @return array|WP_Error
	 */
	public function get_merchant_features() {
		return $this->request(
			'api/online/features/v1/list',
			'GET',
			'Bearer'
		);
	}

	/**
	 * Tell TorobPay whether this merchant's order campaign is enabled.
	 *
	 * @param bool $enabled Whether campaign sharing is on.
	 * @return array|WP_Error
	 */
	public function set_order_campaign_status( $enabled ) {
		return $this->request(
			'api/online/features/v1/set-order-campaign-status',
			'POST',
			'Bearer',
			array(
				'is_enabled' => (bool) $enabled,
			)
		);
	}

	/**
	 * Retrieve campaign-data-result for a specific page.
	 *
	 * @param int $page Page number, starting from 1.
	 * @return array|WP_Error
	 */
	public function get_campaign_data_result( $page = 1 ) {
		$endpoint = 'merchant/campaign-data-result/';
		$params   = $page > 1 ? array( 'page' => $page ) : array();
		return $this->request( $endpoint, 'GET', 'Bearer', $params );
	}

	/**
	 * Retrieve all campaign-data-result pages (max 10 pages safety cap).
	 *
	 * Used by the campaign admin page via torobpay_get_campaign_results().
	 *
	 * @return array|WP_Error Array of merged results or WP_Error on failure.
	 * @see torobpay_get_campaign_results()
	 */
	public function get_all_campaign_data_results() {

		$page    = 1;
		$results = array();

		do {
			$response = $this->get_campaign_data_result( $page );

			if ( is_wp_error( $response ) || empty( $response['successful'] ) ) {
				return $response;
			}

			$results = array_merge( $results, $response['response']['results'] ?? array() );

			++$page;
		} while ( ! empty( $response['response']['next'] ) && $page <= 10 ); // Safety cap to avoid infinite loop

		return $results;
	}
}
