<?php
/**
 * RENOVAX Payments — webhook receiver.
 *
 * Public URL example: https://example.com/renovax/webhook.php
 * Register this URL as the merchant's webhook_url in RENOVAX Payments.
 *
 * Responses:
 *   200 — event processed (or already processed idempotently / ignored).
 *   401 — invalid HMAC signature.
 *   4xx — invalid payload or business mismatch.
 *   5xx — internal error; RENOVAX Payments retries with backoff.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

ignore_user_abort(true);
set_time_limit(60);
header('Content-Type: application/json; charset=utf-8');

function rx_webhook_exit(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

// 1) Read raw body.
$payload = (string) file_get_contents('php://input');
if ($payload === '') {
    rx_webhook_exit(400, ['ok' => false, 'error' => 'empty_body']);
}

// 2) Verify HMAC-SHA256 against the configured webhook_secret.
$secret = trim((string) ($RX_CFG['renovax']['webhook_secret'] ?? ''));
if ($secret === '') {
    rx_log('error', 'webhook_secret not configured');
    rx_webhook_exit(500, ['ok' => false, 'error' => 'webhook_secret_not_configured']);
}

$signatureHeader = (string) ($_SERVER['HTTP_X_RENOVAX_SIGNATURE'] ?? '');
$providedSig     = str_replace('sha256=', '', $signatureHeader);
$expectedSig     = hash_hmac('sha256', $payload, $secret);

if ($providedSig === '' || !hash_equals($expectedSig, $providedSig)) {
    rx_log('warning', 'invalid signature', ['ip' => rx_client_ip()]);
    rx_webhook_exit(401, ['ok' => false, 'error' => 'invalid_signature']);
}

// 3) Parse JSON.
$event = json_decode($payload, true);
if (!is_array($event)) {
    rx_webhook_exit(400, ['ok' => false, 'error' => 'invalid_json']);
}

$eventId   = (string) ($_SERVER['HTTP_X_RENOVAX_EVENT_ID']   ?? ($event['event_id']   ?? ''));
$eventType = (string) ($_SERVER['HTTP_X_RENOVAX_EVENT_TYPE'] ?? ($event['event_type'] ?? ''));
$invoiceId = (string) ($event['invoice_id'] ?? '');
$status    = (string) ($event['status'] ?? '');

if ($invoiceId === '') {
    rx_webhook_exit(400, ['ok' => false, 'error' => 'missing_invoice_id']);
}

// 4) Cross-check: the invoice must exist in pagos_renovax (we created it via create.php).
$pdo = rx_db();
$rowStmt = $pdo->prepare(
    'SELECT * FROM pagos_renovax WHERE invoice_id = :iid LIMIT 1'
);
$rowStmt->execute([':iid' => $invoiceId]);
$row = $rowStmt->fetch();
if (!$row) {
    // 404 (not 200) so RENOVAX retries: the invoice row may not yet be
    // persisted when create.php and the webhook race on first delivery.
    rx_log('warning', 'invoice not in pagos_renovax', ['invoice_id' => $invoiceId]);
    rx_webhook_exit(404, ['ok' => false, 'error' => 'unknown_invoice']);
}

// 5) Cross-check: webx_user_id from metadata must match what we stored.
$metaUserId = (int) ($event['metadata']['webx_user_id'] ?? 0);
if ($metaUserId !== (int) $row['webx_user_id']) {
    rx_log('alert', 'user mismatch on webhook', [
        'invoice_id' => $invoiceId,
        'stored'     => $row['webx_user_id'],
        'received'   => $metaUserId,
    ]);
    rx_telegram_notify("⚠️ <b>RENOVAX Payments</b>: webhook user mismatch on invoice <code>{$invoiceId}</code>.");
    rx_webhook_exit(400, ['ok' => false, 'error' => 'user_mismatch']);
}

// 6) Idempotency: if event_id already stored, skip.
if ($eventId !== '' && $row['event_id'] === $eventId) {
    rx_webhook_exit(200, ['ok' => true, 'duplicate' => true]);
}

// 7) Only confirmed creditable events update balance.
$creditable = ['invoice.paid', 'invoice.overpaid', 'invoice.partial'];
if (!in_array($eventType, $creditable, true) || $status !== 'confirmed') {
    if ($eventType === 'invoice.expired') {
        $upd = $pdo->prepare('UPDATE pagos_renovax SET status = "expired", event_id = :eid WHERE id = :id');
        $upd->execute([':eid' => $eventId ?: null, ':id' => $row['id']]);
        rx_webhook_exit(200, ['ok' => true, 'event' => $eventType]);
    }
    rx_webhook_exit(200, ['ok' => true, 'ignored' => $eventType, 'status' => $status]);
}

// 8) Compute crediting amount + status mapping with ±tolerance check.
$grossFiat   = (float) ($event['amount_received_fiat'] ?? $event['amount_received'] ?? 0);
$netFiat     = (float) ($event['amount_net_fiat']      ?? $event['amount_net']      ?? $grossFiat);
$txHash      = (string) ($event['tx_hash'] ?? '');
$requested   = (float) $row['amount_request'];
$tolerance   = (float) ($RX_CFG['renovax']['amount_tolerance'] ?? 0.05);
$lowerBound  = $requested * (1 - $tolerance);
$upperBound  = $requested * (1 + $tolerance);

$finalStatus = 'paid';
if ($eventType === 'invoice.partial' || $netFiat < $lowerBound) {
    $finalStatus = 'partial';
} elseif ($eventType === 'invoice.overpaid' || $netFiat > $upperBound) {
    $finalStatus = 'overpaid';
}

// 9) Atomic transaction: credit balance + update audit row.
//    Only credit when status is 'paid' or 'overpaid' — partial pays go to manual review.
try {
    $pdo->beginTransaction();

    $balanceBefore = (float) $row['balance_antes'];
    $balanceAfter  = $balanceBefore;

    if ($finalStatus === 'paid' || $finalStatus === 'overpaid') {
        $upd = $pdo->prepare('UPDATE users SET balance = balance + :amt WHERE id = :uid');
        $upd->bindValue(':amt', number_format($netFiat, 3, '.', ''), PDO::PARAM_STR);
        $upd->bindValue(':uid', (int) $row['webx_user_id'], PDO::PARAM_INT);
        $upd->execute();

        $balStmt = $pdo->prepare('SELECT balance FROM users WHERE id = :uid');
        $balStmt->execute([':uid' => (int) $row['webx_user_id']]);
        $balanceAfter = (float) $balStmt->fetchColumn();
    }

    $upd2 = $pdo->prepare(
        'UPDATE pagos_renovax SET
            event_id        = :eid,
            amount_received = :rec,
            balance_despues = :bal,
            tx_hash         = :tx,
            status          = :status
         WHERE id = :id'
    );
    $upd2->execute([
        ':eid'    => $eventId ?: null,
        ':rec'    => number_format($grossFiat, 2, '.', ''),
        ':bal'    => number_format($balanceAfter, 3, '.', ''),
        ':tx'     => $txHash !== '' ? $txHash : null,
        ':status' => $finalStatus,
        ':id'     => $row['id'],
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    rx_log('error', 'transaction failed', ['msg' => $e->getMessage(), 'invoice_id' => $invoiceId]);
    rx_webhook_exit(500, ['ok' => false, 'error' => 'db_transaction_failed']);
}

// 10) Optional Telegram notification.
if ($finalStatus === 'paid' || $finalStatus === 'overpaid') {
    $emoji = $finalStatus === 'overpaid' ? '⚠️' : '✅';
    rx_telegram_notify(sprintf(
        "%s <b>RENOVAX Payments</b> — recarga %s\n"
        . "<b>Usuario:</b> %s (id %d)\n"
        . "<b>Bruto:</b> %s %s\n"
        . "<b>Neto:</b> %s\n"
        . "<b>Antes/Después:</b> %s → %s\n"
        . "<b>TX:</b> <code>%s</code>",
        $emoji,
        strtoupper($finalStatus),
        htmlspecialchars($row['username']),
        (int) $row['webx_user_id'],
        number_format($grossFiat, 2),
        $row['currency'],
        number_format($netFiat, 2),
        number_format($balanceBefore, 3),
        number_format($balanceAfter, 3),
        $txHash !== '' ? $txHash : 'n/a'
    ));
} elseif ($finalStatus === 'partial') {
    rx_telegram_notify(sprintf(
        "🟡 <b>RENOVAX Payments</b> — pago PARCIAL en invoice <code>%s</code>. Revisión manual.",
        $invoiceId
    ));
}

rx_webhook_exit(200, [
    'ok'         => true,
    'event'      => $eventType,
    'status'     => $finalStatus,
    'invoice_id' => $invoiceId,
]);
