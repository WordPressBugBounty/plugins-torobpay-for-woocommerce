<?php
/**
 * Asset dependencies for TorobPay WooCommerce Checkout Block integration
 *
 * @package TorobPay_WooCommerce_Gateway
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

return [
    'dependencies' => [
        // WordPress dependencies
        'wp-element',
        'wp-data',
        'wp-components',
        'wp-polyfill',
        'wp-i18n',
        'wp-html-entities',
        
        // WooCommerce dependencies
        'wc-blocks-registry',
        'wc-settings',
        'wc-blocks-data-store',
    ],
    'version' => TOROBPAY_VERSION,
];
