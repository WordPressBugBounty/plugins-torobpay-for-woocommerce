(function ($) {
    'use strict';

    var __ = ( window.wp && wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function( s ) { return s; };
    var RANGE_SEPARATOR = ' - ';

    function pad2(n) {
        n = String(n);
        return n.length < 2 ? '0' + n : n;
    }

    function gregorianToJalali(gy, gm, gd) {
        var g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        var jy = (gy <= 1600) ? 0 : 979;
        gy -= (gy <= 1600) ? 621 : 1600;
        var gy2 = (gm > 2) ? (gy + 1) : gy;
        var days = (365 * gy) + (parseInt((gy2 + 3) / 4)) - (parseInt((gy2 + 99) / 100)) +
            (parseInt((gy2 + 399) / 400)) - 80 + gd + g_d_m[gm - 1];
        jy += 33 * (parseInt(days / 12053));
        days %= 12053;
        jy += 4 * (parseInt(days / 1461));
        days %= 1461;
        jy += parseInt((days - 1) / 365);
        if (days > 365) days = (days - 1) % 365;
        var jm = (days < 186) ? 1 + parseInt(days / 31) : 7 + parseInt((days - 186) / 30);
        var jd = 1 + ((days < 186) ? (days % 31) : ((days - 186) % 30));
        return [jy, jm, jd];
    }

    function formatJalali(parts) {
        return parts[0] + '/' + pad2(parts[1]) + '/' + pad2(parts[2]);
    }

    function parseGregorianYmd(value) {
        var parts = String(value).trim().split(/[-/]/);
        if (parts.length !== 3) {
            return null;
        }
        var year = parseInt(parts[0], 10);
        var month = parseInt(parts[1], 10);
        var day = parseInt(parts[2], 10);
        if (!year || !month || !day) {
            return null;
        }
        return gregorianToJalali(year, month, day);
    }

    function hydrateJalaliRangeInput() {
        var hidden = document.getElementById('date_range');
        var visible = document.getElementById('date_range_display');
        if (!hidden || !visible || !hidden.value) {
            return;
        }

        var chunks = hidden.value.split(RANGE_SEPARATOR).map(function (chunk) {
            return chunk.trim();
        }).filter(Boolean);
        var jalali = [];
        var i;
        var converted;

        for (i = 0; i < chunks.length; i++) {
            converted = parseGregorianYmd(chunks[i]);
            if (!converted) {
                return;
            }
            jalali.push(formatJalali(converted));
        }

        visible.value = jalali.join(RANGE_SEPARATOR);
    }

    function handleExportAllCSV(event) {
        event.preventDefault();
        var form = event.currentTarget && event.currentTarget.closest('form');
        if (!form) {
            return;
        }
        // Do not post name="action" on a synthetic form — that shadows form.action
        // and navigates to "export_all_csv". Reuse the list-table form (has nonce).
        var input = form.querySelector('input[name="torobpay_export"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'torobpay_export';
            form.appendChild(input);
        }
        input.value = 'all';
        form.submit();
        // CSV download does not reload the page; drop the flag after the browser
        // serializes the POST so later bulk actions are not remapped to export-all.
        window.setTimeout(function () {
            if (input.parentNode) {
                input.parentNode.removeChild(input);
            }
        }, 0);
    }

    function clearTorobpayExportFlag(form) {
        if (!form) {
            return;
        }
        var leftover = form.querySelector('input[name="torobpay_export"]');
        if (leftover && leftover.parentNode) {
            leftover.parentNode.removeChild(leftover);
        }
    }

    function handleExportSelectedCSV() {
        var checkedBoxes = document.querySelectorAll('input[name="order[]"]:checked');
        if (checkedBoxes.length === 0) {
            window.torobpayShowToast(__( 'Please select at least one order.', 'torobpay-for-woocommerce' ), 'warning');
            return false;
        }

        var bulkSelector = document.getElementById('bulk-action-selector-top') || document.getElementById('bulk-action-selector-bottom');
        if (bulkSelector) {
            bulkSelector.value = 'export_csv';
            var mainForm = bulkSelector.closest('form');
            if (mainForm) {
                clearTorobpayExportFlag(mainForm);
                mainForm.submit();
            }
        }
    }

    $(function () {
        var filterKeys = ['order_status', 'mobile_search', 'order_id_search', 'date_range', 'torobpay_status', 'order_channel'];
        var hasActiveFilter = filterKeys.some(function(key) {
            var el = document.querySelector('[name="' + key + '"]');
            return el && el.value !== '';
        });
        if (hasActiveFilter) {
            $('#filter-container').show();
        }

        // Bind export before datepicker init so a startWatch/conversion throw
        // cannot abort ready and leave the buttons as inert type="button".
        $(document).on('click', '#export-all-csv-button', handleExportAllCSV);
        $(document).on('click', '#export-selected-csv-button', handleExportSelectedCSV);

        if ( window.jalaliDatepicker && typeof window.jalaliDatepicker.startWatch === 'function' ) {
            window.jalaliDatepicker.startWatch({
                mode: 'attr',
                maxDate: 'attr',
                targetValueInput: 'attr',
                targetValueType: 'attr',
                hideAfterChange: true,
                useDropDownYears: false,
                persianDigits: true,
                time: false,
            });
        }

        hydrateJalaliRangeInput();

        $(document).on('input', '#date_range_display', function() {
            if (this.value === '') {
                var hidden = document.getElementById('date_range');
                if (hidden) {
                    hidden.value = '';
                }
            }
        });
    });
})(jQuery);
