<?php
/**
 * RenovaxPayments — DHRU Fusion Webhook Receiver
 *
 * Server path:
 *   /renovaxpaymentscallback.php  (DHRU public root)
 *
 * Register this URL as the merchant's webhook_url in RenovaxPayments:
 *   webhook_url = https://your-dhru.com/renovaxpaymentscallback.php
 *
 * Responses:
 *   200 — event processed (or already processed idempotently / ignored).
 *   401 — invalid HMAC signature.
 *   4xx — invalid payload.
 *   5xx — internal error; Renovax retries with backoff (30s, 2min, 10min, 1h, 6h, 24h).
 *
 * Connection-drop resilience:
 *   After HMAC + payload validation the HTTP response is flushed and the
 *   connection to RENOVAX is closed. All DB operations run afterwards under
 *   ignore_user_abort(true) + a generous time limit, so they finish even if
 *   the remote side disconnects mid-flight.
 *
 * Crediting rule:
 *   total    = subtotal                                              (for the invoice to close naturally)
 *   tax      = (invoice.taxrate / 100) × amount_net_fiat
 *            + invoice.fixedcharge + renovax_fee                     (combined fee recorded)
 *   subtotal = amount_received_fiat − tax                            (net credited to the customer)
 *   tbl_invoiceitems.amount (AddFunds) = subtotal                    (so DHRU credits credit_left with subtotal)
 *   notes appended with gross/net/fees for audit.
 */

// Survive connection drops and give DB operations enough time to finish.
ignore_user_abort(true);
set_time_limit(120);

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

define('DEFINE_MY_ACCESS', true);
define('DEFINE_DHRU_FILE', true);
define('ROOTDIR', __DIR__);

include ROOTDIR . '/comm.php';
require ROOTDIR . '/includes/fun.inc.php';
include ROOTDIR . '/includes/gateway.fun.php';
include ROOTDIR . '/includes/invoice.fun.php';

// ---------------------------------------------------------------------------
// Phase 1 — Security + input validation (no DB writes yet).
//   Everything here runs before we flush the response.  If any check fails
//   we send the appropriate 4xx/5xx and stop — RENOVAX will retry on 5xx.
// ---------------------------------------------------------------------------

// 1a) Load gateway config (needed for the HMAC secret).
$GATEWAY = loadGatewayModule('renovaxpayments');
if (empty($GATEWAY) || (isset($GATEWAY['active']) && $GATEWAY['active'] != 1)) {
    rnx_exit(503, array('ok' => false, 'error' => 'gateway_not_active'));
}

$secret = trim($GATEWAY['webhook_secret'] ?? '');
if ($secret === '') {
    rnx_exit(500, array('ok' => false, 'error' => 'webhook_secret_not_configured'));
}

// 1b) Read raw body.
$payload = trim((string) file_get_contents('php://input'));
if ($payload === '') {
    rnx_exit(400, array('ok' => false, 'error' => 'empty_body'));
}

// 1c) Verify HMAC-SHA256 — must happen before any output.
$signatureHeader = $_SERVER['HTTP_X_RENOVAX_SIGNATURE'] ?? '';
$providedSig     = str_replace('sha256=', '', $signatureHeader);
$expectedSig     = hash_hmac('sha256', $payload, $secret);

if ($providedSig === '' || !hash_equals($expectedSig, $providedSig)) {
    rnx_exit(401, array('ok' => false, 'error' => 'invalid_signature'));
}

// 1d) Parse JSON.
$event = json_decode($payload, true);
if (!is_array($event)) {
    rnx_exit(400, array('ok' => false, 'error' => 'invalid_json'));
}

$eventType = $_SERVER['HTTP_X_RENOVAX_EVENT_TYPE'] ?? ($event['event_type'] ?? '');
$invoiceId = $event['invoice_id'] ?? '';
$status    = $event['status']     ?? '';

// 1e) Only credit confirmed, creditable events — everything else is a no-op 200.
$creditableEvents = array('invoice.paid', 'invoice.overpaid', 'invoice.partial');
if (!in_array($eventType, $creditableEvents, true) || $status !== 'confirmed') {
    rnx_exit(200, array('ok' => true, 'event' => $eventType, 'status' => $status, 'ignored' => true));
}

// 1f) Validate required fields before any DB access.
$amountGross = floatval($event['amount_received_fiat'] ?? ($event['amount_received'] ?? 0));
$amountNet   = floatval($event['amount_net_fiat']      ?? ($event['amount_net']      ?? 0));
$renovaxFee  = floatval($event['fee'] ?? 0);
$txHash      = $event['tx_hash'] ?? '';

$dhruInvoiceId = intval($event['metadata']['dhru_invoiceid'] ?? 0);
if ($dhruInvoiceId <= 0) {
    rnx_exit(400, array('ok' => false, 'error' => 'missing_dhru_invoiceid'));
}

if ($amountGross <= 0) {
    logtransaction('renovaxpayments', $event, 'zero_amount');
    rnx_exit(400, array('ok' => false, 'error' => 'zero_amount'));
}

// ---------------------------------------------------------------------------
// Phase 2 — Flush 200 OK and close the connection to RENOVAX.
//   From this point on the script runs in the background.  A dropped
//   connection will NOT interrupt it thanks to ignore_user_abort(true).
// ---------------------------------------------------------------------------
rnx_flush_and_close(200, array('ok' => true, 'queued' => true));

// ---------------------------------------------------------------------------
// Phase 3 — DB operations (idempotency, invoice rewrite, payment record).
//   These run after the response has been sent.
// ---------------------------------------------------------------------------

// 3a) Validate invoice belongs to this gateway.
if (!checkinvoiceid($dhruInvoiceId, 'renovaxpayments')) {
    logtransaction('renovaxpayments', $event, 'invalid_invoice_or_gateway');
    exit;
}

// 3b) Idempotency: skip if already paid.
$invoice = rnx_get_dhru_invoice($dhruInvoiceId);
if ($invoice && ($invoice['status'] ?? '') === 'Paid') {
    logtransaction('renovaxpayments', $event, 'already_paid');
    exit;
}

// 3c) Idempotency: skip if this transaction ID was already recorded.
if (rnx_txid_exists($txHash)) {
    logtransaction('renovaxpayments', $event, 'already_processed');
    exit;
}

logtransaction('renovaxpayments', $event, 'received');

// 3d) Rewrite invoice and record payment.
$taxRatePct  = floatval($invoice['taxrate']     ?? 0);
$fixedCharge = floatval($invoice['fixedcharge'] ?? 0);
$taxRate     = $taxRatePct / 100;

$newTax      = round(($taxRate * $amountNet) + $fixedCharge + $renovaxFee, 3);
$newSubtotal = round(max($amountGross - $newTax, 0), 3);
$newTotal    = $newSubtotal; // total = subtotal so the invoice closes naturally.

$sendEmail  = in_array($eventType, array('invoice.paid', 'invoice.overpaid'), true) ? 1 : 0;
$transidUse = $txHash !== '' ? $txHash : $invoiceId;
$baseDesc   = 'RenovaxPayments ' . $eventType;
$noteTx     = $txHash !== '' ? "tx:{$txHash}" : null;
$auditNote  = sprintf(
    'Renovax webhook rewrite: gross=%.3f net=%.3f renovax_fee=%.3f tax=%.3f subtotal=%.3f',
    $amountGross, $amountNet, $renovaxFee, $newTax, $newSubtotal
);

rnx_rewrite_invoice_from_net($dhruInvoiceId, $newSubtotal, $newTax, $newTotal, $auditNote);

addpayment(
    $dhruInvoiceId,
    $transidUse,
    $newSubtotal,
    $newTax,
    'renovaxpayments',
    $sendEmail,
    null,
    $baseDesc,
    null,
    $noteTx
);

// 3e) Force-close in case addpayment did not mark the invoice as Paid.
$invoiceAfter = rnx_get_dhru_invoice($dhruInvoiceId);
if (($invoiceAfter['status'] ?? '') !== 'Paid') {
    processinvoicetopaid($dhruInvoiceId, $sendEmail, null, null);
}

// 3f) Final audit log.
logtransaction('renovaxpayments', array_merge($event, array(
    '_rewrite' => array(
        'subtotal' => $newSubtotal,
        'tax'      => $newTax,
        'total'    => $newTotal,
    ),
)), 'credited');

// ===========================================================================
// Helpers
// ===========================================================================

/**
 * Send a JSON response, set headers, and exit immediately.
 * Used during Phase 1 (before the connection is closed).
 */
function rnx_exit($code, $data)
{
    http_response_code($code);
    header('Content-Type: application/json');
    exit(json_encode($data));
}

/**
 * Flush the HTTP response to the client and close the connection,
 * then return so Phase 3 can continue in the background.
 *
 * Strategy:
 *   1. fastcgi_finish_request() — available on PHP-FPM, cleanest option.
 *   2. Output-buffering + Content-Length + Connection:close — works on
 *      mod_php / CGI; tells the client the response is complete so it
 *      can close its end while the server keeps running.
 */
function rnx_flush_and_close($code, $data)
{
    $body = json_encode($data);

    http_response_code($code);
    header('Content-Type: application/json');
    header('Connection: close');
    header('Content-Length: ' . strlen($body));

    // Discard any previous output buffer layers before creating ours.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    ob_start();
    echo $body;
    ob_end_flush();

    if (function_exists('fastcgi_finish_request')) {
        // PHP-FPM: flush to client and detach from the request cycle.
        fastcgi_finish_request();
    } else {
        // mod_php / CGI fallback.
        flush();
    }
}

function rnx_get_dhru_invoice($order_id)
{
    $id     = intval($order_id);
    $result = dquery("SELECT * FROM `tbl_invoices` WHERE `id` = '{$id}' LIMIT 1");
    if (!$result) return false;
    return mysqli_fetch_assoc($result);
}

function rnx_txid_exists($transid)
{
    if ($transid === '' || $transid === null) return false;
    // Reject anything outside the strict tx-hash charset (alnum + - _ . :).
    // Prevents SQL injection since dquery() lacks prepared-statement support.
    if (!preg_match('/^[A-Za-z0-9_\-\.:]{1,128}$/', (string) $transid)) {
        return false;
    }
    $result = dquery("SELECT `id` FROM `tbl_transaction` WHERE `transid` = '{$transid}' LIMIT 1");
    if (!$result) return false;
    return mysqli_num_rows($result) > 0;
}

/**
 * Rewrites subtotal/tax/total on tbl_invoices and the AddFunds line item
 * amount on tbl_invoiceitems so DHRU credits the customer wallet with the
 * net amount after fees.
 */
function rnx_rewrite_invoice_from_net($invoiceid, $subtotal, $tax, $total, $note)
{
    $id    = intval($invoiceid);
    $sub   = number_format(floatval($subtotal), 3, '.', '');
    $tx    = number_format(floatval($tax),      3, '.', '');
    $tot   = number_format(floatval($total),    3, '.', '');
    // Strip quotes/backslashes/control chars from the audit note. dquery()
    // lacks prepared statements and addslashes() is unsafe under non-utf8
    // multi-byte charsets, so we sanitize to a SQL-safe printable subset.
    $nSafe = preg_replace('/[\x00-\x1F\x7F\'"\\\\]/', ' ', (string) $note);

    dquery(
        "UPDATE `tbl_invoices` "
        . "SET `subtotal` = {$sub}, "
        . "    `tax`      = {$tx}, "
        . "    `total`    = {$tot}, "
        . "    `notes`    = CONCAT(IFNULL(`notes`,''), '\\n', '{$nSafe}') "
        . "WHERE `id` = {$id} LIMIT 1"
    );

    dquery(
        "UPDATE `tbl_invoiceitems` "
        . "SET `amount` = {$sub} "
        . "WHERE `invoiceid` = {$id} AND `type` = 'AddFunds'"
    );
}
