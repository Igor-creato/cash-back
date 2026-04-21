<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты интеграции Cashback_Outbound_HTTP_Guard в Cashback_Network_Adapter_Base.
 *
 * Проверяет, что http_get/http_post базового адаптера возвращают WP_Error
 * и НЕ вызывают wp_remote_* при попытке обращения к disallowed URL
 * (private-IP литерал или хост вне allowlist).
 *
 * См. ADR Группа 3, finding F-12-001.
 */
#[Group('security')]
#[Group('ssrf')]
#[Group('outbound')]
#[Group('adapters')]
class NetworkAdapterSsrfTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        $guard_file = self::$plugin_root . '/includes/class-cashback-outbound-http-guard.php';
        if (!file_exists($guard_file)) {
            self::markTestSkipped('Cashback_Outbound_HTTP_Guard file not present yet.');
        }

        self::require_if_missing(self::$plugin_root . '/includes/class-cashback-outbound-http-guard.php', 'Cashback_Outbound_HTTP_Guard');
        self::require_if_missing(self::$plugin_root . '/includes/adapters/interface-cashback-network-adapter.php', null);
        self::require_if_missing(self::$plugin_root . '/includes/adapters/abstract-cashback-network-adapter.php', 'Cashback_Network_Adapter_Base');
    }

    private static function require_if_missing(string $file, ?string $class): void
    {
        if ($class !== null && class_exists($class)) {
            return;
        }
        if (!file_exists($file)) {
            self::markTestSkipped("File missing: {$file}");
        }
        require_once $file;
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options']    = array();
        $GLOBALS['_cb_test_filters']    = array();
        $GLOBALS['_cb_audit_log_calls'] = array();
        $GLOBALS['_cb_test_http_calls'] = array();
        $GLOBALS['_cb_test_http_response'] = array(
            'body'     => '{"status":"ok"}',
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'headers'  => array(),
        );

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public function insert(string $table, array $data, $format = null): int
            {
                $GLOBALS['_cb_audit_log_calls'][] = array( 'table' => $table, 'data' => $data );
                return 1;
            }
        };

        if (class_exists('Cashback_Outbound_HTTP_Guard')) {
            Cashback_Outbound_HTTP_Guard::invalidate_cache();
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['_cb_test_options']       = array();
        $GLOBALS['_cb_test_filters']       = array();
        $GLOBALS['_cb_test_http_calls']    = array();
        unset($GLOBALS['wpdb']);

        if (class_exists('Cashback_Outbound_HTTP_Guard')) {
            Cashback_Outbound_HTTP_Guard::invalidate_cache();
        }
    }

    /**
     * Анонимный наследник базы адаптера, публикующий protected-методы
     * http_get / http_post для тестирования.
     */
    private function make_public_adapter(): object
    {
        return new class extends Cashback_Network_Adapter_Base {

            public function get_slug(): string
            {
                return 'test-adapter';
            }

            public function get_token( array $credentials, array $network_config ): ?string
            {
                return null;
            }

            public function build_auth_headers( array $credentials, array $network_config ): ?array
            {
                return array();
            }

            public function fetch_all_actions( array $credentials, array $params, int $max_pages, array $network_config ): array
            {
                return array( 'success' => true, 'actions' => array(), 'total' => 0 );
            }

            public function get_default_status_map(): array
            {
                return array();
            }

            public function test_http_get( string $url, array $headers = array() ): mixed
            {
                return $this->http_get($url, $headers);
            }

            public function test_http_post( string $url, array $headers = array(), mixed $body = '' ): mixed
            {
                return $this->http_post($url, $headers, $body);
            }
        };
    }

    // ================================================================
    // http_get — deny → WP_Error, нет вызова wp_remote_get
    // ================================================================

    public function test_http_get_denied_for_metadata_ip_no_remote_call(): void
    {
        $adapter = $this->make_public_adapter();
        $result  = $adapter->test_http_get('https://169.254.169.254/latest/meta-data/');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('outbound_denied', $result->get_error_code());
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls'], 'wp_remote_get должен НЕ вызываться на denied URL');
    }

    public function test_http_get_denied_for_unknown_host_no_remote_call(): void
    {
        $adapter = $this->make_public_adapter();
        $result  = $adapter->test_http_get('https://evil.example.com/');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_http_get_denied_for_http_scheme(): void
    {
        $adapter = $this->make_public_adapter();
        $result  = $adapter->test_http_get('http://api.admitad.com/');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_http_get_allowed_host_makes_remote_call(): void
    {
        $adapter = $this->make_public_adapter();
        $result  = $adapter->test_http_get('https://api.admitad.com/token/');

        $this->assertNotInstanceOf(WP_Error::class, $result);
        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
        $this->assertSame('GET', $GLOBALS['_cb_test_http_calls'][0]['method']);
        $this->assertSame('https://api.admitad.com/token/', $GLOBALS['_cb_test_http_calls'][0]['url']);
    }

    // ================================================================
    // http_post — симметричные проверки
    // ================================================================

    public function test_http_post_denied_for_metadata_ip_no_remote_call(): void
    {
        $adapter = $this->make_public_adapter();
        $result  = $adapter->test_http_post('https://169.254.169.254/', array(), array());

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_http_post_denied_for_unknown_host_no_remote_call(): void
    {
        $adapter = $this->make_public_adapter();
        $result  = $adapter->test_http_post('https://evil.example.com/', array(), array());

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_http_post_allowed_host_makes_remote_call(): void
    {
        $adapter = $this->make_public_adapter();
        $result  = $adapter->test_http_post('https://oauth2.epn.bz/token', array(), array( 'foo' => 'bar' ));

        $this->assertNotInstanceOf(WP_Error::class, $result);
        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
        $this->assertSame('POST', $GLOBALS['_cb_test_http_calls'][0]['method']);
    }

    // ================================================================
    // Audit-log на deny
    // ================================================================

    public function test_http_get_denied_writes_audit_log(): void
    {
        $adapter = $this->make_public_adapter();
        $adapter->test_http_get('https://169.254.169.254/');

        $this->assertNotEmpty($GLOBALS['_cb_audit_log_calls']);
        $entry = $GLOBALS['_cb_audit_log_calls'][0];
        $this->assertSame('outbound_request_denied', $entry['data']['action']);
    }
}
