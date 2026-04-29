/**
 * Registers the RENOVAX renderer with the Magento 2 checkout payment list.
 */
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component, rendererList) {
        'use strict';

        rendererList.push({
            type:     'renovax',
            component: 'Renovax_Payments/js/view/payment/method-renderer/renovax'
        });

        return Component.extend({});
    }
);
