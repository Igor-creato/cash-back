/**
 * Активатор таба товара по query-параметру cb_tab.
 *
 * Стратегия поиска (по убыванию надёжности):
 *   0. Видимый Woodmart mobile accordion-title по data-accordion-index
 *      (.wd-accordion-title[data-accordion-index="{slug}"]). На viewport
 *      ≤ 1024px тема рендерит accordion вместо табов; click-handler
 *      привязан к .wd-accordion-title, а не к десктопному <a href="#tab-...">.
 *   1. Поиск таб-pane с реальным [cashback_promocodes] внутри
 *      (.cashback-promocodes → ближайший `[id^="tab-"]` → anchor по href).
 *   2. WC-стандарт по slug (li.{slug}_tab > a / a[href="#tab-{slug}"]).
 *   3. Серверный маркер [data-cb-coupons-tab] (на случай если был внедрён).
 *
 * Активация: только jQuery .trigger('click'). Ручную смену классов мы НЕ
 * делаем — Woodmart лениво инициализирует/настраивает содержимое таба через
 * полный click-flow; ручная подмена `active`/`wd-active` создаёт
 * inconsistent state (контент пустой + класс «залипает» при последующих
 * кликах пользователя).
 *
 * Retry с задержкой 700ms — потому что Woodmart's
 * singleProductTabsAccordion на $(document).ready() дёргает первый таб
 * обратно через `.find('.wd-nav a').first().trigger('click')`. Наш retry
 * перезаписывает их выбор.
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
        // 0. Woodmart mobile accordion: .wd-accordion-title[data-accordion-index="{slug}"].
        //    На viewport ≤ 1024px тема рендерит accordion вместо табов; click-handler
        //    Woodmart привязан к .wd-accordion-title, а не к <a href="#tab-...">.
        //    offsetParent !== null = элемент видим (не display:none, в DOM).
        var accordionTitle = document.querySelector(
            '.wd-accordion-title[data-accordion-index="' + escapeForSelector(slug) + '"]'
        );
        if (accordionTitle && accordionTitle.offsetParent !== null) {
            return accordionTitle;
        }

        // 1. По содержимому: ищем реальный [cashback_promocodes].
        var promocodesEl = document.querySelector('.cashback-promocodes');
        if (promocodesEl) {
            var pane = promocodesEl.closest('[id^="tab-"]');
            if (pane && pane.id && pane.id.indexOf('tab-title-') !== 0) {
                var byContent = document.querySelector(
                    'a[href="#' + escapeForSelector(pane.id) + '"]'
                );
                if (byContent) { return byContent; }
            }
        }

        // 2. WC-стандарт по slug.
        var byClass = document.querySelector(
            'li.' + escapeForSelector(slug) + '_tab > a, li.tab-' + escapeForSelector(slug) + ' > a'
        );
        if (byClass) { return byClass; }

        var byHref = document.querySelector('a[href="#tab-' + slug + '"]');
        if (byHref) { return byHref; }

        // 3. Маркер в title (если внедрён сервером).
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

        return null;
    }

    function activate() {
        var anchor = findTabAnchor(safeSlug);
        if (!anchor) { return false; }

        // jQuery .trigger('click') — Woodmart использует jQuery, и WC tabs
        // привязывает обработчик через jQuery .on(). Triggers полный
        // click-flow (все WC/Woodmart hooks отработают, content
        // отрендерится корректно). Native anchor.click() для большинства
        // тем тоже работает, но jQuery — гарантия.
        if (window.jQuery) {
            try { window.jQuery(anchor).trigger('click'); } catch (e) {}
        } else {
            try { anchor.click(); } catch (e) {}
        }
        return true;
    }

    function activateWithRetry() {
        var firstActivated = activate();
        // Skрол сразу к контейнеру табов (один раз, чтобы не дёргать страницу).
        var container = document.querySelector('.woocommerce-tabs, .wd-tabs, .wc-tabs-wrapper');
        if (container && typeof container.scrollIntoView === 'function') {
            container.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        // Retry: Woodmart на ready() дёргает первый таб через trigger('click'),
        // что отменяет наш выбор. Через 700ms делаем повторную активацию.
        // Если первая попытка не нашла таб (lazy-load JS) — это и будет первая.
        window.setTimeout(activate, 700);
        // Финальный retry для медленных устройств / отложенного JS темы.
        window.setTimeout(activate, 1800);
        return firstActivated;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', activateWithRetry);
    } else {
        activateWithRetry();
    }
})();
