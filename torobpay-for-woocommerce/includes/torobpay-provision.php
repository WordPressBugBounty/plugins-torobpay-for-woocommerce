<?php
/**
 * TorobPay credential provisioning with auto-retry and manual fetch.
 *
 * Any request, including Heartbeat, may notice that an attempt is due. Noticing
 * is an option read. The attempt itself runs on a loopback to the public
 * provision-runner route, which probes the verify route on the live REST server
 * and then calls TorobPay. A 60 second lock stops a second notice from starting
 * the same attempt. The 30 minute / 4 hour stamp is written only when that
 * credential call starts. If the loopback never arrives, the next normal
 * wp-admin screen runs the attempt. Action Scheduler is repaired from the
 * runner and from its own callback, with no multi-hour "already checked" flag.
 * Attempts are throttled to once per 30 minutes for the first 3 days, then
 * once per 4 hours for up to 30 days after install, until credentials +
 * successful auth, or until TorobPay returns a terminal error (1033 already
 * provisioned, 1034 auto-activation unavailable, or 1036 registration rejected).
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/torobpay-error-codes.php';

define( 'TOROBPAY_PROVISION_TOKEN_TRANSIENT', 'torobpay_provision_token' );
define( 'TOROBPAY_PROVISION_TOKEN_TTL', 3 * MINUTE_IN_SECONDS );
define( 'TOROBPAY_PROVISION_ENDPOINT', 'api/online/v1/oauth/credential' );
define( 'TOROBPAY_PROVISION_VERIFY_PATH_BASE', '/wp-json/torobpay/v1/provision-verify' );
define( 'TOROBPAY_PROVISION_FAST_RETRY_INTERVAL', 30 * MINUTE_IN_SECONDS );
define( 'TOROBPAY_PROVISION_FAST_WINDOW', 3 * DAY_IN_SECONDS );
define( 'TOROBPAY_PROVISION_RETRY_INTERVAL', 4 * HOUR_IN_SECONDS );
define( 'TOROBPAY_PROVISION_WINDOW', 30 * DAY_IN_SECONDS );
define( 'TOROBPAY_PROVISION_AS_HOOK', 'torobpay_provision_as' );
define( 'TOROBPAY_PROVISION_RUNNER_LOCK_OPTION', 'torobpay_provision_runner_lock' );
define( 'TOROBPAY_PROVISION_RUNNER_LOCK_TTL', 60 );
define( 'TOROBPAY_PROVISION_RUNNER_STALE_AFTER', 2 );
define( 'TOROBPAY_MERCHANT_REGISTRATION_URL', 'https://panel.torob.com/s/torobpayRegisteration' );

// Back-compat alias (pre-4.7.2 path without access key).
if ( ! defined( 'TOROBPAY_PROVISION_VERIFY_PATH' ) ) {
	define( 'TOROBPAY_PROVISION_VERIFY_PATH', TOROBPAY_PROVISION_VERIFY_PATH_BASE );
}

/**
 * Human-friendly message for a provision API error code.
 *
 * @param string|null $error_code Error code from TorobPay.
 * @return string
 */
function torobpay_get_provision_error_message( $error_code ) {
	$definition = torobpay_get_api_error_definition( 'provision', $error_code );

	if ( $definition ) {
		return $definition['message'];
	}

	return __( 'Could not retrieve TorobPay account details.', 'torobpay-for-woocommerce' );
}

/**
 * Normalize a TorobPay error code (string/int/null) to a digit string.
 *
 * @param mixed $error_code Raw error code from the API or a stored option.
 * @return string Empty when the value is not a usable code.
 */
function torobpay_normalize_provision_error_code( $error_code ) {
	return torobpay_normalize_api_error_code( $error_code );
}

/**
 * Read the provision error code from a TorobPay `errorData` envelope, e.g.
 * `{ "successful": false, "errorData": { "errorCode": "1025", ... } }`.
 *
 * @param mixed $body Decoded JSON body.
 * @return string
 */
function torobpay_extract_provision_error_code( $body ) {
	if ( ! is_array( $body ) || ! isset( $body['errorData']['errorCode'] ) ) {
		return '';
	}

	return torobpay_normalize_provision_error_code( $body['errorData']['errorCode'] );
}

/**
 * Read the API-supplied reason string from a provision `errorData` envelope.
 *
 * @param mixed $body Decoded JSON body.
 * @return string|null
 */
function torobpay_extract_provision_api_reason( $body ) {
	if ( ! is_array( $body ) || empty( $body['errorData']['message'] ) || ! is_string( $body['errorData']['message'] ) ) {
		return null;
	}

	return $body['errorData']['message'];
}

/**
 * Whether a decoded provision response is an API error envelope.
 *
 * @param mixed $body Decoded JSON body.
 * @return bool
 */
function torobpay_provision_response_is_api_error( $body ) {
	if ( ! is_array( $body ) ) {
		return false;
	}

	if ( array_key_exists( 'successful', $body ) && empty( $body['successful'] ) ) {
		return true;
	}

	return '' !== torobpay_extract_provision_error_code( $body );
}

/**
 * Format a provision error message for HTML UI (admin notice / toast).
 *
 * @param string      $message    Plain-text message.
 * @param string|null $error_code Optional TorobPay error code.
 * @return string Safe HTML for output.
 */
function torobpay_format_provision_error_notice( $message, $error_code = null ) {
	if ( '1036' === torobpay_normalize_provision_error_code( $error_code ) ) {
		$link = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://panel.torobpay.com/s' ),
			esc_html__( 'Link', 'torobpay-for-woocommerce' )
		);

		return esc_html__( 'Your TorobPay registration was not approved. To see why, ', 'torobpay-for-woocommerce' )
			. $link
			. esc_html__( ' click here.', 'torobpay-for-woocommerce' );
	}

	return esc_html( $message );
}

/**
 * TorobPay merchant registration URL shown when gateway credentials are empty.
 *
 * @return string
 */
function torobpay_get_merchant_registration_url() {
	return TOROBPAY_MERCHANT_REGISTRATION_URL;
}

/**
 * Whether credentials were successfully fetched via the provision API.
 *
 * @return bool
 */
function torobpay_are_credentials_locked() {
	return (bool) get_option( 'torobpay_credentials_locked' );
}

/**
 * Whether the manual fetch control should be shown.
 *
 * Hidden after a successful provision/fetch, or after terminal errors 1033/1034
 * (already provisioned / auto-activation unavailable — credentials must be
 * entered manually from the panel). Not hidden after manual auth alone.
 *
 * @return bool
 */
function torobpay_should_show_manual_fetch() {
	if ( torobpay_are_credentials_locked() ) {
		return false;
	}

	$status = torobpay_get_provision_status();
	if ( in_array( $status, array( 'success', 'stopped_1033', 'stopped_1034' ), true ) ) {
		return false;
	}

	return true;
}

/**
 * Current auto-provision status.
 *
 * @return string One of: '', active, success, stopped_1033, stopped_1034, stopped_1036, stopped_expired, stopped_auth.
 */
function torobpay_get_provision_status() {
	$status = get_option( 'torobpay_provision_status', '' );
	return is_string( $status ) ? $status : '';
}

/**
 * Whether auto-provision retries are still allowed.
 *
 * @return bool
 */
function torobpay_is_auto_provision_active() {
	return 'active' === torobpay_get_provision_status();
}

/**
 * Min seconds between auto-provision attempts for the current phase.
 *
 * @param int $started_at Unix timestamp when the provision window began.
 * @return int
 */
function torobpay_get_provision_retry_interval( $started_at = 0 ) {
	$started_at = (int) $started_at;
	if ( $started_at <= 0 ) {
		$started_at = (int) get_option( 'torobpay_provision_started_at', 0 );
	}

	if ( $started_at > 0 && ( time() - $started_at ) < TOROBPAY_PROVISION_FAST_WINDOW ) {
		return TOROBPAY_PROVISION_FAST_RETRY_INTERVAL;
	}

	return TOROBPAY_PROVISION_RETRY_INTERVAL;
}

/**
 * Stop auto-provision retries and optionally queue an admin notice.
 *
 * @param string $status Terminal status slug.
 * @param bool   $queue_notice Whether to show an admin notice.
 * @return void
 */
function torobpay_stop_auto_provision( $status, $queue_notice = true ) {
	update_option( 'torobpay_provision_status', $status, false );
	delete_option( 'torobpay_provision_pending' );
	torobpay_clear_provision_schedules();

	if ( ! $queue_notice ) {
		return;
	}

	$notice_status = 'success' === $status ? 'success' : 'stopped';

	update_option(
		'torobpay_provision_notice',
		array(
			'status' => $notice_status,
			'reason' => $status,
		),
		false
	);
}

/**
 * Mark credentials as provisioned (read-only) after a successful API fetch.
 *
 * @return void
 */
function torobpay_lock_provisioned_credentials() {
	update_option( 'torobpay_credentials_locked', true, false );
}

/**
 * Build the store verify path TorobPay will fetch (includes a one-time access key).
 *
 * @param string $access_key Secret path segment required to read the provision token.
 * @return string
 */
function torobpay_get_provision_verify_path( $access_key ) {
	return TOROBPAY_PROVISION_VERIFY_PATH_BASE . '/' . rawurlencode( (string) $access_key );
}

/**
 * Store the active provision token + access key for the short verification window.
 *
 * @param string $provision_token Token TorobPay must observe on the store.
 * @param string $access_key      Required path segment to disclose the token.
 * @return void
 */
function torobpay_store_provision_verify_payload( $provision_token, $access_key ) {
	set_transient(
		TOROBPAY_PROVISION_TOKEN_TRANSIENT,
		array(
			'token'      => (string) $provision_token,
			'access_key' => (string) $access_key,
		),
		TOROBPAY_PROVISION_TOKEN_TTL
	);
}

/**
 * Register the verify route on every REST bootstrap.
 *
 * Public by design (TorobPay servers are unauthenticated), but the token is only
 * disclosed when the request URL includes the one-time access_key path segment
 * that was sent to TorobPay as verify_path.
 */
add_action( 'rest_api_init', 'torobpay_register_provision_verify_route' );

/**
 * Route args for the public provision verify endpoint.
 *
 * @return array
 */
function torobpay_provision_verify_route_args() {
	return array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'torobpay_handle_provision_verify_request',
		'permission_callback' => '__return_true',
		'args'                => array(
			'access_key' => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => static function ( $param ) {
					return is_string( $param ) && (bool) preg_match( '/^[a-f0-9]{16}$/', $param );
				},
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	);
}

/**
 * Register the verify route on the current REST server.
 *
 * @return void
 */
function torobpay_register_provision_verify_route() {
	register_rest_route(
		'torobpay/v1',
		'/provision-verify/(?P<access_key>[a-f0-9]{16})',
		torobpay_provision_verify_route_args()
	);

	register_rest_route(
		'torobpay/v1',
		'/provision-runner',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'torobpay_handle_provision_runner_request',
			'permission_callback' => 'torobpay_authorize_provision_runner_request',
			'args'                => array(
				'runner_token' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'torobpay_sanitize_provision_runner_token',
				),
			),
		)
	);
}

/**
 * Keep a runner token that is a 64 character alphanumeric secret.
 *
 * @param mixed $param Raw request value.
 * @return string
 */
function torobpay_sanitize_provision_runner_token( $param ) {
	$param = is_string( $param ) ? $param : '';

	return (bool) preg_match( '/^[A-Za-z0-9]{64}$/', $param ) ? $param : '';
}

/**
 * Confirm the verify route answers before calling TorobPay.
 *
 * On a request whose REST server is already the live one, dispatch there.
 * Otherwise GET the public verify URL. This never creates a REST server and
 * never fires rest_api_init.
 *
 * @param string $provision_token Active provision token.
 * @param string $access_key      Matching access key for the verify URL.
 * @return string ready, unavailable, or unreachable.
 */
function torobpay_probe_provision_verify_endpoint( $provision_token, $access_key ) {
	if ( ! is_string( $provision_token ) || '' === $provision_token ) {
		return 'unavailable';
	}
	if ( ! is_string( $access_key ) || '' === $access_key ) {
		return 'unavailable';
	}

	torobpay_store_provision_verify_payload( $provision_token, $access_key );

	global $wp_rest_server;
	if ( $wp_rest_server instanceof WP_REST_Server && function_exists( 'rest_do_request' ) && class_exists( 'WP_REST_Request' ) ) {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/torobpay/v1/provision-verify/' . $access_key ) );
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_status' ) || ( method_exists( $response, 'is_error' ) && $response->is_error() ) ) {
			$result = 'unavailable';
		} else {
			$result = torobpay_provision_probe_result_from_payload( (int) $response->get_status(), $response->get_data(), $provision_token );
		}
	} else {
		$response = wp_remote_get(
			home_url( torobpay_get_provision_verify_path( $access_key ) ),
			array(
				'timeout'     => 3,
				'redirection' => 0,
				'blocking'    => true,
				'cookies'     => array(),
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress loopback SSL filter.
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		if ( is_wp_error( $response ) ) {
			$result = 'unreachable';
		} else {
			$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$result = torobpay_provision_probe_result_from_payload( (int) wp_remote_retrieve_response_code( $response ), $data, $provision_token );
		}
	}

	if ( 'unavailable' === $result ) {
		delete_transient( TOROBPAY_PROVISION_TOKEN_TRANSIENT );
		return $result;
	}

	// The handler consumes the token. A transport timeout may have consumed it too.
	torobpay_store_provision_verify_payload( $provision_token, $access_key );

	return $result;
}

/**
 * Compare a probe body with the token we just stored.
 *
 * @param int    $code            HTTP status.
 * @param mixed  $data            Decoded response body.
 * @param string $provision_token Expected token.
 * @return string ready or unavailable.
 */
function torobpay_provision_probe_result_from_payload( $code, $data, $provision_token ) {
	$ok = 200 === (int) $code
		&& is_array( $data )
		&& isset( $data['provision_token'] )
		&& is_string( $data['provision_token'] )
		&& hash_equals( $provision_token, $data['provision_token'] );

	return $ok ? 'ready' : 'unavailable';
}

/**
 * Return the active provision token when the request includes a matching access_key.
 *
 * @param WP_REST_Request $request Incoming REST request.
 * @return WP_REST_Response
 */
function torobpay_handle_provision_verify_request( $request ) {
	$payload = get_transient( TOROBPAY_PROVISION_TOKEN_TRANSIENT );

	// Legacy shape (pre-4.7.2): bare token string — treat as unavailable so callers
	// must re-run provisioning with the access_key-gated flow.
	if ( empty( $payload ) || ! is_array( $payload ) ) {
		return new WP_REST_Response( null, 404 );
	}

	$token      = isset( $payload['token'] ) ? (string) $payload['token'] : '';
	$access_key = isset( $payload['access_key'] ) ? (string) $payload['access_key'] : '';
	$provided   = (string) $request['access_key'];

	if ( '' === $token || '' === $access_key || '' === $provided || ! hash_equals( $access_key, $provided ) ) {
		return new WP_REST_Response( null, 404 );
	}

	// One-time disclosure: consume after a successful authenticated read.
	delete_transient( TOROBPAY_PROVISION_TOKEN_TRANSIENT );

	return rest_ensure_response(
		array(
			'provision_token' => $token,
		)
	);
}

/**
 * One-time migration from the old single-attempt provision flags.
 */
add_action( 'admin_init', 'torobpay_migrate_provision_state', 4 );
function torobpay_migrate_provision_state() {
	if ( get_option( 'torobpay_provision_state_migrated' ) ) {
		return;
	}

	$legacy_attempted = (bool) get_option( 'torobpay_auto_provision_attempted' );
	$current_status   = torobpay_get_provision_status();

	if ( '' === $current_status ) {
		if ( torobpay_are_credentials_locked() ) {
			update_option( 'torobpay_provision_status', 'success', false );
		} elseif ( torobpay_has_credentials() && get_option( 'torobpay_auth_ok' ) ) {
			update_option( 'torobpay_provision_status', 'stopped_auth', false );
		} elseif ( $legacy_attempted || get_option( 'torobpay_provision_pending' ) || ! torobpay_has_credentials() ) {
			// Re-open retries under the new windowed model when credentials are missing.
			if ( ! torobpay_has_credentials() ) {
				update_option( 'torobpay_provision_status', 'active', false );
				if ( ! get_option( 'torobpay_provision_started_at' ) ) {
					update_option( 'torobpay_provision_started_at', time(), false );
				}
				update_option( 'torobpay_provision_pending', true, false );
			}
		}
	}

	delete_option( 'torobpay_auto_provision_attempted' );
	update_option( 'torobpay_provision_state_migrated', true, false );
}

/**
 * Mark auto-provision as active on activation when credentials are absent.
 */
add_action( 'torobpay_wc_gateway_activated', 'torobpay_mark_provision_pending_on_activation' );
function torobpay_mark_provision_pending_on_activation() {
	if ( torobpay_has_credentials() || torobpay_are_credentials_locked() ) {
		return;
	}

	$status = torobpay_get_provision_status();
	if ( in_array( $status, array( 'success', 'stopped_1033', 'stopped_1034', 'stopped_1036', 'stopped_expired', 'stopped_auth' ), true ) ) {
		return;
	}

	update_option( 'torobpay_provision_status', 'active', false );
	update_option( 'torobpay_provision_pending', true, false );

	if ( ! get_option( 'torobpay_provision_started_at' ) ) {
		update_option( 'torobpay_provision_started_at', time(), false );
	}

	torobpay_ensure_provision_schedules();
}

/**
 * Ensure an active retry window exists for installs/upgrades without credentials.
 */
add_action( 'admin_init', 'torobpay_maybe_mark_provision_pending', 5 );
function torobpay_maybe_mark_provision_pending() {
	if ( torobpay_are_credentials_locked() || torobpay_has_credentials() ) {
		return;
	}

	$status = torobpay_get_provision_status();
	if ( in_array( $status, array( 'success', 'stopped_1033', 'stopped_1034', 'stopped_1036', 'stopped_expired', 'stopped_auth', 'active' ), true ) ) {
		return;
	}

	update_option( 'torobpay_provision_status', 'active', false );
	update_option( 'torobpay_provision_pending', true, false );

	if ( ! get_option( 'torobpay_provision_started_at' ) ) {
		update_option( 'torobpay_provision_started_at', time(), false );
	}
}

/**
 * Stop auto-retries once credentials exist and auth has succeeded.
 */
add_action( 'admin_init', 'torobpay_maybe_stop_provision_after_auth', 15 );
function torobpay_maybe_stop_provision_after_auth() {
	if ( ! torobpay_is_auto_provision_active() ) {
		return;
	}

	if ( torobpay_has_credentials() && get_option( 'torobpay_auth_ok' ) ) {
		torobpay_stop_auto_provision( 'stopped_auth', false );
		delete_option( 'torobpay_provision_error' );
		delete_option( 'torobpay_provision_notice' );
	}
}

/**
 * Schedule the Action Scheduler runner while provision is active.
 *
 * Uses the current phase interval (30 minutes for the first 3 days, then
 * 4 hours). Safe to call repeatedly; recreates the schedule when missing or
 * when the phase interval has changed.
 *
 * @return void
 */
function torobpay_ensure_provision_schedules() {
	if ( ! torobpay_is_auto_provision_active() ) {
		return;
	}

	if (
		! function_exists( 'as_has_scheduled_action' )
		|| ! function_exists( 'as_schedule_recurring_action' )
		|| ! function_exists( 'as_unschedule_all_actions' )
	) {
		return;
	}

	try {
		$desired_interval   = torobpay_get_provision_retry_interval();
		$scheduled_interval = (int) get_option( 'torobpay_provision_as_interval', 0 );
		$has_schedule       = as_has_scheduled_action( TOROBPAY_PROVISION_AS_HOOK, array(), 'torobpay' );

		if ( $has_schedule && $scheduled_interval === $desired_interval ) {
			return;
		}

		if ( $has_schedule || $scheduled_interval > 0 ) {
			as_unschedule_all_actions( TOROBPAY_PROVISION_AS_HOOK, array(), 'torobpay' );
		}

		as_schedule_recurring_action(
			time() + MINUTE_IN_SECONDS,
			$desired_interval,
			TOROBPAY_PROVISION_AS_HOOK,
			array(),
			'torobpay',
			true
		);
		update_option( 'torobpay_provision_as_interval', $desired_interval, false );
	} catch ( \Throwable $e ) {
		torobpay_provision_log( 'scheduler skipped: ' . $e->getMessage() );
	}
}

/**
 * Clear Action Scheduler provision runners.
 *
 * @return void
 */
function torobpay_clear_provision_schedules() {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		try {
			as_unschedule_all_actions( TOROBPAY_PROVISION_AS_HOOK, array(), 'torobpay' );
		} catch ( \Throwable $e ) {
			torobpay_provision_log( 'scheduler clear skipped: ' . $e->getMessage() );
		}
	}
	delete_option( 'torobpay_provision_as_interval' );
	delete_option( TOROBPAY_PROVISION_RUNNER_LOCK_OPTION );
}

/**
 * Log a provision diagnostic when the logger is loaded.
 *
 * @param string $message Message without the TorobPay prefix.
 * @return void
 */
function torobpay_provision_log( $message ) {
	if ( function_exists( 'torobpay_debug_log' ) ) {
		torobpay_debug_log( 'TorobPay Provision: ' . $message );
	}
}

/**
 * Whether this request is a normal wp-admin screen.
 *
 * Heartbeat, admin-ajax, admin-post, and cron are not screen loads. Only a
 * screen may run an attempt inline, and only after its loopback did not arrive.
 *
 * @return bool
 */
function torobpay_is_provision_admin_screen_request() {
	if ( ! is_admin() ) {
		return false;
	}

	if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
		return false;
	}

	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		return false;
	}

	if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
		return false;
	}

	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return false;
	}

	global $pagenow;
	if ( is_string( $pagenow ) && in_array( $pagenow, array( 'admin-ajax.php', 'admin-post.php' ), true ) ) {
		return false;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only action name so Heartbeat is not a provision attempt.
	if ( isset( $_REQUEST['action'] ) ) {
		$action = wp_unslash( $_REQUEST['action'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only action name.
		if ( is_string( $action ) && 'heartbeat' === sanitize_key( $action ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Mark the 30 day window finished when it has elapsed.
 *
 * @return bool True when the window was stopped on this call.
 */
function torobpay_expire_provision_window_if_needed() {
	if ( ! torobpay_is_auto_provision_active() ) {
		return false;
	}

	$started_at = (int) get_option( 'torobpay_provision_started_at', 0 );
	if ( $started_at <= 0 || ( time() - $started_at ) < TOROBPAY_PROVISION_WINDOW ) {
		return false;
	}

	update_option(
		'torobpay_provision_error',
		array(
			'errorCode' => null,
			'reason'    => 'window_expired',
			'message'   => 'provision window expired',
			'timestamp' => time(),
		),
		false
	);
	torobpay_stop_auto_provision( 'stopped_expired', true );

	return true;
}

/**
 * Whether an automatic credential call is allowed right now.
 *
 * @return bool
 */
function torobpay_auto_provision_attempt_is_due() {
	if ( ! torobpay_is_auto_provision_active() || ! class_exists( 'WooCommerce' ) ) {
		return false;
	}

	$started_at = (int) get_option( 'torobpay_provision_started_at', 0 );
	if ( $started_at <= 0 ) {
		return true;
	}

	if ( ( time() - $started_at ) >= TOROBPAY_PROVISION_WINDOW ) {
		return false;
	}

	$last_attempt = (int) get_option( 'torobpay_provision_last_attempt_at', 0 );
	$interval     = torobpay_get_provision_retry_interval( $started_at );

	return ! ( $last_attempt > 0 && ( time() - $last_attempt ) < $interval );
}

/**
 * Action Scheduler entry point for auto-provision.
 *
 * The callback is already off the page request. It runs the attempt when no
 * loopback owns the lock, then repairs the recurrence. A repair that does
 * nothing is tried again on the next callback.
 *
 * @return void
 */
add_action( TOROBPAY_PROVISION_AS_HOOK, 'torobpay_run_auto_provision_from_as' );
function torobpay_run_auto_provision_from_as() {
	if ( ! empty( $GLOBALS['torobpay_provision_runner_handling'] ) ) {
		return;
	}

	if ( torobpay_expire_provision_window_if_needed() ) {
		return;
	}

	$lock = get_option( TOROBPAY_PROVISION_RUNNER_LOCK_OPTION, false );
	if ( torobpay_provision_runner_lock_is_active( $lock, time() ) ) {
		torobpay_ensure_provision_schedules();
		return;
	}

	if ( ! torobpay_auto_provision_attempt_is_due() ) {
		torobpay_ensure_provision_schedules();
		return;
	}

	$token = torobpay_acquire_provision_runner_lock();
	if ( null === $token || ! torobpay_claim_provision_runner_lock( $token ) ) {
		return;
	}

	torobpay_finish_provision_runner();
}

/**
 * Run one automatic attempt when the retry window allows it.
 *
 * The runner and Action Scheduler are already off the merchant's page, so
 * this does not depend on the current user.
 *
 * @return void
 */
function torobpay_maybe_attempt_auto_provision() {
	if ( torobpay_are_credentials_locked() ) {
		return;
	}

	if ( torobpay_has_credentials() && get_option( 'torobpay_auth_ok' ) ) {
		if ( torobpay_is_auto_provision_active() ) {
			torobpay_stop_auto_provision( 'stopped_auth', false );
			delete_option( 'torobpay_provision_error' );
			delete_option( 'torobpay_provision_notice' );
		}
		return;
	}

	if ( ! torobpay_is_auto_provision_active() ) {
		return;
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	$started_at = (int) get_option( 'torobpay_provision_started_at', 0 );
	if ( $started_at <= 0 ) {
		$started_at = time();
		update_option( 'torobpay_provision_started_at', $started_at, false );
	}

	if ( torobpay_expire_provision_window_if_needed() ) {
		return;
	}

	if ( ! torobpay_auto_provision_attempt_is_due() ) {
		return;
	}

	try {
		torobpay_run_provision( 'auto' );
	} catch ( \Throwable $e ) {
		torobpay_provision_log( 'attempt failed: ' . $e->getMessage() );
	}
}

/**
 * Execute the provision API call and persist results.
 *
 * @param string $context 'auto' or 'manual'.
 * @return array{success:bool,error_code:?string,message:string,locked:bool}
 */
function torobpay_run_provision( $context = 'auto' ) {
	$context = 'manual' === $context ? 'manual' : 'auto';

	$provision_token = bin2hex( random_bytes( 32 ) );
	$access_key      = bin2hex( random_bytes( 8 ) );
	$merchant_domain = torobpay_get_merchant_domain();
	$verify_path     = torobpay_get_provision_verify_path( $access_key );
	$probe           = torobpay_probe_provision_verify_endpoint( $provision_token, $access_key );

	if ( 'ready' !== $probe && 'unreachable' !== $probe ) {
		torobpay_provision_log(
			sprintf(
				'verify endpoint not ready for %s (%s); will retry later.',
				$merchant_domain ? $merchant_domain : '(unresolved domain)',
				$context
			)
		);

		return array(
			'success'    => false,
			'error_code' => null,
			'message'    => __( 'Store verification URL is not ready yet. Please try again in a moment.', 'torobpay-for-woocommerce' ),
			'locked'     => torobpay_are_credentials_locked(),
		);
	}

	$success       = false;
	$error_code    = null;
	$api_reason    = null;
	$error_message = '';

	if ( $merchant_domain ) {
		// Stamp only once the credential call is about to start. A killed POST
		// waits for the retry interval. A failed probe does not.
		// Manual fetch does not write this, so a click is never blocked by it.
		if ( 'auto' === $context ) {
			update_option( 'torobpay_provision_last_attempt_at', time(), false );
		}

		$response = wp_remote_post(
			torobpay_get_provision_api_url(),
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'merchant_domain' => $merchant_domain,
						'provision_token' => $provision_token,
						'verify_path'     => $verify_path,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$api_reason    = $error_message;
		} else {
			$status_code = (int) wp_remote_retrieve_response_code( $response );
			$body        = json_decode( wp_remote_retrieve_body( $response ), true );
			$parsed_code = torobpay_extract_provision_error_code( $body );
			$error_code  = '' !== $parsed_code ? $parsed_code : null;

			if ( 200 === $status_code && is_array( $body ) && ! torobpay_provision_response_is_api_error( $body ) ) {
				$success = torobpay_save_provisioned_credentials( $body );
				if ( ! $success ) {
					$error_message = __( 'Invalid response received from TorobPay.', 'torobpay-for-woocommerce' );
					$api_reason    = 'invalid provision response body';
				}
			} elseif ( null !== $error_code ) {
				$api_reason    = torobpay_extract_provision_api_reason( $body );
				$error_message = torobpay_get_provision_error_message( $error_code );
			} else {
				$error_message = __( 'Invalid response received from TorobPay. (HTTP ', 'torobpay-for-woocommerce' ) . $status_code . ')';
				$api_reason    = 'invalid provision HTTP response (' . $status_code . ')';
			}
		}
	} else {
		$error_message = __( 'Store URL could not be detected.', 'torobpay-for-woocommerce' );
		$api_reason    = 'unresolved merchant domain';
	}

	delete_transient( TOROBPAY_PROVISION_TOKEN_TRANSIENT );

	if ( $success ) {
		torobpay_lock_provisioned_credentials();
		delete_option( 'torobpay_provision_pending' );
		delete_option( 'torobpay_provision_error' );
		torobpay_stop_auto_provision( 'success', 'auto' === $context );

		if ( 'manual' === $context ) {
			update_option(
				'torobpay_provision_notice',
				array(
					'status' => 'success',
					'reason' => 'success',
				),
				false
			);
		}

		return array(
			'success'    => true,
			'error_code' => null,
			'message'    => __( 'TorobPay account details were retrieved successfully.', 'torobpay-for-woocommerce' ),
			'locked'     => true,
		);
	}

	update_option(
		'torobpay_provision_error',
		array(
			'errorCode' => $error_code,
			'reason'    => $api_reason,
			'message'   => $error_message,
			'timestamp' => time(),
			'context'   => $context,
		),
		false
	);

	torobpay_provision_log(
		sprintf(
			'%s provision failed for %s — errorCode=%s reason=%s message=%s',
			$context,
			$merchant_domain ? $merchant_domain : '(unresolved domain)',
			$error_code ? $error_code : 'n/a',
			$api_reason ? $api_reason : 'n/a',
			$error_message
		)
	);

	// Terminal for auto-retries: already provisioned, auto-activation unavailable, or registration rejected.
	if ( '1033' === $error_code ) {
		torobpay_stop_auto_provision( 'stopped_1033', true );
	} elseif ( '1034' === $error_code ) {
		torobpay_stop_auto_provision( 'stopped_1034', true );
	} elseif ( '1036' === $error_code ) {
		torobpay_stop_auto_provision( 'stopped_1036', true );
	}

	return array(
		'success'    => false,
		'error_code' => $error_code,
		'message'    => $error_message,
		'locked'     => torobpay_are_credentials_locked(),
	);
}

/**
 * Notice that an attempt is due, then hand it to the public runner route.
 *
 * Runs on init so the notice is not a shutdown hook. The notice is an option
 * read, plus one non-blocking loopback when an attempt is due. A normal
 * wp-admin screen runs the attempt inline only when that loopback failed
 * immediately or never claimed the lock.
 *
 * The reset tool defines TOROBPAY_SKIP_PROVISION_DISPATCH before WordPress
 * loads, so opening that page does not itself start an attempt.
 */
add_action( 'init', 'torobpay_maybe_dispatch_provision_runner', 20 );
function torobpay_maybe_dispatch_provision_runner() {
	if ( defined( 'TOROBPAY_SKIP_PROVISION_DISPATCH' ) && TOROBPAY_SKIP_PROVISION_DISPATCH ) {
		return;
	}

	if ( ! empty( $GLOBALS['torobpay_provision_runner_handling'] ) ) {
		return;
	}

	if ( torobpay_expire_provision_window_if_needed() ) {
		return;
	}

	$lock = get_option( TOROBPAY_PROVISION_RUNNER_LOCK_OPTION, false );
	if ( torobpay_is_provision_admin_screen_request() && torobpay_provision_runner_lock_is_stale_dispatch( $lock ) ) {
		$token = ( is_array( $lock ) && isset( $lock['token'] ) ) ? (string) $lock['token'] : '';
		if ( '' !== $token && torobpay_claim_provision_runner_lock( $token ) ) {
			torobpay_finish_provision_runner();
		}
		return;
	}

	if ( ! torobpay_auto_provision_attempt_is_due() ) {
		return;
	}

	if ( torobpay_provision_runner_lock_is_active( $lock, time() ) ) {
		return;
	}

	$token = torobpay_acquire_provision_runner_lock();
	if ( null === $token ) {
		return;
	}

	$response = wp_remote_post(
		rest_url( 'torobpay/v1/provision-runner' ),
		array(
			'timeout'     => 0.01,
			'redirection' => 0,
			'httpversion' => '1.1',
			'blocking'    => false,
			'body'        => array(
				'runner_token' => $token,
			),
			'cookies'     => array(),
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress loopback SSL filter.
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
		)
	);

	if ( ! is_wp_error( $response ) ) {
		return;
	}

	torobpay_provision_log( 'runner loopback failed: ' . $response->get_error_message() );

	if ( ! torobpay_is_provision_admin_screen_request() ) {
		return;
	}

	if ( torobpay_claim_provision_runner_lock( $token ) ) {
		torobpay_finish_provision_runner();
	}
}

/**
 * Run one claimed automatic attempt, then keep the lock honest.
 *
 * A credential call writes the long retry stamp and the lock can go. A probe
 * that did not call TorobPay keeps a 60 second cooldown so Heartbeat cannot
 * start the same attempt on every tick. Scheduler repair stays off wp-admin
 * screens, where a stuck Action Scheduler would block the merchant.
 *
 * @return void
 */
function torobpay_finish_provision_runner() {
	$GLOBALS['torobpay_provision_runner_handling'] = true;

	$before = (int) get_option( 'torobpay_provision_last_attempt_at', 0 );
	torobpay_maybe_attempt_auto_provision();
	$after  = (int) get_option( 'torobpay_provision_last_attempt_at', 0 );
	if ( $after === $before ) {
		torobpay_cooldown_provision_runner_lock();
	} else {
		delete_option( TOROBPAY_PROVISION_RUNNER_LOCK_OPTION );
	}

	if ( ! torobpay_is_provision_admin_screen_request() ) {
		torobpay_ensure_provision_schedules();
	}
}

/**
 * Authorize the loopback that carries the current dispatched token.
 *
 * @param WP_REST_Request $request Incoming runner request.
 * @return true|WP_Error
 */
function torobpay_authorize_provision_runner_request( $request ) {
	if ( null !== torobpay_get_matching_dispatched_runner_lock( torobpay_provision_runner_request_token( $request ) ) ) {
		return true;
	}

	return new WP_Error(
		'torobpay_invalid_runner_token',
		__( 'A valid provision runner token is required.', 'torobpay-for-woocommerce' ),
		array( 'status' => 401 )
	);
}

/**
 * Claim the lock and run the attempt for one loopback.
 *
 * @param WP_REST_Request $request Incoming runner request.
 * @return WP_REST_Response
 */
function torobpay_handle_provision_runner_request( $request ) {
	if ( ! torobpay_claim_provision_runner_lock( torobpay_provision_runner_request_token( $request ) ) ) {
		return new WP_REST_Response( array( 'success' => false ), 409 );
	}

	torobpay_finish_provision_runner();

	return new WP_REST_Response( array( 'success' => true ), 200 );
}

/**
 * Read the runner token from a REST request.
 *
 * @param mixed $request Incoming request.
 * @return string
 */
function torobpay_provision_runner_request_token( $request ) {
	if ( ! is_object( $request ) || ! method_exists( $request, 'get_param' ) ) {
		return '';
	}

	$param = $request->get_param( 'runner_token' );

	return is_string( $param ) ? $param : '';
}

/**
 * Load the dispatched lock when the presented token matches it.
 *
 * @param string $token Presented runner token.
 * @return array<string, mixed>|null
 */
function torobpay_get_matching_dispatched_runner_lock( $token ) {
	$lock = get_option( TOROBPAY_PROVISION_RUNNER_LOCK_OPTION, false );
	if ( ! is_array( $lock ) || 'dispatched' !== ( $lock['state'] ?? '' ) ) {
		return null;
	}
	if ( ! torobpay_provision_runner_lock_is_active( $lock, time() ) ) {
		return null;
	}

	$stored = isset( $lock['token'] ) ? (string) $lock['token'] : '';
	if ( '' === $token || '' === $stored || ! hash_equals( $stored, (string) $token ) ) {
		return null;
	}

	return $lock;
}

/**
 * Take the short lock that suppresses a second notice.
 *
 * @return string|null Token when this request owns a new dispatched lock.
 */
function torobpay_acquire_provision_runner_lock() {
	$now      = time();
	$existing = get_option( TOROBPAY_PROVISION_RUNNER_LOCK_OPTION, false );
	if ( torobpay_provision_runner_lock_is_active( $existing, $now ) ) {
		return null;
	}

	$token = wp_generate_password( 64, false, false );
	$lock  = array(
		'token'         => $token,
		'state'         => 'dispatched',
		'expires_at'    => $now + TOROBPAY_PROVISION_RUNNER_LOCK_TTL,
		'dispatched_at' => $now,
	);

	if ( false === $existing ) {
		$acquired = add_option( TOROBPAY_PROVISION_RUNNER_LOCK_OPTION, $lock, '', false );
	} else {
		$acquired = torobpay_replace_provision_runner_lock( $existing, $lock );
	}

	return $acquired ? $token : null;
}

/**
 * Move a dispatched lock to claimed so only one worker runs the attempt.
 *
 * @param string $token Token returned by the acquire call.
 * @return bool
 */
function torobpay_claim_provision_runner_lock( $token ) {
	$lock = torobpay_get_matching_dispatched_runner_lock( $token );
	if ( null === $lock ) {
		return false;
	}

	$claimed = array(
		'token'         => '',
		'state'         => 'claimed',
		'expires_at'    => (int) $lock['expires_at'],
		'dispatched_at' => isset( $lock['dispatched_at'] ) ? (int) $lock['dispatched_at'] : time(),
	);

	return torobpay_replace_provision_runner_lock( $lock, $claimed );
}

/**
 * Hold the lock for one minute after an attempt that did not call TorobPay.
 *
 * @return void
 */
function torobpay_cooldown_provision_runner_lock() {
	$current = get_option( TOROBPAY_PROVISION_RUNNER_LOCK_OPTION, false );
	if ( ! is_array( $current ) ) {
		return;
	}

	$cooldown = array(
		'token'         => '',
		'state'         => 'cooldown',
		'expires_at'    => time() + TOROBPAY_PROVISION_RUNNER_LOCK_TTL,
		'dispatched_at' => time(),
	);
	torobpay_replace_provision_runner_lock( $current, $cooldown );
}

/**
 * Whether a stored lock still suppresses a new notice.
 *
 * @param mixed $lock Stored lock, or false when the option is missing.
 * @param int   $now  Current timestamp.
 * @return bool
 */
function torobpay_provision_runner_lock_is_active( $lock, $now ) {
	return is_array( $lock ) && isset( $lock['expires_at'] ) && (int) $lock['expires_at'] > (int) $now;
}

/**
 * Whether a dispatched loopback has had time to claim and has not.
 *
 * @param mixed $lock Stored lock.
 * @return bool
 */
function torobpay_provision_runner_lock_is_stale_dispatch( $lock ) {
	if ( ! is_array( $lock ) || 'dispatched' !== ( $lock['state'] ?? '' ) ) {
		return false;
	}
	if ( ! torobpay_provision_runner_lock_is_active( $lock, time() ) ) {
		return false;
	}

	$dispatched_at = isset( $lock['dispatched_at'] ) ? (int) $lock['dispatched_at'] : 0;

	return $dispatched_at > 0 && ( time() - $dispatched_at ) >= TOROBPAY_PROVISION_RUNNER_STALE_AFTER;
}

/**
 * Replace the runner lock only when its stored value is unchanged.
 *
 * @param mixed                $expected_lock    Value currently stored.
 * @param array<string, mixed> $replacement_lock Value to store.
 * @return bool
 */
function torobpay_replace_provision_runner_lock( $expected_lock, array $replacement_lock ) {
	global $wpdb;

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) || ! isset( $wpdb->options ) ) {
		return false;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap for the runner lock.
	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
			maybe_serialize( $replacement_lock ),
			TOROBPAY_PROVISION_RUNNER_LOCK_OPTION,
			maybe_serialize( $expected_lock )
		)
	);
	wp_cache_delete( TOROBPAY_PROVISION_RUNNER_LOCK_OPTION, 'options' );

	return 1 === (int) $updated;
}

/**
 * Get TorobPay API base URL, mirroring TorobPayAPI::get_api_base_url() so the
 * provisioning call always targets the same host as the payment gateway.
 *
 * @return string
 */
function torobpay_get_provision_api_base_url() {
	$settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings' );

	if ( ! is_array( $settings ) || empty( $settings['base_url'] ) ) {
		return 'https://cpg.torobpay.com/';
	}

	$base = $settings['base_url'];

	return substr( $base, -1 ) === '/' ? $base : $base . '/';
}

/**
 * Full provision endpoint URL.
 *
 * @return string
 */
function torobpay_get_provision_api_url() {
	return torobpay_get_provision_api_base_url() . TOROBPAY_PROVISION_ENDPOINT;
}

/**
 * Derive the root (home) address sent to TorobPay as merchant_domain.
 *
 * Format: no scheme, no "www." prefix, host + trailing-slashed path for
 * subfolder installs (e.g. "myshop.com", "sub.myshop.com", "myshop.com/root/").
 *
 * @return string|false
 */
function torobpay_get_merchant_domain() {
	$parsed = wp_parse_url( home_url() );

	if ( empty( $parsed['host'] ) ) {
		return false;
	}

	$host = strtolower( $parsed['host'] );

	if ( strpos( $host, 'www.' ) === 0 ) {
		$host = substr( $host, 4 );
	}

	$path = isset( $parsed['path'] ) ? trim( $parsed['path'], '/' ) : '';

	return '' !== $path ? $host . '/' . $path . '/' : $host;
}

/**
 * Map provision API credentials into gateway settings.
 *
 * @param array $body API response body.
 * @return bool
 */
function torobpay_save_provisioned_credentials( $body ) {
	$required = array( 'client_id', 'client_secret', 'username', 'password' );

	foreach ( $required as $key ) {
		if ( empty( $body[ $key ] ) || ! is_string( $body[ $key ] ) ) {
			return false;
		}
	}

	$settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	$settings['client_id']       = sanitize_text_field( $body['client_id'] );
	$settings['client_secret']   = sanitize_text_field( str_replace( 'amp;', '', $body['client_secret'] ) );
	$settings['client_username'] = sanitize_text_field( $body['username'] );
	$settings['client_password'] = sanitize_text_field( str_replace( 'amp;', '', $body['password'] ) );
	$settings['enabled']         = 'yes';

	if ( empty( $settings['base_url'] ) ) {
		$settings['base_url'] = 'https://cpg.torobpay.com/';
	}

	update_option( 'woocommerce_WC_Gateway_TorobPay_settings', $settings ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WooCommerce Settings API key: woocommerce_{gateway_id}_settings. Not a plugin-owned option name.

	if ( function_exists( 'torobpay_get_settings' ) ) {
		torobpay_get_settings( true );
	}

	if ( class_exists( 'TorobPayAPI' ) ) {
		$api = new TorobPayAPI();
		$api->ensure_bearer_token();
	}

	return torobpay_has_credentials();
}

/**
 * AJAX: manually fetch TorobPay credentials via the provision API.
 */
add_action( 'wp_ajax_torobpay_fetch_credentials', 'torobpay_ajax_fetch_credentials' );
function torobpay_ajax_fetch_credentials() {
	check_ajax_referer( 'torobpay_fetch_credentials', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Unauthorized', 'torobpay-for-woocommerce' ),
			),
			403
		);
	}

	if ( ! torobpay_should_show_manual_fetch() ) {
		if ( torobpay_are_credentials_locked() || 'success' === torobpay_get_provision_status() ) {
			wp_send_json_success(
				array(
					'message'    => __( 'Account details were already retrieved and are locked.', 'torobpay-for-woocommerce' ),
					'locked'     => true,
					'hide_fetch' => true,
				)
			);
		}

		$status       = torobpay_get_provision_status();
		$error_code   = ( 'stopped_1034' === $status ) ? '1034' : '1033';
		$message      = torobpay_get_provision_error_message( $error_code );
		$message_html = torobpay_format_provision_error_notice( $message, $error_code );

		wp_send_json_error(
			array(
				'message'      => $message,
				'message_html' => $message_html,
				'error_code'   => $error_code,
				'locked'       => false,
				'hide_fetch'   => true,
			)
		);
	}

	$result = torobpay_run_provision( 'manual' );

	if ( ! empty( $result['success'] ) ) {
		wp_send_json_success(
			array(
				'message'    => $result['message'],
				'locked'     => true,
				'reload'     => true,
				'hide_fetch' => true,
			)
		);
	}

	$error_code   = isset( $result['error_code'] ) ? $result['error_code'] : null;
	$message_html = torobpay_format_provision_error_notice( $result['message'], $error_code );
	$hide_fetch   = ! torobpay_should_show_manual_fetch();

	wp_send_json_error(
		array(
			'message'      => $result['message'],
			'message_html' => $message_html,
			'error_code'   => $error_code,
			'locked'       => ! empty( $result['locked'] ),
			'hide_fetch'   => $hide_fetch,
		)
	);
}

/**
 * Localize/enqueue assets used by the manual fetch button (settings + notices).
 *
 * @return void
 */
function torobpay_enqueue_provision_fetch_assets() {
	static $done = false;

	if ( $done || ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$done = true;

	if ( ! wp_script_is( 'jquery', 'enqueued' ) && ! wp_script_is( 'jquery', 'done' ) ) {
		wp_enqueue_script( 'jquery' );
	}

	if ( function_exists( 'torobpay_enqueue_admin_toast_assets' ) ) {
		torobpay_enqueue_admin_toast_assets();
	}

	$handle  = 'torobpay-admin-gateway';
	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';

	if ( ! wp_script_is( $handle, 'registered' ) ) {
		wp_register_script(
			$handle,
			plugins_url( 'assets/js/admin-gateway.js', TOROBPAY_PLUGIN_FILE ),
			array( 'jquery', 'wp-i18n' ),
			$version,
			true
		);
	}

	wp_enqueue_script( $handle );
	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( $handle, 'torobpay-for-woocommerce', dirname( TOROBPAY_PLUGIN_FILE ) . '/languages' );
	}

	wp_localize_script(
		$handle,
		'torobpayProvision',
		array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'torobpay_fetch_credentials' ),
			'fetchingText' => __( 'Fetching…', 'torobpay-for-woocommerce' ),
			'defaultError' => __( 'Fetching account details failed.', 'torobpay-for-woocommerce' ),
			'settingsUrl'  => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_torobpay' ),
		)
	);
}

/**
 * Ensure fetch-button JS is available when auth/provision notices need it.
 */
add_action( 'admin_enqueue_scripts', 'torobpay_maybe_enqueue_provision_fetch_assets', 30 );
function torobpay_maybe_enqueue_provision_fetch_assets() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$need = false;

	if ( torobpay_should_show_manual_fetch() ) {
		$auth_error = get_option( 'torobpay_auth_error' );
		$notice     = get_option( 'torobpay_provision_notice' );

		if ( ! empty( $auth_error ) && is_array( $auth_error ) ) {
			$need = true;
		}

		if ( ! empty( $notice ) && is_array( $notice ) && ! empty( $notice['status'] ) && 'success' !== $notice['status'] ) {
			$need = true;
		}

		$wc_section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only WC settings section for asset enqueue.
		if ( 'wc_gateway_torobpay' === strtolower( $wc_section ) ) {
			$need = true;
		}
	}

	if ( $need ) {
		torobpay_enqueue_provision_fetch_assets();
	}
}

/**
 * Markup for the manual fetch credentials button.
 *
 * @param string $extra_class Optional extra CSS classes.
 * @param string $label       Button label.
 * @return string
 */
function torobpay_get_manual_fetch_button_html( $extra_class = '', $label = '' ) {
	if ( ! torobpay_should_show_manual_fetch() ) {
		return '';
	}

	if ( '' === $label ) {
		$label = __( 'Fetch account details from TorobPay', 'torobpay-for-woocommerce' );
	}

	$class = trim( 'button button-secondary torobpay-fetch-credentials-btn ' . $extra_class );

	return sprintf(
		'<button type="button" class="%1$s" data-torobpay-fetch-credentials="1">%2$s</button>',
		esc_attr( $class ),
		esc_html( $label )
	);
}

/**
 * Render auto-provision result / stop notices.
 */
add_action( 'admin_notices', 'torobpay_render_provision_notice' );
function torobpay_render_provision_notice() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	// Scoped to TorobPay / WooCommerce admin screens (Guideline 11).
	if ( ! function_exists( 'torobpay_should_render_scoped_admin_notice' )
		|| ! torobpay_should_render_scoped_admin_notice( true ) ) {
		return;
	}

	$notice = get_option( 'torobpay_provision_notice' );

	if ( empty( $notice ) || ! is_array( $notice ) || empty( $notice['status'] ) ) {
		return;
	}

	$status = $notice['status'];
	$reason = isset( $notice['reason'] ) ? $notice['reason'] : '';

	// A failed/stopped notice becomes stale after credentials are entered manually
	// and auth succeeds — or after a successful locked fetch.
	if ( 'success' !== $status ) {
		if ( function_exists( 'torobpay_get_settings' ) ) {
			torobpay_get_settings( true );
		}

		if ( torobpay_are_credentials_locked() || ( torobpay_has_credentials() && get_option( 'torobpay_auth_ok' ) ) ) {
			delete_option( 'torobpay_provision_notice' );
			delete_option( 'torobpay_provision_error' );
			return;
		}
	}

	$show_fetch = torobpay_should_show_manual_fetch();

	if ( 'success' === $status ) {
		$class        = 'notice-success';
		$message_html = esc_html( __( 'TorobPay account details were retrieved successfully.', 'torobpay-for-woocommerce' ) );
	} elseif ( 'stopped_expired' === $reason ) {
		$class        = 'notice-warning';
		$message_html = esc_html( __( 'Automatic account fetch failed over the past 30 days and will not retry automatically. Use the manual fetch button and check your account in the TorobPay panel.', 'torobpay-for-woocommerce' ) );
	} elseif ( 'stopped_1033' === $reason ) {
		$class        = 'notice-warning';
		$message_html = torobpay_format_provision_error_notice( torobpay_get_provision_error_message( '1033' ), '1033' );
	} elseif ( 'stopped_1034' === $reason ) {
		$class        = 'notice-warning';
		$message_html = torobpay_format_provision_error_notice( torobpay_get_provision_error_message( '1034' ), '1034' );
	} elseif ( 'stopped_1036' === $reason ) {
		$class        = 'notice-warning';
		$message_html = torobpay_format_provision_error_notice( torobpay_get_provision_error_message( '1036' ), '1036' );
	} else {
		$class   = 'notice-warning';
		$message = __( 'Automatic TorobPay account fetch did not complete. If registration is incomplete or pending approval, details will be fetched automatically after approval.', 'torobpay-for-woocommerce' );

		$error = get_option( 'torobpay_provision_error' );
		if ( is_array( $error ) && ! empty( $error['errorCode'] ) ) {
			$message = torobpay_get_provision_error_message( $error['errorCode'] );
		}

		$error_code   = ( is_array( $error ) && ! empty( $error['errorCode'] ) ) ? (string) $error['errorCode'] : '';
		$message_html = torobpay_format_provision_error_notice( $message, $error_code );
	}

	if ( $show_fetch && 'success' !== $status ) {
		torobpay_enqueue_provision_fetch_assets();
	}

	echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible torobpay-provision-notice">';
	echo '<p><strong>' . esc_html__( 'TorobPay:', 'torobpay-for-woocommerce' ) . '</strong> ' . wp_kses( $message_html, torobpay_kses_allowed_html() ) . '</p>';

	if ( 'success' !== $status ) {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_torobpay' );

		echo '<p>';
		echo '<a href="' . esc_url( $settings_url ) . '" class="button button-primary">' . esc_html__( 'Gateway settings', 'torobpay-for-woocommerce' ) . '</a> ';

		if ( $show_fetch ) {
			echo wp_kses( torobpay_get_manual_fetch_button_html( 'button-primary torobpay-button-primary' ), torobpay_kses_allowed_html() ) . ' ';
		}

		echo '</p>';
	}

	echo '</div>';

	delete_option( 'torobpay_provision_notice' );
}
