// ceil(unit × qty × factor) >= threshold, same as torobpay_get_amount_by_currency().
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

    return {
        parseUnitPrice: parseUnitPrice,
        isOverDisplayThreshold: isOverDisplayThreshold
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
    var initialHtml = $widgetContainer.html();
    var currentXhr = null;
    var requestedKey = null;
    var renderedKey = null;
    var currentVariation = null;
    var qtyDebounceTimer = null;
    var productId = parseInt(config.productId, 10) || ($variationForm.data('product_id') || 0);
    var productType = config.productType || ($variationForm.length ? 'variable' : 'simple');
    var bakedQty = parseInt(config.initialQuantity, 10);
    if (!bakedQty || bakedQty < 1) {
        bakedQty = 1;
    }
    var initialHtmlQty = bakedQty;
    var activeUnitPrice = torobpayWidgetThreshold.parseUnitPrice(config.unitPrice);

    if (!$widgetContainer.length || !productId) {
        return;
    }

    function applyOverThresholdState(variationId) {
        if (!config.fallbackHtml) {
            return false;
        }
        var $widget = $widgetContainer.find('.torobpay-widget').first();
        // "0" is already the price-less copy. Anything else is rebuilt from it.
        if (!$widget.length || $widget.attr('data-show-price') !== '0') {
            $widgetContainer.html(config.fallbackHtml).show();
            $widget = $widgetContainer.find('.torobpay-widget').first();
            if (!$widget.length) {
                return false;
            }
        }
        if (variationId) {
            $widget.attr('data-variation-id', String(variationId));
        }
        $widget.attr('data-show-price', '0');
        $widgetContainer.removeClass('torobpay-loading').show();
        return true;
    }

    function getQuantity() {
        var $qty = $cartForm.find('input.qty').first();
        if (!$qty.length) {
            $qty = $('form.cart input.qty').first();
        }
        var qty = parseInt($qty.val(), 10);
        return (qty && qty > 0) ? qty : 1;
    }

    function requestKey(variationId, quantity) {
        return String(variationId || 0) + ':' + String(quantity);
    }

    function getPreviewVariationId() {
        var fromConfig = parseInt(config.previewVariationId, 10);
        if (fromConfig) {
            return fromConfig;
        }
        var fromWidget = parseInt($widgetContainer.find('.torobpay-widget').first().attr('data-variation-id'), 10);
        return fromWidget || 0;
    }

    function showUnavailable() {
        // Price-less copy when the total is under 200,000 rial or the refetch fails. The widget stays.
        var fallback = config.fallbackHtml || '';
        if (fallback) {
            $widgetContainer.html(fallback).show();
        } else {
            $widgetContainer.empty().hide();
        }
        $widgetContainer.removeClass('torobpay-loading');
    }

    function rememberPreviewHtml(html, quantity) {
        initialHtml = html;
        initialHtmlQty = quantity;
    }

    function resetWidgetToInitial() {
        if (currentXhr) {
            currentXhr.abort();
            currentXhr = null;
        }
        requestedKey = null;
        renderedKey = null;
        currentVariation = null;
        activeUnitPrice = torobpayWidgetThreshold.parseUnitPrice(config.unitPrice);

        var qty = getQuantity();
        var previewId = getPreviewVariationId();

        // Snapshot matches only the quantity it was captured at.
        if (previewId && qty === initialHtmlQty && initialHtml) {
            requestedKey = requestKey(previewId, qty);
            renderedKey = requestedKey;
            $widgetContainer.addClass('torobpay-loading');
            $widgetContainer.html(initialHtml).show();
            setTimeout(function() {
                $widgetContainer.removeClass('torobpay-loading');
            }, 220);
            return;
        }

        if (previewId) {
            fetchWidget(previewId, qty);
            return;
        }

        showUnavailable();
    }

    function fetchWidget(variationId, quantity) {
        var key = requestKey(variationId, quantity);

        if (key === requestedKey || key === renderedKey) {
            return;
        }

        // Price-less mode at or above the display threshold. The widget stays.
        if (torobpayWidgetThreshold.isOverDisplayThreshold(activeUnitPrice, quantity, config.currencyFactor, config.threshold) && applyOverThresholdState(variationId)) {
            requestedKey = key;
            renderedKey = key;
            if (currentXhr) {
                currentXhr.abort();
                currentXhr = null;
            }
            if (!currentVariation) {
                rememberPreviewHtml($widgetContainer.html(), quantity);
            }
            return;
        }

        requestedKey = key;

        if (currentXhr) {
            currentXhr.abort();
            currentXhr = null;
        }

        $widgetContainer.addClass('torobpay-loading').show();
        currentXhr = $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'torobpay_get_variation_widget',
                variation_id: variationId,
                product_id: productId,
                quantity: quantity,
                nonce: config.nonce
            },
            success: function(response) {
                if (requestedKey !== key) {
                    return;
                }
                renderedKey = key;
                if (response && response.success && response.data && response.data.html) {
                    $widgetContainer.html(response.data.html).show();
                    $widgetContainer.removeClass('torobpay-loading');
                    if (!currentVariation) {
                        rememberPreviewHtml(response.data.html, quantity);
                    }
                } else {
                    showUnavailable();
                }
            },
            error: function(_jqXHR, textStatus) {
                if (textStatus === 'abort') {
                    return;
                }
                if (requestedKey !== key) {
                    return;
                }
                renderedKey = key;
                showUnavailable();
            },
            complete: function() {
                if (requestedKey === key) {
                    currentXhr = null;
                }
            }
        });
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

        fetchWidget(variationId, getQuantity());
    }

    function refreshForQuantity() {
        if (productType === 'variable') {
            if (currentVariation) {
                updateWidget(currentVariation);
                return;
            }
            // No selection yet. Reprice the preview variation.
            var previewId = getPreviewVariationId();
            if (previewId) {
                fetchWidget(previewId, getQuantity());
            }
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

    // Qty input can differ from the server-rendered initial quantity.
    if (getQuantity() !== bakedQty) {
        refreshForQuantity();
    }

    $(document).on('change input', 'form.cart input.qty', function() {
        requestedKey = null;
        renderedKey = null;
        scheduleQuantityRefresh();
    });
});
}
