<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Контрактные тесты для `admin/bank-management.php::handle_update_bank` —
 * server-side дедуп request_id (Группа 5 ADR, F-37-004).
 *
 * Простой UPDATE без транзакции. JS (admin-bank-management.js) уже шлёт
 * request_id через makeRequestId() — этот коммит замыкает серверную половину.
 */
#[Group('idempotency')]
#[Group('group-5')]
final class AdminBankUpdateIdempotencyTest extends TestCase
{
    private const HANDLER_FILE = __DIR__ . '/../../../admin/bank-management.php';
    private const JS_FILE      = __DIR__ . '/../../../assets/js/admin-bank-management.js';

    private function method_body(): string
    {
        $src = file_get_contents(self::HANDLER_FILE);
        $this->assertIsString($src);
        if (!preg_match('/public function handle_update_bank\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('handle_update_bank() not found');
        }
        $start = $m[0][1];
        $tail  = substr($src, $start);
        $end_pos = strpos($tail, 'public function handle_add_bank');
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

    public function test_handler_uses_dedicated_scope(): void
    {
        $this->assertStringContainsString("'admin_bank_update'", $this->method_body());
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

    public function test_handler_releases_claim_on_error_paths(): void
    {
        $body = $this->method_body();

        // Bad params + DB error — каждый освобождает слот.
        $forget_count = substr_count($body, 'Cashback_Idempotency::forget');
        $this->assertGreaterThanOrEqual(2, $forget_count, 'Оба error-пути должны вызывать forget().');
    }

    public function test_handler_returns_409_on_concurrent_claim(): void
    {
        $this->assertMatchesRegularExpression(
            "/Cashback_Idempotency::claim[\s\S]{0,800}?'in_progress'[\s\S]{0,300}?\),\s*409\)/",
            $this->method_body()
        );
    }

    public function test_js_sends_request_id_for_update_bank(): void
    {
        $js = file_get_contents(self::JS_FILE);
        $this->assertIsString($js);

        $this->assertMatchesRegularExpression(
            "/'action':\s*'update_bank'[\s\S]{0,200}'request_id':\s*makeRequestId\(\)/",
            $js
        );
    }
}
