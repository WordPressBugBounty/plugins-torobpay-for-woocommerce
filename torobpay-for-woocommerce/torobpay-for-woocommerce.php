<?php
/**
 * Plugin Name:       TorobPay for WooCommerce
 * Description:       Accept TorobPay installment payments in WooCommerce.
 * Version:           4.8.17
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            TorobPay
 * Author URI:        https://torobpay.com/account?page=about-us
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       torobpay-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 7.1
 * WC tested up to:   11.2.1
 *
 * @package TorobPay
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'add_filter' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit();
}

if ( ! defined( 'TOROBPAY_PLUGIN' ) ) {
	define( 'TOROBPAY_PLUGIN', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'TOROBPAY_PLUGIN_FILE' ) ) {
	define( 'TOROBPAY_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'TOROBPAY_VERSION' ) ) {
	define( 'TOROBPAY_VERSION', '4.8.17' );
}

add_action( 'before_woocommerce_init', 'torobpay_declare_wc_compatibility' );
/**
 * Declare compatibility with WooCommerce features.
 *
 * @return void
 */
function torobpay_declare_wc_compatibility() {

	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}

// Always include plugin files using an absolute path to avoid name collisions with core/theme files.
$torobpay_plugin_dir = __DIR__ . '/';

require_once $torobpay_plugin_dir . 'includes/torobpay-channel.php';
require_once $torobpay_plugin_dir . 'functions.php';
require_once $torobpay_plugin_dir . 'includes/torobpay-instore.php';
require_once $torobpay_plugin_dir . 'includes/torobpay-jalali.php';
require_once $torobpay_plugin_dir . 'includes/torobpay-instore-result.php';
require_once $torobpay_plugin_dir . 'includes/torobpay-marketplace-compat.php';
require_once $torobpay_plugin_dir . 'includes/torobpay-http-allow.php';
require_once $torobpay_plugin_dir . 'hooks.php';
require_once $torobpay_plugin_dir . 'includes/admin/torobpay-order-items-ajax.php';
require_once $torobpay_plugin_dir . 'includes/admin/torobpay-instore-ajax.php';
require_once $torobpay_plugin_dir . 'class-torobpay-api.php';
require_once $torobpay_plugin_dir . 'includes/torobpay-campaign.php';
require_once $torobpay_plugin_dir . 'includes/torobpay-campaign-rest.php';
require_once $torobpay_plugin_dir . 'includes/torobpay-provision.php';
require_once $torobpay_plugin_dir . 'class-wc-gateway-torobpay.php';
require_once $torobpay_plugin_dir . 'includes/torobpay-reconcile.php';
require_once $torobpay_plugin_dir . 'includes/torobpay-notification-center.php';
require_once $torobpay_plugin_dir . 'includes/torobpay-blocked-plugins.php';
require_once $torobpay_plugin_dir . 'includes/torobpay-payment-price-parity.php';

/**
 * Load modules that depend on WooCommerce being fully loaded.
 *
 * @return void
 */
function torobpay_load_after_wc() {
	torobpay_maybe_migrate_widget_display_priority();
	require_once plugin_dir_path( __FILE__ ) . 'includes/torobpay-payment-retry-control.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/torobpay-pdp-promo.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/torobpay-widget.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/torobpay-marquee.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/torobpay-topbar.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/torobpay-slider.php';
	// Custom CSS editor removed for WordPress.org Plugin Directory compliance
	// (arbitrary user-supplied CSS is not permitted).
}
add_action( 'woocommerce_loaded', 'torobpay_load_after_wc' );

/**
 * One-time cleanup after removing the Custom CSS feature.
 */
add_action( 'admin_init', 'torobpay_cleanup_removed_custom_css', 3 );
function torobpay_cleanup_removed_custom_css() {
	if ( get_option( 'torobpay_custom_css_removed' ) ) {
		return;
	}

	delete_option( 'torobpay_custom_css_desktop' );
	delete_option( 'torobpay_custom_css_tablet' );
	delete_option( 'torobpay_custom_css_mobile' );
	update_option( 'torobpay_custom_css_removed', 1, false );
}
require_once $torobpay_plugin_dir . 'includes/torobpay-utm-handler.php';
require_once $torobpay_plugin_dir . 'includes/torobpay-dns-resolve.php';
require_once $torobpay_plugin_dir . 'includes/torobpay-dashboard-widget.php';

add_action( 'admin_init', 'torobpay_load_admin_dependencies' );
/**
 * Lazily load heavy admin-only classes.
 *
 * @return void
 */
function torobpay_load_admin_dependencies() {
	global $pagenow;

	if ( is_admin() && $pagenow === 'admin.php' && isset( $_GET['page'] ) && 'torobpay-orders' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page slug gate for lazy-loading admin class.
		require_once plugin_dir_path( __FILE__ ) . 'class-torobpay-orders-table.php';
	}
}

/**
 * Whether the merchant opted in to writing HTTP Requests Manager allow-list rules.
 *
 * @param array|null $settings Optional gateway settings; defaults to stored option.
 * @return bool
 */
function torobpay_is_hrm_whitelist_opted_in( $settings = null ) {
	if ( null === $settings ) {
		$settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', array() );
	}

	return is_array( $settings )
		&& isset( $settings['hrm_whitelist_enabled'] )
		&& 'yes' === $settings['hrm_whitelist_enabled'];
}

/**
 * Add the TorobPay API host to the HTTP Requests Manager allow list.
 *
 * Runs only when:
 * - the merchant opted in via gateway settings, and
 * - the HTTP Requests Manager plugin is active.
 *
 * Called on settings save and on admin_init (opted-in heal if the rule was removed).
 * Does not remove rules when the opt-in is turned off.
 *
 * @param array|null $settings Optional gateway settings (e.g. just-saved values).
 * @return bool True when a rule was added or already present; false otherwise.
 */
function torobpay_maybe_allow_in_http_requests_manager( $settings = null ) {
	if ( ! class_exists( 'HTTP_Requests_Manager' ) ) {
		return false;
	}

	if ( null === $settings ) {
		$settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', array() );
	}

	if ( ! torobpay_is_hrm_whitelist_opted_in( $settings ) ) {
		return false;
	}

	$base_url = isset( $settings['base_url'] ) ? trim( (string) $settings['base_url'] ) : '';

	// wp_parse_url handles schemeless URLs more gracefully than parse_url.
	$host = wp_parse_url( $base_url, PHP_URL_HOST );
	if ( empty( $host ) ) {
		$host = 'cpg.torobpay.com';
	}
	$host = strtolower( $host );

	$opts         = get_option( HTTP_Requests_Manager::ID, array() );
	$custom_rules = isset( $opts['custom_rules'] ) && is_array( $opts['custom_rules'] ) ? $opts['custom_rules'] : array();

	$rule = array(
		'domain' => $host,
		'allow'  => '', // Empty string means "everywhere" according to HRM docs
	);

	if ( method_exists( 'HTTP_Requests_Manager', 'custom_rule_hash' ) ) {
		$hash = HTTP_Requests_Manager::custom_rule_hash( $rule );
	} else {
		$hash = md5( 'domain:' . $rule['domain'] . ',allow:,' );
	}

	// Prepend if missing so it has highest priority and respect rule limit (30) implicitly.
	if ( ! isset( $custom_rules[ $hash ] ) ) {
		$custom_rules         = array( $hash => $rule ) + $custom_rules;
		$opts['custom_rules'] = $custom_rules;
		update_option( HTTP_Requests_Manager::ID, $opts );
	}

	return true;
}

/**
 * Keep the HRM allow-list rule present when the merchant has opted in.
 * Safe for review: no writes unless hrm_whitelist_enabled is yes and HRM is active.
 */
add_action( 'admin_init', 'torobpay_maybe_allow_in_http_requests_manager' );

/**
 * Scoped notice when HTTP Requests Manager is active but the merchant has not opted in.
 */
add_action( 'admin_notices', 'torobpay_hrm_whitelist_optin_notice' );
function torobpay_hrm_whitelist_optin_notice() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	if ( ! class_exists( 'HTTP_Requests_Manager' ) ) {
		return;
	}

	if ( torobpay_is_hrm_whitelist_opted_in() ) {
		return;
	}

	// Only on TorobPay gateway settings — informational, not a site-wide nag.
	if ( ! function_exists( 'torobpay_is_torobpay_admin_screen' ) || ! torobpay_is_torobpay_admin_screen() ) {
		return;
	}

	$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( 'wc-settings' !== $page || 'checkout' !== $tab || 'wc_gateway_torobpay' !== strtolower( $section ) ) {
		return;
	}

	$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_torobpay' );

	echo '<div class="notice notice-info"><p>';
	echo '<strong>' . esc_html__( 'TorobPay:', 'torobpay-for-woocommerce' ) . '</strong> ';
	echo esc_html__( 'HTTP Requests Manager is active. If TorobPay API calls are blocked, enable “Allow TorobPay API host in the HTTP Requests Manager plugin” under Advanced settings and save.', 'torobpay-for-woocommerce' );
	echo ' <a href="' . esc_url( $settings_url ) . '#woocommerce_WC_Gateway_TorobPay_hrm_whitelist_enabled">' . esc_html__( 'Open Advanced settings', 'torobpay-for-woocommerce' ) . '</a>';
	echo '</p></div>';
}
