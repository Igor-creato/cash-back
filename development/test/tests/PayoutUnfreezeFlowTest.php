<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурный тест: F-S9-NEW-UNFREEZE + F-S9-NEW-DECLINE-BANNED (Session 8, 2026-05-01).
 *
 * Контекст: F-S9-01 (Session 6) добавил guard fail_reason_required для
 * declined/failed transitions. При анализе reversibility найдены 2 продуктовых
 * gap'а:
 *   1. F-S9-NEW-UNFREEZE — admin не может разморозить declined-выплату
 *      после расследования. UI-метка «Выплата заморожена» подразумевает
 *      reversibility, но соответствующего admin-flow не было.
 *   2. F-S9-NEW-DECLINE-BANNED — update_user_balance_on_declined падал
 *      throw'ом если pending_balance < amount, потому что у banned юзера
 *      pending перенесён в frozen_pending_balance_ban (F-11-003 split).
 *
 * Решения (Session 8 AskUserQuestion):
 *   - Q1=B: bucket-aware decline для banned (frozen_pending_balance_ban path
 *     + increment frozen_balance_admin, не трогая ban-bucket после unban).
 *   - Q2=B: разморозка через ledger row payout_unfreeze; status='declined'
 *     остаётся (минимальный touch enum, G1 add-don't-modify).
 *   - Q3=A: кнопка «Разморозить и вернуть» в payout-detail модале.
 *
 * Source-based regression — предохраняет от удаления кода при будущих
 * рефакторингах. E2E на staging проверяется отдельно (см. session note).
 */
#[Group('payouts')]
#[Group('admin')]
final class PayoutUnfreezeFlowTest extends TestCase
{
    private function payouts_source(): string
    {
        $path = dirname(__DIR__, 3) . '/admin/payouts.php';
        $c    = file_get_contents($path);
        $this->assertIsString($c, 'admin/payouts.php must be readable');
        return $c;
    }

    private function mariadb_source(): string
    {
        $path = dirname(__DIR__, 3) . '/mariadb.php';
        $c    = file_get_contents($path);
        $this->assertIsString($c, 'mariadb.php must be readable');
        return $c;
    }

    /**
     * Тело метода update_user_balance_on_declined в admin/payouts.php.
     */
    private function on_declined_body(): string
    {
        $src   = $this->payouts_source();
        $start = strpos($src, 'function update_user_balance_on_declined');
        $this->assertNotFalse(
            $start,
            'admin/payouts.php должен содержать update_user_balance_on_declined'
        );
        $end = strpos($src, "\n    public function ", $start + 1);
        if ($end === false) {
            $end = strpos($src, "\n    private function ", $start + 1);
        }
        if ($end === false) {
            $end = $start + 8000;
        }
        return substr($src, $start, $end - $start);
    }

    /**
     * Тело метода handle_payout_unfreeze (новый AJAX-endpoint).
     */
    private function unfreeze_handler_body(): string
    {
        $src   = $this->payouts_source();
        $start = strpos($src, 'function handle_payout_unfreeze');
        $this->assertNotFalse(
            $start,
            'admin/payouts.php должен содержать handle_payout_unfreeze (новый AJAX-endpoint F-S9-NEW-UNFREEZE)'
        );
        $end = strpos($src, "\n    public function ", $start + 1);
        if ($end === false) {
            $end = strpos($src, "\n    private function ", $start + 1);
        }
        if ($end === false) {
            $end = $start + 8000;
        }
        return substr($src, $start, $end - $start);
    }

    // ---------- F-S9-NEW-UNFREEZE: миграция v7 + ledger enum -------------

    public function test_migration_v7_adds_frozen_balance_admin_column(): void
    {
        $src = $this->mariadb_source();

        $this->assertStringContainsString(
            'migrate_payout_unfreeze_v7',
            $src,
            'mariadb.php должен содержать миграцию v7 для F-S9-NEW-UNFREEZE'
        );

        $this->assertMatchesRegularExpression(
            "/ADD COLUMN\s+`frozen_balance_admin`\s+decimal\(18,2\)/i",
            $src,
            'миграция v7 должна добавлять frozen_balance_admin в cashback_user_balance (DDL через raw $wpdb->query)'
        );

        $this->assertMatchesRegularExpression(
            "/cashback_db_version'\s*,\s*7/",
            $src,
            'миграция v7 должна выставлять cashback_db_version = 7'
        );
    }

    public function test_migration_v7_extends_ledger_type_enum_with_payout_unfreeze(): void
    {
        $src = $this->mariadb_source();

        $this->assertMatchesRegularExpression(
            "/MODIFY\s+COLUMN\s+`type`\s+enum\([^)]*'payout_unfreeze'/i",
            $src,
            'миграция v7 должна расширять enum cashback_balance_ledger.type значением payout_unfreeze'
        );
    }

    public function test_migration_v7_invoked_on_activation(): void
    {
        $src = $this->mariadb_source();

        $this->assertMatchesRegularExpression(
            "/\\\$instance->migrate_payout_unfreeze_v7\s*\(\s*\)\s*;/",
            $src,
            'migrate_payout_unfreeze_v7() должна вызываться в activate() после migrate_split_ban_reason_v6'
        );
    }

    public function test_create_table_includes_frozen_balance_admin_for_fresh_install(): void
    {
        $src = $this->mariadb_source();

        $this->assertMatchesRegularExpression(
            "/`frozen_balance_admin`\s+decimal\(18,2\)\s+NOT NULL\s+DEFAULT\s+0\.00/i",
            $src,
            'CREATE TABLE cashback_user_balance в create_tables() должен содержать frozen_balance_admin (для свежей установки без миграций)'
        );
    }

    public function test_create_table_ledger_enum_includes_payout_unfreeze(): void
    {
        $src = $this->mariadb_source();

        // Базовый enum cashback_balance_ledger.type (мариадб создаётся свежий)
        // — должен включать payout_unfreeze как валидное значение.
        $this->assertMatchesRegularExpression(
            "/enum\([^)]*'payout_unfreeze'[^)]*\)\s+NOT NULL\s+COMMENT\s+'Тип\s+операции'/u",
            $src,
            'CREATE TABLE cashback_balance_ledger.type должен включать payout_unfreeze в enum (для свежей установки)'
        );
    }

    // ---------- F-S9-NEW-DECLINE-BANNED: bucket-aware decline ----------

    public function test_decline_handler_falls_back_to_frozen_pending_balance_ban_for_banned_user(): void
    {
        $body = $this->on_declined_body();

        // Должна быть ветка, которая декрементит из frozen_pending_balance_ban
        // когда pending_balance < amount (юзер забанен → pending в ban-bucket).
        $this->assertMatchesRegularExpression(
            "/frozen_pending_balance_ban\s*=\s*frozen_pending_balance_ban\s*-/i",
            $body,
            'update_user_balance_on_declined должен иметь banned-fallback: декремент из frozen_pending_balance_ban (F-S9-NEW-DECLINE-BANNED)'
        );
    }

    public function test_decline_handler_increments_frozen_balance_admin(): void
    {
        $body = $this->on_declined_body();

        $this->assertMatchesRegularExpression(
            "/frozen_balance_admin\s*=\s*frozen_balance_admin\s*\+/i",
            $body,
            'update_user_balance_on_declined должен инкрементить frozen_balance_admin (новый bucket для admin-разморозки)'
        );
    }

    public function test_decline_handler_checks_user_banned_status_for_fallback_path(): void
    {
        $body = $this->on_declined_body();

        // Defense-in-depth: ban-bucket трогаем только если юзер действительно banned.
        // Иначе можно случайно списать ban-amount у небаненного юзера через
        // некогерентный кеш.
        $this->assertMatchesRegularExpression(
            "/cashback_user_profile|status\s*=\s*'banned'|profile_status/i",
            $body,
            'banned-fallback должен явно проверять cashback_user_profile.status=banned (defense-in-depth, не использовать ban-bucket для не-banned юзеров)'
        );
    }

    // ---------- F-S9-NEW-UNFREEZE: новый AJAX endpoint -----------------

    public function test_unfreeze_handler_registered_via_wp_ajax_action(): void
    {
        $src = $this->payouts_source();

        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'wp_ajax_cashback_payout_unfreeze'\s*,/",
            $src,
            'admin/payouts.php должен регистрировать AJAX action wp_ajax_cashback_payout_unfreeze (Session 8, F-S9-NEW-UNFREEZE)'
        );
    }

    public function test_unfreeze_handler_requires_manage_options_capability(): void
    {
        $body = $this->unfreeze_handler_body();

        $this->assertStringContainsString(
            "current_user_can('manage_options')",
            $body,
            'handle_payout_unfreeze должен требовать manage_options capability (admin-only действие)'
        );
    }

    public function test_unfreeze_handler_verifies_nonce(): void
    {
        $body = $this->unfreeze_handler_body();

        $this->assertMatchesRegularExpression(
            "/wp_verify_nonce\s*\(/",
            $body,
            'handle_payout_unfreeze должен проверять nonce (CSRF protection)'
        );
    }

    public function test_unfreeze_inserts_payout_unfreeze_ledger_row(): void
    {
        $body = $this->unfreeze_handler_body();

        $this->assertMatchesRegularExpression(
            "/INSERT INTO[\s\S]+?'payout_unfreeze'/i",
            $body,
            'handle_payout_unfreeze должен сделать INSERT ledger row с type=payout_unfreeze (источник правды)'
        );
    }

    public function test_unfreeze_uses_idempotency_key_tied_to_payout_id(): void
    {
        $body = $this->unfreeze_handler_body();

        // Идемпотентность: повторный вызов разморозки на том же payout НЕ должен
        // дважды зачислить деньги. UNIQUE на idempotency_key + ON DUPLICATE KEY = id=id.
        $this->assertMatchesRegularExpression(
            "/payout_unfreeze_'\s*\.\s*\\\$payout_id|payout_unfreeze_\{[\\\$]?payout_id\}/i",
            $body,
            'handle_payout_unfreeze должен формировать idempotency_key вида "payout_unfreeze_<payout_id>" (защита от двойной разморозки)'
        );

        $this->assertMatchesRegularExpression(
            "/ON DUPLICATE KEY UPDATE\s+id\s*=\s*id/i",
            $body,
            'INSERT ledger должен использовать ON DUPLICATE KEY UPDATE id=id (idempotency через UNIQUE индекс)'
        );
    }

    public function test_unfreeze_updates_balance_atomically_inside_transaction(): void
    {
        $body = $this->unfreeze_handler_body();

        $this->assertMatchesRegularExpression(
            "/\\\$wpdb->query\s*\(\s*'START\s+TRANSACTION'\s*\)/",
            $body,
            'handle_payout_unfreeze должен открывать транзакцию для атомарного UPDATE balance + INSERT ledger'
        );

        $this->assertMatchesRegularExpression(
            "/\\\$wpdb->query\s*\(\s*'COMMIT'\s*\)/",
            $body,
            'handle_payout_unfreeze должен COMMIT после успешного UPDATE+INSERT'
        );

        $this->assertMatchesRegularExpression(
            "/\\\$wpdb->query\s*\(\s*'ROLLBACK'\s*\)/",
            $body,
            'handle_payout_unfreeze должен ROLLBACK при exception (atomic guarantee)'
        );
    }

    public function test_unfreeze_locks_balance_row_for_update(): void
    {
        $body = $this->unfreeze_handler_body();

        $this->assertMatchesRegularExpression(
            "/SELECT[\s\S]{0,400}FROM\s+%i[\s\S]{0,200}WHERE\s+user_id\s*=\s*%d[\s\S]{0,80}FOR\s+UPDATE/i",
            $body,
            'handle_payout_unfreeze должен делать SELECT ... FOR UPDATE по cashback_user_balance (защита от race с другими транзакциями)'
        );
    }

    public function test_unfreeze_only_works_for_declined_status(): void
    {
        $body = $this->unfreeze_handler_body();

        $this->assertMatchesRegularExpression(
            "/status\s*[!=]+=*\s*'declined'|'declined'\s*[!=]+=\s*\\\$|status[\s\S]{0,40}===?\s*'declined'/i",
            $body,
            'handle_payout_unfreeze должен принимать только payout со status=declined (waiting/processing/paid/failed запрещены)'
        );
    }

    public function test_unfreeze_decrements_frozen_admin_with_greatest_clamp(): void
    {
        $body = $this->unfreeze_handler_body();

        // Защита от ухода frozen_balance_admin в минус если бэкфилл миграции
        // не покрыл legacy declined-payout (pre-v7 declined без admin-bucket attribution).
        $this->assertMatchesRegularExpression(
            "/GREATEST\s*\(\s*0\.00\s*,\s*frozen_balance_admin/i",
            $body,
            'UPDATE cashback_user_balance должен использовать GREATEST(0.00, frozen_balance_admin - X) для clamp (защита от legacy declined без backfill)'
        );
    }

    public function test_unfreeze_writes_audit_log(): void
    {
        $body = $this->unfreeze_handler_body();

        $this->assertMatchesRegularExpression(
            "/write_audit_log\s*\(\s*'payout_unfrozen'/",
            $body,
            'handle_payout_unfreeze должен писать audit_log action=payout_unfrozen с деталями (admin_id, payout_id, amount)'
        );
    }

    public function test_unfreeze_does_not_change_payout_status(): void
    {
        $body = $this->unfreeze_handler_body();

        // По решению Q2=B: status='declined' остаётся, разморозка только через
        // ledger row. UPDATE на cashback_payout_requests с SET status= НЕ должен
        // присутствовать в этом handler'е.
        $this->assertDoesNotMatchRegularExpression(
            "/UPDATE\s+%i[\s\S]{0,300}SET[\s\S]{0,200}status\s*=/i",
            $body,
            'handle_payout_unfreeze НЕ должен менять payout_requests.status (Q2=B: status=declined остаётся, reversibility видна из ledger)'
        );
    }

    // ---------- Reconciliation: payout_unfreeze учитывается ------------

    public function test_reconciliation_handles_payout_unfreeze_ledger_type(): void
    {
        $src = $this->mariadb_source();

        // validate_user_balance_consistency() должна знать про payout_unfreeze:
        //  - инициализация $sums должна включать ключ
        //  - расчётный available должен учитывать +payout_unfreeze
        //  - расчётный frozen должен вычитать |payout_unfreeze|
        $this->assertMatchesRegularExpression(
            "/'payout_unfreeze'\s*=>\s*'0\.00'/",
            $src,
            'validate_user_balance_consistency \$sums должен инициализировать payout_unfreeze (иначе reconciliation falsely flag mismatches после разморозки)'
        );
    }

    // ---------- Admin UI: кнопка «Разморозить и вернуть» --------------

    public function test_admin_ui_renders_unfreeze_button_for_declined_status(): void
    {
        $src = $this->payouts_source();

        // Кнопка видна в payout-detail page только для status=declined +
        // manage_options. Структурно — литерал имени action атрибута на кнопке
        // или CSS-класс для JS-биндинга.
        $this->assertMatchesRegularExpression(
            "/payout-unfreeze-btn|cashback-payout-unfreeze|Разморозить/u",
            $src,
            'admin/payouts.php (render_payout_detail_page) должен содержать кнопку «Разморозить и вернуть» (CSS-класс или текст для status=declined)'
        );
    }

    public function test_admin_ui_unfreeze_localizes_nonce_for_js(): void
    {
        $src = $this->payouts_source();

        // Для AJAX-вызова из JS нужен nonce — должен быть передан через
        // wp_localize_script для admin-payout-detail.js.
        $this->assertMatchesRegularExpression(
            "/cashback_payout_unfreeze|unfreezeNonce/",
            $src,
            'enqueue_admin_scripts должен localize_script с nonce для cashback_payout_unfreeze AJAX (admin-payout-detail.js)'
        );
    }
}
