<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional тесты на Cashback_Epn_Adapter::fetch_campaigns_detailed (v12, Этап 4).
 *
 * EPN использует JSON:API style ответы (`data[].attributes`), pagination
 * через limit+offset, статусы active/disabled/waiting/stopped.
 *
 * @group adapters
 * @group epn
 * @group shop-import
 */
#[Group('adapters')]
#[Group('epn')]
#[Group('shop-import')]
final class EpnShopsDetailedTest extends TestCase
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

    private function fixture_offers_payload(): string
    {
        return wp_json_encode(array(
            'meta' => array('count' => 2),
            'data' => array(
                array(
                    'id'         => '999',
                    'attributes' => array(
                        'name'           => 'AliExpress',
                        'title'          => 'AliExpress Marketplace',
                        'status'         => 'active',
                        'site_url'       => 'https://aliexpress.com',
                        'image'          => 'https://cdn.epn.bz/ali.png',
                        'description'    => 'Global marketplace',
                        'offer_currency' => 'usd',
                        'goto_link'      => 'https://app.epn.bz/g/abc?subid={click_id}',
                        'categories'     => array(
                            array('id' => 1, 'name' => 'Electronics'),
                            array('id' => 2, 'name' => 'Apparel'),
                        ),
                        'countries'      => array(
                            array('code' => 'RU'),
                            array('code' => 'BY'),
                        ),
                    ),
                ),
                array(
                    'id'         => '1000',
                    'attributes' => array(
                        'name'       => 'PausedShop',
                        'status'     => 'disabled',
                        'site_url'   => 'https://paused.example',
                        'categories' => array('Misc'), // плоский формат
                        'countries'  => array('RU'),
                    ),
                ),
            ),
        ));
    }

    // ============================================================
    // Success path
    // ============================================================

    public function test_returns_normalized_campaigns_on_success(): void
    {
        $this->queue_responses(array($this->http_response(200, $this->fixture_offers_payload())));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertCount(2, $result['campaigns']);

        $first = $result['campaigns'][0];
        $this->assertSame('999', $first['id']);
        $this->assertSame('AliExpress', $first['name']);
        $this->assertSame('https://aliexpress.com', $first['site_url']);
        $this->assertSame('https://cdn.epn.bz/ali.png', $first['image_url']);
        $this->assertSame('active', $first['status_raw']);
        $this->assertTrue($first['is_active']);
        $this->assertSame('USD', $first['currency'], 'currency uppercased');
        $this->assertSame(array('RU', 'BY'), $first['regions'], 'countries → regions');
        $this->assertSame(array('Electronics', 'Apparel'), $first['categories']);
        $this->assertSame('https://app.epn.bz/g/abc?subid={click_id}', $first['goto_link']);
        $this->assertIsArray($first['raw']);
    }

    public function test_disabled_status_marked_inactive(): void
    {
        $this->queue_responses(array($this->http_response(200, $this->fixture_offers_payload())));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $second = $result['campaigns'][1];
        $this->assertSame('disabled', $second['status_raw']);
        $this->assertFalse($second['is_active']);
        $this->assertSame('RUB', $second['currency'], 'default currency = RUB при отсутствии offer_currency');
        $this->assertSame(array('Misc'), $second['categories']);
        $this->assertSame(array('RU'), $second['regions']);
    }

    public function test_url_includes_extended_fields(): void
    {
        $this->queue_responses(array($this->http_response(200, '{"data":[],"meta":{"count":0}}')));

        $adapter = $this->make_adapter_with_token();
        $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $url = $GLOBALS['_cb_test_http_calls'][0]['url'];
        $this->assertStringContainsString('app.epn.bz/offers/list', $url);
        $this->assertStringContainsString('site_url', $url, 'fields включает site_url');
        $this->assertStringContainsString('image', $url);
        $this->assertStringContainsString('goto_link', $url);
        $this->assertStringContainsString('categories', $url);
        $this->assertStringContainsString('countries', $url);
    }

    public function test_skips_entries_without_id(): void
    {
        $payload = wp_json_encode(array(
            'meta' => array('count' => 2),
            'data' => array(
                array('id' => '', 'attributes' => array('name' => 'no-id', 'status' => 'active')),
                array('id' => 'valid', 'attributes' => array('name' => 'OK', 'status' => 'active')),
            ),
        ));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertCount(1, $result['campaigns']);
        $this->assertSame('valid', $result['campaigns'][0]['id']);
    }

    public function test_pagination_has_next_correct(): void
    {
        $items   = array_fill(0, 100, array(
            'id'         => '1',
            'attributes' => array('name' => 'X', 'status' => 'active'),
        ));
        $payload = wp_json_encode(array('meta' => array('count' => 250), 'data' => $items));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertTrue($result['has_next']);
        $this->assertSame(100, $result['next_offset']);
    }

    public function test_pagination_last_page(): void
    {
        $items   = array_fill(0, 50, array(
            'id'         => '1',
            'attributes' => array('name' => 'X', 'status' => 'active'),
        ));
        $payload = wp_json_encode(array('meta' => array('count' => 50), 'data' => $items));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertFalse($result['has_next']);
    }

    public function test_name_falls_back_to_title(): void
    {
        $payload = wp_json_encode(array(
            'meta' => array('count' => 1),
            'data' => array(
                array('id' => '1', 'attributes' => array('title' => 'Title Only', 'status' => 'active')),
            ),
        ));
        $this->queue_responses(array($this->http_response(200, $payload)));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertSame('Title Only', $result['campaigns'][0]['name']);
    }

    // ============================================================
    // Auth/retry paths
    // ============================================================

    public function test_retries_once_on_401(): void
    {
        $this->queue_responses(array(
            $this->http_response(401, '{"errors":[{"detail":"unauthorized"}]}'),
            $this->http_response(200, $this->fixture_offers_payload()),
        ));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_retries_once_on_403(): void
    {
        $this->queue_responses(array(
            $this->http_response(403, '{"errors":[{"detail":"forbidden"}]}'),
            $this->http_response(200, $this->fixture_offers_payload()),
        ));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_returns_error_on_http_500(): void
    {
        $this->queue_responses(array($this->http_response(500, 'Internal Server Error')));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('500', $result['error']);
    }

    public function test_returns_error_when_token_unavailable(): void
    {
        $adapter = $this->make_adapter_with_token('');
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 100);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('токен', mb_strtolower($result['error']));
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls']);
    }
}
