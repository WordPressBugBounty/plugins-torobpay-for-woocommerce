<?php
/**
 * TorobPay smart DNS resolution.
 *
 * Bypasses slow/filtered system DNS for outbound TorobPay API calls by injecting
 * a pre-resolved IP into cURL requests, with cache invalidation when the API
 * base URL or override changes.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inject a pre-resolved IP into TorobPay cURL requests via CURLOPT_RESOLVE.
 * Bypasses system DNS (fixes cURL error 28 on slow/filtered resolvers) while
 * keeping the original hostname for Host header and TLS SNI.
 * Priority: manual IP from settings → 12-hour transient cache → fresh gethostbyname().
 */
add_filter( 'http_api_curl', 'torobpay_apply_dns_resolve', 10, 3 );
function torobpay_apply_dns_resolve( $handle, $r, $url ) {
	static $dns_settings = null;

	if ( $dns_settings === null ) {
		$settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', array() );
		$base_url = ! empty( $settings['base_url'] ) ? $settings['base_url'] : 'https://cpg.torobpay.com/';

		$dns_settings = array(
			'enabled'   => ! empty( $settings['dns_smart_resolve_enabled'] ) && $settings['dns_smart_resolve_enabled'] === 'yes',
			'manual_ip' => ! empty( $settings['dns_ip_override'] ) ? trim( $settings['dns_ip_override'] ) : '',
			'api_host'  => wp_parse_url( $base_url, PHP_URL_HOST ),
		);
	}

	if ( ! $dns_settings['enabled'] ) {
		return $handle;
	}

	$parsed = wp_parse_url( $url );
	if ( empty( $parsed['host'] ) ) {
		return $handle;
	}

	$api_host = $dns_settings['api_host'];

	if ( ! $api_host || $parsed['host'] !== $api_host ) {
		return $handle;
	}

	// 1. Manual override from settings.
	$manual_ip = $dns_settings['manual_ip'];
	if ( $manual_ip && filter_var( $manual_ip, FILTER_VALIDATE_IP ) ) {
		$resolved_ip = $manual_ip;
	} else {
		// 2 & 3. Auto-cached IP via transient.
		$transient_key = 'torobpay_dns_' . md5( $api_host );
		$resolved_ip   = get_transient( $transient_key );

		if ( ! $resolved_ip ) {
			$looked_up = gethostbyname( $api_host );
			if ( $looked_up !== $api_host && filter_var( $looked_up, FILTER_VALIDATE_IP ) ) {
				// Successful DNS resolution — cache for 12 hours.
				set_transient( $transient_key, $looked_up, 12 * HOUR_IN_SECONDS );
				$resolved_ip = $looked_up;
			}
		}
	}

	if ( empty( $resolved_ip ) ) {
		// Could not determine an IP — fall through to normal DNS.
		return $handle;
	}

	// Inject the resolved IP for both port 443 (HTTPS) and 80 (HTTP redirect).
	// cURL format: "hostname:port:ip"
	$resolve_entries = array(
		$api_host . ':443:' . $resolved_ip,
		$api_host . ':80:' . $resolved_ip,
	);
	// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Required for CURLOPT_RESOLVE via http_api_curl.
	curl_setopt( $handle, CURLOPT_RESOLVE, $resolve_entries );

	return $handle;
}

/**
 * Clear cached DNS transient when the API base URL is changed in settings,
 * so the new hostname is resolved fresh on the next request.
 */
add_action( 'update_option_woocommerce_WC_Gateway_TorobPay_settings', 'torobpay_clear_dns_cache_on_url_change', 10, 2 );
function torobpay_clear_dns_cache_on_url_change( $old_value, $new_value ) {
	$old_url = ! empty( $old_value['base_url'] ) ? $old_value['base_url'] : '';
	$new_url = ! empty( $new_value['base_url'] ) ? $new_value['base_url'] : '';

	if ( $old_url !== $new_url ) {
		$old_host = $old_url ? wp_parse_url( $old_url, PHP_URL_HOST ) : null;
		$new_host = $new_url ? wp_parse_url( $new_url, PHP_URL_HOST ) : null;

		if ( $old_host ) {
			delete_transient( 'torobpay_dns_' . md5( $old_host ) );
		}
		if ( $new_host && $new_host !== $old_host ) {
			delete_transient( 'torobpay_dns_' . md5( $new_host ) );
		}
	}

	$old_ip = ! empty( $old_value['dns_ip_override'] ) ? $old_value['dns_ip_override'] : '';
	$new_ip = ! empty( $new_value['dns_ip_override'] ) ? $new_value['dns_ip_override'] : '';
	if ( $old_ip !== $new_ip && ! empty( $new_value['base_url'] ) ) {
		$host = wp_parse_url( $new_value['base_url'], PHP_URL_HOST );
		if ( $host ) {
			delete_transient( 'torobpay_dns_' . md5( $host ) );
		}
	}

	$old_mode = ! empty( $old_value['dns_smart_resolve_enabled'] ) ? $old_value['dns_smart_resolve_enabled'] : 'no';
	$new_mode = ! empty( $new_value['dns_smart_resolve_enabled'] ) ? $new_value['dns_smart_resolve_enabled'] : 'no';
	if ( $old_mode !== $new_mode && ! empty( $new_value['base_url'] ) ) {
		$host = wp_parse_url( $new_value['base_url'], PHP_URL_HOST );
		if ( $host ) {
			delete_transient( 'torobpay_dns_' . md5( $host ) );
		}
	}
}
