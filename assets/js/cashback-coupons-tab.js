/**
 * Активатор таба товара по query-параметру cb_tab.
 *
 * Стратегия (по убыванию надёжности):
 *   1. Поиск таб-pane с реальным [cashback_promocodes] внутри
 *      (.cashback-promocodes → ближайший `[id^="tab-"]` → anchor по href).
 *   2. Серверный маркер [data-cb-coupons-tab] в title таба.
 *   3. WC-стандарт: li.{slug}_tab > a / li.tab-{slug} > a.
 *   4. Fallback: a[href="#tab-{slug}"].
 *
 * Используется шорткодом [cashback_coupons_icons] для перехода со страницы
 * каталога на single-product с автоматически открытой вкладкой «Купоны».
 *
 * Активация: native click() — jQuery-делегат WC tabs его поймает; затем
 * scrollIntoView smooth до контейнера табов. retry с задержкой решает
 * race против отложенной jQuery-инициализации Woodmart.
 *
 * @since 7.5.0
 */
(function () {
    'use strict';

    var params = null;
    try {
        params = new URLSearchParams(window.location.search);
    } catch (e) {
        return;
    }
    var wanted = params && params.get ? params.get('cb_tab') : null;
    if (!wanted) {
        return;
    }
    var safeSlug = String(wanted).replace(/[^a-zA-Z0-9_\-]/g, '');
    if (!safeSlug) {
        return;
    }

    function escapeForSelector(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return value.replace(/([!"#$%&'()*+,./:;<=>?@\[\\\]^`{|}~])/g, '\\$1');
    }

    function findTabAnchor(slug) {
        // 1. Самый надёжный путь — найти таб-pane с фактическим
        // содержимым [cashback_promocodes], затем найти anchor по его id.
        var promocodesEl = document.querySelector('.cashback-promocodes');
        if (promocodesEl) {
            var pane = promocodesEl.closest('[id^="tab-"]');
            if (pane && pane.id) {
                var byContent = document.querySelector(
                    'a[href="#' + escapeForSelector(pane.id) + '"]'
                );
                if (byContent) { return byContent; }
            }
        }

        // 2. Серверный маркер в title таба.
        var marker = document.querySelector(
            '.wc-tabs [data-cb-coupons-tab], .wd-nav-tabs [data-cb-coupons-tab], .woocommerce-tabs [data-cb-coupons-tab]'
        );
        if (marker) {
            var li = marker.closest('li');
            if (li) {
                var a = li.querySelector('a');
                if (a) { return a; }
            }
        }

        // 3. WC-стандарт по slug.
        var byClass = document.querySelector(
            'li.' + escapeForSelector(slug) + '_tab > a, li.tab-' + escapeForSelector(slug) + ' > a'
        );
        if (byClass) { return byClass; }

        // 4. Anchor по href.
        return document.querySelector('a[href="#tab-' + slug + '"]');
    }

    function activate() {
        var anchor = findTabAnchor(safeSlug);
        if (!anchor) { return false; }

        // Native click — jQuery-делегат WC/Woodmart его поймает.
        try { anchor.click(); } catch (e) {}

        var container = anchor.closest('.woocommerce-tabs, .wd-tabs') || anchor;
        if (container && typeof container.scrollIntoView === 'function') {
            container.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        return true;
    }

    function activateWithRetry() {
        // Пробуем сразу.
        if (activate()) { return; }
        // Retry через 200ms — на случай, если jQuery WC tabs ещё не привязал
        // обработчик. И финальный retry через 800ms (медленные темы / lazy-load JS).
        window.setTimeout(function () {
            if (activate()) { return; }
            window.setTimeout(activate, 600);
        }, 200);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', activateWithRetry);
    } else {
        activateWithRetry();
    }
})();
