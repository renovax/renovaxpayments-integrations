<?php
/**
 * Plugin Name:       RENOVAX Payments for WooCommerce
 * Plugin URI:        https://github.com/renovax/woocommerce-plugin
 * Description:       Multi-platform payment gateway: Crypto (USDT, USDC, EURC, DAI on BSC, Ethereum, Polygon, Arbitrum, Base, Optimism, Avalanche, Tron, Solana...), Stripe (cards), PayPal and more — all behind a single checkout.
 * Version:           1.0.0
 * Author:            RENOVAX
 * Author URI:        https://payments.renovax.net
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       renovaxpayments
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 8.0
 * WC tested up to:   9.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RENOVAXPAYMENTS_VERSION', '1.0.0');
define('RENOVAXPAYMENTS_PLUGIN_FILE', __FILE__);
define('RENOVAXPAYMENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RENOVAXPAYMENTS_PLUGIN_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', 'renovaxpayments_init', 11);
function renovaxpayments_init()
{
    load_plugin_textdomain('renovaxpayments', false, dirname(plugin_basename(__FILE__)) . '/languages');

    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
               . esc_html__('RENOVAX Payments requires WooCommerce to be installed and active.', 'renovaxpayments')
               . '</p></div>';
        });
        return;
    }

    require_once RENOVAXPAYMENTS_PLUGIN_DIR . 'includes/class-renovax-api-client.php';
    require_once RENOVAXPAYMENTS_PLUGIN_DIR . 'includes/class-wc-gateway-renovax.php';
    require_once RENOVAXPAYMENTS_PLUGIN_DIR . 'includes/class-renovax-webhook.php';

    add_filter('woocommerce_payment_gateways', 'renovaxpayments_register_gateway');

    Renovax_Webhook::register();
}

function renovaxpayments_register_gateway($methods)
{
    $methods[] = 'WC_Gateway_Renovax';
    return $methods;
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings = '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=renovaxpayments')) . '">'
              . esc_html__('Settings', 'renovaxpayments') . '</a>';
    array_unshift($links, $settings);
    return $links;
});

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
