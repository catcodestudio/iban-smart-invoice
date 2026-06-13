/* IBAN Smart Invoice — Thank You frontend (Stage 5).
   Vanilla JS, без jQuery. Clipboard, toast, polling order-status. */

(() => {
    const root = document.querySelector('.isi-pay');
    if (!root) {
        return;
    }

    const cfg = window.ISIPAY_THANKYOU || {};
    const orderId = root.dataset.isiOrder;
    const orderKey = root.dataset.isiKey;
    const toast = root.querySelector('[data-isi-toast]');
    const statusBox = root.querySelector('[data-isi-status]');

    const showToast = (message) => {
        if (!toast) return;
        toast.textContent = message;
        toast.hidden = false;
        toast.classList.add('isi-pay__toast--visible');
        clearTimeout(showToast._t);
        showToast._t = setTimeout(() => {
            toast.classList.remove('isi-pay__toast--visible');
            setTimeout(() => { toast.hidden = true; }, 250);
        }, 1800);
    };

    const copyText = async (text) => {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                return true;
            }
        } catch (e) { /* fallback below */ }
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            const ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        } catch (e) {
            return false;
        }
    };

    root.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-isi-copy-btn]');
        if (!btn) return;
        event.preventDefault();
        const ok = await copyText(btn.getAttribute('data-isi-copy-btn') || '');
        showToast(ok ? (cfg.i18n && cfg.i18n.copied) || 'Скопійовано' : (cfg.i18n && cfg.i18n.copyFailed) || 'Не вдалося скопіювати');
    });

    if (!cfg.statusUrl || !orderId || !orderKey) {
        return;
    }

    const BASE_MS = (cfg.pollInterval && cfg.pollInterval >= 5000) ? cfg.pollInterval : 10000;
    const MAX_MS = 60000;
    const TOTAL_BUDGET_MS = 60 * 60 * 1000; // 1 година
    const startedAt = Date.now();

    let attempts = 0;
    let nextDelay = BASE_MS;

    const setStatusText = (cls, text) => {
        if (!statusBox) return;
        statusBox.classList.remove('isi-pay__status--paid', 'isi-pay__status--partial');
        if (cls) statusBox.classList.add(cls);
        const t = statusBox.querySelector('.isi-pay__status-text');
        if (t) t.textContent = text;
    };

    const formatMoney = (n) => {
        const num = (typeof n === 'number') ? n : parseFloat(n);
        if (!isFinite(num)) return '0.00';
        return num.toFixed(2);
    };

    const markPaid = () => {
        setStatusText('isi-pay__status--paid',
            (cfg.i18n && cfg.i18n.paid) || 'Платіж отримано — оновлюємо сторінку…');
    };

    const markPartial = (received, remaining) => {
        const tmpl = (cfg.i18n && cfg.i18n.partial) ||
            'Отримано {received} ₴ з {expected} ₴. Доплатіть ще {remaining} ₴ щоб завершити замовлення.';
        const text = tmpl
            .replace('{received}', formatMoney(received))
            .replace('{expected}', formatMoney(received + remaining))
            .replace('{remaining}', formatMoney(remaining));
        setStatusText('isi-pay__status--partial', text);
    };

    const poll = async () => {
        attempts += 1;
        try {
            const url = `${cfg.statusUrl}/${encodeURIComponent(orderId)}?key=${encodeURIComponent(orderKey)}`;
            const res = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            if (res.ok) {
                const data = await res.json();
                if (data && (data.paid === true || (data.status && data.status !== 'on-hold' && data.status !== 'pending'))) {
                    markPaid();
                    setTimeout(() => window.location.reload(), 1500);
                    return;
                }
                if (data && data.partial === true && data.remaining > 0) {
                    markPartial(data.received, data.remaining);
                }
            }
        } catch (e) { /* network — продовжуємо */ }

        if (Date.now() - startedAt < TOTAL_BUDGET_MS) {
            nextDelay = Math.min(Math.round(nextDelay * 1.3), MAX_MS);
            setTimeout(poll, nextDelay);
        }
    };

    setTimeout(poll, BASE_MS);
})();
