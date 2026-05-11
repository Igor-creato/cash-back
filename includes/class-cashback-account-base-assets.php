<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Account_Base_Assets
 *
 * Регистрирует и подключает единый базовый CSS-файл личного кабинета
 * (`assets/css/cashback-account-base.css`) — один источник истины для
 * --cb-* дизайн-токенов и общего компонента .cashback-support-tabs.
 *
 * Все per-tab CSS-файлы (cashback-history.css, history-payout.css,
 * affiliate-frontend.css, admin-claims.css, user-support.css,
 * cashback-notifications.css, frontend.css) объявляют этот handle как
 * dependency через wp_enqueue_style($..., ['cashback-account-base']),
 * что гарантирует загрузку базы первой и устраняет дублирование
 * `:root {...}` в 6 CSS-файлах.
 *
 * Подключение — на `wp_enqueue_scripts` с приоритетом 5 (раньше, чем
 * per-tab enqueue с дефолтным приоритетом 10). Bail на admin/non-account
 * страницах: enqueue работает только когда `is_account_page()` true.
 *
 * Security: чистый CSS-enqueue hook без user-input, без AJAX, без
 * cookie/query-парсинга. URL берётся из статического относительного
 * пути через `cashback_asset_url()`, который добавляет ?cv=<filemtime>
 * для cache-bust (минует Clearfy-оптимизатор, который снимает ?ver=).
 *
 * @since 7.4.0
 */
final class Cashback_Account_Base_Assets {

    /** @var string */
    public const HANDLE = 'cashback-account-base';

    /** @var string */
    private const RELATIVE_PATH = 'assets/css/cashback-account-base.css';

    /**
     * Однократная регистрация хука. Идемпотентен: повторные вызовы
     * не приводят к дублированию обработчиков (WP add_action дедуплицирует
     * по callback signature).
     */
    public static function register(): void {
        add_action('wp_enqueue_scripts', array( self::class, 'enqueue_base_css' ), 5);
    }

    /**
     * Колбэк wp_enqueue_scripts. Подключает базовый CSS только на странице
     * «Мой аккаунт» для авторизованных пользователей.
     */
    public static function enqueue_base_css(): void {
        if (is_admin()) {
            return;
        }

        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return;
        }

        if (!function_exists('is_account_page') || !is_account_page()) {
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
