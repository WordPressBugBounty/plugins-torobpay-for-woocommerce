// Rial total: ceil(unit × qty × factor), same as torobpay_get_amount_by_currency().
var torobpayWidgetThreshold = (function () {
    function parseUnitPrice(value) {
        var price = parseFloat(value);
        return (isFinite(price) && price > 0) ? price : null;
    }

    function isOverDisplayThreshold(unitPrice, quantity, currencyFactor, threshold) {
        var factor = parseFloat(currencyFactor);
        var limit = parseFloat(threshold);
        var price = parseUnitPrice(unitPrice);
        if (price === null || !isFinite(factor) || factor <= 0 || !isFinite(limit)) {
            return false;
        }
        var qty = quantity > 0 ? quantity : 1;
        var scaled = price * qty * factor;
        if (!isFinite(scaled)) {
            return false;
        }
        return Math.ceil(scaled) >= limit;
    }

    function showsInstallmentPrice(unitPrice, quantity, currencyFactor, minAmount, threshold) {
        var price = parseUnitPrice(unitPrice);
        var factor = parseFloat(currencyFactor);
        if (price === null || !isFinite(factor) || factor <= 0) {
            return false;
        }
        var qty = quantity > 0 ? quantity : 1;
        var scaled = price * qty * factor;
        if (!isFinite(scaled)) {
            return false;
        }
        var rial = Math.ceil(scaled);
        var min = parseFloat(minAmount);
        if (!isFinite(min)) {
            min = 200000;
        }
        if (rial < min) {
            return false;
        }
        var limit = parseFloat(threshold);
        return !(isFinite(limit) && rial >= limit);
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatStorePrice(amount, currency) {
        currency = currency || {};
        var decimals = parseInt(currency.decimals, 10);
        if (!isFinite(decimals) || decimals < 0) {
            decimals = 0;
        }
        var decimalSeparator = currency.decimalSeparator != null ? String(currency.decimalSeparator) : '.';
        var thousandSeparator = currency.thousandSeparator != null ? String(currency.thousandSeparator) : ',';
        var negative = amount < 0;
        var factor = Math.pow(10, decimals);
        var rounded = Math.round((Math.abs(amount) + Number.EPSILON) * factor) / factor;
        var fixed = rounded.toFixed(decimals);
        var pieces = fixed.split('.');
        pieces[0] = pieces[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandSeparator);
        var number = pieces.join(decimalSeparator);
        if (currency.trimZeros && decimals > 0 && pieces[1]) {
            var trimmed = pieces[1].replace(/0+$/, '');
            number = trimmed.length ? pieces[0] + decimalSeparator + trimmed : pieces[0];
        }
        if (negative) {
            number = '-' + number;
        }
        var symbolHtml = '<span class="woocommerce-Price-currencySymbol">' + escapeHtml(currency.symbol != null ? currency.symbol : '') + '</span>';
        var format = currency.format ? String(currency.format) : '%1$s%2$s';
        var formatted = format.replace('%1$s', symbolHtml).replace('%2$s', escapeHtml(number));
        return '<span class="woocommerce-Price-amount amount"><bdi>' + formatted + '</bdi></span>';
    }

    return {
        parseUnitPrice: parseUnitPrice,
        isOverDisplayThreshold: isOverDisplayThreshold,
        showsInstallmentPrice: showsInstallmentPrice,
        formatStorePrice: formatStorePrice
    };
}());

if (typeof module === 'object' && module.exports) {
    module.exports = torobpayWidgetThreshold;
}

if (typeof jQuery === 'function') {
jQuery(function($) {
    $(document).on('click', '.torobpay-widget', function(e) {
        var $widget = $(this);
        if ($widget.hasClass('has-torobpay-promo')) {
            if (!$(e.target).closest('.torobpay-widget-promo').length) {
                $widget.find('.torobpay-widget-promo').first().trigger('click');
            }
            return;
        }
        var $btn = $('button.single_add_to_cart_button, input.single_add_to_cart_button').first();

        if ($btn.length && !$btn.is('.disabled, [disabled]')) {
            $btn.trigger('click');
        } else {
            var $form = $('form.cart, form.variations_form').first();
            if ($form.length) {
                $form[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            $widget.addClass('torobpay-widget-highlight');
            setTimeout(function() { $widget.removeClass('torobpay-widget-highlight'); }, 500);
        }
    });

    var config = window.torobpayVariationWidget || {};
    var $widgetContainer = $('#torobpay-variation-widget-container');
    var $cartForm = $('form.cart').first();
    var $variationForm = $('.variations_form');
    var currentVariation = null;
    var qtyDebounceTimer = null;
    var productId = parseInt(config.productId, 10) || ($variationForm.data('product_id') || 0);
    var productType = config.productType || ($variationForm.length ? 'variable' : 'simple');
    var bakedQty = parseInt(config.initialQuantity, 10);
    if (!bakedQty || bakedQty < 1) {
        bakedQty = 1;
    }
    var activeUnitPrice = torobpayWidgetThreshold.parseUnitPrice(config.unitPrice);

    if (!$widgetContainer.length || !productId) {
        return;
    }

    function livePreviewEnabled() {
        return config.livePreview !== false && config.livePreview !== 0 && config.livePreview !== '0';
    }

    function getQuantity() {
        var $qty = $cartForm.find('input.qty').first();
        if (!$qty.length) {
            $qty = $('form.cart input.qty').first();
        }
        var qty = parseInt($qty.val(), 10);
        return (qty && qty > 0) ? qty : 1;
    }

    function previewQuantity() {
        if (!livePreviewEnabled()) {
            return 1;
        }
        return getQuantity();
    }

    function getPreviewVariationId() {
        var fromConfig = parseInt(config.previewVariationId, 10);
        if (fromConfig) {
            return fromConfig;
        }
        var fromWidget = parseInt($widgetContainer.find('.torobpay-widget').first().attr('data-variation-id'), 10);
        return fromWidget || 0;
    }

    function pricedTitleHtml(priceHtml) {
        var template = config.pricedTitle || 'Each installment with TorobPay: %s';
        var token = '\u0000PRICE\u0000';
        var withToken = String(template).replace('%1$s', token).replace('%s', token);
        if (withToken.indexOf(token) === -1) {
            withToken += ' ' + token;
        }
        return withToken
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(token, priceHtml);
    }

    function renderInstallment(variationId) {
        var $widget = $widgetContainer.find('.torobpay-widget').first();
        if (!$widget.length) {
            return;
        }
        if (variationId) {
            $widget.attr('data-variation-id', String(variationId));
        }

        var quantity = previewQuantity();
        var showPrice = torobpayWidgetThreshold.showsInstallmentPrice(
            activeUnitPrice,
            quantity,
            config.currencyFactor,
            config.minAmount,
            config.threshold
        );
        var $title = $widget.find('.torobpay-widget-title').first();
        if (showPrice) {
            var installment = activeUnitPrice * quantity / 4;
            $widget.attr('data-show-price', '1');
            if ($title.length) {
                $title.html(pricedTitleHtml(torobpayWidgetThreshold.formatStorePrice(installment, config.currency)));
            }
        } else {
            $widget.attr('data-show-price', '0');
            if ($title.length) {
                $title.text(config.plainTitle || '');
            }
        }
        $widgetContainer.removeClass('torobpay-loading').show();
    }

    function resetWidgetToInitial() {
        currentVariation = null;
        activeUnitPrice = torobpayWidgetThreshold.parseUnitPrice(config.unitPrice);
        renderInstallment(getPreviewVariationId());
    }

    function updateWidget(variation) {
        var variationId = 0;

        if (productType === 'variable') {
            if (!variation || !variation.is_in_stock || !variation.variation_id) {
                resetWidgetToInitial();
                return;
            }

            variationId = parseInt(variation.variation_id, 10);
            if (!variationId) {
                resetWidgetToInitial();
                return;
            }

            currentVariation = variation;
            activeUnitPrice = torobpayWidgetThreshold.parseUnitPrice(variation && variation.display_price);
        }

        renderInstallment(variationId);
    }

    function refreshForQuantity() {
        if (!livePreviewEnabled()) {
            return;
        }
        if (productType === 'variable') {
            if (currentVariation) {
                updateWidget(currentVariation);
                return;
            }
            renderInstallment(getPreviewVariationId());
            return;
        }
        updateWidget(null);
    }

    function scheduleQuantityRefresh() {
        if (qtyDebounceTimer) {
            clearTimeout(qtyDebounceTimer);
        }
        qtyDebounceTimer = setTimeout(function() {
            qtyDebounceTimer = null;
            refreshForQuantity();
        }, 180);
    }

    if (productType === 'variable' && $variationForm.length) {
        $variationForm.on('found_variation', function(event, variation) {
            updateWidget(variation);
        }).on('reset_data', function() {
            resetWidgetToInitial();
        });
    }

    if (livePreviewEnabled() && getQuantity() !== bakedQty) {
        refreshForQuantity();
    }

    $(document).on('change input', 'form.cart input.qty', function() {
        scheduleQuantityRefresh();
    });
});
}
