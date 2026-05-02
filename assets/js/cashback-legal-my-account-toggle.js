/**
 * cashback-legal-my-account-toggle.js
 *
 * UX-cleanup 1.4.0: AJAX toggle для опц. consent'ов в личном кабинете
 * (38-ФЗ marketing, 149-ФЗ tech_data). Vanilla JS, без jQuery.
 *
 * Идемпотентность: server возвращает {noop:true}, если состояние совпадает.
 * При ошибке (rate-limit / network / 4xx) checkbox откатывается, чтобы
 * UI отражал реальное состояние согласия.
 */
(function () {
    'use strict';

    var cfg = window.cashbackLegalMyAccountConsent || null;
    if (!cfg || !cfg.ajaxUrl || !cfg.action || !cfg.nonce) {
        return;
    }

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        var section = document.querySelector('.cashback-legal-consents');
        if (!section) {
            return;
        }
        var status = section.querySelector('.cashback-legal-toggle-status');
        var inputs = section.querySelectorAll('input[type="checkbox"][data-consent-type]');

        var statusTimer = null;
        function flash(message, isError) {
            if (!status) {
                return;
            }
            status.textContent = message || '';
            status.classList.toggle('is-error', !!isError);
            if (statusTimer) {
                window.clearTimeout(statusTimer);
            }
            if (message) {
                statusTimer = window.setTimeout(function () {
                    status.textContent = '';
                    status.classList.remove('is-error');
                }, 3500);
            }
        }

        function send(input) {
            var consentType = input.getAttribute('data-consent-type') || '';
            var enabled = input.checked ? '1' : '0';
            var prevState = input.getAttribute('data-current') === '1';

            var params = new URLSearchParams();
            params.append('action', cfg.action);
            params.append('nonce', cfg.nonce);
            params.append('consent_type', consentType);
            params.append('enabled', enabled);

            input.disabled = true;
            flash(cfg.i18n && cfg.i18n.saving ? cfg.i18n.saving : '', false);

            window.fetch(cfg.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params.toString()
            }).then(function (response) {
                if (response.status === 429) {
                    revert(input, prevState);
                    flash(cfg.i18n && cfg.i18n.rateLimit ? cfg.i18n.rateLimit : 'Rate limit', true);
                    return null;
                }
                if (!response.ok) {
                    revert(input, prevState);
                    flash(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error', true);
                    return null;
                }
                return response.json();
            }).then(function (json) {
                if (!json) {
                    return;
                }
                if (!json.success) {
                    revert(input, prevState);
                    flash(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error', true);
                    return;
                }
                input.setAttribute('data-current', input.checked ? '1' : '0');
                flash(cfg.i18n && cfg.i18n.saved ? cfg.i18n.saved : 'Saved', false);
            }).catch(function () {
                revert(input, prevState);
                flash(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Error', true);
            }).then(function () {
                input.disabled = false;
            });
        }

        function revert(input, prevState) {
            input.checked = prevState;
            input.setAttribute('data-current', prevState ? '1' : '0');
        }

        for (var i = 0; i < inputs.length; i++) {
            inputs[i].addEventListener('change', function (event) {
                send(event.currentTarget);
            });
        }
    });
}());
