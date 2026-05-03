/**
 * Cashback promocodes shortcode runtime.
 *
 * - Copy-to-clipboard для кнопки [data-action="copy"].
 * - Click-tracking AJAX для copy/goto через cashback-rest-api или admin-ajax.
 * - Безопасный рендер description через window.cashbackSafeHtml (DOMPurify).
 */
(function () {
    'use strict';

    var config = window.cashbackPromocodesConfig || {};

    // Безопасный рендер description (DOMPurify через safe-html обёртку Группы 9).
    document.querySelectorAll('[data-cashback-safe-html]').forEach(function (node) {
        var raw = node.getAttribute('data-cashback-safe-html') || '';
        node.removeAttribute('data-cashback-safe-html');
        if (typeof window.cashbackSafeHtml === 'function') {
            node.innerHTML = window.cashbackSafeHtml(raw);
        } else {
            node.textContent = raw; // fail-closed: если safe-html не загрузился, plain text.
        }
    });

    // Click-tracking AJAX (без блокировки UX).
    function trackClick(promoId, action) {
        if (!config.ajaxUrl || !promoId) {
            return;
        }
        try {
            var body = new URLSearchParams();
            body.set('action', 'cashback_promocode_click');
            body.set('promocode_id', String(promoId));
            body.set('promo_action', action);
            body.set('_wpnonce', config.nonce || '');

            fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: body.toString(),
                keepalive: true
            }).catch(function () { /* swallow — не валим UX из-за tracking-фейла */ });
        } catch (e) {
            // Старые браузеры без URLSearchParams/fetch — silent fallback.
        }
    }

    // Copy buttons.
    document.querySelectorAll('.cashback-promo-card__btn--copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var code   = btn.getAttribute('data-clipboard') || '';
            var promoId = btn.getAttribute('data-promo-id') || '';

            var done = function () {
                btn.classList.add('is-copied');
                var orig = btn.textContent;
                btn.textContent = '✓ Скопировано';
                setTimeout(function () {
                    btn.classList.remove('is-copied');
                    btn.textContent = orig;
                }, 1500);
                trackClick(promoId, 'copy');
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code).then(done).catch(function () {
                    // Fallback для не-secure контекста.
                    fallbackCopy(code);
                    done();
                });
            } else {
                fallbackCopy(code);
                done();
            }
        });
    });

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) { /* ignore */ }
        document.body.removeChild(ta);
    }

    // Goto-кнопка ведёт на серверный redirect-handler /?cashback_promo_click={id}
    // (Cashback_Promocodes_Redirect), который сам пишет в cashback_click_log /
    // cashback_click_sessions / cashback_promocode_clicks. JS-учёт здесь больше
    // не нужен — это убирает гонку «AJAX vs навигация» полностью.
}());
