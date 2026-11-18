<?php
/**
 * Admin notice for TorobPay orders needing action.
 *
 * Mirrors the action-order notification provider but surfaces the same count on
 * WooCommerce screens, where merchants manage orders.
 *
 * Dismissal is persisted per-user (stored as the order count at the moment of
 * dismissal). The notice stays hidden until the number of action-needed orders
 * grows beyond that snapshot, at which point it resurfaces.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_notices', 'torobpay_render_action_needed_admin_notice' );
/**
 * Render the action-needed notice on WooCommerce admin screens.
 *
 * @return void
 */
function torobpay_render_action_needed_admin_notice() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	if ( ! torobpay_is_woocommerce_admin_screen() ) {
		return;
	}

	$count = torobpay_get_action_needed_order_count();
	if ( $count < 1 ) {
		return;
	}

	// Honor a persistent per-user dismissal: only re-show once the number of
	// action-needed orders exceeds the count captured when last dismissed.
	$dismissed_at = (int) get_user_meta( get_current_user_id(), 'torobpay_action_notice_dismissed_count', true );
	if ( $dismissed_at > 0 && $count <= $dismissed_at ) {
		return;
	}

	$url = admin_url( 'admin.php?page=torobpay-orders&torobpay_status=ACTION_NEEDED' );
	?>
	<div class="notice notice-warning is-dismissible torobpay-action-needed-notice"
		data-count="<?php echo (int) $count; ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'torobpay-dismiss-action-notice' ) ); ?>">
		<button type="button" class="notice-dismiss">
			<span class="screen-reader-text"><?php esc_html_e( 'Dismiss', 'torobpay-for-woocommerce' ); ?></span>
		</button>
		<p class="torobpay-action-needed-notice__text">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<strong><?php esc_html_e( 'TorobPay:', 'torobpay-for-woocommerce' ); ?></strong>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of orders needing review */
					__( '%d order(s) need review or action (status mismatch or incomplete settlement/refund).', 'torobpay-for-woocommerce' ),
					$count
				)
			);
			?>
		</p>
		<p>
			<a href="<?php echo esc_url( $url ); ?>" class="button button-primary"><?php esc_html_e( 'View orders needing action', 'torobpay-for-woocommerce' ); ?></a>
		</p>
	</div>
	<?php
	torobpay_enqueue_action_needed_notice_dismiss_script();
}

/**
 * Enqueue dismiss handler for the action-needed admin notice (once per request).
 *
 * @return void
 */
function torobpay_enqueue_action_needed_notice_dismiss_script() {
	static $enqueued = false;
	if ( $enqueued ) {
		return;
	}
	$enqueued = true;

	$version  = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';
	$ajax_url = wp_json_encode( admin_url( 'admin-ajax.php' ) );
	$js       = <<<JS
jQuery(function($) {
	var ajaxUrl = {$ajax_url};
	$('.torobpay-action-needed-notice').on('click', '.notice-dismiss', function(e) {
		e.preventDefault();
		var \$notice = $(this).closest('.torobpay-action-needed-notice');
		$.post(ajaxUrl, {
			action:   'torobpay_dismiss_action_notice',
			security: \$notice.data('nonce'),
			count:    \$notice.data('count')
		}, function() {
			\$notice.fadeOut(200, function() { $(this).remove(); });
		});
	});
});
JS;

	wp_register_script( 'torobpay-action-needed-notice-dismiss', '', array( 'jquery' ), $version, true );
	wp_enqueue_script( 'torobpay-action-needed-notice-dismiss' );
	wp_add_inline_script( 'torobpay-action-needed-notice-dismiss', $js );
}

add_action( 'wp_ajax_torobpay_dismiss_action_notice', 'torobpay_ajax_dismiss_action_notice' );
/**
 * Persist the per-user dismissal snapshot for the action-needed notice.
 *
 * @return void
 */
function torobpay_ajax_dismiss_action_notice() {
	check_ajax_referer( 'torobpay-dismiss-action-notice', 'security' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'torobpay-for-woocommerce' ) ) );
		return;
	}

	$count = isset( $_POST['count'] ) ? absint( wp_unslash( $_POST['count'] ) ) : 0;

	update_user_meta( get_current_user_id(), 'torobpay_action_notice_dismissed_count', $count );

	wp_send_json_success();
}
