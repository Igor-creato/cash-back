<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('price-comparison')]
final class PriceComparisonStoreAdminClientTest extends TestCase {

    public static function setUpBeforeClass(): void {
        require_once dirname(__DIR__, 3) . '/includes/price-comparison/class-cashback-price-comparison-client.php';
    }

    protected function setUp(): void {
        $GLOBALS['_cb_test_options'] = array(
            Cashback_Price_Comparison_Client::OPTION_ENABLED     => 1,
            Cashback_Price_Comparison_Client::OPTION_BASE_URL    => 'https://price-service.test',
            Cashback_Price_Comparison_Client::OPTION_HMAC_SECRET => 'test-secret',
            Cashback_Price_Comparison_Client::OPTION_TIMEOUT     => 5,
        );
        $GLOBALS['_cb_test_http_calls']             = array();
        $GLOBALS['_cb_test_http_response_callback'] = null;
    }

    public function test_client_lists_stores_with_hmac_headers(): void {
        $GLOBALS['_cb_test_http_response'] = array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode(array(
                'status' => 'ok',
                'items'  => array(
                    array( 'id' => 1, 'domain' => 'ozon.ru', 'display_name' => 'Ozon' ),
                ),
            )),
        );

        $result = (new Cashback_Price_Comparison_Client())->list_stores();

        self::assertIsArray($result);
        self::assertSame('ozon.ru', $result['items'][0]['domain']);
        self::assertCount(1, $GLOBALS['_cb_test_http_calls']);
        $call = $GLOBALS['_cb_test_http_calls'][0];
        self::assertSame('GET', $call['method']);
        self::assertSame('https://price-service.test/api/v1/stores', $call['url']);
        self::assertSame(hash('sha256', ''), $call['args']['headers']['X-Body-SHA256']);
        self::assertNotEmpty($call['args']['headers']['X-Signature']);
    }

    public function test_client_updates_store_with_patch_override_and_no_secret_leak(): void {
        $GLOBALS['_cb_test_http_response'] = array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode(array(
                'id'           => 5,
                'domain'       => 'ozon.ru',
                'display_name' => 'Ozon',
                'active'       => false,
            )),
        );

        $result = (new Cashback_Price_Comparison_Client())->update_store(5, array( 'active' => false ));

        self::assertIsArray($result);
        self::assertFalse($result['active']);
        $call = $GLOBALS['_cb_test_http_calls'][0];
        self::assertSame('https://price-service.test/api/v1/stores/5', $call['url']);
        self::assertSame('PATCH', $call['args']['method']);
        self::assertJsonStringEqualsJsonString('{"active":false}', $call['args']['body']);
        self::assertStringNotContainsString('test-secret', wp_json_encode($call));
    }

    public function test_client_starts_and_polls_live_search_with_hmac_headers(): void {
        $GLOBALS['_cb_test_http_response_callback'] = static function ( string $url, array $args ): array {
            if (str_ends_with($url, '/api/v1/live-search/runs')) {
                return array(
                    'response' => array( 'code' => 202 ),
                    'body'     => wp_json_encode(array(
                        'status'   => 'accepted',
                        'run_id'   => 'run_1234',
                        'poll_url' => '/api/v1/live-search/runs/run_1234',
                    )),
                );
            }

            return array(
                'response' => array( 'code' => 200 ),
                'body'     => wp_json_encode(array(
                    'status' => 'ok',
                    'items'  => array(),
                )),
            );
        };

        $client = new Cashback_Price_Comparison_Client();
        $start  = $client->start_live_search(array(
            'query'  => 'телевизор',
            'city'   => 'Пенза',
            'stores' => array( 'fixture.test' ),
            'limit'  => 20,
        ));
        $poll   = $client->get_live_search('run_1234');

        self::assertSame('accepted', $start['status']);
        self::assertSame('ok', $poll['status']);
        self::assertSame('POST', $GLOBALS['_cb_test_http_calls'][0]['method']);
        self::assertSame('https://price-service.test/api/v1/live-search/runs', $GLOBALS['_cb_test_http_calls'][0]['url']);
        self::assertSame('GET', $GLOBALS['_cb_test_http_calls'][1]['method']);
        self::assertSame('https://price-service.test/api/v1/live-search/runs/run_1234', $GLOBALS['_cb_test_http_calls'][1]['url']);
        self::assertNotEmpty($GLOBALS['_cb_test_http_calls'][0]['args']['headers']['X-Signature']);
        self::assertStringNotContainsString('test-secret', wp_json_encode($GLOBALS['_cb_test_http_calls']));
    }

    public function test_client_starts_feed_import_with_hmac_headers(): void {
        $GLOBALS['_cb_test_http_response'] = array(
            'response' => array( 'code' => 202 ),
            'body'     => wp_json_encode(array(
                'status'   => 'accepted',
                'task_id'  => 'feed-import-task-123',
                'poll_url' => '/api/v1/stores',
            )),
        );

        $result = (new Cashback_Price_Comparison_Client())->start_feed_import();

        self::assertSame('accepted', $result['status']);
        self::assertSame('feed-import-task-123', $result['task_id']);
        self::assertCount(1, $GLOBALS['_cb_test_http_calls']);
        $call = $GLOBALS['_cb_test_http_calls'][0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://price-service.test/api/v1/feed-import/runs', $call['url']);
        self::assertArrayNotHasKey('body', $call['args']);
        self::assertNotEmpty($call['args']['headers']['X-Signature']);
        self::assertStringNotContainsString('test-secret', wp_json_encode($call));
    }
}
