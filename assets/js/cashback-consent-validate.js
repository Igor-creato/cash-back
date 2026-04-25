/**
 * Cashback Legal — UX-валидация чекбоксов согласий.
 *
 * Vanilla JS-хелпер с публичным API на window.CashbackConsentValidate.
 * Задача: единый паттерн «красная рамка + сообщение под чекбоксом + focus +
 * автосброс при change» для всех форм с обязательными согласиями
 * (WC-регистрация, social-auth, форма вывода, контактная форма).
 *
 * Defense-in-depth: серверная валидация остаётся (WP_Error/AJAX JSON);
 * этот хелпер только улучшает UX, чтобы пользователь сразу понял, что нужно.
 *
 * @since 1.7.0
 */
(function () {
    'use strict';

    var ERROR_CONTAINER_CLASS = 'cashback-consent-error';
    var MESSAGE_CLASS         = 'cashback-consent-error-message';
    var BOUND_FLAG            = 'data-cashback-consent-bound';

    /**
     * Найти контейнер, в который рендерим рамку. По умолчанию — ближайший <p>,
     * <div> или <label>; если не найден — родитель чекбокса.
     */
    function resolveContainer(checkboxEl) {
        if (!checkboxEl) {
            return null;
        }
        var parent = checkboxEl.closest(
            '.cashback-legal-consent, .cashback-legal-payment-consent, .cb-contact-legal-consent, .cashback-social-consent, .form-row, .cb-contact-field, p, .cashback-consent-row'
        );
        return parent || checkboxEl.parentElement;
    }

    function showError(checkboxEl, message) {
        var container = resolveContainer(checkboxEl);
        if (!container) {
            return null;
        }
        container.classList.add(ERROR_CONTAINER_CLASS);

        var existing = container.querySelector('.' + MESSAGE_CLASS);
        if (!existing) {
            existing = document.createElement('span');
            existing.className = MESSAGE_CLASS;
            existing.setAttribute('role', 'alert');
            container.appendChild(existing);
        }
        existing.textContent = String(message || '');

        // ARIA: связать чекбокс с описанием.
        if (checkboxEl && checkboxEl.id) {
            var msgId = checkboxEl.id + '-error';
            existing.id = msgId;
            checkboxEl.setAttribute('aria-describedby', msgId);
            checkboxEl.setAttribute('aria-invalid', 'true');
        }

        return container;
    }

    function clearError(checkboxEl) {
        var container = resolveContainer(checkboxEl);
        if (!container) {
            return;
        }
        container.classList.remove(ERROR_CONTAINER_CLASS);
        var msg = container.querySelector('.' + MESSAGE_CLASS);
        if (msg && msg.parentNode) {
            msg.parentNode.removeChild(msg);
        }
        if (checkboxEl) {
            checkboxEl.removeAttribute('aria-invalid');
            // aria-describedby не убираем полностью — могут быть другие описания.
            if (checkboxEl.getAttribute('aria-describedby') === checkboxEl.id + '-error') {
                checkboxEl.removeAttribute('aria-describedby');
            }
        }
    }

    /**
     * Подключить авто-сброс ошибки при тике чекбокса. Идемпотентно.
     */
    function bindAutoClear(checkboxEl) {
        if (!checkboxEl || checkboxEl.getAttribute(BOUND_FLAG) === '1') {
            return;
        }
        checkboxEl.setAttribute(BOUND_FLAG, '1');
        checkboxEl.addEventListener('change', function () {
            if (checkboxEl.checked) {
                clearError(checkboxEl);
            }
        });
    }

    /**
     * Валидировать массив обязательных чекбоксов. Помечает невыбранные
     * красной рамкой и сообщением, фокусит и скроллит к первому невалидному.
     *
     * @param {Array<HTMLInputElement>} checkboxEls
     * @param {string} message
     * @return {boolean} true если все отмечены
     */
    function validateRequired(checkboxEls, message) {
        if (!checkboxEls || !checkboxEls.length) {
            return true;
        }
        var firstInvalid = null;
        for (var i = 0; i < checkboxEls.length; i++) {
            var cb = checkboxEls[i];
            if (!cb) {
                continue;
            }
            bindAutoClear(cb);
            if (cb.checked) {
                clearError(cb);
            } else {
                showError(cb, message);
                if (!firstInvalid) {
                    firstInvalid = cb;
                }
            }
        }
        if (firstInvalid) {
            try {
                firstInvalid.focus({ preventScroll: true });
            } catch (e) {
                firstInvalid.focus();
            }
            if (typeof firstInvalid.scrollIntoView === 'function') {
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return false;
        }
        return true;
    }

    window.CashbackConsentValidate = {
        showError: showError,
        clearError: clearError,
        bindAutoClear: bindAutoClear,
        validateRequired: validateRequired
    };
})();
