/**
 * TorobPay Badge Compatibility Mode
 * 
 * This script injects product badges into product listings when normal hook-based
 * placement doesn't work due to theme or page builder conflicts.
 */

(function($) {
    'use strict';

    var __ = ( window.wp && wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function( s ) { return s; };
    var sprintf = ( window.wp && wp.i18n && wp.i18n.sprintf ) ? wp.i18n.sprintf : function( fmt ) { var a = Array.prototype.slice.call( arguments, 1 ); return fmt.replace( /%s/g, function() { return a.shift(); } ); };
    'use strict';

    // Configuration from localized script
    const config = torobpayBadgeCompat || {};
    
    /**
     * Find all product elements in the page
     */
    function findProductElements() {
        const selectors = [
            // WooCommerce default
            '.products .product',
            'ul.products li.product',
            '.woocommerce .product',

            // Direct children of a products list (custom/page-builder themes that
            // render cards as <div>/<li> without a .product class, e.g. Elementor
            // child themes). Kept generic so any product-grid wrapper is covered.
            'ul.products > li',
            'ul.products > div',
            '.products > li',
            '.products > div',

            // Common theme patterns
            '.product-item',
            '.product-card',
            '.woo-product',
            
            // Elementor
            '.elementor-widget-wc-products .product',
            '.elementor-product',
            
            // Grid/list patterns
            '.product-grid-item',
            '.product-list-item',
            
            // Other page builders
            '.vc_grid-item-mini',

            // Crocoblock / JetEngine (Listing Grid + JetWooBuilder). These render
            // products from custom templates that bypass the Woo loop hooks, so
            // the card often has no .product class and no add-to-cart link — the
            // product id lives in data-post-id instead.
            '.jet-listing-grid__item',
            '.jet-woo-products__item',
            '.jet-woo-builder-archive-product',
            
            // Archive/shop patterns
            'article.product',
            '.type-product'
        ];

        // Use a Set to dedupe elements matched by multiple selectors.
        const productSet = new Set();

        selectors.forEach(selector => {
            $(selector).each(function() {
                if (!$(this).data('torobpay-processed')) {
                    productSet.add(this);
                }
            });
        });

        // Fallback: derive product cards from add-to-cart triggers. This is the
        // most theme-agnostic signal — every purchasable product in a loop has an
        // container so custom markup (no .product class) is still badged.
        $('a[href*="add-to-cart="], [data-product_id], [data-product-id]').each(function() {
            const $card = findCardContainer($(this));
            if ($card && $card.length && !$card.data('torobpay-processed')) {
                productSet.add($card.get(0));
            }
        });

        // Drop any candidate that is an ancestor of another candidate so we badge
        // the innermost card (not a wrapping grid/column element).
        const all = Array.from(productSet);
        return all.filter(el => !all.some(other => other !== el && el.contains(other)));
    }

    /**
     * Walk up from an add-to-cart trigger to the product card container.
     *
     * Prefers a direct child of a products list/grid wrapper; otherwise falls
     * back to the nearest ancestor that looks like a product card.
     */
    function findCardContainer($trigger) {
        // Nearest ancestor that is a direct child of a products grid wrapper.
        const $listChild = $trigger
            .parentsUntil('ul.products, .products, .elementor-widget-wc-products, .jet-woo-products, .jet-listing-grid__items, .jet-listing-grid')
            .last();
        if ($listChild.length && $listChild.parent().is('ul.products, .products, .elementor-widget-wc-products, .jet-woo-products, .jet-listing-grid__items, .jet-listing-grid')) {
            return $listChild;
        }

        // Fallback: closest element carrying a product-ish class.
        const $byClass = $trigger.closest(
            '.product, li.product, article.product, .product-item, .product-card, ' +
            '.woo-product, .product-grid-item, .product-list-item, .jet-woo-products__item, ' +
            '.jet-listing-grid__item, .type-product'
        );
        if ($byClass.length) {
            return $byClass;
        }

        return $trigger.closest('li, article');
    }
    
    /**
     * Extract product data from element
     */
    function extractProductData($element) {
        // Try to find product ID from various attributes on the card itself.
        let productId = $element.data('product-id') ||
                       $element.data('product_id') ||
                       $element.data('post-id') ||
                       $element.data('item-object-id');

        if (!productId) {
            const $withId = $element.find('[data-product_id], [data-product-id]').first();
            if ($withId.length) {
                productId = $withId.attr('data-product_id') || $withId.attr('data-product-id');
            }
        }

        // JetEngine / JetWooBuilder listing card: the post (product) id lives on
        // the card or a child node as data-post-id / data-item-object-id.
        if (!productId) {
            const $withPost = $element.find('[data-post-id], [data-item-object-id]').first();
            if ($withPost.length) {
                productId = $withPost.attr('data-post-id') || $withPost.attr('data-item-object-id');
            }
        }

        // permalink match because product permalinks also contain "product".
        if (!productId) {
            const cartLink = $element.find('a[href*="add-to-cart="]').first().attr('href');
            if (cartLink) {
                const match = cartLink.match(/[?&]add-to-cart=(\d+)/);
                if (match) {
                    productId = match[1];
                }
            }
        }

        // Add-to-cart form (JetWooBuilder / Woo ajax add-to-cart): the product id
        // is carried by a hidden field or button named add-to-cart / product_id.
        if (!productId) {
            const $cartInput = $element
                .find('form.cart [name="add-to-cart"], form.cart [name="product_id"], button[name="add-to-cart"]')
                .first();
            if ($cartInput.length) {
                productId = $cartInput.val() || $cartInput.attr('value');
            }
        }

        // Try to extract from class names (e.g., post-123)
        if (!productId) {
            const classes = $element.attr('class') || '';
            const match = classes.match(/post-(\d+)/);
            if (match) {
                productId = match[1];
            }
        }

        // Normalize to a clean numeric string (data-* attributes may come back as
        // numbers, and some builders prefix the id).
        if (productId) {
            productId = String(productId).replace(/[^\d]/g, '');
        }

        return {
            productId: productId || undefined,
            $element: $element
        };
    }
    
    /**
     * Find the image container within product element
     */
    function findImageContainer($product) {
        const selectors = [
            '.woocommerce-loop-product__link',
            '.product-image',
            '.product-thumbnail',
            '.product-img',
            'a.woocommerce-LoopProduct-link',
            '.attachment-woocommerce_thumbnail',
            'img'
        ];
        
        for (let selector of selectors) {
            const $container = $product.find(selector).first();
            if ($container.length) {
                // If it's an img, use its parent
                if ($container.is('img')) {
                    return $container.parent();
                }
                return $container;
            }
        }
        
        return $product; // Fallback
    }
    
    /**
     * Create badge HTML
     */
    function createBadge(data) {
        if (!config.badgeEnabled) return '';

        const logo = config.logo;
        const logoHeight = config.logoHeight || 24;
        const logoWidth = logoHeight;
        
        const textHtml = data.showPrice ? sprintf( __( 'Each installment %s', 'torobpay-for-woocommerce' ), data.formattedPrice ) : __( 'Installment payment', 'torobpay-for-woocommerce' );
        // Plain price is already decoded and clean from PHP
        const textPlain = data.showPrice ? sprintf( __( 'Each installment %s', 'torobpay-for-woocommerce' ), data.plainPrice ) : __( 'Installment payment', 'torobpay-for-woocommerce' );
        
        return `
            <div class="torobpay-product-badge torobpay-compat-badge" 
                 data-product-id="${data.productId}"
                 aria-label="${textPlain}">
                <div class="torobpay-product-badge-inner">
                    <img src="${logo}" 
                         alt="${__( 'TorobPay installment payment', 'torobpay-for-woocommerce' )}" 
                         class="torobpay-product-badge-icon"
                         width="${logoWidth}"
                         height="${logoHeight}" />
                    <span class="torobpay-product-badge-text">${textHtml}</span>
                </div>
            </div>
        `;
    }
    
    /**
     * Create marquee HTML (used in JS compatibility mode)
     */
    function createMarquee(data) {
        if (!config.marqueeEnabled) return '';

        const logo = config.logo;
        const slogan = config.marqueeSlogan || __( 'Buy in installments with TorobPay — no fees', 'torobpay-for-woocommerce' );
        const priceText = data.showPrice ? sprintf( __( 'Each installment %s', 'torobpay-for-woocommerce' ), data.formattedPrice ) : __( 'Installment payment', 'torobpay-for-woocommerce' );
        const iconSize = config.marqueeIconSize || 14;

        const singleItem = `<span class="torobpay-product-marquee-item">`
            + `<img src="${logo}" alt="" class="torobpay-product-marquee-logo" width="${iconSize}" height="${iconSize}" />`
            + `${priceText}<span class="torobpay-product-marquee-sep">•</span>${slogan}&nbsp;&nbsp;&nbsp;`
            + `</span>`;

        // 4 copies ensures content is wide enough for any container; keyframe shifts -25% (1 copy)
        const copy = singleItem.replace('class="torobpay-product-marquee-item"', 'class="torobpay-product-marquee-item" aria-hidden="true"');
        const trackItems = singleItem + copy + copy + copy;

        return `<div class="torobpay-product-marquee" data-product-id="${data.productId}">`
            + `<div class="torobpay-product-marquee-track">${trackItems}</div>`
            + `</div>`;
    }

    /**
     * Inject badge into product
     */
    function injectBadge($product, badgeData) {
        if (!config.badgeEnabled) {
            return;
        }

        // Skip if a badge already exists on this card — either one we injected
        // here, or one printed server-side by the normal loop-hook mode. This
        // keeps the two modes from doubling badges when both run together.
        if ($product.find('.torobpay-product-badge').length > 0) {
            return;
        }
        
        const $imageContainer = findImageContainer($product);
        
        if ($imageContainer.css('position') === 'static') {
            $imageContainer.css('position', 'relative');
        }
        
        const badgeHtml = createBadge(badgeData);
        $imageContainer.prepend(badgeHtml);
        
    }
    
    /**
     * Inject marquee into product image container (JS compatibility mode)
     */
    function injectMarquee($product, badgeData) {
        if (!config.marqueeEnabled) return;

        if ($product.find('.torobpay-product-marquee').length > 0) {
            return;
        }

        const $imageContainer = findImageContainer($product);

        if ($imageContainer.css('position') === 'static') {
            $imageContainer.css('position', 'relative');
        }

        $imageContainer.css('overflow', 'hidden').addClass('has-torobpay-marquee');

        const marqueeHtml = createMarquee(badgeData);
        if (marqueeHtml) {
            $imageContainer.append(marqueeHtml);
        }
    }

    /**
     * Fetch badge data for products from server
     */
    function fetchBadgeData(productIds) {
        if (!productIds || productIds.length === 0) {
            return Promise.resolve({});
        }
        
        return $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'torobpay_get_badge_data',
                nonce: config.nonce,
                product_ids: productIds
            },
            dataType: 'json'
        }).then(function(response) {
            if (response.success && response.data) {
                return response.data;
            }
            return {};
        }).catch(function(error) {
            console.warn('TorobPay: Failed to fetch badge data', error);
            return {};
        });
    }
    
    /**
     * Process all products on the page
     */
    function processProducts() {
        const products = findProductElements();
        
        if (products.length === 0) {
            return;
        }
        
        const productData = [];
        const $products = [];
        
        products.forEach(function(product) {
            const $product = $(product);
            const data = extractProductData($product);
            
            if (data.productId) {
                productData.push(data);
                $products.push($product);
            }
        });
        
        if (productData.length === 0) {
            return;
        }
        
        const productIds = productData.map(p => p.productId);
        
        fetchBadgeData(productIds).then(function(badgeDataMap) {
            productData.forEach(function(data, index) {
                const badgeData = badgeDataMap[data.productId];
                
                if (badgeData && badgeData.valid) {
                    let didInject = false;

                    if (config.badgeEnabled) {
                        injectBadge($products[index], {
                            productId: data.productId,
                            showPrice: badgeData.showPrice,
                            formattedPrice: badgeData.formattedPrice,
                            plainPrice: badgeData.plainPrice || badgeData.formattedPrice
                        });
                        didInject = true;
                    }

                    if (config.marqueeEnabled) {
                        injectMarquee($products[index], {
                            productId: data.productId,
                            showPrice: badgeData.showPrice,
                            formattedPrice: badgeData.formattedPrice
                        });
                        didInject = true;
                    }

                    if (didInject) {
                        $products[index].data('torobpay-processed', true);
                    }
                }
            });
        });
    }
    
    /**
     * Initialize compatibility mode
     */
    function init() {
        processProducts();
        
        // Watch for new products (infinite scroll, AJAX filters, etc.)
        const observer = new MutationObserver(function(mutations) {
            let shouldProcess = false;
            
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length > 0) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) { // Element node
                            const $node = $(node);
                            if ($node.hasClass('product') || 
                                $node.find('.product').length > 0 ||
                                $node.hasClass('products') ||
                                $node.is('ul.products')) {
                                shouldProcess = true;
                            }
                        }
                    });
                }
            });
            
            if (shouldProcess) {
                // Debounce processing
                clearTimeout(init.timer);
                init.timer = setTimeout(processProducts, 300);
            }
        });
        
        // Observe the entire document for changes
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
        
        // Re-process on common events
        $(document).on('yith-wcan-ajax-filtered', processProducts);
        $(document).on('berocket_ajax_products_loaded', processProducts);
        $(window).on('load', processProducts);
    }
    
    // Start when DOM is ready
    $(document).ready(init);
    
})(jQuery);
