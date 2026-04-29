<?php
/**
 * RENOVAX Payments — WHMCS Webhook Receiver.
 *
 * Server path:
 *   /modules/gateways/callback/renovaxpayments.php
 *
 * Register this URL as the merchant's webhook_url in RENOVAX:
 *   https://YOUR-WHMCS/modules/gateways/callback/renovaxpayments.php
 *
 * Responses:
 *   200 — event processed (or already processed idempotently / ignored).
 *   401 — invalid HMAC signature.
 *   4xx — invalid payload.
 *   5xx — internal error; RENOVAX retries with backoff.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'renovaxpayments';
$gatewayParams     = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'gateway_not_active']);
    die();
}

$secret = trim($gatewayParams['webhook_secret'] ?? '');
if ($secret === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'webhook_secret_not_configured']);
    die();
}

// 1) Read raw body.
$payload = trim((string) file_get_contents('php://input'));
if ($payload === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty_body']);
    die();
}

// 2) Verify HMAC-SHA256 — must happen before any processing.
$signatureHeader = $_SERVER['HTTP_X_RENOVAX_SIGNATURE'] ?? '';
$providedSig     = str_replace('sha256=', '', $signatureHeader);
$expectedSig     = hash_hmac('sha256', $payload, $secret);

if ($providedSig === '' || !hash_equals($expectedSig, $providedSig)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_signature']);
    die();
}

// 3) Parse JSON.
$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    die();
}

$eventId   = $_SERVER['HTTP_X_RENOVAX_EVENT_ID']   ?? ($event['event_id'] ?? '');
$eventType = $_SERVER['HTTP_X_RENOVAX_EVENT_TYPE'] ?? ($event['event_type'] ?? '');
$status    = $event['status']     ?? '';
$invoiceId = (int) ($event['metadata']['whmcs_invoiceid'] ?? 0);

if ($invoiceId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_whmcs_invoiceid']);
    die();
}

// 4) Validate the WHMCS invoice exists. checkCbInvoiceID() dies with a logged
//    error if not found.
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);

// 5) Idempotency. checkCbTransID() dies silently if the transid was already
//    recorded — RENOVAX can safely re-deliver the same event.
if ($eventId !== '') {
    checkCbTransID($eventId);
}

// 6) Map event_type → action.
$debug      = !empty($gatewayParams['debug']);
$amountFiat = (float) ($event['amount_received_fiat'] ?? $event['amount_received'] ?? 0);
$feeFiat    = (float) ($event['fee'] ?? 0);
$txHash     = (string) ($event['tx_hash'] ?? '');

switch ($eventType) {
    case 'invoice.paid':
    case 'invoice.overpaid':
        if ($status !== 'confirmed' || $amountFiat <= 0) {
            logTransaction($gatewayModuleName, $event, 'Skipped (not confirmed)');
            echo json_encode(['ok' => true, 'skipped' => true]);
            die();
        }

        addInvoicePayment(
            $invoiceId,
            $eventId ?: ($event['invoice_id'] ?? ''),
            $amountFiat,
            $feeFiat,
            $gatewayModuleName
        );

        $note = ($eventType === 'invoice.overpaid')
            ? sprintf('RENOVAX OVERPAID: gross %s, net %s, fee %s, tx %s',
                $amountFiat, $event['amount_net_fiat'] ?? '', $feeFiat, $txHash ?: 'n/a')
            : sprintf('RENOVAX paid: gross %s, net %s, fee %s, tx %s',
                $amountFiat, $event['amount_net_fiat'] ?? '', $feeFiat, $txHash ?: 'n/a');
        logTransaction($gatewayModuleName, $event + ['note' => $note], 'Successful');
        break;

    case 'invoice.partial':
        logTransaction($gatewayModuleName, $event, 'Partial — manual review');
        break;

    case 'invoice.expired':
        logTransaction($gatewayModuleName, $event, 'Expired');
        break;

    default:
        if ($debug) {
            logTransaction($gatewayModuleName, $event, 'Unhandled: ' . $eventType);
        }
        echo json_encode(['ok' => true, 'ignored' => $eventType]);
        die();
}

http_response_code(200);
echo json_encode(['ok' => true, 'event' => $eventType, 'invoiceid' => $invoiceId]);
