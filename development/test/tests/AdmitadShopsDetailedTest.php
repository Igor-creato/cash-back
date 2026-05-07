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
        return array(
            'api_base_url'    => 'https://api.admitad.com',
            'api_website_id'  => '42',
        );
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
                    'id'                => 12345,
                    'name'              => 'Joom',
                    'site_url'          => 'https://www.joom.com/ru',
                    'image'             => 'https://cdn.admitad.com/joom.png',
                    'description'       => 'Marketplace',
                    'status'            => 'active',
                    'connection_status' => 'active',
                    'currency'          => 'rub',
                    'goto_link'         => 'https://ad.admitad.com/g/abc?subid={uid}',
                    'regions'           => array(array('region' => 'RU'), array('region' => 'BY')),
                    'categories'        => array(
                        array('id' => 1, 'name' => 'Electronics'),
                        array('id' => 2, 'name' => 'Apparel'),
                    ),
                ),
                array(
                    'id'                => 22222,
                    'name'              => 'OldShop',
                    'site_url'          => 'https://oldshop.example',
                    'status'            => 'inactive',
                    'connection_status' => 'active',
                    'currency'          => 'USD',
                    'regions'           => array('RU', 'KZ'), // плоский формат
                    'categories'        => array('Misc'),
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
            'results' => array_fill(0, 100, array(
                'id'                => 1,
                'name'              => 'X',
                'status'            => 'active',
                'connection_status' => 'active',
            )),
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
            'results' => array_fill(0, 50, array(
                'id'                => 1,
                'name'              => 'X',
                'status'            => 'active',
                'connection_status' => 'active',
            )),
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
            'results' => array(array(
                'id'                => 1,
                'name'              => 'X',
                'status'            => 'active',
                'connection_status' => 'active',
                'currency'          => 'rubles',
            )),
        ));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertSame('RUB', $result['campaigns'][0]['currency']);
    }

    // ============================================================
    // Website-scoped endpoint + connection_status filter
    // ============================================================

    public function test_returns_error_when_website_id_missing(): void
    {
        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed(
            $this->default_credentials(),
            array('api_base_url' => 'https://api.admitad.com'), // no api_website_id
            0,
            100
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('api_website_id', $result['error']);
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls'], 'без website_id HTTP-запрос вообще не делается');
    }

    public function test_returns_error_when_website_id_empty_string(): void
    {
        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed(
            $this->default_credentials(),
            array('api_base_url' => 'https://api.admitad.com', 'api_website_id' => '   '),
            0,
            100
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('api_website_id', $result['error']);
    }

    public function test_url_uses_website_scoped_endpoint(): void
    {
        $this->queue_responses(array($this->http_response(200, $this->fixture_campaigns_payload())));

        $adapter = $this->make_adapter_with_token();
        $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
        $url = $GLOBALS['_cb_test_http_calls'][0]['url'];
        $this->assertStringContainsString(
            '/advcampaigns/website/42/',
            $url,
            'URL должен использовать website-scoped endpoint, а не общий /advcampaigns/'
        );
        $this->assertStringNotContainsString(
            '/advcampaigns/?',
            $url,
            'НЕ должен бить в общий /advcampaigns/?... (он возвращает весь каталог)'
        );
    }

    public function test_filters_out_pending_and_declined_connection_status(): void
    {
        $payload = wp_json_encode(array(
            '_meta'   => array('count' => 4),
            'results' => array(
                array('id' => 1, 'name' => 'Active', 'status' => 'active', 'connection_status' => 'active'),
                array('id' => 2, 'name' => 'Pending', 'status' => 'active', 'connection_status' => 'pending'),
                array('id' => 3, 'name' => 'Declined', 'status' => 'active', 'connection_status' => 'declined'),
                array('id' => 4, 'name' => 'Suspend', 'status' => 'active', 'connection_status' => 'suspend'),
            ),
        ));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['campaigns'], 'только connection_status=active');
        $this->assertSame('1', $result['campaigns'][0]['id']);
        $this->assertSame('Active', $result['campaigns'][0]['name']);
    }

    public function test_keeps_campaigns_with_empty_connection_status_for_backward_compat(): void
    {
        // Если ответ от старого endpoint без поля — допускаем (для тестов и
        // потенциального override через filter в будущем).
        $payload = wp_json_encode(array(
            '_meta'   => array('count' => 1),
            'results' => array(
                array('id' => 1, 'name' => 'NoConnField', 'status' => 'active'),
            ),
        ));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertCount(1, $result['campaigns']);
        $this->assertSame('', $result['campaigns'][0]['connection_status']);
    }

    public function test_connection_status_normalized_to_lowercase(): void
    {
        $payload = wp_json_encode(array(
            '_meta'   => array('count' => 2),
            'results' => array(
                array('id' => 1, 'name' => 'CapsActive', 'status' => 'active', 'connection_status' => 'ACTIVE'),
                array('id' => 2, 'name' => 'MixedPending', 'status' => 'active', 'connection_status' => 'Pending'),
            ),
        ));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        // 'ACTIVE' → 'active' пройдёт фильтр; 'Pending' → 'pending' отбросится.
        $this->assertCount(1, $result['campaigns']);
        $this->assertSame('active', $result['campaigns'][0]['connection_status']);
    }

    public function test_url_encodes_website_id_special_chars(): void
    {
        // Если кто-то задал api_website_id со знаком (теоретически не должно
        // быть, но для безопасности проверим rawurlencode).
        $this->queue_responses(array($this->http_response(200, $this->fixture_campaigns_payload())));

        $adapter = $this->make_adapter_with_token();
        $adapter->fetch_campaigns_detailed(
            $this->default_credentials(),
            array('api_base_url' => 'https://api.admitad.com', 'api_website_id' => '42&evil'),
            0,
            100
        );

        $url = $GLOBALS['_cb_test_http_calls'][0]['url'];
        $this->assertStringContainsString('/advcampaigns/website/42%26evil/', $url);
    }

    // ============================================================
    // Inline tariffs parsing (actions_detail) — фикс HTTP 404 на /actions/.
    // ============================================================

    private function fixture_campaign_with_actions_detail(array $actions_detail, string $currency = 'rub'): string
    {
        return wp_json_encode(array(
            '_meta'   => array('count' => 1),
            'results' => array(array(
                'id'                => 2381,
                'name'              => 'Kaspersky',
                'site_url'          => 'https://www.kaspersky.ru',
                'image'             => 'https://cdn.example.com/logo.png',
                'status'            => 'active',
                'connection_status' => 'active',
                'currency'          => $currency,
                'actions_detail'    => $actions_detail,
            )),
        ));
    }

    public function test_inline_tariffs_extracted_from_actions_detail_percent(): void
    {
        $actions = array(array(
            'tariffs' => array(array(
                'action_id' => 8724,
                'id'        => 10595,
                'name'      => 'Default rate',
                'rates'     => array(array(
                    'size'          => '20.62',
                    'is_percentage' => true,
                    'country'       => null,
                    'date_s'        => '2026-03-10',
                    'price_s'       => '0.00',
                    'tariff_id'     => 10595,
                    'id'            => 1794877,
                )),
            )),
        ));
        $this->queue_responses(array($this->http_response(200, $this->fixture_campaign_with_actions_detail($actions))));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $first = $result['campaigns'][0];
        $this->assertArrayHasKey('inline_tariffs', $first);
        $this->assertCount(1, $first['inline_tariffs']);

        $tariff = $first['inline_tariffs'][0];
        $this->assertSame('10595', $tariff['tariff_id']);
        $this->assertSame('Default rate', $tariff['name']);
        $this->assertSame('percent', $tariff['tariff_type']);
        $this->assertSame(20.62, $tariff['payment_size']);
        $this->assertNull($tariff['payment_min']);
        $this->assertNull($tariff['payment_max']);
        $this->assertSame('RUB', $tariff['currency'], 'currency наследуется от родителя');
        $this->assertTrue($tariff['is_default'], '"Default rate" → is_default=true');
    }

    public function test_inline_tariffs_handles_fix_type_with_size_field(): void
    {
        $actions = array(array(
            'tariffs' => array(array(
                'id'    => 555,
                'name'  => 'Fixed reward',
                'rates' => array(array(
                    'size'          => '150.00',
                    'is_percentage' => false,
                    'country'       => null,
                )),
            )),
        ));
        $this->queue_responses(array($this->http_response(200, $this->fixture_campaign_with_actions_detail($actions, 'eur'))));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $tariff = $result['campaigns'][0]['inline_tariffs'][0];
        $this->assertSame('fix', $tariff['tariff_type']);
        $this->assertSame(150.00, $tariff['payment_size']);
        $this->assertSame('EUR', $tariff['currency']);
        $this->assertFalse($tariff['is_default']);
    }

    public function test_inline_tariffs_filters_country_specific_rates(): void
    {
        $actions = array(array(
            'tariffs' => array(array(
                'id'    => 1001,
                'name'  => 'Multi-country tariff',
                'rates' => array(
                    array('size' => '5.00', 'is_percentage' => true, 'country' => 'RU'),
                    array('size' => '7.50', 'is_percentage' => true, 'country' => null),
                    array('size' => '10.00', 'is_percentage' => true, 'country' => 'BY'),
                ),
            )),
        ));
        $this->queue_responses(array($this->http_response(200, $this->fixture_campaign_with_actions_detail($actions))));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $tariff = $result['campaigns'][0]['inline_tariffs'][0];
        $this->assertSame(7.50, $tariff['payment_size'], 'берётся первый rate с country=null');
    }

    public function test_inline_tariffs_skips_tariff_with_no_null_country_rate(): void
    {
        $actions = array(array(
            'tariffs' => array(array(
                'id'    => 2002,
                'name'  => 'RU-only',
                'rates' => array(
                    array('size' => '5.00', 'is_percentage' => true, 'country' => 'RU'),
                ),
            )),
        ));
        $this->queue_responses(array($this->http_response(200, $this->fixture_campaign_with_actions_detail($actions))));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertSame(array(), $result['campaigns'][0]['inline_tariffs']);
    }

    public function test_inline_tariffs_skips_tariff_with_empty_rates(): void
    {
        $actions = array(array(
            'tariffs' => array(array(
                'id'    => 3003,
                'name'  => 'No rates',
                'rates' => array(),
            )),
        ));
        $this->queue_responses(array($this->http_response(200, $this->fixture_campaign_with_actions_detail($actions))));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertSame(array(), $result['campaigns'][0]['inline_tariffs']);
    }

    public function test_inline_tariffs_collects_multiple_tariffs_per_action(): void
    {
        $actions = array(array(
            'tariffs' => array(
                array(
                    'id'    => 1,
                    'name'  => 'Default rate',
                    'rates' => array(array('size' => '15.00', 'is_percentage' => true, 'country' => null)),
                ),
                array(
                    'id'    => 2,
                    'name'  => 'Free order',
                    'rates' => array(array('size' => '0.00', 'is_percentage' => true, 'country' => null)),
                ),
            ),
        ));
        $this->queue_responses(array($this->http_response(200, $this->fixture_campaign_with_actions_detail($actions))));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $tariffs = $result['campaigns'][0]['inline_tariffs'];
        $this->assertCount(2, $tariffs);
        $this->assertSame('1', $tariffs[0]['tariff_id']);
        $this->assertSame('2', $tariffs[1]['tariff_id']);
        $this->assertTrue($tariffs[0]['is_default']);
        $this->assertFalse($tariffs[1]['is_default']);
    }

    public function test_inline_tariffs_empty_when_actions_detail_absent(): void
    {
        $payload = wp_json_encode(array(
            '_meta'   => array('count' => 1),
            'results' => array(array(
                'id'                => 9999,
                'name'              => 'NoActions',
                'status'            => 'active',
                'connection_status' => 'active',
            )),
        ));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertArrayHasKey('inline_tariffs', $result['campaigns'][0]);
        $this->assertSame(array(), $result['campaigns'][0]['inline_tariffs']);
    }

    public function test_inline_tariffs_skips_tariff_with_missing_id(): void
    {
        $actions = array(array(
            'tariffs' => array(array(
                'name'  => 'No ID',
                'rates' => array(array('size' => '5', 'is_percentage' => true, 'country' => null)),
            )),
        ));
        $this->queue_responses(array($this->http_response(200, $this->fixture_campaign_with_actions_detail($actions))));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertSame(array(), $result['campaigns'][0]['inline_tariffs']);
    }
}
