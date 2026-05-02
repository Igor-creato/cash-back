<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты на Cashback_Network_Http_Client — generic HTTP-клиент,
 * собирающий auth-headers по api_auth_type сети:
 *   - oauth2_client_credentials → Bearer token через OAuth2 helper.
 *   - api_key → X-API-Key header.
 *   - bearer_token → Authorization: Bearer <token>.
 *
 * @group promocodes
 * @group adapters
 */
#[Group('promocodes')]
#[Group('adapters')]
final class NetworkHttpClientTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
        $files = array(
            '/includes/class-cashback-outbound-http-guard.php',
            '/includes/oauth/class-oauth2-client-credentials-helper.php',
            '/includes/promocodes/class-network-http-client.php',
        );
        foreach ($files as $f) {
            $path = self::$plugin_root . $f;
            if (!file_exists($path)) {
                self::markTestSkipped("File missing: {$f}");
            }
            require_once $path;
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options']    = array();
        $GLOBALS['_cb_test_filters']    = array();
        $GLOBALS['_cb_test_transients'] = array();
        $GLOBALS['_cb_audit_log_calls'] = array();
        $GLOBALS['_cb_test_http_calls'] = array();
        $GLOBALS['_cb_test_http_response'] = array(
            'body'     => '{"results":[]}',
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'headers'  => array(),
        );

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public function insert(string $table, array $data, $format = null): int { return 1; }
        };

        if (class_exists('Cashback_Outbound_HTTP_Guard')) {
            Cashback_Outbound_HTTP_Guard::invalidate_cache();
        }
    }

    public function test_api_key_auth_sends_x_api_key_header(): void
    {
        $client = new Cashback_Network_Http_Client();
        $client->get(
            'https://api.admitad.com/coupons/',
            array(
                'auth_type'   => 'api_key',
                'credentials' => array( 'api_key' => 'KEY_XYZ' ),
                'token_url'   => null,
            )
        );

        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
        $headers = $GLOBALS['_cb_test_http_calls'][0]['args']['headers'];
        $this->assertSame('KEY_XYZ', $headers['X-API-Key']);
    }

    public function test_bearer_token_auth_sends_authorization_header(): void
    {
        $client = new Cashback_Network_Http_Client();
        $client->get(
            'https://api.admitad.com/coupons/',
            array(
                'auth_type'   => 'bearer_token',
                'credentials' => array( 'access_token' => 'BT_ABC' ),
                'token_url'   => null,
            )
        );

        $headers = $GLOBALS['_cb_test_http_calls'][0]['args']['headers'];
        $this->assertSame('Bearer BT_ABC', $headers['Authorization']);
    }

    public function test_oauth2_uses_helper_to_obtain_token(): void
    {
        // Pre-cache токен через transient — helper вернёт его без HTTP-вызова к token URL.
        set_transient('cashback_oauth2_admitad_' . md5('cid_a'), 'CACHED_TOKEN', 3600);

        $client = new Cashback_Network_Http_Client();
        $client->get(
            'https://api.admitad.com/coupons/',
            array(
                'auth_type'   => 'oauth2_client_credentials',
                'credentials' => array(
                    'client_id'     => 'cid_a',
                    'client_secret' => 'sec',
                    'scope'         => 'coupons_for_website',
                ),
                'token_url'        => 'https://api.admitad.com/token/',
                'cache_namespace'  => 'cashback_oauth2_admitad',
            )
        );

        $headers = $GLOBALS['_cb_test_http_calls'][0]['args']['headers'];
        $this->assertSame('Bearer CACHED_TOKEN', $headers['Authorization']);
    }

    public function test_oauth2_returns_wp_error_when_token_unavailable(): void
    {
        // Token endpoint выдаст ошибку.
        $GLOBALS['_cb_test_http_response'] = array(
            'body'     => wp_json_encode(array( 'error' => 'invalid_client' )),
            'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
            'headers'  => array(),
        );

        $client = new Cashback_Network_Http_Client();
        $result = $client->get(
            'https://api.admitad.com/coupons/',
            array(
                'auth_type'   => 'oauth2_client_credentials',
                'credentials' => array(
                    'client_id'     => 'cid_b',
                    'client_secret' => 'sec',
                    'scope'         => 'x',
                ),
                'token_url'       => 'https://api.admitad.com/token/',
                'cache_namespace' => 'cashback_oauth2_admitad',
            )
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('oauth2_token_failed', $result->get_error_code());
    }

    public function test_ssrf_guard_blocks_private_ip(): void
    {
        $client = new Cashback_Network_Http_Client();
        $result = $client->get(
            'https://169.254.169.254/coupons/',
            array(
                'auth_type'   => 'api_key',
                'credentials' => array( 'api_key' => 'KEY' ),
            )
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('outbound_denied', $result->get_error_code());
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_invalidate_oauth_token_clears_cache(): void
    {
        $cache_key = 'cashback_oauth2_admitad_' . md5('cid_c');
        set_transient($cache_key, 'OLD', 3600);

        $client = new Cashback_Network_Http_Client();
        $client->invalidate_oauth_token('cid_c', 'cashback_oauth2_admitad');

        $this->assertFalse(get_transient($cache_key));
    }

    public function test_returns_wp_error_for_unknown_auth_type(): void
    {
        $client = new Cashback_Network_Http_Client();
        $result = $client->get(
            'https://api.admitad.com/coupons/',
            array(
                'auth_type'   => 'mystery_auth',
                'credentials' => array(),
            )
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('unsupported_auth_type', $result->get_error_code());
    }
}
