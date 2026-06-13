<?php

declare(strict_types=1);

namespace CatCode\IbanSmartInvoice;

if (!defined('ABSPATH')) { exit; }

/**
 * Free-plugin bootstrap. Registers the gateway and the admin-notice for
 * a missing IBAN, then fires `isipay/plugin/boot` so the Pro add-on (or any
 * third-party extension) can attach automatic-detection features.
 */
final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $this->maybeUpgradeDb();

        Gateway::register();

        if (is_admin()) {
            add_action('admin_notices', [self::class, 'maybeShowIbanMissingNotice']);
        }

        // Extension point — boots Pro add-on and anything else that wants to
        // layer features on top of the free gateway.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        do_action('isipay/plugin/boot');
    }

    public static function maybeShowIbanMissingNotice(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $iban = (string) SettingsRepository::get('iban', '');
        if ($iban !== '') {
            return;
        }
        $gateways = get_option('woocommerce_' . Gateway::ID . '_settings');
        $enabled  = is_array($gateways) ? ($gateways['enabled'] ?? 'no') : 'no';
        if ($enabled !== 'yes') {
            return;
        }
        $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID);
        echo '<div class="notice notice-warning"><p>';
        printf(
            /* translators: %s — link */
            esc_html__('IBAN Smart Invoice увімкнено, але не задано IBAN. Метод оплати приховано від покупців доки %s.', 'iban-smart-invoice'),
            '<a href="' . esc_url($url) . '">' . esc_html__('налаштування не заповнено', 'iban-smart-invoice') . '</a>'
        );
        echo '</p></div>';
    }

    private function maybeUpgradeDb(): void
    {
        $stored = (string) get_option('isipay_db_version', '');
        if ($stored === IBAN_SMART_INVOICE_DB_VERSION) {
            return;
        }
        Activator::activate();
    }

    private function __construct() {}
    private function __clone() {}
}
