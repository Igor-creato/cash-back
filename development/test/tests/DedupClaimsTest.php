<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit-тесты для Cashback_Dedup_Claims_Strategy (Группа 6, шаг 1).
 *
 * Правила:
 *   - ключ дедупа:     (merchant_id, order_id);
 *   - canonical row:   status-priority approved > sent_to_network > submitted > declined > draft;
 *                      tiebreak — MIN(created_at), затем MIN(claim_id);
 *   - merge canonical: comment/evidence канонической не трогаем (пользовательские поля);
 *   - relink FK:       cashback_claim_events.claim_id → canonical claim_id;
 *   - NULL merchant_id: не объединяется (NULL != NULL в UNIQUE MySQL).
 */
#[Group('dedup')]
final class DedupClaimsTest extends TestCase
{
    private Dedup_Claims_Wpdb_Stub $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Cashback_Dedup_Claims_Strategy')) {
            require_once dirname(__DIR__, 3) . '/tools/lib/class-cashback-dedup-claims-strategy.php';
        }

        $this->wpdb = new Dedup_Claims_Wpdb_Stub();
    }

    // ────────────────────────────────────────────────────────────
    // find_groups()
    // ────────────────────────────────────────────────────────────

    public function test_find_groups_groups_by_merchant_and_order(): void
    {
        $this->wpdb->seed_claims([
            ['claim_id' => 1, 'user_id' => 10, 'merchant_id' => 100, 'order_id' => 'ORDER-A', 'status' => 'approved',  'created_at' => '2026-04-20 10:00:00'],
            ['claim_id' => 2, 'user_id' => 11, 'merchant_id' => 100, 'order_id' => 'ORDER-A', 'status' => 'submitted', 'created_at' => '2026-04-20 11:00:00'],
            // другой мерчант — отдельная (unique) запись
            ['claim_id' => 3, 'user_id' => 10, 'merchant_id' => 200, 'order_id' => 'ORDER-A', 'status' => 'approved',  'created_at' => '2026-04-20 10:00:00'],
            // другой order_id — отдельная
            ['claim_id' => 4, 'user_id' => 11, 'merchant_id' => 100, 'order_id' => 'ORDER-B', 'status' => 'submitted', 'created_at' => '2026-04-20 11:00:00'],
        ]);

        $groups = (new Cashback_Dedup_Claims_Strategy())->find_groups($this->wpdb, 0);

        $this->assertCount(1, $groups, 'Только (merchant_id=100, order_id=ORDER-A) имеет дубль');
        $this->assertEqualsCanonicalizing([1, 2], $groups[0]['ids']);
    }

    public function test_find_groups_skips_null_merchant_id(): void
    {
        $this->wpdb->seed_claims([
            ['claim_id' => 1, 'user_id' => 10, 'merchant_id' => null, 'order_id' => 'ORDER-A', 'status' => 'submitted', 'created_at' => '2026-04-20 10:00:00'],
            ['claim_id' => 2, 'user_id' => 11, 'merchant_id' => null, 'order_id' => 'ORDER-A', 'status' => 'submitted', 'created_at' => '2026-04-20 11:00:00'],
        ]);

        $groups = (new Cashback_Dedup_Claims_Strategy())->find_groups($this->wpdb, 0);

        $this->assertCount(0, $groups, 'NULL merchant_id → группировка не применяется');
    }

    // ────────────────────────────────────────────────────────────
    // choose_canonical() — status priority
    // ────────────────────────────────────────────────────────────

    /**
     * @param array<int, array{claim_id:int,status:string,created_at:string}> $rows
     */
    public function assert_canonical(int $expected, array $rows, string $msg = ''): void
    {
        $group = [
            'key'  => 'k',
            'ids'  => array_column($rows, 'claim_id'),
            'rows' => $rows,
        ];
        $this->assertSame(
            $expected,
            (new Cashback_Dedup_Claims_Strategy())->choose_canonical($group),
            $msg
        );
    }

    public function test_canonical_priority_approved_beats_submitted(): void
    {
        $this->assert_canonical(2, [
            ['claim_id' => 1, 'status' => 'submitted', 'created_at' => '2026-04-20 10:00:00'],
            ['claim_id' => 2, 'status' => 'approved',  'created_at' => '2026-04-20 11:00:00'],
        ], 'approved побеждает submitted независимо от created_at');
    }

    public function test_canonical_priority_sent_to_network_beats_submitted(): void
    {
        $this->assert_canonical(1, [
            ['claim_id' => 1, 'status' => 'sent_to_network', 'created_at' => '2026-04-20 11:00:00'],
            ['claim_id' => 2, 'status' => 'submitted',       'created_at' => '2026-04-20 10:00:00'],
        ]);
    }

    public function test_canonical_priority_submitted_beats_declined_beats_draft(): void
    {
        $this->assert_canonical(1, [
            ['claim_id' => 1, 'status' => 'submitted', 'created_at' => '2026-04-20 12:00:00'],
            ['claim_id' => 2, 'status' => 'declined',  'created_at' => '2026-04-20 10:00:00'],
        ]);
        $this->assert_canonical(1, [
            ['claim_id' => 1, 'status' => 'declined', 'created_at' => '2026-04-20 12:00:00'],
            ['claim_id' => 2, 'status' => 'draft',    'created_at' => '2026-04-20 10:00:00'],
        ]);
    }

    public function test_canonical_tiebreak_min_created_at(): void
    {
        $this->assert_canonical(2, [
            ['claim_id' => 1, 'status' => 'approved', 'created_at' => '2026-04-20 12:00:00'],
            ['claim_id' => 2, 'status' => 'approved', 'created_at' => '2026-04-20 10:00:00'], // earliest
            ['claim_id' => 3, 'status' => 'approved', 'created_at' => '2026-04-20 11:00:00'],
        ], 'При равном статусе — MIN(created_at)');
    }

    public function test_canonical_tiebreak_min_claim_id_when_timestamps_equal(): void
    {
        $this->assert_canonical(5, [
            ['claim_id' => 7, 'status' => 'submitted', 'created_at' => '2026-04-20 10:00:00'],
            ['claim_id' => 5, 'status' => 'submitted', 'created_at' => '2026-04-20 10:00:00'],
            ['claim_id' => 9, 'status' => 'submitted', 'created_at' => '2026-04-20 10:00:00'],
        ], 'При равном статусе и created_at — MIN(claim_id)');
    }

    // ────────────────────────────────────────────────────────────
    // relink_children() — claim_events
    // ────────────────────────────────────────────────────────────

    public function test_relink_children_updates_claim_events_claim_id(): void
    {
        $this->wpdb->seed_claims([
            ['claim_id' => 1, 'user_id' => 10, 'merchant_id' => 100, 'order_id' => 'O', 'status' => 'approved', 'created_at' => '2026-04-20 10:00:00'],
            ['claim_id' => 2, 'user_id' => 10, 'merchant_id' => 100, 'order_id' => 'O', 'status' => 'submitted', 'created_at' => '2026-04-20 11:00:00'],
        ]);
        $this->wpdb->seed_claim_events([
            ['event_id' => 1, 'claim_id' => 1, 'status' => 'approved'],
            ['event_id' => 2, 'claim_id' => 2, 'status' => 'submitted'],
            ['event_id' => 3, 'claim_id' => 2, 'status' => 'sent_to_network'],
        ]);

        $affected = (new Cashback_Dedup_Claims_Strategy())->relink_children($this->wpdb, 1, [2]);

        $this->assertSame(2, $affected, 'Два события перепривязаны с claim_id=2 на claim_id=1');
        $events = $this->wpdb->get_claim_events();
        $this->assertSame(1, $events[1]['claim_id']);
        $this->assertSame(1, $events[2]['claim_id']);
        $this->assertSame(1, $events[3]['claim_id']);
    }

    // ────────────────────────────────────────────────────────────
    // delete_duplicates()
    // ────────────────────────────────────────────────────────────

    public function test_delete_duplicates_removes_only_non_canonical(): void
    {
        $this->wpdb->seed_claims([
            ['claim_id' => 1, 'user_id' => 10, 'merchant_id' => 100, 'order_id' => 'O', 'status' => 'approved',  'created_at' => '2026-04-20 10:00:00'],
            ['claim_id' => 2, 'user_id' => 10, 'merchant_id' => 100, 'order_id' => 'O', 'status' => 'submitted', 'created_at' => '2026-04-20 11:00:00'],
        ]);

        $deleted = (new Cashback_Dedup_Claims_Strategy())->delete_duplicates($this->wpdb, [2]);

        $this->assertSame(1, $deleted);
        $this->assertNotNull($this->wpdb->get_claim(1));
        $this->assertNull($this->wpdb->get_claim(2));
    }
}

// ────────────────────────────────────────────────────────────
// In-memory $wpdb stub для cashback_claims + cashback_claim_events
// ────────────────────────────────────────────────────────────

final class Dedup_Claims_Wpdb_Stub
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var array<int, array<string,mixed>> */
    private array $claims = [];
    /** @var array<int, array<string,mixed>> */
    private array $events = [];

    /** @param array<int, array<string,mixed>> $rows */
    public function seed_claims(array $rows): void
    {
        foreach ($rows as $r) {
            $this->claims[(int) $r['claim_id']] = $r;
        }
    }

    /** @param array<int, array<string,mixed>> $rows */
    public function seed_claim_events(array $rows): void
    {
        foreach ($rows as $r) {
            $this->events[(int) $r['event_id']] = $r;
        }
    }

    /** @return array<string,mixed>|null */
    public function get_claim(int $id): ?array
    {
        return $this->claims[$id] ?? null;
    }

    /** @return array<int, array<string,mixed>> */
    public function get_claim_events(): array
    {
        return $this->events;
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $i = 0;
        return (string) preg_replace_callback('/%[isdf]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            return is_string($v) ? "'" . str_replace("'", "\\'", $v) . "'" : (string) $v;
        }, $query);
    }

    /** @return array<int, array<string,mixed>> */
    public function get_results(string $sql, string $output = 'ARRAY_A'): array
    {
        if (stripos($sql, 'cashback_claims') !== false && stripos($sql, 'GROUP BY') !== false) {
            return $this->find_duplicate_groups_sql($sql);
        }
        if (preg_match('/FROM\s+[\'`]?wp_cashback_claims[\'`]?\s+WHERE\s+claim_id\s+IN\s*\(([^)]+)\)/i', $sql, $m)) {
            $ids = array_map('intval', array_map('trim', explode(',', str_replace("'", '', $m[1]))));
            return array_values(array_intersect_key($this->claims, array_flip($ids)));
        }
        return [];
    }

    public function query(string $sql): int
    {
        if (preg_match('/^\s*(START TRANSACTION|COMMIT|ROLLBACK)/i', $sql)) {
            return 0;
        }

        if (preg_match('/DELETE\s+FROM\s+[\'`]?wp_cashback_claims[\'`]?\s+WHERE\s+claim_id\s+IN\s*\(([^)]+)\)/i', $sql, $m)) {
            $ids = array_map('intval', array_map('trim', explode(',', str_replace("'", '', $m[1]))));
            $count = 0;
            foreach ($ids as $id) {
                if (isset($this->claims[$id])) {
                    unset($this->claims[$id]);
                    $count++;
                }
            }
            return $count;
        }

        if (preg_match(
            '/UPDATE\s+[\'`]?wp_cashback_claim_events[\'`]?\s+SET\s+claim_id\s*=\s*\'?(\d+)\'?\s+WHERE\s+claim_id\s+IN\s*\(([^)]+)\)/i',
            $sql,
            $m
        )) {
            $canonical  = (int) $m[1];
            $dupe_ids   = array_map('intval', array_map('trim', explode(',', str_replace("'", '', $m[2]))));
            $affected   = 0;
            foreach ($this->events as $eid => $event) {
                if (in_array((int) $event['claim_id'], $dupe_ids, true)) {
                    $this->events[$eid]['claim_id'] = $canonical;
                    $affected++;
                }
            }
            return $affected;
        }

        return 0;
    }

    /** @return array<int, array<string,mixed>> */
    private function find_duplicate_groups_sql(string $sql): array
    {
        $groups = [];
        foreach ($this->claims as $r) {
            if ($r['merchant_id'] === null) {
                continue;
            }
            $key = $r['merchant_id'] . '|' . $r['order_id'];
            $groups[$key][] = (int) $r['claim_id'];
        }

        $dupes = array_filter($groups, fn(array $ids) => count($ids) > 1);

        preg_match('/LIMIT\s+(\d+)/i', $sql, $limit_m);
        if (!empty($limit_m[1])) {
            $dupes = array_slice($dupes, 0, (int) $limit_m[1], true);
        }

        $result = [];
        foreach ($dupes as $key => $ids) {
            [$merchant_id, $order_id] = explode('|', $key, 2);
            $result[] = [
                'merchant_id' => (int) $merchant_id,
                'order_id'    => $order_id,
                'ids'         => implode(',', $ids),
                'cnt'         => count($ids),
            ];
        }
        return $result;
    }
}
