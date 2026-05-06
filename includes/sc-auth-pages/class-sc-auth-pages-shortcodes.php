<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_SC_Auth_Pages_Shortcodes
 *
 * Регистрирует шорткоды:
 *   - [sc_login]    → форма входа (form-login.php)
 *   - [sc_register] → форма регистрации (form-register.php)
 *
 * Поведение:
 *   - Залогиненный пользователь, открывающий /login/ или /register/ через шорткод,
 *     перенаправляется на /my-account/ (фильтруемое значение).
 *   - Перед формой выводится `wc_print_notices()` — там видны notice'ы из login/register
 *     handlers и легальной/fraud-валидации.
 *
 * @since 1.3.0
 */
class Cashback_SC_Auth_Pages_Shortcodes {

    public const SHORTCODE_LOGIN    = 'sc_login';
    public const SHORTCODE_REGISTER = 'sc_register';

    /**
     * Регистрация шорткодов. Вызывается из bootstrap на init.
     */
    public static function register(): void {
        if (!function_exists('add_shortcode')) {
            return;
        }
        add_shortcode(self::SHORTCODE_LOGIN, array( __CLASS__, 'render_login' ));
        add_shortcode(self::SHORTCODE_REGISTER, array( __CLASS__, 'render_register' ));
    }

    /**
     * Рендер формы входа.
     *
     * @param array<string,string>|string $atts Не используются — оставлены для
     *                                          совместимости с shortcode-API.
     * @return string HTML.
     */
    public static function render_login( $atts = array() ): string {
        unset($atts);

        if (self::redirect_logged_in_user()) {
            return '';
        }

        ob_start();

        if (function_exists('wc_print_notices')) {
            wc_print_notices();
        }

        $redirect_to  = self::resolve_post_redirect_to();
        $register_url = self::resolve_register_url();

        include __DIR__ . '/templates/form-login.php';

        return (string) ob_get_clean();
    }

    /**
     * Рендер формы регистрации.
     *
     * @param array<string,string>|string $atts
     * @return string HTML.
     */
    public static function render_register( $atts = array() ): string {
        unset($atts);

        if (self::redirect_logged_in_user()) {
            return '';
        }

        ob_start();

        if (function_exists('wc_print_notices')) {
            wc_print_notices();
        }

        $login_url = self::resolve_login_url();

        include __DIR__ . '/templates/form-register.php';

        return (string) ob_get_clean();
    }

    /**
     * Залогиненного юзера перенаправляем на /my-account/. Возвращает true, если
     * редирект выполнен (вызывающий код должен вернуть пустую строку).
     */
    private static function redirect_logged_in_user(): bool {
        if (!is_user_logged_in()) {
            return false;
        }

        // НЕ редиректим в admin / REST / AJAX / preview / customize:
        // - Gutenberg рендерит блок [sc_login] через REST для preview — редирект
        //   ломает редактор (302 в block-renderer endpoint).
        // - В admin-list страниц шорткод не рендерится, но `is_admin()` гарантирует.
        // - Customize/preview даёт админу увидеть форму как guest.
        if (self::is_non_render_context()) {
            return false;
        }

        $target = self::default_logged_in_target();
        $target = (string) apply_filters('sc_auth_pages_logged_in_redirect', $target);

        if ($target === '') {
            return false;
        }

        $safe = function_exists('wp_validate_redirect')
            ? wp_validate_redirect($target, self::default_logged_in_target())
            : $target;

        Cashback_SC_Auth_Pages_Redirect_Helper::send($safe);

        return true;
    }

    /**
     * Контексты, в которых шорткод НЕ должен делать wp_safe_redirect (он сломает
     * Gutenberg block-renderer, customizer preview, AJAX renderer и т.п.).
     *
     * Также пропускаем редирект для пользователей с capability `edit_pages` —
     * админы и редакторы должны иметь возможность открыть /login/ и /register/
     * на frontend через «View Page» в admin-bar чтобы посмотреть верстку.
     */
    private static function is_non_render_context(): bool {
        if (function_exists('is_admin') && is_admin()) {
            return true;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return true;
        }
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return true;
        }
        if (function_exists('is_preview') && is_preview()) {
            return true;
        }
        if (function_exists('is_customize_preview') && is_customize_preview()) {
            return true;
        }
        if (function_exists('current_user_can') && current_user_can('edit_pages')) {
            return true;
        }
        return false;
    }

    /**
     * Дефолтный URL для залогиненного юзера — /my-account/.
     */
    private static function default_logged_in_target(): string {
        return Cashback_SC_Auth_Pages_Redirect_Helper::get_my_account_url();
    }

    /**
     * Восстанавливаем ?redirect_to=... после redirect-after-POST на форме логина.
     *
     * Возвращаемое значение прогоняется через wp_validate_redirect в login-handler'е.
     */
    private static function resolve_post_redirect_to(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- query-string-only read.
        if (!isset($_GET['redirect_to'])) {
            return '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- query-string-only read.
        $raw = sanitize_text_field(wp_unslash((string) $_GET['redirect_to']));
        return function_exists('esc_url_raw') ? esc_url_raw($raw) : $raw;
    }

    /**
     * Permalink на /register/ с graceful fallback.
     * Делегирует в Cashback_SC_Auth_Pages_Register::get_register_url() — у него
     * единый filter sc_auth_pages_register_url для extensibility.
     */
    private static function resolve_register_url(): string {
        if (class_exists('Cashback_SC_Auth_Pages_Register')) {
            return Cashback_SC_Auth_Pages_Register::get_register_url();
        }
        return function_exists('home_url') ? (string) home_url('/register/') : '/register/';
    }

    /**
     * Permalink на /login/ с graceful fallback.
     * Делегирует в Cashback_SC_Auth_Pages_Login::get_login_url() — у него
     * единый filter sc_auth_pages_login_url для extensibility.
     */
    private static function resolve_login_url(): string {
        if (class_exists('Cashback_SC_Auth_Pages_Login')) {
            return Cashback_SC_Auth_Pages_Login::get_login_url();
        }
        return function_exists('home_url') ? (string) home_url('/login/') : '/login/';
    }
}
