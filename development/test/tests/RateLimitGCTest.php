<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты для Cashback_Rate_Limit_GC (Группа 7 ADR, шаг 10).
 *
 * GC удаляет expired rows из cashback_rate_limit_counters (expires_at < NOW()).
 * Batch-limit 5000 защищает от OLTP-лока на больших таблицах.
 */
#[Group('rate-limit')]
final class RateLimitGCTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/includes/class-cashback-rate-limit-migration.php';
        require_once $plugin_root . '/includes/class-cashback-rate-limit-gc.php';
    }

    public function test_collect_issues_delete_with_expires_at_filter(): void
    {
        $wpdb = new Rate_Limit_GC_Wpdb_Stub();
        $wpdb->affected_rows_return = 42;

        $result = \Cashback_Rate_Limit_GC::collect($wpdb, 1_700_000_000);

        $this->assertSame(42, $result);
        $this->assertCount(1, $wpdb->executed_sql);

        $sql = $wpdb->executed_sql[0];
        $this->assertStringContainsString('DELETE FROM `wp_cashback_rate_limit_counters`', $sql);
        $this->assertStringContainsString('WHERE expires_at < 1700000000', $sql);
        $this->assertStringContainsString('LIMIT ' . \Cashback_Rate_Limit_GC::BATCH_LIMIT, $sql);
    }

    public function test_collect_returns_zero_when_nothing_to_delete(): void
    {
        $wpdb = new Rate_Limit_GC_Wpdb_Stub();
        $wpdb->affected_rows_return = 0;

        $result = \Cashback_Rate_Limit_GC::collect($wpdb, time());

        $this->assertSame(0, $result);
    }

    public function test_collect_uses_current_time_when_now_is_null(): void
    {
        $wpdb = new Rate_Limit_GC_Wpdb_Stub();
        $wpdb->affected_rows_return = 1;

        $before = time();
        \Cashback_Rate_Limit_GC::collect($wpdb);
        $after = time();

        $this->assertCount(1, $wpdb->executed_sql);
        $sql = $wpdb->executed_sql[0];

        // Извлекаем число после "expires_at < "
        $this->assertMatchesRegularExpression('/expires_at < (\d+)/', $sql, 'SQL должен содержать timestamp.');
        preg_match('/expires_at < (\d+)/', $sql, $matches);
        $used_ts = (int) $matches[1];

        $this->assertGreaterThanOrEqual($before, $used_ts);
        $this->assertLessThanOrEqual($after, $used_ts);
    }

    public function test_batch_limit_is_bounded(): void
    {
        $this->assertSame(5000, \Cashback_Rate_Limit_GC::BATCH_LIMIT);
    }
}

/**
 * Stub wpdb для GC-тестов.
 */
final class Rate_Limit_GC_Wpdb_Stub
{
    public string $prefix = 'wp_';
    public int $affected_rows_return = 0;
    /** @var array<int, string> */
    public array $executed_sql = array();

    public function prepare(string $query, mixed ...$args): string
    {
        $i = 0;
        return (string) preg_replace_callback(
            '/%[sdif]/',
            function (array $m) use (&$i, $args): string {
                $v = $args[ $i ] ?? '';
                $i++;
                return is_string($v) ? "'" . str_replace("'", "\\'", $v) . "'" : (string) $v;
            },
            $query
        );
    }

    public function query(string $sql): int
    {
        $this->executed_sql[] = $sql;
        return $this->affected_rows_return;
    }
}
