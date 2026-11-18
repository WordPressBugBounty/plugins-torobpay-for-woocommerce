<?php
/**
 * Notification provider: disabled TorobPay visual elements.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

torobpay_register_notification_provider( 'torobpay_visual_elements_notification_provider' );

/**
 * Nudge merchants who turned off widget / badge / marquee / top bar / slider.
 *
 * @return array
 */
function torobpay_visual_elements_notification_provider() {
	$elements = array(
		'torobpay_widget_enabled'  => array( __( 'Widget', 'torobpay-for-woocommerce' ), 'yes' ),
		'torobpay_badge_enabled'   => array( __( 'Badge', 'torobpay-for-woocommerce' ), 'yes' ),
		'torobpay_marquee_enabled' => array( __( 'Marquee', 'torobpay-for-woocommerce' ), 'no' ),
		'torobpay_topbar_enabled'  => array( __( 'Top bar', 'torobpay-for-woocommerce' ), 'no' ),
		'torobpay_slider_enabled'  => array( __( 'Slider', 'torobpay-for-woocommerce' ), 'no' ),
	);

	$disabled = array();
	foreach ( $elements as $option => $element ) {
		list( $label, $default ) = $element;
		if ( 'yes' !== get_option( $option, $default ) ) {
			$disabled[] = $label;
		}
	}

	if ( empty( $disabled ) ) {
		return array();
	}

	$tags_html = '';
	foreach ( $disabled as $label ) {
		$tags_html .= '<span class="torobpay-promo-notice-tag">'
			. '<span class="dashicons dashicons-hidden" aria-hidden="true"></span> '
			. esc_html(
				sprintf(
					/* translators: %s: disabled TorobPay UI element name */
					__( '%s is disabled', 'torobpay-for-woocommerce' ),
					$label
				)
			)
			. '</span>';
	}

	return array(
		array(
			'id'           => 'visual_elements_disabled',
			'type'         => 'visual_setup',
			'priority'     => 20,
			'title'        => __( 'TorobPay visual elements', 'torobpay-for-woocommerce' ),
			'body'         => esc_html__( 'Some TorobPay visual elements are disabled. For the full TorobPay experience, we recommend enabling all elements.', 'torobpay-for-woocommerce' )
				. '<div class="torobpay-promo-notice-tags">' . $tags_html . '</div>',
			'action_url'   => admin_url( 'admin.php?page=torobpay-widget' ),
			'action_label' => __( 'Visual settings', 'torobpay-for-woocommerce' ),
			'dismissible'  => true,
			'badge'        => 1,
			'tier'         => 'notice',
		),
	);
}
