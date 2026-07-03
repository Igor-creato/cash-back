<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('internal-rest-api')]
final class InternalRestControllerStructuralTest extends TestCase
{
    public function test_internal_routes_are_registered_in_dedicated_namespace(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/services/class-internal-hmac-auth-service.php';
        require_once dirname(__DIR__, 3) . '/includes/services/class-cashback-internal-api-service.php';
        require_once dirname(__DIR__, 3) . '/includes/rest/class-cashback-internal-rest-controller.php';

        $GLOBALS['_cb_test_rest_routes'] = array();
        (new Savello_Cashback_Internal_REST_Controller())->register_routes();

        foreach (array(
            '/health',
            '/merchants',
            '/merchants/(?P<merchant_id>[A-Za-z0-9_-]+)/rates',
            '/resolve-product',
            '/deeplink',
            '/users/(?P<external_user_id>[^/]+)/price-monitor-limits',
            '/manifest',
        ) as $route) {
            self::assertArrayHasKey('savello-internal/v1' . $route, $GLOBALS['_cb_test_rest_routes']);
        }
    }

    public function test_browser_extension_controller_is_untouched_by_internal_namespace(): void
    {
        $extension = file_get_contents(dirname(__DIR__, 3) . '/includes/class-cashback-rest-api.php');

        self::assertIsString($extension);
        self::assertStringContainsString("cashback/v1", $extension);
        self::assertStringNotContainsString('savello-internal/v1', $extension);
        self::assertStringNotContainsString('Savello_Cashback_Internal_REST_Controller', $extension);
    }

    public function test_internal_controller_delegates_business_logic_to_service(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 3) . '/includes/rest/class-cashback-internal-rest-controller.php');

        self::assertIsString($controller);
        self::assertStringContainsString('Savello_Cashback_Internal_API_Service', $controller);
        self::assertStringNotContainsString('$wpdb->get_results', $controller);
        self::assertStringNotContainsString('$wpdb->get_var', $controller);
        self::assertStringNotContainsString('SELECT ', $controller);
    }
}
