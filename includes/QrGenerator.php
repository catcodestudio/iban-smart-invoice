<?php

declare(strict_types=1);

namespace CatCode\IbanSmartInvoice;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;

/**
 * QR-код для оплати на IBAN — офіційний стандарт НБУ (формат v002).
 *
 * Reference: офіційний PDF НБУ "Як сформувати QR-код суб'єкту господарювання"
 * https://bank.gov.ua/admin_uploads/article/QR-code_24_12_2024.pdf
 *
 * Структура (Таблиця 1, PDF NBU 2024-12-24):
 *  [0]  BCD                — службова мітка
 *  [1]  002                — версія (поточна чинна)
 *  [2]  2                  — кодування Win1251
 *  [3]  UCT                — функція (кредитовий переказ)
 *  [4]  ''                 — зарезервовано
 *  [5]  recipient          — найменування юр. особи / ПІБ ФОП
 *  [6]  account            — IBAN
 *  [7]  amount/currency    — UAH<amount> (опційно)
 *  [8]  ''                 — зарезервовано
 *  [9]  recipient_code     — ЄДРПОУ/РНОКПП/паспорт
 *  [10] ''                 — зарезервовано
 *  [11] ''                 — зарезервовано
 *  [12] purpose            — призначення платежу
 *  [13] ''                 — зарезервовано
 *
 * КРИТИЧНО (з реверс-аналізу байтів офіційного base64 з PDF):
 *  - separator = CRLF (\r\n), хоча текст PDF каже "LF"
 *  - реальна iconv UTF-8 → Win1251 (не просто declared, фактичні байти Win1251)
 *  - prefix = `https://bank.gov.ua/qr/` (БЕЗ піддомена qr.)
 *  - 13 рядків (плюс trailing CRLF після останнього reserved)
 *
 * Anti-patterns (НЕ робити):
 *  - v003 з 17 полями (opencartbot reference) — це draft, не чинний у живих банках
 *  - LF separator — Privat24 fail-ить
 *  - UTF-8 байти при declared encoding=2 — Privat24 fail-ить
 *  - prefix qr.bank.gov.ua/ — це alternative subdomain, не офіційний
 *  - EPC SCT (BCD v002 + SCT) — SEPA для EUR, не Україна
 */
final class QrGenerator
{
    public const FUNC_REGULAR = 'UCT';

    private const NBU_URL_PREFIX = 'https://bank.gov.ua/qr/';
    private const SEPARATOR      = "\r\n";

    public static function isAvailable(): bool
    {
        return class_exists(QRCode::class);
    }

    public static function payload(
        string $iban,
        string $beneficiary,
        float $amount,
        string $memo,
        string $currency = 'UAH',
        string $edrpou = '',
        string $function = self::FUNC_REGULAR  // legacy сигнатура, тільки UCT для v002
    ): string {
        $iban        = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
        // Сума — trim trailing zeros (1.00 → "1", 99.50 → "99.5", 99.99 → "99.99")
        $amountStr   = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
        $beneficiary = self::trimLine($beneficiary, 140);
        $memo        = self::trimLine($memo, 420);
        $edrpou      = preg_replace('/\D+/', '', $edrpou) ?? '';

        // NBU v002 — 13 полів (Таблиця 1 з офіційного PDF NBU).
        // Реверс-аналіз байтів офіційного прикладу:
        //   - між amount(8) і EDRPOU(9) НЕМАЄ порожнього рядка (мій попередній bug)
        //   - між EDRPOU(9) і purpose(12) — ДВА порожніх рядки (поля 10, 11 reserved)
        //   - після purpose — ще один порожній рядок (field 13 reserved, trailing CRLF)
        $lines = [
            'BCD',                        // field 1  службова мітка
            '002',                        // field 2  версія
            '2',                          // field 3  кодування Win1251
            'UCT',                        // field 4  функція — кредитовий переказ
            '',                           // field 5  зарезервовано
            $beneficiary,                 // field 6  отримувач
            $iban,                        // field 7  рахунок
            $amount > 0 ? $currency . $amountStr : '',  // field 8 сума/валюта (опційно)
            $edrpou,                      // field 9  код отримувача (ЄДРПОУ) — БЕЗ gap перед!
            '',                           // field 10 зарезервовано
            '',                           // field 11 зарезервовано
            $memo,                        // field 12 призначення платежу
            '',                           // field 13 зарезервовано (trailing)
        ];

        // CRLF separator (підтверджено реверс-аналізом байтів офіційного NBU прикладу).
        $payload = implode(self::SEPARATOR, $lines);

        // Реальна конвертація UTF-8 → Win1251 (підтверджено байти з NBU PDF base64).
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'WINDOWS-1251//TRANSLIT', $payload);
            if ($converted !== false) {
                $payload = $converted;
            }
        }

        // Base64URL без padding.
        $b64 = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        return self::NBU_URL_PREFIX . $b64;
    }

    public static function dataUri(string $payload): string
    {
        if (!self::isAvailable()) {
            return '';
        }

        // chillerlan v6 split the single output-type constant into per-format
        // classes addressed via `outputInterface`. PNG output through GD = QRGdImagePNG;
        // base64 wrapping moved from `imageBase64` to `outputBase64`.
        $options = new QROptions([
            'version'          => -1,              // auto-detect
            'eccLevel'         => EccLevel::M,
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64'     => true,
            'scale'            => 6,
            'imageTransparent' => false,
        ]);

        try {
            return (new QRCode($options))->render($payload);
        } catch (\Throwable $e) {
            // Tихий fallback — порожній QR на Thank You означає що клієнт побачить
            // тільки реквізити (IBAN, сума, призначення з copy-кнопками). Без QR.
            // Якщо потрібен debug — додай у wp-config.php:
            //   add_action('isipay_qr_render_failed', fn($msg) => error_log($msg));
            // `isipay_` — публічний префікс плагіна, частина public hook contract.
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            do_action('isipay_qr_render_failed', $e->getMessage(), $payload);
            return '';
        }
    }

    private static function trimLine(string $value, int $max): string
    {
        $value = trim(preg_replace('/[\r\n]+/', ' ', $value) ?? '');
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }
}
