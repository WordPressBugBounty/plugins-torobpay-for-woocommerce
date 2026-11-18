<?php
/**
 * TorobPay Notification Center — rendering.
 *
 * The modal shell, the panel body (active + dismissed lists) and the category
 * filter chips. Active and dismissed items share one item partial.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_footer', 'torobpay_render_notification_panel' );
/**
 * Output the notification modal shell in the admin footer.
 *
 * @return void
 */
function torobpay_render_notification_panel() {
	if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$icon_url = plugins_url( 'assets/images/icon.png', TOROBPAY_PLUGIN_FILE );
	?>
	<div id="torobpay-notification-panel-overlay"
		class="torobpay-modal-overlay"
		style="display:none;"
		role="dialog"
		aria-labelledby="torobpay-notif-panel-title"
		aria-modal="true">
		<div class="torobpay-modal torobpay-notification-panel">
			<div class="torobpay-modal-header">
				<h2 id="torobpay-notif-panel-title">
					<img class="torobpay-modal-title-icon"
						src="<?php echo esc_url( $icon_url ); ?>"
						alt=""
						aria-hidden="true" /><?php esc_html_e( 'TorobPay notifications', 'torobpay-for-woocommerce' ); ?></h2>
				<button type="button"
						class="torobpay-modal-close"
						id="torobpay-notif-panel-close"
						title="<?php echo esc_attr__( 'Close', 'torobpay-for-woocommerce' ); ?>">&times;</button>
			</div>
			<div class="torobpay-modal-body">
				<?php torobpay_render_notification_panel_body(); ?>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Render the notification panel body: active items + dismissed-but-active section.
 * Called on page load and also captured for the dismiss/undismiss AJAX responses.
 *
 * @return void
 */
function torobpay_render_notification_panel_body() {
	$notifications    = torobpay_get_notifications();
	$dismissed_active = torobpay_get_dismissed_active_notifications();

	if ( empty( $notifications ) && empty( $dismissed_active ) ) {
		?>
		<div class="torobpay-no-notifications">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<p><?php esc_html_e( 'No new notifications.', 'torobpay-for-woocommerce' ); ?></p>
		</div>
		<?php
		return;
	}

	torobpay_render_notification_filter_bar();

	if ( ! empty( $notifications ) ) {
		foreach ( $notifications as $notification ) {
			torobpay_render_notification_item( $notification );
		}
	} else {
		?>
		<div class="torobpay-no-notifications torobpay-no-active-notifications">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<p><?php esc_html_e( 'No active notifications.', 'torobpay-for-woocommerce' ); ?></p>
		</div>
		<?php
	}

	if ( empty( $dismissed_active ) ) {
		return;
	}
	?>
	<details class="torobpay-dismissed-section">
		<summary class="torobpay-dismissed-summary">
			<span class="dashicons dashicons-hidden" aria-hidden="true"></span><?php esc_html_e( 'Dismissed notifications', 'torobpay-for-woocommerce' ); ?><span class="torobpay-dismissed-count">(<?php echo count( $dismissed_active ); ?>)</span>
		</summary>
		<div class="torobpay-dismissed-list">
			<?php
			foreach ( $dismissed_active as $notification ) {
				torobpay_render_notification_item( $notification, true );
			}
			?>
		</div>
	</details>
	<?php
}

/**
 * Render one notification card.
 *
 * @param  array $notification Notification item.
 * @param  bool  $is_dismissed Render the dismissed variant (restore button, no tier styling).
 * @return void
 */
function torobpay_render_notification_item( $notification, $is_dismissed = false ) {
	$n_id        = isset( $notification['id'] ) ? $notification['id'] : '';
	$n_type      = isset( $notification['type'] ) ? $notification['type'] : '';
	$tier        = isset( $notification['tier'] ) ? $notification['tier'] : 'notice';
	$badge       = isset( $notification['badge'] ) ? (int) $notification['badge'] : 0;
	$dismissible = ! empty( $notification['dismissible'] );
	$icon_suffix = torobpay_get_notification_icon( $notification );
	$n_category  = torobpay_get_notification_category_for_type( $n_type );

	if ( $is_dismissed ) {
		$classes = 'torobpay-notification-item torobpay-promo-notice torobpay-notification-dismissed';
	} else {
		$classes = 'torobpay-notification-item torobpay-promo-notice '
			. ( ( 'critical' === $tier ) ? 'torobpay-promo-notice--error' : 'torobpay-promo-notice--warning' );
	}
	?>
	<div class="<?php echo esc_attr( $classes ); ?>"
		data-notification-id="<?php echo esc_attr( $n_id ); ?>"
		data-category="<?php echo esc_attr( $n_category ); ?>"
		<?php if ( ! $is_dismissed ) : ?>
			data-tier="<?php echo esc_attr( $tier ); ?>"
			data-badge="<?php echo esc_attr( $badge ); ?>"
		<?php endif; ?>
	>
		<span class="dashicons dashicons-<?php echo esc_attr( $icon_suffix ); ?> torobpay-promo-notice-icon"
				aria-hidden="true"></span>
		<div class="torobpay-promo-notice-body torobpay-notification-content">
			<strong class="torobpay-notification-title">
				<?php echo esc_html( $notification['title'] ); ?>
			</strong>
			<?php if ( ! empty( $notification['body'] ) ) : ?>
				<div class="torobpay-notification-body">
					<?php echo wp_kses_post( $notification['body'] ); ?>
				</div>
			<?php endif; ?>
			<?php
			$has_link    = ! empty( $notification['action_url'] ) && ! empty( $notification['action_label'] );
			$has_recheck = ! empty( $notification['recheck'] ) && ! $is_dismissed;
			if ( $has_link || $has_recheck ) :
				?>
				<div class="torobpay-notification-actions">
					<?php if ( $has_link ) : ?>
						<a href="<?php echo esc_url( $notification['action_url'] ); ?>"
							class="torobpay-promo-cta torobpay-notification-action"
							<?php if ( ! empty( $notification['action_new_tab'] ) ) : ?>
								target="_blank" rel="noopener noreferrer"
							<?php endif; ?>
						>
							<?php echo esc_html( $notification['action_label'] ); ?>
							<span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span>
						</a>
					<?php endif; ?>
					<?php if ( $has_recheck ) : ?>
						<button type="button"
								class="torobpay-notification-recheck"
								data-label-busy="<?php echo esc_attr__( 'Checking…', 'torobpay-for-woocommerce' ); ?>">
							<?php esc_html_e( 'Check again', 'torobpay-for-woocommerce' ); ?>
						</button>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php if ( $is_dismissed ) : ?>
			<button type="button"
					class="torobpay-notification-restore"
					title="<?php echo esc_attr__( 'Restore notice', 'torobpay-for-woocommerce' ); ?>">
				<span class="dashicons dashicons-undo" aria-hidden="true"></span>
			</button>
		<?php elseif ( $dismissible ) : ?>
			<button type="button"
					class="torobpay-notification-dismiss torobpay-notice-dismiss"
					title="<?php echo esc_attr__( 'Dismiss', 'torobpay-for-woocommerce' ); ?>">
				<span class="dashicons dashicons-no" aria-hidden="true"></span>
			</button>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render the category filter chips shown at the top of the notification panel.
 *
 * Only categories that currently have active notifications get a chip. The
 * "all" chip is always present and active by default; actual show/hide
 * filtering is handled client-side in admin-notification-center.js.
 *
 * @return void
 */
function torobpay_render_notification_filter_bar() {
	$stats = torobpay_get_notification_category_stats();
	$map   = torobpay_get_notification_categories();

	$total = 0;
	foreach ( $stats as $s ) {
		$total += (int) $s['count'];
	}

	// Nothing to filter when there is at most one populated category.
	$populated = array_filter(
		$stats,
		function ( $s ) {
			return (int) $s['count'] > 0;
		}
	);
	if ( count( $populated ) < 2 ) {
		return;
	}
	?>
	<div class="torobpay-notif-filter-bar" role="tablist">
		<button type="button"
				class="torobpay-notif-filter-chip is-active"
				data-category="all"><?php esc_html_e( 'All', 'torobpay-for-woocommerce' ); ?><span class="torobpay-notif-filter-chip-count"><?php echo (int) $total; ?></span>
		</button>
		<?php
		foreach ( $map as $cat_id => $cat ) :
			$count = isset( $stats[ $cat_id ] ) ? (int) $stats[ $cat_id ]['count'] : 0;
			if ( $count < 1 ) {
				continue;
			}
			$tier     = $stats[ $cat_id ]['tier'];
			$tier_mod = ( 'critical' === $tier ) ? ' torobpay-notif-filter-chip--critical' : '';
			?>
			<button type="button"
					class="torobpay-notif-filter-chip<?php echo esc_attr( $tier_mod ); ?>"
					data-category="<?php echo esc_attr( $cat_id ); ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $cat['icon'] ); ?>" aria-hidden="true"></span>
				<?php echo esc_html( $cat['label'] ); ?>
				<span class="torobpay-notif-filter-chip-count"><?php echo (int) $count; ?></span>
			</button>
			<?php
		endforeach;
		?>
	</div>
	<?php
}
