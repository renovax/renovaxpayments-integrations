<?php
/**
 * RENOVAX Payments — read-only status endpoint for the success page poll.
 * Returns: { status: pending|paid|overpaid|partial|expired|failed, balance_after: "X.XXX" }
 *
 * Looks up `pagos_renovax` by `iid` from the query string OR the session.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
rx_csrf_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$invoiceId = (string) ($_GET['iid'] ?? ($_SESSION['rx_last_invoice_id'] ?? ''));
$invoiceId = preg_replace('/[^A-Za-z0-9_-]/', '', $invoiceId) ?? '';

if ($invoiceId === '') {
    echo json_encode(['status' => 'unknown']);
    exit;
}

$stmt = rx_db()->prepare(
    'SELECT status, balance_despues FROM pagos_renovax WHERE invoice_id = :iid LIMIT 1'
);
$stmt->execute([':iid' => $invoiceId]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['status' => 'unknown']);
    exit;
}

echo json_encode([
    'status'        => $row['status'],
    'balance_after' => $row['balance_despues'] !== null ? (string) $row['balance_despues'] : null,
]);
