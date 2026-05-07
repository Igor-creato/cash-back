<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional тесты на Cashback_Admitad_Adapter::fetch_campaigns_detailed
 * (v12, Этап 3 shop importer).
 *
 * Проверяем:
 *   - Маппинг полей API → CampaignDetailDTO array shape;
 *   - Retry на 401 (refresh token) и 403 insufficient_scope;
 *   - Корректную обработку ошибок (HTTP 5xx, missing token);
 *   - Пагинацию через has_next/next_offset.
 *
 * Паттерн HTTP-моков заимствован из AdmitadAdapterRetryTest.
 *
 * @group adapters
 * @group admitad
 * @group shop-import
 */
#[Group('adapters')]
#[Group('admitad')]
#[Group('shop-import')]
final class AdmitadShopsDetailedTest extends TestCase
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

    private function default_credentials(): array
    {
        return array('client_id' => 'cid', 'client_secret' => 'csec', 'scope' => 'advcampaigns');
    }

    private function default_network_config(): array
    {
        return array('api_base_url' => 'https://api.admitad.com');
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

    private function fixture_campaigns_payload(): string
    {
        return wp_json_encode(array(
            '_meta'   => array('count' => 2),
            'results' => array(
                array(
                    'id'           => 12345,
                    'name'         => 'Joom',
                    'site_url'     => 'https://www.joom.com/ru',
                    'image'        => 'https://cdn.admitad.com/joom.png',
                    'description'  => 'Marketplace',
                    'status'       => 'active',
                    'currency'     => 'rub',
                    'goto_link'    => 'https://ad.admitad.com/g/abc?subid={uid}',
                    'regions'      => array(array('region' => 'RU'), array('region' => 'BY')),
                    'categories'   => array(
                        array('id' => 1, 'name' => 'Electronics'),
                        array('id' => 2, 'name' => 'Apparel'),
                    ),
                ),
                array(
                    'id'         => 22222,
                    'name'       => 'OldShop',
                    'site_url'   => 'https://oldshop.example',
                    'status'     => 'inactive',
                    'currency'   => 'USD',
                    'regions'    => array('RU', 'KZ'), // плоский формат
                    'categories' => array('Misc'),
                ),
            ),
        ));
    }

    // ============================================================
    // Success path
    // ============================================================

    public function test_returns_normalized_campaigns_on_success(): void
    {
        $this->queue_responses(array($this->http_response(200, $this->fixture_campaigns_payload())));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed(
            $this->default_credentials(),
            $this->default_network_config(),
            0,
            100
        );

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertCount(2, $result['campaigns']);

        $first = $result['campaigns'][0];
        $this->assertSame('12345', $first['id']);
        $this->assertSame('Joom', $first['name']);
        $this->assertSame('https://www.joom.com/ru', $first['site_url']);
        $this->assertSame('https://cdn.admitad.com/joom.png', $first['image_url']);
        $this->assertSame('active', $first['status_raw']);
        $this->assertTrue($first['is_active']);
        $this->assertSame('RUB', $first['currency'], 'currency uppercased');
        $this->assertSame(array('RU', 'BY'), $first['regions']);
        $this->assertSame(array('Electronics', 'Apparel'), $first['categories']);
        $this->assertSame('https://ad.admitad.com/g/abc?subid={uid}', $first['goto_link']);
        $this->assertIsArray($first['raw']);
    }

    public function test_inactive_status_marked_as_is_active_false(): void
    {
        $this->queue_responses(array($this->http_response(200, $this->fixture_campaigns_payload())));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $second = $result['campaigns'][1];
        $this->assertSame('inactive', $second['status_raw']);
        $this->assertFalse($second['is_active']);
        $this->assertSame('USD', $second['currency']);
        $this->assertSame(array('RU', 'KZ'), $second['regions'], 'regions из плоского формата');
        $this->assertSame(array('Misc'), $second['categories'], 'categories из плоского формата');
    }

    public function test_pagination_has_next_when_total_exceeds_returned(): void
    {
        $payload = wp_json_encode(array(
            '_meta'   => array('count' => 250),
            'results' => array_fill(0, 100, array('id' => 1, 'name' => 'X', 'status' => 'active')),
        ));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertTrue($result['has_next'], 'has_next=true когда total > offset+limit');
        $this->assertSame(100, $result['next_offset']);
    }

    public function test_pagination_has_next_false_on_last_page(): void
    {
        $payload = wp_json_encode(array(
            '_meta'   => array('count' => 50),
            'results' => array_fill(0, 50, array('id' => 1, 'name' => 'X', 'status' => 'active')),
        ));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertFalse($result['has_next'], 'has_next=false когда returned < limit');
    }

    public function test_empty_results_are_handled(): void
    {
        $payload = wp_json_encode(array('_meta' => array('count' => 0), 'results' => array()));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['campaigns']);
        $this->assertFalse($result['has_next']);
    }

    // ============================================================
    // Auth/retry paths
    // ============================================================

    public function test_retries_once_on_401_then_succeeds(): void
    {
        $this->queue_responses(array(
            $this->http_response(401, '{"error":"unauthorized"}'),
            $this->http_response(200, $this->fixture_campaigns_payload()),
        ));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertTrue($result['success'], '401 → invalidate token → retry должен дать success');
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls'], 'ровно 2 HTTP вызова (1 fail + 1 retry)');
        $this->assertSame(1, $adapter->invalidate_count, 'токен сбрасывается ровно один раз');
    }

    public function test_retries_once_on_403_insufficient_scope(): void
    {
        $this->queue_responses(array(
            $this->http_response(403, '{"error":"insufficient_scope","error_description":"scope advcampaigns required"}'),
            $this->http_response(200, $this->fixture_campaigns_payload()),
        ));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls']);
        $this->assertSame(1, $adapter->invalidate_count);
    }

    public function test_403_without_insufficient_scope_does_not_retry(): void
    {
        $this->queue_responses(array(
            $this->http_response(403, '{"error":"forbidden"}'),
        ));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertFalse($result['success']);
        $this->assertCount(1, $GLOBALS['_cb_test_http_calls'], 'обычный 403 не ретраится');
        $this->assertStringContainsString('403', $result['error']);
    }

    public function test_returns_error_on_http_500(): void
    {
        $this->queue_responses(array($this->http_response(500, '<html>nginx 500</html>')));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('500', $result['error']);
        $this->assertSame(0, $result['next_offset']);
        $this->assertFalse($result['has_next']);
    }

    public function test_returns_error_when_token_unavailable(): void
    {
        $adapter = $this->make_adapter_with_token('');
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('токен', mb_strtolower($result['error']));
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls'], 'без токена HTTP-запрос вообще не делается');
    }

    public function test_clamps_limit_to_500_max(): void
    {
        $this->queue_responses(array($this->http_response(200, '{"_meta":{"count":0},"results":[]}')));

        $adapter = $this->make_adapter_with_token();
        $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 9999);

        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
        $url = $GLOBALS['_cb_test_http_calls'][0]['url'];
        $this->assertStringContainsString('limit=500', $url, 'limit должен быть clamped к 500 максимум');
    }

    public function test_clamps_offset_to_zero_min(): void
    {
        $this->queue_responses(array($this->http_response(200, '{"_meta":{"count":0},"results":[]}')));

        $adapter = $this->make_adapter_with_token();
        $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), -50, 100);

        $url = $GLOBALS['_cb_test_http_calls'][0]['url'];
        $this->assertStringContainsString('offset=0', $url, 'отрицательный offset клемпится к 0');
    }

    public function test_invalid_currency_falls_back_to_rub(): void
    {
        $payload = wp_json_encode(array(
            '_meta'   => array('count' => 1),
            'results' => array(array('id' => 1, 'name' => 'X', 'status' => 'active', 'currency' => 'rubles')),
        ));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertSame('RUB', $result['campaigns'][0]['currency']);
    }
}
