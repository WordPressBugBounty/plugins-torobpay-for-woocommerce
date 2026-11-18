/**
 * TorobPay admin toast notifications (orders, settings, and shared admin UI).
 */
(function ($) {
    'use strict';

    var __ = ( window.wp && wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function( s ) { return s; };

    var toastSeq = 0;
    var activeProgressToast = null;
    var icons = {
        success: 'yes-alt',
        error:   'warning',
        warning: 'warning',
        info:    'info'
    };

    function ensureContainer() {
        if (!$('#torobpay-toast-container').length) {
            $('body').append(
                '<div id="torobpay-toast-container" class="torobpay-toast-container" aria-live="polite"></div>'
            );
        }
    }

    function clearProgressToast() {
        if (activeProgressToast && activeProgressToast.dismiss) {
            activeProgressToast.dismiss();
            activeProgressToast = null;
        }
    }

    /**
     * @param {string} message
     * @param {string} type success|error|warning|info
     * @param {Object} options duration (ms), sticky (bool), progress (bool)
     * @returns {{ dismiss: Function }}
     */
    function showToast(message, type, options) {
        options = options || {};
        type = type || 'info';
        ensureContainer();
        clearProgressToast();

        var duration = options.duration !== undefined
            ? options.duration
            : (options.progress ? 0
                : (type === 'success' ? 3000 : ((options.sticky === true || type === 'error') ? 0 : 4000)));
        var icon = icons[type] || icons.info;
        var toastId = 'torobpay-toast-' + (++toastSeq);

        var $toast = $(
            '<div class="torobpay-toast torobpay-toast--' + type + '" role="' + (type === 'error' ? 'alert' : 'status') + '">' +
                '<span class="dashicons dashicons-' + icon + ' torobpay-toast-icon" aria-hidden="true"></span>' +
                '<span class="torobpay-toast-message"></span>' +
                '<button type="button" class="torobpay-toast-dismiss" aria-label="' + __('Close', 'torobpay-for-woocommerce') + '">&times;</button>' +
            '</div>'
        ).attr('id', toastId);

        if (options.html) {
            $toast.find('.torobpay-toast-message').html(message);
        } else {
            $toast.find('.torobpay-toast-message').text(message);
        }
        $('#torobpay-toast-container').append($toast);
        requestAnimationFrame(function () {
            $toast.addClass('is-visible');
        });

        function dismissToast() {
            $toast.removeClass('is-visible');
            setTimeout(function () { $toast.remove(); }, 200);
            if (activeProgressToast && activeProgressToast.$el && activeProgressToast.$el[0] === $toast[0]) {
                activeProgressToast = null;
            }
        }

        $toast.on('click', '.torobpay-toast-dismiss', dismissToast);
        if (duration > 0) {
            setTimeout(dismissToast, duration);
        }

        var handle = { dismiss: dismissToast, $el: $toast };
        if (options.progress) {
            activeProgressToast = handle;
        }
        return handle;
    }

    window.torobpayShowToast = showToast;
    window.torobpayClearProgressToast = clearProgressToast;
})(jQuery);
