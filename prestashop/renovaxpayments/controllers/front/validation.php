<?php
/**
 * RENOVAX Payments — validation controller.
 *
 * Entry point when the customer clicks "Pay with RENOVAX" at checkout.
 * Creates an invoice via the merchant API and redirects the browser to
 * the RENOVAX hosted checkout (pay_url).
 *
 * No order is created at this stage — the order is materialised by
 * webhook.php when invoice.paid / invoice.overpaid arrives. This avoids
 * orphan unpaid orders polluting the order list.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class RenovaxPaymentsValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $cart = $this->context->cart;

        if (!Validate::isLoadedObject($cart)
            || $cart->id_customer == 0
            || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0
            || !$this->module->active
        ) {
            Tools::redirect('index.php?controller=order&step=1');
            return;
        }

        $authorized = false;
        foreach (Module::getPaymentModules() as $m) {
            if ($m['name'] === $this->module->name) {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            $this->errorAndBack('Payment method is not available.');
            return;
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
            return;
        }

        $currency  = new Currency((int) $cart->id_currency);
        $total     = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $totalStr  = number_format($total, 2, '.', '');
        $ttl       = (int) Configuration::get('RENOVAX_INVOICE_TTL_MIN');
        if ($ttl < 1 || $ttl > 1440) {
            $ttl = 15;
        }

        $successUrl = $this->context->link->getModuleLink('renovaxpayments', 'return',
            array('id_cart' => (int) $cart->id, 'key' => $cart->secure_key), true);
        $cancelUrl  = $this->context->link->getModuleLink('renovaxpayments', 'cancel',
            array('id_cart' => (int) $cart->id), true);

        $payload = array(
            'amount'             => $totalStr,
            'currency'           => $currency->iso_code,
            'description'        => sprintf('PrestaShop cart #%d', (int) $cart->id),
            'client_remote_id'   => (string) $cart->id,
            'success_url'        => $successUrl,
            'cancel_url'         => $cancelUrl,
            'expires_in_minutes' => $ttl,
            'metadata' => array(
                'ps_cart_id'     => (int) $cart->id,
                'ps_customer_id' => (int) $cart->id_customer,
                'ps_email'       => (string) $customer->email,
                'ps_secure_key'  => (string) $cart->secure_key,
                'ps_shop_url'    => Tools::getShopDomainSsl(true, true),
            ),
        );

        try {
            $client   = new RenovaxApiClient();
            $response = $client->createInvoice($payload);
        } catch (RenovaxApiException $e) {
            RenovaxLogger::error('createInvoice failed: ' . $e->renovaxCode . ' ' . $e->getMessage());
            $this->errorAndBack($e->getMessage());
            return;
        } catch (Exception $e) {
            RenovaxLogger::error('createInvoice unexpected: ' . $e->getMessage());
            $this->errorAndBack('Could not start the payment. Please try again or contact support.');
            return;
        }

        if (empty($response['pay_url']) || empty($response['id'])) {
            RenovaxLogger::error('createInvoice incomplete response: ' . json_encode($response));
            $this->errorAndBack('RENOVAX returned an incomplete response. Please try again.');
            return;
        }

        // Persist invoice_id ↔ cart so the webhook can recover the mapping
        // even if metadata is stripped/altered upstream.
        Configuration::updateValue('RENOVAX_CART_' . (int) $cart->id, (string) $response['id']);

        RenovaxLogger::info(sprintf(
            'invoice created: cart=%d invoice=%s amount=%s %s',
            (int) $cart->id, $response['id'], $totalStr, $currency->iso_code
        ));

        Tools::redirect($response['pay_url']);
    }

    private function errorAndBack($message)
    {
        $this->context->cookie->__set('renovax_error', (string) $message);
        $this->context->cookie->write();
        Tools::redirect('index.php?controller=order&step=3');
    }
}
