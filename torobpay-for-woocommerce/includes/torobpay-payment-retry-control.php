<?php
/**
 * TorobPay Payment Retry Control
 *
 * This file contains all functionality related to controlling payment retry attempts
 * when the 'disable_payment_retry' setting is enabled in TorobPay gateway settings.
 *
 * @package TorobPay
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether checkout/order-pay retry should be blocked for this order.
 *
 * disable_payment_retry is checkout / order-pay only.
 * In-store POS retry (merchant QR and the customer result page) must keep
 * working; do not apply this setting to in-store orders.
 *
 * @param mixed $order Order object.
 * @return bool
 */
function torobpay_order_disables_payment_retry( $order ) {
	if ( ! $order ) {
		return false;
	}

	if ( function_exists( 'torobpay_is_instore_order' ) && torobpay_is_instore_order( $order ) ) {
		return false;
	}

	if ( ! method_exists( $order, 'get_payment_method' ) || $order->get_payment_method() !== 'WC_Gateway_TorobPay' ) {
		return false;
	}

	$gateway_settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', array() );
	if ( ! isset( $gateway_settings['disable_payment_retry'] ) || $gateway_settings['disable_payment_retry'] !== 'yes' ) {
		return false;
	}

	return (bool) ( $order->get_meta( '_torobpay_transaction_id' ) || torobpay_get_order_payment_token( $order ) );
}

/**
 * Control whether orders can be paid again
 *
 * Removes 'failed' from valid payment statuses for TorobPay orders that already
 * have transaction data when disable_payment_retry is enabled.
 */
add_filter( 'woocommerce_valid_order_statuses_for_payment', 'torobpay_control_payment_retry', 10, 2 );
function torobpay_control_payment_retry( $statuses, $order = null ) {
	if ( ! torobpay_order_disables_payment_retry( $order ) ) {
		return $statuses;
	}

	if ( ( $key = array_search( 'failed', $statuses, true ) ) !== false ) {
		unset( $statuses[ $key ] );
	}

	return $statuses;
}

/**
 * Disable pay action for orders with existing transaction data in My Account > Orders
 *
 * This removes the "Pay" button/link from the My Account orders table for TorobPay
 * orders that already have transaction data when retry is disabled.
 */
add_filter( 'woocommerce_my_account_my_orders_actions', 'torobpay_disable_pay_action', 10, 2 );
function torobpay_disable_pay_action( $actions, $order ) {
	if ( torobpay_order_disables_payment_retry( $order ) ) {
		unset( $actions['pay'] );
	}

	return $actions;
}

/**
 * Prevent payment processing if transaction already exists
 *
 * This redirects users away from the payment page if they try to access it directly
 * for a TorobPay order that already has transaction data when retry is disabled.
 */
add_action( 'woocommerce_before_pay', 'torobpay_prevent_payment_retry', 10, 1 );
function torobpay_prevent_payment_retry( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! torobpay_order_disables_payment_retry( $order ) ) {
		return;
	}

	wc_add_notice( __( 'This order has already entered the payment gateway and cannot be paid again.', 'torobpay-for-woocommerce' ), 'error' );
	wp_safe_redirect( wc_get_cart_url() );
	exit;
}

/**
 * Poison the cart hash to force a new order for TorobPay retry attempts - classic checkout.
 *
 * Only runs when disable_payment_retry is enabled and the awaiting-payment TorobPay order
 * already has transaction/token data. Payment method switches are left to WooCommerce's
 * normal order resume flow.
 */
add_filter( 'woocommerce_cart_hash', 'torobpay_poison_cart_hash_for_retry_prevention', 10, 1 );
function torobpay_poison_cart_hash_for_retry_prevention( $cart_hash ) {
	$gateway_settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', array() );
	if ( ! isset( $gateway_settings['disable_payment_retry'] ) || $gateway_settings['disable_payment_retry'] !== 'yes' ) {
		return $cart_hash;
	}

	$chosen_payment_method = '';
	if ( isset( $_POST['payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checkout payment_method read; WC verifies checkout nonce on submit.
		$chosen_payment_method = sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checkout payment_method read; WC verifies checkout nonce on submit.
	} elseif ( function_exists( 'WC' ) && WC()->session ) {
		$chosen_payment_method = WC()->session->get( 'chosen_payment_method' );
	}

	if ( $chosen_payment_method !== 'WC_Gateway_TorobPay' ) {
		return $cart_hash;
	}

	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return $cart_hash;
	}

	$order_awaiting_payment = WC()->session->get( 'order_awaiting_payment' );
	if ( ! $order_awaiting_payment ) {
		return $cart_hash;
	}

	$order = wc_get_order( $order_awaiting_payment );
	if ( ! torobpay_order_disables_payment_retry( $order ) ) {
		return $cart_hash;
	}

	// Force WooCommerce to create a new order instead of resuming the gateway-touched one.
	return $cart_hash . '_torobpay_retry_' . uniqid( '', true );
}

/**
 * Close the TorobPay order abandoned by the cart hash poison - classic checkout.
 *
 * Poisoning the hash makes WooCommerce build a fresh order instead of resuming the one
 * that already went to the gateway, but the abandoned order stays 'pending' and keeps
 * its stock reservation. Cancelling it releases the hold.
 *
 * Priority 5 runs before priority 10, where WooCommerce hooks wc_reserve_stock_for_order(),
 * so the released units are available to the order being created.
 */
add_action( 'woocommerce_checkout_order_created', 'torobpay_cancel_abandoned_retry_order', 5 );
function torobpay_cancel_abandoned_retry_order( $order ) {
	if ( ! $order instanceof WC_Order || ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	$gateway_settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', array() );
	if ( ! isset( $gateway_settings['disable_payment_retry'] ) || $gateway_settings['disable_payment_retry'] !== 'yes' ) {
		return;
	}

	$previous_order_id = absint( WC()->session->get( 'order_awaiting_payment' ) );
	if ( ! $previous_order_id || $previous_order_id === $order->get_id() ) {
		return;
	}

	$previous_order = wc_get_order( $previous_order_id );
	if ( ! torobpay_order_disables_payment_retry( $previous_order ) ) {
		return;
	}

	// A paid or already closed order was never abandoned by the poison, so it must stay as it is.
	if ( $previous_order->is_paid() || ! $previous_order->has_status( array( 'pending', 'failed' ) ) ) {
		return;
	}

	$previous_order->update_status( 'cancelled', '<span class="torobpay-notice">' . esc_html__( 'Order cancelled due to a payment retry attempt', 'torobpay-for-woocommerce' ) . '</span>' );
}

/**
 * Block checkout: when disable_payment_retry is on, refuse reuse of a TorobPay order
 * that already entered the gateway. Payment method switches use WooCommerce resume.
 */
add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'torobpay_block_checkout_prevent_reuse', 10, 2 );
function torobpay_block_checkout_prevent_reuse( $order, $request ) {
	if ( ! $order ) {
		return;
	}

	$payment_method = isset( $request['payment_method'] ) ? $request['payment_method'] : $order->get_payment_method();
	if ( $payment_method !== 'WC_Gateway_TorobPay' ) {
		return;
	}

	if ( ! torobpay_order_disables_payment_retry( $order ) ) {
		return;
	}

	$order->update_status( 'cancelled', '<span class="torobpay-notice">' . esc_html__( 'Order cancelled due to a payment retry attempt', 'torobpay-for-woocommerce' ) . '</span>' );

	if ( function_exists( 'WC' ) && WC()->session ) {
		WC()->session->__unset( 'store_api_draft_order' );
		WC()->session->__unset( 'order_awaiting_payment' );
	}

	// Stop current processing; a fresh order is created on the next place-order attempt.
	throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
		'torobpay_retry_blocked',
		esc_html__( 'This order has already entered the payment gateway. Creating a new order...', 'torobpay-for-woocommerce' )
		. '<br>'
		. esc_html__( 'If you are not redirected automatically, please click "Place order".', 'torobpay-for-woocommerce' ),
		409
	);
}

/**
 * Whether hide-retry assets should run for this order.
 *
 * @param WC_Order|false|null $order Order object.
 * @return bool
 */
function torobpay_order_should_hide_payment_retry( $order ) {
	return torobpay_order_disables_payment_retry( $order );
}

/**
 * Register, enqueue, and print an empty-handle inline style (safe after enqueue phase).
 *
 * @param string $handle Style handle.
 * @param string $css    CSS without <style> tags.
 */
function torobpay_print_inline_style( $handle, $css ) {
	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';
	wp_register_style( $handle, false, array(), $version );
	wp_enqueue_style( $handle );
	wp_add_inline_style( $handle, wp_strip_all_tags( $css ) );
	wp_print_styles( $handle );
}

/**
 * Register, enqueue, and print an empty-handle inline script (safe after enqueue phase).
 *
 * @param string   $handle Script handle.
 * @param string   $js     JS without <script> tags.
 * @param string[] $deps   Script dependencies.
 */
function torobpay_print_inline_script( $handle, $js, $deps = array() ) {
	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';
	wp_register_script( $handle, '', $deps, $version, true );
	wp_enqueue_script( $handle );
	wp_add_inline_script( $handle, $js );
	wp_print_scripts( $handle );
}

/**
 * Comprehensive hide-retry CSS (thankyou priority 1).
 *
 * @return string
 */
function torobpay_get_hide_retry_comprehensive_css() {
	return '
                /* Hide all payment retry related elements */
                .woocommerce-order-pay-button,
                .woocommerce-Button--pay,
                a[href*="order-pay"],
                .button.pay,
                .woocommerce-order-received .woocommerce-order-pay-button,
                .woocommerce-order-received .woocommerce-Button--pay,
                .woocommerce-order-received a[href*="order-pay"],
                .woocommerce-order-received .button.pay,
                .woocommerce-order .woocommerce-order-pay-button,
                .woocommerce-order .woocommerce-Button--pay,
                .woocommerce-order a[href*="order-pay"],
                .woocommerce-order .button.pay,
                .wc-order-status-failed .woocommerce-order-pay-button,
                .wc-order-status-failed .woocommerce-Button--pay,
                .wc-order-status-failed a[href*="order-pay"],
                .wc-order-status-failed .button.pay {
                    display: none !important;
                    visibility: hidden !important;
                }
                
                /* Hide any element with payment-related href */
                a[href*="/checkout/order-pay/"],
                a[href*="/checkout-"],
                a[href*="?pay_for_order=true"],
                a[href*="&key=wc_order_"],
                a[href*="order_again"] {
                    display: none !important;
                    visibility: hidden !important;
                }
                
                /* Common Try Again button patterns */
                .retry-payment,
                .payment-retry,
                .try-again,
                .pay-again {
                    display: none !important;
                    visibility: hidden !important;
                }
            ';
}

/**
 * Comprehensive hide-retry JS (thankyou priority 1).
 *
 * @return string
 */
function torobpay_get_hide_retry_comprehensive_js() {
	return '
                (function() {
                    function hideRetryElements() {
                        // Hide payment retry buttons and links by href pattern
                        var paymentElements = document.querySelectorAll(
                            "a[href*=\\"order-pay\\"], " +
                            "a[href*=\\"/checkout/order-pay/\\"], " +
                            "a[href*=\\"/checkout-\\"], " +
                            "a[href*=\\"?pay_for_order=true\\"], " +
                            "a[href*=\\"&key=wc_order_\\"], " +
                            ".woocommerce-order-pay-button, " +
                            ".woocommerce-Button--pay, " +
                            ".button.pay"
                        );
                        
                        for (var i = 0; i < paymentElements.length; i++) {
                            paymentElements[i].style.display = "none";
                            paymentElements[i].style.visibility = "hidden";
                        }
                        
                        // Hide elements by text content (for multiple languages)
                        var textPatterns = [
                            "try again",
                            "تلاش مجدد",
                            "پرداخت مجدد",
                            "pay now",
                            "Payment",
                            "پرداخت",
                            "retry payment"
                        ];
                        
                        var allElements = document.querySelectorAll("a, button, input[type=submit]");
                        for (var j = 0; j < allElements.length; j++) {
                            var element = allElements[j];
                            var text = element.textContent || element.value || "";
                            var href = element.getAttribute("href") || "";
                            
                            for (var k = 0; k < textPatterns.length; k++) {
                                if (text.toLowerCase().includes(textPatterns[k].toLowerCase())) {
                                    element.style.display = "none";
                                    element.style.visibility = "hidden";
                                    break;
                                }
                            }
                            
                            if (href.includes("order-pay") || 
                                href.includes("pay_for_order") || 
                                href.includes("/checkout") && href.includes("order-pay") ||
                                href.includes("&key=wc_order_")) {
                                element.style.display = "none";
                                element.style.visibility = "hidden";
                            }
                        }
                    }
                    
                    // Run immediately
                    hideRetryElements();
                    
                    // Run after DOM is ready
                    if (document.readyState === "loading") {
                        document.addEventListener("DOMContentLoaded", hideRetryElements);
                    }
                    
                    // Run after a short delay to catch dynamically added elements
                    setTimeout(hideRetryElements, 500);
                    setTimeout(hideRetryElements, 1000);
                    
                    if (window.MutationObserver) {
                        var observer = new MutationObserver(function(mutations) {
                            var shouldCheck = false;
                            mutations.forEach(function(mutation) {
                                if (mutation.type === "childList" && mutation.addedNodes.length > 0) {
                                    shouldCheck = true;
                                }
                            });
                            if (shouldCheck) {
                                setTimeout(hideRetryElements, 100);
                            }
                        });
                        
                        observer.observe(document.body, {
                            childList: true,
                            subtree: true
                        });
                    }
                })();
            ';
}

/**
 * Order-received page hide-retry CSS (wp_enqueue_scripts / former wp_head).
 *
 * @return string
 */
function torobpay_get_hide_retry_order_received_css() {
	return '
                .woocommerce-order-received .woocommerce-order-pay-button,
                .woocommerce-order-received .woocommerce-Button--pay,
                .woocommerce-order-received a[href*="order-pay"],
                .woocommerce-order-received .button.pay,
                .woocommerce-order .woocommerce-order-pay-button,
                .woocommerce-order .woocommerce-Button--pay,
                .woocommerce-order a[href*="order-pay"],
                .woocommerce-order .button.pay,
                .wc-order-status-failed .woocommerce-order-pay-button,
                .wc-order-status-failed .woocommerce-Button--pay,
                .wc-order-status-failed a[href*="order-pay"],
                .wc-order-status-failed .button.pay,
                .woocommerce-order a[href*="pay"]:contains("Try again"),
                .woocommerce-order a[href*="pay"]:contains("تلاش مجدد"),
                .woocommerce-order a[href*="pay"]:contains("پرداخت مجدد") {
                    display: none !important;
                }
            ';
}

/**
 * Order-received page hide-retry JS (jQuery).
 *
 * @return string
 */
function torobpay_get_hide_retry_order_received_js() {
	return '
                jQuery(document).ready(function($) {
                    // Hide any links or buttons that contain payment-related text or URLs
                    $("a, button").filter(function() {
                        var text = $(this).text().toLowerCase();
                        var href = $(this).attr("href") || "";
                        return text.includes("try again") || 
                               text.includes("تلاش مجدد") || 
                               text.includes("پرداخت مجدد") ||
                               text.includes("pay now") ||
                               href.includes("order-pay") ||
                               href.includes("pay_for_order") ||
                               href.includes("&key=wc_order_") ||
                               (href.includes("/checkout") && href.includes("order-pay"));
                    }).hide();
                });
            ';
}

/**
 * Simple thankyou hide-retry CSS (priority 5).
 *
 * @return string
 */
function torobpay_get_hide_retry_simple_css() {
	return '
                .woocommerce-order-pay-button,
                .woocommerce-Button--pay,
                a[href*="order-pay"],
                .button.pay {
                    display: none !important;
                }
            ';
}

/**
 * Simple thankyou hide-retry JS (priority 5).
 *
 * @return string
 */
function torobpay_get_hide_retry_simple_js() {
	return '
                (function() {
                    document.addEventListener("DOMContentLoaded", function() {
                        // Hide payment retry buttons and links
                        var paymentElements = document.querySelectorAll("a[href*=\\"order-pay\\"], .woocommerce-order-pay-button, .woocommerce-Button--pay, .button.pay");
                        for (var i = 0; i < paymentElements.length; i++) {
                            paymentElements[i].style.display = "none";
                        }
                        
                        // Hide links with retry-related text content
                        var allLinks = document.querySelectorAll("a, button");
                        for (var j = 0; j < allLinks.length; j++) {
                            var text = allLinks[j].textContent.toLowerCase();
                            if (text.includes("try again") || text.includes("تلاش مجدد") || text.includes("پرداخت مجدد")) {
                                allLinks[j].style.display = "none";
                            }
                        }
                    });
                })();
            ';
}

/**
 * Enqueue order-received hide-retry CSS/JS during the normal enqueue phase.
 */
add_action( 'wp_enqueue_scripts', 'torobpay_enqueue_hide_try_again_assets' );
function torobpay_enqueue_hide_try_again_assets() {
	if ( ! function_exists( 'is_wc_endpoint_url' ) || ! function_exists( 'wc_get_order' ) ) {
		return;
	}

	if ( ! is_wc_endpoint_url( 'order-received' ) ) {
		return;
	}

	global $wp;
	if ( empty( $wp->query_vars['order-received'] ) ) {
		return;
	}

	$order = wc_get_order( absint( $wp->query_vars['order-received'] ) );
	if ( ! torobpay_order_should_hide_payment_retry( $order ) ) {
		return;
	}

	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';

	wp_register_style( 'torobpay-hide-retry-order-received', false, array(), $version );
	wp_enqueue_style( 'torobpay-hide-retry-order-received' );
	wp_add_inline_style( 'torobpay-hide-retry-order-received', wp_strip_all_tags( torobpay_get_hide_retry_order_received_css() ) );

	wp_register_script( 'torobpay-hide-retry-order-received', '', array( 'jquery' ), $version, true );
	wp_enqueue_script( 'torobpay-hide-retry-order-received' );
	wp_add_inline_script( 'torobpay-hide-retry-order-received', torobpay_get_hide_retry_order_received_js() );
}

/**
 * Comprehensive solution to hide "Try again" functionality on thank you page
 *
 * This is the main function that outputs CSS and JavaScript to hide all payment
 * retry elements when disable_payment_retry is enabled. It uses multiple techniques
 * to ensure complete coverage across different themes and WooCommerce versions.
 */
add_action( 'woocommerce_thankyou', 'torobpay_hide_retry_comprehensive', 1 );
function torobpay_hide_retry_comprehensive( $order_id ) {
	if ( ! $order_id ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! torobpay_order_should_hide_payment_retry( $order ) ) {
		return;
	}

	torobpay_print_inline_style( 'torobpay-hide-retry', torobpay_get_hide_retry_comprehensive_css() );
	torobpay_print_inline_script( 'torobpay-hide-retry-js', torobpay_get_hide_retry_comprehensive_js() );
}

/**
 * Alternative approach for hiding retry elements on thank you page
 *
 * This provides a simpler fallback method for hiding payment retry elements
 * using the woocommerce_thankyou hook with priority 5.
 */
add_action( 'woocommerce_thankyou', 'torobpay_hide_retry_on_thankyou', 5 );
function torobpay_hide_retry_on_thankyou( $order_id ) {
	if ( ! $order_id ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! torobpay_order_should_hide_payment_retry( $order ) ) {
		return;
	}

	torobpay_print_inline_style( 'torobpay-hide-retry-simple', torobpay_get_hide_retry_simple_css() );
	torobpay_print_inline_script( 'torobpay-hide-retry-simple-js', torobpay_get_hide_retry_simple_js() );
}
