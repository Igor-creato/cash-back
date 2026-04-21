<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты для Cashback_Rate_Limit_SQL_Counter (Группа 7 ADR, шаг 2).
 *
 * Покрывает контракт-валидацию конструктора и метода increment() — всё, что не
 * требует реальной БД. Integration-сценарии (sequential/concurrent/expiry)
 * живут в RateLimiterConcurrencyTest.
 */
#[Group('rate-limit')]
final class RateLimitSqlCounterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/includes/rate-limit/interface-cashback-rate-limit-counter.php';
        require_once $plugin_root . '/includes/rate-limit/class-cashback-rate-limit-sql-counter.php';
    }

    public function test_constructor_throws_on_empty_table(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new \Cashback_Rate_Limit_SQL_Counter($this->make_wpdb_mock(), '');
    }

    public function test_constructor_throws_on_unsupported_db_object(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @psalm-suppress InvalidArgument — намеренное нарушение для проверки валидации. */
        new \Cashback_Rate_Limit_SQL_Counter(new \stdClass(), 'cashback_rate_limit_counters');
    }

    public function test_constructor_accepts_wpdb_duck_typed_object(): void
    {
        $counter = new \Cashback_Rate_Limit_SQL_Counter(
            $this->make_wpdb_mock(),
            'cashback_rate_limit_counters'
        );

        $this->assertInstanceOf(\Cashback_Rate_Limit_Counter_Interface::class, $counter);
    }

    public function test_increment_throws_on_empty_scope_key(): void
    {
        $counter = new \Cashback_Rate_Limit_SQL_Counter(
            $this->make_wpdb_mock(),
            'cashback_rate_limit_counters'
        );

        $this->expectException(\InvalidArgumentException::class);
        $counter->increment('', 60, 5);
    }

    public function test_increment_throws_on_non_positive_window(): void
    {
        $counter = new \Cashback_Rate_Limit_SQL_Counter(
            $this->make_wpdb_mock(),
            'cashback_rate_limit_counters'
        );

        $this->expectException(\InvalidArgumentException::class);
        $counter->increment('scope', 0, 5);
    }

    public function test_increment_throws_on_negative_limit(): void
    {
        $counter = new \Cashback_Rate_Limit_SQL_Counter(
            $this->make_wpdb_mock(),
            'cashback_rate_limit_counters'
        );

        $this->expectException(\InvalidArgumentException::class);
        $counter->increment('scope', 60, -1);
    }

    public function test_increment_throws_on_scope_key_longer_than_64_chars(): void
    {
        $counter = new \Cashback_Rate_Limit_SQL_Counter(
            $this->make_wpdb_mock(),
            'cashback_rate_limit_counters'
        );

        $this->expectException(\InvalidArgumentException::class);
        $counter->increment(str_repeat('a', 65), 60, 5);
    }

    public function test_increment_returns_expected_shape_with_wpdb(): void
    {
        $wpdb = $this->make_wpdb_mock(
            upsert_callback: null,
            get_row_return: array(
                'hits'              => '3',
                'window_started_at' => (string) time(),
                'expires_at'        => (string) (time() + 60),
            )
        );

        $counter = new \Cashback_Rate_Limit_SQL_Counter($wpdb, 'cashback_rate_limit_counters');

        $result = $counter->increment('scope_x', 60, 5);

        $this->assertArrayHasKey('hits', $result);
        $this->assertArrayHasKey('allowed', $result);
        $this->assertArrayHasKey('reset_at', $result);
        $this->assertSame(3, $result['hits']);
        $this->assertTrue($result['allowed']);
        $this->assertIsInt($result['reset_at']);
    }

    public function test_increment_allowed_false_when_hits_exceed_limit(): void
    {
        $wpdb = $this->make_wpdb_mock(
            upsert_callback: null,
            get_row_return: array(
                'hits'              => '7',
                'window_started_at' => (string) time(),
                'expires_at'        => (string) (time() + 60),
            )
        );

        $counter = new \Cashback_Rate_Limit_SQL_Counter($wpdb, 'cashback_rate_limit_counters');

        $result = $counter->increment('scope_y', 60, 5);

        $this->assertSame(7, $result['hits']);
        $this->assertFalse($result['allowed']);
    }

    public function test_increment_throws_runtime_error_when_row_missing_after_upsert(): void
    {
        $wpdb = $this->make_wpdb_mock(
            upsert_callback: null,
            get_row_return: null
        );

        $counter = new \Cashback_Rate_Limit_SQL_Counter($wpdb, 'cashback_rate_limit_counters');

        $this->expectException(\RuntimeException::class);
        $counter->increment('scope_z', 60, 5);
    }

    // ─────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────

    /**
     * Анонимный wpdb-mock с минимальным API: prepare / query / get_row.
     * Подменяет wpdb для unit-тестов без коннекта к MariaDB.
     */
    private function make_wpdb_mock(
        ?callable $upsert_callback = null,
        ?array $get_row_return = null
    ): object {
        return new class($upsert_callback, $get_row_return) {
            public string $prefix = 'wp_';

            public function __construct(
                private $upsert_cb = null,
                private ?array $get_row_value = null
            ) {}

            public function prepare(string $query, ...$args): string
            {
                // Минимальная эмуляция: подменяем %s/%d на repr значения.
                $i = 0;
                return (string) preg_replace_callback(
                    '/%[sd]/',
                    function (array $m) use (&$i, $args): string {
                        $val = $args[ $i ] ?? '';
                        $i++;
                        return $m[0] === '%d' ? (string) (int) $val : "'" . addslashes((string) $val) . "'";
                    },
                    $query
                );
            }

            public function query(string $sql): int
            {
                if ($this->upsert_cb !== null) {
                    ($this->upsert_cb)($sql);
                }
                return 1;
            }

            public function get_row(string $sql, string $output = 'OBJECT'): ?array
            {
                return $this->get_row_value;
            }
        };
    }
}
