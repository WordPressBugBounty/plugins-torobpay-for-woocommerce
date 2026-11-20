=== TorobPay for WooCommerce ===
Contributors: torobpay, macse2
Tags: torobpay, ترب‌پی, woocommerce, payment gateway, installments
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 4.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept TorobPay installment payments in your WooCommerce store.

== Description ==

[TorobPay](https://torobpay.com/) lets customers pay in installments at checkout.

You need a TorobPay merchant account to use this plugin. You may [register here](https://panel.torob.com/s/torobpayRegisteration).

The plugin:

* Works with classic and block checkout
* Syncs order status and supports refunds
* Shows installment info on product pages, plus a slider and banners so shoppers know installments are available
* Takes in-store payments with a QR code
* Shows TorobPay special discount campaigns on the product widget and top bar when your merchant account has one
* Lets merchants join the TorobPay campaign

== Installation ==

1. Upload the plugin or install the ZIP via **Plugins → Add New**.
2. Activate **TorobPay for WooCommerce**.
3. Make sure WooCommerce is active.
4. Go to **WooCommerce → Settings → Payments**, enable TorobPay, and enter your merchant details.

== Frequently Asked Questions ==

= Do I need a TorobPay account? =

Yes. Register as a TorobPay merchant and get your account details before you can accept payments.

= Why don't I see the In-store selling option on the TorobPay orders page? =

Ask TorobPay to enable this feature for you.

= Does this work with High-Performance Order Storage (HPOS)? =

Yes.

= Where can I get support? =

Visit [https://panel.torobpay.com/s](https://panel.torobpay.com/s) or use the support options in your TorobPay merchant panel.

= What happened to the Custom CSS editor? =

It was removed in 4.7.2. If you had custom CSS there, copy it into your theme or **Appearance → Customize → Additional CSS**. Those rules are not migrated automatically.

== Screenshots ==

1. TorobPay orders, with filters for order number, status, TorobPay status, sale type, date, and mobile number.
2. Product-page widget styles, with a live preview and the optional TorobPay discount code.
3. Campaign participation, and the lists of customers who received credit.
4. Support page, with contact options, useful links, and common questions.
5. WooCommerce payment settings for the TorobPay gateway.

== Changelog ==

= 4.9.0 =
* Special discount campaigns now come from TorobPay instead of manual code and terms in settings. The product widget and top bar show what TorobPay sends; you choose where they appear and the widget row style.
* Checkout and payment method UI updates, including clearer discount messaging and refreshed styling.
* Checkout offer and product widget animations updated.
* Checkout collects and validates the customer mobile number on checkout to display special offers.
* Special discount settings grouped under visual elements in admin.

= 4.8.17 =
* Product widget, badge, marquee, discount amounts, and the in-store payment total follow the currency position set in WooCommerce. With Left selected, تومان and ریال stay on the left of the amount. This matches WooCommerce 11.2.

= 4.8.16 =
* Checkout does not open TorobPay when the amount TorobPay stored does not match the amount the store sent. The customer is asked to try again. This is to combat infringement and keep price integrity.
* Two edits of the same TorobPay order cannot run at once. A save that already finished is not applied again.
* When editing a TorobPay order, item, discount, and shipping amounts accept Persian and Arabic-Indic digits.
* Clearer message when TorobPay will not update an order that already has a discount.
* Works with more adding fee/surcharge plugins when those plugins add a surcharge on TorobPay. TorobPay removes the fee on its payment method; other gateways are unchanged.
* Adding fee plugins surcharges are removed with new updated methods.
* Optional beta setting under Advanced: allow TorobPay API calls when WordPress or another plugin blocks outbound HTTP.
* Automatic gateway setup can finish from the admin screen when the host blocks background requests.
* Clearer diagnostics on the gateway settings screen.
* New confetti animation for Special Discounts popup and Topbar.

= 4.8.7 =
* Gateway URL stays locked until you turn on "Use a custom gateway URL".
* Product widget sits higher on the page. Stores that set their own hook or priority are left as they are.
* Stops other plugins from adding a fee or raising prices on TorobPay. Discounts on other payment methods stay.
* An order that is already paid finishes checkout instead of starting another payment.
* The admin notice for orders that need review can be dismissed until more orders need action.

= 4.8.0 =
* In-store orders from the TorobPay orders screen. The customer pays by QR code and sees a payment result page with installment dates.
* In-store orders are marked in the orders list and in the order screen.
* Optional TorobPay discount code on the product-page widget. Shoppers enter it on the TorobPay payment page, not in the store coupon field.
* Clearer TorobPay error and warning messages, with a way to recheck eligibility from the notification center if there was any issue reported by TorobPay.

= 4.7.6 =
* Better handling when TorobPay is default payment method.
* Stops a conflicting discount plugin from adding extra fees on TorobPay.
* New campaign data sharing method, now merchants could just enable it and it works automatically.
* New UTM action profile for "utm_medium" which contain "TorobPay" term.
* Improved settings UI and bug fixes.
* Improved marquee and badge styles to support more themes out of the box.
* Combined Jalali date range filter in TorobPay orders table page. 

= 4.7.5 =
* Guest checkout now goes straight to TorobPay.
* Clearer checkout error messages.
* Checkout always redirects to TorobPay.

= 4.7.4 =
* WordPress.org review follow-up.

= 4.7.3 =
* WordPress.org review follow-up.
* Product-page installment amounts now follow the selected quantity.
* Safer handling when a paid order is not marked paid by WooCommerce.

= 4.7.2 =
* WordPress.org review fixes. Custom CSS editor removed — move any custom CSS to your theme or **Appearance → Customize → Additional CSS**.

= 4.7.1 =
* More reliable order updates, especially on shared hosting.

= 4.7.0 =
* First WordPress.org release. Updates now come through WordPress.org. Re-activate the plugin if the main file name changed.

== Upgrade Notice ==

= 4.9.0 =
Special discounts are now driven by TorobPay campaigns. Upgrade recommended.

= 4.8.17 =
Installment prices follow WooCommerce's currency position, so تومان and ریال stay on the correct side. Upgrade recommended with WooCommerce 11.2.

= 4.8.16 =
Checkout confirms the payment amount before opening TorobPay. Fee-plugin compatibility and a beta fix for blocked API calls are included. Upgrade recommended.

= 4.8.7 =
Already-paid orders finish checkout instead of starting another payment. Upgrade recommended.

= 4.8.0 =
In-store payments and a product-page discount code. Upgrade recommended.

= 4.7.6 =
New campaign method and UI. Upgrade recommended.

= 4.7.5 =
Guest checkout now goes straight to TorobPay. Recommended for all stores.

= 4.7.2 =
Custom CSS editor removed. Move any custom CSS to your theme or Appearance → Customize → Additional CSS.

= 4.7.0 =
Updates now come through WordPress.org. Re-activate the plugin if the main file name changed.
