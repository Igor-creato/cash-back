<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурный тест: cron-сверка ledger ↔ audit_log (E2E follow-up P2-A1-1, вариант B).
 *
 * Закрывает defense-in-depth gap: если кто-то делает write в `cashback_balance_ledger`
 * минуя plugin handler (raw SQL, неавторизованный путь), audit_log остаётся пустым.
 * Cron-сверка раз в час за окно 25ч находит такие orphan-записи и алёртит админу
 * (email + audit-запись `ledger_entry_without_audit`).
 *
 * Source-based: парсит includes/class-cashback-audit-trail-reconciliation.php +
 * cashback-plugin.php и проверяет ключевые контракты (HOOK, окно, whitelist
 * типов, email + audit_log на mismatch).
 */
#[Group('reconciliation')]
#[Group('audit-trail')]
final class AuditTrailReconciliationTest extends TestCase
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
        return $this->source('includes/class-cashback-audit-trail-reconciliation.php');
    }

    // =========================================================================
    // Класс существует + базовые константы
    // =========================================================================

    public function test_class_file_exists(): void
    {
        $path = dirname(__DIR__, 3) . '/includes/class-cashback-audit-trail-reconciliation.php';
        $this->assertFileExists($path, 'класс должен жить в includes/class-cashback-audit-trail-reconciliation.php');
    }

    public function test_class_declared(): void
    {
        $this->assertMatchesRegularExpression(
            '/class\s+Cashback_Audit_Trail_Reconciliation/',
            $this->class_src(),
            'класс Cashback_Audit_Trail_Reconciliation должен быть объявлен'
        );
    }

    public function test_hook_name_constant_present(): void
    {
        $this->assertMatchesRegularExpression(
            "/const\s+HOOK_NAME\s*=\s*'cashback_audit_trail_reconciliation'/",
            $this->class_src(),
            "HOOK_NAME должен быть = 'cashback_audit_trail_reconciliation'"
        );
    }

    public function test_window_hours_25(): void
    {
        $this->assertMatchesRegularExpression(
            '/const\s+WINDOW_HOURS\s*=\s*25\s*;/',
            $this->class_src(),
            'WINDOW_HOURS должен быть = 25 (24ч окно + 1ч safety)'
        );
    }

    // =========================================================================
    // Регистрация AS hook + cron каждый час
    // =========================================================================

    public function test_init_registers_as_hook(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*self::HOOK_NAME\s*,/",
            $this->class_src(),
            'init() должен подписать AS-callback на self::HOOK_NAME'
        );
    }

    public function test_maybe_schedule_uses_hour_in_seconds(): void
    {
        $src = $this->class_src();
        $this->assertMatchesRegularExpression(
            "/as_schedule_recurring_action\s*\([^,]+,\s*HOUR_IN_SECONDS\s*,\s*self::HOOK_NAME/",
            $src,
            'maybe_schedule() должен использовать HOUR_IN_SECONDS как interval (раз в час)'
        );
        $this->assertMatchesRegularExpression(
            '/as_has_scheduled_action\s*\(\s*self::HOOK_NAME/',
            $src,
            'maybe_schedule() должен проверять as_has_scheduled_action для идемпотентности'
        );
    }

    public function test_init_registered_in_bootstrap(): void
    {
        $this->assertMatchesRegularExpression(
            '/Cashback_Audit_Trail_Reconciliation::init\s*\(\s*\)/',
            $this->source('cashback-plugin.php'),
            'CashbackPlugin::initialize_components() должен вызывать Cashback_Audit_Trail_Reconciliation::init()'
        );
    }

    // =========================================================================
    // SQL: окно 25ч + whitelist типов
    // =========================================================================

    public function test_sql_uses_25_hour_window(): void
    {
        $this->assertMatchesRegularExpression(
            "/INTERVAL\s+%d\s+HOUR/i",
            $this->class_src(),
            'SQL должен фильтровать ledger по INTERVAL %d HOUR (с подстановкой WINDOW_HOURS)'
        );
    }

    public function test_sql_filters_by_whitelisted_types(): void
    {
        $src = $this->class_src();
        // Whitelist должен покрывать admin-driven операции, не webhook-driven.
        // Webhook'и (accrual, affiliate_*) пишут много, но audit для каждого не нужен.
        foreach (
            array(
                'adjustment',
                'payout_complete',
                'payout_cancel',
                'payout_declined',
                'ban_freeze',
                'ban_unfreeze',
            ) as $type
        ) {
            $this->assertStringContainsString(
                "'{$type}'",
                $src,
                "whitelist типов должен включать '{$type}' (admin-driven операция)"
            );
        }
    }

    public function test_sql_excludes_webhook_types(): void
    {
        $src = $this->class_src();
        // Whitelist подход = webhook-types НЕ перечислены в whitelist.
        // Проверяем что accrual / affiliate_accrual нигде НЕ figурируют как
        // expected audit-action — иначе будут massive false positives.
        $this->assertStringNotContainsString(
            "'accrual_audit_required'",
            $src,
            'accrual НЕ должен быть в списке audit-required (webhook-driven, ложные срабатывания)'
        );
    }

    // =========================================================================
    // Mismatch behaviour: email + audit-запись
    // =========================================================================

    public function test_mismatch_writes_audit_log_entry(): void
    {
        $this->assertMatchesRegularExpression(
            "/Cashback_Encryption::write_audit_log\s*\(\s*'ledger_entry_without_audit'/",
            $this->class_src(),
            "при mismatch должен писаться audit-action 'ledger_entry_without_audit'"
        );
    }

    public function test_mismatch_sends_email_to_admin(): void
    {
        $src = $this->class_src();
        $this->assertMatchesRegularExpression(
            "/Cashback_Email_Sender[\s\S]+?send_critical/",
            $src,
            'при наличии mismatch должен отправляться email через Cashback_Email_Sender::send_critical (bypass opt-out)'
        );
        $this->assertMatchesRegularExpression(
            "/get_option\s*\(\s*'admin_email'\s*\)/",
            $src,
            "адресат email — get_option('admin_email')"
        );
    }

    public function test_run_returns_summary_with_orphan_count(): void
    {
        $this->assertMatchesRegularExpression(
            "/return\s+array\s*\([\s\S]*?'orphans'\s*=>/",
            $this->class_src(),
            "run() должен возвращать summary с ключом 'orphans' (счётчик найденных orphan-ledger-записей)"
        );
    }

    // =========================================================================
    // Защита: read-only contract (не пытаемся «починить»)
    // =========================================================================

    public function test_does_not_modify_ledger_or_audit_log(): void
    {
        $src = $this->class_src();
        $this->assertStringNotContainsString(
            'wpdb->update',
            $src,
            'cron-сверка read-only: НЕ должна делать $wpdb->update'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/DELETE\s+FROM/i',
            $src,
            'cron-сверка read-only: НЕ должна делать DELETE'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/INSERT\s+INTO\s+`?\{?\$?wpdb->prefix\}?cashback_balance_ledger/i',
            $src,
            'cron-сверка read-only: НЕ должна писать в balance_ledger (только write_audit_log allowed)'
        );
    }
}
