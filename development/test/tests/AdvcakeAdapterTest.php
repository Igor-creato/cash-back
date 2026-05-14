<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit-тесты Cashback_Advcake_Adapter.
 *
 * Покрытие:
 *  - XML парсинг (валидный, malformed, empty, XXE-safe);
 *  - подстановка {token} в URL пути;
 *  - status mapping (1/2/3 → waiting/completed/declined);
 *  - 5xx retry с exp backoff (filter обнуляет sleep);
 *  - 401/403 — фатально (без invalidate-noop);
 *  - clamp окна реконсилиации до 7 дней;
 *  - stub'ы для fetch_campaigns/_detailed/_tariffs;
 *  - валидация токена (пустой / с недопустимыми символами).
 *
 * @group adapters
 * @group advcake
 */
#[Group('adapters')]
#[Group('advcake')]
final class AdvcakeAdapterTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        self::require_if_missing('/includes/class-cashback-outbound-http-guard.php', 'Cashback_Outbound_HTTP_Guard');
        self::require_if_missing('/includes/adapters/interface-cashback-network-adapter.php', null);
        self::require_if_missing('/includes/adapters/abstract-cashback-network-adapter.php', 'Cashback_Network_Adapter_Base');
        self::require_if_missing('/includes/adapters/class-cashback-advcake-adapter.php', 'Cashback_Advcake_Adapter');
    }

    private static function require_if_missing(string $relative, ?string $class): void
    {
        if ($class !== null && class_exists($class)) {
            return;
        }
        $path = self::$plugin_root . $relative;
        if (!file_exists($path)) {
            self::markTestSkipped("File missing: {$relative}");
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
        $GLOBALS['_cb_test_http_response']          = array(
            'body'     => '<items></items>',
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'headers'  => array(),
        );
        $GLOBALS['_cb_test_http_response_callback'] = null;

        // Фильтр обнуляет backoff — тесты не должны спать.
        add_filter(
            'cashback_advcake_5xx_retry_delay_seconds',
            static fn(): int => 0,
            10,
            3
        );

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

    private function queue_responses(array $responses): void
    {
        $queue = $responses;
        $GLOBALS['_cb_test_http_response_callback'] = static function (string $url, array $args) use (&$queue) {
            if (count($queue) > 1) {
                return array_shift($queue);
            }
            return $queue[0] ?? array(
                'body'     => '',
                'response' => array( 'code' => 500, 'message' => 'Internal Server Error' ),
                'headers'  => array(),
            );
        };
    }

    private function http_response(int $code, string $body = ''): array
    {
        return array(
            'body'     => $body,
            'response' => array( 'code' => $code, 'message' => 'HTTP ' . $code ),
            'headers'  => array(),
        );
    }

    private function default_credentials(): array
    {
        return array( 'api_key' => 'REDACTED_ADVCAKE_TEST_KEY' );
    }

    private function default_network_config(): array
    {
        return array(
            'api_base_url'         => 'https://api.advcake.ru',
            'api_actions_endpoint' => '/export/webmaster/{token}',
        );
    }

    private function sample_xml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<items>
    <item>
        <offer>demo</offer>
        <offer_id>6</offer_id>
        <order_id>820217</order_id>
        <click_id>f7230324edc38a5a2e96906569419b19d</click_id>
        <clicked_at>2026-03-16 12:36:23</clicked_at>
        <date>2026-03-16 15:57:04</date>
        <dateChange>2026-03-16 15:57:05</dateChange>
        <price>700</price>
        <commission>42</commission>
        <status>2</status>
        <ip>198.51.100.100</ip>
        <reason>test order</reason>
        <paid>yes</paid>
        <payment_status>balance</payment_status>
        <bid>24,15 %</bid>
        <category>clothes</category>
        <customer>old</customer>
        <sub1>11111111111111111111111111111111</sub1>
        <sub2>22222222222222222222222222222222</sub2>
        <link_hash>6a6a1b5936d49cf5</link_hash>
        <landing_id>1841</landing_id>
        <keyword>Купить одежду</keyword>
        <currency>RUB</currency>
    </item>
    <item>
        <offer>demo</offer>
        <offer_id>6</offer_id>
        <order_id>820218</order_id>
        <click_id>different-click-id</click_id>
        <price>1500</price>
        <commission>90</commission>
        <status>1</status>
        <sub1>33333333333333333333333333333333</sub1>
        <currency>RUB</currency>
    </item>
</items>
XML;
    }

    // ------------------------------------------------------------------
    // get_slug / get_aliases / get_default_status_map
    // ------------------------------------------------------------------

    public function test_slug_is_advcake(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $this->assertSame('advcake', $adapter->get_slug());
    }

    public function test_aliases_include_short_form_and_domain(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $aliases = $adapter->get_aliases();
        $this->assertContains('adv', $aliases);
        $this->assertContains('advcake.ru', $aliases);
    }

    public function test_default_status_map_uses_numeric_keys(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $map     = $adapter->get_default_status_map();
        $this->assertSame('waiting', $map['1']);
        $this->assertSame('completed', $map['2']);
        $this->assertSame('declined', $map['3']);
    }

    // ------------------------------------------------------------------
    // get_token / build_auth_headers
    // ------------------------------------------------------------------

    public function test_get_token_returns_api_key(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $token   = $adapter->get_token($this->default_credentials(), $this->default_network_config());
        $this->assertSame('REDACTED_ADVCAKE_TEST_KEY', $token);
    }

    public function test_get_token_returns_null_for_empty_api_key(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $this->assertNull($adapter->get_token(array(), $this->default_network_config()));
        $this->assertNull($adapter->get_token(array( 'api_key' => '   ' ), $this->default_network_config()));
    }

    public function test_get_token_rejects_invalid_characters(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $this->assertNull($adapter->get_token(array( 'api_key' => 'token with spaces' ), $this->default_network_config()));
        $this->assertNull($adapter->get_token(array( 'api_key' => 'token<script>' ), $this->default_network_config()));
    }

    public function test_build_auth_headers_returns_null_no_authorization_header(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $this->assertNull($adapter->build_auth_headers($this->default_credentials(), $this->default_network_config()));
    }

    // ------------------------------------------------------------------
    // URL composition
    // ------------------------------------------------------------------

    public function test_actions_url_substitutes_token_into_path(): void
    {
        $this->queue_responses(array( $this->http_response(200, '<items></items>') ));

        $adapter = new Cashback_Advcake_Adapter();
        $adapter->fetch_actions(
            $this->default_credentials(),
            array( 'update_from' => '2026-05-07', 'update_to' => '2026-05-14' ),
            $this->default_network_config()
        );

        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
        $url = $GLOBALS['_cb_test_http_calls'][0]['url'];
        $this->assertStringContainsString('https://api.advcake.ru/export/webmaster/REDACTED_ADVCAKE_TEST_KEY', $url);
        $this->assertStringContainsString('update_from=2026-05-07', $url);
        $this->assertStringContainsString('update_to=2026-05-14', $url);
        // {token} placeholder не должен остаться в финальном URL.
        $this->assertStringNotContainsString('{token}', $url);
    }

    public function test_disallowed_query_keys_are_filtered_out(): void
    {
        $this->queue_responses(array( $this->http_response(200, '<items></items>') ));

        $adapter = new Cashback_Advcake_Adapter();
        $adapter->fetch_actions(
            $this->default_credentials(),
            array(
                'update_from' => '2026-05-13',
                'update_to'   => '2026-05-14',
                'secret_key'  => 'should-not-leak',
                'password'    => 'p0wn',
            ),
            $this->default_network_config()
        );

        $url = $GLOBALS['_cb_test_http_calls'][0]['url'];
        $this->assertStringNotContainsString('secret_key', $url);
        $this->assertStringNotContainsString('password', $url);
    }

    // ------------------------------------------------------------------
    // XML parsing
    // ------------------------------------------------------------------

    public function test_xml_parsing_extracts_actions_and_maps_fields(): void
    {
        $this->queue_responses(array( $this->http_response(200, $this->sample_xml()) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            array(),
            $this->default_network_config()
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['total']);

        $first = $result['actions'][0];
        $this->assertSame('820217', $first['order_id']);
        $this->assertSame('2', $first['status']);
        $this->assertSame(42.0, $first['commission']);
        $this->assertSame(42.0, $first['payment'], 'payment alias should mirror commission');
        $this->assertSame(700.0, $first['price']);
        $this->assertSame(700.0, $first['cart'], 'cart alias should mirror price');
        $this->assertSame('11111111111111111111111111111111', $first['sub1']);
        $this->assertSame('22222222222222222222222222222222', $first['sub2']);
        $this->assertSame('6', $first['offer_id']);
        $this->assertSame('demo', $first['offer']);
        $this->assertSame('RUB', $first['currency']);
        $this->assertSame('balance', $first['payment_status']);
    }

    public function test_xml_parsing_returns_success_for_empty_items_list(): void
    {
        $this->queue_responses(array( $this->http_response(200, '<?xml version="1.0"?><items></items>') ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            array(),
            $this->default_network_config()
        );

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(array(), $result['actions']);
    }

    public function test_malformed_xml_returns_fetch_error(): void
    {
        $this->queue_responses(array( $this->http_response(200, '<items><item>broken') ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            array(),
            $this->default_network_config()
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Malformed XML', $result['error']);
    }

    /**
     * XXE-safe: внешние сущности не должны загружаться. Парсер не должен
     * вытащить содержимое /etc/passwd или другого файла даже если payload
     * содержит DOCTYPE с external entity.
     */
    public function test_xxe_attack_payload_is_ignored(): void
    {
        $xxe_payload = '<?xml version="1.0"?>'
            . '<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<items><item><id>1</id><order_id>X</order_id><commission>&xxe;</commission></item></items>';

        $this->queue_responses(array( $this->http_response(200, $xxe_payload) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            array(),
            $this->default_network_config()
        );

        // Парсер должен либо отбросить XML (success=false), либо вернуть
        // commission=0 (entity не разрешена). Главное — содержимое внешнего
        // файла не должно попасть в результат.
        if ($result['success']) {
            $commission_str = isset($result['actions'][0]['commission']) ? (string) $result['actions'][0]['commission'] : '';
            $this->assertStringNotContainsString('root:', $commission_str);
            $this->assertStringNotContainsString('/bin/bash', $commission_str);
        }
    }

    // ------------------------------------------------------------------
    // HTTP error handling
    // ------------------------------------------------------------------

    public function test_retries_once_on_5xx_then_succeeds(): void
    {
        $this->queue_responses(array(
            $this->http_response(500, '<html>nginx 500</html>'),
            $this->http_response(200, '<items></items>'),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            array(),
            $this->default_network_config()
        );

        $this->assertTrue($result['success']);
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_gives_up_after_two_5xx_retries(): void
    {
        $this->queue_responses(array(
            $this->http_response(503),
            $this->http_response(503),
            $this->http_response(502),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            array(),
            $this->default_network_config()
        );

        $this->assertFalse($result['success']);
        $this->assertCount(3, $GLOBALS['_cb_test_http_calls'], '1 initial + 2 retries = 3 calls');
    }

    public function test_401_is_terminal_no_retry(): void
    {
        $this->queue_responses(array( $this->http_response(401, 'unauthorized') ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            array(),
            $this->default_network_config()
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('401', $result['error']);
        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_safe_error_summary_redacts_xml_items(): void
    {
        $body = '<items><item><sub1>SECRET_CLICK_ID</sub1><order_id>123</order_id></item></items>';
        $this->queue_responses(array( $this->http_response(500, $body) ));
        $this->queue_responses(array(
            $this->http_response(500, $body),
            $this->http_response(500, $body),
            $this->http_response(500, $body),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            array(),
            $this->default_network_config()
        );

        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('SECRET_CLICK_ID', $result['error']);
    }

    // ------------------------------------------------------------------
    // fetch_all_actions / window clamp / deduplication
    // ------------------------------------------------------------------

    public function test_fetch_all_actions_dedups_by_id(): void
    {
        $xml = '<?xml version="1.0"?><items>'
            . '<item><id>A</id><order_id>1</order_id><status>1</status></item>'
            . '<item><id>A</id><order_id>1</order_id><status>2</status></item>'
            . '<item><id>B</id><order_id>2</order_id><status>2</status></item>'
            . '</items>';
        $this->queue_responses(array( $this->http_response(200, $xml) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_all_actions(
            $this->default_credentials(),
            array(),
            5,
            $this->default_network_config()
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['total'], 'duplicate id A должен схлопнуться');
        // Должна остаться вторая (status=2) — массив переписывается на одинаковом id.
        $byId = array();
        foreach ($result['actions'] as $a) {
            $byId[ $a['id'] ] = $a['status'];
        }
        $this->assertSame('2', $byId['A']);
        $this->assertSame('2', $byId['B']);
    }

    public function test_window_clamp_truncates_to_7_days(): void
    {
        $this->queue_responses(array( $this->http_response(200, '<items></items>') ));

        $adapter = new Cashback_Advcake_Adapter();
        $adapter->fetch_all_actions(
            $this->default_credentials(),
            array( 'update_from' => '2026-04-01', 'update_to' => '2026-05-14' ),
            5,
            $this->default_network_config()
        );

        $url = $GLOBALS['_cb_test_http_calls'][0]['url'];
        $this->assertStringContainsString('update_to=2026-05-14', $url);
        // 7 дней назад от 2026-05-14 23:59:59 UTC = 2026-05-07.
        $this->assertStringContainsString('update_from=2026-05-07', $url);
    }

    public function test_window_within_7_days_is_unchanged(): void
    {
        $this->queue_responses(array( $this->http_response(200, '<items></items>') ));

        $adapter = new Cashback_Advcake_Adapter();
        $adapter->fetch_all_actions(
            $this->default_credentials(),
            array( 'update_from' => '2026-05-10', 'update_to' => '2026-05-14' ),
            5,
            $this->default_network_config()
        );

        $url = $GLOBALS['_cb_test_http_calls'][0]['url'];
        $this->assertStringContainsString('update_from=2026-05-10', $url);
        $this->assertStringContainsString('update_to=2026-05-14', $url);
    }

    // ------------------------------------------------------------------
    // Stub'ы для v12 импортёра
    // ------------------------------------------------------------------

    public function test_fetch_campaigns_returns_success_stub(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());
        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['campaigns']);
    }

    public function test_fetch_campaigns_detailed_returns_success_stub_no_next(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);
        $this->assertTrue($result['success']);
        $this->assertFalse($result['has_next']);
        $this->assertSame(array(), $result['campaigns']);
    }

    public function test_fetch_shop_tariffs_returns_success_stub(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '6');
        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['tariffs']);
    }
}
