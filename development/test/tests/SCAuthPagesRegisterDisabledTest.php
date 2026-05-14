<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Registration Gate в Cashback_SC_Auth_Pages_Register::maybe_handle (POST /register/).
 *
 * Покрывает:
 *  - users_can_register=0 → wc_create_new_customer НЕ вызван, notice, redirect
 *  - users_can_register=1 → нормальное прохождение (sanity, не дублируем существующие тесты)
 */
#[Group('sc-auth-pages')]
#[Group('registration-gate')]
final class SCAuthPagesRegisterDisabledTest extends TestCase
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
            // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
            eval('class WP_User { public int $ID = 0; public string $user_login = ""; public function __construct(int $id = 0, string $login = "") { $this->ID = $id; $this->user_login = $login; } }');
        }

        require_once $plugin_root . '/includes/auth/class-cashback-registration-gate.php';
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

    public function test_users_can_register_0_blocks_wc_create_silently(): void
    {
        update_option('users_can_register', 0);
        $_POST = $this->valid_post_data();

        Cashback_SC_Auth_Pages_Register::maybe_handle();

        $this->assertCount(0, $GLOBALS['_cb_test_wc_create_calls'], 'wc_create_new_customer не должен быть вызван');
        // Silent reject: notice НЕ создаётся (wc_add_notice пишет в WC session →
        // amplification-vector для ботов). Юзер увидит disabled-страницу через
        // shortcode render на GET /register/ после редиректа.
        $this->assertCount(0, $GLOBALS['_cb_test_wc_notices'], 'disabled-path НЕ должен писать notice (защита от session-amplification)');
        $this->assertCount(1, $GLOBALS['_cb_test_redirects']);
        $this->assertSame('http://localhost/register/', $GLOBALS['_cb_test_redirects'][0]['url']);
    }

    public function test_gate_runs_before_rate_limit_so_disabled_state_does_not_consume_budget(): void
    {
        update_option('users_can_register', 0);
        // Заполняем rate-limit заранее — gate должен сработать ДО проверки.
        $key = Cashback_SC_Auth_Pages_Register::RATE_LIMIT_PREFIX . md5('203.0.113.10');
        set_transient($key, 1, 3600);

        $_POST = $this->valid_post_data();
        Cashback_SC_Auth_Pages_Register::maybe_handle();

        // Rate-limit НЕ инкрементирован (мы не дошли до register_violation).
        $this->assertSame(1, (int) get_transient($key), 'gate должен сработать до rate-limit-инкремента');
    }

    public function test_users_can_register_1_does_not_block(): void
    {
        update_option('users_can_register', 1);
        $_POST = $this->valid_post_data();
        $GLOBALS['_cb_test_wc_create_result'] = 555;

        Cashback_SC_Auth_Pages_Register::maybe_handle();

        $this->assertCount(1, $GLOBALS['_cb_test_wc_create_calls'], 'регистрация разрешена → wc_create вызывается');
    }
}
