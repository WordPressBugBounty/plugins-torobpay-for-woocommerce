<?php

/**
 * TorobPay Support Page
 *
 * This file contains the support page functionality
 * where shop owners can find contact information and support resources.
 *
 * @package TorobPay
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Print a support-page dashicon.
 *
 * @param string $icon        Dashicon class without the dashicons- prefix.
 * @param string $extra_class Optional extra class, such as the FAQ chevron.
 * @return void
 */
function torobpay_support_print_dashicon( $icon, $extra_class = '' ) {
	$icon = sanitize_html_class( $icon );
	if ( '' === $icon ) {
		return;
	}
	if ( 0 !== strpos( $icon, 'dashicons-' ) ) {
		$icon = 'dashicons-' . $icon;
	}

	$classes = 'dashicons ' . $icon;
	$extra_class = sanitize_html_class( $extra_class );
	if ( '' !== $extra_class ) {
		$classes .= ' ' . $extra_class;
	}

	echo '<span class="' . esc_attr( $classes ) . '" aria-hidden="true"></span>';
}

/**
 * Display the support page
 */
function torobpay_support_page() {

	// Default support contact information
	$support_bale         = 'https://ble.ir/torobpay_merchant';
	$support_bale_channel = 'https://ble.ir/join/BX9VsfA1bz';
	$panel_url            = 'https://panel.torobpay.com/s';
	$torobpay_website     = 'https://torobpay.com';
	?>
	<div class="wrap trb-support-page">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'TorobPay support', 'torobpay-for-woocommerce' ); ?></h1>

		<div class="trb-support-layout">
			<div class="trb-support-side">
				<div class="trb-support-card">
					<div class="trb-support-card-head">
						<span class="trb-support-card-icon" aria-hidden="true"><?php torobpay_support_print_dashicon( 'admin-users' ); ?></span>
						<h2><?php esc_html_e( 'Contact support', 'torobpay-for-woocommerce' ); ?></h2>
					</div>
					<p class="trb-support-card-lead"><?php esc_html_e( 'For support and guidance, contact us using the options below:', 'torobpay-for-woocommerce' ); ?></p>
					<p class="trb-support-method-label"><?php esc_html_e( 'Bale support', 'torobpay-for-woocommerce' ); ?></p>
					<div class="trb-support-actions">
						<a href="<?php echo esc_url( $support_bale ); ?>" target="_blank" rel="noopener noreferrer" class="trb-support-btn trb-support-btn--primary">
							<?php torobpay_support_print_dashicon( 'format-chat' ); ?>
							<?php esc_html_e( 'Direct message on Bale', 'torobpay-for-woocommerce' ); ?>
						</a>
						<a href="<?php echo esc_url( $support_bale_channel ); ?>" target="_blank" rel="noopener noreferrer" class="trb-support-btn">
							<?php torobpay_support_print_dashicon( 'megaphone' ); ?>
							<?php esc_html_e( 'Join Bale channel', 'torobpay-for-woocommerce' ); ?>
						</a>
					</div>
				</div>

				<div class="trb-support-card">
					<div class="trb-support-card-head">
						<span class="trb-support-card-icon" aria-hidden="true"><?php torobpay_support_print_dashicon( 'admin-links' ); ?></span>
						<h2><?php esc_html_e( 'Useful links', 'torobpay-for-woocommerce' ); ?></h2>
					</div>
					<ul class="trb-support-links">
						<li class="trb-support-link">
							<div class="trb-support-link-copy">
								<strong><?php esc_html_e( 'TorobPay merchant panel', 'torobpay-for-woocommerce' ); ?></strong>
								<p><?php esc_html_e( 'Manage orders and track transactions', 'torobpay-for-woocommerce' ); ?></p>
							</div>
							<a href="<?php echo esc_url( $panel_url ); ?>" target="_blank" rel="noopener noreferrer" class="trb-support-btn trb-support-btn--primary"><?php esc_html_e( 'Go to panel', 'torobpay-for-woocommerce' ); ?></a>
						</li>
						<li class="trb-support-link">
							<div class="trb-support-link-copy">
								<strong><?php esc_html_e( 'TorobPay website', 'torobpay-for-woocommerce' ); ?></strong>
								<p><?php esc_html_e( 'Official TorobPay page', 'torobpay-for-woocommerce' ); ?></p>
							</div>
							<a href="<?php echo esc_url( $torobpay_website ); ?>" target="_blank" rel="noopener noreferrer" class="trb-support-btn"><?php esc_html_e( 'View website', 'torobpay-for-woocommerce' ); ?></a>
						</li>
					</ul>
				</div>
			</div>

			<div class="trb-support-card trb-support-main">
				<div class="trb-support-card-head">
					<span class="trb-support-card-icon" aria-hidden="true"><?php torobpay_support_print_dashicon( 'editor-help' ); ?></span>
					<h2><?php esc_html_e( 'Frequently asked questions', 'torobpay-for-woocommerce' ); ?></h2>
				</div>
				<div class="trb-support-faq torobpay-accordion">
					<div class="trb-support-faq-item">
						<button type="button" class="torobpay-accordion-header" aria-expanded="false">
							<span class="trb-support-faq-label"><?php esc_html_e( 'How do I use in-store selling?', 'torobpay-for-woocommerce' ); ?></span>
							<?php torobpay_support_print_dashicon( 'arrow-down-alt2', 'trb-support-faq-chevron' ); ?>
						</button>
						<div class="torobpay-accordion-content">
							<p><?php esc_html_e( 'In-store selling lets a customer pay with TorobPay installments while they are in your shop. The Add in-store order button appears on TorobPay > Orders when TorobPay has enabled this feature for your store.', 'torobpay-for-woocommerce' ); ?></p>
							<ol>
								<li><?php esc_html_e( 'Open TorobPay > Orders and click "Add in-store order".', 'torobpay-for-woocommerce' ); ?></li>
								<li><?php esc_html_e( 'Enter the customer\'s mobile number. The name is optional.', 'torobpay-for-woocommerce' ); ?></li>
								<li><?php esc_html_e( 'Add products by searching the name or SKU, or enter a total amount instead of products. A discount coupon can be used when you add products.', 'torobpay-for-woocommerce' ); ?></li>
								<li><?php esc_html_e( 'Click "Create order".', 'torobpay-for-woocommerce' ); ?></li>
								<li><?php esc_html_e( 'Show the customer the QR code, or copy the payment link. TorobPay also sends the payment link by SMS; the QR code is a backup. The window waits until the customer pays.', 'torobpay-for-woocommerce' ); ?></li>
								<li><?php esc_html_e( 'If the payment is not completed, click "Retry payment" to show a new QR code.', 'torobpay-for-woocommerce' ); ?></li>
								<li><?php esc_html_e( 'You can show the QR again later from the orders list with "Show payment QR". In-store orders are marked "In-store".', 'torobpay-for-woocommerce' ); ?></li>
							</ol>
						</div>
					</div>

					<div class="trb-support-faq-item">
						<button type="button" class="torobpay-accordion-header" aria-expanded="false">
							<span class="trb-support-faq-label"><?php esc_html_e( 'How do I know an order was successful?', 'torobpay-for-woocommerce' ); ?></span>
							<?php torobpay_support_print_dashicon( 'arrow-down-alt2', 'trb-support-faq-chevron' ); ?>
						</button>
						<div class="torobpay-accordion-content">
							<p><?php esc_html_e( 'Open the order. If the order notes contain "TorobPay: Transaction was successful.", the order was registered successfully.', 'torobpay-for-woocommerce' ); ?></p>
							<img src="<?php echo esc_url( torobpay_plugin_asset_url( 'assets/images/support-successfullpayment.webp' ) ); ?>" alt="<?php echo esc_attr__( 'TorobPay: Transaction was successful.', 'torobpay-for-woocommerce' ); ?>">
							<p><?php esc_html_e( 'You can also check the order status in the', 'torobpay-for-woocommerce' ); ?> <a href="<?php echo esc_url( $panel_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'TorobPay merchant panel', 'torobpay-for-woocommerce' ); ?></a><?php esc_html_e( '.', 'torobpay-for-woocommerce' ); ?></p>
						</div>
					</div>

					<div class="trb-support-faq-item">
						<button type="button" class="torobpay-accordion-header" aria-expanded="false">
							<span class="trb-support-faq-label"><?php esc_html_e( 'How do I change items in an order?', 'torobpay-for-woocommerce' ); ?></span>
							<?php torobpay_support_print_dashicon( 'arrow-down-alt2', 'trb-support-faq-chevron' ); ?>
						</button>
						<div class="torobpay-accordion-content">
							<div class="trb-support-note">
								<?php torobpay_support_print_dashicon( 'info' ); ?>
								<p><?php esc_html_e( 'Edit and sync lets you change and sync registered order data with TorobPay. Do not change the entire order status to cancelled.', 'torobpay-for-woocommerce' ); ?></p>
							</div>

							<h4><?php esc_html_e( 'New method (recommended): use the edit and sync window', 'torobpay-for-woocommerce' ); ?></h4>
							<ol>
								<li><?php esc_html_e( 'Open the order page and click "Edit and sync items".', 'torobpay-for-woocommerce' ); ?></li>
								<li><?php esc_html_e( 'In the window that opens, make your changes to order costs.', 'torobpay-for-woocommerce' ); ?></li>
								<li><?php esc_html_e( 'You can also remove or add items; to add a product, search by name, select it from results, and click +.', 'torobpay-for-woocommerce' ); ?></li>
								<li><?php esc_html_e( 'After changes, click "Save and sync". On success, the difference between the original and new order amount is refunded to the customer.', 'torobpay-for-woocommerce' ); ?></li>
							</ol>
							<img src="<?php echo esc_url( torobpay_plugin_asset_url( 'assets/images/support-edit-button.webp' ) ); ?>" alt="<?php echo esc_attr__( 'Edit/sync button on order page', 'torobpay-for-woocommerce' ); ?>">
							<img src="<?php echo esc_url( torobpay_plugin_asset_url( 'assets/images/support-edit-modal.webp' ) ); ?>" alt="<?php echo esc_attr__( 'Order edit/sync window', 'torobpay-for-woocommerce' ); ?>">
							<img src="<?php echo esc_url( torobpay_plugin_asset_url( 'assets/images/support-edit-success.webp' ) ); ?>" alt="<?php echo esc_attr__( 'Confirm order sync', 'torobpay-for-woocommerce' ); ?>">

							<h4><?php esc_html_e( 'Legacy method (still supported): change order status', 'torobpay-for-woocommerce' ); ?></h4>
							<ol>
								<li><?php esc_html_e( 'First set the order status to On hold.', 'torobpay-for-woocommerce' ); ?></li>
								<li><?php esc_html_e( 'Then change the desired product(s).', 'torobpay-for-woocommerce' ); ?></li>
								<li><?php esc_html_e( 'Then set the order status to Processing or Completed.', 'torobpay-for-woocommerce' ); ?></li>
							</ol>
							<p><?php esc_html_e( 'After applying changes you should see the message below in order notes. If you get an error or do not see it, contact Telegram support.', 'torobpay-for-woocommerce' ); ?></p>
							<img src="<?php echo esc_url( torobpay_plugin_asset_url( 'assets/images/support-order-notes.webp' ) ); ?>" alt="<?php echo esc_attr__( 'Change TorobPay order', 'torobpay-for-woocommerce' ); ?>">

							<div class="trb-support-note trb-support-note--warning">
								<?php torobpay_support_print_dashicon( 'warning' ); ?>
								<p><strong><?php esc_html_e( 'Important:', 'torobpay-for-woocommerce' ); ?></strong> <?php esc_html_e( 'In both methods, only changes that reduce the total order amount can be synced. Otherwise your changes will not be recorded in TorobPay.', 'torobpay-for-woocommerce' ); ?></p>
							</div>
						</div>
					</div>

					<div class="trb-support-faq-item">
						<button type="button" class="torobpay-accordion-header" aria-expanded="false">
							<span class="trb-support-faq-label"><?php esc_html_e( 'Where do I review TorobPay orders?', 'torobpay-for-woocommerce' ); ?></span>
							<?php torobpay_support_print_dashicon( 'arrow-down-alt2', 'trb-support-faq-chevron' ); ?>
						</button>
						<div class="torobpay-accordion-content">
							<p><?php esc_html_e( 'From TorobPay > Orders in the menu, you can view TorobPay orders.', 'torobpay-for-woocommerce' ); ?></p>
							<img src="<?php echo esc_url( torobpay_plugin_asset_url( 'assets/images/support-orders-page.webp' ) ); ?>" alt="<?php echo esc_attr__( 'TorobPay orders', 'torobpay-for-woocommerce' ); ?>">
						</div>
					</div>

					<div class="trb-support-faq-item">
						<button type="button" class="torobpay-accordion-header" aria-expanded="false">
							<span class="trb-support-faq-label"><?php esc_html_e( 'How do I cancel an order if I approve the cancellation?', 'torobpay-for-woocommerce' ); ?></span>
							<?php torobpay_support_print_dashicon( 'arrow-down-alt2', 'trb-support-faq-chevron' ); ?>
						</button>
						<div class="torobpay-accordion-content">
							<p><?php esc_html_e( 'Open the order and click TorobPay refund, or set the order status to Cancelled or Refunded.', 'torobpay-for-woocommerce' ); ?></p>
							<img src="<?php echo esc_url( torobpay_plugin_asset_url( 'assets/images/support-cancel-order.webp' ) ); ?>" alt="<?php echo esc_attr__( 'TorobPay order refund', 'torobpay-for-woocommerce' ); ?>">
							<p><?php esc_html_e( 'Or from TorobPay > Orders click Refund. After cancellation you should see the message below in order notes. (Contact Telegram support if you see an error or the message does not appear.)', 'torobpay-for-woocommerce' ); ?></p>
							<img src="<?php echo esc_url( torobpay_plugin_asset_url( 'assets/images/support-cancel-note.webp' ) ); ?>" alt="<?php echo esc_attr__( 'TorobPay order refund', 'torobpay-for-woocommerce' ); ?>">
						</div>
					</div>

					<div class="trb-support-faq-item">
						<button type="button" class="torobpay-accordion-header" aria-expanded="false">
							<span class="trb-support-faq-label"><?php esc_html_e( 'How is the refund amount returned to the customer when an order is changed or cancelled?', 'torobpay-for-woocommerce' ); ?></span>
							<?php torobpay_support_print_dashicon( 'arrow-down-alt2', 'trb-support-faq-chevron' ); ?>
						</button>
						<div class="torobpay-accordion-content">
							<p><?php esc_html_e( 'When an order is changed or cancelled, TorobPay refunds the amount to the customer within 48 hours.', 'torobpay-for-woocommerce' ); ?>
								<?php esc_html_e( 'Refunds must always be processed by TorobPay; the store must not pay the customer directly (even if part or all of the order was settled with the store).', 'torobpay-for-woocommerce' ); ?></p>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div id="trb-lightbox">
		<img id="trb-lightbox-img" src="" alt="">
	</div>
	<?php
}

/**
 * Enqueue accordion/lightbox assets on the TorobPay support admin page.
 *
 * @param string $hook Current admin page hook.
 */
function torobpay_enqueue_support_page_assets( $hook ) {
	$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page slug for asset enqueue.

	// Screen hook is sanitize_title( translated parent menu title ) + '_page_torobpay-support',
	// so it changes with locale (e.g. torobpay_page_* in English). Match the page slug instead.
	if ( 'torobpay-support' !== $current_page && false === strpos( (string) $hook, 'torobpay-support' ) ) {
		return;
	}

	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';

	wp_enqueue_style(
		'torobpay-support-page',
		plugins_url( 'assets/css/admin-support.css', TOROBPAY_PLUGIN_FILE ),
		array( 'dashicons' ),
		$version
	);

	$js = <<<'JS'
jQuery(document).ready(function($) {
	$('.torobpay-accordion-header').on('click', function() {
		var $header = $(this);
		var open = ! $header.hasClass('is-open');
		$header.toggleClass('is-open', open).attr('aria-expanded', open ? 'true' : 'false');
		$header.next('.torobpay-accordion-content').slideToggle();
	});

	var $lightbox = $('#trb-lightbox');
	var $lightboxImg = $('#trb-lightbox-img');

	$(document).on('click', '.torobpay-accordion-content img', function() {
		$lightboxImg.attr('src', this.src).attr('alt', this.alt);
		$lightbox.css('display', 'flex').hide().fadeIn(180);
	});

	$lightbox.on('click', function() {
		$lightbox.fadeOut(150);
	});

	$(document).on('keydown', function(e) {
		if (e.key === 'Escape') { $lightbox.fadeOut(150); }
	});
});
JS;

	wp_register_script( 'torobpay-support-page', false, array( 'jquery' ), $version, true );
	wp_enqueue_script( 'torobpay-support-page' );
	wp_add_inline_script( 'torobpay-support-page', $js );
}
add_action( 'admin_enqueue_scripts', 'torobpay_enqueue_support_page_assets' );
