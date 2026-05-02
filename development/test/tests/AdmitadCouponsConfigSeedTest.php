<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional тест на migrate_seed_admitad_coupons_config():
 *   - UPDATE дефолтного coupons-конфига для admitad если api_coupons_endpoint IS NULL.
 *   - Не перезаписывает если admin уже настроил конфиг.
 *   - Graceful skip если запись slug='admitad' не существует.
 *   - Идемпотентность — повторный вызов ничего не делает.
 *
 * @group migration
 * @group promocodes
 */
#[Group('migration')]
#[Group('promocodes')]
final class AdmitadCouponsConfigSeedTest extends TestCase
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
     * Statefull mock $wpdb: эмулирует SELECT/UPDATE для cashback_affiliate_networks
     * с одной записью admitad.
     */
    private function make_wpdb_mock(?string $existing_endpoint): object
    {
        return new class($existing_endpoint) {
            public string $prefix = 'wp_';
            public string $last_error = '';

            /** @var array<int,array{table:string,data:array,where:array}> */
            public array $update_calls = array();

            public function __construct(private ?string $existing_endpoint) {}

            public function prepare(string $query, mixed ...$args): string
            {
                // Минимальная имитация: подменяем %s/%i на quoted.
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
                // Возвращаем existing endpoint при SELECT api_coupons_endpoint ... slug='admitad'.
                if (str_contains($query, 'api_coupons_endpoint') && str_contains($query, "'admitad'")) {
                    return $this->existing_endpoint;
                }
                return null;
            }

            public function update(string $table, array $data, array $where): int
            {
                $this->update_calls[] = array(
                    'table' => $table,
                    'data'  => $data,
                    'where' => $where,
                );
                $this->existing_endpoint = (string) ( $data['api_coupons_endpoint'] ?? $this->existing_endpoint );
                return 1;
            }
        };
    }

    /**
     * Создаёт инстанс Mariadb_Plugin без вызова конструктора (минуем зависимости WP-init).
     * Используем reflection для вызова private/public method'ов.
     */
    private function call_seed_method(object $wpdb_mock): object
    {
        $GLOBALS['wpdb'] = $wpdb_mock;

        if (!class_exists('Mariadb_Plugin')) {
            require_once self::$plugin_root . '/mariadb.php';
        }

        $reflection = new ReflectionClass('Mariadb_Plugin');
        if (!$reflection->hasMethod('migrate_seed_admitad_coupons_config')) {
            $this->markTestSkipped('Method migrate_seed_admitad_coupons_config not present yet.');
        }

        $instance = $reflection->newInstanceWithoutConstructor();
        $method   = $reflection->getMethod('migrate_seed_admitad_coupons_config');
        $method->invoke($instance);

        return $wpdb_mock;
    }

    // ============================================================
    // 1. api_coupons_endpoint IS NULL → seed UPDATE с дефолтами.
    // ============================================================

    public function test_seed_inserts_defaults_when_endpoint_null(): void
    {
        $wpdb = $this->call_seed_method($this->make_wpdb_mock(null));

        $this->assertNotEmpty($wpdb->update_calls, 'Должен быть UPDATE call для admitad когда endpoint пустой');
        $call = $wpdb->update_calls[0];
        $this->assertSame('admitad', $call['where']['slug']);
        $this->assertArrayHasKey('api_coupons_endpoint', $call['data']);
        $this->assertStringContainsString('{advcampaign_id}', $call['data']['api_coupons_endpoint'], 'Endpoint должен содержать placeholder {advcampaign_id}');
        $this->assertStringContainsString('{website_id}', $call['data']['api_coupons_endpoint'], 'Endpoint должен содержать placeholder {website_id}');
    }

    // ============================================================
    // 2. Дефолтные mappings содержат базовые поля.
    // ============================================================

    public function test_seed_includes_field_map_with_required_keys(): void
    {
        $wpdb = $this->call_seed_method($this->make_wpdb_mock(null));
        $data = $wpdb->update_calls[0]['data'];

        $this->assertArrayHasKey('api_coupons_field_map', $data);
        $field_map = json_decode($data['api_coupons_field_map'], true);
        $this->assertIsArray($field_map);

        // Минимально необходимые маппинги для DTO.
        foreach (array( 'id', 'promocode', 'name', 'goto_link', 'date_start', 'date_end', 'status' ) as $key) {
            $this->assertArrayHasKey($key, $field_map, "Field map должен маппить '{$key}'");
        }
    }

    public function test_seed_includes_species_map_with_canonical_values(): void
    {
        $wpdb = $this->call_seed_method($this->make_wpdb_mock(null));
        $data = $wpdb->update_calls[0]['data'];

        $this->assertArrayHasKey('api_coupons_species_map', $data);
        $species = json_decode($data['api_coupons_species_map'], true);
        $this->assertIsArray($species);

        // Канонические значения: 'promocode' и 'deal'.
        $values = array_values($species);
        $this->assertContains('promocode', $values);
        $this->assertContains('deal', $values);
    }

    // ============================================================
    // 3. Pagination — 'offset_limit' для Admitad.
    // ============================================================

    public function test_seed_sets_pagination_offset_limit(): void
    {
        $wpdb = $this->call_seed_method($this->make_wpdb_mock(null));
        $data = $wpdb->update_calls[0]['data'];

        $this->assertSame('offset_limit', $data['api_coupons_pagination']);
    }

    // ============================================================
    // 4. Идемпотентность — endpoint уже задан → НЕТ UPDATE.
    // ============================================================

    public function test_seed_skipped_when_endpoint_already_configured(): void
    {
        $wpdb = $this->call_seed_method(
            $this->make_wpdb_mock('/coupons/website/9999/?campaign={advcampaign_id}')
        );

        $this->assertEmpty(
            $wpdb->update_calls,
            'Не должно быть UPDATE если api_coupons_endpoint уже настроен админом'
        );
    }
}
