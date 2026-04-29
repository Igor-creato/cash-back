/**
 * Cashback Legal — Cookies banner.
 *
 * Vanilla JS, без зависимостей. Показывает баннер при первом визите,
 * фиксирует выбор в localStorage с TTL и пишет лог на сервере через AJAX.
 *
 * Контекст конфига: глобальная переменная cashbackLegalCookiesBanner
 * (wp_localize_script). Поля: ajaxUrl, action, nonce, storageKey,
 * documentVersion, renewAfterDays, policyUrl, i18n.{message,accept,reject,details}.
 *
 * @since 1.3.0
 */
(function () {
    'use strict';

    if (typeof window === 'undefined' || !window.cashbackLegalCookiesBanner) {
        return;
    }

    var cfg = window.cashbackLegalCookiesBanner;

    /**
     * Проверка существующего согласия в localStorage. Возвращает true, если
     * нужно показать баннер (нет записи / истёк TTL / устаревшая версия).
     */
    function shouldShow() {
        var raw;
        try {
            raw = window.localStorage.getItem(cfg.storageKey);
        } catch (e) {
            // localStorage недоступен (приватный режим, политика безопасности) —
            // показываем баннер каждый раз; это безопасный fallback.
            return true;
        }
        if (!raw) {
            return true;
        }
        var record;
        try {
            record = JSON.parse(raw);
        } catch (e) {
            return true;
        }
        if (!record || typeof record !== 'object') {
            return true;
        }
        if (record.version !== cfg.documentVersion) {
            return true;
        }
        var grantedAt = parseInt(record.grantedAt, 10);
        if (!grantedAt) {
            return true;
        }
        var ttlMs = (cfg.renewAfterDays || 365) * 24 * 60 * 60 * 1000;
        if (Date.now() - grantedAt > ttlMs) {
            return true;
        }
        return false;
    }

    function persistChoice(choice, requestId) {
        var record = {
            choice: choice,
            version: cfg.documentVersion,
            grantedAt: Date.now(),
            requestId: requestId
        };
        try {
            window.localStorage.setItem(cfg.storageKey, JSON.stringify(record));
        } catch (e) {
            // Тихо игнорируем — серверный лог уже зафиксировал выбор.
        }
    }

    /**
     * Простой UUID v4 fallback (не cryptographic-grade, но достаточно для
     * idempotency в журнале согласий).
     */
    function generateRequestId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID().replace(/-/g, '');
        }
        // Минимальный fallback — Math.random + timestamp.
        var hex = '';
        for (var i = 0; i < 32; i++) {
            hex += Math.floor(Math.random() * 16).toString(16);
        }
        return hex;
    }

    function recordOnServer(choice, requestId) {
        if (!cfg.ajaxUrl || !cfg.nonce) {
            return Promise.resolve();
        }
        var body = new URLSearchParams();
        body.append('action', cfg.action);
        body.append('nonce', cfg.nonce);
        body.append('choice', choice);
        body.append('request_id', requestId);

        return fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).catch(function () {
            // network ошибка не блокирует UX — у нас есть localStorage fallback.
        });
    }

    function buildBanner(container) {
        container.innerHTML = '';
        container.classList.remove('is-hidden');

        var inner = document.createElement('div');
        inner.className = 'cashback-legal-cookies-banner__inner';

        var msg = document.createElement('p');
        msg.className = 'cashback-legal-cookies-banner__message';
        msg.textContent = cfg.i18n.message;
        inner.appendChild(msg);

        var actions = document.createElement('div');
        actions.className = 'cashback-legal-cookies-banner__actions';

        var btnAccept = document.createElement('button');
        btnAccept.type = 'button';
        btnAccept.className = 'cashback-legal-cookies-banner__btn cashback-legal-cookies-banner__btn--primary';
        btnAccept.textContent = cfg.i18n.accept;
        actions.appendChild(btnAccept);

        var btnReject = document.createElement('button');
        btnReject.type = 'button';
        btnReject.className = 'cashback-legal-cookies-banner__btn cashback-legal-cookies-banner__btn--secondary';
        btnReject.textContent = cfg.i18n.reject;
        actions.appendChild(btnReject);

        if (cfg.policyUrl) {
            var linkDetails = document.createElement('a');
            linkDetails.className = 'cashback-legal-cookies-banner__link';
            linkDetails.href = cfg.policyUrl;
            linkDetails.target = '_blank';
            linkDetails.rel = 'noopener noreferrer';
            linkDetails.textContent = cfg.i18n.details;
            actions.appendChild(linkDetails);
        }

        inner.appendChild(actions);
        container.appendChild(inner);

        function handle(choice) {
            var rid = generateRequestId();
            persistChoice(choice, rid);
            container.classList.add('is-hidden');
            recordOnServer(choice, rid);
        }

        btnAccept.addEventListener('click', function () { handle('granted'); });
        btnReject.addEventListener('click', function () { handle('rejected'); });
    }

    function start() {
        if (!shouldShow()) {
            return;
        }
        var container = document.getElementById('cashback-legal-cookies-banner');
        if (!container) {
            return;
        }
        buildBanner(container);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
