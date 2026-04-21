<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit-тесты для Cashback_Rate_Limit_Migration (Группа 7 ADR, шаг 3).
 *
 * Миграция создаёт {$wpdb->prefix}cashback_rate_limit_counters для атомарного
 * INSERT ... ON DUPLICATE KEY UPDATE (SQL rate-limit backend).
 *
 * Поведение:
 *  - idempotent: при installed флаге cashback_rate_limit_v1_applied — skip.
 *  - CREATE TABLE IF NOT EXISTS → повторный запуск без флага тоже безопасен.
 *  - после успеха — set flag + log run.end.
 */
#[Group('rate-limit')]
#[Group('rate-limit-migration')]
final class RateLimitMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cb_test_options'] = array();

        if (!class_exists('Cashback_Rate_Limit_Migration')) {
            require_once dirname(__DIR__, 3) . '/includes/class-cashback-rate-limit-migration.php';
        }
    }

    public function test_skipped_when_applied_flag_true(): void
    {
        update_option('cashback_rate_limit_v1_applied', true);
        $wpdb = new Rate_Limit_Migration_Wpdb_Stub();

        $migration = new Cashback_Rate_Limit_Migration($wpdb);
        $result    = $migration->run();

        $this->assertTrue($result['already_applied']);
        $this->assertFalse($result['applied']);
        $this->assertEmpty($wpdb->executed_ddl, 'Ни одного DDL на already-applied.');
    }

    public function test_sets_applied_flag_on_clean_success(): void
    {
        $wpdb = new Rate_Limit_Migration_Wpdb_Stub();

        $migration = new Cashback_Rate_Limit_Migration($wpdb);
        $result    = $migration->run();

        $this->assertTrue($result['applied']);
        $this->assertFalse($result['already_applied']);
        $this->assertTrue($result['table_created']);
        $this->assertCount(1, $result['ddl_executed']);
        $this->assertTrue((bool) get_option('cashback_rate_limit_v1_applied'));
    }

    public function test_ddl_contains_expected_schema(): void
    {
        $wpdb = new Rate_Limit_Migration_Wpdb_Stub();

        $migration = new Cashback_Rate_Limit_Migration($wpdb);
        $migration->run();

        $this->assertNotEmpty($wpdb->executed_ddl);
        $ddl = $wpdb->executed_ddl[0];

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', $ddl);
        $this->assertStringContainsString('`wp_cashback_rate_limit_counters`', $ddl);
        $this->assertStringContainsString('scope_key VARCHAR(64) NOT NULL', $ddl);
        $this->assertStringContainsString('window_started_at INT UNSIGNED NOT NULL', $ddl);
        $this->assertStringContainsString('hits INT UNSIGNED NOT NULL', $ddl);
        $this->assertStringContainsString('expires_at INT UNSIGNED NOT NULL', $ddl);
        $this->assertStringContainsString('PRIMARY KEY (scope_key)', $ddl);
        $this->assertStringContainsString('KEY idx_expires (expires_at)', $ddl);
        $this->assertStringContainsString('ENGINE=InnoDB', $ddl);
        $this->assertStringContainsString('utf8mb4', $ddl);
    }

    public function test_table_created_false_when_already_present(): void
    {
        $wpdb                     = new Rate_Limit_Migration_Wpdb_Stub();
        $wpdb->existing_tables[]  = 'wp_cashback_rate_limit_counters';

        $migration = new Cashback_Rate_Limit_Migration($wpdb);
        $result    = $migration->run();

        // CREATE TABLE IF NOT EXISTS всё равно выполняется, но table_created=false.
        $this->assertTrue($result['applied']);
        $this->assertFalse($result['table_created']);
        $this->assertCount(1, $result['ddl_executed']);
    }

    public function test_logger_receives_run_start_and_end(): void
    {
        $events = array();
        $wpdb   = new Rate_Limit_Migration_Wpdb_Stub();

        $migration = new Cashback_Rate_Limit_Migration(
            $wpdb,
            function (string $event, array $ctx) use (&$events): void {
                $events[] = $event;
            }
        );
        $migration->run();

        $this->assertContains('run.start', $events);
        $this->assertContains('run.end', $events);
    }

    public function test_rerun_without_option_still_idempotent_via_create_if_not_exists(): void
    {
        // Вручную очищаем option (эмулируя corrupted state), запускаем дважды.
        $wpdb = new Rate_Limit_Migration_Wpdb_Stub();

        ( new Cashback_Rate_Limit_Migration($wpdb) )->run();
        delete_option('cashback_rate_limit_v1_applied');
        $wpdb->existing_tables[] = 'wp_cashback_rate_limit_counters';

        $second = ( new Cashback_Rate_Limit_Migration($wpdb) )->run();

        $this->assertTrue($second['applied']);
        $this->assertFalse($second['table_created'], 'CREATE TABLE IF NOT EXISTS не должен пересоздать.');
    }
}

// ─────────────────────────────────────────────
// In-memory wpdb stub для миграции.
// ─────────────────────────────────────────────

final class Rate_Limit_Migration_Wpdb_Stub
{
    public string $prefix     = 'wp_';
    public string $last_error = '';
    /** @var array<int, string> */
    public array $executed_ddl = array();
    /** @var array<int, string> */
    public array $existing_tables = array();

    public function prepare(string $query, mixed ...$args): string
    {
        $i = 0;
        return (string) preg_replace_callback(
            '/%[sdif]/',
            function (array $m) use (&$i, $args): string {
                $v = $args[ $i ] ?? '';
                $i++;
                return is_string($v) ? "'" . str_replace("'", "\\'", $v) . "'" : (string) $v;
            },
            $query
        );
    }

    public function query(string $sql): int
    {
        if (preg_match('/^\s*CREATE\s+TABLE/i', $sql)) {
            $this->executed_ddl[] = $sql;
            // Добавляем таблицу в existing после успешного CREATE (имитируя реальный DDL).
            if (preg_match('/`([a-z0-9_]+)`/i', $sql, $m)) {
                $this->existing_tables[] = $m[1];
            }
            return 0;
        }
        return 0;
    }

    /** @return array<int, array<string, mixed>> */
    public function get_results(string $sql, string $output = 'ARRAY_A'): array
    {
        // SHOW TABLES LIKE '<name>'
        if (preg_match("/^\\s*SHOW\\s+TABLES\\s+LIKE\\s+'([^']+)'\\s*$/i", $sql, $m)) {
            return in_array($m[1], $this->existing_tables, true)
                ? array( array( 'Tables_in_db' => $m[1] ) )
                : array();
        }
        return array();
    }
}
