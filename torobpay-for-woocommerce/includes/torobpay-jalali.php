<?php
/**
 * Jalali (Solar Hijri) calendar helpers.
 *
 * Conversion uses the algorithmic civil calendar (jalaali-js / Khayyam),
 * not PHP Intl. End-of-month arithmetic stays in Jalali so installment
 * due dates follow Iranian month lengths (31 / 30 / 29-or-30 Esfand).
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asia/Tehran timezone used for TorobPay installment due dates.
 *
 * @return DateTimeZone
 */
function torobpay_jalali_timezone() {
	static $timezone = null;

	if ( ! $timezone instanceof DateTimeZone ) {
		$timezone = new DateTimeZone( 'Asia/Tehran' );
	}

	return $timezone;
}

/**
 * Convert a Gregorian date to Jalali.
 *
 * @param int $gy Gregorian year.
 * @param int $gm Gregorian month (1-12).
 * @param int $gd Gregorian day.
 * @return array{0:int,1:int,2:int} Jalali year, month, day.
 */
function torobpay_gregorian_to_jalali( $gy, $gm, $gd ) {
	$gy = (int) $gy;
	$gm = (int) $gm;
	$gd = (int) $gd;

	if ( $gm < 1 || $gm > 12 ) {
		$gm = 1;
	}

	$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
	$jy    = ( $gy <= 1600 ) ? 0 : 979;
	$gy   -= ( $gy <= 1600 ) ? 621 : 1600;
	$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
	$days  = ( 365 * $gy )
		+ (int) ( ( $gy2 + 3 ) / 4 )
		- (int) ( ( $gy2 + 99 ) / 100 )
		+ (int) ( ( $gy2 + 399 ) / 400 )
		- 80
		+ $gd
		+ $g_d_m[ $gm - 1 ];
	$jy   += 33 * (int) ( $days / 12053 );
	$days %= 12053;
	$jy   += 4 * (int) ( $days / 1461 );
	$days %= 1461;
	$jy   += (int) ( ( $days - 1 ) / 365 );
	if ( $days > 365 ) {
		$days = ( $days - 1 ) % 365;
	}
	$jm = ( $days < 186 ) ? ( 1 + (int) ( $days / 31 ) ) : ( 7 + (int) ( ( $days - 186 ) / 30 ) );
	$jd = 1 + ( ( $days < 186 ) ? ( $days % 31 ) : ( ( $days - 186 ) % 30 ) );

	return array( $jy, $jm, $jd );
}

/**
 * Convert a Jalali date to Gregorian.
 *
 * @param int $jy Jalali year.
 * @param int $jm Jalali month (1-12).
 * @param int $jd Jalali day.
 * @return array{0:int,1:int,2:int} Gregorian year, month, day.
 */
function torobpay_jalali_to_gregorian( $jy, $jm, $jd ) {
	$jy = (int) $jy;
	$jm = (int) $jm;
	$jd = (int) $jd;

	$gy   = ( $jy <= 979 ) ? 621 : 1600;
	$jy  -= ( $jy <= 979 ) ? 0 : 979;
	$days = ( 365 * $jy )
		+ ( (int) ( $jy / 33 ) * 8 )
		+ (int) ( ( ( $jy % 33 ) + 3 ) / 4 )
		+ 78
		+ $jd
		+ ( ( $jm < 7 ) ? ( ( $jm - 1 ) * 31 ) : ( ( ( $jm - 7 ) * 30 ) + 186 ) );
	$gy  += 400 * (int) ( $days / 146097 );
	$days %= 146097;
	if ( $days > 36524 ) {
		--$days;
		$gy   += 100 * (int) ( $days / 36524 );
		$days %= 36524;
		if ( $days >= 365 ) {
			++$days;
		}
	}
	$gy   += 4 * (int) ( $days / 1461 );
	$days %= 1461;
	if ( $days > 365 ) {
		$gy   += (int) ( ( $days - 1 ) / 365 );
		$days  = ( $days - 1 ) % 365;
	}
	$gd         = $days + 1;
	$leap_feb   = ( ( 0 === $gy % 4 && 0 !== $gy % 100 ) || 0 === $gy % 400 ) ? 29 : 28;
	$month_days = array( 0, 31, $leap_feb, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
	$gm         = 1;
	foreach ( $month_days as $month => $length ) {
		if ( 0 === $month ) {
			continue;
		}
		$gm = $month;
		if ( $gd <= $length ) {
			break;
		}
		$gd -= $length;
	}

	return array( $gy, $gm, $gd );
}

/**
 * Number of days in a Jalali month.
 *
 * @param int $jy Jalali year.
 * @param int $jm Jalali month (1-12).
 * @return int
 */
function torobpay_jalali_month_length( $jy, $jm ) {
	$jy = (int) $jy;
	$jm = (int) $jm;

	if ( $jm >= 1 && $jm <= 6 ) {
		return 31;
	}
	if ( $jm >= 7 && $jm <= 11 ) {
		return 30;
	}
	if ( 12 !== $jm ) {
		return 0;
	}

	$gregorian = torobpay_jalali_to_gregorian( $jy, 12, 30 );
	$roundtrip = torobpay_gregorian_to_jalali( $gregorian[0], $gregorian[1], $gregorian[2] );

	return ( 12 === (int) $roundtrip[1] && 30 === (int) $roundtrip[2] ) ? 30 : 29;
}

/**
 * Last day of a Jalali month, optionally shifted by whole months.
 *
 * @param int $jy         Jalali year.
 * @param int $jm         Jalali month (1-12).
 * @param int $add_months Months to add (may be zero).
 * @return array{0:int,1:int,2:int}
 */
function torobpay_jalali_end_of_month( $jy, $jm, $add_months = 0 ) {
	$jy         = (int) $jy;
	$jm         = (int) $jm + (int) $add_months;
	$add_years  = (int) floor( ( $jm - 1 ) / 12 );
	$jy        += $add_years;
	$jm         = $jm - ( $add_years * 12 );
	if ( $jm < 1 ) {
		$jm += 12;
		--$jy;
	}

	return array( $jy, $jm, torobpay_jalali_month_length( $jy, $jm ) );
}

/**
 * Four installment due dates from a Jalali payment day.
 *
 * Tranche 1 is the payment day. Tranche 2 is the last day of the current
 * month when the payment day is 1-15, otherwise the last day of the next
 * month. Tranche 3 and 4 are the last days of the following months.
 *
 * @param int $jy Jalali year.
 * @param int $jm Jalali month.
 * @param int $jd Jalali day.
 * @return array<int,array{0:int,1:int,2:int}>
 */
function torobpay_jalali_installment_due_parts( $jy, $jm, $jd ) {
	$jy     = (int) $jy;
	$jm     = (int) $jm;
	$jd     = (int) $jd;
	$offset = ( $jd <= 15 ) ? 0 : 1;

	return array(
		array( $jy, $jm, $jd ),
		torobpay_jalali_end_of_month( $jy, $jm, $offset ),
		torobpay_jalali_end_of_month( $jy, $jm, $offset + 1 ),
		torobpay_jalali_end_of_month( $jy, $jm, $offset + 2 ),
	);
}

/**
 * Convert ASCII digits to Persian digits when the locale is Persian.
 *
 * @param string $text Text that may contain 0-9.
 * @return string
 */
function torobpay_localize_digits( $text ) {
	$text   = (string) $text;
	$locale = '';
	if ( function_exists( 'determine_locale' ) ) {
		$locale = (string) determine_locale();
	} elseif ( function_exists( 'get_locale' ) ) {
		$locale = (string) get_locale();
	}

	if ( 0 !== strpos( $locale, 'fa' ) ) {
		return $text;
	}

	$western = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
	$persian = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );

	return str_replace( $western, $persian, $text );
}

/**
 * Jalali month name (1-12), translated.
 *
 * @param int $jm Jalali month.
 * @return string
 */
function torobpay_jalali_month_name( $jm ) {
	$names = array(
		1  => __( 'Farvardin', 'torobpay-for-woocommerce' ),
		2  => __( 'Ordibehesht', 'torobpay-for-woocommerce' ),
		3  => __( 'Khordad', 'torobpay-for-woocommerce' ),
		4  => __( 'Tir', 'torobpay-for-woocommerce' ),
		5  => __( 'Mordad', 'torobpay-for-woocommerce' ),
		6  => __( 'Shahrivar', 'torobpay-for-woocommerce' ),
		7  => __( 'Mehr', 'torobpay-for-woocommerce' ),
		8  => __( 'Aban', 'torobpay-for-woocommerce' ),
		9  => __( 'Azar', 'torobpay-for-woocommerce' ),
		10 => __( 'Dey', 'torobpay-for-woocommerce' ),
		11 => __( 'Bahman', 'torobpay-for-woocommerce' ),
		12 => __( 'Esfand', 'torobpay-for-woocommerce' ),
	);
	$jm    = (int) $jm;

	return isset( $names[ $jm ] ) ? $names[ $jm ] : '';
}

/**
 * Format a Jalali date as "day MonthName" (no year).
 *
 * @param int $jm Jalali month.
 * @param int $jd Jalali day.
 * @return string
 */
function torobpay_format_jalali_day_month( $jm, $jd ) {
	$day  = torobpay_localize_digits( (string) (int) $jd );
	$name = torobpay_jalali_month_name( $jm );

	return trim( $day . ' ' . $name );
}

/**
 * Convert a datetime to a Jalali civil date in Asia/Tehran.
 *
 * @param DateTimeInterface $datetime Datetime.
 * @return array{0:int,1:int,2:int}
 */
function torobpay_datetime_to_jalali( $datetime ) {
	$local = ( $datetime instanceof DateTimeInterface )
		? ( new DateTimeImmutable( '@' . $datetime->getTimestamp() ) )->setTimezone( torobpay_jalali_timezone() )
		: new DateTimeImmutable( 'now', torobpay_jalali_timezone() );

	return torobpay_gregorian_to_jalali(
		(int) $local->format( 'Y' ),
		(int) $local->format( 'n' ),
		(int) $local->format( 'j' )
	);
}

/**
 * Resolve the payment instant used as tranche 1.
 *
 * Prefers the order paid datetime; falls back to now in Asia/Tehran.
 *
 * @param object|null $order Order.
 * @return DateTimeImmutable
 */
function torobpay_order_payment_datetime( $order = null ) {
	$tehran = torobpay_jalali_timezone();
	$paid   = null;

	if ( is_object( $order ) && method_exists( $order, 'get_date_paid' ) ) {
		$paid = $order->get_date_paid();
	}

	$resolved = torobpay_parse_payment_datetime( $paid, $tehran );

	return $resolved instanceof DateTimeImmutable
		? $resolved
		: new DateTimeImmutable( 'now', $tehran );
}

/**
 * Parse a WooCommerce paid-date value into Asia/Tehran.
 *
 * @param mixed         $paid   WC_DateTime, timestamp, or datetime string.
 * @param DateTimeZone  $tehran Asia/Tehran.
 * @return DateTimeImmutable|null
 */
function torobpay_parse_payment_datetime( $paid, $tehran ) {
	if ( $paid instanceof DateTimeInterface ) {
		return ( new DateTimeImmutable( '@' . $paid->getTimestamp() ) )->setTimezone( $tehran );
	}

	if ( is_numeric( $paid ) && (int) $paid > 0 ) {
		return ( new DateTimeImmutable( '@' . (int) $paid ) )->setTimezone( $tehran );
	}

	if ( ! is_string( $paid ) ) {
		return null;
	}

	$paid = trim( $paid );
	if ( '' === $paid ) {
		return null;
	}

	try {
		return ( new DateTimeImmutable( $paid, $tehran ) )->setTimezone( $tehran );
	} catch ( Exception $e ) {
		return null;
	}
}

/**
 * Format a Jalali date as Y/m/d.
 *
 * @param int $jy Jalali year.
 * @param int $jm Jalali month.
 * @param int $jd Jalali day.
 * @return string
 */
function torobpay_format_jalali_date( $jy, $jm, $jd ) {
	$formatted = sprintf( '%04d/%02d/%02d', (int) $jy, (int) $jm, (int) $jd );

	return torobpay_localize_digits( $formatted );
}

/**
 * Four installment due-date labels for an order.
 *
 * Tranche 1 is "Today". Later tranches are "day MonthName".
 *
 * @param object|null $order Order.
 * @return array<int,string>
 */
function torobpay_order_installment_due_labels( $order = null ) {
	$jalali = torobpay_datetime_to_jalali( torobpay_order_payment_datetime( $order ) );
	$parts  = torobpay_jalali_installment_due_parts( $jalali[0], $jalali[1], $jalali[2] );
	$labels = array();

	foreach ( $parts as $index => $part ) {
		if ( 0 === (int) $index ) {
			$labels[] = __( 'Today', 'torobpay-for-woocommerce' );
			continue;
		}
		$labels[] = torobpay_format_jalali_day_month( $part[1], $part[2] );
	}

	return $labels;
}

/**
 * Weekday name for PHP date( 'w' ): 0 Sunday through 6 Saturday.
 *
 * @param int $weekday Weekday number.
 * @return string
 */
function torobpay_jalali_weekday_name( $weekday ) {
	$names   = array(
		0 => __( 'Sunday', 'torobpay-for-woocommerce' ),
		1 => __( 'Monday', 'torobpay-for-woocommerce' ),
		2 => __( 'Tuesday', 'torobpay-for-woocommerce' ),
		3 => __( 'Wednesday', 'torobpay-for-woocommerce' ),
		4 => __( 'Thursday', 'torobpay-for-woocommerce' ),
		5 => __( 'Friday', 'torobpay-for-woocommerce' ),
		6 => __( 'Saturday', 'torobpay-for-woocommerce' ),
	);
	$weekday = (int) $weekday;

	return isset( $names[ $weekday ] ) ? $names[ $weekday ] : '';
}

/**
 * Expiry phrase such as "Until 12 at night Friday 10 Mehr".
 *
 * Uses the datetime's own civil day. The offer ends at midnight that night.
 *
 * @param DateTimeInterface $datetime Selected expiry date.
 * @return string
 */
function torobpay_format_jalali_until_phrase( $datetime ) {
	if ( ! $datetime instanceof DateTimeInterface ) {
		return '';
	}

	$jalali = torobpay_gregorian_to_jalali(
		(int) $datetime->format( 'Y' ),
		(int) $datetime->format( 'n' ),
		(int) $datetime->format( 'j' )
	);

	return sprintf(
		/* translators: 1: weekday name, 2: Jalali day and month such as "10 Mehr". */
		__( 'Until 12 at night %1$s %2$s', 'torobpay-for-woocommerce' ),
		torobpay_jalali_weekday_name( (int) $datetime->format( 'w' ) ),
		torobpay_format_jalali_day_month( $jalali[1], $jalali[2] )
	);
}
