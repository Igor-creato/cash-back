<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional тесты на Cashback_Epn_Adapter::fetch_shop_tariffs (v12, Этап 4).
 *
 * Endpoint: GET /offers/{id}, тарифы в data.attributes.rates[].
 * EPN передаёт rate_type ('percent' / 'fixed') и rate (число).
 * Currency может быть в self или в parent offer_currency.
 *
 * @group adapters
 * @group epn
 * @group shop-import
 */
#[Group('adapters')]
#[Group('epn')]
#[Group('shop-import')]
final class EpnShopTariffsTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        self::require_if_missing('/includes/class-cashback-outbound-http-guard.php', 'Cashback_Outbound_HTTP_Guard');
        self::require_if_missing('/includes/oauth/class-oauth2-client-credentials-helper.php', 'Cashback_OAuth2_Client_Credentials_Helper');
        self::require_if_missing('/includes/adapters/interface-cashback-network-adapter.php', null);
        self::require_if_missing('/includes/adapters/abstract-cashback-network-adapter.php', 'Cashback_Network_Adapter_Base');
        self::require_if_missing('/includes/adapters/class-epn-adapter.php', 'Cashback_Epn_Adapter');
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

    private function make_adapter_with_token(string $token = 'epn-tok'): Cashback_Epn_Adapter
    {
        return new class($token) extends Cashback_Epn_Adapter {
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
                return array('X-ACCESS-TOKEN' => $this->stub_token);
            }
        };
    }

    private function default_credentials(): array
    {
        return array('client_id' => 'cid', 'client_secret' => 'csec');
    }

    private function default_network_config(): array
    {
        return array('api_base_url' => 'https://oauth2.epn.bz');
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

    private function fixture_offer_payload(): string
    {
        return wp_json_encode(array(
            'data' => array(
                'id'         => '999',
                'attributes' => array(
                    'name'           => 'AliExpress',
                    'offer_currency' => 'USD',
                    'rates'          => array(
                        array(
                            'id'         => 'rate-percent',
                            'name'       => 'Стандартная категория',
                            'rate_type'  => 'percent',
                            'rate'       => 5.5,
                            'is_default' => true,
                        ),
                        array(
                            'id'        => 'rate-fix',
                            'name'      => 'Фиксированная',
                            'rate_type' => 'fixed',
                            'rate'      => 100.0,
                            'currency'  => 'EUR',
                        ),
                        array(
                            'id'   => 'rate-bare',
                            'name' => 'Без типа',
                            'rate' => 7.0,
                        ),
                    ),
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
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('campaign_id', $result['error']);
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_returns_error_when_token_unavailable(): void
    {
        $adapter = $this->make_adapter_with_token('');
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '999');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('токен', mb_strtolower($result['error']));
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls']);
    }

    // ============================================================
    // Success path
    // ============================================================

    public function test_url_includes_offer_id(): void
    {
        $this->queue_responses(array($this->http_response(200, '{"data":{"id":"999","attributes":{"rates":[]}}}')));

        $adapter = $this->make_adapter_with_token();
        $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '999');

        $url = $GLOBALS['_cb_test_http_calls'][0]['url'];
        $this->assertStringContainsString('app.epn.bz/offers/999', $url);
    }

    public function test_returns_normalized_tariffs_on_success(): void
    {
        $this->queue_responses(array($this->http_response(200, $this->fixture_offer_payload())));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '999');

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['tariffs']);

        $percent = $result['tariffs'][0];
        $this->assertSame('rate-percent', $percent['tariff_id']);
        $this->assertSame('percent', $percent['tariff_type']);
        $this->assertSame(5.5, $percent['payment_size']);
        $this->assertSame('USD', $percent['currency'], 'currency наследуется от offer_currency');
        $this->assertTrue($percent['is_default']);

        $fix = $result['tariffs'][1];
        $this->assertSame('fix', $fix['tariff_type'], 'EPN type "fixed" → "fix"');
        $this->assertSame(100.0, $fix['payment_size']);
        $this->assertSame('EUR', $fix['currency'], 'currency на rate перекрывает offer_currency');
        $this->assertFalse($fix['is_default']);

        $bare = $result['tariffs'][2];
        $this->assertSame('percent', $bare['tariff_type'], 'без rate_type → fallback по эвристике/default percent');
    }

    public function test_skips_rates_without_id(): void
    {
        $payload = wp_json_encode(array(
            'data' => array(
                'id'         => '1',
                'attributes' => array(
                    'rates' => array(
                        array('id' => '', 'rate_type' => 'percent', 'rate' => 5),
                        array('id' => 'valid', 'rate_type' => 'percent', 'rate' => 10),
                    ),
                ),
            ),
        ));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '1');

        $this->assertCount(1, $result['tariffs']);
        $this->assertSame('valid', $result['tariffs'][0]['tariff_id']);
    }

    public function test_empty_rates_array(): void
    {
        $payload = wp_json_encode(array(
            'data' => array('id' => '1', 'attributes' => array('rates' => array())),
        ));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '1');

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['tariffs']);
    }

    public function test_default_currency_rub_when_offer_currency_missing(): void
    {
        $payload = wp_json_encode(array(
            'data' => array(
                'id'         => '1',
                'attributes' => array(
                    'rates' => array(
                        array('id' => 'r1', 'rate_type' => 'percent', 'rate' => 5),
                    ),
                ),
            ),
        ));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '1');

        $this->assertSame('RUB', $result['tariffs'][0]['currency']);
    }

    public function test_payment_size_falls_back_to_rate_or_payment_size_field(): void
    {
        // Некоторые реализации EPN-API могут отдать `payment_size` вместо `rate`.
        $payload = wp_json_encode(array(
            'data' => array(
                'id'         => '1',
                'attributes' => array(
                    'rates' => array(
                        array('id' => 'a', 'rate_type' => 'percent', 'rate' => 5.0),
                        array('id' => 'b', 'rate_type' => 'fixed', 'payment_size' => 99.0),
                        array('id' => 'c', 'rate_type' => 'percent'),
                    ),
                ),
            ),
        ));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '1');

        $this->assertSame(5.0, $result['tariffs'][0]['payment_size']);
        $this->assertSame(99.0, $result['tariffs'][1]['payment_size']);
        $this->assertSame(0.0, $result['tariffs'][2]['payment_size'], 'без значения → 0.0');
    }

    // ============================================================
    // Retry / errors
    // ============================================================

    public function test_retries_once_on_401(): void
    {
        $this->queue_responses(array(
            $this->http_response(401, '{"errors":[]}'),
            $this->http_response(200, $this->fixture_offer_payload()),
        ));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '999');

        $this->assertTrue($result['success']);
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_returns_error_on_http_500(): void
    {
        $this->queue_responses(array($this->http_response(500, 'oops')));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), '999');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('500', $result['error']);
    }

    public function test_returns_error_on_http_404(): void
    {
        $this->queue_responses(array($this->http_response(404, '{"errors":[]}')));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_shop_tariffs($this->default_credentials(), $this->default_network_config(), 'unknown');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('404', $result['error']);
    }
}
