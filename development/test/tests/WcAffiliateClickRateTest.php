<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/includes/rate-limit/interface-cashback-rate-limit-counter.php';
require_once dirname(__DIR__, 3) . '/includes/class-cashback-rate-limiter.php';

/**
 * Unit-тесты для миграции wc-affiliate click-rate на атомарный backend (Группа 7 ADR, шаг 6).
 *
 * Раньше get_click_rate_status() делал get_transient/set_transient подряд (race).
 * Теперь — два независимых increment() через Cashback_Rate_Limiter::counter_backend().
 * Тесты проверяют что маппинг hits → normal/spam/blocked сохраняется.
 *
 * После group 12 refactor (F-2-001) метод переехал в Cashback_Click_Session_Service —
 * wc-affiliate делегирует в общий сервис; этот тест закрывает оригинальную
 * behavioural регрессию на прежнем callsite, но теперь через новый target.
 */
#[Group('rate-limit')]
final class WcAffiliateClickRateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cb_test_transients'] = array();
        $GLOBALS['_cb_test_cache']      = array();

        if (!class_exists('Cashback_Click_Session_Service')) {
            require_once dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php';
        }
        // iter-3: hard-cap использует extract_subnet → нужен класс Affiliate_Service.
        if (!class_exists('Cashback_Affiliate_Service')) {
            require_once dirname(__DIR__, 3) . '/affiliate/class-affiliate-service.php';
        }
    }

    protected function tearDown(): void
    {
        \Cashback_Rate_Limiter::set_backend(null);
        parent::tearDown();
    }

    public function test_normal_when_hits_below_spam_threshold(): void
    {
        // pp_spam=3, gl_spam=10: при hits=1 все три bucket'а ниже spam.
        // Группа 13 iter-3: для гостя добавлен hard subnet-only bucket.
        $backend = new Click_Rate_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $status = $this->invoke_click_rate('1.2.3.4', 100);

        $this->assertSame('normal', $status);
        $this->assertSame(3, $backend->call_count, 'pp + gl + gh (hard subnet cap для гостей).');
    }

    public function test_spam_when_per_product_hits_exceed_spam_threshold(): void
    {
        // pp=4 (>3 SPAM, <10 BLOCK), gl=5 (<10 SPAM).
        $backend = new Click_Rate_Fake_Backend(array(
            array( 'hits' => 4, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 5, 'allowed' => true, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $status = $this->invoke_click_rate('1.2.3.4', 100);

        $this->assertSame('spam', $status);
    }

    public function test_spam_when_global_hits_exceed_spam_threshold(): void
    {
        // pp=2, gl=11 (>10 SPAM, <60 BLOCK).
        $backend = new Click_Rate_Fake_Backend(array(
            array( 'hits' => 2,  'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 11, 'allowed' => true, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $status = $this->invoke_click_rate('1.2.3.4', 100);

        $this->assertSame('spam', $status);
    }

    public function test_blocked_when_per_product_hits_reach_block(): void
    {
        // pp=10 (>=10 BLOCK), gl=5.
        $backend = new Click_Rate_Fake_Backend(array(
            array( 'hits' => 10, 'allowed' => false, 'reset_at' => time() + 60 ),
            array( 'hits' => 5,  'allowed' => true,  'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $status = $this->invoke_click_rate('1.2.3.4', 100);

        $this->assertSame('blocked', $status);
    }

    public function test_blocked_when_global_hits_reach_block(): void
    {
        // pp=3, gl=60 (>=60 BLOCK).
        $backend = new Click_Rate_Fake_Backend(array(
            array( 'hits' => 3,  'allowed' => true,  'reset_at' => time() + 60 ),
            array( 'hits' => 60, 'allowed' => false, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $status = $this->invoke_click_rate('1.2.3.4', 100);

        $this->assertSame('blocked', $status);
    }

    public function test_backend_error_falls_open_to_normal(): void
    {
        $backend = new Click_Rate_Fake_Backend(throwing: true);
        \Cashback_Rate_Limiter::set_backend($backend);

        $status = $this->invoke_click_rate('1.2.3.4', 100);

        $this->assertSame('normal', $status);
    }

    public function test_per_product_scope_differs_from_global_scope(): void
    {
        $backend = new Click_Rate_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $this->invoke_click_rate('1.2.3.4', 100);

        $this->assertCount(3, $backend->scope_keys); // pp_ + gl_ + gh_ (iter-3)
        $this->assertStringStartsWith('pp_', $backend->scope_keys[0]);
        $this->assertStringStartsWith('gl_', $backend->scope_keys[1]);
        $this->assertStringStartsWith('gh_', $backend->scope_keys[2]);
        $this->assertNotSame($backend->scope_keys[0], $backend->scope_keys[1]);
        $this->assertNotSame($backend->scope_keys[1], $backend->scope_keys[2]);
    }

    public function test_different_products_produce_different_pp_scope_same_gl_scope(): void
    {
        $backend = new Click_Rate_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 2, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 2, 'allowed' => true, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $this->invoke_click_rate('1.2.3.4', 100);
        $this->invoke_click_rate('1.2.3.4', 101);

        // scope_keys per call: [pp_, gl_, gh_]. Для двух вызовов: [0..2] и [3..5].
        // pp_ — разные (разные product_id).
        $this->assertNotSame($backend->scope_keys[0], $backend->scope_keys[3]);
        // gl_ — одинаковые (один subject subnet+UA).
        $this->assertSame($backend->scope_keys[1], $backend->scope_keys[4]);
        // gh_ — одинаковые (один subnet, hard cap не шардится по product_id).
        $this->assertSame($backend->scope_keys[2], $backend->scope_keys[5]);
    }

    public function test_hard_subnet_cap_blocks_ua_rotation_for_guests(): void
    {
        // Группа 13 iter-3: защита от UA-rotation bypass'а. Per-family bucket
        // позволяет attacker'у размножить квоту, чередуя Chrome/Firefox/Edge/...
        // Hard subnet-only bucket должен ловить такой спам.
        // Симуляция: hard bucket возвращает hits >= hard_block => 'blocked'.
        $backend = new Click_Rate_Fake_Backend_Hard(
            /* pp_hits */ 1,
            /* gl_hits */ 1,
            /* hard_hits */ 151 // > RATE_GLOBAL_HARD_BLOCK_GUEST (150)
        );
        \Cashback_Rate_Limiter::set_backend($backend);

        $status = $this->invoke_click_rate('203.0.113.10', 100, 0, 'Mozilla/5.0 Firefox/120');

        $this->assertSame('blocked', $status, 'UA-rotation attacker должен быть заблокирован hard subnet cap\'ом.');
        $this->assertGreaterThanOrEqual(3, $backend->call_count, 'Должен быть pp + gl + hard bucket.');
    }

    public function test_hard_subnet_cap_skipped_for_authenticated(): void
    {
        // Для залогиненных hard subnet cap не нужен — scope уже per-user, NAT-safe.
        $backend = new Click_Rate_Fake_Backend(array(
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
            array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + 60 ),
        ));
        \Cashback_Rate_Limiter::set_backend($backend);

        $status = $this->invoke_click_rate('203.0.113.10', 100, 42, 'Mozilla/5.0 Chrome/120');

        $this->assertSame('normal', $status);
        $this->assertSame(2, $backend->call_count, 'Для user_id>0 hard subnet bucket не должен добавляться.');
    }

    // ─────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────

    private function invoke_click_rate(string $ip, int $product_id, int $user_id = 0, string $ua = 'Mozilla/5.0 Chrome/120'): string
    {
        // После group 12 refactor метод стал private static в Cashback_Click_Session_Service.
        // Группа 13: signature (user_id, ip, ua, product_id) — NAT-safe composite subject.
        $method = new \ReflectionMethod(\Cashback_Click_Session_Service::class, 'get_click_rate_status');

        return (string) $method->invoke(null, $user_id, $ip, $ua, $product_id);
    }
}

/**
 * Fake backend, отслеживающий вызовы.
 */
final class Click_Rate_Fake_Backend implements Cashback_Rate_Limit_Counter_Interface
{
    public int $call_count = 0;
    /** @var list<string> */
    public array $scope_keys = array();
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

        if ($this->throwing) {
            throw new \RuntimeException('simulated backend failure');
        }

        return array_shift($this->queue)
            ?? array( 'hits' => 1, 'allowed' => true, 'reset_at' => time() + $window_seconds );
    }
}

/**
 * Backend для hard-cap сценариев: маршрутизирует ответы по префиксу scope_key
 * (pp_ / gl_ / gh_) вместо FIFO-очереди.
 */
final class Click_Rate_Fake_Backend_Hard implements Cashback_Rate_Limit_Counter_Interface
{
    public int $call_count = 0;
    /** @var list<string> */
    public array $scope_keys = array();

    public function __construct(
        private int $pp_hits,
        private int $gl_hits,
        private int $hard_hits
    ) {
    }

    public function increment(string $scope_key, int $window_seconds, int $limit): array
    {
        $this->call_count++;
        $this->scope_keys[] = $scope_key;

        $hits = match (true) {
            str_starts_with($scope_key, 'gh_') => $this->hard_hits,
            str_starts_with($scope_key, 'pp_') => $this->pp_hits,
            str_starts_with($scope_key, 'gl_') => $this->gl_hits,
            default                             => 1,
        };
        $allowed = $hits < $limit;

        return array( 'hits' => $hits, 'allowed' => $allowed, 'reset_at' => time() + $window_seconds );
    }
}
