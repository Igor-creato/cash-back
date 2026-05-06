<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_SC_Auth_Pages_Header_Replacer
 *
 * Глобальная замена ссылок «Вход» / «Регистрация» на имя юзера для тех мест,
 * где наш wp_get_nav_menu_items-фильтр не срабатывает: WoodMart Header Builder
 * Text/HTML elements (статические `.wd-header-text` блоки), кастомные виджеты,
 * sidebar-ы и т. п.
 *
 * Работает в два шага:
 *  1. На wp_head выводит inline CSS с :has() селектором — скрывает .wd-header-text
 *     wrapper'ы со ссылками /login/ и /register/ для залогиненного юзера. Это
 *     устраняет FOUC: блоки скрыты до того, как JS успеет отработать.
 *  2. На wp_footer выводит inline JS — удаляет скрытые wrapper'ы из DOM и
 *     инжектит новый элемент с именем юзера и ссылкой на /my-account/.
 *
 * Filter:
 *  - sc_auth_pages_header_replacer_enabled (bool, default true)
 *  - sc_auth_pages_menu_user_label (общий с menu-filter, не дублируем код)
 *  - sc_auth_pages_menu_user_url   (общий с menu-filter)
 *
 * @since 1.3.0
 */
class Cashback_SC_Auth_Pages_Header_Replacer {

    /**
     * Регистрация хуков. Вызывается из Bootstrap.
     */
    public static function register(): void {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('wp_head', array( __CLASS__, 'print_styles' ), 100);
        add_action('wp_footer', array( __CLASS__, 'print_script' ), 100);
    }

    /**
     * Inline CSS — скрывает wrapper'ы .wd-header-text со ссылками /login/ /register/.
     */
    public static function print_styles(): void {
        if (!self::should_run()) {
            return;
        }

        $login_url    = self::get_login_url();
        $register_url = self::get_register_url();

        if ($login_url === '' && $register_url === '') {
            return;
        }

        $rules = array();
        // :has() работает во всех современных браузерах (Chromium 105+, Safari 15.4+, FF 121+).
        // Старые браузеры просто не скроют wrapper до JS — graceful FOUC.
        foreach (array( $login_url, $register_url ) as $url) {
            if ($url === '') {
                continue;
            }
            // Покрываем оба варианта URL: с trailing slash и без.
            $variants = array( $url, rtrim($url, '/') );
            $variants = array_unique($variants);
            foreach ($variants as $u) {
                // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction -- значение встраивается в CSS-селектор `a[href="..."]`, не в HTML href; esc_attr корректен для CSS attribute-value.
                $css_safe = esc_attr($u);
                $rules[]  = '.wd-header-text:has(a[href="' . $css_safe . '"])';
                $rules[]  = '.wd-header-text:has(> a[href="' . $css_safe . '"])';
            }
        }

        if ($rules === array()) {
            return;
        }

        $selector = implode(',', array_unique($rules));
        echo '<style id="sc-auth-pages-header-replacer-css">';
        echo $selector . '{display:none!important;}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selectors built from esc_attr URLs.
        echo '</style>';
    }

    /**
     * Inline JS — удаляет wrapper'ы и вставляет user-пункт.
     */
    public static function print_script(): void {
        if (!self::should_run()) {
            return;
        }

        $login_url    = self::get_login_url();
        $register_url = self::get_register_url();

        if ($login_url === '' && $register_url === '') {
            return;
        }

        $user  = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $label = '';
        if (is_object($user)) {
            $display = (string) ($user->display_name ?? '');
            $label   = $display !== '' ? $display : (string) ($user->user_login ?? '');
        }
        $label = (string) apply_filters('sc_auth_pages_menu_user_label', $label, $user);
        if ($label === '') {
            return;
        }

        $my_account = class_exists('Cashback_SC_Auth_Pages_Redirect_Helper')
            ? Cashback_SC_Auth_Pages_Redirect_Helper::get_my_account_url()
            : (function_exists('home_url') ? (string) home_url('/my-account/') : '');
        $my_account = (string) apply_filters('sc_auth_pages_menu_user_url', $my_account, $user);
        if ($my_account === '') {
            return;
        }

        // URL-варианты для сравнения (с/без trailing slash).
        $patterns = array_values(array_unique(array_filter(array(
            $login_url,
            rtrim($login_url, '/'),
            $register_url,
            rtrim($register_url, '/'),
        ))));

        $payload = wp_json_encode(array(
            'urls'      => $patterns,
            'name'      => $label,
            'myAccount' => $my_account,
        ));

        if (!is_string($payload)) {
            return;
        }

        ?>
        <script id="sc-auth-pages-header-replacer-js">
        (function () {
            var data;
            try { data = <?php echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode эскейпит. ?>; } catch (e) { return; }
            if (!data || !Array.isArray(data.urls) || !data.urls.length) return;

            function normalize(href) {
                if (!href) return '';
                var i = href.indexOf('#'); if (i >= 0) href = href.substring(0, i);
                i = href.indexOf('?'); if (i >= 0) href = href.substring(0, i);
                return href.replace(/\/+$/, '');
            }
            var targets = data.urls.map(normalize);

            function isMatch(href) {
                var n = normalize(href);
                return n !== '' && targets.indexOf(n) !== -1;
            }

            var inserted = false;
            var anchors = document.querySelectorAll('a[href]');
            for (var i = 0; i < anchors.length; i++) {
                var a = anchors[i];
                if (!isMatch(a.getAttribute('href'))) continue;
                var wrapper = a.closest('.wd-header-text') || a.parentElement;
                if (!wrapper) continue;
                if (!inserted) {
                    var newA = document.createElement('a');
                    newA.href = data.myAccount;
                    newA.textContent = data.name;
                    var strong = document.createElement('strong');
                    strong.appendChild(newA);
                    wrapper.innerHTML = '';
                    wrapper.appendChild(strong);
                    wrapper.classList.add('sc-auth-pages-user-wrapper');
                    wrapper.style.display = '';
                    inserted = true;
                } else {
                    wrapper.parentNode && wrapper.parentNode.removeChild(wrapper);
                }
            }
        })();
        </script>
        <?php
    }

    /**
     * Должен ли модуль вообще работать на этом запросе?
     */
    private static function should_run(): bool {
        if (function_exists('is_admin') && is_admin()) {
            return false;
        }
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return false;
        }
        return (bool) apply_filters('sc_auth_pages_header_replacer_enabled', true);
    }

    private static function get_login_url(): string {
        if (class_exists('Cashback_SC_Auth_Pages_Login')) {
            return (string) Cashback_SC_Auth_Pages_Login::get_login_url();
        }
        return '';
    }

    private static function get_register_url(): string {
        if (class_exists('Cashback_SC_Auth_Pages_Register')) {
            return (string) Cashback_SC_Auth_Pages_Register::get_register_url();
        }
        return '';
    }
}
