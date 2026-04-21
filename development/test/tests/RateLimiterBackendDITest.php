<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

// Интерфейс нужен на top-level, чтобы in-file Fake_RL_Backend реализовал его
// в момент парсинга (так же как в RateLimitCachedCounterTest).
require_once dirname(__DIR__, 3) . '/includes/rate-limit/interface-cashback-rate-limit-counter.php';
require_once dirname(__DIR__, 3) . '/includes/class-cashback-rate-limiter.php';

/**
 * Unit-тесты для DI-backend в Cashback_Rate_Limiter::check() (Группа 7 ADR, шаг 5).
 *
 * Публичный контракт check() не изменился (allowed/remaining/retry_after), но
 * внутренняя реализация теперь делегирует атомарному counter'у вместо
 * неатомарного get/set_transient. Тесты проверяют совместимость ответов.
 */
#[Group('rate-limit')]
final class RateLimiterBackendDITest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cb_test_transients'] = array();
        $GLOBALS['_cb_test_options']    = array();
        $GLOBALS['_cb_test_cache']      = array();
    }

    protected function tearDown(): void
    {
        \Cashback_Rate_Limiter::set_backend(null);
        parent::tearDown();
    }

    public function test_unregistered_action_returns_allowed_and_bypasses_backend(): void
    {
        $backend = new Fake_RL_Backend();
        \Cashback_Rate_Limiter::set_backend($backend);

        $result = \Cashback_Rate_Limiter::check('some_unknown_action', 1, '1.2.3.4');

        $this->assertTrue($result['allowed']);
        $this->assertSame(999, $result['remaining']);
        $this->assertSame(0, $result['retry_after']);
        $this->assertSame(0, $backend->call_count, 'Unregistered action не должен звать backend.');
    }

    public function test_allowed_response_returns_remaining_limit_minus_hits(): void
    {
        $backend = new Fake_RL_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        // critical tier: limit=5, window=60
        $result = \Cashback_Rate_Limiter::check('process_cashback_withdrawal', 42, '198.51.100.7');

        $this->assertTrue($result['allowed']);
        $this->assertSame(4, $result['remaining']); // 5 - 1
        $this->assertSame(0, $result['retry_after']);
        $this->assertSame(1, $backend->call_count);
    }

    public function test_denied_response_returns_retry_after_window(): void
    {
        $backend = new Fake_RL_Backend(array(
            array( 'hits' => 6, 'allowed' => false, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $result = \Cashback_Rate_Limiter::check('process_cashback_withdrawal', 42, '198.51.100.7');

        $this->assertFalse($result['allowed']);
        $this->assertSame(0, $result['remaining']);
        $this->assertSame(60, $result['retry_after']);
    }

    public function test_backend_exception_fails_open(): void
    {
        $backend = new Fake_RL_Backend(throwing: true);
        \Cashback_Rate_Limiter::set_backend($backend);

        $result = \Cashback_Rate_Limiter::check('process_cashback_withdrawal', 42, '198.51.100.7');

        $this->assertTrue($result['allowed'], 'Backend-exception не должен ломать availability (fail-open).');
        $this->assertSame(0, $result['retry_after']);
    }

    public function test_scope_key_derivation_is_deterministic_for_same_triple(): void
    {
        $backend = new Fake_RL_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 2, 'allowed' => true, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        \Cashback_Rate_Limiter::check('process_cashback_withdrawal', 42, '198.51.100.7');
        \Cashback_Rate_Limiter::check('process_cashback_withdrawal', 42, '198.51.100.7');

        $this->assertCount(2, $backend->scope_keys);
        $this->assertSame($backend->scope_keys[0], $backend->scope_keys[1]);
    }

    public function test_scope_key_differs_for_different_users(): void
    {
        $backend = new Fake_RL_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        \Cashback_Rate_Limiter::check('process_cashback_withdrawal', 42, '198.51.100.7');
        \Cashback_Rate_Limiter::check('process_cashback_withdrawal', 43, '198.51.100.7');

        $this->assertNotSame($backend->scope_keys[0], $backend->scope_keys[1]);
    }

    public function test_scope_key_fits_varchar_64_limit(): void
    {
        $backend = new Fake_RL_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        \Cashback_Rate_Limiter::check('process_cashback_withdrawal', 42, '198.51.100.7');

        $this->assertLessThanOrEqual(64, strlen($backend->scope_keys[0]));
    }

    public function test_window_passed_to_backend_matches_tier(): void
    {
        $backend = new Fake_RL_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        \Cashback_Rate_Limiter::check('process_cashback_withdrawal', 42, '198.51.100.7');

        $this->assertSame(60, $backend->windows[0]);
        $this->assertSame(5, $backend->limits[0]); // critical tier default
    }
}

/**
 * Fake backend для DI-тестов rate-limiter'а.
 */
final class Fake_RL_Backend implements Cashback_Rate_Limit_Counter_Interface
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

    /**
     * @param list<array{hits:int, allowed:bool, reset_at:int}> $queue
     */
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
