<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Контрактные тесты для `admin/users-management.php::handle_bulk_update_cashback_rate` —
 * server-side дедуп request_id (Группа 5 ADR, F-34-005).
 *
 * Preview-запросы (read-only счёт пользователей) пропускают guard; apply —
 * full cycle защиты от дубля audit-лога и rate_history.
 */
#[Group('idempotency')]
#[Group('group-5')]
final class AdminUsersBulkCashbackRateIdempotencyTest extends TestCase
{
    private const HANDLER_FILE = __DIR__ . '/../../../admin/users-management.php';
    private const JS_FILE      = __DIR__ . '/../../../assets/js/admin-users-management.js';

    private function method_body(): string
    {
        $src = file_get_contents(self::HANDLER_FILE);
        $this->assertIsString($src);
        if (!preg_match('/public function handle_bulk_update_cashback_rate\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('handle_bulk_update_cashback_rate() not found');
        }
        $start = $m[0][1];
        $tail  = substr($src, $start);
        $end_pos = strpos($tail, 'private function handle_user_ban');
        $this->assertNotFalse($end_pos);
        return substr($tail, 0, (int) $end_pos);
    }

    public function test_handler_extracts_request_id_via_helper(): void
    {
        $body = $this->method_body();
        $this->assertStringContainsString('Cashback_Idempotency::normalize_request_id', $body);
    }

    public function test_handler_skips_claim_for_preview(): void
    {
        $body = $this->method_body();

        $this->assertMatchesRegularExpression(
            "/\\\$preview_raw\s*=\s*!empty\(\s*\\\$_POST\[['\"]preview['\"]\]\s*\)/",
            $body
        );
        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*!\\\$preview_raw\s*&&\s*isset\s*\(\s*\\\$_POST\[['\"]request_id['\"]\]/",
            $body
        );
    }

    public function test_handler_claims_before_transaction(): void
    {
        $body = $this->method_body();

        $claim_pos = strpos($body, 'Cashback_Idempotency::claim');
        $tx_pos    = strpos($body, "\$wpdb->query('START TRANSACTION'");

        $this->assertNotFalse($claim_pos);
        $this->assertNotFalse($tx_pos);
        $this->assertLessThan($tx_pos, $claim_pos);
    }

    public function test_handler_uses_dedicated_scope(): void
    {
        $this->assertStringContainsString("'admin_users_bulk_cashback_rate'", $this->method_body());
    }

    public function test_handler_stores_result_before_success(): void
    {
        $body = $this->method_body();

        $store_pos   = strrpos($body, 'Cashback_Idempotency::store_result');
        $success_pos = strrpos($body, 'wp_send_json_success');

        $this->assertNotFalse($store_pos);
        $this->assertLessThan($success_pos, $store_pos);
    }

    public function test_handler_releases_claim_on_all_rollbacks(): void
    {
        $body = $this->method_body();

        // Bad params, invalid new_rate, invalid old_rate, count=0, DB error, catch → 6+.
        $forget_count = substr_count($body, 'Cashback_Idempotency::forget');
        $this->assertGreaterThanOrEqual(6, $forget_count);
    }

    public function test_handler_returns_409_on_concurrent_claim(): void
    {
        $this->assertMatchesRegularExpression(
            "/Cashback_Idempotency::claim[\s\S]{0,800}?'in_progress'[\s\S]{0,300}?\),\s*409\)/",
            $this->method_body()
        );
    }

    public function test_js_sends_request_id_for_apply_branch(): void
    {
        $js = file_get_contents(self::JS_FILE);
        $this->assertIsString($js);

        // В файле два call'а с этим action: первый — preview (без request_id),
        // второй — apply (с request_id). Проверяем наличие apply-ветки с request_id.
        $this->assertMatchesRegularExpression(
            "/action:\s*'bulk_update_cashback_rate'[\s\S]{0,300}request_id:\s*makeRequestId\(\)/",
            $js
        );
    }
}
