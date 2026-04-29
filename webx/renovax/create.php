<?php
require_once __DIR__ . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php', true, 303);
    exit;
}

$flash = function (string $code) {
    header('Location: index.php?err=' . urlencode($code), true, 303);
    exit;
};

$user      = trim((string) ($_POST['user'] ?? ''));
$amountIn  = trim((string) ($_POST['amount'] ?? ''));
$csrf      = (string) ($_POST['csrf'] ?? '');
$cfg       = $RX_CFG;
$currency  = $cfg['renovax']['currency'];

// 1) CSRF.
if (!rx_csrf_verify($csrf)) {
    $flash('csrf');
}

// 2) Input validation. Generic message — anti-enumeration.
if ($user === '' || strlen($user) > 120 || $amountIn === '') {
    $flash('user');
}
$amount = filter_var($amountIn, FILTER_VALIDATE_FLOAT);
if ($amount === false) {
    $flash('user');
}
$amount = round((float) $amount, 2);
if ($amount < (float) $cfg['renovax']['min_amount'] || $amount > (float) $cfg['renovax']['max_amount']) {
    $flash('user');
}

// 3) Per-IP rate limit (10-minute sliding window via pagos_renovax).
$ip   = rx_client_ip();
$stmt = rx_db()->prepare(
    'SELECT COUNT(*) FROM pagos_renovax WHERE client_ip = :ip AND created_at >= NOW() - INTERVAL 10 MINUTE'
);
$stmt->execute([':ip' => $ip]);
if ((int) $stmt->fetchColumn() >= (int) $cfg['limits']['create_per_ip_10m']) {
    $flash('rate');
}

// 4) Lookup user.
$webxUser = rx_find_user($user);
if (!$webxUser) {
    $flash('user');
}

// 5) Per-user pending limit.
$stmt = rx_db()->prepare(
    'SELECT COUNT(*) FROM pagos_renovax
     WHERE webx_user_id = :uid AND status = "pending"
       AND created_at >= NOW() - INTERVAL :hours HOUR'
);
$stmt->bindValue(':uid', (int) $webxUser['id'], PDO::PARAM_INT);
$stmt->bindValue(':hours', (int) $cfg['limits']['expire_after_hours'], PDO::PARAM_INT);
$stmt->execute();
if ((int) $stmt->fetchColumn() >= (int) $cfg['limits']['pending_per_user']) {
    $flash('pend');
}

// 6) Create the RENOVAX Payments invoice.
$ts        = time();
$remoteId  = sprintf('webx-%d-%d', $webxUser['id'], $ts);
$publicUrl = rtrim($cfg['site']['public_url'], '/');

$result = rx_renovax()->createInvoice([
    'amount'             => number_format($amount, 2, '.', ''),
    'currency'           => $currency,
    'client_remote_id'   => $remoteId,
    'success_url'        => $publicUrl . '/index.php?status=ok',
    'cancel_url'         => $publicUrl . '/index.php?status=cancel',
    'expires_in_minutes' => max(1, min(1440, (int) $cfg['renovax']['invoice_ttl_minutes'])),
    'metadata'           => [
        'webx_user_id'  => (string) $webxUser['id'],
        'webx_username' => (string) $webxUser['username'],
        'webx_email'    => (string) $webxUser['email'],
        'site_name'     => (string) $cfg['site']['name'],
    ],
]);

if (!$result['ok'] || empty($result['data']['id']) || empty($result['data']['pay_url'])) {
    rx_log('error', 'createInvoice failed', [
        'status' => $result['status'] ?? 0,
        'error'  => $result['error']  ?? '',
    ]);
    $flash('create');
}

$invoiceId = $result['data']['id'];
$payUrl    = $result['data']['pay_url'];

// 7) Persist pending row in pagos_renovax.
$ins = rx_db()->prepare(
    'INSERT INTO pagos_renovax
        (invoice_id, webx_user_id, webx_email, username, amount_request, currency, balance_antes, status, client_ip)
     VALUES
        (:invoice_id, :uid, :email, :username, :amount, :currency, :balance_antes, "pending", :ip)'
);
$ins->execute([
    ':invoice_id'    => $invoiceId,
    ':uid'           => (int) $webxUser['id'],
    ':email'         => (string) $webxUser['email'],
    ':username'      => (string) $webxUser['username'],
    ':amount'        => number_format($amount, 2, '.', ''),
    ':currency'      => $currency,
    ':balance_antes' => $webxUser['balance'],
    ':ip'            => $ip,
]);

// 8) Persist the invoice id in session so the success page can poll for it.
$_SESSION['rx_last_invoice_id'] = $invoiceId;
$_SESSION['rx_last_user_id']    = (int) $webxUser['id'];

header('Location: ' . $payUrl, true, 303);
exit;
