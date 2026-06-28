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
        $GLOBALS['_cb_test_actions_fired']          = array();
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
        // Тот же контракт для 429-retry: тесты не должны реально спать
        // между retry-попытками rate-limited запросов.
        add_filter(
            'cashback_advcake_429_retry_delay_seconds',
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
        return array( 'api_key' => $this->synthetic_api_key() );
    }

    private function synthetic_api_key(): string
    {
        return 'unit_test_' . substr(hash('sha256', self::class), 0, 24);
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
        $this->assertSame($this->synthetic_api_key(), $token);
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

    public function test_cakelink_request_sends_subids_as_top_level_params_not_inside_dl(): void
    {
        $this->queue_responses(array(
            $this->http_response(200, (string) wp_json_encode(array(
                'success' => true,
                'data'    => array(
                    'url' => 'https://www.kolesa-darom.ru/?advcake_params=abc123',
                ),
            ))),
        ));

        $target_url = 'https://www.kolesa-darom.ru/product?id=123&utm_source=abc';
        $adapter    = new Cashback_Advcake_Adapter();
        $result     = $adapter->create_deeplink(
            $this->default_credentials(),
            $this->default_network_config(),
            '2222',
            $target_url,
            array(
                'sub1' => 'TEST_TOP_1782590258',
                'sub2' => 'CLICK_TOP_7c83cd625efd',
            ),
            'https://go.redav.online/20fe219674c95fc1?erid=2VfnxxEEBKF&m=31',
            true
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);

        $request_url = (string) $GLOBALS['_cb_test_http_calls'][0]['url'];
        $this->assertSame('cakelink.ru', wp_parse_url($request_url, PHP_URL_HOST));
        $this->assertSame('/link', wp_parse_url($request_url, PHP_URL_PATH));

        $query = (string) wp_parse_url($request_url, PHP_URL_QUERY);
        parse_str($query, $params);

        $this->assertSame($target_url, $params['dl']);
        $this->assertSame('TEST_TOP_1782590258', $params['sub1']);
        $this->assertSame('CLICK_TOP_7c83cd625efd', $params['sub2']);
        $this->assertSame($this->synthetic_api_key(), $params['pass']);
        $this->assertStringNotContainsString('sub1=', (string) $params['dl']);
        $this->assertStringNotContainsString('sub2=', (string) $params['dl']);

        $dl_pos   = strpos($query, 'dl=');
        $sub1_pos = strpos($query, 'sub1=');
        $sub2_pos = strpos($query, 'sub2=');
        $pass_pos = strpos($query, 'pass=');

        $this->assertIsInt($dl_pos);
        $this->assertIsInt($sub1_pos);
        $this->assertIsInt($sub2_pos);
        $this->assertIsInt($pass_pos);
        $this->assertLessThan($sub1_pos, $dl_pos);
        $this->assertLessThan($sub2_pos, $sub1_pos);
        $this->assertLessThan($pass_pos, $sub2_pos);
    }

    public function test_cakelink_dl_is_urlencoded_so_target_query_string_survives(): void
    {
        // Целевой URL с query-строкой — главный кейс бага: при сыром `dl`
        // cakelink парсит `&categoryId=5510` как СВОЙ параметр и хвост URL теряется.
        $target = 'https://site.ru/product/?sortBy=popular&categoryId=5510';

        $this->queue_responses(array(
            $this->http_response(200, (string) wp_json_encode(array(
                'success' => true,
                'data'    => array(
                    'url' => 'https://site.ru/product/?advcake_params=abc123',
                ),
            ))),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->create_deeplink(
            $this->default_credentials(),
            $this->default_network_config(),
            '3333',
            $target,
            array(
                'sub1' => 'TEST_DIRTY_URL_1782590258',
                'sub2' => 'CLICK_DIRTY_URL_7c83cd625efd',
            ),
            'https://go.redav.online/20fe219674c95fc1?erid=2VfnxxEEBKF&m=31',
            true
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);

        $request_url = (string) $GLOBALS['_cb_test_http_calls'][0]['url'];
        $raw_query   = (string) wp_parse_url($request_url, PHP_URL_QUERY);

        // Ассерт на СЫРОЙ query-стринг (до parse_str) — ловит сам факт кодирования:
        // если `dl` уйдёт сырым, этой подстроки в запросе не будет.
        $this->assertStringContainsString('dl=' . rawurlencode($target), $raw_query);

        // И что хвост целевого URL не утёк в собственные параметры cakelink.
        parse_str($raw_query, $params);
        $this->assertSame($target, $params['dl']);
        $this->assertArrayNotHasKey('categoryId', $params);
        $this->assertArrayNotHasKey('sortBy', $params);
    }

    public function test_cakelink_success_returns_api_data_url_without_mutating_it(): void
    {
        $returned_url = 'https://mnogomebeli.com/item/?utm_source=advcake&advcake_params=abc123';

        $this->queue_responses(array(
            $this->http_response(200, (string) wp_json_encode(array(
                'success' => true,
                'url'     => $returned_url,
            ))),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->create_deeplink(
            $this->default_credentials(),
            $this->default_network_config(),
            '1111',
            'https://mnogomebeli.com/item/',
            array(
                'sub1' => '0123456789abcdef0123456789abcdef',
                'sub2' => '7f2c1763a0017fd3e98c822ba1296704',
            ),
            'https://go.redav.online/20fe219674c95fc1?erid=2VfnxxEEBKF&m=31',
            true
        );

        $this->assertTrue($result['success']);
        $this->assertSame('cakelink', $result['link_type']);
        $this->assertSame($returned_url, $result['url']);
        $this->assertStringNotContainsString('sub1=', $result['url']);
        $this->assertStringNotContainsString('sub2=', $result['url']);
    }

    public function test_prefer_stored_affiliate_url_skips_cakelink_for_safe_static_template(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->create_deeplink(
            $this->default_credentials(),
            array_merge($this->default_network_config(), array(
                'prefer_stored_affiliate_url' => true,
            )),
            '977',
            'https://www.vseinstrumenti.ru/product/perforator-makita-hr-2470-5195/',
            array(
                'sub1' => '0123456789abcdef0123456789abcdef',
                'sub2' => 'unregistered',
            ),
            'https://go.redav.online/b35a045b3e97f221?erid=2VfnxweGDap&m=31',
            true
        );

        $this->assertTrue($result['success']);
        $this->assertSame('stored_affiliate_url', $result['link_type']);
        $this->assertSame(
            'https://go.redav.online/b35a045b3e97f221?erid=2VfnxweGDap&m=31&sub1=0123456789abcdef0123456789abcdef&sub2=unregistered',
            $result['url']
        );
        $this->assertSame(array(), $GLOBALS['_cb_test_http_calls']);
    }

    public function test_cakelink_requires_sub1_tracking_before_calling_api(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->create_deeplink(
            $this->default_credentials(),
            $this->default_network_config(),
            '1111',
            'https://mnogomebeli.com/item/',
            array(
                'click_id' => '0123456789abcdef0123456789abcdef',
                'sub2'     => '7f2c1763a0017fd3e98c822ba1296704',
            ),
            '',
            true
        );

        $this->assertFalse($result['success']);
        $this->assertSame('advcake_missing_sub1_tracking', $result['reason_code']);
        $this->assertSame(array(), $GLOBALS['_cb_test_http_calls']);
    }

    public function test_cakelink_debug_hook_is_opt_in_and_redacts_pass(): void
    {
        add_filter('cashback_advcake_cakelink_debug_enabled', static fn(): bool => true);

        $returned_url = 'https://www.kolesa-darom.ru/?advcake_params=diag';
        $this->queue_responses(array(
            $this->http_response(200, (string) wp_json_encode(array(
                'success' => true,
                'data'    => array(
                    'url' => $returned_url,
                ),
            ))),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->create_deeplink(
            $this->default_credentials(),
            $this->default_network_config(),
            '2222',
            'https://www.kolesa-darom.ru/',
            array(
                'sub1' => 'TEST_TOP_1782590258',
                'sub2' => 'CLICK_TOP_7c83cd625efd',
            ),
            '',
            true
        );

        $this->assertTrue($result['success']);

        $events = array_values(array_filter(
            $GLOBALS['_cb_test_actions_fired'],
            static fn(array $event): bool => $event['hook'] === 'cashback_advcake_cakelink_debug'
        ));
        $this->assertCount(1, $events);

        $payload = $events[0]['args'][0];
        $this->assertSame('cakelink', $payload['link_type']);
        $this->assertSame('https://www.kolesa-darom.ru/', $payload['target_url']);
        $this->assertSame(array( 'sub1', 'sub2' ), $payload['tracking_keys']);
        $this->assertSame($returned_url, $payload['returned_url']);
        $this->assertStringContainsString('pass=[redacted]', $payload['cakelink_request_url']);
        $this->assertStringNotContainsString($this->synthetic_api_key(), $payload['cakelink_request_url']);
        $this->assertStringContainsString('sub1=[redacted]', $payload['cakelink_request_url']);
        $this->assertStringContainsString('sub2=[redacted]', $payload['cakelink_request_url']);
        $this->assertStringNotContainsString('TEST_TOP_1782590258', $payload['cakelink_request_url']);
        $this->assertStringNotContainsString('CLICK_TOP_7c83cd625efd', $payload['cakelink_request_url']);
    }

    public function test_cakelink_success_reads_nested_data_url(): void
    {
        $this->queue_responses(array(
            $this->http_response(200, (string) wp_json_encode(array(
                'success' => true,
                'data'    => array(
                    'url' => 'https://mnogomebeli.com/item/?advcake_params=nested',
                ),
            ))),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->create_deeplink(
            $this->default_credentials(),
            $this->default_network_config(),
            '1111',
            'https://mnogomebeli.com/item/',
            array(
                'sub1' => '0123456789abcdef0123456789abcdef',
                'sub2' => '7f2c1763a0017fd3e98c822ba1296704',
            ),
            '',
            true
        );

        $this->assertTrue($result['success']);
        $this->assertSame('cakelink', $result['link_type']);
        $this->assertStringContainsString('advcake_params=nested', $result['url']);
    }

    public function test_cakelink_success_reads_nested_data_result_url(): void
    {
        $returned_url = 'https://mnogomebeli.com/item/?advcake_params=nested-result';
        $this->queue_responses(array(
            $this->http_response(200, (string) wp_json_encode(array(
                'success' => true,
                'data'    => array(
                    'result' => array(
                        'url' => $returned_url,
                    ),
                ),
            ))),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->create_deeplink(
            $this->default_credentials(),
            $this->default_network_config(),
            '1111',
            'https://mnogomebeli.com/item/',
            array(
                'sub1' => '0123456789abcdef0123456789abcdef',
                'sub2' => '7f2c1763a0017fd3e98c822ba1296704',
            ),
            '',
            true
        );

        $this->assertTrue($result['success']);
        $this->assertSame('cakelink', $result['link_type']);
        $this->assertSame($returned_url, $result['url']);
    }

    public function test_cakelink_api_failure_returns_error_without_fallback(): void
    {
        $this->queue_responses(array(
            $this->http_response(200, (string) wp_json_encode(array(
                'success' => false,
                'error'   => 'invalid_dl',
            ))),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->create_deeplink(
            $this->default_credentials(),
            $this->default_network_config(),
            '1111',
            'https://mnogomebeli.com/item/',
            array( 'sub1' => '0123456789abcdef0123456789abcdef' ),
            '',
            true
        );

        $this->assertFalse($result['success']);
        $this->assertSame('advcake_api_error', $result['reason_code']);
        $this->assertSame('invalid_dl', $result['error']);
    }

    public function test_cakelink_empty_data_url_returns_error(): void
    {
        $this->queue_responses(array(
            $this->http_response(200, (string) wp_json_encode(array(
                'success' => true,
                'data'    => array(
                    'url' => '',
                ),
            ))),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->create_deeplink(
            $this->default_credentials(),
            $this->default_network_config(),
            '1111',
            'https://mnogomebeli.com/item/',
            array( 'sub1' => '0123456789abcdef0123456789abcdef' ),
            '',
            true
        );

        $this->assertFalse($result['success']);
        $this->assertSame('advcake_empty_deeplink', $result['reason_code']);
    }

    public function test_cakelink_http_error_redacts_api_key_from_error(): void
    {
        $token = $this->synthetic_api_key();
        $this->queue_responses(array(
            $this->http_response(
                500,
                'failed url=https://cakelink.ru/link?pass=' . $token . '&dl=x path=/export/webmaster/' . $token
            ),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->create_deeplink(
            $this->default_credentials(),
            $this->default_network_config(),
            '1111',
            'https://mnogomebeli.com/item/',
            array( 'sub1' => '0123456789abcdef0123456789abcdef' ),
            '',
            true
        );

        $this->assertFalse($result['success']);
        $this->assertSame('advcake_api_error', $result['reason_code']);
        $this->assertStringNotContainsString($token, $result['error']);
        $this->assertStringContainsString('[redacted]', $result['error']);
    }

    public function test_cakelink_not_in_allowlist_returns_error_without_stored_affiliate_fallback(): void
    {
        $this->queue_responses(array(
            $this->http_response(200, (string) wp_json_encode(array(
                'success' => false,
                'error'   => 'not_in_allowlist',
            ))),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->create_deeplink(
            $this->default_credentials(),
            $this->default_network_config(),
            '1111',
            'https://mnogomebeli.com/divany/nord/divan-nord/!divan-nord-alkantara-shokolad/',
            array(
                'sub1' => '0123456789abcdef0123456789abcdef',
                'sub2' => '7f2c1763a0017fd3e98c822ba1296704',
            ),
            'https://go.redav.online/20fe219674c95fc1?erid=2VfnxxEEBKF&m=31',
            true
        );

        $this->assertFalse($result['success']);
        $this->assertSame('advcake_api_error', $result['reason_code']);
        $this->assertSame('not_in_allowlist', $result['error']);
    }

    public function test_cakelink_live_smoke_is_opt_in(): void
    {
        if (getenv('ADVCAKE_LIVE_TEST') !== '1') {
            $this->markTestSkipped('Set ADVCAKE_LIVE_TEST=1 to run the live CakeLink smoke.');
        }

        $api_key = (string) getenv('ADVCAKE_API_KEY');
        if ($api_key === '') {
            $this->markTestSkipped('Set ADVCAKE_API_KEY to run the live CakeLink smoke.');
        }

        $target_url = (string) getenv('ADVCAKE_TEST_URL');
        if ($target_url === '') {
            $target_url = 'https://www.kolesa-darom.ru/';
        }

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->create_deeplink(
            array( 'api_key' => $api_key ),
            $this->default_network_config(),
            'live-smoke',
            $target_url,
            array(
                'sub1' => 'TEST_TOP_' . time(),
                'sub2' => 'CLICK_TOP_' . substr(hash('sha256', $target_url . microtime(true)), 0, 12),
            ),
            '',
            true
        );

        $this->assertTrue($result['success'], (string) ( $result['error'] ?? $result['reason_code'] ?? 'CakeLink failed' ));
        $this->assertSame('cakelink', $result['link_type']);
        $this->assertNotEmpty($result['url']);
    }

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
        $this->assertStringContainsString('https://api.advcake.ru/export/webmaster/' . $this->synthetic_api_key(), $url);
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
        $this->assertSame('test order', $first['reason']);
    }

    /**
     * Advcake XML-экспорт НЕ содержит элемента `<id>` — идентификатор заказа
     * это `<order_id>` (офиц. дока support.advcake.com + примеры в кабинете).
     * Постбэк-макрос `{id}` (URL `uniq_id={id}`) тоже = order_id. Чтобы
     * webhook-строка и XML-reconciliation резолвили ОДИН `uniq_id`
     * (контракт `(partner, uniq_id)` идентичности), нормализованное поле
     * `id` обязано падать в `order_id`, когда `<id>` отсутствует. Иначе
     * `Cashback_API_Client::resolve_uniq_id()` получает пустой native →
     * `no_dedup_inputs` → XML-action пропускается → баланс не зачисляется.
     */
    public function test_id_falls_back_to_order_id_when_id_element_absent(): void
    {
        $this->queue_responses(array( $this->http_response(200, $this->sample_xml()) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            array(),
            $this->default_network_config()
        );

        $this->assertTrue($result['success']);
        $first = $result['actions'][0];
        $this->assertSame('820217', $first['order_id']);
        $this->assertSame(
            '820217',
            $first['id'],
            'нет <id> в XML Advcake → id обязан падать в order_id для webhook↔XML uniq_id-паритета'
        );
    }

    /**
     * Если в каком-то оффере Advcake всё же присылает непустой `<id>`,
     * он имеет приоритет над `order_id` (сохраняем native-идентичность).
     */
    public function test_explicit_id_element_takes_precedence_over_order_id(): void
    {
        $xml = '<?xml version="1.0"?><items><item>'
            . '<id>NATIVE-7</id><order_id>820217</order_id><status>2</status>'
            . '<commission>10</commission><price>100</price>'
            . '</item></items>';
        $this->queue_responses(array( $this->http_response(200, $xml) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            array(),
            $this->default_network_config()
        );

        $this->assertTrue($result['success']);
        $this->assertSame('NATIVE-7', $result['actions'][0]['id']);
        $this->assertSame('820217', $result['actions'][0]['order_id']);
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

    // ------------------------------------------------------------------
    // 429 Too Many Requests — rate-limit retry с backoff. Без него
    // первый же 429 от Advcake (а это вероятно на проде с большим
    // каталогом, см. fix/advcake-import-hang) валит весь импорт.
    // ------------------------------------------------------------------

    public function test_fetch_actions_retries_on_429_then_succeeds(): void
    {
        $this->queue_responses(array(
            $this->http_response(429, 'Too Many Requests'),
            $this->http_response(200, '<items></items>'),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            array(),
            $this->default_network_config()
        );

        $this->assertTrue($result['success']);
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls'], '1 initial + 1 retry = 2 calls');
    }

    public function test_fetch_actions_gives_up_after_two_429_retries(): void
    {
        $this->queue_responses(array(
            $this->http_response(429),
            $this->http_response(429),
            $this->http_response(429),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            array(),
            $this->default_network_config()
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('429', $result['error']);
        $this->assertCount(3, $GLOBALS['_cb_test_http_calls'], '1 initial + 2 retries = 3 calls');
    }

    public function test_fetch_campaigns_retries_on_429_then_succeeds(): void
    {
        $this->queue_responses(array(
            $this->http_response(429, '{"error":"rate limit"}'),
            $this->http_response(200, $this->offers_response_body(array(
                $this->sample_offer(array( 'id' => 9 )),
            ))),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['campaigns']);
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_fetch_campaigns_gives_up_after_two_429_retries(): void
    {
        $this->queue_responses(array(
            $this->http_response(429),
            $this->http_response(429),
            $this->http_response(429),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('429', $result['error']);
        $this->assertCount(3, $GLOBALS['_cb_test_http_calls'], '1 initial + 2 retries');
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

    public function test_status_updated_params_are_converted_to_update_window(): void
    {
        $this->queue_responses(array( $this->http_response(200, '<items></items>') ));

        $adapter = new Cashback_Advcake_Adapter();
        $adapter->fetch_all_actions(
            $this->default_credentials(),
            array(
                'status_updated_start' => '2026-05-13 00:00:00',
                'status_updated_end'   => '2026-05-14 23:59:59',
            ),
            5,
            $this->default_network_config()
        );

        $url = $GLOBALS['_cb_test_http_calls'][0]['url'];
        $this->assertStringContainsString('update_from=2026-05-13', $url);
        $this->assertStringContainsString('update_to=2026-05-14', $url);
        $this->assertStringNotContainsString('status_updated_start', $url);
        $this->assertStringNotContainsString('status_updated_end', $url);
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
    // fetch_campaigns — GET /offers?pass={token}&type=json (Publisher API)
    //
    // См. https://support.advcake.com/docs/api/publisher-api.html
    // Ответ:
    //   {"success":true,"dt":"…","total":N,
    //    "data":[{"id":1,"name":"…","active":true,"available":true,...}]}
    //
    // is_active = active && available
    // connection_status = 'available' | 'unavailable' (по полю available)
    // status            = 'active'    | 'stopped'     (по полю active)
    // ------------------------------------------------------------------

    private function offers_response_body(array $data, ?int $total = null): string
    {
        return (string) wp_json_encode(array(
            'success' => true,
            'dt'      => '2026-05-14 12:00:00',
            'total'   => $total ?? count($data),
            'data'    => $data,
        ));
    }

    /** @return array<string, mixed> */
    private function sample_offer(array $overrides = array()): array
    {
        return array_merge(array(
            'id'          => 1,
            'alias'       => 'tutu',
            'name'        => 'tutu.ru',
            'description' => 'Партнёрская программа tutu.ru',
            'country'     => 'RU',
            'currency'    => 'RUB',
            'website_url' => 'https://www.tutu.travel',
            'thumbnail'   => 'https://static.advcake.com/upload/offers/tutu.png',
            'category'    => 'travel',
            'type'        => 'CPA',
            'active'      => true,
            'available'   => true,
        ), $overrides);
    }

    public function test_fetch_campaigns_url_uses_offers_endpoint_with_pass_and_type_json(): void
    {
        $this->queue_responses(array( $this->http_response(200, $this->offers_response_body(array())) ));

        $adapter = new Cashback_Advcake_Adapter();
        $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
        $url = $GLOBALS['_cb_test_http_calls'][0]['url'];

        $this->assertStringStartsWith('https://api.advcake.ru/offers?', $url);
        $this->assertStringContainsString('pass=' . $this->synthetic_api_key(), $url);
        $this->assertStringContainsString('type=json', $url);
        // Не должен попасть actions-endpoint path.
        $this->assertStringNotContainsString('/export/webmaster/', $url);
        // Не должен утечь {token} placeholder.
        $this->assertStringNotContainsString('{token}', $url);
    }

    public function test_fetch_campaigns_returns_normalized_campaign_fields(): void
    {
        $body = $this->offers_response_body(array(
            $this->sample_offer(array( 'id' => 6, 'name' => 'demo', 'active' => true, 'available' => true )),
        ));
        $this->queue_responses(array( $this->http_response(200, $body) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertCount(1, $result['campaigns']);

        $campaign = $result['campaigns'][0];
        $this->assertSame('6', $campaign['id'], 'id должен быть string (offer_id может быть int в JSON)');
        $this->assertSame('demo', $campaign['name']);
        $this->assertTrue($campaign['is_active']);
        $this->assertSame('active', $campaign['status']);
        $this->assertSame('available', $campaign['connection_status']);
    }

    /**
     * @dataProvider provide_active_available_combinations
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provide_active_available_combinations')]
    public function test_is_active_requires_both_active_and_available(
        bool $active,
        bool $available,
        bool $expected_is_active,
        string $expected_status,
        string $expected_connection_status
    ): void {
        $body = $this->offers_response_body(array(
            $this->sample_offer(array( 'id' => 42, 'active' => $active, 'available' => $available )),
        ));
        $this->queue_responses(array( $this->http_response(200, $body) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertTrue($result['success']);
        $campaign = $result['campaigns'][0];
        $this->assertSame($expected_is_active, $campaign['is_active']);
        $this->assertSame($expected_status, $campaign['status']);
        $this->assertSame($expected_connection_status, $campaign['connection_status']);
    }

    public static function provide_active_available_combinations(): array
    {
        return array(
            'active+available → is_active=true' => array( true, true, true, 'active', 'available' ),
            'active+!available → is_active=false (вебмастер не подключён)' => array( true, false, false, 'active', 'unavailable' ),
            '!active+available → is_active=false (программа остановлена)' => array( false, true, false, 'stopped', 'available' ),
            '!active+!available → is_active=false' => array( false, false, false, 'stopped', 'unavailable' ),
        );
    }

    public function test_fetch_campaigns_status_stopped_overrides_active_flag(): void
    {
        $body = $this->offers_response_body(array(
            $this->sample_offer(array(
                'id'        => 43,
                'active'    => true,
                'available' => true,
                'status'    => 'stopped',
            )),
        ));
        $this->queue_responses(array( $this->http_response(200, $body) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertTrue($result['success']);
        $campaign = $result['campaigns'][0];
        $this->assertFalse($campaign['is_active']);
        $this->assertSame('stopped', $campaign['status']);
        $this->assertSame('available', $campaign['connection_status']);
    }

    public function test_fetch_campaigns_available_false_overrides_status_active(): void
    {
        $body = $this->offers_response_body(array(
            $this->sample_offer(array(
                'id'        => 44,
                'active'    => true,
                'available' => false,
                'status'    => 'active',
            )),
        ));
        $this->queue_responses(array( $this->http_response(200, $body) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertTrue($result['success']);
        $campaign = $result['campaigns'][0];
        $this->assertFalse($campaign['is_active']);
        $this->assertSame('active', $campaign['status']);
        $this->assertSame('unavailable', $campaign['connection_status']);
    }

    public function test_fetch_campaigns_paginates_via_offset_until_data_less_than_limit(): void
    {
        // Для status-check /offers берём небольшие страницы: на проде
        // limit=500 таймаутился за 30 секунд даже без with_bids.
        $page1 = array();
        for ($i = 1; $i <= 100; $i++) {
            $page1[] = $this->sample_offer(array( 'id' => $i, 'name' => 'shop-' . $i ));
        }
        $page2 = array(
            $this->sample_offer(array( 'id' => 101, 'name' => 'shop-101' )),
        );

        $this->queue_responses(array(
            $this->http_response(200, $this->offers_response_body($page1, 101)),
            $this->http_response(200, $this->offers_response_body($page2, 101)),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertTrue($result['success']);
        $this->assertSame(101, count($result['campaigns']));
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls']);

        $this->assertStringContainsString('offset=0', $GLOBALS['_cb_test_http_calls'][0]['url']);
        $this->assertStringContainsString('limit=100', $GLOBALS['_cb_test_http_calls'][0]['url']);
        $this->assertStringContainsString('offset=100', $GLOBALS['_cb_test_http_calls'][1]['url']);
    }

    public function test_fetch_campaigns_stops_at_max_pages_safety_cap(): void
    {
        // Безопасная остановка: имитируем «бесконечный» поток full-pages, адаптер
        // не должен сделать больше 20 запросов (max_pages по образцу Admitad).
        $full_page = array();
        for ($i = 1; $i <= 100; $i++) {
            $full_page[] = $this->sample_offer(array( 'id' => $i ));
        }
        $forever_full = $this->offers_response_body($full_page, 999999);
        $responses = array();
        for ($i = 0; $i < 30; $i++) {
            $responses[] = $this->http_response(200, $forever_full);
        }
        $this->queue_responses($responses);

        $adapter = new Cashback_Advcake_Adapter();
        $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertLessThanOrEqual(20, count($GLOBALS['_cb_test_http_calls']), 'максимум 20 страниц');
    }

    public function test_fetch_campaigns_401_returns_error_no_retry(): void
    {
        $this->queue_responses(array( $this->http_response(401, '{"error":"Unauthorized"}') ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('401', $result['error']);
        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_fetch_campaigns_403_returns_error_no_retry(): void
    {
        $this->queue_responses(array( $this->http_response(403, '{"error":"Forbidden"}') ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('403', $result['error']);
        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_fetch_campaigns_5xx_retries_then_succeeds(): void
    {
        $this->queue_responses(array(
            $this->http_response(500, 'gateway timeout'),
            $this->http_response(200, $this->offers_response_body(array(
                $this->sample_offer(array( 'id' => 7 )),
            ))),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['campaigns']);
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_fetch_campaigns_5xx_gives_up_after_two_retries(): void
    {
        $this->queue_responses(array(
            $this->http_response(503),
            $this->http_response(503),
            $this->http_response(502),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertFalse($result['success']);
        $this->assertCount(3, $GLOBALS['_cb_test_http_calls'], '1 initial + 2 retries');
    }

    public function test_fetch_campaigns_malformed_json_returns_error(): void
    {
        $this->queue_responses(array( $this->http_response(200, '<<<not-json>>>') ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
    }

    public function test_fetch_campaigns_api_success_false_returns_error(): void
    {
        $body = (string) wp_json_encode(array(
            'success' => false,
            'error'   => 'invalid token',
        ));
        $this->queue_responses(array( $this->http_response(200, $body) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertFalse($result['success']);
    }

    public function test_fetch_campaigns_empty_data_array_returns_success_empty_campaigns(): void
    {
        $this->queue_responses(array( $this->http_response(200, $this->offers_response_body(array(), 0)) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['campaigns']);
        $this->assertNull($result['error']);
    }

    public function test_fetch_all_actions_reports_effective_window_after_clamp(): void
    {
        $this->queue_responses(array( $this->http_response(200, '<items></items>') ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_all_actions(
            $this->default_credentials(),
            array(
                'date_from' => '2026-05-01',
                'date_to'   => '2026-06-02',
            ),
            20,
            $this->default_network_config()
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['window_limited']);
        $this->assertSame('2026-05-26', $result['effective_params']['date_from']);
        $this->assertSame('2026-06-02', $result['effective_params']['date_to']);
        $this->assertSame('2026-05-01', $result['requested_params']['date_from']);
    }

    public function test_fetch_campaigns_empty_token_returns_error_no_http_call(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns(array( 'api_key' => '' ), $this->default_network_config());

        $this->assertFalse($result['success']);
        $this->assertSame(array(), $result['campaigns']);
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_fetch_campaigns_skips_entries_without_id(): void
    {
        $body = $this->offers_response_body(array(
            $this->sample_offer(array( 'id' => 1, 'name' => 'ok' )),
            array( 'name' => 'broken — нет id', 'active' => true, 'available' => true ),
            $this->sample_offer(array( 'id' => 0, 'name' => 'zero-id-skip' )),
            $this->sample_offer(array( 'id' => 2, 'name' => 'ok2' )),
        ));
        $this->queue_responses(array( $this->http_response(200, $body) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $this->assertTrue($result['success']);
        $ids = array_map(static fn(array $c): string => $c['id'], $result['campaigns']);
        $this->assertSame(array( '1', '2' ), $ids);
    }

    // ------------------------------------------------------------------
    // fetch_campaigns_detailed — реализован поверх /offers, см.
    // AdvcakeShopsDetailedTest для покрытия маппинга DTO-полей,
    // пагинации, обработки 401/5xx.
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // funds_ready (контракт Cashback_API_Client::resolve_funds_ready)
    // ------------------------------------------------------------------

    /**
     * @dataProvider provide_payment_status_funds_ready
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provide_payment_status_funds_ready')]
    public function test_funds_ready_derived_from_payment_status(string $payment_status, int $expected_funds_ready): void
    {
        $xml = '<?xml version="1.0"?><items><item>'
            . '<id>X</id><order_id>Y</order_id><status>2</status>'
            . '<payment_status>' . $payment_status . '</payment_status>'
            . '<commission>10</commission><price>100</price>'
            . '</item></items>';
        $this->queue_responses(array( $this->http_response(200, $xml) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            array(),
            $this->default_network_config()
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['actions']);
        $this->assertSame(
            $expected_funds_ready,
            $result['actions'][0]['funds_ready'],
            "payment_status={$payment_status} должен дать funds_ready={$expected_funds_ready}"
        );
    }

    public static function provide_payment_status_funds_ready(): array
    {
        return array(
            'balance — согласованная'         => array( 'balance', 1 ),
            'processing — ожидает оплаты'     => array( 'processing', 1 ),
            'withdrawal — выведена'           => array( 'withdrawal', 1 ),
            'BALANCE — case-insensitive'      => array( 'BALANCE', 1 ),
            'open — неподтверждённая'         => array( 'open', 0 ),
            'on_hold — на холде'              => array( 'on_hold', 0 ),
            'not_apply — не подлежит выплате' => array( 'not_apply', 0 ),
            'empty — не задан'                => array( '', 0 ),
        );
    }

    // ------------------------------------------------------------------
    // uniq_id-паритет: `id` ← order_id fallback (регресс-замок 8266c33).
    // Advcake XML НЕ содержит <id>; идентичность = <order_id>. Без
    // fallback'а normalize_xml_item даёт пустой `id` → resolve_uniq_id()
    // → no_dedup_inputs → action скипается → cashback не зачисляется.
    // ef32586 убрал click_id-fallback, поэтому паритет критичен.
    // ------------------------------------------------------------------

    /**
     * @dataProvider provide_id_order_id_parity
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provide_id_order_id_parity')]
    public function test_uniq_id_parity_id_falls_back_to_order_id(
        string $id_xml,
        string $order_id_xml,
        ?string $expected_id
    ): void {
        $xml = '<?xml version="1.0"?><items><item>'
            . $id_xml . $order_id_xml
            . '<status>2</status><payment_status>balance</payment_status>'
            . '<commission>10</commission><price>100</price>'
            . '</item></items>';
        $this->queue_responses(array( $this->http_response(200, $xml) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            array(),
            $this->default_network_config()
        );

        $this->assertTrue($result['success']);

        if ($expected_id === null) {
            // Ни <id>, ни <order_id> — action бесполезен, скипается.
            $this->assertCount(0, $result['actions']);
            return;
        }

        $this->assertCount(1, $result['actions']);
        $this->assertArrayHasKey(
            'id',
            $result['actions'][0],
            'normalize_xml_item ОБЯЗАН всегда отдавать ключ `id` (контракт api_field_for(uniq_id))'
        );
        $this->assertSame(
            $expected_id,
            $result['actions'][0]['id'],
            "id_xml={$id_xml} order_id_xml={$order_id_xml} должен дать id={$expected_id}"
        );
    }

    public static function provide_id_order_id_parity(): array
    {
        return array(
            '<id> присутствует — берём id'              => array( '<id>ADV-1</id>', '<order_id>ORD-9</order_id>', 'ADV-1' ),
            '<id> отсутствует — fallback на order_id'   => array( '', '<order_id>ORD-9</order_id>', 'ORD-9' ),
            '<id> пустой — fallback на order_id'        => array( '<id></id>', '<order_id>ORD-9</order_id>', 'ORD-9' ),
            '<id> только пробелы — fallback на order_id' => array( '<id>   </id>', '<order_id>ORD-9</order_id>', 'ORD-9' ),
            'оба отсутствуют — action скипается'        => array( '', '', null ),
            'оба пустые — action скипается'             => array( '<id> </id>', '<order_id> </order_id>', null ),
        );
    }

    public function test_fetch_shop_tariffs_returns_success_stub(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '6');
        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['tariffs']);
    }

    /**
     * Regression-guard: Advcake-адаптер НЕ должен иметь fetch_campaign_by_id.
     * Метод был добавлен в v4.4.27 (API-импорт `ar` из /stat) и откатан в
     * v4.4.28: Advcake `/stat.ar` — публикатор-специфичная статистика, а блок
     * в редакторе товара показывает offer-wide AR из кабинета Advcake (не
     * выставлен в публичном Publisher API). Для Advcake-товаров значение
     * вводится админом вручную через UI, для Admitad — обновляется через API.
     * Если метод вернётся, Cashback_Shop_Rate_Of_Approve_Refresher переключит
     * Advcake обратно в API-режим — это регрессия, тест её поймает.
     */
    public function test_fetch_campaign_by_id_is_not_implemented_for_advcake(): void
    {
        $this->assertFalse(
            method_exists(Cashback_Advcake_Adapter::class, 'fetch_campaign_by_id'),
            'Advcake adapter must NOT expose fetch_campaign_by_id — Publisher API не отдаёт offer-wide AR; для Advcake-товаров используется manual UI ввод.'
        );
    }
}
