/**
 * TorobPay payment method integration for WooCommerce Blocks
 * 
 * This file registers the TorobPay payment method with the WooCommerce Blocks system,
 * allowing it to be used in the block-based checkout experience.
 *
 * Note: settings.description (the gateway description) now comes from API response, with fallback to static text.
 */
(function() {
    // Exit early if WooCommerce Blocks is not available
    if (!window.wc || !window.wc.wcBlocksRegistry || !window.wc.wcSettings) {
        return;
    }

    // Get required dependencies
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { getSetting } = window.wc.wcSettings;
    const { createElement, useEffect } = window.wp.element;
    const { __ } = window.wp.i18n;
    const { decodeEntities } = window.wp.htmlEntities || {};
    
    // Get the payment settings
    const settings = getSetting('WC_Gateway_TorobPay_data', {});
    
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
    
    // Content component - displays payment method description
    const Content = function(props) {
        const { eventRegistration, emitResponse, billing } = props;
        const { onPaymentProcessing } = eventRegistration;
        
        // Handle payment processing events
        useEffect(() => {
            const unsubscribe = onPaymentProcessing(async () => {
                // Validate required fields if necessary
                // Access billing data directly from the current props/billing context
                const billingData = billing?.billingAddress || {};
                
                // Check mobile if required - also check for empty string
                if (settings.mobile_enabled === 'yes' && (!billingData.phone || billingData.phone.trim() === '')) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: __( 'Mobile number is required.', 'torobpay-for-woocommerce'),
                    };
                }
                
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
        
        return createElement('div', {
            dangerouslySetInnerHTML: { 
                __html: decodeEntities ? decodeEntities(settings.description || __( 'TorobPay installment payment', 'torobpay-for-woocommerce' )) : (settings.description || __( 'TorobPay installment payment', 'torobpay-for-woocommerce' ))
            }
        });
    };
    
    // Label component - displays payment method title and icon
    const Label = function(props) {
        const { PaymentMethodLabel } = props.components;
        
        return createElement(PaymentMethodLabel, {
            text: decodeEntities ? decodeEntities(settings.title || __( 'TorobPay installment payment', 'torobpay-for-woocommerce' )) : (settings.title || __( 'TorobPay installment payment', 'torobpay-for-woocommerce' )),
            icon: settings.icon ? createElement('img', {
                src: settings.icon,
                alt: 'TorobPay',
                style: { 
                    width: '40px',
                    height: 'auto',
                    marginLeft: '8px',
                    verticalAlign: 'middle'
                }
            }) : null
        });
    };
    
    // Editor component - displays in the block editor
    const EditComponent = function() {
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
                decodeEntities ? decodeEntities(settings.title || __( 'TorobPay installment payment', 'torobpay-for-woocommerce' )) : (settings.title || __( 'TorobPay installment payment', 'torobpay-for-woocommerce' ))
            ),
            createElement('p', 
                { style: { margin: 0 } }, 
                // settings.description now comes from API response, fallback to static text
                decodeEntities ? decodeEntities(settings.description || __( 'Installments based on TorobPay credit', 'torobpay-for-woocommerce' )) : (settings.description || __( 'Installments based on TorobPay credit', 'torobpay-for-woocommerce' ))
            )
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
