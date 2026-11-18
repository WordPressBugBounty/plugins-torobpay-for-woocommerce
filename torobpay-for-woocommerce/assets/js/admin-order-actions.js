/**
 * TorobPay admin order actions: refund button + edit-items modal.
 */
jQuery(function($) {
    'use strict';

    var __ = ( window.wp && wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function( s ) { return s; };
    var sprintf = ( window.wp && wp.i18n && wp.i18n.sprintf ) ? wp.i18n.sprintf : function( fmt ) {
        var args = Array.prototype.slice.call( arguments, 1 );
        return fmt.replace( /%(\d+)\$s/g, function( match, index ) {
            return args[ parseInt( index, 10 ) - 1 ];
        } ).replace( /%s/g, function() {
            return args.shift();
        } );
    };

    $(document).on('click', '.refund-order', function() {
        var orderId = $(this).data('order-id');
        var button  = $(this);
        if (!confirm(__( 'Are you sure you want to refund this order?', 'torobpay-for-woocommerce' ))) {
            return;
        }
        button.prop('disabled', true).addClass('trb-loading');
        $.ajax({
            type: 'POST',
            url:  ajaxurl,
            data: {
                action:   'torobpay_preflight_action',
                order_id: orderId,
                security: torobpayAdmin.preflightNonce
            },
            success: function(preflightResponse) {
                if (!preflightResponse.success) {
                    window.torobpayShowToast(
                        preflightResponse.data.message || __( 'Payment status was not confirmed.', 'torobpay-for-woocommerce' ),
                        'error',
                        { sticky: true }
                    );
                    button.prop('disabled', false).removeClass('trb-loading');
                    return;
                }
                $.ajax({
                    type: 'POST',
                    url:  ajaxurl,
                    data: {
                        action:   'torobpay_refund_order',
                        order_id: orderId,
                        security: torobpayAdmin.refundNonce
                    },
                    success: function(response) {
                        if (response.success) {
                            window.torobpayShowToast(
                                (response.data && response.data.message) ? response.data.message : __( 'Order refunded successfully.', 'torobpay-for-woocommerce' ),
                                'success'
                            );
                            setTimeout(function() { location.reload(); }, 1500);
                        } else {
                            window.torobpayShowToast(
                                (response.data && response.data.message) ? response.data.message : __( 'Order refund failed. Please try again.', 'torobpay-for-woocommerce' ),
                                'error',
                                { sticky: true }
                            );
                            button.prop('disabled', false).removeClass('trb-loading');
                        }
                    },
                    error: function() {
                        window.torobpayShowToast(__( 'Order refund failed. Please try again.', 'torobpay-for-woocommerce' ), 'error', { sticky: true });
                        button.prop('disabled', false).removeClass('trb-loading');
                    }
                });
            },
            error: function() {
                window.torobpayShowToast(__( 'Error checking payment status. Please try again.', 'torobpay-for-woocommerce' ), 'error', { sticky: true });
                button.prop('disabled', false).removeClass('trb-loading');
            }
        });
    });

    var editSyncOrderId  = null;
    var editSyncOriginalTotal = 0;
    var editSyncResumedMode = false;
    var editSyncPreviewTimer = null;
    var editSyncPreviewRequestId = 0;
    var editSyncLastPreviewHash = null;
    var editSyncLastPreviewDiscount = 0;
    var editSyncLastPreviewTax = 0;
    var editSyncLastPreviewTotal = null;
    var editSyncPreviewInProgress = false;
    var editSyncModalOpen = false;

    function clearFailedEditPreflight(orderId) {
        editSyncModalOpen = false;
        editSyncOrderId = null;
        editSyncResumedMode = false;
        editSyncPreviewInProgress = false;
        editSyncLastPreviewHash = null;
        editSyncLastPreviewDiscount = 0;
        editSyncLastPreviewTax = 0;
        editSyncLastPreviewTotal = null;
        if (editSyncPreviewTimer) {
            clearTimeout(editSyncPreviewTimer);
            editSyncPreviewTimer = null;
        }

        if (!orderId) {
            return;
        }

        // Clear stale interrupted-edit flags when preflight fails.
        $.ajax({
            type: 'POST',
            url: ajaxurl,
            data: {
                action: 'torobpay_cancel_edit_items',
                order_id: orderId,
                security: torobpayAdmin.editSyncNonce
            }
        });
    }

    window.addEventListener('beforeunload', function (event) {
        if (editSyncModalOpen) {
            event.preventDefault();
            event.returnValue = '';
            return '';
        }
    });

    function showTorobpayToast(message, type, options) {
        return window.torobpayShowToast(message, type, options);
    }

    function torobpayAjaxErrorMessage(xhr, fallback) {
        fallback = fallback || __( 'Server connection error.', 'torobpay-for-woocommerce' );
        try {
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                return String(xhr.responseJSON.data.message);
            }
            if (xhr && xhr.responseText) {
                var parsed = JSON.parse(xhr.responseText);
                if (parsed && parsed.data && parsed.data.message) {
                    return String(parsed.data.message);
                }
            }
        } catch (e) { /* ignore */ }
        if (xhr && xhr.status === 403) {
            return fallback + __( ' (Blocked / unauthorized)', 'torobpay-for-woocommerce' );
        }
        if (xhr && xhr.status === 500) {
            return fallback + __( ' (Internal server error)', 'torobpay-for-woocommerce' );
        }
        if (xhr && xhr.status === 0) {
            return fallback + __( ' (Connection lost)', 'torobpay-for-woocommerce' );
        }
        if (xhr && xhr.status) {
            return fallback + ' (HTTP ' + xhr.status + ')';
        }
        return fallback;
    }

    function withJsonItems(data) {
        var payload = $.extend({}, data);
        if (payload.items && typeof payload.items !== 'string') {
            payload.items = JSON.stringify(payload.items);
        }
        return payload;
    }

    function clearEditValidation() {
        $('#torobpay-edit-validation').hide().empty();
        $('#torobpay-edit-already-saved').hide().empty();
    }

    function showEditValidation(message) {
        $('#torobpay-edit-validation').text(message).show();
    }

    function showAlreadySavedNotice(message, reloadLabel) {
        var $box = $('#torobpay-edit-already-saved');
        $box.empty();
        $box.append($('<p>', { 'class': 'torobpay-already-saved-message', text: message }));
        $('<button>', {
            type: 'button',
            'class': 'button torobpay-reload-editor',
            text: reloadLabel || __( 'Reload editor', 'torobpay-for-woocommerce' )
        }).on('click', function() {
            refreshEditModalFromServer({
                message: message,
                label: reloadLabel || __( 'Reload editor', 'torobpay-for-woocommerce' )
            });
        }).appendTo($box);
        $box.css('display', 'flex');
        $('#torobpay-save-sync-btn').prop('disabled', true).removeClass('trb-loading');
    }

    function setResumeBanner(visible) {
        $('#torobpay-edit-resume-banner').toggle(!!visible);
    }

    function resetEditModalChrome() {
        clearEditValidation();
        setResumeBanner(false);
    }

    /**
     * TorobPay only accepts updates that lower the payable amount. Validate the
     * server-computed total (items, discount, shipping, tax and rounding as
     * WooCommerce will actually save them) against the original order total.
     * Returns the validation message, or null when the total is acceptable.
     */
    function editTotalValidationMessage(newTotal) {
        if (newTotal === null || isNaN(newTotal)) {
            return null;
        }
        if (newTotal <= 0) {
            /* translators: %s: new order amount */
            return sprintf( __( 'New amount (%s) must be greater than zero.', 'torobpay-for-woocommerce' ), formatNumber(newTotal) );
        }
        if (editSyncOriginalTotal > 0 && newTotal >= editSyncOriginalTotal) {
            /* translators: 1: new order amount, 2: original order amount */
            return sprintf( __( 'New amount (%1$s) must be less than the original order amount (%2$s).', 'torobpay-for-woocommerce' ), formatNumber(newTotal), formatNumber(editSyncOriginalTotal) );
        }
        return null;
    }

    var modalIconUrl = (window.torobpayAdmin && torobpayAdmin.modalIconUrl) ? torobpayAdmin.modalIconUrl : '';
    $('body').append(
        '<div id="torobpay-edit-modal-overlay" class="torobpay-modal-overlay" style="display:none;">' +
            '<div class="torobpay-modal" role="dialog" aria-modal="true" aria-labelledby="torobpay-edit-modal-title">' +
                '<div class="torobpay-modal-header">' +
                    '<h2 id="torobpay-edit-modal-title"><img class="torobpay-modal-title-icon" src="' + modalIconUrl + '" alt="" aria-hidden="true" />' + __( 'Edit order items ', 'torobpay-for-woocommerce' ) + '<span id="torobpay-edit-order-id"></span></h2>' +
                    '<button type="button" class="torobpay-modal-close" title="' + __( 'Close', 'torobpay-for-woocommerce' ) + '" aria-label="' + __( 'Close', 'torobpay-for-woocommerce' ) + '">&times;</button>' +
                '</div>' +
                '<div class="torobpay-modal-body">' +
                    '<div id="torobpay-edit-already-saved" class="torobpay-edit-already-saved" style="display:none;" role="alert"></div>' +
                    '<div id="torobpay-edit-resume-banner" class="torobpay-edit-resume-banner" style="display:none;" role="status">' +
                        '<span class="dashicons dashicons-warning" aria-hidden="true"></span>' +
                        '<span>' + __( 'Resuming a previous incomplete edit; you can apply new changes or cancel.', 'torobpay-for-woocommerce' ) + '</span>' +
                    '</div>' +
                    '<div id="torobpay-edit-validation" class="torobpay-edit-validation" style="display:none;" role="alert"></div>' +
                    '<div id="torobpay-edit-loading" style="text-align:center;padding:30px;">' + __( 'Loading...', 'torobpay-for-woocommerce' ) + '</div>' +
                    '<table id="torobpay-edit-items-table" class="widefat torobpay-items-table" style="display:none;">' +
                        '<thead><tr>' +
                            '<th>' + __( 'Product', 'torobpay-for-woocommerce' ) + '</th>' +
                            '<th>SKU</th>' +
                            '<th style="width:120px;">' + __( 'Unit price (', 'torobpay-for-woocommerce' ) + '<span class="trb-currency-unit">...</span>)</th>' +
                            '<th style="width:80px;">' + __( 'Qty', 'torobpay-for-woocommerce' ) + '</th>' +
                            '<th>' + __( 'Total', 'torobpay-for-woocommerce' ) + '</th>' +
                            '<th style="width:50px;"></th>' +
                        '</tr></thead>' +
                        '<tbody></tbody>' +
                    '</table>' +
                    '<div id="torobpay-add-product-section" class="trb-add-product-section" style="display:none;margin-top:12px;">' +
                        '<div class="trb-add-product-row">' +
                            '<input type="text" id="torobpay-product-search-input" class="regular-text" placeholder="' + __( 'Search product (name or SKU)…', 'torobpay-for-woocommerce' ) + '" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="torobpay-product-search-results" aria-haspopup="listbox" />' +
                            '<button type="button" class="button trb-icon-btn" id="torobpay-add-product-btn" disabled title="' + __( 'Add product', 'torobpay-for-woocommerce' ) + '"><span class="dashicons dashicons-plus-alt2" style="line-height: normal;"></span></button>' +
                        '</div>' +
                        '<div id="torobpay-product-search-results" role="listbox" style="display:none;"></div>' +
                    '</div>' +
                    '<div id="torobpay-order-fields" style="display:none;margin-top:16px;">' +
                        '<div class="torobpay-order-fields-grid">' +
                            '<div class="torobpay-field-group">' +
                                '<label>' + __( 'Discount (', 'torobpay-for-woocommerce' ) + '<span class="trb-currency-unit">...</span>)</label>' +
                                '<input type="text" id="torobpay-edit-discount" inputmode="decimal" autocomplete="off" />' +
                            '</div>' +
                            '<div class="torobpay-field-group">' +
                                '<label>' + __( 'Shipping (', 'torobpay-for-woocommerce' ) + '<span class="trb-currency-unit">...</span>)</label>' +
                                '<input type="text" id="torobpay-edit-shipping" inputmode="decimal" autocomplete="off" />' +
                            '</div>' +
                            '<div class="torobpay-field-group"></div>' +
                            '<div class="torobpay-field-group">' +
                                '<label>' + __( 'Tax (', 'torobpay-for-woocommerce' ) + '<span class="trb-currency-unit">...</span>) <span id="torobpay-tax-warning" style="display:none;cursor:help;" title="' + __( 'This order had no tax; it will stay tax-free.', 'torobpay-for-woocommerce' ) + '"><span class="dashicons dashicons-warning" style="font-size:16px;width:16px;height:16px;color:#996800;vertical-align:middle;"></span></span></label>' +
                                '<input type="text" id="torobpay-edit-tax" readonly class="torobpay-edit-tax" value="..." />' +
                            '</div>' +
                            '<div class="torobpay-field-group">' +
                                '<label>' + __( 'Original total (', 'torobpay-for-woocommerce' ) + '<span class="trb-currency-unit">...</span>)</label>' +
                                '<input type="text" id="torobpay-original-total" readonly value="..." />' +
                            '</div>' +
                            '<div class="torobpay-field-group">' +
                                '<label>' + __( 'New total (', 'torobpay-for-woocommerce' ) + '<span class="trb-currency-unit">...</span>)</label>' +
                                '<input type="text" id="torobpay-edit-new-total" readonly value="..." />' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="torobpay-modal-footer">' +
                    '<button type="button" class="button button-primary torobpay-button-primary" id="torobpay-save-sync-btn" disabled>' + __( 'Save and sync', 'torobpay-for-woocommerce' ) + '</button>' +
                    '<button type="button" class="button" id="torobpay-cancel-edit-btn">' + __( 'Cancel', 'torobpay-for-woocommerce' ) + '</button>' +
                '</div>' +
            '</div>' +
        '</div>'
    );

    function formatNumber(n) {
        return window.torobpayFormatNumber ? window.torobpayFormatNumber(n) : Number(n).toLocaleString();
    }

    function parseAmount(value) {
        return window.torobpayParseAmount ? window.torobpayParseAmount(value) : 0;
    }

    function parseCount(value) {
        return Math.floor(parseAmount(value));
    }

    var $editOverlay = $('#torobpay-edit-modal-overlay');
    var editModalA11y = window.torobpayBindModalA11y
        ? window.torobpayBindModalA11y($editOverlay, {
            namespace: 'torobpay-edit-modal',
            labelledBy: 'torobpay-edit-modal-title',
            closeOnOverlay: false,
            closeOnEscape: false,
            onClose: closeEditModal,
            initialFocus: '#torobpay-cancel-edit-btn'
        })
        : null;

    function hideEditOverlay() {
        if (editModalA11y) {
            editModalA11y.deactivate();
        }
        $editOverlay.fadeOut(200);
    }

    function updateEditModalTitle(orderId) {
        var text = orderId ? (' - #' + orderId) : '';
        $('#torobpay-edit-order-id').text(text);
    }

    function currencyName(code) {
        var tomanVariants = ['IRT', 'TOMAN', 'IRAN TOMAN', 'IRANIAN TOMAN',
                             'IRAN-TOMAN', 'IRANIAN-TOMAN', 'IRAN_TOMAN', 'IRANIAN_TOMAN'];
        if (!code) { return __( 'Rial', 'torobpay-for-woocommerce' ); }
        var upper = code.toUpperCase();
        if (tomanVariants.indexOf(upper) !== -1) { return __( 'Toman', 'torobpay-for-woocommerce' ); }
        if (upper === 'IRHT') { return 'IRHT'; }
        if (upper === 'IRHR') { return 'IRHR'; }
        return __( 'Rial', 'torobpay-for-woocommerce' );
    }

    function collectPreviewState() {
        var items = [];
        $('#torobpay-edit-items-table tbody tr').each(function() {
            var itemId = $(this).data('item-id');
            var entry = {
                item_id:    itemId,
                quantity:   parseCount($(this).find('.torobpay-item-qty').val()),
                unit_price: parseAmount($(this).find('.torobpay-item-unit-price-input').val())
            };
            if (!itemId) {
                entry.product_id = $(this).data('product-id') || 0;
                entry.name       = $(this).data('product-name') || '';
            }
            items.push(entry);
        });
        var discount = parseAmount($('#torobpay-edit-discount').val());
        var shipping = parseAmount($('#torobpay-edit-shipping').val());
        return { items: items, discount: discount, shipping: shipping };
    }

    function schedulePreview() {
        if (editSyncPreviewTimer) {
            clearTimeout(editSyncPreviewTimer);
        }
        editSyncPreviewTimer = setTimeout(function() {
            requestPreview();
        }, 350);
    }

    function requestPreview(callback) {
        if (!editSyncOrderId) {
            if (typeof callback === 'function') callback(false);
            return;
        }

        var state = collectPreviewState();
        var stateHash = JSON.stringify(state);
        var requestId = ++editSyncPreviewRequestId;

        editSyncPreviewInProgress = true;
        $('#torobpay-save-sync-btn').prop('disabled', true).addClass('trb-loading');
        $('#torobpay-edit-tax, #torobpay-edit-new-total').addClass('trb-tax-updating');

        $.ajax({
            type: 'POST',
            url: ajaxurl,
            data: withJsonItems({
                action: 'torobpay_calc_tax_preview',
                order_id: editSyncOrderId,
                items: state.items,
                discount: state.discount,
                shipping: state.shipping,
                security: torobpayAdmin.editSyncNonce
            }),
            success: function(response) {
                if (requestId !== editSyncPreviewRequestId) {
                    return;
                }

                if (response.success) {
                    editSyncLastPreviewDiscount = parseFloat(response.data.discount_total_raw || state.discount || 0);
                    editSyncLastPreviewTax = parseFloat(response.data.tax_total_raw || 0);
                    editSyncLastPreviewHash = stateHash;
                    $('#torobpay-edit-tax').val(formatNumber(editSyncLastPreviewTax));

                    // Prefer the total WooCommerce actually computed; fall back to
                    // the client-side estimate only if the server omitted it.
                    var serverTotal = parseFloat(response.data.total_raw);
                    if (isNaN(serverTotal)) {
                        var subtotal = 0;
                        $.each(state.items, function(_, item) {
                            subtotal += (item.unit_price || 0) * (item.quantity || 0);
                        });
                        serverTotal = subtotal - editSyncLastPreviewDiscount + (state.shipping || 0) + editSyncLastPreviewTax;
                    }
                    editSyncLastPreviewTotal = serverTotal;
                    $('#torobpay-edit-new-total').val(formatNumber(serverTotal));
                    $('#torobpay-original-total').val(formatNumber(editSyncOriginalTotal || 0));
                    $('#torobpay-edit-tax, #torobpay-edit-new-total').removeClass('trb-tax-updating');

                    // Block live: keep Save disabled while the new total is not
                    // strictly below the original amount.
                    var validationMessage = editTotalValidationMessage(serverTotal);
                    if (validationMessage) {
                        showEditValidation(validationMessage);
                        $('#torobpay-save-sync-btn').prop('disabled', true).removeClass('trb-loading');
                        if (typeof callback === 'function') callback(false);
                        return;
                    }
                    clearEditValidation();
                    $('#torobpay-save-sync-btn').prop('disabled', false).removeClass('trb-loading');
                    if (typeof callback === 'function') callback(true);
                } else {
                    showTorobpayToast(response.data.message || __( 'Error calculating tax.', 'torobpay-for-woocommerce' ), 'error', { sticky: true });
                    $('#torobpay-save-sync-btn').prop('disabled', true).removeClass('trb-loading');
                    $('#torobpay-edit-tax, #torobpay-edit-new-total').removeClass('trb-tax-updating');
                    if (typeof callback === 'function') callback(false);
                }
            },
            error: function(xhr) {
                if (requestId !== editSyncPreviewRequestId) {
                    return;
                }
                showTorobpayToast(torobpayAjaxErrorMessage(xhr), 'error', { sticky: true });
                $('#torobpay-save-sync-btn').prop('disabled', true).removeClass('trb-loading');
                $('#torobpay-edit-tax, #torobpay-edit-new-total').removeClass('trb-tax-updating');
                if (typeof callback === 'function') callback(false);
            },
            complete: function() {
                if (requestId !== editSyncPreviewRequestId) {
                    return;
                }
                editSyncPreviewInProgress = false;
                $('#torobpay-edit-tax, #torobpay-edit-new-total').removeClass('trb-tax-updating');
            }
        });
    }

    var addProductSearchTimer = null;

    function addProductRow(product) {
        var $row = $('<tr/>')
            .addClass('torobpay-item-new')
            .attr('data-item-id', '0')
            .data({
                itemId: 0,
                productId: product.id,
                productName: product.name || '',
                origQty: 1,
                origUnitPrice: product.price
            });

        $row.append($('<td class="torobpay-item-name"/>').text(product.name || ''));
        $row.append($('<td/>').text(product.sku ? product.sku : '—'));
        $row.append(
            $('<td/>').append(
                $('<input type="text" inputmode="decimal" class="torobpay-item-unit-price-input" autocomplete="off" />').val(formatNumber(product.price))
            )
        );
        $row.append(
            $('<td/>').append(
                $('<input type="text" inputmode="numeric" class="torobpay-item-qty" autocomplete="off" value="1" />')
            )
        );
        $row.append($('<td class="torobpay-item-total"/>').text(formatNumber(product.price)));
        $row.append(
            $('<td/>').append(
                $('<button type="button" class="button torobpay-remove-item" title="' + __( 'Remove', 'torobpay-for-woocommerce' ) + '">&times;</button>')
            )
        );

        $('#torobpay-edit-items-table tbody').append($row);
        schedulePreview();
    }

    function clearProductSearch() {
        $('#torobpay-product-search-input').val('');
        hideEditProductResults();
    }

    function editSearchInput() {
        return $('#torobpay-product-search-input');
    }

    function editSearchResults() {
        return $('#torobpay-product-search-results');
    }

    function hideEditProductResults() {
        window.torobpayProductCombobox.hide(
            editSearchInput(),
            editSearchResults(),
            '#torobpay-add-product-btn'
        );
    }

    function addEditProducts(products) {
        $.each($.isArray(products) ? products : [products], function(_, product) {
            if (product) {
                addProductRow(product);
            }
        });
        editSearchInput().trigger('focus');
    }

    $(document).on('input', '#torobpay-product-search-input', function() {
        var term = $(this).val();
        if (addProductSearchTimer) { clearTimeout(addProductSearchTimer); }
        if (!term || term.length < 2) {
            hideEditProductResults();
            return;
        }
        window.torobpayProductCombobox.setAddButtonState('#torobpay-add-product-btn', 0);
        editSearchInput().attr('aria-expanded', 'true').removeAttr('aria-activedescendant');
        editSearchResults().show().removeAttr('aria-multiselectable').html('<div class="trb-add-product-searching">' + __( 'Searching...', 'torobpay-for-woocommerce' ) + '</div>');
        addProductSearchTimer = setTimeout(function() {
            $.ajax({
                type: 'POST',
                url: ajaxurl,
                data: {
                    action:   'torobpay_search_products',
                    search:   term,
                    security: torobpayAdmin.editSyncNonce
                },
                success: function(response) {
                    if (response.success) {
                        window.torobpayProductCombobox.renderResults(
                            editSearchInput(),
                            editSearchResults(),
                            response.data.products,
                            'torobpay-edit-product-opt-'
                        );
                    } else {
                        editSearchResults().removeAttr('aria-multiselectable').html('<div class="trb-add-product-searching">' + __( 'Search error.', 'torobpay-for-woocommerce' ) + '</div>').show();
                        editSearchInput().attr('aria-expanded', 'true').removeAttr('aria-activedescendant');
                        window.torobpayProductCombobox.setAddButtonState('#torobpay-add-product-btn', 0);
                    }
                },
                error: function(xhr) {
                    editSearchResults().removeAttr('aria-multiselectable').html(
                        '<div class="trb-add-product-searching">' + $('<span>').text(torobpayAjaxErrorMessage(xhr)).html() + '</div>'
                    ).show();
                    editSearchInput().attr('aria-expanded', 'true').removeAttr('aria-activedescendant');
                    window.torobpayProductCombobox.setAddButtonState('#torobpay-add-product-btn', 0);
                }
            });
        }, 300);
    });

    window.torobpayProductCombobox.bind({
        inputSelector: '#torobpay-product-search-input',
        resultsSelector: '#torobpay-product-search-results',
        addButton: '#torobpay-add-product-btn',
        onAddOne: function(product) {
            addEditProducts(product);
        },
        onAddMany: function(products) {
            addEditProducts(products);
        }
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('#torobpay-add-product-section').length) {
            hideEditProductResults();
        }
    });

    function refreshEditModalFromServer(alreadySaved) {
        if (!editSyncOrderId) {
            return;
        }

        editSyncLastPreviewHash = null;
        editSyncLastPreviewTotal = null;
        editSyncPreviewInProgress = false;
        clearEditValidation();
        $('#torobpay-edit-loading').show().html(__( 'Restoring order data...', 'torobpay-for-woocommerce' ));
        $('#torobpay-edit-items-table').hide();
        $('#torobpay-add-product-section').hide();
        $('#torobpay-order-fields').hide();
        $('#torobpay-save-sync-btn').prop('disabled', true);

        function restoreEditorAfterFailedReload() {
            $('#torobpay-edit-loading').hide();
            $('#torobpay-edit-items-table').show();
            $('#torobpay-add-product-section').show();
            $('#torobpay-order-fields').show();
            if (alreadySaved && alreadySaved.message) {
                showAlreadySavedNotice(alreadySaved.message, alreadySaved.label);
            }
        }

        $.ajax({
            type: 'POST',
            url: ajaxurl,
            data: {
                action:   'torobpay_unlock_order_items',
                order_id: editSyncOrderId,
                security: torobpayAdmin.editSyncNonce
            },
            success: function(response) {
                if (response.success) {
                    renderItems(response.data.items, response.data);
                } else {
                    restoreEditorAfterFailedReload();
                    showTorobpayToast(response.data.message || __( 'Error reloading items.', 'torobpay-for-woocommerce' ), 'error', { sticky: true });
                }
            },
            error: function(xhr) {
                restoreEditorAfterFailedReload();
                showTorobpayToast(torobpayAjaxErrorMessage(xhr), 'error', { sticky: true });
            }
        });
    }

    function renderItems(items, orderData) {
        var $tbody = $('#torobpay-edit-items-table tbody');
        $tbody.empty();

        $.each(items, function(i, item) {
            var lineTotal = item.unit_price * item.quantity;
            var row = '<tr data-item-id="' + item.item_id + '" data-orig-qty="' + item.quantity + '" data-orig-unit-price="' + item.unit_price + '">' +
                '<td class="torobpay-item-name">' + $('<span>').text(item.name).html() + '</td>' +
                '<td>' + (item.sku ? $('<span>').text(item.sku).html() : '—') + '</td>' +
                '<td><input type="text" inputmode="decimal" class="torobpay-item-unit-price-input" autocomplete="off" value="' + formatNumber(item.unit_price) + '" /></td>' +
                '<td><input type="text" inputmode="numeric" class="torobpay-item-qty" autocomplete="off" value="' + item.quantity + '" /></td>' +
                '<td class="torobpay-item-total">' + formatNumber(lineTotal) + '</td>' +
                '<td><button type="button" class="button torobpay-remove-item" title="' + __( 'Remove', 'torobpay-for-woocommerce' ) + '">&times;</button></td>' +
                '</tr>';
            $tbody.append(row);
        });

        if (orderData) {
            editSyncOriginalTotal = parseFloat(orderData.order_total) || 0;
            editSyncLastPreviewDiscount = parseFloat(orderData.discount_total || 0);
            editSyncResumedMode = orderData.resumed === true;
            
            var unit = currencyName(orderData.currency);
            $('.trb-currency-unit').text(unit);
            setResumeBanner(editSyncResumedMode);
            $('#torobpay-edit-discount').val(formatNumber(orderData.discount_total || 0));
            $('#torobpay-edit-shipping').val(formatNumber(orderData.shipping_total || 0));
            $('#torobpay-edit-tax').val(formatNumber(orderData.tax_total || 0));
            $('#torobpay-edit-new-total').val(formatNumber(parseFloat(orderData.order_total || editSyncOriginalTotal || 0)));
            $('#torobpay-original-total').val(formatNumber(editSyncOriginalTotal || 0));
            if (!orderData.tax_enabled) {
                $('#torobpay-tax-warning').show();
            } else {
                $('#torobpay-tax-warning').hide();
            }
            $('#torobpay-order-fields').show();
        }

        $('#torobpay-edit-loading').hide();
        $('#torobpay-edit-items-table').show();
        $('#torobpay-add-product-section').show();
        $('#torobpay-save-sync-btn').prop('disabled', true);
        schedulePreview();
    }

    $(document).on('click', '.edit-sync-items', function() {
        editSyncOrderId  = $(this).data('order-id');
        var orderStatus  = $(this).data('order-status');
        var button       = $(this);
        var originalHTML = button.html();

        button.prop('disabled', true).addClass('trb-loading');

        // Interrupted on-hold edits are already locked — skip preflight.
        var skipPreflight = (orderStatus === 'on-hold');

        function openModalAndLoadItems() {
            updateEditModalTitle(editSyncOrderId);
            resetEditModalChrome();
            $('#torobpay-edit-loading').show().html(__( 'Loading...', 'torobpay-for-woocommerce' ));
            $('#torobpay-edit-items-table').hide();
            $('#torobpay-add-product-section').hide();
            $('#torobpay-order-fields').hide();
            $('#torobpay-save-sync-btn').prop('disabled', true);
            $editOverlay.fadeIn(200);
            if (editModalA11y) {
                editModalA11y.activate();
            }
            editSyncModalOpen = true;

            $.ajax({
                type: 'POST',
                url:  ajaxurl,
                data: {
                    action:   'torobpay_unlock_order_items',
                    order_id: editSyncOrderId,
                    security: torobpayAdmin.editSyncNonce
                },
                success: function(response) {
                    if (response.success) {
                        renderItems(response.data.items, response.data);
                    } else {
                        showTorobpayToast(response.data.message || __( 'Error loading items.', 'torobpay-for-woocommerce' ), 'error', { sticky: true });
                        closeEditModal();
                    }
                },
                error: function(xhr) {
                    showTorobpayToast(torobpayAjaxErrorMessage(xhr), 'error', { sticky: true });
                    closeEditModal();
                },
                complete: function() {
                    button.prop('disabled', false).removeClass('trb-loading').html(originalHTML);
                }
            });
        }

        if (skipPreflight) {
            openModalAndLoadItems();
            return;
        }

        // Preflight: verify/settle before opening the edit modal.
        $.ajax({
            type: 'POST',
            url:  ajaxurl,
            data: {
                action:   'torobpay_preflight_action',
                order_id: editSyncOrderId,
                security: torobpayAdmin.preflightNonce
            },
            success: function(preflightResponse) {
                if (!preflightResponse.success) {
                    clearFailedEditPreflight(editSyncOrderId);
                    button.prop('disabled', false).removeClass('trb-loading').html(originalHTML);
                    showTorobpayToast(
                        preflightResponse.data.message || __( 'Payment status was not confirmed.', 'torobpay-for-woocommerce' ),
                        'error',
                        { sticky: true }
                    );
                    return;
                }
                openModalAndLoadItems();
            },
            error: function() {
                clearFailedEditPreflight(editSyncOrderId);
                button.prop('disabled', false).removeClass('trb-loading').html(originalHTML);
                showTorobpayToast(__( 'Error checking payment status. Please try again.', 'torobpay-for-woocommerce' ), 'error', { sticky: true });
            }
        });
    });

    $(document).on('input', '#torobpay-edit-items-table .torobpay-item-qty, #torobpay-edit-items-table .torobpay-item-unit-price-input', function() {
        clearEditValidation();
        var $row = $(this).closest('tr');
        var unitPrice = parseAmount($row.find('.torobpay-item-unit-price-input').val());
        var qty = parseCount($row.find('.torobpay-item-qty').val());
        $row.find('.torobpay-item-total').text(formatNumber(unitPrice * qty));
        schedulePreview();
    });

    $(document).on('input', '#torobpay-edit-discount, #torobpay-edit-shipping', function() {
        clearEditValidation();
        schedulePreview();
    });

    $(document).on('blur', '.torobpay-item-unit-price-input, #torobpay-edit-discount, #torobpay-edit-shipping', function() {
        $(this).val(formatNumber(parseAmount($(this).val())));
    });

    $(document).on('blur', '#torobpay-edit-items-table .torobpay-item-qty', function() {
        $(this).val(String(parseCount($(this).val())));
    });

    $(document).on('click', '#torobpay-edit-items-table .torobpay-remove-item', function() {
        var $row = $(this).closest('tr');
        $row.find('.torobpay-item-qty').val(0);
        $row.find('.torobpay-item-total').text(formatNumber(0));
        $row.addClass('torobpay-item-removed');
        $(this)
            .removeClass('torobpay-remove-item')
            .addClass('torobpay-restore-item')
            .attr('title', __( 'Restore', 'torobpay-for-woocommerce' ))
            .html('&#8617;');
        schedulePreview();
    });

    $(document).on('click', '#torobpay-edit-items-table .torobpay-restore-item', function() {
        var $row = $(this).closest('tr');
        var origQty       = parseInt($row.data('orig-qty')) || 1;
        var origUnitPrice = parseFloat($row.data('orig-unit-price')) || 0;
        $row.find('.torobpay-item-qty').val(origQty);
        $row.find('.torobpay-item-unit-price-input').val(formatNumber(origUnitPrice));
        $row.find('.torobpay-item-total').text(formatNumber(origUnitPrice * origQty));
        $row.removeClass('torobpay-item-removed');
        $(this)
            .removeClass('torobpay-restore-item')
            .addClass('torobpay-remove-item')
            .attr('title', __( 'Remove', 'torobpay-for-woocommerce' ))
            .html('&times;');
        schedulePreview();
    });

    $(document).on('click', '#torobpay-save-sync-btn', function() {
        var $btn = $(this);
        var currentState = collectPreviewState();
        var items = currentState.items;

        clearEditValidation();

        var allZero = items.every(function(item) { return item.quantity === 0; });
        if (allZero) {
            showEditValidation(__( 'At least one item must remain in the order.', 'torobpay-for-woocommerce' ));
            return;
        }

        var discount = currentState.discount;
        var shipping = currentState.shipping;
        var currentHash = JSON.stringify(currentState);

        function performSave() {
            // performSave only runs after a fresh preview for this exact state, so
            // editSyncLastPreviewTotal is the total WooCommerce will save and TorobPay will see.
            var validationMessage = editTotalValidationMessage(editSyncLastPreviewTotal);
            if (validationMessage) {
                showEditValidation(validationMessage);
                $btn.prop('disabled', true).removeClass('trb-loading');
                return;
            }

            $btn.prop('disabled', true).addClass('trb-loading');

            $.ajax({
                type: 'POST',
                url: ajaxurl,
                data: withJsonItems({
                    action:    'torobpay_save_and_sync_items',
                    order_id:  editSyncOrderId,
                    items:     items,
                    discount:  discount,
                    shipping:  shipping,
                    security:  torobpayAdmin.editSyncNonce
                }),
                success: function(response) {
                    if (response.success) {
                        clearEditValidation();
                        editSyncModalOpen = false;
                        $btn.removeClass('trb-loading');
                        hideEditOverlay();
                        showTorobpayToast(response.data.message || __( 'Items were edited successfully and sent to TorobPay.', 'torobpay-for-woocommerce' ), 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else if (response.data && response.data.reload_editor) {
                        showAlreadySavedNotice(response.data.message, response.data.reload_label);
                    } else {
                        showTorobpayToast(response.data.message || __( 'An error occurred.', 'torobpay-for-woocommerce' ), 'error', { sticky: true });
                        $btn.prop('disabled', false).removeClass('trb-loading');
                        if (response.data && response.data.refresh_modal) {
                            refreshEditModalFromServer();
                        }
                    }
                },
                error: function(xhr) {
                    showTorobpayToast(torobpayAjaxErrorMessage(xhr), 'error', { sticky: true });
                    $btn.prop('disabled', false).removeClass('trb-loading');
                }
            });
        }

        if (editSyncLastPreviewHash === currentHash && !editSyncPreviewInProgress) {
            performSave();
        } else {
            requestPreview(function(ok) {
                if (ok) {
                    performSave();
                } else if (!$('#torobpay-edit-validation').is(':visible')) {
                    // Preview failed for a non-validation reason (network etc.); let the
                    // merchant retry. Amount-rule failures keep Save disabled until edited.
                    $btn.prop('disabled', false).removeClass('trb-loading');
                }
            });
        }
    });

    function closeEditModal() {
        if (!editSyncOrderId) {
            updateEditModalTitle(null);
            resetEditModalChrome();
            hideEditOverlay();
            return;
        }

        $.ajax({
            type: 'POST',
            url: ajaxurl,
            data: {
                action:   'torobpay_cancel_edit_items',
                order_id: editSyncOrderId,
                security: torobpayAdmin.editSyncNonce
            },
            complete: function() {
                var wasResumed = editSyncResumedMode;
                editSyncOrderId = null;
                editSyncOriginalTotal = 0;
                editSyncResumedMode = false;
                editSyncModalOpen = false;
                if (editSyncPreviewTimer) { clearTimeout(editSyncPreviewTimer); editSyncPreviewTimer = null; }
                if (addProductSearchTimer) { clearTimeout(addProductSearchTimer); addProductSearchTimer = null; }
                editSyncLastPreviewHash = null;
                editSyncLastPreviewDiscount = 0;
                editSyncLastPreviewTax = 0;
                editSyncLastPreviewTotal = null;
                editSyncPreviewInProgress = false;
                clearProductSearch();
                resetEditModalChrome();
                updateEditModalTitle(null);
                hideEditOverlay();
                if (wasResumed) {
                    setTimeout(function() { location.reload(); }, 200);
                }
            }
        });
    }

    $(document).on('click', '#torobpay-cancel-edit-btn, #torobpay-edit-modal-overlay .torobpay-modal-close', function() {
        closeEditModal();
    });
});

/**
 * Action Needed suggestions modal (probe on open; VERIFY→settle may self-heal).
 */
jQuery(function ($) {
    'use strict';

    var __ = ( window.wp && wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function( s ) { return s; };

    var actionMeta = (window.torobpayAdmin && torobpayAdmin.actionMeta) ? torobpayAdmin.actionMeta : {};
    var iconUrl    = (window.torobpayAdmin && torobpayAdmin.modalIconUrl) ? torobpayAdmin.modalIconUrl : '';

    var overlayHtml =
        '<div id="torobpay-action-modal-overlay" class="torobpay-modal-overlay" style="display:none;">' +
            '<div class="torobpay-modal torobpay-action-modal" role="dialog" aria-modal="true" aria-labelledby="torobpay-action-modal-title">' +
                '<div class="torobpay-modal-header">' +
                    '<h2><img class="torobpay-modal-title-icon" src="' + iconUrl + '" alt="" aria-hidden="true" /><span id="torobpay-action-modal-title" class="torobpay-action-modal-title">' + __( 'Action required', 'torobpay-for-woocommerce' ) + '</span></h2>' +
                    '<button type="button" class="torobpay-modal-close" title="' + __( 'Close', 'torobpay-for-woocommerce' ) + '" aria-label="' + __( 'Close', 'torobpay-for-woocommerce' ) + '">&times;</button>' +
                '</div>' +
                '<div class="torobpay-modal-body"></div>' +
            '</div>' +
        '</div>';
    $('body').append(overlayHtml);

    var $overlay = $('#torobpay-action-modal-overlay');
    var currentOrderId = null;
    var actionModalA11y = window.torobpayBindModalA11y
        ? window.torobpayBindModalA11y($overlay, {
            namespace: 'torobpay-action-modal',
            labelledBy: 'torobpay-action-modal-title',
            closeOnOverlay: true,
            onClose: closeModal,
            initialFocus: function () {
                var $btn = $overlay.find('.torobpay-action-buttons button:visible').first();
                return $btn.length ? $btn : $overlay.find('.torobpay-modal-close');
            }
        })
        : null;

    function escapeHtml(text) {
        return $('<div/>').text(text == null ? '' : String(text)).html();
    }

    function renderButtons(actions) {
        if (!actions || !actions.length) {
            actions = ['dismiss'];
        }
        var html = '';
        if ($.inArray('refund', actions) !== -1) {
            html += '<button type="button" class="button torobpay-action-do-refund">' + __( 'Refund', 'torobpay-for-woocommerce' ) + '</button>';
        }
        if ($.inArray('fail', actions) !== -1) {
            html += '<button type="button" class="button torobpay-action-do-fail">' + __( 'Mark order as failed', 'torobpay-for-woocommerce' ) + '</button>';
        }
        if ($.inArray('resume_edit', actions) !== -1) {
            html += '<button type="button" class="button torobpay-action-do-resume-edit">' + __( 'Resume edit', 'torobpay-for-woocommerce' ) + '</button>';
        }
        if ($.inArray('dismiss', actions) !== -1) {
            html += '<button type="button" class="button torobpay-action-do-dismiss">' + __( 'Dismiss warning', 'torobpay-for-woocommerce' ) + '</button>';
        }
        return html;
    }

    function renderBody(meta, reason) {
        var html = '';
        html += '<div class="torobpay-action-summary severity-' + escapeHtml(meta.severity) + '">';
        html += '<h3>' + escapeHtml(meta.label) + '</h3>';
        if (reason) {
            html += '<p class="torobpay-action-reason">' + escapeHtml(reason) + '</p>';
        }
        if (meta.description) {
            html += '<p class="torobpay-action-hint">' + escapeHtml(meta.description) + '</p>';
        }
        html += '</div>';
        html += '<div class="torobpay-action-buttons">' + renderButtons(meta.actions) + '</div>';
        $overlay.find('.torobpay-modal-body').html(html);
    }

    // Probe TorobPay; only VERIFY→settle may auto-heal.
    function autoProbe() {
        if (!currentOrderId) {
            return;
        }
        var probeNonce = (window.torobpayAdmin && torobpayAdmin.probeNonce) ? torobpayAdmin.probeNonce : '';
        if (!probeNonce) {
            feedback(__( 'Could not update status.', 'torobpay-for-woocommerce' ), 'error');
            return;
        }
        setBusy(true);
        feedback(__( 'Checking status with TorobPay…', 'torobpay-for-woocommerce' ));
        $.ajax({
            type: 'POST',
            url:  ajaxurl,
            data: {
                action:   'torobpay_probe_payment',
                order_id: currentOrderId,
                security: probeNonce
            },
            success: function (response) {
                if (!response || !response.success) {
                    setBusy(false);
                    feedback((response && response.data && response.data.message) ? response.data.message : __( 'Could not update status.', 'torobpay-for-woocommerce' ), 'error');
                    return;
                }
                var data = response.data || {};
                if (data.reload) {
                    feedback(data.message || __( 'Status updated.', 'torobpay-for-woocommerce' ), 'success');
                    setTimeout(function () { location.reload(); }, 1500);
                    return;
                }
                if (data.needs_action === false) {
                    $overlay.find('.torobpay-action-buttons').empty();
                    setBusy(false);
                    feedback(data.message || __( 'No mismatch found.', 'torobpay-for-woocommerce' ), 'success');
                    return;
                }
                renderBody({
                    label:       data.label || __( 'Needs review', 'torobpay-for-woocommerce' ),
                    description: data.description || '',
                    severity:    data.severity || 'warning',
                    actions:     (data.actions && data.actions.length) ? data.actions : ['dismiss']
                }, data.reason || '');
                setBusy(false);
                window.torobpayClearProgressToast();
                if (actionModalA11y) {
                    actionModalA11y.refreshFocus();
                }
            },
            error: function () {
                setBusy(false);
                feedback(__( 'Status update failed.', 'torobpay-for-woocommerce' ), 'error');
            }
        });
    }

    function openModal($btn) {
        currentOrderId = $btn.data('order-id');
        var titleText = __( 'Action required', 'torobpay-for-woocommerce' );
        if (currentOrderId) {
            titleText += __( ' — Order #', 'torobpay-for-woocommerce' ) + escapeHtml(currentOrderId);
        }
        $overlay.find('.torobpay-action-modal-title').html(titleText);
        var code   = String($btn.data('action-code') || '');
        var reason = String($btn.data('action-reason') || '');
        var meta   = actionMeta[code] || {
            label: __( 'Needs review', 'torobpay-for-woocommerce' ),
            description: '',
            severity: 'warning',
            actions: ['dismiss']
        };

        renderBody(meta, reason);
        $overlay.fadeIn(200);
        if (actionModalA11y) {
            actionModalA11y.activate();
        }

        autoProbe();
    }

    function closeModal() {
        window.torobpayClearProgressToast();
        if (actionModalA11y) {
            actionModalA11y.deactivate();
        }
        $overlay.fadeOut(200);
    }

    function setBusy(busy) {
        $overlay.find('.torobpay-action-buttons button').prop('disabled', busy);
    }

    function feedback(message, type) {
        type = type || 'info';
        if (type === 'info') {
            window.torobpayShowToast(message, 'info', { progress: true });
            return;
        }
        var options = type === 'error' ? { sticky: true } : {};
        window.torobpayShowToast(message, type, options);
    }

    $(document).on('click', '.torobpay-action-alert', function (e) {
        e.preventDefault();
        openModal($(this));
    });

    $overlay.on('click', '.torobpay-modal-close', closeModal);

    // Escape / overlay-click live in torobpayBindModalA11y. Keep a fallback if admin-ui.js did not load.
    if (!actionModalA11y) {
        $(document).on('keydown.torobpay-action-modal', function (e) {
            if (!$overlay.is(':visible')) {
                return;
            }
            if (e.key === 'Escape' || e.key === 'Esc' || e.keyCode === 27) {
                e.preventDefault();
                closeModal();
            }
        });
        $overlay.on('click', function (e) {
            if ($(e.target).is($overlay)) {
                closeModal();
            }
        });
    }

    $overlay.on('click', '.torobpay-action-do-refund', function () {
        if (!currentOrderId) {
            return;
        }
        if (!confirm(__( 'Are you sure you want to refund this order?', 'torobpay-for-woocommerce' ))) {
            return;
        }
        setBusy(true);
        feedback(__( 'Checking payment status…', 'torobpay-for-woocommerce' ));
        $.ajax({
            type: 'POST',
            url:  ajaxurl,
            data: {
                action:   'torobpay_preflight_action',
                order_id: currentOrderId,
                security: torobpayAdmin.preflightNonce
            },
            success: function (preflight) {
                if (!preflight.success) {
                    setBusy(false);
                    feedback((preflight.data && preflight.data.message) ? preflight.data.message : __( 'Payment status was not confirmed.', 'torobpay-for-woocommerce' ), 'error');
                    return;
                }
                feedback(__( 'Submitting refund…', 'torobpay-for-woocommerce' ));
                $.ajax({
                    type: 'POST',
                    url:  ajaxurl,
                    data: {
                        action:   'torobpay_refund_order',
                        order_id: currentOrderId,
                        security: torobpayAdmin.refundNonce
                    },
                    success: function (refund) {
                        if (refund.success) {
                            feedback((refund.data && refund.data.message) ? refund.data.message : __( 'Order refunded successfully.', 'torobpay-for-woocommerce' ), 'success');
                            setTimeout(function () { location.reload(); }, 1500);
                        } else {
                            setBusy(false);
                            feedback((refund.data && refund.data.message) ? refund.data.message : __( 'Order refund failed.', 'torobpay-for-woocommerce' ), 'error');
                        }
                    },
                    error: function () {
                        setBusy(false);
                        feedback(__( 'Order refund failed.', 'torobpay-for-woocommerce' ), 'error');
                    }
                });
            },
            error: function () {
                setBusy(false);
                feedback(__( 'Error checking payment status. Please try again.', 'torobpay-for-woocommerce' ), 'error');
            }
        });
    });

    $overlay.on('click', '.torobpay-action-do-fail', function () {
        if (!currentOrderId) {
            return;
        }
        if (!confirm(__( 'The order will be marked as "Failed". Continue?', 'torobpay-for-woocommerce' ))) {
            return;
        }
        setBusy(true);
        feedback(__( 'Marking as failed…', 'torobpay-for-woocommerce' ));
        $.ajax({
            type: 'POST',
            url:  ajaxurl,
            data: {
                action:   'torobpay_fail_order',
                order_id: currentOrderId,
                security: torobpayAdmin.failNonce
            },
            success: function (response) {
                if (response && response.success) {
                    feedback((response.data && response.data.message) ? response.data.message : __( 'Done.', 'torobpay-for-woocommerce' ), 'success');
                    setTimeout(function () { location.reload(); }, 1500);
                } else {
                    setBusy(false);
                    feedback((response && response.data && response.data.message) ? response.data.message : __( 'Status change failed.', 'torobpay-for-woocommerce' ), 'error');
                }
            },
            error: function () {
                setBusy(false);
                feedback(__( 'Status change failed. Please try again.', 'torobpay-for-woocommerce' ), 'error');
            }
        });
    });

    $overlay.on('click', '.torobpay-action-do-resume-edit', function () {
        if (!currentOrderId) {
            return;
        }
        var $target = $('.edit-sync-items').filter(function () {
            return String($(this).data('order-id')) === String(currentOrderId);
        }).first();

        if (!$target.length) {
            feedback(__( 'The "Resume edit" button was not found on this page. Open the order page and continue editing from there.', 'torobpay-for-woocommerce' ), 'warning');
            return;
        }

        closeModal();
        setTimeout(function () {
            $target.trigger('click');
        }, 220);
    });

    $overlay.on('click', '.torobpay-action-do-dismiss', function () {
        if (!currentOrderId) {
            return;
        }
        setBusy(true);
        feedback(__( 'Dismissing warning…', 'torobpay-for-woocommerce' ));
        $.ajax({
            type: 'POST',
            url:  ajaxurl,
            data: {
                action:   'torobpay_dismiss_action',
                order_id: currentOrderId,
                security: torobpayAdmin.dismissNonce
            },
            success: function (response) {
                if (response && response.success) {
                    feedback((response.data && response.data.message) ? response.data.message : __( 'Done.', 'torobpay-for-woocommerce' ), 'success');
                    setTimeout(function () { location.reload(); }, 1500);
                } else {
                    setBusy(false);
                    feedback((response && response.data && response.data.message) ? response.data.message : __( 'Failed.', 'torobpay-for-woocommerce' ), 'error');
                }
            },
            error: function () {
                setBusy(false);
                feedback(__( 'Error. Please try again.', 'torobpay-for-woocommerce' ), 'error');
            }
        });
    });
});
