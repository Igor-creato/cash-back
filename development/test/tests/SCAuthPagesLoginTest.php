<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты обработчика POST /login/ → wp_signon.
 *
 * Покрывает:
 *  - GET → не обрабатывается
 *  - POST с неверным sc_auth_action → не обрабатывается
 *  - POST без nonce → notice "сессия истекла" + redirect /login/
 *  - POST с пустыми полями → notice "заполните поля"
 *  - POST с invalid creds → wp_signon WP_Error → generic notice + redirect
 *  - POST с rate-limit miss (5+ попыток) → notice "слишком много попыток"
 *  - POST с valid creds → success, wp_safe_redirect на /my-account/
 *  - POST с redirect_to из формы → wp_validate_redirect используется
 *  - filter sc_auth_pages_login_redirect меняет цель
 *  - rate-limit очищается после успешного login
 *  - banned-юзер (WP_Error code cashback_user_banned) → специальное сообщение
 */
#[Group('sc-auth-pages')]
#[Group('sc-auth-pages-login')]
final class SCAuthPagesLoginTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        if (!defined('CASHBACK_SC_AUTH_PAGES_NO_EXIT')) {
            define('CASHBACK_SC_AUTH_PAGES_NO_EXIT', true);
        }
        if (!defined('CASHBACK_SC_AUTH_PAGES_TEST_BYPASS_PAGE_CHECK')) {
            define('CASHBACK_SC_AUTH_PAGES_TEST_BYPASS_PAGE_CHECK', true);
        }
        if (!defined('MINUTE_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
        }

        if (!function_exists('wp_validate_redirect')) {
            function wp_validate_redirect( string $location, string $default = '' ): string
            {
                if (!str_starts_with($location, 'http://localhost') && !str_starts_with($location, '/')) {
                    return $default;
                }
                return $location !== '' ? $location : $default;
            }
        }
        if (!function_exists('home_url')) {
            function home_url( string $path = '/' ): string
            {
                return rtrim('http://localhost', '/') . '/' . ltrim($path, '/');
            }
        }
        if (!function_exists('wc_get_page_permalink')) {
            function wc_get_page_permalink( string $page ): string
            {
                return $GLOBALS['_cb_test_wc_page_permalinks'][ $page ] ?? '';
            }
        }
        if (!function_exists('get_permalink')) {
            function get_permalink( int $post_id ): string
            {
                return $GLOBALS['_cb_test_permalinks'][ $post_id ] ?? '';
            }
        }
        if (!function_exists('is_page')) {
            function is_page( $page = '' ): bool
            {
                return (bool) ($GLOBALS['_cb_test_is_login_page'] ?? false);
            }
        }
        if (!function_exists('is_ssl')) {
            function is_ssl(): bool
            {
                return false;
            }
        }
        if (!function_exists('sanitize_user')) {
            function sanitize_user( string $user_login, bool $strict = false ): string
            {
                return trim($user_login);
            }
        }
        if (!function_exists('wc_add_notice')) {
            function wc_add_notice( string $message, string $notice_type = 'success', array $data = array() ): void
            {
                $GLOBALS['_cb_test_wc_notices'][] = array( 'message' => $message, 'type' => $notice_type );
            }
        }
        if (!function_exists('esc_url_raw')) {
            function esc_url_raw( string $url ): string
            {
                return $url;
            }
        }
        if (!function_exists('wp_signon')) {
            function wp_signon( array $credentials, $secure_cookie = '' )
            {
                $GLOBALS['_cb_test_wp_signon_calls'][] = $credentials;
                return $GLOBALS['_cb_test_wp_signon_result'] ?? new WP_Error('test', 'no result mocked');
            }
        }

        if (!class_exists('WP_User')) {
            // Минимальный мок WP_User
            // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- тестовая обёртка.
            eval('class WP_User { public int $ID = 0; public string $user_login = ""; public function __construct(int $id = 0, string $login = "") { $this->ID = $id; $this->user_login = $login; } }');
        }

        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-activator.php';
        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-redirect-helper.php';
        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-login.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']            = array();
        $GLOBALS['_cb_test_filters']            = array();
        $GLOBALS['_cb_test_actions_fired']      = array();
        $GLOBALS['_cb_test_redirects']          = array();
        $GLOBALS['_cb_test_wc_notices']         = array();
        $GLOBALS['_cb_test_wp_signon_calls']    = array();
        $GLOBALS['_cb_test_wp_signon_result']   = null;
        $GLOBALS['_cb_test_transients']         = array();
        $GLOBALS['_cb_test_wc_page_permalinks'] = array(
            'myaccount' => 'http://localhost/my-account/',
        );
        $GLOBALS['_cb_test_permalinks']         = array(
            100 => 'http://localhost/login/',
        );
        $GLOBALS['_cb_test_is_login_page']      = true;
        $_POST                                  = array();
        $_GET                                   = array();
        $_SERVER                                = array(
            'REMOTE_ADDR'    => '203.0.113.10',
            'REQUEST_METHOD' => 'POST',
        );

        update_option(Cashback_SC_Auth_Pages_Activator::OPTION_LOGIN_PAGE_ID, 100);

        Cashback_SC_Auth_Pages_Redirect_Helper::$test_capture = static function ( string $url ): void {
            $GLOBALS['_cb_test_redirects'][] = array( 'url' => $url );
        };
        // Гарантируем стабильный login-URL независимо от чужих моков get_permalink.
        add_filter('sc_auth_pages_login_url', static fn() => 'http://localhost/login/');
        add_filter('sc_auth_pages_default_my_account_url', static fn() => 'http://localhost/my-account/');
    }

    protected function tearDown(): void
    {
        Cashback_SC_Auth_Pages_Redirect_Helper::$test_capture = null;
        parent::tearDown();
    }

    public function test_get_request_does_not_handle(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Cashback_SC_Auth_Pages_Login::maybe_handle();

        $this->assertCount(0, $GLOBALS['_cb_test_wp_signon_calls']);
        $this->assertCount(0, $GLOBALS['_cb_test_redirects']);
    }

    public function test_post_without_action_marker_skipped(): void
    {
        $_POST = array( '_sc_auth_nonce' => 'nonce', 'log' => 'a', 'pwd' => 'b' );
        Cashback_SC_Auth_Pages_Login::maybe_handle();

        $this->assertCount(0, $GLOBALS['_cb_test_wp_signon_calls']);
    }

    public function test_post_with_invalid_nonce_redirects_back_with_notice(): void
    {
        $_POST = array(
            'sc_auth_action' => 'login',
            '_sc_auth_nonce' => '', // пусто → wp_verify_nonce вернёт false (но мок возвращает 1; обходим через специальный override).
            'log'            => 'user',
            'pwd'            => 'pass',
        );
        Cashback_SC_Auth_Pages_Login::maybe_handle();

        $this->assertCount(1, $GLOBALS['_cb_test_redirects']);
        $this->assertSame('http://localhost/login/', $GLOBALS['_cb_test_redirects'][0]['url']);
        $this->assertCount(1, $GLOBALS['_cb_test_wc_notices']);
        $this->assertSame('error', $GLOBALS['_cb_test_wc_notices'][0]['type']);
        $this->assertCount(0, $GLOBALS['_cb_test_wp_signon_calls'], 'wp_signon не должен вызываться');
    }

    public function test_post_with_empty_credentials_shows_notice(): void
    {
        $_POST = array(
            'sc_auth_action' => 'login',
            '_sc_auth_nonce' => 'valid',
            'log'            => '',
            'pwd'            => '',
        );
        Cashback_SC_Auth_Pages_Login::maybe_handle();

        $this->assertCount(1, $GLOBALS['_cb_test_wc_notices']);
        $this->assertCount(0, $GLOBALS['_cb_test_wp_signon_calls']);
    }

    public function test_post_with_invalid_credentials_shows_generic_notice_and_increments_rate_limit(): void
    {
        $_POST = array(
            'sc_auth_action' => 'login',
            '_sc_auth_nonce' => 'valid',
            'log'            => 'wrong@example.com',
            'pwd'            => 'badpass',
        );
        $GLOBALS['_cb_test_wp_signon_result'] = new WP_Error('incorrect_password', 'WC raw msg');

        Cashback_SC_Auth_Pages_Login::maybe_handle();

        $this->assertCount(1, $GLOBALS['_cb_test_wp_signon_calls']);
        $this->assertCount(1, $GLOBALS['_cb_test_wc_notices']);
        $this->assertSame(
            'Неверный логин или пароль.',
            $GLOBALS['_cb_test_wc_notices'][0]['message'],
            'Сообщение должно быть generic (anti user-enumeration)'
        );
        $this->assertCount(1, $GLOBALS['_cb_test_redirects']);

        // Rate-limit инкрементировался.
        $key = Cashback_SC_Auth_Pages_Login::RATE_LIMIT_PREFIX . md5('203.0.113.10');
        $this->assertSame(1, (int) get_transient($key));
    }

    public function test_banned_user_gets_specialized_message(): void
    {
        $_POST = array(
            'sc_auth_action' => 'login',
            '_sc_auth_nonce' => 'valid',
            'log'            => 'banned@example.com',
            'pwd'            => 'pass',
        );
        $GLOBALS['_cb_test_wp_signon_result'] = new WP_Error('cashback_user_banned', 'Account banned');

        Cashback_SC_Auth_Pages_Login::maybe_handle();

        $this->assertSame(
            'Аккаунт заблокирован. Свяжитесь с поддержкой.',
            $GLOBALS['_cb_test_wc_notices'][0]['message']
        );
    }

    public function test_rate_limit_blocks_after_max_attempts(): void
    {
        $key = Cashback_SC_Auth_Pages_Login::RATE_LIMIT_PREFIX . md5('203.0.113.10');
        set_transient($key, Cashback_SC_Auth_Pages_Login::RATE_LIMIT_MAX, 900);

        $_POST = array(
            'sc_auth_action' => 'login',
            '_sc_auth_nonce' => 'valid',
            'log'            => 'user@example.com',
            'pwd'            => 'pass',
        );
        Cashback_SC_Auth_Pages_Login::maybe_handle();

        $this->assertCount(0, $GLOBALS['_cb_test_wp_signon_calls'], 'wp_signon не должен вызываться при rate-limit');
        $this->assertCount(1, $GLOBALS['_cb_test_wc_notices']);
        $this->assertStringContainsString('Слишком много попыток', $GLOBALS['_cb_test_wc_notices'][0]['message']);
    }

    public function test_successful_login_redirects_to_my_account_and_clears_rate_limit(): void
    {
        // Заполним счётчик 2 неудачными попытками.
        $key = Cashback_SC_Auth_Pages_Login::RATE_LIMIT_PREFIX . md5('203.0.113.10');
        set_transient($key, 2, 900);

        $_POST = array(
            'sc_auth_action' => 'login',
            '_sc_auth_nonce' => 'valid',
            'log'            => 'good@example.com',
            'pwd'            => 'goodpass',
        );
        $GLOBALS['_cb_test_wp_signon_result'] = new WP_User(42, 'good@example.com');

        Cashback_SC_Auth_Pages_Login::maybe_handle();

        $this->assertCount(1, $GLOBALS['_cb_test_wp_signon_calls']);
        $this->assertCount(0, $GLOBALS['_cb_test_wc_notices'], 'success — без notice');
        $this->assertCount(1, $GLOBALS['_cb_test_redirects']);
        $this->assertSame('http://localhost/my-account/', $GLOBALS['_cb_test_redirects'][0]['url']);

        // Rate-limit очищен.
        $this->assertFalse(get_transient($key));
    }

    public function test_redirect_to_from_form_is_used_after_validate(): void
    {
        $_POST = array(
            'sc_auth_action' => 'login',
            '_sc_auth_nonce' => 'valid',
            'log'            => 'good@example.com',
            'pwd'            => 'goodpass',
            'redirect_to'    => 'http://localhost/my-account/orders/',
        );
        $GLOBALS['_cb_test_wp_signon_result'] = new WP_User(42);

        Cashback_SC_Auth_Pages_Login::maybe_handle();

        $this->assertSame(
            'http://localhost/my-account/orders/',
            $GLOBALS['_cb_test_redirects'][0]['url']
        );
    }

    public function test_redirect_to_external_url_falls_back_to_my_account(): void
    {
        $_POST = array(
            'sc_auth_action' => 'login',
            '_sc_auth_nonce' => 'valid',
            'log'            => 'good@example.com',
            'pwd'            => 'goodpass',
            'redirect_to'    => 'http://evil.com/phishing',
        );
        $GLOBALS['_cb_test_wp_signon_result'] = new WP_User(42);

        Cashback_SC_Auth_Pages_Login::maybe_handle();

        $this->assertSame(
            'http://localhost/my-account/',
            $GLOBALS['_cb_test_redirects'][0]['url'],
            'wp_validate_redirect должен зарезать external URL'
        );
    }

    public function test_filter_overrides_login_redirect(): void
    {
        $_POST = array(
            'sc_auth_action' => 'login',
            '_sc_auth_nonce' => 'valid',
            'log'            => 'good@example.com',
            'pwd'            => 'goodpass',
        );
        $GLOBALS['_cb_test_wp_signon_result'] = new WP_User(42);
        add_filter('sc_auth_pages_login_redirect', static fn() => 'http://localhost/dashboard/');

        Cashback_SC_Auth_Pages_Login::maybe_handle();

        $this->assertSame(
            'http://localhost/dashboard/',
            $GLOBALS['_cb_test_redirects'][0]['url']
        );
    }
}
