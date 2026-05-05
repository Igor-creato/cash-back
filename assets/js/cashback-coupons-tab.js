/**
 * Активатор таба товара по query-параметру cb_tab.
 *
 * Стратегия (по убыванию надёжности):
 *   1. Поиск таб-pane с реальным [cashback_promocodes] внутри
 *      (.cashback-promocodes → ближайший `[id^="tab-"]` → anchor по href).
 *   2. WC-стандарт по slug (li.{slug}_tab > a / a[href="#tab-{slug}"]).
 *   3. Серверный маркер [data-cb-coupons-tab] (на случай если был внедрён).
 *
 * Активация (multi-strategy, для совместимости с разными темами):
 *   а. native click() — стандартный WC handler привязан к ul.tabs > li > a.
 *   б. jQuery .trigger('click') — если jQuery доступен (Woodmart использует).
 *   в. Manual class-toggle: removeClass active/wd-active со всех табов и
 *      panes, addClass только нужным. Защита от случаев, когда click
 *      handler ещё не привязан (race с DOMContentLoaded).
 *
 * Retry с возрастающими задержками (200ms / 600ms / 1500ms) — на случай,
 * если WC/Woodmart JS инициализируется отложенно (lazy-load ассетов).
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

    /**
     * @returns {{anchor: HTMLElement, paneId: string} | null}
     */
    function findTab(slug) {
        // 1. По содержимому: ищем реальный [cashback_promocodes].
        var promocodesEl = document.querySelector('.cashback-promocodes');
        if (promocodesEl) {
            var pane = promocodesEl.closest('[id^="tab-"]');
            if (pane && pane.id && pane.id.indexOf('tab-title-') !== 0) {
                var byContent = document.querySelector(
                    'a[href="#' + escapeForSelector(pane.id) + '"]'
                );
                if (byContent) {
                    return { anchor: byContent, paneId: pane.id };
                }
            }
        }

        // 2. WC-стандарт по slug.
        var byClass = document.querySelector(
            'li.' + escapeForSelector(slug) + '_tab > a, li.tab-' + escapeForSelector(slug) + ' > a'
        );
        if (byClass && byClass.getAttribute('href')) {
            return { anchor: byClass, paneId: byClass.getAttribute('href').replace(/^#/, '') };
        }

        var byHref = document.querySelector('a[href="#tab-' + slug + '"]');
        if (byHref) {
            return { anchor: byHref, paneId: 'tab-' + slug };
        }

        // 3. Маркер в title (если внедрён сервером).
        var marker = document.querySelector(
            '.wc-tabs [data-cb-coupons-tab], .wd-nav-tabs [data-cb-coupons-tab], .woocommerce-tabs [data-cb-coupons-tab]'
        );
        if (marker) {
            var li = marker.closest('li');
            if (li) {
                var a = li.querySelector('a');
                if (a && a.getAttribute('href')) {
                    return { anchor: a, paneId: a.getAttribute('href').replace(/^#/, '') };
                }
            }
        }

        return null;
    }

    function manualActivate(anchor, paneId) {
        var tabsWrapper = anchor.closest('.wc-tabs-wrapper, .woocommerce-tabs');
        if (!tabsWrapper) { return; }

        // Все <li> в навигации tabs.
        var navLis = tabsWrapper.querySelectorAll(
            'ul.wc-tabs > li, ul.tabs > li, ul.wd-nav-tabs > li'
        );
        navLis.forEach(function (li) {
            li.classList.remove('active', 'wd-active');
        });

        var anchorLi = anchor.closest('li');
        if (anchorLi) {
            anchorLi.classList.add('active', 'wd-active');
        }

        // Все таб-панели (контентные, не titles).
        var panes = tabsWrapper.querySelectorAll('[id^="tab-"]');
        panes.forEach(function (pane) {
            if (pane.id && pane.id.indexOf('tab-title-') === 0) { return; }
            pane.style.display = 'none';
            pane.classList.remove('wd-active', 'wd-in', 'active');
        });

        var targetPane = document.getElementById(paneId);
        if (targetPane) {
            targetPane.style.display = '';
            targetPane.classList.add('wd-active', 'wd-in', 'active');
        }
    }

    function activate() {
        var found = findTab(safeSlug);
        if (!found) { return false; }

        var anchor = found.anchor;
        var paneId = found.paneId;

        // 1. Native click — стандартный WC handler.
        try { anchor.click(); } catch (e) {}

        // 2. jQuery trigger — если доступен (Woodmart использует).
        if (window.jQuery) {
            try { window.jQuery(anchor).trigger('click'); } catch (e) {}
        }

        // 3. Manual class-toggle как защита от не-привязанного handler'а.
        try { manualActivate(anchor, paneId); } catch (e) {}

        // Скролл к контейнеру табов.
        var container = anchor.closest('.woocommerce-tabs, .wd-tabs, .wc-tabs-wrapper') || anchor;
        if (container && typeof container.scrollIntoView === 'function') {
            container.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        return true;
    }

    function activateWithRetry() {
        if (activate()) {
            // Сделаем ещё одну попытку через 600ms — Woodmart's
            // singleProductTabsAccordion на ready дёргает первый таб обратно;
            // эта вторая попытка перезапишет его выбор.
            window.setTimeout(activate, 600);
            return;
        }
        window.setTimeout(function () {
            if (activate()) {
                window.setTimeout(activate, 600);
                return;
            }
            window.setTimeout(activate, 1500);
        }, 200);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', activateWithRetry);
    } else {
        activateWithRetry();
    }
})();
