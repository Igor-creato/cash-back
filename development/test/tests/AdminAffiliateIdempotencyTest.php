<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Контрактные тесты для 5 admin-AJAX хендлеров в
 * `affiliate/class-affiliate-admin.php` — server-side дедуп request_id
 * (Группа 5 ADR, F-35-006).
 *
 * Методы:
 *   - handle_toggle_module             → scope admin_affiliate_toggle_module
 *   - handle_save_settings             → scope admin_affiliate_save_settings
 *   - handle_update_partner (dispatcher) → scope admin_affiliate_update_partner (claim-only;
 *     sub-операции update_partner_rate/toggle_partner_status идемпотентны на БД-уровне)
 *   - handle_bulk_update_commission_rate → scope admin_affiliate_bulk_update_commission_rate
 *     (preview-запросы пропускают guard; apply — full cycle)
 *   - handle_edit_accrual              → scope admin_affiliate_edit_accrual
 */
#[Group('idempotency')]
#[Group('group-5')]
final class AdminAffiliateIdempotencyTest extends TestCase
{
    private const HANDLER_FILE = __DIR__ . '/../../../affiliate/class-affiliate-admin.php';
    private const JS_FILE      = __DIR__ . '/../../../assets/js/admin-affiliate.js';

    private function handler_source(): string
    {
        $src = file_get_contents(self::HANDLER_FILE);
        $this->assertIsString($src);
        return $src;
    }

    private function method_body( string $method ): string
    {
        $src = $this->handler_source();
        if (!preg_match('/public function ' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail($method . '() not found');
        }
        $start = $m[0][1];
        $tail  = substr($src, $start);

        // Берём до следующего function / комментария-разделителя на уровне класса.
        if (preg_match('/\n    (?:public|private) function |\n    \/\* ═══/', $tail, $next_m, PREG_OFFSET_CAPTURE, 200)) {
            return substr($tail, 0, (int) $next_m[0][1]);
        }
        return $tail;
    }

    // ────────────── toggle_module (simple, full cycle) ──────────────

    public function test_toggle_module_full_cycle(): void
    {
        $body = $this->method_body('handle_toggle_module');

        $this->assertStringContainsString('Cashback_Idempotency::normalize_request_id', $body);
        $this->assertStringContainsString("'admin_affiliate_toggle_module'", $body);
        $this->assertMatchesRegularExpression('/Cashback_Idempotency::get_stored_result\s*\(/', $body);
        $this->assertMatchesRegularExpression('/Cashback_Idempotency::claim\s*\(/', $body);

        $store_pos   = strrpos($body, 'Cashback_Idempotency::store_result');
        $success_pos = strrpos($body, 'wp_send_json_success');
        $this->assertNotFalse($store_pos);
        $this->assertLessThan($success_pos, $store_pos);
    }

    public function test_toggle_module_returns_409_on_concurrent_claim(): void
    {
        $this->assertMatchesRegularExpression(
            "/Cashback_Idempotency::claim[\s\S]{0,800}?'in_progress'[\s\S]{0,300}?\),\s*409\)/",
            $this->method_body('handle_toggle_module')
        );
    }

    // ────────────── save_settings (simple, full cycle) ──────────────

    public function test_save_settings_full_cycle(): void
    {
        $body = $this->method_body('handle_save_settings');

        $this->assertStringContainsString("'admin_affiliate_save_settings'", $body);

        $store_pos   = strrpos($body, 'Cashback_Idempotency::store_result');
        $success_pos = strrpos($body, 'wp_send_json_success');
        $this->assertNotFalse($store_pos);
        $this->assertLessThan($success_pos, $store_pos);
    }

    public function test_save_settings_returns_409_on_concurrent_claim(): void
    {
        $this->assertMatchesRegularExpression(
            "/Cashback_Idempotency::claim[\s\S]{0,800}?'in_progress'[\s\S]{0,300}?\),\s*409\)/",
            $this->method_body('handle_save_settings')
        );
    }

    // ────────────── update_partner (dispatcher, claim-only) ──────────────

    public function test_update_partner_dispatcher_claims_slot(): void
    {
        $body = $this->method_body('handle_update_partner');

        $this->assertStringContainsString("'admin_affiliate_update_partner'", $body);
        $this->assertMatchesRegularExpression('/Cashback_Idempotency::claim\s*\(/', $body);

        $this->assertMatchesRegularExpression(
            "/Cashback_Idempotency::claim[\s\S]{0,800}?'in_progress'[\s\S]{0,300}?\),\s*409\)/",
            $body
        );
    }

    public function test_update_partner_releases_on_bad_params(): void
    {
        $body = $this->method_body('handle_update_partner');

        // Неверный user_id и 'Неизвестное действие' — оба forget().
        $forget_count = substr_count($body, 'Cashback_Idempotency::forget');
        $this->assertGreaterThanOrEqual(2, $forget_count);
    }

    // ────────────── bulk_update (preview-aware) ──────────────

    public function test_bulk_update_full_cycle_for_apply(): void
    {
        $body = $this->method_body('handle_bulk_update_commission_rate');

        $this->assertStringContainsString("'admin_affiliate_bulk_update_commission_rate'", $body);
        $this->assertMatchesRegularExpression('/Cashback_Idempotency::get_stored_result\s*\(/', $body);

        $store_pos   = strrpos($body, 'Cashback_Idempotency::store_result');
        $success_pos = strrpos($body, 'wp_send_json_success');
        $this->assertNotFalse($store_pos);
        $this->assertLessThan($success_pos, $store_pos);
    }

    public function test_bulk_update_skips_claim_for_preview(): void
    {
        $body = $this->method_body('handle_bulk_update_commission_rate');

        // Preview-ветка должна читать $_POST['preview'] ДО извлечения request_id,
        // чтобы не блокировать read-only запросы.
        $this->assertMatchesRegularExpression(
            "/\\\$preview_raw\s*=\s*!empty\(\s*\\\$_POST\[['\"]preview['\"]\]\s*\)/",
            $body,
            'bulk_update должен различать preview и apply на уровне извлечения request_id.'
        );
        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*!\\\$preview_raw\s*&&\s*isset\s*\(\s*\\\$_POST\[['\"]request_id['\"]\]/",
            $body,
            'request_id извлекается только для apply-запросов.'
        );
    }

    public function test_bulk_update_releases_claim_on_all_rollbacks(): void
    {
        $body = $this->method_body('handle_bulk_update_commission_rate');

        // В apply-ветке много error-paths: bad params, invalid rates, not found, DB error, rollback.
        $forget_count = substr_count($body, 'Cashback_Idempotency::forget');
        $this->assertGreaterThanOrEqual(5, $forget_count);
    }

    // ────────────── edit_accrual (full, multi-rollback) ──────────────

    public function test_edit_accrual_full_cycle(): void
    {
        $body = $this->method_body('handle_edit_accrual');

        $this->assertStringContainsString("'admin_affiliate_edit_accrual'", $body);
        $this->assertMatchesRegularExpression('/Cashback_Idempotency::get_stored_result\s*\(/', $body);
        $this->assertMatchesRegularExpression('/Cashback_Idempotency::claim\s*\(/', $body);

        $store_pos   = strrpos($body, 'Cashback_Idempotency::store_result');
        $success_pos = strrpos($body, 'wp_send_json_success');
        $this->assertNotFalse($store_pos);
        $this->assertLessThan($success_pos, $store_pos);
    }

    public function test_edit_accrual_returns_409_on_concurrent_claim(): void
    {
        $this->assertMatchesRegularExpression(
            "/Cashback_Idempotency::claim[\s\S]{0,800}?'in_progress'[\s\S]{0,300}?\),\s*409\)/",
            $this->method_body('handle_edit_accrual')
        );
    }

    public function test_edit_accrual_releases_claim_on_rollback_points(): void
    {
        $body = $this->method_body('handle_edit_accrual');

        // 7+ error-paths (no accrual_id, rate OOB, amount negative, not found, final status,
        // bad transition, empty update, DB error).
        $forget_count = substr_count($body, 'Cashback_Idempotency::forget');
        $this->assertGreaterThanOrEqual(6, $forget_count);
    }

    // ────────────── JS (все 5 actions уже шлют request_id) ──────────────

    public function test_js_sends_request_id_for_all_affiliate_actions(): void
    {
        $js = file_get_contents(self::JS_FILE);
        $this->assertIsString($js);

        $actions = array(
            'affiliate_toggle_module',
            'affiliate_save_settings',
            'affiliate_update_partner',
            'affiliate_edit_accrual',
        );
        foreach ($actions as $action) {
            $this->assertMatchesRegularExpression(
                "/action:\s*'" . preg_quote($action, '/') . "'[\s\S]{0,400}request_id:\s*makeRequestId\(\)/",
                $js,
                "JS action '{$action}' должен шлать request_id."
            );
        }

        // Apply-ветка bulk_update тоже шлёт request_id (preview — нет, и это ок).
        $this->assertMatchesRegularExpression(
            "/action:\s*'affiliate_bulk_update_commission_rate'[\s\S]{0,400}request_id:\s*makeRequestId\(\)/",
            $js
        );
    }
}
