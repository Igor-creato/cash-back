<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit-тесты для Cashback_Dedup_Runner (Группа 6, шаг 1).
 *
 * Runner — orchestrator, отвечающий за:
 *   - transaction envelope (START TRANSACTION → COMMIT / ROLLBACK);
 *   - dry-run семантика (find_groups выполняется, merge/relink/delete — нет);
 *   - limit (проксируется в strategy::find_groups);
 *   - логгирование (callable $logger получает structured events).
 *
 * Сами стратегии тестируются в DedupFraudDeviceIds* / DedupClaims* / DedupAffiliateNetworks*.
 */
#[Group('dedup')]
final class DedupRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Cashback_Dedup_Runner')) {
            require_once dirname(__DIR__, 3) . '/tools/lib/class-cashback-dedup-runner.php';
        }
    }

    // ────────────────────────────────────────────────────────────
    // dry_run
    // ────────────────────────────────────────────────────────────

    public function test_dry_run_calls_find_groups_without_mutations(): void
    {
        $wpdb   = new Dedup_Test_Query_Log_Wpdb();
        $strategy = new Dedup_Test_Fake_Strategy([
            ['key' => 'k1', 'ids' => [1, 2], 'rows' => [['id' => 1], ['id' => 2]]],
        ]);

        $runner = new Cashback_Dedup_Runner($wpdb, $strategy, ['dry_run' => true]);
        $stats  = $runner->run();

        $this->assertSame(1, $stats['groups']);
        $this->assertSame(0, $stats['deleted']);
        $this->assertSame(0, $strategy->merge_calls, 'merge_canonical не должен вызываться в dry-run');
        $this->assertSame(0, $strategy->relink_calls, 'relink_children не должен вызываться в dry-run');
        $this->assertSame(0, $strategy->delete_calls, 'delete_duplicates не должен вызываться в dry-run');
        $this->assertNotContains('START TRANSACTION', $wpdb->queries, 'TX не должен открываться в dry-run');
    }

    // ────────────────────────────────────────────────────────────
    // destructive run — orchestration
    // ────────────────────────────────────────────────────────────

    public function test_destructive_run_wraps_each_group_in_transaction(): void
    {
        $wpdb   = new Dedup_Test_Query_Log_Wpdb();
        $strategy = new Dedup_Test_Fake_Strategy([
            ['key' => 'k1', 'ids' => [1, 2, 3], 'rows' => [['id' => 1], ['id' => 2], ['id' => 3]]],
            ['key' => 'k2', 'ids' => [10, 20], 'rows' => [['id' => 10], ['id' => 20]]],
        ]);

        $runner = new Cashback_Dedup_Runner($wpdb, $strategy, ['dry_run' => false]);
        $stats  = $runner->run();

        $this->assertSame(2, $stats['groups']);
        $this->assertSame(3, $stats['deleted'], 'Ожидаем 2+1 удалённых не-канонических строк');

        $tx_open  = array_filter($wpdb->queries, fn($q) => stripos($q, 'START TRANSACTION') === 0);
        $tx_close = array_filter($wpdb->queries, fn($q) => stripos($q, 'COMMIT') === 0);
        $this->assertCount(2, $tx_open, 'Каждая группа в своей TX');
        $this->assertCount(2, $tx_close);

        $this->assertSame(2, $strategy->merge_calls);
        $this->assertSame(2, $strategy->relink_calls);
        $this->assertSame(2, $strategy->delete_calls);
    }

    public function test_rollback_on_strategy_exception(): void
    {
        $wpdb   = new Dedup_Test_Query_Log_Wpdb();
        $strategy = new Dedup_Test_Fake_Strategy([
            ['key' => 'k1', 'ids' => [1, 2], 'rows' => [['id' => 1], ['id' => 2]]],
        ]);
        $strategy->throw_on_relink = true;

        $runner = new Cashback_Dedup_Runner($wpdb, $strategy, ['dry_run' => false]);
        $stats  = $runner->run();

        $this->assertSame(1, $stats['errors']);
        $this->assertSame(0, $stats['deleted']);
        $this->assertContains('ROLLBACK', $wpdb->queries, 'Runner должен откатить TX при исключении стратегии');
        $this->assertNotContains('COMMIT', $wpdb->queries);
    }

    // ────────────────────────────────────────────────────────────
    // limit
    // ────────────────────────────────────────────────────────────

    public function test_limit_is_forwarded_to_strategy_find_groups(): void
    {
        $wpdb   = new Dedup_Test_Query_Log_Wpdb();
        $strategy = new Dedup_Test_Fake_Strategy([]);

        $runner = new Cashback_Dedup_Runner($wpdb, $strategy, ['dry_run' => true, 'limit' => 42]);
        $runner->run();

        $this->assertSame(42, $strategy->last_limit_arg, 'Runner должен прокидывать limit в find_groups');
    }

    // ────────────────────────────────────────────────────────────
    // empty-table / no-op
    // ────────────────────────────────────────────────────────────

    public function test_no_duplicates_yields_zero_stats(): void
    {
        $wpdb   = new Dedup_Test_Query_Log_Wpdb();
        $strategy = new Dedup_Test_Fake_Strategy([]);

        $runner = new Cashback_Dedup_Runner($wpdb, $strategy, ['dry_run' => false]);
        $stats  = $runner->run();

        $this->assertSame(0, $stats['groups']);
        $this->assertSame(0, $stats['deleted']);
        $this->assertSame(0, $stats['errors']);
        $this->assertEmpty(
            array_filter($wpdb->queries, fn($q) => stripos($q, 'START TRANSACTION') === 0),
            'Нет групп → нет TX'
        );
    }

    // ────────────────────────────────────────────────────────────
    // logger
    // ────────────────────────────────────────────────────────────

    public function test_logger_receives_start_and_end_events(): void
    {
        $events  = [];
        $wpdb    = new Dedup_Test_Query_Log_Wpdb();
        $strategy = new Dedup_Test_Fake_Strategy([
            ['key' => 'k1', 'ids' => [1, 2], 'rows' => [['id' => 1], ['id' => 2]]],
        ]);

        $runner = new Cashback_Dedup_Runner($wpdb, $strategy, [
            'dry_run' => false,
            'logger'  => function (string $event, array $ctx) use (&$events): void {
                $events[] = ['event' => $event, 'ctx' => $ctx];
            },
        ]);
        $runner->run();

        $names = array_column($events, 'event');
        $this->assertContains('run.start', $names);
        $this->assertContains('group.commit', $names);
        $this->assertContains('run.end', $names);
    }
}

// ────────────────────────────────────────────────────────────
// Helpers
// ────────────────────────────────────────────────────────────

/**
 * Минимальный $wpdb-stub: запоминает ВСЕ query(), умеет prepare() no-op,
 * не возвращает реальных данных — достаточно для orchestration-тестов Runner.
 */
final class Dedup_Test_Query_Log_Wpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var array<int, string> */
    public array $queries = [];

    public function query(string $sql): int
    {
        $this->queries[] = trim($sql);
        return 0;
    }

    public function prepare(string $query, mixed ...$args): string
    {
        return $query;
    }
}

/**
 * Фейковая стратегия: отдаёт предзаданный набор групп и считает вызовы.
 * Полезна для проверки orchestration Runner'а без реального SQL.
 */
final class Dedup_Test_Fake_Strategy
{
    public int $merge_calls = 0;
    public int $relink_calls = 0;
    public int $delete_calls = 0;
    public int $last_limit_arg = -1;
    public bool $throw_on_relink = false;

    /** @param array<int, array{key:string,ids:array<int,int>,rows:array<int,array<string,mixed>>}> $groups */
    public function __construct(private array $groups)
    {
    }

    public function scope_name(): string
    {
        return 'fake';
    }

    /** @return array<int, array{key:string,ids:array<int,int>,rows:array<int,array<string,mixed>>}> */
    public function find_groups(object $wpdb, int $limit): array
    {
        $this->last_limit_arg = $limit;
        return $this->groups;
    }

    /** @param array{key:string,ids:array<int,int>,rows:array<int,array<string,mixed>>} $group */
    public function choose_canonical(array $group): int
    {
        return (int) $group['ids'][0];
    }

    /** @param array{key:string,ids:array<int,int>,rows:array<int,array<string,mixed>>} $group */
    public function merge_canonical(object $wpdb, int $canonical_id, array $group): int
    {
        $this->merge_calls++;
        return 1;
    }

    /** @param array<int,int> $duplicate_ids */
    public function relink_children(object $wpdb, int $canonical_id, array $duplicate_ids): int
    {
        $this->relink_calls++;
        if ($this->throw_on_relink) {
            throw new RuntimeException('relink exploded');
        }
        return count($duplicate_ids);
    }

    /** @param array<int,int> $duplicate_ids */
    public function delete_duplicates(object $wpdb, array $duplicate_ids): int
    {
        $this->delete_calls++;
        return count($duplicate_ids);
    }
}
