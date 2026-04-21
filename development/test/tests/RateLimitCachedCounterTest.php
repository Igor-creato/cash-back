<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

// Loaded at top-level (а не в setUp), чтобы Fake_Inner_Counter мог реализовать
// интерфейс в момент парсинга файла.
require_once dirname(__DIR__, 3) . '/includes/rate-limit/interface-cashback-rate-limit-counter.php';
require_once dirname(__DIR__, 3) . '/includes/rate-limit/class-cashback-rate-limit-cached-counter.php';

/**
 * Unit-тесты для Cashback_Rate_Limit_Cached_Counter (Группа 7 ADR, шаг 4).
 *
 * Декоратор кеширует только DENIED-ответы на короткое TTL — ALLOWED всегда
 * проходят через inner-counter (иначе лимит обойдётся).
 */
#[Group('rate-limit')]
final class RateLimitCachedCounterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cb_test_cache'] = array();
    }

    public function test_constructor_rejects_non_positive_ttl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new \Cashback_Rate_Limit_Cached_Counter(new Fake_Inner_Counter(), 0);
    }

    public function test_allowed_requests_always_hit_inner_counter(): void
    {
        $inner = new Fake_Inner_Counter(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 2, 'allowed' => true, 'reset_at' => time() + 60 ),
        ));

        $counter = new \Cashback_Rate_Limit_Cached_Counter($inner);

        $counter->increment('scope_a', 60, 5);
        $counter->increment('scope_a', 60, 5);

        $this->assertSame(2, $inner->call_count, 'ALLOWED-ответы не должны кешироваться — каждый вызов идёт в inner.');
    }

    public function test_denied_response_is_cached_and_short_circuits_next_call(): void
    {
        $reset_at = time() + 60;
        $inner    = new Fake_Inner_Counter(array(
            array( 'hits' => 6, 'allowed' => false, 'reset_at' => $reset_at ),
            array( 'hits' => 7, 'allowed' => false, 'reset_at' => $reset_at ),
        ));

        $counter = new \Cashback_Rate_Limit_Cached_Counter($inner, 10);

        $first  = $counter->increment('scope_b', 60, 5);
        $second = $counter->increment('scope_b', 60, 5);

        $this->assertFalse($first['allowed']);
        $this->assertFalse($second['allowed']);
        $this->assertSame(1, $inner->call_count, 'Повторный denied должен подхватываться из кеша, не звать inner.');
        $this->assertSame($first['hits'], $second['hits'], 'Кеш вернул тот же hits, что был в первом ответе.');
    }

    public function test_denied_cache_ttl_is_capped_to_reset_at_minus_now(): void
    {
        $inner = new Fake_Inner_Counter(array(
            array( 'hits' => 6, 'allowed' => false, 'reset_at' => time() + 2 ),
        ));

        $counter = new \Cashback_Rate_Limit_Cached_Counter($inner, 60); // deny_cache_ttl=60
        $counter->increment('scope_c', 60, 5);

        // Кеш должен быть установлен; если TTL был бы 60, cache остался бы доступен
        // и после истечения reset_at. Проверяем что для reset_at=+2 TTL выбран короче.
        $cached = array_values($GLOBALS['_cb_test_cache']['cashback_rate_limit'] ?? array());
        $this->assertNotEmpty($cached, 'Cache entry должен быть установлен для denied.');
        $entry = $cached[0];
        $this->assertLessThanOrEqual(time() + 2, $entry['expires_at']);
    }

    public function test_expired_cache_entry_triggers_fresh_inner_call(): void
    {
        $inner = new Fake_Inner_Counter(array(
            array( 'hits' => 6, 'allowed' => false, 'reset_at' => time() - 10 ), // просрочено
            array( 'hits' => 1, 'allowed' => true,  'reset_at' => time() + 60 ),
        ));

        $counter = new \Cashback_Rate_Limit_Cached_Counter($inner);

        $first  = $counter->increment('scope_d', 60, 5);
        $second = $counter->increment('scope_d', 60, 5);

        $this->assertFalse($first['allowed']);
        $this->assertTrue($second['allowed'], 'Просроченный denied не должен подавлять следующий allowed.');
        $this->assertSame(2, $inner->call_count);
    }

    public function test_different_scope_keys_do_not_share_cache(): void
    {
        $inner = new Fake_Inner_Counter(array(
            array( 'hits' => 6, 'allowed' => false, 'reset_at' => time() + 60 ),
            array( 'hits' => 1, 'allowed' => true,  'reset_at' => time() + 60 ),
        ));

        $counter = new \Cashback_Rate_Limit_Cached_Counter($inner);

        $res_a = $counter->increment('scope_x', 60, 5);
        $res_b = $counter->increment('scope_y', 60, 5);

        $this->assertFalse($res_a['allowed']);
        $this->assertTrue($res_b['allowed']);
        $this->assertSame(2, $inner->call_count, 'Разные scope_keys → разные cache entries.');
    }
}

/**
 * Внутренний fake-counter: возвращает предзаданные ответы по порядку.
 */
final class Fake_Inner_Counter implements Cashback_Rate_Limit_Counter_Interface
{
    public int $call_count = 0;
    /** @var array<int, array{hits:int, allowed:bool, reset_at:int}> */
    private array $queue;

    /** @param array<int, array{hits:int, allowed:bool, reset_at:int}> $queue */
    public function __construct(array $queue = array())
    {
        $this->queue = $queue;
    }

    public function increment(string $scope_key, int $window_seconds, int $limit): array
    {
        $this->call_count++;
        return array_shift($this->queue) ?? array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + $window_seconds );
    }
}
