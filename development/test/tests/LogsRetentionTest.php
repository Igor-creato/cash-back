<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты на Cashback_Logs_Retention — ежедневная очистка 11 лог/аудит/очередь
 * таблиц, которые до сих пор росли бесконечно (только INSERT, без DELETE).
 *
 * @group retention
 * @group logs
 */
#[Group('retention')]
#[Group('logs')]
final class LogsRetentionTest extends TestCase
{
    /** Ожидаемые table_key конфига — ровно 11, по 4 группам решения владельца. */
    private const EXPECTED_KEYS = array(
        'sync_log',
        'click_log',
        'click_sessions',
        'promocode_clicks',
        'affiliate_clicks',
        'affiliate_audit',
        'cron_state',
        'rate_history',
        'claim_events',
        'broadcast_queue',
        'broadcast_campaigns',
    );

    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once dirname(__DIR__) . '/Shop_Test_Wpdb_Stub.php';

        if (!class_exists('Cashback_Logs_Retention')) {
            require_once $plugin_root . '/includes/class-cashback-logs-retention.php';
        }
    }

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new Shop_Test_Wpdb_Stub();
        // Truthy для information_schema existence-check И для GET_LOCK/RELEASE.
        $wpdb->next_get_var = 1;
        $GLOBALS['_cb_test_filters']      = array();
        $GLOBALS['_cb_test_as_scheduled'] = false;
    }

    /** @return array<int,string> SQL всех DELETE-запросов */
    private function deleteSqls(): array
    {
        global $wpdb;
        $out = array();
        foreach ($wpdb->queries as $q) {
            if (str_contains($q['sql'], 'DELETE FROM')) {
                $out[] = $q['sql'];
            }
        }
        return $out;
    }

    public function test_config_covers_exactly_eleven_expected_tables(): void
    {
        $ref = new ReflectionMethod('Cashback_Logs_Retention', 'config');
        $ref->setAccessible(true);
        $config = $ref->invoke(null);

        $this->assertCount(11, $config, 'Конфиг должен покрывать ровно 11 таблиц');
        $this->assertSame(
            self::EXPECTED_KEYS,
            array_keys($config),
            'Набор и порядок table_key должны совпадать с ожидаемыми 11'
        );
        foreach ($config as $key => $cfg) {
            $this->assertArrayHasKey('table_suffix', $cfg, "{$key}: нет table_suffix");
            $this->assertArrayHasKey('date_col', $cfg, "{$key}: нет date_col");
            $this->assertArrayHasKey('extra_where', $cfg, "{$key}: нет extra_where");
            $this->assertStringStartsWith('cashback_', $cfg['table_suffix']);
        }
    }

    public function test_run_emits_batched_delete_per_table_with_default_180(): void
    {
        $results = Cashback_Logs_Retention::run();
        $sqls    = $this->deleteSqls();

        $this->assertCount(11, $sqls, 'По одному DELETE на каждую из 11 таблиц');
        $this->assertCount(11, $results, 'run() возвращает deleted-счётчики по всем таблицам');

        foreach ($sqls as $sql) {
            $this->assertStringContainsString('DELETE FROM `wp_cashback_', $sql, '%i биндит имя таблицы');
            $this->assertStringContainsString('DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 DAY)', $sql);
            $this->assertStringContainsString('LIMIT 5000', $sql, 'батч-LIMIT, не full-table DELETE');
        }
    }

    public function test_protected_tables_carry_extra_where_guard(): void
    {
        Cashback_Logs_Retention::run();
        $joined = implode("\n", $this->deleteSqls());

        // click_sessions — не удалять открытые окна активации
        $this->assertMatchesRegularExpression(
            '/wp_cashback_click_sessions`[^\n]*status <> \'active\'/',
            $joined
        );
        // cron_state — не удалять running-этапы
        $this->assertMatchesRegularExpression(
            '/wp_cashback_cron_state`[^\n]*status <> \'running\'/',
            $joined
        );
        // claim_events — только заявки в терминальном статусе
        $this->assertMatchesRegularExpression(
            "/wp_cashback_claim_events`[^\n]*claim_id IN \(SELECT claim_id FROM .*status IN \('approved','declined'\)\)/",
            $joined
        );
    }

    public function test_global_filter_clamped_to_min_days(): void
    {
        add_filter('cashback_logs_retention_days', static fn() => 5);

        Cashback_Logs_Retention::run();

        foreach ($this->deleteSqls() as $sql) {
            $this->assertStringContainsString(
                'INTERVAL 30 DAY',
                $sql,
                'Меньше MIN_DAYS=30 → clamp до 30'
            );
        }
    }

    public function test_per_table_override_filter(): void
    {
        add_filter('cashback_logs_retention_days_sync_log', static fn() => 365);

        Cashback_Logs_Retention::run();

        $sync = null;
        $other = null;
        foreach ($this->deleteSqls() as $sql) {
            if (str_contains($sql, 'wp_cashback_sync_log`')) {
                $sync = $sql;
            } elseif (str_contains($sql, 'wp_cashback_rate_history`')) {
                $other = $sql;
            }
        }

        $this->assertNotNull($sync);
        $this->assertNotNull($other);
        $this->assertStringContainsString('INTERVAL 365 DAY', $sync, 'per-table override применён');
        $this->assertStringContainsString('INTERVAL 180 DAY', $other, 'остальные на дефолте 180');
    }

    public function test_skips_all_when_lock_busy(): void
    {
        global $wpdb;
        $wpdb->next_get_var = 0; // GET_LOCK не получен

        $results = Cashback_Logs_Retention::run();

        $this->assertSame(array(), $results);
        $this->assertCount(0, $this->deleteSqls(), 'lock busy → ни одного DELETE');
    }

    public function test_skips_table_when_not_exists(): void
    {
        global $wpdb;
        // GET_LOCK helper в стабе вернёт 1 (т.к. next_get_var===null), а
        // information_schema existence-check тоже вернёт null → таблицы «нет».
        $wpdb->next_get_var = null;

        $results = Cashback_Logs_Retention::run();

        $this->assertSame(array(), $results);
        $this->assertCount(0, $this->deleteSqls(), 'нет таблиц → нет DELETE');
    }

    public function test_query_failure_does_not_abort_other_tables(): void
    {
        global $wpdb;
        $wpdb->fail_on_query_substring = 'wp_cashback_sync_log';

        $results = Cashback_Logs_Retention::run();

        $this->assertArrayNotHasKey('sync_log', $results, 'упавшая таблица не в результатах');
        $this->assertArrayHasKey('rate_history', $results, 'остальные таблицы обработаны');
        $this->assertNotEmpty($wpdb->last_error);
    }

    public function test_register_schedules_daily_as_action(): void
    {
        $GLOBALS['_cb_test_as_scheduled'] = false;

        Cashback_Logs_Retention::register();

        $scheduled = $GLOBALS['_cb_test_as_scheduled'];
        $this->assertIsArray($scheduled);
        $this->assertSame(Cashback_Logs_Retention::HOOK_NAME, $scheduled['hook']);
        $this->assertSame(Cashback_Logs_Retention::CRON_GROUP, $scheduled['group']);
        $this->assertSame(DAY_IN_SECONDS, $scheduled['interval_in_seconds'] ?? null, 'recurring = ежедневно');
    }

    public function test_class_wired_into_plugin_bootstrap_and_deactivation(): void
    {
        $plugin = file_get_contents(dirname(__DIR__, 3) . '/cashback-plugin.php');
        $this->assertIsString($plugin);

        $this->assertStringContainsString(
            "require_file('includes/class-cashback-logs-retention.php')",
            $plugin,
            'класс должен подключаться'
        );
        $this->assertMatchesRegularExpression(
            '/class_exists\(\s*[\'"]Cashback_Logs_Retention[\'"]\s*\)\s*\)\s*\{\s*Cashback_Logs_Retention::register\(\)/s',
            $plugin,
            'register() должен вызываться при наличии класса'
        );
        $this->assertStringContainsString(
            "'cashback_logs_retention',",
            $plugin,
            'HOOK_NAME должен сниматься при деактивации (в \$as_hooks)'
        );
    }
}
