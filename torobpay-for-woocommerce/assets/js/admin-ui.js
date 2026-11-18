/**
 * Shared admin UI helpers for TorobPay modals, number formatting, and product search.
 */
(function (window, $) {
    'use strict';

    var __ = (window.wp && wp.i18n && wp.i18n.__) ? window.wp.i18n.__ : function (s) { return s; };
    var sprintf = (window.wp && wp.i18n && wp.i18n.sprintf) ? window.wp.i18n.sprintf : function (s) {
        var args = Array.prototype.slice.call(arguments, 1);
        return String(s).replace(/%[sd]/g, function () {
            return args.length ? args.shift() : '';
        });
    };

    function getLocale() {
        if (window.torobpayAdminUi && window.torobpayAdminUi.locale) {
            return String(window.torobpayAdminUi.locale);
        }
        return document.documentElement.lang || undefined;
    }

    window.torobpayFormatNumber = function (n) {
        try {
            return Number(n).toLocaleString(getLocale());
        } catch (e) {
            return Number(n).toLocaleString();
        }
    };

    /**
     * Parse a merchant-typed amount.
     *
     * Accepts Persian and Arabic-Indic digits, grouped thousands, and a decimal
     * point. A trailing separator, as in "250.", keeps the digits already typed.
     * Empty, negative, and invalid input is 0.
     */
    window.torobpayParseAmount = function (value) {
        if (value == null) {
            return 0;
        }
        var s = String(value);
        s = s.replace(/[۰-۹]/g, function (d) {
            return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
        }).replace(/[\u0660-\u0669]/g, function (d) {
            return String('\u0660\u0661\u0662\u0663\u0664\u0665\u0666\u0667\u0668\u0669'.indexOf(d));
        });
        s = s.replace(/[,٬،\s']/g, '');
        s = s.replace(/٫/g, '.');
        if (s.charAt(s.length - 1) === '.') {
            s = s.slice(0, -1);
        }
        if ((s.match(/\./g) || []).length > 1) {
            s = s.replace(/\./g, '');
        }
        if (!/^\d+(\.\d+)?$/.test(s)) {
            return 0;
        }
        var n = parseFloat(s);
        return (!isNaN(n) && n > 0) ? n : 0;
    };

    function focusable($root) {
        return $root.find(
            'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        ).filter(':visible');
    }

    function resolveFocus(opts, $dialog) {
        var target = opts.initialFocus;
        if (typeof target === 'function') {
            target = target();
        }
        var $el = target ? $(target) : $();
        if ($el.length) {
            return $el;
        }
        var $first = focusable($dialog).first();
        return $first.length ? $first : $dialog;
    }

    /**
     * @param {jQuery} $overlay
     * @param {{onClose:Function, closeOnOverlay?:boolean, closeOnEscape?:boolean, initialFocus?:string|Function, labelledBy?:string}} opts
     */
    window.torobpayBindModalA11y = function ($overlay, opts) {
        opts = opts || {};
        var ns = opts.namespace || ('torobpay-modal-' + Math.random().toString(36).slice(2));
        var $dialog = $overlay.children('.torobpay-modal').first();
        var lastFocus = null;
        var active = false;

        $dialog.attr({
            role: 'dialog',
            'aria-modal': 'true',
            tabindex: '-1'
        });
        if (opts.labelledBy) {
            $dialog.attr('aria-labelledby', opts.labelledBy);
        }

        function onKeydown(e) {
            if (e.key === 'Tab') {
                var $f = focusable($dialog);
                if (!$f.length) {
                    e.preventDefault();
                    $dialog.trigger('focus');
                    return;
                }
                var first = $f.get(0);
                var last = $f.get($f.length - 1);
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
                return;
            }
            if ((e.key === 'Escape' || e.key === 'Esc') && opts.closeOnEscape !== false && !e.isDefaultPrevented()) {
                e.preventDefault();
                if (opts.onClose) {
                    opts.onClose();
                }
            }
        }

        function onOverlayClick(e) {
            if ($(e.target).is($overlay) && opts.onClose) {
                opts.onClose();
            }
        }

        return {
            activate: function () {
                if (!active) {
                    lastFocus = document.activeElement;
                    $(document).on('keydown.' + ns, onKeydown);
                    if (opts.closeOnOverlay) {
                        $overlay.on('click.' + ns, onOverlayClick);
                    }
                    active = true;
                }
                resolveFocus(opts, $dialog).trigger('focus');
            },
            deactivate: function () {
                if (!active) {
                    return;
                }
                $(document).off('keydown.' + ns);
                $overlay.off('click.' + ns);
                active = false;
                if (lastFocus && typeof lastFocus.focus === 'function') {
                    lastFocus.focus();
                }
                lastFocus = null;
            },
            refreshFocus: function () {
                if (active) {
                    resolveFocus(opts, $dialog).trigger('focus');
                }
            }
        };
    };

    function resultItems($results) {
        return $results.children('.trb-product-result-item');
    }

    function formatProductPrice(product) {
        if (product.price_formatted) {
            return String(product.price_formatted);
        }
        if (typeof product.price !== 'undefined' && product.price !== '') {
            return window.torobpayFormatNumber(product.price);
        }
        return '';
    }

    window.torobpayProductCombobox = {
        hide: function ($input, $results, addButton) {
            $results.hide().empty().removeAttr('aria-multiselectable');
            $input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
            if (addButton) {
                this.setAddButtonState(addButton, 0);
            }
        },
        setHighlight: function ($input, $results, index) {
            var $items = resultItems($results);
            $items.removeClass('trb-selected');
            if (index < 0 || index >= $items.length) {
                $input.removeAttr('aria-activedescendant');
                return;
            }
            var $active = $items.eq(index);
            $active.addClass('trb-selected');
            $input.attr({
                'aria-expanded': 'true',
                'aria-activedescendant': $active.attr('id') || ''
            });
            var el = $active.get(0);
            if (el && el.scrollIntoView) {
                el.scrollIntoView({ block: 'nearest' });
            }
        },
        moveHighlight: function ($input, $results, delta) {
            var $items = resultItems($results);
            if (!$items.length) {
                return;
            }
            var current = $items.index($items.filter('.trb-selected'));
            var index = current < 0 ? (delta > 0 ? 0 : $items.length - 1) : current + delta;
            if (index < 0) {
                index = $items.length - 1;
            } else if (index >= $items.length) {
                index = 0;
            }
            this.setHighlight($input, $results, index);
        },
        isChecked: function ($item) {
            return $item.hasClass('trb-checked');
        },
        setChecked: function ($items, checked) {
            $items.toggleClass('trb-checked', !!checked).attr('aria-selected', checked ? 'true' : 'false');
            return this;
        },
        toggleChecked: function ($item) {
            this.setChecked($item, !this.isChecked($item));
            return this;
        },
        getChecked: function ($results) {
            var products = [];
            resultItems($results).filter('.trb-checked').each(function () {
                var product = $(this).data('product');
                if (product) {
                    products.push(product);
                }
            });
            return products;
        },
        clearChecked: function ($results) {
            this.setChecked(resultItems($results), false);
            return this;
        },
        checkAll: function ($results) {
            this.setChecked(resultItems($results), true);
            return this;
        },
        setAddButtonState: function (btn, count) {
            var $btn = $(btn);
            if (!$btn.length) {
                return;
            }
            var n = parseInt(count, 10) || 0;
            var enabled = n > 0;
            var label = __('Add product', 'torobpay-for-woocommerce');
            if (enabled) {
                /* translators: %d: number of selected products */
                label = sprintf(__('Add %d selected products', 'torobpay-for-woocommerce'), n);
            }
            $btn.prop('disabled', !enabled)
                .toggleClass('button-primary torobpay-button-primary', enabled)
                .attr({ title: label, 'aria-label': label });
        },
        buildItem: function (product, index, idPrefix) {
            var $meta = $('<span class="trb-product-result-meta"/>');
            if (product.sku) {
                $meta.append(
                    $('<span class="trb-product-result-sku" dir="ltr"/>').text('SKU: ' + product.sku)
                );
            }
            var priceText = formatProductPrice(product);
            if (priceText !== '') {
                $meta.append(
                    $('<span class="trb-product-result-price"/>').append($('<bdi/>').text(priceText))
                );
            }
            var addLabel = __('Add product', 'torobpay-for-woocommerce');
            var $add = $('<button type="button" class="button trb-icon-btn trb-product-result-add" tabindex="-1" aria-hidden="true"/>')
                .attr('title', addLabel)
                .append($('<span class="dashicons dashicons-plus-alt2" aria-hidden="true"/>'));
            return $('<div class="trb-product-result-item"/>')
                .attr({
                    role: 'option',
                    id: idPrefix + index,
                    'aria-selected': 'false'
                })
                .data('product', product)
                .append($('<span class="trb-product-result-check" aria-hidden="true"/>'))
                .append(
                    $('<span class="trb-product-result-main"/>')
                        .append($('<span class="trb-product-result-name"/>').text(product.name || ''))
                        .append($meta)
                )
                .append($add);
        },
        renderResults: function ($input, $results, products, idPrefix) {
            $results.empty();
            if (!products || !products.length) {
                $results.removeAttr('aria-multiselectable')
                    .append(
                        $('<div class="trb-add-product-searching"/>').text(
                            __('No products found.', 'torobpay-for-woocommerce')
                        )
                    )
                    .show();
                $input.attr('aria-expanded', 'true').removeAttr('aria-activedescendant');
                return;
            }
            var self = this;
            $results.attr('aria-multiselectable', 'true');
            $.each(products, function (index, product) {
                $results.append(self.buildItem(product, index, idPrefix));
            });
            $results.show();
            $input.attr('aria-expanded', 'true');
            this.setHighlight($input, $results, 0);
        },
        syncAddButton: function (opts) {
            if (!opts.addButton) {
                return;
            }
            this.setAddButtonState(opts.addButton, this.getChecked(opts.$results).length);
        },
        addChecked: function (opts) {
            var products = this.getChecked(opts.$results);
            if (!products.length) {
                return;
            }
            if (opts.onAddMany) {
                opts.onAddMany(products);
            }
            this.clearChecked(opts.$results);
            this.syncAddButton(opts);
        },
        addHighlighted: function (opts) {
            var $items = resultItems(opts.$results);
            var $active = $items.filter('.trb-selected');
            if (!$active.length) {
                $active = $items.first();
            }
            var product = $active.data('product');
            if (product && opts.onAddOne) {
                opts.onAddOne(product);
            }
        },
        handleKeydown: function (e, opts) {
            var $input = opts.$input;
            var $results = opts.$results;
            var $items = resultItems($results);
            var hasItems = $results.is(':visible') && $items.length > 0;
            var key = e.key;
            var ctrl = !!(e.ctrlKey || e.metaKey);

            if (key === 'ArrowDown' || key === 'Down') {
                if (!hasItems) {
                    return;
                }
                e.preventDefault();
                this.moveHighlight($input, $results, 1);
                return;
            }
            if (key === 'ArrowUp' || key === 'Up') {
                if (!hasItems) {
                    return;
                }
                e.preventDefault();
                this.moveHighlight($input, $results, -1);
                return;
            }
            if ((key === ' ' || key === 'Spacebar' || key === 'Space') && hasItems && ctrl) {
                e.preventDefault();
                var $spaceTarget = $items.filter('.trb-selected');
                if (!$spaceTarget.length) {
                    $spaceTarget = $items.first();
                }
                this.toggleChecked($spaceTarget);
                this.syncAddButton(opts);
                return;
            }
            if (ctrl && hasItems && (e.code === 'KeyA' || e.keyCode === 65)) {
                e.preventDefault();
                this.checkAll($results);
                this.syncAddButton(opts);
                return;
            }
            if (key === 'Enter') {
                if (!hasItems) {
                    return;
                }
                e.preventDefault();
                if (ctrl) {
                    this.addChecked(opts);
                    return;
                }
                this.addHighlighted(opts);
                return;
            }
            if ((key === 'Escape' || key === 'Esc') && $results.is(':visible')) {
                e.preventDefault();
                e.stopPropagation();
                this.hide($input, $results, opts.addButton);
                return;
            }
            if (key === 'Tab' && $results.is(':visible')) {
                if (!e.shiftKey && this.getChecked($results).length && opts.addButton) {
                    return;
                }
                this.hide($input, $results, opts.addButton);
            }
        },
        bind: function (opts) {
            var cb = this;
            var inputSel = opts.inputSelector;
            var resultsSel = opts.resultsSelector;
            var addSel = opts.addButton;

            function context() {
                return {
                    $input: $(inputSel),
                    $results: $(resultsSel),
                    addButton: addSel,
                    onAddOne: opts.onAddOne,
                    onAddMany: opts.onAddMany
                };
            }

            $(document).on('keydown', inputSel, function (e) {
                cb.handleKeydown(e, context());
            });

            $(document).on('mouseenter', resultsSel + ' .trb-product-result-item', function () {
                var ctx = context();
                cb.setHighlight(ctx.$input, ctx.$results, resultItems(ctx.$results).index(this));
            });

            $(document).on('mousedown', resultsSel + ' .trb-product-result-add', function (e) {
                e.preventDefault();
            });

            $(document).on('click', resultsSel + ' .trb-product-result-add', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var product = $(this).closest('.trb-product-result-item').data('product');
                if (product && opts.onAddOne) {
                    opts.onAddOne(product);
                }
            });

            $(document).on('click', resultsSel + ' .trb-product-result-item', function (e) {
                if ($(e.target).closest('.trb-product-result-add').length) {
                    return;
                }
                var ctx = context();
                var $item = $(this);
                cb.toggleChecked($item);
                cb.setHighlight(ctx.$input, ctx.$results, resultItems(ctx.$results).index($item));
                cb.syncAddButton(ctx);
            });

            $(document).on('click', addSel, function (e) {
                e.preventDefault();
                cb.addChecked(context());
            });

            this.ensureHelp(
                $(inputSel).closest('.torobpay-modal'),
                $(inputSel).closest('.trb-add-product-section')
            );
        },
        helpRow: function (keys, desc) {
            var $keys = $('<span class="trb-kbd-help__keys"/>');
            $.each(keys, function (_, key) {
                $keys.append($('<kbd/>').text(key));
            });
            return $('<div class="trb-kbd-help__row"/>')
                .append($('<span class="trb-kbd-help__desc"/>').text(desc))
                .append($keys);
        },
        helpComboRow: function (mods, key, desc) {
            var $keys = $('<span class="trb-kbd-help__keys"/>');
            $.each(mods, function (i, mod) {
                if (i) {
                    $keys.append($('<span class="trb-kbd-help__sep"/>').text('/'));
                }
                $keys.append($('<kbd/>').text(mod));
            });
            $keys.append($('<span class="trb-kbd-help__sep"/>').text('+'));
            $keys.append($('<kbd/>').text(key));
            return $('<div class="trb-kbd-help__row"/>')
                .append($('<span class="trb-kbd-help__desc"/>').text(desc))
                .append($keys);
        },
        buildHelp: function () {
            var panelId = 'trb-kbd-help-' + Math.random().toString(36).slice(2, 10);
            var title = __('Keyboard shortcuts', 'torobpay-for-woocommerce');
            var $btn = $('<button type="button" class="trb-kbd-help__btn" aria-expanded="false"/>')
                .attr({
                    'aria-label': title,
                    'aria-controls': panelId,
                    title: title
                })
                .append($('<span class="dashicons dashicons-editor-help" aria-hidden="true"/>'));
            var $panel = $('<div class="trb-kbd-help__panel" role="tooltip"/>')
                .attr('id', panelId)
                .append($('<p class="trb-kbd-help__title"/>').text(title))
                .append(this.helpRow(['↑', '↓'], __('Move highlight', 'torobpay-for-woocommerce')))
                .append(this.helpComboRow(['Ctrl', '⌘'], 'Space', __('Check or uncheck', 'torobpay-for-woocommerce')))
                .append(this.helpRow(['Enter'], __('Add highlighted product', 'torobpay-for-woocommerce')))
                .append(this.helpComboRow(['Ctrl', '⌘'], 'Enter', __('Add checked products', 'torobpay-for-woocommerce')))
                .append(this.helpComboRow(['Ctrl', '⌘'], 'A', __('Check all results', 'torobpay-for-woocommerce')))
                .append(this.helpRow(['Esc'], __('Close the list', 'torobpay-for-woocommerce')))
                .append(this.helpRow(['Tab'], __('Go to add when items are checked', 'torobpay-for-woocommerce')));
            var $help = $('<div class="trb-kbd-help"/>').append($btn).append($panel);
            $help.on('mouseenter focusin', function () {
                $btn.attr('aria-expanded', 'true');
            });
            $help.on('mouseleave focusout', function (e) {
                if ($help.has(e.relatedTarget).length) {
                    return;
                }
                $btn.attr('aria-expanded', 'false');
            });
            return $help;
        },
        ensureHelp: function ($modal, $search) {
            if (!$modal || !$modal.length || $modal.find('.trb-kbd-help').length) {
                return;
            }
            var $header = $modal.children('.torobpay-modal-header').first();
            var $close = $header.children('.torobpay-modal-close');
            if (!$close.length) {
                $close = $header.find('.torobpay-modal-close').first();
            }
            if (!$header.length || !$close.length) {
                return;
            }
            if (!$close.parent().hasClass('torobpay-modal-header-actions')) {
                $close.wrap('<div class="torobpay-modal-header-actions"></div>');
            }
            var $help = this.buildHelp();
            $close.parent().prepend($help);
            this.bindHelpVisibility($help, $search, $modal);
        },
        bindHelpVisibility: function ($help, $search, $modal) {
            function sync() {
                var visible = !!( $search && $search.length && $search.is(':visible') );
                $help.toggleClass('is-visible', visible);
                if (!visible) {
                    $help.find('.trb-kbd-help__btn').attr('aria-expanded', 'false');
                }
            }
            sync();
            if (typeof MutationObserver === 'undefined' || !$search || !$search.length) {
                return;
            }
            var $overlay = $modal.closest('.torobpay-modal-overlay');
            var $root = $overlay.length ? $overlay : $modal;
            var observer = new MutationObserver(sync);
            $search.add($search.parentsUntil($root.parent())).add($root).each(function () {
                observer.observe(this, { attributes: true, attributeFilter: ['style', 'class', 'hidden'] });
            });
        }
    };
})(window, jQuery);
