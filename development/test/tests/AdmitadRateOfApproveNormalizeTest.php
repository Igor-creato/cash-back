<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Тесты парсера `rate_of_approve` в Admitad-адаптере.
 *
 * Поле приходит **только** в per-campaign endpoint `/advcampaigns/{id}/`
 * (подтверждено пробным запросом 2026-05-20). В payload это строка вида
 * "75" — поэтому `is_numeric` + `(float)` обязательны. Валидация
 * [0..100], outside-range → null (честное «нет данных», не ложный 0).
 *
 * @group adapters
 * @group admitad
 * @group rate-of-approve
 */
#[Group('adapters')]
#[Group('admitad')]
#[Group('rate-of-approve')]
final class AdmitadRateOfApproveNormalizeTest extends TestCase
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

    private function queue_payload(array $payload): void
    {
        $body = wp_json_encode($payload);
        $GLOBALS['_cb_test_http_response_callback'] = static function () use ($body) {
            return array(
                'body'     => $body,
                'response' => array('code' => 200, 'message' => 'OK'),
                'headers'  => array(),
            );
        };
    }

    public static function rate_provider(): array
    {
        return array(
            'admitad real string'         => array(array('id' => 1, 'rate_of_approve' => '75'), 75.0),
            'integer'                     => array(array('id' => 2, 'rate_of_approve' => 80), 80.0),
            'float string with decimals'  => array(array('id' => 3, 'rate_of_approve' => '45.55'), 45.55),
            'zero'                        => array(array('id' => 4, 'rate_of_approve' => 0), 0.0),
            'hundred edge'                => array(array('id' => 5, 'rate_of_approve' => 100), 100.0),
            'over 100 — clamp to null'    => array(array('id' => 6, 'rate_of_approve' => 150), null),
            'negative — clamp to null'    => array(array('id' => 7, 'rate_of_approve' => -10), null),
            'non-numeric string'          => array(array('id' => 8, 'rate_of_approve' => 'foo'), null),
            'null value'                  => array(array('id' => 9, 'rate_of_approve' => null), null),
            'missing field'               => array(array('id' => 10), null),
            'rounding 2 decimals'         => array(array('id' => 11, 'rate_of_approve' => '75.555'), 75.56),
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @param float|null           $expected
     */
    #[DataProvider('rate_provider')]
    public function test_extract_rate_of_approve_normalizes_payload_value(array $raw, ?float $expected): void
    {
        $this->queue_payload($raw);

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaign_by_id(
            array('client_id' => 'cid', 'client_secret' => 'csec', 'scope' => 'advcampaigns'),
            array('api_base_url' => 'https://api.admitad.com', 'api_website_id' => '42'),
            '12345'
        );

        $this->assertTrue($result['success'], 'fetch_campaign_by_id should succeed on 200');
        $this->assertIsArray($result['campaign']);
        $this->assertArrayHasKey('rate_of_approve', $result['campaign']);
        $this->assertSame($expected, $result['campaign']['rate_of_approve']);
    }

    public function test_field_name_is_filterable(): void
    {
        $this->queue_payload(array('id' => 12345, 'rate_of_approve' => 70, 'custom_rate' => 88));

        add_filter('cashback_admitad_rate_of_approve_fields', static function ($default) {
            return array('custom_rate');
        });

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaign_by_id(
            array('client_id' => 'cid', 'client_secret' => 'csec'),
            array('api_base_url' => 'https://api.admitad.com'),
            '12345'
        );

        $this->assertSame(88.0, $result['campaign']['rate_of_approve'], 'filter overrides field name');
    }
}
