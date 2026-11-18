<?php
/**
 * Customer-facing payment result page for in-store (POS) orders.
 *
 * Used for both successful and failed returns so checkout/thank-you cannot
 * rebuild a cart or add shipping onto the existing in-store order.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/torobpay-jalali.php';

/**
 * Query vars for the in-store result URL.
 *
 * @param array $vars Public query vars.
 * @return array
 */
function torobpay_instore_result_query_vars( $vars ) {
	$vars[] = 'torobpay_instore_result';
	return $vars;
}

/**
 * Whether the current request is the in-store payment result page.
 *
 * @return bool
 */
function torobpay_is_instore_result_request() {
	$result = '';

	if ( isset( $_GET['torobpay_instore_result'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public result-page flag.
		$result = sanitize_key( wp_unslash( $_GET['torobpay_instore_result'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public result-page flag.
	} elseif ( function_exists( 'get_query_var' ) ) {
		$result = sanitize_key( (string) get_query_var( 'torobpay_instore_result' ) );
	}

	return in_array( $result, array( 'success', 'failed' ), true );
}

/**
 * Keep this result URL reachable when the shop is in coming-soon mode.
 *
 * @param bool $exclude Whether the request is already excluded.
 * @return bool
 */
function torobpay_instore_result_coming_soon_exclude( $exclude ) {
	return $exclude || torobpay_is_instore_result_request();
}

/**
 * Resolve the in-store order for the result request using the order key.
 *
 * @return object|null
 */
function torobpay_get_instore_result_request_order() {
	$order_id = 0;
	$key      = '';

	if ( isset( $_POST['torobpay_instore_retry'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Order key authorizes; nonce checked on retry.
		$order_id = isset( $_POST['wc_order'] ) ? absint( wp_unslash( $_POST['wc_order'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Order key authorizes; nonce checked on retry.
		$key      = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Order key authorizes; nonce checked on retry.
	} else {
		$order_id = isset( $_GET['wc_order'] ) ? absint( wp_unslash( $_GET['wc_order'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public order id + key.
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public order id + key.
	}

	if ( $order_id <= 0 || $key === '' ) {
		return null;
	}

	$order = function_exists( 'torobpay_get_fresh_order' )
		? torobpay_get_fresh_order( $order_id )
		: ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false );

	if ( ! $order || ! torobpay_is_instore_order( $order ) ) {
		return null;
	}

	$expected = method_exists( $order, 'get_order_key' ) ? (string) $order->get_order_key() : '';
	if ( $expected === '' || ! hash_equals( $expected, $key ) ) {
		return null;
	}

	return $order;
}

/**
 * Watch state used for the result page document title.
 *
 * @param string|null $state Watch state to store; null to read.
 * @return string
 */
function torobpay_instore_result_page_state( $state = null ) {
	static $current = 'failed';

	if ( null !== $state ) {
		$current = (string) $state;
	}

	return $current;
}

/**
 * Send in-store customers away from WooCommerce order-received to the result page.
 *
 * @return void
 */
function torobpay_redirect_instore_order_received() {
	if ( is_admin() || torobpay_is_instore_result_request() ) {
		return;
	}

	$is_received = ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
		|| ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) );

	if ( ! $is_received ) {
		return;
	}

	$order_id = 0;
	if ( function_exists( 'get_query_var' ) ) {
		$order_id = absint( get_query_var( 'order-received' ) );
	}
	if ( $order_id <= 0 && isset( $GLOBALS['wp']->query_vars['order-received'] ) ) {
		$order_id = absint( $GLOBALS['wp']->query_vars['order-received'] );
	}
	if ( $order_id <= 0 ) {
		return;
	}

	$order = function_exists( 'torobpay_get_fresh_order' )
		? torobpay_get_fresh_order( $order_id )
		: ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false );

	if ( ! $order || ! torobpay_is_instore_order( $order ) ) {
		return;
	}

	$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Order-received key check.
	$expected = method_exists( $order, 'get_order_key' ) ? (string) $order->get_order_key() : '';
	if ( $expected === '' || $key === '' || ! hash_equals( $expected, $key ) ) {
		return;
	}

	$state  = torobpay_get_instore_payment_watch_state( $order );
	$result = ( 'paid' === $state ) ? 'success' : 'failed';

	wp_safe_redirect( torobpay_get_instore_result_url( $order, $result ) );
	exit;
}

/**
 * Handle GET display and POST retry for the in-store result page.
 *
 * GET renders from local watch state. The success/failed query arg is routing
 * only. Retry POST refreshes remotely before minting a payment URL.
 *
 * @return void
 */
function torobpay_handle_instore_result_request() {
	if ( is_admin() || ! torobpay_is_instore_result_request() ) {
		return;
	}

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Cache plugin convention.
		define( 'DONOTCACHEPAGE', true );
	}
	if ( function_exists( 'nocache_headers' ) ) {
		nocache_headers();
	}

	$order = torobpay_get_instore_result_request_order();
	if ( ! $order ) {
		wp_die(
			esc_html__( 'This payment page is not valid.', 'torobpay-for-woocommerce' ),
			esc_html__( 'Payment was not completed', 'torobpay-for-woocommerce' ),
			array( 'response' => 403 )
		);
	}

	$state = torobpay_get_instore_payment_watch_state( $order );

	$retry_error = '';
	if ( isset( $_POST['torobpay_instore_retry'] ) && 'paid' !== $state ) {
		$retry_error = torobpay_process_instore_customer_retry( $order );
	}

	torobpay_render_instore_result_page( $order, $state, $retry_error );
	exit;
}

/**
 * Send the customer back to TorobPay, reusing an active URL when the order is still pending.
 *
 * Same mint rule as the merchant QR: refresh watch state, then
 * torobpay_resolve_instore_payment_page_url() (reuse a pending URL; mint only
 * on failed). Old tokens are not invalidated; whichever pays first wins.
 *
 * @param object $order Order.
 * @return string Empty on redirect; error message to show on the page.
 */
function torobpay_process_instore_customer_retry( $order ) {
	$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
	$nonce    = isset( $_POST['torobpay_instore_retry_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['torobpay_instore_retry_nonce'] ) ) : '';

	if ( $order_id <= 0 || ! wp_verify_nonce( $nonce, 'torobpay_instore_customer_retry_' . $order_id ) ) {
		return __( 'The retry request could not be verified. Please try again.', 'torobpay-for-woocommerce' );
	}

	$resolved = torobpay_refresh_instore_payment_watch_state( $order );
	$order    = $resolved['order'];
	$state    = $resolved['state'];

	if ( 'paid' === $state ) {
		$success_url = torobpay_get_instore_result_url( $order, 'success' );
		if ( $success_url && wp_safe_redirect( $success_url ) ) {
			exit;
		}
		return __( 'This order can no longer accept payment.', 'torobpay-for-woocommerce' );
	}

	$payment_url = torobpay_resolve_instore_payment_page_url( $order, $state );

	if ( is_wp_error( $payment_url ) ) {
		if ( 'torobpay_instore_no_payment_url' === $payment_url->get_error_code() ) {
			return __( 'This order can no longer accept payment.', 'torobpay-for-woocommerce' );
		}
		return $payment_url->get_error_message();
	}

	$payment_url = esc_url_raw( (string) $payment_url );
	$host        = wp_parse_url( $payment_url, PHP_URL_HOST );
	if ( ! $payment_url || ! $host ) {
		return __( 'Error redirecting to the gateway. Please try again.', 'torobpay-for-woocommerce' );
	}

	add_filter(
		'allowed_redirect_hosts',
		static function ( $hosts ) use ( $host ) {
			$hosts[] = $host;
			return array_unique( $hosts );
		}
	);

	if ( wp_safe_redirect( $payment_url ) ) {
		exit;
	}

	return __( 'Error redirecting to the gateway. Please try again.', 'torobpay-for-woocommerce' );
}

/**
 * Document title for the in-store result page.
 *
 * @param string $title Current title.
 * @return string
 */
function torobpay_instore_result_document_title( $title ) {
	unset( $title );

	if ( 'paid' === torobpay_instore_result_page_state() ) {
		return __( 'Payment successful', 'torobpay-for-woocommerce' );
	}

	return __( 'Payment was not completed', 'torobpay-for-woocommerce' );
}

/**
 * Discourage indexing of payment-result URLs.
 *
 * @param array $robots Robots directives.
 * @return array
 */
function torobpay_instore_result_robots( $robots ) {
	$robots['noindex']  = true;
	$robots['nofollow'] = true;
	return $robots;
}

/**
 * Enqueue styles for the in-store result page.
 *
 * @return void
 */
function torobpay_enqueue_instore_result_assets() {
	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';
	wp_enqueue_style(
		'torobpay-instore-result',
		plugins_url( 'assets/css/instore-result.css', TOROBPAY_PLUGIN_FILE ),
		array(),
		$version
	);
}

/**
 * Drop theme and other frontend assets so this page is standalone.
 *
 * @return void
 */
function torobpay_instore_result_dequeue_theme_assets() {
	global $wp_styles, $wp_scripts;

	$keep_styles = array( 'torobpay-instore-result', 'admin-bar', 'dashicons' );
	if ( isset( $wp_styles ) && is_object( $wp_styles ) && isset( $wp_styles->queue ) && is_array( $wp_styles->queue ) ) {
		foreach ( $wp_styles->queue as $handle ) {
			if ( ! in_array( $handle, $keep_styles, true ) ) {
				wp_dequeue_style( $handle );
			}
		}
	}

	$keep_scripts = array( 'admin-bar', 'hoverintent-js', 'jquery', 'jquery-core', 'jquery-migrate' );
	if ( isset( $wp_scripts ) && is_object( $wp_scripts ) && isset( $wp_scripts->queue ) && is_array( $wp_scripts->queue ) ) {
		foreach ( $wp_scripts->queue as $handle ) {
			if ( ! in_array( $handle, $keep_scripts, true ) ) {
				wp_dequeue_script( $handle );
			}
		}
	}
}

/**
 * Print WordPress footer scripts without theme footer markup.
 *
 * @return void
 */
function torobpay_instore_result_print_footer() {
	remove_all_actions( 'wp_footer' );
	add_action( 'wp_footer', 'wp_print_footer_scripts', 20 );
	if ( function_exists( 'is_admin_bar_showing' ) && is_admin_bar_showing() && function_exists( 'wp_admin_bar_render' ) ) {
		add_action( 'wp_footer', 'wp_admin_bar_render', 1000 );
	}
	wp_footer();
}

/**
 * Status icon for the result card.
 *
 * @param bool $success Whether payment succeeded.
 * @return string
 */
function torobpay_instore_result_status_icon_html( $success ) {
	if ( $success ) {
		return '<span class="torobpay-instore-result__icon torobpay-instore-result__icon--success" aria-hidden="true">'
			. '<svg viewBox="0 0 72 72" focusable="false">'
			. '<circle cx="36" cy="36" r="36" fill="#9ce76a"/>'
			. '<path d="M22 38.5 L31.5 48 L51 26" fill="none" stroke="#003d01" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>'
			. '</svg></span>';
	}

	return '<span class="torobpay-instore-result__icon torobpay-instore-result__icon--failed" aria-hidden="true">'
		. '<svg viewBox="0 0 72 72" focusable="false">'
		. '<circle cx="36" cy="36" r="36" fill="#f8d7da"/>'
		. '<path d="M26 26 L46 46 M46 26 L26 46" fill="none" stroke="#842029" stroke-width="5" stroke-linecap="round"/>'
		. '</svg></span>';
}

/**
 * Clover mark for one installment (1-4 petals filled).
 *
 * @param int $filled Number of filled petals (1-4).
 * @return string
 */
function torobpay_instore_tranche_icon_html( $filled ) {
	static $icons = array();

	$filled = (int) $filled;
	if ( $filled < 1 ) {
		$filled = 1;
	} elseif ( $filled > 4 ) {
		$filled = 4;
	}

	if ( isset( $icons[ $filled ] ) ) {
		return $icons[ $filled ];
	}

	$base = defined( 'TOROBPAY_PLUGIN_FILE' )
		? dirname( TOROBPAY_PLUGIN_FILE )
		: dirname( __DIR__ );
	$file = $base . '/assets/images/tranche-' . $filled . '.svg';
	$svg  = is_readable( $file ) ? file_get_contents( $file ) : '';

	$icons[ $filled ] = is_string( $svg ) ? trim( $svg ) : '';

	return $icons[ $filled ];
}

/**
 * Timeline steps for the result card.
 *
 * Paid orders show four Jalali installment due dates. Other states keep
 * the payment-status timeline.
 *
 * @param string      $state Watch state.
 * @param object|null $order Order (used for the paid-date on success).
 * @return array<int,array{label:string,status:string,date:string}>
 */
function torobpay_instore_result_steps( $state, $order = null ) {
	if ( 'paid' === $state ) {
		return torobpay_instore_result_installment_steps( $order );
	}

	$failed         = in_array( $state, array( 'failed', 'closed' ), true );
	$payment_status = $failed ? 'failed' : 'current';

	return array(
		array(
			'label'  => __( 'Order created', 'torobpay-for-woocommerce' ),
			'status' => 'done',
			'date'   => '',
		),
		array(
			'label'  => __( 'Payment', 'torobpay-for-woocommerce' ),
			'status' => $payment_status,
			'date'   => '',
		),
		array(
			'label'  => __( 'Completed', 'torobpay-for-woocommerce' ),
			'status' => 'pending',
			'date'   => '',
		),
	);
}

/**
 * Four installment due-date steps for a successful payment.
 *
 * @param object|null $order Order.
 * @return array<int,array{label:string,status:string,date:string}>
 */
function torobpay_instore_result_installment_steps( $order = null ) {
	$labels = torobpay_order_installment_due_labels( $order );
	$steps  = array();

	foreach ( $labels as $index => $label ) {
		$steps[] = array(
			'label'  => (string) $label,
			'status' => ( 0 === (int) $index ) ? 'done' : 'upcoming',
			'date'   => '',
		);
	}

	return $steps;
}

/**
 * Heading above the result-card steps.
 *
 * @param string $state Watch state.
 * @return string
 */
function torobpay_instore_result_steps_title( $state ) {
	if ( 'paid' === $state ) {
		return __( 'Your installment due dates', 'torobpay-for-woocommerce' );
	}

	return __( 'Payment status', 'torobpay-for-woocommerce' );
}

/**
 * Render a standalone result page (no checkout, no cart).
 *
 * @param object $order       Order.
 * @param string $state       Watch state.
 * @param string $retry_error Error from a failed retry POST, if any.
 * @return void
 */
function torobpay_render_instore_result_page( $order, $state, $retry_error = '' ) {
	torobpay_instore_result_page_state( $state );
	add_filter( 'pre_get_document_title', 'torobpay_instore_result_document_title' );
	add_filter( 'wp_robots', 'torobpay_instore_result_robots' );
	add_action( 'wp_enqueue_scripts', 'torobpay_enqueue_instore_result_assets' );
	add_action( 'wp_enqueue_scripts', 'torobpay_instore_result_dequeue_theme_assets', 9999 );
	add_action( 'wp_print_styles', 'torobpay_instore_result_dequeue_theme_assets', 1 );
	add_action( 'wp_print_scripts', 'torobpay_instore_result_dequeue_theme_assets', 1 );
	if ( function_exists( 'wp_enqueue_scripts' ) ) {
		torobpay_enqueue_instore_result_assets();
	}

	$is_paid   = ( 'paid' === $state );
	$can_retry = ! $is_paid && torobpay_instore_order_allows_payment_qr( $order );
	$title     = $is_paid
		? __( 'Payment successful', 'torobpay-for-woocommerce' )
		: __( 'Payment was not completed', 'torobpay-for-woocommerce' );
	$message   = $is_paid
		? torobpay_get_instore_success_message()
		: torobpay_get_instore_failed_message();
	$order_id  = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
	$order_no  = method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $order_id;
	$total     = method_exists( $order, 'get_formatted_order_total' ) ? $order->get_formatted_order_total() : '';
	$form_url  = torobpay_get_instore_result_url( $order, 'failed' );
	$store     = function_exists( 'get_bloginfo' ) ? wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) : '';
	$logo_url    = defined( 'TOROBPAY_PLUGIN_FILE' ) ? plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) : '';
	$parts_url   = defined( 'TOROBPAY_PLUGIN_FILE' ) ? plugins_url( 'assets/images/all-parts.svg', TOROBPAY_PLUGIN_FILE ) : '';
	$outcome     = $is_paid ? 'success' : 'failed';
	$steps       = torobpay_instore_result_steps( $state, $order );
	$steps_title = torobpay_instore_result_steps_title( $state );

	status_header( 200 );
	header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

	$closed_copy = '';
	if ( ! $is_paid && ( 'closed' === $state || ! $can_retry ) ) {
		$messages    = torobpay_instore_watch_messages();
		$closed_copy = $messages['closed'];
	}

	$body_classes = array( 'torobpay-instore-result-body', 'torobpay-instore-result-body--' . $outcome );

	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( $body_classes ); ?>>
	<div class="torobpay-instore-result" id="torobpay-instore-result">
		<main class="torobpay-instore-result__main">
			<div class="torobpay-instore-result__card">
				<div class="torobpay-instore-result__summary">
					<div class="torobpay-instore-result__summary-text">
						<?php if ( $store !== '' ) : ?>
							<p class="torobpay-instore-result__store">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: store name */
										__( 'Purchase from %s', 'torobpay-for-woocommerce' ),
										$store
									)
								);
								?>
							</p>
						<?php endif; ?>
						<?php if ( $total !== '' ) : ?>
							<p class="torobpay-instore-result__total">
								<span class="torobpay-instore-result__total-label"><?php esc_html_e( 'Total:', 'torobpay-for-woocommerce' ); ?></span>
								<span class="torobpay-instore-result__total-value"><?php echo wp_kses( $total, torobpay_kses_allowed_html() ); ?></span>
							</p>
						<?php endif; ?>
						<?php if ( $order_no !== '' ) : ?>
							<p class="torobpay-instore-result__order">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: order number */
										__( 'Order #%s', 'torobpay-for-woocommerce' ),
										$order_no
									)
								);
								?>
							</p>
						<?php endif; ?>
					</div>
				</div>

				<div class="torobpay-instore-result__status" role="status">
					<?php echo torobpay_instore_result_status_icon_html( $is_paid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hard-coded SVG. ?>
					<h1 class="torobpay-instore-result__title"><?php echo esc_html( $title ); ?></h1>
					<p class="torobpay-instore-result__message"><?php echo esc_html( $message ); ?></p>
				</div>

				<?php if ( $retry_error !== '' ) : ?>
					<p class="torobpay-instore-result__error" role="alert"><?php echo esc_html( $retry_error ); ?></p>
				<?php endif; ?>

				<?php if ( $can_retry ) : ?>
					<form class="torobpay-instore-result__form" method="post" action="<?php echo esc_url( $form_url ); ?>">
						<?php wp_nonce_field( 'torobpay_instore_customer_retry_' . $order_id, 'torobpay_instore_retry_nonce' ); ?>
						<input type="hidden" name="torobpay_instore_retry" value="1">
						<input type="hidden" name="wc_order" value="<?php echo esc_attr( (string) $order_id ); ?>">
						<input type="hidden" name="key" value="<?php echo esc_attr( method_exists( $order, 'get_order_key' ) ? (string) $order->get_order_key() : '' ); ?>">
						<button type="submit" class="torobpay-instore-result__retry">
							<?php esc_html_e( 'Try payment again', 'torobpay-for-woocommerce' ); ?>
						</button>
					</form>
				<?php elseif ( $closed_copy !== '' ) : ?>
					<p class="torobpay-instore-result__closed"><?php echo esc_html( $closed_copy ); ?></p>
				<?php endif; ?>

				<div class="torobpay-instore-result__steps<?php echo $is_paid ? ' torobpay-instore-result__steps--installments' : ''; ?>">
					<p class="torobpay-instore-result__steps-title"><?php echo esc_html( $steps_title ); ?></p>
					<ol class="torobpay-instore-result__steps-list" aria-label="<?php echo esc_attr( $steps_title ); ?>">
						<?php foreach ( $steps as $index => $step ) : ?>
							<li class="torobpay-instore-result__step is-<?php echo esc_attr( $step['status'] ); ?>">
								<?php if ( $is_paid ) : ?>
									<?php echo torobpay_instore_tranche_icon_html( (int) $index + 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plugin SVG files. ?>
								<?php elseif ( $logo_url ) : ?>
									<img class="torobpay-instore-result__step-icon" src="<?php echo esc_url( $logo_url ); ?>" alt="" width="36" height="36" />
								<?php endif; ?>
								<span class="torobpay-instore-result__step-label"><?php echo esc_html( $step['label'] ); ?></span>
								<?php if ( ! empty( $step['date'] ) ) : ?>
									<span class="torobpay-instore-result__step-date"><?php echo esc_html( $step['date'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ol>
				</div>
			</div>
		</main>
		<aside class="torobpay-instore-result__brand">
			<?php if ( $logo_url ) : ?>
				<img class="torobpay-instore-result__brand-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="" width="72" height="72" />
			<?php endif; ?>
			<p class="torobpay-instore-result__brand-title"><?php esc_html_e( 'Pay in installments with TorobPay', 'torobpay-for-woocommerce' ); ?></p>
			<?php if ( $parts_url ) : ?>
				<img class="torobpay-instore-result__brand-parts" src="<?php echo esc_url( $parts_url ); ?>" alt="" width="220" height="49" />
			<?php endif; ?>
			<p class="torobpay-instore-result__brand-tagline"><?php esc_html_e( '4 installments. No fee, checks, or guarantor.', 'torobpay-for-woocommerce' ); ?></p>
		</aside>
	</div>
	<?php torobpay_instore_result_print_footer(); ?>
</body>
</html>
	<?php
}

add_filter( 'query_vars', 'torobpay_instore_result_query_vars' );
add_filter( 'woocommerce_coming_soon_exclude', 'torobpay_instore_result_coming_soon_exclude' );
add_action( 'template_redirect', 'torobpay_redirect_instore_order_received', 4 );
add_action( 'template_redirect', 'torobpay_handle_instore_result_request', 5 );
