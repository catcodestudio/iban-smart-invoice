<?php

declare(strict_types=1);

namespace CatCode\IbanSmartInvoice;

/**
 * Розгортання memo-шаблону у конкретний рядок призначення платежу.
 * Один формат використовується і при checkout (зберігаємо в order meta),
 * і при matching у webhook — щоб обидві сторони працювали з ідентичним рядком.
 */
final class Memo
{
    public const ORDER_META_KEY = '_isipay_memo';

    public const DEFAULT_TEMPLATE = 'Оплата за замовлення #{order_id}';

    public static function forOrder(\WC_Order $order): string
    {
        $template = (string) SettingsRepository::get('memo_template', self::DEFAULT_TEMPLATE);
        if ($template === '') {
            $template = self::DEFAULT_TEMPLATE;
        }

        $host = (string) (wp_parse_url(home_url(), PHP_URL_HOST) ?? '');
        $site = preg_replace('/[^a-zA-Z0-9.\-]/', '', $host) ?? '';

        $rendered = strtr($template, [
            '{order_id}' => (string) $order->get_id(),
            '{site}' => $site,
        ]);

        return trim(preg_replace('/\s+/', ' ', $rendered) ?? $rendered);
    }
}
