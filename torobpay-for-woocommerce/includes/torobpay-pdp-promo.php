<?php
/**
 * Merchant TorobPay gateway promo code shown on the PDP widget.
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
 * @return array{
 *   enabled:string,
 *   code:string,
 *   discount_type:string,
 *   discount_value:float,
 *   min_total:float,
 *   expires_at:int,
 *   style:string
 * }
 */
function torobpay_pdp_promo_defaults() {
	return array(
		'enabled'        => 'no',
		'code'           => '',
		'discount_type'  => 'amount',
		'discount_value' => 0.0,
		'min_total'      => 0.0,
		'expires_at'     => 0,
		'style'          => 'marquee',
	);
}

/**
 * Stored promo settings, sanitized.
 *
 * @return array{
 *   enabled:string,
 *   code:string,
 *   discount_type:string,
 *   discount_value:float,
 *   min_total:float,
 *   expires_at:int,
 *   style:string
 * }
 */
function torobpay_get_pdp_promo_settings() {
	$stored = get_option( 'torobpay_pdp_promo', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	return torobpay_sanitize_pdp_promo_settings( $stored );
}

/**
 * Sanitize a promo settings array.
 *
 * @param array $input Raw settings.
 * @return array{
 *   enabled:string,
 *   code:string,
 *   discount_type:string,
 *   discount_value:float,
 *   min_total:float,
 *   expires_at:int,
 *   style:string
 * }
 */
function torobpay_sanitize_pdp_promo_settings( $input ) {
	$defaults = torobpay_pdp_promo_defaults();
	if ( ! is_array( $input ) ) {
		return $defaults;
	}

	$code = isset( $input['code'] ) ? sanitize_text_field( (string) $input['code'] ) : '';
	$code = preg_replace( '/\s+/', '', $code );
	$code = is_string( $code ) ? $code : '';
	if ( function_exists( 'mb_substr' ) ) {
		$code = mb_substr( $code, 0, 40 );
	} else {
		$code = substr( $code, 0, 40 );
	}

	$type = isset( $input['discount_type'] ) ? sanitize_key( (string) $input['discount_type'] ) : 'amount';
	if ( ! in_array( $type, array( 'amount', 'percent' ), true ) ) {
		$type = 'amount';
	}

	$value = isset( $input['discount_value'] ) ? (float) $input['discount_value'] : 0.0;
	if ( $value < 0 ) {
		$value = 0.0;
	}
	if ( 'percent' === $type ) {
		if ( $value > 100 ) {
			$value = 100.0;
		}
	} elseif ( $value > 1000000000000 ) {
		$value = 1000000000000.0;
	}

	$min = isset( $input['min_total'] ) ? (float) $input['min_total'] : 0.0;
	if ( $min < 0 ) {
		$min = 0.0;
	}
	if ( $min > 1000000000000 ) {
		$min = 1000000000000.0;
	}

	$expires = isset( $input['expires_at'] ) ? (int) $input['expires_at'] : 0;
	if ( $expires < 0 ) {
		$expires = 0;
	}

	$enabled = ( isset( $input['enabled'] ) && 'yes' === $input['enabled'] ) ? 'yes' : 'no';
	$style   = isset( $input['style'] ) ? sanitize_key( (string) $input['style'] ) : 'marquee';
	if ( ! in_array( $style, array( 'marquee', 'simple' ), true ) ) {
		$style = 'marquee';
	}

	return array(
		'enabled'        => $enabled,
		'code'           => $code,
		'discount_type'  => $type,
		'discount_value' => $value,
		'min_total'      => $min,
		'expires_at'     => $expires,
		'style'          => $style,
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
 * Whether the promo should render on the storefront.
 *
 * Only the discount code is required. Amount, percent, minimum, and expiry
 * are optional display fields.
 *
 * @param array|null $settings Optional preloaded settings.
 * @return bool
 */
function torobpay_is_pdp_promo_active( $settings = null ) {
	if ( null === $settings ) {
		$settings = torobpay_get_pdp_promo_settings();
	}

	if ( 'yes' !== $settings['enabled'] ) {
		return false;
	}

	if ( '' === $settings['code'] ) {
		return false;
	}

	$cutoff = torobpay_pdp_promo_expires_cutoff( $settings['expires_at'] );
	if ( $cutoff instanceof DateTimeInterface && $cutoff->getTimestamp() <= time() ) {
		return false;
	}

	return true;
}

/**
 * Site timezone for the promo expiry date.
 *
 * @return DateTimeZone
 */
function torobpay_pdp_promo_site_timezone() {
	return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
}

/**
 * Start of the selected expiry date in the site timezone.
 *
 * The clock time is ignored. The offer lasts until midnight at the end of this day.
 *
 * @param int $timestamp Unix timestamp.
 * @return DateTimeImmutable|null
 */
function torobpay_pdp_promo_expires_datetime( $timestamp ) {
	$timestamp = (int) $timestamp;
	if ( $timestamp <= 0 ) {
		return null;
	}

	$local = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( torobpay_pdp_promo_site_timezone() );

	return $local->setTime( 0, 0 );
}

/**
 * Midnight when the selected expiry date ends.
 *
 * @param int $timestamp Unix timestamp.
 * @return DateTimeImmutable|null
 */
function torobpay_pdp_promo_expires_cutoff( $timestamp ) {
	$day = torobpay_pdp_promo_expires_datetime( $timestamp );

	return $day ? $day->modify( '+1 day' ) : null;
}

/**
 * Parse a site-timezone date to midnight at the start of that day.
 *
 * A clock time, if present, is ignored.
 *
 * @param string $local Local date (`Y-m-d`) or datetime.
 * @return int
 */
function torobpay_pdp_promo_parse_expires_local( $local ) {
	$local = trim( (string) $local );
	if ( '' === $local ) {
		return 0;
	}

	$date = substr( $local, 0, 10 );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		return 0;
	}

	$dt = date_create_immutable( $date . ' 00:00:00', torobpay_pdp_promo_site_timezone() );

	return $dt ? $dt->getTimestamp() : 0;
}

/**
 * Format a unix timestamp for the expiry hidden field (site timezone).
 *
 * @param int $timestamp Unix timestamp.
 * @return string Gregorian `Y-m-d` or empty.
 */
function torobpay_pdp_promo_expires_local_value( $timestamp ) {
	$dt = torobpay_pdp_promo_expires_datetime( $timestamp );

	return $dt ? $dt->format( 'Y-m-d' ) : '';
}

/**
 * Save promo fields from the visual settings POST.
 *
 * @return void
 */
function torobpay_save_pdp_promo_from_post() {
	$expires_raw = isset( $_POST['pdp_promo_expires_at'] ) ? sanitize_text_field( wp_unslash( $_POST['pdp_promo_expires_at'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verifies the page nonce.

	torobpay_set_pdp_promo_settings(
		array(
			'enabled'        => isset( $_POST['pdp_promo_enabled'] ) ? 'yes' : 'no', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'code'           => isset( $_POST['pdp_promo_code'] ) ? wp_unslash( $_POST['pdp_promo_code'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in torobpay_sanitize_pdp_promo_settings().
			'discount_type'  => isset( $_POST['pdp_promo_discount_type'] ) ? wp_unslash( $_POST['pdp_promo_discount_type'] ) : 'amount', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'discount_value' => isset( $_POST['pdp_promo_discount_value'] ) ? wp_unslash( $_POST['pdp_promo_discount_value'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'min_total'      => isset( $_POST['pdp_promo_min_total'] ) ? wp_unslash( $_POST['pdp_promo_min_total'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'expires_at'     => torobpay_pdp_promo_parse_expires_local( $expires_raw ),
			'style'          => isset( $_POST['pdp_promo_style'] ) ? wp_unslash( $_POST['pdp_promo_style'] ) : 'marquee', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in torobpay_sanitize_pdp_promo_settings().
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
 * Display-only discount heading (amount or percent). Not used for totals.
 *
 * @param array $settings Promo settings.
 * @return string Escaped HTML.
 */
function torobpay_pdp_promo_discount_heading_html( $settings ) {
	if ( $settings['discount_value'] <= 0 ) {
		return '';
	}

	if ( 'percent' === $settings['discount_type'] ) {
		return sprintf(
			/* translators: %s: percentage number without the percent sign */
			esc_html__( '%s%% discount', 'torobpay-for-woocommerce' ),
			esc_html( torobpay_pdp_promo_format_number( $settings['discount_value'] ) )
		);
	}

	$price = function_exists( 'wc_price' )
		? wp_kses( wc_price( $settings['discount_value'] ), torobpay_kses_allowed_html() )
		: esc_html( torobpay_pdp_promo_format_number( $settings['discount_value'] ) );

	return sprintf(
		/* translators: %s: formatted discount amount from wc_price() */
		esc_html__( '%s discount', 'torobpay-for-woocommerce' ),
		$price
	);
}

/**
 * Optional minimum-purchase or amount-cap line. Display-only.
 *
 * Amount discounts show a minimum purchase. Percent discounts show an amount
 * cap: the percent is calculated on this amount even if the total is higher.
 *
 * @param array $settings Promo settings.
 * @return string Escaped HTML or empty.
 */
function torobpay_pdp_promo_min_total_html( $settings ) {
	if ( $settings['min_total'] <= 0 ) {
		return '';
	}

	$price = function_exists( 'wc_price' )
		? wp_kses( wc_price( $settings['min_total'] ), torobpay_kses_allowed_html() )
		: esc_html( torobpay_pdp_promo_format_number( $settings['min_total'] ) );

	if ( 'percent' === $settings['discount_type'] ) {
		return sprintf(
			/* translators: %s: formatted amount cap from wc_price(). Persian: تا سقف مبلغ %s */
			esc_html__( 'Up to %s', 'torobpay-for-woocommerce' ),
			$price
		);
	}

	return sprintf(
		/* translators: %s: formatted minimum order total from wc_price() */
		esc_html__( 'Minimum purchase: %s', 'torobpay-for-woocommerce' ),
		$price
	);
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
	if ( ! torobpay_is_pdp_promo_active( $settings ) ) {
		return '';
	}

	$label    = esc_html__( 'Special TorobPay discount', 'torobpay-for-woocommerce' );
	$code     = esc_html( $settings['code'] );
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
 * Lightbox markup (printed once in the footer).
 *
 * @return string HTML or empty.
 */
function torobpay_get_pdp_promo_lightbox_html() {
	$settings = torobpay_get_pdp_promo_settings();
	if ( ! torobpay_is_pdp_promo_active( $settings ) ) {
		return '';
	}

	$amount_html = torobpay_pdp_promo_discount_heading_html( $settings );
	$amount_row  = '';
	if ( '' !== $amount_html ) {
		$amount_row = '<p class="torobpay-promo-card-amount">' . $amount_html . '</p>';
	}

	$min_html = torobpay_pdp_promo_min_total_html( $settings );
	$min_row  = '';
	if ( '' !== $min_html ) {
		$min_row = '<p class="torobpay-promo-card-min">' . $min_html . '</p>';
	}

	$expiry = '';
	$day    = torobpay_pdp_promo_expires_datetime( $settings['expires_at'] );
	$cutoff = torobpay_pdp_promo_expires_cutoff( $settings['expires_at'] );
	if ( $day instanceof DateTimeInterface && $cutoff instanceof DateTimeInterface ) {
		$expiry = sprintf(
			'<p class="torobpay-promo-expiry" data-expires-at="%1$d">'
			. '<time datetime="%2$s">%3$s</time>'
			. '</p>',
			$cutoff->getTimestamp(),
			esc_attr( $cutoff->format( 'c' ) ),
			esc_html( torobpay_format_jalali_until_phrase( $day ) )
		);
	}

	return sprintf(
		'<div id="torobpay-promo-overlay" class="torobpay-promo-overlay" hidden>'
		. '<div id="torobpay-promo-dialog" class="torobpay-promo-dialog" role="dialog" aria-modal="true" aria-labelledby="torobpay-promo-title" tabindex="-1">'
			. '<div class="torobpay-promo-confetti torobpay-confetti-stage" aria-hidden="true"></div>'
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
				. '<button type="button" class="torobpay-promo-copy" data-promo-code="%9$s">%10$s</button>'
			. '</div>'
			. '<button type="button" class="torobpay-promo-dismiss">%11$s</button>'
		. '</div>'
		. '</div>',
		esc_html__( 'Special discount', 'torobpay-for-woocommerce' ),
		esc_html__( 'After adding the item to the cart, select TorobPay at checkout, then enter the discount code on TorobPay when paying the first installment.', 'torobpay-for-woocommerce' ),
		esc_html__( 'Do not enter this code on the store website.', 'torobpay-for-woocommerce' ),
		$amount_row,
		$min_row,
		esc_html__( 'Discount code:', 'torobpay-for-woocommerce' ),
		esc_html( $settings['code'] ),
		$expiry,
		esc_attr( $settings['code'] ),
		esc_html__( 'Copy code', 'torobpay-for-woocommerce' ),
		esc_html__( 'Got it', 'torobpay-for-woocommerce' )
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
	if ( ! torobpay_is_pdp_promo_active() ) {
		return;
	}

	$done = true;

	wp_enqueue_style(
		'torobpay-pdp-promo',
		plugins_url( 'assets/css/pdp-promo.css', TOROBPAY_PLUGIN_FILE ),
		array(),
		TOROBPAY_VERSION
	);

	$script_deps = array( 'jquery', 'wp-i18n' );
	if ( function_exists( 'torobpay_enqueue_topbar_confetti_script' ) ) {
		torobpay_enqueue_topbar_confetti_script();
		$script_deps[] = 'torobpay-topbar-confetti';
	}

	wp_enqueue_script(
		'torobpay-pdp-promo',
		plugins_url( 'assets/js/pdp-promo.js', TOROBPAY_PLUGIN_FILE ),
		$script_deps,
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
