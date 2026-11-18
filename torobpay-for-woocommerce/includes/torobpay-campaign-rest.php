<?php
/**
 * Inbound REST pull: POST /wp-json/torobpay/v1/campaign-orders
 *
 * Permission is local (Basic client_id/client_secret + opt-in). Store OAuth
 * is checked in the handler so failed Basic auth does not hit TorobPay.
 * Params are a JSON object (after, before, page, per_page). GET is not registered.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TOROBPAY_CAMPAIGN_REST_NAMESPACE', 'torobpay/v1' );
define( 'TOROBPAY_CAMPAIGN_REST_ROUTE', '/campaign-orders' );

/**
 * REST WP_Error helper with an HTTP status.
 *
 * @param string $code    Error code.
 * @param string $message Message.
 * @param int    $status  HTTP status.
 * @return WP_Error
 */
function torobpay_campaign_rest_error( $code, $message, $status ) {
	return new WP_Error( $code, $message, array( 'status' => (int) $status ) );
}

/**
 * Read the Authorization header from a REST request or the server.
 *
 * @param WP_REST_Request|null $request Optional REST request.
 * @return string
 */
function torobpay_get_authorization_header( $request = null ) {
	if ( $request instanceof WP_REST_Request ) {
		$header = $request->get_header( 'authorization' );
		if ( is_string( $header ) && '' !== $header ) {
			return $header;
		}
	}

	if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- REST Authorization header, not a form action.
		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
	}

	if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- CGI Authorization fallback, not a form action.
		return sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
	}

	return '';
}

/**
 * Canonical campaign-orders REST path (/torobpay/v1/campaign-orders).
 *
 * @return string
 */
function torobpay_campaign_orders_rest_route() {
	return '/' . trim( TOROBPAY_CAMPAIGN_REST_NAMESPACE . TOROBPAY_CAMPAIGN_REST_ROUTE, '/' );
}

/**
 * Whether a REST path is exactly the campaign-orders route.
 *
 * @param mixed $route rest_route query var.
 * @return bool
 */
function torobpay_rest_route_is_campaign_orders( $route ) {
	if ( ! is_string( $route ) || '' === $route ) {
		return false;
	}

	return ( '/' . trim( $route, '/' ) ) === torobpay_campaign_orders_rest_route();
}

/**
 * Whether the current request is the campaign-orders REST route.
 *
 * Matches only WP's rest_route query var (exact path). Does not inspect REQUEST_URI,
 * so query-string lookalikes cannot opt this request out of Application Passwords.
 *
 * @return bool
 */
function torobpay_is_campaign_orders_rest_request() {
	if ( ! isset( $GLOBALS['wp'] ) || ! is_object( $GLOBALS['wp'] ) || ! isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
		return false;
	}

	return torobpay_rest_route_is_campaign_orders( $GLOBALS['wp']->query_vars['rest_route'] );
}

/**
 * Do not treat campaign-orders Basic auth as a WordPress application password.
 *
 * @param bool $is_api_request Whether Application Passwords should handle this request.
 * @return bool
 */
function torobpay_campaign_skip_application_passwords( $is_api_request ) {
	if ( ! $is_api_request ) {
		return $is_api_request;
	}

	if ( torobpay_is_campaign_orders_rest_request() ) {
		return false;
	}

	return $is_api_request;
}
add_filter( 'application_password_is_api_request', 'torobpay_campaign_skip_application_passwords' );

/**
 * Clear Application Password failures so permission_callback can authenticate.
 *
 * @param mixed $result Current REST authentication result.
 * @return mixed
 */
function torobpay_campaign_rest_auth_passthrough( $result ) {
	if ( ! is_wp_error( $result ) || ! torobpay_is_campaign_orders_rest_request() ) {
		return $result;
	}

	return null;
}
add_filter( 'rest_authentication_errors', 'torobpay_campaign_rest_auth_passthrough', 99 );

/**
 * Whether this request must never be stored in a shared HTTP cache.
 *
 * Uses rest_route when available. Falls back to REQUEST_URI only for cache
 * suppression (never for Application Password bypass): LiteSpeed may decide
 * cacheability before query vars are populated.
 *
 * @return bool
 */
function torobpay_campaign_request_must_not_be_cached() {
	if ( torobpay_is_campaign_orders_rest_request() ) {
		return true;
	}

	$uri = '';
	if ( isset( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache-control probe, not a form action.
		$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	}
	if ( '' === $uri ) {
		return false;
	}

	$path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $uri, PHP_URL_PATH ) : parse_url( $uri, PHP_URL_PATH );
	$path = is_string( $path ) ? rtrim( $path, '/' ) : '';
	if ( substr( $path, -strlen( '/wp-json/torobpay/v1/campaign-orders' ) ) === '/wp-json/torobpay/v1/campaign-orders' ) {
		return true;
	}

	$query = function_exists( 'wp_parse_url' ) ? wp_parse_url( $uri, PHP_URL_QUERY ) : parse_url( $uri, PHP_URL_QUERY );
	if ( ! is_string( $query ) || '' === $query ) {
		return false;
	}

	$args = array();
	parse_str( $query, $args );
	$route = isset( $args['rest_route'] ) ? (string) $args['rest_route'] : '';

	return torobpay_rest_route_is_campaign_orders( $route );
}

/**
 * Cache-Control value for campaign-orders (private PII; never a shared cache).
 *
 * @return string
 */
function torobpay_campaign_cache_control_value() {
	return 'no-cache, must-revalidate, max-age=0, no-store, private';
}

/**
 * URI patterns page-cache plugins should never store.
 *
 * Applied via each plugin's reject-URI filter so merchants do not have to
 * add excludes in LiteSpeed / WP Rocket / W3TC.
 *
 * @return string[]
 */
function torobpay_campaign_cache_reject_uri_patterns() {
	return array(
		'/wp-json/torobpay/v1/campaign-orders',
		'/index.php\\?rest_route=/torobpay/v1/campaign-orders',
		'/index.php\\?rest_route=%2Ftorobpay%2Fv1%2Fcampaign-orders',
	);
}

/**
 * Merge campaign-orders paths into a cache plugin's reject-URI list.
 *
 * @param mixed $uris Existing list.
 * @return array
 */
function torobpay_campaign_filter_cache_reject_uris( $uris ) {
	if ( ! is_array( $uris ) ) {
		$uris = array();
	}

	foreach ( torobpay_campaign_cache_reject_uri_patterns() as $pattern ) {
		if ( ! in_array( $pattern, $uris, true ) ) {
			$uris[] = $pattern;
		}
	}

	return $uris;
}

/**
 * Define DONOTCACHEPAGE-style constants page caches look for.
 *
 * @return void
 */
function torobpay_campaign_define_cache_bypass_constants() {
	if ( ! torobpay_campaign_request_must_not_be_cached() ) {
		return;
	}

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Cache plugin convention.
		define( 'DONOTCACHEPAGE', true );
	}
	if ( ! defined( 'DONOTCACHEDB' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Cache plugin convention.
		define( 'DONOTCACHEDB', true );
	}
	if ( ! defined( 'LSCACHE_NO_CACHE' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- LiteSpeed Cache convention.
		define( 'LSCACHE_NO_CACHE', true );
	}
}

/**
 * Send origin + CDN + LiteSpeed headers so campaign-orders is not stored.
 *
 * Successful campaign JSON is authenticated PII. WP REST only nocaches logged-in
 * users. Shared page caches store GET; this route is POST-only so a typical
 * cache will not keep the body even if it ignores Cache-Control.
 *
 * @return void
 */
function torobpay_campaign_mark_response_uncacheable() {
	torobpay_campaign_define_cache_bypass_constants();

	if ( function_exists( 'nocache_headers' ) ) {
		nocache_headers();
	}

	$cache_control = torobpay_campaign_cache_control_value();

	if ( ! headers_sent() ) {
		header( 'Cache-Control: ' . $cache_control );
		header( 'Pragma: no-cache' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'CDN-Cache-Control: no-store, private' );
		header( 'Cloudflare-CDN-Cache-Control: no-store' );
		header( 'Surrogate-Control: no-store' );
		header( 'X-Accel-Expires: 0' );
	}

	if ( function_exists( 'do_action' ) ) {
		do_action( 'litespeed_control_set_nocache', 'torobpay-campaign-orders' );
	}
}

/**
 * Force WP REST nocache headers for campaign-orders (anonymous Basic auth).
 *
 * @param bool $send Whether nocache headers should be sent.
 * @return bool
 */
function torobpay_campaign_rest_send_nocache_headers( $send ) {
	if ( torobpay_campaign_request_must_not_be_cached() ) {
		torobpay_campaign_mark_response_uncacheable();
		return true;
	}

	return $send;
}
add_filter( 'rest_send_nocache_headers', 'torobpay_campaign_rest_send_nocache_headers' );

/**
 * Tell LiteSpeed Cache not to store campaign-orders.
 *
 * @param bool $cacheable Whether LSCache considers this request cacheable.
 * @return bool
 */
function torobpay_campaign_litespeed_not_cacheable( $cacheable ) {
	if ( torobpay_campaign_request_must_not_be_cached() ) {
		return false;
	}

	return $cacheable;
}
add_filter( 'litespeed_control_cacheable', 'torobpay_campaign_litespeed_not_cacheable', 999 );

/**
 * LiteSpeed Cache is_cacheable API (some versions use this name).
 *
 * @param bool $cacheable Whether LSCache considers this request cacheable.
 * @return bool
 */
function torobpay_campaign_litespeed_is_not_cacheable( $cacheable ) {
	return torobpay_campaign_litespeed_not_cacheable( $cacheable );
}
add_filter( 'litespeed_cache_is_cacheable', 'torobpay_campaign_litespeed_is_not_cacheable', 999 );

/**
 * WP Rocket: honor DONOTCACHEPAGE on this route.
 *
 * @param bool $override Whether Rocket should respect DONOTCACHEPAGE.
 * @return bool
 */
function torobpay_campaign_rocket_honor_donotcache( $override ) {
	if ( torobpay_campaign_request_must_not_be_cached() ) {
		return true;
	}

	return $override;
}
add_filter( 'rocket_override_donotcachepage', 'torobpay_campaign_rocket_honor_donotcache' );

add_filter( 'rocket_cache_reject_uri', 'torobpay_campaign_filter_cache_reject_uris' );
add_filter( 'w3tc_pgcache_reject_uri', 'torobpay_campaign_filter_cache_reject_uris' );
add_action( 'plugins_loaded', 'torobpay_campaign_define_cache_bypass_constants', 0 );
add_action( 'init', 'torobpay_campaign_define_cache_bypass_constants', 0 );
add_action( 'rest_api_init', 'torobpay_campaign_define_cache_bypass_constants', 0 );

/**
 * Attach nocache headers to the REST response object.
 *
 * @param mixed $response REST response or WP_Error.
 * @return mixed
 */
function torobpay_campaign_apply_nocache_to_response( $response ) {
	if ( $response instanceof WP_REST_Response ) {
		$cache_control = torobpay_campaign_cache_control_value();
		$response->header( 'Cache-Control', $cache_control );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		$response->header( 'CDN-Cache-Control', 'no-store, private' );
		$response->header( 'Cloudflare-CDN-Cache-Control', 'no-store' );
		$response->header( 'Surrogate-Control', 'no-store' );
		$response->header( 'X-Accel-Expires', '0' );
		$response->header( 'Vary', 'Authorization', false );
	}

	return $response;
}

/**
 * After dispatch, keep campaign-orders out of shared caches.
 *
 * @param mixed           $response Result to send.
 * @param WP_REST_Server  $server   Server instance.
 * @param WP_REST_Request $request  Request.
 * @return mixed
 */
function torobpay_campaign_rest_post_dispatch_nocache( $response, $server, $request ) {
	unset( $server, $request );

	if ( ! torobpay_campaign_request_must_not_be_cached() ) {
		return $response;
	}

	torobpay_campaign_mark_response_uncacheable();

	return torobpay_campaign_apply_nocache_to_response( $response );
}
add_filter( 'rest_post_dispatch', 'torobpay_campaign_rest_post_dispatch_nocache', 10, 3 );

/**
 * Register the inbound campaign-orders route.
 *
 * @return void
 */
function torobpay_register_campaign_orders_route() {
	register_rest_route(
		TOROBPAY_CAMPAIGN_REST_NAMESPACE,
		TOROBPAY_CAMPAIGN_REST_ROUTE,
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'torobpay_handle_campaign_orders_request',
			'permission_callback' => 'torobpay_campaign_orders_permission',
			'args'                => array(
				'after'    => array(
					'required'          => true,
					'type'              => 'string',
					'description'       => 'Inclusive start of the order date range (ISO-8601 / RFC3339).',
					'validate_callback' => 'torobpay_rest_validate_campaign_datetime',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'before'   => array(
					'required'          => true,
					'type'              => 'string',
					'description'       => 'Inclusive end of the order date range (ISO-8601 / RFC3339).',
					'validate_callback' => 'torobpay_rest_validate_campaign_datetime',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'page'     => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 1,
					'minimum'           => 1,
					'sanitize_callback' => 'absint',
				),
				'per_page' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => TOROBPAY_CAMPAIGN_DEFAULT_PER_PAGE,
					'minimum'           => 1,
					'maximum'           => TOROBPAY_CAMPAIGN_MAX_PER_PAGE,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'torobpay_register_campaign_orders_route' );

/**
 * Validate an ISO-8601 datetime query arg.
 *
 * @param mixed           $value   Raw value.
 * @param WP_REST_Request $request Request.
 * @param string          $param   Param name.
 * @return true|WP_Error
 */
function torobpay_rest_validate_campaign_datetime( $value, $request, $param ) {
	unset( $request );

	if ( false === torobpay_parse_campaign_date_param( $value ) ) {
		return torobpay_campaign_rest_error(
			'rest_invalid_param',
			sprintf(
				/* translators: %s: parameter name */
				__( 'Invalid %s datetime. Use ISO-8601 (RFC3339).', 'torobpay-for-woocommerce' ),
				$param
			),
			400
		);
	}

	return true;
}

/**
 * Authorize an inbound campaign-orders request (local checks only).
 *
 * @param WP_REST_Request $request Incoming request.
 * @return true|WP_Error
 */
function torobpay_campaign_orders_permission( $request ) {
	$credentials = torobpay_parse_basic_authorization( torobpay_get_authorization_header( $request ) );
	$settings    = function_exists( 'torobpay_get_settings' ) ? torobpay_get_settings( true ) : array();

	$client_id     = ( is_array( $settings ) && ! empty( $settings['client_id'] ) ) ? (string) $settings['client_id'] : '';
	$client_secret = ( is_array( $settings ) && ! empty( $settings['client_secret'] ) )
		? torobpay_normalize_credential_secret( $settings['client_secret'] )
		: '';

	if ( false === $credentials || '' === $client_id || '' === $client_secret ) {
		return torobpay_campaign_rest_error(
			'torobpay_campaign_unauthorized',
			__( 'Invalid authentication credentials.', 'torobpay-for-woocommerce' ),
			401
		);
	}

	$id_ok     = torobpay_credentials_match( $client_id, $credentials['user'] );
	$secret_ok = torobpay_credentials_match( $client_secret, torobpay_normalize_credential_secret( $credentials['pass'] ) );

	if ( ! $id_ok || ! $secret_ok ) {
		return torobpay_campaign_rest_error(
			'torobpay_campaign_unauthorized',
			__( 'Invalid authentication credentials.', 'torobpay-for-woocommerce' ),
			401
		);
	}

	if ( ! torobpay_is_campaign_enabled() ) {
		return torobpay_campaign_rest_error(
			'torobpay_campaign_disabled',
			__( 'Campaign data sharing is disabled for this store.', 'torobpay-for-woocommerce' ),
			403
		);
	}

	return true;
}

/**
 * Confirm this store can still authenticate to TorobPay (cached token or OAuth).
 *
 * @return true|WP_Error
 */
function torobpay_campaign_ensure_store_auth() {
	if ( ! class_exists( 'TorobPayAPI' ) ) {
		return torobpay_campaign_rest_error(
			'torobpay_campaign_auth_inactive',
			__( 'TorobPay authentication is unavailable.', 'torobpay-for-woocommerce' ),
			401
		);
	}

	$api = new TorobPayAPI();
	if ( ! $api->ensure_bearer_token() ) {
		return torobpay_campaign_rest_error(
			'torobpay_campaign_auth_inactive',
			__( 'TorobPay authentication failed. Please check your TorobPay credentials in settings.', 'torobpay-for-woocommerce' ),
			401
		);
	}

	return true;
}

/**
 * Handle POST /torobpay/v1/campaign-orders.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error
 */
function torobpay_handle_campaign_orders_request( $request ) {
	$auth = torobpay_campaign_ensure_store_auth();
	if ( is_wp_error( $auth ) ) {
		return $auth;
	}

	$after_ts  = torobpay_parse_campaign_date_param( $request->get_param( 'after' ) );
	$before_ts = torobpay_parse_campaign_date_param( $request->get_param( 'before' ) );

	if ( false === $after_ts || false === $before_ts ) {
		return torobpay_campaign_rest_error(
			'rest_invalid_param',
			__( 'Invalid date range.', 'torobpay-for-woocommerce' ),
			400
		);
	}

	if ( $after_ts > $before_ts ) {
		return torobpay_campaign_rest_error(
			'rest_invalid_param',
			__( 'The after datetime must be before or equal to before.', 'torobpay-for-woocommerce' ),
			400
		);
	}

	$page     = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = (int) $request->get_param( 'per_page' );
	if ( $per_page < 1 ) {
		$per_page = TOROBPAY_CAMPAIGN_DEFAULT_PER_PAGE;
	}
	$per_page = min( TOROBPAY_CAMPAIGN_MAX_PER_PAGE, $per_page );

	$query = torobpay_query_campaign_orders( $after_ts, $before_ts, $page, $per_page );

	torobpay_record_campaign_fetch(
		time(),
		array(
			'page'  => $page,
			'count' => count( $query['orders'] ),
			'total' => $query['total'],
		)
	);

	$response = rest_ensure_response(
		array(
			'orders'      => $query['orders'],
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $query['total'],
			'total_pages' => $query['total_pages'],
		)
	);

	$response->header( 'X-WP-Total', (string) $query['total'] );
	$response->header( 'X-WP-TotalPages', (string) $query['total_pages'] );

	torobpay_campaign_mark_response_uncacheable();

	return torobpay_campaign_apply_nocache_to_response( $response );
}
