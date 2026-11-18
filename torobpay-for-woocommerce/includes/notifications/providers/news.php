<?php
/**
 * Notification provider: TorobPay news.
 *
 * @todo Implement when the TorobPay news API endpoint is ready.
 *       Expected item shape: type='news', tier='notice', badge=0, dismissible=true.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

torobpay_register_notification_provider( 'torobpay_news_notification_provider' );

/**
 * News items pulled from TorobPay.
 *
 * @return array
 */
function torobpay_news_notification_provider() {
	return array();
}
