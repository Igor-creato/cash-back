<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * migrate_dedup_source_alias_v19() — alias-tolerant receiver_uniq_source.
 *
 * v16 и v18-step1 ищут встроенные сети по ЛИТЕРАЛЬНОМУ слагу
 * (admitad/epn/advcake). На реальных деплоях слаг Admitad — алиас `adm`
 * (name = "Admitad"), поэтому receiver_uniq_source так и не засеялся, v17
 * поставил generic native-дефолт без него, а bump db_version до 18
 * навсегда заморозил slug-only fast-path v18. v19 переназначает
 * каноничный receiver_uniq_source, резолвя встроенные сети по
 * slug ИЛИ LOWER(name) (паттерн как у receiver transaction_exists_for_action
 * `LOWER(partner) IN (LOWER(slug),LOWER(name))`). Additive, идемпотентно,
 * fail-closed (B-2: no-bump на ошибке чтения), ставит маркер только когда
 * он пуст, прочие ключи контракта сохраняются.
 */
#[Group('migration')]
#[Group('split-order')]
final class DedupSourceAliasV19Test extends TestCase
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

    /**
     * @param array<int,array<string,mixed>> $rows network rows (slug,name,id,dedup_identity)
     */
    private function make_wpdb_mock(bool $col_exists, array $rows = array(), string $fail_on = ''): object
    {
        return new class($col_exists, $rows, $fail_on) {
            public string $prefix = 'wp_';
            public string $last_error = '';
            /** @var array<int,string> */
            public array $query_calls = array();

            /** @param array<int,array<string,mixed>> $rows */
            public function __construct(public bool $col_exists, public array $rows, public string $fail_on) {}

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
                    if ($this->fail_on === 'col') {
                        $this->last_error = 'simulated col read error';
                        return null;
                    }
                    return $this->col_exists ? 1 : 0;
                }
                return null;
            }

            /** @return array<int,array<string,mixed>> */
            public function get_results(string $query, mixed $output = null): array
            {
                if ($this->fail_on === 'results') {
                    $this->last_error = 'simulated scan error';
                    return array();
                }
                // v19 step: WHERE slug = '<slug>' OR LOWER(name) = '<name>'
                if (!preg_match("/WHERE slug = '([^']*)' OR LOWER\\(name\\) = '([^']*)'/", $query, $m)) {
                    return array();
                }
                $slug_arg = $m[1];
                $name_arg = $m[2];
                $out = array();
                foreach ($this->rows as $r) {
                    $slug = (string) ($r['slug'] ?? '');
                    $name = strtolower((string) ($r['name'] ?? ''));
                    if ($slug === $slug_arg || ($name !== '' && $name === $name_arg)) {
                        $out[] = array(
                            'id'             => $r['id'] ?? 0,
                            'dedup_identity' => $r['dedup_identity'] ?? null,
                        );
                    }
                }
                return $out;
            }

            public function query(string $query): int
            {
                $this->query_calls[] = $query;
                return 1;
            }
        };
    }

    private function call_v19(object $wpdb_mock): object
    {
        $GLOBALS['wpdb'] = $wpdb_mock;
        if (!class_exists('Mariadb_Plugin')) {
            require_once self::$plugin_root . '/mariadb.php';
        }
        $r = new ReflectionClass('Mariadb_Plugin');
        if (!$r->hasMethod('migrate_dedup_source_alias_v19')) {
            $this->markTestSkipped('migrate_dedup_source_alias_v19 not present yet.');
        }
        $r->getMethod('migrate_dedup_source_alias_v19')
          ->invoke($r->newInstanceWithoutConstructor());
        return $wpdb_mock;
    }

    /** @return string[] UPDATE dedup_identity prepared queries. */
    private function update_queries(object $wpdb): array
    {
        return array_values(array_filter(
            $wpdb->query_calls,
            static fn(string $q): bool => strpos($q, 'UPDATE') !== false
                && strpos($q, 'dedup_identity') !== false
        ));
    }

    private function native_default_json(): string
    {
        return (string) wp_json_encode(array(
            'has_native_action_id'       => true,
            'synthetic_fields'           => array('order_number', 'offer_id', 'action_type'),
            'synthetic_include_click_id' => false,
        ));
    }

    public function test_idempotent_fast_path_when_version_already_19(): void
    {
        update_option('cashback_db_version', 19);
        $wpdb = $this->call_v19($this->make_wpdb_mock(col_exists: true, rows: array(
            array('slug' => 'adm', 'name' => 'Admitad', 'id' => 1, 'dedup_identity' => $this->native_default_json()),
        )));
        $this->assertEmpty($wpdb->query_calls, 'fast-path: no queries when version >= 19');
    }

    public function test_skips_without_bump_when_column_absent(): void
    {
        $wpdb = $this->call_v19($this->make_wpdb_mock(col_exists: false));
        $this->assertEmpty($this->update_queries($wpdb));
        $this->assertNotSame(19, get_option('cashback_db_version'), 'no bump — v16 must still create the column');
    }

    public function test_b2_no_bump_on_col_read_error(): void
    {
        $wpdb = $this->call_v19($this->make_wpdb_mock(col_exists: true, fail_on: 'col'));
        $this->assertEmpty($this->update_queries($wpdb));
        $this->assertNotSame(19, get_option('cashback_db_version'), 'B-2: no bump when col read errors');
    }

    public function test_b2_no_bump_on_get_results_read_error(): void
    {
        $wpdb = $this->call_v19($this->make_wpdb_mock(
            col_exists: true,
            rows: array(array('slug' => 'adm', 'name' => 'Admitad', 'id' => 7, 'dedup_identity' => $this->native_default_json())),
            fail_on: 'results'
        ));
        $this->assertEmpty($this->update_queries($wpdb), 'no UPDATE issued on get_results read error');
        $this->assertNotSame(19, get_option('cashback_db_version'), 'B-2: no bump on read error');
    }

    public function test_alias_adm_admitad_gets_canonical_receiver(): void
    {
        $wpdb = $this->call_v19($this->make_wpdb_mock(col_exists: true, rows: array(
            array('slug' => 'adm', 'name' => 'Admitad', 'id' => 7, 'dedup_identity' => $this->native_default_json()),
        )));

        $upd = $this->update_queries($wpdb);
        $this->assertCount(1, $upd, 'exactly one UPDATE for the aliased Admitad row');
        $this->assertSame(1, preg_match("/SET dedup_identity = '(.*)' WHERE id = 7/", $upd[0], $m));
        $c = json_decode(stripslashes($m[1]), true);
        $this->assertIsArray($c);
        $this->assertSame('admitad_id', $c['receiver_uniq_source']);
        $this->assertTrue($c['has_native_action_id'], 'native flag preserved');
        $this->assertSame(array('order_number', 'offer_id', 'action_type'), $c['synthetic_fields'], 'synthetic_fields preserved');
        $this->assertFalse($c['synthetic_include_click_id'], 'synthetic_include_click_id preserved');
        $this->assertSame(19, get_option('cashback_db_version'));
        // v19 must only touch the audit marker, never rewrite uniq_id values.
        $this->assertStringNotContainsStringIgnoringCase('uniq_id =', $upd[0], 'v19 must NOT rewrite uniq_id values');
    }

    public function test_idempotent_when_receiver_already_set(): void
    {
        $with_src = (string) wp_json_encode(array(
            'has_native_action_id'       => true,
            'synthetic_fields'           => array('order_number', 'offer_id', 'action_type'),
            'synthetic_include_click_id' => false,
            'receiver_uniq_source'       => 'admitad_id',
        ));
        $wpdb = $this->call_v19($this->make_wpdb_mock(col_exists: true, rows: array(
            array('slug' => 'adm', 'name' => 'Admitad', 'id' => 7, 'dedup_identity' => $with_src),
        )));
        $this->assertEmpty($this->update_queries($wpdb), 'no UPDATE when receiver_uniq_source already present');
        $this->assertSame(19, get_option('cashback_db_version'));
    }

    public function test_epn_alias_by_exact_slug_still_works(): void
    {
        $wpdb = $this->call_v19($this->make_wpdb_mock(col_exists: true, rows: array(
            array('slug' => 'epn', 'name' => 'ePN', 'id' => 2, 'dedup_identity' => $this->native_default_json()),
        )));
        $upd = $this->update_queries($wpdb);
        $this->assertCount(1, $upd);
        $this->assertSame(1, preg_match("/SET dedup_identity = '(.*)' WHERE id = 2/", $upd[0], $m));
        $c = json_decode(stripslashes($m[1]), true);
        $this->assertSame('transactionId', $c['receiver_uniq_source']);
        $this->assertSame(19, get_option('cashback_db_version'));
    }

    public function test_advcake_by_exact_slug_still_works(): void
    {
        $wpdb = $this->call_v19($this->make_wpdb_mock(col_exists: true, rows: array(
            array('slug' => 'advcake', 'name' => 'Advcake', 'id' => 3, 'dedup_identity' => $this->native_default_json()),
        )));
        $upd = $this->update_queries($wpdb);
        $this->assertCount(1, $upd);
        $this->assertSame(1, preg_match("/SET dedup_identity = '(.*)' WHERE id = 3/", $upd[0], $m));
        $c = json_decode(stripslashes($m[1]), true);
        $this->assertSame('id', $c['receiver_uniq_source']);
        $this->assertSame(19, get_option('cashback_db_version'));
    }

    public function test_custom_network_not_touched(): void
    {
        $wpdb = $this->call_v19($this->make_wpdb_mock(col_exists: true, rows: array(
            array('slug' => 'mycpa', 'name' => 'My CPA', 'id' => 50, 'dedup_identity' => $this->native_default_json()),
        )));
        $this->assertEmpty($this->update_queries($wpdb), 'custom network is not a built-in — never touched');
        $this->assertSame(19, get_option('cashback_db_version'), 'clean run still bumps version');
    }

    public function test_null_contract_uses_native_default(): void
    {
        $wpdb = $this->call_v19($this->make_wpdb_mock(col_exists: true, rows: array(
            array('slug' => 'adm', 'name' => 'Admitad', 'id' => 7, 'dedup_identity' => null),
        )));
        $upd = $this->update_queries($wpdb);
        $this->assertCount(1, $upd);
        $this->assertSame(1, preg_match("/SET dedup_identity = '(.*)' WHERE id = 7/", $upd[0], $m));
        $c = json_decode(stripslashes($m[1]), true);
        $this->assertSame('admitad_id', $c['receiver_uniq_source']);
        $this->assertTrue($c['has_native_action_id']);
        $this->assertSame(array('order_number', 'offer_id', 'action_type'), $c['synthetic_fields']);
        $this->assertFalse($c['synthetic_include_click_id']);
    }

    public function test_runner_and_init_hook_wire_v19_after_v18(): void
    {
        $maria = file_get_contents(self::$plugin_root . '/mariadb.php');
        $plug  = file_get_contents(self::$plugin_root . '/cashback-plugin.php');
        $this->assertIsString($maria);
        $this->assertIsString($plug);

        $m18   = strpos($maria, '$instance->migrate_dedup_source_consistency_v18();');
        $m19   = strpos($maria, '$instance->migrate_dedup_source_alias_v19();');
        $obend = strpos($maria, 'ob_end_clean();');
        $this->assertIsInt($m18);
        $this->assertIsInt($m19, 'activation runner must wire v19');
        $this->assertGreaterThan($m18, $m19, 'v19 after v18 in runner');
        $this->assertGreaterThan($m19, $obend, 'v19 invoked before ob_end_clean');

        $p18 = strpos($plug, 'migrate_dedup_source_consistency_v18();');
        $p19 = strpos($plug, 'migrate_dedup_source_alias_v19();');
        $this->assertIsInt($p18);
        $this->assertIsInt($p19, 'init-hook must auto-fire v19 (zero-downtime)');
        $this->assertGreaterThan($p18, $p19, 'init-hook v19 after v18 (same guarded try)');
        // error_log line must mention v19 too.
        $this->assertStringContainsString('v14/v15/v16/v17/v18/v19 auto-fire failed', $plug);
    }
}
