<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit-тесты для Cashback_Schema_Idempotency_Migration (Группа 6 ADR, шаг 2).
 *
 * Миграция добавляет UNIQUE-ключи для:
 *   - cashback_fraud_device_ids: ADD session_date DATE GENERATED + UNIQUE(user_id, session_date, device_id)
 *   - cashback_claims:           ADD idempotency_key CHAR(36) NULL + UNIQUE(user_id, idempotency_key)
 *                                + UNIQUE(merchant_id, order_id)
 *   - cashback_support_messages: ADD request_id CHAR(36) NULL + UNIQUE(request_id)
 *
 * Поведение:
 *   - idempotent: при установленном option'е cashback_schema_idempotency_v1_applied — skip.
 *   - pre-check дублей по каждому будущему UNIQUE → abort + set admin-notice-флаг.
 *   - clean DB → DDL выполняются по одному; state SHOW COLUMNS / SHOW INDEX проверяется перед каждым.
 *   - после успеха → set applied-флаг + log run.end.
 */
#[Group('dedup')]
#[Group('schema-migration')]
final class SchemaIdempotencyMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cb_test_options'] = array();

        if (!class_exists('Cashback_Schema_Idempotency_Migration')) {
            require_once dirname(__DIR__, 3) . '/includes/class-cashback-schema-idempotency-migration.php';
        }
    }

    // ────────────────────────────────────────────────────────────
    // Idempotency
    // ────────────────────────────────────────────────────────────

    public function test_skipped_when_applied_flag_true(): void
    {
        update_option('cashback_schema_idempotency_v1_applied', true);
        $wpdb = new Schema_Migration_Wpdb_Stub();

        $migration = new Cashback_Schema_Idempotency_Migration($wpdb);
        $result    = $migration->run();

        $this->assertTrue($result['already_applied']);
        $this->assertFalse($result['applied']);
        $this->assertEmpty($wpdb->executed_ddl, 'Ни одного ALTER не выполнено');
    }

    public function test_sets_applied_flag_on_clean_success(): void
    {
        $wpdb = new Schema_Migration_Wpdb_Stub();

        $migration = new Cashback_Schema_Idempotency_Migration($wpdb);
        $result    = $migration->run();

        $this->assertTrue($result['applied']);
        $this->assertFalse($result['already_applied']);
        $this->assertNull($result['aborted_reason']);
        $this->assertTrue((bool) get_option('cashback_schema_idempotency_v1_applied'));
    }

    // ────────────────────────────────────────────────────────────
    // Pre-check дублей → abort
    // ────────────────────────────────────────────────────────────

    public function test_aborts_when_fraud_device_ids_has_duplicates(): void
    {
        $wpdb = new Schema_Migration_Wpdb_Stub();
        $wpdb->duplicate_counts['cashback_fraud_device_ids'] = 3;

        $migration = new Cashback_Schema_Idempotency_Migration($wpdb);
        $result    = $migration->run();

        $this->assertFalse($result['applied']);
        $this->assertSame('duplicates_found', $result['aborted_reason']);
        $this->assertSame(3, $result['duplicate_checks']['cashback_fraud_device_ids']);
        $this->assertEmpty($wpdb->executed_ddl, 'При обнаруженных дублях DDL не должен вызываться');
        $this->assertFalse((bool) get_option('cashback_schema_idempotency_v1_applied'));
    }

    public function test_aborts_when_claims_merchant_order_has_duplicates(): void
    {
        $wpdb = new Schema_Migration_Wpdb_Stub();
        $wpdb->duplicate_counts['cashback_claims_merchant_order'] = 1;

        $migration = new Cashback_Schema_Idempotency_Migration($wpdb);
        $result    = $migration->run();

        $this->assertFalse($result['applied']);
        $this->assertSame('duplicates_found', $result['aborted_reason']);
        $this->assertEmpty($wpdb->executed_ddl);
    }

    public function test_abort_sets_admin_notice_flag(): void
    {
        $wpdb = new Schema_Migration_Wpdb_Stub();
        $wpdb->duplicate_counts['cashback_fraud_device_ids'] = 5;

        $migration = new Cashback_Schema_Idempotency_Migration($wpdb);
        $migration->run();

        $blocked_info = get_option('cashback_schema_idempotency_v1_blocked');
        $this->assertIsArray($blocked_info);
        $this->assertArrayHasKey('duplicate_checks', $blocked_info);
        $this->assertSame(5, $blocked_info['duplicate_checks']['cashback_fraud_device_ids']);
    }

    // ────────────────────────────────────────────────────────────
    // DDL execution
    // ────────────────────────────────────────────────────────────

    public function test_clean_run_executes_all_seven_ddl(): void
    {
        $wpdb = new Schema_Migration_Wpdb_Stub();

        $migration = new Cashback_Schema_Idempotency_Migration($wpdb);
        $migration->run();

        $this->assertCount(7, $wpdb->executed_ddl);

        $joined = implode("\n", $wpdb->executed_ddl);
        $this->assertStringContainsString('ADD COLUMN `session_date`', $joined);
        $this->assertStringContainsString('ADD UNIQUE KEY `uk_user_session_device`', $joined);
        $this->assertStringContainsString('ADD COLUMN `idempotency_key`', $joined);
        $this->assertStringContainsString('ADD UNIQUE KEY `uk_user_idempotency`', $joined);
        $this->assertStringContainsString('ADD UNIQUE KEY `uk_merchant_order`', $joined);
        $this->assertStringContainsString('ADD COLUMN `request_id`', $joined);
        $this->assertStringContainsString('ADD UNIQUE KEY `uk_request_id`', $joined);
    }

    public function test_skips_add_column_when_already_exists(): void
    {
        $wpdb = new Schema_Migration_Wpdb_Stub();
        $wpdb->existing_columns['cashback_claims'][] = 'idempotency_key';

        $migration = new Cashback_Schema_Idempotency_Migration($wpdb);
        $migration->run();

        $joined = implode("\n", $wpdb->executed_ddl);
        $this->assertStringNotContainsString('ADD COLUMN `idempotency_key`', $joined);
        $this->assertStringContainsString('ADD UNIQUE KEY `uk_user_idempotency`', $joined, 'UNIQUE всё равно накладывается');
    }

    public function test_skips_add_unique_when_already_exists(): void
    {
        $wpdb = new Schema_Migration_Wpdb_Stub();
        $wpdb->existing_indexes['cashback_claims'][] = 'uk_merchant_order';

        $migration = new Cashback_Schema_Idempotency_Migration($wpdb);
        $migration->run();

        $joined = implode("\n", $wpdb->executed_ddl);
        $this->assertStringNotContainsString('ADD UNIQUE KEY `uk_merchant_order`', $joined);
    }

    public function test_fully_migrated_database_is_noop_without_applied_flag(): void
    {
        $wpdb = new Schema_Migration_Wpdb_Stub();
        $wpdb->existing_columns['cashback_fraud_device_ids'][] = 'session_date';
        $wpdb->existing_indexes['cashback_fraud_device_ids'][] = 'uk_user_session_device';
        $wpdb->existing_columns['cashback_claims'][]           = 'idempotency_key';
        $wpdb->existing_indexes['cashback_claims'][]           = 'uk_user_idempotency';
        $wpdb->existing_indexes['cashback_claims'][]           = 'uk_merchant_order';
        $wpdb->existing_columns['cashback_support_messages'][] = 'request_id';
        $wpdb->existing_indexes['cashback_support_messages'][] = 'uk_request_id';

        $migration = new Cashback_Schema_Idempotency_Migration($wpdb);
        $result    = $migration->run();

        $this->assertEmpty($wpdb->executed_ddl, 'Всё уже есть — DDL не запускаются');
        $this->assertTrue($result['applied']);
        $this->assertTrue((bool) get_option('cashback_schema_idempotency_v1_applied'));
    }

    // ────────────────────────────────────────────────────────────
    // Logger
    // ────────────────────────────────────────────────────────────

    public function test_logger_receives_run_start_and_end_events(): void
    {
        $events = array();
        $wpdb   = new Schema_Migration_Wpdb_Stub();

        $migration = new Cashback_Schema_Idempotency_Migration(
            $wpdb,
            function (string $event, array $ctx) use (&$events): void {
                $events[] = $event;
            }
        );
        $migration->run();

        $this->assertContains('run.start', $events);
        $this->assertContains('run.end', $events);
    }

    public function test_logger_receives_abort_event_on_duplicates(): void
    {
        $events = array();
        $wpdb   = new Schema_Migration_Wpdb_Stub();
        $wpdb->duplicate_counts['cashback_fraud_device_ids'] = 2;

        $migration = new Cashback_Schema_Idempotency_Migration(
            $wpdb,
            function (string $event, array $ctx) use (&$events): void {
                $events[] = $event;
            }
        );
        $migration->run();

        $this->assertContains('run.aborted', $events);
        $this->assertNotContains('run.end', $events);
    }
}

// ────────────────────────────────────────────────────────────
// In-memory $wpdb stub для schema-migration тестов
// ────────────────────────────────────────────────────────────

final class Schema_Migration_Wpdb_Stub
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var array<int, string> */
    public array $executed_ddl = array();
    /** @var array<string, int> — table_suffix → кол-во групп дубликатов. */
    public array $duplicate_counts = array();
    /** @var array<string, array<int, string>> — table_suffix → список существующих колонок. */
    public array $existing_columns = array();
    /** @var array<string, array<int, string>> — table_suffix → список существующих индексов. */
    public array $existing_indexes = array();

    public function prepare(string $query, mixed ...$args): string
    {
        $i = 0;
        return (string) preg_replace_callback('/%[isdf]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            return is_string($v) ? "'" . str_replace("'", "\\'", $v) . "'" : (string) $v;
        }, $query);
    }

    public function query(string $sql): int
    {
        if (preg_match('/^\s*ALTER\s+TABLE/i', $sql)) {
            $this->executed_ddl[] = $sql;
            return 0;
        }
        return 0;
    }

    public function get_var(string $sql): string|int|null
    {
        // Pre-check дубликатов: SELECT COUNT(*) FROM (... GROUP BY ... HAVING COUNT(*)>1) ...
        if (stripos($sql, 'cashback_fraud_device_ids') !== false && stripos($sql, 'HAVING COUNT') !== false) {
            return $this->duplicate_counts['cashback_fraud_device_ids'] ?? 0;
        }
        if (stripos($sql, 'cashback_claims') !== false && stripos($sql, 'merchant_id') !== false && stripos($sql, 'HAVING COUNT') !== false) {
            return $this->duplicate_counts['cashback_claims_merchant_order'] ?? 0;
        }
        return 0;
    }

    /** @return array<int, array<string,mixed>> */
    public function get_results(string $sql, string $output = 'ARRAY_A'): array
    {
        // SHOW COLUMNS FROM wp_<table> LIKE '<col>'
        if (preg_match("/SHOW\\s+COLUMNS\\s+FROM\\s+[`']?wp_([a-z_]+)[`']?\\s+LIKE\\s+'([a-z_]+)'/i", $sql, $m)) {
            $table = $m[1];
            $col   = $m[2];
            $cols  = $this->existing_columns[$table] ?? array();
            return in_array($col, $cols, true) ? array(array('Field' => $col)) : array();
        }

        // SHOW INDEX FROM wp_<table> WHERE Key_name = '<key>'
        if (preg_match("/SHOW\\s+INDEX\\s+FROM\\s+[`']?wp_([a-z_]+)[`']?\\s+WHERE\\s+Key_name\\s*=\\s*'([a-z_]+)'/i", $sql, $m)) {
            $table = $m[1];
            $key   = $m[2];
            $idxes = $this->existing_indexes[$table] ?? array();
            return in_array($key, $idxes, true) ? array(array('Key_name' => $key)) : array();
        }

        return array();
    }
}
