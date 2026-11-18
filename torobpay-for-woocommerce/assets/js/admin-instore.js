/**
 * TorobPay in-store (POS) order modal: create form then payment QR.
 */
jQuery(function($) {
    'use strict';

    var __ = ( window.wp && wp.i18n && wp.i18n.__ ) ? window.wp.i18n.__ : function( s ) { return s; };
    var cfg = window.torobpayInstore || {};
    var iconUrl = cfg.iconUrl || '';
    var searchTimer = null;
    var previewTimer = null;
    var qrInstance = null;
    var watchTimer = null;
    var watchOrderId = 0;
    var watchState = '';
    var watchInFlight = false;
    var watchActive = false;
    var shouldReloadOnClose = false;
    var createInFlight = false;
    var WATCH_LOCAL_MS = 3000;

    if ( ! $('#torobpay-instore-modal-overlay').length ) {
        var amountHint = __( 'Enter an amount instead of adding products.', 'torobpay-for-woocommerce' );
        var amountHintAttr = $('<div/>').text(amountHint).html().replace(/"/g, '&quot;');
        var taxEnabled = !! cfg.taxEnabled;
        var taxTotalField = '';
        if ( taxEnabled ) {
            taxTotalField =
                '<div class="torobpay-field-group" id="torobpay-instore-tax-wrap">' +
                    '<label>' + __( 'Tax', 'torobpay-for-woocommerce' ) + '</label>' +
                    '<input type="text" id="torobpay-instore-tax" readonly value="0" />' +
                '</div>';
        }

        $('body').append(
            '<div id="torobpay-instore-modal-overlay" class="torobpay-modal-overlay" style="display:none;">' +
                '<div class="torobpay-modal" role="dialog" aria-modal="true" aria-labelledby="torobpay-instore-modal-title">' +
                    '<div class="torobpay-modal-header">' +
                        '<h2><img class="torobpay-modal-title-icon" src="' + iconUrl + '" alt="" aria-hidden="true" />' +
                        '<span id="torobpay-instore-modal-title">' + __( 'Add in-store order', 'torobpay-for-woocommerce' ) + '</span></h2>' +
                        '<button type="button" class="torobpay-modal-close" title="' + __( 'Close', 'torobpay-for-woocommerce' ) + '" aria-label="' + __( 'Close', 'torobpay-for-woocommerce' ) + '">&times;</button>' +
                    '</div>' +
                    '<div class="torobpay-modal-body">' +
                        '<div id="torobpay-instore-form-step">' +
                            '<div class="torobpay-instore-form-grid">' +
                                '<div class="torobpay-field-group">' +
                                    '<label for="torobpay-instore-phone">' + __( 'Customer phone number', 'torobpay-for-woocommerce' ) + ' <span class="required" aria-hidden="true">*</span></label>' +
                                    '<input type="text" id="torobpay-instore-phone" class="regular-text" autocomplete="off" required aria-required="true" />' +
                                '</div>' +
                                '<div class="torobpay-field-group torobpay-instore-amount-group">' +
                                    '<label for="torobpay-instore-amount" class="torobpay-instore-amount-label">' +
                                        __( 'Total amount (optional)', 'torobpay-for-woocommerce' ) +
                                        '<span class="torobpay-tooltip" data-tip="' + amountHintAttr + '" title="' + amountHintAttr + '">?</span>' +
                                    '</label>' +
                                    '<input type="text" id="torobpay-instore-amount" class="regular-text" inputmode="decimal" autocomplete="off" />' +
                                '</div>' +
                                '<div class="torobpay-field-group">' +
                                    '<label for="torobpay-instore-name">' + __( 'Customer name (optional)', 'torobpay-for-woocommerce' ) + '</label>' +
                                    '<input type="text" id="torobpay-instore-name" class="regular-text" autocomplete="off" />' +
                                '</div>' +
                                '<div class="torobpay-field-group">' +
                                    '<label for="torobpay-instore-coupon">' + __( 'Discount coupon code', 'torobpay-for-woocommerce' ) + '</label>' +
                                    '<input type="text" id="torobpay-instore-coupon" class="regular-text" autocomplete="off" />' +
                                '</div>' +
                            '</div>' +
                            '<table id="torobpay-instore-items-table" class="widefat torobpay-items-table">' +
                                '<thead><tr>' +
                                    '<th>' + __( 'Product', 'torobpay-for-woocommerce' ) + '</th>' +
                                    '<th>SKU</th>' +
                                    '<th style="width:80px;">' + __( 'Qty', 'torobpay-for-woocommerce' ) + '</th>' +
                                    '<th>' + __( 'Total', 'torobpay-for-woocommerce' ) + '</th>' +
                                    '<th style="width:50px;"></th>' +
                                '</tr></thead>' +
                                '<tbody>' +
                                    '<tr class="torobpay-instore-items-empty no-items"><td colspan="5">' +
                                        __( 'No products added yet. Search below to add items, or enter a total amount instead.', 'torobpay-for-woocommerce' ) +
                                    '</td></tr>' +
                                '</tbody>' +
                            '</table>' +
                            '<div id="torobpay-instore-add-product-section" class="trb-add-product-section">' +
                                '<div class="trb-add-product-row">' +
                                    '<input type="text" id="torobpay-instore-product-search-input" class="regular-text" placeholder="' + __( 'Search product (name or SKU)…', 'torobpay-for-woocommerce' ) + '" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="torobpay-instore-product-search-results" aria-haspopup="listbox" />' +
                                    '<button type="button" class="button trb-icon-btn" id="torobpay-instore-add-product-btn" disabled title="' + __( 'Add product', 'torobpay-for-woocommerce' ) + '"><span class="dashicons dashicons-plus-alt2" style="line-height: normal;"></span></button>' +
                                '</div>' +
                                '<div id="torobpay-instore-product-search-results" role="listbox" style="display:none;"></div>' +
                            '</div>' +
                            '<div class="torobpay-instore-totals">' +
                                '<div class="torobpay-field-group">' +
                                    '<label>' + __( 'Discount', 'torobpay-for-woocommerce' ) + '</label>' +
                                    '<input type="text" id="torobpay-instore-discount" readonly value="0" />' +
                                '</div>' +
                                taxTotalField +
                                '<div class="torobpay-field-group">' +
                                    '<label for="torobpay-instore-total">' + __( 'Total', 'torobpay-for-woocommerce' ) + '</label>' +
                                    '<input type="text" id="torobpay-instore-total" readonly value="0" />' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                        '<div id="torobpay-instore-pay-step" style="display:none;">' +
                            '<p id="torobpay-instore-pay-heading"></p>' +
                            '<p id="torobpay-instore-pay-note" class="torobpay-instore-pay-note">' + __( 'An SMS with the payment link has been sent to the customer. This QR code is a backup if they need another way to pay.', 'torobpay-for-woocommerce' ) + '</p>' +
                            '<div id="torobpay-instore-watch-status" class="torobpay-instore-watch is-pending" role="status"></div>' +
                            '<div class="torobpay-instore-qr-wrap"><div id="torobpay-instore-qr"></div></div>' +
                            '<p class="torobpay-instore-pay-meta" id="torobpay-instore-pay-total"></p>' +
                            '<div class="torobpay-instore-url-row">' +
                                '<input type="text" id="torobpay-instore-pay-url" readonly />' +
                                '<button type="button" class="button" id="torobpay-instore-copy-url">' + __( 'Copy link', 'torobpay-for-woocommerce' ) + '</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="torobpay-modal-footer">' +
                        '<button type="button" class="button button-primary torobpay-button-primary" id="torobpay-instore-create-btn">' + __( 'Create order', 'torobpay-for-woocommerce' ) + '</button>' +
                        '<button type="button" class="button button-primary torobpay-button-primary" id="torobpay-instore-retry-btn" style="display:none;">' + __( 'Retry payment', 'torobpay-for-woocommerce' ) + '</button>' +
                        '<button type="button" class="button" id="torobpay-instore-done-btn" style="display:none;">' + __( 'Close', 'torobpay-for-woocommerce' ) + '</button>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );
    }

    var $instoreOverlay = $('#torobpay-instore-modal-overlay');
    var instoreModalA11y = window.torobpayBindModalA11y
        ? window.torobpayBindModalA11y($instoreOverlay, {
            namespace: 'torobpay-instore-modal',
            labelledBy: 'torobpay-instore-modal-title',
            closeOnOverlay: true,
            onClose: closeModal,
            initialFocus: function() {
                if ( $('#torobpay-instore-form-step').is(':visible') ) {
                    return $('#torobpay-instore-phone');
                }
                var $retry = $('#torobpay-instore-retry-btn');
                if ( $retry.is(':visible') ) {
                    return $retry;
                }
                return $('#torobpay-instore-done-btn');
            }
        })
        : null;

    function formatNumber(n) {
        return window.torobpayFormatNumber ? window.torobpayFormatNumber(n) : Number(n).toLocaleString();
    }

    function parseInstoreAmount(value) {
        return window.torobpayParseAmount ? window.torobpayParseAmount(value) : 0;
    }

    function customAmountValue() {
        return parseInstoreAmount( $('#torobpay-instore-amount').val() );
    }

    function hasCollectedItems() {
        return collectedItems().length > 0;
    }

    function syncPricingMode() {
        var hasItems = hasCollectedItems();
        var amount = customAmountValue();
        var amountOnly = ! hasItems && amount > 0;
        $('#torobpay-instore-amount').prop('disabled', hasItems);
        $('#torobpay-instore-coupon').prop('disabled', amountOnly);
        if ( amountOnly ) {
            $('#torobpay-instore-coupon').val('');
        }
        if ( ! hasItems ) {
            $('#torobpay-instore-discount').val(formatNumber(0));
            $('#torobpay-instore-tax').val(formatNumber(0));
            $('#torobpay-instore-total').val(formatNumber(amount));
        }
        $('#torobpay-instore-product-search-input, #torobpay-instore-add-product-btn').prop('disabled', amountOnly);
        $('#torobpay-instore-add-product-section').toggleClass('is-disabled', amountOnly);
        if ( amountOnly ) {
            hideInstoreProductResults();
        }
    }

    function showToast(message, type, options) {
        if ( window.torobpayShowToast ) {
            return window.torobpayShowToast(message, type, options);
        }
    }

    function disableInstoreIfUnavailable(response, xhr) {
        var data = ( response && response.data ) || ( xhr && xhr.responseJSON && xhr.responseJSON.data ) || null;
        if ( ! data || data.instore_enabled !== false ) {
            return false;
        }
        showToast(data.message || __( 'In-store selling is not available for this shop.', 'torobpay-for-woocommerce' ), 'error');
        $('#torobpay-add-instore-order, .torobpay-show-instore-qr').remove();
        if ( $instoreOverlay.is(':visible') ) {
            shouldReloadOnClose = false;
            closeModal();
        }
        return true;
    }

    function ajaxErrorMessage(xhr, fallback) {
        fallback = fallback || __( 'Server connection error.', 'torobpay-for-woocommerce' );
        try {
            if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
                return String(xhr.responseJSON.data.message);
            }
        } catch ( e ) { /* ignore */ }
        return fallback;
    }

    function collectedItems() {
        var items = [];
        $('#torobpay-instore-items-table tbody tr').not('.torobpay-instore-items-empty').each(function() {
            var productId = parseInt($(this).data('productId'), 10) || 0;
            var qty = parseInt($(this).find('.torobpay-instore-item-qty').val(), 10) || 0;
            if ( productId && qty > 0 ) {
                items.push({ product_id: productId, quantity: qty });
            }
        });
        return items;
    }

    function itemsEmptyRowHtml() {
        return '<tr class="torobpay-instore-items-empty no-items"><td colspan="5">' +
            __( 'No products added yet. Search below to add items, or enter a total amount instead.', 'torobpay-for-woocommerce' ) +
            '</td></tr>';
    }

    function syncItemsEmptyState() {
        var $tbody = $('#torobpay-instore-items-table tbody');
        var $empty = $tbody.find('tr.torobpay-instore-items-empty');
        var hasItems = $tbody.children('tr').not('.torobpay-instore-items-empty').length > 0;
        if ( hasItems ) {
            $empty.remove();
            return;
        }
        if ( ! $empty.length ) {
            $tbody.append( itemsEmptyRowHtml() );
        }
    }

    function resetFormStep() {
        $('#torobpay-instore-phone, #torobpay-instore-name, #torobpay-instore-coupon, #torobpay-instore-amount').val('');
        $('#torobpay-instore-items-table tbody').empty();
        syncItemsEmptyState();
        $('#torobpay-instore-discount, #torobpay-instore-tax, #torobpay-instore-total').val(formatNumber(0));
        $('#torobpay-instore-product-search-input').val('');
        hideInstoreProductResults();
        syncPricingMode();
        stopWatch();
        watchOrderId = 0;
        watchState = '';
        shouldReloadOnClose = false;
        $('#torobpay-instore-form-step').show();
        $('#torobpay-instore-pay-step').hide();
        $('#torobpay-instore-create-btn').show().prop('disabled', false).removeClass('trb-loading');
        $('#torobpay-instore-retry-btn').hide().prop('disabled', false).removeClass('trb-loading');
        $('#torobpay-instore-modal-title').text(__( 'Add in-store order', 'torobpay-for-woocommerce' ));
        $('#torobpay-instore-pay-heading').show().text('');
        setWatchUi( 'pending', __( 'Waiting for the customer to pay…', 'torobpay-for-woocommerce' ), false );
        $('#torobpay-instore-done-btn').hide();
    }

    function showInstoreOverlay() {
        $instoreOverlay.stop(true, true).fadeIn(200);
        if ( instoreModalA11y ) {
            instoreModalA11y.activate();
        }
    }

    function openModal() {
        if ( createInFlight ) {
            showInstoreOverlay();
            return;
        }
        resetFormStep();
        showInstoreOverlay();
    }

    function closeModal() {
        stopWatch();
        if ( instoreModalA11y ) {
            instoreModalA11y.deactivate();
        }
        $instoreOverlay.stop(true, true).fadeOut(200);
        if ( shouldReloadOnClose ) {
            window.location.reload();
        }
    }

    function stopWatch() {
        watchActive = false;
        if ( watchTimer ) {
            clearTimeout( watchTimer );
            watchTimer = null;
        }
        watchInFlight = false;
    }

    function setWatchUi( state, message, canRetry ) {
        var $status = $('#torobpay-instore-watch-status');
        $status
            .removeClass('is-pending is-paid is-failed is-closed')
            .addClass('is-' + state)
            .text(message || '');

        var waiting = ( state === 'pending' );
        $('.torobpay-instore-qr-wrap, .torobpay-instore-url-row, #torobpay-instore-pay-note').toggle( waiting );
        $('#torobpay-instore-retry-btn').toggle( !! canRetry );
        $('#torobpay-instore-done-btn').show();
    }

    function startWatch( orderId ) {
        stopWatch();
        watchOrderId = parseInt( orderId, 10 ) || 0;
        watchState = 'pending';
        if ( ! watchOrderId ) {
            return;
        }
        setWatchUi( 'pending', __( 'Waiting for the customer to pay…', 'torobpay-for-woocommerce' ), false );
        watchActive = true;
        pollWatch();
    }

    function pollWatch() {
        if ( ! watchActive || ! watchOrderId ) {
            return;
        }
        if ( watchInFlight ) {
            watchTimer = setTimeout( pollWatch, WATCH_LOCAL_MS );
            return;
        }
        watchInFlight = true;
        $.ajax({
            type: 'POST',
            url: ajaxurl,
            data: {
                action: 'torobpay_poll_instore_payment',
                security: cfg.nonce,
                order_id: watchOrderId
            },
            success: function( response ) {
                watchInFlight = false;
                if ( ! watchActive ) {
                    return;
                }
                if ( ! response.success || ! response.data ) {
                    watchTimer = setTimeout( pollWatch, WATCH_LOCAL_MS );
                    return;
                }
                applyWatchState( response.data );
            },
            error: function() {
                watchInFlight = false;
                if ( ! watchActive ) {
                    return;
                }
                watchTimer = setTimeout( pollWatch, 4000 );
            }
        });
    }

    function scheduleWatchPoll() {
        watchActive = true;
        if ( watchTimer ) {
            clearTimeout( watchTimer );
        }
        watchTimer = setTimeout( pollWatch, WATCH_LOCAL_MS );
    }

    function applyWatchState( data ) {
        var state = data.state || 'pending';
        var holdingRetryUi = ( watchState === 'failed' );
        watchState = state;
        var $heading = $('#torobpay-instore-pay-heading');

        if ( state === 'paid' ) {
            setWatchUi( state, data.message || '', false );
            shouldReloadOnClose = true;
            stopWatch();
            $('#torobpay-instore-modal-title').text(__( 'Payment successful', 'torobpay-for-woocommerce' ));
            $heading.hide().text('');
            $('.torobpay-show-instore-qr').filter(function() {
                return String( $(this).data('order-id') ) === String( watchOrderId );
            }).remove();
            return;
        }

        if ( state === 'closed' ) {
            setWatchUi( state, data.message || '', false );
            stopWatch();
            $('#torobpay-instore-modal-title').text(__( 'Payment unavailable', 'torobpay-for-woocommerce' ));
            $heading.hide().text('');
            shouldReloadOnClose = true;
            $('.torobpay-show-instore-qr').filter(function() {
                return String( $(this).data('order-id') ) === String( watchOrderId );
            }).remove();
            return;
        }

        if ( state === 'failed' ) {
            setWatchUi( state, data.message || '', !! data.can_retry );
            $('#torobpay-instore-modal-title').text(__( 'Payment not completed', 'torobpay-for-woocommerce' ));
            $heading.hide().text('');
            scheduleWatchPoll();
            return;
        }

        // Pending after a bounce: keep retry UI (old QR is dead) and watch for paid.
        if ( holdingRetryUi ) {
            watchState = 'failed';
            scheduleWatchPoll();
            return;
        }

        setWatchUi( state, data.message || '', false );
        $heading.show();
        scheduleWatchPoll();
    }

    function requestPaymentQr( orderId, $trigger ) {
        if ( $trigger ) {
            $trigger.prop('disabled', true).addClass('trb-loading');
        }
        $.ajax({
            type: 'POST',
            url: ajaxurl,
            data: {
                action: 'torobpay_get_instore_payment_qr',
                security: cfg.nonce,
                order_id: orderId
            },
            success: function(response) {
                if ( $trigger ) {
                    $trigger.prop('disabled', false).removeClass('trb-loading');
                }
                $('#torobpay-instore-retry-btn').prop('disabled', false).removeClass('trb-loading');
                if ( ! response.success ) {
                    if ( disableInstoreIfUnavailable(response) ) {
                        return;
                    }
                    showToast(response.data && response.data.message ? response.data.message : __( 'Could not load the payment QR.', 'torobpay-for-woocommerce' ), 'error', { sticky: true });
                    return;
                }
                if ( response.data.state === 'paid' || response.data.state === 'closed' ) {
                    openPaidOrClosedQr( response.data );
                    return;
                }
                if ( ! response.data.payment_page_url ) {
                    showToast(__( 'Could not load the payment QR.', 'torobpay-for-woocommerce' ), 'error', { sticky: true });
                    return;
                }
                showInstoreOverlay();
                renderQr(response.data.payment_page_url, response.data.order_id, response.data.total || '');
            },
            error: function(xhr) {
                if ( $trigger ) {
                    $trigger.prop('disabled', false).removeClass('trb-loading');
                }
                $('#torobpay-instore-retry-btn').prop('disabled', false).removeClass('trb-loading');
                if ( disableInstoreIfUnavailable(null, xhr) ) {
                    return;
                }
                showToast(ajaxErrorMessage(xhr, __( 'Could not load the payment QR.', 'torobpay-for-woocommerce' )), 'error', { sticky: true });
            }
        });
    }

    function renderQr(url, orderId, totalHtml) {
        $('#torobpay-instore-form-step').hide();
        $('#torobpay-instore-pay-step').show();
        $('#torobpay-instore-create-btn').hide();
        $('#torobpay-instore-retry-btn').hide();
        $('#torobpay-instore-done-btn').show();
        $('#torobpay-instore-modal-title').text(__( 'Scan to pay', 'torobpay-for-woocommerce' ));
        $('#torobpay-instore-pay-heading').show().text( __( 'Order #', 'torobpay-for-woocommerce' ) + orderId );
        $('#torobpay-instore-pay-total').html(totalHtml || '');
        $('#torobpay-instore-pay-url').val(url);

        var $qr = $('#torobpay-instore-qr');
        $qr.empty();
        qrInstance = null;
        if ( typeof window.QRCode === 'function' ) {
            qrInstance = new window.QRCode($qr.get(0), {
                text: url,
                width: 220,
                height: 220,
                correctLevel: window.QRCode.CorrectLevel ? window.QRCode.CorrectLevel.M : 0
            });
        }

        startWatch( orderId );
        if ( instoreModalA11y ) {
            instoreModalA11y.refreshFocus();
        }
    }

    function openPaidOrClosedQr( data ) {
        showInstoreOverlay();
        $('#torobpay-instore-form-step').hide();
        $('#torobpay-instore-pay-step').show();
        $('#torobpay-instore-create-btn').hide();
        $('#torobpay-instore-retry-btn').hide();
        $('#torobpay-instore-done-btn').show();
        $('#torobpay-instore-pay-total').html( data.total || '' );
        watchOrderId = parseInt( data.order_id, 10 ) || watchOrderId;
        applyWatchState( data );
        if ( instoreModalA11y ) {
            instoreModalA11y.refreshFocus();
        }
    }

    function schedulePreview() {
        if ( previewTimer ) {
            clearTimeout(previewTimer);
        }
        previewTimer = setTimeout(runPreview, 300);
    }

    function runPreview() {
        var items = collectedItems();
        if ( ! items.length ) {
            syncPricingMode();
            return;
        }
        $.ajax({
            type: 'POST',
            url: ajaxurl,
            data: {
                action: 'torobpay_preview_instore_order',
                security: cfg.nonce,
                items: JSON.stringify(items),
                coupon: $('#torobpay-instore-coupon').val()
            },
            success: function(response) {
                if ( ! response.success ) {
                    showToast(response.data && response.data.message ? response.data.message : __( 'Could not preview totals.', 'torobpay-for-woocommerce' ), 'error');
                    return;
                }
                $('#torobpay-instore-discount').val(formatNumber(response.data.discount || 0));
                $('#torobpay-instore-tax').val(formatNumber(response.data.tax || 0));
                $('#torobpay-instore-total').val(formatNumber(response.data.total || 0));
                syncPricingMode();
            }
        });
    }

    function updateInstoreRowTotal($row) {
        var unitPrice = parseFloat($row.data('unitPrice')) || 0;
        var $qty = $row.find('.torobpay-instore-item-qty');
        var qty = parseInt($qty.val(), 10) || 0;
        var maxQty = parseInt($qty.attr('max'), 10);
        if ( qty < 1 ) {
            qty = 1;
            $qty.val(1);
        }
        if ( ! isNaN(maxQty) && qty > maxQty ) {
            qty = maxQty;
            $qty.val(qty);
        }
        $row.find('.torobpay-item-total').text(formatNumber(unitPrice * qty));
    }

    function addProductRow(product) {
        var productId = parseInt(product.id, 10) || 0;
        var $existing = $('#torobpay-instore-items-table tbody tr').not('.torobpay-instore-items-empty').filter(function() {
            return ( parseInt($(this).data('productId'), 10) || 0 ) === productId;
        }).first();
        if ( $existing.length ) {
            var $existingQty = $existing.find('.torobpay-instore-item-qty');
            $existingQty.val( ( parseInt($existingQty.val(), 10) || 0 ) + 1 );
            updateInstoreRowTotal($existing);
            schedulePreview();
            return;
        }
        var maxQty = ( product.sellable_qty === null || typeof product.sellable_qty === 'undefined' ) ? '' : product.sellable_qty;
        var unitPrice = parseFloat(product.price) || 0;
        var $row = $('<tr/>').data({
            productId: product.id,
            productName: product.name || '',
            unitPrice: unitPrice
        });
        $row.append($('<td class="torobpay-item-name"/>').text(product.name || ''));
        $row.append($('<td/>').text(product.sku ? product.sku : '—'));
        var $qty = $('<input type="number" class="torobpay-item-qty torobpay-instore-item-qty" min="1" value="1" />');
        if ( maxQty !== '' ) {
            $qty.attr('max', maxQty);
        }
        $row.append($('<td/>').append($qty));
        $row.append($('<td class="torobpay-item-total"/>').text(formatNumber(unitPrice)));
        $row.append(
            $('<td/>').append(
                $('<button type="button" class="button torobpay-remove-item" title="' + __( 'Remove', 'torobpay-for-woocommerce' ) + '">&times;</button>')
            )
        );
        $('#torobpay-instore-items-table tbody').append($row);
        syncItemsEmptyState();
        schedulePreview();
        syncPricingMode();
    }

    $(document).on('click', '#torobpay-add-instore-order', function(e) {
        e.preventDefault();
        openModal();
    });

    $(document).on('click', '#torobpay-instore-modal-overlay .torobpay-modal-close, #torobpay-instore-done-btn', function() {
        closeModal();
    });

    function instoreSearchInput() {
        return $('#torobpay-instore-product-search-input');
    }

    function instoreSearchResults() {
        return $('#torobpay-instore-product-search-results');
    }

    function hideInstoreProductResults() {
        window.torobpayProductCombobox.hide(
            instoreSearchInput(),
            instoreSearchResults(),
            '#torobpay-instore-add-product-btn'
        );
    }

    function addInstoreProducts(products) {
        $.each($.isArray(products) ? products : [products], function(_, product) {
            if (product) {
                addProductRow(product);
            }
        });
        instoreSearchInput().trigger('focus');
    }

    $(document).on('input', '#torobpay-instore-product-search-input', function() {
        var term = $(this).val();
        if ( searchTimer ) {
            clearTimeout(searchTimer);
        }
        if ( ! term || term.length < 2 ) {
            hideInstoreProductResults();
            return;
        }
        window.torobpayProductCombobox.setAddButtonState('#torobpay-instore-add-product-btn', 0);
        instoreSearchInput().attr('aria-expanded', 'true').removeAttr('aria-activedescendant');
        instoreSearchResults().show().removeAttr('aria-multiselectable').html('<div class="trb-add-product-searching">' + __( 'Searching...', 'torobpay-for-woocommerce' ) + '</div>');
        searchTimer = setTimeout(function() {
            $.ajax({
                type: 'POST',
                url: ajaxurl,
                data: {
                    action: 'torobpay_search_instore_products',
                    search: term,
                    security: cfg.nonce
                },
                success: function(response) {
                    var products = (response.success && response.data && response.data.products) ? response.data.products : [];
                    window.torobpayProductCombobox.renderResults(
                        instoreSearchInput(),
                        instoreSearchResults(),
                        products,
                        'torobpay-instore-product-opt-'
                    );
                },
                error: function(xhr) {
                    instoreSearchResults().removeAttr('aria-multiselectable').html(
                        '<div class="trb-add-product-searching">' + $('<span>').text(ajaxErrorMessage(xhr)).html() + '</div>'
                    ).show();
                    instoreSearchInput().attr('aria-expanded', 'true').removeAttr('aria-activedescendant');
                    window.torobpayProductCombobox.setAddButtonState('#torobpay-instore-add-product-btn', 0);
                }
            });
        }, 300);
    });

    window.torobpayProductCombobox.bind({
        inputSelector: '#torobpay-instore-product-search-input',
        resultsSelector: '#torobpay-instore-product-search-results',
        addButton: '#torobpay-instore-add-product-btn',
        onAddOne: function(product) {
            addInstoreProducts(product);
        },
        onAddMany: function(products) {
            addInstoreProducts(products);
        }
    });

    $(document).on('click', function(e) {
        if ( ! $(e.target).closest('#torobpay-instore-add-product-section').length ) {
            hideInstoreProductResults();
        }
    });

    $(document).on('click', '#torobpay-instore-items-table .torobpay-remove-item', function() {
        $(this).closest('tr').remove();
        syncItemsEmptyState();
        schedulePreview();
        syncPricingMode();
    });

    $(document).on('input', '#torobpay-instore-items-table .torobpay-instore-item-qty', function() {
        updateInstoreRowTotal($(this).closest('tr'));
        schedulePreview();
    });

    $(document).on('input', '#torobpay-instore-coupon', schedulePreview);

    $(document).on('input', '#torobpay-instore-amount', function() {
        if ( $(this).prop('disabled') ) {
            return;
        }
        syncPricingMode();
    });

    $(document).on('blur', '#torobpay-instore-amount', function() {
        if ( $(this).prop('disabled') ) {
            return;
        }
        var amount = customAmountValue();
        $(this).val(amount > 0 ? formatNumber(amount) : '');
        syncPricingMode();
    });

    $(document).on('click', '#torobpay-instore-create-btn', function() {
        var $btn = $(this);
        var items = collectedItems();
        var customAmount = customAmountValue();
        if ( ! $('#torobpay-instore-phone').val() ) {
            showToast(__( 'Enter a valid mobile number.', 'torobpay-for-woocommerce' ), 'error');
            return;
        }
        if ( ! items.length && customAmount <= 0 ) {
            showToast(__( 'Add at least one product or enter a total amount.', 'torobpay-for-woocommerce' ), 'error');
            return;
        }
        createInFlight = true;
        $btn.prop('disabled', true).addClass('trb-loading');
        $.ajax({
            type: 'POST',
            url: ajaxurl,
            data: {
                action: 'torobpay_create_instore_order',
                security: cfg.nonce,
                phone: $('#torobpay-instore-phone').val(),
                name: $('#torobpay-instore-name').val(),
                coupon: items.length ? $('#torobpay-instore-coupon').val() : '',
                items: JSON.stringify(items),
                amount: customAmount
            },
            success: function(response) {
                createInFlight = false;
                $btn.prop('disabled', false).removeClass('trb-loading');
                if ( ! response.success ) {
                    if ( disableInstoreIfUnavailable(response) ) {
                        return;
                    }
                    var message = response.data && response.data.message ? response.data.message : __( 'Could not create the order.', 'torobpay-for-woocommerce' );
                    showToast(message, 'error', { sticky: true });
                    var orderId = response.data ? parseInt(response.data.order_id, 10) : 0;
                    if ( orderId && response.data.can_retry ) {
                        showInstoreOverlay();
                        watchOrderId = orderId;
                        watchState = 'failed';
                        shouldReloadOnClose = true;
                        $('#torobpay-instore-form-step').hide();
                        $('#torobpay-instore-pay-step').show();
                        $('#torobpay-instore-create-btn').hide();
                        $('#torobpay-instore-retry-btn').show().prop('disabled', false).removeClass('trb-loading');
                        $('#torobpay-instore-done-btn').show();
                        $('#torobpay-instore-pay-heading').hide().text('');
                        $('#torobpay-instore-modal-title').text(__( 'Payment not completed', 'torobpay-for-woocommerce' ));
                        setWatchUi( 'failed', message, true );
                        scheduleWatchPoll();
                    }
                    return;
                }
                shouldReloadOnClose = true;
                showInstoreOverlay();
                renderQr(response.data.payment_page_url, response.data.order_id, response.data.total || '');
            },
            error: function(xhr) {
                createInFlight = false;
                $btn.prop('disabled', false).removeClass('trb-loading');
                if ( disableInstoreIfUnavailable(null, xhr) ) {
                    return;
                }
                showToast(ajaxErrorMessage(xhr, __( 'Could not create the order.', 'torobpay-for-woocommerce' )), 'error', { sticky: true });
            }
        });
    });

    $(document).on('click', '#torobpay-instore-retry-btn', function() {
        if ( ! watchOrderId ) {
            return;
        }
        $(this).prop('disabled', true).addClass('trb-loading');
        requestPaymentQr( watchOrderId, $(this) );
    });

    $(document).on('click', '#torobpay-instore-copy-url', function() {
        var url = $('#torobpay-instore-pay-url').val();
        if ( ! url ) {
            return;
        }
        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            navigator.clipboard.writeText(url).then(function() {
                showToast(__( 'Payment link copied.', 'torobpay-for-woocommerce' ), 'success');
            });
            return;
        }
        $('#torobpay-instore-pay-url').trigger('select');
        try {
            document.execCommand('copy');
            showToast(__( 'Payment link copied.', 'torobpay-for-woocommerce' ), 'success');
        } catch ( e ) { /* ignore */ }
    });

    $(document).on('click', '.torobpay-show-instore-qr', function() {
        requestPaymentQr( $(this).data('order-id'), $(this) );
    });
});
