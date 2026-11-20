/**
 * TorobPay payment method integration for WooCommerce Blocks
 * 
 * This file registers the TorobPay payment method with the WooCommerce Blocks system,
 * allowing it to be used in the block-based checkout experience.
 *
 * Note: settings.description comes from the eligible response. descriptionType
 * picks the presentation. discount and first_order_discount render that
 * sentence on the method label. installment, and any unknown type, stay in
 * the payment method description. Inline formatting in the sentence is kept.
 * The allowed tags match torobpay_eligibility_description_allowed_html().
 */
(function() {
    // Exit early if WooCommerce Blocks is not available
    if (!window.wc || !window.wc.wcBlocksRegistry || !window.wc.wcSettings) {
        return;
    }

    // Get required dependencies
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { getSetting } = window.wc.wcSettings;
    const { createElement, useEffect, Fragment } = window.wp.element;
    const { useSelect } = window.wp.data;
    const cartStoreKey = ( window.wc.wcBlocksData && window.wc.wcBlocksData.CART_STORE_KEY ) || 'wc/store/cart';
    const { __ } = window.wp.i18n;
    const { decodeEntities } = window.wp.htmlEntities || {};
    
    // Get the payment settings
    const settings = getSetting('WC_Gateway_TorobPay_data', {});

    function decodeText(value) {
        if (typeof value !== 'string') {
            return '';
        }
        return decodeEntities ? decodeEntities(value) : value;
    }

    // Keep in step with torobpay_eligibility_description_allowed_html().
    var decorationTags = {
        STRONG: true,
        B: true,
        EM: true,
        I: true,
        U: true,
        S: true,
        DEL: true,
        INS: true,
        MARK: true,
        SMALL: true,
        SUB: true,
        SUP: true,
        BR: true,
        SPAN: true
    };

    function sanitizeDecorationNode(node) {
        Array.prototype.slice.call(node.childNodes).forEach(function(child) {
            if (child.nodeType === 3) {
                return;
            }
            if (child.nodeType === 1 && (child.tagName === 'SCRIPT' || child.tagName === 'STYLE')) {
                node.removeChild(child);
                return;
            }
            if (child.nodeType !== 1 || !decorationTags[child.tagName]) {
                node.replaceChild(document.createTextNode(child.textContent || ''), child);
                return;
            }
            while (child.attributes.length) {
                child.removeAttribute(child.attributes[0].name);
            }
            sanitizeDecorationNode(child);
        });
    }

    function descriptionHtml(value) {
        if (typeof value !== 'string' || value === '') {
            return '';
        }
        // Do not run this through decodeEntities. That helper reads textarea
        // text and drops tags such as strong before they can be rendered.
        if (typeof document === 'undefined') {
            return value;
        }
        var holder = document.createElement('template');
        var root = holder.content;
        if (!root) {
            holder = document.createElement('div');
            root = holder;
        }
        holder.innerHTML = value;
        sanitizeDecorationNode(root);
        return holder.innerHTML;
    }

    // Keep in step with torobpay_normalize_description_type().
    function normalizeDescriptionType(type) {
        if (type === 'discount' || type === 'first_order_discount' || type === 'installment') {
            return type;
        }
        return 'installment';
    }

    function isOfferType(type) {
        return type === 'discount' || type === 'first_order_discount';
    }

    // One CSS url() string. Same cuts as torobpay_checkout_logo_html():
    // quotes, parentheses, backslashes, and semicolons cannot close it.
    function cssBackgroundImage(url) {
        var safe = String(url || '').replace(/[\u0000-\u001f\u007f"\\();]/g, '');
        return 'url("' + safe + '")';
    }

    function offerElement(description, type) {
        if (!isOfferType(type)) {
            return null;
        }
        var html = descriptionHtml(description);
        if (!html) {
            return null;
        }
        return createElement('span', {
            className: 'torobpay-offer',
            dangerouslySetInnerHTML: { __html: html }
        });
    }

    function useCheckoutCopy() {
        const live = useSelect(function(select) {
            const store = select(cartStoreKey);
            if (!store || typeof store.getCartData !== 'function') {
                return null;
            }
            const cart = store.getCartData();
            return cart && cart.extensions && cart.extensions.torobpay ? cart.extensions.torobpay : null;
        }, []);

        if (!live) {
            return {
                description: settings.description || '',
                type: normalizeDescriptionType(settings.descriptionType || '')
            };
        }

        const liveDescription = typeof live.description === 'string' ? live.description : '';
        const liveType = typeof live.description_type === 'string' ? live.description_type : '';
        const type = normalizeDescriptionType(liveType || settings.descriptionType || '');
        // An offer with no sentence stays blank. Installment still falls back to
        // the description from the first page render.
        const description = liveDescription || (isOfferType(type) ? '' : (settings.description || ''));

        return {
            description: description,
            type: type
        };
    }
    
    // Intercept Store API responses for automatic retry on CASE 3
    (function() {
        let hasRetried = false;
        const originalFetch = window.fetch;
        
        window.fetch = function(...args) {
            return originalFetch.apply(this, args).then(response => {
                // Check if this is a Store API checkout request with 409 error
                if (response.url && response.url.includes('/wc/store/') && response.status === 409) {
                    response.clone().json().then(data => {
                        if (hasRetried) return;
                        
                        // Check for TorobPay retry blocked error
                        if (data && data.code === 'torobpay_retry_blocked') {
                            hasRetried = true;
                            console.log('[TorobPay] Retry detected, automatically placing order...');
                            
                            // Wait for error to be displayed, then auto-click place order
                            setTimeout(() => {
                                const placeOrderButton = document.querySelector('.wc-block-components-checkout-place-order-button');
                                if (placeOrderButton) {
                                    placeOrderButton.click();
                                }
                            }, 800);
                        }
                    }).catch(() => {
                        // Failed to parse JSON, ignore
                    });
                }
                return response;
            });
        };
    })();
    
    // Content component - installment description, plus payment validation.
    // Discount copy is rendered on the label so it stays visible before selection.
    const Content = function(props) {
        const { eventRegistration, emitResponse, billing } = props;
        const { onPaymentProcessing } = eventRegistration;
        const copy = useCheckoutCopy();
        
        // Handle payment processing events
        useEffect(() => {
            const unsubscribe = onPaymentProcessing(async () => {
                const billingData = billing?.billingAddress || {};

                // Check postal code if required - also check for empty string
                if (settings.postal_enabled === 'yes' && (!billingData.postcode || billingData.postcode.trim() === '')) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: __( 'Postal code is required.', 'torobpay-for-woocommerce'),
                    };
                }
                
                // If validation passes, proceed with payment
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            torobpay_gateway: true
                        }
                    }
                };
            });
            
            // Cleanup subscription
            return () => unsubscribe();
        }, [
            onPaymentProcessing,
            emitResponse.responseTypes.ERROR,
            emitResponse.responseTypes.SUCCESS,
            billing,
        ]);
        
        if (isOfferType(copy.type)) {
            return createElement('span', {
                className: 'torobpay-offer-shown-on-label',
                hidden: true
            });
        }

        const description = descriptionHtml(copy.description) || descriptionHtml(__( 'TorobPay installment payment', 'torobpay-for-woocommerce' ));
        return createElement('div', {
            dangerouslySetInnerHTML: { __html: description }
        });
    };
    
    // Label component - displays payment method title, icon, and any discount highlight.
    const Label = function(props) {
        const { PaymentMethodLabel } = props.components;
        const copy = useCheckoutCopy();
        const title = decodeText(settings.title || '') || __( 'TorobPay installment payment', 'torobpay-for-woocommerce' );
        const offer = offerElement(copy.description, copy.type);
        const icon = settings.icon ? (offer ? createElement('span', {
            className: 'torobpay-checkout-logo torobpay-logo-elastic'
        }, createElement('span', {
            className: 'torobpay-logo-spin',
            role: 'img',
            'aria-label': 'TorobPay',
            style: { backgroundImage: cssBackgroundImage(settings.icon) }
        })) : createElement('img', {
            src: settings.icon,
            alt: 'TorobPay',
            style: {
                width: '40px',
                height: 'auto',
                marginLeft: '8px',
                verticalAlign: 'middle'
            }
        })) : null;

        return createElement(
            Fragment,
            null,
            createElement(PaymentMethodLabel, {
                text: title,
                icon: icon
            }),
            offer
        );
    };
    
    // Editor component - displays in the block editor
    const EditComponent = function() {
        const title = decodeText(settings.title || '') || __( 'TorobPay installment payment', 'torobpay-for-woocommerce' );
        const description = settings.description || __( 'Installments based on TorobPay credit', 'torobpay-for-woocommerce' );
        const type = normalizeDescriptionType(settings.descriptionType || '');
        const offer = offerElement(description, type);

        return createElement('div', 
            { 
                style: { 
                    padding: '20px',
                    border: '1px solid #ddd',
                    borderRadius: '4px',
                    backgroundColor: '#f9f9f9'
                } 
            },
            settings.icon && createElement('img', {
                src: settings.icon,
                alt: 'TorobPay',
                style: { 
                    maxHeight: '48px', 
                    marginBottom: '10px',
                    display: 'block'
                }
            }),
            createElement('h4', 
                { style: { margin: '0 0 10px 0' } }, 
                title
            ),
            offer || createElement('p', {
                style: { margin: 0 },
                dangerouslySetInnerHTML: { __html: descriptionHtml(description) }
            })
        );
    };
    
    // CanMakePayment callback - determines if payment method should be available
    const canMakePayment = function() {
        // You can add logic here to determine availability based on cart contents, customer location, etc.
        return true;
    };
    
    // Register the payment method
    const torobPayPaymentMethod = {
        name: 'WC_Gateway_TorobPay',
        label: createElement(Label),
        content: createElement(Content),
        edit: createElement(EditComponent),
        canMakePayment: canMakePayment,
        ariaLabel: settings.title || __( 'TorobPay installment payment', 'torobpay-for-woocommerce' ),
        supports: {
            // Disable saved cards functionality
            showSavedCards: false,
            showSaveOption: false,
            // Add support for features
            features: settings.supports?.features || ['products']
        },
        // Gateway ID for editor settings redirect
        gatewayId: settings.gatewayId || 'WC_Gateway_TorobPay',
        // Payment method ID for processing
        paymentMethodId: 'WC_Gateway_TorobPay'
    };
    
    // Register with WooCommerce Blocks
    registerPaymentMethod(torobPayPaymentMethod);
})();
