/**
 * TorobPay Gateway Settings Admin Scripts
 *
 * Scoped to the WooCommerce gateway settings page for TorobPay.
 * Handles the DNS IP override field toggle, credential fetch AJAX,
 * and injects dashicons into WooCommerce section title headers.
 */
jQuery(function ($) {
    'use strict';

    var __ = ( window.wp && wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function( s ) { return s; };

    /* ── Gateway URL stays locked until the merchant opts in ── */
    var $baseUrl = $('#woocommerce_WC_Gateway_TorobPay_base_url');
    var $baseUrlUnlock = $('#torobpay_base_url_unlock');

    if ($baseUrl.length && $baseUrlUnlock.length) {
        var savedBaseUrl = $baseUrl.val();

        function syncBaseUrlLock() {
            var unlocked = $baseUrlUnlock.is(':checked');
            if (!unlocked) {
                $baseUrl.val(savedBaseUrl);
            }
            $baseUrl.prop('readonly', !unlocked);
            $baseUrl.toggleClass('torobpay-readonly-input', !unlocked);
            $baseUrl.attr('aria-disabled', unlocked ? 'false' : 'true');
        }

        syncBaseUrlLock();
        $baseUrlUnlock.on('change', syncBaseUrlLock);
    }

    /* ── DNS IP override enable/disable toggle ── */
    var $toggle = $('#woocommerce_WC_Gateway_TorobPay_dns_smart_resolve_enabled');
    var $input  = $('#woocommerce_WC_Gateway_TorobPay_dns_ip_override');

    if ($toggle.length && $input.length) {
        var $row = $input.closest('tr');

        function syncDnsFieldState() {
            var enabled = $toggle.is(':checked');
            $input.prop('readonly', !enabled);
            $input.toggleClass('torobpay-readonly-input', !enabled);
            $input.attr('aria-disabled', enabled ? 'false' : 'true');
            $row.css('opacity', enabled ? '' : '0.55');
        }

        syncDnsFieldState();
        $toggle.on('change', syncDnsFieldState);
    }

    /* ── Inject dashicons into section title headers ── */
    var sectionIcons = {
        'woocommerce_WC_Gateway_TorobPay_base_config':    'dashicons-admin-settings',
        'woocommerce_WC_Gateway_TorobPay_account_config': 'dashicons-id-alt',
        'woocommerce_WC_Gateway_TorobPay_advanced_config': 'dashicons-admin-tools',
        'woocommerce_WC_Gateway_TorobPay_utm_config':     'dashicons-tag',
        'woocommerce_WC_Gateway_TorobPay_payment_config': 'dashicons-money-alt',
    };

    $.each(sectionIcons, function (id, icon) {
        var $h3 = $('#' + id);
        if ($h3.length) {
            $h3.prepend('<span class="dashicons ' + icon + ' trb-section-icon"></span>');
        }
    });

    /* ── Manual provision / fetch credentials ── */
    $(document).on('click', '.torobpay-fetch-credentials-btn', function (e) {
        e.preventDefault();

        var config = window.torobpayProvision || {};
        if (!config.ajaxUrl || !config.nonce) {
            return;
        }

        var $btn = $(this);
        if ($btn.prop('disabled') || $btn.data('torobpayFetching')) {
            return;
        }

        var originalHtml = $btn.html();
        $btn.data('torobpayFetching', true)
            .prop('disabled', true)
            .text(config.fetchingText || __( 'Fetching…', 'torobpay-for-woocommerce' ));

        $.post(config.ajaxUrl, {
            action: 'torobpay_fetch_credentials',
            nonce: config.nonce
        })
            .done(function (response) {
                var data = (response && response.data) ? response.data : {};
                var message = data.message || (config.defaultError || __( 'Error', 'torobpay-for-woocommerce' ));
                var messageHtml = data.message_html || '';
                var hideFetch = !!data.hide_fetch;

                if (response && response.success) {
                    if (window.torobpayShowToast) {
                        window.torobpayShowToast(message, 'success');
                    }

                    hideFetch = true;
                    if (data.reload) {
                        window.setTimeout(function () {
                            window.location.reload();
                        }, 600);
                    }
                } else if (window.torobpayShowToast) {
                    if (messageHtml) {
                        window.torobpayShowToast(messageHtml, 'error', { sticky: true, html: true });
                    } else {
                        window.torobpayShowToast(message, 'error', { sticky: true });
                    }
                } else {
                    window.alert(message);
                }

                if (hideFetch) {
                    var $row = $btn.closest('tr');
                    if ($row.length) {
                        $row.hide();
                    }
                    $btn.remove();
                    return;
                }
            })
            .fail(function (xhr) {
                var message = config.defaultError || __( 'Error', 'torobpay-for-woocommerce' );
                var messageHtml = '';
                var hideFetch = false;
                if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                    if (xhr.responseJSON.data.message) {
                        message = xhr.responseJSON.data.message;
                    }
                    if (xhr.responseJSON.data.message_html) {
                        messageHtml = xhr.responseJSON.data.message_html;
                    }
                    hideFetch = !!xhr.responseJSON.data.hide_fetch;
                }
                if (window.torobpayShowToast) {
                    if (messageHtml) {
                        window.torobpayShowToast(messageHtml, 'error', { sticky: true, html: true });
                    } else {
                        window.torobpayShowToast(message, 'error', { sticky: true });
                    }
                } else {
                    window.alert(message);
                }

                if (hideFetch) {
                    var $row = $btn.closest('tr');
                    if ($row.length) {
                        $row.hide();
                    }
                    $btn.remove();
                }
            })
            .always(function () {
                if (!$btn.closest('body').length) {
                    return;
                }
                $btn.data('torobpayFetching', false)
                    .prop('disabled', false)
                    .html(originalHtml);
            });
    });
});
