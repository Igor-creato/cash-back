<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты редиректа /my-account/ → /login/ для гостей.
 *
 * Покрывает:
 *  - logged-in юзер → НЕ редирект (для него /my-account/ — Dashboard)
 *  - гость не на /my-account/ → НЕ редирект
 *  - гость на /my-account/ → redirect на /login/?redirect_to=...
 *  - гость на /my-account/lost-password/ → НЕ редирект (whitelist)
 *  - гость на /my-account/reset-password/ → НЕ редирект (whitelist)
 *  - гость на /my-account/customer-logout/ → НЕ редирект (whitelist)
 */
#[Group('sc-auth-pages')]
#[Group('sc-auth-pages-redirector')]
final class SCAuthPagesRedirectorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        if (!defined('CASHBACK_SC_AUTH_PAGES_NO_EXIT')) {
            define('CASHBACK_SC_AUTH_PAGES_NO_EXIT', true);
        }

        if (!function_exists('home_url')) {
            function home_url( string $path = '/' ): string
            {
                return 'http://localhost' . (str_starts_with($path, '/') ? $path : '/' . $path);
            }
        }
        if (!function_exists('get_permalink')) {
            function get_permalink( int $post_id ): string
            {
                return $GLOBALS['_cb_test_permalinks'][ $post_id ] ?? '';
            }
        }
        if (!function_exists('is_account_page')) {
            function is_account_page(): bool
            {
                return (bool) ($GLOBALS['_cb_test_is_account_page'] ?? false);
            }
        }
        if (!function_exists('add_query_arg')) {
            function add_query_arg( $args, $url )
            {
                if (is_array($args)) {
                    $query = http_build_query($args);
                } else {
                    $query = $args . '=' . $url;
                }
                $separator = str_contains((string) $url, '?') ? '&' : '?';
                return $url . $separator . $query;
            }
        }
        if (!function_exists('wp_parse_url')) {
            function wp_parse_url( string $url, int $component = -1 )
            {
                return $component === -1 ? parse_url($url) : parse_url($url, $component);
            }
        }

        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-activator.php';
        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-redirect-helper.php';
        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-redirector.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']           = array();
        $GLOBALS['_cb_test_filters']           = array();
        $GLOBALS['_cb_test_redirects']         = array();
        $GLOBALS['_cb_test_is_logged_in']      = false;
        $GLOBALS['_cb_test_is_account_page']   = true;
        $GLOBALS['_cb_test_permalinks']        = array(
            100 => 'http://localhost/login/',
        );
        $_SERVER = array(
            'REQUEST_URI' => '/my-account/',
        );

        update_option(Cashback_SC_Auth_Pages_Activator::OPTION_LOGIN_PAGE_ID, 100);

        Cashback_SC_Auth_Pages_Redirect_Helper::$test_capture = static function ( string $url ): void {
            $GLOBALS['_cb_test_redirects'][] = array( 'url' => $url );
        };
        add_filter('sc_auth_pages_login_url', static fn() => 'http://localhost/login/');
    }

    protected function tearDown(): void
    {
        Cashback_SC_Auth_Pages_Redirect_Helper::$test_capture = null;
        parent::tearDown();
    }

    public function test_logged_in_user_is_not_redirected(): void
    {
        $GLOBALS['_cb_test_is_logged_in'] = true;

        Cashback_SC_Auth_Pages_Redirector::maybe_redirect();

        $this->assertCount(0, $GLOBALS['_cb_test_redirects']);
    }

    public function test_guest_outside_account_page_is_not_redirected(): void
    {
        $GLOBALS['_cb_test_is_account_page'] = false;

        Cashback_SC_Auth_Pages_Redirector::maybe_redirect();

        $this->assertCount(0, $GLOBALS['_cb_test_redirects']);
    }

    public function test_guest_on_my_account_redirects_to_login_with_redirect_to(): void
    {
        Cashback_SC_Auth_Pages_Redirector::maybe_redirect();

        $this->assertCount(1, $GLOBALS['_cb_test_redirects']);
        $url = $GLOBALS['_cb_test_redirects'][0]['url'];
        $this->assertStringContainsString('http://localhost/login/', $url);
        $this->assertStringContainsString('redirect_to=', $url);
        $this->assertStringContainsString(
            rawurlencode('/my-account/'),
            $url,
            'redirect_to должен содержать urlencoded путь /my-account/'
        );
    }

    public function test_guest_on_lost_password_endpoint_is_not_redirected(): void
    {
        $_SERVER['REQUEST_URI'] = '/my-account/lost-password/';

        Cashback_SC_Auth_Pages_Redirector::maybe_redirect();

        $this->assertCount(0, $GLOBALS['_cb_test_redirects']);
    }

    public function test_guest_on_reset_password_endpoint_is_not_redirected(): void
    {
        $_SERVER['REQUEST_URI'] = '/my-account/reset-password/';

        Cashback_SC_Auth_Pages_Redirector::maybe_redirect();

        $this->assertCount(0, $GLOBALS['_cb_test_redirects']);
    }

    public function test_guest_on_customer_logout_endpoint_is_not_redirected(): void
    {
        $_SERVER['REQUEST_URI'] = '/my-account/customer-logout/';

        Cashback_SC_Auth_Pages_Redirector::maybe_redirect();

        $this->assertCount(0, $GLOBALS['_cb_test_redirects']);
    }

    public function test_guest_on_my_account_subpage_other_than_whitelist_is_redirected(): void
    {
        $_SERVER['REQUEST_URI'] = '/my-account/orders/';

        Cashback_SC_Auth_Pages_Redirector::maybe_redirect();

        $this->assertCount(1, $GLOBALS['_cb_test_redirects'], '/my-account/orders/ для guest — редирект');
    }
}
