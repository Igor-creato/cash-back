<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Frontend_Performance
 *
 * Делает рендер-неблокирующим CSS-ассеты плагина, которые не нужны для
 * первого экрана: cookie-баннер показывается JS после чтения localStorage,
 * виджет CAPTCHA создаётся динамически только для подозрительных
 * пользователей. Без этого фильтра оба стиля грузились в `<head>` как
 * обычные блокирующие <link rel="stylesheet">, прибавляя 2 раунд-трипа
 * к Critical Rendering Path на каждой странице сайта.
 *
 * Паттерн преобразования: <link rel="preload" as="style"
 *   onload="this.onload=null;this.rel='stylesheet'"> + <noscript>-fallback.
 *
 * Для cookie-баннера дополнительно инлайнится единственное критичное
 * правило (`.is-hidden { display:none }`), чтобы между удалением `is-hidden`
 * в JS и применением полного CSS не было FOUC. Остальные стили (position
 * fixed, padding, кнопки) не критичны для первого экрана — они применяются
 * к контейнеру, который изначально невидим.
 *
 * @since 1.3.0
 */
class Cashback_Frontend_Performance {

    /**
     * Handle'ы CSS-ассетов плагина, которые НЕ нужны для первого экрана.
     *
     * @var string[]
     */
    private const NON_BLOCKING_HANDLES = array(
        'cashback-legal-cookies-banner',
        'cashback-bot-protection',
    );

    /**
     * Handle'ы JS-ассетов, безопасные для атрибута `defer`.
     *
     * Defer = parser-blocking download removed: HTML-парсинг идёт непрерывно,
     * скрипт скачивается параллельно и исполняется после DOMContentLoaded
     * в порядке появления тегов. Подходит для:
     *  - Cashback-плагин: собственные скрипты, все навешиваются на jQuery
     *    `(document).ready()` или DOMContentLoaded — нет inline-call'ов до
     *    DOM-готовности.
     *  - WC analytics/tracking: чисто аналитика, не блокирует UX.
     *
     * Allowlist (vs denylist) выбран намеренно: безопаснее консервативно
     * добавлять handle сюда, чем случайно сломать сторонний плагин с
     * inline-скриптом, депендящимся на синхронной загрузке другого. Сторонним
     * темам / плагинам можно расширить allowlist через filter
     * `cashback_defer_js_handles`.
     *
     * НЕ defer'им: jquery, jquery-migrate, woodmart-theme-init, любые
     * скрипты с inline-зависимостями до DOMContentLoaded.
     *
     * @var string[]
     */
    private const DEFER_JS_HANDLES = array(
        // Cashback-плагин (полный контроль).
        'cashback-legal-cookies-banner',
        'cashback-bot-protection',
        'cashback-coupons-icons',
        'cashback-contact-form',
        'cashback-consent-validate',
        'cashback-legal-my-account-toggle',
        'cashback-legal-registration-validate',
        'wc-affiliate-url-params',
        // WooCommerce analytics (DOM-ready, без UX-зависимости).
        'sourcebuster-js',
        'wc-order-attribution',
        // Elementor — frontend крутится на DOMContentLoaded; webpack runtime
        // и frontend-modules идут как зависимости, defer сохраняет порядок.
        'elementor-webpack-runtime',
        'elementor-frontend-modules',
        'elementor-frontend',
        // WoodMart — non-init utility-скрипты для shop-страницы / mobile UX.
        // НЕ покрываем: woodmart-theme (helpers.min.js — главный init,
        // зависимости плагинов читают window.wd* инлайном),
        // wd-elementor-integration (вызывается Elementor'ом).
        'wd-products-load-more',
        'wd-ajax-filters',
        'wd-shop-page-init',
        'wd-mobile-navigation',
        'wd-hidden-sidebar',
    );

    /**
     * Подключение хуков. Идемпотентно — повторный вызов не создаёт дублей
     * (WP сам дедуплицирует add_filter / add_action по callable).
     */
    public static function init(): void {
        if (!function_exists('add_filter')) {
            return;
        }
        add_filter('style_loader_tag', array( __CLASS__, 'defer_non_critical_css' ), 10, 2);
        add_filter('script_loader_tag', array( __CLASS__, 'defer_non_critical_js' ), 10, 2);
        add_action('wp_head', array( __CLASS__, 'inline_critical_cookies_banner_rule' ), 1);
    }

    /**
     * Преобразует <link rel="stylesheet"> для неблокирующих handle'ов в
     * <link rel="preload" as="style"> с onload-swap и <noscript>-fallback.
     *
     * Возвращает $tag без изменений, если handle не в allowlist'е, или если
     * тег уже содержит rel="preload" (защита от двойного применения), или
     * если входной тег не является ожидаемым `<link rel='stylesheet'`).
     *
     * @param string $tag    Исходный HTML-тег от wp_styles.
     * @param string $handle Handle стиля.
     * @return string
     */
    public static function defer_non_critical_css( string $tag, string $handle ): string {
        if (!in_array($handle, self::NON_BLOCKING_HANDLES, true)) {
            return $tag;
        }
        // Защита от повторного применения / неожиданных шаблонов тега.
        if (false === stripos($tag, 'rel=')) {
            return $tag;
        }
        if (false !== stripos($tag, 'rel="preload"') || false !== stripos($tag, "rel='preload'")) {
            return $tag;
        }

        // WP wp_get_styles по умолчанию рендерит атрибуты в одинарных кавычках.
        // Новые атрибуты всегда в двойных — позволяет внутри onload использовать
        // одинарные без эскейпинга (`this.rel='stylesheet'`).
        $preload_tag = preg_replace(
            '/(<link\s[^>]*?)rel=("|\')stylesheet\2/i',
            '$1rel="preload" as="style" onload="this.onload=null;this.rel=\'stylesheet\'"',
            $tag,
            1,
            $count
        );

        if (null === $preload_tag || 0 === $count) {
            return $tag;
        }

        // <noscript>-fallback: переиспользуем исходный $tag (rel='stylesheet').
        // Это даёт идентичный fallback браузерам с отключённым JS без необходимости
        // обратного преобразования preload-тега.
        return $preload_tag . '<noscript>' . $tag . '</noscript>';
    }

    /**
     * Добавляет атрибут `defer` к non-critical JS-handle'ам из DEFER_JS_HANDLES
     * (расширяется фильтром `cashback_defer_js_handles`).
     *
     * Защиты от двойного применения / поломок:
     *  - Inline-script (без `src=`) — defer бессмысленен, не трогаем.
     *  - Уже defer/async — пропускаем (filter повторно срабатывает в
     *    некоторых сценариях preview/customizer).
     *
     * @param string $tag    Исходный <script>-тег от wp_print_scripts.
     * @param string $handle Handle скрипта.
     * @return string
     */
    public static function defer_non_critical_js( string $tag, string $handle ): string {
        $allowed = apply_filters('cashback_defer_js_handles', self::DEFER_JS_HANDLES);
        if (!is_array($allowed) || !in_array($handle, $allowed, true)) {
            return $tag;
        }

        // Inline-script (нет src=) — defer бессмыслен, defer-attribute
        // на inline'ах в HTML5 spec игнорируется, но валидаторы ругаются.
        if (false === stripos($tag, ' src=')) {
            return $tag;
        }

        // Уже defer / async — двойное применение запрещено spec'ом и
        // визуально шумит в DevTools.
        if (preg_match('/<script\b[^>]*\s(?:defer|async)\b/i', $tag)) {
            return $tag;
        }

        // Добавляем defer сразу после `<script`. Регэксп ловит и `<script src=...`,
        // и теоретическое `<script>` (но src-guard выше не пустит сюда последний).
        $new_tag = preg_replace('/<script(\s|>)/', '<script defer$1', $tag, 1);
        return null === $new_tag ? $tag : $new_tag;
    }

    /**
     * Инлайнит критичное правило `.is-hidden { display:none }` для
     * cookie-баннера в `<head>` (priority 1 — раньше любого CSS-enqueue).
     *
     * Без этого: при non-blocking CSS контейнер `<div class="...is-hidden">`
     * мог бы кратковременно показаться (style.display=block по умолчанию)
     * до подгрузки cookies-banner.css. JS убирает класс is-hidden только
     * когда баннер должен показаться — поэтому критично, чтобы эта
     * единственная rule была доступна сразу.
     */
    public static function inline_critical_cookies_banner_rule(): void {
        echo '<style id="cashback-frontend-perf-critical">'
            . '.cashback-legal-cookies-banner.is-hidden{display:none}'
            . '</style>';
    }
}
