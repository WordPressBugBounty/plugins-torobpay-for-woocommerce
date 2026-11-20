<?php
/**
 * TorobPay UTM Handler - Opt-in auto-select and installment-only checkout for UTM visitors.
 *
 * One checkout element is hidden for 2 hours when utm_medium contains TorobPay. That does not use those settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'torobpay_detect_utm_source', 1 );
add_action( 'template_redirect', 'torobpay_detect_utm_source', 1 );
add_action( 'wp', 'torobpay_detect_utm_source', 1 );

/**
 * Current logged-in user ID, or 0.
 *
 * @return int
 */
function torobpay_utm_current_user_id() {
	if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() || ! function_exists( 'get_current_user_id' ) ) {
		return 0;
	}

	return (int) get_current_user_id();
}

/**
 * WooCommerce session object when available.
 *
 * @return object|null
 */
function torobpay_utm_get_session() {
	if ( ! function_exists( 'WC' ) ) {
		return null;
	}

	try {
		$wc = WC();
		if ( $wc && isset( $wc->session ) && $wc->session ) {
			return $wc->session;
		}
	} catch ( Exception $e ) {
	}

	return null;
}

/**
 * Whether utm_medium contains "TorobPay" (case-insensitive, prefix/suffix allowed).
 *
 * @param mixed $utm_medium Raw utm_medium value.
 * @return bool
 */
function torobpay_utm_medium_contains_torobpay( $utm_medium ) {
	if ( ! is_string( $utm_medium ) || $utm_medium === '' ) {
		return false;
	}

	return false !== stripos( $utm_medium, 'torobpay' );
}

/**
 * Read a public UTM query parameter from the current request.
 *
 * @param string $key Query arg name, such as utm_medium.
 * @return string
 */
function torobpay_utm_request_value( $key ) {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public storefront UTM attribution; not a privileged form action.
	if ( isset( $_GET[ $key ] ) ) {
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}

	if ( isset( $_REQUEST[ $key ] ) ) {
		return sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	return '';
}

/**
 * Attribution window for auto-select / pre-select (24 hours).
 *
 * @return int
 */
function torobpay_get_utm_attribution_duration() {
	return 24 * HOUR_IN_SECONDS;
}

/**
 * Get the TTL (in seconds) for keeping TorobPay as the only installment method.
 *
 * @return int
 */
function torobpay_get_exclude_others_duration() {
	return 2 * HOUR_IN_SECONDS;
}

/**
 * @param string $name Cookie name.
 * @param mixed  $value Cookie value.
 * @param int    $expires Unix timestamp.
 * @return void
 */
function torobpay_utm_set_cookie( $name, $value, $expires ) {
	if ( ! defined( 'COOKIEPATH' ) ) {
		return;
	}

	$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
	$secure = function_exists( 'is_ssl' ) && is_ssl();
	setcookie( $name, (string) $value, (int) $expires, COOKIEPATH, $domain, $secure, true );
}

/**
 * @param string $name Cookie name.
 * @return void
 */
function torobpay_utm_expire_cookie( $name ) {
	if ( ! defined( 'COOKIEPATH' ) ) {
		return;
	}

	$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
	$secure = function_exists( 'is_ssl' ) && is_ssl();
	setcookie( $name, '', time() - 3600, COOKIEPATH, $domain, $secure, true );
}

/**
 * Remove keys from session, cookies, and the current user's meta.
 *
 * @param string[] $keys Storage keys.
 * @return void
 */
function torobpay_utm_clear_keys( $keys ) {
	$session = torobpay_utm_get_session();
	if ( $session ) {
		try {
			foreach ( $keys as $key ) {
				$session->__unset( $key );
			}
		} catch ( Exception $e ) {
		}
	}

	foreach ( $keys as $key ) {
		if ( isset( $_COOKIE[ $key ] ) ) {
			torobpay_utm_expire_cookie( $key );
		}
	}

	$user_id = torobpay_utm_current_user_id();
	if ( $user_id && function_exists( 'delete_user_meta' ) ) {
		foreach ( $keys as $key ) {
			delete_user_meta( $user_id, $key );
		}
	}
}

/**
 * Persist values to WooCommerce session (preferred), cookies as fallback, and user meta when logged in.
 *
 * @param array $values_with_expiry Map of key => array with value and expires keys.
 * @return void
 */
function torobpay_utm_store_values( $values_with_expiry ) {
	$session           = torobpay_utm_get_session();
	$stored_in_session = false;

	if ( $session ) {
		try {
			if ( method_exists( $session, 'has_session' ) && ! $session->has_session() && method_exists( $session, 'set_customer_session_cookie' ) ) {
				$session->set_customer_session_cookie( true );
			}

			foreach ( $values_with_expiry as $key => $item ) {
				$session->set( $key, $item['value'] );
			}
			$stored_in_session = true;
		} catch ( Exception $e ) {
		}
	}

	if ( ! $stored_in_session ) {
		foreach ( $values_with_expiry as $key => $item ) {
			torobpay_utm_set_cookie( $key, $item['value'], $item['expires'] );
		}
	}

	$user_id = torobpay_utm_current_user_id();
	if ( $user_id && function_exists( 'update_user_meta' ) ) {
		foreach ( $values_with_expiry as $key => $item ) {
			update_user_meta( $user_id, $key, $item['value'] );
		}
	}
}

/**
 * @param string $key Session key.
 * @return mixed|null
 */
function torobpay_utm_read_session_value( $key ) {
	$session = torobpay_utm_get_session();
	if ( ! $session ) {
		return null;
	}

	try {
		$value = $session->get( $key );
		if ( $value !== null && $value !== '' && $value !== false ) {
			return $value;
		}
	} catch ( Exception $e ) {
	}

	return null;
}

/**
 * @param string $key Cookie name.
 * @return string|null
 */
function torobpay_utm_read_cookie_value( $key ) {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public storefront UTM attribution cookie; not a privileged form action.
	if ( ! isset( $_COOKIE[ $key ] ) ) {
		return null;
	}

	$value = sanitize_text_field( wp_unslash( $_COOKIE[ $key ] ) );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	return $value === '' ? null : $value;
}

/**
 * @param string $key User meta key.
 * @return mixed|null
 */
function torobpay_utm_read_user_meta_value( $key ) {
	$user_id = torobpay_utm_current_user_id();
	if ( ! $user_id || ! function_exists( 'get_user_meta' ) ) {
		return null;
	}

	$value = get_user_meta( $user_id, $key, true );
	if ( $value === '' || $value === false || $value === null ) {
		return null;
	}

	return $value;
}

/**
 * @param mixed $value Stored flag.
 * @param mixed $expiration Unix timestamp or empty.
 * @return bool|null True if usable, false if expired, null if missing.
 */
function torobpay_utm_pair_is_valid( $value, $expiration ) {
	if ( $value === null || $value === '' || $value === false ) {
		return null;
	}

	$expiration = ( $expiration === null || $expiration === '' || $expiration === false ) ? 0 : absint( $expiration );
	if ( $expiration && time() > $expiration ) {
		return false;
	}

	return true;
}

/**
 * Read a value+expiration pair from session, then cookie, then user meta.
 * Expired backends are cleared individually so a stale session cannot wipe a valid account flag.
 *
 * @param string $value_key Storage key for the flag.
 * @param string $expiration_key Storage key for the expiration timestamp.
 * @param string $expected_value Normalized expected value (lowercase).
 * @return bool
 */
function torobpay_utm_read_valid_pair( $value_key, $expiration_key, $expected_value ) {
	$value      = torobpay_utm_read_session_value( $value_key );
	$expiration = torobpay_utm_read_session_value( $expiration_key );
	$state      = torobpay_utm_pair_is_valid( $value, $expiration );
	if ( false === $state ) {
		$session = torobpay_utm_get_session();
		if ( $session ) {
			try {
				$session->__unset( $value_key );
				$session->__unset( $expiration_key );
			} catch ( Exception $e ) {
			}
		}
	} elseif ( true === $state && strtolower( (string) $value ) === $expected_value ) {
		return true;
	}

	$value      = torobpay_utm_read_cookie_value( $value_key );
	$expiration = torobpay_utm_read_cookie_value( $expiration_key );
	$state      = torobpay_utm_pair_is_valid( $value, $expiration );
	if ( false === $state ) {
		torobpay_utm_expire_cookie( $value_key );
		torobpay_utm_expire_cookie( $expiration_key );
	} elseif ( true === $state && strtolower( (string) $value ) === $expected_value ) {
		return true;
	}

	$value      = torobpay_utm_read_user_meta_value( $value_key );
	$expiration = torobpay_utm_read_user_meta_value( $expiration_key );
	$state      = torobpay_utm_pair_is_valid( $value, $expiration );
	if ( false === $state ) {
		$user_id = torobpay_utm_current_user_id();
		if ( $user_id && function_exists( 'delete_user_meta' ) ) {
			delete_user_meta( $user_id, $value_key );
			delete_user_meta( $user_id, $expiration_key );
		}
	} elseif ( true === $state && strtolower( (string) $value ) === $expected_value ) {
		return true;
	}

	return false;
}

/**
 * First non-expired timestamp from session, cookie, or user meta.
 *
 * @param string $key Storage key.
 * @return int
 */
function torobpay_utm_read_valid_timestamp( $key ) {
	$now = time();

	$value = torobpay_utm_read_session_value( $key );
	if ( $value ) {
		$expiration = absint( $value );
		if ( $expiration >= $now ) {
			return $expiration;
		}
		$session = torobpay_utm_get_session();
		if ( $session ) {
			try {
				$session->__unset( $key );
			} catch ( Exception $e ) {
			}
		}
	}

	$value = torobpay_utm_read_cookie_value( $key );
	if ( $value ) {
		$expiration = absint( $value );
		if ( $expiration >= $now ) {
			return $expiration;
		}
		torobpay_utm_expire_cookie( $key );
	}

	$value = torobpay_utm_read_user_meta_value( $key );
	if ( $value ) {
		$expiration = absint( $value );
		if ( $expiration >= $now ) {
			return $expiration;
		}
		$user_id = torobpay_utm_current_user_id();
		if ( $user_id && function_exists( 'delete_user_meta' ) ) {
			delete_user_meta( $user_id, $key );
		}
	}

	return 0;
}

/**
 * Session or cookie only (used when copying onto a newly logged-in / registered user).
 *
 * @param string $key Storage key.
 * @return mixed|null
 */
function torobpay_utm_peek_session_or_cookie( $key ) {
	$value = torobpay_utm_read_session_value( $key );
	if ( null !== $value ) {
		return $value;
	}

	return torobpay_utm_read_cookie_value( $key );
}

function torobpay_is_torob_visitor_valid() {
	return torobpay_utm_read_valid_pair( 'torobpay_utm_source', 'torobpay_utm_expiration', 'torob' );
}

function torobpay_is_utm_medium_visitor_valid() {
	return torobpay_utm_read_valid_pair( 'torobpay_utm_medium', 'torobpay_utm_medium_expiration', 'torobpay' );
}

function torobpay_clear_utm_data() {
	torobpay_utm_clear_keys(
		array(
			'torobpay_utm_source',
			'torobpay_utm_expiration',
			'torobpay_utm_exclude_until',
		)
	);
}

/**
 * Whether a gateway UTM checkbox setting is enabled.
 *
 * @param string     $key Gateway setting key.
 * @param array|null $gateway_settings Gateway settings.
 * @return bool
 */
function torobpay_utm_setting_is_yes( $key, $gateway_settings = null ) {
	if ( null === $gateway_settings ) {
		$gateway_settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', array() );
	}

	return is_array( $gateway_settings ) && isset( $gateway_settings[ $key ] ) && $gateway_settings[ $key ] === 'yes';
}

/**
 * Session values to store for a utm_medium hit.
 * The checkout hide timestamp is always stored for 2 hours. Other keys follow opt-in settings.
 *
 * @param mixed      $utm_medium Raw utm_medium value.
 * @param array|null $gateway_settings Gateway settings.
 * @param int|null   $now Unix timestamp.
 * @return array
 */
function torobpay_utm_medium_values_to_store( $utm_medium, $gateway_settings = null, $now = null ) {
	if ( ! torobpay_utm_medium_contains_torobpay( $utm_medium ) ) {
		return array();
	}

	$now        = null === $now ? time() : (int) $now;
	$hide_until = $now + ( 2 * HOUR_IN_SECONDS );
	$stored     = array(
		'torobpay_utm_bwdk_hide_until' => array(
			'value'   => $hide_until,
			'expires' => $hide_until,
		),
	);

	if ( torobpay_utm_setting_is_yes( 'utm_medium_enabled', $gateway_settings ) ) {
		$expiration_time                          = $now + torobpay_get_utm_attribution_duration();
		$stored['torobpay_utm_medium']            = array(
			'value'   => 'torobpay',
			'expires' => $expiration_time,
		);
		$stored['torobpay_utm_medium_expiration'] = array(
			'value'   => $expiration_time,
			'expires' => $expiration_time,
		);
	}

	if ( torobpay_utm_setting_is_yes( 'utm_medium_exclude_others', $gateway_settings ) ) {
		$exclude_until                               = $now + torobpay_get_exclude_others_duration();
		$stored['torobpay_utm_medium_exclude_until'] = array(
			'value'   => $exclude_until,
			'expires' => $exclude_until,
		);
	}

	return $stored;
}

/**
 * Session values to store for a utm_source=torob hit, based on opt-in settings.
 *
 * @param mixed      $utm_source Raw utm_source value.
 * @param array|null $gateway_settings Gateway settings.
 * @param int|null   $now Unix timestamp.
 * @return array
 */
function torobpay_utm_source_values_to_store( $utm_source, $gateway_settings = null, $now = null ) {
	if ( ! is_string( $utm_source ) || $utm_source === '' ) {
		return array();
	}

	if ( ! in_array( strtolower( $utm_source ), array( 'torob' ), true ) ) {
		return array();
	}

	$now    = null === $now ? time() : (int) $now;
	$stored = array();

	if ( torobpay_utm_setting_is_yes( 'utm_torob_enabled', $gateway_settings ) ) {
		$expiration_time                   = $now + torobpay_get_utm_attribution_duration();
		$stored['torobpay_utm_source']     = array(
			'value'   => 'torob',
			'expires' => $expiration_time,
		);
		$stored['torobpay_utm_expiration'] = array(
			'value'   => $expiration_time,
			'expires' => $expiration_time,
		);
	}

	if ( torobpay_utm_setting_is_yes( 'utm_exclude_others', $gateway_settings ) ) {
		$exclude_until                        = $now + torobpay_get_exclude_others_duration();
		$stored['torobpay_utm_exclude_until'] = array(
			'value'   => $exclude_until,
			'expires' => $exclude_until,
		);
	}

	return $stored;
}

/**
 * Retrieve the stored expiration timestamp for the utm_exclude_others flag.
 *
 * @return int
 */
function torobpay_get_exclude_others_expiration() {
	return torobpay_utm_read_valid_timestamp( 'torobpay_utm_exclude_until' );
}

/**
 * Determine if utm_exclude_others should keep TorobPay as the only installment method.
 *
 * @param array|null $gateway_settings
 * @return bool
 */
function torobpay_is_exclude_others_active( $gateway_settings = null ) {
	if ( ! torobpay_utm_setting_is_yes( 'utm_exclude_others', $gateway_settings ) ) {
		return false;
	}

	return torobpay_get_exclude_others_expiration() > 0;
}

/**
 * Pre-select TorobPay: opt-in utm_medium path, or settings-gated utm_source=torob.
 *
 * @return bool
 */
function torobpay_should_preselect_gateway() {
	$gateway_settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', array() );

	if ( torobpay_utm_setting_is_yes( 'utm_medium_enabled', $gateway_settings ) && torobpay_is_utm_medium_visitor_valid() ) {
		return true;
	}

	if ( ! torobpay_utm_setting_is_yes( 'utm_torob_enabled', $gateway_settings ) ) {
		return false;
	}

	return torobpay_is_torob_visitor_valid();
}

/**
 * Keep TorobPay as the only installment method: opt-in utm_medium path, or settings-gated utm_source path.
 *
 * @param array|null $gateway_settings
 * @return bool
 */
function torobpay_should_hide_competitor_gateways( $gateway_settings = null ) {
	if ( null === $gateway_settings ) {
		$gateway_settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', array() );
	}

	if ( torobpay_utm_setting_is_yes( 'utm_medium_exclude_others', $gateway_settings ) && torobpay_utm_read_valid_timestamp( 'torobpay_utm_medium_exclude_until' ) > 0 ) {
		return true;
	}

	return torobpay_is_exclude_others_active( $gateway_settings );
}

/**
 * Check if a gateway ID should be excluded.
 * Matches gateway IDs case-insensitively and checks for partial matches.
 *
 * @param string $gateway_id The gateway ID to check
 * @return bool
 */
function torobpay_should_exclude_gateway( $gateway_id ) {
	$gateway_id_lower = strtolower( $gateway_id );

	// List of competitor gateway patterns to exclude
	$excluded_patterns = array(
		'digipay',
		'snapppay', // Note: 'snapppay' with 3 'p's to match possible variations
		'azki',
		'nopay',
		'keepa',
		'grandbazar',
		'ozone',
		'tara',
	);

	foreach ( $excluded_patterns as $pattern ) {
		$position = strpos( $gateway_id_lower, $pattern );

		if ( $position !== false ) {
			return true;
		}
	}

	return false;
}

/**
 * Copy valid session/cookie UTM flags onto a user account.
 *
 * @param int $user_id User ID.
 * @return void
 */
function torobpay_persist_utm_data_to_user( $user_id ) {
	$user_id = function_exists( 'absint' ) ? absint( $user_id ) : abs( (int) $user_id );
	if ( ! $user_id || ! function_exists( 'update_user_meta' ) ) {
		return;
	}

	$now = time();

	$medium     = torobpay_utm_peek_session_or_cookie( 'torobpay_utm_medium' );
	$medium_exp = absint( torobpay_utm_peek_session_or_cookie( 'torobpay_utm_medium_expiration' ) );
	if ( is_string( $medium ) && strtolower( $medium ) === 'torobpay' && $medium_exp > $now ) {
		update_user_meta( $user_id, 'torobpay_utm_medium', 'torobpay' );
		update_user_meta( $user_id, 'torobpay_utm_medium_expiration', $medium_exp );
	}

	$medium_exclude = absint( torobpay_utm_peek_session_or_cookie( 'torobpay_utm_medium_exclude_until' ) );
	if ( $medium_exclude > $now ) {
		update_user_meta( $user_id, 'torobpay_utm_medium_exclude_until', $medium_exclude );
	}

	$bwdk_hide_until = absint( torobpay_utm_peek_session_or_cookie( 'torobpay_utm_bwdk_hide_until' ) );
	if ( $bwdk_hide_until > $now ) {
		update_user_meta( $user_id, 'torobpay_utm_bwdk_hide_until', $bwdk_hide_until );
	}

	$source     = torobpay_utm_peek_session_or_cookie( 'torobpay_utm_source' );
	$source_exp = absint( torobpay_utm_peek_session_or_cookie( 'torobpay_utm_expiration' ) );
	if ( is_string( $source ) && strtolower( $source ) === 'torob' && $source_exp > $now ) {
		update_user_meta( $user_id, 'torobpay_utm_source', 'torob' );
		update_user_meta( $user_id, 'torobpay_utm_expiration', $source_exp );
	}

	$source_exclude = absint( torobpay_utm_peek_session_or_cookie( 'torobpay_utm_exclude_until' ) );
	if ( $source_exclude > $now ) {
		update_user_meta( $user_id, 'torobpay_utm_exclude_until', $source_exclude );
	}
}

/**
 * @param string $user_login User login.
 * @param mixed  $user WP_User or user ID.
 * @return void
 */
function torobpay_persist_utm_data_on_login( $user_login, $user = null ) {
	$user_id = 0;
	if ( is_object( $user ) && isset( $user->ID ) ) {
		$user_id = (int) $user->ID;
	} elseif ( is_numeric( $user ) ) {
		$user_id = (int) $user;
	} elseif ( function_exists( 'get_user_by' ) ) {
		$login_user = get_user_by( 'login', $user_login );
		if ( $login_user ) {
			$user_id = (int) $login_user->ID;
		}
	}

	if ( $user_id ) {
		torobpay_persist_utm_data_to_user( $user_id );
	}
}

/**
 * Guest storefront registration only (skip wp-admin creating users).
 *
 * @param int $user_id New user ID.
 * @return void
 */
function torobpay_persist_utm_data_on_register( $user_id ) {
	if ( function_exists( 'is_admin' ) && is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		return;
	}

	$current = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	if ( $current && $current !== (int) $user_id ) {
		return;
	}

	torobpay_persist_utm_data_to_user( $user_id );
}

add_action( 'wp_login', 'torobpay_persist_utm_data_on_login', 10, 2 );
add_action( 'user_register', 'torobpay_persist_utm_data_on_register', 10, 1 );
add_action( 'woocommerce_created_customer', 'torobpay_persist_utm_data_to_user', 10, 1 );

/**
 * Detect utm_source=Torob and utm_medium containing TorobPay.
 * Auto-select and installment-only behavior stay settings-gated.
 */
function torobpay_detect_utm_source() {
	// Prevent multiple executions in same request
	static $already_executed = false;
	if ( $already_executed ) {
		return;
	}
	$already_executed = true;

	if ( is_admin() || ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	$gateway_settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', array() );

	$utm_medium = torobpay_utm_request_value( 'utm_medium' );

	$stored = torobpay_utm_medium_values_to_store( $utm_medium, $gateway_settings );
	if ( ! empty( $stored ) ) {
		torobpay_utm_store_values( $stored );
	}

	$utm_source = torobpay_utm_request_value( 'utm_source' );

	$stored = torobpay_utm_source_values_to_store( $utm_source, $gateway_settings );
	if ( ! empty( $stored ) ) {
		torobpay_utm_store_values( $stored );
	}
}

add_filter( 'woocommerce_available_payment_gateways', 'torobpay_prioritize_for_torob_visitors', 99 );

// Put TorobPay first in payment methods list for attributed visitors (or keep it as the only installment method if configured)
function torobpay_prioritize_for_torob_visitors( $available_gateways ) {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return $available_gateways;
	}

	$should_preselect = torobpay_should_preselect_gateway();
	$should_hide      = torobpay_should_hide_competitor_gateways();

	if ( ! $should_preselect && ! $should_hide ) {
		return $available_gateways;
	}

	if ( ! isset( $available_gateways['WC_Gateway_TorobPay'] ) ||
		! $available_gateways['WC_Gateway_TorobPay']->is_available() ) {
		return $available_gateways;
	}

	if ( $should_hide ) {
		foreach ( $available_gateways as $gateway_id => $gateway ) {
			if ( $gateway_id === 'WC_Gateway_TorobPay' ) {
				continue;
			}

			if ( torobpay_should_exclude_gateway( $gateway_id ) ) {
				unset( $available_gateways[ $gateway_id ] );
			}
		}
	}

	if ( $should_preselect ) {
		$torobpay_gateway = $available_gateways['WC_Gateway_TorobPay'];
		unset( $available_gateways['WC_Gateway_TorobPay'] );
		$available_gateways = array( 'WC_Gateway_TorobPay' => $torobpay_gateway ) + $available_gateways;
	}

	return $available_gateways;
}

function torobpay_add_cache_headers_for_utm() {
	$bypass_cache = torobpay_should_preselect_gateway() || torobpay_should_hide_competitor_gateways();
	if ( ! $bypass_cache && function_exists( 'is_checkout' ) && is_checkout() ) {
		$bypass_cache = torobpay_should_hide_bwdk_checkout_widget();
	}

	if ( ! $bypass_cache || headers_sent() ) {
		return;
	}

	header( 'Cache-Control: no-cache, no-store, must-revalidate' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );
}
add_action( 'template_redirect', 'torobpay_add_cache_headers_for_utm', 1 );

add_action( 'woocommerce_checkout_init', 'torobpay_set_default_for_torob_visitors', 10 );
add_action( 'woocommerce_before_checkout_form', 'torobpay_set_default_for_torob_visitors', 10 );

function torobpay_set_default_for_torob_visitors() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	if ( ! torobpay_should_preselect_gateway() ) {
		return;
	}

	$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
	if ( ! isset( $available_gateways['WC_Gateway_TorobPay'] ) ||
		! $available_gateways['WC_Gateway_TorobPay']->is_available() ) {
		return;
	}

	// Always set TorobPay as chosen method for Torob visitors (allows user to change later)
	WC()->session->set( 'chosen_payment_method', 'WC_Gateway_TorobPay' );

}

add_filter( 'woocommerce_checkout_posted_data', 'torobpay_ensure_default_payment_method' );
add_filter( 'option_woocommerce_checkout_order_receive_url', 'torobpay_ensure_session_payment_method' );

function torobpay_ensure_default_payment_method( $data ) {
	if ( ! torobpay_should_preselect_gateway() ) {
		return $data;
	}

	// CRITICAL FIX: Only set TorobPay if no payment method AND user hasn't explicitly chosen another
	// Do not override if user has already selected a payment method in session
	if ( ! isset( $data['payment_method'] ) || empty( $data['payment_method'] ) ) {
		$chosen_method = '';
		if ( function_exists( 'WC' ) && WC()->session ) {
			$chosen_method = WC()->session->get( 'chosen_payment_method' );
		}

		if ( empty( $chosen_method ) ) {
			$data['payment_method'] = 'WC_Gateway_TorobPay';

		} else {
		}
	}

	return $data;
}

function torobpay_ensure_session_payment_method( $url ) {
	// This is a dummy filter that runs early and allows us to set session data
	if ( function_exists( 'WC' ) && WC()->session && function_exists( 'is_checkout' ) && is_checkout() ) {
		if ( torobpay_should_preselect_gateway() ) {
			$current_method = WC()->session->get( 'chosen_payment_method' );
			if ( empty( $current_method ) ) {
				WC()->session->set( 'chosen_payment_method', 'WC_Gateway_TorobPay' );

			}
		}
	}
	return $url;
}

add_filter( 'woocommerce_checkout_get_value', 'torobpay_force_payment_method_for_torob_visitors', 10, 2 );

function torobpay_force_payment_method_for_torob_visitors( $value, $input ) {
	if ( $input !== 'payment_method' ) {
		return $value;
	}

	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return $value;
	}

	if ( ! torobpay_should_preselect_gateway() ) {
		return $value;
	}

	$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
	if ( ! isset( $available_gateways['WC_Gateway_TorobPay'] ) ||
		! $available_gateways['WC_Gateway_TorobPay']->is_available() ) {

		return $value;
	}

	// Always return TorobPay for Torob visitors (this ensures pre-selection)
	// The user can still change it manually
	$result = 'WC_Gateway_TorobPay';

	return $result;
}

add_action( 'woocommerce_checkout_update_order_review', 'torobpay_maintain_selection_on_ajax_update' );

function torobpay_maintain_selection_on_ajax_update( $post_data ) {
	if ( ! torobpay_should_preselect_gateway() ) {
		return;
	}

	parse_str( $post_data, $data );

	// If user has NOT explicitly selected a different payment method, maintain TorobPay
	if ( ! isset( $data['payment_method'] ) || empty( $data['payment_method'] ) ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'chosen_payment_method', 'WC_Gateway_TorobPay' );

		}
	}
}

add_action( 'woocommerce_after_checkout_form', 'torobpay_add_checkout_selection_script' );

function torobpay_add_checkout_selection_script() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}

	if ( ! torobpay_should_preselect_gateway() ) {
		return;
	}

	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';
	$js      = <<<'JS'
jQuery(document).ready(function($) {
	'use strict';
	
	var initialSelectionDone = false;
	
	function selectTorobPayOnLoad() {
		if (initialSelectionDone) {
			return; // Don't run again
		}
		
		var torobpayRadio = $("input[name='payment_method'][value='WC_Gateway_TorobPay']");
		if (torobpayRadio.length) {
			torobpayRadio.prop('checked', true).trigger('change');
			initialSelectionDone = true;
		}
	}
	
	selectTorobPayOnLoad();
	
	$(document.body).on('updated_checkout', function() {
		setTimeout(function() {
			var selectedMethod = $("input[name='payment_method']:checked").val();
			
			if (!selectedMethod) {
				var torobpayRadio = $("input[name='payment_method'][value='WC_Gateway_TorobPay']");
				if (torobpayRadio.length) {
					torobpayRadio.prop('checked', true).trigger('change');
				}
			}
		}, 100);
	});
});
JS;

	wp_register_script( 'torobpay-utm-checkout-selection', '', array( 'jquery' ), $version, true );
	wp_enqueue_script( 'torobpay-utm-checkout-selection' );
	wp_add_inline_script( 'torobpay-utm-checkout-selection', $js );
	wp_print_scripts( 'torobpay-utm-checkout-selection' );
}

function torobpay_init_checkout_block_integration() {
	// Check if block checkout is available
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry' ) ) {
		return;
	}

	add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', 'torobpay_enqueue_checkout_block_scripts' );
}
add_action( 'woocommerce_blocks_loaded', 'torobpay_init_checkout_block_integration' );

function torobpay_enqueue_checkout_block_scripts() {
	if ( ! torobpay_should_preselect_gateway() ) {
		return;
	}

	// Add inline script for block checkout
	wp_add_inline_script(
		'wc-checkout-block',
		'
        (function() {
            const { select, dispatch, subscribe } = wp.data;
            
            if (!select || !dispatch || !subscribe) {
                return;
            }
            
            let hasUserSelected = false;
            let initialSelectionDone = false;
            
            const setTorobPayDefault = (forceSet = false) => {
                if (hasUserSelected && !forceSet) return;
                
                try {
                    const checkoutStore = select("wc/store/checkout");
                    const paymentStore = select("wc/store/payment");
                    
                    if (!checkoutStore || !paymentStore) return;
                    
                    const availablePaymentMethods = paymentStore.getAvailablePaymentMethods();
                    
                    if (availablePaymentMethods && availablePaymentMethods["WC_Gateway_TorobPay"]) {
                        if (!initialSelectionDone || forceSet) {
                            dispatch("wc/store/payment").setActivePaymentMethod("WC_Gateway_TorobPay");
                            initialSelectionDone = true;
                            hasUserSelected = false; // Reset user selection tracking after setting default
                        }
                    }
                } catch (e) {
                    console.error("TorobPay UTM: Error setting default payment method", e);
                }
            };
            
            const unsubscribe = subscribe(() => {
                try {
                    const paymentStore = select("wc/store/payment");
                    if (!paymentStore) return;
                    
                    const isInitialized = paymentStore.isPaymentMethodsInitialized ? 
                        paymentStore.isPaymentMethodsInitialized() : 
                        (paymentStore.getAvailablePaymentMethods && Object.keys(paymentStore.getAvailablePaymentMethods()).length > 0);
                    
                    if (isInitialized && !initialSelectionDone) {
                        setTorobPayDefault(true);
                    }
                    
                    if (initialSelectionDone) {
                        const checkoutStore = select("wc/store/checkout");
                        if (checkoutStore) {
                            const currentMethod = checkoutStore.getPaymentMethod();
                            if (currentMethod && currentMethod !== "WC_Gateway_TorobPay") {
                                hasUserSelected = true;
                            }
                        }
                    }
                } catch (e) {
                    console.error("TorobPay UTM: Error in subscription", e);
                }
            });
            
            setTimeout(() => setTorobPayDefault(true), 100);
            setTimeout(() => setTorobPayDefault(true), 500);
        })();
        ',
		'after'
	);
}

function torobpay_filter_store_api_checkout_data() {
	if ( ! torobpay_should_preselect_gateway() ) {
		return;
	}

	add_filter(
		'woocommerce_store_api_checkout_schema',
		function ( $schema ) {
			if ( isset( $schema['payment_method'] ) ) {
				$schema['payment_method']['default'] = 'WC_Gateway_TorobPay';
			}
			return $schema;
		}
	);

}
add_action( 'rest_api_init', 'torobpay_filter_store_api_checkout_data' );

/**
 * Keep TorobPay as the only installment method in WooCommerce Checkout Blocks
 * when an opt-in utm_medium or utm_source setting is active.
 */
add_action( 'woocommerce_blocks_payment_method_type_registration', 'torobpay_filter_blocks_payment_methods', 10, 1 );

function torobpay_filter_blocks_payment_methods( $payment_method_registry ) {
	if ( ! torobpay_should_hide_competitor_gateways() ) {
		return;
	}

	if ( ! method_exists( $payment_method_registry, 'get_all_registered' ) ) {
		return;
	}

	$registered_methods = $payment_method_registry->get_all_registered();

	foreach ( $registered_methods as $payment_method ) {
		$method_name = method_exists( $payment_method, 'get_name' ) ? $payment_method->get_name() : '';

		if ( $method_name === 'WC_Gateway_TorobPay' ) {
			continue;
		}

		if ( $method_name && torobpay_should_exclude_gateway( $method_name ) ) {
			if ( method_exists( $payment_method_registry, 'unregister' ) ) {
				$payment_method_registry->unregister( $method_name );
			}
		}
	}
}

/**
 * Whether the checkout hide rule should run.
 * True for this request, or for 2 hours after a utm_medium TorobPay visit.
 * Gateway UTM settings are not consulted.
 *
 * @return bool
 */
function torobpay_should_hide_bwdk_checkout_widget() {
	if ( torobpay_utm_medium_contains_torobpay( torobpay_utm_request_value( 'utm_medium' ) ) ) {
		return true;
	}

	return torobpay_utm_read_valid_timestamp( 'torobpay_utm_bwdk_hide_until' ) > 0;
}

/**
 * Print the checkout hide rule for TorobPay utm_medium visitors.
 *
 * @return void
 */
function torobpay_enqueue_bwdk_checkout_widget_hide_style() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}

	if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		return;
	}

	if ( ! torobpay_should_hide_bwdk_checkout_widget() ) {
		return;
	}

	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';
	$css     = "digify-clickable-box#bwdk-checkout-widget {\n\tdisplay: none !important;\n}";
	wp_register_style( 'torobpay-hide-bwdk-checkout-widget', false, array(), $version );
	wp_enqueue_style( 'torobpay-hide-bwdk-checkout-widget' );
	wp_add_inline_style( 'torobpay-hide-bwdk-checkout-widget', wp_strip_all_tags( $css ) );
}
add_action( 'wp_enqueue_scripts', 'torobpay_enqueue_bwdk_checkout_widget_hide_style' );
