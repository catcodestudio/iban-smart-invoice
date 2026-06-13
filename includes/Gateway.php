<?php

declare(strict_types=1);

namespace CatCode\IbanSmartInvoice;

if (!defined('ABSPATH')) { exit; }

/**
 * WooCommerce payment gateway "Оплата на IBAN/картку (Monobank / Privat24)".
 *
 * Logic:
 * 1. Checkout shows our method like any other gateway.
 * 2. process_payment() saves the memo on the order, sets it to on-hold, redirects to Thank You.
 * 3. Thank You renders templates/thankyou-payment.php (QR / Monobank / Privat24 / copy).
 * 4. Reconciliation:
 *    - Free version: shop owner marks the order paid manually from WC → Orders.
 *    - With the Pro add-on installed: Mono Personal API webhook + OrderMatcher flip the
 *      order to processing automatically (see PUBLIC HOOKS below).
 *
 * PUBLIC HOOKS (used by iban-smart-invoice-pro and any third-party extension):
 *   filter isipay/gateway/pro_form_fields($fields, $isipay_settings) — return extra WC settings fields
 *                                                                inserted before display_section.
 *   action isipay/gateway/webhook_field_html($key, $data)         — render the body of a custom
 *                                                                type='isipay_webhook' settings field.
 *   filter isipay/gateway/sync_patch($patch, $gateway_settings)   — mutate the patch handed to
 *                                                                SettingsRepository on save.
 *   filter isipay/thankyou/amount($amount, $order, $expected)     — return the amount we should
 *                                                                build the QR / deep-links for
 *                                                                (Pro returns remaining when partial).
 *   filter isipay/thankyou/partial_summary($current, $order)      — null when nothing partial yet;
 *                                                                an array {received, remaining,
 *                                                                is_partial} when Pro has data.
 *   filter isipay/thankyou/localize($data, $order)                — augment ISIPAY_THANKYOU JS payload.
 */
final class Gateway extends \WC_Payment_Gateway
{
    public const ID = 'isi_bank_transfer';

    public function __construct()
    {
        $this->id                 = self::ID;
        $this->has_fields         = false;
        $this->method_title       = __('IBAN Smart Invoice', 'iban-smart-invoice');
        $this->method_description = __('Оплата на IBAN з QR і deep-links Monobank/Privat24. Автоматична детекція оплати — через add-on IBAN Smart Invoice — Pro.', 'iban-smart-invoice');
        $this->supports           = ['products'];
        $this->icon               = '';

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = (string) $this->get_option('title');
        $this->description = (string) $this->get_option('description');
        $this->enabled     = (string) $this->get_option('enabled', 'yes');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [self::class, 'syncToIsipaySettings'], 20);
        add_action('woocommerce_thankyou_' . $this->id, [self::class, 'renderThankYou']);
        add_action('woocommerce_email_before_order_table', [self::class, 'renderEmailInstructions'], 10, 4);
    }

    public function init_form_fields(): void
    {
        $isipay = SettingsRepository::all();

        $fields = [
            'enabled' => [
                'title'   => __('Активувати', 'iban-smart-invoice'),
                'type'    => 'checkbox',
                'label'   => __('Показувати на сторінці оформлення замовлення', 'iban-smart-invoice'),
                'default' => 'yes',
            ],
            'title' => [
                'title'       => __('Назва способу оплати', 'iban-smart-invoice'),
                'type'        => 'text',
                'description' => __('Те, що бачить покупець у списку способів оплати.', 'iban-smart-invoice'),
                'default'     => __('Оплата на IBAN/картку (Monobank / Privat24)', 'iban-smart-invoice'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __('Опис під назвою', 'iban-smart-invoice'),
                'type'        => 'textarea',
                'description' => __('Показується покупцеві після того, як він обрав цей спосіб оплати.', 'iban-smart-invoice'),
                'default'     => __('QR-код і кнопки для Monobank та Privat24 — на наступній сторінці. Підтверджу зарахування оплати вручну, як тільки вона надійде.', 'iban-smart-invoice'),
                'desc_tip'    => true,
            ],

            'requisites_section' => [
                'title' => __('Реквізити отримувача', 'iban-smart-invoice'),
                'type'  => 'title',
                'description' => __('Дані ФОП / юр. особи. Підставляються в QR і email-інструкції.', 'iban-smart-invoice'),
            ],
            'iban' => [
                'title'       => __('IBAN', 'iban-smart-invoice'),
                'type'        => 'text',
                'default'     => $isipay['iban'],
                'placeholder' => 'UA000000000000000000000000000',
                'description' => __('29 символів, починається з UA. Контрольна сума за ISO 13616.', 'iban-smart-invoice'),
                'desc_tip'    => true,
                'css'         => 'min-width:340px;',
            ],
            'beneficiary_name' => [
                'title'       => __('Отримувач', 'iban-smart-invoice'),
                'type'        => 'text',
                'default'     => $isipay['beneficiary_name'],
                'description' => __('Назва ФОП / юр. особи як у банку.', 'iban-smart-invoice'),
                'desc_tip'    => true,
                'css'         => 'min-width:340px;',
            ],
            'beneficiary_edrpou' => [
                'title'       => __('ЄДРПОУ / ІПН', 'iban-smart-invoice'),
                'type'        => 'text',
                'default'     => $isipay['beneficiary_edrpou'],
                'description' => __('Необов\'язково. Деякі банки вимагають у призначенні платежу.', 'iban-smart-invoice'),
                'desc_tip'    => true,
            ],
            'memo_template' => [
                'title'       => __('Призначення платежу', 'iban-smart-invoice'),
                'type'        => 'text',
                'default'     => $isipay['memo_template'],
                'placeholder' => 'Оплата за замовлення #{order_id}',
                'description' => __('Що клієнт побачить у полі «Призначення платежу» свого банку. <code>{order_id}</code> підставляється на номер замовлення (наприклад "Оплата за замовлення #42"). Залиш цей токен — Pro add-on шукає його у виписці Monobank щоб зіставити з замовленням.', 'iban-smart-invoice'),
            ],

            'display_section' => [
                'title' => __('Що показувати покупцю на сторінці «Дякуємо»', 'iban-smart-invoice'),
                'type'  => 'title',
            ],
            'show_qr' => [
                'title'   => __('QR-код', 'iban-smart-invoice'),
                'type'    => 'checkbox',
                'label'   => __('Показувати QR-код (клієнт сканує у своєму банку → сума і реквізити заповнюються автоматично)', 'iban-smart-invoice'),
                'default' => $isipay['show_qr'],
            ],
            'show_monobank' => [
                'title'   => __('Кнопка «Відкрити в Monobank»', 'iban-smart-invoice'),
                'type'    => 'checkbox',
                'label'   => __('Показувати кнопку швидкого переказу в додатку Monobank', 'iban-smart-invoice'),
                'default' => $isipay['show_monobank'],
            ],
            'monobank_send_link' => [
                'title'       => __('Посилання на твою банку Monobank', 'iban-smart-invoice'),
                'type'        => 'text',
                'default'     => $isipay['monobank_send_link'],
                'placeholder' => 'https://send.monobank.ua/jar/...',
                'description' => __('Якщо у тебе є <strong>банка Monobank</strong> (накопичувальний рахунок) — встав сюди її посилання вигляду <code>https://send.monobank.ua/jar/abc123</code>. Кнопка відкриє Mono з вже заповненою сумою і призначенням. Залиш порожнім — кнопка не показуватиметься.', 'iban-smart-invoice'),
                'css'         => 'min-width:340px;',
            ],
            'show_privat24' => [
                'title'   => __('Кнопка «Відкрити в Privat24»', 'iban-smart-invoice'),
                'type'    => 'checkbox',
                'label'   => __('Показувати кнопку швидкого переказу в Privat24', 'iban-smart-invoice'),
                'default' => $isipay['show_privat24'],
            ],
            'privat24_link' => [
                'title'       => __('Посилання на твій шаблон Privat24', 'iban-smart-invoice'),
                'type'        => 'text',
                'default'     => $isipay['privat24_link'],
                'placeholder' => 'https://next.privat24.ua/payments/form/...',
                'description' => __('Якщо у тебе налаштований <strong>шаблон переказу в Privat24</strong> — встав сюди його посилання. Залиш порожнім якщо нема — кнопка не показуватиметься.', 'iban-smart-invoice'),
                'css'         => 'min-width:340px;',
            ],
        ];

        // Extension point: Pro add-on (or any other plugin) returns extra fields
        // here, to be inserted before display_section. If nothing hooks in, we
        // show a single promo block pointing at the Pro upgrade.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        $proFields = (array) apply_filters('isipay/gateway/pro_form_fields', [], $isipay);

        if (!empty($proFields)) {
            $fields = self::insertBefore($fields, 'display_section', $proFields);
        } else {
            $fields = self::insertBefore($fields, 'display_section', [
                'pro_promo' => [
                    'title' => __('Автоматична детекція оплати — Pro add-on', 'iban-smart-invoice'),
                    'type'  => 'title',
                    'description' => __('У безкоштовній версії клієнт оплачує за QR-кодом, а ти підтверджуєш надходження вручну через WooCommerce → Замовлення. Окремий add-on додає Monobank Personal API webhook → замовлення автоматично переходить у «В обробці» за ~3 секунди після оплати. Деталі — на сайті розробника.', 'iban-smart-invoice'),
                ],
            ]);
        }

        $this->form_fields = $fields;
    }

    /**
     * @param array<string,array<string,mixed>> $fields
     * @param string $beforeKey
     * @param array<string,array<string,mixed>> $insert
     * @return array<string,array<string,mixed>>
     */
    private static function insertBefore(array $fields, string $beforeKey, array $insert): array
    {
        $out = [];
        foreach ($fields as $k => $v) {
            if ($k === $beforeKey) {
                foreach ($insert as $ik => $iv) {
                    $out[$ik] = $iv;
                }
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Custom field renderer for type='isipay_webhook'. Empty by default — Pro
     * add-on hooks into `isipay/gateway/webhook_field_html` to print the
     * "Connect Monobank" UI. WC::generate_settings_html looks for a
     * generate_<type>_html method on the gateway, so we have to declare it
     * here even though the body is contributed externally.
     */
    public function generate_isipay_webhook_html(string $key, array $data): string
    {
        ob_start();
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        do_action('isipay/gateway/webhook_field_html', $key, $data);
        return (string) ob_get_clean();
    }

    /**
     * Project gateway settings onto the shared `isipay_settings` option, where
     * the rest of the plugin reads them. The Pro add-on hooks `isipay/gateway/sync_patch`
     * to add an encrypted mono_token to the patch and scrub the plaintext.
     */
    public static function syncToIsipaySettings(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $gw = get_option('woocommerce_' . self::ID . '_settings');
        if (!is_array($gw)) {
            return;
        }

        $iban = trim((string) ($gw['iban'] ?? ''));
        if ($iban !== '' && !SettingsRepository::isIbanValid($iban)) {
            \WC_Admin_Settings::add_error(__('IBAN не пройшов перевірку контрольної суми. Решта налаштувань збережена, IBAN — ні.', 'iban-smart-invoice'));
            $gw['iban'] = (string) SettingsRepository::get('iban', '');
            update_option('woocommerce_' . self::ID . '_settings', $gw, false);
            $iban = $gw['iban'];
        }

        $patch = [
            'iban'               => $iban !== '' ? strtoupper(preg_replace('/\s+/', '', $iban)) : '',
            'beneficiary_name'   => sanitize_text_field((string) ($gw['beneficiary_name'] ?? '')),
            'beneficiary_edrpou' => sanitize_text_field((string) ($gw['beneficiary_edrpou'] ?? '')),
            'memo_template'      => self::sanitizeMemo((string) ($gw['memo_template'] ?? Memo::DEFAULT_TEMPLATE)),
            'show_qr'            => ($gw['show_qr'] ?? 'no') === 'yes' ? 'yes' : 'no',
            'show_monobank'      => ($gw['show_monobank'] ?? 'no') === 'yes' ? 'yes' : 'no',
            'show_privat24'      => ($gw['show_privat24'] ?? 'no') === 'yes' ? 'yes' : 'no',
            'monobank_send_link' => esc_url_raw(trim((string) ($gw['monobank_send_link'] ?? '')), ['http', 'https']),
            'privat24_link'      => esc_url_raw(trim((string) ($gw['privat24_link'] ?? '')), ['http', 'https']),
        ];

        // Pro add-on hook: add encrypted mono_token to the patch, scrub plaintext from $gw.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        $patch = (array) apply_filters('isipay/gateway/sync_patch', $patch, $gw);

        SettingsRepository::update($patch);
    }

    private static function sanitizeMemo(string $raw): string
    {
        $raw = sanitize_text_field($raw);
        if ($raw === '') {
            return Memo::DEFAULT_TEMPLATE;
        }
        if (!str_contains($raw, '{order_id}')) {
            $raw .= ' #{order_id}';
        }
        return $raw;
    }

    /**
     * Hide the method on checkout when no valid IBAN is configured —
     * rendering the Thank You page without an IBAN would just be confusing.
     */
    public function is_available(): bool
    {
        if (!parent::is_available()) {
            return false;
        }
        $iban = (string) SettingsRepository::get('iban', '');
        return $iban !== '' && SettingsRepository::isIbanValid($iban);
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return ['result' => 'failure'];
        }

        $memo = Memo::forOrder($order);
        $order->update_meta_data(Memo::ORDER_META_KEY, $memo);
        $order->save();

        $order->update_status('on-hold', sprintf(
            /* translators: %s — призначення платежу */
            __('Очікуємо оплату на IBAN. Призначення платежу: %s', 'iban-smart-invoice'),
            $memo
        ));

        wc_reduce_stock_levels($order_id);

        if (function_exists('WC') && WC()->cart instanceof \WC_Cart) {
            WC()->cart->empty_cart();
        }

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }

    public static function register(): void
    {
        add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
            $gateways[] = self::class;
            return $gateways;
        });
    }

    public static function renderThankYou(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }
        if ($order->get_payment_method() !== self::ID) {
            return;
        }
        if ($order->is_paid()) {
            self::renderPaidNotice($order);
            return;
        }

        $memo = (string) $order->get_meta(Memo::ORDER_META_KEY);
        if ($memo === '') {
            $memo = Memo::forOrder($order);
        }

        $settings = SettingsRepository::all();
        $expected = (float) $order->get_total();
        $currency = $order->get_currency();

        // Extension point: Pro returns the remaining amount when partial payments
        // have already arrived. Without Pro, $amount === $expected and there is no
        // partial state.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        $amount  = (float) apply_filters('isipay/thankyou/amount', $expected, $order, $expected);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        $partial = apply_filters('isipay/thankyou/partial_summary', null, $order);

        $received  = is_array($partial) ? (float) ($partial['received']  ?? 0.0) : 0.0;
        $isPartial = is_array($partial) ? (bool)  ($partial['is_partial'] ?? false) : false;

        $qrDataUri      = '';
        $nbuPaymentUrl  = '';
        if ($settings['iban'] !== '') {
            $nbuPaymentUrl = QrGenerator::payload(
                (string) $settings['iban'],
                (string) $settings['beneficiary_name'],
                $amount,
                $memo,
                $currency,
                (string) ($settings['beneficiary_edrpou'] ?? '')
            );
            if (($settings['show_qr'] ?? 'no') === 'yes') {
                $qrDataUri = QrGenerator::dataUri($nbuPaymentUrl);
            }
        }

        $monoLink   = Deeplinks::monobank((string) $settings['monobank_send_link'], $amount, $memo);
        $privatLink = Deeplinks::privat24((string) $settings['privat24_link'], $amount, $memo);

        self::enqueueThankYouAssets($order);

        $template = IBAN_SMART_INVOICE_PLUGIN_DIR . 'templates/thankyou-payment.php';
        if (is_readable($template)) {
            include $template;
        }
    }

    /**
     * Already-paid orders that overpaid: show a customer-facing note about
     * the difference so the missing money doesn't look "lost".
     */
    private static function renderPaidNotice(\WC_Order $order): void
    {
        $overpayment = (float) $order->get_meta('_isipay_overpayment');
        if ($overpayment <= 0.0) {
            return;
        }
        $currency = $order->get_currency();
        ?>
        <section class="isi-pay isi-pay--paid-overpaid" aria-live="polite">
            <div class="isi-pay__partial-notice">
                <strong>
                    <?php esc_html_e('Замовлення оплачено. Дякуємо!', 'iban-smart-invoice'); ?>
                </strong>
                <span>
                    <?php
                    printf(
                        /* translators: %s: overpayment amount */
                        esc_html__('Ви переплатили %s — ми звʼяжемось з вами для повернення різниці або зарахування на наступне замовлення.', 'iban-smart-invoice'),
                        wp_kses_post(wc_price($overpayment, ['currency' => $currency]))
                    );
                    ?>
                </span>
            </div>
        </section>
        <?php
        wp_enqueue_style(
            'isipay-thankyou',
            IBAN_SMART_INVOICE_PLUGIN_URL . 'assets/css/thankyou.css',
            [],
            IBAN_SMART_INVOICE_VERSION
        );
    }

    private static function enqueueThankYouAssets(\WC_Order $order): void
    {
        wp_enqueue_style(
            'isipay-thankyou',
            IBAN_SMART_INVOICE_PLUGIN_URL . 'assets/css/thankyou.css',
            [],
            IBAN_SMART_INVOICE_VERSION
        );
        wp_enqueue_script(
            'isipay-thankyou',
            IBAN_SMART_INVOICE_PLUGIN_URL . 'assets/js/thankyou.js',
            [],
            IBAN_SMART_INVOICE_VERSION,
            true
        );
        $localize = [
            'i18n' => [
                'copied'     => __('Скопійовано', 'iban-smart-invoice'),
                'copyFailed' => __('Не вдалося скопіювати', 'iban-smart-invoice'),
                'paid'       => __('Платіж отримано — оновлюємо сторінку…', 'iban-smart-invoice'),
                'partial'    => __('Отримано {received} ₴ з {expected} ₴. Доплатіть ще {remaining} ₴ щоб завершити замовлення.', 'iban-smart-invoice'),
            ],
        ];
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        $localize = (array) apply_filters('isipay/thankyou/localize', $localize, $order);
        wp_localize_script('isipay-thankyou', 'ISIPAY_THANKYOU', $localize);
    }

    /**
     * On-hold / invoice email body: paste payment requisites so the customer
     * can finish paying without coming back to Thank You.
     */
    public static function renderEmailInstructions($order, bool $sent_to_admin = false, bool $plain_text = false, $email = null): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }
        if ($order->get_payment_method() !== self::ID) {
            return;
        }
        if ($sent_to_admin || $order->is_paid()) {
            return;
        }

        $settings = SettingsRepository::all();
        $memo = (string) $order->get_meta(Memo::ORDER_META_KEY);
        if ($memo === '') {
            $memo = Memo::forOrder($order);
        }

        $iban        = (string) $settings['iban'];
        $beneficiary = (string) $settings['beneficiary_name'];
        $edrpou      = (string) $settings['beneficiary_edrpou'];
        $total       = $order->get_total();

        if ($plain_text) {
            echo "\n" . esc_html__('Реквізити для оплати:', 'iban-smart-invoice') . "\n";
            echo 'IBAN: ' . esc_html($iban) . "\n";
            if ($beneficiary !== '') {
                echo esc_html__('Отримувач', 'iban-smart-invoice') . ': ' . esc_html($beneficiary) . "\n";
            }
            if ($edrpou !== '') {
                echo 'ЄДРПОУ/ІПН: ' . esc_html($edrpou) . "\n";
            }
            echo esc_html__('Сума', 'iban-smart-invoice') . ': ' . esc_html(wp_strip_all_tags(wc_price($total, ['currency' => $order->get_currency()]))) . "\n";
            echo esc_html__('Призначення платежу', 'iban-smart-invoice') . ': ' . esc_html($memo) . "\n\n";
            return;
        }

        ?>
        <h2><?php esc_html_e('Реквізити для оплати', 'iban-smart-invoice'); ?></h2>
        <table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #e5e5e5;border-collapse:collapse;">
            <tr><th align="left">IBAN</th><td><code><?php echo esc_html($iban); ?></code></td></tr>
            <?php if ($beneficiary !== '') : ?>
                <tr><th align="left"><?php esc_html_e('Отримувач', 'iban-smart-invoice'); ?></th><td><?php echo esc_html($beneficiary); ?></td></tr>
            <?php endif; ?>
            <?php if ($edrpou !== '') : ?>
                <tr><th align="left">ЄДРПОУ/ІПН</th><td><?php echo esc_html($edrpou); ?></td></tr>
            <?php endif; ?>
            <tr><th align="left"><?php esc_html_e('Сума', 'iban-smart-invoice'); ?></th><td><?php echo wp_kses_post(wc_price($total, ['currency' => $order->get_currency()])); ?></td></tr>
            <tr><th align="left"><?php esc_html_e('Призначення платежу', 'iban-smart-invoice'); ?></th><td><code><?php echo esc_html($memo); ?></code></td></tr>
        </table>
        <p><?php esc_html_e('Після зарахування коштів магазин підтвердить замовлення.', 'iban-smart-invoice'); ?></p>
        <?php
    }
}
