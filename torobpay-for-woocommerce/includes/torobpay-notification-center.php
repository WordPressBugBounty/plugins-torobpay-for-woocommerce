<?php
/**
 * TorobPay Notification Center — loader.
 *
 * Layout:
 *   notifications/notification-engine.php   collection, dismissals, badge, categories
 *   notifications/notification-render.php   modal, panel body, filter chips
 *   notifications/notification-ajax.php     dismiss, restore, contract recheck
 *   notifications/providers/*.php           one file per notification, auto-loaded
 *   notifications/action-needed-notice.php  companion admin notice
 *
 * A new notification is a new file in providers/ that calls
 * torobpay_register_notification_provider(); nothing here needs to change.
 * A new API error code is one entry in includes/torobpay-error-codes.php.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/torobpay-error-codes.php';
require_once __DIR__ . '/notifications/notification-engine.php';
require_once __DIR__ . '/notifications/notification-render.php';
require_once __DIR__ . '/notifications/notification-ajax.php';

foreach ( glob( __DIR__ . '/notifications/providers/*.php' ) ?: array() as $torobpay_provider_file ) {
	require_once $torobpay_provider_file;
}
unset( $torobpay_provider_file );

require_once __DIR__ . '/notifications/action-needed-notice.php';
