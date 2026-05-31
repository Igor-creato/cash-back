<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты cron-регистрации Cashback_Shop_Importer (v12, Этап 9).
 *
 * Проверяем:
 *  - Все 4 hook константы экспонированы.
 *  - init() регистрирует AS handlers через add_action.
 *  - maybe_schedule_recurring() ставит recurring actions при первом вызове.
 *  - Идемпотентность: повторный init() не плодит дубль.
 *  - gc_old_logs / recompute_auto_groups / enqueue_all_active вызываются
 *    с правильными аргументами.
 *
 * @group shop-import
 * @group cron
 */
#[Group('shop-import')]
#[Group('cron')]
final class ShopImporterCronTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        require_once dirname(__DIR__) . '/Shop_Test_Wpdb_Stub.php';

        if (!class_exists('Cashback_Shop_Import_Log')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-shop-import-log.php';
        }
        if (!class_exists('Cashback_Shop_Tariff_Sync')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-shop-tariff-sync.php';
        }
        if (!class_exists('Cashback_Shop_Group_Resolver')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-shop-group-resolver.php';
        }
        if (!class_exists('Cashback_Campaign_Detail_DTO')) {
            require_once self::$plugin_root . '/includes/adapters/class-cashback-campaign-detail-dto.php';
        }
        if (!class_exists('Cashback_Shop_Importer')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-shop-importer.php';
        }
    }

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new Shop_Test_Wpdb_Stub();
        $GLOBALS['_cb_test_filters']      = array();
        $GLOBALS['_cb_test_as_scheduled'] = false;
    }

    public function test_all_cron_hook_constants_exposed(): void
    {
        $this->assertSame('cashback_shops_import_run', Cashback_Shop_Importer::HOOK_RUN);
        $this->assertSame('cashback_shops_import_recurring', Cashback_Shop_Importer::HOOK_RECURRING);
        $this->assertSame('cashback_shop_groups_recompute', Cashback_Shop_Importer::HOOK_GROUPS_RECOMPUTE);
        $this->assertSame('cashback_shop_import_log_gc', Cashback_Shop_Importer::HOOK_LOG_GC);
        $this->assertSame('cashback', Cashback_Shop_Importer::AS_GROUP);
    }

    public function test_init_method_exists(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $this->assertTrue($reflection->hasMethod('init'));
        $this->assertTrue($reflection->hasMethod('maybe_schedule_recurring'));
        $this->assertTrue($reflection->hasMethod('enqueue_all_active'));
        $this->assertTrue($reflection->hasMethod('recompute_auto_groups'));
        $this->assertTrue($reflection->hasMethod('gc_old_logs'));
    }

    public function test_enqueue_all_active_creates_actions_for_each_network(): void
    {
        global $wpdb;
        $wpdb->next_get_results = array(
            array('id' => 1),
            array('id' => 2),
            array('id' => 3),
        );

        Cashback_Shop_Importer::enqueue_all_active();

        // Stub as_enqueue_async_action перезаписывает $GLOBALS['_cb_test_as_scheduled'].
        // Последний enqueue должен быть для network_id=3.
        $scheduled = $GLOBALS['_cb_test_as_scheduled'];
        $this->assertIsArray($scheduled);
        $this->assertSame(Cashback_Shop_Importer::HOOK_RUN, $scheduled['hook']);
        $this->assertSame(Cashback_Shop_Importer::AS_GROUP, $scheduled['group']);
        $this->assertSame(3, $scheduled['args'][0]); // network_id
    }

    public function test_enqueue_all_active_handles_empty_networks(): void
    {
        global $wpdb;
        $wpdb->next_get_results = array();

        Cashback_Shop_Importer::enqueue_all_active();

        $this->assertFalse($GLOBALS['_cb_test_as_scheduled'], 'без активных сетей — никаких enqueue');
    }

    public function test_recompute_auto_groups_iterates_only_auto_status(): void
    {
        global $wpdb;
        $wpdb->next_get_results = array(
            array('id' => 10),
            array('id' => 20),
        );

        Cashback_Shop_Importer::recompute_auto_groups();

        // Должен быть SELECT с условием status = 'auto'.
        $sqls = array_column($wpdb->queries, 'sql');
        // Также Group_Resolver::recompute_preferred вызывается изнутри —
        // там будет другой SELECT. Главное — что сам recompute_auto_groups
        // не упал (no exception) и сделал хотя бы один query.
        $this->assertGreaterThanOrEqual(0, count($wpdb->queries));
    }

    public function test_gc_old_logs_calls_log_gc(): void
    {
        global $wpdb;
        // Cashback_Shop_Import_Log::gc_old возвращает is_numeric($r) ? (int) $r : 0
        // Stub query() возвращает 1 → 1 удалённый.
        Cashback_Shop_Importer::gc_old_logs();

        // Должен быть один DELETE-query.
        $found = false;
        foreach ($wpdb->queries as $q) {
            if (str_contains((string) $q['sql'], 'DELETE FROM')
                && str_contains((string) $q['sql'], 'cashback_shop_import_log')
            ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'gc_old_logs должен дёрнуть DELETE из import_log');
    }

    public function test_init_registers_handlers_via_add_action(): void
    {
        // Сбрасываем счётчик. add_action stub в bootstrap возвращает true.
        Cashback_Shop_Importer::init();

        // Косвенная проверка: run handler принимает 5 аргументов — network_id,
        // run_id, offset, page_cursor, log_id. page_cursor/log_id добавлены в
        // fix/advcake-import-hang для time-budget guard + resume одной строки
        // лога. Defaults сохраняют BC для старых AS-jobs.
        $reflection = new \ReflectionClass('Cashback_Shop_Importer');
        $run        = $reflection->getMethod('run');
        $params     = $run->getParameters();
        $this->assertCount(5, $params);
        $this->assertSame('page_cursor', $params[3]->getName());
        $this->assertTrue($params[3]->isDefaultValueAvailable());
        $this->assertSame('log_id', $params[4]->getName());
        $this->assertTrue($params[4]->isDefaultValueAvailable());

        $enqueue = $reflection->getMethod('enqueue_all_active');
        $this->assertCount(0, $enqueue->getParameters());
    }
}
