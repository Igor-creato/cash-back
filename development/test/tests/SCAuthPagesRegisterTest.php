<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты обработчика POST /register/ → wc_create_new_customer.
 *
 * Покрывает:
 *  - GET / no-action → не обрабатывается
 *  - POST без nonce → notice + redirect
 *  - POST с заполненным honeypot (email_2) → silent reject (без wc_create)
 *  - POST с password != confirm → notice
 *  - POST с password length < 8 → notice
 *  - POST с invalid email → notice
 *  - POST с rate-limit miss → notice
 *  - POST с email exists (WC возвращает WP_Error) → notice с raw текстом
 *  - POST с valid data → wc_create_new_customer вызвался, auto_login сработал, redirect
 *  - filter sc_auth_pages_auto_login → false → НЕ logged in
 *  - filter sc_auth_pages_register_redirect меняет цель
 *  - rate-limit очищается после успеха
 */
#[Group('sc-auth-pages')]
#[Group('sc-auth-pages-register')]
final class SCAuthPagesRegisterTest extends TestCase
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
        if (!defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 3600);
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
                return (bool) ($GLOBALS['_cb_test_is_register_page'] ?? false);
            }
        }
        if (!function_exists('is_email')) {
            function is_email( string $email ): bool
            {
                return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            }
        }
        if (!function_exists('wc_add_notice')) {
            function wc_add_notice( string $message, string $notice_type = 'success', array $data = array() ): void
            {
                $GLOBALS['_cb_test_wc_notices'][] = array( 'message' => $message, 'type' => $notice_type );
            }
        }
        if (!function_exists('wc_create_new_customer')) {
            function wc_create_new_customer( string $email, string $username = '', string $password = '', array $args = array() )
            {
                $GLOBALS['_cb_test_wc_create_calls'][] = array(
                    'email'    => $email,
                    'username' => $username,
                    'password' => $password,
                );
                $result = $GLOBALS['_cb_test_wc_create_result'] ?? new WP_Error('test', 'no result');
                return $result;
            }
        }
        if (!function_exists('wp_set_current_user')) {
            function wp_set_current_user( int $user_id, string $name = '' ): void
            {
                $GLOBALS['_cb_test_wp_set_current_user_calls'][] = $user_id;
            }
        }
        if (!function_exists('wp_set_auth_cookie')) {
            function wp_set_auth_cookie( int $user_id, bool $remember = false, mixed $secure = '', string $token = '' ): void
            {
                $GLOBALS['_cb_test_wp_set_auth_cookie_calls'][] = array( 'user_id' => $user_id, 'remember' => $remember );
            }
        }

        if (!class_exists('WP_User')) {
            // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- тестовый стаб.
            eval('class WP_User { public int $ID = 0; public string $user_login = ""; public function __construct(int $id = 0, string $login = "") { $this->ID = $id; $this->user_login = $login; } }');
        }

        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-activator.php';
        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-redirect-helper.php';
        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-register.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']                  = array();
        $GLOBALS['_cb_test_filters']                  = array();
        $GLOBALS['_cb_test_actions_fired']            = array();
        $GLOBALS['_cb_test_redirects']                = array();
        $GLOBALS['_cb_test_wc_notices']               = array();
        $GLOBALS['_cb_test_wc_create_calls']          = array();
        $GLOBALS['_cb_test_wc_create_result']         = null;
        $GLOBALS['_cb_test_wp_set_current_user_calls'] = array();
        $GLOBALS['_cb_test_wp_set_auth_cookie_calls']  = array();
        $GLOBALS['_cb_test_transients']               = array();
        $GLOBALS['_cb_test_wc_page_permalinks']       = array(
            'myaccount' => 'http://localhost/my-account/',
        );
        $GLOBALS['_cb_test_permalinks']               = array(
            200 => 'http://localhost/register/',
        );
        $GLOBALS['_cb_test_is_register_page']         = true;
        $_POST                                        = array();
        $_GET                                         = array();
        $_SERVER                                      = array(
            'REMOTE_ADDR'    => '203.0.113.10',
            'REQUEST_METHOD' => 'POST',
        );

        update_option(Cashback_SC_Auth_Pages_Activator::OPTION_REGISTER_PAGE_ID, 200);

        Cashback_SC_Auth_Pages_Redirect_Helper::$test_capture = static function ( string $url ): void {
            $GLOBALS['_cb_test_redirects'][] = array( 'url' => $url );
        };
        add_filter('sc_auth_pages_register_url', static fn() => 'http://localhost/register/');
        add_filter('sc_auth_pages_default_my_account_url', static fn() => 'http://localhost/my-account/');
    }

    protected function tearDown(): void
    {
        Cashback_SC_Auth_Pages_Redirect_Helper::$test_capture = null;
        parent::tearDown();
    }

    private function valid_post_data(): array
    {
        return array(
            'sc_auth_action'   => 'register',
            '_sc_auth_nonce'   => 'valid',
            'email'            => 'new@example.com',
            'password'         => 'SecurePass1',
            'password_confirm' => 'SecurePass1',
        );
    }

    public function test_get_request_does_not_handle(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Cashback_SC_Auth_Pages_Register::maybe_handle();

        $this->assertCount(0, $GLOBALS['_cb_test_wc_create_calls']);
    }

    public function test_post_with_invalid_nonce_redirects_with_notice(): void
    {
        $_POST = $this->valid_post_data();
        $_POST['_sc_auth_nonce'] = '';

        Cashback_SC_Auth_Pages_Register::maybe_handle();

        $this->assertCount(0, $GLOBALS['_cb_test_wc_create_calls']);
        $this->assertCount(1, $GLOBALS['_cb_test_wc_notices']);
        $this->assertCount(1, $GLOBALS['_cb_test_redirects']);
        $this->assertSame('http://localhost/register/', $GLOBALS['_cb_test_redirects'][0]['url']);
    }

    public function test_honeypot_filled_silently_rejects(): void
    {
        $_POST          = $this->valid_post_data();
        $_POST['email_2'] = 'spammer@bot.example';

        Cashback_SC_Auth_Pages_Register::maybe_handle();

        $this->assertCount(0, $GLOBALS['_cb_test_wc_create_calls']);
        $this->assertCount(0, $GLOBALS['_cb_test_wc_notices'], 'silent reject — без notice');
        $this->assertCount(1, $GLOBALS['_cb_test_redirects']);
    }

    public function test_password_mismatch_shows_notice(): void
    {
        $_POST = $this->valid_post_data();
        $_POST['password_confirm'] = 'Different!';

        Cashback_SC_Auth_Pages_Register::maybe_handle();

        $this->assertCount(0, $GLOBALS['_cb_test_wc_create_calls']);
        $this->assertSame(
            'Пароли не совпадают.',
            $GLOBALS['_cb_test_wc_notices'][0]['message']
        );
    }

    public function test_short_password_shows_notice(): void
    {
        $_POST = $this->valid_post_data();
        $_POST['password']         = 'short';
        $_POST['password_confirm'] = 'short';

        Cashback_SC_Auth_Pages_Register::maybe_handle();

        $this->assertCount(0, $GLOBALS['_cb_test_wc_create_calls']);
        $this->assertStringContainsString(
            'минимум 8 символов',
            $GLOBALS['_cb_test_wc_notices'][0]['message']
        );
    }

    public function test_invalid_email_shows_notice(): void
    {
        $_POST = $this->valid_post_data();
        $_POST['email'] = 'not-an-email';

        Cashback_SC_Auth_Pages_Register::maybe_handle();

        $this->assertCount(0, $GLOBALS['_cb_test_wc_create_calls']);
        $this->assertStringContainsString('email', $GLOBALS['_cb_test_wc_notices'][0]['message']);
    }

    public function test_rate_limit_blocks_after_max(): void
    {
        $key = Cashback_SC_Auth_Pages_Register::RATE_LIMIT_PREFIX . md5('203.0.113.10');
        set_transient($key, Cashback_SC_Auth_Pages_Register::RATE_LIMIT_MAX, 3600);

        $_POST = $this->valid_post_data();
        Cashback_SC_Auth_Pages_Register::maybe_handle();

        $this->assertCount(0, $GLOBALS['_cb_test_wc_create_calls']);
        $this->assertStringContainsString('Слишком много', $GLOBALS['_cb_test_wc_notices'][0]['message']);
    }

    public function test_wc_create_returns_error_shows_notice_and_increments_rate_limit(): void
    {
        $_POST = $this->valid_post_data();
        $GLOBALS['_cb_test_wc_create_result'] = new WP_Error(
            'registration-error-email-exists',
            'Аккаунт уже существует. <a href="/login/">Войти?</a>'
        );

        Cashback_SC_Auth_Pages_Register::maybe_handle();

        $this->assertCount(1, $GLOBALS['_cb_test_wc_create_calls']);
        $this->assertCount(1, $GLOBALS['_cb_test_wc_notices']);
        $this->assertStringContainsString('Аккаунт уже существует', $GLOBALS['_cb_test_wc_notices'][0]['message']);

        // Rate-limit инкрементирован.
        $key = Cashback_SC_Auth_Pages_Register::RATE_LIMIT_PREFIX . md5('203.0.113.10');
        $this->assertSame(1, (int) get_transient($key));
    }

    public function test_successful_registration_calls_wc_create_auto_login_and_redirects(): void
    {
        $_POST = $this->valid_post_data();
        $GLOBALS['_cb_test_wc_create_result'] = 555;

        Cashback_SC_Auth_Pages_Register::maybe_handle();

        $this->assertCount(1, $GLOBALS['_cb_test_wc_create_calls']);
        $this->assertSame('new@example.com', $GLOBALS['_cb_test_wc_create_calls'][0]['email']);
        $this->assertSame('SecurePass1', $GLOBALS['_cb_test_wc_create_calls'][0]['password']);

        $this->assertCount(0, $GLOBALS['_cb_test_wc_notices'], 'success — без notice');

        // Auto-login по умолчанию ON.
        $this->assertSame([555], $GLOBALS['_cb_test_wp_set_current_user_calls']);
        $this->assertCount(1, $GLOBALS['_cb_test_wp_set_auth_cookie_calls']);
        $this->assertSame(555, $GLOBALS['_cb_test_wp_set_auth_cookie_calls'][0]['user_id']);
        $this->assertTrue($GLOBALS['_cb_test_wp_set_auth_cookie_calls'][0]['remember']);

        // Redirect на /my-account/.
        $this->assertCount(1, $GLOBALS['_cb_test_redirects']);
        $this->assertSame('http://localhost/my-account/', $GLOBALS['_cb_test_redirects'][0]['url']);
    }

    public function test_filter_disables_auto_login(): void
    {
        $_POST = $this->valid_post_data();
        $GLOBALS['_cb_test_wc_create_result'] = 555;
        add_filter('sc_auth_pages_auto_login', static fn() => false);

        Cashback_SC_Auth_Pages_Register::maybe_handle();

        $this->assertSame([], $GLOBALS['_cb_test_wp_set_current_user_calls'], 'auto-login отключён');
        $this->assertSame([], $GLOBALS['_cb_test_wp_set_auth_cookie_calls']);
        // Redirect всё равно должен быть.
        $this->assertCount(1, $GLOBALS['_cb_test_redirects']);
    }

    public function test_filter_overrides_register_redirect(): void
    {
        $_POST = $this->valid_post_data();
        $GLOBALS['_cb_test_wc_create_result'] = 555;
        add_filter('sc_auth_pages_register_redirect', static fn() => 'http://localhost/welcome/');

        Cashback_SC_Auth_Pages_Register::maybe_handle();

        $this->assertSame(
            'http://localhost/welcome/',
            $GLOBALS['_cb_test_redirects'][0]['url']
        );
    }

    public function test_external_redirect_falls_back(): void
    {
        $_POST = $this->valid_post_data();
        $GLOBALS['_cb_test_wc_create_result'] = 555;
        add_filter('sc_auth_pages_register_redirect', static fn() => 'http://evil.com/');

        Cashback_SC_Auth_Pages_Register::maybe_handle();

        $this->assertSame(
            'http://localhost/my-account/',
            $GLOBALS['_cb_test_redirects'][0]['url']
        );
    }

    public function test_auto_password_mode_skips_password_validation_and_passes_empty_to_wc(): void
    {
        // WC настройка: автогенерация пароля.
        update_option('woocommerce_registration_generate_password', 'yes');

        $_POST = array(
            'sc_auth_action' => 'register',
            '_sc_auth_nonce' => 'valid',
            'email'          => 'newuser@example.com',
            // password / password_confirm НЕ передаём — формы их не показывает.
        );
        $GLOBALS['_cb_test_wc_create_result'] = 555;

        Cashback_SC_Auth_Pages_Register::maybe_handle();

        $this->assertCount(1, $GLOBALS['_cb_test_wc_create_calls']);
        $this->assertSame(
            '',
            $GLOBALS['_cb_test_wc_create_calls'][0]['password'],
            'В auto-password режиме password передаётся пустым (WC сгенерирует сам)'
        );

        // Auto-login НЕ должен сработать — юзер пройдёт по ссылке из email.
        $this->assertSame([], $GLOBALS['_cb_test_wp_set_auth_cookie_calls']);

        // Должен быть success notice.
        $success_notices = array_filter(
            $GLOBALS['_cb_test_wc_notices'],
            static fn( $n ) => $n['type'] === 'success'
        );
        $this->assertCount(1, $success_notices);
    }

    public function test_auto_password_filter_overrides_wc_setting(): void
    {
        // WC: НЕ генерировать пароль.
        update_option('woocommerce_registration_generate_password', 'no');
        // Filter: всё-таки генерировать.
        add_filter('sc_auth_pages_auto_generate_password', static fn() => true);

        $this->assertTrue(Cashback_SC_Auth_Pages_Register::is_auto_password_mode());
    }

    public function test_rate_limit_cleared_after_successful_registration(): void
    {
        $key = Cashback_SC_Auth_Pages_Register::RATE_LIMIT_PREFIX . md5('203.0.113.10');
        set_transient($key, 1, 3600);

        $_POST = $this->valid_post_data();
        $GLOBALS['_cb_test_wc_create_result'] = 555;

        Cashback_SC_Auth_Pages_Register::maybe_handle();

        $this->assertFalse(get_transient($key));
    }
}
