# Reply to plugins@wordpress.org — Review ID F1 iban-smart-invoice/catcodestudio/22May26/T1 22May26/3.9

Hi,

Uploaded 0.1.6 addressing every point from your initial review. Slug stays `iban-smart-invoice`.

1. **Not permitted files** — `.distignore` extended to drop `vendor/**/LICENSE-*`, `NOTICE*`, `CHANGELOG*`, `CONTRIBUTING*`, `UPGRADE*`, `SECURITY*`, `phpunit.xml*`, `phpstan.neon*`, `psalm.xml*`, `.editorconfig`, `.gitattributes`, `.gitignore`, `.php-cs-fixer*`, plus the `tests/`, `examples/`, `docs/` and `.github/` dirs from any vendor package. `LICENSE-ASL-2.0` (the one you flagged) and its NOTICE/MIT duplicates no longer ship. The remaining single `LICENSE` per vendor package is the upstream-required attribution — left in place. Build is reproducible via `tools/build.sh`.

2. **Out-of-date `chillerlan/php-qrcode`** — `composer.json` now requires `^6.0`, `composer.lock` and the shipped `vendor/` are pinned to `6.0.1`. The QR generator already uses the v6 `outputInterface` API (`QRGdImagePNG::class`).

3. **Undocumented external service** — the Monobank webhook code (the only outbound `wp_remote_post`) has been moved out of the free plugin and now lives in the separate paid add-on "IBAN Smart Invoice — Pro", which is not part of this submission. The free plugin makes no outbound HTTP requests at all. A new `== External services ==` section in `readme.txt` states this explicitly and documents the bank.gov.ua / send.monobank.ua / link.privat24.ua URLs that are *encoded inside the QR / deep-link* (the customer's banking app opens them on their device — the plugin never calls them server-side), with terms-of-service and privacy-policy links for each operator.

4. **Generic / short prefixes** — every flagged identifier renamed from the three-letter `isi_` to the four-plus-letter `isipay_`:
   * Options: `isi_settings`, `isi_db_version` → `isipay_settings`, `isipay_db_version`
   * Action / filter hooks: `isi/plugin/boot`, `isi/gateway/*`, `isi/thankyou/*`, `isi_qr_render_failed` → `isipay/*` / `isipay_qr_render_failed`
   * Script handles & JS globals: `isi-thankyou`, `ISI_THANKYOU` → `isipay-thankyou`, `ISIPAY_THANKYOU`
   * Field type / generator: `isi_webhook` → `isipay_webhook`, `generate_isi_webhook_html` → `generate_isipay_webhook_html`
   * Post meta: `_isi_memo`, `_isi_overpayment` → `_isipay_memo`, `_isipay_overpayment`
   The previously-flagged `isi_webhook_secret`, `isi_memo_candidates_limit` filters and the `wp_ajax_isi_*` AJAX handlers lived in the now-separate Pro add-on and were never part of the free plugin code path. The Pro add-on has been renamed in lockstep on our side so the public hook contract stays consistent.
   * `Gateway::ID = 'isi_bank_transfer'` is kept unchanged — it's the WC payment-gateway ID, persisted on existing orders as `_payment_method` and used by `woocommerce_<id>_settings` storage. Changing it would break order history for any future installs after the .org listing goes live. Public-facing BEM CSS class names (`.isi-pay__*`) are also kept — they're presentation, not code identifiers, and existing theme overrides target them.
   * `update_option('woocommerce_' . self::ID . '_settings', ...)` you flagged in Gateway.php — this is `WC_Settings_API` standard gateway-settings storage (see `woocommerce/includes/abstracts/abstract-wc-settings-api.php::init_settings()`), the key is built and owned by WooCommerce core, not by our plugin.

Tested on a clean WP install with `WP_DEBUG=true` + WC 10.7 + Plugin Check 2.x — no notices, no warnings, no errors on activation, on checkout, on the Thank You page, on settings save, on uninstall.

Thanks for the review.

— CatCode Studio
