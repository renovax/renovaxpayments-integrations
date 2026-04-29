<?php
/**
 * RENOVAX Payments — schema installer.
 *
 * Creates the events table used for webhook idempotency. The PRIMARY KEY on
 * event_id allows INSERT IGNORE to act as a deduplication latch when the
 * same X-Renovax-Event-Id is delivered twice by the retry mechanism.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$sql = array();

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'renovax_events` ('
    . '`event_id`     VARCHAR(64)  NOT NULL,'
    . '`event_type`   VARCHAR(64)  NOT NULL DEFAULT "",'
    . '`invoice_id`   VARCHAR(64)  NOT NULL DEFAULT "",'
    . '`id_order`     INT UNSIGNED NULL,'
    . '`id_cart`      INT UNSIGNED NULL,'
    . '`payload_hash` CHAR(64)     NOT NULL DEFAULT "",'
    . '`received_at`  DATETIME     NOT NULL,'
    . 'PRIMARY KEY (`event_id`),'
    . 'KEY `idx_invoice` (`invoice_id`),'
    . 'KEY `idx_order`   (`id_order`)'
    . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

foreach ($sql as $query) {
    if (!Db::getInstance()->execute($query)) {
        return false;
    }
}

return true;
