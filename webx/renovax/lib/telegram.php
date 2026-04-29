<?php
declare(strict_types=1);

/**
 * Optional Telegram notifier. Disabled by default (config.telegram.enabled = false).
 * Once RENOVAX Payments ships its native Telegram bot, prefer that over this helper.
 */
function rx_telegram_notify(string $message): void
{
    global $RX_CFG;
    if (empty($RX_CFG['telegram']['enabled'])) {
        return;
    }
    $token  = $RX_CFG['telegram']['bot_token'];
    $chatId = $RX_CFG['telegram']['chat_id'];
    if ($token === '' || $chatId === '') {
        return;
    }

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'chat_id' => $chatId,
            'text'    => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]),
    ]);
    @curl_exec($ch);
    curl_close($ch);
}
