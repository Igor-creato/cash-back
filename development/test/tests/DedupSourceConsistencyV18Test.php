<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * migrate_dedup_source_consistency_v18() + S2 source-field guard.
 *
 * Native uniq_id is an opaque, case-sensitive, exact-match token
 * (Stripe/IETF idempotency-key + affiliate-postback standard). NO
 * normalization: case-fold/zero-strip are forbidden (entropy loss collapses
 * distinct conversions => lost transaction). The real duplicate risk is
 * push/pull source-field drift; v18 closes it WITHOUT touching uniq_id:
 *   - step 1: re-assert canonical receiver_uniq_source for built-in
 *     networks (v16 only seeded WHERE IS NULL);
 *   - step 2: flag custom native networks that explicitly remap uniq_id
 *     but never declared receiver_uniq_source (the only silent-drift
 *     vector) into an option for a persistent admin notice;
 *   - idempotent via cashback_db_version >= 18 fast-path.
 * Plus structural assertions for the save-gate, admin single-code-path,
 * notice wiring and runner wiring.
 */
#[Group('migration')]
#[Group('split-order')]
final class DedupSourceConsistencyV18Test extends TestCase
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
     * @param array<int,array<string,mixed>> $rows network rows (slug,id,dedup_identity,api_field_map)
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

            public function get_row(string $query, mixed $output = null): mixed
            {
                if ($this->fail_on === 'row') {
                    $this->last_error = 'simulated row read error';
                    return null;
                }
                if (preg_match("/WHERE slug = '([^']+)'/", $query, $m)) {
                    foreach ($this->rows as $r) {
                        if ((string) ($r['slug'] ?? '') === $m[1]) {
                            return array(
                                'id'             => $r['id'] ?? 0,
                                'dedup_identity' => $r['dedup_identity'] ?? null,
                            );
                        }
                    }
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
                $out = array();
                foreach ($this->rows as $r) {
                    $out[] = array(
                        'slug'          => $r['slug'] ?? '',
                        'api_field_map' => $r['api_field_map'] ?? null,
                        'dedup_identity' => $r['dedup_identity'] ?? null,
                    );
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

    private function call_v18(object $wpdb_mock): object
    {
        $GLOBALS['wpdb'] = $wpdb_mock;
        if (!class_exists('Mariadb_Plugin')) {
            require_once self::$plugin_root . '/mariadb.php';
        }
        $r = new ReflectionClass('Mariadb_Plugin');
        if (!$r->hasMethod('migrate_dedup_source_consistency_v18')) {
            $this->markTestSkipped('migrate_dedup_source_consistency_v18 not present yet.');
        }
        $r->getMethod('migrate_dedup_source_consistency_v18')
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

    public function test_idempotent_fast_path_when_version_already_18(): void
    {
        update_option('cashback_db_version', 18);
        $wpdb = $this->call_v18($this->make_wpdb_mock(col_exists: true, rows: array(
            array('slug' => 'admitad', 'id' => 1, 'dedup_identity' => $this->native_default_json()),
        )));
        $this->assertEmpty($wpdb->query_calls, 'fast-path: no queries when version >= 18');
    }

    public function test_skips_without_bump_when_column_absent(): void
    {
        $wpdb = $this->call_v18($this->make_wpdb_mock(col_exists: false));
        $this->assertEmpty($this->update_queries($wpdb));
        $this->assertNotSame(18, get_option('cashback_db_version'), 'no bump — v16 must still create the column');
    }

    public function test_step1_reasserts_canonical_receiver_for_builtin_missing_it(): void
    {
        $wpdb = $this->call_v18($this->make_wpdb_mock(col_exists: true, rows: array(
            array('slug' => 'admitad', 'id' => 1, 'dedup_identity' => $this->native_default_json()),
            array('slug' => 'epn',     'id' => 2, 'dedup_identity' => $this->native_default_json()),
            array('slug' => 'advcake', 'id' => 3, 'dedup_identity' => $this->native_default_json()),
        )));

        $upd  = $this->update_queries($wpdb);
        $blob = implode("\n", $upd);
        $this->assertCount(3, $upd, 'one UPDATE per built-in network missing receiver_uniq_source');

        foreach (array('admitad' => 'admitad_id', 'epn' => 'transactionId', 'advcake' => 'id') as $slug => $src) {
            $found = null;
            foreach ($upd as $q) {
                if (preg_match("/SET dedup_identity = '(.*)' WHERE id = (\\d+)/", $q, $m)) {
                    $c = json_decode(stripslashes($m[1]), true);
                    if (is_array($c) && ($c['receiver_uniq_source'] ?? '') === $src) {
                        $found = $c;
                        break;
                    }
                }
            }
            $this->assertIsArray($found, "canonical receiver_uniq_source for {$slug} must be asserted");
            $this->assertTrue($found['has_native_action_id'], 'native flag preserved');
        }
        $this->assertSame(18, get_option('cashback_db_version'));
        $this->assertStringNotContainsStringIgnoringCase('LOWER(', $blob, 'v18 must NOT normalize/case-fold uniq_id');
        $this->assertStringNotContainsStringIgnoringCase('uniq_id =', $blob, 'v18 must NOT rewrite uniq_id values');
    }

    public function test_step1_idempotent_when_receiver_already_set(): void
    {
        $with_src = (string) wp_json_encode(array(
            'has_native_action_id'       => true,
            'synthetic_fields'           => array('order_number', 'offer_id', 'action_type'),
            'synthetic_include_click_id' => false,
            'receiver_uniq_source'       => 'admitad_id',
        ));
        $wpdb = $this->call_v18($this->make_wpdb_mock(col_exists: true, rows: array(
            array('slug' => 'admitad', 'id' => 1, 'dedup_identity' => $with_src),
        )));
        $this->assertEmpty($this->update_queries($wpdb), 'no UPDATE when receiver_uniq_source already present');
        $this->assertSame(18, get_option('cashback_db_version'));
    }

    public function test_step1_handles_null_contract_with_native_default(): void
    {
        $wpdb = $this->call_v18($this->make_wpdb_mock(col_exists: true, rows: array(
            array('slug' => 'epn', 'id' => 9, 'dedup_identity' => null),
        )));
        $upd = $this->update_queries($wpdb);
        $this->assertCount(1, $upd);
        $this->assertSame(1, preg_match("/SET dedup_identity = '(.*)' WHERE id = 9/", $upd[0], $m));
        $c = json_decode(stripslashes($m[1]), true);
        $this->assertSame('transactionId', $c['receiver_uniq_source']);
        $this->assertTrue($c['has_native_action_id']);
        $this->assertSame(array('order_number', 'offer_id', 'action_type'), $c['synthetic_fields']);
    }

    public function test_step2_flags_custom_native_with_explicit_uniq_remap_and_no_receiver(): void
    {
        $wpdb = $this->call_v18($this->make_wpdb_mock(col_exists: true, rows: array(
            array(
                'slug'          => 'mycpa',
                'id'            => 50,
                'dedup_identity' => $this->native_default_json(), // native, no receiver_uniq_source
                'api_field_map'  => (string) wp_json_encode(array('order_id' => 'uniq_id')),
            ),
        )));
        $drift = get_option('cashback_dedup_source_drift');
        $this->assertSame(array('mycpa'), $drift, 'custom native + explicit uniq remap + no receiver => flagged');
    }

    public function test_step2_does_not_flag_default_field_map(): void
    {
        $wpdb = $this->call_v18($this->make_wpdb_mock(col_exists: true, rows: array(
            array(
                'slug'          => 'mycpa2',
                'id'            => 51,
                'dedup_identity' => $this->native_default_json(),
                'api_field_map'  => (string) wp_json_encode(array('order_id' => 'order_number')), // no uniq_id remap
            ),
        )));
        $this->assertFalse(
            (bool) get_option('cashback_dedup_source_drift'),
            'default map (cron falls back to action_id) is NOT drift'
        );
    }

    public function test_step2_b3_does_not_flag_explicit_default_action_id_pair(): void
    {
        // B-3: api_field_map explicitly stores the DEFAULT pair
        // {"action_id":"uniq_id"} — functionally identical to default
        // (cron resolves uniq_id from action_id either way). Must NOT be
        // flagged, else valid credential rotation is blocked (lost-tx).
        $this->call_v18($this->make_wpdb_mock(col_exists: true, rows: array(
            array(
                'slug'          => 'mycpa_default',
                'id'            => 60,
                'dedup_identity' => $this->native_default_json(),
                'api_field_map'  => (string) wp_json_encode(array('action_id' => 'uniq_id')),
            ),
        )));
        $this->assertFalse(
            (bool) get_option('cashback_dedup_source_drift'),
            'explicit {action_id:uniq_id} == default, NOT a remap'
        );
        $this->assertSame(18, get_option('cashback_db_version'));
    }

    public function test_step2_b3_duplicate_uniq_values_use_last_wins_like_runtime(): void
    {
        // Codex iter-2: array_search would see FIRST key (action_id) and
        // miss the drift, but runtime api_field_for() = array_flip is
        // LAST-wins → cron actually resolves uniq_id from `foo`. The scan
        // MUST mirror array_flip and flag this as drift.
        $this->call_v18($this->make_wpdb_mock(col_exists: true, rows: array(
            array(
                'slug'          => 'mycpa_dup',
                'id'            => 61,
                'dedup_identity' => $this->native_default_json(),
                'api_field_map'  => '{"action_id":"uniq_id","foo":"uniq_id"}',
            ),
        )));
        $this->assertSame(
            array('mycpa_dup'),
            get_option('cashback_dedup_source_drift'),
            'duplicate uniq_id values: last-wins (foo) ≠ action_id => drift'
        );
    }

    public function test_b2_no_bump_on_col_read_error(): void
    {
        $wpdb = $this->call_v18($this->make_wpdb_mock(col_exists: true, fail_on: 'col'));
        $this->assertEmpty($this->update_queries($wpdb));
        $this->assertNotSame(18, get_option('cashback_db_version'), 'B-2: no bump when col read errors');
    }

    public function test_b2_no_bump_on_step1_row_read_error(): void
    {
        $wpdb = $this->call_v18($this->make_wpdb_mock(
            col_exists: true,
            rows: array(array('slug' => 'admitad', 'id' => 1, 'dedup_identity' => null)),
            fail_on: 'row'
        ));
        $this->assertEmpty($this->update_queries($wpdb), 'no UPDATE issued on row read error');
        $this->assertNotSame(18, get_option('cashback_db_version'), 'B-2: no bump on step-1 read error');
    }

    public function test_b2_no_bump_and_drift_option_preserved_on_scan_error(): void
    {
        update_option('cashback_dedup_source_drift', array('preexisting'));
        $this->call_v18($this->make_wpdb_mock(
            col_exists: true,
            rows: array(array('slug' => 'mycpa', 'id' => 70, 'dedup_identity' => $this->native_default_json(),
                'api_field_map' => (string) wp_json_encode(array('x' => 'uniq_id')))),
            fail_on: 'results'
        ));
        $this->assertSame(
            array('preexisting'),
            get_option('cashback_dedup_source_drift'),
            'B-2: scan error must NOT clobber existing drift option'
        );
        $this->assertNotSame(18, get_option('cashback_db_version'), 'B-2: no bump on scan error');
    }

    public function test_step2_does_not_flag_when_receiver_declared(): void
    {
        $with_src = (string) wp_json_encode(array(
            'has_native_action_id' => true,
            'receiver_uniq_source' => 'my_order_field',
        ));
        $this->call_v18($this->make_wpdb_mock(col_exists: true, rows: array(
            array(
                'slug'          => 'mycpa3',
                'id'            => 52,
                'dedup_identity' => $with_src,
                'api_field_map'  => (string) wp_json_encode(array('order_id' => 'uniq_id')),
            ),
        )));
        $this->assertFalse((bool) get_option('cashback_dedup_source_drift'));
    }

    public function test_step2_does_not_flag_synthetic_network(): void
    {
        $synthetic = (string) wp_json_encode(array('has_native_action_id' => false));
        $this->call_v18($this->make_wpdb_mock(col_exists: true, rows: array(
            array(
                'slug'          => 'directpx',
                'id'            => 53,
                'dedup_identity' => $synthetic,
                'api_field_map'  => (string) wp_json_encode(array('order_id' => 'uniq_id')),
            ),
        )));
        $this->assertFalse((bool) get_option('cashback_dedup_source_drift'), 'synthetic networks have no native source-drift');
    }

    // ---- structural: save-gate, single code-path, notice, runner wiring ----

    public function test_save_gate_present_with_narrow_trigger(): void
    {
        $src = file_get_contents(self::$plugin_root . '/admin/class-cashback-admin-api-validation.php');
        $this->assertIsString($src);
        $this->assertStringContainsString('S2 enforced source-field guard', $src);
        $this->assertStringContainsString('$explicit_uniq_remap', $src, 'gate fires only on explicit uniq_id remap');
        // B-3: real remap = uniq_id source key (array_flip last-wins, MUST
        // mirror runtime api_field_for) that is NOT the default action_id.
        $this->assertStringContainsString("array_flip(\$fm_strvals)['uniq_id'] ?? false", $src);
        $this->assertStringContainsString("(string) \$uniq_src_key !== 'action_id'", $src);
        $this->assertStringNotContainsString("array_search('uniq_id'", $src, 'B-3: array_search first-key is unsafe vs runtime array_flip');
        $this->assertStringContainsString("receiver_uniq_source", $src);
        // B-1: fail-CLOSED on transient DB read error (source-drift double-credit
        // is NOT caught by idempotency_key UNIQUE).
        $this->assertStringContainsString('fail-closed', $src);
        $this->assertStringNotContainsString('fail-open', $src, 'B-1: fail-open removed');
        // gate must run BEFORE the network row UPDATE (it is inserted
        // immediately above the next $wpdb->update() call).
        $gate = strpos($src, 'S2 enforced source-field guard');
        $this->assertIsInt($gate);
        $upd = strpos($src, '$wpdb->update(', $gate);
        $this->assertIsInt($upd, 'a $wpdb->update() must follow the guard');
        $this->assertStringContainsString(
            'cashback_affiliate_networks',
            substr($src, $upd, 120),
            'the UPDATE right after the guard is the networks-row write'
        );
    }

    public function test_admin_manual_insert_routes_through_universal_resolver(): void
    {
        $src = file_get_contents(self::$plugin_root . '/admin/class-cashback-admin-api-validation.php');
        $this->assertIsString($src);
        $this->assertStringContainsString('Cashback_API_Client::resolve_uniq_id(', $src);
        $this->assertStringContainsString('$action_id = $resolved_action_id;', $src);
        // resolver call must precede idempotency_key derivation (single code-path).
        $resolve = strpos($src, '[$resolved_action_id, $resolve_reason] = Cashback_API_Client::resolve_uniq_id(');
        $idem    = strpos($src, "\$idempotency_key = hash('sha256', strtolower(\$network) . '|' . \$action_id);");
        $this->assertIsInt($resolve);
        $this->assertIsInt($idem);
        $this->assertLessThan($idem, $resolve, 'resolver output must feed idempotency_key');
    }

    public function test_drift_notice_registered_and_reads_option(): void
    {
        $src = file_get_contents(self::$plugin_root . '/cashback-plugin.php');
        $this->assertIsString($src);
        $this->assertStringContainsString(
            "add_action('admin_notices', array( 'CashbackPlugin', 'dedup_source_drift_notice' ));",
            $src
        );
        $this->assertStringContainsString('public static function dedup_source_drift_notice(): void', $src);
        $this->assertStringContainsString("get_option('cashback_dedup_source_drift')", $src);
    }

    public function test_runner_and_init_hook_wire_v18_after_v17(): void
    {
        $maria = file_get_contents(self::$plugin_root . '/mariadb.php');
        $plug  = file_get_contents(self::$plugin_root . '/cashback-plugin.php');

        $m17 = strpos($maria, '$instance->migrate_dedup_identity_backfill_v17();');
        $m18 = strpos($maria, '$instance->migrate_dedup_source_consistency_v18();');
        $obend = strpos($maria, 'ob_end_clean();');
        $this->assertIsInt($m17);
        $this->assertIsInt($m18, 'activation runner must wire v18');
        $this->assertGreaterThan($m17, $m18, 'v18 after v17 in runner');
        $this->assertGreaterThan($m18, $obend, 'v18 invoked before ob_end_clean');

        $p17 = strpos($plug, 'migrate_dedup_identity_backfill_v17();');
        $p18 = strpos($plug, 'migrate_dedup_source_consistency_v18();');
        $this->assertIsInt($p17);
        $this->assertIsInt($p18, 'init-hook must auto-fire v18 (zero-downtime)');
        $this->assertGreaterThan($p17, $p18, 'init-hook v18 after v17 (same guarded try)');
    }
}
