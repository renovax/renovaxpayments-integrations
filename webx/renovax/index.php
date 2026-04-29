<?php
require_once __DIR__ . '/lib/bootstrap.php';
rx_csrf_start();

$status   = $_GET['status']    ?? '';
$invoice  = (string) ($_GET['iid'] ?? ($_SESSION['rx_last_invoice_id'] ?? ''));
$flash    = $_GET['err']       ?? '';
$lang     = rx_lang();
$rtl      = rx_is_rtl();
$currency = $RX_CFG['renovax']['currency'];
$token    = rx_csrf_token();

$flashMessages = [
    'user'   => t('err_user_or_amount'),
    'csrf'   => t('err_csrf'),
    'create' => t('err_create'),
    'pend'   => t('err_too_many'),
    'rate'   => t('err_rate_limit'),
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES) ?>" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(t('page_title'), ENT_QUOTES) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php if ($rtl): ?>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/style.css" rel="stylesheet">
</head>
<body class="rnx-body">

<div class="rnx-card-wrapper">
    <div class="card rnx-card shadow-sm">
        <div class="card-body p-4 p-md-5">

            <div class="text-center mb-4">
                <img src="assets/icon.png" alt="RENOVAX Payments" class="rnx-logo mb-3">
                <h1 class="h3 rnx-brand mb-1"><?= htmlspecialchars(t('recharge'), ENT_QUOTES) ?></h1>
                <p class="text-muted small mb-0"><?= htmlspecialchars(t('subtitle'), ENT_QUOTES) ?></p>
            </div>

            <?php if ($status === 'ok' && $invoice !== ''): ?>
                <div class="alert alert-success text-center" role="alert">
                    <h2 class="h5 mb-2"><?= htmlspecialchars(t('success_title'), ENT_QUOTES) ?></h2>
                    <p class="mb-0"><?= htmlspecialchars(t('success_body'), ENT_QUOTES) ?></p>
                </div>
                <div class="text-center small text-muted" id="rx-poll" data-iid="<?= htmlspecialchars($invoice, ENT_QUOTES) ?>"></div>

            <?php elseif ($status === 'cancel'): ?>
                <div class="alert alert-warning text-center" role="alert">
                    <h2 class="h5 mb-2"><?= htmlspecialchars(t('cancel_title'), ENT_QUOTES) ?></h2>
                    <p class="mb-3"><?= htmlspecialchars(t('cancel_body'), ENT_QUOTES) ?></p>
                    <a href="?" class="btn btn-outline-secondary"><?= htmlspecialchars(t('try_again'), ENT_QUOTES) ?></a>
                </div>

            <?php else: ?>
                <?php if ($flash !== '' && isset($flashMessages[$flash])): ?>
                    <div class="alert alert-danger small mb-3" role="alert">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <?= htmlspecialchars($flashMessages[$flash], ENT_QUOTES) ?>
                    </div>
                <?php endif; ?>

                <form action="create.php" method="POST" autocomplete="off" novalidate>
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
                    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang, ENT_QUOTES) ?>">

                    <div class="mb-3">
                        <label for="user" class="form-label fw-semibold">
                            <i class="bi bi-person me-1"></i><?= htmlspecialchars(t('user_label'), ENT_QUOTES) ?>
                        </label>
                        <input type="text" class="form-control form-control-lg" id="user" name="user"
                               placeholder="<?= htmlspecialchars(t('user_placeholder'), ENT_QUOTES) ?>"
                               required maxlength="120" autocomplete="username">
                    </div>

                    <div class="mb-4">
                        <label for="amount" class="form-label fw-semibold">
                            <i class="bi bi-cash-coin me-1"></i><?= htmlspecialchars(t('amount_label'), ENT_QUOTES) ?>
                            <span class="text-muted small">(<?= htmlspecialchars($currency, ENT_QUOTES) ?>)</span>
                        </label>
                        <input type="text" class="form-control form-control-lg" id="amount" name="amount"
                               inputmode="decimal" pattern="^\d+(\.\d{1,2})?$"
                               placeholder="<?= htmlspecialchars(t('amount_placeholder'), ENT_QUOTES) ?>"
                               required>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100 rnx-btn">
                        <?= htmlspecialchars(t('pay_button'), ENT_QUOTES) ?>
                        <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                </form>
            <?php endif; ?>

            <div class="text-center small text-muted mt-4">
                <i class="bi bi-shield-lock me-1"></i>
                <?= htmlspecialchars(t('powered_by'), ENT_QUOTES) ?>
            </div>
        </div>
    </div>
</div>

<?php if ($status === 'ok' && $invoice !== ''): ?>
<script>
(function () {
    var node = document.getElementById('rx-poll');
    if (!node) return;
    var iid = node.dataset.iid;
    var attempts = 0;
    var maxAttempts = 12;
    function tick() {
        attempts++;
        fetch('status.php?iid=' + encodeURIComponent(iid))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.status === 'paid') {
                    node.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> Balance: ' + d.balance_after + '</span>';
                } else if (attempts < maxAttempts) {
                    setTimeout(tick, 5000);
                } else {
                    node.textContent = '';
                }
            })
            .catch(function () {
                if (attempts < maxAttempts) setTimeout(tick, 5000);
            });
    }
    setTimeout(tick, 3000);
})();
</script>
<?php endif; ?>

</body>
</html>
