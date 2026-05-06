<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_SC_Auth_Pages_Bootstrap
 *
 * Точка инициализации модуля «отдельные страницы /login/ и /register/».
 *
 * Регистрирует:
 *   - shortcode'ы [sc_login] и [sc_register]
 *   - POST-handler'ы login + register на template_redirect prio 5
 *   - guest-redirector /my-account/ → /login/ на template_redirect prio 1
 *   - enqueue CSS только на наших страницах
 *
 * @since 1.3.0
 */
class Cashback_SC_Auth_Pages_Bootstrap {

    private static bool $booted = false;

    /**
     * Точка входа. Вызывается из cashback-plugin.php → initialize_components().
     */
    public static function init(): void {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        if (!function_exists('add_action')) {
            return;
        }

        add_action('init', array( 'Cashback_SC_Auth_Pages_Shortcodes', 'register' ));

        // template_redirect: prio 1 — самый ранний gate (guest /my-account/ → /login/),
        // prio 5 — POST-handler'ы. Между ними другие плагины могут добавиться.
        add_action('template_redirect', array( 'Cashback_SC_Auth_Pages_Redirector', 'maybe_redirect' ), 1);
        add_action('template_redirect', array( 'Cashback_SC_Auth_Pages_Login', 'maybe_handle' ), 5);
        add_action('template_redirect', array( 'Cashback_SC_Auth_Pages_Register', 'maybe_handle' ), 5);

        add_action('wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ));

        // Меню: для авторизованных юзеров заменяем «Вход / Регистрация» на имя.
        if (class_exists('Cashback_SC_Auth_Pages_Menu_Filter')) {
            add_filter('wp_get_nav_menu_items', array( 'Cashback_SC_Auth_Pages_Menu_Filter', 'filter_items' ), 10, 3);
        }

        // wp_loaded — после plugins_loaded и init: к этому моменту social-auth
        // уже зарегистрировал свои callback'и на woocommerce_(login|register)_form_end.
        // Перенесём кнопки социальной авторизации на наш собственный хук в начало
        // формы (sc_auth_pages_(login|register)_form_top) — отключаемо через filter.
        add_action('wp_loaded', array( __CLASS__, 'relocate_social_auth_buttons' ), 20);
    }

    /**
     * Перенос social-auth кнопок из конца формы в начало (после заголовка).
     *
     * Отключается через filter sc_auth_pages_relocate_social_auth = false —
     * тогда кнопки остаются внизу через стандартный woocommerce_(login|register)_form_end.
     */
    public static function relocate_social_auth_buttons(): void {
        if (!(bool) apply_filters('sc_auth_pages_relocate_social_auth', true)) {
            return;
        }

        if (!class_exists('Cashback_Social_Auth_Renderer')) {
            return;
        }

        $renderer = Cashback_Social_Auth_Renderer::instance();

        if (method_exists($renderer, 'print_login_buttons')) {
            remove_action('woocommerce_login_form_end', array( $renderer, 'print_login_buttons' ), 15);
            add_action('sc_auth_pages_login_form_top', array( $renderer, 'print_login_buttons' ));
        }

        if (method_exists($renderer, 'print_register_buttons')) {
            remove_action('woocommerce_register_form_end', array( $renderer, 'print_register_buttons' ), 15);
            add_action('sc_auth_pages_register_form_top', array( $renderer, 'print_register_buttons' ));
        }
    }

    /**
     * Подключаем CSS только когда зашли на /login/ или /register/.
     */
    public static function enqueue_assets(): void {
        if (!self::is_module_page()) {
            return;
        }

        $plugin_root_file = dirname(__DIR__, 2) . '/cashback-plugin.php';
        $css_path         = dirname(__DIR__, 2) . '/assets/css/sc-auth-pages.css';
        $css_url          = plugins_url('assets/css/sc-auth-pages.css', $plugin_root_file);
        $ver              = file_exists($css_path) ? (string) filemtime($css_path) : '1.0.0';

        wp_enqueue_style('sc-auth-pages', $css_url, array(), $ver);
    }

    /**
     * Текущий запрос — наша login или register страница?
     */
    private static function is_module_page(): bool {
        if (!function_exists('is_page')) {
            return false;
        }
        $login_id    = (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_LOGIN_PAGE_ID, 0);
        $register_id = (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_REGISTER_PAGE_ID, 0);

        if ($login_id > 0 && is_page($login_id)) {
            return true;
        }
        if ($register_id > 0 && is_page($register_id)) {
            return true;
        }
        return false;
    }
}
