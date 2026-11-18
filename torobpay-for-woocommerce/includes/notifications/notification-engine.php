<?php
/**
 * TorobPay Notification Center — engine.
 *
 * Collects notification items from registered providers, applies per-user
 * dismissals and derives the badge, severity tier and per-category totals.
 * Rendering lives in notification-render.php, AJAX in notification-ajax.php and
 * the items themselves in providers/.
 *
 * Notification schema:
 *   id             string  stable unique key (e.g. 'update_1.2.3')
 *   type           string  key of torobpay_get_notification_types()
 *   priority       int     lower = shown first (default: 10)
 *   title          string  short headline
 *   body           string  optional HTML body (wp_kses_post applied on output)
 *   action_url     string  primary CTA link
 *   action_label   string  CTA button label
 *   action_new_tab bool    open the CTA in a new tab (optional)
 *   dismissible    bool    whether the user can permanently dismiss this item
 *   badge          int     how many units this item adds to the admin bar badge counter
 *   tier           string  'critical' (red + pulse) | 'notice' (orange, no animation)
 *   icon           string  dashicons class without the 'dashicons-' prefix; defaults
 *                          to the icon registered for the item's type
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register a notification provider callback.
 *
 * The callback receives no arguments and returns an array of notification items.
 *
 * @param  callable $provider Provider callback.
 * @return void
 */
function torobpay_register_notification_provider( $provider ) {
	add_filter(
		'torobpay_notification_providers',
		function ( $providers ) use ( $provider ) {
			$providers[] = $provider;
			return $providers;
		}
	);
}

/**
 * Notification types and how each one is presented.
 *
 * `category` groups the type into one of torobpay_get_notification_categories();
 * `icon` is the default dashicon for items of that type.
 *
 * @return array<string,array{icon:string,category:string}>
 */
function torobpay_get_notification_types() {
	return apply_filters(
		'torobpay_notification_types',
		array(
			'update'              => array(
				'icon'     => 'update',
				'category' => 'update',
			),
			'action_order'        => array(
				'icon'     => 'warning',
				'category' => 'orders',
			),
			'unfinished_edit'     => array(
				'icon'     => 'edit',
				'category' => 'edits',
			),
			'visual_setup'        => array(
				'icon'     => 'admin-appearance',
				'category' => 'settings',
			),
			'auth_error'          => array(
				'icon'     => 'lock',
				'category' => 'settings',
			),
			'eligibility_error'   => array(
				'icon'     => 'warning',
				'category' => 'settings',
			),
			'policy_violation'    => array(
				'icon'     => 'shield',
				'category' => 'settings',
			),
			'missing_credentials' => array(
				'icon'     => 'id',
				'category' => 'settings',
			),
			'news'                => array(
				'icon'     => 'megaphone',
				'category' => 'news',
			),
		)
	);
}

/**
 * User-facing categories shown in the admin-bar submenu and the modal filter.
 *
 * Declaration order is display order.
 *
 * @return array<string,array{label:string,icon:string}>
 */
function torobpay_get_notification_categories() {
	return apply_filters(
		'torobpay_notification_categories',
		array(
			'update'   => array(
				'label' => __( 'Update', 'torobpay-for-woocommerce' ),
				'icon'  => 'update',
			),
			'orders'   => array(
				'label' => __( 'Orders needing action', 'torobpay-for-woocommerce' ),
				'icon'  => 'warning',
			),
			'edits'    => array(
				'label' => __( 'Incomplete edits', 'torobpay-for-woocommerce' ),
				'icon'  => 'edit',
			),
			'settings' => array(
				'label' => __( 'Settings need attention', 'torobpay-for-woocommerce' ),
				'icon'  => 'admin-settings',
			),
			'news'     => array(
				'label' => __( 'News', 'torobpay-for-woocommerce' ),
				'icon'  => 'megaphone',
			),
		)
	);
}

/**
 * Categories with the types belonging to each, derived from the two registries.
 *
 * @return array<string,array{label:string,icon:string,types:string[]}>
 */
function torobpay_get_notification_category_map() {
	$map = array();

	foreach ( torobpay_get_notification_categories() as $cat_id => $category ) {
		$map[ $cat_id ] = array(
			'label' => $category['label'],
			'icon'  => $category['icon'],
			'types' => array(),
		);
	}

	foreach ( torobpay_get_notification_types() as $type => $definition ) {
		$cat_id = isset( $definition['category'] ) ? $definition['category'] : '';
		if ( isset( $map[ $cat_id ] ) ) {
			$map[ $cat_id ]['types'][] = $type;
		}
	}

	return $map;
}

/**
 * Resolve the category id for a notification `type`.
 *
 * @param  string $type Notification type.
 * @return string Category id, or 'other' when no category matches.
 */
function torobpay_get_notification_category_for_type( $type ) {
	$types = torobpay_get_notification_types();

	if ( isset( $types[ $type ]['category'] ) ) {
		return $types[ $type ]['category'];
	}

	return 'other';
}

/**
 * Dashicon suffix for a notification: its own `icon`, else its type's default.
 *
 * @param  array $notification Notification item.
 * @return string
 */
function torobpay_get_notification_icon( $notification ) {
	if ( ! empty( $notification['icon'] ) ) {
		return $notification['icon'];
	}

	$type  = isset( $notification['type'] ) ? $notification['type'] : '';
	$types = torobpay_get_notification_types();

	return isset( $types[ $type ]['icon'] ) ? $types[ $type ]['icon'] : 'bell';
}

/**
 * Collect, sort and filter notifications from all registered providers.
 *
 * @return array Filtered, sorted notification items.
 */
function torobpay_get_notifications() {
	$notifications = torobpay_sort_notifications( torobpay_collect_raw_notifications() );
	$dismissed     = torobpay_get_dismissed_notification_ids();

	return array_values(
		array_filter(
			$notifications,
			function ( $n ) use ( $dismissed ) {
				if ( empty( $n['dismissible'] ) ) {
					return true; // Non-dismissible notifications always show.
				}
				return ! in_array( $n['id'], $dismissed, true );
			}
		)
	);
}

/**
 * Collect the raw, unsorted notification items from every registered provider.
 *
 * The provider callbacks run database queries (orders, options, …), so their
 * combined output is memoized for the duration of the request — the badge,
 * tier, category-stats and panel-body all reuse this single collection instead
 * of re-querying. The result is independent of per-user dismissals (those are
 * applied later by torobpay_get_notifications()), so the cache never needs to
 * be invalidated when a user dismisses or restores an item.
 *
 * @return array Raw notification items (unsorted, un-filtered).
 */
function torobpay_collect_raw_notifications() {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$providers     = apply_filters( 'torobpay_notification_providers', array() );
	$notifications = array();

	foreach ( $providers as $provider ) {
		if ( is_callable( $provider ) ) {
			$items = call_user_func( $provider );
			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					$notifications[] = $item;
				}
			}
		}
	}

	$cache = $notifications;
	return $cache;
}

/**
 * Order notification items by ascending priority.
 *
 * @param  array $notifications Items to sort.
 * @return array
 */
function torobpay_sort_notifications( $notifications ) {
	usort(
		$notifications,
		function ( $a, $b ) {
			$pa = isset( $a['priority'] ) ? (int) $a['priority'] : 10;
			$pb = isset( $b['priority'] ) ? (int) $b['priority'] : 10;
			return $pa - $pb;
		}
	);

	return $notifications;
}

/**
 * Sum the badge contribution of all active (non-dismissed) notifications.
 *
 * @return int
 */
function torobpay_get_notification_badge_count() {
	$count = 0;
	foreach ( torobpay_get_notifications() as $n ) {
		$count += isset( $n['badge'] ) ? (int) $n['badge'] : 0;
	}
	return $count;
}

/**
 * Return the highest-severity tier across all active badge-contributing notifications.
 *
 * @return string 'critical' | 'notice' | ''
 */
function torobpay_get_notification_tier() {
	$tier = '';
	foreach ( torobpay_get_notifications() as $n ) {
		if ( ! isset( $n['badge'] ) || (int) $n['badge'] <= 0 ) {
			continue;
		}
		$n_tier = isset( $n['tier'] ) ? $n['tier'] : 'notice';
		if ( 'critical' === $n_tier ) {
			return 'critical'; // Can't escalate further.
		}
		$tier = 'notice';
	}
	return $tier;
}

/**
 * Per-category badge totals and severity tiers across active notifications.
 *
 * @return array<string,array{count:int,tier:string}> Keyed by category id.
 */
function torobpay_get_notification_category_stats() {
	$stats = array();
	foreach ( array_keys( torobpay_get_notification_categories() ) as $cat_id ) {
		$stats[ $cat_id ] = array(
			'count' => 0,
			'tier'  => '',
		);
	}

	foreach ( torobpay_get_notifications() as $n ) {
		$type   = isset( $n['type'] ) ? $n['type'] : '';
		$cat_id = torobpay_get_notification_category_for_type( $type );
		if ( ! isset( $stats[ $cat_id ] ) ) {
			continue; // 'other' — not surfaced in the categorized UI.
		}

		$badge = isset( $n['badge'] ) ? (int) $n['badge'] : 0;
		$stats[ $cat_id ]['count'] += $badge;

		if ( $badge > 0 ) {
			$n_tier = isset( $n['tier'] ) ? $n['tier'] : 'notice';
			if ( 'critical' === $n_tier ) {
				$stats[ $cat_id ]['tier'] = 'critical';
			} elseif ( '' === $stats[ $cat_id ]['tier'] ) {
				$stats[ $cat_id ]['tier'] = 'notice';
			}
		}
	}

	return $stats;
}

/**
 * Notification ids the current user has dismissed.
 *
 * @return string[]
 */
function torobpay_get_dismissed_notification_ids() {
	$dismissed = get_user_meta( get_current_user_id(), 'torobpay_dismissed_notifications', true );

	return is_array( $dismissed ) ? $dismissed : array();
}

/**
 * Persistently dismiss a notification for the current user.
 *
 * @param  string $id Notification id.
 * @return void
 */
function torobpay_dismiss_notification( $id ) {
	$id = sanitize_text_field( $id );
	if ( empty( $id ) ) {
		return;
	}

	$dismissed = torobpay_get_dismissed_notification_ids();
	if ( ! in_array( $id, $dismissed, true ) ) {
		$dismissed[] = $id;
		update_user_meta( get_current_user_id(), 'torobpay_dismissed_notifications', $dismissed );
	}
}

/**
 * Remove a notification ID from the current user's dismissed list.
 *
 * @param  string $id Notification id.
 * @return void
 */
function torobpay_undismiss_notification( $id ) {
	$id = sanitize_text_field( $id );
	if ( empty( $id ) ) {
		return;
	}

	$dismissed = torobpay_get_dismissed_notification_ids();
	$key       = array_search( $id, $dismissed, true );
	if ( false !== $key ) {
		unset( $dismissed[ $key ] );
		update_user_meta( get_current_user_id(), 'torobpay_dismissed_notifications', array_values( $dismissed ) );
	}
}

/**
 * Return notifications whose underlying condition is still active
 * but that the current user has previously dismissed.
 *
 * @return array
 */
function torobpay_get_dismissed_active_notifications() {
	$dismissed = torobpay_get_dismissed_notification_ids();
	if ( empty( $dismissed ) ) {
		return array();
	}

	return array_values(
		array_filter(
			torobpay_sort_notifications( torobpay_collect_raw_notifications() ),
			function ( $n ) use ( $dismissed ) {
				return ! empty( $n['dismissible'] ) && in_array( $n['id'], $dismissed, true );
			}
		)
	);
}
