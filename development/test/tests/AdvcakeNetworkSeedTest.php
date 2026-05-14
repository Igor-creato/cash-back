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

        // INSERT для advcake row должен быть вызван.
        $this->assertCount(1, $wpdb->insert_calls);
        $insert = $wpdb->insert_calls[0];
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
        $this->assertEmpty($wpdb->insert_calls, 'Существующая advcake-row не должна перезаписываться');
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
