<?php
/**
 * RENOVAX Payments — PrestaShop module.
 *
 * Multi-platform payment gateway: Crypto (USDT, USDC, EURC, DAI on BSC,
 * Ethereum, Polygon, Arbitrum, Base, Optimism, Avalanche, Tron, Solana...),
 * Stripe (cards), PayPal and more — all behind a single hosted checkout.
 *
 * Compatible with PrestaShop 1.7.x, 8.x and 9.x (PHP 7.2 → 8.3).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/RenovaxLogger.php';
require_once dirname(__FILE__) . '/classes/RenovaxApiClient.php';

class RenovaxPayments extends PaymentModule
{
    const CONFIG_API_BASE_URL    = 'RENOVAX_API_BASE_URL';
    const CONFIG_BEARER_TOKEN    = 'RENOVAX_BEARER_TOKEN';
    const CONFIG_WEBHOOK_SECRET  = 'RENOVAX_WEBHOOK_SECRET';
    const CONFIG_INVOICE_TTL_MIN = 'RENOVAX_INVOICE_TTL_MIN';
    const CONFIG_DEBUG_LOG       = 'RENOVAX_DEBUG_LOG';
    const CONFIG_OS_PARTIAL      = 'RENOVAX_OS_PARTIAL';

    public function __construct()
    {
        $this->name                   = 'renovaxpayments';
        $this->tab                    = 'payments_gateways';
        $this->version                = '1.0.0';
        $this->author                 = 'RENOVAX';
        $this->controllers            = array('validation', 'webhook', 'return', 'cancel');
        $this->currencies             = true;
        $this->currencies_mode        = 'checkbox';
        $this->bootstrap              = true;
        $this->need_instance          = 0;
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => '9.99.99');

        parent::__construct();

        $this->displayName = $this->trans('RENOVAX Payments', array(), 'Modules.Renovaxpayments.Admin');
        $this->description = $this->trans(
            'Multi-platform payment gateway: Crypto (USDT, USDC, EURC, DAI on multiple chains), Stripe (cards), PayPal and more — single hosted checkout.',
            array(),
            'Modules.Renovaxpayments.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Uninstall RENOVAX Payments? Configuration will be removed; the events table is preserved unless you tick "purge data".',
            array(),
            'Modules.Renovaxpayments.Admin'
        );
    }

    public function install()
    {
        if (!parent::install()
            || !$this->registerHook('paymentOptions')
            || !$this->registerHook('displayPaymentReturn')
            || !$this->registerHook('actionOrderSlipAdd')
        ) {
            return false;
        }

        if (!$this->installSql()) {
            return false;
        }

        $this->installPartialOrderState();

        Configuration::updateValue(self::CONFIG_API_BASE_URL,    'https://payments.renovax.net');
        Configuration::updateValue(self::CONFIG_BEARER_TOKEN,    '');
        Configuration::updateValue(self::CONFIG_WEBHOOK_SECRET,  '');
        Configuration::updateValue(self::CONFIG_INVOICE_TTL_MIN, 15);
        Configuration::updateValue(self::CONFIG_DEBUG_LOG,       0);

        return true;
    }

    public function uninstall()
    {
        $purge = (bool) Tools::getValue('renovax_purge_data', false);

        Configuration::deleteByName(self::CONFIG_API_BASE_URL);
        Configuration::deleteByName(self::CONFIG_BEARER_TOKEN);
        Configuration::deleteByName(self::CONFIG_WEBHOOK_SECRET);
        Configuration::deleteByName(self::CONFIG_INVOICE_TTL_MIN);
        Configuration::deleteByName(self::CONFIG_DEBUG_LOG);
        Configuration::deleteByName(self::CONFIG_OS_PARTIAL);

        if ($purge) {
            include dirname(__FILE__) . '/sql/uninstall.php';
        }

        return parent::uninstall();
    }

    private function installSql()
    {
        include dirname(__FILE__) . '/sql/install.php';
        return true;
    }

    private function installPartialOrderState()
    {
        if ((int) Configuration::get(self::CONFIG_OS_PARTIAL) > 0) {
            return;
        }

        $os                  = new OrderState();
        $os->name            = array();
        $os->color           = '#f1c40f';
        $os->paid            = 0;
        $os->logable         = 0;
        $os->delivery        = 0;
        $os->invoice         = 0;
        $os->shipped         = 0;
        $os->hidden          = 0;
        $os->module_name     = $this->name;
        $os->send_email      = 0;
        $os->template        = '';

        foreach (Language::getLanguages(false) as $lang) {
            $os->name[(int) $lang['id_lang']] = 'RENOVAX — Pago parcial';
        }

        if ($os->add()) {
            Configuration::updateValue(self::CONFIG_OS_PARTIAL, (int) $os->id);
        }
    }

    /* ---------------------------------------------------------------------
     * Admin configuration form
     * --------------------------------------------------------------------- */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit_' . $this->name)) {
            $apiBase = trim((string) Tools::getValue(self::CONFIG_API_BASE_URL));
            $token   = trim((string) Tools::getValue(self::CONFIG_BEARER_TOKEN));
            $secret  = trim((string) Tools::getValue(self::CONFIG_WEBHOOK_SECRET));
            $ttl     = (int) Tools::getValue(self::CONFIG_INVOICE_TTL_MIN);
            $debug   = (int) Tools::getValue(self::CONFIG_DEBUG_LOG);

            if (!Validate::isUrl($apiBase)) {
                $output .= $this->displayError($this->trans('API Base URL is not a valid URL.', array(), 'Modules.Renovaxpayments.Admin'));
            } elseif ($ttl < 1 || $ttl > 1440) {
                $output .= $this->displayError($this->trans('Invoice TTL must be between 1 and 1440 minutes.', array(), 'Modules.Renovaxpayments.Admin'));
            } else {
                Configuration::updateValue(self::CONFIG_API_BASE_URL,    $apiBase);
                Configuration::updateValue(self::CONFIG_BEARER_TOKEN,    $token);
                Configuration::updateValue(self::CONFIG_WEBHOOK_SECRET,  $secret);
                Configuration::updateValue(self::CONFIG_INVOICE_TTL_MIN, $ttl);
                Configuration::updateValue(self::CONFIG_DEBUG_LOG,       $debug ? 1 : 0);
                $output .= $this->displayConfirmation($this->trans('Settings updated.', array(), 'Modules.Renovaxpayments.Admin'));
            }
        }

        return $output . $this->renderForm();
    }

    private function renderForm()
    {
        $webhookUrl = $this->context->link->getModuleLink($this->name, 'webhook', array(), true);

        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('RENOVAX Payments — Settings', array(), 'Modules.Renovaxpayments.Admin'),
                    'icon'  => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type'     => 'text',
                        'label'    => $this->trans('API Base URL', array(), 'Modules.Renovaxpayments.Admin'),
                        'name'     => self::CONFIG_API_BASE_URL,
                        'required' => true,
                        'desc'     => $this->trans('Leave the default value in production.', array(), 'Modules.Renovaxpayments.Admin'),
                    ),
                    array(
                        'type'     => 'password',
                        'label'    => $this->trans('Bearer Token', array(), 'Modules.Renovaxpayments.Admin'),
                        'name'     => self::CONFIG_BEARER_TOKEN,
                        'required' => true,
                        'desc'     => $this->trans('Merchant token in RENOVAX. Generate it at: Merchants → Edit → API Tokens → Create. Shown only once — copy and paste here without spaces.', array(), 'Modules.Renovaxpayments.Admin'),
                    ),
                    array(
                        'type'     => 'password',
                        'label'    => $this->trans('Webhook Secret', array(), 'Modules.Renovaxpayments.Admin'),
                        'name'     => self::CONFIG_WEBHOOK_SECRET,
                        'required' => true,
                        'desc'     => $this->trans('Merchant HMAC secret (visible on the merchant edit page in RENOVAX). Validates the X-Renovax-Signature header on incoming webhooks.', array(), 'Modules.Renovaxpayments.Admin'),
                    ),
                    array(
                        'type'  => 'html',
                        'label' => $this->trans('Webhook URL', array(), 'Modules.Renovaxpayments.Admin'),
                        'name'  => 'RENOVAX_WEBHOOK_URL_DISPLAY',
                        'html_content' => '<p class="form-control-static">'
                            . $this->trans('Register this URL as the merchant\'s webhook_url in RENOVAX:', array(), 'Modules.Renovaxpayments.Admin')
                            . '<br><code style="user-select:all">' . htmlspecialchars($webhookUrl, ENT_QUOTES, 'UTF-8') . '</code></p>',
                    ),
                    array(
                        'type'     => 'text',
                        'label'    => $this->trans('Invoice TTL (minutes)', array(), 'Modules.Renovaxpayments.Admin'),
                        'name'     => self::CONFIG_INVOICE_TTL_MIN,
                        'required' => true,
                        'desc'     => $this->trans('How long the invoice stays valid before expiring (1–1440). Recommended: 15.', array(), 'Modules.Renovaxpayments.Admin'),
                    ),
                    array(
                        'type'    => 'switch',
                        'label'   => $this->trans('Debug log', array(), 'Modules.Renovaxpayments.Admin'),
                        'name'    => self::CONFIG_DEBUG_LOG,
                        'is_bool' => true,
                        'desc'    => $this->trans('Log API requests and webhook events with the [renovax] prefix.', array(), 'Modules.Renovaxpayments.Admin'),
                        'values'  => array(
                            array('id' => 'active_on',  'value' => 1, 'label' => $this->trans('Enabled',  array(), 'Modules.Renovaxpayments.Admin')),
                            array('id' => 'active_off', 'value' => 0, 'label' => $this->trans('Disabled', array(), 'Modules.Renovaxpayments.Admin')),
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save', array(), 'Modules.Renovaxpayments.Admin'),
                    'class' => 'btn btn-default pull-right',
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->module                    = $this;
        $helper->name_controller           = $this->name;
        $helper->token                     = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex              = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language     = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang  = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->title                     = $this->displayName;
        $helper->show_toolbar              = false;
        $helper->table                     = $this->table;
        $helper->submit_action             = 'submit_' . $this->name;
        $helper->fields_value = array(
            self::CONFIG_API_BASE_URL    => Configuration::get(self::CONFIG_API_BASE_URL, 'https://payments.renovax.net'),
            self::CONFIG_BEARER_TOKEN    => Configuration::get(self::CONFIG_BEARER_TOKEN, ''),
            self::CONFIG_WEBHOOK_SECRET  => Configuration::get(self::CONFIG_WEBHOOK_SECRET, ''),
            self::CONFIG_INVOICE_TTL_MIN => Configuration::get(self::CONFIG_INVOICE_TTL_MIN, 15),
            self::CONFIG_DEBUG_LOG       => Configuration::get(self::CONFIG_DEBUG_LOG, 0),
        );

        return $helper->generateForm(array($fields_form));
    }

    /* ---------------------------------------------------------------------
     * Hooks
     * --------------------------------------------------------------------- */
    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return array();
        }

        if (!$this->checkCurrency($params['cart'])) {
            return array();
        }

        $token  = (string) Configuration::get(self::CONFIG_BEARER_TOKEN);
        $secret = (string) Configuration::get(self::CONFIG_WEBHOOK_SECRET);
        if ($token === '' || $secret === '') {
            return array();
        }

        $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->trans('Pay with RENOVAX Payments', array(), 'Modules.Renovaxpayments.Shop'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true))
            ->setAdditionalInformation($this->trans(
                'Pay with crypto, card or PayPal via RENOVAX. You will be redirected to a secure checkout.',
                array(),
                'Modules.Renovaxpayments.Shop'
            ));

        $iconPath = _PS_MODULE_DIR_ . $this->name . '/views/img/icon.png';
        if (file_exists($iconPath)) {
            $option->setLogo(Media::getMediaPath($iconPath));
        }

        return array($option);
    }

    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active) {
            return '';
        }

        $this->context->smarty->assign(array(
            'shop_name' => $this->context->shop->name,
        ));

        return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
    }

    public function hookActionOrderSlipAdd($params)
    {
        if (empty($params['order'])) {
            return;
        }

        /** @var Order $order */
        $order = $params['order'];

        if ($order->module !== $this->name) {
            return;
        }

        $invoiceId = $this->getInvoiceIdFromOrder($order);
        if ($invoiceId === '') {
            RenovaxLogger::warning('refund skipped: no invoice id for order ' . (int) $order->id);
            return;
        }

        $amount = 0.0;
        if (Tools::getIsset('partialRefundProduct') && is_array(Tools::getValue('partialRefundProduct'))) {
            foreach ((array) Tools::getValue('partialRefundProduct') as $line) {
                $amount += (float) (is_array($line) ? array_sum($line) : $line);
            }
        }
        $partialShipping = (float) Tools::getValue('partialRefundShippingCost', 0);
        $amount += $partialShipping;
        if ($amount <= 0) {
            $amount = (float) $order->total_paid_real;
        }

        $reason = (string) Tools::getValue('cancelNote', '');

        try {
            $client = new RenovaxApiClient();
            $client->refundInvoice($invoiceId, $amount, $reason);

            $msg = new Message();
            $msg->message = sprintf(
                '[renovax] Refund posted: %s %s on invoice %s.',
                number_format($amount, 2, '.', ''),
                $order->getOrdersTotalPaid() ? Currency::getIsoCodeById((int) $order->id_currency) : '',
                $invoiceId
            );
            $msg->id_order = (int) $order->id;
            $msg->private  = 1;
            $msg->add();
        } catch (Exception $e) {
            RenovaxLogger::error('refund failed: ' . $e->getMessage(), (int) $order->id);
            $msg = new Message();
            $msg->message  = '[renovax] Refund failed: ' . Tools::substr($e->getMessage(), 0, 500);
            $msg->id_order = (int) $order->id;
            $msg->private  = 1;
            $msg->add();
        }
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * --------------------------------------------------------------------- */
    private function checkCurrency(Cart $cart)
    {
        $currency_order    = new Currency((int) $cart->id_currency);
        $currencies_module = $this->getCurrency((int) $cart->id_currency);
        if (!is_array($currencies_module)) {
            return false;
        }
        foreach ($currencies_module as $currency_module) {
            if ((int) $currency_order->id === (int) $currency_module['id_currency']) {
                return true;
            }
        }
        return false;
    }

    public function getInvoiceIdFromOrder(Order $order)
    {
        $payments = $order->getOrderPayments();
        if (!empty($payments)) {
            foreach ($payments as $p) {
                if (!empty($p->transaction_id)) {
                    return (string) $p->transaction_id;
                }
            }
        }
        $row = Db::getInstance()->getRow(
            'SELECT `invoice_id` FROM `' . _DB_PREFIX_ . 'renovax_events` '
            . 'WHERE `id_order` = ' . (int) $order->id . ' AND `invoice_id` <> "" '
            . 'ORDER BY `received_at` DESC LIMIT 1'
        );
        return $row && !empty($row['invoice_id']) ? (string) $row['invoice_id'] : '';
    }
}
