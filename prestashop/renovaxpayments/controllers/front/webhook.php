<?php
/**
 * RENOVAX Payments — Webhook receiver.
 *
 * Endpoint: POST {shop}/index.php?fc=module&module=renovaxpayments&controller=webhook
 *
 * Headers:
 *   X-Renovax-Signature: sha256=<hmac_sha256(body, webhook_secret)>
 *   X-Renovax-Event-Id:  <uuid>            (idempotency key)
 *   X-Renovax-Event-Type: invoice.paid|invoice.overpaid|invoice.partial|invoice.expired
 *
 * Connection-drop resilience (mirrors the DHRU Fusion callback):
 *   After HMAC + payload validation the HTTP response is flushed and the
 *   connection to RENOVAX is closed. All DB operations run afterwards under
 *   ignore_user_abort(true) + a generous time limit, so they finish even if
 *   the remote side disconnects mid-flight.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class RenovaxPaymentsWebhookModuleFrontController extends ModuleFrontController
{
    public $ssl           = true;
    public $auth          = false;
    public $guestAllowed  = true;
    public $ajax          = true;

    public function init()
    {
        // Skip maintenance / SSL redirect / customer init — this is a server-to-server endpoint.
        if (Tools::getValue('controller') === null) {
            $_GET['controller'] = 'webhook';
        }
        parent::init();
    }

    public function postProcess()
    {
        @ignore_user_abort(true);
        @set_time_limit(120);

        // -------------------------------------------------------------------
        // Phase 1 — Security + input validation (no DB writes yet).
        // -------------------------------------------------------------------
        $secret = trim((string) Configuration::get('RENOVAX_WEBHOOK_SECRET'));
        if ($secret === '') {
            $this->rnxExit(500, array('ok' => false, 'error' => 'webhook_secret_not_configured'));
        }

        $body = trim((string) file_get_contents('php://input'));
        if ($body === '') {
            $this->rnxExit(400, array('ok' => false, 'error' => 'empty_body'));
        }

        $signatureHeader = isset($_SERVER['HTTP_X_RENOVAX_SIGNATURE']) ? (string) $_SERVER['HTTP_X_RENOVAX_SIGNATURE'] : '';
        $providedSig     = str_replace('sha256=', '', $signatureHeader);
        $expectedSig     = hash_hmac('sha256', $body, $secret);

        if ($providedSig === '' || !hash_equals($expectedSig, $providedSig)) {
            $this->rnxExit(401, array('ok' => false, 'error' => 'invalid_signature'));
        }

        $event = json_decode($body, true);
        if (!is_array($event)) {
            $this->rnxExit(400, array('ok' => false, 'error' => 'invalid_json'));
        }

        $eventId   = isset($_SERVER['HTTP_X_RENOVAX_EVENT_ID'])   ? (string) $_SERVER['HTTP_X_RENOVAX_EVENT_ID']   : '';
        $eventType = isset($_SERVER['HTTP_X_RENOVAX_EVENT_TYPE']) ? (string) $_SERVER['HTTP_X_RENOVAX_EVENT_TYPE'] : (isset($event['event_type']) ? (string) $event['event_type'] : '');
        $invoiceId = isset($event['invoice_id']) ? (string) $event['invoice_id'] : '';

        if ($eventId === '') {
            $eventId = 'sha-' . hash('sha256', $body);
            RenovaxLogger::warning('missing X-Renovax-Event-Id header; using payload hash');
        }

        // -------------------------------------------------------------------
        // Phase 2 — Flush 200 OK and close the connection.
        // -------------------------------------------------------------------
        $this->rnxFlushAndClose(200, array('ok' => true, 'queued' => true));

        // -------------------------------------------------------------------
        // Phase 3 — DB operations run after the response has been sent.
        // -------------------------------------------------------------------
        try {
            $this->processEvent($event, $eventId, $eventType, $invoiceId, $body);
        } catch (Exception $e) {
            RenovaxLogger::error('processEvent threw: ' . $e->getMessage());
        }
    }

    private function processEvent(array $event, $eventId, $eventType, $invoiceId, $rawBody)
    {
        // Idempotency: insert event_id; duplicate → no-op.
        $payloadHash = hash('sha256', $rawBody);
        $idCart      = (int) (isset($event['metadata']['ps_cart_id']) ? $event['metadata']['ps_cart_id'] : 0);

        $inserted = Db::getInstance()->insert('renovax_events', array(
            'event_id'     => pSQL($eventId),
            'event_type'   => pSQL($eventType),
            'invoice_id'   => pSQL($invoiceId),
            'id_cart'      => $idCart > 0 ? $idCart : null,
            'payload_hash' => pSQL($payloadHash),
            'received_at'  => date('Y-m-d H:i:s'),
        ), false, true, Db::INSERT_IGNORE);

        if (!$inserted || (int) Db::getInstance()->Affected_Rows() === 0) {
            RenovaxLogger::info('duplicate event ignored: ' . $eventId);
            return;
        }

        RenovaxLogger::info(sprintf(
            'webhook %s invoice=%s cart=%d',
            $eventType, $invoiceId, $idCart
        ));

        $creditable = array('invoice.paid', 'invoice.overpaid', 'invoice.partial');
        $status     = isset($event['status']) ? (string) $event['status'] : '';

        $idOrder = $this->resolveOrder($idCart, $invoiceId);

        switch ($eventType) {
            case 'invoice.paid':
            case 'invoice.overpaid':
                if ($status !== 'confirmed') {
                    RenovaxLogger::warning('paid event with non-confirmed status: ' . $status);
                    return;
                }
                $this->handlePaid($event, $invoiceId, $idCart, $idOrder, $eventType === 'invoice.overpaid');
                break;

            case 'invoice.partial':
                if ($idOrder > 0) {
                    $this->changeState($idOrder, $this->partialOrderStateId(), $event);
                }
                break;

            case 'invoice.expired':
                if ($idOrder > 0) {
                    $order = new Order($idOrder);
                    if (Validate::isLoadedObject($order) && !(bool) $order->hasBeenPaid()) {
                        $this->changeState($idOrder, (int) Configuration::get('PS_OS_CANCELED'), $event);
                    }
                }
                break;

            default:
                RenovaxLogger::info('event ignored: ' . $eventType);
                return;
        }

        // Audit row update
        Db::getInstance()->update('renovax_events', array(
            'id_order' => $idOrder > 0 ? (int) $idOrder : null,
            'id_cart'  => $idCart  > 0 ? (int) $idCart  : null,
        ), '`event_id` = "' . pSQL($eventId) . '"');

        if (in_array($eventType, $creditable, true)) {
            Configuration::deleteByName('RENOVAX_CART_' . (int) $idCart);
        }
    }

    private function resolveOrder($idCart, $invoiceId)
    {
        if ($idCart > 0) {
            $idOrder = (int) Order::getIdByCartId($idCart);
            if ($idOrder > 0) {
                return $idOrder;
            }
        }
        if ($invoiceId !== '') {
            $row = Db::getInstance()->getRow(
                'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'order_payment` op '
                . 'JOIN `' . _DB_PREFIX_ . 'order_invoice_payment` oip ON oip.`id_order_payment` = op.`id_order_payment` '
                . 'WHERE op.`transaction_id` = "' . pSQL($invoiceId) . '" LIMIT 1'
            );
            if ($row && !empty($row['id_order'])) {
                return (int) $row['id_order'];
            }
        }
        return 0;
    }

    private function handlePaid(array $event, $invoiceId, $idCart, $idOrder, $overpaid)
    {
        $gross    = (float) (isset($event['amount_received_fiat']) ? $event['amount_received_fiat'] : (isset($event['amount_received']) ? $event['amount_received'] : 0));
        $net      = (float) (isset($event['amount_net_fiat'])      ? $event['amount_net_fiat']      : (isset($event['amount_net'])      ? $event['amount_net']      : 0));
        $fee      = (float) (isset($event['fee'])                  ? $event['fee']                  : 0);
        $txHash   = isset($event['tx_hash']) ? (string) $event['tx_hash'] : '';
        $currency = isset($event['invoice_currency']) ? (string) $event['invoice_currency'] : '';

        $note = sprintf(
            ($overpaid ? 'RENOVAX OVERPAID: gross=%.2f %s net=%.2f fee=%.2f tx=%s' : 'RENOVAX paid: gross=%.2f %s net=%.2f fee=%.2f tx=%s'),
            $gross, $currency, $net, $fee, $txHash
        );

        if ($idOrder > 0) {
            $order = new Order($idOrder);
            if (!Validate::isLoadedObject($order)) {
                RenovaxLogger::error('order load failed: id=' . $idOrder);
                return;
            }
            if ((bool) $order->hasBeenPaid()) {
                $this->addPrivateMessage($idOrder, '[renovax] webhook received but order already paid.');
                return;
            }
            // Mismatch guard
            $stored = $this->getStoredTransactionId($idOrder);
            if ($stored !== '' && $invoiceId !== '' && !hash_equals($stored, $invoiceId)) {
                RenovaxLogger::error('invoice mismatch order=' . $idOrder . ' stored=' . $stored . ' incoming=' . $invoiceId);
                return;
            }
            $this->changeState($idOrder, (int) Configuration::get('PS_OS_PAYMENT'), $event);
            $this->addPrivateMessage($idOrder, $note);
            return;
        }

        // No order yet → create it via validateOrder.
        if ($idCart <= 0) {
            RenovaxLogger::error('cannot create order: missing ps_cart_id metadata');
            return;
        }

        $cart = new Cart((int) $idCart);
        if (!Validate::isLoadedObject($cart)) {
            RenovaxLogger::error('cart load failed: id=' . $idCart);
            return;
        }

        $module    = Module::getInstanceByName('renovaxpayments');
        $extra     = array('transaction_id' => (string) $invoiceId);
        $secureKey = (string) $cart->secure_key;
        $amount    = (float) $cart->getOrderTotal(true, Cart::BOTH);

        try {
            $module->validateOrder(
                (int) $cart->id,
                (int) Configuration::get('PS_OS_PAYMENT'),
                $amount,
                'RENOVAX Payments',
                $note,
                $extra,
                null,
                false,
                $secureKey
            );
        } catch (Exception $e) {
            RenovaxLogger::error('validateOrder failed: ' . $e->getMessage());
            return;
        }

        $idOrderNew = (int) Order::getIdByCartId((int) $cart->id);
        if ($idOrderNew > 0) {
            $this->setOrderPaymentTransactionId($idOrderNew, $invoiceId);
            RenovaxLogger::info('order created: id=' . $idOrderNew . ' invoice=' . $invoiceId);
        }
    }

    private function partialOrderStateId()
    {
        $id = (int) Configuration::get('RENOVAX_OS_PARTIAL');
        if ($id > 0) {
            return $id;
        }
        return (int) Configuration::get('PS_OS_OUTOFSTOCK_UNPAID');
    }

    private function changeState($idOrder, $idOrderState, array $event)
    {
        if ($idOrderState <= 0) {
            return;
        }
        $history = new OrderHistory();
        $history->id_order = (int) $idOrder;
        $history->changeIdOrderState((int) $idOrderState, (int) $idOrder);
        $history->addWithemail();
    }

    private function getStoredTransactionId($idOrder)
    {
        $row = Db::getInstance()->getRow(
            'SELECT `transaction_id` FROM `' . _DB_PREFIX_ . 'order_payment` op '
            . 'JOIN `' . _DB_PREFIX_ . 'order_invoice_payment` oip ON oip.`id_order_payment` = op.`id_order_payment` '
            . 'WHERE oip.`id_order` = ' . (int) $idOrder . ' AND op.`transaction_id` <> "" LIMIT 1'
        );
        return $row && !empty($row['transaction_id']) ? (string) $row['transaction_id'] : '';
    }

    private function setOrderPaymentTransactionId($idOrder, $invoiceId)
    {
        if ($invoiceId === '') {
            return;
        }
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'order_payment` op '
            . 'JOIN `' . _DB_PREFIX_ . 'order_invoice_payment` oip ON oip.`id_order_payment` = op.`id_order_payment` '
            . 'SET op.`transaction_id` = "' . pSQL($invoiceId) . '" '
            . 'WHERE oip.`id_order` = ' . (int) $idOrder
        );
    }

    private function addPrivateMessage($idOrder, $text)
    {
        $msg           = new Message();
        $msg->message  = '[renovax] ' . Tools::substr((string) $text, 0, 1500);
        $msg->id_order = (int) $idOrder;
        $msg->private  = 1;
        $msg->add();
    }

    /* ---------------------------------------------------------------------
     * HTTP helpers — flush-and-close pattern (port of DHRU rnx_flush_and_close)
     * --------------------------------------------------------------------- */
    private function rnxExit($code, array $data)
    {
        http_response_code((int) $code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    private function rnxFlushAndClose($code, array $data)
    {
        $body = json_encode($data);

        http_response_code((int) $code);
        header('Content-Type: application/json');
        header('Connection: close');
        header('Content-Length: ' . strlen($body));

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        ob_start();
        echo $body;
        ob_end_flush();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            flush();
        }
    }
}
