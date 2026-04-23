<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Группа 12d ADR — CAPTCHA POST + fail-closed на upstream error.
 *
 * Closes:
 *  - F-10-003: server_key уходил в URL (GET query string) — утечка в логи сервера,
 *              proxy, браузер-историю. Должен быть в POST body.
 *  - F-10-004: любая ошибка upstream (timeout/5xx/invalid JSON) давала true
 *              (fail-open). Меняем default на fail-closed через фильтр
 *              `cashback_captcha_upstream_policy` (default 'deny').
 *
 * Filter API:
 *   add_filter('cashback_captcha_upstream_policy', function () {
 *       return 'allow'; // восстановить legacy fail-open (availability trade-off)
 *   });
 *
 * Тесты behavioural: вызываем Cashback_Captcha::verify_token() и проверяем
 * _cb_test_http_calls + return value. Mock wp_remote_post в bootstrap.php.
 */
#[Group('security')]
#[Group('group12')]
#[Group('captcha')]
class CaptchaUpstreamPolicyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        $guard_file = $plugin_root . '/includes/class-cashback-outbound-http-guard.php';
        if (!class_exists('Cashback_Outbound_HTTP_Guard') && file_exists($guard_file)) {
            require_once $guard_file;
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options']       = array();
        $GLOBALS['_cb_test_filters']       = array();
        $GLOBALS['_cb_test_transients']    = array();
        $GLOBALS['_cb_audit_log_calls']    = array();
        $GLOBALS['_cb_test_http_calls']    = array();
        $GLOBALS['_cb_test_http_response'] = array(
            'body'     => '{"status":"ok"}',
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'headers'  => array(),
        );

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public function insert( string $table, array $data, $format = null ): int
            {
                return 1;
            }
        };

        update_option('cashback_captcha_server_key', 'test-server-key-plain');

        if (class_exists('Cashback_Outbound_HTTP_Guard')) {
            Cashback_Outbound_HTTP_Guard::invalidate_cache();
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['_cb_test_options']    = array();
        $GLOBALS['_cb_test_filters']    = array();
        $GLOBALS['_cb_test_http_calls'] = array();
        $GLOBALS['_cb_test_transients'] = array();
        unset($GLOBALS['wpdb']);

        if (class_exists('Cashback_Outbound_HTTP_Guard')) {
            Cashback_Outbound_HTTP_Guard::invalidate_cache();
        }
    }

    // =====================================================================
    // F-10-003: POST вместо GET, secret в body
    // =====================================================================

    public function test_verify_token_uses_post_method(): void
    {
        Cashback_Captcha::verify_token('token-abc', '203.0.113.10');

        self::assertCount(1, $GLOBALS['_cb_test_http_calls'], 'Должен быть 1 upstream-вызов');
        self::assertSame(
            'POST',
            $GLOBALS['_cb_test_http_calls'][0]['method'],
            'F-10-003: verify_token должен использовать wp_remote_post, не wp_remote_get.'
        );
    }

    public function test_verify_token_does_not_leak_secret_in_url(): void
    {
        Cashback_Captcha::verify_token('token-abc', '203.0.113.10');

        $url = (string) $GLOBALS['_cb_test_http_calls'][0]['url'];
        self::assertStringNotContainsString(
            'test-server-key-plain',
            $url,
            'F-10-003: server_key НЕ должен попадать в URL — leak в access-logs/proxy/history.'
        );
        self::assertStringNotContainsString(
            'secret=',
            $url,
            'F-10-003: secret-параметр НЕ должен быть в query string.'
        );
    }

    public function test_verify_token_puts_secret_in_request_body(): void
    {
        Cashback_Captcha::verify_token('token-abc', '203.0.113.10');

        $args = $GLOBALS['_cb_test_http_calls'][0]['args'];
        self::assertArrayHasKey(
            'body',
            $args,
            'F-10-003: POST-запрос должен передавать secret/token/ip в body.'
        );

        $body = $args['body'];
        if (is_array($body)) {
            self::assertArrayHasKey('secret', $body);
            self::assertSame('test-server-key-plain', $body['secret']);
            self::assertArrayHasKey('token', $body);
            self::assertSame('token-abc', $body['token']);
            self::assertArrayHasKey('ip', $body);
            self::assertSame('203.0.113.10', $body['ip']);
        } else {
            // Если body — строка (urlencoded), проверяем содержимое.
            $body_str = (string) $body;
            self::assertStringContainsString('secret=test-server-key-plain', $body_str);
            self::assertStringContainsString('token=token-abc', $body_str);
            self::assertStringContainsString('ip=203.0.113.10', $body_str);
        }
    }

    // =====================================================================
    // F-10-004: fail-closed default на upstream error
    // =====================================================================

    public function test_wp_error_defaults_to_fail_closed(): void
    {
        $GLOBALS['_cb_test_http_response'] = new WP_Error('http_request_failed', 'Simulated timeout');

        $result = Cashback_Captcha::verify_token('token-abc', '203.0.113.10');

        self::assertFalse(
            $result,
            'F-10-004: при wp_error из upstream verify_token должен возвращать false (fail-closed default).'
        );
    }

    public function test_http_5xx_defaults_to_fail_closed(): void
    {
        $GLOBALS['_cb_test_http_response'] = array(
            'body'     => '',
            'response' => array( 'code' => 503, 'message' => 'Service Unavailable' ),
            'headers'  => array(),
        );

        $result = Cashback_Captcha::verify_token('token-abc', '203.0.113.10');

        self::assertFalse(
            $result,
            'F-10-004: при HTTP ≠ 200 verify_token должен возвращать false (fail-closed default).'
        );
    }

    public function test_invalid_json_defaults_to_fail_closed(): void
    {
        $GLOBALS['_cb_test_http_response'] = array(
            'body'     => 'not-a-json-response',
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'headers'  => array(),
        );

        $result = Cashback_Captcha::verify_token('token-abc', '203.0.113.10');

        self::assertFalse(
            $result,
            'F-10-004: при невалидном JSON verify_token должен возвращать false (fail-closed default).'
        );
    }

    // =====================================================================
    // F-10-004: filter 'allow' восстанавливает legacy fail-open
    // =====================================================================

    public function test_filter_allow_restores_legacy_fail_open(): void
    {
        add_filter('cashback_captcha_upstream_policy', static function () {
            return 'allow';
        });

        $GLOBALS['_cb_test_http_response'] = new WP_Error('http_request_failed', 'Simulated timeout');
        $result_on_wperror                 = Cashback_Captcha::verify_token('token-abc', '203.0.113.10');

        $GLOBALS['_cb_test_http_response'] = array(
            'body'     => '',
            'response' => array( 'code' => 503, 'message' => 'Service Unavailable' ),
            'headers'  => array(),
        );
        $GLOBALS['_cb_test_http_calls']    = array();
        $result_on_5xx                     = Cashback_Captcha::verify_token('token-abc', '203.0.113.10');

        self::assertTrue(
            $result_on_wperror,
            'F-10-004: filter=allow должен восстанавливать legacy fail-open на wp_error.'
        );
        self::assertTrue(
            $result_on_5xx,
            'F-10-004: filter=allow должен восстанавливать legacy fail-open на HTTP 5xx.'
        );
    }

    // =====================================================================
    // Regression: happy path и unconfigured — без изменений
    // =====================================================================

    public function test_happy_path_ok_returns_true(): void
    {
        $result = Cashback_Captcha::verify_token('token-abc', '203.0.113.10');
        self::assertTrue($result, 'Regression: валидный {status:"ok"} должен возвращать true.');
    }

    public function test_unconfigured_server_key_still_graceful(): void
    {
        update_option('cashback_captcha_server_key', '');

        $result = Cashback_Captcha::verify_token('token-abc', '203.0.113.10');

        self::assertTrue(
            $result,
            'Regression: unconfigured server_key — graceful degradation (true). Это config state, не upstream failure.'
        );
        self::assertCount(
            0,
            $GLOBALS['_cb_test_http_calls'],
            'Regression: при пустом server_key HTTP-вызов не должен производиться.'
        );
    }

    public function test_empty_token_returns_false(): void
    {
        $result = Cashback_Captcha::verify_token('', '203.0.113.10');
        self::assertFalse($result, 'Regression: пустой token → false (до любых запросов).');
        self::assertCount(0, $GLOBALS['_cb_test_http_calls']);
    }
}
