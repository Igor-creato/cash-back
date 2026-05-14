<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты на Cashback_Promocodes_Repository.
 *
 * Покрывает:
 *   - upsert_for_campaign: INSERT новых + UPDATE существующих через
 *     INSERT ... ON DUPLICATE KEY UPDATE по (network_id, external_id).
 *   - deactivate_missing: купоны которых нет в seen_external_ids → is_active=0.
 *   - get_active_for_campaign: фильтр active + dates валидны + RU в regions.
 *
 * @group promocodes
 * @group repository
 */
#[Group('promocodes')]
#[Group('repository')]
final class PromocodesRepositoryTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
        $files = array(
            '/includes/promocodes/dto/class-coupon-dto.php',
            '/includes/promocodes/class-cashback-promocodes-repository.php',
        );
        foreach ($files as $f) {
            $path = self::$plugin_root . $f;
            if (!file_exists($path)) {
                self::markTestSkipped("File missing: {$f}");
            }
            require_once $path;
        }
    }

    private function make_wpdb_mock(): object
    {
        return new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            /** @var array<int,array{type:string,sql:string,bindings:array}> */
            public array $queries = array();
            /** @var array<int,array{table:string,data:array,format:mixed}> */
            public array $inserts = array();
            /** @var array<int,array> */
            public array $get_results_response = array();

            public function prepare( string $query, mixed ...$args ): string
            {
                $i = 0;
                $rendered = preg_replace_callback('/%[sid]/', function ($m) use (&$i, $args) {
                    $val = $args[$i++] ?? '';
                    if ($m[0] === '%s') return "'" . addslashes((string) $val) . "'";
                    if ($m[0] === '%i') return '`' . str_replace('`', '', (string) $val) . '`';
                    return (string) (int) $val;
                }, $query);
                $this->queries[] = array( 'type' => 'prepare', 'sql' => $rendered, 'bindings' => $args );
                return $rendered;
            }

            public function query( string $query ): int|false
            {
                $this->queries[] = array( 'type' => 'query', 'sql' => $query, 'bindings' => array() );
                return 1;
            }

            /**
             * v4.3.4: upsert_for_campaign теперь использует SELECT GET_LOCK/RELEASE_LOCK
             * через get_var. По умолчанию stub возвращает 1 (lock acquired) — тесты,
             * не моделирующие конкуренцию, проходят обычным flow.
             */
            public function get_var( string $query ): mixed
            {
                if (stripos($query, 'GET_LOCK(') !== false || stripos($query, 'RELEASE_LOCK(') !== false) {
                    return 1;
                }
                return null;
            }

            public function insert( string $table, array $data, mixed $format = null ): int|false
            {
                $this->inserts[] = array( 'table' => $table, 'data' => $data, 'format' => $format );
                return 1;
            }

            public function get_results( string $query, string $output = 'OBJECT' ): array
            {
                return $this->get_results_response;
            }
        };
    }

    private function valid_dto(array $overrides = []): Cashback_Coupon_DTO
    {
        $payload = array_merge(array(
            'external_id' => 'C1',
            'species'     => 'promocode',
            'promocode'   => 'SAVE10',
            'name'        => 'Тест',
            'goto_link'   => 'https://example.com/go',
            'date_start'  => '2026-01-01 00:00:00',
            'date_end'    => '2026-12-31 23:59:59',
            'regions'     => 'RU',
        ), $overrides);
        return Cashback_Coupon_DTO::from_array($payload);
    }

    public function test_upsert_executes_insert_on_duplicate_key_for_each_coupon(): void
    {
        $wpdb = $this->make_wpdb_mock();
        $GLOBALS['wpdb'] = $wpdb;

        $repo    = new Cashback_Promocodes_Repository();
        $coupons = array(
            $this->valid_dto(array( 'external_id' => 'A1' )),
            $this->valid_dto(array( 'external_id' => 'A2' )),
            $this->valid_dto(array( 'external_id' => 'A3' )),
        );

        $repo->upsert_for_campaign(1, '35530', $coupons);

        // Каждый купон → INSERT ... ON DUPLICATE KEY UPDATE.
        $insert_sqls = array_filter($wpdb->queries, fn($q) => str_contains(strtoupper($q['sql']), 'ON DUPLICATE KEY UPDATE'));
        $this->assertGreaterThanOrEqual(3, count($insert_sqls), 'INSERT ... ON DUPLICATE KEY UPDATE для каждого купона');
    }

    public function test_upsert_deactivates_missing_coupons(): void
    {
        $wpdb = $this->make_wpdb_mock();
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new Cashback_Promocodes_Repository();
        $repo->upsert_for_campaign(
            1,
            '35530',
            array(
                $this->valid_dto(array( 'external_id' => 'KEEP1' )),
                $this->valid_dto(array( 'external_id' => 'KEEP2' )),
            )
        );

        // Должен быть UPDATE с is_active=0 WHERE external_id NOT IN (KEEP1, KEEP2).
        $deactivate_sqls = array_filter($wpdb->queries, fn($q) =>
            str_contains(strtoupper($q['sql']), 'IS_ACTIVE')
            && (str_contains(strtoupper($q['sql']), 'NOT IN') || str_contains(strtoupper($q['sql']), 'UPDATE'))
        );
        $this->assertNotEmpty($deactivate_sqls, 'Должен быть deactivate_missing UPDATE');
    }

    public function test_get_active_filters_by_active_dates_and_ru_region(): void
    {
        $wpdb = $this->make_wpdb_mock();
        $wpdb->get_results_response = array();
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new Cashback_Promocodes_Repository();
        $repo->get_active_for_campaign(1, '35530');

        $select_sqls = array_filter($wpdb->queries, fn($q) => str_contains(strtoupper($q['sql']), 'SELECT'));
        $this->assertNotEmpty($select_sqls);

        // Объединить SQL для проверки.
        $all_sql = strtolower(implode(' ', array_map(fn($q) => $q['sql'], $select_sqls)));

        $this->assertStringContainsString('is_active', $all_sql, 'Фильтр is_active');
        $this->assertStringContainsString('find_in_set', $all_sql, 'Фильтр FIND_IN_SET (RU)');
        $this->assertStringContainsString('date_end', $all_sql, 'Фильтр по date_end');
    }

    public function test_get_active_respects_limit_with_hard_cap(): void
    {
        $wpdb = $this->make_wpdb_mock();
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new Cashback_Promocodes_Repository();
        // Передаём огромный limit — должен срезаться до hard-cap (~100).
        $repo->get_active_for_campaign(1, '35530', array( 'limit' => 999999 ));

        $select_sqls = array_filter($wpdb->queries, fn($q) => str_contains(strtoupper($q['sql']), 'SELECT'));
        $all_sql = implode(' ', array_map(fn($q) => $q['sql'], $select_sqls));
        // hard-cap должен быть применён.
        $this->assertMatchesRegularExpression('/LIMIT\s+\d{1,3}\b/i', $all_sql);
        $this->assertDoesNotMatchRegularExpression('/LIMIT\s+999999/', $all_sql);
    }

    public function test_get_active_filters_species_when_provided(): void
    {
        $wpdb = $this->make_wpdb_mock();
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new Cashback_Promocodes_Repository();
        $repo->get_active_for_campaign(1, '35530', array( 'species' => array( 'promocode', 'deal' ) ));

        $select_sqls = array_filter($wpdb->queries, fn($q) => str_contains(strtoupper($q['sql']), 'SELECT'));
        $all_sql = strtolower(implode(' ', array_map(fn($q) => $q['sql'], $select_sqls)));

        $this->assertStringContainsString("species", $all_sql);
        $this->assertTrue(
            str_contains($all_sql, 'promocode') && str_contains($all_sql, 'deal'),
            'WHERE species IN (...) должен содержать переданные значения'
        );
    }

    public function test_upsert_returns_summary_counts(): void
    {
        $wpdb = $this->make_wpdb_mock();
        $GLOBALS['wpdb'] = $wpdb;

        $repo   = new Cashback_Promocodes_Repository();
        $result = $repo->upsert_for_campaign(1, '35530', array(
            $this->valid_dto(array( 'external_id' => 'X1' )),
            $this->valid_dto(array( 'external_id' => 'X2' )),
        ));

        $this->assertIsArray($result);
        $this->assertArrayHasKey('upserted', $result);
        $this->assertArrayHasKey('deactivated', $result);
        $this->assertSame(2, $result['upserted']);
    }

    public function test_upsert_with_empty_coupons_only_deactivates(): void
    {
        $wpdb = $this->make_wpdb_mock();
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new Cashback_Promocodes_Repository();
        $result = $repo->upsert_for_campaign(1, '35530', array());

        $this->assertSame(0, $result['upserted']);
        // С пустым набором seen_external_ids — все купоны кампании в БД deactivated.
        $deactivate_calls = array_filter($wpdb->queries, fn($q) =>
            str_contains(strtoupper($q['sql']), 'UPDATE') && str_contains(strtoupper($q['sql']), 'IS_ACTIVE')
        );
        $this->assertNotEmpty($deactivate_calls);
    }

    public function test_get_distinct_species_returns_empty_for_invalid_args(): void
    {
        $wpdb            = $this->make_wpdb_mock();
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new Cashback_Promocodes_Repository();

        $this->assertSame(array(), $repo->get_distinct_species_for_campaign(0, '35530'));
        $this->assertSame(array(), $repo->get_distinct_species_for_campaign(-5, '35530'));
        $this->assertSame(array(), $repo->get_distinct_species_for_campaign(1, ''));

        // Не должно быть SQL-вызовов: ранний return.
        $this->assertEmpty($wpdb->queries, 'Не должно быть запросов при некорректных аргументах');
    }

    public function test_get_distinct_species_groups_by_species_with_filters(): void
    {
        $wpdb                          = $this->make_wpdb_mock();
        $wpdb->get_results_response = array(
            array( 'species' => 'promocode', 'name' => 'Промо',  'description' => 'д' ),
            array( 'species' => 'gift',      'name' => 'Подарок','description' => '' ),
        );
        $GLOBALS['wpdb']               = $wpdb;

        $repo = new Cashback_Promocodes_Repository();
        $rows = $repo->get_distinct_species_for_campaign(1, '35530');

        $this->assertCount(2, $rows);
        $this->assertSame('promocode', $rows[0]['species']);
        $this->assertSame('gift',      $rows[1]['species']);

        $select_sqls = array_filter($wpdb->queries, fn($q) => str_contains(strtoupper($q['sql']), 'SELECT'));
        $all_sql     = strtolower(implode(' ', array_map(fn($q) => $q['sql'], $select_sqls)));

        $this->assertStringContainsString('group by species', $all_sql, 'GROUP BY species обязателен');
        $this->assertStringContainsString('is_active', $all_sql, 'Фильтр is_active');
        $this->assertStringContainsString('find_in_set', $all_sql, 'RU-фильтр через FIND_IN_SET');
        $this->assertStringContainsString('date_start', $all_sql, 'Фильтр по date_start');
        $this->assertStringContainsString('date_end',   $all_sql, 'Фильтр по date_end');
    }
}
