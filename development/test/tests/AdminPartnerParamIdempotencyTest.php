<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Контрактные тесты для `partner/partner-management.php` — server-side дедуп
 * request_id для handle_update_network_param и handle_delete_network_param
 * (Группа 5 ADR, F-35-006).
 */
#[Group('idempotency')]
#[Group('group-5')]
final class AdminPartnerParamIdempotencyTest extends TestCase
{
    private const HANDLER_FILE = __DIR__ . '/../../../partner/partner-management.php';
    private const JS_FILE      = __DIR__ . '/../../../assets/js/admin-affiliate-network.js';

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

        // Ищем начало следующего public function (для update_network_param это конец файла)
        if (preg_match('/\n    public function /', $tail, $next_m, PREG_OFFSET_CAPTURE, 200)) {
            return substr($tail, 0, (int) $next_m[0][1]);
        }
        return $tail;
    }

    // ────────────── delete_network_param ──────────────

    public function test_delete_extracts_request_id(): void
    {
        $body = $this->method_body('handle_delete_network_param');
        $this->assertStringContainsString('Cashback_Idempotency::normalize_request_id', $body);
    }

    public function test_delete_uses_dedicated_scope(): void
    {
        $this->assertStringContainsString(
            "'admin_partner_param_delete'",
            $this->method_body('handle_delete_network_param')
        );
    }

    public function test_delete_stores_result_before_success(): void
    {
        $body = $this->method_body('handle_delete_network_param');

        $store_pos   = strrpos($body, 'Cashback_Idempotency::store_result');
        $success_pos = strrpos($body, 'wp_send_json_success');

        $this->assertNotFalse($store_pos);
        $this->assertLessThan($success_pos, $store_pos);
    }

    public function test_delete_returns_409_on_concurrent_claim(): void
    {
        $this->assertMatchesRegularExpression(
            "/Cashback_Idempotency::claim[\s\S]{0,800}?'in_progress'[\s\S]{0,300}?\),\s*409\)/",
            $this->method_body('handle_delete_network_param')
        );
    }

    // ────────────── update_network_param ──────────────

    public function test_update_extracts_request_id(): void
    {
        $body = $this->method_body('handle_update_network_param');
        $this->assertStringContainsString('Cashback_Idempotency::normalize_request_id', $body);
    }

    public function test_update_uses_dedicated_scope(): void
    {
        $this->assertStringContainsString(
            "'admin_partner_param_update'",
            $this->method_body('handle_update_network_param')
        );
    }

    public function test_update_stores_result_before_success(): void
    {
        $body = $this->method_body('handle_update_network_param');

        $store_pos   = strrpos($body, 'Cashback_Idempotency::store_result');
        $success_pos = strrpos($body, 'wp_send_json_success');

        $this->assertNotFalse($store_pos);
        $this->assertLessThan($success_pos, $store_pos);
    }

    public function test_update_releases_claim_on_errors(): void
    {
        $body = $this->method_body('handle_update_network_param');
        $forget_count = substr_count($body, 'Cashback_Idempotency::forget');
        $this->assertGreaterThanOrEqual(2, $forget_count);
    }

    // ────────────── JS ──────────────

    public function test_js_sends_request_id_for_update_and_delete(): void
    {
        $js = file_get_contents(self::JS_FILE);
        $this->assertIsString($js);

        $this->assertMatchesRegularExpression(
            "/action:\s*'update_network_param'[\s\S]{0,200}request_id:\s*makeRequestId\(\)/",
            $js
        );
        $this->assertMatchesRegularExpression(
            "/action:\s*'delete_network_param'[\s\S]{0,200}request_id:\s*makeRequestId\(\)/",
            $js
        );
    }
}
