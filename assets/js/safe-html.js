/**
 * Cashback safe-html: обёртка над DOMPurify с fail-closed fallback.
 *
 * Вызывается из admin-claims.js, affiliate-frontend.js, cashback-history.js,
 * history-payout.js, support/user-support.js перед jQuery .html() вставкой
 * AJAX-ответов вида { data: { html: "..." } }.
 *
 * Группа 9 ADR: если DOMPurify не подгрузился (сбой CDN/enqueue) — возвращаем
 * пустую строку, а не dirty-passthrough. console.error сигнализирует в dev.
 */
(function (window) {
    'use strict';

    var ALLOWED_TAGS = [
        'div', 'span', 'strong', 'em', 'b', 'i', 'u', 'p', 'h3', 'h4',
        'a', 'br', 'hr', 'small', 'code', 'pre',
        'ul', 'ol', 'li', 'nav',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
        'textarea', 'button', 'input', 'label', 'select', 'option',
        'img'
    ];

    var ALLOWED_ATTR = [
        'class', 'style', 'href', 'target', 'rel', 'id', 'title',
        'type', 'rows', 'cols', 'maxlength', 'minlength',
        'placeholder', 'multiple', 'accept', 'name', 'disabled',
        'readonly', 'checked', 'selected', 'value',
        'colspan', 'rowspan', 'scope', 'headers',
        'role', 'aria-label', 'aria-hidden', 'aria-live',
        'aria-expanded', 'aria-controls', 'aria-describedby',
        'src', 'alt', 'width', 'height', 'loading'
    ];

    window.cashbackSafeHtml = function (dirty) {
        if (typeof window.DOMPurify !== 'undefined' && typeof window.DOMPurify.sanitize === 'function') {
            return window.DOMPurify.sanitize(dirty, {
                ALLOWED_TAGS: ALLOWED_TAGS,
                ALLOWED_ATTR: ALLOWED_ATTR,
                ALLOW_DATA_ATTR: true
            });
        }

        if (typeof window.console !== 'undefined' && typeof window.console.error === 'function') {
            window.console.error('[cashback] DOMPurify unavailable — safe-html returns empty string (fail-closed).');
        }

        return '';
    };
})(window);
