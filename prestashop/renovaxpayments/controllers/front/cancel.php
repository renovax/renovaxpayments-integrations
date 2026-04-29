<?php
/**
 * RENOVAX Payments — cancel controller.
 *
 * Landing when the customer cancels at the RENOVAX hosted checkout.
 * No order has been created yet (the webhook is the only path that
 * materialises orders) — we just redirect back to the payment step
 * with a friendly notice in the cookie.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class RenovaxPaymentsCancelModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $this->context->cookie->__set(
            'renovax_error',
            $this->module->trans(
                'Payment was cancelled. You can choose another payment method or retry.',
                array(),
                'Modules.Renovaxpayments.Shop'
            )
        );
        $this->context->cookie->write();
        Tools::redirect('index.php?controller=order&step=3');
    }
}
