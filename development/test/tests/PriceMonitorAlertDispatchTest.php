<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('internal-rest-api')]
final class PriceMonitorAlertDispatchTest extends TestCase
{
    private const SECRET = 'internal-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cb_test_rest_routes']   = array();
        $GLOBALS['_cb_test_mail_calls']    = array();
        $GLOBALS['_cb_test_wp_mail_result'] = true;
        $GLOBALS['_cb_test_users']         = array();

        update_option('savello_internal_api_enabled', 1);
        update_option('savello_internal_api_secret', self::SECRET);

        require_once dirname(__DIR__, 3) . '/includes/services/class-internal-hmac-auth-service.php';
        require_once dirname(__DIR__, 3) . '/includes/services/class-cashback-internal-api-service.php';
        require_once dirname(__DIR__, 3) . '/includes/rest/class-cashback-internal-rest-controller.php';
    }

    public function test_internal_alert_route_is_registered(): void
    {
        (new Savello_Cashback_Internal_REST_Controller())->register_routes();

        self::assertArrayHasKey(
            'savello-internal/v1/price-monitor/alerts/send',
            $GLOBALS['_cb_test_rest_routes']
        );
    }

    public function test_internal_alert_route_uses_existing_hmac_permission_and_rejects_bad_signatures(): void
    {
        (new Savello_Cashback_Internal_REST_Controller())->register_routes();

        $route = $GLOBALS['_cb_test_rest_routes']['savello-internal/v1/price-monitor/alerts/send'] ?? null;
        self::assertIsArray($route);
        self::assertSame('check_hmac', $route['args']['permission_callback'][1] ?? null);

        $permission_callback = $route['args']['permission_callback'];

        $missing = call_user_func($permission_callback, new WP_REST_Request('POST', '/savello-internal/v1/price-monitor/alerts/send'));
        self::assertInstanceOf(WP_Error::class, $missing);
        self::assertSame(401, $missing->get_error_data()['status'] ?? null);

        $invalid = call_user_func($permission_callback, $this->request(signature: 'bad-signature'));
        self::assertInstanceOf(WP_Error::class, $invalid);
        self::assertSame(403, $invalid->get_error_data()['status'] ?? null);
    }

    public function test_invalid_user_id_returns_404(): void
    {
        $controller = new Savello_Cashback_Internal_REST_Controller();

        $result = $controller->send_price_monitor_alert($this->request());

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame(404, $result->get_error_data()['status'] ?? null);
    }

    public function test_invalid_payload_returns_explicit_400(): void
    {
        $GLOBALS['_cb_test_users'][15] = (object) array(
            'ID'           => 15,
            'user_email'   => 'alerts@example.com',
            'display_name' => 'Иван',
        );

        $controller = new Savello_Cashback_Internal_REST_Controller();
        $payload    = $this->valid_payload();
        unset($payload['observed_price_minor']);

        $result = $controller->send_price_monitor_alert($this->request($payload));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame(400, $result->get_error_data()['status'] ?? null);
    }

    public function test_valid_payload_sends_email_with_required_fields_and_returns_sent_status(): void
    {
        $GLOBALS['_cb_test_users'][15] = (object) array(
            'ID'           => 15,
            'user_email'   => 'alerts@example.com',
            'display_name' => 'Иван',
        );

        $controller = new Savello_Cashback_Internal_REST_Controller();
        $response   = $controller->send_price_monitor_alert($this->request());

        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(200, $response->get_status());
        self::assertSame(array( 'status' => 'sent' ), $response->get_data());

        self::assertCount(1, $GLOBALS['_cb_test_mail_calls']);
        $call = $GLOBALS['_cb_test_mail_calls'][0];

        self::assertSame('alerts@example.com', $call['to']);
        self::assertStringContainsString('Смартфон Savello X', $call['subject']);
        self::assertStringContainsString('Смартфон Savello X', $call['message']);
        self::assertStringContainsString('1 799.00 RUB', $call['message']);
        self::assertStringContainsString('2 000.00 RUB', $call['message']);
        self::assertStringContainsString('https://example.test/product/savello-x', $call['message']);
        self::assertStringContainsString('https://savelloclub.test/account/price-monitor/777', $call['message']);
    }

    public function test_wp_mail_failure_returns_explicit_500(): void
    {
        $GLOBALS['_cb_test_users'][15] = (object) array(
            'ID'           => 15,
            'user_email'   => 'alerts@example.com',
            'display_name' => 'Иван',
        );
        $GLOBALS['_cb_test_wp_mail_result'] = false;

        $controller = new Savello_Cashback_Internal_REST_Controller();
        $result     = $controller->send_price_monitor_alert($this->request());

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame(500, $result->get_error_data()['status'] ?? null);
    }

    private function request(?array $payload = null, ?string $timestamp = null, ?string $signature = null): WP_REST_Request
    {
        $payload ??= $this->valid_payload();
        $body      = (string) wp_json_encode($payload);
        $timestamp ??= (string) time();
        $signature ??= Savello_Internal_HMAC_Auth_Service::build_signature($timestamp, $body, self::SECRET);

        $request = new WP_REST_Request('POST', '/savello-internal/v1/price-monitor/alerts/send');
        $request->set_body($body);
        $request->set_header('X-Savello-Site', 'savelloclub.test');
        $request->set_header('X-Savello-Timestamp', $timestamp);
        $request->set_header('X-Savello-Signature', $signature);

        return $request;
    }

    private function valid_payload(): array
    {
        return array(
            'alert_event_id'        => 'evt-123',
            'watchlist_item_id'     => 777,
            'product_id'            => 345,
            'user_id'               => 15,
            'target_price_minor'    => 200000,
            'observed_price_minor'  => 179900,
            'currency'              => 'RUB',
            'product_title'         => 'Смартфон Savello X',
            'product_url'           => 'https://example.test/product/savello-x',
            'action_url'            => 'https://savelloclub.test/account/price-monitor/777',
        );
    }
}
