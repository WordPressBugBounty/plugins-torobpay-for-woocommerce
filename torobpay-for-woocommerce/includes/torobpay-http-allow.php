<?php
/**
 * Opt-in bypass for TorobPay API calls blocked by WordPress or another plugin.
 *
 * Off unless the gateway checkbox http_unblock_enabled is yes. The allowed
 * host is the saved Gateway URL. When that URL has no host, torobpay.com and
 * its subdomains are allowed instead.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gateway settings array, or the supplied array when already loaded.
 *
 * @param array|null $settings Optional settings. Null loads the stored option.
 * @return array
 */
function torobpay_http_allow_settings( $settings = null ) {
	if ( null !== $settings ) {
		return is_array( $settings ) ? $settings : array();
	}

	$stored = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', array() );

	return is_array( $stored ) ? $stored : array();
}

/**
 * Whether the merchant opted in to letting blocked TorobPay API calls through.
 *
 * @param array|null $settings Optional gateway settings.
 * @return bool
 */
function torobpay_is_http_unblock_opted_in( $settings = null ) {
	$settings = torobpay_http_allow_settings( $settings );

	return isset( $settings['http_unblock_enabled'] ) && 'yes' === $settings['http_unblock_enabled'];
}

/**
 * Host from the saved Gateway URL, or an empty string when it has none.
 *
 * @param array|null $settings Optional gateway settings.
 * @return string
 */
function torobpay_http_allow_base_host( $settings = null ) {
	$settings = torobpay_http_allow_settings( $settings );
	$base_url = isset( $settings['base_url'] ) ? trim( (string) $settings['base_url'] ) : '';

	if ( '' === $base_url ) {
		return '';
	}

	if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $base_url ) ) {
		$base_url = 'https://' . ltrim( $base_url, '/' );
	}

	$host = wp_parse_url( $base_url, PHP_URL_HOST );
	if ( ! is_string( $host ) || '' === $host ) {
		return '';
	}

	return strtolower( rtrim( $host, '.' ) );
}

/**
 * Host the block notice should talk about.
 *
 * @param array|null $settings Optional gateway settings.
 * @return string
 */
function torobpay_http_allow_request_host( $settings = null ) {
	$base_host = torobpay_http_allow_base_host( $settings );

	return '' !== $base_host ? $base_host : 'cpg.torobpay.com';
}

/**
 * Whether a hostname is torobpay.com or a subdomain of it.
 *
 * @param string $host Hostname.
 * @return bool
 */
function torobpay_http_allow_is_torobpay_host( $host ) {
	$host = strtolower( rtrim( (string) $host, '.' ) );

	if ( 'torobpay.com' === $host ) {
		return true;
	}

	$suffix = '.torobpay.com';
	$length = strlen( $suffix );

	return strlen( $host ) > $length && substr( $host, -$length ) === $suffix;
}

/**
 * Whether this outbound host may skip HTTP blocks while the opt-in is on.
 *
 * @param string     $host     Hostname.
 * @param array|null $settings Optional gateway settings.
 * @return bool
 */
function torobpay_http_allow_is_allowed_host( $host, $settings = null ) {
	$host = strtolower( rtrim( (string) $host, '.' ) );
	if ( '' === $host ) {
		return false;
	}

	$base_host = torobpay_http_allow_base_host( $settings );
	if ( '' !== $base_host ) {
		return $host === $base_host;
	}

	return torobpay_http_allow_is_torobpay_host( $host );
}

/**
 * WP_ACCESSIBLE_HOSTS value to suggest for this gateway host.
 *
 * @param string $host Hostname that must be reachable.
 * @return string
 */
function torobpay_http_block_hosts_snippet( $host ) {
	$host = strtolower( rtrim( (string) $host, '.' ) );

	if ( torobpay_http_allow_is_torobpay_host( $host ) || ! preg_match( '/\A[a-z0-9.-]{1,253}\z/', $host ) ) {
		return 'cpg.torobpay.com,*.torobpay.com';
	}

	return $host;
}

/**
 * Whether WP_ACCESSIBLE_HOSTS would let this host through.
 *
 * Wildcard rules match WordPress core: a leading star needs at least one
 * character, so *.torobpay.com does not match the apex. Comparison is
 * case-insensitive.
 *
 * @param string $host              Hostname.
 * @param string $accessible_hosts  WP_ACCESSIBLE_HOSTS value.
 * @return bool
 */
function torobpay_host_in_accessible_hosts( $host, $accessible_hosts ) {
	$host = strtolower( rtrim( (string) $host, '.' ) );
	if ( '' === $host || ! is_string( $accessible_hosts ) || '' === trim( $accessible_hosts ) ) {
		return false;
	}

	$rules = preg_split( '/,\s*/', strtolower( $accessible_hosts ) );
	if ( ! is_array( $rules ) ) {
		return false;
	}

	$rules = array_values(
		array_filter(
			array_map( 'trim', $rules ),
			static function ( $rule ) {
				return '' !== $rule;
			}
		)
	);
	if ( empty( $rules ) ) {
		return false;
	}

	if ( false !== strpos( $accessible_hosts, '*' ) ) {
		$patterns = array();
		foreach ( $rules as $rule ) {
			$patterns[] = str_replace( '\*', '.+', preg_quote( $rule, '/' ) );
		}

		return 1 === preg_match( '/^(' . implode( '|', $patterns ) . ')$/', $host );
	}

	return in_array( $host, $rules, true );
}

/**
 * Whether WP_HTTP_BLOCK_EXTERNAL would stop requests to this host.
 *
 * @param string $host Hostname.
 * @return bool
 */
function torobpay_http_constant_blocks_host( $host ) {
	if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || ! WP_HTTP_BLOCK_EXTERNAL ) {
		return false;
	}

	$accessible = defined( 'WP_ACCESSIBLE_HOSTS' ) ? (string) WP_ACCESSIBLE_HOSTS : '';

	return ! torobpay_host_in_accessible_hosts( $host, $accessible );
}

/**
 * Deep link to the gateway checkbox.
 *
 * @return string
 */
function torobpay_http_unblock_settings_url() {
	return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_torobpay' ) . '#woocommerce_WC_Gateway_TorobPay_http_unblock_enabled';
}

/**
 * Register the bypass after themes have added their own HTTP filters.
 */
function torobpay_http_allow_register() {
	if ( ! torobpay_is_http_unblock_opted_in() ) {
		return;
	}

	add_filter( 'pre_http_request', 'torobpay_http_allow_pre_http_request', PHP_INT_MAX, 3 );
}
add_action( 'after_setup_theme', 'torobpay_http_allow_register', PHP_INT_MAX );
add_action( 'init', 'torobpay_http_allow_register', PHP_INT_MAX );

/**
 * One-shot hostname used to make core treat the next block check as local.
 *
 * @param string|null $host Host to store, or null to read. Empty string clears.
 * @return string
 */
function torobpay_http_allow_bypass_host( $host = null ) {
	static $stored = '';

	if ( null !== $host ) {
		$stored = strtolower( rtrim( (string) $host, '.' ) );
	}

	return $stored;
}

/**
 * Clear an early block for the allowed host, and arm the external-HTTP bypass.
 *
 * @param false|array|WP_Error $pre  Value from earlier pre_http_request callbacks.
 * @param array                $args Request arguments. Unused.
 * @param string               $url  Request URL.
 * @return false|array|WP_Error
 */
function torobpay_http_allow_pre_http_request( $pre, $args, $url ) {
	unset( $args );

	$host = wp_parse_url( (string) $url, PHP_URL_HOST );
	if ( ! is_string( $host ) || ! torobpay_http_allow_is_allowed_host( $host ) ) {
		return $pre;
	}

	// A response array is a mock or a finished request. Leave it in place.
	if ( is_array( $pre ) ) {
		return $pre;
	}

	if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) {
		torobpay_http_allow_bypass_host( $host );
		add_filter( 'pre_option_siteurl', 'torobpay_http_allow_spoof_siteurl', PHP_INT_MAX );
		add_filter( 'block_local_requests', 'torobpay_http_allow_pass_local_request', PHP_INT_MAX );
	}

	return false;
}

/**
 * Make WP_Http::block_request() see the target host as this site, once.
 *
 * @param mixed $pre_option Value from an earlier pre_option_siteurl callback.
 * @return mixed
 */
function torobpay_http_allow_spoof_siteurl( $pre_option ) {
	remove_filter( 'pre_option_siteurl', 'torobpay_http_allow_spoof_siteurl', PHP_INT_MAX );

	$host = torobpay_http_allow_bypass_host();
	if ( '' === $host ) {
		return $pre_option;
	}

	return 'https://' . $host;
}

/**
 * Do not block the request that was just classified as local.
 *
 * @param bool $block Whether WordPress would block the local request.
 * @return bool
 */
function torobpay_http_allow_pass_local_request( $block ) {
	unset( $block );

	remove_filter( 'block_local_requests', 'torobpay_http_allow_pass_local_request', PHP_INT_MAX );
	torobpay_http_allow_bypass_host( '' );

	return false;
}
