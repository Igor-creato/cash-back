<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты retry-поведения Cashback_Admitad_Adapter::fetch_actions при 5xx-ответах
 * Admitad API.
 *
 * Контекст: их `/statistics/actions/` периодически отвечает 500 без JSON-тела
 * либо вообще не отвечает за 60s timeout. До этой задачи адаптер ронял всю
 * sync-сессию на первой же 500-ке. Теперь — до 2 retry с экспоненциальным
 * backoff (filter-controlled, чтобы не спать в тестах).
 *
 * Затрагивается только {@see Cashback_Admitad_Adapter::fetch_actions()};
 * существующая ветка retry на 401/403 не должна регрессировать.
 *
 * @group adapters
 * @group admitad
 * @group retry
 */
#[Group('adapters')]
#[Group('admitad')]
#[Group('retry')]
final class AdmitadAdapterRetryTest extends TestCase
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
        if ($class !== null && class_exists($class)) {
            return;
        }
        $path = self::$plugin_root . $relative;
        if (!file_exists($path)) {
            self::markTestSkipped("File missing: {$relative}");
        }
        require_once $path;
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options']             = array();
        $GLOBALS['_cb_test_filters']             = array();
        $GLOBALS['_cb_test_transients']          = array();
        $GLOBALS['_cb_test_cache']               = array();
        $GLOBALS['_cb_test_http_calls']          = array();
        $GLOBALS['_cb_test_http_response']       = array(
            'body'     => '{"results":[]}',
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'headers'  => array(),
        );
        $GLOBALS['_cb_test_http_response_callback'] = null;

        // Фильтр обнуляет backoff — тесты не должны спать.
        add_filter(
            'cashback_admitad_5xx_retry_delay_seconds',
            static fn(): int => 0,
            10,
            3
        );

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

    /**
     * Создаёт адаптер с подменённой авторизацией: возвращает фиксированный токен,
     * чтобы изолировать тест retry-логики от OAuth2-цепочки.
     */
    private function make_adapter_with_token(string $token = 'test-token-xyz'): Cashback_Admitad_Adapter
    {
        return new class($token) extends Cashback_Admitad_Adapter {
            public function __construct(private string $stub_token) {}

            public function get_token( array $credentials, array $network_config ): ?string
            {
                return $this->stub_token;
            }

            public function build_auth_headers( array $credentials, array $network_config ): ?array
            {
                return array( 'Authorization' => 'Bearer ' . $this->stub_token );
            }

            public function invalidate_token( array $credentials ): void
            {
                // no-op в тесте.
            }
        };
    }

    /**
     * Подаёт серию HTTP-ответов из очереди в порядке поступления.
     * После исчерпания очереди возвращает последний элемент (для устойчивости).
     *
     * @param array<int,array> $responses Список response-массивов в формате wp_remote_get.
     */
    private function queue_responses(array $responses): void
    {
        $queue = $responses;
        $GLOBALS['_cb_test_http_response_callback'] = static function (string $url, array $args) use (&$queue) {
            if (count($queue) > 1) {
                return array_shift($queue);
            }
            return $queue[0] ?? array(
                'body'     => '',
                'response' => array( 'code' => 500, 'message' => 'Internal Server Error' ),
                'headers'  => array(),
            );
        };
    }

    private function default_params(): array
    {
        return array(
            'date_start' => '01.04.2026',
            'date_end'   => '07.05.2026',
            'website'    => 2082764,
            'limit'      => 1,
            'offset'     => 0,
        );
    }

    private function default_credentials(): array
    {
        return array( 'client_id' => 'cid', 'client_secret' => 'csec', 'scope' => 'statistics' );
    }

    private function default_network_config(): array
    {
        return array(
            'api_base_url'           => 'https://api.admitad.com',
            'api_actions_endpoint'   => 'https://api.admitad.com/statistics/actions/',
        );
    }

    private function ok_response(string $body = '{"results":[],"_meta":{"count":0}}'): array
    {
        return array(
            'body'     => $body,
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'headers'  => array(),
        );
    }

    private function http_response(int $code, string $body = ''): array
    {
        return array(
            'body'     => $body,
            'response' => array( 'code' => $code, 'message' => 'HTTP ' . $code ),
            'headers'  => array(),
        );
    }

    /**
     * Если первый вызов отвечает 500, а второй — 200, fetch_actions должен
     * сделать ровно 2 HTTP-запроса и вернуть success=true с пустым результатом.
     */
    public function test_retries_once_on_5xx_then_succeeds(): void
    {
        $this->queue_responses(array(
            $this->http_response(500, '<html>nginx 500</html>'),
            $this->ok_response(),
        ));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            $this->default_params(),
            $this->default_network_config()
        );

        $this->assertTrue($result['success'], 'fetch_actions должен вернуть success=true после успешного retry');
        $this->assertSame(0, $result['total']);
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls'], 'должно быть ровно 2 HTTP-запроса (1 fail + 1 retry)');
    }

    /**
     * Persistent 500 — после исчерпания retry адаптер возвращает success=false.
     * Лимит — 2 retry поверх первого вызова, итого 3 HTTP-запроса.
     */
    public function test_persistent_5xx_fails_after_two_retries(): void
    {
        $this->queue_responses(array(
            $this->http_response(500, '<html>500</html>'),
            $this->http_response(500, '<html>500</html>'),
            $this->http_response(500, '<html>500</html>'),
            // safety: дальше не должны спросить.
            $this->ok_response(),
        ));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            $this->default_params(),
            $this->default_network_config()
        );

        $this->assertFalse($result['success'], 'persistent 500 должен дать success=false');
        $this->assertStringContainsString('500', (string) $result['error']);
        $this->assertCount(3, $GLOBALS['_cb_test_http_calls'], 'должно быть ровно 3 HTTP-запроса (1 + 2 retry)');
    }

    /**
     * 502 / 503 / 504 — все считаются retryable 5xx.
     * Регрессия защиты от bad gateway / service unavailable / gateway timeout.
     *
     * @dataProvider provideRetryable5xxCodes
     */
    public function test_retries_on_other_5xx_codes(int $code): void
    {
        $this->queue_responses(array(
            $this->http_response($code),
            $this->ok_response(),
        ));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            $this->default_params(),
            $this->default_network_config()
        );

        $this->assertTrue($result['success'], "HTTP {$code} должен retry'иться и в итоге success=true");
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls']);
    }

    public static function provideRetryable5xxCodes(): array
    {
        return array(
            'bad gateway'        => array( 502 ),
            'service unavail'    => array( 503 ),
            'gateway timeout'    => array( 504 ),
        );
    }

    /**
     * 200 OK с первой попытки — никакого retry, ровно 1 HTTP-запрос.
     * Защита: retry-логика не должна срабатывать на success-ответе.
     */
    public function test_no_retry_on_first_200(): void
    {
        $this->queue_responses(array(
            $this->ok_response('{"results":[{"id":1}],"_meta":{"count":1}}'),
        ));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            $this->default_params(),
            $this->default_network_config()
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
    }

    /**
     * 400 Bad Request — НЕ 5xx, retry не должен срабатывать. Адаптер сразу
     * возвращает fetch_error без повторов. Это защита от лишней нагрузки на
     * Admitad API при ошибках клиента (ошибочные параметры запроса).
     */
    public function test_no_retry_on_4xx_client_error(): void
    {
        $this->queue_responses(array(
            $this->http_response(400, '{"error":"bad_request"}'),
            $this->ok_response(),
        ));

        $adapter = $this->make_adapter_with_token();
        $result  = $adapter->fetch_actions(
            $this->default_credentials(),
            $this->default_params(),
            $this->default_network_config()
        );

        $this->assertFalse($result['success']);
        $this->assertCount(1, $GLOBALS['_cb_test_http_calls'], '4xx не должен триггерить retry');
    }
}
