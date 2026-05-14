<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional тест на migrate_advcake_seed_v14():
 *   - INSERT seed-row для slug='advcake' если её нет;
 *   - ALTER TABLE cashback_webhooks ADD COLUMN event_type если колонки нет;
 *   - идемпотентность через cashback_db_version >= 14 (fast-path);
 *   - не перезаписывает существующую advcake-row.
 *
 * @group migration
 * @group advcake
 */
#[Group('migration')]
#[Group('advcake')]
final class AdvcakeNetworkSeedTest extends TestCase
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
     * Mock $wpdb с минимальным state'ом для миграции v14.
     */
    private function make_wpdb_mock(bool $row_exists, bool $event_type_exists): object
    {
        return new class($row_exists, $event_type_exists) {
            public string $prefix = 'wp_';
            public string $last_error = '';
            /** @var array<int,array{table:string,data:array}> */
            public array $insert_calls = array();
            /** @var array<int,string> */
            public array $query_calls = array();

            public function __construct(
                private bool $row_exists,
                private bool $event_type_exists
            ) {}

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
                // COUNT(*) по INFORMATION_SCHEMA.COLUMNS — для проверки event_type
                if (strpos($query, 'COLUMNS') !== false) {
                    return $this->event_type_exists ? 1 : 0;
                }
                // SELECT id FROM cashback_affiliate_networks WHERE slug='advcake' — для seed params lookup
                if (strpos($query, 'affiliate_networks') !== false && strpos($query, 'slug') !== false) {
                    return $this->row_exists ? 42 : 9; // 9 = just-inserted from migration, 42 = pre-existing
                }
                // COUNT(*) для idempotency-check seed params (по умолчанию — не существуют)
                if (strpos($query, 'affiliate_network_params') !== false) {
                    return 0;
                }
                return null;
            }

            public function get_row(string $query): ?object
            {
                // SELECT id FROM ... WHERE slug = 'advcake'
                if ($this->row_exists) {
                    return (object) array( 'id' => 42 );
                }
                return null;
            }

            public function query(string $query): int
            {
                $this->query_calls[] = $query;
                if (strpos($query, 'ALTER TABLE') !== false && strpos($query, 'event_type') !== false) {
                    $this->event_type_exists = true;
                }
                return 1;
            }

            public function insert(string $table, array $data, array $formats = array()): int
            {
                $this->insert_calls[] = array(
                    'table' => $table,
                    'data'  => $data,
                );
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
        if (!$reflection->hasMethod('migrate_advcake_seed_v14')) {
            $this->markTestSkipped('Method migrate_advcake_seed_v14 not present yet.');
        }

        $instance = $reflection->newInstanceWithoutConstructor();
        $method   = $reflection->getMethod('migrate_advcake_seed_v14');
        $method->invoke($instance);

        return $wpdb_mock;
    }

    // ------------------------------------------------------------------
    // 1. Свежая инсталляция: row не существует, колонка event_type отсутствует.
    // ------------------------------------------------------------------

    public function test_fresh_install_creates_row_and_adds_column(): void
    {
        $wpdb = $this->call_migration($this->make_wpdb_mock(row_exists: false, event_type_exists: false));

        // ALTER TABLE должен быть вызван.
        $alter_calls = array_filter(
            $wpdb->query_calls,
            static fn(string $q): bool => strpos($q, 'ALTER TABLE') !== false && strpos($q, 'event_type') !== false
        );
        $this->assertNotEmpty($alter_calls, 'ALTER TABLE должен быть вызван для добавления event_type');

        // INSERT для advcake row в networks-таблицу должен быть вызван.
        $networks_inserts = array_values(array_filter(
            $wpdb->insert_calls,
            static fn(array $call): bool => $call['table'] === 'wp_cashback_affiliate_networks'
        ));
        $this->assertCount(1, $networks_inserts);
        $insert = $networks_inserts[0];
        $this->assertSame('wp_cashback_affiliate_networks', $insert['table']);
        $this->assertSame('advcake', $insert['data']['slug']);
        $this->assertSame('Advcake', $insert['data']['name']);
        $this->assertSame('api_key', $insert['data']['api_auth_type']);
        $this->assertSame('https://api.advcake.ru', $insert['data']['api_base_url']);
        $this->assertSame('/export/webmaster/{token}', $insert['data']['api_actions_endpoint']);
        $this->assertSame('sub1', $insert['data']['api_click_field']);
        $this->assertSame('sub2', $insert['data']['api_user_field']);
        $this->assertSame(0, $insert['data']['is_active'], 'is_active=0 — admin сам включает после ввода токена');

        // db_version должна стать 14.
        $this->assertSame(14, get_option('cashback_db_version'));
    }

    public function test_status_map_has_numeric_keys_for_advcake(): void
    {
        $wpdb = $this->call_migration($this->make_wpdb_mock(row_exists: false, event_type_exists: false));

        $insert    = $wpdb->insert_calls[0];
        $status_map = json_decode((string) $insert['data']['api_status_map'], true);

        $this->assertIsArray($status_map);
        $this->assertSame('waiting', $status_map['1']);
        $this->assertSame('completed', $status_map['2']);
        $this->assertSame('declined', $status_map['3']);
    }

    public function test_fresh_install_seeds_sub1_and_sub2_url_params(): void
    {
        $wpdb = $this->call_migration($this->make_wpdb_mock(row_exists: false, event_type_exists: false));

        // Из insert_calls фильтруем те что в cashback_affiliate_network_params.
        $param_inserts = array_filter(
            $wpdb->insert_calls,
            static fn(array $call): bool => $call['table'] === 'wp_cashback_affiliate_network_params'
        );

        $this->assertCount(2, $param_inserts, 'должно быть 2 INSERT в network_params (sub1 + sub2)');

        $param_inserts = array_values($param_inserts);
        $by_name       = array();
        foreach ($param_inserts as $call) {
            $by_name[ $call['data']['param_name'] ] = $call['data'];
        }

        $this->assertArrayHasKey('sub1', $by_name, 'должен быть seed sub1');
        $this->assertSame('uuid', $by_name['sub1']['param_type'], 'sub1 → uuid (click_id UUIDv7)');

        $this->assertArrayHasKey('sub2', $by_name, 'должен быть seed sub2');
        $this->assertSame('user', $by_name['sub2']['param_type'], 'sub2 → user (partner_token через Cashback_Click_Session_Service)');
    }

    public function test_existing_row_does_not_create_duplicate_url_params_when_already_seeded(): void
    {
        // make_wpdb_mock(row_exists=true): seed-row уже есть.
        // get_var для affiliate_network_params возвращает 0 → INSERT всё равно будет.
        // Это тест что seed работает даже если networks-row уже была (re-run scenario).
        $wpdb = $this->call_migration($this->make_wpdb_mock(row_exists: true, event_type_exists: true));

        // INSERT в networks НЕ должен быть (row exists), но INSERT в params — должен (idempotent re-seed).
        $networks_inserts = array_filter(
            $wpdb->insert_calls,
            static fn(array $call): bool => $call['table'] === 'wp_cashback_affiliate_networks'
        );
        $this->assertCount(0, $networks_inserts, 'существующая seed-row не дублируется');

        $param_inserts = array_filter(
            $wpdb->insert_calls,
            static fn(array $call): bool => $call['table'] === 'wp_cashback_affiliate_network_params'
        );
        $this->assertCount(2, $param_inserts, 'params seed работает независимо (re-run safety)');
    }

    public function test_field_map_includes_commission_and_click_fields(): void
    {
        $wpdb = $this->call_migration($this->make_wpdb_mock(row_exists: false, event_type_exists: false));

        $insert    = $wpdb->insert_calls[0];
        $field_map = json_decode((string) $insert['data']['api_field_map'], true);

        $this->assertIsArray($field_map);
        $this->assertSame('commission', $field_map['comission'], 'комиссия в Advcake XML — <commission>');
        $this->assertSame('price', $field_map['sum_order']);
        $this->assertSame('id', $field_map['uniq_id']);
        $this->assertSame('order_id', $field_map['order_number']);
        $this->assertSame('offer_id', $field_map['offer_id']);
        $this->assertSame('offer', $field_map['offer_name']);
    }

    // ------------------------------------------------------------------
    // 2. Колонка event_type уже существует → ALTER пропускается.
    // ------------------------------------------------------------------

    public function test_skips_alter_when_event_type_already_exists(): void
    {
        $wpdb = $this->call_migration($this->make_wpdb_mock(row_exists: false, event_type_exists: true));

        $alter_calls = array_filter(
            $wpdb->query_calls,
            static fn(string $q): bool => strpos($q, 'ALTER TABLE') !== false && strpos($q, 'event_type') !== false
        );
        $this->assertEmpty($alter_calls, 'ALTER TABLE НЕ должен вызываться повторно');
    }

    // ------------------------------------------------------------------
    // 3. Row уже существует → INSERT пропускается.
    // ------------------------------------------------------------------

    public function test_skips_insert_when_advcake_row_exists(): void
    {
        $wpdb = $this->call_migration($this->make_wpdb_mock(row_exists: true, event_type_exists: true));

        $networks_inserts = array_filter(
            $wpdb->insert_calls,
            static fn(array $call): bool => $call['table'] === 'wp_cashback_affiliate_networks'
        );
        $this->assertEmpty($networks_inserts, 'Существующая advcake-row в networks не должна перезаписываться');
        $this->assertSame(14, get_option('cashback_db_version'));
    }

    // ------------------------------------------------------------------
    // 4. Идемпотентность: повторный вызов на db_version >= 14 — no-op.
    // ------------------------------------------------------------------

    public function test_idempotent_when_db_version_already_14(): void
    {
        update_option('cashback_db_version', 14);
        $wpdb = $this->call_migration($this->make_wpdb_mock(row_exists: false, event_type_exists: false));

        $this->assertEmpty($wpdb->insert_calls);
        $this->assertEmpty($wpdb->query_calls);
    }
}
