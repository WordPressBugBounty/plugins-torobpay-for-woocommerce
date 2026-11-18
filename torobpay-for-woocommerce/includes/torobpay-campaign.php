<?php
/**
 * Campaign domain: merchant opt-in, paid-order query, and credit-file status.
 *
 * Opt-in is pushed to TorobPay via set-order-campaign-status on join/leave.
 * Unset local state is initialized from GET /api/online/features/v1/list (force-off).
 * HTTP for the inbound pull lives in includes/torobpay-campaign-rest.php.
 * The admin UI lives in campaign-page.php.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TOROBPAY_CAMPAIGN_MAX_PER_PAGE', 100 );
define( 'TOROBPAY_CAMPAIGN_DEFAULT_PER_PAGE', 50 );

/**
 * Whether the merchant opted in to campaign data sharing.
 *
 * @return bool
 */
function torobpay_is_campaign_enabled() {
	return 'yes' === get_option( 'torobpay_campaign_enabled', null );
}

/**
 * Persist the campaign opt-in flag.
 *
 * @param bool $enabled Whether sharing is on.
 * @return void
 */
function torobpay_set_campaign_enabled( $enabled ) {
	update_option( 'torobpay_campaign_enabled', $enabled ? 'yes' : 'no' );
}

/**
 * Fallback copy when campaign status cannot be updated on TorobPay.
 *
 * @return string
 */
function torobpay_campaign_status_error_fallback() {
	return __( 'Could not update campaign status with TorobPay.', 'torobpay-for-woocommerce' );
}

/**
 * Merchant-facing message for a failed set-order-campaign-status payload.
 *
 * @param mixed $data Failure payload (WP_Error or API envelope).
 * @return string
 */
function torobpay_campaign_status_error_message( $data ) {
	$fallback = torobpay_campaign_status_error_fallback();
	$detail   = '';

	if ( function_exists( 'torobpay_get_api_response_error_message' ) ) {
		$detail = (string) torobpay_get_api_response_error_message( $data, '', 'campaign' );
	} elseif ( is_wp_error( $data ) ) {
		$detail = (string) $data->get_error_message();
	}

	$detail = sanitize_text_field( trim( $detail ) );
	if ( '' === $detail || $detail === $fallback ) {
		return $fallback;
	}

	return sprintf(
		/* translators: %s: error detail from the TorobPay API or WordPress HTTP API */
		__( 'Could not update campaign status: %s', 'torobpay-for-woocommerce' ),
		$detail
	);
}

/**
 * Interpret set-order-campaign-status API payload.
 *
 * @param mixed $data Response from TorobPayAPI::set_order_campaign_status().
 * @return true|string True on success, merchant-facing error message on failure.
 */
function torobpay_interpret_campaign_status_response( $data ) {
	if ( function_exists( 'torobpay_api_response_is_successful' ) ) {
		if ( torobpay_api_response_is_successful( $data ) ) {
			return true;
		}
	} elseif ( is_array( $data ) && ! empty( $data['successful'] ) ) {
		return true;
	}

	return torobpay_campaign_status_error_message( $data );
}

/**
 * Push campaign opt-in to TorobPay. Does not write the local option.
 *
 * @param bool $enabled Whether sharing should be on.
 * @return true|string True on success, merchant-facing error message on failure.
 */
function torobpay_push_campaign_status( $enabled ) {
	if ( ! class_exists( 'TorobPayAPI' ) ) {
		return torobpay_campaign_status_error_fallback();
	}

	try {
		$api      = new TorobPayAPI();
		$response = $api->set_order_campaign_status( (bool) $enabled );
	} catch ( Throwable $e ) {
		torobpay_debug_log( 'TorobPay campaign status: ' . $e->getMessage() );
		return torobpay_campaign_status_error_fallback();
	}

	$result = torobpay_interpret_campaign_status_response( $response );
	if ( true !== $result ) {
		torobpay_debug_log( 'TorobPay campaign status: ' . $result );
	}

	return $result;
}

/**
 * Read is_order_campaign_enabled from GET /api/online/features/v1/list.
 *
 * @param mixed $data Response from TorobPayAPI::get_merchant_features().
 * @return bool|null True/false when the flag is present, null when unusable.
 */
function torobpay_interpret_order_campaign_enabled( $data ) {
	if ( function_exists( 'torobpay_api_response_is_successful' ) ) {
		if ( ! torobpay_api_response_is_successful( $data ) ) {
			return null;
		}
	} elseif ( ! is_array( $data ) || empty( $data['successful'] ) ) {
		return null;
	}

	if ( ! isset( $data['response'] ) || ! is_array( $data['response'] ) || ! array_key_exists( 'is_order_campaign_enabled', $data['response'] ) ) {
		return null;
	}

	return (bool) $data['response']['is_order_campaign_enabled'];
}

/**
 * GET current order-campaign flag from TorobPay. Does not write the local option.
 *
 * @return bool|null True/false on success, null on failure.
 */
function torobpay_fetch_order_campaign_enabled() {
	if ( ! class_exists( 'TorobPayAPI' ) ) {
		return null;
	}

	try {
		$api      = new TorobPayAPI();
		$response = $api->get_merchant_features();
	} catch ( Throwable $e ) {
		torobpay_debug_log( 'TorobPay campaign features: ' . $e->getMessage() );
		return null;
	}

	return torobpay_interpret_order_campaign_enabled( $response );
}

/**
 * One-shot init: if the merchant never chose, GET TorobPay and force campaign off.
 *
 * Leaves the option unset on GET/POST failure so the next admin page load can try
 * again (e.g. credentials were not saved yet). No retry of user toggle POSTs.
 *
 * @return void
 */
function torobpay_maybe_init_campaign_status() {
	if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
		return;
	}

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	if ( null !== get_option( 'torobpay_campaign_enabled', null ) ) {
		return;
	}

	if ( ! function_exists( 'torobpay_is_configured' ) || ! torobpay_is_configured() ) {
		return;
	}

	$remote = torobpay_fetch_order_campaign_enabled();
	if ( null === $remote ) {
		return;
	}

	if ( $remote && true !== torobpay_push_campaign_status( false ) ) {
		return;
	}

	torobpay_set_campaign_enabled( false );
}

add_action( 'admin_init', 'torobpay_maybe_init_campaign_status' );

/**
 * Unix timestamp of the last successful inbound campaign-orders fetch.
 *
 * @return int
 */
function torobpay_get_last_campaign_fetch() {
	return (int) get_option( 'torobpay_last_campaign_fetch', 0 );
}

/**
 * Unix timestamp of the latest credit file (from campaign-data-result created_at).
 *
 * @return int
 */
function torobpay_get_last_campaign_credit() {
	return (int) get_option( 'torobpay_last_campaign_credit', 0 );
}

/**
 * Record a successful inbound order-data fetch.
 *
 * @param int   $timestamp Unix timestamp.
 * @param array $context   Optional page/count/total for debug log.
 * @return void
 */
function torobpay_record_campaign_fetch( $timestamp, $context = array() ) {
	$timestamp = (int) $timestamp;
	if ( $timestamp <= 0 ) {
		return;
	}

	update_option( 'torobpay_last_campaign_fetch', $timestamp, false );

	$payload = array(
		'at' => gmdate( 'c', $timestamp ),
	);
	if ( isset( $context['page'] ) ) {
		$payload['page'] = (int) $context['page'];
	}
	if ( isset( $context['count'] ) ) {
		$payload['count'] = (int) $context['count'];
	}
	if ( isset( $context['total'] ) ) {
		$payload['total'] = (int) $context['total'];
	}

	torobpay_debug_log( 'TorobPay campaign fetch: ' . wp_json_encode( $payload ) );
}

/**
 * Record the latest credit-file timestamp (does not move backwards).
 *
 * @param int        $timestamp Unix timestamp from result created_at.
 * @param int|string $result_id Optional campaign-data-result id.
 * @return void
 */
function torobpay_record_campaign_credit( $timestamp, $result_id = null ) {
	$timestamp = (int) $timestamp;
	if ( $timestamp <= 0 ) {
		return;
	}

	$previous = torobpay_get_last_campaign_credit();
	if ( $timestamp < $previous ) {
		return;
	}

	if ( $timestamp === $previous && null === $result_id ) {
		return;
	}

	update_option( 'torobpay_last_campaign_credit', $timestamp, false );

	$payload = array(
		'created_at' => gmdate( 'c', $timestamp ),
	);
	if ( null !== $result_id && '' !== $result_id ) {
		$payload['id'] = $result_id;
	}

	torobpay_debug_log( 'TorobPay campaign credit file: ' . wp_json_encode( $payload ) );
}

/**
 * Normalize a stored client_secret / password (WooCommerce may persist amp;).
 *
 * @param string $value Raw setting value.
 * @return string
 */
function torobpay_normalize_credential_secret( $value ) {
	return str_replace( 'amp;', '', (string) $value );
}

/**
 * Timing-safe string compare that is quiet on PHP 7.4 when lengths differ.
 *
 * @param string $known Known value.
 * @param string $given User-supplied value.
 * @return bool
 */
function torobpay_credentials_match( $known, $given ) {
	$known = (string) $known;
	$given = (string) $given;

	if ( strlen( $known ) !== strlen( $given ) ) {
		return false;
	}

	return hash_equals( $known, $given );
}

/**
 * Parse HTTP Basic credentials from an Authorization header value.
 *
 * @param string $header Authorization header.
 * @return array{user:string,pass:string}|false
 */
function torobpay_parse_basic_authorization( $header ) {
	if ( ! is_string( $header ) || '' === $header ) {
		return false;
	}

	if ( ! preg_match( '/^Basic\s+(.+)$/i', trim( $header ), $matches ) ) {
		return false;
	}

	$decoded = base64_decode( $matches[1], true );
	if ( false === $decoded || '' === $decoded ) {
		return false;
	}

	$parts = explode( ':', $decoded, 2 );
	if ( 2 !== count( $parts ) ) {
		return false;
	}

	return array(
		'user' => $parts[0],
		'pass' => $parts[1],
	);
}

/**
 * Parse a campaign date-range parameter to a Unix timestamp.
 *
 * @param mixed $value Raw datetime string.
 * @return int|false
 */
function torobpay_parse_campaign_date_param( $value ) {
	if ( ! is_string( $value ) || '' === $value ) {
		return false;
	}

	if ( function_exists( 'rest_parse_date' ) ) {
		$timestamp = rest_parse_date( $value );
		if ( $timestamp ) {
			return (int) $timestamp;
		}
	}

	$timestamp = strtotime( $value );
	return $timestamp ? (int) $timestamp : false;
}

/**
 * Order statuses included in campaign pulls.
 *
 * Same set WooCommerce uses for is_paid(): processing and completed by default,
 * never refunded/cancelled/failed. Custom paid statuses via
 * woocommerce_order_is_paid_statuses are included.
 *
 * @return string[]
 */
function torobpay_get_campaign_order_statuses() {
	$statuses = function_exists( 'wc_get_is_paid_statuses' )
		? wc_get_is_paid_statuses()
		: array( 'processing', 'completed' );

	$excluded = array( 'refunded', 'cancelled', 'canceled', 'failed', 'pending', 'on-hold' );
	$statuses = array_values( array_diff( array_map( 'strval', (array) $statuses ), $excluded ) );

	return ! empty( $statuses ) ? $statuses : array( 'processing', 'completed' );
}

/**
 * Query paid (not refunded/cancelled) orders in a date range for the campaign pull API.
 *
 * @param int $after_ts  Inclusive start (Unix).
 * @param int $before_ts Inclusive end (Unix).
 * @param int $page      Page number (1-based).
 * @param int $per_page  Page size.
 * @return array{orders:array,total:int,total_pages:int}
 */
function torobpay_query_campaign_orders( $after_ts, $before_ts, $page = 1, $per_page = 50 ) {
	$empty = array(
		'orders'      => array(),
		'total'       => 0,
		'total_pages' => 0,
	);

	if ( ! function_exists( 'wc_get_orders' ) ) {
		return $empty;
	}

	$page     = max( 1, (int) $page );
	$per_page = max( 1, min( TOROBPAY_CAMPAIGN_MAX_PER_PAGE, (int) $per_page ) );

	$args = array(
		'type'         => 'shop_order',
		'status'       => torobpay_get_campaign_order_statuses(),
		'limit'        => $per_page,
		'page'         => $page,
		'paginate'     => true,
		'orderby'      => 'date',
		'order'        => 'DESC',
		'date_created' => (int) $after_ts . '...' . (int) $before_ts,
		'return'       => 'objects',
	);

	$results = wc_get_orders( $args );

	$orders_raw  = array();
	$total       = 0;
	$total_pages = 0;

	if ( is_object( $results ) && isset( $results->orders ) ) {
		$orders_raw  = is_array( $results->orders ) ? $results->orders : array();
		$total       = isset( $results->total ) ? (int) $results->total : count( $orders_raw );
		$total_pages = isset( $results->max_num_pages ) ? (int) $results->max_num_pages : ( $per_page > 0 ? (int) ceil( $total / $per_page ) : 0 );
	} elseif ( is_array( $results ) ) {
		$orders_raw  = $results;
		$total       = count( $orders_raw );
		$total_pages = 1;
	}

	$orders = array();
	foreach ( $orders_raw as $order ) {
		$row = torobpay_format_campaign_order( $order );
		if ( null !== $row ) {
			$orders[] = $row;
		}
	}

	return array(
		'orders'      => $orders,
		'total'       => $total,
		'total_pages' => $total_pages,
	);
}

/**
 * Whether an object is a refund rather than a shop order.
 *
 * @param object $order Order-like object.
 * @return bool
 */
function torobpay_is_campaign_refund_object( $order ) {
	if ( class_exists( 'WC_Order_Refund' ) && is_a( $order, 'WC_Order_Refund' ) ) {
		return true;
	}

	return method_exists( $order, 'get_type' ) && 'shop_order_refund' === $order->get_type();
}

/**
 * Map a WooCommerce order to the campaign payload.
 *
 * @param mixed $order Order object.
 * @return array{phone_number:string,order_date:string,order_amount:int,payment_gateway:string}|null
 */
function torobpay_format_campaign_order( $order ) {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
		return null;
	}

	if ( torobpay_is_campaign_refund_object( $order ) ) {
		return null;
	}

	// Sub-orders duplicate the parent's amount; counting both double-reports revenue.
	if ( function_exists( 'torobpay_should_bypass_order' ) && torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		return null;
	}

	$phone = method_exists( $order, 'get_billing_phone' ) ? (string) $order->get_billing_phone() : '';

	$order_date = torobpay_get_campaign_order_date( $order );
	if ( '' === $order_date ) {
		return null;
	}

	$payment_method = method_exists( $order, 'get_payment_method_title' ) ? (string) $order->get_payment_method_title() : '';
	$method_id      = method_exists( $order, 'get_payment_method' ) ? strtolower( (string) $order->get_payment_method() ) : '';
	if ( 'wc_gateway_torobpay' === $method_id ) {
		$payment_method = 'TorobPay';
	}
	if ( '' === $payment_method ) {
		$payment_method = 'Unknown';
	}

	if ( function_exists( 'torobpay_get_order_amount_in_iranian_rial' ) ) {
		$amount = (int) torobpay_get_order_amount_in_iranian_rial( $order );
	} else {
		$amount = method_exists( $order, 'get_total' ) ? (int) ceil( (float) $order->get_total() ) : 0;
	}

	if ( $amount <= 0 ) {
		return null;
	}

	return array(
		'phone_number'    => $phone,
		'order_date'      => $order_date,
		'order_amount'    => $amount,
		'payment_gateway' => $payment_method,
	);
}

/**
 * Resolve an order's created datetime as Y-m-d H:i:s.
 *
 * Uses get_date_created() when present, then the CPT post_date. Never
 * substitutes the current time; returns empty string when unknown.
 *
 * @param object $order Order object.
 * @return string
 */
function torobpay_get_campaign_order_date( $order ) {
	if ( method_exists( $order, 'get_date_created' ) ) {
		$created   = $order->get_date_created();
		$formatted = torobpay_format_campaign_datetime( $created );
		if ( '' !== $formatted ) {
			return $formatted;
		}
	}

	if ( method_exists( $order, 'get_id' ) && function_exists( 'get_post_field' ) ) {
		$post_date = get_post_field( 'post_date', $order->get_id() );
		if ( is_string( $post_date ) && '' !== $post_date && '0000-00-00 00:00:00' !== $post_date ) {
			return $post_date;
		}
	}

	return '';
}

/**
 * Format a datetime value to Y-m-d H:i:s.
 *
 * @param mixed $datetime WC_DateTime, DateTimeInterface, timestamp, or date string.
 * @return string
 */
function torobpay_format_campaign_datetime( $datetime ) {
	if ( $datetime instanceof \DateTimeInterface ) {
		if ( method_exists( $datetime, 'date' ) ) {
			return (string) $datetime->date( 'Y-m-d H:i:s' );
		}
		return $datetime->format( 'Y-m-d H:i:s' );
	}

	if ( is_object( $datetime ) ) {
		if ( method_exists( $datetime, 'date' ) ) {
			return (string) $datetime->date( 'Y-m-d H:i:s' );
		}
		if ( method_exists( $datetime, 'format' ) ) {
			return (string) $datetime->format( 'Y-m-d H:i:s' );
		}
		return '';
	}

	if ( is_int( $datetime ) || ( is_string( $datetime ) && ctype_digit( $datetime ) ) ) {
		$ts = (int) $datetime;
		if ( $ts <= 0 ) {
			return '';
		}
		return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d H:i:s', $ts ) : gmdate( 'Y-m-d H:i:s', $ts );
	}

	if ( ! is_string( $datetime ) || '' === trim( $datetime ) ) {
		return '';
	}

	$ts = strtotime( $datetime );
	if ( false === $ts ) {
		return '';
	}

	return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d H:i:s', $ts ) : gmdate( 'Y-m-d H:i:s', $ts );
}

/**
 * Keep only the credit-file fields the plugin uses.
 *
 * @param mixed $row Raw campaign-data-result row.
 * @return array{created_at:string,result_url:string}|null
 */
function torobpay_normalize_campaign_result_row( $row ) {
	if ( ! is_array( $row ) ) {
		return null;
	}

	$created_at = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
	$result_url = isset( $row['result_url'] ) ? (string) $row['result_url'] : '';

	$result_url = torobpay_sanitize_campaign_result_url( $result_url );

	if ( '' === $created_at && '' === $result_url ) {
		return null;
	}

	return array(
		'created_at' => $created_at,
		'result_url' => $result_url,
	);
}

/**
 * Keep a campaign credit-file URL only when it is a credential-free HTTPS URL.
 *
 * @param mixed $url Raw result_url from the API.
 * @return string Empty when the URL must not be rendered as a download link.
 */
function torobpay_sanitize_campaign_result_url( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return '';
	}

	$sanitized = esc_url_raw( $url, array( 'https' ) );
	if ( '' === $sanitized ) {
		return '';
	}

	$parts = wp_parse_url( $sanitized );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return '';
	}

	if ( empty( $parts['scheme'] ) || 'https' !== strtolower( (string) $parts['scheme'] ) ) {
		return '';
	}

	if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return '';
	}

	return $sanitized;
}

/**
 * Fallback copy when campaign credit files cannot be loaded.
 *
 * @return string
 */
function torobpay_campaign_credit_files_error_fallback() {
	return __( 'Could not load campaign files from TorobPay.', 'torobpay-for-woocommerce' );
}

/**
 * Whether a campaign-data-result payload is an API/HTTP failure.
 *
 * Successful payloads are a list of rows. Failures are WP_Error, a non-array,
 * or an API envelope with successful=false / errorData.
 *
 * @param mixed $data Response from TorobPayAPI::get_all_campaign_data_results().
 * @return bool
 */
function torobpay_is_campaign_credit_files_failure( $data ) {
	if ( is_wp_error( $data ) || ! is_array( $data ) ) {
		return true;
	}

	if ( array_key_exists( 'successful', $data ) ) {
		return empty( $data['successful'] );
	}

	return ! empty( $data['errorData'] );
}

/**
 * Build a merchant-facing message for a failed campaign-data-result payload.
 *
 * @param mixed $data Failure payload (WP_Error or API envelope).
 * @return string
 */
function torobpay_campaign_credit_files_error_message( $data ) {
	$fallback = torobpay_campaign_credit_files_error_fallback();
	$detail   = '';

	if ( function_exists( 'torobpay_get_api_response_error_message' ) ) {
		$detail = (string) torobpay_get_api_response_error_message( $data, '', 'campaign' );
	} elseif ( is_wp_error( $data ) ) {
		$detail = (string) $data->get_error_message();
	}

	if ( '' === $detail && is_array( $data ) && ! empty( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
		$detail = $data['error']['message'];
	}

	$detail = sanitize_text_field( trim( $detail ) );
	if ( '' === $detail || $detail === $fallback ) {
		return $fallback;
	}

	return sprintf(
		/* translators: %s: error detail from the TorobPay API or WordPress HTTP API */
		__( 'Could not load campaign files: %s', 'torobpay-for-woocommerce' ),
		$detail
	);
}

/**
 * Merchant-facing error from a campaign results array, or empty when the fetch succeeded.
 *
 * @param mixed $campaign_results Return value of torobpay_get_campaign_results().
 * @return string
 */
function torobpay_campaign_results_user_error( $campaign_results ) {
	if ( ! is_array( $campaign_results ) || ! empty( $campaign_results['success'] ) ) {
		return '';
	}

	if ( ! empty( $campaign_results['error'] ) && is_string( $campaign_results['error'] ) ) {
		return $campaign_results['error'];
	}

	return torobpay_campaign_credit_files_error_fallback();
}

/**
 * Normalize, sort, or convert a campaign-data-result API payload into page data.
 *
 * @param mixed $data Response from TorobPayAPI::get_all_campaign_data_results().
 * @return array{success:bool,results:array<int,array{created_at:string,result_url:string}>,error:string}
 */
function torobpay_interpret_campaign_credit_files_response( $data ) {
	$result = array(
		'success' => false,
		'results' => array(),
		'error'   => '',
	);

	if ( torobpay_is_campaign_credit_files_failure( $data ) ) {
		$result['error'] = torobpay_campaign_credit_files_error_message( $data );
		return $result;
	}

	if ( array_key_exists( 'successful', $data ) ) {
		$data = ( isset( $data['response']['results'] ) && is_array( $data['response']['results'] ) )
			? $data['response']['results']
			: array();
	}

	$normalized = array();
	foreach ( $data as $row ) {
		$item = torobpay_normalize_campaign_result_row( $row );
		if ( null !== $item ) {
			$normalized[] = $item;
		}
	}

	if ( ! empty( $normalized ) ) {
		usort(
			$normalized,
			static function ( $a, $b ) {
				$a_time = isset( $a['created_at'] ) ? strtotime( (string) $a['created_at'] ) : 0;
				$b_time = isset( $b['created_at'] ) ? strtotime( (string) $b['created_at'] ) : 0;
				return $b_time <=> $a_time;
			}
		);
	}

	$result['success'] = true;
	$result['results'] = $normalized;

	return $result;
}

/**
 * Fetch and sort campaign credit files from TorobPay (no option writes).
 *
 * @return array{success:bool,results:array<int,array{created_at:string,result_url:string}>,error:string}
 */
function torobpay_fetch_campaign_credit_files() {
	$failed = array(
		'success' => false,
		'results' => array(),
		'error'   => torobpay_campaign_credit_files_error_fallback(),
	);

	if ( ! class_exists( 'TorobPayAPI' ) ) {
		return $failed;
	}

	try {
		$api    = new TorobPayAPI();
		$result = torobpay_interpret_campaign_credit_files_response( $api->get_all_campaign_data_results() );
	} catch ( Throwable $e ) {
		torobpay_debug_log( 'TorobPay campaign results: ' . $e->getMessage() );
		return $failed;
	}

	if ( empty( $result['success'] ) && ! empty( $result['error'] ) ) {
		torobpay_debug_log( 'TorobPay campaign results: ' . $result['error'] );
	}

	return $result;
}

/**
 * Persist last-credit from the newest row that has a download URL.
 *
 * @param array $results Normalized result rows, newest first.
 * @return void
 */
function torobpay_sync_latest_campaign_credit( $results ) {
	if ( ! is_array( $results ) ) {
		return;
	}

	foreach ( $results as $row ) {
		if ( empty( $row['result_url'] ) || empty( $row['created_at'] ) ) {
			continue;
		}
		$credit_at = strtotime( (string) $row['created_at'] );
		if ( $credit_at ) {
			torobpay_record_campaign_credit( $credit_at );
		}
		break;
	}
}

/**
 * Get campaign credit files and refresh the last-credit timestamp.
 *
 * Used by the campaign admin page.
 *
 * @return array{success:bool,results:array,error:string}
 */
function torobpay_get_campaign_results() {
	$result = torobpay_fetch_campaign_credit_files();
	if ( ! empty( $result['results'] ) ) {
		torobpay_sync_latest_campaign_credit( $result['results'] );
	}

	return $result;
}
