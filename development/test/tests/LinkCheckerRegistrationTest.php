<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('link-checker')]
#[Group('shortcodes')]
final class LinkCheckerRegistrationTest extends TestCase {

    public static function setUpBeforeClass(): void {
        if (!function_exists('add_shortcode')) {
            function add_shortcode( string $tag, callable $callback ): bool {
                $GLOBALS['_cb_test_shortcodes'][ $tag ] = $callback;
                return true;
            }
        }
        if (!function_exists('shortcode_atts')) {
            function shortcode_atts( array $pairs, array $atts, string $shortcode = '' ): array {
                unset($shortcode);
                return array_merge($pairs, is_array($atts) ? $atts : array());
            }
        }
        if (!function_exists('rest_url')) {
            function rest_url( string $path = '' ): string {
                return 'https://savelloclub.test/wp-json/' . ltrim($path, '/');
            }
        }

        require_once dirname(__DIR__, 3) . '/includes/link-checker/class-cashback-link-checker-url-validator.php';
        require_once dirname(__DIR__, 3) . '/includes/link-checker/class-cashback-link-checker-service.php';
        require_once dirname(__DIR__, 3) . '/includes/link-checker/class-cashback-link-checker-rest-controller.php';
        require_once dirname(__DIR__, 3) . '/includes/link-checker/class-cashback-link-checker-shortcode.php';
    }

    protected function setUp(): void {
        $GLOBALS['_cb_test_shortcodes']         = array();
        $GLOBALS['shortcode_tags']              = array();
        $GLOBALS['_cb_test_rest_routes']        = array();
        $GLOBALS['_cb_test_enqueued_scripts']   = array();
        $GLOBALS['_cb_test_enqueued_styles']    = array();
        $GLOBALS['_cb_test_localized_scripts']  = array();
    }

    public function test_shortcode_registers_and_renders_link_checker_form(): void {
        Cashback_Link_Checker_Shortcode::init();

        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/link-checker/class-cashback-link-checker-shortcode.php');
        self::assertStringContainsString('add_shortcode(self::SHORTCODE', $source);

        $html = Cashback_Link_Checker_Shortcode::render(array());

        self::assertStringContainsString('data-cashback-link-checker-form', $html);
        self::assertStringContainsString('name="direct_url"', $html);
        self::assertStringContainsString('Проверить кэшбэк', $html);
        self::assertArrayHasKey('cashback-link-checker', $GLOBALS['_cb_test_enqueued_scripts']);
        self::assertArrayHasKey('cashback-link-checker', $GLOBALS['_cb_test_enqueued_styles']);
        self::assertSame(
            'https://savelloclub.test/wp-json/cashback/v1/link-checker',
            $GLOBALS['_cb_test_localized_scripts']['cashback-link-checker']['CashbackLinkChecker']['restBase']
        );
    }

    public function test_rest_controller_registers_check_and_activate_routes(): void {
        $controller = new Cashback_Link_Checker_REST_Controller(new Cashback_Link_Checker_Service());
        $controller->register_routes();

        self::assertArrayHasKey('cashback/v1/link-checker/check', $GLOBALS['_cb_test_rest_routes']);
        self::assertArrayHasKey('cashback/v1/link-checker/activate', $GLOBALS['_cb_test_rest_routes']);

        $check = $GLOBALS['_cb_test_rest_routes']['cashback/v1/link-checker/check']['args'];
        self::assertSame('POST', $check['methods']);
        self::assertArrayHasKey('permission_callback', $check);
        self::assertArrayHasKey('url', $check['args']);

        $activate = $GLOBALS['_cb_test_rest_routes']['cashback/v1/link-checker/activate']['args'];
        self::assertSame('POST', $activate['methods']);
        self::assertArrayHasKey('client_request_id', $activate['args']);
    }

    public function test_rest_controller_requires_rest_nonce_before_rate_limit(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/link-checker/class-cashback-link-checker-rest-controller.php');

        self::assertStringContainsString('X-WP-Nonce', $source);
        self::assertStringContainsString("wp_verify_nonce(\$nonce, 'wp_rest')", $source);
        self::assertStringContainsString('rest_cookie_invalid_nonce', $source);
    }
}
