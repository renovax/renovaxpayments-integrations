<?php
/**
 * RENOVAX Payments — daily housekeeping (CLI only).
 *
 * Suggested cron entry:
 *   0 3 * * * /usr/bin/php /var/www/html/renovax/cleanup.php >> /var/log/renovax-cleanup.log 2>&1
 *
 * - Marks pending invoices older than `expire_after_hours` as 'expired'.
 * - Logs a one-line summary.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/lib/bootstrap.php';

$hours = (int) ($RX_CFG['limits']['expire_after_hours'] ?? 24);

$stmt = rx_db()->prepare(
    'UPDATE pagos_renovax
        SET status = "expired"
      WHERE status = "pending"
        AND created_at < NOW() - INTERVAL :hours HOUR'
);
$stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
$stmt->execute();

$expired = $stmt->rowCount();
fwrite(STDOUT, sprintf("[%s] expired_pending=%d hours_threshold=%d\n", date('c'), $expired, $hours));
