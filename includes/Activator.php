<?php

declare(strict_types=1);

namespace CatCode\IbanSmartInvoice;

/**
 * Free-plugin activator: seeds default `isipay_settings`. No DB table — the
 * automated-payment journal lives in the Pro add-on and is created there
 * (Pro's Activator owns wp_isipay_payments + webhook_secret seeding).
 */
final class Activator
{
    public static function activate(): void
    {
        update_option('isipay_db_version', IBAN_SMART_INVOICE_DB_VERSION, false);

        $existing = get_option('isipay_settings');
        if (!is_array($existing)) {
            $existing = [];
        }
        $defaults = [
            'iban' => '',
            'beneficiary_name' => '',
            'beneficiary_edrpou' => '',
            'memo_template' => '{order_id}',
            'show_qr' => 'yes',
            'show_monobank' => 'yes',
            'show_privat24' => 'yes',
            'monobank_send_link' => '',
            'privat24_link' => '',
        ];
        update_option('isipay_settings', array_merge($defaults, $existing), false);
    }

    public static function deactivate(): void
    {
        // Settings survive deactivate. Cleanup happens in uninstall.php.
    }
}
