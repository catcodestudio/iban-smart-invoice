<?php

declare(strict_types=1);

namespace CatCode\IbanSmartInvoice;

/**
 * Thin wrapper around the `isipay_settings` option. Other modules (Gateway,
 * any add-on) talk to this so the underlying storage can evolve without
 * touching every caller.
 *
 * Token + webhook helpers live in the Pro add-on (it owns those secrets);
 * Free only knows about display-facing settings.
 */
final class SettingsRepository
{
    public const OPTION = 'isipay_settings';

    public const DEFAULTS = [
        'iban' => '',
        'beneficiary_name' => '',
        'beneficiary_edrpou' => '',
        'memo_template' => 'Оплата за замовлення #{order_id}',
        'show_qr' => 'yes',
        'show_monobank' => 'yes',
        'show_privat24' => 'yes',
        'monobank_send_link' => '',
        'privat24_link' => '',
    ];

    public static function all(): array
    {
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw)) {
            $raw = [];
        }
        return array_merge(self::DEFAULTS, $raw);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function update(array $patch): void
    {
        $next = array_merge(self::all(), $patch);
        update_option(self::OPTION, $next, false);
    }

    public static function isIbanValid(string $iban): bool
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban));
        if (!preg_match('/^UA\d{27}$/', $iban)) {
            return false;
        }
        // ISO 13616 mod-97
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        $remainder = '';
        foreach (str_split($numeric) as $digit) {
            $remainder = (string) (((int) ($remainder . $digit)) % 97);
        }
        return $remainder === '1';
    }
}
