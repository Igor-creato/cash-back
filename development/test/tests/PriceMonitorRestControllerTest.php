<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('price-monitor')]
final class PriceMonitorRestControllerTest extends TestCase {

    private function controller_path(): string {
        return dirname(__DIR__, 3) . '/includes/price-monitor/class-cashback-price-monitor-rest-controller.php';
    }

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['_cb_test_rest_routes']       = array();
        $GLOBALS['_cb_test_is_logged_in']      = false;
        $GLOBALS['_cb_test_user_id']           = 0;
        $GLOBALS['_cb_test_current_user_can']  = true;
    }

    private function load_controller(): void {
        $path = $this->controller_path();
        self::assertFileExists($path, 'Price monitor REST controller class must exist before proxy routes can work.');

        require_once dirname(__DIR__, 3) . '/includes/price-monitor/class-cashback-price-monitor-client.php';
        require_once dirname(__DIR__, 3) . '/includes/link-checker/class-cashback-link-checker-url-validator.php';
        require_once dirname(__DIR__, 3) . '/includes/link-checker/class-cashback-link-checker-service.php';
        require_once dirname(__DIR__, 3) . '/includes/class-cashback-rate-limiter.php';
        require_once $path;

        Cashback_Rate_Limiter::set_backend(new class {
            public function increment(string $scope_key, int $window, int $limit): array {
                unset($scope_key, $window, $limit);
                return array(
                    'allowed' => true,
                    'hits'    => 1,
                );
            }
        });
    }

    public function test_registers_price_monitor_routes_in_dedicated_namespace(): void {
        $this->load_controller();

        $controller = new Cashback_Price_Monitor_REST_Controller(
            new class {
                public function request(string $method, string $path, array $payload = array(), ?string $idempotency_key = null): array {
                    unset($method, $path, $payload, $idempotency_key);
                    return array();
                }
            },
            new class {
                public function check(string $url, int $user_id = 0): array {
                    unset($url, $user_id);
                    return array();
                }
            }
        );

        $controller->register_routes();

        foreach (array(
            'cashback/v1/price-monitor/items',
            'cashback/v1/price-monitor/items/(?P<item_id>[A-Za-z0-9_-]+)',
            'cashback/v1/price-monitor/items/(?P<item_id>[A-Za-z0-9_-]+)/refresh',
        ) as $route) {
            self::assertArrayHasKey($route, $GLOBALS['_cb_test_rest_routes']);
        }

        $items_route = $GLOBALS['_cb_test_rest_routes']['cashback/v1/price-monitor/items']['args'];
        self::assertSame('POST', $items_route[0]['methods']);
        self::assertSame('GET', $items_route[1]['methods']);

        $item_route = $GLOBALS['_cb_test_rest_routes']['cashback/v1/price-monitor/items/(?P<item_id>[A-Za-z0-9_-]+)']['args'];
        self::assertSame('PATCH', $item_route[0]['methods']);
        self::assertSame('DELETE', $item_route[1]['methods']);

        self::assertSame('POST', $GLOBALS['_cb_test_rest_routes']['cashback/v1/price-monitor/items/(?P<item_id>[A-Za-z0-9_-]+)/refresh']['args']['methods']);
    }

    public function test_write_permission_requires_rest_nonce_and_authenticated_user(): void {
        $this->load_controller();

        $controller = new Cashback_Price_Monitor_REST_Controller(
            new class {
                public function request(string $method, string $path, array $payload = array(), ?string $idempotency_key = null): array {
                    unset($method, $path, $payload, $idempotency_key);
                    return array();
                }
            },
            new class {
                public function check(string $url, int $user_id = 0): array {
                    unset($url, $user_id);
                    return array();
                }
            }
        );

        $missing_nonce = $controller->allow_write_request(new WP_REST_Request('POST', '/cashback/v1/price-monitor/items'));

        self::assertInstanceOf(WP_Error::class, $missing_nonce);
        self::assertSame('rest_cookie_invalid_nonce', $missing_nonce->get_error_code());
        self::assertSame(403, $missing_nonce->get_error_data()['status'] ?? null);

        $request = new WP_REST_Request('POST', '/cashback/v1/price-monitor/items');
        $request->set_header('X-WP-Nonce', 'ok');

        $unauthenticated = $controller->allow_write_request($request);

        self::assertInstanceOf(WP_Error::class, $unauthenticated);
        self::assertContains($unauthenticated->get_error_data()['status'] ?? null, array( 401, 403 ));
    }

    public function test_create_item_returns_unsupported_store_when_source_is_not_supported(): void {
        $this->load_controller();

        $GLOBALS['_cb_test_is_logged_in'] = true;
        $GLOBALS['_cb_test_user_id']      = 77;

        $calls      = array();
        $controller = new Cashback_Price_Monitor_REST_Controller(
            new class( $calls ) {
                public array $calls;

                public function __construct(array &$calls) {
                    $this->calls = &$calls;
                }

                public function request(string $method, string $path, array $payload = array(), ?string $idempotency_key = null): array {
                    $this->calls[] = compact('method', 'path', 'payload', 'idempotency_key');

                    return array(
                        'supported' => false,
                        'error'     => array(
                            'code'    => 'unsupported_store',
                            'message' => 'Магазин не поддерживается',
                        ),
                    );
                }
            },
            new class {
                public function check(string $url, int $user_id = 0): array {
                    unset($url, $user_id);
                    return array();
                }
            }
        );

        $request = new WP_REST_Request('POST', '/cashback/v1/price-monitor/items');
        $request->set_param('url', 'https://unsupported.example/item');

        $response = $controller->create_item($request);

        self::assertSame(422, $response->get_status());
        self::assertSame('unsupported_store', $response->get_data()['code']);
        self::assertCount(1, $calls);
        self::assertSame('GET', $calls[0]['method']);
        self::assertSame('/api/v1/sources/supported', $calls[0]['path']);
        self::assertSame(array( 'url' => 'https://unsupported.example/item' ), $calls[0]['payload']);
    }

    public function test_create_item_returns_enriched_card_shape_after_watch_creation(): void {
        $this->load_controller();

        $GLOBALS['_cb_test_is_logged_in'] = true;
        $GLOBALS['_cb_test_user_id']      = 77;

        $calls            = array();
        $activation_calls = array();
        $controller       = new Cashback_Price_Monitor_REST_Controller(
            new class( $calls ) {
                public array $calls;

                public function __construct(array &$calls) {
                    $this->calls = &$calls;
                }

                public function request(string $method, string $path, array $payload = array(), ?string $idempotency_key = null): array {
                    $this->calls[] = compact('method', 'path', 'payload', 'idempotency_key');

                    if ($method === 'GET' && $path === '/api/v1/sources/supported') {
                        return array(
                            'supported' => true,
                            'source'    => array(
                                'source_domain' => 'shop.example',
                                'display_name'  => 'Shop Example',
                            ),
                        );
                    }

                    if ($method === 'GET' && $path === '/api/v1/products/product-1') {
                        return array(
                            'product' => array(
                                'title'               => 'Example Product',
                                'image_url'           => 'https://example.com/image.jpg',
                                'rating_value'        => '4.7',
                                'current_price_minor' => 11888,
                                'currency'            => 'RUB',
                            ),
                            'source'  => array(
                                'source_domain' => 'shop.example',
                                'display_name'  => 'Shop Example',
                                'logo_url'      => 'https://example.com/logo.png',
                            ),
                            'actions' => array(
                                'direct_url' => 'https://shop.example/item',
                            ),
                        );
                    }

                    if ($method === 'GET' && $path === '/api/v1/products/product-1/price-chart') {
                        return array(
                            'currency' => 'RUB',
                            'points'   => array(
                                array(
                                    'date'            => '2026-06-30',
                                    'min_price_minor' => 11888,
                                    'max_price_minor' => 11888,
                                ),
                            ),
                        );
                    }

                    return array(
                        'created' => true,
                        'item'    => array(
                            'id'                => 'item-1',
                            'user_id'           => 'wp:savelloclub.test:77',
                            'product_id'        => 'product-1',
                            'canonical_url'     => 'https://shop.example/item',
                            'target_price_minor'=> 12345,
                            'currency'          => 'RUB',
                        'status'            => 'active',
                        ),
                    );
                }
            },
            new class( $activation_calls ) {
                public array $calls;

                public function __construct(array &$calls) {
                    $this->calls = &$calls;
                }

                public function check(string $url, int $user_id = 0): array {
                    $this->calls[] = array(
                        'url'     => $url,
                        'user_id' => $user_id,
                    );

                    return array(
                        'status'              => 'available',
                        'activation_required' => true,
                        'button_text'         => 'Активировать кэшбэк',
                    );
                }
            }
        );

        $request = new WP_REST_Request('POST', '/cashback/v1/price-monitor/items');
        $request->set_param('url', 'https://shop.example/item');
        $request->set_param('target_price_minor', 12345);
        $request->set_param('currency', 'RUB');
        $request->set_param('client_request_id', 'client-watch-123');

        $response = $controller->create_item($request);
        $data     = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertCount(4, $calls);
        self::assertSame('GET', $calls[0]['method']);
        self::assertSame('POST', $calls[1]['method']);
        self::assertSame('/api/v1/watchlist/items', $calls[1]['path']);
        self::assertSame('client-watch-123', $calls[1]['idempotency_key']);
        self::assertSame(
            array(
                'user_id'            => 'wp:savelloclub.test:77',
                'url'                => 'https://shop.example/item',
                'target_price_minor' => 12345,
                'currency'           => 'RUB',
            ),
            $calls[1]['payload']
        );
        self::assertSame('GET', $calls[2]['method']);
        self::assertSame('/api/v1/products/product-1', $calls[2]['path']);
        self::assertSame(array(), $calls[2]['payload']);
        self::assertSame('GET', $calls[3]['method']);
        self::assertSame('/api/v1/products/product-1/price-chart', $calls[3]['path']);
        self::assertSame(array( 'days' => 30 ), $calls[3]['payload']);
        self::assertTrue($data['created']);
        self::assertSame('item-1', $data['item']['id']);
        self::assertSame('Example Product', $data['product']['title']);
        self::assertSame('Shop Example', $data['source']['display_name']);
        self::assertSame('https://shop.example/item', $data['actions']['direct_url']);
        self::assertSame('RUB', $data['chart']['currency']);
        self::assertCount(1, $data['chart']['points']);
        self::assertSame('available', $data['activation']['status']);
        self::assertTrue($data['activation']['activation_required']);
        self::assertCount(1, $activation_calls);
        self::assertSame('https://shop.example/item', $activation_calls[0]['url']);
        self::assertSame(77, $activation_calls[0]['user_id']);
    }

    public function test_update_delete_and_refresh_forward_user_id_to_backend_routes(): void {
        $this->load_controller();

        $GLOBALS['_cb_test_is_logged_in'] = true;
        $GLOBALS['_cb_test_user_id']      = 77;

        $calls      = array();
        $controller = new Cashback_Price_Monitor_REST_Controller(
            new class( $calls ) {
                public array $calls;

                public function __construct(array &$calls) {
                    $this->calls = &$calls;
                }

                public function request(string $method, string $path, array $payload = array(), ?string $idempotency_key = null): array {
                    $this->calls[] = compact('method', 'path', 'payload', 'idempotency_key');

                    return match ($method) {
                        'PATCH' => array(
                            'item' => array(
                                'id'                 => 'item-1',
                                'target_price_minor' => 9999,
                            ),
                        ),
                        'POST' => array(
                            'scheduled'         => true,
                            'watchlist_item_id' => 'item-1',
                            'product_id'        => 'product-1',
                            'status'            => 'queued',
                        ),
                        'GET' => match ($path) {
                            '/api/v1/watchlist/items' => array(
                                'items' => array(
                                    array(
                                        'id'                 => 'item-1',
                                        'user_id'            => 'wp:savelloclub.test:77',
                                        'product_id'         => 'product-1',
                                        'canonical_url'      => 'https://shop.example/item',
                                        'target_price_minor' => 9999,
                                        'currency'           => 'RUB',
                                        'status'             => 'active',
                                    ),
                                ),
                            ),
                            '/api/v1/products/product-1' => array(
                                'product' => array(
                                    'title'               => 'Example Product Refreshed',
                                    'image_url'           => 'https://example.com/image.jpg',
                                    'rating_value'        => '4.9',
                                    'current_price_minor' => 10999,
                                    'currency'            => 'RUB',
                                ),
                                'source'  => array(
                                    'source_domain' => 'shop.example',
                                    'display_name'  => 'Shop Example',
                                    'logo_url'      => 'https://example.com/logo.png',
                                ),
                                'actions' => array(
                                    'direct_url' => 'https://shop.example/item',
                                ),
                            ),
                            '/api/v1/products/product-1/price-chart' => array(
                                'currency' => 'RUB',
                                'points'   => array(
                                    array(
                                        'date'            => '2026-06-29',
                                        'min_price_minor' => 12100,
                                        'max_price_minor' => 12100,
                                    ),
                                    array(
                                        'date'            => '2026-06-30',
                                        'min_price_minor' => 10999,
                                        'max_price_minor' => 10999,
                                    ),
                                ),
                            ),
                            default => array(),
                        },
                        default => array(),
                    };
                }
            },
            new class {
                public array $calls = array();

                public function check(string $url, int $user_id = 0): array {
                    $this->calls[] = array(
                        'url'     => $url,
                        'user_id' => $user_id,
                    );

                    return array(
                        'status'              => 'available',
                        'activation_required' => true,
                        'button_text'         => 'Активировать кэшбэк',
                    );
                }
            }
        );

        $update_request = new WP_REST_Request('PATCH', '/cashback/v1/price-monitor/items/item-1');
        $update_request->set_param('item_id', 'item-1');
        $update_request->set_param('target_price_minor', 9999);
        $update_request->set_param('client_request_id', 'client-watch-patch');

        $delete_request = new WP_REST_Request('DELETE', '/cashback/v1/price-monitor/items/item-1');
        $delete_request->set_param('item_id', 'item-1');
        $delete_request->set_param('client_request_id', 'client-watch-delete');

        $refresh_request = new WP_REST_Request('POST', '/cashback/v1/price-monitor/items/item-1/refresh');
        $refresh_request->set_param('item_id', 'item-1');
        $refresh_request->set_param('client_request_id', 'client-watch-refresh');

        $update_response  = $controller->update_item($update_request);
        $delete_response  = $controller->delete_item($delete_request);
        $refresh_response = $controller->refresh_item($refresh_request);

        self::assertSame(200, $update_response->get_status());
        self::assertSame(204, $delete_response->get_status());
        self::assertSame(200, $refresh_response->get_status());
        self::assertCount(6, $calls);

        self::assertSame('PATCH', $calls[0]['method']);
        self::assertSame('/api/v1/watchlist/items/item-1', $calls[0]['path']);
        self::assertSame('client-watch-patch', $calls[0]['idempotency_key']);
        self::assertSame(
            array(
                'user_id'            => 'wp:savelloclub.test:77',
                'target_price_minor' => 9999,
            ),
            $calls[0]['payload']
        );

        self::assertSame('DELETE', $calls[1]['method']);
        self::assertSame('/api/v1/watchlist/items/item-1', $calls[1]['path']);
        self::assertSame('client-watch-delete', $calls[1]['idempotency_key']);
        self::assertSame(
            array(
                'user_id' => 'wp:savelloclub.test:77',
            ),
            $calls[1]['payload']
        );

        self::assertSame('POST', $calls[2]['method']);
        self::assertSame('/api/v1/watchlist/items/item-1/refresh', $calls[2]['path']);
        self::assertSame('client-watch-refresh', $calls[2]['idempotency_key']);
        self::assertSame(
            array(
                'user_id' => 'wp:savelloclub.test:77',
            ),
            $calls[2]['payload']
        );

        self::assertSame('GET', $calls[3]['method']);
        self::assertSame('/api/v1/watchlist/items', $calls[3]['path']);
        self::assertSame(
            array(
                'user_id' => 'wp:savelloclub.test:77',
            ),
            $calls[3]['payload']
        );
        self::assertSame('GET', $calls[4]['method']);
        self::assertSame('/api/v1/products/product-1', $calls[4]['path']);
        self::assertSame('GET', $calls[5]['method']);
        self::assertSame('/api/v1/products/product-1/price-chart', $calls[5]['path']);
        self::assertSame(array( 'days' => 30 ), $calls[5]['payload']);

        $refresh_data = $refresh_response->get_data();
        self::assertTrue($refresh_data['scheduled']);
        self::assertSame('item-1', $refresh_data['item']['id']);
        self::assertSame('product-1', $refresh_data['item']['product_id']);
        self::assertSame('https://shop.example/item', $refresh_data['item']['canonical_url']);
        self::assertSame('Example Product Refreshed', $refresh_data['product']['title']);
        self::assertSame('Shop Example', $refresh_data['source']['display_name']);
        self::assertSame('https://shop.example/item', $refresh_data['actions']['direct_url']);
        self::assertSame('RUB', $refresh_data['chart']['currency']);
        self::assertCount(2, $refresh_data['chart']['points']);
        self::assertSame('available', $refresh_data['activation']['status']);
    }
}
