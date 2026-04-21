<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Контрактные тесты для `admin/transactions.php::handle_update_transaction` —
 * server-side дедуп request_id (Группа 5 ADR, F-35-002).
 *
 * JS уже шлёт `request_id` через makeRequestId() (iter-35). Эти тесты
 * фиксируют серверный контракт и инвариант, что клиентский патч не регрессирует.
 */
#[Group('idempotency')]
#[Group('group-5')]
final class AdminTransactionIdempotencyTest extends TestCase
{
    private const HANDLER_FILE = __DIR__ . '/../../../admin/transactions.php';
    private const JS_FILE      = __DIR__ . '/../../../assets/js/admin-transactions.js';

    private function handler_source(): string
    {
        $source = file_get_contents(self::HANDLER_FILE);
        $this->assertIsString($source, 'admin/transactions.php must be readable');
        return $source;
    }

    private function method_body(): string
    {
        $src = $this->handler_source();
        if (!preg_match('/public function handle_update_transaction\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('handle_update_transaction() not found');
        }
        $start = $m[0][1];
        $tail  = substr($src, $start);
        $end_pos = strpos($tail, 'public function handle_get_transaction');
        $this->assertNotFalse($end_pos, 'method end marker not found');
        return substr($tail, 0, (int) $end_pos);
    }

    public function test_handler_extracts_request_id_via_helper(): void
    {
        $body = $this->method_body();

        $this->assertStringContainsString("\$_POST['request_id']", $body);
        $this->assertStringContainsString('Cashback_Idempotency::normalize_request_id', $body);
    }

    public function test_handler_returns_stored_result_on_retry(): void
    {
        $body = $this->method_body();

        $this->assertMatchesRegularExpression(
            '/Cashback_Idempotency::get_stored_result\s*\(/',
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
        $this->assertLessThan($tx_pos, $claim_pos, 'claim() должен быть до START TRANSACTION.');
    }

    public function test_handler_uses_dedicated_scope(): void
    {
        $body = $this->method_body();
        $this->assertStringContainsString("'admin_transaction_update'", $body);
    }

    public function test_handler_stores_result_before_success(): void
    {
        $body = $this->method_body();

        $store_pos   = strrpos($body, 'Cashback_Idempotency::store_result');
        $success_pos = strrpos($body, 'wp_send_json_success');

        $this->assertNotFalse($store_pos);
        $this->assertNotFalse($success_pos);
        $this->assertLessThan($success_pos, $store_pos);
    }

    public function test_handler_releases_claim_on_catch(): void
    {
        $body = $this->method_body();

        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\?Throwable\s+\$e\s*\)\s*\{[^}]*Cashback_Idempotency::forget/s',
            $body,
            'В catch-блоке должен освобождаться idempotency-слот.'
        );
    }

    public function test_handler_returns_409_on_concurrent_claim(): void
    {
        $body = $this->method_body();

        $this->assertMatchesRegularExpression(
            "/Cashback_Idempotency::claim[\s\S]{0,800}?'in_progress'[\s\S]{0,300}?\),\s*409\)/",
            $body
        );
    }

    public function test_handler_preserves_backward_compat(): void
    {
        $body = $this->method_body();

        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*\\\$idem_request_id\s*!==\s*''\s*\)/",
            $body
        );
    }

    // ────────────────────────────────────────────────────────────
    // JS: request_id уже шлётся через makeRequestId() (iter-35)
    // ────────────────────────────────────────────────────────────

    public function test_js_sends_request_id_for_update_transaction(): void
    {
        $js = file_get_contents(self::JS_FILE);
        $this->assertIsString($js);

        $this->assertStringContainsString('function makeRequestId', $js, 'makeRequestId() helper должен присутствовать');
        $this->assertMatchesRegularExpression(
            "/action:\s*'update_transaction'[\s\S]{0,200}request_id:\s*makeRequestId\(\)/",
            $js,
            'update_transaction action должен шлать request_id для идемпотентного retry.'
        );
    }
}
