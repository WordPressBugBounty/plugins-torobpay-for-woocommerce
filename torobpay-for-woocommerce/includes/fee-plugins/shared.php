<?php
/**
 * Shared fee-plugin blocker: gateway identity, fee-line removal, and admin notices.
 *
 * Each fee plugin registers itself. Adding a plugin does not require a new
 * branch in the removal loop.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registered fee plugins, keyed by notice id.
 *
 * @return array
 */
function torobpay_blocked_fee_plugins() {
	if ( ! isset( $GLOBALS['torobpay_blocked_fee_plugins'] ) || ! is_array( $GLOBALS['torobpay_blocked_fee_plugins'] ) ) {
		$GLOBALS['torobpay_blocked_fee_plugins'] = array();
	}

	return $GLOBALS['torobpay_blocked_fee_plugins'];
}

/**
 * Register one fee plugin's notice and cart-fee check.
 *
 * @param array $plugin id, dom_id, priority, active, notice_body, fee_line.
 * @return void
 */
function torobpay_register_blocked_fee_plugin( $plugin ) {
	if ( ! is_array( $plugin ) || empty( $plugin['id'] ) ) {
		return;
	}

	if ( ! isset( $GLOBALS['torobpay_blocked_fee_plugins'] ) || ! is_array( $GLOBALS['torobpay_blocked_fee_plugins'] ) ) {
		$GLOBALS['torobpay_blocked_fee_plugins'] = array();
	}

	$GLOBALS['torobpay_blocked_fee_plugins'][ (string) $plugin['id'] ] = $plugin;
}

/**
 * Registered callbacks that run around each price-parity gateway calculation.
 *
 * @return array
 */
function torobpay_price_parity_gateway_probes() {
	if ( ! isset( $GLOBALS['torobpay_price_parity_gateway_probes'] ) || ! is_array( $GLOBALS['torobpay_price_parity_gateway_probes'] ) ) {
		$GLOBALS['torobpay_price_parity_gateway_probes'] = array();
	}

	return $GLOBALS['torobpay_price_parity_gateway_probes'];
}

/**
 * Let a fee plugin adjust state before one gateway total is measured.
 *
 * @param callable $before Receives the gateway id and returns state for $after.
 * @param callable $after  Receives that state.
 * @param callable $reset  Clears leftover probe state before the shopper cart is restored.
 * @return void
 */
function torobpay_register_price_parity_gateway_probe( $before, $after, $reset ) {
	if ( ! isset( $GLOBALS['torobpay_price_parity_gateway_probes'] ) || ! is_array( $GLOBALS['torobpay_price_parity_gateway_probes'] ) ) {
		$GLOBALS['torobpay_price_parity_gateway_probes'] = array();
	}

	$GLOBALS['torobpay_price_parity_gateway_probes'][] = array(
		'before' => $before,
		'after'  => $after,
		'reset'  => $reset,
	);
}

/**
 * Run probe setup for every registered fee plugin.
 *
 * @param string $gateway_id Gateway ID being measured.
 * @return array States to pass back to torobpay_price_parity_end_gateway().
 */
function torobpay_price_parity_begin_gateway( $gateway_id ) {
	$states = array();

	foreach ( torobpay_price_parity_gateway_probes() as $probe ) {
		$state = null;
		if ( is_callable( $probe['before'] ) ) {
			$state = call_user_func( $probe['before'], $gateway_id );
		}
		$states[] = array(
			'after' => $probe['after'],
			'state' => $state,
		);
	}

	return $states;
}

/**
 * Restore fee-plugin state after one gateway total.
 *
 * @param array $states Values from torobpay_price_parity_begin_gateway().
 * @return void
 */
function torobpay_price_parity_end_gateway( $states ) {
	if ( ! is_array( $states ) ) {
		return;
	}

	foreach ( $states as $row ) {
		if ( is_array( $row ) && isset( $row['after'] ) && is_callable( $row['after'] ) ) {
			call_user_func( $row['after'], $row['state'] );
		}
	}
}

/**
 * Clear leftover probe state before the shopper cart is calculated again.
 *
 * @return void
 */
function torobpay_price_parity_reset_gateway_probes() {
	foreach ( torobpay_price_parity_gateway_probes() as $probe ) {
		if ( isset( $probe['reset'] ) && is_callable( $probe['reset'] ) ) {
			call_user_func( $probe['reset'] );
		}
	}
}

/**
 * TorobPay WooCommerce gateway ID(s).
 *
 * @return string[]
 */
function torobpay_gateway_fee_torobpay_gateway_ids() {
	// Include the legacy 'torobpay' id for older installs / fee options.
	return array( 'WC_Gateway_TorobPay', 'torobpay' );
}

/**
 * Whether a payment method ID is TorobPay.
 *
 * @param string $gateway_id Chosen payment method ID.
 * @return bool
 */
function torobpay_gateway_fee_is_torobpay_gateway_id( $gateway_id ) {
	$gateway_id = (string) $gateway_id;
	if ( '' === $gateway_id ) {
		return false;
	}

	foreach ( torobpay_gateway_fee_torobpay_gateway_ids() as $known_id ) {
		if ( 0 === strcasecmp( $gateway_id, $known_id ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Chosen payment method for fee stripping (checkout POST, then session).
 *
 * @return string
 */
function torobpay_gateway_fee_chosen_payment_method() {
	$chosen = '';

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only checkout payload for fee stripping.
	if ( ! empty( $_POST['payment_method'] ) ) {
		$chosen = sanitize_text_field( wp_unslash( $_POST['payment_method'] ) );
	} elseif ( ! empty( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
		parse_str( wp_unslash( $_POST['post_data'] ), $post_data ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! empty( $post_data['payment_method'] ) ) {
			$chosen = sanitize_text_field( $post_data['payment_method'] );
		}
	}
	// phpcs:enable

	if ( '' === $chosen && function_exists( 'WC' ) && WC() && WC()->session ) {
		$chosen = (string) WC()->session->get( 'chosen_payment_method' );
	}

	return $chosen;
}

/**
 * Whether surcharge removal should wait for an in-progress price comparison.
 *
 * The parity include is optional at load time. When it is absent, removal runs.
 *
 * @return bool
 */
function torobpay_blocked_fee_removal_is_paused() {
	return function_exists( 'torobpay_is_payment_price_parity_probing' )
		&& torobpay_is_payment_price_parity_probing();
}

/**
 * Strip unauthorized TorobPay gateway fees after third-party fee plugins run.
 *
 * Toroobche adds fees on `woocommerce_cart_calculate_fees` (default priority).
 * SiteSkill Pay Discount, Karmozd Pay, and Arvan Pay add them at priority 20.
 * QestX adds them at priority 30, including rule-engine add_fee rows. We run
 * later and remove only positive surcharge lines when TorobPay is selected —
 * without touching those plugins’ options or activation state.
 *
 * Removal waits while a price-parity probe is reading totals. Stripping during
 * that read would hide the surcharge from the comparison.
 *
 * @param WC_Cart $cart Cart instance.
 * @return void
 */
function torobpay_strip_unauthorized_gateway_fees( $cart ) {
	if ( torobpay_blocked_fee_removal_is_paused() ) {
		return;
	}

	if ( ! $cart instanceof WC_Cart ) {
		return;
	}

	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}

	if ( ! function_exists( 'WC' ) || ! WC() ) {
		return;
	}

	$chosen = torobpay_gateway_fee_chosen_payment_method();
	if ( ! torobpay_gateway_fee_is_torobpay_gateway_id( $chosen ) ) {
		return;
	}

	$fees = $cart->get_fees();
	if ( empty( $fees ) || ! is_array( $fees ) ) {
		return;
	}

	$changed = false;
	foreach ( $fees as $key => $fee ) {
		foreach ( torobpay_blocked_fee_plugins() as $plugin ) {
			$check = isset( $plugin['fee_line'] ) ? $plugin['fee_line'] : '';
			if ( is_callable( $check ) && $check( $fee ) ) {
				unset( $fees[ $key ] );
				$changed = true;
				break;
			}
		}
	}

	if ( $changed ) {
		$cart->fees_api()->set_fees( $fees );
	}
}
add_action( 'woocommerce_cart_calculate_fees', 'torobpay_strip_unauthorized_gateway_fees', 100 );

/**
 * Drop legacy detection-cache option from the previous hard-block approach.
 *
 * @return void
 */
function torobpay_gateway_fee_cleanup_legacy_cache() {
	if ( false !== get_option( 'torobpay_blocked_gateway_fee_cache', false ) ) {
		delete_option( 'torobpay_blocked_gateway_fee_cache' );
	}
}
add_action( 'admin_init', 'torobpay_gateway_fee_cleanup_legacy_cache', 1 );

/**
 * Enqueue dismiss JS once for blocked-plugin admin notices.
 *
 * @return void
 */
function torobpay_enqueue_blocked_plugin_notice_dismiss_script() {
	static $enqueued = false;
	if ( $enqueued ) {
		return;
	}
	$enqueued = true;

	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';
	$js      = <<<'JS'
jQuery(function($) {
	$('.torobpay-blocked-plugin-notice').on('click', '.notice-dismiss', function(e) {
		e.preventDefault();
		var $notice = $(this).closest('.torobpay-blocked-plugin-notice');
		var notifId = $notice.data('notification-id');
		if (!notifId || typeof torobpayNotifCenter === 'undefined') { return; }
		$.post(torobpayNotifCenter.ajaxurl, {
			action:          'torobpay_dismiss_notification',
			security:        torobpayNotifCenter.nonce,
			notification_id: notifId
		}, function() {
			$notice.fadeOut(200, function() { $(this).remove(); });
		});
	});
});
JS;

	if ( wp_script_is( 'torobpay-admin-notification-center', 'enqueued' ) ) {
		wp_add_inline_script( 'torobpay-admin-notification-center', $js );
	} else {
		wp_register_script( 'torobpay-blocked-plugin-notice-dismiss', '', array( 'jquery' ), $version, true );
		wp_enqueue_script( 'torobpay-blocked-plugin-notice-dismiss' );
		wp_add_inline_script( 'torobpay-blocked-plugin-notice-dismiss', $js );
	}
}

/**
 * Render one dismissible blocked-plugin admin notice.
 *
 * @param string $notification_id Stable notification id (dismissal key).
 * @param string $dom_id          Element id.
 * @param string $body_html       Pre-escaped notice body.
 * @return void
 */
function torobpay_render_blocked_fee_plugin_notice( $notification_id, $dom_id, $body_html ) {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	// Guideline 11: TorobPay + WooCommerce + Plugins only.
	if ( ! torobpay_should_render_scoped_admin_notice( true ) ) {
		return;
	}

	$dismissed = get_user_meta( get_current_user_id(), 'torobpay_dismissed_notifications', true );
	if ( is_array( $dismissed ) && in_array( $notification_id, $dismissed, true ) ) {
		return;
	}
	?>
	<div class="notice notice-warning is-dismissible torobpay-blocked-plugin-notice"
		id="<?php echo esc_attr( $dom_id ); ?>"
		data-notification-id="<?php echo esc_attr( $notification_id ); ?>">
		<button type="button" class="notice-dismiss">
			<span class="screen-reader-text"><?php esc_html_e( 'Close', 'torobpay-for-woocommerce' ); ?></span>
		</button>
		<div style="display: flex; align-items: center; margin-bottom: 8px;">
			<img src="<?php echo esc_url( plugins_url( 'assets/images/icon.png', TOROBPAY_PLUGIN_FILE ) ); ?>" alt="TorobPay" style="width:20px;height:20px;margin-left:8px;">
			<h3 style="margin:0;font-size:1.2em;font-weight:700;"><?php esc_html_e( 'TorobPay notice', 'torobpay-for-woocommerce' ); ?></h3>
		</div>
		<p><strong><?php esc_html_e( 'TorobPay terms and conditions reminder', 'torobpay-for-woocommerce' ); ?></strong></p>
		<?php echo wp_kses_post( $body_html ); ?>
	</div>
	<?php
	torobpay_enqueue_blocked_plugin_notice_dismiss_script();
}

/**
 * Notification items for every active registered fee plugin.
 *
 * @return array
 */
function torobpay_blocked_fee_plugins_notification_provider() {
	$items = array();

	foreach ( torobpay_blocked_fee_plugins() as $plugin ) {
		$active = isset( $plugin['active'] ) ? $plugin['active'] : '';
		$body   = isset( $plugin['notice_body'] ) ? $plugin['notice_body'] : '';
		if ( ! is_callable( $active ) || ! is_callable( $body ) || ! $active() ) {
			continue;
		}

		$items[] = array(
			'id'           => (string) $plugin['id'],
			'type'         => 'policy_violation',
			'priority'     => isset( $plugin['priority'] ) ? (int) $plugin['priority'] : 20,
			'title'        => __( 'TorobPay terms reminder: gateway fee', 'torobpay-for-woocommerce' ),
			'body'         => call_user_func( $body ),
			'action_url'   => '',
			'action_label' => '',
			'dismissible'  => true,
			'badge'        => 1,
			'tier'         => 'notice',
			'icon'         => 'info',
		);
	}

	return $items;
}

/**
 * Register the fee-plugin notification provider.
 *
 * @param array $providers Registered notification providers.
 * @return array
 */
function torobpay_register_gateway_fee_notification_provider( $providers ) {
	$providers[] = 'torobpay_blocked_fee_plugins_notification_provider';
	return $providers;
}
add_filter( 'torobpay_notification_providers', 'torobpay_register_gateway_fee_notification_provider' );

/**
 * Show one dismissible admin notice per active fee plugin.
 *
 * @return void
 */
function torobpay_render_blocked_fee_plugin_notices() {
	foreach ( torobpay_blocked_fee_plugins() as $plugin ) {
		$active = isset( $plugin['active'] ) ? $plugin['active'] : '';
		$body   = isset( $plugin['notice_body'] ) ? $plugin['notice_body'] : '';
		if ( ! is_callable( $active ) || ! is_callable( $body ) || ! $active() ) {
			continue;
		}

		torobpay_render_blocked_fee_plugin_notice(
			(string) $plugin['id'],
			isset( $plugin['dom_id'] ) ? (string) $plugin['dom_id'] : (string) $plugin['id'],
			call_user_func( $body )
		);
	}
}
add_action( 'admin_notices', 'torobpay_render_blocked_fee_plugin_notices' );

