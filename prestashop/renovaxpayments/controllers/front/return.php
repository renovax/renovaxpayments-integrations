<?php
/**
 * RENOVAX Payments — return controller.
 *
 * Landing page after the customer completes (or attempts) the payment on
 * the RENOVAX hosted checkout. The order may not exist yet at this point —
 * it is created by webhook.php when the payment is confirmed on-chain or
 * by the upstream PSP. We render a friendly "awaiting confirmation" page;
 * if the webhook already arrived and the order is materialised, we
 * redirect to PrestaShop's standard order confirmation.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class RenovaxPaymentsReturnModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $idCart = (int) Tools::getValue('id_cart');
        $key    = (string) Tools::getValue('key');

        $idOrder = $idCart > 0 ? (int) Order::getIdByCartId($idCart) : 0;

        if ($idOrder > 0) {
            $order = new Order($idOrder);
            if (Validate::isLoadedObject($order) && $key !== '' && hash_equals((string) $order->secure_key, $key)) {
                Tools::redirect('index.php?controller=order-confirmation'
                    . '&id_cart=' . (int) $idCart
                    . '&id_module=' . (int) $this->module->id
                    . '&id_order=' . (int) $idOrder
                    . '&key=' . urlencode($key));
                return;
            }
        }

        $this->context->smarty->assign(array(
            'shop_name' => $this->context->shop->name,
            'id_cart'   => $idCart,
        ));

        $this->setTemplate('module:renovaxpayments/views/templates/front/return.tpl');
    }
}
