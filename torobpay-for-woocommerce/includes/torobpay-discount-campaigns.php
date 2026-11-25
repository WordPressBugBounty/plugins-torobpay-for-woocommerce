<?php
/**
 * TorobPay special discount campaigns fetched from the gateway API.
 *
 * GET api/online/discount/v1/campaigns returns the merchant's active
 * discount campaigns (code, discount terms, ready-made display texts).
 * A successful response is fresh for the server TTL and kept longer so a
 * later timeout can still show that list. The next refresh uses a short
 * timeout. A successful empty list clears the stored campaigns.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient key for the cached campaigns payload.
 *
 * @return string
 */
function torobpay_discount_campaigns_transient_key() {
	return 'torobpay_discount_campaigns';
}

/**
 * HTTP timeout for a campaigns refresh, including a nested token request.
 *
 * Short on purpose: this call can run during a product-page render.
 *
 * @return int Seconds.
 */
function torobpay_discount_campaigns_http_timeout() {
	return 3;
}

/**
 * How long to wait before trying again after a failed refresh.
 *
 * @return int Seconds.
 */
function torobpay_discount_campaigns_retry_seconds() {
	return 60;
}

/**
 * Drop the cached campaigns and the per-request copies.
 *
 * Called when merchant credentials change so the next request cannot show
 * another account's code.
 *
 * @return void
 */
function torobpay_forget_discount_campaigns_cache() {
	delete_transient( torobpay_discount_campaigns_transient_key() );
	unset(
		$GLOBALS['torobpay_discount_campaigns_cache'],
		$GLOBALS['torobpay_active_discount_campaign'],
		$GLOBALS['torobpay_discount_campaigns_status']
	);
}

/**
 * Sanitize one raw campaign object from the API.
 *
 * @param mixed $raw Raw campaign array.
 * @return array|null Normalized campaign or null when unusable.
 */
function torobpay_sanitize_discount_campaign( $raw ) {
	if ( ! is_array( $raw ) ) {
		return null;
	}

	$code = isset( $raw['code'] ) ? sanitize_text_field( (string) $raw['code'] ) : '';
	$code = is_string( $code ) ? preg_replace( '/\s+/', '', $code ) : '';
	if ( ! is_string( $code ) || '' === $code ) {
		return null;
	}
	if ( function_exists( 'mb_substr' ) ) {
		$code = mb_substr( $code, 0, 40 );
	} else {
		$code = substr( $code, 0, 40 );
	}

	$type_raw = isset( $raw['discount_type'] ) ? strtoupper( sanitize_key( (string) $raw['discount_type'] ) ) : '';
	$type     = 'PERCENTAGE' === $type_raw ? 'percent' : 'fixed';

	$fixed_amount = isset( $raw['discount_fixed_amount'] ) && is_numeric( $raw['discount_fixed_amount'] )
		? max( 0.0, (float) $raw['discount_fixed_amount'] )
		: 0.0;
	$percentage   = isset( $raw['discount_percentage'] ) && is_numeric( $raw['discount_percentage'] )
		? max( 0.0, (float) $raw['discount_percentage'] )
		: 0.0;
	$max_amount   = isset( $raw['discount_percentage_max_amount'] ) && is_numeric( $raw['discount_percentage_max_amount'] )
		? max( 0.0, (float) $raw['discount_percentage_max_amount'] )
		: null;
	$min_order    = isset( $raw['min_order_total_amount'] ) && is_numeric( $raw['min_order_total_amount'] )
		? max( 0.0, (float) $raw['min_order_total_amount'] )
		: 0.0;

	$expires_at = isset( $raw['expires_at'] ) ? sanitize_text_field( (string) $raw['expires_at'] ) : '';
	$expires_ts = 0;
	if ( '' !== $expires_at ) {
		$expires_dt = date_create_immutable( $expires_at );
		if ( $expires_dt instanceof DateTimeInterface ) {
			$expires_ts = $expires_dt->getTimestamp();
		} else {
			$expires_at = '';
		}
	}

	$texts = array();
	foreach ( array( 'amount_text', 'amount_title', 'short_amount_title', 'min_order_text', 'max_discount_text', 'expiry_text' ) as $text_key ) {
		$texts[ $text_key ] = isset( $raw[ $text_key ] ) ? sanitize_text_field( (string) $raw[ $text_key ] ) : '';
	}

	return array(
		'code'               => $code,
		'discount_type'      => $type,
		'discount_fixed_amount'      => $fixed_amount,
		'discount_percentage'        => $percentage,
		'discount_percentage_max_amount' => $max_amount,
		'min_order_total_amount'     => $min_order,
		'expires_at'         => $expires_at,
		'expires_ts'         => $expires_ts,
		'first_order_only'   => ! empty( $raw['first_order_only'] ),
		'amount_text'        => $texts['amount_text'],
		'amount_title'       => $texts['amount_title'],
		'short_amount_title' => $texts['short_amount_title'],
		'min_order_text'     => $texts['min_order_text'],
		'max_discount_text'  => $texts['max_discount_text'],
		'expiry_text'        => $texts['expiry_text'],
	);
}

/**
 * Whether a campaign can still be shown (has a code and is not expired).
 *
 * @param array $campaign Sanitized campaign.
 * @param int   $now      Optional unix timestamp. Defaults to time().
 * @return bool
 */
function torobpay_discount_campaign_is_active( $campaign, $now = null ) {
	if ( ! is_array( $campaign ) || empty( $campaign['code'] ) ) {
		return false;
	}

	$expires_ts = isset( $campaign['expires_ts'] ) ? (int) $campaign['expires_ts'] : 0;
	if ( $expires_ts > 0 ) {
		$now = null === $now ? time() : (int) $now;
		if ( $expires_ts <= $now ) {
			return false;
		}
	}

	return true;
}

/**
 * Pick the first displayable campaign from a sanitized list.
 *
 * @param array $campaigns Sanitized campaigns.
 * @param int   $now       Optional unix timestamp.
 * @return array|null
 */
function torobpay_select_discount_campaign( $campaigns, $now = null ) {
	if ( ! is_array( $campaigns ) ) {
		return null;
	}

	foreach ( $campaigns as $campaign ) {
		if ( torobpay_discount_campaign_is_active( $campaign, $now ) ) {
			return $campaign;
		}
	}

	return null;
}

/**
 * Read the stored campaigns payload.
 *
 * A payload with no fresh_until stays fresh until WordPress expires the
 * transient. That covers caches written before freshness was tracked.
 *
 * @return array{campaigns:array,fresh_until:int,ok:bool}|null
 */
function torobpay_read_discount_campaigns_cache() {
	$cached = get_transient( torobpay_discount_campaigns_transient_key() );
	if ( ! is_array( $cached ) || ! isset( $cached['campaigns'] ) || ! is_array( $cached['campaigns'] ) ) {
		return null;
	}

	$ok = ! array_key_exists( 'ok', $cached ) || ! empty( $cached['ok'] );

	return array(
		'campaigns'   => $cached['campaigns'],
		'fresh_until' => isset( $cached['fresh_until'] ) ? (int) $cached['fresh_until'] : PHP_INT_MAX,
		'ok'          => $ok,
	);
}

/**
 * Persist campaigns. Freshness and retention are separate.
 *
 * The transient outlives fresh_until when there is something worth showing
 * again, or when the API confirmed the list (including an empty list).
 *
 * @param array $campaigns Sanitized campaigns.
 * @param int   $fresh_for Seconds until the next refresh.
 * @param bool  $ok        Whether this payload came from a successful response.
 * @return void
 */
function torobpay_store_discount_campaigns_cache( $campaigns, $fresh_for, $ok ) {
	$fresh_for = max( 60, min( DAY_IN_SECONDS, (int) $fresh_for ) );
	$keep      = ( $ok || ! empty( $campaigns ) ) ? max( $fresh_for, DAY_IN_SECONDS ) : $fresh_for;

	set_transient(
		torobpay_discount_campaigns_transient_key(),
		array(
			'campaigns'   => array_values( $campaigns ),
			'fresh_until' => time() + $fresh_for,
			'ok'          => (bool) $ok,
		),
		$keep
	);
}

/**
 * Remember campaigns for the rest of this request.
 *
 * @param array  $campaigns Sanitized campaigns.
 * @param string $status    ok|error.
 * @return array
 */
function torobpay_discount_campaigns_remember( $campaigns, $status ) {
	$GLOBALS['torobpay_discount_campaigns_cache']  = array_values( is_array( $campaigns ) ? $campaigns : array() );
	$GLOBALS['torobpay_discount_campaigns_status'] = 'error' === $status ? 'error' : 'ok';
	unset( $GLOBALS['torobpay_active_discount_campaign'] );

	return $GLOBALS['torobpay_discount_campaigns_cache'];
}

/**
 * Whether a campaigns response cannot be trusted.
 *
 * A timeout, a transport error, and successful:false all take this path.
 * A successful empty list does not: that means the merchant has no campaign.
 *
 * @param mixed $response API response.
 * @return bool
 */
function torobpay_discount_campaigns_response_failed( $response ) {
	if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
		return true;
	}

	return ! is_array( $response ) || empty( $response['successful'] );
}

/**
 * Store a campaigns API response, keeping the previous list when it failed.
 *
 * @param mixed      $response API response, WP_Error, or another failure value.
 * @param array|null $previous Previous payload from torobpay_read_discount_campaigns_cache().
 * @return array Sanitized campaigns to display.
 */
function torobpay_discount_campaigns_commit_response( $response, $previous ) {
	$previous_campaigns = ( is_array( $previous ) && isset( $previous['campaigns'] ) && is_array( $previous['campaigns'] ) )
		? $previous['campaigns']
		: array();

	if ( torobpay_discount_campaigns_response_failed( $response ) ) {
		torobpay_store_discount_campaigns_cache( $previous_campaigns, torobpay_discount_campaigns_retry_seconds(), false );
		return torobpay_discount_campaigns_remember( $previous_campaigns, 'error' );
	}

	$raw_campaigns = isset( $response['response']['campaigns'] ) && is_array( $response['response']['campaigns'] )
		? $response['response']['campaigns']
		: array();

	$campaigns = array();
	foreach ( $raw_campaigns as $raw ) {
		$campaign = torobpay_sanitize_discount_campaign( $raw );
		if ( null !== $campaign ) {
			$campaigns[] = $campaign;
		}
	}

	$ttl = isset( $response['response']['cache_ttl_seconds'] ) ? absint( $response['response']['cache_ttl_seconds'] ) : 600;
	$ttl = max( 60, min( DAY_IN_SECONDS, $ttl ) );
	torobpay_store_discount_campaigns_cache( $campaigns, $ttl, true );

	return torobpay_discount_campaigns_remember( $campaigns, 'ok' );
}

/**
 * Fetch campaigns from the API.
 *
 * Unavailable or slow responses keep the previous list and pause retries for
 * a minute. The product page only waits for the short campaigns timeout.
 *
 * @param array|null $previous Previous payload. Read from the transient when omitted.
 * @return array Sanitized campaigns.
 */
function torobpay_fetch_discount_campaigns( $previous = null ) {
	if ( null === $previous ) {
		$previous = torobpay_read_discount_campaigns_cache();
	}

	if ( ! class_exists( 'TorobPayAPI' ) || ! function_exists( 'torobpay_is_configured' ) || ! torobpay_is_configured() ) {
		torobpay_forget_discount_campaigns_cache();
		return torobpay_discount_campaigns_remember( array(), 'ok' );
	}

	$response = ( new TorobPayAPI() )->get_discount_campaigns( torobpay_discount_campaigns_http_timeout() );

	return torobpay_discount_campaigns_commit_response( $response, $previous );
}

/**
 * Option key for the single in-flight campaigns refresh.
 *
 * @return string
 */
function torobpay_discount_campaigns_refresh_lock_key() {
	return 'torobpay_discount_campaigns_refresh';
}

/**
 * How long a refresh lock blocks other requests after the holder dies.
 *
 * Longer than the campaigns HTTP timeout so a live request is not joined
 * by a second one, and short enough that a crashed request does not stall
 * the next refresh.
 *
 * @return int Seconds.
 */
function torobpay_discount_campaigns_refresh_lock_ttl() {
	return 15;
}

/**
 * Become the one request allowed to call the campaigns API.
 *
 * add_option is the atomic insert. A lock older than the TTL is abandoned.
 *
 * @return bool
 */
function torobpay_discount_campaigns_acquire_refresh_lock() {
	$key = torobpay_discount_campaigns_refresh_lock_key();
	$now = time();
	if ( add_option( $key, $now, '', false ) ) {
		return true;
	}

	$started = (int) get_option( $key, 0 );
	if ( $started > 0 && ( $now - $started ) < torobpay_discount_campaigns_refresh_lock_ttl() ) {
		return false;
	}

	delete_option( $key );

	return (bool) add_option( $key, $now, '', false );
}

/**
 * Let the next stale read refresh again.
 *
 * @return void
 */
function torobpay_discount_campaigns_release_refresh_lock() {
	delete_option( torobpay_discount_campaigns_refresh_lock_key() );
}

/**
 * All cached discount campaigns (sanitized). Empty array when unavailable.
 *
 * The torobpay_discount_campaigns filter short-circuits the cache and the
 * API; it must return an array of sanitized campaigns (or null to continue).
 * A stale cache is refreshed by one request. Others keep the last list
 * instead of each waiting on the gateway.
 *
 * @return array
 */
function torobpay_get_discount_campaigns() {
	if ( isset( $GLOBALS['torobpay_discount_campaigns_cache'] ) && is_array( $GLOBALS['torobpay_discount_campaigns_cache'] ) ) {
		return $GLOBALS['torobpay_discount_campaigns_cache'];
	}

	$filtered = apply_filters( 'torobpay_discount_campaigns', null );
	if ( is_array( $filtered ) ) {
		return torobpay_discount_campaigns_remember( $filtered, 'ok' );
	}

	$cached = torobpay_read_discount_campaigns_cache();
	if ( null !== $cached && $cached['fresh_until'] > time() ) {
		return torobpay_discount_campaigns_remember( $cached['campaigns'], $cached['ok'] ? 'ok' : 'error' );
	}

	if ( ! torobpay_discount_campaigns_acquire_refresh_lock() ) {
		if ( null !== $cached ) {
			return torobpay_discount_campaigns_remember( $cached['campaigns'], $cached['ok'] ? 'ok' : 'error' );
		}

		return torobpay_discount_campaigns_remember( array(), 'error' );
	}

	try {
		return torobpay_fetch_discount_campaigns( $cached );
	} finally {
		torobpay_discount_campaigns_release_refresh_lock();
	}
}

/**
 * Whether the last campaigns read succeeded.
 *
 * error means the gateway was unreachable or slow. An empty list with ok
 * means TorobPay reported no campaign.
 *
 * @return string ok|error
 */
function torobpay_discount_campaigns_status() {
	if ( ! isset( $GLOBALS['torobpay_discount_campaigns_status'] ) ) {
		torobpay_get_discount_campaigns();
	}

	return ( isset( $GLOBALS['torobpay_discount_campaigns_status'] ) && 'error' === $GLOBALS['torobpay_discount_campaigns_status'] )
		? 'error'
		: 'ok';
}

/**
 * The campaign currently shown on the storefront, or null.
 *
 * The first unexpired campaign is the one that is displayed.
 *
 * @return array|null
 */
function torobpay_get_active_discount_campaign() {
	if ( ! array_key_exists( 'torobpay_active_discount_campaign', $GLOBALS ) ) {
		$GLOBALS['torobpay_active_discount_campaign'] = torobpay_select_discount_campaign( torobpay_get_discount_campaigns() );
	}

	return $GLOBALS['torobpay_active_discount_campaign'];
}
