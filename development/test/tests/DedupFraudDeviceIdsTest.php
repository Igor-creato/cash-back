<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit-тесты для Cashback_Dedup_Fraud_Device_Ids_Strategy (Группа 6, шаг 1).
 *
 * Правила канонической строки:
 *   - ключ дедупа:      (user_id, DATE(first_seen), device_id);
 *   - canonical row:    MIN(first_seen);
 *   - merge canonical:  last_seen = MAX(last_seen), confidence_score = MAX(confidence_score);
 *   - NULL user_id:     не объединяется с другими NULL (MySQL: NULL != NULL в UNIQUE);
 *   - разные даты:      остаются отдельными строками.
 */
#[Group('dedup')]
final class DedupFraudDeviceIdsTest extends TestCase
{
    private Dedup_Fraud_Device_Ids_Wpdb_Stub $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Cashback_Dedup_Fraud_Device_Ids_Strategy')) {
            require_once dirname(__DIR__, 3) . '/tools/lib/class-cashback-dedup-fraud-device-ids-strategy.php';
        }

        $this->wpdb = new Dedup_Fraud_Device_Ids_Wpdb_Stub();
    }

    // ────────────────────────────────────────────────────────────
    // find_groups()
    // ────────────────────────────────────────────────────────────

    public function test_find_groups_groups_by_user_date_device(): void
    {
        $this->wpdb->seed([
            // Группа 1: user=10, device=D1, date=2026-04-20 (2 строки)
            ['id' => 1, 'user_id' => 10, 'device_id' => 'D1', 'first_seen' => '2026-04-20 10:00:00', 'last_seen' => '2026-04-20 10:05:00', 'confidence_score' => 0.30],
            ['id' => 2, 'user_id' => 10, 'device_id' => 'D1', 'first_seen' => '2026-04-20 18:00:00', 'last_seen' => '2026-04-20 18:30:00', 'confidence_score' => 0.70],
            // Одиночная — другой день
            ['id' => 3, 'user_id' => 10, 'device_id' => 'D1', 'first_seen' => '2026-04-21 10:00:00', 'last_seen' => '2026-04-21 10:05:00', 'confidence_score' => 0.40],
            // Одиночная — другой device_id
            ['id' => 4, 'user_id' => 10, 'device_id' => 'D2', 'first_seen' => '2026-04-20 11:00:00', 'last_seen' => '2026-04-20 11:05:00', 'confidence_score' => 0.50],
        ]);

        $strategy = new Cashback_Dedup_Fraud_Device_Ids_Strategy();
        $groups   = $strategy->find_groups($this->wpdb, 0);

        $this->assertCount(1, $groups, 'Ровно одна группа дублей (user=10, device=D1, date=2026-04-20)');
        $this->assertCount(2, $groups[0]['ids']);
        $this->assertEqualsCanonicalizing([1, 2], $groups[0]['ids']);
    }

    public function test_find_groups_excludes_null_user_id(): void
    {
        $this->wpdb->seed([
            ['id' => 1, 'user_id' => null, 'device_id' => 'D1', 'first_seen' => '2026-04-20 10:00:00', 'last_seen' => '2026-04-20 10:05:00', 'confidence_score' => 0.30],
            ['id' => 2, 'user_id' => null, 'device_id' => 'D1', 'first_seen' => '2026-04-20 18:00:00', 'last_seen' => '2026-04-20 18:30:00', 'confidence_score' => 0.70],
        ]);

        $groups = (new Cashback_Dedup_Fraud_Device_Ids_Strategy())->find_groups($this->wpdb, 0);

        $this->assertCount(0, $groups, 'NULL user_id не должен схлопываться с другими NULL (гостевые сессии)');
    }

    public function test_find_groups_respects_limit(): void
    {
        $this->wpdb->seed([
            ['id' => 1, 'user_id' => 10, 'device_id' => 'D1', 'first_seen' => '2026-04-20 10:00:00', 'last_seen' => '2026-04-20 10:05:00', 'confidence_score' => 0.30],
            ['id' => 2, 'user_id' => 10, 'device_id' => 'D1', 'first_seen' => '2026-04-20 18:00:00', 'last_seen' => '2026-04-20 18:30:00', 'confidence_score' => 0.70],
            ['id' => 3, 'user_id' => 11, 'device_id' => 'D2', 'first_seen' => '2026-04-20 10:00:00', 'last_seen' => '2026-04-20 10:05:00', 'confidence_score' => 0.30],
            ['id' => 4, 'user_id' => 11, 'device_id' => 'D2', 'first_seen' => '2026-04-20 11:00:00', 'last_seen' => '2026-04-20 11:05:00', 'confidence_score' => 0.30],
        ]);

        $groups = (new Cashback_Dedup_Fraud_Device_Ids_Strategy())->find_groups($this->wpdb, 1);

        $this->assertCount(1, $groups, 'limit=1 — обрабатывается одна группа');
    }

    // ────────────────────────────────────────────────────────────
    // choose_canonical() + merge_canonical()
    // ────────────────────────────────────────────────────────────

    public function test_choose_canonical_picks_min_first_seen(): void
    {
        $group = [
            'key' => 'k',
            'ids' => [1, 2, 3],
            'rows' => [
                ['id' => 1, 'first_seen' => '2026-04-20 18:00:00'],
                ['id' => 2, 'first_seen' => '2026-04-20 10:00:00'], // самая ранняя
                ['id' => 3, 'first_seen' => '2026-04-20 14:00:00'],
            ],
        ];

        $canonical = (new Cashback_Dedup_Fraud_Device_Ids_Strategy())->choose_canonical($group);

        $this->assertSame(2, $canonical);
    }

    public function test_merge_canonical_sets_last_seen_max_and_confidence_max(): void
    {
        $this->wpdb->seed([
            ['id' => 1, 'user_id' => 10, 'device_id' => 'D1', 'first_seen' => '2026-04-20 10:00:00', 'last_seen' => '2026-04-20 10:05:00', 'confidence_score' => 0.30],
            ['id' => 2, 'user_id' => 10, 'device_id' => 'D1', 'first_seen' => '2026-04-20 18:00:00', 'last_seen' => '2026-04-20 18:30:00', 'confidence_score' => 0.70],
        ]);

        $strategy = new Cashback_Dedup_Fraud_Device_Ids_Strategy();
        $group    = $strategy->find_groups($this->wpdb, 0)[0];
        $strategy->merge_canonical($this->wpdb, 1, $group);

        $canonical = $this->wpdb->row(1);
        $this->assertSame('2026-04-20 18:30:00', $canonical['last_seen'], 'last_seen = MAX по группе');
        $this->assertSame(0.70, (float) $canonical['confidence_score'], 'confidence_score = MAX по группе');
    }

    // ────────────────────────────────────────────────────────────
    // delete_duplicates()
    // ────────────────────────────────────────────────────────────

    public function test_delete_duplicates_removes_only_non_canonical(): void
    {
        $this->wpdb->seed([
            ['id' => 1, 'user_id' => 10, 'device_id' => 'D1', 'first_seen' => '2026-04-20 10:00:00', 'last_seen' => '2026-04-20 10:05:00', 'confidence_score' => 0.30],
            ['id' => 2, 'user_id' => 10, 'device_id' => 'D1', 'first_seen' => '2026-04-20 18:00:00', 'last_seen' => '2026-04-20 18:30:00', 'confidence_score' => 0.70],
        ]);

        $deleted = (new Cashback_Dedup_Fraud_Device_Ids_Strategy())->delete_duplicates($this->wpdb, [2]);

        $this->assertSame(1, $deleted);
        $this->assertNotNull($this->wpdb->row(1), 'Каноническая строка должна сохраниться');
        $this->assertNull($this->wpdb->row(2), 'Не-каноническая удалена');
    }

    // ────────────────────────────────────────────────────────────
    // relink_children()
    // ────────────────────────────────────────────────────────────

    public function test_relink_children_is_noop_no_fk_tables(): void
    {
        // У cashback_fraud_device_ids нет FK-детей — relink = 0 UPDATE'ов.
        $result = (new Cashback_Dedup_Fraud_Device_Ids_Strategy())->relink_children($this->wpdb, 1, [2, 3]);

        $this->assertSame(0, $result);
    }
}

// ────────────────────────────────────────────────────────────
// In-memory $wpdb stub для cashback_fraud_device_ids
// ────────────────────────────────────────────────────────────

final class Dedup_Fraud_Device_Ids_Wpdb_Stub
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var array<int, array<string,mixed>> */
    private array $rows = [];

    /** @param array<int, array<string,mixed>> $rows */
    public function seed(array $rows): void
    {
        foreach ($rows as $row) {
            $this->rows[(int) $row['id']] = $row;
        }
    }

    /** @return array<string,mixed>|null */
    public function row(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $i = 0;
        return (string) preg_replace_callback('/%[isdfl]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            if ($m[0] === '%l') {
                // "list placeholder" — не-стандарт; выше по стеку не используется, но оставим defensive.
                return is_array($v) ? implode(',', array_map('intval', $v)) : '0';
            }
            return is_string($v) ? "'" . str_replace("'", "\\'", $v) . "'" : (string) $v;
        }, $query);
    }

    /** @return array<int, array<string,mixed>> */
    public function get_results(string $sql, string $output = 'ARRAY_A'): array
    {
        // Имитация SELECT дубликатов:
        // SELECT user_id, DATE(first_seen) AS d, device_id, GROUP_CONCAT(id), COUNT(*)
        //   FROM wp_cashback_fraud_device_ids
        //   WHERE user_id IS NOT NULL
        //   GROUP BY user_id, DATE(first_seen), device_id
        //   HAVING COUNT(*)>1
        //   [LIMIT N]
        if (stripos($sql, 'fraud_device_ids') !== false && stripos($sql, 'GROUP BY') !== false) {
            return $this->find_duplicate_groups_sql($sql);
        }

        // SELECT id,... FROM ... WHERE id IN (...) FOR UPDATE
        if (preg_match('/WHERE\s+id\s+IN\s*\(([^)]+)\)/i', $sql, $m)) {
            $ids = array_map('intval', array_map('trim', explode(',', str_replace("'", '', $m[1]))));
            $result = [];
            foreach ($ids as $id) {
                if (isset($this->rows[$id])) {
                    $result[] = $this->rows[$id];
                }
            }
            return $result;
        }

        return [];
    }

    public function query(string $sql): int
    {
        // TX no-op
        if (preg_match('/^\s*(START TRANSACTION|COMMIT|ROLLBACK)/i', $sql)) {
            return 0;
        }

        // DELETE FROM wp_cashback_fraud_device_ids WHERE id IN (...)
        if (preg_match('/DELETE\s+FROM\s+[\'`]?wp_cashback_fraud_device_ids[\'`]?\s+WHERE\s+id\s+IN\s*\(([^)]+)\)/i', $sql, $m)) {
            $ids = array_map('intval', array_map('trim', explode(',', str_replace("'", '', $m[1]))));
            $count = 0;
            foreach ($ids as $id) {
                if (isset($this->rows[$id])) {
                    unset($this->rows[$id]);
                    $count++;
                }
            }
            return $count;
        }

        // UPDATE ... SET last_seen=..., confidence_score=... WHERE id=...
        if (preg_match("/UPDATE\s+[\'`]?wp_cashback_fraud_device_ids[\'`]?\s+SET\s+(.+?)\s+WHERE\s+id\s*=\s*'?(\d+)'?/is", $sql, $m)) {
            $id = (int) $m[2];
            if (!isset($this->rows[$id])) {
                return 0;
            }
            foreach (explode(',', $m[1]) as $assign) {
                if (preg_match("/([a-z_]+)\s*=\s*'?([^']*)'?/i", trim($assign), $am)) {
                    $col = $am[1];
                    $val = $am[2];
                    $this->rows[$id][$col] = is_numeric($val) ? (float) $val : $val;
                }
            }
            return 1;
        }

        return 0;
    }

    /** @return array<int, array<string,mixed>> */
    private function find_duplicate_groups_sql(string $sql): array
    {
        $groups = [];
        foreach ($this->rows as $row) {
            if ($row['user_id'] === null) {
                continue;
            }
            $date = substr((string) $row['first_seen'], 0, 10);
            $key  = $row['user_id'] . '|' . $date . '|' . $row['device_id'];
            $groups[$key][] = (int) $row['id'];
        }

        $dupes = array_filter($groups, fn(array $ids) => count($ids) > 1);

        preg_match('/LIMIT\s+(\d+)/i', $sql, $limit_m);
        if (!empty($limit_m[1])) {
            $dupes = array_slice($dupes, 0, (int) $limit_m[1], true);
        }

        $result = [];
        foreach ($dupes as $key => $ids) {
            [$user_id, $date, $device_id] = explode('|', $key);
            $result[] = [
                'user_id'      => (int) $user_id,
                'session_date' => $date,
                'device_id'    => $device_id,
                'ids'          => implode(',', $ids),
                'cnt'          => count($ids),
            ];
        }
        return $result;
    }
}
