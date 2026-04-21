<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/includes/rate-limit/interface-cashback-rate-limit-counter.php';
require_once dirname(__DIR__, 3) . '/includes/class-cashback-rate-limiter.php';

if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $func): bool
    {
        return true;
    }
}

/**
 * Unit-тесты для миграции contact-form rate-limit на атомарный backend
 * (Группа 7 ADR, шаг 8).
 *
 * Раньше handle_submit() вызывал get_transient/set_transient последовательно, причём
 * set_transient только ПОСЛЕ успешного submit — bot-rejected попытки квоту не
 * использовали. Теперь — один атомарный increment() в начале, независимо от
 * исхода валидации: более строгий и корректный rate-limit.
 */
#[Group('rate-limit')]
final class ContactFormRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cb_test_transients'] = array();
        $GLOBALS['_cb_test_cache']      = array();

        if (!class_exists('Cashback_Contact_Form')) {
            $file = dirname(__DIR__, 3) . '/includes/class-cashback-contact-form.php';
            if (!file_exists($file)) {
                $this->markTestSkipped('class-cashback-contact-form.php отсутствует');
            }
            require_once $file;
        }
    }

    protected function tearDown(): void
    {
        \Cashback_Rate_Limiter::set_backend(null);
        parent::tearDown();
    }

    public function test_denied_response_triggers_rate_limited_error(): void
    {
        $backend = new Contact_Form_Fake_Backend(array(
            array( 'hits' => 4, 'allowed' => false, 'reset_at' => time() + 3600 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $response = $this->invoke_handle_submit('1.2.3.4');

        $this->assertFalse($response['success']);
        $this->assertSame('rate_limited', $response['data']['code'] ?? null);
        $this->assertSame(429, $response['status_code'] ?? null);
    }

    public function test_allowed_response_proceeds_past_rate_limit_check(): void
    {
        $backend = new Contact_Form_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        // Ожидаем, что rate-limit пройдёт; дальше handle_submit провалится на
        // другой проверке (honeypot/nonce/etc), но уже НЕ с кодом rate_limited.
        $response = $this->invoke_handle_submit('1.2.3.4');

        if ($response['success'] === false) {
            $this->assertNotSame(
                'rate_limited',
                $response['data']['code'] ?? null,
                'При allowed=true код ошибки НЕ должен быть rate_limited.'
            );
        }
    }

    public function test_scope_key_is_derived_from_ip(): void
    {
        $backend = new Contact_Form_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $this->invoke_handle_submit('1.2.3.4');

        $this->assertCount(1, $backend->scope_keys);
        $this->assertSame('contact_' . substr(sha1('1.2.3.4'), 0, 40), $backend->scope_keys[0]);
        $this->assertSame(3600, $backend->windows[0]);
        $this->assertSame(3, $backend->limits[0]); // RATE_LIMIT const
    }

    public function test_different_ips_produce_different_scope_keys(): void
    {
        $backend = new Contact_Form_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 3600 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $this->invoke_handle_submit('1.2.3.4');
        $this->invoke_handle_submit('5.6.7.8');

        $this->assertNotSame($backend->scope_keys[0], $backend->scope_keys[1]);
    }

    public function test_backend_exception_fails_open(): void
    {
        $backend = new Contact_Form_Fake_Backend(throwing: true);
        \Cashback_Rate_Limiter::set_backend($backend);

        $response = $this->invoke_handle_submit('1.2.3.4');

        // Fail-open: rate-limit не блокирует; последующие проверки (honeypot, nonce)
        // могут завалить запрос, но НЕ с кодом rate_limited.
        if ($response['success'] === false) {
            $this->assertNotSame('rate_limited', $response['data']['code'] ?? null);
        }
    }

    /**
     * Симулирует AJAX-вызов handle_submit() с заданным IP. Перехватывает
     * wp_send_json_success/error через Cashback_Test_Halt_Signal (bootstrap).
     *
     * @return array{success: bool, data: mixed, status_code: int|null}
     */
    private function invoke_handle_submit(string $ip): array
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
        unset($GLOBALS['_cb_test_last_json_response']);

        $reflection = new \ReflectionClass(\Cashback_Contact_Form::class);
        $form       = $reflection->newInstanceWithoutConstructor();

        try {
            $form->handle_submit();
        } catch (\Cashback_Test_Halt_Signal $e) {
            // expected — wp_send_json_* throws Halt в тестах.
        }

        /** @var array{success:bool, data:mixed, status_code:int|null} $resp */
        $resp = $GLOBALS['_cb_test_last_json_response'] ?? array(
            'success'     => false,
            'data'        => null,
            'status_code' => null,
        );

        return $resp;
    }
}

final class Contact_Form_Fake_Backend implements Cashback_Rate_Limit_Counter_Interface
{
    public int $call_count = 0;
    /** @var list<string> */
    public array $scope_keys = array();
    /** @var list<int> */
    public array $windows = array();
    /** @var list<int> */
    public array $limits = array();
    /** @var list<array{hits:int, allowed:bool, reset_at:int}> */
    private array $queue;
    private bool $throwing;

    /** @param list<array{hits:int, allowed:bool, reset_at:int}> $queue */
    public function __construct(array $queue = array(), bool $throwing = false)
    {
        $this->queue    = $queue;
        $this->throwing = $throwing;
    }

    public function increment(string $scope_key, int $window_seconds, int $limit): array
    {
        $this->call_count++;
        $this->scope_keys[] = $scope_key;
        $this->windows[]    = $window_seconds;
        $this->limits[]     = $limit;

        if ($this->throwing) {
            throw new \RuntimeException('simulated backend failure');
        }

        return array_shift($this->queue)
            ?? array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + $window_seconds );
    }
}
