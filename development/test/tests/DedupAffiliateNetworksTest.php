<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit-тесты для Cashback_Dedup_Affiliate_Networks_Strategy (Группа 6, шаг 1).
 *
 * Контекст: UNIQUE(slug) на `cashback_affiliate_networks` УЖЕ существует
 * (mariadb.php:186). Скрипт — legacy safety net: на чистых установках
 * обязан вернуть 0 групп; на инсталляциях, заведённых до введения UNIQUE,
 * должен уметь дедуплицировать дубликаты slug с релинком
 * `cashback_affiliate_network_params.network_id` до DELETE.
 *
 * Правила:
 *   - ключ дедупа:    `slug`;
 *   - canonical row:  MIN(id) (детерминированно и обратимо — стабильно между прогонами);
 *   - FK relink:      cashback_affiliate_network_params.network_id → canonical id;
 *   - NULL slug:      в схеме slug NOT NULL — не рассматривается.
 */
#[Group('dedup')]
final class DedupAffiliateNetworksTest extends TestCase
{
    private Dedup_Affiliate_Networks_Wpdb_Stub $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Cashback_Dedup_Affiliate_Networks_Strategy')) {
            require_once dirname(__DIR__, 3) . '/tools/lib/class-cashback-dedup-affiliate-networks-strategy.php';
        }

        $this->wpdb = new Dedup_Affiliate_Networks_Wpdb_Stub();
    }

    public function test_noop_when_no_slug_duplicates(): void
    {
        $this->wpdb->seed_networks([
            ['id' => 1, 'slug' => 'admitad', 'name' => 'Admitad'],
            ['id' => 2, 'slug' => 'epn',     'name' => 'EPN'],
        ]);

        $groups = (new Cashback_Dedup_Affiliate_Networks_Strategy())->find_groups($this->wpdb, 0);

        $this->assertCount(0, $groups, 'UNIQUE(slug) уже существует — на чистых БД групп быть не должно');
    }

    public function test_reports_slug_groups_when_legacy_duplicates_present(): void
    {
        // Синтетический сценарий legacy-дубликатов (pre-UNIQUE установка).
        $this->wpdb->seed_networks([
            ['id' => 1, 'slug' => 'admitad', 'name' => 'Admitad v1'],
            ['id' => 2, 'slug' => 'admitad', 'name' => 'Admitad v2 (дубль)'],
            ['id' => 3, 'slug' => 'epn',     'name' => 'EPN'],
        ]);

        $groups = (new Cashback_Dedup_Affiliate_Networks_Strategy())->find_groups($this->wpdb, 0);

        $this->assertCount(1, $groups);
        $this->assertEqualsCanonicalizing([1, 2], $groups[0]['ids']);
    }

    public function test_choose_canonical_picks_min_id(): void
    {
        $group = [
            'key'  => 'admitad',
            'ids'  => [3, 1, 2],
            'rows' => [
                ['id' => 3, 'slug' => 'admitad'],
                ['id' => 1, 'slug' => 'admitad'],
                ['id' => 2, 'slug' => 'admitad'],
            ],
        ];

        $canonical = (new Cashback_Dedup_Affiliate_Networks_Strategy())->choose_canonical($group);

        $this->assertSame(1, $canonical);
    }

    public function test_relink_network_params_before_delete(): void
    {
        $this->wpdb->seed_networks([
            ['id' => 1, 'slug' => 'admitad', 'name' => 'Admitad v1'],
            ['id' => 2, 'slug' => 'admitad', 'name' => 'Admitad v2'],
        ]);
        $this->wpdb->seed_params([
            ['id' => 100, 'network_id' => 1, 'key' => 'A'],
            ['id' => 101, 'network_id' => 2, 'key' => 'B'],
            ['id' => 102, 'network_id' => 2, 'key' => 'C'],
        ]);

        $affected = (new Cashback_Dedup_Affiliate_Networks_Strategy())->relink_children($this->wpdb, 1, [2]);

        $this->assertSame(2, $affected, 'Два параметра перепривязаны с network_id=2 → 1');
        $params = $this->wpdb->get_params();
        $this->assertSame(1, $params[100]['network_id']);
        $this->assertSame(1, $params[101]['network_id']);
        $this->assertSame(1, $params[102]['network_id']);
    }

    public function test_delete_duplicates_removes_only_non_canonical(): void
    {
        $this->wpdb->seed_networks([
            ['id' => 1, 'slug' => 'admitad', 'name' => 'Admitad v1'],
            ['id' => 2, 'slug' => 'admitad', 'name' => 'Admitad v2'],
        ]);

        $deleted = (new Cashback_Dedup_Affiliate_Networks_Strategy())->delete_duplicates($this->wpdb, [2]);

        $this->assertSame(1, $deleted);
        $this->assertNotNull($this->wpdb->get_network(1));
        $this->assertNull($this->wpdb->get_network(2));
    }
}

// ────────────────────────────────────────────────────────────
// In-memory $wpdb stub для cashback_affiliate_networks + params
// ────────────────────────────────────────────────────────────

final class Dedup_Affiliate_Networks_Wpdb_Stub
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var array<int, array<string,mixed>> */
    private array $networks = [];
    /** @var array<int, array<string,mixed>> */
    private array $params = [];

    /** @param array<int, array<string,mixed>> $rows */
    public function seed_networks(array $rows): void
    {
        foreach ($rows as $r) {
            $this->networks[(int) $r['id']] = $r;
        }
    }

    /** @param array<int, array<string,mixed>> $rows */
    public function seed_params(array $rows): void
    {
        foreach ($rows as $r) {
            $this->params[(int) $r['id']] = $r;
        }
    }

    /** @return array<string,mixed>|null */
    public function get_network(int $id): ?array
    {
        return $this->networks[$id] ?? null;
    }

    /** @return array<int, array<string,mixed>> */
    public function get_params(): array
    {
        return $this->params;
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
        if (stripos($sql, 'cashback_affiliate_networks') !== false && stripos($sql, 'GROUP BY') !== false) {
            $groups = [];
            foreach ($this->networks as $n) {
                $groups[$n['slug']][] = (int) $n['id'];
            }
            $dupes = array_filter($groups, fn(array $ids) => count($ids) > 1);
            preg_match('/LIMIT\s+(\d+)/i', $sql, $lim);
            if (!empty($lim[1])) {
                $dupes = array_slice($dupes, 0, (int) $lim[1], true);
            }
            $out = [];
            foreach ($dupes as $slug => $ids) {
                $out[] = ['slug' => (string) $slug, 'ids' => implode(',', $ids), 'cnt' => count($ids)];
            }
            return $out;
        }

        if (preg_match('/FROM\s+[\'`]?wp_cashback_affiliate_networks[\'`]?\s+WHERE\s+id\s+IN\s*\(([^)]+)\)/i', $sql, $m)) {
            $ids = array_map('intval', array_map('trim', explode(',', str_replace("'", '', $m[1]))));
            return array_values(array_intersect_key($this->networks, array_flip($ids)));
        }

        return [];
    }

    public function query(string $sql): int
    {
        if (preg_match('/^\s*(START TRANSACTION|COMMIT|ROLLBACK)/i', $sql)) {
            return 0;
        }

        if (preg_match(
            '/UPDATE\s+[\'`]?wp_cashback_affiliate_network_params[\'`]?\s+SET\s+network_id\s*=\s*\'?(\d+)\'?\s+WHERE\s+network_id\s+IN\s*\(([^)]+)\)/i',
            $sql,
            $m
        )) {
            $canonical = (int) $m[1];
            $dupe_ids  = array_map('intval', array_map('trim', explode(',', str_replace("'", '', $m[2]))));
            $affected  = 0;
            foreach ($this->params as $pid => $p) {
                if (in_array((int) $p['network_id'], $dupe_ids, true)) {
                    $this->params[$pid]['network_id'] = $canonical;
                    $affected++;
                }
            }
            return $affected;
        }

        if (preg_match('/DELETE\s+FROM\s+[\'`]?wp_cashback_affiliate_networks[\'`]?\s+WHERE\s+id\s+IN\s*\(([^)]+)\)/i', $sql, $m)) {
            $ids = array_map('intval', array_map('trim', explode(',', str_replace("'", '', $m[1]))));
            $count = 0;
            foreach ($ids as $id) {
                if (isset($this->networks[$id])) {
                    unset($this->networks[$id]);
                    $count++;
                }
            }
            return $count;
        }

        return 0;
    }
}
