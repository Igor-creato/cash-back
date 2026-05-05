/**
 * Активатор таба товара по query-параметру cb_tab.
 *
 * При наличии ?cb_tab=<slug> на странице товара:
 *   1. Ищет таб с маркером [data-cb-coupons-tab] (его сервер инжектит при
 *      обнаружении [cashback_promocodes] в content таба).
 *   2. Fallback на стандартные WC-классы: li.{slug}_tab > a.
 *   3. Ещё fallback на anchor href="#tab-{slug}".
 *   4. Native click() — jQuery-делегат WC tabs его поймает и активирует таб.
 *   5. scrollIntoView к контейнеру табов (smooth).
 *
 * Используется шорткодом [cashback_coupons_icons] для перехода со страницы
 * каталога на single-product с автоматически открытой вкладкой «Купоны».
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
        var byClass = document.querySelector(
            'li.' + escapeForSelector(slug) + '_tab > a, li.tab-' + escapeForSelector(slug) + ' > a'
        );
        if (byClass) { return byClass; }
        return document.querySelector('a[href="#tab-' + slug + '"]');
    }

    function activate() {
        var anchor = findTabAnchor(safeSlug);
        if (!anchor) { return; }
        try { anchor.click(); } catch (e) {}
        var container = anchor.closest('.woocommerce-tabs, .wd-tabs') || anchor;
        if (container && typeof container.scrollIntoView === 'function') {
            container.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', activate);
    } else {
        activate();
    }
})();
