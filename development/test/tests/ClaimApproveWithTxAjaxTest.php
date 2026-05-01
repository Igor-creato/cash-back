<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты combined AJAX-обработчика claim approve + INSERT tx
 * (P0.1 F-S7-NO-MANUAL-CREDIT, run-h backlog).
 *
 * Source-based: парсят PHP-файлы и проверяют ключевые конструкции —
 * nonce, capability, idempotency, FOR UPDATE, INSERT mapping, audit-log,
 * атомарный flow transition + INSERT в одной TX.
 *
 * Контракт нового handler'а `Cashback_Claims_Admin::ajax_approve_with_tx`:
 *  - AJAX hook: `wp_ajax_cashback_claim_approve_with_tx`.
 *  - Nonce: переиспользует `cashback_stuck_claim_nonce` (тот же UI flow,
 *    тот же набор полей comission + funds_ready).
 *  - Capability: `manage_options`.
 *  - Idempotency: scope `admin_claim_approve_with_tx`, ключ `request_id`.
 *  - Inputs: claim_id (absint), comission (string regex `^\d+(\.\d{1,2})?$`,
 *    > 0), funds_ready (strict string '0' | '1'), request_id (UUIDv4).
 *  - Atomic flow: START TRANSACTION → SELECT claim FOR UPDATE → assert
 *    status IN ('submitted','sent_to_network') → UPDATE status='approved'
 *    → log_event → SELECT existing tx FOR UPDATE → INSERT tx → audit ×3
 *    → COMMIT.
 *  - audit actions: `claim_approved`, `manual_tx_from_stuck_claim`,
 *    `transaction_created` (последние два переиспользуем из existing
 *    catalog с `source='claim_approve'` в details — без drift в ADR
 *    audit-log-completeness).
 *  - INSERT mapping: api_verified=1, order_status='completed',
 *    currency='RUB', created_by_admin=1, idempotency_key=`manual_claim_<id>`
 *    (UNIQUE на cashback_transactions защищает от race с stuck-claim-tx).
 *  - Reuse: `Cashback_Balance_Reconciliation_Admin::resolve_product_name`
 *    + `resolve_network_name` (оба становятся public static в commit A).
 */
#[Group('claims')]
#[Group('group-p0-1')]
final class ClaimApproveWithTxAjaxTest extends TestCase
{
    private function source(string $rel): string
    {
        $path    = dirname(__DIR__, 3) . '/' . $rel;
        $content = file_get_contents($path);
        $this->assertIsString($content, "{$rel} must be readable");
        return $content;
    }

    private function admin_src(): string
    {
        return $this->source('claims/class-claims-admin.php');
    }

    private function recon_src(): string
    {
        return $this->source('admin/class-cashback-balance-reconciliation-admin.php');
    }

    private function method_body(string $method_name, string $end_marker): string
    {
        $src = $this->admin_src();
        if (!preg_match('/public function ' . preg_quote($method_name, '/') . '\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail($method_name . '() not found');
        }
        $start   = (int) $m[0][1];
        $tail    = substr($src, $start);
        $end_pos = strpos($tail, $end_marker);
        $this->assertNotFalse($end_pos, $method_name . ' end marker not found');
        return substr($tail, 0, (int) $end_pos);
    }

    // =========================================================================
    // Регистрация AJAX hook + сигнатура
    // =========================================================================

    public function test_init_registers_approve_with_tx_ajax_hook(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'wp_ajax_cashback_claim_approve_with_tx'\s*,/",
            $this->admin_src(),
            'constructor должен регистрировать wp_ajax_cashback_claim_approve_with_tx'
        );
    }

    public function test_handler_method_exists(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+function\s+ajax_approve_with_tx\s*\(\s*\)\s*:\s*void/',
            $this->admin_src(),
            'ajax_approve_with_tx должен быть public function(): void'
        );
    }

    // =========================================================================
    // Nonce + capability
    // =========================================================================

    public function test_handler_uses_nonce_check(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertStringContainsString(
            'wp_verify_nonce',
            $body,
            'handler должен проверять nonce через wp_verify_nonce'
        );
        $this->assertStringContainsString(
            "'cashback_stuck_claim_nonce'",
            $body,
            'nonce action — переиспользуем cashback_stuck_claim_nonce (общий UI)'
        );
    }

    public function test_handler_checks_manage_options(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertMatchesRegularExpression(
            "/current_user_can\(\s*'manage_options'\s*\)/",
            $body,
            'manage_options должен проверяться'
        );
    }

    // =========================================================================
    // Idempotency (Группа 5 ADR)
    // =========================================================================

    public function test_handler_uses_idempotency_dedicated_scope(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertStringContainsString(
            "'admin_claim_approve_with_tx'",
            $body,
            'idempotency scope должен быть admin_claim_approve_with_tx (отдельный от stuck-claim)'
        );
        $this->assertStringContainsString(
            'Cashback_Idempotency::claim',
            $body,
            'handler должен вызывать Cashback_Idempotency::claim'
        );
        $this->assertStringContainsString(
            'Cashback_Idempotency::normalize_request_id',
            $body,
            'request_id должен пройти normalize_request_id'
        );
        $this->assertStringContainsString(
            'Cashback_Idempotency::store_result',
            $body,
            'store_result должен сохранять результат для idempotent retry'
        );
        $this->assertStringContainsString(
            'Cashback_Idempotency::forget',
            $body,
            'forget должен вызываться на ROLLBACK / валидационных отказах'
        );
    }

    // =========================================================================
    // Валидация comission + funds_ready (контракт идентичен stuck-claim-tx)
    // =========================================================================

    public function test_handler_validates_comission_strict_regex(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertMatchesRegularExpression(
            "#preg_match\(\s*'/\\^\\\\d\\+\\(\\\\\\.\\\\d\\{1,2\\}\\)\\?\\$/'#",
            $body,
            'comission должна валидироваться строгой regex ^\\d+(\\.\\d{1,2})?$'
        );
    }

    public function test_handler_rejects_zero_comission(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        // bccomp(>0) сравнение, fallback — float > 0.
        $this->assertMatchesRegularExpression(
            '/bccomp\(\s*\$raw_comission\s*,\s*[\'"]0[\'"]|float\)\s*\$raw_comission\s*>\s*0\.0/',
            $body,
            'комиссия должна быть строго > 0'
        );
    }

    public function test_handler_strict_string_funds_ready(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertMatchesRegularExpression(
            "/\\\$raw_funds_ready\s*!==\s*'0'\s*&&\s*\\\$raw_funds_ready\s*!==\s*'1'/",
            $body,
            'funds_ready должен сравниваться строго со строками 0 и 1 ДО cast'
        );
    }

    // =========================================================================
    // Atomic flow: START TRANSACTION + FOR UPDATE
    // =========================================================================

    public function test_handler_wraps_writes_in_transaction(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertMatchesRegularExpression(
            '/START TRANSACTION[\s\S]+?COMMIT/i',
            $body,
            'все mutation должны быть обёрнуты в START TRANSACTION/COMMIT'
        );
        $this->assertStringContainsString(
            'ROLLBACK',
            $body,
            'на исключение должен быть ROLLBACK'
        );
    }

    public function test_handler_locks_claim_for_update(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertMatchesRegularExpression(
            '/SELECT[\s\S]+?cashback_claims[\s\S]+?FOR UPDATE|claims_table[\s\S]+?FOR UPDATE/i',
            $body,
            'SELECT claim должен использовать FOR UPDATE'
        );
    }

    public function test_handler_rejects_invalid_status_for_transition_to_approved(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        // Допустимые источники для approved: submitted, sent_to_network.
        // Текущий статус должен быть проверен ДО UPDATE.
        $this->assertStringContainsString(
            "'submitted'",
            $body,
            'статус submitted должен быть в списке allowed sources для approved'
        );
        $this->assertStringContainsString(
            "'sent_to_network'",
            $body,
            'статус sent_to_network должен быть в списке allowed sources для approved'
        );
    }

    public function test_handler_updates_claim_status_to_approved(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        // Прямой UPDATE на cashback_claims внутри той же TX.
        $this->assertMatchesRegularExpression(
            '/UPDATE %i SET status = %s/i',
            $body,
            'UPDATE claims.status должен использовать prepared с %i + %s'
        );
    }

    public function test_handler_logs_event_via_claims_manager(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertStringContainsString(
            'Cashback_Claims_Manager::log_event',
            $body,
            'event для claim_events должен идти через public log_event helper'
        );
    }

    public function test_handler_locks_existing_tx_for_update(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertMatchesRegularExpression(
            '/SELECT id FROM %i WHERE user_id = %d AND click_id = %s LIMIT 1 FOR UPDATE/i',
            $body,
            'pre-flight check существующей tx должен использовать FOR UPDATE (race-safe)'
        );
    }

    // =========================================================================
    // INSERT mapping (контракт идентичен handle_create_stuck_claim_tx)
    // =========================================================================

    public function test_handler_uses_idempotency_key_manual_claim(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertMatchesRegularExpression(
            "/'manual_claim_'\s*\.\s*\\\$claim_id/",
            $body,
            'idempotency_key для tx должен быть manual_claim_<claim_id> (UNIQUE)'
        );
    }

    public function test_handler_inserts_api_verified_one(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertMatchesRegularExpression(
            "/'api_verified'\s*=>\s*1/",
            $body,
            'api_verified должен ставиться = 1'
        );
    }

    public function test_handler_inserts_order_status_completed(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertMatchesRegularExpression(
            "/'order_status'\s*=>\s*'completed'/",
            $body,
            "order_status должен быть 'completed'"
        );
    }

    public function test_handler_inserts_currency_rub(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertMatchesRegularExpression(
            "/'currency'\s*=>\s*'RUB'/",
            $body,
            "currency должна быть 'RUB' по умолчанию"
        );
    }

    public function test_handler_inserts_created_by_admin_one(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertMatchesRegularExpression(
            "/'created_by_admin'\s*=>\s*1/",
            $body,
            'created_by_admin должен ставиться = 1'
        );
    }

    public function test_handler_does_not_set_reference_id_or_cashback_in_insert(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        // Триггер calculate_cashback_before_insert сам ставит reference_id и cashback.
        $matched = preg_match(
            '/\$insert_data\s*=\s*array\((.*?)\);/s',
            $body,
            $m
        );
        $this->assertSame(1, $matched, 'не нашёл блок $insert_data = array(...);');
        $insert_block = (string) $m[1];

        $this->assertDoesNotMatchRegularExpression(
            "/'reference_id'\s*=>/",
            $insert_block,
            'reference_id не должен передаваться в INSERT — его генерирует триггер'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/'cashback'\s*=>/",
            $insert_block,
            'cashback не должен передаваться в INSERT — его рассчитывает триггер'
        );
    }

    // =========================================================================
    // Audit-log: 3 actions (claim_approved + manual_tx + transaction_created)
    // =========================================================================

    public function test_handler_writes_audit_claim_approved(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertStringContainsString(
            "'claim_approved'",
            $body,
            'audit-action claim_approved должен писаться'
        );
    }

    public function test_handler_writes_audit_manual_tx_from_stuck_claim_with_source(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        // Переиспользуем существующий action из catalog'а; differentiation идёт через
        // details.source='claim_approve' (не плодим действий в audit-log inventory).
        $this->assertStringContainsString(
            "'manual_tx_from_stuck_claim'",
            $body,
            "audit-action manual_tx_from_stuck_claim должен переиспользоваться"
        );
        $this->assertMatchesRegularExpression(
            "/'source'\s*=>\s*'claim_approve'/",
            $body,
            "source='claim_approve' должен быть в details для дифференциации от 14-day stuck flow"
        );
    }

    public function test_handler_writes_audit_transaction_created_with_source(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertStringContainsString(
            "'transaction_created'",
            $body,
            'audit catalog-action transaction_created должен писаться'
        );
    }

    // =========================================================================
    // Reuse Reconciliation_Admin resolve helpers (visibility commit A)
    // =========================================================================

    public function test_resolve_product_name_is_public_static(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+resolve_product_name\s*\(\s*array\s+\$claim\s*\)\s*:\s*string/',
            $this->recon_src(),
            'resolve_product_name должен стать public static (refactor — переиспользуем из claims-admin)'
        );
    }

    public function test_resolve_network_name_is_public_static(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+resolve_network_name\s*\(\s*string\s+\$cpa_slug\s*\)\s*:\s*string/',
            $this->recon_src(),
            'resolve_network_name должен стать public static (refactor — переиспользуем из claims-admin)'
        );
    }

    public function test_handler_calls_resolve_helpers_via_recon_admin(): void
    {
        $body = $this->method_body('ajax_approve_with_tx', 'private function');
        $this->assertStringContainsString(
            'Cashback_Balance_Reconciliation_Admin::resolve_product_name',
            $body,
            'product name должен резолвиться через shared helper (DRY с stuck-claim-tx)'
        );
        $this->assertStringContainsString(
            'Cashback_Balance_Reconciliation_Admin::resolve_network_name',
            $body,
            'network name должен резолвиться через shared helper (DRY с stuck-claim-tx)'
        );
    }
}
