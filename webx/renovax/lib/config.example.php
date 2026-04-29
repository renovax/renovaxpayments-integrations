<?php
/**
 * RENOVAX Payments — WebX.One drop-in configuration.
 *
 * Copy this file to `config.php` and fill in your values.
 * `config.php` is in .gitignore and must never be committed or served via HTTP.
 *
 * Permissions recommendation:
 *   chmod 640 lib/config.php
 *   chown <web-server-user>:<web-server-group> lib/config.php
 */

return [

    // ----------------------------------------------------------------
    // WebX.One MySQL/MariaDB credentials.
    // The integration reads `users` (id, username, email, balance) and
    // writes `users.balance` + `pagos_renovax`.
    // ----------------------------------------------------------------
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'webx',
        'user'    => '',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // ----------------------------------------------------------------
    // RENOVAX Payments merchant credentials.
    // Generate the bearer token at: Merchants -> Edit -> API Tokens -> Create.
    // The webhook secret is shown on the merchant edit page.
    // ----------------------------------------------------------------
    'renovax' => [
        'api_base'            => 'https://payments.renovax.net',
        'bearer_token'        => '',
        'webhook_secret'      => '',
        // Currency the merchant charges in (RENOVAX Payments handles FX).
        'currency'            => 'USD',
        'invoice_ttl_minutes' => 15,
        // Min/max amount per top-up in `currency`.
        'min_amount'          => 1.00,
        'max_amount'          => 5000.00,
        // Tolerance for considering an amount "paid" vs overpaid/partial.
        'amount_tolerance'    => 0.05, // ±5 %
    ],

    // ----------------------------------------------------------------
    // Optional Telegram notifier (admin-only audit messages).
    // Leave 'enabled' => false until RENOVAX Payments ships its native
    // Telegram bot integration (see project_pending_features.md).
    // ----------------------------------------------------------------
    'telegram' => [
        'enabled'   => false,
        'bot_token' => '',
        'chat_id'   => '',
    ],

    // ----------------------------------------------------------------
    // Site / branding.
    // ----------------------------------------------------------------
    'site' => [
        'name'       => 'My Unlock Server',
        'public_url' => 'https://example.com/renovax/',
    ],

    // ----------------------------------------------------------------
    // Anti-abuse limits.
    // ----------------------------------------------------------------
    'limits' => [
        // Max simultaneous pending invoices per WebX user.
        'pending_per_user' => 3,
        // Max invoice creations per IP in 10 minutes.
        'create_per_ip_10m' => 20,
        // After how many hours a pending invoice is auto-expired by cleanup.php.
        'expire_after_hours' => 24,
    ],
];
