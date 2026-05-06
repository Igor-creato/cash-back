<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты шорткодов [sc_login] и [sc_register].
 *
 * Покрывает:
 *  - guest на login: рендерится <form>, есть nonce, есть полю log/pwd
 *  - guest на register: рендерится <form>, есть email/password/password_confirm,
 *    срабатывает do_action('woocommerce_register_form')
 *  - logged-in на любую — редирект на /my-account/ (HTML пустой)
 *  - filter sc_auth_pages_logged_in_redirect меняет destination
 *  - на login форма содержит ссылку «Забыли пароль» на wc_lostpassword_url
 */
#[Group('sc-auth-pages')]
#[Group('sc-auth-pages-shortcodes')]
final class SCAuthPagesShortcodesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        // Подавляем exit() в шорткоде — тест проверит редирект через DI-seam.
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
        if (!function_exists('esc_attr_e')) {
            function esc_attr_e( string $text, string $domain = 'default' ): void
            {
                echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }

        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-activator.php';
        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-redirect-helper.php';
        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-login.php';
        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-register.php';
        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-shortcodes.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']                = array();
        $GLOBALS['_cb_test_filters']                = array();
        $GLOBALS['_cb_test_actions_fired']          = array();
        $GLOBALS['_cb_test_shortcodes']             = array();
        $GLOBALS['_cb_test_redirects']              = array();
        Cashback_SC_Auth_Pages_Redirect_Helper::$test_capture = static function ( string $url ): void {
            $GLOBALS['_cb_test_redirects'][] = array( 'url' => $url );
        };
        add_filter('sc_auth_pages_login_url', static fn() => 'http://localhost/login/');
        add_filter('sc_auth_pages_register_url', static fn() => 'http://localhost/register/');
        add_filter('sc_auth_pages_default_my_account_url', static fn() => 'http://localhost/my-account/');
        $GLOBALS['_cb_test_wc_page_permalinks']     = array(
            'myaccount' => 'http://localhost/my-account/',
        );
        $GLOBALS['_cb_test_permalinks']             = array(
            100 => 'http://localhost/login/',
            200 => 'http://localhost/register/',
        );
        $GLOBALS['_cb_test_is_logged_in']           = false;
        // Default: рядовой юзер (не админ); тесты на admin-bypass guard ставят true.
        $GLOBALS['_cb_test_current_user_can']       = false;
        $GLOBALS['_cb_test_wc_notices_printed']     = false;
        $_GET                                       = array();
        $_POST                                      = array();

        update_option(Cashback_SC_Auth_Pages_Activator::OPTION_LOGIN_PAGE_ID, 100);
        update_option(Cashback_SC_Auth_Pages_Activator::OPTION_REGISTER_PAGE_ID, 200);
    }

    protected function tearDown(): void
    {
        Cashback_SC_Auth_Pages_Redirect_Helper::$test_capture = null;
        parent::tearDown();
    }

    public function test_register_does_not_throw(): void
    {
        // Регистрация шорткодов — тривиальная цепочка add_shortcode вызовов.
        // Other test files в test-suite уже могут declared add_shortcode по-своему,
        // поэтому проверяем только что метод не падает — функциональность render
        // покрывается отдельными тестами ниже.
        Cashback_SC_Auth_Pages_Shortcodes::register();
        $this->assertSame('sc_login', Cashback_SC_Auth_Pages_Shortcodes::SHORTCODE_LOGIN);
        $this->assertSame('sc_register', Cashback_SC_Auth_Pages_Shortcodes::SHORTCODE_REGISTER);
    }

    public function test_render_login_for_guest_outputs_form_with_required_fields(): void
    {
        $html = Cashback_SC_Auth_Pages_Shortcodes::render_login();

        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('name="log"', $html);
        $this->assertStringContainsString('name="pwd"', $html);
        $this->assertStringContainsString('name="rememberme"', $html);
        $this->assertStringContainsString('_sc_auth_nonce', $html);
        $this->assertStringContainsString('http://localhost/my-account/lost-password/', $html);
        $this->assertStringContainsString('http://localhost/register/', $html);
        $this->assertTrue($GLOBALS['_cb_test_wc_notices_printed'], 'wc_print_notices должен вызваться');
    }

    public function test_render_register_for_guest_outputs_form_and_fires_woocommerce_register_form_hook(): void
    {
        $html = Cashback_SC_Auth_Pages_Shortcodes::render_register();

        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString('name="password_confirm"', $html);
        $this->assertStringContainsString('name="email_2"', $html, 'honeypot должен быть в форме');
        $this->assertStringContainsString('_sc_auth_nonce', $html);
        $this->assertStringContainsString('http://localhost/login/', $html);

        // Проверяем что do_action('woocommerce_register_form') действительно стрельнул.
        $hooks_fired = array_column($GLOBALS['_cb_test_actions_fired'], 'hook');
        $this->assertContains(
            'woocommerce_register_form',
            $hooks_fired,
            'Хук woocommerce_register_form должен сработать (для legal/fraud consent)'
        );
        $this->assertContains('woocommerce_register_form_start', $hooks_fired);
        $this->assertContains('woocommerce_register_form_end', $hooks_fired);
    }

    public function test_render_login_for_logged_in_user_redirects_to_my_account(): void
    {
        $GLOBALS['_cb_test_is_logged_in'] = true;

        $html = Cashback_SC_Auth_Pages_Shortcodes::render_login();

        $this->assertSame('', $html);
        $this->assertCount(1, $GLOBALS['_cb_test_redirects']);
        $this->assertSame(
            'http://localhost/my-account/',
            $GLOBALS['_cb_test_redirects'][0]['url']
        );
    }

    public function test_render_register_for_logged_in_user_redirects(): void
    {
        $GLOBALS['_cb_test_is_logged_in'] = true;

        $html = Cashback_SC_Auth_Pages_Shortcodes::render_register();

        $this->assertSame('', $html);
        $this->assertCount(1, $GLOBALS['_cb_test_redirects']);
    }

    public function test_filter_overrides_logged_in_redirect_target(): void
    {
        $GLOBALS['_cb_test_is_logged_in'] = true;
        add_filter('sc_auth_pages_logged_in_redirect', static fn() => 'http://localhost/dashboard/');

        Cashback_SC_Auth_Pages_Shortcodes::render_login();

        $this->assertSame(
            'http://localhost/dashboard/',
            $GLOBALS['_cb_test_redirects'][0]['url']
        );
    }

    public function test_admin_user_with_edit_pages_capability_sees_form_instead_of_redirect(): void
    {
        // Залогиненный админ открывает /login/ через «View Page» в admin-bar:
        // должен увидеть форму, не редирект (иначе не может проверить верстку).
        $GLOBALS['_cb_test_is_logged_in']     = true;
        $GLOBALS['_cb_test_current_user_can'] = true;

        $html = Cashback_SC_Auth_Pages_Shortcodes::render_login();

        $this->assertStringContainsString('<form', $html, 'Админ должен увидеть форму');
        $this->assertCount(0, $GLOBALS['_cb_test_redirects'], 'Не должно быть редиректа для админа');
    }

    public function test_rest_request_does_not_redirect_logged_in_user(): void
    {
        // Эмулируем REST-context (Gutenberg block-renderer): редирект убил бы редактор.
        $GLOBALS['_cb_test_is_logged_in']     = true;
        $GLOBALS['_cb_test_current_user_can'] = false;

        if (!defined('REST_REQUEST')) {
            define('REST_REQUEST', true);
        }

        $html = Cashback_SC_Auth_Pages_Shortcodes::render_login();

        $this->assertStringContainsString('<form', $html);
        $this->assertCount(0, $GLOBALS['_cb_test_redirects']);
    }

    public function test_login_form_carries_redirect_to_from_query(): void
    {
        $_GET['redirect_to'] = 'http://localhost/account-edit/';

        $html = Cashback_SC_Auth_Pages_Shortcodes::render_login();

        $this->assertStringContainsString(
            'value="http://localhost/account-edit/"',
            $html,
            'redirect_to должен попасть в hidden-input'
        );
    }
}
