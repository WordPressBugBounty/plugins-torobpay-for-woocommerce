<?php

/**
 * TorobPay Dashboard Widget
 *
 * Registers a WordPress admin dashboard widget with plugin usage help and tips.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the TorobPay dashboard widget.
 */
add_action( 'wp_dashboard_setup', 'torobpay_register_dashboard_widget' );
function torobpay_register_dashboard_widget() {
	wp_add_dashboard_widget(
		'torobpay_dashboard_widget',
		__( 'TorobPay plugin guide', 'torobpay-for-woocommerce' ),
		'torobpay_dashboard_widget_content'
	);
}

/**
 * Remember to place the widget once for each merchant after this activation.
 *
 * Plugin updates do not run activation, so an existing dashboard stays put.
 */
add_action( 'torobpay_wc_gateway_activated', 'torobpay_mark_dashboard_widget_pin_pending' );
function torobpay_mark_dashboard_widget_pin_pending() {
	update_option( 'torobpay_dashboard_widget_pin', '1', false );
}

/**
 * Place the widget first in each merchant's layout once after activation.
 *
 * Merchants who already arranged the dashboard get the widget prepended.
 * The rest of that order is kept, and a later drag is left alone.
 */
add_action( 'wp_dashboard_setup', 'torobpay_pin_dashboard_widget_once', 20 );
function torobpay_pin_dashboard_widget_once() {
	if ( ! get_option( 'torobpay_dashboard_widget_pin' ) || ! torobpay_is_site_dashboard() ) {
		return;
	}

	$user_id = get_current_user_id();
	if ( $user_id <= 0 || get_user_meta( $user_id, 'torobpay_dashboard_widget_pinned', true ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$order   = get_user_option( 'meta-box-order_dashboard', $user_id );
	$updated = torobpay_order_with_widget_prepended( $order, 'torobpay_dashboard_widget' );
	if ( null !== $updated ) {
		$written = update_user_meta( $user_id, 'meta-box-order_dashboard', $updated );
		if ( ! $written ) {
			return;
		}
	}

	update_user_meta( $user_id, 'torobpay_dashboard_widget_pinned', '1' );
	clean_user_cache( $user_id );
}

/**
 * Whether the current admin screen is the site dashboard.
 *
 * @return bool
 */
function torobpay_is_site_dashboard() {
	$screen = get_current_screen();

	return is_object( $screen ) && isset( $screen->id ) && 'dashboard' === $screen->id;
}

/**
 * Copy a saved layout with the widget at the start of the main column.
 *
 * @param mixed  $order     Saved meta-box-order_dashboard value.
 * @param string $widget_id Widget id.
 * @return array|null Updated order, or null when there is nothing to change.
 */
function torobpay_order_with_widget_prepended( $order, $widget_id ) {
	if ( ! is_array( $order ) || ! torobpay_dashboard_order_has_widgets( $order ) ) {
		return null;
	}

	if ( torobpay_dashboard_order_contains_widget( $order, $widget_id ) ) {
		return null;
	}

	$normal = ( isset( $order['normal'] ) && is_string( $order['normal'] ) ) ? $order['normal'] : '';
	$ids    = array();
	foreach ( explode( ',', $normal ) as $id ) {
		$id = trim( $id );
		if ( '' !== $id ) {
			$ids[] = $id;
		}
	}

	array_unshift( $ids, $widget_id );
	$order['normal'] = implode( ',', $ids );

	return $order;
}

/**
 * Whether a saved dashboard order lists the widget.
 *
 * @param mixed  $order     Saved meta-box-order_dashboard value.
 * @param string $widget_id Widget id.
 * @return bool
 */
function torobpay_dashboard_order_contains_widget( $order, $widget_id ) {
	if ( ! is_array( $order ) ) {
		return false;
	}

	foreach ( $order as $ids ) {
		if ( ! is_string( $ids ) || '' === $ids ) {
			continue;
		}

		foreach ( explode( ',', $ids ) as $id ) {
			if ( $widget_id === trim( $id ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Place the widget first on a dashboard that has no saved layout.
 *
 * WooCommerce registers its widgets at "high", which renders above the default
 * "core" list. A saved layout is left untouched here; fresh activations insert
 * the widget into that layout once in torobpay_pin_dashboard_widget_once().
 */
add_action( 'wp_dashboard_setup', 'torobpay_prioritize_dashboard_widget', 1000 );
function torobpay_prioritize_dashboard_widget() {
	global $wp_meta_boxes;

	$widget_id = 'torobpay_dashboard_widget';

	if ( torobpay_user_has_saved_dashboard_order() ) {
		return;
	}

	$box = torobpay_pull_dashboard_widget( $widget_id );
	if ( null === $box ) {
		return;
	}

	if ( ! isset( $wp_meta_boxes['dashboard']['normal']['high'] ) || ! is_array( $wp_meta_boxes['dashboard']['normal']['high'] ) ) {
		$wp_meta_boxes['dashboard']['normal']['high'] = array();
	}

	$wp_meta_boxes['dashboard']['normal']['high'] = array_merge(
		array( $widget_id => $box ),
		$wp_meta_boxes['dashboard']['normal']['high']
	);
}

/**
 * Whether the current user has arranged dashboard widgets.
 *
 * @return bool
 */
function torobpay_user_has_saved_dashboard_order() {
	return torobpay_dashboard_order_has_widgets( get_user_option( 'meta-box-order_dashboard' ) );
}

/**
 * Whether a saved dashboard order contains any widget id.
 *
 * @param mixed $order Saved meta-box-order_dashboard value.
 * @return bool
 */
function torobpay_dashboard_order_has_widgets( $order ) {
	if ( ! is_array( $order ) ) {
		return false;
	}

	foreach ( $order as $ids ) {
		if ( ! is_string( $ids ) || '' === $ids ) {
			continue;
		}

		foreach ( explode( ',', $ids ) as $id ) {
			if ( '' !== trim( $id ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Remove the TorobPay widget from the normal dashboard column.
 *
 * @param string $widget_id Widget id.
 * @return array|null Widget definition, or null when it is not registered.
 */
function torobpay_pull_dashboard_widget( $widget_id ) {
	global $wp_meta_boxes;

	if ( empty( $wp_meta_boxes['dashboard']['normal'] ) || ! is_array( $wp_meta_boxes['dashboard']['normal'] ) ) {
		return null;
	}

	foreach ( $wp_meta_boxes['dashboard']['normal'] as $priority => $boxes ) {
		if ( ! is_array( $boxes ) || ! isset( $boxes[ $widget_id ] ) ) {
			continue;
		}

		$box = $boxes[ $widget_id ];
		unset( $wp_meta_boxes['dashboard']['normal'][ $priority ][ $widget_id ] );

		return $box;
	}

	return null;
}

/**
 * Render the dashboard widget content.
 */
function torobpay_dashboard_widget_content() {
	$plugin_url   = plugin_dir_url( TOROBPAY_PLUGIN_FILE );
	$widget_url   = esc_url( admin_url( 'admin.php?page=torobpay-widget' ) );
	$slider_url   = esc_url( admin_url( 'admin.php?page=torobpay-widget#slider-settings' ) );
	$campaign_url = esc_url( admin_url( 'admin.php?page=torobpay-campaign' ) );
	?>
	<div class="torobpay-dw-wrap">

		<div class="torobpay-dw-carousel" id="torobpay-dw-carousel">

			<!-- Track -->
			<div class="torobpay-dw-track" id="torobpay-dw-track">

				<!-- Slide 1: Credit campaign -->
				<div class="torobpay-dw-slide">

					<img src="<?php echo esc_url( torobpay_plugin_asset_url( 'assets/images/campaign.webp' ) ); ?>"
						alt=""
						class="torobpay-dw-banner"
						width="800"
						height="355"
						aria-hidden="true">

					<div class="torobpay-dw-intro">
						<img src="<?php echo esc_url( $plugin_url . 'assets/images/logo_favicon.svg' ); ?>"
							alt="TorobPay"
							onerror="this.style.display='none'">
						<p>
							<strong><span class="dashicons dashicons-megaphone" aria-hidden="true"></span><?php esc_html_e( 'Join TorobPay campaign', 'torobpay-for-woocommerce' ); ?></strong><br>
							<?php esc_html_e( 'TorobPay sends SMS to customers who are eligible to receive credit. TorobPay covers the cost of these messages.', 'torobpay-for-woocommerce' ); ?>
						</p>
					</div>

					<hr class="torobpay-dw-divider">

					<div class="torobpay-dw-feature">
						<span class="dashicons dashicons-email-alt"></span>
						<div>
							<strong><?php esc_html_e( 'Free SMS from TorobPay', 'torobpay-for-woocommerce' ); ?></strong>
							<p><?php esc_html_e( 'TorobPay sends dedicated SMS to your customers at no cost, and encourages them to place orders on your site.', 'torobpay-for-woocommerce' ); ?></p>
						</div>
					</div>

					<div class="torobpay-dw-feature">
						<span class="dashicons dashicons-share"></span>
						<div>
							<strong><?php esc_html_e( 'Enable campaign data sharing', 'torobpay-for-woocommerce' ); ?></strong>
							<p><?php esc_html_e( 'TorobPay periodically fetches paid orders from your store. Refunded and cancelled orders are not included.', 'torobpay-for-woocommerce' ); ?></p>
						</div>
					</div>

					<div class="torobpay-dw-feature">
						<span class="dashicons dashicons-download"></span>
						<div>
							<strong><?php esc_html_e( 'Campaign files', 'torobpay-for-woocommerce' ); ?></strong>
							<p><?php esc_html_e( 'Download campaign files when they are ready.', 'torobpay-for-woocommerce' ); ?>
								<?php esc_html_e( 'Enable it from', 'torobpay-for-woocommerce' ); ?> <a href="<?php echo esc_url( $campaign_url ); ?>"><?php esc_html_e( 'Join campaign', 'torobpay-for-woocommerce' ); ?></a>.</p>
						</div>
					</div>

				</div><!-- /slide campaign -->

				<!-- Slide 2: Promo slider -->
				<div class="torobpay-dw-slide">

					<img src="<?php echo esc_url( torobpay_plugin_asset_url( 'assets/images/slider.webp' ) ); ?>"
						alt=""
						class="torobpay-dw-banner"
						aria-hidden="true">

					<div class="torobpay-dw-intro">
						<img src="<?php echo esc_url( $plugin_url . 'assets/images/logo_favicon.svg' ); ?>"
							alt="TorobPay"
							onerror="this.style.display='none'">
						<p>
							<strong><span class="dashicons dashicons-images-alt2" aria-hidden="true"></span><?php esc_html_e( 'TorobPay promo slider', 'torobpay-for-woocommerce' ); ?></strong><br>
							<?php esc_html_e( 'Promo slider to inform shoppers about installment purchases; the TorobPay slide and your custom slides can appear in several store locations (via hook selection) or anywhere via shortcode.', 'torobpay-for-woocommerce' ); ?>
							<?php esc_html_e( 'You can also embed one or more slides in other slider plugins such as Revolution Slider via shortcode.', 'torobpay-for-woocommerce' ); ?>
						</p>
					</div>

					<hr class="torobpay-dw-divider">

					<div class="torobpay-dw-feature">
						<span class="dashicons dashicons-format-gallery"></span>
						<div>
							<strong><?php esc_html_e( 'TorobPay slide + custom slides', 'torobpay-for-woocommerce' ); ?></strong>
							<p><?php esc_html_e( 'The TorobPay slide is always shown; you can add more slides with text, buttons, and animated backgrounds.', 'torobpay-for-woocommerce' ); ?></p>
						</div>
					</div>

					<div class="torobpay-dw-feature">
						<span class="dashicons dashicons-location"></span>
						<div>
							<strong><?php esc_html_e( 'Multiple display locations and shortcode', 'torobpay-for-woocommerce' ); ?></strong>
							<p><?php esc_html_e( 'Place the slider on WooCommerce hooks (e.g. above the shop) or using', 'torobpay-for-woocommerce' ); ?> <code dir="ltr">[torobpay_slider]</code> <?php esc_html_e( 'and', 'torobpay-for-woocommerce' ); ?> <code dir="ltr">[torobpay_slide]</code> <?php esc_html_e( 'on any page.', 'torobpay-for-woocommerce' ); ?></p>
						</div>
					</div>

					<div class="torobpay-dw-feature">
						<span class="dashicons dashicons-controls-play"></span>
						<div>
							<strong><?php esc_html_e( 'Autoplay and full control', 'torobpay-for-woocommerce' ); ?></strong>
							<p><?php esc_html_e( 'Autoplay, pause on hover, arrows, and dots are configurable from', 'torobpay-for-woocommerce' ); ?>
								<a href="<?php echo esc_url( $slider_url ); ?>"><?php esc_html_e( 'Slider settings', 'torobpay-for-woocommerce' ); ?></a><?php esc_html_e( '; separate display for desktop, tablet, and mobile is also supported.', 'torobpay-for-woocommerce' ); ?></p>
						</div>
					</div>

				</div><!-- /slide promo slider -->

				<!-- Slide 3: Visual elements -->
				<div class="torobpay-dw-slide">

					<img src="<?php echo esc_url( torobpay_plugin_asset_url( 'assets/images/widget-banner.webp' ) ); ?>"
						alt=""
						class="torobpay-dw-banner"
						aria-hidden="true">

					<div class="torobpay-dw-intro">
						<img src="<?php echo esc_url( $plugin_url . 'assets/images/logo_favicon.svg' ); ?>"
							alt="TorobPay"
							onerror="this.style.display='none'">
						<p>
							<?php esc_html_e( 'With the', 'torobpay-for-woocommerce' ); ?> <strong><?php esc_html_e( 'TorobPay', 'torobpay-for-woocommerce' ); ?></strong> <?php esc_html_e( 'plugin you can enable installment payments and with the visual elements below, inform shoppers and increase your sales.', 'torobpay-for-woocommerce' ); ?>
						</p>
					</div>

					<hr class="torobpay-dw-divider">

					<!-- Badge -->
					<div class="torobpay-dw-feature">
						<span class="dashicons dashicons-awards"></span>
						<div>
							<strong><?php esc_html_e( 'Badge (label)', 'torobpay-for-woocommerce' ); ?></strong>
							<p><?php esc_html_e( 'A small label on the product image showing the item is available for installment purchase.', 'torobpay-for-woocommerce' ); ?>
								<?php esc_html_e( 'Enable it from', 'torobpay-for-woocommerce' ); ?> <a href="<?php echo esc_url( $widget_url ); ?>#badge-settings"><?php esc_html_e( 'Badge settings', 'torobpay-for-woocommerce' ); ?></a>.</p>
						</div>
					</div>

					<!-- Marquee -->
					<div class="torobpay-dw-feature">
						<span class="dashicons dashicons-megaphone"></span>
						<div>
							<strong><?php esc_html_e( 'Marquee', 'torobpay-for-woocommerce' ); ?></strong>
							<p><?php esc_html_e( 'A scrolling bar on the page that tells shoppers about installment payment.', 'torobpay-for-woocommerce' ); ?>
								<?php esc_html_e( 'Configure it in', 'torobpay-for-woocommerce' ); ?> <a href="<?php echo esc_url( $widget_url ); ?>#marquee-settings"><?php esc_html_e( 'Marquee settings', 'torobpay-for-woocommerce' ); ?></a>.</p>
						</div>
					</div>

					<!-- Widget -->
					<div class="torobpay-dw-feature">
						<span class="dashicons dashicons-layout"></span>
						<div>
							<strong><?php esc_html_e( 'Product widget', 'torobpay-for-woocommerce' ); ?></strong>
							<p><?php esc_html_e( 'A box on the product page showing installment details to shoppers and improving conversion. It can also display TorobPay\'s special discount.', 'torobpay-for-woocommerce' ); ?>
								<?php esc_html_e( 'Configure it from', 'torobpay-for-woocommerce' ); ?> <a href="<?php echo esc_url( $widget_url ); ?>#widget-settings"><?php esc_html_e( 'Widget settings', 'torobpay-for-woocommerce' ); ?></a>.</p>
						</div>
					</div>

					<!-- Slider -->
					<div class="torobpay-dw-feature">
						<span class="dashicons dashicons-images-alt2"></span>
						<div>
							<strong><?php esc_html_e( 'Promo slider', 'torobpay-for-woocommerce' ); ?></strong>
							<p><?php esc_html_e( 'A customizable promo slider with text and images on store pages that draws shopper attention.', 'torobpay-for-woocommerce' ); ?>
								<?php esc_html_e( 'Enable it from', 'torobpay-for-woocommerce' ); ?> <a href="<?php echo esc_url( $widget_url ); ?>#slider-settings"><?php esc_html_e( 'Slider settings', 'torobpay-for-woocommerce' ); ?></a>.</p>
						</div>
					</div>

					<!-- Topbar -->
					<div class="torobpay-dw-feature">
						<span class="dashicons dashicons-arrow-up-alt2"></span>
						<div>
							<strong><?php esc_html_e( 'Top page bar', 'torobpay-for-woocommerce' ); ?></strong>
							<p><?php esc_html_e( 'A promo bar at the top of the site with rotating text informing visitors about installment purchases.', 'torobpay-for-woocommerce' ); ?>
								<?php esc_html_e( 'Enable it from', 'torobpay-for-woocommerce' ); ?> <a href="<?php echo esc_url( $widget_url ); ?>#topbar-settings"><?php esc_html_e( 'Top bar settings', 'torobpay-for-woocommerce' ); ?></a>.</p>
						</div>
					</div>

				</div><!-- /slide visual elements -->

				<!-- Slide 4: Edit Order Items Feature -->
				<div class="torobpay-dw-slide">

					<img src="<?php echo esc_url( torobpay_plugin_asset_url( 'assets/images/update-banner.webp' ) ); ?>"
						alt=""
						class="torobpay-dw-banner"
						aria-hidden="true">

					<div class="torobpay-dw-intro">
						<p>
							<strong><span class="dashicons dashicons-update" aria-hidden="true"></span><?php esc_html_e( 'Edit order items', 'torobpay-for-woocommerce' ); ?></strong><br>
							<?php esc_html_e( 'You can now edit installment order items directly from the dashboard.', 'torobpay-for-woocommerce' ); ?>
							<?php esc_html_e( 'Change quantities, prices, and products and sync automatically with TorobPay.', 'torobpay-for-woocommerce' ); ?>
						</p>
					</div>

					<hr class="torobpay-dw-divider">

					<!-- Edit Items Feature -->
					<div class="torobpay-dw-feature">
						<span class="dashicons dashicons-edit"></span>
						<div>
							<strong><?php esc_html_e( 'Order item edit window', 'torobpay-for-woocommerce' ); ?></strong>
							<p><?php esc_html_e( 'Change product quantities, add or remove items, and adjust discount and tax in one integrated operation without manually changing order status.', 'torobpay-for-woocommerce' ); ?></p>
						</div>
					</div>

					<!-- Sync Feature -->
					<div class="torobpay-dw-feature">
						<span class="dashicons dashicons-update"></span>
						<div>
							<strong><?php esc_html_e( 'Automatic sync', 'torobpay-for-woocommerce' ); ?></strong>
							<p><?php esc_html_e( 'All changes sync automatically with TorobPay after saving — click "Edit and sync items" on the order page or TorobPay orders page.', 'torobpay-for-woocommerce' ); ?></p>
						</div>
					</div>

				</div><!-- /slide edit order items -->

			</div><!-- /track -->

			<!-- Navigation -->
			<div class="torobpay-dw-nav">
				<button class="torobpay-dw-nav-arrow" id="torobpay-dw-prev" aria-label="<?php esc_attr_e( 'Previous', 'torobpay-for-woocommerce' ); ?>">
					<span class="dashicons dashicons-arrow-right-alt2"></span>
				</button>
				<!-- Dots are generated automatically by JS based on .torobpay-dw-slide count -->
				<div class="torobpay-dw-dots" id="torobpay-dw-dots"></div>
				<button class="torobpay-dw-nav-arrow" id="torobpay-dw-next" aria-label="<?php esc_attr_e( 'Next', 'torobpay-for-woocommerce' ); ?>">
					<span class="dashicons dashicons-arrow-left-alt2"></span>
				</button>
			</div>

		</div><!-- /carousel -->

	</div>
	<?php
	$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';
	$js      = <<<'JS'
(function () {
	var __ = ( window.wp && wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function( s ) { return s; };
	var carousel = document.getElementById('torobpay-dw-carousel');
	var track    = document.getElementById('torobpay-dw-track');
	var dotsWrap = document.getElementById('torobpay-dw-dots');

	// Auto-detect slides — just add a new .torobpay-dw-slide div and it works ;)
	var slides  = carousel.querySelectorAll('.torobpay-dw-slide');
	var total   = slides.length;
	var current = 0;
	var autoplayMs = 14000;
	var timer = null;
	var paused = false;

	slides.forEach(function (_, i) {
		var btn = document.createElement('button');
		btn.className = 'torobpay-dw-dot' + (i === 0 ? ' is-active' : '');
		btn.setAttribute('aria-label', __( 'Slide ', 'torobpay-for-woocommerce' ) + (i + 1));
		btn.addEventListener('click', function () { goTo(i, true); });
		dotsWrap.appendChild(btn);
	});

	function goTo(index, userInitiated) {
		current = (index + total) % total;
		track.style.transform = 'translateX(' + (current * 100) + '%)';
		dotsWrap.querySelectorAll('.torobpay-dw-dot').forEach(function (d, i) {
			d.classList.toggle('is-active', i === current);
		});
		if (userInitiated) {
			resetAutoplay();
		}
	}

	function resetAutoplay() {
		if (timer) {
			clearInterval(timer);
			timer = null;
		}
		if (total < 2 || paused) {
			return;
		}
		timer = setInterval(function () {
			goTo(current + 1, false);
		}, autoplayMs);
	}

	document.getElementById('torobpay-dw-next').addEventListener('click', function () { goTo(current + 1, true); });
	document.getElementById('torobpay-dw-prev').addEventListener('click', function () { goTo(current - 1, true); });

	carousel.addEventListener('mouseenter', function () {
		paused = true;
		if (timer) {
			clearInterval(timer);
			timer = null;
		}
	});
	carousel.addEventListener('mouseleave', function () {
		paused = false;
		resetAutoplay();
	});
	carousel.addEventListener('focusin', function () {
		paused = true;
		if (timer) {
			clearInterval(timer);
			timer = null;
		}
	});
	carousel.addEventListener('focusout', function () {
		if (!carousel.contains(document.activeElement)) {
			paused = false;
			resetAutoplay();
		}
	});

	resetAutoplay();
})();
JS;

	wp_register_script( 'torobpay-dashboard-widget-carousel', '', array( 'wp-i18n' ), $version, true );
	wp_enqueue_script( 'torobpay-dashboard-widget-carousel' );
	wp_add_inline_script( 'torobpay-dashboard-widget-carousel', $js );
	wp_print_scripts( 'torobpay-dashboard-widget-carousel' );
}
