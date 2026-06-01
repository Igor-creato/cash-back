<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Регрессионные тесты для null-rate sentinel в `Cashback_Shop_Rate_Of_Approve_Refresher`.
 *
 * Контекст: до 4.4.31 при `success=true + campaign=null` (типично 404 от
 * Admitad) `save_rate_for_product()` удалял `_cashback_rate_of_approve_fetched_at`,
 * и cron возвращал такой товар в выборку каждые 2 часа — бесконечный цикл
 * бесполезных запросов. Фикс: сохраняем `fetched_at = time()` даже при null,
 * а `query_product_ids_for_network()` исключает товары без rate в окне cooldown.
 *
 * @group shops
 * @group rate-of-approve
 */
#[Group('shops')]
#[Group('rate-of-approve')]
final class RateOfApproveRefresherSaveSentinelTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
        foreach (array(
            '/includes/shops/class-cashback-shop-importer.php',
            '/includes/shops/class-cashback-shop-rate-of-approve-refresher.php',
        ) as $rel) {
            $path = self::$plugin_root . $rel;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_post_meta'] = array();
        $GLOBALS['_cb_test_filters']   = array();
        unset($GLOBALS['wpdb']);
        unset($GLOBALS['_cb_test_wpdb_last_prepare_args'], $GLOBALS['_cb_test_wpdb_last_prepare_sql']);
    }

    protected function tearDown(): void
    {
        $GLOBALS['_cb_test_post_meta'] = array();
        $GLOBALS['_cb_test_filters']   = array();
        unset($GLOBALS['wpdb']);
        unset($GLOBALS['_cb_test_wpdb_last_prepare_args'], $GLOBALS['_cb_test_wpdb_last_prepare_sql']);
    }

    /**
     * Главный регрессионный тест: при rate=null НЕ удаляем fetched_at —
     * наоборот, ставим time() (sentinel факта проверки). rate и source
     * удаляются, UI всё равно покажет «нет данных от сети».
     */
    public function test_null_rate_stores_sentinel_timestamp_without_rate_and_source(): void
    {
        // Засеиваем «старые» данные, чтобы убедиться что rate и source реально удаляются.
        update_post_meta(4203, '_cashback_rate_of_approve', '65.5');
        update_post_meta(4203, '_cashback_rate_of_approve_source', 'adm');
        update_post_meta(4203, '_cashback_rate_of_approve_fetched_at', '1700000000');

        $before = time();
        $this->invoke_save(4203, null, 'adm');
        $after  = time();

        $rate       = get_post_meta(4203, '_cashback_rate_of_approve', true);
        $source     = get_post_meta(4203, '_cashback_rate_of_approve_source', true);
        $fetched_at = (int) get_post_meta(4203, '_cashback_rate_of_approve_fetched_at', true);

        $this->assertSame('', $rate, 'rate должен быть удалён при null-результате');
        $this->assertSame('', $source, 'source должен быть удалён при null-результате');
        $this->assertGreaterThanOrEqual($before, $fetched_at, 'fetched_at должен быть установлен в time() (sentinel)');
        $this->assertLessThanOrEqual($after, $fetched_at, 'fetched_at не должен уходить в будущее');
    }

    /**
     * Регрессия для нормального прохода: rate=число → пишется значение + текущий timestamp.
     */
    public function test_valid_rate_persists_rate_and_timestamp(): void
    {
        $before = time();
        $this->invoke_save(4191, 73.456, 'adm');
        $after  = time();

        $this->assertSame('73.46', get_post_meta(4191, '_cashback_rate_of_approve', true));
        $this->assertSame('adm', get_post_meta(4191, '_cashback_rate_of_approve_source', true));
        $fetched_at = (int) get_post_meta(4191, '_cashback_rate_of_approve_fetched_at', true);
        $this->assertGreaterThanOrEqual($before, $fetched_at);
        $this->assertLessThanOrEqual($after, $fetched_at);
    }

    /**
     * Non-numeric rate тоже считается «нет данных» — sentinel-запись.
     */
    public function test_non_numeric_rate_writes_sentinel_like_null(): void
    {
        $before = time();
        $this->invoke_save(4191, 'not-a-number', 'adm');
        $after  = time();

        $this->assertSame('', get_post_meta(4191, '_cashback_rate_of_approve', true));
        $fetched_at = (int) get_post_meta(4191, '_cashback_rate_of_approve_fetched_at', true);
        $this->assertGreaterThanOrEqual($before, $fetched_at);
        $this->assertLessThanOrEqual($after, $fetched_at);
    }

    /**
     * Дефолтный cooldown (7 дней) попадает в prepare() как time() - 604800.
     */
    public function test_query_passes_default_cooldown_threshold(): void
    {
        $this->install_wpdb_stub();
        $before = time();
        $this->invoke_query(network_id: 1, cycle_started_at: $before, limit: 30);
        $after  = time();

        $args = $GLOBALS['_cb_test_wpdb_last_prepare_args'] ?? null;
        $this->assertIsArray($args, 'wpdb->prepare должен быть вызван');
        // Сигнатура: [meta_network_id, meta_offer_id, meta_fetched_at, meta_rate,
        //             network_id, cycle_started_at, stale_threshold, cooldown_threshold, limit].
        $this->assertCount(9, $args, 'prepare() должен получить stale + cooldown thresholds');
        $stale_threshold = (int) $args[6];
        $this->assertGreaterThanOrEqual($before - DAY_IN_SECONDS, $stale_threshold);
        $this->assertLessThanOrEqual($after - DAY_IN_SECONDS, $stale_threshold);
        $cooldown_threshold = (int) $args[7];
        $this->assertGreaterThanOrEqual($before - 604800, $cooldown_threshold);
        $this->assertLessThanOrEqual($after - 604800, $cooldown_threshold);
        $this->assertSame(30, (int) $args[8], 'limit передаётся последним');
    }

    public function test_query_respects_stale_after_filter(): void
    {
        add_filter('cashback_shop_rate_of_approve_stale_after_seconds', static fn() => 3600);

        $this->install_wpdb_stub();
        $before = time();
        $this->invoke_query(network_id: 1, cycle_started_at: $before, limit: 30);
        $after  = time();

        $args = $GLOBALS['_cb_test_wpdb_last_prepare_args'] ?? null;
        $this->assertIsArray($args);
        $stale_threshold = (int) $args[6];
        $this->assertGreaterThanOrEqual($before - 3600, $stale_threshold);
        $this->assertLessThanOrEqual($after - 3600, $stale_threshold);
    }

    /**
     * Filter `cashback_shop_rate_of_approve_null_cooldown_seconds` снижает cooldown.
     * Используется в этом тесте для быстрого прогона; в продакшене filter
     * нужен для редких кейсов (например, форсировать ретест 404-кампаний).
     */
    public function test_query_respects_cooldown_filter(): void
    {
        add_filter('cashback_shop_rate_of_approve_null_cooldown_seconds', static fn() => 60);

        $this->install_wpdb_stub();
        $before = time();
        $this->invoke_query(network_id: 1, cycle_started_at: $before, limit: 30);
        $after  = time();

        $args = $GLOBALS['_cb_test_wpdb_last_prepare_args'] ?? null;
        $this->assertIsArray($args);
        $cooldown_threshold = (int) $args[7];
        $this->assertGreaterThanOrEqual($before - 60, $cooldown_threshold);
        $this->assertLessThanOrEqual($after - 60, $cooldown_threshold);
    }

    /**
     * Negative cooldown через filter → защита fall-back на дефолт 7 дней.
     * Нужна, чтобы кривая интеграция не открыла infinite-loop обратно.
     */
    public function test_query_negative_cooldown_falls_back_to_default(): void
    {
        add_filter('cashback_shop_rate_of_approve_null_cooldown_seconds', static fn() => -1);

        $this->install_wpdb_stub();
        $before = time();
        $this->invoke_query(network_id: 1, cycle_started_at: $before, limit: 30);
        $after  = time();

        $args = $GLOBALS['_cb_test_wpdb_last_prepare_args'] ?? null;
        $this->assertIsArray($args);
        $cooldown_threshold = (int) $args[7];
        $this->assertGreaterThanOrEqual($before - 604800, $cooldown_threshold);
        $this->assertLessThanOrEqual($after - 604800, $cooldown_threshold);
    }

    /** Constant exists и равна 7 дням — guard от случайной правки. */
    public function test_cooldown_constant_is_seven_days(): void
    {
        $this->assertSame(7 * 86400, Cashback_Shop_Rate_Of_Approve_Refresher::NULL_RESULT_COOLDOWN_SECONDS);
    }

    public function test_stale_after_constant_is_one_day(): void
    {
        $this->assertSame(DAY_IN_SECONDS, Cashback_Shop_Rate_Of_Approve_Refresher::STALE_AFTER_SECONDS);
    }

    /**
     * Структурный guard на SQL: WHERE-клаус должен содержать оба условия
     * cooldown (mr.meta_id IS NOT NULL — rate реально записан, включая '0';
     * И fallback по таймштампу). Без этого assert'а будущая случайная правка
     * SQL могла бы тихо снять защиту от infinite-loop (Codex iter-1 finding).
     */
    public function test_query_sql_contains_cooldown_and_rate_presence_clauses(): void
    {
        $this->install_wpdb_stub();
        $this->invoke_query(network_id: 1, cycle_started_at: time(), limit: 30);

        $sql = (string) ($GLOBALS['_cb_test_wpdb_last_prepare_sql'] ?? '');
        $this->assertNotSame('', $sql, 'prepare() должен быть вызван');

        // LEFT JOIN на META_RATE (mr) обязателен — без него mr.meta_id IS NOT NULL
        // не работает, cooldown превратится в always-true.
        $this->assertMatchesRegularExpression(
            '/LEFT\s+JOIN\s+\S+\s+mr\s+ON\s+mr\.post_id\s*=\s*p\.ID\s+AND\s+mr\.meta_key\s*=\s*%s/i',
            $sql,
            'mr LEFT JOIN на _cashback_rate_of_approve обязателен'
        );

        // Cooldown clause: rate присутствует (mr.meta_id IS NOT NULL — ловит и '0',
        // и любое число), ИЛИ никогда не обновлялся, ИЛИ обновлён давно.
        $this->assertMatchesRegularExpression(
            '/AND\s*\(\s*mr\.meta_id\s+IS\s+NOT\s+NULL\s+OR\s+mf\.meta_value\s+IS\s+NULL\s+OR\s+CAST\(mf\.meta_value\s+AS\s+UNSIGNED\)\s*<\s*%d\s*\)/i',
            $sql,
            'cooldown-clause должен явно ловить META_RATE наличие; rate=0 НЕ считается null-результатом'
        );

        $this->assertMatchesRegularExpression(
            '/AND\s*\(\s*mf\.meta_value\s+IS\s+NULL\s+OR\s+CAST\(mf\.meta_value\s+AS\s+UNSIGNED\)\s*<\s*%d\s*\)/i',
            $sql,
            'fresh fetched_at должен исключать товар из 2h refresh'
        );
    }

    public function test_run_batch_yields_when_shop_import_lock_is_busy(): void
    {
        $GLOBALS['_cb_test_as_scheduled'] = false;
        $GLOBALS['wpdb'] = new class {
            public string $prefix   = 'wp_';
            public string $posts    = 'wp_posts';
            public string $postmeta = 'wp_postmeta';
            public array $lock_sqls = array();

            public function prepare(string $sql, mixed ...$args): string
            {
                foreach ($args as $arg) {
                    $sql = preg_replace('/%[ds]|%i/', (string) $arg, $sql, 1) ?? $sql;
                }
                return $sql;
            }

            public function get_var(string $sql): mixed
            {
                $this->lock_sqls[] = $sql;
                if (str_contains($sql, 'cashback_rate_of_approve_n1')) {
                    return 1;
                }
                if (str_contains($sql, 'cashback_shops_import_n1')) {
                    return 0;
                }
                return 1;
            }
        };

        $result = Cashback_Shop_Rate_Of_Approve_Refresher::run_batch(1, 123456);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['processed'], 'busy import lock => no API work');
        $this->assertSame(0, $result['errors']);
        $scheduled = $GLOBALS['_cb_test_as_scheduled'];
        $this->assertIsArray($scheduled, 'batch should be re-enqueued');
        $this->assertSame(Cashback_Shop_Rate_Of_Approve_Refresher::HOOK_BATCH, $scheduled['hook']);
        $this->assertSame(array(1, 123456), $scheduled['args']);
        $this->assertGreaterThanOrEqual(time() + 299, (int) $scheduled['timestamp']);
    }

    /**
     * Вызвать private save_rate_for_product через Reflection.
     */
    private function invoke_save(int $product_id, mixed $rate, string $source): void
    {
        $ref = new \ReflectionMethod(Cashback_Shop_Rate_Of_Approve_Refresher::class, 'save_rate_for_product');
        $ref->invoke(null, $product_id, $rate, $source);
    }

    /**
     * Вызвать private query_product_ids_for_network через Reflection.
     */
    private function invoke_query(int $network_id, int $cycle_started_at, int $limit): array
    {
        $ref = new \ReflectionMethod(Cashback_Shop_Rate_Of_Approve_Refresher::class, 'query_product_ids_for_network');
        $result = $ref->invoke(null, $network_id, $cycle_started_at, $limit);
        return is_array($result) ? $result : array();
    }

    /**
     * Минимальный $wpdb stub: сохраняет args последнего prepare() в global,
     * get_col возвращает пустой массив (нам важен сам prepare-вызов).
     */
    private function install_wpdb_stub(): void
    {
        $GLOBALS['wpdb'] = new class {
            public string $prefix   = 'wp_';
            public string $posts    = 'wp_posts';
            public string $postmeta = 'wp_postmeta';

            public function prepare(string $sql, mixed ...$args): string
            {
                $GLOBALS['_cb_test_wpdb_last_prepare_sql']  = $sql;
                $GLOBALS['_cb_test_wpdb_last_prepare_args'] = $args;
                return $sql;
            }

            public function get_col(string $sql): array
            {
                return array();
            }
        };
    }
}
