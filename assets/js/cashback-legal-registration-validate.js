/**
 * Cashback Legal — UX-валидация чекбоксов согласий на форме WC-регистрации.
 *
 * Использует window.CashbackConsentValidate (cashback-consent-validate.js).
 * Серверная валидация в Cashback_Legal_Registration_Checkboxes::validate_checkboxes
 * остаётся как fallback для случая «JS отключён».
 *
 * @since 1.7.0
 */
(function () {
    'use strict';

    function init() {
        if (!window.CashbackConsentValidate) {
            return;
        }
        var i18n = window.cashbackLegalRegistrationI18n || {};
        var pdMessage = i18n.pdRequiredMessage || 'Необходимо согласие на обработку персональных данных.';
        var offerMessage = i18n.offerRequiredMessage || 'Необходимо принятие Пользовательского соглашения (публичной оферты).';

        // WooCommerce form selector — register блок шорткода/страницы.
        var forms = document.querySelectorAll('form.woocommerce-form-register, form.register, form#customer_login_form .woocommerce-form-register');
        if (!forms.length) {
            return;
        }

        forms.forEach(function (form) {
            if (form.getAttribute('data-cashback-consent-validate-bound') === '1') {
                return;
            }
            form.setAttribute('data-cashback-consent-validate-bound', '1');

            var pdCheckbox = form.querySelector('input[name="cashback_legal_consent_pd"]');
            var offerCheckbox = form.querySelector('input[name="cashback_legal_consent_offer"]');

            if (pdCheckbox) {
                window.CashbackConsentValidate.bindAutoClear(pdCheckbox);
            }
            if (offerCheckbox) {
                window.CashbackConsentValidate.bindAutoClear(offerCheckbox);
            }

            form.addEventListener('submit', function (e) {
                var ok = true;

                // Каждый чекбокс валидируется со своим сообщением — потому
                // что у каждого согласия свой юр. факт (152-ФЗ ст.9 vs ГК 437).
                if (pdCheckbox && !window.CashbackConsentValidate.validateRequired([pdCheckbox], pdMessage)) {
                    ok = false;
                }
                if (offerCheckbox && !window.CashbackConsentValidate.validateRequired([offerCheckbox], offerMessage)) {
                    // Фокус уйдёт на pd, если оба невалидны — это нормально.
                    ok = false;
                }

                if (!ok) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                }
            }, true);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
