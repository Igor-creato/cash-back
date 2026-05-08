<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты на Cashback_OAuth2_Client_Credentials_Helper — generic OAuth2
 * client_credentials grant с кешированием (transient + runtime).
 *
 * Helper извлечён из Cashback_Admitad_Adapter::get_token() — должен
 * сохранять обратную совместимость с продакшн-поведением Admitad адаптера
 * (см. includes/adapters/class-admitad-adapter.php).
 *
 * @group oauth
 * @group adapters
 */
#[Group('oauth')]
#[Group('adapters')]
final class OAuth2ClientCredentialsHelperTest extends TestCase
{
    private const TOKEN_URL = 'https://api.admitad.com/token/';

    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        $helper_file = self::$plugin_root . '/includes/oauth/class-oauth2-client-credentials-helper.php';
        if (!file_exists($helper_file)) {
            self::markTestSkipped('Cashback_OAuth2_Client_Credentials_Helper file not present yet.');
        }

        if (!class_exists('Cashback_Outbound_HTTP_Guard')) {
            require_once self::$plugin_root . '/includes/class-cashback-outbound-http-guard.php';
        }
        if (!class_exists('Cashback_OAuth2_Client_Credentials_Helper')) {
            require_once $helper_file;
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
            'body'     => wp_json_encode(array(
                'access_token' => 'AT_TEST_123',
                'expires_in'   => 3600,
                'token_type'   => 'bearer',
            )),
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
        $GLOBALS['_cb_test_options']    = array();
        $GLOBALS['_cb_test_transients'] = array();
        $GLOBALS['_cb_test_http_calls'] = array();
        unset($GLOBALS['wpdb']);
    }

    // ============================================================
    // 1. Невалидные credentials → null + last_error, без HTTP-вызова
    // ============================================================

    public function test_get_token_returns_null_when_client_id_empty(): void
    {
        $helper = new Cashback_OAuth2_Client_Credentials_Helper();
        $token  = $helper->get_token(self::TOKEN_URL, '', 'secret', 'scope_a');

        $this->assertNull($token);
        $this->assertNotEmpty($helper->get_last_error());
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls'], 'HTTP-вызов не должен происходить при пустом client_id');
    }

    public function test_get_token_returns_null_when_client_secret_empty(): void
    {
        $helper = new Cashback_OAuth2_Client_Credentials_Helper();
        $token  = $helper->get_token(self::TOKEN_URL, 'cid', '', 'scope_a');

        $this->assertNull($token);
        $this->assertNotEmpty($helper->get_last_error());
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls']);
    }

    // ============================================================
    // 2. Happy-path → возвращает токен из ответа
    // ============================================================

    public function test_get_token_returns_access_token_from_response(): void
    {
        $helper = new Cashback_OAuth2_Client_Credentials_Helper();
        $token  = $helper->get_token(self::TOKEN_URL, 'cid', 'secret', 'scope_a');

        $this->assertSame('AT_TEST_123', $token);
        $this->assertSame('', $helper->get_last_error());
        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
        $this->assertSame('POST', $GLOBALS['_cb_test_http_calls'][0]['method']);
        $this->assertSame(self::TOKEN_URL, $GLOBALS['_cb_test_http_calls'][0]['url']);
    }

    // ============================================================
    // 3. Basic Auth header формируется корректно (RFC 7617)
    // ============================================================

    public function test_get_token_sends_basic_auth_header_with_credentials(): void
    {
        $helper = new Cashback_OAuth2_Client_Credentials_Helper();
        $helper->get_token(self::TOKEN_URL, 'my_client', 'my_secret', 'scope_a');

        $headers = $GLOBALS['_cb_test_http_calls'][0]['args']['headers'];
        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertSame(
            'Basic ' . base64_encode('my_client:my_secret'),
            $headers['Authorization']
        );
        $this->assertSame('application/x-www-form-urlencoded', $headers['Content-Type']);
    }

    // ============================================================
    // 4. Body содержит grant_type + client_id + scope
    // ============================================================

    public function test_get_token_posts_grant_type_and_scope_in_body(): void
    {
        $helper = new Cashback_OAuth2_Client_Credentials_Helper();
        $helper->get_token(self::TOKEN_URL, 'my_client', 'my_secret', 'statistics advcampaigns coupons_for_website');

        $body = $GLOBALS['_cb_test_http_calls'][0]['args']['body'];
        $this->assertSame('client_credentials', $body['grant_type']);
        $this->assertSame('my_client', $body['client_id']);
        $this->assertSame('statistics advcampaigns coupons_for_website', $body['scope']);
    }

    // ============================================================
    // 5. HTTP non-200 → null + error
    // ============================================================

    public function test_get_token_returns_null_on_http_error_status(): void
    {
        $GLOBALS['_cb_test_http_response'] = array(
            'body'     => wp_json_encode(array( 'error' => 'invalid_client' )),
            'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
            'headers'  => array(),
        );

        $helper = new Cashback_OAuth2_Client_Credentials_Helper();
        $token  = $helper->get_token(self::TOKEN_URL, 'cid', 'bad_secret', 'scope_a');

        $this->assertNull($token);
        $this->assertStringContainsString('401', $helper->get_last_error());
    }

    public function test_get_token_returns_null_on_wp_error_response(): void
    {
        $GLOBALS['_cb_test_http_response'] = new WP_Error('http_error', 'Connection refused');

        $helper = new Cashback_OAuth2_Client_Credentials_Helper();
        $token  = $helper->get_token(self::TOKEN_URL, 'cid', 'secret', 'scope_a');

        $this->assertNull($token);
        $this->assertNotEmpty($helper->get_last_error());
    }

    // ============================================================
    // 6. Кеширование — transient HIT короткозамыкает HTTP
    // ============================================================

    public function test_get_token_returns_cached_value_from_transient_without_http_call(): void
    {
        // F-P3-001: токен в transient хранится зашифрованным; pre-set ciphertext.
        $cache_key = 'cashback_oauth2_token_admitad_' . md5('cid');
        $encrypted = Cashback_Encryption::encrypt('CACHED_TOKEN_XYZ');
        set_transient($cache_key, $encrypted, 3600);

        $helper = new Cashback_OAuth2_Client_Credentials_Helper('cashback_oauth2_token_admitad');
        $token  = $helper->get_token(self::TOKEN_URL, 'cid', 'secret', 'scope_a');

        $this->assertSame('CACHED_TOKEN_XYZ', $token);
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls'], 'HTTP не должен вызываться при transient HIT');
    }

    // ============================================================
    // 7. После успешного fetch'а токен кешируется (зашифрованный)
    // ============================================================

    public function test_get_token_caches_token_in_transient_after_successful_fetch(): void
    {
        $helper = new Cashback_OAuth2_Client_Credentials_Helper('cashback_oauth2_token_admitad');
        $token  = $helper->get_token(self::TOKEN_URL, 'cid', 'secret', 'scope_a');

        $this->assertSame('AT_TEST_123', $token);

        // F-P3-001: transient содержит ciphertext, не plain. Расшифровка
        // через Cashback_Encryption::decrypt должна вернуть исходный токен.
        $cache_key = 'cashback_oauth2_token_admitad_' . md5('cid');
        $cached    = get_transient($cache_key);
        $this->assertIsString($cached);
        $this->assertNotSame('AT_TEST_123', $cached, 'transient НЕ должен содержать plain (F-P3-001)');
        $this->assertSame('AT_TEST_123', Cashback_Encryption::decrypt($cached));
    }

    // ============================================================
    // 8. Cache key изолирует разных client_id
    // ============================================================

    public function test_get_token_separates_cache_per_client_id(): void
    {
        // cid=A → token TOKEN_A; cid=B → token TOKEN_B (разные ответы по очереди)
        $GLOBALS['_cb_test_http_response'] = array(
            'body'     => wp_json_encode(array( 'access_token' => 'TOKEN_A', 'expires_in' => 3600 )),
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'headers'  => array(),
        );

        $helper = new Cashback_OAuth2_Client_Credentials_Helper('cashback_oauth2_token_admitad');
        $token_a = $helper->get_token(self::TOKEN_URL, 'A', 'secret', 'scope');
        $this->assertSame('TOKEN_A', $token_a);

        // Меняем response для второго client'а — cache key должен быть другим, чтобы дойти до HTTP
        $GLOBALS['_cb_test_http_response'] = array(
            'body'     => wp_json_encode(array( 'access_token' => 'TOKEN_B', 'expires_in' => 3600 )),
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'headers'  => array(),
        );

        $token_b = $helper->get_token(self::TOKEN_URL, 'B', 'secret', 'scope');
        $this->assertSame('TOKEN_B', $token_b);
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls'], 'Каждый client_id должен делать свой HTTP-вызов');
    }

    // ============================================================
    // 9. invalidate_token удаляет transient и runtime cache
    // ============================================================

    public function test_invalidate_token_clears_transient_for_client(): void
    {
        $cache_key = 'cashback_oauth2_token_admitad_' . md5('cid');
        set_transient($cache_key, 'OLD_TOKEN', 3600);

        $helper = new Cashback_OAuth2_Client_Credentials_Helper('cashback_oauth2_token_admitad');
        $helper->invalidate_token('cid');

        $this->assertFalse(get_transient($cache_key), 'transient должен быть удалён');
    }

    public function test_invalidate_token_forces_next_get_to_hit_http(): void
    {
        $helper = new Cashback_OAuth2_Client_Credentials_Helper('cashback_oauth2_token_admitad');

        // Первый вызов — fetch + cache.
        $token1 = $helper->get_token(self::TOKEN_URL, 'cid', 'secret', 'scope');
        $this->assertSame('AT_TEST_123', $token1);
        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);

        // Второй вызов — HIT transient, без HTTP.
        $token2 = $helper->get_token(self::TOKEN_URL, 'cid', 'secret', 'scope');
        $this->assertSame('AT_TEST_123', $token2);
        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);

        // После invalidate — должен снова идти в HTTP.
        $helper->invalidate_token('cid');
        $token3 = $helper->get_token(self::TOKEN_URL, 'cid', 'secret', 'scope');
        $this->assertSame('AT_TEST_123', $token3);
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls'], 'после invalidate HTTP должен вызваться снова');
    }

    // ============================================================
    // 10. SSRF guard — denied URL возвращает null без HTTP
    // ============================================================

    public function test_get_token_blocked_by_ssrf_guard_for_private_ip(): void
    {
        $helper = new Cashback_OAuth2_Client_Credentials_Helper();
        $token  = $helper->get_token('https://169.254.169.254/token/', 'cid', 'secret', 'scope');

        $this->assertNull($token);
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls'], 'HTTP не должен вызываться для denied URL');
    }

    // ============================================================
    // 11. TTL = max(60, expires_in - 300) — соответствует Admitad-поведению
    // ============================================================

    public function test_get_token_caches_with_ttl_minus_300_seconds(): void
    {
        $GLOBALS['_cb_test_http_response'] = array(
            'body'     => wp_json_encode(array( 'access_token' => 'TKN', 'expires_in' => 7200 )),
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'headers'  => array(),
        );

        $helper = new Cashback_OAuth2_Client_Credentials_Helper('cashback_oauth2_token_admitad');
        $helper->get_token(self::TOKEN_URL, 'cid', 'secret', 'scope');

        $cache_key = 'cashback_oauth2_token_admitad_' . md5('cid');
        $entry = $GLOBALS['_cb_test_transients'][$cache_key] ?? null;
        $this->assertNotNull($entry);
        // TTL = 7200 - 300 = 6900 sec, плюс/минус секунда из-за времени теста.
        $ttl = $entry['expires_at'] - time();
        $this->assertGreaterThanOrEqual(6890, $ttl);
        $this->assertLessThanOrEqual(6900, $ttl);
    }

    public function test_get_token_caches_with_min_ttl_60_when_short_expires(): void
    {
        $GLOBALS['_cb_test_http_response'] = array(
            'body'     => wp_json_encode(array( 'access_token' => 'TKN', 'expires_in' => 30 )),
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'headers'  => array(),
        );

        $helper = new Cashback_OAuth2_Client_Credentials_Helper('cashback_oauth2_token_admitad');
        $helper->get_token(self::TOKEN_URL, 'cid', 'secret', 'scope');

        $cache_key = 'cashback_oauth2_token_admitad_' . md5('cid');
        $entry = $GLOBALS['_cb_test_transients'][$cache_key];
        $ttl = $entry['expires_at'] - time();
        $this->assertGreaterThanOrEqual(50, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);
    }

    // ============================================================
    // 12. Body без access_token → null
    // ============================================================

    public function test_get_token_returns_null_when_response_missing_access_token(): void
    {
        $GLOBALS['_cb_test_http_response'] = array(
            'body'     => wp_json_encode(array( 'token_type' => 'bearer' )),
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'headers'  => array(),
        );

        $helper = new Cashback_OAuth2_Client_Credentials_Helper();
        $token  = $helper->get_token(self::TOKEN_URL, 'cid', 'secret', 'scope');

        $this->assertNull($token);
        $this->assertNotEmpty($helper->get_last_error());
    }

    // ============================================================
    // 13. Логи не должны содержать access_token / client_secret в plaintext
    // ============================================================

    public function test_get_token_does_not_leak_secret_into_last_error(): void
    {
        $GLOBALS['_cb_test_http_response'] = array(
            'body'     => wp_json_encode(array(
                'access_token' => 'SHOULD_NOT_LEAK',
                'error'        => 'invalid_grant',
            )),
            'response' => array( 'code' => 400, 'message' => 'Bad Request' ),
            'headers'  => array(),
        );

        $helper = new Cashback_OAuth2_Client_Credentials_Helper();
        $helper->get_token(self::TOKEN_URL, 'cid', 'super_secret_value', 'scope');

        $error = $helper->get_last_error();
        $this->assertStringNotContainsString('super_secret_value', $error);
        $this->assertStringNotContainsString('SHOULD_NOT_LEAK', $error);
    }
}
