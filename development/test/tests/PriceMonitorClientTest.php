<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('price-monitor')]
final class PriceMonitorClientTest extends TestCase {

    private const SECRET = 'price-monitor-secret';

    private function client_path(): string {
        return dirname(__DIR__, 3) . '/includes/price-monitor/class-cashback-price-monitor-client.php';
    }

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['_cb_test_options']        = array();
        $GLOBALS['_cb_test_http_calls']     = array();
        $GLOBALS['_cb_test_http_response']  = array(
            'body'     => '',
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'headers'  => array(),
        );
    }

    public function test_request_signs_supported_source_get_with_exact_query_target(): void {
        $path = $this->client_path();
        self::assertFileExists($path, 'Price monitor client class must exist before GET signing can work.');
        require_once $path;

        update_option('cashback_price_monitor_backend_url', 'https://backend.example');
        update_option('cashback_price_monitor_backend_secret', self::SECRET);
        update_option('cashback_price_monitor_enabled', 1);

        $captured = array();
        $client   = new Cashback_Price_Monitor_Client(
            transport: static function ( string $method, string $url, array $args ) use ( &$captured ): array {
                $captured[] = array(
                    'method' => $method,
                    'url'    => $url,
                    'args'   => $args,
                );

                return array(
                    'body'     => wp_json_encode(array( 'supported' => true )),
                    'response' => array( 'code' => 200, 'message' => 'OK' ),
                    'headers'  => array(),
                );
            },
            time_source: static fn (): string => '1719744000',
            request_id_source: static fn (): string => 'req-supported-source'
        );

        $result = $client->request(
            'GET',
            '/api/v1/sources/supported',
            array(
                'url' => 'https://shop.example/item?a=1&b=2',
            )
        );

        self::assertSame(array( 'supported' => true ), $result);
        self::assertCount(1, $captured);
        self::assertSame('GET', $captured[0]['method']);
        self::assertSame(
            'https://backend.example/api/v1/sources/supported?url=https%3A%2F%2Fshop.example%2Fitem%3Fa%3D1%26b%3D2',
            $captured[0]['url']
        );

        $headers       = $captured[0]['args']['headers'];
        $expected_path = '/api/v1/sources/supported?url=https%3A%2F%2Fshop.example%2Fitem%3Fa%3D1%26b%3D2';
        $expected_hash = hash('sha256', '');
        $expected_sig  = hash_hmac(
            'sha256',
            "GET\n{$expected_path}\n1719744000\nreq-supported-source\n{$expected_hash}",
            self::SECRET
        );

        self::assertSame('req-supported-source', $headers['X-Request-Id']);
        self::assertSame('1719744000', $headers['X-Request-Timestamp']);
        self::assertSame($expected_hash, $headers['X-Body-SHA256']);
        self::assertSame($expected_sig, $headers['X-Signature']);
        self::assertArrayNotHasKey('body', $captured[0]['args']);
    }

    public function test_request_signs_post_body_and_keeps_secret_redacted(): void {
        $path = $this->client_path();
        self::assertFileExists($path, 'Price monitor client class must exist before POST signing can work.');
        require_once $path;

        update_option('cashback_price_monitor_backend_url', 'https://backend.example/');
        update_option('cashback_price_monitor_backend_secret', self::SECRET);
        update_option('cashback_price_monitor_enabled', 1);

        $captured = array();
        $client   = new Cashback_Price_Monitor_Client(
            transport: static function ( string $method, string $url, array $args ) use ( &$captured ): array {
                $captured[] = array(
                    'method' => $method,
                    'url'    => $url,
                    'args'   => $args,
                );

                return array(
                    'body'     => wp_json_encode(array( 'created' => true )),
                    'response' => array( 'code' => 201, 'message' => 'Created' ),
                    'headers'  => array(),
                );
            },
            time_source: static fn (): string => '1719744015',
            request_id_source: static fn (): string => 'req-watchlist-create'
        );

        $payload = array(
            'external_user_id'    => 'wp:savelloclub.test:77',
            'url'                 => 'https://shop.example/item',
            'target_price_minor'  => 12345,
            'currency'            => 'RUB',
        );

        $result = $client->request(
            'POST',
            '/api/v1/watchlist/items',
            $payload,
            'idem-watchlist-create'
        );

        self::assertSame(array( 'created' => true ), $result);
        self::assertCount(1, $captured);
        self::assertSame('POST', $captured[0]['method']);
        self::assertSame('https://backend.example/api/v1/watchlist/items', $captured[0]['url']);

        $expected_body = '{"external_user_id":"wp:savelloclub.test:77","url":"https://shop.example/item","target_price_minor":12345,"currency":"RUB"}';
        $expected_hash = hash('sha256', $expected_body);
        $expected_sig  = hash_hmac(
            'sha256',
            "POST\n/api/v1/watchlist/items\n1719744015\nreq-watchlist-create\n{$expected_hash}",
            self::SECRET
        );

        self::assertSame($expected_body, $captured[0]['args']['body']);
        self::assertSame($expected_hash, $captured[0]['args']['headers']['X-Body-SHA256']);
        self::assertSame($expected_sig, $captured[0]['args']['headers']['X-Signature']);
        self::assertSame('idem-watchlist-create', $captured[0]['args']['headers']['Idempotency-Key']);

        $redacted = $client->redacted_settings();

        self::assertIsArray($redacted);
        self::assertArrayHasKey('backend_secret', $redacted);
        self::assertNotSame(self::SECRET, $redacted['backend_secret']);
        self::assertStringNotContainsString(self::SECRET, (string) wp_json_encode($redacted));
    }
}
