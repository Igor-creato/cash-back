/**
 * Cashback Social Auth — consent toggle (Group 11b-3, iter-11).
 *
 * Чекбокс «Согласен на обработку технических данных устройства» ставится рядом
 * с кнопками VK/Yandex. До его тика кнопки отключены (aria-disabled + класс
 * cashback-social-btn--disabled + href="#"). После тика — href переключается
 * на значение из data-consent-href (URL содержит &cashback_social_consent=1),
 * и клик отправляет пользователя на OAuth-start endpoint, который на сервере
 * проверит параметр и сохранит consent_given=true в session-data.
 *
 * Без этого JS (если он не загрузился) кнопки остаются с href="#" и клик
 * ничего не делает — fail-closed UX. Прямой GET на OAuth-start без параметра
 * cashback_social_consent=1 отвергается на сервере.
 */
(function () {
    'use strict';

    function syncButtonsFromCheckbox(checkbox) {
        var container = checkbox.closest('.cashback-social-buttons');
        if (!container) {
            return;
        }
        var buttons = container.querySelectorAll('.cashback-social-btn');
        var on = !!checkbox.checked;

        buttons.forEach(function (btn) {
            var consentHref = btn.getAttribute('data-consent-href') || '';
            if (on && consentHref !== '') {
                btn.setAttribute('href', consentHref);
                btn.classList.remove('cashback-social-btn--disabled');
                btn.setAttribute('aria-disabled', 'false');
            } else {
                btn.setAttribute('href', '#');
                btn.classList.add('cashback-social-btn--disabled');
                btn.setAttribute('aria-disabled', 'true');
            }
        });
    }

    function init() {
        var checkboxes = document.querySelectorAll('[data-cashback-social-consent]');
        if (!checkboxes.length) {
            return;
        }

        checkboxes.forEach(function (checkbox) {
            // Начальное состояние: синхронизируем сразу, чтобы disabled-статус
            // отражал текущее значение чекбокса (с учётом восстановления формы браузером).
            syncButtonsFromCheckbox(checkbox);

            checkbox.addEventListener('change', function () {
                syncButtonsFromCheckbox(checkbox);
            });
        });

        // Перехват клика на отключённой кнопке — блокируем переход.
        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('.cashback-social-btn') : null;
            if (!btn) {
                return;
            }
            if (btn.getAttribute('aria-disabled') === 'true'
                || btn.classList.contains('cashback-social-btn--disabled')
                || btn.getAttribute('href') === '#') {
                e.preventDefault();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
