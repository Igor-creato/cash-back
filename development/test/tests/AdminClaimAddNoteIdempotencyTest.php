<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Контрактные тесты для `claims/class-claims-admin.php::ajax_add_note` —
 * server-side дедуп request_id (Группа 5 ADR, F-33-004 partial).
 *
 * ajax_add_note() делегирует в Cashback_Claims_Manager::add_note();
 * idempotency-guard срабатывает ДО делегирования, так что retry не создаёт
 * дубля admin-комментария.
 */
#[Group('idempotency')]
#[Group('group-5')]
final class AdminClaimAddNoteIdempotencyTest extends TestCase
{
    private const HANDLER_FILE = __DIR__ . '/../../../claims/class-claims-admin.php';
    private const JS_FILE      = __DIR__ . '/../../../assets/js/admin-claims.js';

    private function handler_source(): string
    {
        $source = file_get_contents(self::HANDLER_FILE);
        $this->assertIsString($source);
        return $source;
    }

    private function method_body(): string
    {
        $src = $this->handler_source();
        if (!preg_match('/public function ajax_add_note\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('ajax_add_note() not found');
        }
        $start = $m[0][1];
        $tail  = substr($src, $start);
        $end_pos = strpos($tail, 'public function ajax_get_detail');
        $this->assertNotFalse($end_pos);
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
        $this->assertMatchesRegularExpression('/Cashback_Idempotency::get_stored_result\s*\(/', $body);
    }

    public function test_handler_claims_before_delegating(): void
    {
        $body = $this->method_body();

        $claim_pos    = strpos($body, 'Cashback_Idempotency::claim');
        $delegate_pos = strpos($body, 'Cashback_Claims_Manager::add_note');

        $this->assertNotFalse($claim_pos);
        $this->assertNotFalse($delegate_pos);
        $this->assertLessThan($delegate_pos, $claim_pos, 'claim() должен быть до add_note().');
    }

    public function test_handler_uses_dedicated_scope(): void
    {
        $body = $this->method_body();
        $this->assertStringContainsString("'admin_claim_add_note'", $body);
    }

    public function test_handler_stores_result_only_on_success(): void
    {
        $body = $this->method_body();

        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*\\\$result\[['\"]success['\"]\]\s*\)[\s\S]{0,400}?Cashback_Idempotency::store_result/",
            $body
        );
    }

    public function test_handler_releases_claim_on_failure(): void
    {
        $body = $this->method_body();

        $this->assertMatchesRegularExpression(
            "/else\s*\{[\s\S]{0,400}?Cashback_Idempotency::forget[\s\S]{0,200}?wp_send_json_error/",
            $body
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

    public function test_js_sends_request_id_for_add_note(): void
    {
        $js = file_get_contents(self::JS_FILE);
        $this->assertIsString($js);

        $this->assertMatchesRegularExpression(
            "/action:\s*'claims_admin_add_note'[\s\S]{0,200}request_id:\s*makeRequestId\(\)/",
            $js
        );
    }
}
