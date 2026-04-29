<?php
/**
 * RENOVAX Payments — Logger helper.
 *
 * Thin wrapper around PrestaShopLogger::addLog with a consistent [renovax]
 * prefix and severity mapping. Honours the RENOVAX_DEBUG_LOG flag so that
 * informational events are silenced in production.
 *
 *   1 = info, 2 = warning, 3 = error, 4 = major
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class RenovaxLogger
{
    public static function info($message, $idOrder = null)
    {
        if (!self::debugEnabled()) {
            return;
        }
        self::write(1, $message, $idOrder);
    }

    public static function warning($message, $idOrder = null)
    {
        self::write(2, $message, $idOrder);
    }

    public static function error($message, $idOrder = null)
    {
        self::write(3, $message, $idOrder);
    }

    private static function debugEnabled()
    {
        return (int) Configuration::get('RENOVAX_DEBUG_LOG') === 1;
    }

    private static function write($severity, $message, $idOrder)
    {
        $msg = '[renovax] ' . $message;
        if (class_exists('PrestaShopLogger')) {
            PrestaShopLogger::addLog(
                $msg,
                (int) $severity,
                null,
                'RenovaxPayments',
                $idOrder !== null ? (int) $idOrder : null,
                true
            );
        } else {
            error_log($msg);
        }
    }
}
