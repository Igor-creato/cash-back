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
     * Однократная регистрация хуков. Идемпотентен по логике WP (add_filter
     * и add_action дедуплицируют по callback signature).
     */
    public static function register(): void {
        add_filter('body_class', array( self::class, 'add_body_class' ));
        add_action('wp_enqueue_scripts', array( self::class, 'enqueue_css' ), 20);
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
