/**
 * Refresh checkout when the phone becomes a mobile number.
 *
 * Classic checkout does not refresh payment methods as the phone changes, so a
 * full number triggers update_checkout. Clearing that number does not call
 * eligibility again: the description from before the number was sent is put
 * back, including when the refresh response arrives late.
 * Block checkout pushes the phone to the cart customer so the same eligibility
 * result updates the payment method. An older update-customer response is
 * dropped once a newer one has been sent, so it cannot replace that text.
 * While billing uses the shipping address, the typed number is sent on both.
 * Eligibility reads billing first, and a shipping-only update would come back
 * as the previous number.
 */
(function ($) {
	var form = $('form.checkout');
	var gatewayId = 'WC_Gateway_TorobPay';
	var classic = form.length && form.find('#billing_phone, #shipping_phone').length;

	if (!classic) {
		bindBlockPhone();
		return;
	}

	var lastPhone = currentPhone();
	var confirmedPhone = '';
	var lastAttempt = '';
	var classicFailures = 0;
	var baseline = null;
	var offerBaseline = null;
	var timer = null;
	var ownUpdate = false;
	var phoneRefresh = false;

	rememberDescription();
	form.on('input change', '#billing_phone, #shipping_phone', schedule);
	$(document.body).on('update_checkout', noteForeignUpdate);
	$(document.body).on('updated_checkout', noteUpdatedCheckout);
	$(document).on('ajaxError', noteCheckoutAjaxError);

	// A number already in the field has not been sent yet. Refresh once.
	if (lastPhone) {
		lastPhone = '';
		refreshIfChanged();
	}

	function schedule() {
		window.clearTimeout(timer);
		timer = window.setTimeout(refreshIfChanged, 500);
	}

	function currentPhone() {
		var billing = form.find('#billing_phone');
		var shipping = form.find('#shipping_phone');
		var billingPhone = billing.length ? normalize(billing.val()) : '';

		if (billingPhone) {
			return billingPhone;
		}

		return shipping.length ? normalize(shipping.val()) : '';
	}

	function refreshIfChanged() {
		var phone = currentPhone();
		if (phone !== lastAttempt) {
			classicFailures = 0;
			lastAttempt = phone;
		}
		// A matching field is finished only after updated_checkout confirms it.
		if (phone === confirmedPhone) {
			return;
		}
		if (phone === lastPhone && phoneRefresh) {
			return;
		}
		if (phone !== '' && classicFailures >= 3) {
			return;
		}

		if (phone === '') {
			lastPhone = '';
			confirmedPhone = '';
			// Leave phoneRefresh set when a request is still in flight. Its
			// response must not replace this restored description.
			restoreDescription();
			return;
		}

		if (lastPhone === '') {
			rememberDescription();
		}

		lastPhone = phone;
		phoneRefresh = true;
		ownUpdate = true;
		$(document.body).trigger('update_checkout');
	}

	function noteForeignUpdate() {
		if (ownUpdate) {
			ownUpdate = false;
			return;
		}
		// WooCommerce aborts the previous checkout request, so ours will not finish.
		if (!phoneRefresh) {
			return;
		}
		phoneRefresh = false;
		lastPhone = confirmedPhone;
		if (currentPhone() === confirmedPhone) {
			return;
		}
		window.clearTimeout(timer);
		timer = window.setTimeout(refreshIfChanged, 500);
	}

	function noteCheckoutAjaxError(event, jqXHR, settings, thrownError) {
		var url = settings && settings.url ? String(settings.url) : '';
		if (url.indexOf('update_order_review') === -1 || thrownError === 'abort') {
			return;
		}
		if (!phoneRefresh) {
			return;
		}
		phoneRefresh = false;
		classicFailures++;
		lastPhone = confirmedPhone;
		if (classicFailures >= 3) {
			return;
		}
		window.clearTimeout(timer);
		timer = window.setTimeout(refreshIfChanged, 500);
	}

	function noteUpdatedCheckout() {
		if (phoneRefresh) {
			var phone = currentPhone();
			var cleared = phone === '';
			// A newer number can be in the field while this response is for the
			// previous one. WooCommerce aborts that request when another update
			// starts; this covers the gap before that next update.
			var stale = phone !== lastPhone;
			phoneRefresh = false;
			if (cleared) {
				confirmedPhone = '';
				lastPhone = '';
				restoreDescription();
			} else if (!stale) {
				confirmedPhone = phone;
				classicFailures = 0;
			}
			return;
		}

		if (currentPhone() === '') {
			rememberDescription();
		}
	}

	function descriptionBox() {
		var selector = '.payment_box.payment_method_' + gatewayId;
		var box = form.find(selector).first();
		if (box.length) {
			return box;
		}
		return $(selector).first();
	}

	function methodLabel() {
		var selector = 'label[for="payment_method_' + gatewayId + '"]';
		var label = form.find(selector).first();
		if (label.length) {
			return label;
		}
		return $(selector).first();
	}

	function rememberDescription() {
		var box = descriptionBox();
		if (box.length) {
			baseline = box.html();
		}
		var offer = methodLabel().find('.torobpay-offer').first();
		offerBaseline = offer.length ? offer.clone() : null;
	}

	function restoreDescription() {
		restoreBox();
		restoreOffer();
	}

	function restoreBox() {
		if (baseline === null) {
			return;
		}
		var box = descriptionBox();
		if (!box.length) {
			var label = methodLabel();
			if (!label.length) {
				return;
			}
			var checked = form.find('#payment_method_' + gatewayId).is(':checked');
			box = $('<div>', {
				'class': 'payment_box payment_method_' + gatewayId
			});
			if (!checked) {
				box.hide();
			}
			label.after(box);
		}
		box.html(baseline);
	}

	function restoreOffer() {
		var label = methodLabel();
		if (!label.length) {
			return;
		}
		label.find('.torobpay-offer').remove();
		if (offerBaseline) {
			label.append(offerBaseline.clone());
		}
	}

	function normalize(raw) {
		var value = toEnglishDigits(raw || '').replace(/[^\d+]/g, '');

		if (/^\+980\d{10}$/.test(value)) {
			return '+98' + value.slice(4);
		}
		if (/^\+98\d{10}$/.test(value)) {
			return value;
		}
		if (/^98\d{10}$/.test(value)) {
			return '+' + value;
		}
		if (/^09\d{9}$/.test(value)) {
			return '+98' + value.slice(1);
		}
		if (/^9\d{9}$/.test(value)) {
			return '+98' + value;
		}
		return '';
	}

	function toEnglishDigits(value) {
		var persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
		var arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
		var index;

		for (index = 0; index < 10; index++) {
			value = value.split(persian[index]).join(String(index));
			value = value.split(arabic[index]).join(String(index));
		}

		return value;
	}

	function installCustomerUpdateGuard() {
		if (installCustomerUpdateGuard.done || typeof window.fetch !== 'function') {
			return;
		}
		installCustomerUpdateGuard.done = true;
		var fetchNow = window.fetch;
		var latest = 0;

		window.fetch = function (input, init) {
			if (!isCustomerUpdateRequest(input, init)) {
				return fetchNow.apply(this, arguments);
			}

			var id = ++latest;
			var sentPhone = typedPhone();
			var pending = fetchNow.apply(this, arguments);
			var unreadableCustomerResponse = function (response) {
				// The body cannot be checked. Keep it only when the field is
				// still the number this request was sent for.
				if (id !== latest || typedPhone() !== sentPhone) {
					throw staleCustomerUpdate();
				}
				return response;
			};

			return Promise.resolve(pending).then(function (response) {
				if (id !== latest) {
					throw staleCustomerUpdate();
				}
				var parsed;
				try {
					parsed = response.clone().json();
				} catch (error) {
					return unreadableCustomerResponse(response);
				}
				return parsed.then(function (data) {
					// The field can change while this request is still the latest one
					// sent. Applying it would flash the previous number's result.
					if (id !== latest || !customerResponseMatchesField(data)) {
						throw staleCustomerUpdate();
					}
					return response;
				}, function () {
					return unreadableCustomerResponse(response);
				});
			});
		};
	}

	function isCustomerUpdateRequest(input, init) {
		var url = '';
		if (typeof input === 'string') {
			url = input;
		} else if (input && typeof input.url === 'string') {
			url = input.url;
		}
		var body = init && typeof init.body === 'string' ? init.body : '';
		return url.indexOf('cart/update-customer') !== -1 || body.indexOf('cart/update-customer') !== -1;
	}

	function staleCustomerUpdate() {
		// api-fetch turns any other fetch rejection into the checkout notice
		// "Could not get a valid response from the server." AbortError is left
		// alone, and WooCommerce does not show that as a checkout error.
		var error;
		try {
			error = new DOMException('torobpay-stale-customer-update', 'AbortError');
		} catch (ignored) {
			error = new Error('torobpay-stale-customer-update');
			error.name = 'AbortError';
		}
		try {
			error.torobpayStale = true;
		} catch (ignored) {}
		return error;
	}

	function typedPhone() {
		if (document.getElementById('billing_phone') || document.getElementById('shipping_phone')) {
			return currentPhone();
		}
		return blockPhone().normalized;
	}

	function cartSnapshot(body) {
		if (!body || typeof body !== 'object') {
			return null;
		}
		if (body.billing_address || body.shipping_address) {
			return body;
		}
		// A failed update can still carry the cart that would be painted.
		var nested = body.data && body.data.cart;
		if (nested && (nested.billing_address || nested.shipping_address)) {
			return nested;
		}
		return null;
	}

	function cartsInCustomerResponse(data) {
		var bodies = [];
		if (data && data.responses && data.responses.length) {
			data.responses.forEach(function (item) {
				if (item && item.body) {
					bodies.push(item.body);
				}
			});
		} else if (data) {
			bodies.push(data);
		}
		var carts = [];
		bodies.forEach(function (body) {
			var cart = cartSnapshot(body);
			if (cart) {
				carts.push(cart);
			}
		});
		return carts;
	}

	function customerResponseMatchesField(data) {
		var carts = cartsInCustomerResponse(data);
		if (!carts.length) {
			// No cart snapshot, so this body has no payment copy to paint.
			// Let it through so a real update error is still reported.
			return true;
		}
		var typed = typedPhone();
		var linked = sameAddressForBilling();
		return carts.every(function (cart) {
			var billing = cart && cart.billing_address ? normalize(cart.billing_address.phone) : '';
			var shipping = cart && cart.shipping_address ? normalize(cart.shipping_address.phone) : '';
			// Billing wins for eligibility. A shipping-only update still carries
			// the previous billing number, and that response is the old result.
			if (linked) {
				return (billing || shipping) === typed;
			}
			if (!typed) {
				return !billing && !shipping;
			}
			return billing === typed || shipping === typed;
		});
	}

	function sameAddressForBilling() {
		if (!window.wp || !window.wp.data || typeof window.wp.data.select !== 'function') {
			return false;
		}
		var key = (window.wc && window.wc.wcBlocksData && window.wc.wcBlocksData.CHECKOUT_STORE_KEY) || 'wc/store/checkout';
		var select;
		try {
			select = window.wp.data.select(key);
		} catch (error) {
			return false;
		}
		if (!select || typeof select.getUseShippingAsBilling !== 'function') {
			return false;
		}
		try {
			return !!select.getUseShippingAsBilling();
		} catch (error) {
			return false;
		}
	}

	function cartActions() {
		if (!window.wp || !window.wp.data || typeof window.wp.data.dispatch !== 'function') {
			return null;
		}
		try {
			return window.wp.data.dispatch(cartStoreKey());
		} catch (error) {
			return null;
		}
	}

	function phonePayload(key, value) {
		var data = {};
		data[key] = { phone: value };
		if (!sameAddressForBilling()) {
			return data;
		}
		data.billing_address = { phone: value };
		data.shipping_address = { phone: value };
		return data;
	}

	function bindBlockPhone() {
		var last = blockPhone().normalized;
		var lastKey = 'billing_address';
		var timer = null;
		var pushGen = 0;
		var settledPhone = last;
		var storeRetries = 0;
		var storeWait = false;
		var pushFailures = 0;

		installCustomerUpdateGuard();

		$(document.body).on('input change', '#billing-phone, #shipping-phone', scheduleBlock);
		// A number already in the field has not been sent yet. Same as classic
		// checkout: do not treat it as settled until this push returns.
		var seededPhone = last;
		last = '';
		settledPhone = '';
		if (seededPhone) {
			pushBlockPhone();
		}

		function scheduleBlock() {
			window.clearTimeout(timer);
			storeRetries = 0;
			pushFailures = 0;
			timer = window.setTimeout(pushBlockPhone, 500);
		}

		function retryWhenCartStoreReady() {
			if (storeWait || !window.wp || !window.wp.data || typeof window.wp.data.subscribe !== 'function') {
				return;
			}
			storeWait = true;
			var unsubscribe = window.wp.data.subscribe(function () {
				var actions = cartActions();
				if (!actions || typeof actions.updateCustomerData !== 'function') {
					return;
				}
				storeWait = false;
				if (typeof unsubscribe === 'function') {
					unsubscribe();
				}
				storeRetries = 0;
				pushFailures = 0;
				pushBlockPhone();
			});
		}

		function pushBlockPhone() {
			var state = blockPhone();
			// Matching the last attempt is not enough. A failed push leaves
			// that attempt equal to the field while settledPhone stays behind.
			if (state.normalized === last && state.normalized === settledPhone) {
				return;
			}

			var actions = cartActions();
			if (!actions || typeof actions.updateCustomerData !== 'function') {
				// Remembering the number before the cart store exists makes the
				// next attempt look unchanged, so the push never runs.
				if (storeRetries < 8) {
					storeRetries++;
					window.clearTimeout(timer);
					timer = window.setTimeout(pushBlockPhone, 500);
				} else {
					retryWhenCartStoreReady();
				}
				return;
			}
			storeRetries = 0;

			var previousKey = lastKey;
			last = state.normalized;
			lastKey = state.key;

			var data = state.normalized ? phonePayload(state.key, state.value) : phonePayload(previousKey, '');

			var gen = ++pushGen;
			var finish = function () {
				if (gen !== pushGen) {
					return;
				}
				pushFailures = 0;
				if (blockPhone().normalized !== state.normalized) {
					return;
				}
				settledPhone = state.normalized;
			};
			var failPush = function () {
				if (gen !== pushGen) {
					return;
				}
				// A discarded or failed update did not refresh eligibility.
				// Settling here would show the previous result for this number.
				// Stop after a few tries: another identical push is unlikely to
				// help, and endless retries would keep hitting the Store API.
				if (blockPhone().normalized !== state.normalized || pushFailures >= 3) {
					return;
				}
				pushFailures++;
				window.clearTimeout(timer);
				timer = window.setTimeout(pushBlockPhone, 500);
			};
			var request;
			try {
				request = actions.updateCustomerData(data);
			} catch (error) {
				failPush();
				return;
			}
			if (request && typeof request.then === 'function') {
				request.then(finish, failPush);
			} else {
				failPush();
			}
		}
	}

	function blockPhone() {
		var billing = document.getElementById('billing-phone');
		var shipping = document.getElementById('shipping-phone');
		var billingValue = billing ? billing.value : '';
		var shippingValue = shipping ? shipping.value : '';

		if (normalize(billingValue)) {
			return { key: 'billing_address', value: billingValue, normalized: normalize(billingValue) };
		}
		if (normalize(shippingValue)) {
			return { key: 'shipping_address', value: shippingValue, normalized: normalize(shippingValue) };
		}

		return { key: 'billing_address', value: '', normalized: '' };
	}

	function cartStoreKey() {
		return (window.wc && window.wc.wcBlocksData && window.wc.wcBlocksData.CART_STORE_KEY) || 'wc/store/cart';
	}
})(jQuery);
