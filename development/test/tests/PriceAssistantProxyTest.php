<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('price-assistant-proxy')]
final class PriceAssistantProxyTest extends TestCase
{
    private const SECRET = 'price-monitor-secret';

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3) . '/includes/services/class-price-assistant-proxy-client.php';
        require_once dirname(__DIR__, 3) . '/includes/rest/class-price-assistant-rest-controller.php';

        $GLOBALS['_cb_test_options'] = array();
        $GLOBALS['_cb_test_http_calls'] = array();
        $GLOBALS['_cb_test_http_response_callback'] = null;
        $GLOBALS['_cb_test_is_logged_in'] = true;
        $GLOBALS['_cb_test_user_id'] = 77;
        $GLOBALS['_cb_test_current_user_can'] = true;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';

        update_option('price_monitor_enabled', 1);
        update_option('price_monitor_base_url', 'https://price-monitor.test');
        update_option('price_monitor_site_id', 'savelloclub.test');
        update_option('price_monitor_hmac_secret', self::SECRET);
        update_option('price_monitor_marketplace_ozon_enabled', 1);

        $GLOBALS['_cb_test_http_response'] = array(
            'body' => '{"connection_id":12,"marketplace":"ozon","status":"disconnected","last_validated_at":null,"last_synced_at":null,"next_retry_at":null,"reason":null}',
            'response' => array('code' => 200, 'message' => 'OK'),
            'headers' => array(),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_cb_test_http_response_callback']);
        parent::tearDown();
    }

    public function test_proxy_client_signs_raw_body_with_price_monitor_secret(): void
    {
        $client = new Cashback_Price_Assistant_Proxy_Client(
            static fn(): int => 1781516800
        );

        $response = $client->request(
            'POST',
            '/v1/marketplace-connections',
            array('site_id' => 'savelloclub.test', 'external_user_id' => 'wp:savelloclub.test:77')
        );

        self::assertSame(200, $response['status']);
        self::assertCount(1, $GLOBALS['_cb_test_http_calls']);

        $call = $GLOBALS['_cb_test_http_calls'][0];
        $raw_body = (string) $call['args']['body'];
        $expected = hash_hmac('sha256', '1781516800.' . $raw_body, self::SECRET);

        self::assertSame('POST', $call['args']['method']);
        self::assertSame('https://price-monitor.test/v1/marketplace-connections', $call['url']);
        self::assertSame('savelloclub.test', $call['args']['headers']['X-Savello-Site']);
        self::assertSame('1781516800', $call['args']['headers']['X-Savello-Timestamp']);
        self::assertSame($expected, $call['args']['headers']['X-Savello-Signature']);
        self::assertStringNotContainsString(self::SECRET, wp_json_encode($response));
    }

    public function test_permission_requires_logged_in_user_and_rest_nonce(): void
    {
        $controller = new Cashback_Price_Assistant_REST_Controller();
        $request = $this->request('GET', '/cashback/v1/price-assistant/connections');
        $request->set_header('X-WP-Nonce', '');

        $result = $controller->check_read_permission($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('price_assistant_nonce_required', $result->get_error_code());
        self::assertSame(403, $result->get_error_data()['status'] ?? null);

        $GLOBALS['_cb_test_is_logged_in'] = false;
        $request->set_header('X-WP-Nonce', 'test_nonce_' . md5('wp_rest'));

        $result = $controller->check_read_permission($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('price_assistant_login_required', $result->get_error_code());
        self::assertSame(401, $result->get_error_data()['status'] ?? null);
    }

    public function test_session_bundle_upload_requires_explicit_consent(): void
    {
        $controller = new Cashback_Price_Assistant_REST_Controller(
            new Cashback_Price_Assistant_Proxy_Client(static fn(): int => 1781516800)
        );
        $request = $this->request(
            'POST',
            '/cashback/v1/price-assistant/connections/10/session-bundle',
            array(
                'marketplace' => 'ozon',
                'consent' => false,
                'scope' => array('cart_read'),
                'captured_at' => '2026-06-15T10:00:00Z',
                'connector_version' => '0.1.0',
                'session_bundle' => array(
                    'cookies' => array(array('name' => 'session-id', 'value' => 'secret-cookie')),
                ),
            )
        );
        $request->set_param('connection_id', 10);

        $response = $controller->upload_session_bundle($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('consent_required', $response->get_data()['code']);
        self::assertSame(array(), $GLOBALS['_cb_test_http_calls']);
    }

    public function test_session_bundle_upload_proxies_without_marketplace_or_consent_fields(): void
    {
        $controller = new Cashback_Price_Assistant_REST_Controller(
            new Cashback_Price_Assistant_Proxy_Client(static fn(): int => 1781516800)
        );
        $request = $this->request(
            'POST',
            '/cashback/v1/price-assistant/connections/10/session-bundle',
            array(
                'marketplace' => 'ozon',
                'consent' => true,
                'scope' => array('cart_read'),
                'captured_at' => '2026-06-15T10:00:00Z',
                'connector_version' => '0.1.0',
                'session_bundle' => array(
                    'cookies' => array(array('name' => 'session-id', 'value' => 'secret-cookie')),
                ),
            )
        );
        $request->set_param('connection_id', 10);

        $response = $controller->upload_session_bundle($request);
        $body = json_decode((string) $GLOBALS['_cb_test_http_calls'][0]['args']['body'], true);

        self::assertSame(200, $response->get_status());
        self::assertSame('https://price-monitor.test/v1/marketplace-connections/10/session-bundle', $GLOBALS['_cb_test_http_calls'][0]['url']);
        self::assertArrayNotHasKey('marketplace', $body);
        self::assertArrayNotHasKey('consent', $body);
        self::assertSame('wp:savelloclub.test:77', $body['external_user_id']);
        self::assertSame('session-bundle-10-wp:savelloclub.test:77', $GLOBALS['_cb_test_http_calls'][0]['args']['headers']['Idempotency-Key']);
    }

    public function test_connection_requests_are_scoped_to_current_wordpress_user(): void
    {
        $controller = new Cashback_Price_Assistant_REST_Controller(
            new Cashback_Price_Assistant_Proxy_Client(static fn(): int => 1781516800)
        );
        $request = $this->request(
            'POST',
            '/cashback/v1/price-assistant/connections',
            array(
                'marketplace' => 'ozon',
                'external_user_id' => 'wp:savelloclub.test:999',
                'scope' => array('cart_read', 'favorites_read'),
                'captured_at' => '2026-06-15T10:00:00Z',
                'connector_version' => '0.1.0',
            )
        );

        $response = $controller->create_connection($request);

        self::assertSame(200, $response->get_status());
        $body = json_decode((string) $GLOBALS['_cb_test_http_calls'][0]['args']['body'], true);

        self::assertSame('savelloclub.test', $body['site_id']);
        self::assertSame('wp:savelloclub.test:77', $body['external_user_id']);
        self::assertSame('price-assistant-session-v1', $body['consent_version']);
    }

    public function test_admin_marketplace_feature_flag_blocks_disabled_source(): void
    {
        update_option('price_monitor_marketplace_ozon_enabled', 0);

        $controller = new Cashback_Price_Assistant_REST_Controller(
            new Cashback_Price_Assistant_Proxy_Client(static fn(): int => 1781516800)
        );
        $request = $this->request(
            'POST',
            '/cashback/v1/price-assistant/connections',
            array(
                'marketplace' => 'ozon',
                'scope' => array('cart_read'),
                'captured_at' => '2026-06-15T10:00:00Z',
                'connector_version' => '0.1.0',
            )
        );

        $response = $controller->create_connection($request);

        self::assertSame(423, $response->get_status());
        self::assertSame('marketplace_disabled', $response->get_data()['code']);
        self::assertSame(array(), $GLOBALS['_cb_test_http_calls']);
    }

    public function test_disconnect_proxies_to_backend_and_deletes_own_bundle(): void
    {
        $controller = new Cashback_Price_Assistant_REST_Controller(
            new Cashback_Price_Assistant_Proxy_Client(static fn(): int => 1781516800)
        );
        $request = $this->request('DELETE', '/cashback/v1/price-assistant/connections/12');
        $request->set_param('connection_id', 12);

        $response = $controller->disconnect($request);

        self::assertSame(200, $response->get_status());
        self::assertSame('disconnected', $response->get_data()['status']);

        $call = $GLOBALS['_cb_test_http_calls'][0];
        self::assertSame('POST', $call['args']['method']);
        self::assertSame('https://price-monitor.test/v1/marketplace-connections/12/disconnect', $call['url']);
        self::assertSame('disconnect-12-wp:savelloclub.test:77', $call['args']['headers']['Idempotency-Key']);
    }

    public function test_read_proxy_routes_append_owner_query_to_upstream(): void
    {
        $controller = new Cashback_Price_Assistant_REST_Controller(
            new Cashback_Price_Assistant_Proxy_Client(static fn(): int => 1781516800)
        );

        $controller->list_connections($this->request('GET', '/cashback/v1/price-assistant/sync-status'));
        $controller->get_collections($this->request('GET', '/cashback/v1/price-assistant/collections'));

        $chart = $this->request('GET', '/cashback/v1/price-assistant/products/44/chart');
        $chart->set_param('tracked_product_id', 44);
        $chart->set_param('days', 14);
        $chart->set_param('granularity', 'daily');
        $chart->set_param('currency', 'RUB');
        $controller->get_chart($chart);

        $compare = $this->request('GET', '/cashback/v1/price-assistant/products/44/compare');
        $compare->set_param('tracked_product_id', 44);
        $controller->get_compare($compare);

        $urls = array_column($GLOBALS['_cb_test_http_calls'], 'url');

        self::assertSame(
            'https://price-monitor.test/v1/marketplace-connections?site_id=savelloclub.test&external_user_id=wp%3Asavelloclub.test%3A77',
            $urls[0]
        );
        self::assertSame(
            'https://price-monitor.test/v1/collections?site_id=savelloclub.test&external_user_id=wp%3Asavelloclub.test%3A77',
            $urls[1]
        );
        self::assertSame(
            'https://price-monitor.test/v1/products/44/price-chart?site_id=savelloclub.test&external_user_id=wp%3Asavelloclub.test%3A77&days=14&granularity=daily&currency=RUB',
            $urls[2]
        );
        self::assertSame(
            'https://price-monitor.test/v1/products/44/compare?site_id=savelloclub.test&external_user_id=wp%3Asavelloclub.test%3A77',
            $urls[3]
        );
    }

    public function test_upstream_failure_returns_safe_502_without_internal_url_or_secret(): void
    {
        $GLOBALS['_cb_test_http_response_callback'] = static function (): array {
            return array(
                'body' => '{"detail":"upstream exploded"}',
                'response' => array('code' => 503, 'message' => 'Service Unavailable'),
                'headers' => array(),
            );
        };
        $controller = new Cashback_Price_Assistant_REST_Controller(
            new Cashback_Price_Assistant_Proxy_Client(static fn(): int => 1781516800)
        );
        $request = $this->request('GET', '/cashback/v1/price-assistant/connections');

        $response = $controller->list_connections($request);
        $encoded = wp_json_encode($response->get_data());

        self::assertSame(502, $response->get_status());
        self::assertStringContainsString('upstream_unavailable', $encoded);
        self::assertStringNotContainsString('https://price-monitor.test', $encoded);
        self::assertStringNotContainsString(self::SECRET, $encoded);
    }

    public function test_consent_metadata_contains_required_copy_and_no_internal_config(): void
    {
        $controller = new Cashback_Price_Assistant_REST_Controller();
        $response = $controller->get_consent($this->request('GET', '/cashback/v1/price-assistant/consent'));
        $data = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertSame('price-assistant-session-v1', $data['consent_version']);
        self::assertStringContainsString(
            'Мы сохраним технический токен доступа к корзине/избранному. Логин и пароль не сохраняются',
            $data['text']
        );
        self::assertArrayNotHasKey('price_monitor_base_url', $data);
        self::assertArrayNotHasKey('hmac_secret', $data);
    }

    private function request(string $method, string $route, array $body = array()): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $route);
        $request->set_header('X-WP-Nonce', 'test_nonce_' . md5('wp_rest'));
        if ($body !== array()) {
            $request->set_body((string) wp_json_encode($body));
            foreach ($body as $key => $value) {
                $request->set_param((string) $key, $value);
            }
        }
        return $request;
    }
}
