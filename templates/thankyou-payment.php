<?php
/**
 * Thank You payment instructions — Stage 5 full UX.
 *
 * Очікувані змінні (передаються з Gateway::renderThankYou):
 *
 * @var \WC_Order $order
 * @var string    $memo
 * @var array     $settings
 * @var string    $qrDataUri    base64 data URI або ''
 * @var string    $monoLink     повний URL з amount+memo або ''
 * @var string    $privatLink   повний URL з amount+memo або ''
 * @var float     $amount
 * @var string    $currency
 */

if (!defined('ABSPATH')) {
    exit;
}

// Локальні template-змінні — не глобальні (файл інклюдиться у scope методу).
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$iban        = (string) ($settings['iban'] ?? '');
$beneficiary = (string) ($settings['beneficiary_name'] ?? '');
$edrpou      = (string) ($settings['beneficiary_edrpou'] ?? '');
$showQr      = ($settings['show_qr'] ?? 'no') === 'yes' && $qrDataUri !== '';
$showMono    = ($settings['show_monobank'] ?? 'no') === 'yes' && $monoLink !== '';
$showPrivat  = ($settings['show_privat24'] ?? 'no') === 'yes' && $privatLink !== '';
$amountFmt   = wc_price($amount, ['currency' => $currency]);
// $isPartial / $received / $expected — передаються з Gateway::renderThankYou (можуть бути не визначені у legacy)
$isPartial   = isset($isPartial) ? (bool) $isPartial : false;
$received    = isset($received) ? (float) $received : 0.0;
$expected    = isset($expected) ? (float) $expected : (float) $order->get_total();
// phpcs:enable
?>
<section class="isi-pay" aria-labelledby="isi-pay-title"
         data-isi-order="<?php echo esc_attr((string) $order->get_id()); ?>"
         data-isi-key="<?php echo esc_attr((string) $order->get_order_key()); ?>">

    <h2 class="isi-pay__title" id="isi-pay-title">
        <?php esc_html_e('Оплата на IBAN', 'iban-smart-invoice'); ?>
    </h2>

    <?php if ($isPartial) : ?>
        <div class="isi-pay__partial-notice" role="status">
            <strong>
                <?php
                printf(
                    /* translators: 1: received, 2: expected */
                    esc_html__('Отримано %1$s з %2$s.', 'iban-smart-invoice'),
                    wp_kses_post(wc_price($received, ['currency' => $currency])),
                    wp_kses_post(wc_price($expected, ['currency' => $currency]))
                );
                ?>
            </strong>
            <span>
                <?php
                printf(
                    /* translators: %s: remaining amount */
                    esc_html__('Доплатіть ще %s — QR і реквізити нижче вже містять суму, що лишилась.', 'iban-smart-invoice'),
                    wp_kses_post($amountFmt)
                );
                ?>
            </span>
        </div>
    <?php else : ?>
        <p class="isi-pay__intro">
            <?php esc_html_e('Відскануйте QR-код у вашому банк-додатку — IBAN, сума та призначення заповняться автоматично. Як тільки платіж надійде, замовлення оновиться без перезавантаження.', 'iban-smart-invoice'); ?>
        </p>
    <?php endif; ?>

    <div class="isi-pay__layout">
        <?php if ($showQr || $nbuPaymentUrl !== '') : ?>
            <div class="isi-pay__qr">
                <?php if ($showQr) : ?>
                    <img src="<?php echo esc_attr($qrDataUri); ?>"
                         alt="<?php esc_attr_e('QR-код для оплати на IBAN', 'iban-smart-invoice'); ?>"
                         width="240" height="240">
                    <p class="isi-pay__qr-hint">
                        <?php esc_html_e('Скануй QR у банк-додатку — реквізити заповняться автоматично', 'iban-smart-invoice'); ?>
                    </p>
                <?php endif; ?>
                <?php if ($nbuPaymentUrl !== '') : ?>
                    <a class="isi-pay__btn isi-pay__btn--primary" href="<?php echo esc_url($nbuPaymentUrl); ?>" rel="noopener">
                        <?php esc_html_e('Або відкрити в банк-додатку', 'iban-smart-invoice'); ?>
                    </a>
                    <p class="isi-pay__qr-hint isi-pay__qr-hint--secondary">
                        <?php esc_html_e('Зручно якщо купуєш з телефону: банк відкриється з заповненими реквізитами', 'iban-smart-invoice'); ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="isi-pay__details">
            <dl class="isi-pay__list">
                <dt><?php esc_html_e('IBAN', 'iban-smart-invoice'); ?></dt>
                <dd>
                    <code class="isi-pay__value" data-isi-copy="<?php echo esc_attr($iban); ?>"><?php echo esc_html($iban); ?></code>
                    <button type="button" class="isi-pay__copy" data-isi-copy-btn="<?php echo esc_attr($iban); ?>"
                            aria-label="<?php esc_attr_e('Скопіювати IBAN', 'iban-smart-invoice'); ?>">
                        <?php esc_html_e('Скопіювати', 'iban-smart-invoice'); ?>
                    </button>
                </dd>

                <?php if ($beneficiary !== '') : ?>
                    <dt><?php esc_html_e('Отримувач', 'iban-smart-invoice'); ?></dt>
                    <dd><span class="isi-pay__value"><?php echo esc_html($beneficiary); ?></span></dd>
                <?php endif; ?>

                <?php if ($edrpou !== '') : ?>
                    <dt><?php esc_html_e('ЄДРПОУ / ІПН', 'iban-smart-invoice'); ?></dt>
                    <dd><span class="isi-pay__value"><?php echo esc_html($edrpou); ?></span></dd>
                <?php endif; ?>

                <dt><?php esc_html_e('Сума', 'iban-smart-invoice'); ?></dt>
                <dd>
                    <span class="isi-pay__value"><?php echo wp_kses_post($amountFmt); ?></span>
                    <button type="button" class="isi-pay__copy" data-isi-copy-btn="<?php echo esc_attr(number_format($amount, 2, '.', '')); ?>"
                            aria-label="<?php esc_attr_e('Скопіювати суму', 'iban-smart-invoice'); ?>">
                        <?php esc_html_e('Скопіювати', 'iban-smart-invoice'); ?>
                    </button>
                </dd>

                <dt><?php esc_html_e('Призначення платежу', 'iban-smart-invoice'); ?></dt>
                <dd>
                    <code class="isi-pay__value" data-isi-copy="<?php echo esc_attr($memo); ?>"><?php echo esc_html($memo); ?></code>
                    <button type="button" class="isi-pay__copy" data-isi-copy-btn="<?php echo esc_attr($memo); ?>"
                            aria-label="<?php esc_attr_e('Скопіювати призначення', 'iban-smart-invoice'); ?>">
                        <?php esc_html_e('Скопіювати', 'iban-smart-invoice'); ?>
                    </button>
                </dd>
            </dl>

            <?php if ($showMono || $showPrivat) : ?>
                <div class="isi-pay__actions">
                    <?php if ($showMono) : ?>
                        <a class="isi-pay__btn isi-pay__btn--mono" href="<?php echo esc_url($monoLink); ?>"
                           target="_blank" rel="noopener">
                            <?php esc_html_e('Відкрити в Monobank (банка)', 'iban-smart-invoice'); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($showPrivat) : ?>
                        <a class="isi-pay__btn isi-pay__btn--privat" href="<?php echo esc_url($privatLink); ?>"
                           target="_blank" rel="noopener">
                            <?php esc_html_e('Відкрити в Privat24', 'iban-smart-invoice'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="isi-pay__status" data-isi-status>
        <span class="isi-pay__status-dot" aria-hidden="true"></span>
        <span class="isi-pay__status-text">
            <?php esc_html_e('Очікуємо надходження платежу…', 'iban-smart-invoice'); ?>
        </span>
    </div>

    <div class="isi-pay__toast" data-isi-toast role="status" aria-live="polite" hidden></div>
</section>
