<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * migrate_dedup_identity_v16():
 *   - ALTER cashback_affiliate_networks ADD dedup_identity (when column absent);
 *   - seed Admitad/EPN/Advcake contracts ONLY where dedup_identity IS NULL;
 *   - idempotent via cashback_db_version >= 16 fast-path;
 *   - skips ALTER when column already present;
 *   - runner wiring: v15 + v16 invoked after v14, before ob_end_clean.
 */
#[Group('migration')]
#[Group('split-order')]
final class DedupIdentityMigrationV16Test extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options'] = array();
    }

    private function make_wpdb_mock(bool $col_exists): object
    {
        return new class($col_exists) {
            public string $prefix = 'wp_';
            public string $last_error = '';
            /** @var array<int,string> */
            public array $query_calls = array();

            public function __construct(public bool $col_exists) {}

            public function prepare(string $query, mixed ...$args): string
            {
                $i = 0;
                return preg_replace_callback('/%[sid]/', function ($m) use (&$i, $args) {
                    $val = $args[$i++] ?? '';
                    if ($m[0] === '%s') {
                        return "'" . addslashes((string) $val) . "'";
                    }
                    if ($m[0] === '%i') {
                        return '`' . str_replace('`', '', (string) $val) . '`';
                    }
                    return (string) (int) $val;
                }, $query);
            }

            public function get_var(string $query): mixed
            {
                if (strpos($query, 'information_schema.COLUMNS') !== false) {
                    return $this->col_exists ? 1 : 0;
                }
                return null;
            }

            public function query(string $query): int
            {
                $this->query_calls[] = $query;
                if (strpos($query, 'ALTER TABLE') !== false && strpos($query, 'dedup_identity') !== false) {
                    $this->col_exists = true; // post-verify get_var now returns 1
                }
                return 1;
            }
        };
    }

    private function call_migration(object $wpdb_mock): object
    {
        $GLOBALS['wpdb'] = $wpdb_mock;
        if (!class_exists('Mariadb_Plugin')) {
            require_once self::$plugin_root . '/mariadb.php';
        }
        $reflection = new ReflectionClass('Mariadb_Plugin');
        if (!$reflection->hasMethod('migrate_dedup_identity_v16')) {
            $this->markTestSkipped('migrate_dedup_identity_v16 not present yet.');
        }
        $instance = $reflection->newInstanceWithoutConstructor();
        $reflection->getMethod('migrate_dedup_identity_v16')->invoke($instance);
        return $wpdb_mock;
    }

    /** @return string[] UPDATE-seed prepared queries. */
    private function seed_queries(object $wpdb): array
    {
        return array_values(array_filter(
            $wpdb->query_calls,
            static fn(string $q): bool => strpos($q, 'UPDATE') !== false
                && strpos($q, 'dedup_identity') !== false
                && strpos($q, 'ALTER') === false
        ));
    }

    public function test_fresh_install_alters_and_seeds_three_networks(): void
    {
        $wpdb = $this->call_migration($this->make_wpdb_mock(col_exists: false));

        $alter = array_filter(
            $wpdb->query_calls,
            static fn(string $q): bool => strpos($q, 'ALTER TABLE') !== false
                && strpos($q, 'dedup_identity') !== false
        );
        $this->assertNotEmpty($alter, 'ALTER must add dedup_identity on fresh install');

        $seeds = $this->seed_queries($wpdb);
        $this->assertCount(3, $seeds, 'must seed exactly admitad/epn/advcake');

        $blob = implode("\n", $seeds);
        $this->assertStringContainsString("WHERE slug = 'admitad' AND dedup_identity IS NULL", $blob);
        $this->assertStringContainsString("WHERE slug = 'epn' AND dedup_identity IS NULL", $blob);
        $this->assertStringContainsString("WHERE slug = 'advcake' AND dedup_identity IS NULL", $blob);

        $this->assertSame(16, get_option('cashback_db_version'));
    }

    public function test_seed_contract_json_is_correct_per_network(): void
    {
        $wpdb  = $this->call_migration($this->make_wpdb_mock(col_exists: true));
        $seeds = $this->seed_queries($wpdb);

        $expected = array(
            'admitad' => 'admitad_id',
            'epn'     => 'transactionId',
            'advcake' => 'id',
        );
        foreach ($expected as $slug => $recv_src) {
            $q = null;
            foreach ($seeds as $s) {
                if (strpos($s, "slug = '{$slug}'") !== false) {
                    $q = $s;
                    break;
                }
            }
            $this->assertNotNull($q, "seed query for {$slug} must exist");
            // Extract the single-quoted JSON between SET dedup_identity = '...'
            $this->assertSame(
                1,
                preg_match("/SET dedup_identity = '(.*)' WHERE slug = '{$slug}'/", $q, $m)
            );
            $contract = json_decode(stripslashes($m[1]), true);
            $this->assertIsArray($contract);
            $this->assertTrue($contract['has_native_action_id']);
            $this->assertFalse($contract['synthetic_include_click_id']);
            $this->assertSame(
                array( 'order_number', 'offer_id', 'action_type' ),
                $contract['synthetic_fields']
            );
            $this->assertSame($recv_src, $contract['receiver_uniq_source']);
        }
    }

    public function test_skips_alter_when_column_present_but_still_seeds(): void
    {
        $wpdb = $this->call_migration($this->make_wpdb_mock(col_exists: true));

        $alter = array_filter(
            $wpdb->query_calls,
            static fn(string $q): bool => strpos($q, 'ALTER TABLE') !== false
                && strpos($q, 'dedup_identity') !== false
        );
        $this->assertEmpty($alter, 'ALTER must be skipped when column already exists');
        $this->assertCount(3, $this->seed_queries($wpdb), 'seeds still run (IS NULL guard)');
        $this->assertSame(16, get_option('cashback_db_version'));
    }

    public function test_idempotent_when_db_version_already_16(): void
    {
        update_option('cashback_db_version', 16);
        $wpdb = $this->call_migration($this->make_wpdb_mock(col_exists: false));
        $this->assertEmpty($wpdb->query_calls, 'fast-path: no queries when version >= 16');
    }

    public function test_runner_wires_v15_then_v16_after_v14(): void
    {
        $src = file_get_contents(self::$plugin_root . '/mariadb.php');
        $this->assertIsString($src);

        $v14 = strpos($src, '$instance->migrate_advcake_seed_v14();');
        $v15 = strpos($src, '$instance->migrate_v15_uniqueness();');
        $v16 = strpos($src, '$instance->migrate_dedup_identity_v16();');
        $obend = strpos($src, 'ob_end_clean();');

        $this->assertIsInt($v14);
        $this->assertIsInt($v15, 'migrate_v15_uniqueness must be wired (else bump to 16 strands it)');
        $this->assertIsInt($v16, 'migrate_dedup_identity_v16 must be wired into the runner');
        $this->assertIsInt($obend);
        $this->assertGreaterThan($v14, $v15, 'v15 after v14');
        $this->assertGreaterThan($v15, $v16, 'v16 after v15');
        $this->assertGreaterThan($v16, $obend, 'both invoked before ob_end_clean');
    }

    private function call_v17(object $wpdb_mock): object
    {
        $GLOBALS['wpdb'] = $wpdb_mock;
        if (!class_exists('Mariadb_Plugin')) {
            require_once self::$plugin_root . '/mariadb.php';
        }
        $r = new ReflectionClass('Mariadb_Plugin');
        if (!$r->hasMethod('migrate_dedup_identity_backfill_v17')) {
            $this->markTestSkipped('migrate_dedup_identity_backfill_v17 not present yet.');
        }
        $r->getMethod('migrate_dedup_identity_backfill_v17')
          ->invoke($r->newInstanceWithoutConstructor());
        return $wpdb_mock;
    }

    public function test_v17_backfills_null_rows_slug_agnostic(): void
    {
        $wpdb = $this->call_v17($this->make_wpdb_mock(col_exists: true));

        $upd = array_values(array_filter(
            $wpdb->query_calls,
            static fn(string $q): bool => strpos($q, 'UPDATE') !== false
                && strpos($q, 'dedup_identity') !== false
        ));
        $this->assertCount(1, $upd, 'v17 = single slug-agnostic UPDATE');
        // No slug predicate — universal backfill, only the IS NULL guard.
        $this->assertStringContainsString('WHERE dedup_identity IS NULL', $upd[0]);
        $this->assertStringNotContainsString("slug =", $upd[0], 'must NOT hardcode any slug');
        // Safe native default contract.
        $this->assertSame(1, preg_match("/SET dedup_identity = '(.*)' WHERE/", $upd[0], $m));
        $c = json_decode(stripslashes($m[1]), true);
        $this->assertTrue($c['has_native_action_id']);
        $this->assertFalse($c['synthetic_include_click_id']);
        $this->assertSame(array('order_number', 'offer_id', 'action_type'), $c['synthetic_fields']);
        $this->assertArrayNotHasKey('receiver_uniq_source', $c, 'receiver_uniq_source is operator-declared, not seeded');
        $this->assertSame(17, get_option('cashback_db_version'));
    }

    public function test_v17_idempotent_when_version_already_17(): void
    {
        update_option('cashback_db_version', 17);
        $wpdb = $this->call_v17($this->make_wpdb_mock(col_exists: true));
        $this->assertEmpty($wpdb->query_calls, 'fast-path: no queries when version >= 17');
    }

    public function test_v17_skips_without_bump_when_column_absent(): void
    {
        $wpdb = $this->call_v17($this->make_wpdb_mock(col_exists: false));
        $this->assertEmpty(array_filter(
            $wpdb->query_calls,
            static fn(string $q): bool => strpos($q, 'UPDATE') !== false
        ));
        // No bump — v16 must still get a chance to create the column.
        $this->assertNotSame(17, get_option('cashback_db_version'));
    }

    public function test_runner_and_init_hook_wire_v17_after_v16(): void
    {
        $maria = file_get_contents(self::$plugin_root . '/mariadb.php');
        $plug  = file_get_contents(self::$plugin_root . '/cashback-plugin.php');

        $m16 = strpos($maria, '$instance->migrate_dedup_identity_v16();');
        $m17 = strpos($maria, '$instance->migrate_dedup_identity_backfill_v17();');
        $this->assertIsInt($m16);
        $this->assertIsInt($m17, 'activation runner must wire v17');
        $this->assertGreaterThan($m16, $m17, 'v17 after v16 in runner');

        $p16 = strpos($plug, 'migrate_dedup_identity_v16();');
        $p17 = strpos($plug, 'migrate_dedup_identity_backfill_v17();');
        $this->assertIsInt($p16);
        $this->assertIsInt($p17, 'init-hook must auto-fire v17 (zero-downtime)');
        $this->assertGreaterThan($p16, $p17, 'init-hook v17 after v16');
    }

    public function test_init_hook_zero_downtime_autofire_wires_v16(): void
    {
        // The activation runner only fires on (re)activation. Zero-downtime
        // deploys (git pull, no re-activation) rely on the init-hook
        // auto-fire in cashback-plugin.php — v14/v15 are wired there, v16
        // MUST be too or the migration never applies on a normal deploy.
        $src = file_get_contents(self::$plugin_root . '/cashback-plugin.php');
        $this->assertIsString($src);

        $v14 = strpos($src, 'migrate_advcake_seed_v14();');
        $v15 = strpos($src, 'migrate_v15_uniqueness();');
        $v16 = strpos($src, 'migrate_dedup_identity_v16();');

        $this->assertIsInt($v14, 'init-hook must auto-fire v14');
        $this->assertIsInt($v15, 'init-hook must auto-fire v15');
        $this->assertIsInt(
            $v16,
            'init-hook (cashback-plugin.php) must auto-fire migrate_dedup_identity_v16 '
            . '— else zero-downtime deploys never apply v16'
        );
        $this->assertGreaterThan($v15, $v16, 'init-hook v16 after v15 (same guarded try)');
    }
}
