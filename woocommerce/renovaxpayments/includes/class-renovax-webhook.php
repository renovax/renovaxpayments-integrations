<?php
/**
 * RENOVAX Payments — Incoming webhook receiver.
 *
 * Endpoint: POST {site}/wp-json/renovax/v1/webhook
 * Header:   X-Renovax-Signature: sha256=<hmac_sha256(body, webhook_secret)>
 *           X-Renovax-Event-Id:  <uuid>            (idempotency key)
 *           X-Renovax-Event-Type: invoice.paid|invoice.overpaid|invoice.partial|invoice.expired
 */

if (!defined('ABSPATH')) {
    exit;
}

class Renovax_Webhook
{
    public static function register()
    {
        add_action('rest_api_init', [__CLASS__, 'register_route']);
    }

    public static function register_route()
    {
        register_rest_route('renovax/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle(WP_REST_Request $request)
    {
        $gateway = self::get_gateway();
        if (!$gateway || $gateway->enabled !== 'yes') {
            return new WP_REST_Response(['ok' => false, 'error' => 'gateway_disabled'], 503);
        }

        $secret = trim((string) $gateway->webhook_secret);
        if ($secret === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'webhook_secret_not_configured'], 500);
        }

        $body = $request->get_body();
        if ($body === '' || $body === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'empty_body'], 400);
        }

        $signature_header = (string) $request->get_header('x_renovax_signature');
        $provided_sig     = str_replace('sha256=', '', $signature_header);
        $expected_sig     = hash_hmac('sha256', $body, $secret);

        if ($provided_sig === '' || !hash_equals($expected_sig, $provided_sig)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_signature'], 401);
        }

        $event = json_decode($body, true);
        if (!is_array($event)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_json'], 400);
        }

        $event_id   = (string) $request->get_header('x_renovax_event_id');
        $event_type = (string) ($request->get_header('x_renovax_event_type') ?: ($event['event_type'] ?? ''));
        $invoice_id = (string) ($event['invoice_id'] ?? '');
        $status     = (string) ($event['status'] ?? '');

        if ($event_id !== '') {
            $idem_key = 'renovax_evt_' . md5($event_id);
            if (get_transient($idem_key)) {
                return new WP_REST_Response(['ok' => true, 'duplicate' => true], 200);
            }
            set_transient($idem_key, 1, DAY_IN_SECONDS);
        }

        $logger = $gateway->debug_log ? wc_get_logger() : null;
        if ($logger) {
            $logger->info('[renovax] webhook ' . $event_type . ' invoice=' . $invoice_id . ' status=' . $status, ['source' => 'renovaxpayments']);
        }

        $order = self::find_order($event, $invoice_id);
        if (!$order) {
            if ($logger) {
                $logger->warning('[renovax] order not found for invoice ' . $invoice_id, ['source' => 'renovaxpayments']);
            }
            return new WP_REST_Response(['ok' => false, 'error' => 'order_not_found'], 200);
        }

        $stored_invoice = (string) $order->get_meta('_renovax_invoice_id');
        if ($stored_invoice !== '' && $invoice_id !== '' && !hash_equals($stored_invoice, $invoice_id)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invoice_mismatch'], 400);
        }

        switch ($event_type) {
            case 'invoice.paid':
                self::mark_paid($order, $event, false);
                break;
            case 'invoice.overpaid':
                self::mark_paid($order, $event, true);
                break;
            case 'invoice.partial':
                self::mark_partial($order, $event);
                break;
            case 'invoice.expired':
                self::mark_expired($order, $event);
                break;
            default:
                return new WP_REST_Response(['ok' => true, 'ignored' => true, 'event' => $event_type], 200);
        }

        return new WP_REST_Response(['ok' => true, 'event' => $event_type, 'order_id' => $order->get_id()], 200);
    }

    private static function get_gateway()
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        return isset($gateways['renovaxpayments']) ? $gateways['renovaxpayments'] : null;
    }

    private static function find_order(array $event, $invoice_id)
    {
        $wc_order_id = (int) ($event['metadata']['wc_order_id'] ?? 0);
        if ($wc_order_id > 0) {
            $order = wc_get_order($wc_order_id);
            if ($order) {
                return $order;
            }
        }

        if ($invoice_id !== '') {
            $orders = wc_get_orders([
                'limit'      => 1,
                'meta_key'   => '_renovax_invoice_id',
                'meta_value' => $invoice_id,
            ]);
            if (!empty($orders)) {
                return $orders[0];
            }
        }

        return null;
    }

    private static function mark_paid(WC_Order $order, array $event, $overpaid)
    {
        if ($order->is_paid()) {
            $order->add_order_note(__('RENOVAX webhook received but order is already paid — no action.', 'renovaxpayments'));
            return;
        }

        $tx_hash      = (string) ($event['tx_hash'] ?? '');
        $gross        = (string) ($event['amount_received_fiat'] ?? $event['amount_received'] ?? '');
        $net          = (string) ($event['amount_net_fiat'] ?? $event['amount_net'] ?? '');
        $fee          = (string) ($event['fee'] ?? '');
        $currency     = (string) ($event['invoice_currency'] ?? $order->get_currency());

        if ($tx_hash !== '') {
            $order->update_meta_data('_renovax_tx_hash', $tx_hash);
        }
        if ($net !== '') {
            $order->update_meta_data('_renovax_amount_net_fiat', $net);
        }
        if ($fee !== '') {
            $order->update_meta_data('_renovax_fee_fiat', $fee);
        }

        $note = $overpaid
            /* translators: 1: gross amount, 2: currency, 3: net amount, 4: fee */
            ? sprintf(__('RENOVAX OVERPAID: gross %1$s %2$s, net %3$s, fee %4$s.', 'renovaxpayments'), $gross, $currency, $net, $fee)
            /* translators: 1: gross amount, 2: currency, 3: net amount, 4: fee */
            : sprintf(__('RENOVAX paid: gross %1$s %2$s, net %3$s, fee %4$s.', 'renovaxpayments'), $gross, $currency, $net, $fee);

        $order->add_order_note($note);
        $order->payment_complete($tx_hash);
        $order->save();
    }

    private static function mark_partial(WC_Order $order, array $event)
    {
        $gross = (string) ($event['amount_received_fiat'] ?? '');
        $order->update_status('on-hold', sprintf(
            /* translators: %s: amount received so far */
            __('RENOVAX partial payment received (%s). Manual review required.', 'renovaxpayments'),
            $gross
        ));
    }

    private static function mark_expired(WC_Order $order, array $event)
    {
        if ($order->is_paid()) {
            return;
        }
        $order->update_status('cancelled', __('RENOVAX invoice expired without payment.', 'renovaxpayments'));
    }
}
