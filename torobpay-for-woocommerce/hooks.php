<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue admin styles for TorobPay (order notes, menu icon, order status field)
 */
add_action( 'admin_enqueue_scripts', 'torobpay_enqueue_admin_styles' );
function torobpay_enqueue_admin_styles( $hook ) {
	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';

	$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page slug for asset enqueue.

	wp_enqueue_style(
		'torobpay-admin-order',
		plugins_url( 'assets/css/admin-order.css', TOROBPAY_PLUGIN_FILE ),
		array(),
		$version
	);

	$plugin_url  = plugin_dir_url( TOROBPAY_PLUGIN_FILE );
	$logo_url    = esc_url( $plugin_url . 'assets/images/logo_favicon.svg' );
	$dynamic_css = sprintf(
		'.note_content:has(.torobpay-success) { background-image: url("%1$s") !important; }
         .note_content:has(.torobpay-notice) { background-image: url("%1$s") !important; }',
		$logo_url
	);
	wp_add_inline_style( 'torobpay-admin-order', wp_strip_all_tags( $dynamic_css ) );

	$torobpay_pages = array(
		'toplevel_page_torobpay-orders',
		'torobpay_page_torobpay-orders',
		'torobpay-orders_page_torobpay-campaign',
		'torobpay-orders_page_torobpay-support',
		'torobpay-orders_page_torobpay-widget',
		'torobpay-orders_page_torobpay-settings',
	);

	if ( in_array( $hook, $torobpay_pages, true ) || strpos( $hook, 'torobpay' ) !== false ) {
		wp_enqueue_style(
			'torobpay-admin-shared',
			plugins_url( 'assets/css/admin-shared.css', TOROBPAY_PLUGIN_FILE ),
			array(),
			$version
		);
		torobpay_enqueue_admin_toast_assets();
	}

	// Submenu under torobpay-orders → hook is torobpay-orders_page_torobpay-campaign, not torobpay_page_*.
	if ( 'torobpay-campaign' === $current_page || false !== strpos( (string) $hook, 'torobpay-campaign' ) ) {
		wp_enqueue_style(
			'torobpay-admin-campaign',
			plugins_url( 'assets/css/admin-campaign.css', TOROBPAY_PLUGIN_FILE ),
			array( 'dashicons', 'torobpay-admin-shared' ),
			$version
		);
	}

	if ( $hook === 'woocommerce_page_wc-settings' ) {
		wp_enqueue_style(
			'torobpay-admin-shared',
			plugins_url( 'assets/css/admin-shared.css', TOROBPAY_PLUGIN_FILE ),
			array(),
			$version
		);
	}

	$wc_section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only WC settings section for asset enqueue.
	if ( $hook === 'woocommerce_page_wc-settings'
		&& in_array( strtolower( $wc_section ), array( 'wc_gateway_torobpay' ), true ) ) {
		wp_enqueue_style(
			'torobpay-admin-gateway',
			plugins_url( 'assets/css/admin-gateway.css', TOROBPAY_PLUGIN_FILE ),
			array(),
			$version
		);
		wp_enqueue_script(
			'torobpay-admin-gateway',
			plugins_url( 'assets/js/admin-gateway.js', TOROBPAY_PLUGIN_FILE ),
			array( 'jquery', 'torobpay-admin-toast', 'wp-i18n' ),
			$version,
			true
		);
		wp_set_script_translations( 'torobpay-admin-gateway', 'torobpay-for-woocommerce', dirname( TOROBPAY_PLUGIN_FILE ) . '/languages' );
		torobpay_enqueue_admin_toast_assets();
	}

	if ( $hook === 'index.php' ) {
		wp_enqueue_style(
			'torobpay-dashboard-widget',
			plugins_url( 'assets/css/admin-dashboard-widget.css', TOROBPAY_PLUGIN_FILE ),
			array(),
			$version
		);
	}

	$orders_hooks = array( 'toplevel_page_torobpay-orders', 'torobpay_page_torobpay-orders' );
	if ( in_array( $hook, $orders_hooks, true ) ) {
		wp_enqueue_style(
			'torobpay-jalalidatepicker',
			plugins_url( 'assets/vendor/jalalidatepicker/jalalidatepicker.css', TOROBPAY_PLUGIN_FILE ),
			array(),
			$version
		);
		wp_enqueue_script(
			'torobpay-jalalidatepicker',
			plugins_url( 'assets/vendor/jalalidatepicker/jalalidatepicker.js', TOROBPAY_PLUGIN_FILE ),
			array(),
			$version,
			true
		);
		wp_enqueue_style(
			'torobpay-admin-orders-table',
			plugins_url( 'assets/css/admin-orders-table.css', TOROBPAY_PLUGIN_FILE ),
			array( 'torobpay-jalalidatepicker', 'torobpay-admin-shared' ),
			$version
		);
		torobpay_enqueue_admin_toast_assets();
		wp_enqueue_script(
			'torobpay-admin-orders-table',
			plugins_url( 'assets/js/admin-orders-table.js', TOROBPAY_PLUGIN_FILE ),
			array( 'jquery', 'torobpay-jalalidatepicker', 'torobpay-admin-toast', 'wp-i18n' ),
			$version,
			true
		);
		wp_set_script_translations( 'torobpay-admin-orders-table', 'torobpay-for-woocommerce', dirname( TOROBPAY_PLUGIN_FILE ) . '/languages' );
		wp_localize_script(
			'torobpay-admin-orders-table',
			'torobpayAdmin',
			torobpay_get_admin_order_actions_localize_data()
		);
		if ( function_exists( 'torobpay_enqueue_admin_instore_assets' ) ) {
			torobpay_enqueue_admin_instore_assets();
		}
	}

	wp_enqueue_style(
		'torobpay-admin-notification-center',
		plugins_url( 'assets/css/admin-notification-center.css', TOROBPAY_PLUGIN_FILE ),
		array( 'torobpay-admin-order' ),
		$version
	);
	$notif_icon_url = esc_url( plugins_url( 'assets/images/icon.png', TOROBPAY_PLUGIN_FILE ) );
	wp_add_inline_style(
		'torobpay-admin-notification-center',
		wp_strip_all_tags(
			'#wpadminbar #wp-admin-bar-torobpay-notifications > .ab-item .ab-icon:before,'
			. '#wpadminbar #wp-admin-bar-torobpay-notifications > .ab-item .torobpay-notif-logo-wrap:before{'
			. 'content:""!important;'
			. 'display:block!important;'
			. 'width:20px!important;'
			. 'height:20px!important;'
			. 'margin:0!important;'
			. 'padding:0!important;'
			. 'background-color:transparent!important;'
			. 'background-image:url("' . $notif_icon_url . '")!important;'
			. 'background-repeat:no-repeat!important;'
			. 'background-position:center!important;'
			. 'background-size:contain!important;'
			. '}'
		)
	);
	wp_enqueue_script(
		'torobpay-admin-notification-center',
		plugins_url( 'assets/js/admin-notification-center.js', TOROBPAY_PLUGIN_FILE ),
		array( 'jquery', 'wp-i18n' ),
		$version,
		true
	);
	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'torobpay-admin-notification-center', 'torobpay-for-woocommerce', dirname( TOROBPAY_PLUGIN_FILE ) . '/languages' );
	}
	wp_localize_script(
		'torobpay-admin-notification-center',
		'torobpayNotifCenter',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'torobpay-dismiss-notification' ),
		)
	);

	// Load widget settings page assets (live preview + display toggles).
	if ( $current_page === 'torobpay-widget' ) {
		wp_enqueue_style(
			'torobpay-widget-style',
			plugins_url( 'assets/css/widget.css', TOROBPAY_PLUGIN_FILE ),
			array(),
			$version
		);
		wp_enqueue_style(
			'torobpay-jalalidatepicker',
			plugins_url( 'assets/vendor/jalalidatepicker/jalalidatepicker.css', TOROBPAY_PLUGIN_FILE ),
			array(),
			$version
		);
		wp_enqueue_script(
			'torobpay-jalalidatepicker',
			plugins_url( 'assets/vendor/jalalidatepicker/jalalidatepicker.js', TOROBPAY_PLUGIN_FILE ),
			array(),
			$version,
			true
		);
		wp_enqueue_style(
			'torobpay-widget-settings',
			plugins_url( 'assets/css/widget-settings.css', TOROBPAY_PLUGIN_FILE ),
			array( 'torobpay-widget-style', 'torobpay-jalalidatepicker' ),
			$version
		);
		wp_enqueue_media();
		wp_enqueue_script(
			'torobpay-widget-settings',
			plugins_url( 'assets/js/widget-settings.js', TOROBPAY_PLUGIN_FILE ),
			array( 'torobpay-admin-toast', 'wp-i18n', 'torobpay-jalalidatepicker' ),
			$version,
			true
		);
		wp_set_script_translations( 'torobpay-widget-settings', 'torobpay-for-woocommerce', dirname( TOROBPAY_PLUGIN_FILE ) . '/languages' );
		wp_localize_script(
			'torobpay-widget-settings',
			'torobpayDisplayToggles',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'torobpay_display_toggles' ),
			)
		);
		$layout_presets_js = array();
		if ( function_exists( 'torobpay_widget_layout_presets' ) ) {
			foreach ( torobpay_widget_layout_presets() as $slug => $preset ) {
				$accents = function_exists( 'torobpay_get_widget_layout_accent_colors' )
					? torobpay_get_widget_layout_accent_colors( $slug )
					: array(
						'accent'      => '#9CE76A',
						'accent_dark' => '#003D01',
					);
				$layout_presets_js[ $slug ] = array(
					'defaults' => isset( $preset['defaults'] ) ? $preset['defaults'] : array(),
					'swatch'   => array(
						'accent'      => $accents['accent'],
						'accent_dark' => $accents['accent_dark'],
					),
				);
			}
		}
		wp_localize_script(
			'torobpay-widget-settings',
			'torobpayWidgetLayouts',
			array(
				'presets' => $layout_presets_js,
			)
		);

		$loader_js = '( function () {
				var wrap = document.querySelector( \'.torobpay-visual-settings-wrap\' );
				if ( wrap ) {
					wrap.dataset.loaderStartedAt = String( Date.now() );
				}
			} )();';
		wp_add_inline_script( 'torobpay-widget-settings', $loader_js, 'before' );

		$hook_toggle_js = "( function () {
					var hookSelect = document.getElementById('widget_display_hook');
					if ( ! hookSelect ) {
						return;
					}
					hookSelect.addEventListener('change', function() {
						var customRow = document.getElementById('custom_hook_row');
						if ( customRow ) {
							customRow.style.display = this.value === 'custom' ? '' : 'none';
						}
					});
				} )();";
		wp_add_inline_script( 'torobpay-widget-settings', $hook_toggle_js );

		$topbar_bg_js = <<<'JS'
( function () {
	var bgSelect = document.getElementById( 'topbar_bg_animation' );
	if ( ! bgSelect ) {
		return;
	}
	var rows = document.querySelectorAll( '.torobpay-topbar-bg-control' );
	var canvasModes = { perlin: true, rain: true, float: true };

	function syncTopbarBgControls() {
		var anim = bgSelect.value;
		var showBg = anim !== 'none';
		var showCanvas = !! canvasModes[ anim ];
		for ( var i = 0; i < rows.length; i++ ) {
			if ( rows[ i ].classList.contains( 'torobpay-topbar-bg-canvas-control' ) ) {
				rows[ i ].style.display = showCanvas ? '' : 'none';
			} else {
				rows[ i ].style.display = showBg ? '' : 'none';
			}
		}
	}

	bgSelect.addEventListener( 'change', syncTopbarBgControls );
	syncTopbarBgControls();
} )();
JS;
		wp_add_inline_script( 'torobpay-widget-settings', $topbar_bg_js );

		$topbar_position_js = <<<'JS'
( function () {
	var positionSelect = document.getElementById( 'topbar_position' );
	var offsetRow = document.getElementById( 'topbar_sticky_offset_row' );
	var selectorRow = document.getElementById( 'topbar_sticky_header_selector_row' );
	var zIndexRow = document.getElementById( 'topbar_z_index_row' );
	if ( positionSelect && offsetRow && selectorRow ) {
		positionSelect.addEventListener( 'change', function () {
			var rowDisplay = this.value === 'normal' ? 'none' : '';
			offsetRow.style.display = rowDisplay;
			selectorRow.style.display = rowDisplay;
			if ( zIndexRow ) {
				zIndexRow.style.display = rowDisplay;
			}
		} );
	}
} )();
JS;
		wp_add_inline_script( 'torobpay-widget-settings', $topbar_position_js );

		$topbar_placement_js = <<<'JS'
( function () {
	var placementRadios = document.querySelectorAll( 'input[name="topbar_placement"]' );
	var behaviorBlock   = document.getElementById( 'topbar-automatic-behavior' );
	if ( ! placementRadios.length || ! behaviorBlock ) {
		return;
	}

	function syncTopbarPlacementUi() {
		var mode = 'automatic';
		for ( var i = 0; i < placementRadios.length; i++ ) {
			if ( placementRadios[ i ].checked ) {
				mode = placementRadios[ i ].value;
				break;
			}
		}
		behaviorBlock.style.display = mode === 'manual' ? 'none' : '';
	}

	for ( var j = 0; j < placementRadios.length; j++ ) {
		placementRadios[ j ].addEventListener( 'change', syncTopbarPlacementUi );
	}
	syncTopbarPlacementUi();
} )();
JS;
		wp_add_inline_script( 'torobpay-widget-settings', $topbar_placement_js );
	}
}

/**
 * SECURITY: Detect and log potential session/cache contamination issues.
 * This hook fires when WooCommerce is about to create/update an order from checkout data.
 * We verify that the order being used matches the expected customer session.
 */
add_action( 'woocommerce_checkout_order_created', 'torobpay_detect_order_session_mismatch', 5, 1 );
function torobpay_detect_order_session_mismatch( $order ) {
	if ( ! $order || ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	$order_customer_id   = $order->get_customer_id();
	$current_user_id     = get_current_user_id();
	$session_customer_id = WC()->session->get( 'customer_id' );

	$mismatch_detected = false;
	$mismatch_details  = array();

	if ( $current_user_id > 0 && $order_customer_id > 0 && $current_user_id !== $order_customer_id ) {
		$mismatch_detected  = true;
		$mismatch_details[] = sprintf(
			'Logged-in user %d but order assigned to customer %d',
			$current_user_id,
			$order_customer_id
		);
	}

	if ( $session_customer_id && $current_user_id > 0 && absint( $session_customer_id ) !== $current_user_id ) {
		$mismatch_detected  = true;
		$mismatch_details[] = sprintf(
			'Session customer_id %s but logged-in user is %d',
			$session_customer_id,
			$current_user_id
		);
	}

	// Log security warning if mismatch detected
	if ( $mismatch_detected ) {
		$log_message = sprintf(
			'TorobPay SECURITY WARNING: Potential session contamination detected for order #%d. Details: %s. IP: %s, User-Agent: %s',
			$order->get_id(),
			implode( '; ', $mismatch_details ),
			isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown',
			isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown'
		);

		torobpay_debug_log( $log_message );
	}
}

register_activation_hook( TOROBPAY_PLUGIN_FILE, 'torobpay_wc_gateway_activate' );
/**
 * Run activation tasks.
 *
 * @return void
 */
function torobpay_wc_gateway_activate() {
	/**
	 * Fires after TorobPay gateway activation tasks complete.
	 */
	do_action( 'torobpay_wc_gateway_activated' );
}

register_deactivation_hook( TOROBPAY_PLUGIN_FILE, 'torobpay_wc_gateway_deactivate' );
/**
 * Clear scheduled jobs on deactivation.
 *
 * @return void
 */
function torobpay_wc_gateway_deactivate() {
	if ( function_exists( 'torobpay_clear_provision_schedules' ) ) {
		torobpay_clear_provision_schedules();
	}
}


add_action( 'woocommerce_order_status_changed', 'torobpay_order_status_update_items', 99, 4 );

/**
 * Update TorobPay with the latest order details when order status changes from pending/on-hold to processing/completed
 *
 * @param int $order_id The order ID
 * @param string $from_status The old status
 * @param string $to_status The new status
 * @param WC_Order $order The order object
 */
function torobpay_order_status_update_items( $order_id, $from_status, $to_status, $order ) {
	return torobpay_sync_order_items_to_torobpay( $order_id, $from_status, $to_status, $order );
}

/**
 * Sync changed order items to TorobPay and report the result.
 *
 * @param int      $order_id The order ID.
 * @param string   $from_status The old status.
 * @param string   $to_status The new status.
 * @param WC_Order $order The order object.
 * @param bool     $force When true, skip status-transition guards (edit-sync modal save).
 * @return true|WP_Error True when synced or intentionally skipped; WP_Error on failed sync.
 */
function torobpay_sync_order_items_to_torobpay( $order_id, $from_status, $to_status, $order, $force = false ) {
	// Marketplace vendor sub-orders have no payment token.
	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		return true;
	}

	torobpay_debug_log( sprintf( 'TorobPay Debug: order_status_changed fired for order %d: from=%s to=%s payment_method=%s', $order_id, $from_status, $to_status, method_exists( $order, 'get_payment_method' ) ? $order->get_payment_method() : 'N/A' ) );
	$order_payment_method = $order->get_payment_method();
	if ( ! torobpay_is_torobpay_payment_method( $order_payment_method ) ) {
		return true;
	}

	// In-store POS never goes through process_payment(). Completing that payment
	// (pending → completed) is not an item edit; calling update() fails with API 1012
	// ("new amount must be less than the original amount"). Edit-sync still uses $force.
	if ( ! $force && function_exists( 'torobpay_is_instore_order' ) && torobpay_is_instore_order( $order ) ) {
		torobpay_debug_log( sprintf( 'TorobPay Debug: Skipping item update() for in-store order %d', $order_id ) );
		return true;
	}

	// Skip the unlock transition (any paid status → on-hold) during edit-sync flow.
	if ( ! $force && $order->get_meta( '_torobpay_editing_items' ) === 'yes' && $to_status === 'on-hold' ) {
		return true;
	}

	// Status-change hook only syncs on pending/on-hold → processing/completed.
	// The edit-sync modal passes $force so custom restore targets still reach TorobPay.
	if ( ! $force ) {
		if ( ! in_array( $to_status, array( 'processing', 'completed' ), true ) ) {
			return true;
		}

		if ( ! in_array( $from_status, array( 'pending', 'on-hold' ), true ) ) {
			return true;
		}
	}

	$order_data    = [
		'items'    => $order->get_items(),
		'total'    => $order->get_total(),
		'discount' => $order->get_discount_total(),
	];
	$items_hash    = md5( json_encode( $order_data ) );
	$previous_hash = $order->get_meta( '_torobpay_items_hash' );
	$item_count = is_array( $order_data['items'] ) ? count( $order_data['items'] ) : ( $order_data['items'] instanceof Countable ? count( $order_data['items'] ) : 'n/a' );
	torobpay_debug_log( sprintf( 'TorobPay Debug: order %d hash check previous=%s current=%s item_count=%s total=%s discount=%s', $order_id, $previous_hash ? $previous_hash : 'none', $items_hash, $item_count, $order_data['total'], $order_data['discount'] ) );

	if ( $previous_hash && $previous_hash === $items_hash ) {
		torobpay_debug_log( sprintf( 'TorobPay Debug: Skipping order %d because items hash unchanged', $order_id ) );
		return true;
	}

	$order_payment_token = torobpay_get_order_payment_token( $order );
	if ( ! $order_payment_token ) {
		return new WP_Error( 'torobpay_missing_payment_token', __( 'TorobPay payment token was not found for this order.', 'torobpay-for-woocommerce' ) );
	}

	torobpay_debug_log( sprintf( 'TorobPay Debug: Sending update() for order %d', $order_id ) );

	$torobPayAPI = new TorobPayAPI();
	$response    = torobpay_retry_api_call(
		function () use ( $torobPayAPI, $order, $order_payment_token ) {
			return $torobPayAPI->update( $order, $order_payment_token );
		},
		3,
		'Update',
		$order_id
	);

	torobpay_handle_response( $order, $response, 'update' );
	if ( is_wp_error( $response ) ) {
		torobpay_debug_log( sprintf( 'TorobPay Debug: update() response error for order %d: %s', $order_id, $response->get_error_message() ) );
	} else {
		$successful = isset( $response['successful'] ) && $response['successful'] ? 'true' : 'false';
		torobpay_debug_log( sprintf( 'TorobPay Debug: update() response for order %d successful=%s', $order_id, $successful ) );
	}

	if ( ! torobpay_api_response_is_successful( $response ) ) {
		return new WP_Error(
			'torobpay_update_failed',
			torobpay_get_api_response_error_message( $response, __( 'Updating the order in TorobPay failed.', 'torobpay-for-woocommerce' ) )
		);
	}

	$order->update_meta_data( '_torobpay_items_hash', $items_hash );
	$order->save();
	torobpay_debug_log( sprintf( 'TorobPay Debug: Stored new items hash for order %d: %s', $order_id, $items_hash ) );

	return true;
}

add_action( 'wp_insert_comment', 'torobpay_remove_redundant_refund_note_after_insert', PHP_INT_MAX, 2 );
function torobpay_remove_redundant_refund_note_after_insert( $comment_id, $comment ) {
	if ( is_object( $comment ) ) {
		$comment_type = $comment->comment_type ?? '';
		$order_id     = isset( $comment->comment_post_ID ) ? absint( $comment->comment_post_ID ) : 0;
		$content      = isset( $comment->comment_content ) ? wp_strip_all_tags( $comment->comment_content ) : '';
	} else {
		$comment_type = isset( $comment['comment_type'] ) ? $comment['comment_type'] : '';
		$order_id     = isset( $comment['comment_post_ID'] ) ? absint( $comment['comment_post_ID'] ) : 0;
		$content      = isset( $comment['comment_content'] ) ? wp_strip_all_tags( $comment['comment_content'] ) : '';
	}

	if ( $comment_type !== 'order_note' ) {
		return;
	}

	if ( ! $order_id ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	if ( ! torobpay_is_torobpay_payment_method( $order->get_payment_method() ) ) {
		return;
	}

	if ( $content === '' ) {
		return;
	}

	if ( stripos( $content, __( 'TorobPay', 'torobpay-for-woocommerce' ) ) !== false ) {
		return;
	}

	$matches_redundant = (
		stripos( $content, __( 'Order status is set for refund', 'torobpay-for-woocommerce' ) ) !== false ||
		stripos( $content, 'Order status set to refunded' ) !== false
	);

	if ( $matches_redundant ) {
		wp_delete_comment( $comment_id, true );
	}
}

add_filter( 'woocommerce_available_payment_gateways', 'torobpay_filter_gateways_by_cart_amount', PHP_INT_MAX - 2, 1 );

function torobpay_filter_gateways_by_cart_amount( $available_gateways ) {

	if ( ! is_checkout() ) {
		return $available_gateways;
	}

	// Thank-you has no payment method UI.
	if ( ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) )
		|| ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) ) {
		return $available_gateways;
	}

	// Avoid re-entering eligibility during parity probe calculate_totals().
	if ( ! function_exists( 'torobpay_is_payment_price_parity_probing' ) ) {
		require_once dirname( TOROBPAY_PLUGIN_FILE ) . '/includes/torobpay-payment-price-parity.php';
	}
	if ( torobpay_is_payment_price_parity_probing() ) {
		return $available_gateways;
	}

	if ( did_action( 'woocommerce_checkout_before_customer_details' ) ) {
		return $available_gateways;
	}

	if ( isset( $_POST['_wp_http_referer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence-only guard during checkout gateway filtering; no action taken on value.
		return $available_gateways;
	}

	$torobpay_settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings' );

	if ( ! is_array( $torobpay_settings ) || ( $torobpay_settings['enabled'] ?? '' ) !== 'yes' ) {
		unset( $available_gateways['WC_Gateway_TorobPay'] );
		return $available_gateways;
	}

	// Safeguard: If TorobPay is enabled but not in available gateways,
	// try to get it from WooCommerce payment gateways registry
	if ( ! isset( $available_gateways['WC_Gateway_TorobPay'] ) && function_exists( 'WC' ) && WC() ) {
		$payment_gateways = WC()->payment_gateways();
		if ( $payment_gateways && isset( $payment_gateways->payment_gateways['WC_Gateway_TorobPay'] ) ) {
			$available_gateways['WC_Gateway_TorobPay'] = $payment_gateways->payment_gateways['WC_Gateway_TorobPay'];
		}
	}

	if ( ! WC() || ! WC()->cart ) {
		// Cart not available - likely in admin/editor context, keep gateway available
		return $available_gateways;
	}

	$amount   = WC()->cart->get_total( 'edit' );
	$currency = get_woocommerce_currency();

	if ( ! function_exists( 'torobpay_resolve_eligibility' ) ) {
		require_once dirname( TOROBPAY_PLUGIN_FILE ) . '/includes/torobpay-eligibility.php';
	}

	$eligibility = torobpay_resolve_eligibility( $amount, $currency );

	if ( $eligibility['wp_error'] ) {
		wc_print_notice( $eligibility['wp_error']->get_error_message(), 'error' );
		unset( $available_gateways['WC_Gateway_TorobPay'] );
		return $available_gateways;
	}

	if ( $eligibility['eligible'] ) {
		if ( isset( $available_gateways['WC_Gateway_TorobPay'] ) ) {
			$gateway              = $available_gateways['WC_Gateway_TorobPay'];
			$gateway->title       = $eligibility['title'];
			$gateway->description = $eligibility['description'];
		}
	} else {
		unset( $available_gateways['WC_Gateway_TorobPay'] );
	}

	return $available_gateways;
}

/**
 * Modify checkout fields to enforce TorobPay requirements for Classic Checkout
 *
 * This makes postal code and phone required based on TorobPay settings.
 * The validation is payment-method-specific (enforced in validation hook below).
 */
add_filter( 'woocommerce_checkout_fields', 'torobpay_checkout_fields', 98 );
function torobpay_checkout_fields( $fields ) {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return $fields;
	}

	$torobpay_settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings' );

	if ( ! is_array( $torobpay_settings ) ) {
		return $fields;
	}

	$torobpay_enabled = isset( $torobpay_settings['enabled'] ) && $torobpay_settings['enabled'] === 'yes';
	if ( ! $torobpay_enabled ) {
		return $fields;
	}

	$postal_enabled = isset( $torobpay_settings['postal_enabled'] ) ? $torobpay_settings['postal_enabled'] : 'no';
	$mobile_enabled = isset( $torobpay_settings['mobile_enabled'] ) ? $torobpay_settings['mobile_enabled'] : 'yes';

	// If postal code is enabled (set to 'yes'), ensure it's marked as required
	if ( $postal_enabled === 'yes' ) {
		if ( isset( $fields['billing']['billing_postcode'] ) ) {
			$fields['billing']['billing_postcode']['required'] = true;
			$fields['billing']['billing_postcode']['class'][]  = 'torobpay-required-field';
		}
		if ( isset( $fields['shipping']['shipping_postcode'] ) ) {
			$fields['shipping']['shipping_postcode']['required'] = true;
			$fields['shipping']['shipping_postcode']['class'][]  = 'torobpay-required-field';
		}
	}

	// If mobile is enabled (set to 'yes'), ensure it's marked as required
	if ( $mobile_enabled === 'yes' ) {
		if ( isset( $fields['billing']['billing_phone'] ) ) {
			$fields['billing']['billing_phone']['required'] = true;
			$fields['billing']['billing_phone']['class'][]  = 'torobpay-required-field';
		}
		if ( isset( $fields['shipping']['shipping_phone'] ) ) {
			$fields['shipping']['shipping_phone']['required'] = true;
			$fields['shipping']['shipping_phone']['class'][]  = 'torobpay-required-field';
		}
	}

	return $fields;
}

add_filter( 'plugin_action_links_' . TOROBPAY_PLUGIN, 'torobpay_plugin_action_links' );
function torobpay_plugin_action_links( $links ) {
	$params = array(
		'page'    => 'wc-settings',
		'tab'     => 'checkout',
		'section' => 'WC_Gateway_TorobPay',
	);

	$admin_url    = add_query_arg( $params, 'admin.php' );
	$plugin_links = array(
		"<a href='" . esc_url( $admin_url ) . "'>" . esc_html__( 'Settings', 'torobpay-for-woocommerce' ) . '</a>',
		"<a href='https://torobpay.com/'>" . esc_html__( 'Support', 'torobpay-for-woocommerce' ) . '</a>',
	);

	return array_merge( $plugin_links, $links );
}

// Note: When wc_create_refund() is used (via the Refund button), it already handles
// stock restoration. This hook ensures stock is restored when order status is manually
// changed to failed/refunded without using the refund button.
add_action( 'woocommerce_order_status_failed', 'torobpay_handle_restore_stock', 10, 2 );
add_action( 'woocommerce_order_status_refunded', 'torobpay_handle_restore_stock', 10, 2 );

function torobpay_handle_restore_stock( $order_id, $order ) {
	if ( ! torobpay_is_torobpay_payment_method( $order->get_payment_method() ) ) {
		return;
	}
	// Sub-orders duplicate the parent's line items; restocking both over-restocks.
	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		return;
	}
	torobpay_clear_order_cache( $order );
	torobpay_restore_stock( $order );
}

/**
 * Clear all TorobPay cached data from an order
 * This includes verification status, API transaction ID, and cached status
 *
 * @param WC_Order $order The order to clear cache from
 */
function torobpay_clear_order_cache( $order ) {
	$order->delete_meta_data( '_torobpay_verification_status' );
	$order->delete_meta_data( '_torobpay_verification_timestamp' );
	$order->delete_meta_data( '_torobpay_verification_hash' );
	$order->delete_meta_data( '_torobpay_api_transaction_id' );
	$order->delete_meta_data( '_torobpay_cached_status' );
	$order->delete_meta_data( '_torobpay_cached_status_time' );
	$order->save_meta_data();
}

if ( torobpay_has_comission() ) {
	add_action( 'product_cat_add_form_fields', 'torobpay_add_commission_type_new_field', 10, 2 );
	add_action( 'product_cat_edit_form_fields', 'torobpay_add_commission_type_edit_field', 10, 2 );
	add_action( 'edited_product_cat', 'torobpay_save_taxonomy_commission_type', 10, 2 );
	add_action( 'create_product_cat', 'torobpay_save_taxonomy_commission_type', 10, 2 );
}

function torobpay_add_commission_type_new_field() {
	?>
	<div class="form-field">
		<label for="trb_commission_type"><?php echo esc_html__( 'Commission type', 'torobpay-for-woocommerce' ); ?></label>
		<input type="text" name="trb_commission_type" id="trb_commission_type" value="">
		<p class="description"><?php echo esc_html__( 'Enter the commission type specified for this category', 'torobpay-for-woocommerce' ); ?></p>
	</div>
	<?php
}

function torobpay_add_commission_type_edit_field( $term ) {

	$t_id = $term->term_id;

	$trb_commission_type = get_term_meta( $t_id, 'trb_commission_type', true );
	?>
	<tr class="form-field">
		<th scope="row" valign="top"><label for="trb_commission_type"><?php echo esc_html__( 'Commission type', 'torobpay-for-woocommerce' ); ?></label></th>
		<td>
			<input type="text" name="trb_commission_type" id="trb_commission_type" value="<?php echo esc_attr( $trb_commission_type ) ? esc_attr( $trb_commission_type ) : ''; ?>">
			<p class="description"><?php echo esc_html__( 'Enter the commission type specified for this category', 'torobpay-for-woocommerce' ); ?></p>
		</td>
	</tr>
	<?php
}

function torobpay_save_taxonomy_commission_type( $term_id ) {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	if ( ! isset( $_POST['trb_commission_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Fires on create_product_cat/edited_product_cat after WP core verifies taxonomy nonce.
		return;
	}
	$commission_type = sanitize_text_field( wp_unslash( $_POST['trb_commission_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Fires on create_product_cat/edited_product_cat after WP core verifies taxonomy nonce.
	update_term_meta( $term_id, 'trb_commission_type', $commission_type );
}

add_action( 'admin_init', 'torobpay_check_if_woocommerce_installed' );
function torobpay_check_if_woocommerce_installed() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( is_admin() && current_user_can( 'activate_plugins' ) && ! is_plugin_active( 'woocommerce/woocommerce.php' ) && ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'torobpay_woo_active_notice' );

		deactivate_plugins( TOROBPAY_PLUGIN );
		if ( isset( $_GET['activate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Clearing WP activate notice flag after forced deactivation.
			unset( $_GET['activate'] );
		}
	}
}

add_action( 'admin_notices', 'torobpay_show_auth_error_notice' );
function torobpay_show_auth_error_notice() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	// Guideline 11: keep operational notices on TorobPay / WooCommerce screens.
	// Clears itself when auth succeeds (see class-torobpay-api.php).
	if ( ! torobpay_should_render_scoped_admin_notice() ) {
		return;
	}

	$auth_error = get_option( 'torobpay_auth_error' );

	if ( empty( $auth_error ) || ! is_array( $auth_error ) ) {
		return;
	}

	$is_missing_credentials = isset( $auth_error['type'] ) && 'missing_credentials' === $auth_error['type'];

	// Auth failures only apply when the gateway is fully configured; missing-credential
	// notices must still show when one or more fields are empty.
	if ( ! $is_missing_credentials && ( ! function_exists( 'torobpay_is_configured' ) || ! torobpay_is_configured() ) ) {
		return;
	}

	$message = isset( $auth_error['friendly_message'] ) ? $auth_error['friendly_message'] : '';

	if ( empty( $message ) ) {
		$message = __( 'Error connecting to TorobPay. Please check the settings.', 'torobpay-for-woocommerce' );
		if ( ! empty( $auth_error['message'] ) ) {
			$message .= ' (' . $auth_error['message'] . ')';
		}
	}

	$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_torobpay' );
	$dismiss_url  = wp_nonce_url( admin_url( 'admin-post.php?action=torobpay_dismiss_auth_error' ), 'torobpay_dismiss_auth_error' );
	$show_fetch   = function_exists( 'torobpay_should_show_manual_fetch' ) && torobpay_should_show_manual_fetch();
	$notice_class = $is_missing_credentials ? 'notice-warning' : 'notice-error';

	if ( $show_fetch && function_exists( 'torobpay_enqueue_provision_fetch_assets' ) ) {
		torobpay_enqueue_provision_fetch_assets();
	}

	echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible torobpay-auth-notice">';
	echo '<p><strong>' . esc_html__( 'TorobPay:', 'torobpay-for-woocommerce' ) . '</strong> ' . esc_html( $message ) . '</p>';
	echo '<p>';
	echo '<a href="' . esc_url( $settings_url ) . '" class="button button-primary torobpay-button-primary">' . esc_html__( 'Review settings', 'torobpay-for-woocommerce' ) . '</a> ';

	if ( $show_fetch && function_exists( 'torobpay_get_manual_fetch_button_html' ) ) {
		echo wp_kses(
			torobpay_get_manual_fetch_button_html( 'button-primary torobpay-button-primary', __( 'Fetch account details from TorobPay', 'torobpay-for-woocommerce' ) ),
			torobpay_kses_allowed_html()
		) . ' ';
	}

	echo '<a href="' . esc_url( $dismiss_url ) . '" class="button">' . esc_html__( 'Dismiss this message', 'torobpay-for-woocommerce' ) . '</a>';
	echo '</p>';
	echo '</div>';
}

add_action( 'admin_post_torobpay_dismiss_auth_error', 'torobpay_handle_dismiss_auth_error' );
function torobpay_handle_dismiss_auth_error() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'Unauthorized', 'torobpay-for-woocommerce' ) );
	}

	check_admin_referer( 'torobpay_dismiss_auth_error' );

	delete_option( 'torobpay_auth_error' );

	$redirect = wp_get_referer();
	if ( ! $redirect ) {
		$redirect = admin_url();
	}

	wp_safe_redirect( $redirect );
	exit;
}

/**
 * Show an admin notice when WP_HTTP_BLOCK_EXTERNAL blocks TorobPay.
 *
 * wp-config.php stays the durable fix. The gateway checkbox is the quick fix.
 * A previous dismiss of this notice stays dismissed.
 */
add_action( 'admin_notices', 'torobpay_show_http_block_notice' );
function torobpay_show_http_block_notice() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	if ( ! torobpay_should_render_scoped_admin_notice() ) {
		return;
	}

	if ( torobpay_is_http_unblock_opted_in() ) {
		return;
	}

	$dismissed = get_option( 'torobpay_http_block_notice_dismissed', false );
	if ( $dismissed ) {
		return;
	}

	$host = torobpay_http_allow_request_host();
	if ( ! torobpay_http_constant_blocks_host( $host ) ) {
		return;
	}

	$dismiss_url  = wp_nonce_url(
		admin_url( 'admin-post.php?action=torobpay_dismiss_http_block_notice' ),
		'torobpay_dismiss_http_block_notice'
	);
	$settings_url = torobpay_http_unblock_settings_url();
	$snippet      = torobpay_http_block_hosts_snippet( $host );
	$define_line  = "define('WP_ACCESSIBLE_HOSTS', '" . $snippet . "');";
	$append_hint  = ( 'cpg.torobpay.com,*.torobpay.com' === $snippet )
		? __( 'If <code>WP_ACCESSIBLE_HOSTS</code> is already defined, append the TorobPay domains to it.', 'torobpay-for-woocommerce' )
		: __( 'If <code>WP_ACCESSIBLE_HOSTS</code> is already defined, append this host to it.', 'torobpay-for-woocommerce' );
	?>
	<div class="torobpay-promo-notice torobpay-promo-notice--error">
		<span class="dashicons dashicons-warning torobpay-promo-notice-icon"></span>
		<div class="torobpay-promo-notice-body">
			<strong><?php echo esc_html__( 'TorobPay — required setting', 'torobpay-for-woocommerce' ); ?></strong>
			<p><?php echo wp_kses_post( __( 'The <code>WP_HTTP_BLOCK_EXTERNAL</code> setting is enabled on your WordPress site and blocks API requests to TorobPay.', 'torobpay-for-woocommerce' ) ); ?></p>
			<p><?php echo wp_kses_post( __( 'To fix this, add the following code to your <code>wp-config.php</code> file:', 'torobpay-for-woocommerce' ) ); ?></p>
			<pre class="torobpay-promo-code-block"><?php echo esc_html( $define_line ); ?></pre>
			<p class="description">
				<span class="dashicons dashicons-lightbulb"></span>
				<?php echo wp_kses_post( $append_hint ); ?>
			</p>
			<p class="description">
				<span class="dashicons dashicons-lightbulb"></span>
				<?php echo esc_html__( 'Quick fix (beta): under Advanced settings, enable “Allow TorobPay API calls when WordPress or another plugin blocks them” and save.', 'torobpay-for-woocommerce' ); ?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php echo esc_html__( 'Open Advanced settings', 'torobpay-for-woocommerce' ); ?></a>
			</p>
		</div>
		<a href="<?php echo esc_url( $dismiss_url ); ?>" class="torobpay-notice-dismiss" title="<?php echo esc_attr__( 'Dismiss', 'torobpay-for-woocommerce' ); ?>">
			<span class="dashicons dashicons-no"></span>
		</a>
	</div>
	<?php
}

add_action( 'admin_post_torobpay_dismiss_http_block_notice', 'torobpay_handle_dismiss_http_block_notice' );
function torobpay_handle_dismiss_http_block_notice() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'Unauthorized', 'torobpay-for-woocommerce' ) );
	}

	check_admin_referer( 'torobpay_dismiss_http_block_notice' );

	update_option( 'torobpay_http_block_notice_dismissed', true );

	$redirect = wp_get_referer();
	if ( ! $redirect ) {
		$redirect = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_torobpay' );
	}

	wp_safe_redirect( $redirect );
	exit;
}

/**
 * Build the shared data object localized to the admin order-actions script.
 *
 * Used on both the WooCommerce order edit page and the TorobPay orders table so
 * the nonce set, modal icon, and action metadata stay in sync from one source.
 *
 * @return array
 */
function torobpay_get_admin_order_actions_localize_data() {
	return array(
		'refundNonce'    => wp_create_nonce( 'torobpay-refund-order' ),
		'probeNonce'     => wp_create_nonce( 'torobpay-probe-payment' ),
		'editSyncNonce'  => wp_create_nonce( 'torobpay-edit-sync-items' ),
		'preflightNonce' => wp_create_nonce( 'torobpay-action-preflight' ),
		'failNonce'      => wp_create_nonce( 'torobpay-fail-order' ),
		'dismissNonce'   => wp_create_nonce( 'torobpay-dismiss-action' ),
		'modalIconUrl'   => plugins_url( 'assets/images/icon.png', TOROBPAY_PLUGIN_FILE ),
		'actionMeta'     => function_exists( 'torobpay_get_action_meta' ) ? torobpay_get_action_meta() : array(),
	);
}

/**
 * Build compact action icon markup for a TorobPay order (order edit page).
 *
 * @param WC_Order $order Order object.
 * @return string Button HTML or empty string.
 */
function torobpay_build_order_actions_buttons_html( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return '';
	}

	if ( torobpay_should_bypass_order( $order, 'torobpay_build_order_actions_buttons_html' ) ) {
		return '';
	}

	$order_payment_method = $order->get_payment_method();
	if ( ! torobpay_is_torobpay_payment_method( $order_payment_method ) ) {
		return '';
	}

	$order_id            = $order->get_id();
	$_trb_is_paid        = torobpay_is_order_paid_for_actions( $order );
	$order_payment_token = torobpay_get_order_payment_token( $order );
	$buttons             = '';

	if ( $_trb_is_paid ) {
		$buttons .= sprintf(
			'<button type="button" class="trb-action-btn trb-btn-danger refund-order" data-order-id="%d" data-tooltip="%s">'
			. '<span class="dashicons dashicons-undo"></span>'
			. '</button>',
			$order_id,
			esc_attr__( 'Refund order', 'torobpay-for-woocommerce' )
		);
	}

	$edit_sync_token = $order_payment_token;
	$is_interrupted  = $order->get_meta( '_torobpay_editing_items' ) === 'yes' && $order->get_status() === 'on-hold';

	if ( $edit_sync_token && $is_interrupted ) {
		$buttons .= sprintf(
			'<button type="button" class="trb-action-btn trb-btn-warning edit-sync-items" data-order-id="%d" data-order-status="on-hold" data-tooltip="%s">'
			. '<span class="dashicons dashicons-update"></span>'
			. '</button>',
			$order_id,
			esc_attr__( 'Resume edit — item editing is incomplete', 'torobpay-for-woocommerce' )
		);
	} elseif ( $edit_sync_token && $_trb_is_paid ) {
		$buttons .= sprintf(
			'<button type="button" class="trb-action-btn trb-btn-primary edit-sync-items" data-order-id="%d" data-order-status="%s" data-tooltip="%s">'
			. '<span class="dashicons dashicons-edit"></span>'
			. '</button>',
			$order_id,
			esc_attr( $order->get_status() ),
			esc_attr__( 'Edit items', 'torobpay-for-woocommerce' )
		);
	}

	$needs_action = function_exists( 'torobpay_order_needs_action' ) && torobpay_order_needs_action( $order );

	if ( $needs_action ) {
		$action_code  = (string) $order->get_meta( '_torobpay_action_code' );
		$action_meta  = torobpay_get_action_meta( $action_code !== '' ? $action_code : '_unknown_' );
		$is_dismissed = function_exists( 'torobpay_order_action_dismissed' ) && torobpay_order_action_dismissed( $order );
		$buttons     .= sprintf(
			'<button type="button" class="trb-action-btn trb-btn-attention torobpay-action-alert severity-%s%s" data-order-id="%d" data-action-code="%s" data-action-reason="%s" data-tooltip="%s">'
			. '<span class="dashicons dashicons-warning"></span>'
			. '</button>',
			esc_attr( $action_meta['severity'] ),
			$is_dismissed ? ' is-dismissed' : '',
			$order_id,
			esc_attr( $action_code ),
			esc_attr( (string) $order->get_meta( '_torobpay_action_reason' ) ),
			esc_attr( $action_meta['label'] )
		);
	}

	if ( function_exists( 'torobpay_merchant_has_instore_payments' )
		&& torobpay_merchant_has_instore_payments()
		&& function_exists( 'torobpay_instore_order_allows_payment_qr' )
		&& torobpay_instore_order_allows_payment_qr( $order ) ) {
		$buttons .= sprintf(
			'<button type="button" class="trb-action-btn trb-btn-primary torobpay-show-instore-qr" data-order-id="%d" data-tooltip="%s">'
			. '<span class="dashicons dashicons-smartphone"></span>'
			. '</button>',
			$order_id,
			esc_attr__( 'Show payment QR', 'torobpay-for-woocommerce' )
		);
	}

	return $buttons;
}

/**
 * Render the TorobPay actions block for the order edit page (below status).
 *
 * @param WC_Order $order Order object.
 * @return string Block HTML or empty string.
 */
function torobpay_render_order_actions_group( $order ) {
	$buttons = torobpay_build_order_actions_buttons_html( $order );
	if ( $buttons === '' ) {
		return '';
	}

	return '<div class="torobpay-order-actions-block">'
		. '<h3>' . esc_html__( 'TorobPay actions:', 'torobpay-for-woocommerce' ) . '</h3>'
		. '<div class="trb-actions trb-order-actions">' . $buttons . '</div>'
		. '</div>';
}

/**
 * Enqueue shared toast helper used by order-action scripts.
 */
function torobpay_enqueue_admin_toast_assets() {
	wp_enqueue_script(
		'torobpay-admin-toast',
		plugins_url( 'assets/js/admin-toast.js', TOROBPAY_PLUGIN_FILE ),
		array( 'jquery', 'wp-i18n' ),
		defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0',
		true
	);
	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'torobpay-admin-toast', 'torobpay-for-woocommerce', dirname( TOROBPAY_PLUGIN_FILE ) . '/languages' );
	}
}

/**
 * BCP 47 locale for admin UI number formatting.
 *
 * @return string
 */
function torobpay_admin_ui_locale() {
	$locale = function_exists( 'determine_locale' ) ? determine_locale() : ( function_exists( 'get_locale' ) ? get_locale() : 'en_US' );
	return str_replace( '_', '-', (string) $locale );
}

/**
 * Shared admin UI helpers (number format, modal a11y, product combobox).
 *
 * @return void
 */
function torobpay_enqueue_admin_ui_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	wp_enqueue_script(
		'torobpay-admin-ui',
		plugins_url( 'assets/js/admin-ui.js', TOROBPAY_PLUGIN_FILE ),
		array( 'jquery', 'wp-i18n' ),
		defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0',
		true
	);
	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'torobpay-admin-ui', 'torobpay-for-woocommerce', dirname( TOROBPAY_PLUGIN_FILE ) . '/languages' );
	}
	wp_localize_script(
		'torobpay-admin-ui',
		'torobpayAdminUi',
		array(
			'locale' => torobpay_admin_ui_locale(),
		)
	);
}

/**
 * Enqueue JS for TorobPay order-page actions (refund, edit-sync, action modal).
 */
function torobpay_enqueue_admin_order_actions_assets() {
	torobpay_enqueue_admin_toast_assets();
	torobpay_enqueue_admin_ui_assets();
	wp_enqueue_script(
		'torobpay-admin-order-actions',
		plugins_url( 'assets/js/admin-order-actions.js', TOROBPAY_PLUGIN_FILE ),
		array( 'jquery', 'wp-i18n', 'torobpay-admin-toast', 'torobpay-admin-ui' ),
		defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0',
		true
	);
	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'torobpay-admin-order-actions', 'torobpay-for-woocommerce', dirname( TOROBPAY_PLUGIN_FILE ) . '/languages' );
	}
	wp_localize_script(
		'torobpay-admin-order-actions',
		'torobpayAdmin',
		torobpay_get_admin_order_actions_localize_data()
	);
	if ( function_exists( 'torobpay_enqueue_admin_instore_assets' ) ) {
		torobpay_enqueue_admin_instore_assets();
	}
}

/**
 * Enqueue in-store order modal + QR library on TorobPay order screens.
 *
 * @return void
 */
function torobpay_enqueue_admin_instore_assets() {
	if ( ! function_exists( 'torobpay_merchant_has_instore_payments' ) || ! torobpay_merchant_has_instore_payments() ) {
		return;
	}

	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';

	torobpay_enqueue_admin_ui_assets();
	wp_enqueue_script(
		'torobpay-qrcode',
		plugins_url( 'assets/vendor/qrcodejs/qrcode.js', TOROBPAY_PLUGIN_FILE ),
		array(),
		'1.0.0',
		true
	);
	wp_enqueue_script(
		'torobpay-admin-instore',
		plugins_url( 'assets/js/admin-instore.js', TOROBPAY_PLUGIN_FILE ),
		array( 'jquery', 'wp-i18n', 'torobpay-admin-toast', 'torobpay-qrcode', 'torobpay-admin-ui' ),
		$version,
		true
	);
	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'torobpay-admin-instore', 'torobpay-for-woocommerce', dirname( TOROBPAY_PLUGIN_FILE ) . '/languages' );
	}

	$tax_enabled = function_exists( 'wc_tax_enabled' ) && wc_tax_enabled();
	wp_localize_script(
		'torobpay-admin-instore',
		'torobpayInstore',
		array(
			'nonce'      => wp_create_nonce( 'torobpay-instore-order' ),
			'iconUrl'    => plugins_url( 'assets/images/icon.png', TOROBPAY_PLUGIN_FILE ),
			'taxEnabled' => $tax_enabled,
		)
	);
}

add_action( 'wp_ajax_torobpay_refund_order', 'torobpay_ajax_refund_order' );
function torobpay_ajax_refund_order() {
	check_ajax_referer( 'torobpay-refund-order', 'security' );

	if ( ! current_user_can( 'edit_shop_orders' ) || empty( $_POST['order_id'] ) ) {
		wp_send_json_error( array( 'message' => __( 'An error occurred.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order = wc_get_order( (int) $_POST['order_id'] );
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order_payment_method = $order->get_payment_method();
	if ( ! torobpay_is_torobpay_payment_method( $order_payment_method ) ) {
		wp_send_json_error( array( 'message' => __( 'This order does not belong to TorobPay.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		wp_send_json_error( array( 'message' => __( 'This order is a marketplace vendor sub-order and has no independent payment token.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	// Confirm remote cancel before creating a local refund.
	$from_status = $order->get_status();
	$cancel_ok   = torobpay_cancel_order( $order->get_id(), $from_status );

	$fresh_for_guard = torobpay_get_fresh_order( $order->get_id() ) ?: $order;
	$confirmed       = torobpay_has_confirmed_cancellation(
		$fresh_for_guard,
		torobpay_get_order_payment_token( $fresh_for_guard )
	);

	if ( ! $cancel_ok || ! $confirmed ) {
		wp_send_json_error( array( 'message' => __( 'Refund was not confirmed in TorobPay; local refund was not recorded to avoid a financial mismatch. Please check the payment status in the TorobPay panel.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order = $fresh_for_guard;

	torobpay_clear_order_cache( $order );

	$line_items = array();
	foreach ( $order->get_items() as $item_id => $item ) {
		$line_items[ $item_id ] = array(
			'qty'          => $item->get_quantity(),
			'refund_total' => $item->get_total(),
			'refund_tax'   => $item->get_total_tax(),
		);
	}

	$refund = wc_create_refund(
		array(
			'order_id'      => $order->get_id(),
			'amount'        => $order->get_total(),
			'reason'        => __( 'TorobPay refund', 'torobpay-for-woocommerce' ),
			'line_items'    => $line_items,
			'restock_items' => true,
		)
	);

	if ( is_wp_error( $refund ) ) {
		wp_send_json_error( array( 'message' => __( 'Error creating refund: ', 'torobpay-for-woocommerce' ) . $refund->get_error_message() ) );
		return;
	}

	torobpay_mark_refund_torobpay_confirmed( $refund );

	$order->update_meta_data( '_torobpay_stock_restored', 'yes' );
	$order->save();

	$fresh_order = torobpay_get_fresh_order( $order->get_id() );
	if ( $fresh_order ) {
		torobpay_cache_payment_status( $fresh_order, 'REVERT' );
		if ( function_exists( 'torobpay_clear_action_required' ) ) {
			torobpay_clear_action_required( $fresh_order );
		}
	}

	wp_send_json_success( array( 'message' => __( 'Order refunded successfully.', 'torobpay-for-woocommerce' ) ) );
}

/**
 * AJAX: Action Needed modal payment probe (VERIFY→settle may auto-heal; else suggest).
 *
 * @return void
 */
add_action( 'wp_ajax_torobpay_probe_payment', 'torobpay_ajax_probe_payment' );
function torobpay_ajax_probe_payment() {
	check_ajax_referer( 'torobpay-probe-payment', 'security' );

	if ( ! current_user_can( 'edit_shop_orders' ) || empty( $_POST['order_id'] ) ) {
		wp_send_json_error( array( 'message' => __( 'An error occurred.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order_id = (int) $_POST['order_id'];
	$order    = wc_get_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( ! torobpay_is_torobpay_payment_method( $order->get_payment_method() ) ) {
		wp_send_json_error( array( 'message' => __( 'This order does not belong to TorobPay.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		wp_send_json_error( array( 'message' => __( 'This order is a marketplace vendor sub-order and has no independent payment token.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$was_flagged = torobpay_order_needs_action( $order );
	$probe_status = function_exists( 'torobpay_get_cached_payment_status' ) ? torobpay_get_cached_payment_status( $order ) : '';

	$probe_cooldown = 60;
	$last_probe     = (int) $order->get_meta( '_torobpay_last_modal_probe' );
	$on_cooldown    = $last_probe > 0 && ( time() - $last_probe ) < $probe_cooldown;

	if ( ! $on_cooldown && torobpay_get_order_payment_token( $order ) ) {
		$order->update_meta_data( '_torobpay_last_modal_probe', time() );
		$order->save_meta_data();
		$probe_status = torobpay_reconcile_order( $order, 'modal_probe' );
		$order = torobpay_get_fresh_order( $order_id ) ?: wc_get_order( $order_id );
		if ( $order && '' === $probe_status && function_exists( 'torobpay_get_cached_payment_status' ) ) {
			$probe_status = torobpay_get_cached_payment_status( $order );
		}
	}

	if ( $order && torobpay_remote_status_is_paid( $probe_status ) ) {
		if ( torobpay_flag_remote_wc_status_downgrade_divergence( $order, $probe_status ) ) {
			$order = torobpay_get_fresh_order( $order_id ) ?: $order;
		} elseif ( torobpay_flag_local_refund_divergence( $order, $probe_status ) ) {
			$order = torobpay_get_fresh_order( $order_id ) ?: $order;
		} elseif ( ! torobpay_order_wc_is_paid_for_reconcile( $order )
			&& ! in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) {
			if ( 'VERIFY' === strtoupper( (string) $probe_status ) ) {
				torobpay_set_action_required( $order, 'settle_pending' );
			} else {
				torobpay_set_action_required( $order, torobpay_get_settle_divergence_code( $order ) );
			}
			$order = torobpay_get_fresh_order( $order_id ) ?: $order;
		}
	}

	$needs_action = $order && torobpay_order_needs_action( $order );
	$resolved     = $was_flagged && ! $needs_action;

	if ( ! $needs_action ) {
		wp_send_json_success(
			array(
				'needs_action' => false,
				'resolved'     => $resolved,
				'reload'       => $resolved,
				'message'      => $resolved
					? __( 'Payment status was synced with TorobPay and no further action is needed.', 'torobpay-for-woocommerce' )
					: __( 'No mismatch was found between WooCommerce and TorobPay status.', 'torobpay-for-woocommerce' ),
			)
		);
		return;
	}

	$code = (string) $order->get_meta( '_torobpay_action_code' );
	$reason = (string) $order->get_meta( '_torobpay_action_reason' );

	if ( 'REVERT' === $probe_status ) {
		// The live probe already confirmed REVERT, so do not offer another
		// auto-heal here. The merchant can only mark the order failed or dismiss.
		$code   = 'revert_divergence';
		$reason = '';
	}

	$meta = torobpay_get_action_meta( $code !== '' ? $code : '_unknown_' );

	wp_send_json_success(
		array(
			'needs_action' => true,
			'resolved'     => false,
			'reload'       => false,
			'remote_status' => $probe_status,
			'code'         => $code,
			'reason'       => $reason,
			'label'        => $meta['label'],
			'description'  => $meta['description'],
			'severity'     => $meta['severity'],
			'actions'      => array_values( $meta['actions'] ),
		)
	);
}

/**
 * AJAX handler: mark a flagged TorobPay order as failed.
 *
 * Used by the "Action Needed" modal when TorobPay reports the payment was
 * reverted/cancelled (REVERT) and the merchant confirms no money was captured.
 * Sets the order to "failed" and clears the action-required projection.
 *
 * @return void
 */
add_action( 'wp_ajax_torobpay_fail_order', 'torobpay_ajax_fail_order' );
function torobpay_ajax_fail_order() {
	check_ajax_referer( 'torobpay-fail-order', 'security' );

	if ( ! current_user_can( 'edit_shop_orders' ) || empty( $_POST['order_id'] ) ) {
		wp_send_json_error( array( 'message' => __( 'An error occurred.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order = wc_get_order( (int) $_POST['order_id'] );
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'torobpay-for-woocommerce' ) ) );
		return;
	}
	if ( ! torobpay_is_torobpay_payment_method( $order->get_payment_method() ) ) {
		wp_send_json_error( array( 'message' => __( 'This order does not belong to TorobPay.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		wp_send_json_error( array( 'message' => __( 'This order is a marketplace vendor sub-order and has no independent payment token.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( ! $order->has_status( 'failed' ) ) {
		$order->update_status(
			'failed',
			__( 'TorobPay: With admin confirmation, and because payment was cancelled/incomplete in TorobPay, the order was marked as "Failed".', 'torobpay-for-woocommerce' )
		);
	}

	// Re-read fresh and clear the flag last so the passive status hook can't
	// re-flag the order during the update above.
	$fresh = function_exists( 'torobpay_get_fresh_order' ) ? ( torobpay_get_fresh_order( $order->get_id() ) ?: $order ) : $order;
	if ( function_exists( 'torobpay_clear_action_required' ) ) {
		torobpay_clear_action_required( $fresh );
	}

	wp_send_json_success( array( 'message' => __( 'The order was marked as "Failed" and the warning was cleared.', 'torobpay-for-woocommerce' ) ) );
}

/**
 * AJAX: dismiss Action Needed alert (keeps list projection; hides notices until resolved/changed).
 *
 * @return void
 */
add_action( 'wp_ajax_torobpay_dismiss_action', 'torobpay_ajax_dismiss_action' );
function torobpay_ajax_dismiss_action() {
	check_ajax_referer( 'torobpay-dismiss-action', 'security' );

	if ( ! current_user_can( 'edit_shop_orders' ) || empty( $_POST['order_id'] ) ) {
		wp_send_json_error( array( 'message' => __( 'An error occurred.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order = wc_get_order( (int) $_POST['order_id'] );
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( ! torobpay_is_torobpay_payment_method( $order->get_payment_method() ) ) {
		wp_send_json_error( array( 'message' => __( 'This order does not belong to TorobPay.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		wp_send_json_error( array( 'message' => __( 'This order is a marketplace vendor sub-order and has no independent payment token.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order->add_order_note( '<span class="torobpay-notice">' . esc_html__( 'TorobPay: The "Action required" warning was reviewed and dismissed by an admin; the order remains listed until fully resolved.', 'torobpay-for-woocommerce' ) . '</span>' );
	if ( function_exists( 'torobpay_dismiss_action_required' ) ) {
		torobpay_dismiss_action_required( $order );
	}

	wp_send_json_success( array( 'message' => __( 'Warning removed from notices; the order remains in the list until fully resolved.', 'torobpay-for-woocommerce' ) ) );
}

add_action( 'admin_menu', 'torobpay_admin_menu' );
function torobpay_admin_menu() {
	$torobpay_plugin_path = plugin_dir_path( TOROBPAY_PLUGIN_FILE );

	$campaign_page_file        = $torobpay_plugin_path . 'campaign-page.php';
	$support_page_file         = $torobpay_plugin_path . 'support-page.php';
	$widget_settings_page_file = $torobpay_plugin_path . 'includes/widget-settings-page.php';

	if ( file_exists( $campaign_page_file ) ) {
		require_once $campaign_page_file;
	}

	if ( file_exists( $support_page_file ) ) {
		require_once $support_page_file;
	}

	if ( file_exists( $widget_settings_page_file ) ) {
		require_once $widget_settings_page_file;
	}

	$icon_url = plugin_dir_url( TOROBPAY_PLUGIN_FILE ) . 'assets/images/icon.png';

	$orders_page = add_menu_page(
		__( 'TorobPay', 'torobpay-for-woocommerce' ),                                        // Page title
		__( 'TorobPay', 'torobpay-for-woocommerce' ),                                        // Menu title
		'manage_woocommerce',                           // Capability
		'torobpay-orders',                              // Menu slug - changed to orders page
		'torobpay_orders_page_wrapper',                 // Function - use wrapper function
		$icon_url,                                      // PNG icon URL for proper colors
		26                                              // Position after Comments (25)
	);

	add_action( "load-$orders_page", 'torobpay_orders_screen_options' );

	add_submenu_page(
		'torobpay-orders',                              // Parent slug
		__( 'Orders', 'torobpay-for-woocommerce' ),                                     // Page title
		__( 'Orders', 'torobpay-for-woocommerce' ),                                     // Menu title
		'manage_woocommerce',                           // Capability
		'torobpay-orders',                              // Menu slug
		'torobpay_orders_page_wrapper'                  // Function
	);

	add_submenu_page(
		'torobpay-orders',                              // Parent slug
		__( 'Join campaign', 'torobpay-for-woocommerce' ),                               // Page title
		__( 'Join campaign', 'torobpay-for-woocommerce' ),                               // Menu title
		'manage_woocommerce',                           // Capability
		'torobpay-campaign',                            // Menu slug
		'torobpay_campaign_page'                        // Function
	);

	add_submenu_page(
		'torobpay-orders',                              // Parent slug
		__( 'Support', 'torobpay-for-woocommerce' ),                                    // Page title
		__( 'Support', 'torobpay-for-woocommerce' ),                                    // Menu title
		'manage_woocommerce',                           // Capability
		'torobpay-support',                             // Menu slug
		'torobpay_support_page'                         // Function
	);

	add_submenu_page(
		'torobpay-orders',                              // Parent slug
		__( 'TorobPay visual settings', 'torobpay-for-woocommerce' ),                        // Page title
		__( 'TorobPay visual settings', 'torobpay-for-woocommerce' ),                        // Menu title
		'manage_woocommerce',                           // Capability
		'torobpay-widget',                              // Menu slug
		'torobpay_widget_settings_page'                 // Function
	);

	add_submenu_page(
		'torobpay-orders',                              // Parent slug
		__( 'Gateway settings', 'torobpay-for-woocommerce' ),                               // Page title
		__( 'Gateway settings', 'torobpay-for-woocommerce' ),                               // Menu title
		'manage_woocommerce',                           // Capability
		'torobpay-settings',                            // Menu slug
		'torobpay_gateway_settings_page'                // Function
	);
}

add_action( 'admin_bar_menu', 'torobpay_add_admin_bar_notification_node', 100 );
function torobpay_add_admin_bar_notification_node( $wp_admin_bar ) {
	if ( ! is_admin() || ! is_admin_bar_showing() || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	if ( ! function_exists( 'torobpay_get_notification_badge_count' ) ) {
		return;
	}

	$badge_count = torobpay_get_notification_badge_count();
	$tier        = torobpay_get_notification_tier();

	$badge_html = '<span class="torobpay-notif-badge"'
		. ( $badge_count > 0 ? '' : ' style="display:none;"' )
		. '>'
		. ( $badge_count > 0 ? intval( $badge_count ) : '' )
		. '</span>';

	// CSS background on .ab-icon (icon.png) — img/svg inside the admin bar
	// is stripped or hidden by kses / admin-bar CSS.
	$bell_icon = '<span class="ab-icon torobpay-notif-logo-wrap" aria-hidden="true"></span>';

	$title_html   = wp_kses(
		$bell_icon . '<span class="torobpay-notif-label">' . esc_html__( 'TorobPay notifications', 'torobpay-for-woocommerce' ) . '</span>' . $badge_html,
		torobpay_kses_allowed_html()
	);
	$parent_class = 'torobpay-notifications-bar-node';
	if ( 'critical' === $tier ) {
		$parent_class .= ' has-attention-critical';
	} elseif ( 'notice' === $tier ) {
		$parent_class .= ' has-attention-notice';
	}

	$wp_admin_bar->add_node(
		array(
			'id'    => 'torobpay-notifications',
			'title' => $title_html,
			'href'  => '#',
			'meta'  => array(
				'class' => $parent_class,
				'title' => __( 'TorobPay notifications', 'torobpay-for-woocommerce' ),
			),
		)
	);

	// Each opens the notification modal pre-filtered to that category
	// (handled in admin-notification-center.js by parsing the node id).
	if ( function_exists( 'torobpay_get_notification_category_map' )
		&& function_exists( 'torobpay_get_notification_category_stats' ) ) {

		$category_map   = torobpay_get_notification_category_map();
		$category_stats = torobpay_get_notification_category_stats();

		foreach ( $category_map as $cat_id => $cat ) {
			$count    = isset( $category_stats[ $cat_id ] ) ? (int) $category_stats[ $cat_id ]['count'] : 0;
			$cat_tier = isset( $category_stats[ $cat_id ] ) ? $category_stats[ $cat_id ]['tier'] : '';

			if ( $count < 1 ) {
				continue;
			}

			$count_html = '<span class="torobpay-notif-cat-count">' . intval( $count ) . '</span>';

			$item_class = 'torobpay-notif-cat-item';
			if ( 'critical' === $cat_tier ) {
				$item_class .= ' torobpay-notif-cat-item--critical';
			} elseif ( 'notice' === $cat_tier ) {
				$item_class .= ' torobpay-notif-cat-item--notice';
			}

			$item_title = '<span class="dashicons dashicons-' . esc_attr( $cat['icon'] ) . '" aria-hidden="true"></span> '
				. '<span class="torobpay-notif-cat-label">' . esc_html( $cat['label'] ) . '</span>'
				. $count_html;

			$wp_admin_bar->add_node(
				array(
					'parent' => 'torobpay-notifications',
					'id'     => 'torobpay-notif-cat-' . $cat_id,
					'title'  => $item_title,
					'href'   => '#',
					'meta'   => array(
						'class' => $item_class,
					),
				)
			);
		}
	}

	$wp_admin_bar->add_node(
		array(
			'parent' => 'torobpay-notifications',
			'id'     => 'torobpay-notif-cat-all',
			'title'  => '<span class="dashicons dashicons-bell" aria-hidden="true"></span> '
				. '<span class="torobpay-notif-cat-label">' . esc_html__( 'All notifications', 'torobpay-for-woocommerce' ) . '</span>',
			'href'   => '#',
			'meta'   => array(
				'class' => 'torobpay-notif-cat-item torobpay-notif-cat-item--all',
			),
		)
	);
}

add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'torobpay_filter_cpt_order_channel_query', 10, 2 );

/**
 * Register screen options for the orders page
 */
function torobpay_orders_screen_options() {
	global $torobpay_orders_screen;

	$screen     = get_current_screen();
	$screen->id = 'torobpay_orders'; // Set a unique screen ID for saving column preferences

	$option = 'per_page';
	$args   = array(
		'label'   => __( 'Orders', 'torobpay-for-woocommerce' ),
		'default' => 20,
		'option'  => 'torobpay_orders_per_page',
	);
	add_screen_option( $option, $args );

	require_once plugin_dir_path( TOROBPAY_PLUGIN_FILE ) . 'class-torobpay-orders-table.php';

	$torobpay_orders_screen = new TorobPay_Orders_Table();

	$torobpay_orders_screen->get_columns();
}

/**
 * Wrapper function for the orders page to ensure screen options work
 */
function torobpay_orders_page_wrapper() {
	torobpay_orders_page();
}

function torobpay_orders_page() {
	// Enqueue edit-sync script and modal so the button in the actions column works.
	torobpay_enqueue_admin_order_actions_assets();

	require_once plugin_dir_path( TOROBPAY_PLUGIN_FILE ) . 'class-torobpay-orders-table.php';

	if ( isset( $_GET['refunds_processed'] ) && 'true' === $_GET['refunds_processed'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flags from redirect.
		$refunded_count = isset( $_GET['refunded'] ) ? absint( $_GET['refunded'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flags from redirect.
		$failed_orders  = isset( $_GET['failed_orders'] ) ? sanitize_text_field( wp_unslash( $_GET['failed_orders'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flags from redirect.

		if ( $refunded_count > 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html(
				sprintf(
					/* translators: %d: number of refunded orders */
					__( '✓ %d order(s) refunded successfully.', 'torobpay-for-woocommerce' ),
					(int) $refunded_count
				)
			);
			echo '</p></div>';
		}

		if ( ! empty( $failed_orders ) ) {
			$failed_order_ids = explode( ',', $failed_orders );
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo '<span class="dashicons dashicons-warning"></span> ' . esc_html__( 'Error refunding orders: #', 'torobpay-for-woocommerce' ) . esc_html( implode( ', #', $failed_order_ids ) );
			echo '</p></div>';
		}

		if ( $refunded_count == 0 && empty( $failed_orders ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'No refundable orders were found.', 'torobpay-for-woocommerce' ) . '</p></div>';
		}
	}
	if ( isset( $_GET['verification_refreshed'] ) && 'true' === $_GET['verification_refreshed'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag from redirect.
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Payment status was successfully queried from TorobPay.', 'torobpay-for-woocommerce' ) . '</p></div>';
	}

	$orders_table = new TorobPay_Orders_Table();
	$orders_table->prepare_items();

	echo '<div class="wrap">';
	echo '<h1 class="wp-heading-inline">' . esc_html__( 'TorobPay orders', 'torobpay-for-woocommerce' ) . '</h1>';
	if ( function_exists( 'torobpay_merchant_has_instore_payments' ) && torobpay_merchant_has_instore_payments() ) {
		echo '<a href="#" class="page-title-action" id="torobpay-add-instore-order">' . esc_html__( 'Add in-store order', 'torobpay-for-woocommerce' ) . '</a>';
	}
	echo '<hr class="wp-header-end" />';

	echo '<form method="get">';
	echo '<input type="hidden" name="page" value="torobpay-orders" />';
	$orders_table->display_filter_box();
	echo '</form>';

	echo '<form method="post">';
	wp_nonce_field( 'bulk-' . $orders_table->_args['plural'] );
	echo '<input type="hidden" name="page" value="torobpay-orders" />';
	$orders_table->display();
	echo '</form>';

	echo '</div>';
}

add_filter( 'set-screen-option', 'torobpay_set_screen_option', 10, 3 );
function torobpay_set_screen_option( $status, $option, $value ) {
	if ( 'torobpay_orders_per_page' === $option ) {
		return $value;
	}
	return $status;
}

/**
 * WooCommerce Blocks Integration for TorobPay
 *
 * The following hooks handle integration with WooCommerce Blocks system.
 */

/**
 * Declare compatibility with WooCommerce cart and checkout blocks
 *
 * This lets WooCommerce know that TorobPay payment method is compatible with the blocks-based checkout.
 */
add_action( 'before_woocommerce_init', 'torobpay_declare_blocks_compatibility' );
function torobpay_declare_blocks_compatibility() {
	if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		return;
	}

	// @phan-suppress-next-line PhanUndeclaredClassMethod
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
		'cart_checkout_blocks',
		TOROBPAY_PLUGIN_FILE,
		true
	);

	// @phan-suppress-next-line PhanUndeclaredClassMethod
	// Specifically declare compatibility with checkout blocks payment methods
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
		'checkout_blocks_payment_method_integration',
		TOROBPAY_PLUGIN_FILE,
		true
	);

	// @phan-suppress-next-line PhanUndeclaredClassMethod
	// Declare compatibility with new React-based payment settings (data-view)
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
		'reactify_classic_payments_settings',
		TOROBPAY_PLUGIN_FILE,
		true
	);

	// @phan-suppress-next-line PhanUndeclaredClassMethod
	// Declare compatibility with custom order tables (HPOS)
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
		'custom_order_tables',
		TOROBPAY_PLUGIN_FILE,
		true
	);
}

/**
 * Force WooCommerce to recognize TorobPay as blocks compatible
 *
 * This filter ensures that TorobPay payment method is marked as compatible with block checkout
 * even if WooCommerce's auto-detection doesn't work correctly.
 *
 * @param bool   $is_supported  Whether the payment method supports the feature.
 * @param string $feature       The feature being checked.
 * @param string $payment_method The payment method ID.
 * @return bool
 */
add_filter( 'woocommerce_payment_method_supports', 'torobpay_payment_method_supports_blocks', 10, 3 );
function torobpay_payment_method_supports_blocks( $is_supported, $feature, $payment_method ) {
	if ( $payment_method === 'WC_Gateway_TorobPay' && $feature === 'block_checkout' ) {
		return true;
	}

	return $is_supported;
}

/**
 * Register TorobPay payment method for WooCommerce Checkout Block
 *
 * This hook loads TorobPay blocks support class and registers it with WooCommerce.
 */
add_action( 'woocommerce_blocks_loaded', 'torobpay_register_blocks_support' );
function torobpay_register_blocks_support() {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	require_once dirname( TOROBPAY_PLUGIN_FILE ) . '/class-torobpay-blocks-support.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( $registry ) {
			$registry->register( new TorobPay_Blocks_Support() );
		}
	);
}

function torobpay_gateway_settings_page() {
	wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=WC_Gateway_TorobPay' ) );
	exit;
}

// Check for gateway conflicts in admin area
add_action( 'admin_init', 'torobpay_check_gateway_conflicts' );
function torobpay_check_gateway_conflicts() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$torobpay_settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings', array() );
	$torobpay_enabled  = isset( $torobpay_settings['enabled'] ) && $torobpay_settings['enabled'] === 'yes';
	$torobpay_default  = isset( $torobpay_settings['default_gateway'] ) && $torobpay_settings['default_gateway'] === 'yes';

	$has_conflict = false;

	// Check for SnappPay conflict
	if ( class_exists( 'WC_Gateway_SnappPay' ) ) {
		$snapppay_settings = get_option( 'woocommerce_WC_Gateway_SnappPay_settings', array() );
		$snapppay_enabled  = isset( $snapppay_settings['enabled'] ) && $snapppay_settings['enabled'] === 'yes';
		$snapppay_default  = isset( $snapppay_settings['default_gateway'] ) && $snapppay_settings['default_gateway'] === 'yes';

		// Conflict exists if both gateways are enabled and both set as default
		if ( $torobpay_enabled && $torobpay_default && $snapppay_enabled && $snapppay_default ) {
			$has_conflict = true;
		}
	}

	// Update or clear the conflict flag
	if ( $has_conflict ) {
		update_option( 'torobpay_snapppay_default_conflict', true );
	} else {
		delete_option( 'torobpay_snapppay_default_conflict' );
	}
}

// Add admin notice for conflicting default gateway settings
add_action( 'admin_notices', 'torobpay_show_default_gateway_conflict_notice' );
function torobpay_show_default_gateway_conflict_notice() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	// Guideline 11: TorobPay + WooCommerce + Plugins screens only.
	// Self-clears when the conflict option is removed (see torobpay_check_default_gateway_conflict).
	if ( ! torobpay_should_render_scoped_admin_notice( true ) ) {
		return;
	}

	if ( ! get_option( 'torobpay_snapppay_default_conflict' ) ) {
		return;
	}

	?>
	<div class="torobpay-promo-notice torobpay-promo-notice--warning">
		<span class="dashicons dashicons-warning torobpay-promo-notice-icon"></span>
		<div class="torobpay-promo-notice-body">
			<strong><?php echo esc_html__( 'Payment gateway settings conflict', 'torobpay-for-woocommerce' ); ?></strong>
			<p><?php echo esc_html__( 'To avoid conflicts between payment plugins, enable only the TorobPay default-gateway option. Select only one gateway as default:', 'torobpay-for-woocommerce' ); ?></p>
			<div class="torobpay-promo-notice-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_torobpay' ) ); ?>" class="torobpay-promo-cta torobpay-promo-cta--ghost">
					<span class="dashicons dashicons-admin-settings"></span>
					<?php echo esc_html__( 'TorobPay settings', 'torobpay-for-woocommerce' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_snapppay' ) ); ?>" class="torobpay-promo-cta torobpay-promo-cta--ghost">
					<span class="dashicons dashicons-admin-settings"></span>
					<?php echo esc_html__( 'SnappPay settings', 'torobpay-for-woocommerce' ); ?>
				</a>
			</div>
		</div>
	</div>
	<?php
}

add_filter( 'woocommerce_available_payment_gateways', 'torobpay_set_as_default_gateway', 15 );

/**
 * Make TorobPay the default gateway by putting it first in the list when enabled as default
 * This is safer than using woocommerce_default_gateway filter
 *
 * @param array $available_gateways Available payment gateways
 * @return array Modified gateways array with TorobPay first if it should be default
 */
function torobpay_set_as_default_gateway( $available_gateways ) {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return $available_gateways;
	}

	// Don't interfere in admin or AJAX contexts
	if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		return $available_gateways;
	}

	$torobpay_settings = get_option( 'woocommerce_WC_Gateway_TorobPay_settings' );
	if ( ! is_array( $torobpay_settings ) ) {
		return $available_gateways;
	}

	$is_enabled = isset( $torobpay_settings['enabled'] ) && $torobpay_settings['enabled'] === 'yes';
	$is_default = isset( $torobpay_settings['default_gateway'] ) && $torobpay_settings['default_gateway'] === 'yes';

	if ( ! $is_enabled || ! $is_default ) {
		return $available_gateways;
	}

	if ( ! isset( $available_gateways['WC_Gateway_TorobPay'] ) ) {
		return $available_gateways;
	}

	$torobpay_gateway = $available_gateways['WC_Gateway_TorobPay'];
	unset( $available_gateways['WC_Gateway_TorobPay'] );
	$available_gateways = array( 'WC_Gateway_TorobPay' => $torobpay_gateway ) + $available_gateways;

	return $available_gateways;
}

/**
 * Add TorobPay status field to WooCommerce order page
 */
add_action( 'woocommerce_admin_order_data_after_shipping_address', 'torobpay_add_order_status_field', 10, 1 );
function torobpay_add_order_status_field( $order ) {
	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		return;
	}

	$order_payment_method = $order->get_payment_method();
	if ( ! torobpay_is_torobpay_payment_method( $order_payment_method ) ) {
		return;
	}

	$payment_token = torobpay_get_order_payment_token( $order );
	$token_history = $order->get_meta( '_torobpay_token_history' );
	$has_any_token = ! empty( $payment_token ) || ( is_array( $token_history ) && count( $token_history ) > 0 );

	// Empty cache = unknown until AJAX refresh; VERIFY→settle heal is via Action Needed probe.
	if ( ! $has_any_token ) {
		$status_text = 'TOKEN_MISSING';
	} else {
		$cached_status = $order->get_meta( '_torobpay_cached_status' );
		$cached_time   = $order->get_meta( '_torobpay_cached_status_time' );

		$use_cache = false;
		if ( $cached_status && $cached_time ) {
			$time_diff = time() - intval( $cached_time );
			if ( $time_diff < 300 ) {
				$use_cache = true;
			}
		}

		if ( $use_cache ) {
			$status_text = $cached_status;
			if ( function_exists( 'torobpay_flag_divergence_from_status' ) ) {
				torobpay_flag_divergence_from_status( $order, $cached_status );
			}
		} else {
			$status_text = __( 'Loading...', 'torobpay-for-woocommerce' );
		}
	}

	$formatted_status = torobpay_format_payment_status( $status_text );

	$hide_refresh = ! $has_any_token;

	$actions_html = torobpay_render_order_actions_group( $order );

	echo '<div class="order_data_column torobpay_order_status">';
	echo '<h3>' . esc_html__( 'Order status in TorobPay:', 'torobpay-for-woocommerce' );
	if ( function_exists( 'torobpay_is_instore_order' ) && torobpay_is_instore_order( $order ) ) {
		echo ' <span class="torobpay-instore-badge">' . esc_html__( 'In-store', 'torobpay-for-woocommerce' ) . '</span>';
	}
	echo '</h3>';
	echo '<div class="address">';
	echo '<div id="torobpay-status-display">';
	echo '<span id="torobpay-status-value" class="' . esc_attr( $formatted_status['class'] ) . '">' . esc_html( $formatted_status['text'] ) . '</span>';
	if ( ! $hide_refresh ) {
		echo '<a href="#" id="torobpay-refresh-status" class="torobpay-refresh-link" title="' . esc_attr__( 'Refresh status', 'torobpay-for-woocommerce' ) . '"><span class="dashicons dashicons-update"></span></a>';
	}
	echo '</div>';
	if ( $actions_html !== '' ) {
		echo wp_kses( $actions_html, torobpay_kses_allowed_html() );
	}
	echo '</div>';
	echo '</div>';

	torobpay_enqueue_admin_order_actions_assets();

	if ( ! $hide_refresh ) {
		$auto_refresh = $use_cache ? '' : "\n\t\ttorobpayRefreshStatus();\n";
		$js           = 'jQuery(document).ready(function($) {' . $auto_refresh . '
		$(\'#torobpay-refresh-status\').on(\'click\', function(e) {
			e.preventDefault();
			torobpayRefreshStatus();
		});

		function torobpayRefreshStatus() {
			var $link = $(\'#torobpay-refresh-status\');
			var $statusValue = $(\'#torobpay-status-value\');
			
			$link.addClass(\'disabled\');
			$statusValue
				.removeClass(\'status-success status-pending status-failed status-unknown\')
				.addClass(\'status-loading\')
				.html(' . wp_json_encode( __( 'Loading...', 'torobpay-for-woocommerce' ) ) . ');

			$.ajax({
				url: ajaxurl,
				type: \'POST\',
				data: {
					action: \'torobpay_get_order_status\',
					order_id: ' . (int) $order->get_id() . ',
					security: ' . wp_json_encode( wp_create_nonce( 'torobpay_order_status_' . $order->get_id() ) ) . '
				},
				success: function(response) {
					if (response.success) {
						$statusValue
							.removeClass(\'status-success status-pending status-failed status-unknown status-loading\')
							.addClass(response.data.status_class)
							.html(response.data.status_text);
					} else {
						$statusValue
							.removeClass(\'status-success status-pending status-loading status-unknown\')
							.addClass(\'status-failed\')
							.html(' . wp_json_encode( __( '✕ Error loading', 'torobpay-for-woocommerce' ) ) . ');
					}
				},
				error: function() {
					$statusValue
						.removeClass(\'status-success status-pending status-loading status-unknown\')
						.addClass(\'status-failed\')
						.html(' . wp_json_encode( __( '✕ Error loading', 'torobpay-for-woocommerce' ) ) . ');
				},
				complete: function() {
					$link.removeClass(\'disabled\');
				}
			});
		}
	});';

		wp_add_inline_script( 'torobpay-admin-order-actions', $js );
	}
}

/**
 * AJAX handler to get TorobPay order status
 */
add_action( 'wp_ajax_torobpay_get_order_status', 'torobpay_ajax_get_order_status' );
function torobpay_ajax_get_order_status() {
	$order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
	if ( ! $order_id || ! check_ajax_referer( 'torobpay_order_status_' . $order_id, 'security', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid request', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Order not found', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order_payment_method = $order->get_payment_method();
	if ( ! torobpay_is_torobpay_payment_method( $order_payment_method ) ) {
		wp_send_json_error( array( 'message' => __( 'This order is not a TorobPay order', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$payment_token = torobpay_get_order_payment_token( $order );
	if ( ! $payment_token ) {
		wp_send_json_error( array( 'message' => __( 'Payment token not found', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$api      = new TorobPayAPI();
	$response = $api->status( $payment_token );

	if ( is_wp_error( $response ) || ! isset( $response['successful'] ) || ! $response['successful'] ) {
		torobpay_debug_log( 'TorobPay: Order status refresh failed for order ' . $order_id . '. Trying token history fallback.' );

		if ( ! torobpay_rescue_payment_status( $order, $order_id, $order->get_status() ) ) {
			$error_message = is_wp_error( $response )
				? $response->get_error_message()
				: ( isset( $response['message'] ) ? $response['message'] : __( 'Unknown error', 'torobpay-for-woocommerce' ) );
			wp_send_json_error( array( 'message' => __( 'API error: ', 'torobpay-for-woocommerce' ) . $error_message ) );
			return;
		}

		$order = torobpay_get_fresh_order( $order_id ) ?: wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'The order could not be loaded after restoring history.', 'torobpay-for-woocommerce' ) ) );
			return;
		}

		$payment_token = torobpay_get_order_payment_token( $order );
		if ( ! $payment_token ) {
			wp_send_json_error( array( 'message' => __( 'Payment token not found', 'torobpay-for-woocommerce' ) ) );
			return;
		}

		$response = $api->status( $payment_token );
		if ( is_wp_error( $response ) || ! isset( $response['successful'] ) || ! $response['successful'] ) {
			$error_message = is_wp_error( $response )
				? $response->get_error_message()
				: ( isset( $response['message'] ) ? $response['message'] : __( 'Unknown error', 'torobpay-for-woocommerce' ) );
			wp_send_json_error( array( 'message' => __( 'API error: ', 'torobpay-for-woocommerce' ) . $error_message ) );
			return;
		}
	}

	$status = isset( $response['response']['status'] ) ? $response['response']['status'] : '';

	$formatted_status = torobpay_format_payment_status( $status );

	$api_txn = isset( $response['response']['transactionId'] ) ? (string) $response['response']['transactionId'] : '';
	torobpay_cache_payment_status( $order, $status, $api_txn );

	if ( function_exists( 'torobpay_flag_divergence_from_status' ) ) {
		torobpay_flag_divergence_from_status( $order, $status );
	}

	wp_send_json_success(
		array(
			'status_class' => $formatted_status['class'],
			'status_text'  => $formatted_status['text'],
			'raw_status'   => $status,
		)
	);
}

/**
 * Preflight for refund / edit-sync: refresh status, settle if VERIFY, then succeed or error.
 */
add_action( 'wp_ajax_torobpay_preflight_action', 'torobpay_ajax_preflight_action' );
function torobpay_ajax_preflight_action() {
	check_ajax_referer( 'torobpay-action-preflight', 'security' );

	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
	if ( ! $order_id ) {
		wp_send_json_error( array( 'message' => __( 'Invalid order ID', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Order not found', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	if ( torobpay_should_bypass_order( $order, __FUNCTION__ ) ) {
		wp_send_json_error( array( 'message' => __( 'This order is a marketplace vendor sub-order and has no independent payment token.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$preflight_result = torobpay_run_action_preflight( $order );

	if ( $preflight_result['success'] ) {
		wp_send_json_success( array( 'raw_status' => $preflight_result['raw_status'] ) );
		return;
	}

	wp_send_json_error(
		array(
			'message'    => $preflight_result['message'],
			'raw_status' => $preflight_result['raw_status'],
		)
	);
}

/**
 * Format payment status for display with CSS class
 */
function torobpay_format_payment_status( $status ) {
	if ( __( 'Loading...', 'torobpay-for-woocommerce' ) === $status ) {
		return array(
			'class' => 'status-loading',
			'text'  => $status,
		);
	}

	$meta = torobpay_get_payment_status_meta( $status );

	return array(
		'class' => $meta['css_class'],
		'text'  => $meta['text'],
	);
}

/**
 * AJAX: live-save the three display toggle checkboxes from the widget settings card.
 * Triggered by checkbox changes on the مدیریت نمایش و ظاهر المان‌های ترب‌پی card.
 */
add_action( 'wp_ajax_torobpay_save_display_toggles', 'torobpay_ajax_save_display_toggles' );
function torobpay_ajax_save_display_toggles() {
	check_ajax_referer( 'torobpay_display_toggles', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized', 'torobpay-for-woocommerce' ) ), 403 );
		return;
	}

	update_option( 'torobpay_widget_enabled', ( isset( $_POST['widget_enabled'] ) && '1' === $_POST['widget_enabled'] ) ? 'yes' : 'no' );
	update_option( 'torobpay_badge_enabled', ( isset( $_POST['badge_enabled'] ) && '1' === $_POST['badge_enabled'] ) ? 'yes' : 'no' );
	update_option( 'torobpay_marquee_enabled', ( isset( $_POST['marquee_enabled'] ) && '1' === $_POST['marquee_enabled'] ) ? 'yes' : 'no' );
	update_option( 'torobpay_topbar_enabled', ( isset( $_POST['topbar_enabled'] ) && '1' === $_POST['topbar_enabled'] ) ? 'yes' : 'no' );
	$slider_enabled = ( isset( $_POST['slider_enabled'] ) && '1' === $_POST['slider_enabled'] ) ? 'yes' : 'no';
	update_option( 'torobpay_slider_enabled', $slider_enabled );
	if ( 'yes' === $slider_enabled ) {
		torobpay_slider_reset_visibility_if_none_visible();
	}

	wp_send_json_success( array( 'message' => __( 'Saved', 'torobpay-for-woocommerce' ) ) );
}
