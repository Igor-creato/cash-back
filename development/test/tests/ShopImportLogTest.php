<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional тесты на Cashback_Shop_Import_Log (v12, Этап 5).
 *
 * Использует in-memory $wpdb stub чтобы захватывать INSERT/UPDATE/DELETE
 * без реальной БД и проверять что параметры собраны корректно.
 *
 * @group shop-import
 * @group import-log
 */
#[Group('shop-import')]
#[Group('import-log')]
final class ShopImportLogTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        require_once dirname(__DIR__) . '/Shop_Test_Wpdb_Stub.php';

        if (!class_exists('Cashback_Shop_Import_Log')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-shop-import-log.php';
        }
    }

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new Shop_Test_Wpdb_Stub();
    }

    public function test_start_page_inserts_row_with_correct_columns(): void
    {
        global $wpdb;

        $log_id = Cashback_Shop_Import_Log::start_page('run-abc', 5, 0);

        $this->assertGreaterThan(0, $log_id);
        $this->assertCount(1, $wpdb->inserts);
        $insert = $wpdb->inserts[0];
        $this->assertSame('wp_cashback_shop_import_log', $insert['table']);
        $this->assertSame('run-abc', $insert['data']['run_id']);
        $this->assertSame(5, $insert['data']['network_id']);
        $this->assertSame(0, $insert['data']['page']);
        $this->assertSame(0, $insert['data']['fetched']);
        $this->assertSame(0, $insert['data']['upserted_new']);
        $this->assertNull($insert['data']['errors']);
        $this->assertNull($insert['data']['finished_at']);
    }

    public function test_start_page_returns_zero_on_invalid_input(): void
    {
        $this->assertSame(0, Cashback_Shop_Import_Log::start_page('', 5, 0));
        $this->assertSame(0, Cashback_Shop_Import_Log::start_page('run-1', 0, 0));
        $this->assertSame(0, Cashback_Shop_Import_Log::start_page('run-1', -3, 0));
    }

    public function test_update_progress_writes_counters(): void
    {
        global $wpdb;
        Cashback_Shop_Import_Log::update_progress(42, 100, 80, 5, 200);

        $this->assertCount(1, $wpdb->updates);
        $update = $wpdb->updates[0];
        $this->assertSame('wp_cashback_shop_import_log', $update['table']);
        $this->assertSame(array(
            'fetched'        => 100,
            'upserted_new'   => 80,
            'upserted_upd'   => 5,
            'tariffs_synced' => 200,
        ), $update['data']);
        $this->assertSame(array('id' => 42), $update['where']);
    }

    public function test_update_progress_clamps_negative_to_zero(): void
    {
        global $wpdb;
        Cashback_Shop_Import_Log::update_progress(42, -5, -10, 3, 7);

        $update = $wpdb->updates[0];
        $this->assertSame(0, $update['data']['fetched']);
        $this->assertSame(0, $update['data']['upserted_new']);
        $this->assertSame(3, $update['data']['upserted_upd']);
    }

    public function test_update_progress_noop_on_invalid_log_id(): void
    {
        global $wpdb;
        Cashback_Shop_Import_Log::update_progress(0, 1, 1, 1, 1);
        Cashback_Shop_Import_Log::update_progress(-3, 1, 1, 1, 1);
        $this->assertCount(0, $wpdb->updates);
    }

    public function test_finish_page_writes_finished_at_and_no_errors(): void
    {
        global $wpdb;
        Cashback_Shop_Import_Log::finish_page(42, null);

        $this->assertCount(1, $wpdb->updates);
        $update = $wpdb->updates[0];
        $this->assertNotEmpty($update['data']['finished_at']);
        $this->assertNull($update['data']['errors']);
    }

    public function test_finish_page_writes_error_message(): void
    {
        global $wpdb;
        Cashback_Shop_Import_Log::finish_page(42, 'HTTP 500 from Admitad');

        $update = $wpdb->updates[0];
        $this->assertSame('HTTP 500 from Admitad', $update['data']['errors']);
    }

    public function test_gc_old_runs_delete_query(): void
    {
        global $wpdb;
        $deleted = Cashback_Shop_Import_Log::gc_old(30);

        $this->assertSame(1, $deleted, 'stub query() возвращает 1');
        $this->assertCount(1, $wpdb->queries);
        $this->assertStringContainsString('DELETE FROM', $wpdb->queries[0]['sql']);
        $this->assertStringContainsString('cashback_shop_import_log', $wpdb->queries[0]['sql']);
    }

    public function test_generate_run_id_returns_non_empty_string(): void
    {
        $a = Cashback_Shop_Import_Log::generate_run_id();
        $b = Cashback_Shop_Import_Log::generate_run_id();
        $this->assertNotEmpty($a);
        $this->assertNotEmpty($b);
        $this->assertNotSame($a, $b, 'Два вызова должны давать разные id');
    }

    public function test_get_recent_returns_results(): void
    {
        global $wpdb;
        $wpdb->next_get_results = array(
            array('id' => 1, 'run_id' => 'r1', 'network_id' => 5, 'fetched' => 100),
            array('id' => 2, 'run_id' => 'r2', 'network_id' => 5, 'fetched' => 50),
        );
        $rows = Cashback_Shop_Import_Log::get_recent(5, 10);
        $this->assertCount(2, $rows);
    }

    public function test_get_run_filters_by_run_id(): void
    {
        global $wpdb;
        $wpdb->next_get_results = array(
            array('id' => 1, 'run_id' => 'r1', 'page' => 0),
            array('id' => 2, 'run_id' => 'r1', 'page' => 1),
        );
        $rows = Cashback_Shop_Import_Log::get_run('r1');
        $this->assertCount(2, $rows);
    }

    public function test_get_run_returns_empty_on_empty_run_id(): void
    {
        $this->assertSame(array(), Cashback_Shop_Import_Log::get_run(''));
    }
}
