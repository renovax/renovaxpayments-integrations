<?php
/**
 * RENOVAX Payments — WebX.One bootstrap.
 *
 * Loads config, opens the PDO connection, exposes helpers used across
 * index.php / create.php / webhook.php / status.php / cleanup.php.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('PHP 7.4+ required.');
}

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(500);
    exit('Configuration missing — copy lib/config.example.php to lib/config.php and edit it.');
}

/** @var array $RX_CFG */
$RX_CFG = require __DIR__ . '/config.php';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/renovax.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/telegram.php';

date_default_timezone_set('UTC');

function rx_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = explode(',', (string) $_SERVER[$h])[0];
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

function rx_log(string $level, string $message, array $context = []): void
{
    $line = '[renovax-payments][' . $level . '] ' . $message;
    foreach ($context as $k => $v) {
        $line .= ' ' . $k . '=' . (is_scalar($v) ? (string) $v : json_encode($v));
    }
    error_log($line);
}
