<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Контрактные тесты для `support/admin-support.php::handle_admin_reply` —
 * server-side дедуп request_id (Группа 5 ADR, F-27-002).
 *
 * Handler с START TRANSACTION + FOR UPDATE + несколькими ROLLBACK-точками +
 * вложениями (processed после COMMIT) + email-уведомлением. Retry без дедупа
 * → дубль admin-сообщения, повторная смена статуса тикета, повторный email.
 */
#[Group('idempotency')]
#[Group('group-5')]
final class AdminSupportReplyIdempotencyTest extends TestCase
{
    private const HANDLER_FILE = __DIR__ . '/../../../support/admin-support.php';

    private function handler_source(): string
    {
        $source = file_get_contents(self::HANDLER_FILE);
        $this->assertIsString($source, 'support/admin-support.php must be readable');
        return $source;
    }

    private function method_body(): string
    {
        $src = $this->handler_source();
        if (!preg_match('/public function handle_admin_reply\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('handle_admin_reply() not found');
        }
        $start = $m[0][1];
        $tail  = substr($src, $start);
        $end_pos = strpos($tail, 'public function handle_change_status');
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
        $this->assertMatchesRegularExpression('/Cashback_Idempotency::get_stored_result\s*\(/', $body);
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
        $this->assertStringContainsString("'support_admin_reply'", $body);
    }

    public function test_handler_stores_result_before_success_response(): void
    {
        $body = $this->method_body();

        $store_pos   = strrpos($body, 'Cashback_Idempotency::store_result');
        $success_pos = strrpos($body, 'wp_send_json_success');

        $this->assertNotFalse($store_pos);
        $this->assertNotFalse($success_pos);
        $this->assertLessThan($success_pos, $store_pos);
    }

    public function test_handler_releases_claim_on_all_rollback_points(): void
    {
        $body = $this->method_body();

        // В support/admin-reply есть 4 ROLLBACK-точки (ticket not found, closed,
        // insert failure, update failure). Каждая должна освободить idempotency-слот.
        $rollback_count = substr_count($body, "\$wpdb->query('ROLLBACK')");
        $forget_count   = substr_count($body, 'Cashback_Idempotency::forget');

        $this->assertGreaterThanOrEqual(
            $rollback_count,
            $forget_count,
            'Кол-во forget() должно быть ≥ кол-ва ROLLBACK (каждый ROLLBACK + pre-TX bad-params сбрасывает слот).'
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
    // Inline JS в admin-support.php для кнопки Ответ
    // ────────────────────────────────────────────────────────────

    public function test_inline_js_generates_request_id_and_sends(): void
    {
        $src = $this->handler_source();

        $this->assertStringContainsString(
            'crypto.randomUUID',
            $src,
            'Inline JS должен использовать crypto.randomUUID() для request_id.'
        );

        $this->assertMatchesRegularExpression(
            "/fd\.append\(\s*'request_id',\s*requestId\s*\)/",
            $src,
            'Inline JS должен пробрасывать request_id в FormData.'
        );
    }

    public function test_inline_js_resets_request_id_on_success(): void
    {
        $src = $this->handler_source();

        $this->assertStringContainsString(
            "removeData('cb-request-id')",
            $src,
            'Inline JS должен сбрасывать cb-request-id на success — следующий клик получит свежий UUID.'
        );
    }
}
