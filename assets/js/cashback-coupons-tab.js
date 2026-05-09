/**
 * Активатор таба товара по query-параметру cb_tab.
 *
 * Базовый сценарий: при ?cb_tab=coupons сервер уже отрисовывает страницу
 * с активным табом «Промокоды» — Cashback_Promocodes_Bootstrap через
 * filter woocommerce_product_tabs ставит priority=-100, WoodMart-template
 * (single-product/tabs/tabs.php) выставляет wd-active на первый таб.
 * В этом случае JS — no-op (idempotency-guard isAlreadyActive).
 *
 * Fallback-сценарии (когда server-side preactivate не сработал):
 *   - accordion_state='all' / 'closed' (тема настроена иначе) — server
 *     не выставит wd-active, JS активирует на DOMContentLoaded.
 *   - сторонний плагин ломает priority-сортировку — JS активирует.
 *   - WoodMart-JS singleProductTabsAccordion отменяет наш таб — ловит
 *     MutationObserver на .wd-nav, переактивирует один раз и disconnect.
 *   - lazy-init темы (Elementor wd_single_product_tabs) — MutationObserver
 *     срабатывает как только DOM табов появляется.
 *
 * Стратегия поиска anchor (по убыванию надёжности):
 *   1. Поиск таб-pane с реальным [cashback_promocodes] → реальный key из
 *      data-accordion-index (или id="tab-{key}") → resolveByKey().
 *   2. По slug из URL (resolveByKey(slug)) — fallback.
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
 * полный click-flow.
 *
 * @since 7.6.0
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

    // Проверка «таб уже активен» — защита от toggle при повторных вызовах.
    // Woodmart accordion и WC tabs трактуют click по активной вкладке как
    // «закрыть/переключить»: повторный click на уже открытый промокод-таб
    // схлопнет его обратно. Поэтому MutationObserver-callback должен
    // активировать ТОЛЬКО если таб реально неактивен.
    function isAlreadyActive(el) {
        if (!el || !el.classList) { return false; }
        // Mobile accordion-title: класс wd-active.
        if (el.classList.contains('wd-active')) { return true; }
        // Desktop <a>: активность хранится на родителе <li>.
        var parent = el.parentElement;
        if (parent && parent.classList) {
            if (parent.classList.contains('active')
                || parent.classList.contains('wd-active')
            ) {
                return true;
            }
        }
        return false;
    }

    // Финальный фиксап inconsistent state в WoodMart accordion: class wd-active
    // на title и content уже есть, но content.style.display="none" остался от
    // initial state (WoodMart accordion init проигнорировал наш wd-active или
    // не успел сделать slideDown). Без этого title визуально выделен, но
    // содержимое таба collapsed. Применяется ТОЛЬКО для accordion-title (mobile);
    // desktop <a> такой проблемы не имеет.
    function ensureContentExpanded(anchor) {
        if (!anchor || !anchor.classList || !anchor.getAttribute) { return; }
        if (!anchor.classList.contains('wd-accordion-title')) { return; }
        var key = anchor.getAttribute('data-accordion-index');
        if (!key) { return; }
        var safeKey = escapeForSelector(key);
        var content = document.querySelector(
            '.wd-accordion-content[data-accordion-index="' + safeKey + '"]'
        );
        if (!content) { return; }
        // Inconsistent state: class active, но display:none — принудительно открываем.
        if (anchor.classList.contains('wd-active')
            && content.classList.contains('wd-active')
            && content.style.display === 'none'
        ) {
            content.style.display = 'block';
        }
    }

    function activate(anchor) {
        if (!anchor) { return false; }
        if (isAlreadyActive(anchor)) {
            ensureContentExpanded(anchor);
            return true;
        }

        // Подавляем нативный default action клика по anchor:
        // <a href="#tab-promokody"> при срабатывании default action делает
        // navigation к fragment'у — браузер мгновенно прыгает к элементу
        // с id="tab-promokody". preventDefault на capture phase отменяет
        // default, не блокируя bubble-handlers WC/Woodmart, которые
        // активируют tab-pane.
        var suppressDefault = function (e) {
            e.preventDefault();
        };
        anchor.addEventListener('click', suppressDefault, true);

        try {
            if (window.jQuery) {
                window.jQuery(anchor).trigger('click');
            } else {
                anchor.click();
            }
        } catch (e) {}

        anchor.removeEventListener('click', suppressDefault, true);
        ensureContentExpanded(anchor);
        return true;
    }

    function instantScrollToTabs() {
        var container = document.querySelector('.woocommerce-tabs, .wd-tabs, .wc-tabs-wrapper');
        if (container && typeof container.scrollIntoView === 'function') {
            // behavior:'auto' — мгновенный скролл. Пользователь только
            // что загрузил страницу, плавность не воспринимается, а
            // лишняя анимация добавляет визуальную задержку.
            container.scrollIntoView({ behavior: 'auto', block: 'start' });
        }
    }

    function run() {
        // Шаг 1: проверяем серверную пре-активацию. Если сервер уже
        // отрендерил наш таб первым и WoodMart-template поставил
        // wd-active — activate() сделает no-op (isAlreadyActive guard).
        // Если нет (accordion_state≠'first', сторонний плагин и т.п.) —
        // активируем сейчас.
        var anchor = findTabAnchor(safeSlug);
        activate(anchor);

        // Шаг 2: мгновенный скролл к табам — высота контейнера табов
        // на DOMContentLoaded уже стабильна (заголовок таба + sticky-nav).
        instantScrollToTabs();

        // Шаг 3: one-shot retry через requestAnimationFrame. На мобильных
        // WoodMart accordion-handler (themes/woodmart/js/scripts/elements/
        // accordion.js) может attached позже DOMContentLoaded — на следующем
        // frame повторно findTabAnchor()+activate(). Idempotent через
        // isAlreadyActive() guard в activate().
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(function () {
                var retryAnchor = findTabAnchor(safeSlug);
                if (retryAnchor && !isAlreadyActive(retryAnchor)) {
                    activate(retryAnchor);
                }
            });
        }

        // Шаг 4: polling retry с интервалом 80ms × 5 попыток (400ms total).
        // Покрывает случай когда WoodMart accordion init или его reset на
        // первый таб случается между нашим DOMContentLoaded handler'ом и
        // полностью «settled» состоянием. Идемпотентно через
        // isAlreadyActive() guard — лишних кликов не будет, как только
        // наш таб станет активным, polling ничего не делает.
        var pollAttempts = 0;
        var pollMax = 5;
        function pollActivate() {
            pollAttempts++;
            var pollAnchor = findTabAnchor(safeSlug);
            if (pollAnchor) {
                if (!isAlreadyActive(pollAnchor)) {
                    activate(pollAnchor);
                } else {
                    // Title уже active — но content может быть в inconsistent
                    // state (class wd-active, но style.display:none). Это
                    // happens когда WoodMart accordion init runs ПОСЛЕ нашего
                    // initial click и оставляет display:none на не-первом табе.
                    ensureContentExpanded(pollAnchor);
                }
            }
            if (pollAttempts < pollMax) {
                window.setTimeout(pollActivate, 80);
            }
        }
        window.setTimeout(pollActivate, 80);

        // Шаг 5: late retry — last-resort backstop когда документ полностью
        // загрузился. Если наш скрипт runs after window.load (footer-script
        // на быстрой mobile-странице), addEventListener('load') бесполезен —
        // event уже выстрелил. В этом случае делаем setTimeout-retry на
        // следующем tick. Иначе вешаем 'load' listener.
        function lateActivate() {
            var lateAnchor = findTabAnchor(safeSlug);
            if (lateAnchor && !isAlreadyActive(lateAnchor)) {
                activate(lateAnchor);
                instantScrollToTabs();
            }
        }
        if (document.readyState === 'complete') {
            window.setTimeout(lateActivate, 0);
        } else if (window.addEventListener) {
            window.addEventListener('load', lateActivate, false);
        }

        // Шаг 5: safety-net через MutationObserver. WoodMart's
        // singleProductTabsAccordion на $(document).ready может выстрелить
        // ПОСЛЕ нашего DOMContentLoaded и кликнуть .wd-nav a:first.
        // Если сервер не пре-активировал наш таб — WoodMart активирует
        // первый (не наш). Observer ловит class-mutation на .wd-nav,
        // переактивирует наш таб один раз, disconnect.
        // .wd-accordion — mobile-only DOM-узел WoodMart; без него на
        // мобильных observer не находил target и тихо отключался.
        var observerTarget = document.querySelector('.wc-tabs-wrapper, .woocommerce-tabs, .wd-tabs, .wd-accordion');
        if (!observerTarget || typeof window.MutationObserver !== 'function') {
            return;
        }

        var observer = new window.MutationObserver(function () {
            var current = findTabAnchor(safeSlug);
            if (!current) { return; }
            if (isAlreadyActive(current)) {
                observer.disconnect();
                return;
            }
            activate(current);
            // Намеренно НЕ disconnect здесь: если WoodMart accordion-handler
            // ещё не был attached, наш click ушёл в пустоту, и наш таб
            // не стал активным. Оставляем observer слушать дальнейшие
            // class-mutations (включая ту, что выстрелит, когда WoodMart
            // активирует первый таб) — на каждой mutation повторяем
            // activate(). Hard-disconnect через 3000ms всё равно остановит
            // observer чтобы не было утечки.
        });

        observer.observe(observerTarget, {
            attributes: true,
            subtree: true,
            attributeFilter: ['class'],
        });

        // Hard-disconnect через 3000ms — leak-prevent для случаев когда
        // WoodMart-JS никогда не выстреливает (отключенный JS темы).
        window.setTimeout(function () {
            observer.disconnect();
        }, 3000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
