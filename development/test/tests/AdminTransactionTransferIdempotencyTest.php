<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Контрактные тесты для `admin/transactions.php::handle_transfer_unregistered` —
 * server-side дедуп request_id (Группа 5 ADR, F-35-005).
 *
 * Handler переносит unregistered-транзакцию на зарегистрированного пользователя.
 * Retry без дедупа → дубль в registered_table + повторный audit-log. Сейчас
 * два success-пути (duplicate-cleanup и нормальный transfer) + 4 rollback-точки.
 */
#[Group('idempotency')]
#[Group('group-5')]
final class AdminTransactionTransferIdempotencyTest extends TestCase
{
    private const HANDLER_FILE = __DIR__ . '/../../../admin/transactions.php';
    private const JS_FILE      = __DIR__ . '/../../../assets/js/admin-transactions.js';

    private function method_body(): string
    {
        $src = file_get_contents(self::HANDLER_FILE);
        $this->assertIsString($src);
        if (!preg_match('/public function handle_transfer_unregistered\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('handle_transfer_unregistered() not found');
        }
        $start = $m[0][1];
        $tail  = substr($src, $start);
        $end_pos = strpos($tail, 'private function get_status_label');
        $this->assertNotFalse($end_pos);
        return substr($tail, 0, (int) $end_pos);
    }

    public function test_handler_extracts_request_id(): void
    {
        $body = $this->method_body();
        $this->assertStringContainsString("\$_POST['request_id']", $body);
        $this->assertStringContainsString('Cashback_Idempotency::normalize_request_id', $body);
    }

    public function test_handler_returns_stored_result_on_retry(): void
    {
        $this->assertMatchesRegularExpression(
            '/Cashback_Idempotency::get_stored_result\s*\(/',
            $this->method_body()
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
        $this->assertStringContainsString(
            "'admin_transaction_transfer_unregistered'",
            $this->method_body()
        );
    }

    public function test_handler_stores_result_on_both_success_paths(): void
    {
        $body = $this->method_body();

        // Три wp_send_json_success: retry-path (get_stored_result), duplicate-cleanup,
        // normal transfer. Первый — возврат уже сохранённого (без store); остальные
        // два — новые обработки, каждый должен вызывать store_result.
        $store_count = substr_count($body, 'Cashback_Idempotency::store_result');
        $this->assertSame(
            2,
            $store_count,
            'Должно быть ровно 2 store_result() — для двух оригинальных success-путей (без retry-path).'
        );
    }

    public function test_handler_releases_claim_on_multiple_error_paths(): void
    {
        $body = $this->method_body();

        // Pre-TX: bad transaction_id, bad email, user not found = 3.
        // In-TX: tx not found, insert fail, delete fail, catch = 4.
        // Итого 7+.
        $forget_count = substr_count($body, 'Cashback_Idempotency::forget');
        $this->assertGreaterThanOrEqual(7, $forget_count);
    }

    public function test_handler_returns_409_on_concurrent_claim(): void
    {
        $this->assertMatchesRegularExpression(
            "/Cashback_Idempotency::claim[\s\S]{0,800}?'in_progress'[\s\S]{0,300}?\),\s*409\)/",
            $this->method_body()
        );
    }

    public function test_js_sends_request_id_for_transfer_action(): void
    {
        $js = file_get_contents(self::JS_FILE);
        $this->assertIsString($js);

        $this->assertMatchesRegularExpression(
            "/action:\s*'transfer_unregistered_transaction'[\s\S]{0,200}request_id:\s*makeRequestId\(\)/",
            $js
        );
    }
}
