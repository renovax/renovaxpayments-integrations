<?php
declare(strict_types=1);

/**
 * Returns a singleton PDO connected to the WebX.One database.
 */
function rx_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $RX_CFG;
    $cfg = $RX_CFG['db'];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        (int) ($cfg['port'] ?? 3306),
        $cfg['name'],
        $cfg['charset'] ?? 'utf8mb4'
    );

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        rx_log('error', 'DB connection failed', ['msg' => $e->getMessage()]);
        http_response_code(500);
        exit('Database connection failed. Please contact the administrator.');
    }

    return $pdo;
}

/**
 * Look up a WebX user by email or username (input may be either).
 * Returns ['id', 'username', 'email', 'balance'] or null.
 */
function rx_find_user(string $emailOrUsername): ?array
{
    $emailOrUsername = trim($emailOrUsername);
    if ($emailOrUsername === '') {
        return null;
    }

    $byEmail = strpos($emailOrUsername, '@') !== false;
    $sql = $byEmail
        ? 'SELECT id, username, email, balance FROM users WHERE email = :v LIMIT 1'
        : 'SELECT id, username, email, balance FROM users WHERE username = :v LIMIT 1';

    $stmt = rx_db()->prepare($sql);
    $stmt->execute([':v' => $emailOrUsername]);
    $row = $stmt->fetch();
    return $row ?: null;
}
