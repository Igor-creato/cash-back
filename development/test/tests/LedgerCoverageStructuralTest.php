<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты Группы 14 (ledger-first coverage).
 *
 * Используют source-based паттерн (как AdminTransactionIdempotencyTest): читают
 * исходный файл и проверяют регэкспами наличие ключевых конструкций. Служат
 * safety-net'ом от регрессий при будущих рефакторингах — гарантируют, что
 * инварианты ledger-first не удалены случайно.
 */
#[Group('ledger')]
#[Group('group-14')]
final class LedgerCoverageStructuralTest extends TestCase
{
    private function source( string $rel ): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $c    = file_get_contents($path);
        $this->assertIsString($c, "{$rel} must be readable");
        return $c;
    }

    // =========================================================================
    // Шаг A: admin/users-management — ban/unban ledger integration
    // =========================================================================

    public function test_users_management_selects_banned_at_for_idempotency_key(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertStringContainsString(
            "'SELECT status, ban_reason, banned_at FROM %i WHERE user_id = %d FOR UPDATE'",
            $src,
            'SELECT profile FOR UPDATE должен включать banned_at для ledger idempotency_key'
        );
    }

    public function test_users_management_writes_unfreeze_entry_before_profile_update(): void
    {
        $src = $this->source('admin/users-management.php');

        $unfreeze_pos = strpos($src, 'Cashback_Ban_Ledger::write_unfreeze_entry');
        $update_pos   = strpos($src, 'wpdb->update(' . "\n" . '                $this->profile_table_name,');
        // менее хрупкий fallback — ищем "Обновляем только те поля, которые были изменены"
        if ($update_pos === false) {
            $update_pos = strpos($src, 'Обновляем только те поля, которые были изменены');
        }

        $this->assertNotFalse($unfreeze_pos, 'Должен быть вызов Cashback_Ban_Ledger::write_unfreeze_entry');
        $this->assertNotFalse($update_pos, 'Должен быть UPDATE profile');
        $this->assertLessThan(
            $update_pos,
            $unfreeze_pos,
            'write_unfreeze_entry должен вызываться ДО UPDATE profile (пока ban-бакеты не обнулены триггером)'
        );
    }

    public function test_users_management_writes_freeze_entry_after_handle_user_ban(): void
    {
        $src = $this->source('admin/users-management.php');

        $handle_pos = strpos($src, '$this->handle_user_ban($user_id, $ban_reason, true)');
        $freeze_pos = strpos($src, 'Cashback_Ban_Ledger::write_freeze_entry');

        $this->assertNotFalse($handle_pos);
        $this->assertNotFalse($freeze_pos);
        $this->assertGreaterThan(
            $handle_pos,
            $freeze_pos,
            'write_freeze_entry должен вызываться ПОСЛЕ handle_user_ban (когда триггер переместил available/pending → frozen_*_ban)'
        );
    }

    // =========================================================================
    // Шаг A-follow-up: antifraud/class-fraud-admin — bulk-ban ledger coverage
    // =========================================================================

    public function test_fraud_admin_writes_ban_ledger_entry_after_freeze_fallback(): void
    {
        $src = $this->source('antifraud/class-fraud-admin.php');

        $freeze_pos = strpos($src, 'Cashback_Trigger_Fallbacks::freeze_balance_on_ban');
        $ledger_pos = strpos($src, 'Cashback_Ban_Ledger::write_freeze_entry');

        $this->assertNotFalse($freeze_pos, 'fraud-admin должен вызывать freeze_balance_on_ban fallback');
        $this->assertNotFalse($ledger_pos, 'fraud-admin должен писать ban_freeze в ledger');
        $this->assertGreaterThan($freeze_pos, $ledger_pos);
    }

    public function test_fraud_admin_uses_single_banned_at_for_update_and_ledger(): void
    {
        $src = $this->source('antifraud/class-fraud-admin.php');
        // Значение banned_at фиксируется в переменную один раз — иначе UPDATE и
        // strtotime() в ledger-write могут дать разные timestamp'ы (разный idempotency_key).
        $this->assertMatchesRegularExpression(
            '/\$banned_at_mysql\s*=\s*current_time\(\s*[\'"]mysql[\'"]\s*\)\s*;/',
            $src,
            'fraud-admin должен запомнить banned_at один раз, не вызывать current_time() дважды'
        );
    }

    // =========================================================================
    // Шаг C: admin/transactions — reject правки после accrual
    // =========================================================================

    public function test_transactions_selects_processed_at_for_reject_check(): void
    {
        $src = $this->source('admin/transactions.php');
        $this->assertStringContainsString(
            'processed_at',
            $src,
            'SELECT transaction должен включать processed_at'
        );
        $this->assertMatchesRegularExpression(
            '/SELECT\s+id,\s*reference_id,\s*user_id,\s*order_status,\s*sum_order,\s*comission,\s*cashback,\s*processed_at/i',
            $src
        );
    }

    public function test_transactions_rejects_finance_edit_when_processed(): void
    {
        $src = $this->source('admin/transactions.php');
        $this->assertStringContainsString(
            "'already_accrued'",
            $src,
            'handle_update_transaction должен возвращать code=already_accrued для правки финансовых полей после processed_at'
        );
        $this->assertStringContainsString(
            "array( 'comission', 'cashback', 'sum_order', 'order_status' )",
            $src,
            'Должен быть явный whitelist финансовых полей для проверки'
        );
    }

    // =========================================================================
    // Шаг D: api-client — reversal в sync_update_local
    // =========================================================================

    public function test_api_client_writes_reversal_adjustment_for_completed_to_declined(): void
    {
        $src = $this->source('includes/class-cashback-api-client.php');

        $this->assertStringContainsString(
            "'reversal_tx_%d'",
            $src,
            'sync_update_local должен формировать idempotency_key=reversal_tx_{id}'
        );
        $this->assertStringContainsString(
            "'reversal'",
            $src,
            'Должен быть reference_type=reversal'
        );
        $this->assertStringContainsString(
            "GREATEST(0.00, available_balance - %s)",
            $src,
            'Кэш должен декрементиться с clamp >= 0 в той же TX'
        );
    }

    // =========================================================================
    // Шаг E: daily reconciliation job
    // =========================================================================

    public function test_reconciliation_hook_registered_with_day_interval(): void
    {
        $src = $this->source('includes/class-cashback-balance-reconciliation.php');
        $this->assertStringContainsString("'cashback_daily_balance_reconciliation'", $src);
        $this->assertStringContainsString('DAY_IN_SECONDS', $src);
        $this->assertStringContainsString("'cashback'", $src); // AS group
    }

    public function test_reconciliation_calls_validate_user_balance_consistency(): void
    {
        $src = $this->source('includes/class-cashback-balance-reconciliation.php');
        $this->assertStringContainsString(
            'Mariadb_Plugin::validate_user_balance_consistency',
            $src
        );
        $this->assertStringContainsString(
            "'balance_consistency_mismatch'",
            $src,
            'Mismatch должен писаться в audit-log c action=balance_consistency_mismatch'
        );
    }

    public function test_reconciliation_stale_approved_claims_query(): void
    {
        $src = $this->source('includes/class-cashback-balance-reconciliation.php');
        $this->assertStringContainsString(
            'check_stale_approved_claims',
            $src
        );
        $this->assertStringContainsString(
            'DATE_SUB(NOW(), INTERVAL 14 DAY)',
            $src,
            '14-дневный порог для stuck approved claims'
        );
        $this->assertStringContainsString(
            "'claim_approved_no_transaction'",
            $src,
            'Audit-log action для stuck claims'
        );
    }

    public function test_reconciliation_registered_in_plugin_init(): void
    {
        $src = $this->source('cashback-plugin.php');
        $this->assertStringContainsString(
            'Cashback_Balance_Reconciliation::init',
            $src,
            'Класс должен инициализироваться через initialize_components'
        );
        $this->assertStringContainsString(
            'class-cashback-balance-reconciliation.php',
            $src,
            'Файл должен подключаться через require_file'
        );
    }

    // =========================================================================
    // Шаг G: safety-backfill ledger.accrual
    // =========================================================================

    public function test_backfill_migration_registered_in_activate(): void
    {
        $src = $this->source('mariadb.php');
        $this->assertStringContainsString(
            'migrate_backfill_ledger_accruals',
            $src
        );
        $this->assertStringContainsString(
            "'cashback_ledger_accrual_backfill_v1'",
            $src,
            'Fast-path option-flag для идемпотентности'
        );
    }

    public function test_backfill_uses_on_duplicate_key_update(): void
    {
        $src = $this->source('mariadb.php');
        // Backfill должен использовать INSERT ... ON DUPLICATE KEY UPDATE с idempotency_key='accrual_{id}'.
        $this->assertMatchesRegularExpression(
            '/ON DUPLICATE KEY UPDATE\s+id\s*=\s*id/',
            $src
        );
        $this->assertStringContainsString(
            "'accrual_' . (int) \$row['id']",
            $src,
            'Детерминированный idempotency_key=accrual_{id} для совпадения с process_ready_transactions'
        );
    }

    public function test_backfill_runtime_trigger_in_maybe_run_migrations(): void
    {
        $src = $this->source('cashback-plugin.php');
        $this->assertStringContainsString(
            'migrate_backfill_ledger_accruals',
            $src,
            'Backfill должен вызываться runtime через maybe_run_migrations (паттерн F-22-003)'
        );
    }

    // =========================================================================
    // validate_user_balance_consistency — ban_freeze/ban_unfreeze покрытие
    // =========================================================================

    public function test_consistency_check_includes_ban_freeze_in_sums(): void
    {
        $src = $this->source('mariadb.php');
        $this->assertStringContainsString(
            "'ban_freeze'         => '0.00'",
            $src,
            '$sums init должен содержать ban_freeze'
        );
        $this->assertStringContainsString(
            "'ban_unfreeze'       => '0.00'",
            $src,
            '$sums init должен содержать ban_unfreeze'
        );
    }

    public function test_consistency_check_adds_ban_frozen_to_ledger_frozen(): void
    {
        $src = $this->source('mariadb.php');
        $this->assertStringContainsString('$ban_frozen', $src);
        $this->assertStringContainsString('$ban_net', $src);
        // ban_frozen clamped >= 0.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*bccomp\s*\(\s*\$ban_frozen,\s*[\'"]0[\'"],\s*2\s*\)\s*<\s*0\s*\)\s*\{\s*\$ban_frozen\s*=\s*[\'"]0\.00[\'"]\s*;/',
            $src
        );
    }
}
