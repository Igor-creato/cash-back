<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурный тест: ежедневная retention-чистка cashback_audit_log
 * (P2 prod-readiness CONCERN C1, 2026-05-02).
 *
 * Audit-log пишется бесконечно — без cleanup-cron таблица будет расти
 * годами. Срок хранения 1825 дней = 5 лет (152-ФЗ ст. 5 ч. 7 +
 * 161-ФЗ ст. 27 — финансовая первичка).
 *
 * Source-based: парсит includes/class-cashback-audit-log-retention.php +
 * cashback-plugin.php и проверяет ключевые контракты (HOOK, retention,
 * single-runner LOCK, daily AS-job, batch-limit, идемпотентная регистрация).
 */
#[Group('retention')]
#[Group('audit-log')]
final class AuditLogRetentionTest extends TestCase
{
    private function source(string $rel): string
    {
        $path    = dirname(__DIR__, 3) . '/' . $rel;
        $content = file_get_contents($path);
        $this->assertIsString($content, "{$rel} must be readable");
        return $content;
    }

    private function class_src(): string
    {
        return $this->source('includes/class-cashback-audit-log-retention.php');
    }

    public function test_class_file_exists(): void
    {
        $path = dirname(__DIR__, 3) . '/includes/class-cashback-audit-log-retention.php';
        $this->assertFileExists(
            $path,
            'класс должен жить в includes/class-cashback-audit-log-retention.php'
        );
    }

    public function test_class_declared(): void
    {
        $this->assertMatchesRegularExpression(
            '/class\s+Cashback_Audit_Log_Retention\b/',
            $this->class_src(),
            'класс Cashback_Audit_Log_Retention должен быть объявлен'
        );
    }

    public function test_hook_name_constant_present(): void
    {
        $this->assertMatchesRegularExpression(
            "/const\s+HOOK_NAME\s*=\s*'cashback_audit_log_retention_cleanup'/",
            $this->class_src(),
            "HOOK_NAME должен быть = 'cashback_audit_log_retention_cleanup'"
        );
    }

    public function test_retention_days_5_years(): void
    {
        $this->assertMatchesRegularExpression(
            '/const\s+RETENTION_DAYS\s*=\s*1825\b/',
            $this->class_src(),
            'RETENTION_DAYS = 1825 (5 лет, 152-ФЗ ст.5 ч.7 + 161-ФЗ ст.27)'
        );
    }

    public function test_batch_limit_constant_present(): void
    {
        $this->assertMatchesRegularExpression(
            '/const\s+BATCH_LIMIT\s*=\s*\d{2,}/',
            $this->class_src(),
            'BATCH_LIMIT должна ограничивать DELETE per-run (защита от OLTP-лока)'
        );
    }

    public function test_init_registers_as_hook(): void
    {
        $src = $this->class_src();
        $this->assertMatchesRegularExpression(
            '/add_action\s*\(\s*self::HOOK_NAME\s*,/',
            $src,
            'init() должен подписать AS-callback на self::HOOK_NAME'
        );
    }

    public function test_maybe_schedule_uses_day_in_seconds(): void
    {
        $src = $this->class_src();
        $this->assertMatchesRegularExpression(
            '/as_schedule_recurring_action\s*\([^,]+,\s*DAY_IN_SECONDS\s*,\s*self::HOOK_NAME/',
            $src,
            'maybe_schedule() должен использовать DAY_IN_SECONDS (раз в сутки)'
        );
        $this->assertMatchesRegularExpression(
            '/as_has_scheduled_action\s*\(\s*self::HOOK_NAME/',
            $src,
            'maybe_schedule() должен проверять as_has_scheduled_action для идемпотентности'
        );
    }

    public function test_run_uses_get_lock_for_single_runner(): void
    {
        $src = $this->class_src();
        $this->assertMatchesRegularExpression(
            '/GET_LOCK/i',
            $src,
            'run() должен использовать MySQL GET_LOCK как single-runner guard'
        );
        $this->assertMatchesRegularExpression(
            '/RELEASE_LOCK/i',
            $src,
            'run() должен освобождать LOCK через RELEASE_LOCK после DELETE'
        );
    }

    public function test_sql_deletes_by_created_at_with_interval(): void
    {
        $src = $this->class_src();
        $this->assertMatchesRegularExpression(
            '/DELETE\s+FROM/i',
            $src,
            'run() должен делать DELETE FROM (cleanup старых записей)'
        );
        $this->assertMatchesRegularExpression(
            '/INTERVAL\s+%d\s+DAY/i',
            $src,
            "DELETE должен использовать created_at < UTC_TIMESTAMP() - INTERVAL %d DAY"
        );
        $this->assertMatchesRegularExpression(
            '/created_at/',
            $src,
            'фильтр должен идти по created_at'
        );
    }

    public function test_sql_uses_limit_to_avoid_oltp_lock(): void
    {
        $this->assertMatchesRegularExpression(
            '/LIMIT\s+%d/i',
            $this->class_src(),
            'DELETE должен иметь LIMIT %d для batch-обработки'
        );
    }

    public function test_run_returns_summary_with_deleted_count(): void
    {
        $this->assertMatchesRegularExpression(
            "/'deleted'\s*=>/",
            $this->class_src(),
            "run() должен возвращать summary с ключом 'deleted'"
        );
        $this->assertMatchesRegularExpression(
            "/'retention_days'\s*=>/",
            $this->class_src(),
            "run() должен возвращать summary с ключом 'retention_days' для прозрачности"
        );
    }

    public function test_filter_allows_retention_override(): void
    {
        $src = $this->class_src();
        $this->assertMatchesRegularExpression(
            "/apply_filters\s*\(\s*'cashback_audit_log_retention_days'/",
            $src,
            "должен быть filter 'cashback_audit_log_retention_days' для override через wp-config/MU-plugin"
        );
    }

    public function test_does_not_modify_other_tables(): void
    {
        $src = $this->class_src();
        $this->assertDoesNotMatchRegularExpression(
            '/DELETE\s+FROM\s+`?\{?\$?wpdb->prefix\}?cashback_balance_ledger/i',
            $src,
            'retention НЕ должна удалять из balance_ledger (финансовая первичка хранится отдельно)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/DELETE\s+FROM\s+`?\{?\$?wpdb->prefix\}?cashback_consent_log/i',
            $src,
            'retention НЕ должна удалять из consent_log (append-only по 152-ФЗ + триггер блокирует)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/DELETE\s+FROM\s+`?\{?\$?wpdb->prefix\}?cashback_payout_requests/i',
            $src,
            'retention НЕ должна удалять из payout_requests'
        );
    }

    public function test_init_registered_in_bootstrap(): void
    {
        $src = $this->source('cashback-plugin.php');
        $this->assertMatchesRegularExpression(
            '/Cashback_Audit_Log_Retention::init\s*\(\s*\)/',
            $src,
            'CashbackPlugin::initialize_components() должен вызывать Cashback_Audit_Log_Retention::init()'
        );
    }

    public function test_dependency_loaded_in_bootstrap(): void
    {
        $src = $this->source('cashback-plugin.php');
        $this->assertStringContainsString(
            'class-cashback-audit-log-retention.php',
            $src,
            'load_dependencies() должен подключать includes/class-cashback-audit-log-retention.php'
        );
    }
}
