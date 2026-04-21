<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Контрактные тесты для `claims/class-claims-admin.php::ajax_transition` —
 * server-side дедуп request_id (Группа 5 ADR, F-33-004 partial).
 *
 * В отличие от payout/transaction, ajax_transition не имеет собственной
 * транзакции — делегирует в Cashback_Claims_Manager::transition_status
 * (там START TRANSACTION). Idempotency-guard вызываем ДО делегирования,
 * чтобы двойной retry не приводил к двойной transition.
 */
#[Group('idempotency')]
#[Group('group-5')]
final class AdminClaimTransitionIdempotencyTest extends TestCase
{
    private const HANDLER_FILE = __DIR__ . '/../../../claims/class-claims-admin.php';
    private const JS_FILE      = __DIR__ . '/../../../assets/js/admin-claims.js';

    private function handler_source(): string
    {
        $source = file_get_contents(self::HANDLER_FILE);
        $this->assertIsString($source, 'class-claims-admin.php must be readable');
        return $source;
    }

    private function method_body(): string
    {
        $src = $this->handler_source();
        if (!preg_match('/public function ajax_transition\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('ajax_transition() not found');
        }
        $start = $m[0][1];
        $tail  = substr($src, $start);
        $end_pos = strpos($tail, 'public function ajax_add_note');
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

    public function test_handler_claims_before_delegating_to_manager(): void
    {
        $body = $this->method_body();

        // Idempotency-guard должен срабатывать ДО транзиционной логики,
        // чтобы ни один retry не попал в Claims_Manager::transition_status().
        $claim_pos      = strpos($body, 'Cashback_Idempotency::claim');
        $transition_pos = strpos($body, 'Cashback_Claims_Manager::transition_status');

        $this->assertNotFalse($claim_pos);
        $this->assertNotFalse($transition_pos);
        $this->assertLessThan($transition_pos, $claim_pos, 'claim() должен быть до делегирования в transition_status.');
    }

    public function test_handler_uses_dedicated_scope(): void
    {
        $body = $this->method_body();
        $this->assertStringContainsString("'admin_claim_transition'", $body);
    }

    public function test_handler_stores_result_only_on_success_branch(): void
    {
        $body = $this->method_body();

        // store_result должен быть внутри if ($result['success']).
        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*\\\$result\[['\"]success['\"]\]\s*\)[\s\S]{0,400}?Cashback_Idempotency::store_result/",
            $body,
            'store_result() должен быть в success-ветке.'
        );
    }

    public function test_handler_releases_claim_on_manager_failure(): void
    {
        $body = $this->method_body();

        // Несуспех transition_status — forget(), чтобы retry мог заново попробовать.
        $this->assertMatchesRegularExpression(
            "/else\s*\{[\s\S]{0,400}?Cashback_Idempotency::forget[\s\S]{0,200}?wp_send_json_error/",
            $body,
            'В else-ветке (неуспех manager-а) должен вызываться forget().'
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

    public function test_js_sends_request_id_for_transition(): void
    {
        $js = file_get_contents(self::JS_FILE);
        $this->assertIsString($js);

        $this->assertMatchesRegularExpression(
            "/action:\s*'claims_admin_transition'[\s\S]{0,200}request_id:\s*makeRequestId\(\)/",
            $js,
            'claims_admin_transition action должен шлать request_id для идемпотентного retry.'
        );
    }
}
