<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты Группы 15, шаг S2.A (AJAX handler ручной корректировки).
 *
 * Source-based: проверяют, что handle_adjust_balance в admin/users-management.php
 * имеет ключевые защитные и ledger-first конструкции (nonce, capability,
 * idempotency, TX, FOR UPDATE, ledger INSERT с ON DUPLICATE KEY UPDATE, audit).
 *
 * Тесты рассчитаны на то, что S2.B (UI) может их не сломать.
 */
#[Group('ledger')]
#[Group('group-15')]
final class BalanceAdjustAjaxTest extends TestCase
{
    private function source(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $content = file_get_contents($path);
        $this->assertIsString($content, "{$rel} must be readable");
        return $content;
    }

    // =========================================================================
    // Регистрация AJAX hook + метод handler
    // =========================================================================

    public function test_users_management_registers_adjust_balance_ajax_hook(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'wp_ajax_cashback_adjust_balance'\s*,/",
            $src,
            'S2.A: wp_ajax_cashback_adjust_balance должен регистрироваться в constructor'
        );
    }

    public function test_users_management_has_handle_adjust_balance_method(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertMatchesRegularExpression(
            '/public\s+function\s+handle_adjust_balance\s*\(\s*\)\s*:\s*void/',
            $src,
            'S2.A: handle_adjust_balance должен быть public function(): void'
        );
    }

    // =========================================================================
    // Защитные проверки: nonce + capability
    // =========================================================================

    public function test_adjust_balance_verifies_nonce(): void
    {
        $src = $this->source('admin/users-management.php');
        // Nonce action — строка для wp_verify_nonce внутри handle_adjust_balance.
        $this->assertStringContainsString(
            "'cashback_adjust_balance_nonce'",
            $src,
            'S2.A: nonce action должен быть cashback_adjust_balance_nonce'
        );
        $this->assertStringContainsString(
            'wp_verify_nonce',
            $src,
            'S2.A: handler должен вызывать wp_verify_nonce'
        );
    }

    public function test_adjust_balance_checks_manage_options_capability(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertStringContainsString(
            "current_user_can('manage_options')",
            $src,
            'S2.A: handler должен проверять manage_options capability'
        );
    }

    // =========================================================================
    // Idempotency (Группа 5 ADR паттерн)
    // =========================================================================

    public function test_adjust_balance_uses_idempotency_claim(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertStringContainsString(
            "Cashback_Idempotency::claim",
            $src,
            'S2.A: handler должен использовать server-side дедуп через Cashback_Idempotency::claim'
        );
        $this->assertStringContainsString(
            "'admin_balance_adjust'",
            $src,
            'S2.A: scope для idempotency должен быть admin_balance_adjust'
        );
    }

    public function test_adjust_balance_normalizes_request_id(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertStringContainsString(
            "Cashback_Idempotency::normalize_request_id",
            $src,
            'S2.A: request_id должен пройти через normalize_request_id до claim'
        );
    }

    // =========================================================================
    // Валидация amount + reason
    // =========================================================================

    public function test_adjust_balance_parses_amount_via_money_vo(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertStringContainsString(
            'Cashback_Money::from_string',
            $src,
            'S2.A: amount должен парситься через Cashback_Money::from_string (Группа 10 ADR)'
        );
    }

    public function test_adjust_balance_rejects_zero_amount(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertStringContainsString(
            'is_zero',
            $src,
            'S2.A: zero amount должен быть отклонён (is_zero check)'
        );
    }

    public function test_adjust_balance_requires_minimum_reason_length(): void
    {
        $src = $this->source('admin/users-management.php');
        // Константа MIN_REASON_LENGTH = 20 или inline-check.
        $this->assertMatchesRegularExpression(
            '/MIN_ADJUST_REASON_LENGTH\s*=\s*20|strlen\([^)]+reason[^)]*\)\s*<\s*20/i',
            $src,
            'S2.A: минимальная длина reason 20 символов'
        );
    }

    // =========================================================================
    // Транзакция + FOR UPDATE + ledger INSERT
    // =========================================================================

    public function test_adjust_balance_wraps_writes_in_transaction(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertMatchesRegularExpression(
            '/handle_adjust_balance[\s\S]+?START TRANSACTION[\s\S]+?COMMIT/i',
            $src,
            'S2.A: handler должен обернуть ledger INSERT + cache UPDATE в START TRANSACTION/COMMIT'
        );
    }

    public function test_adjust_balance_locks_user_balance_for_update(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertMatchesRegularExpression(
            '/SELECT[\s\S]+?cashback_user_balance|SELECT[\s\S]+?user_balance_table[\s\S]+?FOR UPDATE/i',
            $src,
            'S2.A: SELECT из cashback_user_balance должен использовать FOR UPDATE'
        );
    }

    public function test_adjust_balance_inserts_ledger_entry_with_type_adjustment(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertMatchesRegularExpression(
            "/INSERT\s+INTO\s+%i[\s\S]+?'adjustment'/i",
            $src,
            'S2.A: INSERT в cashback_balance_ledger должен ставить type=adjustment'
        );
    }

    public function test_adjust_balance_uses_idempotency_key_with_on_duplicate(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertStringContainsString(
            'idempotency_key',
            $src,
            'S2.A: INSERT должен включать idempotency_key'
        );
        $this->assertMatchesRegularExpression(
            '/ON\s+DUPLICATE\s+KEY\s+UPDATE\s+id\s*=\s*id/i',
            $src,
            'S2.A: INSERT ledger должен использовать ON DUPLICATE KEY UPDATE id=id (идемпотентность)'
        );
    }

    public function test_adjust_balance_ledger_reference_type_is_manual(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertStringContainsString(
            "'manual'",
            $src,
            'S2.A: reference_type для adjustment должен быть manual'
        );
    }

    public function test_adjust_balance_idempotency_key_includes_user_and_admin(): void
    {
        $src = $this->source('admin/users-management.php');
        // Пересечение user_id + admin_id + reason + amount в seed для sha1.
        $this->assertMatchesRegularExpression(
            '/sha1\s*\([\s\S]+?\$user_id[\s\S]+?\$admin_id/',
            $src,
            'S2.A: idempotency_key seed должен включать user_id и admin_id'
        );
    }

    // =========================================================================
    // UPDATE cache + GREATEST clamp + version++
    // =========================================================================

    public function test_adjust_balance_clamps_balance_with_greatest(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertMatchesRegularExpression(
            '/GREATEST\s*\(\s*0\.00\s*,\s*available_balance/i',
            $src,
            'S2.A: UPDATE должен clamp-ить available_balance через GREATEST(0.00, ...)'
        );
    }

    public function test_adjust_balance_increments_version_on_update(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertMatchesRegularExpression(
            '/version\s*=\s*version\s*\+\s*1/i',
            $src,
            'S2.A: UPDATE user_balance должен инкрементить version (optimistic locking)'
        );
    }

    // =========================================================================
    // Audit-log
    // =========================================================================

    public function test_adjust_balance_writes_audit_log(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertStringContainsString(
            "'balance_manual_adjustment'",
            $src,
            'S2.A: аудит-запись должна иметь action balance_manual_adjustment'
        );
        $this->assertStringContainsString(
            'Cashback_Encryption::write_audit_log',
            $src,
            'S2.A: handler должен вызывать Cashback_Encryption::write_audit_log'
        );
    }

    // =========================================================================
    // Response
    // =========================================================================

    public function test_adjust_balance_returns_new_balance_and_ledger_id(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertStringContainsString(
            "'new_available_balance'",
            $src,
            'S2.A: response должен содержать new_available_balance'
        );
        $this->assertStringContainsString(
            "'ledger_entry_id'",
            $src,
            'S2.A: response должен содержать ledger_entry_id'
        );
    }

    // =========================================================================
    // S2.B — модал (JS/CSS) + кнопка в users-management
    // =========================================================================

    public function test_balance_adjust_js_asset_exists(): void
    {
        $path = dirname(__DIR__, 3) . '/assets/js/admin-balance-adjust.js';
        $this->assertFileExists($path, 'S2.B: JS модала должен существовать');
    }

    public function test_balance_adjust_css_asset_exists(): void
    {
        $path = dirname(__DIR__, 3) . '/assets/css/admin-balance-adjust.css';
        $this->assertFileExists($path, 'S2.B: CSS модала должен существовать');
    }

    public function test_balance_adjust_js_exposes_open_api(): void
    {
        $src = $this->source('assets/js/admin-balance-adjust.js');
        $this->assertStringContainsString(
            'window.CashbackBalanceAdjust',
            $src,
            'S2.B: глобальный API window.CashbackBalanceAdjust обязателен (переиспользование в S3)'
        );
        $this->assertMatchesRegularExpression(
            '/open\s*:\s*openModal/',
            $src,
            'S2.B: CashbackBalanceAdjust.open должен публиковаться'
        );
    }

    public function test_balance_adjust_js_posts_to_correct_ajax_action(): void
    {
        $src = $this->source('assets/js/admin-balance-adjust.js');
        $this->assertStringContainsString(
            "'cashback_adjust_balance'",
            $src,
            'S2.B: fetch должен отправлять action=cashback_adjust_balance'
        );
        $this->assertMatchesRegularExpression(
            '/body\.append\(\s*[\'"]nonce[\'"]/',
            $src,
            'S2.B: FormData должен включать nonce'
        );
        $this->assertMatchesRegularExpression(
            '/body\.append\(\s*[\'"]request_id[\'"]/',
            $src,
            'S2.B: FormData должен включать request_id для server-side дедупа'
        );
    }

    public function test_balance_adjust_js_has_focus_trap_and_escape(): void
    {
        $src = $this->source('assets/js/admin-balance-adjust.js');
        $this->assertStringContainsString(
            "role', 'dialog'",
            $src,
            'S2.B: модал должен иметь role=dialog'
        );
        $this->assertStringContainsString(
            "aria-modal",
            $src,
            'S2.B: модал должен иметь aria-modal=true'
        );
        $this->assertStringContainsString(
            "'Escape'",
            $src,
            'S2.B: Escape должен закрывать модал'
        );
        $this->assertStringContainsString(
            'trapFocus',
            $src,
            'S2.B: Tab-focus-trap должен быть реализован'
        );
    }

    public function test_balance_adjust_js_disables_apply_until_confirm_checked(): void
    {
        $src = $this->source('assets/js/admin-balance-adjust.js');
        $this->assertMatchesRegularExpression(
            '/applyBtn\.disabled\s*=\s*!\s*confirmInput\.checked/',
            $src,
            'S2.B: Apply-кнопка должна быть disabled пока checkbox подтверждения не включён'
        );
    }

    public function test_users_management_enqueues_balance_adjust_assets(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertStringContainsString(
            "'cashback-admin-balance-adjust'",
            $src,
            'S2.B: enqueue handle должен быть cashback-admin-balance-adjust'
        );
        $this->assertStringContainsString(
            'admin-balance-adjust.js',
            $src,
            'S2.B: enqueue должен указывать на admin-balance-adjust.js'
        );
        $this->assertStringContainsString(
            'admin-balance-adjust.css',
            $src,
            'S2.B: enqueue должен указывать на admin-balance-adjust.css'
        );
    }

    public function test_users_management_localizes_nonce_and_min_length(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertStringContainsString(
            "wp_create_nonce('cashback_adjust_balance_nonce')",
            $src,
            'S2.B: wp_localize_script должен передавать nonce cashback_adjust_balance_nonce'
        );
        $this->assertMatchesRegularExpression(
            '/minReasonLength[\'"]\s*=>\s*self::MIN_ADJUST_REASON_LENGTH/',
            $src,
            'S2.B: localize должен передавать minReasonLength из серверной константы'
        );
    }

    public function test_users_management_renders_adjust_button_with_data_user_id(): void
    {
        $src = $this->source('admin/users-management.php');
        $this->assertStringContainsString(
            'cashback-adjust-balance-btn',
            $src,
            'S2.B: action-кнопка должна иметь class cashback-adjust-balance-btn'
        );
        // Кнопка рендерится внутри цикла пользователей с data-user-id=$user[ID].
        $this->assertMatchesRegularExpression(
            '/class="button button-secondary cashback-adjust-balance-btn"[\s\S]{0,200}data-user-id=/',
            $src,
            'S2.B: кнопка должна иметь data-user-id'
        );
    }

    public function test_reconciliation_admin_enqueues_balance_adjust_assets(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        // Подстраница расхождений тоже должна подключать модал — кнопка «Корректировка»
        // в таблице расхождений (S1.B) использует тот же window.CashbackBalanceAdjust.
        $this->assertStringContainsString(
            'admin-balance-adjust.js',
            $src,
            'S2.B: reconciliation-admin должна enqueue-ить модал для кнопки из таблицы расхождений'
        );
    }

    // =========================================================================
    // S3 — already_accrued → S2 модал в admin-transactions.js
    // =========================================================================

    public function test_transactions_admin_row_has_data_user_id(): void
    {
        $src = $this->source('admin/transactions.php');
        $this->assertStringContainsString(
            'data-user-id="<?php echo esc_attr($tx[\'user_id\'])',
            $src,
            'S3: <tr> транзакции должен содержать data-user-id для JS'
        );
    }

    public function test_transactions_admin_enqueues_balance_adjust_assets(): void
    {
        $src = $this->source('admin/transactions.php');
        $this->assertStringContainsString(
            "'cashback-admin-balance-adjust'",
            $src,
            'S3: admin/transactions.php должен enqueue модал S2'
        );
    }

    public function test_transactions_js_handles_already_accrued_code(): void
    {
        $src = $this->source('assets/js/admin-transactions.js');
        $this->assertStringContainsString(
            "code === 'already_accrued'",
            $src,
            'S3: admin-transactions.js должен проверять code=already_accrued в save-handler'
        );
    }

    public function test_transactions_js_prompts_adjustment_on_already_accrued(): void
    {
        $src = $this->source('assets/js/admin-transactions.js');
        $this->assertStringContainsString(
            'promptAlreadyAccruedAdjustment',
            $src,
            'S3: admin-transactions.js должен иметь helper promptAlreadyAccruedAdjustment'
        );
    }

    public function test_transactions_js_opens_balance_adjust_modal(): void
    {
        $src = $this->source('assets/js/admin-transactions.js');
        $this->assertStringContainsString(
            'window.CashbackBalanceAdjust.open',
            $src,
            'S3: JS должен открывать S2 модал через window.CashbackBalanceAdjust.open'
        );
    }

    public function test_transactions_js_prefills_reason_with_transaction_id(): void
    {
        $src = $this->source('assets/js/admin-transactions.js');
        // Reason префил должен упоминать конкретную транзакцию — для аудита.
        $this->assertMatchesRegularExpression(
            '/prefillReason\s*:[\s\S]{0,200}транзакц/u',
            $src,
            'S3: prefillReason должен ссылаться на транзакцию #<id>'
        );
    }

    public function test_transactions_js_reads_user_id_from_row_dataset(): void
    {
        $src = $this->source('assets/js/admin-transactions.js');
        $this->assertMatchesRegularExpression(
            '/row\.attr\(\s*[\'"]data-user-id[\'"]\s*\)/',
            $src,
            'S3: JS должен читать user_id из data-user-id строки'
        );
    }
}
