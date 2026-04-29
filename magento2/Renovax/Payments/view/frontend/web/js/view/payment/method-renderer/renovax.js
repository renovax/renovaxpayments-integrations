define(
    [
        'Magento_Checkout/js/view/payment/default'
    ],
    function (Component) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Renovax_Payments/payment/renovax'
            },
            redirectAfterPlaceOrder: true,

            getPlaceOrderDeferredObject: function () {
                return this._super().done(function () {
                    window.location.replace(window.checkoutConfig.payment.renovax.redirect_url || '/renovax/redirect');
                });
            },

            getMethodTitle: function () {
                return window.checkoutConfig.payment.renovax?.title || 'RENOVAX Payments';
            },

            getMethodDescription: function () {
                return window.checkoutConfig.payment.renovax?.description || '';
            }
        });
    }
);
