<?php
/**
 * Detect third-party gateway fee plugins and keep TorobPay fee-free.
 *
 * Soft policy only: dismissible admin warning when a known fee/surcharge
 * plugin is active, strip unauthorized positive fees from the cart when
 * TorobPay is the chosen payment method, and hide plugin-specific surcharge
 * UI on TorobPay. Discounts (negative fees) and other gateways are left
 * alone. Does not deactivate other plugins or write their options.
 *
 * One file per fee plugin lives in includes/fee-plugins/. Register a new
 * plugin from its own file; the shared remover and notices pick it up.
 *
 * Known plugins:
 * - Toroobche (wc-payment-adjuster, formerly wc-custom-percentage-gateway-fee)
 * - SiteSkill Pay Discount (siteskill-paydiscount)
 * - Karmozd Pay (karmozd-pay)
 * - QestX (qestx) — surcharge and minimum order amount only
 * - Arvan Pay (arvan-extra-fees) — surcharge on TorobPay only
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$torobpay_fee_plugin_dir = __DIR__ . '/fee-plugins/';

require_once $torobpay_fee_plugin_dir . 'shared.php';
require_once $torobpay_fee_plugin_dir . 'toroobche.php';
require_once $torobpay_fee_plugin_dir . 'siteskill.php';
require_once $torobpay_fee_plugin_dir . 'karmozd.php';
require_once $torobpay_fee_plugin_dir . 'qestx.php';
require_once $torobpay_fee_plugin_dir . 'arvan.php';
