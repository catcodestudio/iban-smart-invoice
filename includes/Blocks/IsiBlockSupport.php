<?php

declare(strict_types=1);

namespace CatCode\IbanSmartInvoice\Blocks;

if (!defined('ABSPATH')) { exit; }

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use CatCode\IbanSmartInvoice\Gateway;
use CatCode\IbanSmartInvoice\SettingsRepository;

/**
 * WC Blocks integration: реєструє наш payment method у блочному checkout.
 *
 * Legacy [woocommerce_checkout] shortcode підхоплює gateway сам через
 * `woocommerce_payment_gateways` filter. Block checkout (WC 8.3+) — окремий
 * пайплайн через `AbstractPaymentMethodType` + client-side React-реєстрацію.
 */
final class IsiBlockSupport extends AbstractPaymentMethodType
{
    protected $name = Gateway::ID;

    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_' . Gateway::ID . '_settings', []);
    }

    public function is_active(): bool
    {
        $enabled = ($this->settings['enabled'] ?? 'no') === 'yes';
        if (!$enabled) {
            return false;
        }
        $iban = (string) SettingsRepository::get('iban', '');
        return $iban !== '' && SettingsRepository::isIbanValid($iban);
    }

    public function get_payment_method_script_handles(): array
    {
        // Handle convention `wc-payment-method-<id>` мірорить core WC Bacs (`wc-payment-method-bacs`).
        $handle = 'wc-payment-method-isipay';

        wp_register_script(
            $handle,
            IBAN_SMART_INVOICE_PLUGIN_URL . 'assets/js/blocks.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
            IBAN_SMART_INVOICE_VERSION,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle, 'iban-smart-invoice');
        }

        return [$handle];
    }

    public function get_payment_method_data(): array
    {
        return [
            'title'       => (string) ($this->settings['title'] ?? __('Оплата на IBAN/картку (Monobank / Privat24)', 'iban-smart-invoice')),
            'description' => (string) ($this->settings['description'] ?? ''),
            'supports'    => ['products'],
        ];
    }
}
