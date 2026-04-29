<?php
/**
 * RENOVAX Payments — schema uninstaller.
 *
 * Only invoked when the merchant explicitly ticks "purge data" in the
 * uninstall confirmation. By default the events table is preserved so a
 * reinstall keeps the historical idempotency log intact.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'renovax_events`;');

return true;
