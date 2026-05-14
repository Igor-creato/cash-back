<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional тесты на Cashback_Webhooks_Retention (v4.3.4).
 * Закрывает audit-finding P-3: cashback_webhooks без retention растёт infinitely.
 *
 * @group webhooks
 * @group retention
 */
#[Group('webhooks')]
#[Group('retention')]
final class WebhooksRetentionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once dirname(__DIR__) . '/Shop_Test_Wpdb_Stub.php';

        if (!class_exists('Cashback_Webhooks_Retention')) {
            require_once $plugin_root . '/includes/class-cashback-webhooks-retention.php';
        }
    }

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new Shop_Test_Wpdb_Stub();
        $GLOBALS['_cb_test_filters']      = array();
        $GLOBALS['_cb_test_as_scheduled'] = false;
    }

    public function test_run_returns_deleted_count_from_single_batch(): void
    {
        // Stub возвращает 42 на каждый query, но второй loop break (т.к. 42 < 5000 BATCH_LIMIT).
        global $wpdb;
        $wpdb->next_get_var = 42;  // не используется, but reset

        $deleted = Cashback_Webhooks_Retention::run();

        $this->assertSame(1, $deleted, 'stub::query() returns 1 — единственный батч');
        $this->assertCount(1, $wpdb->queries, 'DELETE вызван 1 раз (батч неполный → стоп)');
        $this->assertStringContainsString('DELETE FROM', $wpdb->queries[0]['sql']);
        $this->assertStringContainsString('cashback_webhooks', $wpdb->queries[0]['sql']);
        $this->assertStringContainsString('processing_status IS NOT NULL', $wpdb->queries[0]['sql']);
        $this->assertStringContainsString('INTERVAL 90 DAY', $wpdb->queries[0]['sql']);
        $this->assertStringContainsString('LIMIT 5000', $wpdb->queries[0]['sql']);
    }

    public function test_run_respects_retention_days_filter_with_min_floor(): void
    {
        $GLOBALS['_cb_test_filters'] = array();
        add_filter('cashback_webhooks_retention_days', static fn() => 30);

        Cashback_Webhooks_Retention::run();

        global $wpdb;
        $this->assertStringContainsString('INTERVAL 30 DAY', $wpdb->queries[0]['sql']);
    }

    public function test_run_floor_min_7_days_for_safety(): void
    {
        add_filter('cashback_webhooks_retention_days', static fn() => 1);

        Cashback_Webhooks_Retention::run();

        global $wpdb;
        $this->assertStringContainsString('INTERVAL 7 DAY', $wpdb->queries[0]['sql'], 'Меньше MIN_DAYS=7 → clamp');
    }

    public function test_run_breaks_on_query_failure(): void
    {
        global $wpdb;
        $wpdb->fail_on_query_substring = 'DELETE FROM';

        $deleted = Cashback_Webhooks_Retention::run();

        $this->assertSame(0, $deleted);
        $this->assertCount(1, $wpdb->queries, 'После failed DELETE — больше попыток нет (break)');
    }

    public function test_register_idempotent_scheduler(): void
    {
        $GLOBALS['_cb_test_as_scheduled'] = false;

        Cashback_Webhooks_Retention::register();

        $scheduled = $GLOBALS['_cb_test_as_scheduled'];
        $this->assertIsArray($scheduled);
        $this->assertSame(Cashback_Webhooks_Retention::HOOK_NAME, $scheduled['hook']);
        $this->assertSame(Cashback_Webhooks_Retention::CRON_GROUP, $scheduled['group']);
    }
}
