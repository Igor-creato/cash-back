<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional тесты на Cashback_Admitad_Adapter::fetch_shop_tariffs
 * (v12, Этап 3).
 *
 * Endpoint: GET /advcampaigns/{id}/actions/?limit=500.
 * Возвращает массив тарифов action'а с полями id, name, type
 * ('percent'/'fixed'), payment_size, payment_size_min/max.
 *
 * @group adapters
 * @group admitad
 * @group shop-import
 */
#[Group('adapters')]
#[Group('admitad')]
#[Group('shop-import')]
final class AdmitadShopTariffsTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        self::require_if_missing('/includes/class-cashback-outbound-http-guard.php', 'Cashback_Outbound_HTTP_Guard');
        self::require_if_missing('/includes/oauth/class-oauth2-client-credentials-helper.php', 'Cashback_OAuth2_Client_Credentials_Helper');
        self::require_if_missing('/includes/adapters/interface-cashback-network-adapter.php', null);
        self::require_if_missing('/includes/adapters/abstract-cashback-network-adapter.php', 'Cashback_Network_Adapter_Base');
        self::require_if_missing('/includes/adapters/class-admitad-adapter.php', 'Cashback_Admitad_Adapter');
    }

    private static function require_if_missing(string $relative, ?string $class): void
    {
        if ($class !== null && (class_exists($class) || interface_exists($class))) {
            return;
        }
        $path = self::$plugin_root . $relative;
        if (!file_exists($path)) {
            self::markTestSkipped("File missing: {$relative}");
            return;
        }
        require_once $path;
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options']                = array();
        $GLOBALS['_cb_test_filters']                = array();
        $GLOBALS['_cb_test_transients']             = array();
        $GLOBALS['_cb_test_cache']                  = array();
        $GLOBALS['_cb_test_http_calls']             = array();
        $GLOBALS['_cb_test_http_response_callback'] = null;

        if (class_exists('Cashback_Outbound_HTTP_Guard')) {
            Cashback_Outbound_HTTP_Guard::invalidate_cache();
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['_cb_test_http_response_callback'] = null;
        $GLOBALS['_cb_test_http_calls']             = array();
        $GLOBALS['_cb_test_filters']                = array();
    }

    private function make_adapter_with_token(string $token = 'tok-xyz'): Cashback_Admitad_Adapter
    {
        return new class($token) extends Cashback_Admitad_Adapter {
            public int $invalidate_count = 0;

            public function __construct(private string $stub_token) {}

            public function get_token(array $credentials, array $network_config): ?string
            {
                return $this->stub_token === '' ? null : $this->stub_token;
            }

            public function build_auth_headers(array $credentials, array $network_config): ?array
            {
                if ($this->stub_token === '') {
                    return null;
                }
                return array('Authorization' => 'Bearer ' . $this->stub_token);
            }

            public function invalidate_token(array $credentials): void
            {
                $this->invalidate_count++;
            }
        };
    }

    private function http_response(int $code, string $body = ''): array
    {
        return array(
            'body'     => $body,
            'response' => array('code' => $code, 'message' => 'HTTP ' . $code),
            'headers'  => array(),
        );
    }

    private function queue_responses(array $responses): void
    {
        $queue = $responses;
        $GLOBALS['_cb_test_http_response_callback'] = static function (string $url, array $args) use (&$queue) {
            if (count($queue) > 1) {
                return array_shift($queue);
            }
            return $queue[0] ?? array(
                'body'     => '',
                'response' => array('code' => 500, 'message' => 'Internal'),
                'headers'  => array(),
            );
        };
    }

    private function default_credentials(): array
    {
        return array('client_id' => 'cid', 'client_secret' => 'csec');
    }

    private function default_network_config(): array
    {
        return array('api_base_url' => 'https://api.admitad.com');
    }

    private function fixture_actions_payload(): string
    {
        // Реальный shape /advcampaigns/{id}/actions/ — массив тарифов.
        return wp_json_encode(array(
            'results' => array(
                array(
                    'id'                => 'cat-5',
                    'name'              => 'Оплаченный заказ из категории 5',
                    'type'              => 'percent',
                    'payment_size'      => '15.05',
                    'payment_size_min'  => '0.5',
                    'payment_size_max'  => '500.0',
                    'currency'          => 'RUB',
                    'is_default'        => true,
                ),
                array(
                    'id'           => 'flat-eu',
                    'name'         => 'Оплаченный заказ из категории 1',
                    'type'         => 'fixed',
                    'payment_size' => 65.0,
                    'currency'     => 'EUR',
                    'is_default'   => false,
                ),
                array(
                    'id'           => 'cat-3',
                    'name'         => 'Оплаченный заказ из категории 3',
                    'type'         => 'percent',
                    'payment_size' => 3.92,
                ),
            ),
        ));
    }

    // ============================================================
    // Validation
    // ============================================================

    public function test_returns_error_when_campaign_id_empty(): void
    {
        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs(
            $this->default_credentials(),
            $this->default_network_config(),
            ''
        );

        $this->assertFalse($result['success']);
        $this->assertSame(array(), $result['tariffs']);
        $this->assertStringContainsString('campaign_id', $result['error']);
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_returns_error_when_token_unavailable(): void
    {
        $adapter = $this->make_adapter_with_token('');
        $result  = $adapter->fetch_shop_tariffs(
            $this->default_credentials(),
            $this->default_network_config(),
            '12345'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('токен', mb_strtolower($result['error']));
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls']);
    }

    // ============================================================
    // Success path
    // ============================================================

    public function test_returns_normalized_tariffs_on_success(): void
    {
        $this->queue_responses(array($this->http_response(200, $this->fixture_actions_payload())));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs(
            $this->default_credentials(),
            $this->default_network_config(),
            '12345'
        );

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertCount(3, $result['tariffs']);
    }

    public function test_url_includes_campaign_id_and_actions_endpoint(): void
    {
        $this->queue_responses(array($this->http_response(200, '{"results":[]}')));

        $adapter = $this->make_adapter_with_token();
        $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '12345');

        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
        $url = $GLOBALS['_cb_test_http_calls'][0]['url'];
        $this->assertStringContainsString('/advcampaigns/12345/actions/', $url);
        $this->assertStringContainsString('limit=500', $url);
    }

    public function test_percent_type_normalized(): void
    {
        $this->queue_responses(array($this->http_response(200, $this->fixture_actions_payload())));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '12345');

        $first = $result['tariffs'][0];
        $this->assertSame('cat-5', $first['tariff_id']);
        $this->assertSame('percent', $first['tariff_type']);
        $this->assertSame(15.05, $first['payment_size']);
        $this->assertSame(0.5, $first['payment_min']);
        $this->assertSame(500.0, $first['payment_max']);
        $this->assertSame('RUB', $first['currency']);
        $this->assertTrue($first['is_default']);
        $this->assertIsArray($first['raw']);
    }

    public function test_fixed_type_normalized_to_fix(): void
    {
        $this->queue_responses(array($this->http_response(200, $this->fixture_actions_payload())));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '12345');

        $second = $result['tariffs'][1];
        $this->assertSame('flat-eu', $second['tariff_id']);
        $this->assertSame('fix', $second['tariff_type'], 'Admitad type "fixed" → нормализуется к "fix"');
        $this->assertSame(65.0, $second['payment_size']);
        $this->assertNull($second['payment_min']);
        $this->assertNull($second['payment_max']);
        $this->assertSame('EUR', $second['currency']);
        $this->assertFalse($second['is_default']);
    }

    public function test_missing_min_max_become_null(): void
    {
        $this->queue_responses(array($this->http_response(200, $this->fixture_actions_payload())));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '12345');

        $third = $result['tariffs'][2];
        $this->assertSame('cat-3', $third['tariff_id']);
        $this->assertNull($third['payment_min']);
        $this->assertNull($third['payment_max']);
        $this->assertSame('RUB', $third['currency'], 'currency default = RUB');
    }

    public function test_skips_entries_without_id(): void
    {
        $payload = wp_json_encode(array(
            'results' => array(
                array('id' => '', 'name' => 'no-id', 'type' => 'percent', 'payment_size' => 1),
                array('id' => 'valid', 'name' => 'OK', 'type' => 'percent', 'payment_size' => 2),
            ),
        ));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '1');

        $this->assertCount(1, $result['tariffs']);
        $this->assertSame('valid', $result['tariffs'][0]['tariff_id']);
    }

    public function test_unknown_type_falls_back_via_name_heuristic(): void
    {
        $payload = wp_json_encode(array(
            'results' => array(
                array('id' => 'p1', 'name' => 'Бонус 5 процентов', 'type' => '', 'payment_size' => 5),
                array('id' => 'f1', 'name' => 'Фиксированная выплата 100 руб', 'type' => '', 'payment_size' => 100),
                array('id' => 'd1', 'name' => 'Без указания типа', 'type' => '', 'payment_size' => 7),
            ),
        ));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '1');

        $this->assertSame('percent', $result['tariffs'][0]['tariff_type'], 'name содержит "процент" → percent');
        $this->assertSame('fix', $result['tariffs'][1]['tariff_type'], 'name содержит "Фикс" + "руб" → fix');
        $this->assertSame('percent', $result['tariffs'][2]['tariff_type'], 'fallback default = percent');
    }

    // ============================================================
    // Auth/retry paths
    // ============================================================

    public function test_retries_once_on_401(): void
    {
        $this->queue_responses(array(
            $this->http_response(401, '{"error":"unauthorized"}'),
            $this->http_response(200, $this->fixture_actions_payload()),
        ));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '12345');

        $this->assertTrue($result['success']);
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls']);
        $this->assertSame(1, $adapter->invalidate_count);
    }

    public function test_returns_error_on_http_500(): void
    {
        $this->queue_responses(array($this->http_response(500, '<html>500</html>')));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '12345');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('500', $result['error']);
    }

    public function test_returns_error_on_http_404(): void
    {
        $this->queue_responses(array($this->http_response(404, '{"error":"campaign not found"}')));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '99999');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('404', $result['error']);
    }

    public function test_empty_results_returns_empty_tariffs_array(): void
    {
        $this->queue_responses(array($this->http_response(200, '{"results":[]}')));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '12345');

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['tariffs']);
    }
}
