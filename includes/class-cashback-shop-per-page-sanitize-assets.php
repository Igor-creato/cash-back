<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Shop_Per_Page_Sanitize_Assets
 *
 * Подключает frontend-JS, который при загрузке любой страницы проверяет
 * cookie `shop_per_page` и стирает его, если значение не из allowed-набора
 * 9/12/18/24 (admin-список WoodMart Theme Options).
 *
 * Назначение — однократно почистить stale cookies у пользователей, которым
 * раннее браузерное расширение через REST успело прописать `shop_per_page=5`.
 * После серверного REST-shield (см. includes/cashback-rest-per-page-shield.php)
 * утечка закрыта на стороне сервера, но cookies в браузерах уже выставлены.
 *
 * Загружается рано (priority=5 на wp_enqueue_scripts), in_footer=false,
 * без зависимостей — должен отработать ДО WoodMart-init, чтобы при первой
 * перезагрузке страницы серверный рендер уже не видел мусорное cookie.
 *
 * Защитные инварианты:
 *  - is_admin() bail — не нужен в админке.
 *  - cache-bust через cashback_asset_url() (?cv=<filemtime>) — обновление
 *    JS-логики гарантированно подхватывается, минуя Clearfy/WP-Rocket.
 *
 * @since 4.1.0
 */
final class Cashback_Shop_Per_Page_Sanitize_Assets {

    /** @var string */
    public const HANDLE = 'cashback-shop-per-page-sanitize';

    /** @var string */
    private const RELATIVE_PATH = 'assets/js/cashback-shop-per-page-sanitize.js';

    /**
     * Регистрация хука wp_enqueue_scripts на high priority.
     */
    public static function register(): void {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('wp_enqueue_scripts', array( self::class, 'enqueue' ), 5);
    }

    /**
     * Колбэк wp_enqueue_scripts. Регистрирует JS-санитизатор на frontend.
     */
    public static function enqueue(): void {
        if (function_exists('is_admin') && is_admin()) {
            return;
        }
        if (!function_exists('cashback_asset_url')) {
            return;
        }

        wp_enqueue_script(
            self::HANDLE,
            cashback_asset_url(self::RELATIVE_PATH),
            array(),
            // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version embedded via cashback_asset_url() ?cv=<filemtime>
            null,
            false
        );
    }
}
