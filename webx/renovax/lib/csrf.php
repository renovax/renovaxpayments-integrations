<?php
declare(strict_types=1);

/**
 * Minimal CSRF token helper. Stores the token in a session cookie and
 * validates with hash_equals() on form submit.
 */

function rx_csrf_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('renovax_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        @session_start();
    }
}

function rx_csrf_token(): string
{
    rx_csrf_start();
    if (empty($_SESSION['rx_csrf'])) {
        $_SESSION['rx_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['rx_csrf'];
}

function rx_csrf_verify(?string $submitted): bool
{
    rx_csrf_start();
    if (empty($_SESSION['rx_csrf']) || !is_string($submitted) || $submitted === '') {
        return false;
    }
    return hash_equals($_SESSION['rx_csrf'], $submitted);
}
