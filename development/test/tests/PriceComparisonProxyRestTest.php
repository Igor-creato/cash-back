<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('price-comparison')]
final class PriceComparisonProxyRestTest extends TestCase {

    public static function setUpBeforeClass(): void {
        require_once dirname(__DIR__, 3) . '/includes/price-comparison/class-cashback-price-comparison-client.php';
        require_once dirname(__DIR__, 3) . '/includes/price-comparison/class-cashback-price-comparison-service.php';
        require_once dirname(__DIR__, 3) . '/includes/price-comparison/class-cashback-price-comparison-rest-controller.php';
    }

    protected function setUp(): void {
        $GLOBALS['_cb_test_rest_routes'] = array();
    }

    public function test_rest_controller_registers_search_route(): void {
        $controller = new Cashback_Price_Comparison_REST_Controller();
        $controller->register_routes();

        self::assertArrayHasKey('cashback/v1/price-comparison/search', $GLOBALS['_cb_test_rest_routes']);

        $route = $GLOBALS['_cb_test_rest_routes']['cashback/v1/price-comparison/search']['args'];
        self::assertSame('POST', $route['methods']);
        self::assertArrayHasKey('permission_callback', $route);
        self::assertArrayHasKey('city', $route['args']);
        self::assertArrayHasKey('query', $route['args']);
        self::assertArrayHasKey('limit', $route['args']);
        self::assertArrayHasKey('offset', $route['args']);
    }

    public function test_live_search_routes_are_registered(): void {
        $controller = new Cashback_Price_Comparison_REST_Controller();
        $controller->register_routes();

        self::assertArrayHasKey(
            'cashback/v1/price-comparison/live-search',
            $GLOBALS['_cb_test_rest_routes']
        );
        self::assertArrayHasKey(
            'cashback/v1/price-comparison/live-search/(?P<run_id>[a-zA-Z0-9_-]+)',
            $GLOBALS['_cb_test_rest_routes']
        );
    }

    public function test_rest_controller_requires_rest_nonce(): void {
        $controller = new Cashback_Price_Comparison_REST_Controller();
        $controller->register_routes();

        $route      = $GLOBALS['_cb_test_rest_routes']['cashback/v1/price-comparison/search']['args'];
        $permission = call_user_func($route['permission_callback'], new WP_REST_Request('POST', '/cashback/v1/price-comparison/search'));

        self::assertInstanceOf(WP_Error::class, $permission);
        self::assertSame('rest_cookie_invalid_nonce', $permission->get_error_code());
        self::assertSame(403, $permission->get_error_data()['status'] ?? null);
    }

    public function test_search_returns_safe_validation_error_for_empty_city(): void {
        $controller = new Cashback_Price_Comparison_REST_Controller();
        $request    = new WP_REST_Request('POST', '/cashback/v1/price-comparison/search');
        $request->set_param('city', '');
        $request->set_param('query', 'iphone');

        $response = $controller->search($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('INVALID_CITY', $response->get_data()['error_code']);
        self::assertStringNotContainsString('price_compare_hmac_secret', wp_json_encode($response->get_data()));
    }
}
