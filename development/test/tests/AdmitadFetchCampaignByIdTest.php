<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * fetch_campaign_by_id() — per-campaign GET /advcampaigns/{id}/.
 * Happy path + retry на 401 + ошибки 404/500/empty-body/no-token.
 *
 * @group adapters
 * @group admitad
 * @group rate-of-approve
 */
#[Group('adapters')]
#[Group('admitad')]
#[Group('rate-of-approve')]
final class AdmitadFetchCampaignByIdTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
        foreach (array(
            '/includes/class-cashback-outbound-http-guard.php',
            '/includes/oauth/class-oauth2-client-credentials-helper.php',
            '/includes/adapters/interface-cashback-network-adapter.php',
            '/includes/adapters/abstract-cashback-network-adapter.php',
            '/includes/adapters/class-admitad-adapter.php',
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

    private function make_adapter(string $token = 'tok-xyz'): Cashback_Admitad_Adapter
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

    private function queue(array $responses): void
    {
        $queue = $responses;
        $GLOBALS['_cb_test_http_response_callback'] = static function () use (&$queue) {
            if (count($queue) > 1) {
                return array_shift($queue);
            }
            return $queue[0] ?? array('body' => '', 'response' => array('code' => 500, 'message' => 'X'), 'headers' => array());
        };
    }

    private function resp(int $code, string $body): array
    {
        return array('body' => $body, 'response' => array('code' => $code, 'message' => 'HTTP'), 'headers' => array());
    }

    private function creds(): array
    {
        return array('client_id' => 'cid', 'client_secret' => 'csec', 'scope' => 'advcampaigns');
    }

    private function cfg(): array
    {
        return array('api_base_url' => 'https://api.admitad.com', 'api_website_id' => '42');
    }

    public function test_happy_path_returns_normalized_campaign(): void
    {
        $this->queue(array($this->resp(200, wp_json_encode(array(
            'id'                => 2381,
            'name'              => 'Kaspersky',
            'site_url'          => 'https://www.kaspersky.ru/home-security',
            'status'            => 'active',
            'connection_status' => 'active',
            'currency'          => 'rub',
            'rate_of_approve'   => '75',
            'avg_money_transfer_time' => 29,
            'avg_hold_time'     => 29,
        )))));

        $adapter = $this->make_adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '2381');

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertSame('2381', $result['campaign']['id']);
        $this->assertSame('Kaspersky', $result['campaign']['name']);
        $this->assertSame(75.0, $result['campaign']['rate_of_approve']);
        $this->assertSame(29, $result['campaign']['payment_time_days']);
    }

    public function test_empty_campaign_id_returns_error_without_http(): void
    {
        // Если зайдёт в http_get — заверну все ответы в 500, тест провалится.
        $this->queue(array($this->resp(500, '')));

        $adapter = $this->make_adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '');

        $this->assertFalse($result['success']);
        $this->assertNull($result['campaign']);
        $this->assertSame('campaign_id обязателен', $result['error']);
        $this->assertSame(0, count($GLOBALS['_cb_test_http_calls']), 'no HTTP call without id');
    }

    public function test_no_token_returns_error(): void
    {
        $adapter = $this->make_adapter('');

        $result = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '2381');

        $this->assertFalse($result['success']);
        $this->assertNull($result['campaign']);
        $this->assertStringContainsString('токен', $result['error']);
    }

    public function test_401_invalidates_token_and_retries(): void
    {
        $this->queue(array(
            $this->resp(401, '{"error":"invalid_token"}'),
            $this->resp(200, wp_json_encode(array('id' => 1, 'rate_of_approve' => '60'))),
        ));

        $adapter = $this->make_adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '1');

        $this->assertTrue($result['success']);
        $this->assertSame(60.0, $result['campaign']['rate_of_approve']);
        $this->assertSame(1, $adapter->invalidate_count, '401 should invalidate token once');
    }

    public function test_404_means_campaign_deleted_returns_success_null(): void
    {
        // 404 = удалённая кампания → success=true + campaign=null,
        // caller (Refresher / Provider) интерпретирует как «удалить мету».
        // Это НЕ ошибка, а валидный «нет данных от сети» (prod-инцидент
        // 2026-05-20: 404 для product=4203, кампания исчезла из Admitad).
        $this->queue(array($this->resp(404, '{"error":"Not Found"}')));

        $adapter = $this->make_adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '999999');

        $this->assertTrue($result['success']);
        $this->assertNull($result['campaign']);
        $this->assertNull($result['error']);
    }

    public function test_429_retries_with_admitad_body_delay(): void
    {
        // Отключаем sleep в тестах — filter возвращает 0.
        add_filter('cashback_admitad_429_retry_delay_seconds', static fn() => 0);

        $this->queue(array(
            $this->resp(429, '{"error":"Запрос был проигнорирован. Expected available in 11 seconds."}'),
            $this->resp(200, wp_json_encode(array('id' => 1, 'rate_of_approve' => '60'))),
        ));

        $adapter = $this->make_adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '1');

        $this->assertTrue($result['success']);
        $this->assertSame(60.0, $result['campaign']['rate_of_approve']);
    }

    public function test_429_after_2_retries_returns_error(): void
    {
        add_filter('cashback_admitad_429_retry_delay_seconds', static fn() => 0);

        // Три подряд 429 — после 2 повторов сдаёмся.
        $this->queue(array(
            $this->resp(429, '{"error":"available in 5 seconds"}'),
            $this->resp(429, '{"error":"available in 5 seconds"}'),
            $this->resp(429, '{"error":"available in 5 seconds"}'),
        ));

        $adapter = $this->make_adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('HTTP 429', (string) $result['error']);
    }

    public function test_invalid_json_returns_error(): void
    {
        $this->queue(array($this->resp(200, '<<not-json>>')));

        $adapter = $this->make_adapter();
        $result  = $adapter->fetch_campaign_by_id($this->creds(), $this->cfg(), '2381');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('JSON', (string) $result['error']);
    }
}
