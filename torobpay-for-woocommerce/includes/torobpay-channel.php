<?php
/**
 * Distribution channel for this package.
 *
 * WordPress.org builds ship this file as-is (directory / d_).
 * Hosted zips overwrite it from build/hosted/includes/torobpay-channel.php.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TOROBPAY_CHANNEL' ) ) {
	define( 'TOROBPAY_CHANNEL', 'directory' );
}

if ( ! defined( 'TOROBPAY_VERSION_PREFIX' ) ) {
	define( 'TOROBPAY_VERSION_PREFIX', 'd' );
}
