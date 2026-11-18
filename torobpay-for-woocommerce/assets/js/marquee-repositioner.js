/**
 * TorobPay Marquee Repositioner
 *
 * Moves server-rendered .torobpay-product-marquee elements inside their
 * product image containers so they overlay the bottom of the thumbnail.
 * Runs in hook-based (non-compatibility) mode only.
 */
(function($) {
    'use strict';

    var imageSelectors = [
        'a.woocommerce-LoopProduct-link',
        '.woocommerce-loop-product__link',
        '.product-image',
        '.product-thumbnail',
        '.product-img'
    ];

    function moveMarquees() {
        $('.torobpay-product-marquee').each(function() {
            var $marquee = $(this);

            if ($marquee.data('tp-repositioned')) {
                return;
            }

            // Find the closest product wrapper
            var $product = $marquee.closest(
                '.product, li.product, article.product, .type-product, ' +
                '.product-item, .product-card, .woo-product, .wc-block-grid__product'
            );

            if (!$product.length) {
                return;
            }

            // Find the image anchor/container
            var $imageContainer = null;
            for (var i = 0; i < imageSelectors.length; i++) {
                var $found = $product.find(imageSelectors[i]).first();
                if ($found.length) {
                    $imageContainer = $found;
                    break;
                }
            }

            if (!$imageContainer) {
                return;
            }

            if ($imageContainer.css('position') === 'static') {
                $imageContainer.css('position', 'relative');
            }

            $imageContainer.css('overflow', 'hidden').addClass('has-torobpay-marquee');

            $marquee.appendTo($imageContainer);
            $marquee.data('tp-repositioned', true);
        });
    }

    $(document).ready(moveMarquees);
    $(window).on('load', moveMarquees);

})(jQuery);
