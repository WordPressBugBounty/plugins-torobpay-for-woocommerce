<?php
/**
 * TorobPay special discount campaign shown on storefront elements.
 *
 * Campaign data (code, discount terms, display texts) comes from the
 * api/online/discount/v1/campaigns endpoint via torobpay-discount-campaigns.php.
 * Local settings only control where it shows (widget, top bar) and the widget row style.
 *
 * Display-only: never applied to the WooCommerce cart.
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default promo settings.
 *
 * `widget` replaced the legacy `enabled` key so stores that still have
 * `enabled => no` from the old off-by-default pick up the on default.
 *
 * @return array{widget:string,topbar:string,style:string}
 */
function torobpay_pdp_promo_defaults() {
	return array(
		'widget' => 'yes',
		'topbar' => 'yes',
		'style'  => 'marquee',
	);
}

/**
 * Stored promo settings, sanitized.
 *
 * @return array{widget:string,topbar:string,style:string}
 */
function torobpay_get_pdp_promo_settings() {
	$stored = get_option( 'torobpay_pdp_promo', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	return torobpay_sanitize_pdp_promo_settings( $stored );
}

/**
 * Sanitize a promo settings array. Only visibility and style are local;
 * campaign details always come from the API.
 *
 * Legacy `enabled` is ignored on purpose — that key defaulted to off.
 *
 * @param array $input Raw settings.
 * @return array{widget:string,topbar:string,style:string}
 */
function torobpay_sanitize_pdp_promo_settings( $input ) {
	$defaults = torobpay_pdp_promo_defaults();
	if ( ! is_array( $input ) ) {
		return $defaults;
	}

	$widget = $defaults['widget'];
	if ( array_key_exists( 'widget', $input ) ) {
		$widget = ( 'yes' === $input['widget'] ) ? 'yes' : 'no';
	}
	$topbar = $defaults['topbar'];
	if ( array_key_exists( 'topbar', $input ) ) {
		$topbar = ( 'yes' === $input['topbar'] ) ? 'yes' : 'no';
	}
	$style = isset( $input['style'] ) ? sanitize_key( (string) $input['style'] ) : 'marquee';
	if ( ! in_array( $style, array( 'marquee', 'simple' ), true ) ) {
		$style = 'marquee';
	}

	return array(
		'widget' => $widget,
		'topbar' => $topbar,
		'style'  => $style,
	);
}

/**
 * Persist promo settings.
 *
 * @param array $settings Settings array.
 * @return void
 */
function torobpay_set_pdp_promo_settings( $settings ) {
	update_option( 'torobpay_pdp_promo', torobpay_sanitize_pdp_promo_settings( $settings ), false );
}

/**
 * The active discount campaign for storefront display, or null.
 *
 * @return array|null
 */
function torobpay_get_pdp_promo_campaign() {
	if ( ! function_exists( 'torobpay_get_active_discount_campaign' ) ) {
		return null;
	}

	return torobpay_get_active_discount_campaign();
}

/**
 * Whether the promo should render on the storefront.
 *
 * Requires the local toggle plus a live campaign from the API.
 *
 * @param array|null $settings Optional preloaded settings.
 * @return bool
 */
function torobpay_is_pdp_promo_active( $settings = null ) {
	if ( null === $settings ) {
		$settings = torobpay_get_pdp_promo_settings();
	}

	if ( empty( $settings['widget'] ) || 'yes' !== $settings['widget'] ) {
		return false;
	}

	return null !== torobpay_get_pdp_promo_campaign();
}

/**
 * Whether the top bar should include the campaign slide.
 *
 * Requires the top-bar switch plus a live campaign. The top bar itself
 * still has to be rendered.
 *
 * @param array|null $settings Optional preloaded settings.
 * @return bool
 */
function torobpay_is_pdp_promo_topbar_active( $settings = null ) {
	if ( null === $settings ) {
		$settings = torobpay_get_pdp_promo_settings();
	}

	if ( empty( $settings['topbar'] ) || 'yes' !== $settings['topbar'] ) {
		return false;
	}

	return null !== torobpay_get_pdp_promo_campaign();
}

/**
 * Whether the top bar should omit its merchant custom sentence.
 *
 * True only while a live discount campaign is shown on the top bar (the
 * top-bar switch is on). Widget-only campaigns keep the custom sentence.
 * It is not re-added client-side after expiry; the next page load brings it back.
 *
 * @param array|null $settings Optional preloaded settings.
 * @return bool
 */
function torobpay_pdp_promo_hides_topbar_custom_sentence( $settings = null ) {
	return torobpay_is_pdp_promo_topbar_active( $settings );
}

/**
 * Whether either storefront surface should show the discount dialog.
 *
 * @param array|null $settings Optional preloaded settings.
 * @return bool
 */
function torobpay_pdp_promo_dialog_active( $settings = null ) {
	if ( null === $settings ) {
		$settings = torobpay_get_pdp_promo_settings();
	}

	$widget_on = isset( $settings['widget'] ) && 'yes' === $settings['widget'];
	$topbar_on = isset( $settings['topbar'] ) && 'yes' === $settings['topbar'];
	if ( ! $widget_on && ! $topbar_on ) {
		return false;
	}

	return null !== torobpay_get_pdp_promo_campaign();
}

/**
 * Save promo fields from the visual settings POST.
 *
 * @return void
 */
function torobpay_save_pdp_promo_from_post() {
	torobpay_set_pdp_promo_settings(
		array(
			'widget' => isset( $_POST['pdp_promo_widget'] ) ? 'yes' : 'no', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verifies the page nonce.
			'topbar' => isset( $_POST['pdp_promo_topbar'] ) ? 'yes' : 'no', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verifies the page nonce.
			'style'  => isset( $_POST['pdp_promo_style'] ) ? wp_unslash( $_POST['pdp_promo_style'] ) : 'marquee', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in torobpay_sanitize_pdp_promo_settings().
		)
	);
}

/**
 * Reset promo settings to defaults.
 *
 * @return void
 */
function torobpay_reset_pdp_promo_settings() {
	torobpay_set_pdp_promo_settings( torobpay_pdp_promo_defaults() );
}

/**
 * Format a numeric value for compact display (drop trailing zeros).
 *
 * @param float $value Number.
 * @return string
 */
function torobpay_pdp_promo_format_number( $value ) {
	$value = (float) $value;
	if ( abs( $value - round( $value ) ) < 0.001 ) {
		return (string) (int) round( $value );
	}

	return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
}

/**
 * Convert an API amount (Iranian rial) into the store currency.
 *
 * @param float $irr Amount in rial.
 * @return float
 */
function torobpay_pdp_promo_store_amount( $irr ) {
	$factor = function_exists( 'torobpay_get_currency_amount_factor' ) ? (float) torobpay_get_currency_amount_factor() : 10.0;
	$factor = $factor > 0 ? $factor : 10.0;

	return (float) $irr / $factor;
}

/**
 * Price HTML for a rial amount in the store currency.
 *
 * @param float $irr Amount in rial.
 * @return string Escaped HTML.
 */
function torobpay_pdp_promo_price_html( $irr ) {
	$store_amount = torobpay_pdp_promo_store_amount( $irr );
	if ( function_exists( 'wc_price' ) ) {
		return wp_kses( wc_price( $store_amount ), torobpay_kses_allowed_html() );
	}

	return esc_html( torobpay_pdp_promo_format_number( $store_amount ) );
}

/**
 * Discount heading row for the lightbox. Prefers the API-provided text.
 *
 * @param array $campaign Sanitized campaign.
 * @return string Escaped HTML or empty.
 */
function torobpay_pdp_promo_amount_html( $campaign ) {
	if ( ! empty( $campaign['amount_text'] ) ) {
		return esc_html( $campaign['amount_text'] ) . ' ' . esc_html__( 'TorobPay', 'torobpay-for-woocommerce' );
	}

	if ( 'percent' === $campaign['discount_type'] ) {
		if ( $campaign['discount_percentage'] <= 0 ) {
			return '';
		}

		return sprintf(
			/* translators: %s: percentage number without the percent sign */
			esc_html__( '%s%% discount', 'torobpay-for-woocommerce' ),
			esc_html( torobpay_pdp_promo_format_number( $campaign['discount_percentage'] ) )
		);
	}

	if ( $campaign['discount_fixed_amount'] <= 0 ) {
		return '';
	}

	return sprintf(
		/* translators: %s: formatted discount amount from wc_price() */
		esc_html__( '%s discount', 'torobpay-for-woocommerce' ),
		torobpay_pdp_promo_price_html( $campaign['discount_fixed_amount'] )
	);
}

/**
 * Minimum-purchase and percent-cap rows for the lightbox. Display-only.
 *
 * @param array $campaign Sanitized campaign.
 * @return string Escaped HTML rows or empty.
 */
function torobpay_pdp_promo_terms_html( $campaign ) {
	$rows = '';

	if ( $campaign['min_order_total_amount'] > 0 ) {
		$min_inner = '' !== $campaign['min_order_text']
			? esc_html( $campaign['min_order_text'] )
			: torobpay_pdp_promo_price_html( $campaign['min_order_total_amount'] );
		$rows     .= '<p class="torobpay-promo-card-min">'
			. sprintf(
				/* translators: %s: minimum purchase amount or API-provided text */
				esc_html__( 'Minimum purchase: %s', 'torobpay-for-woocommerce' ),
				$min_inner
			)
			. '</p>';
	}

	$has_cap_text = '' !== $campaign['max_discount_text'];
	$has_cap      = 'percent' === $campaign['discount_type'] && null !== $campaign['discount_percentage_max_amount'] && $campaign['discount_percentage_max_amount'] > 0;
	if ( $has_cap_text || $has_cap ) {
		$cap_inner = $has_cap_text
			? sprintf(
				/* translators: %s: API-provided maximum discount text */
				esc_html__( 'Maximum discount: %s', 'torobpay-for-woocommerce' ),
				esc_html( $campaign['max_discount_text'] )
			)
			: sprintf(
				/* translators: %s: formatted amount cap */
				esc_html__( 'Up to %s', 'torobpay-for-woocommerce' ),
				torobpay_pdp_promo_price_html( $campaign['discount_percentage_max_amount'] )
			);
		$rows      .= '<p class="torobpay-promo-card-min">' . $cap_inner . '</p>';
	}

	return $rows;
}

/**
 * Compact label for the discount chip. Prefers the API short title.
 *
 * @param array $campaign Sanitized campaign.
 * @return string
 */
function torobpay_pdp_promo_campaign_label( $campaign ) {
	foreach ( array( 'short_amount_title', 'amount_title' ) as $key ) {
		if ( ! empty( $campaign[ $key ] ) ) {
			return $campaign[ $key ];
		}
	}

	return __( 'Special TorobPay discount', 'torobpay-for-woocommerce' );
}

/**
 * Top-bar campaign title: first non-empty short/title/text field, plus «ترب‌پی».
 *
 * @param array $campaign Sanitized campaign.
 * @return string
 */
function torobpay_pdp_promo_topbar_title( $campaign ) {
	foreach ( array( 'short_amount_title', 'amount_title', 'amount_text' ) as $key ) {
		if ( ! empty( $campaign[ $key ] ) ) {
			return (string) $campaign[ $key ] . ' ترب‌پی';
		}
	}

	return __( 'Special TorobPay discount', 'torobpay-for-woocommerce' );
}

/**
 * Ticker campaign line: {@see torobpay_pdp_promo_topbar_title()} + Get Discount button.
 *
 * @param array $campaign Sanitized campaign.
 * @return string HTML.
 */
function torobpay_pdp_promo_topbar_sentence_html( $campaign ) {
	$title = esc_html( torobpay_pdp_promo_topbar_title( $campaign ) );
	$label = esc_html__( 'Get Discount', 'torobpay-for-woocommerce' );
	$title_html = '<span class="torobpay-topbar-campaign-title">' . $title . '</span>';
	$hover = esc_html__( 'View Promo', 'torobpay-for-woocommerce' );

	return '<span class="torobpay-topbar-campaign-line">'
		. $title_html
		. torobpay_pdp_promo_topbar_discount_button_html( $label, $hover )
		. '</span>';
}

/**
 * Spread campaign-slot button (label is the full line on small screens).
 *
 * @return string HTML.
 */
function torobpay_pdp_promo_topbar_spread_button_html() {
	$label = esc_html__( 'Get discount code', 'torobpay-for-woocommerce' );
	$hover = esc_html__( 'View Promo', 'torobpay-for-woocommerce' );

	return torobpay_pdp_promo_topbar_discount_button_html( $label, $hover );
}

/**
 * Shared topbar discount button markup.
 *
 * @param string $label Escaped resting label.
 * @param string $hover Escaped hover label.
 * @return string HTML.
 */
function torobpay_pdp_promo_topbar_discount_button_html( $label, $hover ) {
	return '<button type="button" class="torobpay-topbar-campaign" aria-haspopup="dialog" aria-expanded="false" aria-controls="torobpay-promo-dialog">'
		. '<span class="torobpay-topbar-campaign-labels">'
			. '<span class="torobpay-topbar-campaign-label">' . $label . '</span>'
			. '<span class="torobpay-topbar-campaign-label torobpay-topbar-campaign-label--alt" aria-hidden="true">' . $hover . '</span>'
		. '</span>'
		. '</button>';
}

/**
 * Saved discount-row style. Marquee is the default.
 *
 * @return string marquee|simple
 */
function torobpay_get_pdp_promo_style() {
	$settings = torobpay_get_pdp_promo_settings();

	return $settings['style'];
}

/**
 * One label and code pair inside the discount row or marquee.
 *
 * @param string $label  Escaped label HTML.
 * @param string $code   Escaped code HTML.
 * @param bool   $hidden Hide duplicate copies from assistive tech.
 * @return string
 */
function torobpay_pdp_promo_style_item_html( $label, $code, $hidden = false ) {
	return '<span class="torobpay-widget-promo-item"' . ( $hidden ? ' aria-hidden="true"' : '' ) . '>'
		. '<span class="torobpay-widget-promo-label">' . $label . '</span>'
		. '<kbd class="torobpay-widget-promo-code" dir="ltr">' . $code . '</kbd>'
		. '</span>';
}

/**
 * One half of the scrolling bar. The two halves are identical so the loop is seamless.
 *
 * @param string $label    Escaped label HTML.
 * @param string $code     Escaped code HTML.
 * @param bool   $hide_all Hide this entire half from assistive tech.
 * @return string
 */
function torobpay_pdp_promo_marquee_set_html( $label, $code, $hide_all = false ) {
	$html = '';
	for ( $i = 0; $i < 4; $i++ ) {
		$html .= torobpay_pdp_promo_style_item_html( $label, $code, ! $hide_all && $i > 0 );
	}

	return '<span class="torobpay-widget-promo-set"' . ( $hide_all ? ' aria-hidden="true"' : '' ) . '>' . $html . '</span>';
}

/**
 * Promo chip inside the PDP widget. Opens the lightbox.
 *
 * @param array{tabindex?:int} $args Optional. tabindex -1 keeps the admin preview out of tab order.
 * @return string HTML or empty.
 */
function torobpay_get_pdp_promo_widget_html( $args = array() ) {
	$settings = torobpay_get_pdp_promo_settings();
	if ( empty( $settings['widget'] ) || 'yes' !== $settings['widget'] ) {
		return '';
	}
	$campaign = torobpay_get_pdp_promo_campaign();
	if ( null === $campaign ) {
		return '';
	}

	$label    = esc_html( torobpay_pdp_promo_campaign_label( $campaign ) );
	$code     = esc_html( $campaign['code'] );
	$classes  = array( 'torobpay-widget-promo' );
	$tabindex = '';
	if ( isset( $args['tabindex'] ) ) {
		$tabindex = ' tabindex="' . (int) $args['tabindex'] . '"';
	}

	if ( 'simple' === $settings['style'] ) {
		$inner = '<span class="torobpay-widget-promo-label">' . $label . '</span>'
			. '<kbd class="torobpay-widget-promo-code" dir="ltr">' . $code . '</kbd>';
	} else {
		$classes[] = 'torobpay-widget-promo--marquee';
		$inner     = '<span class="torobpay-widget-promo-track">'
			. torobpay_pdp_promo_marquee_set_html( $label, $code, false )
			. torobpay_pdp_promo_marquee_set_html( $label, $code, true )
			. '</span>';
	}

	return '<button type="button" class="' . esc_attr( implode( ' ', $classes ) ) . '" aria-haspopup="dialog" aria-expanded="false" aria-controls="torobpay-promo-dialog"' . $tabindex . '>'
		. $inner
		. '</button>';
}

/**
 * Expiry row for the lightbox, driven by the campaign's expires_at.
 *
 * @param array $campaign Sanitized campaign.
 * @return string HTML or empty.
 */
function torobpay_pdp_promo_expiry_html( $campaign ) {
	$expires_ts = isset( $campaign['expires_ts'] ) ? (int) $campaign['expires_ts'] : 0;

	if ( '' !== $campaign['expiry_text'] ) {
		$phrase = esc_html( $campaign['expiry_text'] );
	} else {
		$expires_dt = '' !== $campaign['expires_at'] ? date_create_immutable( $campaign['expires_at'] ) : false;
		if ( ! $expires_dt instanceof DateTimeInterface || function_exists( 'torobpay_format_jalali_until_phrase' ) === false ) {
			return '';
		}
		$phrase = esc_html( torobpay_format_jalali_until_phrase( $expires_dt ) );
	}

	$attrs = '';
	if ( $expires_ts > 0 ) {
		$attrs = sprintf(
			' data-expires-at="%1$d"><time datetime="%2$s">%3$s</time></p>',
			$expires_ts,
			esc_attr( gmdate( 'c', $expires_ts ) ),
			$phrase
		);
		return '<p class="torobpay-promo-expiry"' . $attrs;
	}

	return '<p class="torobpay-promo-expiry">' . $phrase . '</p>';
}

/**
 * Lightbox markup (printed once in the footer).
 *
 * @return string HTML or empty.
 */
function torobpay_get_pdp_promo_lightbox_html() {
	$settings = torobpay_get_pdp_promo_settings();
	$widget_on = isset( $settings['widget'] ) && 'yes' === $settings['widget'];
	$topbar_on = isset( $settings['topbar'] ) && 'yes' === $settings['topbar'];
	if ( ! $widget_on && ! $topbar_on ) {
		return '';
	}
	$campaign = torobpay_get_pdp_promo_campaign();
	if ( null === $campaign ) {
		return '';
	}

	$amount_html = torobpay_pdp_promo_amount_html( $campaign );
	$amount_row  = '' !== $amount_html ? '<p class="torobpay-promo-card-amount">' . $amount_html . '</p>' : '';

	$terms_rows = torobpay_pdp_promo_terms_html( $campaign );

	$first_order_row = ! empty( $campaign['first_order_only'] )
		? '<p class="torobpay-promo-card-min">' . esc_html__( 'First order only', 'torobpay-for-woocommerce' ) . '</p>'
		: '';

	$expiry = torobpay_pdp_promo_expiry_html( $campaign );

	return sprintf(
		'<div id="torobpay-promo-overlay" class="torobpay-promo-overlay" hidden>'
		. '<div id="torobpay-promo-dialog" class="torobpay-promo-dialog" role="dialog" aria-modal="true" aria-labelledby="torobpay-promo-title" tabindex="-1">'
			. '<h2 id="torobpay-promo-title" class="torobpay-promo-title">'
				. '<span class="torobpay-promo-title-mark" aria-hidden="true">%%</span>'
				. '<span>%1$s</span>'
			. '</h2>'
			. '<p class="torobpay-promo-steps">%2$s</p>'
			. '<p class="torobpay-promo-warning" role="note">%3$s</p>'
			. '<div class="torobpay-promo-card">'
				. '%4$s'
				. '%5$s'
				. '<p class="torobpay-promo-card-code">'
					. '<span class="torobpay-promo-card-code-label">%6$s</span>'
					. '<kbd class="torobpay-promo-card-code-value" dir="ltr">%7$s</kbd>'
				. '</p>'
				. '%8$s'
			. '</div>'
			. '<button type="button" class="torobpay-promo-dismiss" data-promo-code="%9$s">%10$s</button>'
		. '</div>'
		. '</div>',
		esc_html__( 'Special discount', 'torobpay-for-woocommerce' ),
		esc_html__( 'After adding the item to the cart, select TorobPay at checkout, then enter the discount code on TorobPay when paying the first installment.', 'torobpay-for-woocommerce' ),
		esc_html__( 'Do not enter this code on the store website.', 'torobpay-for-woocommerce' ),
		$amount_row,
		$terms_rows . $first_order_row,
		esc_html__( 'Discount code:', 'torobpay-for-woocommerce' ),
		esc_html( $campaign['code'] ),
		$expiry,
		esc_attr( $campaign['code'] ),
		esc_html__( 'Copy the code', 'torobpay-for-woocommerce' )
	);
}

/**
 * Mark that this request rendered a PDP widget with an active promo.
 *
 * @return void
 */
function torobpay_pdp_promo_mark_needed() {
	$GLOBALS['torobpay_pdp_promo_needed'] = true;
	torobpay_enqueue_pdp_promo_assets();
}

/**
 * Enqueue storefront promo assets when the widget is on the page.
 *
 * @return void
 */
function torobpay_enqueue_pdp_promo_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	if ( ! torobpay_pdp_promo_dialog_active() ) {
		return;
	}

	$done = true;

	wp_enqueue_style(
		'torobpay-pdp-promo',
		plugins_url( 'assets/css/pdp-promo.css', TOROBPAY_PLUGIN_FILE ),
		array(),
		TOROBPAY_VERSION
	);

	wp_enqueue_script(
		'torobpay-pdp-promo',
		plugins_url( 'assets/js/pdp-promo.js', TOROBPAY_PLUGIN_FILE ),
		array( 'jquery', 'wp-i18n' ),
		TOROBPAY_VERSION,
		true
	);

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'torobpay-pdp-promo', 'torobpay-for-woocommerce', dirname( TOROBPAY_PLUGIN_FILE ) . '/languages' );
	}
}

/**
 * Print the lightbox once in the footer.
 *
 * @return void
 */
function torobpay_print_pdp_promo_lightbox() {
	static $printed = false;
	if ( $printed ) {
		return;
	}
	if ( empty( $GLOBALS['torobpay_pdp_promo_needed'] ) ) {
		return;
	}

	$html = torobpay_get_pdp_promo_lightbox_html();
	if ( '' === $html ) {
		return;
	}

	$printed = true;
	echo wp_kses( $html, torobpay_kses_allowed_html() );
}
add_action( 'wp_footer', 'torobpay_print_pdp_promo_lightbox', 5 );
