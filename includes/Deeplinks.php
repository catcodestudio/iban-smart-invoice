<?php

declare(strict_types=1);

namespace CatCode\IbanSmartInvoice;

/**
 * Deep-link URL-и для мобільних додатків банків.
 *
 * Універсального публічного API для автозаповнення IBAN-перекладу в Mono/Privat
 * немає — кожен ФОП має власне посилання на банку (`https://send.monobank.ua/<id>`)
 * або шаблон Privat24. Тому беремо базовий URL з settings і доклеюємо amount+memo
 * як query параметри. Якщо клієнт не задав URL — кнопку не показуємо.
 */
final class Deeplinks
{
    public static function monobank(string $baseUrl, float $amount, string $memo): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '' || !self::looksLikeUrl($baseUrl)) {
            return '';
        }
        // send.monobank.ua/<jar>?a=<сума>&t=<призначення>
        return self::appendQuery($baseUrl, [
            'a' => number_format($amount, 2, '.', ''),
            't' => $memo,
        ]);
    }

    public static function privat24(string $baseUrl, float $amount, string $memo): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '' || !self::looksLikeUrl($baseUrl)) {
            return '';
        }
        return self::appendQuery($baseUrl, [
            'amount' => number_format($amount, 2, '.', ''),
            'purpose' => $memo,
        ]);
    }

    private static function looksLikeUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://#i', $url);
    }

    private static function appendQuery(string $url, array $params): string
    {
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
