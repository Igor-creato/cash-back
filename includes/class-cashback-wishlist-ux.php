<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Wishlist_Ux
 *
 * На странице WoodMart-wishlist (ID хранится в Theme Options
 * `wishlist_page`) добавляет body-класс `cashback-wishlist-page` и
 * подключает узкий CSS, который на мобильном (<=768.98px) скрывает
 * сайдбар `.wd-my-account-sidebar` (заголовок «Мой аккаунт» + nav
 * `.woocommerce-MyAccount-navigation`).
 *
 * WoodMart рендерит standalone wishlist через Elementor My Account widget,
 * который наследует стандартный My Account dashboard layout с сайдбаром
 * слева. На мобильном сайдбар стекается над контентом и занимает почти
 * весь первый экран — содержимое wishlist уезжает ниже фолда. На desktop
 * сайдбар сбоку и не мешает, поэтому скрытие ограничено breakpoint'ом
 * WoodMart-`sm` (768.98px) — той же точкой, на которой sidebar grid
 * переходит из `--wd-col-md:4` в `--wd-col-sm:12`.
 *
 * Bail-условия (модуль no-op'ит):
 * - тема не WoodMart (нет `woodmart_get_opt()`);
 * - опция `wishlist_page` пустая или невалидная;
 * - запрос admin/REST/AJAX.
 */
final class Cashback_Wishlist_Ux {

    /** @var string */
    public const HANDLE = 'cashback-wishlist-ux';

    /** @var string */
    public const BODY_CLASS = 'cashback-wishlist-page';

    /** @var string */
    private const RELATIVE_PATH = 'assets/css/cashback-wishlist-ux.css';

    /**
     * Test-only emitter для перехвата вызовов header() и nocache_headers().
     * В PHP CLI `header()` no-op и `headers_list()` пустой, что мешает
     * functional-тестам. Прод-код ставит null → используется реальная PHP.
     *
     * Сигнатура: function(string $name, string $value): void — name без двоеточия,
     * value — то что пойдёт после двоеточия. Если null — используется
     * стандартный header() + nocache_headers().
     *
     * @var callable(string,string):void|null
     */
    private static $header_emitter_for_tests = null;

    /**
     * Test-only override для is_wishlist_page(). Прод-код держит null →
     * работает обычная проверка через is_page(woodmart_get_opt('wishlist_page')).
     * Тесты выставляют true/false НЕ определяя глобальные WP/WoodMart функции:
     * иначе ломается контракт сторонних тестов (LegalAuditTest полагается на
     * function_exists('woodmart_get_opt')===false).
     *
     * @var bool|null
     */
    private static ?bool $is_wishlist_page_override_for_tests = null;

    /**
     * Однократная регистрация хуков. Идемпотентен по логике WP (add_filter
     * и add_action дедуплицируют по callback signature).
     *
     * `template_redirect` с приоритетом 0 — раньше любых кэш-плагинов
     * (Clearfy и пр. обычно >= 1) и до отправки тела ответа: нужно, чтобы
     * `header()` ушёл в response до того, как WP начнёт рендер шаблона.
     */
    public static function register(): void {
        add_filter('body_class', array( self::class, 'add_body_class' ));
        add_action('wp_enqueue_scripts', array( self::class, 'enqueue_css' ), 20);
        add_action('template_redirect', array( self::class, 'send_no_store_headers' ), 0);
    }

    /**
     * Возвращает ID страницы wishlist из WoodMart Theme Options или 0,
     * если тема не активна / опция не задана.
     */
    private static function get_wishlist_page_id(): int {
        if (!function_exists('woodmart_get_opt')) {
            return 0;
        }
        return (int) woodmart_get_opt('wishlist_page');
    }

    /**
     * True, если текущий запрос — frontend-просмотр страницы wishlist.
     */
    private static function is_wishlist_page(): bool {
        if (self::$is_wishlist_page_override_for_tests !== null) {
            return self::$is_wishlist_page_override_for_tests;
        }
        if (!function_exists('is_page')) {
            return false;
        }
        $id = self::get_wishlist_page_id();
        return $id > 0 && is_page($id);
    }

    /**
     * Колбэк фильтра body_class. Добавляет маркер `cashback-wishlist-page`
     * только на странице wishlist.
     *
     * @param array<int,string> $classes
     * @return array<int,string>
     */
    public static function add_body_class( array $classes ): array {
        if (self::is_wishlist_page()) {
            $classes[] = self::BODY_CLASS;
        }
        return $classes;
    }

    /**
     * Колбэк template_redirect (priority 0).
     *
     * Отправляет `Cache-Control: no-store, no-cache, must-revalidate,
     * private, max-age=0` на странице WoodMart wishlist, что закрывает
     * сразу три слоя кэширования:
     *
     *  1. nginx fastcgi_cache — по умолчанию НЕ кэширует ответ с
     *     `Cache-Control: no-store/private` от backend (поведение nginx
     *     fastcgi_ignore_headers, не переопределено в deploy-cashback).
     *     Без этого фикса /izbrannye-magaziny/ кэшировалась 30 минут,
     *     причём ключ `$scheme$method$host$uri` без cookie — все гости
     *     получали один и тот же HTML с чужим wishlist (lightweight
     *     privacy-leak + симптом «удалил, а товары остались»).
     *
     *  2. HTTP-кэш браузера на мобильных устройствах (Chrome Mobile /
     *     Яндекс.Браузер): без явного no-store браузер кэширует ответ
     *     эвристически.
     *
     *  3. bfcache (back-forward cache) в Chromium-браузерах: при возврате
     *     через «Назад» / клик по «Избранное» в шапке браузер показывает
     *     prerendered копию страницы без перевыполнения JS. Cache-Control
     *     no-store строго отключает bfcache в Chrome/Yandex (Chromium).
     *
     * Bail-условия:
     *  - заголовки уже отправлены (защита от warning);
     *  - страница не wishlist (`is_wishlist_page()` уже проверяет тему +
     *    page_id из woodmart_get_opt('wishlist_page'), что устойчиво к
     *    смене slug страницы).
     *
     * `template_redirect` сам по себе фронтенд-хук и не срабатывает в
     * admin/REST/AJAX, поэтому отдельных guards `is_admin()` /
     * `defined('REST_REQUEST')` / `wp_doing_ajax()` не нужно.
     */
    public static function send_no_store_headers(): void {
        if (!self::is_wishlist_page()) {
            return;
        }

        // Test-mode: маршрутизация через emitter, чтобы PHPUnit мог проверить
        // отправляемые заголовки (в PHP CLI native header() недоступен).
        if (self::$header_emitter_for_tests !== null) {
            $emitter = self::$header_emitter_for_tests;
            // nocache_headers() WP отправляет Expires + Cache-Control no-cache/no-store/private.
            // Эмулируем тот же набор в тесте.
            $emitter('Expires', 'Wed, 11 Jan 1984 05:00:00 GMT');
            $emitter('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
            return;
        }

        // Прод-режим: bail если заголовки уже отправлены (другой плагин
        // напечатал output) — иначе header() выдаст warning.
        if (headers_sent()) {
            return;
        }

        // WP-стандарт: Expires + Cache-Control no-cache/no-store/private/max-age=0.
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        // Defense-in-depth: явный no-store + private. Некоторые сторонние
        // фильтры (`nocache_headers`, `wp_headers`) могут пересоздать
        // Cache-Control без `no-store`. Replace=true гарантирует поверх них.
        header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0', true);
    }

    /**
     * Только для unit-тестов: подменить header-emitter на callback,
     * который тест сможет интроспектировать. Прод-код этим setter'ом
     * не пользуется.
     *
     * @internal
     * @param callable(string,string):void|null $emitter null = вернуть прод-поведение.
     */
    public static function set_header_emitter_for_tests( ?callable $emitter ): void {
        self::$header_emitter_for_tests = $emitter;
    }

    /**
     * Только для unit-тестов: подменить результат is_wishlist_page().
     * Прод-код ставит null = вернуть проверку через `is_page()`.
     *
     * @internal
     */
    public static function set_is_wishlist_page_override_for_tests( ?bool $value ): void {
        self::$is_wishlist_page_override_for_tests = $value;
    }

    /**
     * Колбэк wp_enqueue_scripts. Подключает CSS-правило только на странице
     * wishlist.
     */
    public static function enqueue_css(): void {
        if (!self::is_wishlist_page()) {
            return;
        }

        if (!function_exists('cashback_asset_url')) {
            return;
        }

        wp_enqueue_style(
            self::HANDLE,
            cashback_asset_url(self::RELATIVE_PATH),
            array(),
            // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version embedded via cashback_asset_url() ?cv=<filemtime>
            null
        );
    }
}
