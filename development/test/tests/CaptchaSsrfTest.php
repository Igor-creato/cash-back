<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты интеграции Cashback_Outbound_HTTP_Guard в Cashback_Captcha::verify_token.
 *
 * Основной хост smartcaptcha.yandexcloud.net захардкожен в коде и входит в baseline
 * allowlist — SSRF-риска нет. Тест гарантирует, что guard вызывается ДО wp_remote_get
 * и что при deny (если allowlist подменён через фильтр) не происходит исходящего запроса.
 *
 * См. ADR Группа 3.
 */
#[Group('security')]
#[Group('ssrf')]
#[Group('outbound')]
#[Group('captcha')]
class CaptchaSsrfTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        $guard_file = $plugin_root . '/includes/class-cashback-outbound-http-guard.php';
        if (!file_exists($guard_file)) {
            self::markTestSkipped('Cashback_Outbound_HTTP_Guard file not present yet.');
        }
        if (!class_exists('Cashback_Outbound_HTTP_Guard')) {
            require_once $guard_file;
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options']       = array();
        $GLOBALS['_cb_test_filters']       = array();
        $GLOBALS['_cb_audit_log_calls']    = array();
        $GLOBALS['_cb_test_http_calls']    = array();
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

        // SmartCaptcha настроен — server-key заставляет verify_token идти в HTTP-путь.
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
        unset($GLOBALS['wpdb']);

        if (class_exists('Cashback_Outbound_HTTP_Guard')) {
            Cashback_Outbound_HTTP_Guard::invalidate_cache();
        }
    }

    // ================================================================
    // Happy-path: allowlist по умолчанию, smartcaptcha.yandexcloud.net в нём
    // ================================================================

    public function test_verify_token_happy_path_calls_wp_remote_get_once(): void
    {
        $result = Cashback_Captcha::verify_token('dummy-captcha-token', 0, '203.0.113.10');

        $this->assertTrue($result);
        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
        $this->assertStringContainsString('smartcaptcha.yandexcloud.net', $GLOBALS['_cb_test_http_calls'][0]['url']);
    }

    // ================================================================
    // Если allowlist подменён так, что smartcaptcha вне списка —
    // guard должен deny'ить ДО вызова wp_remote_get, а verify_token
    // graceful-degrade к true (существующая политика).
    // ================================================================

    public function test_verify_token_skips_http_when_guard_denies(): void
    {
        // Подменяем allowlist — убираем smartcaptcha.
        add_filter('cashback_outbound_allowlist', static function (array $config): array {
            $config['hosts'] = array_values(array_diff(
                $config['hosts'],
                array( 'smartcaptcha.yandexcloud.net' )
            ));
            return $config;
        });
        Cashback_Outbound_HTTP_Guard::invalidate_cache();

        $result = Cashback_Captcha::verify_token('dummy-captcha-token', 0, '203.0.113.10');

        // Graceful degradation — API "недоступен" → пропускаем проверку.
        $this->assertTrue($result);
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls'], 'wp_remote_get НЕ должен вызываться при deny guard');
    }
}
