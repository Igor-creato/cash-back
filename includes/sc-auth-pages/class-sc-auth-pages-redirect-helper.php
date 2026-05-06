<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_SC_Auth_Pages_Redirect_Helper
 *
 * Единый wrapper для wp_safe_redirect + exit. Содержит DI-seam через
 * статический callback — тесты регистрируют свой capture-обработчик и
 * подменяют side-effect.
 *
 * Существует чтобы изолировать наши тесты от других тестовых файлов в проекте,
 * которые могли declared конкурирующий мок wp_safe_redirect (например
 * LegalAuditTest::wp_safe_redirect → пишет в _cb_test_last_redirect).
 *
 * @since 1.3.0
 */
class Cashback_SC_Auth_Pages_Redirect_Helper {

    /**
     * Тестовый callable: function(string $url): void.
     * Если установлен — заменяет вызов wp_safe_redirect + exit.
     *
     * @var callable|null
     */
    public static $test_capture = null;

    /**
     * Permalink на /my-account/ — единая точка для всех handler'ов
     * (login redirect, register redirect, logged-in shortcode redirect, и др.).
     *
     * Применяет filter `sc_auth_pages_default_my_account_url` — extension point
     * для override на сайтах с кастомными my-account-страницами.
     */
    public static function get_my_account_url(): string {
        $url = function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : '';
        if ($url === '' && function_exists('home_url')) {
            $url = (string) home_url('/');
        }
        return (string) apply_filters('sc_auth_pages_default_my_account_url', $url);
    }

    /**
     * Безопасный редирект с авто-exit.
     */
    public static function send( string $url ): void {
        if (is_callable(self::$test_capture)) {
            call_user_func(self::$test_capture, $url);
            return;
        }
        if (!function_exists('wp_safe_redirect')) {
            return;
        }
        // phpcs:ignore WordPressVIPMinimum.Security.ExitAfterRedirect.NoExit -- exit под условием ниже; в тестах exit подавляется через CASHBACK_SC_AUTH_PAGES_NO_EXIT.
        wp_safe_redirect($url);
        if (!defined('CASHBACK_SC_AUTH_PAGES_NO_EXIT')) {
            exit;
        }
    }
}
