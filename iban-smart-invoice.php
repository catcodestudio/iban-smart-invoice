<?php
/**
 * Plugin Name:       IBAN Smart Invoice
 * Plugin URI:        https://catcode.com.ua/modules/iban-smart-invoice
 * Description:       QR code (NBU v002) + Monobank/Privat24 deep-link buttons on the WooCommerce Thank You page. Optional Pro add-on adds automatic payment detection via Monobank Personal API webhook.
 * Version:           0.1.9
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Author:            CatCode
 * Author URI:        https://catcode.com.ua
 * License:           GPL-2.0-or-later
 * Text Domain:       iban-smart-invoice
 * Domain Path:       /languages
 *
 * WC requires at least: 10.0
 * WC tested up to:      10.7
 */

if (!defined('ABSPATH')) {
    exit;
}

define('IBAN_SMART_INVOICE_VERSION', '0.1.9');
define('IBAN_SMART_INVOICE_PLUGIN_FILE', __FILE__);
define('IBAN_SMART_INVOICE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IBAN_SMART_INVOICE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('IBAN_SMART_INVOICE_DB_VERSION', '1');

if (file_exists(IBAN_SMART_INVOICE_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once IBAN_SMART_INVOICE_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'CatCode\\IbanSmartInvoice\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = IBAN_SMART_INVOICE_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($path)) {
            require_once $path;
        }
    });
}

register_activation_hook(__FILE__, [\CatCode\IbanSmartInvoice\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\CatCode\IbanSmartInvoice\Activator::class, 'deactivate']);

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            IBAN_SMART_INVOICE_PLUGIN_FILE,
            true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            IBAN_SMART_INVOICE_PLUGIN_FILE,
            true
        );
    }
});

add_action('woocommerce_blocks_loaded', static function (): void {
    if (!class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
        return;
    }
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        static function ($registry): void {
            $registry->register(new \CatCode\IbanSmartInvoice\Blocks\IsiBlockSupport());
        }
    );
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function (array $links): array {
    $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . \CatCode\IbanSmartInvoice\Gateway::ID);
    $settings_link = '<a href="' . esc_url($settings_url) . '">' . esc_html__('Налаштування', 'iban-smart-invoice') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

add_action('plugins_loaded', static function (): void {
    // WP 4.6+ auto-loads translations by slug — we don't call load_plugin_textdomain manually
    // (PluginCheck flags it as discouraged when invoked from the plugin itself).

    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('IBAN Smart Invoice потребує активного WooCommerce.', 'iban-smart-invoice');
            echo '</p></div>';
        });
        return;
    }

    \CatCode\IbanSmartInvoice\Plugin::instance()->boot();
});
