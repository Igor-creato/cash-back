/**
 * Активатор таба товара по query-параметру cb_tab.
 *
 * Стратегия поиска (по убыванию надёжности):
 *   1. Поиск таб-pane с реальным [cashback_promocodes] → реальный key из
 *      data-accordion-index (или id="tab-{key}") → resolveByKey(): сначала
 *      видимый Woodmart accordion-title (.wd-accordion-title[data-...="{key}"])
 *      на mobile, иначе desktop <a href="#tab-{key}">.
 *   2. По slug из URL (resolveByKey(slug)) — fallback если pane не найден.
 *   3. WC-стандарт по slug-классу (li.{slug}_tab > a) — для старых тем.
 *   4. Серверный маркер [data-cb-coupons-tab] (mobile accordion-title или <li>).
 *
 * Реальный key таба может НЕ совпадать с URL slug ("coupons"): WoodMart
 * использует транслит русского title (e.g. «Промокоды» → "promokody") или
 * кастомный slug Custom Tab CPT. Поэтому primary-стратегия — определить key
 * по DOM-pane, а не по URL.
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

    function isVisible(el) {
        return el && el.offsetParent !== null;
    }

    // По key таба находим кликабельный элемент: видимый mobile accordion-title
    // приоритетнее, иначе desktop <a>.
    function resolveByKey(key) {
        if (!key) { return null; }
        var safeKey = escapeForSelector(key);

        var accordionTitle = document.querySelector(
            '.wd-accordion-title[data-accordion-index="' + safeKey + '"]'
        );
        if (isVisible(accordionTitle)) {
            return accordionTitle;
        }

        var anchor = document.querySelector('a[href="#tab-' + safeKey + '"]');
        if (anchor) { return anchor; }

        // Desktop tab может быть скрыт CSS на mobile, но если accordion-title
        // нет (другая разметка темы) — вернём accordion-title даже скрытый.
        if (accordionTitle) { return accordionTitle; }

        return null;
    }

    function findTabAnchor(slug) {
        // 1. По содержимому: ищем реальный [cashback_promocodes] и его pane.
        //    pane имеет id="tab-{key}" и data-accordion-index="{key}";
        //    реальный {key} ≠ slug из URL когда тема использует
        //    транслитерированный/кастомный ключ (напр. «Промокоды» → "promokody"
        //    вместо "coupons").
        var promocodesEl = document.querySelector('.cashback-promocodes');
        if (promocodesEl) {
            var pane = promocodesEl.closest('[data-accordion-index]')
                || promocodesEl.closest('[id^="tab-"]');
            if (pane) {
                var realKey = pane.getAttribute('data-accordion-index');
                if (!realKey && pane.id
                    && pane.id.indexOf('tab-') === 0
                    && pane.id.indexOf('tab-title-') !== 0
                ) {
                    realKey = pane.id.substring(4); // strip "tab-"
                }
                var byContent = resolveByKey(realKey);
                if (byContent) { return byContent; }
            }
        }

        // 2. По slug из URL (legacy / fallback если pane не найден).
        var bySlug = resolveByKey(slug);
        if (bySlug) { return bySlug; }

        // 3. WC-стандарт по slug-классу (старые темы).
        var byClass = document.querySelector(
            'li.' + escapeForSelector(slug) + '_tab > a, li.tab-' + escapeForSelector(slug) + ' > a'
        );
        if (byClass) { return byClass; }

        // 4. Маркер в title (если внедрён сервером): mobile accordion-title
        //    приоритетнее, иначе <li> на desktop.
        var marker = document.querySelector('[data-cb-coupons-tab]');
        if (marker) {
            var accordionMarker = marker.closest('.wd-accordion-title');
            if (isVisible(accordionMarker)) { return accordionMarker; }
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
