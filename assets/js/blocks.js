(function () {
    'use strict';

    const settings = window.wc.wcSettings.getSetting('isi_bank_transfer_data', {});
    const el = window.wp.element.createElement;
    const decodeEntities = window.wp.htmlEntities.decodeEntities;

    const label = decodeEntities(settings.title || 'Оплата на IBAN/картку');
    const description = decodeEntities(settings.description || '');

    const Content = () => el('div', { className: 'isi-blocks-content' }, description);

    const Label = () => el('span', { className: 'isi-blocks-label' }, label);

    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'isi_bank_transfer',
        label: el(Label),
        ariaLabel: label,
        content: el(Content),
        edit: el(Content),
        canMakePayment: () => true,
        paymentMethodId: 'isi_bank_transfer',
        supports: {
            features: settings.supports || ['products'],
        },
    });
})();
