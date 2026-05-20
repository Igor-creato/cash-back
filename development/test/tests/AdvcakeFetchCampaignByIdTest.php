<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * fetch_campaign_by_id() — per-offer GET /stat?offer_id={N}&days=30 для Advcake.
 * Happy path + retry на 5xx + ошибки 401/403/404/empty/malformed/no-token.
 *
 * Контракт совпадает с Admitad-аналогом — те же ключи (success/campaign/error),
 * та же интерпретация success+null как «нет данных» у вызывающей стороны
 * (Cashback_Shop_Rate_Of_Approve_Refresher).
 *
 * @group adapters
 * @group advcake
 * @group rate-of-approve
 */
#[Group('adapters')]
#[Group('advcake')]
#[Group('rate-of-approve')]
final class AdvcakeFetchCampaignByIdTest extends TestCase
{
    private static string $plugin_root;
    /** @var array<int, array{0: string, 1: callable, 2: int}> */
    private array $registered_filters = array();

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
        foreach (array(
            '/includes/class-cashback-outbound-http-guard.php',
            '/includes/adapters/interface-cashback-network-adapter.php',
            '/includes/adapters/abstract-cashback-network-adapter.php',
            '/includes/adapters/class-cashback-advcake-adapter.php',
        ) as $rel) {
            $path = self::$plugin_root . $rel;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options']                = array();
        $GLOBALS['_cb_test_filters']                = array();
        $GLOBALS['_cb_test_transients']             = array();
        $GLOBALS['_cb_test_cache']                  = array();
        $GLOBALS['_cb_test_http_calls']             = array();
        $GLOBALS['_cb_test_http_response_callback'] = null;

        // 5xx backoff zero-out — тесты не спят.
        $this->add_tracked_filter('cashback_advcake_5xx_retry_delay_seconds', static fn(): int => 0, 10, 3);
        $this->add_tracked_filter('cashback_advcake_429_retry_delay_seconds', static fn(): int => 0, 10, 3);

        if (class_exists('Cashback_Outbound_HTTP_Guard')) {
            Cashback_Outbound_HTTP_Guard::invalidate_cache();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->registered_filters as $entry) {
            remove_filter($entry[0], $entry[1], $entry[2]);
        }
        $this->registered_filters = array();
        $GLOBALS['_cb_test_http_response_callback'] = null;
        $GLOBALS['_cb_test_http_calls']             = array();
        $GLOBALS['_cb_test_filters']                = array();
    }

    private function queue(array $responses): void
    {
        $queue = $responses;
        $GLOBALS['_cb_test_http_response_callback'] = static function (string $url, array $args) use (&$queue) {
            if (count($queue) > 1) {
                return array_shift($queue);
            }
            return $queue[0] ?? array('body' => '', 'response' => array('code' => 500, 'message' => 'X'), 'headers' => array());
        };
    }

    private function resp(int $code, string $body): array
    {
        return array('body' => $body, 'response' => array('code' => $code, 'message' => 'HTTP ' . $code), 'headers' => array());
    }

    private function creds(string $token = 'REDACTED_ADVCAKE_TEST_KEY'): array
    {
        return array('api_key' => $token);
    }

    private function cfg(): array
    {
        return array(
            'api_base_url'         => 'https://api.advcake.ru',
            'api_actions_endpoint' => '/export/webmaster/{token}',
        );
    }

    private function add_tracked_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        add_filter($hook, $callback, $priority, $accepted_args);
        $this->registered_filters[] = array($hook, $callback, $priority);
    }

    public function test_happy_path_returns_rate_scaled_to_percent(): void
    {
        // ar=0.0976 → 9.76%. orders_total=123 > 0 → success.
        $this->queue(array($this->resp(200, wp_json_encode(array(
            'success' => true,
            'total'   => 1,
            'data'    => array(
                array(
                    'offer'         => 'Demo',
                    'offer_id'      => 240,
                    'visits'        => 1234,
                    'orders_total'  => 123,
                    'orders_approved' => 12,
                    'ar'            => 0.0976,
                    'cr'            => 0.0997,
                ),
            ),
        )))));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '240');

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertIsArray($result['campaign']);
        $this->assertSame(9.76, $result['campaign']['rate_of_approve']);
    }

    public function test_url_contains_pass_offer_id_and_days_30_by_default(): void
    {
        $this->queue(array($this->resp(200, wp_json_encode(array('success' => true, 'data' => array())))));

        $adapter = new Cashback_Advcake_Adapter();
        $adapter->fetch_campaign_by_id($this->creds('tok-1'), $this->cfg(), '999');

        $this->assertNotEmpty($GLOBALS['_cb_test_http_calls']);
        $url = $GLOBALS['_cb_test_http_calls'][0]['url'] ?? '';
        $this->assertStringContainsString('/stat?', $url);
        $this->assertStringContainsString('pass=tok-1', $url);
        $this->assertStringContainsString('offer_id=999', $url);
        $this->assertStringContainsString('days=30', $url);
        $this->assertStringContainsString('type=json', $url);
    }

    public function test_filter_overrides_days_window(): void
    {
        $this->add_tracked_filter('cashback_advcake_stat_days', static fn(): int => 90, 10, 2);

        $this->queue(array($this->resp(200, wp_json_encode(array('success' => true, 'data' => array())))));

        $adapter = new Cashback_Advcake_Adapter();
        $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '240');

        $url = $GLOBALS['_cb_test_http_calls'][0]['url'] ?? '';
        $this->assertStringContainsString('days=90', $url);
    }

    public function test_filter_days_out_of_range_falls_back_to_default(): void
    {
        $this->add_tracked_filter('cashback_advcake_stat_days', static fn(): int => 9999, 10, 2);

        $this->queue(array($this->resp(200, wp_json_encode(array('success' => true, 'data' => array())))));

        $adapter = new Cashback_Advcake_Adapter();
        $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '240');

        $url = $GLOBALS['_cb_test_http_calls'][0]['url'] ?? '';
        $this->assertStringContainsString('days=30', $url, 'invalid filter → default');
    }

    public function test_empty_campaign_id_returns_error_without_http(): void
    {
        $this->queue(array($this->resp(500, '')));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '');

        $this->assertFalse($result['success']);
        $this->assertNull($result['campaign']);
        $this->assertSame('campaign_id обязателен', $result['error']);
        $this->assertSame(0, count($GLOBALS['_cb_test_http_calls']), 'no HTTP call without id');
    }

    public function test_empty_token_returns_error_no_http(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id(array('api_key' => ''), $this->cfg(), '240');

        $this->assertFalse($result['success']);
        $this->assertNull($result['campaign']);
        $this->assertStringContainsString('api_key', (string) $result['error']);
        $this->assertSame(0, count($GLOBALS['_cb_test_http_calls']));
    }

    public function test_invalid_token_chars_returns_error_no_http(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id(array('api_key' => 'tok with space'), $this->cfg(), '240');

        $this->assertFalse($result['success']);
        $this->assertNull($result['campaign']);
        $this->assertStringContainsString('недопустимые символы', (string) $result['error']);
    }

    public function test_404_returns_success_null(): void
    {
        // Theoretical edge: 404 от прокси/LB → трактуем как «оффера нет», как у Admitad.
        $this->queue(array($this->resp(404, '{"error":"Not Found"}')));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '999999');

        $this->assertTrue($result['success']);
        $this->assertNull($result['campaign']);
        $this->assertNull($result['error']);
    }

    public function test_empty_data_array_returns_success_null(): void
    {
        $this->queue(array($this->resp(200, wp_json_encode(array(
            'success' => true,
            'total'   => 0,
            'data'    => array(),
        )))));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '240');

        $this->assertTrue($result['success']);
        $this->assertNull($result['campaign']);
    }

    public function test_orders_total_zero_returns_success_null(): void
    {
        // Оффер есть, но в окне 30 дней — 0 заказов. ar может быть 0 или null.
        // Должны вернуть success+null, не показывать ложные «0%».
        $this->queue(array($this->resp(200, wp_json_encode(array(
            'success' => true,
            'total'   => 1,
            'data'    => array(
                array('offer_id' => 240, 'orders_total' => 0, 'ar' => 0),
            ),
        )))));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '240');

        $this->assertTrue($result['success']);
        $this->assertNull($result['campaign']);
    }

    public function test_filter_min_orders_zero_allows_zero_orders(): void
    {
        $this->add_tracked_filter('cashback_advcake_stat_min_orders', static fn(): int => 0, 10, 2);

        $this->queue(array($this->resp(200, wp_json_encode(array(
            'success' => true,
            'total'   => 1,
            'data'    => array(
                array('offer_id' => 240, 'orders_total' => 0, 'ar' => 0.5),
            ),
        )))));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '240');

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['campaign']);
        $this->assertSame(50.0, $result['campaign']['rate_of_approve']);
    }

    public function test_401_returns_error_no_retry(): void
    {
        $this->queue(array($this->resp(401, '{"error":"unauthorized"}')));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '240');

        $this->assertFalse($result['success']);
        $this->assertNull($result['campaign']);
        $this->assertStringContainsString('HTTP 401', (string) $result['error']);
        $this->assertStringContainsString('api_key', (string) $result['error']);
        $this->assertSame(1, count($GLOBALS['_cb_test_http_calls']), 'no retry on 401');
    }

    public function test_403_returns_error_no_retry(): void
    {
        $this->queue(array($this->resp(403, '{"error":"forbidden"}')));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '240');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('HTTP 403', (string) $result['error']);
    }

    public function test_5xx_retries_up_to_two_then_succeeds(): void
    {
        $this->queue(array(
            $this->resp(503, ''),
            $this->resp(502, ''),
            $this->resp(200, wp_json_encode(array(
                'success' => true,
                'data'    => array(array('offer_id' => 240, 'orders_total' => 5, 'ar' => 0.8)),
            ))),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '240');

        $this->assertTrue($result['success']);
        $this->assertSame(80.0, $result['campaign']['rate_of_approve']);
        $this->assertSame(3, count($GLOBALS['_cb_test_http_calls']), '1 initial + 2 retries');
    }

    public function test_5xx_gives_up_after_two_retries(): void
    {
        $this->queue(array(
            $this->resp(500, ''),
            $this->resp(500, ''),
            $this->resp(500, ''),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '240');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('HTTP 500', (string) $result['error']);
        $this->assertSame(3, count($GLOBALS['_cb_test_http_calls']));
    }

    public function test_http_code_zero_retries_up_to_two_then_succeeds(): void
    {
        $this->queue(array(
            $this->resp(0, ''),
            $this->resp(0, ''),
            $this->resp(200, wp_json_encode(array(
                'success' => true,
                'data'    => array(array('offer_id' => 240, 'orders_total' => 5, 'ar' => 0.65)),
            ))),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '240');

        $this->assertTrue($result['success']);
        $this->assertSame(65.0, $result['campaign']['rate_of_approve']);
        $this->assertSame(3, count($GLOBALS['_cb_test_http_calls']));
    }

    public function test_429_retries_up_to_two_then_succeeds(): void
    {
        $this->queue(array(
            $this->resp(429, '{"error":"Expected available in 3 seconds."}'),
            $this->resp(200, wp_json_encode(array(
                'success' => true,
                'data'    => array(array('offer_id' => 240, 'orders_total' => 5, 'ar' => 0.8)),
            ))),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '240');

        $this->assertTrue($result['success']);
        $this->assertSame(80.0, $result['campaign']['rate_of_approve']);
        $this->assertSame(2, count($GLOBALS['_cb_test_http_calls']));
    }

    public function test_api_success_false_returns_error(): void
    {
        $this->queue(array($this->resp(200, wp_json_encode(array(
            'success' => false,
            'error'   => 'Access denied',
        )))));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '240');

        $this->assertFalse($result['success']);
        $this->assertNull($result['campaign']);
        $this->assertStringContainsString('success=false', (string) $result['error']);
        $this->assertStringContainsString('Access denied', (string) $result['error']);
    }

    public function test_api_success_string_false_returns_error(): void
    {
        $this->queue(array($this->resp(200, wp_json_encode(array(
            'success' => 'false',
            'error'   => 'Access denied',
        )))));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '240');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('success=false', (string) $result['error']);
    }

    public function test_malformed_json_returns_error(): void
    {
        $this->queue(array($this->resp(200, '<<not-json>>')));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '240');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('JSON', (string) $result['error']);
    }

    public function test_ar_out_of_range_returns_success_null(): void
    {
        // Защита от формата 0..100 в будущем (или подделанный ответ): значения
        // вне [0..1] игнорируются → success+null.
        $this->queue(array($this->resp(200, wp_json_encode(array(
            'success' => true,
            'data'    => array(array('offer_id' => 240, 'orders_total' => 10, 'ar' => 75)),
        )))));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '240');

        $this->assertTrue($result['success']);
        $this->assertNull($result['campaign']);
    }
}
