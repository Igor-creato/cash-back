<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Registration Gate в Cashback_SC_Auth_Pages_Shortcodes::render_register (GET /register/).
 *
 * Покрывает:
 *  - users_can_register=0 → render_register не содержит <form>, содержит ссылку «Войти»
 *  - users_can_register=1 → render_register содержит <form>
 */
#[Group('sc-auth-pages')]
#[Group('registration-gate')]
final class SCAuthPagesShortcodeRegisterDisabledTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        if (!defined('CASHBACK_SC_AUTH_PAGES_NO_EXIT')) {
            define('CASHBACK_SC_AUTH_PAGES_NO_EXIT', true);
        }

        if (!function_exists('wp_validate_redirect')) {
            function wp_validate_redirect( string $location, string $default = '' ): string
            {
                return $location !== '' ? $location : $default;
            }
        }
        if (!function_exists('wc_get_page_permalink')) {
            function wc_get_page_permalink( string $page ): string
            {
                return $GLOBALS['_cb_test_wc_page_permalinks'][ $page ] ?? '';
            }
        }
        if (!function_exists('wc_lostpassword_url')) {
            function wc_lostpassword_url(): string
            {
                return 'http://localhost/my-account/lost-password/';
            }
        }
        if (!function_exists('wp_lostpassword_url')) {
            function wp_lostpassword_url( string $redirect = '' ): string
            {
                return 'http://localhost/wp-login.php?action=lostpassword';
            }
        }
        if (!function_exists('home_url')) {
            function home_url( string $path = '/' ): string
            {
                return rtrim('http://localhost', '/') . '/' . ltrim($path, '/');
            }
        }
        if (!function_exists('get_permalink')) {
            function get_permalink( int $post_id ): string
            {
                return $GLOBALS['_cb_test_permalinks'][ $post_id ] ?? 'http://localhost/?p=' . $post_id;
            }
        }
        if (!function_exists('add_shortcode')) {
            function add_shortcode( string $tag, callable $callback ): bool
            {
                $GLOBALS['_cb_test_shortcodes'][ $tag ] = $callback;
                return true;
            }
        }
        if (!function_exists('wc_print_notices')) {
            function wc_print_notices(): void
            {
                $GLOBALS['_cb_test_wc_notices_printed'] = true;
            }
        }
        if (!function_exists('esc_url_raw')) {
            function esc_url_raw( string $url ): string
            {
                return $url;
            }
        }
        if (!function_exists('esc_url')) {
            function esc_url( string $url ): string
            {
                return $url;
            }
        }
        if (!function_exists('esc_html_e')) {
            function esc_html_e( string $text, string $domain = 'default' ): void
            {
                echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }
        if (!function_exists('has_action')) {
            function has_action( string $hook_name, $callback = false )
            {
                $filters = $GLOBALS['_cb_test_filters'][ $hook_name ] ?? array();
                return !empty($filters);
            }
        }
        if (!function_exists('esc_attr_e')) {
            function esc_attr_e( string $text, string $domain = 'default' ): void
            {
                echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }

        require_once $plugin_root . '/includes/auth/class-cashback-registration-gate.php';
        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-activator.php';
        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-redirect-helper.php';
        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-login.php';
        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-register.php';
        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-shortcodes.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']            = array();
        $GLOBALS['_cb_test_filters']            = array();
        $GLOBALS['_cb_test_is_logged_in']       = false;
        $GLOBALS['_cb_test_wc_page_permalinks'] = array(
            'myaccount' => 'http://localhost/my-account/',
        );
        $GLOBALS['_cb_test_permalinks'] = array(
            200 => 'http://localhost/register/',
            100 => 'http://localhost/login/',
        );
        update_option(Cashback_SC_Auth_Pages_Activator::OPTION_LOGIN_PAGE_ID, 100);
        update_option(Cashback_SC_Auth_Pages_Activator::OPTION_REGISTER_PAGE_ID, 200);
        // Override URL'ов через фильтры — устраняет race с чужими get_permalink
        // моками из других suite'ов, которые при run в full-suite могут вернуть
        // 'http://localhost/?p=ID' вместо ожидаемых slug'ов.
        add_filter('sc_auth_pages_login_url', static fn() => 'http://localhost/login/');
        add_filter('sc_auth_pages_register_url', static fn() => 'http://localhost/register/');
        add_filter('sc_auth_pages_default_my_account_url', static fn() => 'http://localhost/my-account/');
        $_GET = array();
        $_POST = array();
    }

    public function test_disabled_render_does_not_contain_form_and_shows_login_button(): void
    {
        update_option('users_can_register', 0);

        $html = Cashback_SC_Auth_Pages_Shortcodes::render_register();

        $this->assertStringNotContainsString('<form', $html, 'форма регистрации должна быть скрыта');
        $this->assertStringNotContainsString('name="email"', $html);
        $this->assertStringContainsString('Регистрация', $html, 'заголовок остаётся');
        $this->assertStringContainsString('Войти', $html, 'кнопка «Войти» отображается');
        $this->assertStringContainsString('http://localhost/login/', $html, 'ссылка на /login/');
    }

    public function test_enabled_render_contains_form(): void
    {
        update_option('users_can_register', 1);

        $html = Cashback_SC_Auth_Pages_Shortcodes::render_register();

        $this->assertStringContainsString('<form', $html, 'регистрация разрешена → форма рендерится');
    }
}
