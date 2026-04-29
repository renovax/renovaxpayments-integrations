<?php
/**
 * RENOVAX Payments — WooCommerce Payment Gateway.
 *
 * Classic WC_Payment_Gateway implementation. The customer is redirected to the
 * RENOVAX hosted checkout (pay_url) where they choose Crypto / Stripe / PayPal.
 * Payment confirmation arrives via the signed webhook handled in
 * class-renovax-webhook.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Renovax extends WC_Payment_Gateway
{
    public $api_base_url;
    public $bearer_token;
    public $webhook_secret;
    public $invoice_ttl_minutes;
    public $debug_log;

    public function __construct()
    {
        $this->id                 = 'renovaxpayments';
        $this->method_title       = __('RENOVAX Payments', 'renovaxpayments');
        $this->method_description = __('Multi-platform payment gateway: Crypto (USDT, USDC, EURC, DAI on multiple chains), Stripe (cards), PayPal and more — single hosted checkout.', 'renovaxpayments');
        $this->icon               = apply_filters('renovaxpayments_icon_url', RENOVAXPAYMENTS_PLUGIN_URL . 'assets/icon.png');
        $this->has_fields         = false;
        $this->supports           = ['products', 'refunds'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title               = $this->get_option('title', __('RENOVAX Payments', 'renovaxpayments'));
        $this->description         = $this->get_option('description', __('Pay with crypto, card or PayPal via RENOVAX. You will be redirected to a secure checkout.', 'renovaxpayments'));
        $this->api_base_url        = $this->get_option('api_base_url', 'https://payments.renovax.net');
        $this->bearer_token        = $this->get_option('bearer_token', '');
        $this->webhook_secret      = $this->get_option('webhook_secret', '');
        $this->invoice_ttl_minutes = (int) $this->get_option('invoice_ttl_minutes', 15);
        $this->debug_log           = $this->get_option('debug_log', 'no') === 'yes';

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields()
    {
        $webhook_url = rest_url('renovax/v1/webhook');

        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable/Disable', 'renovaxpayments'),
                'type'    => 'checkbox',
                'label'   => __('Enable RENOVAX Payments', 'renovaxpayments'),
                'default' => 'no',
            ],
            'title' => [
                'title'       => __('Title', 'renovaxpayments'),
                'type'        => 'text',
                'description' => __('Title shown to the customer at checkout.', 'renovaxpayments'),
                'default'     => __('RENOVAX Payments', 'renovaxpayments'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __('Description', 'renovaxpayments'),
                'type'        => 'textarea',
                'description' => __('Description shown below the title at checkout.', 'renovaxpayments'),
                'default'     => __('Pay with crypto, card or PayPal via RENOVAX. You will be redirected to a secure checkout.', 'renovaxpayments'),
            ],
            'api_base_url' => [
                'title'       => __('API Base URL', 'renovaxpayments'),
                'type'        => 'text',
                'description' => __('Leave the default value in production.', 'renovaxpayments'),
                'default'     => 'https://payments.renovax.net',
                'desc_tip'    => true,
            ],
            'bearer_token' => [
                'title'       => __('Bearer Token', 'renovaxpayments'),
                'type'        => 'password',
                'description' => __('Merchant token in RENOVAX. Generate it at: Merchants → Edit → API Tokens → Create. Shown only once — copy and paste here without spaces.', 'renovaxpayments'),
            ],
            'webhook_secret' => [
                'title'       => __('Webhook Secret', 'renovaxpayments'),
                'type'        => 'password',
                'description' => __('Merchant HMAC secret (visible on the merchant edit page in RENOVAX). Used to validate the X-Renovax-Signature header on incoming webhooks.', 'renovaxpayments'),
            ],
            'webhook_url' => [
                'title'       => __('Webhook URL', 'renovaxpayments'),
                'type'        => 'title',
                /* translators: %s: webhook URL to register in RENOVAX */
                'description' => sprintf(
                    /* translators: %s: full webhook URL */
                    __('Register this URL as the merchant\'s webhook_url in RENOVAX:%s', 'renovaxpayments'),
                    '<br><code style="user-select:all">' . esc_html($webhook_url) . '</code>'
                ),
            ],
            'invoice_ttl_minutes' => [
                'title'             => __('Invoice TTL (minutes)', 'renovaxpayments'),
                'type'              => 'number',
                'description'       => __('How long the invoice stays valid before expiring. Recommended: 15.', 'renovaxpayments'),
                'default'           => 15,
                'custom_attributes' => ['min' => 1, 'max' => 1440, 'step' => 1],
                'desc_tip'          => true,
            ],
            'debug_log' => [
                'title'       => __('Debug log', 'renovaxpayments'),
                'type'        => 'checkbox',
                'label'       => __('Log API requests and webhook events to WooCommerce → Status → Logs (renovax-*).', 'renovaxpayments'),
                'default'     => 'no',
            ],
        ];
    }

    public function process_payment($order_id)
    {
        $order  = wc_get_order($order_id);
        $logger = $this->debug_log ? wc_get_logger() : null;
        $client = new Renovax_API_Client($this->api_base_url, $this->bearer_token, $logger);

        $payload = [
            'amount'             => (string) wc_format_decimal($order->get_total(), wc_get_price_decimals()),
            'currency'           => $order->get_currency(),
            'client_remote_id'   => (string) $order->get_id(),
            'success_url'        => $this->get_return_url($order),
            'cancel_url'         => $order->get_cancel_order_url_raw(),
            'expires_in_minutes' => max(1, min(1440, $this->invoice_ttl_minutes)),
            'metadata'           => [
                'wc_order_id'  => (string) $order->get_id(),
                'wc_order_key' => (string) $order->get_order_key(),
                'wc_email'     => (string) $order->get_billing_email(),
                'wc_site_url'  => home_url('/'),
            ],
        ];

        $response = $client->create_invoice($payload);

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            wc_add_notice($message, 'error');
            $order->add_order_note(sprintf(
                /* translators: %s: error message */
                __('RENOVAX invoice creation failed: %s', 'renovaxpayments'),
                $message
            ));
            return ['result' => 'failure'];
        }

        if (empty($response['pay_url']) || empty($response['id'])) {
            wc_add_notice(__('RENOVAX returned an incomplete response. Please try again.', 'renovaxpayments'), 'error');
            return ['result' => 'failure'];
        }

        $order->update_meta_data('_renovax_invoice_id', $response['id']);
        $order->update_meta_data('_renovax_pay_url', $response['pay_url']);
        $order->set_transaction_id($response['id']);
        $order->update_status('pending', sprintf(
            /* translators: %s: RENOVAX invoice UUID */
            __('Awaiting RENOVAX payment (invoice %s).', 'renovaxpayments'),
            $response['id']
        ));
        $order->save();

        return [
            'result'   => 'success',
            'redirect' => $response['pay_url'],
        ];
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('renovax_no_order', __('Order not found.', 'renovaxpayments'));
        }

        $invoice_id = $order->get_meta('_renovax_invoice_id');
        if (empty($invoice_id)) {
            return new WP_Error('renovax_no_invoice', __('No RENOVAX invoice associated with this order.', 'renovaxpayments'));
        }

        $logger   = $this->debug_log ? wc_get_logger() : null;
        $client   = new Renovax_API_Client($this->api_base_url, $this->bearer_token, $logger);
        $response = $client->refund_invoice($invoice_id, $amount, $reason);

        if (is_wp_error($response)) {
            return $response;
        }

        $order->add_order_note(sprintf(
            /* translators: 1: refunded amount, 2: invoice UUID */
            __('RENOVAX refund processed: %1$s (invoice %2$s).', 'renovaxpayments'),
            wc_price($amount, ['currency' => $order->get_currency()]),
            $invoice_id
        ));

        return true;
    }
}
