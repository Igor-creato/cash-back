<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Парсинг `ar` (approval rate) из /stat у Advcake.
 *
 * Advcake отдаёт `ar` как float [0..1] (например 0.0976 = 9.76%). В отличие
 * от Admitad `rate_of_approve` (уже в [0..100]), Advcake надо масштабировать
 * × 100. Тесты dataProvider'ом покрывают edge-кейсы:
 *   - happy: 0, 0.0976, 0.5, 1.0;
 *   - формат: int 0/1, строка "0.5", null, missing;
 *   - вне диапазона: -0.1, 1.5, 100 (защита от изменения формата API);
 *   - non-numeric: "foo".
 *
 * @group adapters
 * @group advcake
 * @group rate-of-approve
 */
#[Group('adapters')]
#[Group('advcake')]
#[Group('rate-of-approve')]
final class AdvcakeRateOfApproveNormalizeTest extends TestCase
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

        $this->add_tracked_filter('cashback_advcake_5xx_retry_delay_seconds', static fn(): int => 0, 10, 3);
        // По умолчанию допускаем любое orders_total — тесты фокусированы на `ar`.
        $this->add_tracked_filter('cashback_advcake_stat_min_orders', static fn(): int => 0, 10, 2);

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

    private function queue_payload(array $row): void
    {
        $body = wp_json_encode(array(
            'success' => true,
            'total'   => empty($row) ? 0 : 1,
            'data'    => empty($row) ? array() : array($row),
        ));
        $GLOBALS['_cb_test_http_response_callback'] = static function () use ($body) {
            return array(
                'body'     => $body,
                'response' => array('code' => 200, 'message' => 'OK'),
                'headers'  => array(),
            );
        };
    }

    private function add_tracked_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        add_filter($hook, $callback, $priority, $accepted_args);
        $this->registered_filters[] = array($hook, $callback, $priority);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: float|null}>
     */
    public static function rate_provider(): array
    {
        return array(
            'advcake real float'           => array(array('offer_id' => 1, 'orders_total' => 100, 'ar' => 0.0976), 9.76),
            'half'                         => array(array('offer_id' => 1, 'orders_total' => 100, 'ar' => 0.5), 50.0),
            'zero (with orders)'           => array(array('offer_id' => 1, 'orders_total' => 100, 'ar' => 0), 0.0),
            'one edge'                     => array(array('offer_id' => 1, 'orders_total' => 100, 'ar' => 1), 100.0),
            'numeric string'               => array(array('offer_id' => 1, 'orders_total' => 100, 'ar' => '0.75'), 75.0),
            'scientific notation string'   => array(array('offer_id' => 1, 'orders_total' => 100, 'ar' => '1e-2'), 1.0),
            'int zero'                     => array(array('offer_id' => 1, 'orders_total' => 100, 'ar' => 0), 0.0),
            'over 1 — clamp to null'       => array(array('offer_id' => 1, 'orders_total' => 100, 'ar' => 1.5), null),
            'over 1 looks like percent'    => array(array('offer_id' => 1, 'orders_total' => 100, 'ar' => 75), null),
            'negative — clamp to null'     => array(array('offer_id' => 1, 'orders_total' => 100, 'ar' => -0.1), null),
            'non-numeric — null'           => array(array('offer_id' => 1, 'orders_total' => 100, 'ar' => 'foo'), null),
            'array value — null'           => array(array('offer_id' => 1, 'orders_total' => 100, 'ar' => array(0.5)), null),
            'null value — null'            => array(array('offer_id' => 1, 'orders_total' => 100, 'ar' => null), null),
            'missing field — null'         => array(array('offer_id' => 1, 'orders_total' => 100), null),
            'rounding 2 decimals'          => array(array('offer_id' => 1, 'orders_total' => 100, 'ar' => 0.123456), 12.35),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param float|null           $expected
     */
    #[DataProvider('rate_provider')]
    public function test_extract_rate_of_approve_from_stat(array $row, ?float $expected): void
    {
        $this->queue_payload($row);

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id(
            array('api_key' => 'tok-abc'),
            array('api_base_url' => 'https://api.advcake.ru'),
            '12345'
        );

        $this->assertTrue($result['success'], 'fetch_campaign_by_id should succeed on 200');

        if ($expected === null) {
            $this->assertNull($result['campaign'], 'invalid/out-of-range ar → success+null');
        } else {
            $this->assertIsArray($result['campaign']);
            $this->assertArrayHasKey('rate_of_approve', $result['campaign']);
            $this->assertSame($expected, $result['campaign']['rate_of_approve']);
        }
    }

    public function test_field_name_is_filterable(): void
    {
        // Если адаптер-наследник переименует поле — filter позволяет переключить
        // без правки кода. По умолчанию читаем 'ar'.
        $this->queue_payload(array('offer_id' => 1, 'orders_total' => 100, 'ar' => 0.3, 'custom_rate' => 0.7));

        $this->add_tracked_filter('cashback_advcake_stat_rate_fields', static fn() => array('custom_rate'), 10, 2);

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id(
            array('api_key' => 'tok-abc'),
            array('api_base_url' => 'https://api.advcake.ru'),
            '12345'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(70.0, $result['campaign']['rate_of_approve'], 'filter overrides field name');
    }

    public function test_non_array_rate_fields_filter_falls_back_to_scalar_cast(): void
    {
        $this->queue_payload(array('offer_id' => 1, 'orders_total' => 100, 'custom_rate' => 0.4));
        $this->add_tracked_filter('cashback_advcake_stat_rate_fields', static fn() => 'custom_rate', 10, 2);

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id(
            array('api_key' => 'tok-abc'),
            array('api_base_url' => 'https://api.advcake.ru'),
            '12345'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(40.0, $result['campaign']['rate_of_approve']);
    }

    public function test_rate_fields_filter_ignores_null_candidates_and_uses_ar(): void
    {
        $this->queue_payload(array('offer_id' => 1, 'orders_total' => 100, 'ar' => 0.4));
        $this->add_tracked_filter('cashback_advcake_stat_rate_fields', static fn() => array(null, 'ar'), 10, 2);

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaign_by_id(
            array('api_key' => 'tok-abc'),
            array('api_base_url' => 'https://api.advcake.ru'),
            '12345'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(40.0, $result['campaign']['rate_of_approve']);
    }
}
