<?php
/**
 * TorobPay campaign opt-in page.
 *
 * Merchants opt in so TorobPay can periodically pull paid-order data.
 * Campaign files still come from merchant/campaign-data-result/ (created_at + result_url).
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display the campaign participation page.
 *
 * @return void
 */
function torobpay_campaign_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'torobpay-for-woocommerce' ) );
	}

	$woocommerce_active = class_exists( 'WooCommerce' );
	$notice             = array(
		'type'    => '',
		'message' => '',
	);

	if ( isset( $_POST['torobpay_campaign_save'] ) ) {
		$notice = torobpay_handle_campaign_page_post( $woocommerce_active );
	}

	$notice_type    = $notice['type'];
	$notice_message = $notice['message'];

	$enabled          = torobpay_is_campaign_enabled();
	$campaign_results = array(
		'success' => true,
		'results' => array(),
		'error'   => '',
	);
	$api_error        = '';
	$last_fetch_ts    = 0;

	if ( $enabled ) {
		$campaign_results = torobpay_get_campaign_results();
		$api_error        = torobpay_campaign_results_user_error( $campaign_results );
		$last_fetch_ts    = torobpay_get_last_campaign_fetch();
	}

	?>
	<div class="wrap trb-campaign-page<?php echo $enabled ? ' is-enabled' : ' is-disabled'; ?>">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Join TorobPay campaign', 'torobpay-for-woocommerce' ); ?></h1>

		<?php if ( $notice_message ) : ?>
			<div class="notice notice-<?php echo 'success' === $notice_type ? 'success trb-notice-success' : 'error'; ?> is-dismissible">
				<p><?php echo esc_html( $notice_message ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $api_error ) : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $api_error ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! $woocommerce_active ) : ?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'WooCommerce is not active. WooCommerce must be active to join the campaign.', 'torobpay-for-woocommerce' ); ?></p>
			</div>
		<?php endif; ?>

		<?php
		if ( $enabled ) {
			torobpay_render_campaign_active( $last_fetch_ts, $campaign_results );
		} else {
			torobpay_render_campaign_invite( $woocommerce_active );
		}
		?>
	</div>
	<?php
}

/**
 * Process the campaign opt-in form POST.
 *
 * Opt-in requires WooCommerce. Enabling sharing is consent to the campaign terms.
 * This page is only reachable while this plugin is active, which already requires WooCommerce.
 *
 * @param bool $woocommerce_active Whether WooCommerce is loaded.
 * @return array{type:string,message:string}
 */
function torobpay_handle_campaign_page_post( $woocommerce_active ) {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return array(
			'type'    => 'error',
			'message' => __( 'You do not have permission to access this page.', 'torobpay-for-woocommerce' ),
		);
	}

	if ( ! isset( $_POST['torobpay_campaign_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['torobpay_campaign_nonce'] ) ), 'torobpay_campaign_action' ) ) {
		return array(
			'type'    => 'error',
			'message' => __( 'Security verification failed. Please try again.', 'torobpay-for-woocommerce' ),
		);
	}

	$want_enabled = isset( $_POST['torobpay_campaign_enabled'] );

	if ( $want_enabled && ! $woocommerce_active ) {
		return array(
			'type'    => 'error',
			'message' => __( 'WooCommerce is not active. WooCommerce must be active to join the campaign.', 'torobpay-for-woocommerce' ),
		);
	}

	$pushed = torobpay_push_campaign_status( $want_enabled );
	if ( true !== $pushed ) {
		$remote = torobpay_fetch_order_campaign_enabled();
		if ( null !== $remote ) {
			torobpay_set_campaign_enabled( $remote );
		}

		return array(
			'type'    => 'error',
			'message' => ( is_string( $pushed ) && '' !== $pushed )
				? $pushed
				: torobpay_campaign_status_error_fallback(),
		);
	}

	torobpay_set_campaign_enabled( $want_enabled );

	return array(
		'type'    => '',
		'message' => '',
	);
}

/**
 * Format a campaign timestamp using the site date format (no time).
 *
 * @param int $timestamp Unix timestamp.
 * @return string Empty when the timestamp is not positive.
 */
function torobpay_campaign_format_date( $timestamp ) {
	$timestamp = (int) $timestamp;
	if ( $timestamp <= 0 ) {
		return '';
	}

	$format = get_option( 'date_format', 'Y-m-d' );
	if ( ! is_string( $format ) || '' === $format ) {
		$format = 'Y-m-d';
	}

	if ( function_exists( 'wp_date' ) ) {
		return (string) wp_date( $format, $timestamp );
	}

	return (string) date_i18n( $format, $timestamp );
}

/**
 * Echo a formatted campaign date, or the pending placeholder.
 *
 * @param int $timestamp Unix timestamp.
 * @return void
 */
function torobpay_campaign_echo_date_or_pending( $timestamp ) {
	$formatted = torobpay_campaign_format_date( $timestamp );
	if ( '' !== $formatted ) {
		echo esc_html( $formatted );
		return;
	}

	esc_html_e( 'Not yet', 'torobpay-for-woocommerce' );
}

/**
 * Print a campaign dashicon.
 *
 * @param string $icon Dashicon class without the dashicons- prefix, or a full dashicons-* class.
 * @return void
 */
function torobpay_campaign_print_dashicon( $icon ) {
	$icon = sanitize_html_class( $icon );
	if ( '' === $icon ) {
		return;
	}
	if ( 0 !== strpos( $icon, 'dashicons-' ) ) {
		$icon = 'dashicons-' . $icon;
	}
	echo '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
}

/**
 * Render the not-enabled invitation card.
 *
 * @param bool $woocommerce_active Whether WooCommerce is loaded.
 * @return void
 */
function torobpay_render_campaign_invite( $woocommerce_active ) {
	$arrow = function_exists( 'is_rtl' ) && is_rtl() ? 'arrow-left-alt2' : 'arrow-right-alt2';
	?>
	<div class="trb-campaign-card trb-campaign-invite">
		<div class="trb-campaign-invite-row--main">
			<div class="trb-campaign-hero">
				<div class="trb-campaign-hero-copy">
					<h2 class="trb-campaign-hero-title">
						<?php esc_html_e( 'Special TorobPay credit', 'torobpay-for-woocommerce' ); ?>
						<span class="trb-campaign-hero-accent"><?php esc_html_e( 'for your customers', 'torobpay-for-woocommerce' ); ?></span>
					</h2>
					<p class="trb-campaign-hero-lead">
						<?php esc_html_e( 'TorobPay offers purchase credit to your eligible customers and invites them to buy from your store in installments.', 'torobpay-for-woocommerce' ); ?>
					</p>
				</div>
				<img
					class="trb-campaign-hero-art"
					src="<?php echo esc_url( torobpay_plugin_asset_url( 'assets/images/campaign-hero.webp' ) ); ?>"
					alt=""
					width="280"
					height="280"
				>
			</div>

			<ul class="trb-campaign-features">
				<li class="trb-campaign-feature">
					<?php torobpay_campaign_print_dashicon( 'testimonial' ); ?>
					<span><?php echo esc_html__( "SMS sent by\nTorobPay", 'torobpay-for-woocommerce' ); ?></span>
				</li>
				<li class="trb-campaign-feature">
					<?php torobpay_campaign_print_dashicon( 'tag' ); ?>
					<span><?php echo esc_html__( "No cost\nfor the store", 'torobpay-for-woocommerce' ); ?></span>
				</li>
				<li class="trb-campaign-feature">
					<?php torobpay_campaign_print_dashicon( 'chart-line' ); ?>
					<span><?php echo esc_html__( "Aimed at increasing\nrepeat purchases", 'torobpay-for-woocommerce' ); ?></span>
				</li>
			</ul>
		</div>

		<form method="post" action="" class="trb-campaign-activate-form">
			<?php wp_nonce_field( 'torobpay_campaign_action', 'torobpay_campaign_nonce' ); ?>
			<input type="hidden" name="torobpay_campaign_enabled" value="yes">
			<button
				type="submit"
				name="torobpay_campaign_save"
				value="1"
				class="trb-campaign-activate"
				<?php disabled( ! $woocommerce_active ); ?>
			>
				<?php esc_html_e( 'Activate campaign', 'torobpay-for-woocommerce' ); ?>
				<?php torobpay_campaign_print_dashicon( $arrow ); ?>
			</button>
		</form>

		<div class="trb-campaign-privacy">
			<?php torobpay_campaign_print_dashicon( 'lock' ); ?>
			<p>
				<?php esc_html_e( 'By activating, paid order data is shared with TorobPay to identify eligible customers. This information stays confidential and is used only for this campaign.', 'torobpay-for-woocommerce' ); ?>
			</p>
		</div>
	</div>
	<?php
}

/**
 * Render the enabled campaign status and download list.
 *
 * @param int   $last_fetch_ts    Last successful inbound fetch timestamp.
 * @param array $campaign_results Campaign results from the API.
 * @return void
 */
function torobpay_render_campaign_active( $last_fetch_ts, $campaign_results ) {
	$rows      = array();
	$api_error = torobpay_campaign_results_user_error( $campaign_results );

	if ( ! empty( $campaign_results['results'] ) && is_array( $campaign_results['results'] ) ) {
		foreach ( $campaign_results['results'] as $result ) {
			if ( empty( $result['created_at'] ) && empty( $result['result_url'] ) ) {
				continue;
			}
			$rows[] = $result;
		}
	}
	?>
	<div class="trb-campaign-active">
		<div class="trb-campaign-card">
			<div class="trb-campaign-card-head">
				<span class="trb-campaign-card-icon" aria-hidden="true"><?php torobpay_campaign_print_dashicon( 'yes-alt' ); ?></span>
				<h2><?php esc_html_e( 'Campaign status', 'torobpay-for-woocommerce' ); ?></h2>
			</div>

			<form method="post" action="" class="trb-campaign-active-form">
				<?php wp_nonce_field( 'torobpay_campaign_action', 'torobpay_campaign_nonce' ); ?>
				<div class="trb-campaign-active-banner">
					<div class="trb-campaign-active-banner-row">
						<strong><?php esc_html_e( 'Your campaign is active', 'torobpay-for-woocommerce' ); ?></strong>
						<button
							type="submit"
							name="torobpay_campaign_save"
							value="1"
							class="trb-campaign-switch"
							role="switch"
							aria-checked="true"
							aria-label="<?php echo esc_attr__( 'Disable campaign sharing', 'torobpay-for-woocommerce' ); ?>"
						>
							<span class="trb-campaign-switch-thumb"></span>
						</button>
					</div>
					<p><?php esc_html_e( 'TorobPay identifies eligible customers of your store and notifies them by SMS to receive credit. TorobPay sends these messages free of charge.', 'torobpay-for-woocommerce' ); ?></p>
				</div>
			</form>

			<div class="trb-campaign-meta">
				<div class="trb-campaign-meta-row">
					<span class="trb-campaign-meta-label">
						<?php torobpay_campaign_print_dashicon( 'calendar-alt' ); ?>
						<?php esc_html_e( 'Last information update', 'torobpay-for-woocommerce' ); ?>
					</span>
					<span class="trb-campaign-meta-value"><?php torobpay_campaign_echo_date_or_pending( $last_fetch_ts ); ?></span>
				</div>
			</div>
		</div>

		<div class="trb-campaign-card">
			<div class="trb-campaign-card-head">
				<span class="trb-campaign-card-icon" aria-hidden="true"><?php torobpay_campaign_print_dashicon( 'download' ); ?></span>
				<h2><?php esc_html_e( 'List of customers receiving credit', 'torobpay-for-woocommerce' ); ?></h2>
			</div>
			<p class="trb-campaign-card-lead">
				<?php esc_html_e( 'You can download the list of customers who received TorobPay credit and send them SMS, or use it in your own dedicated campaign.', 'torobpay-for-woocommerce' ); ?>
			</p>

			<?php if ( empty( $rows ) ) : ?>
				<?php if ( $api_error ) : ?>
					<p class="trb-campaign-empty trb-campaign-empty--error"><?php echo esc_html( $api_error ); ?></p>
				<?php else : ?>
					<p class="trb-campaign-empty"><?php esc_html_e( 'No campaign files yet.', 'torobpay-for-woocommerce' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<ul class="trb-campaign-downloads">
					<?php foreach ( $rows as $result ) : ?>
						<?php
						$item_ts   = ! empty( $result['created_at'] ) ? (int) strtotime( (string) $result['created_at'] ) : 0;
						$item_date = torobpay_campaign_format_date( $item_ts );
						if ( '' === $item_date ) {
							$item_date = '—';
						}
						?>
						<li class="trb-campaign-download-row">
							<?php
							$download_url = ! empty( $result['result_url'] )
								? torobpay_sanitize_campaign_result_url( $result['result_url'] )
								: '';
							?>
							<?php if ( '' !== $download_url ) : ?>
								<a href="<?php echo esc_url( $download_url ); ?>" download class="trb-campaign-download-btn">
									<?php torobpay_campaign_print_dashicon( 'download' ); ?>
									<?php esc_html_e( 'Download list', 'torobpay-for-woocommerce' ); ?>
								</a>
							<?php else : ?>
								<span class="trb-campaign-processing"><?php esc_html_e( 'The campaign file is being prepared…', 'torobpay-for-woocommerce' ); ?></span>
							<?php endif; ?>
							<span class="trb-campaign-download-date"><?php echo esc_html( $item_date ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
