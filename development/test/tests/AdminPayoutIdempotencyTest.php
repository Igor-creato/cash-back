<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Контрактные тесты для `admin/payouts.php::handle_update_payout_request` —
 * server-side дедуп request_id (Группа 5 ADR, F-32-004).
 *
 * Методика: source-string invariants + JS-патч на клиентской стороне.
 *
 * Мы НЕ можем функционально исполнить хендлер без полного wpdb-mocking,
 * поэтому проверяем инварианты через анализ исходника (как
 * AffiliateCommissionIdempotencyTest.php в том же каталоге).
 */
#[Group('idempotency')]
#[Group('group-5')]
final class AdminPayoutIdempotencyTest extends TestCase
{
    private const HANDLER_FILE = __DIR__ . '/../../../admin/payouts.php';
    private const JS_FILE      = __DIR__ . '/../../../assets/js/admin-payout-detail.js';

    /** @return string Полный исходник admin/payouts.php */
    private function handler_source(): string
    {
        $source = file_get_contents(self::HANDLER_FILE);
        $this->assertIsString($source, 'admin/payouts.php must be readable');
        return $source;
    }

    /** @return string Тело метода handle_update_payout_request() */
    private function method_body(): string
    {
        $src = $this->handler_source();
        if (!preg_match('/public function handle_update_payout_request\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('handle_update_payout_request() not found');
        }
        $start = $m[0][1];
        $tail  = substr($src, $start);
        // Режем до следующего private function / public function на том же уровне
        $end_pos = strpos($tail, 'private function update_user_balance_on_payout');
        $this->assertNotFalse($end_pos, 'method end marker not found');
        return substr($tail, 0, (int) $end_pos);
    }

    public function test_handler_extracts_request_id_via_helper(): void
    {
        $body = $this->method_body();

        $this->assertStringContainsString(
            "\$_POST['request_id']",
            $body,
            'Хендлер должен читать $_POST[\'request_id\'] для сервер-сайд дедупа.'
        );
        $this->assertStringContainsString(
            'Cashback_Idempotency::normalize_request_id',
            $body,
            'Нормализация должна идти через Cashback_Idempotency::normalize_request_id (unified UUID regex).'
        );
    }

    public function test_handler_returns_stored_result_on_retry(): void
    {
        $body = $this->method_body();

        $this->assertMatchesRegularExpression(
            '/Cashback_Idempotency::get_stored_result\s*\([^)]+\)/',
            $body,
            'При ретрае хендлер должен сначала вернуть get_stored_result(), а не обрабатывать заново.'
        );
    }

    public function test_handler_claims_slot_before_transaction(): void
    {
        $body = $this->method_body();

        // claim() должен вызываться ДО START TRANSACTION, чтобы параллельный
        // retry не попадал в гонку на БД-уровне.
        $claim_pos = strpos($body, 'Cashback_Idempotency::claim');
        $tx_pos    = strpos($body, "\$wpdb->query('START TRANSACTION'");

        $this->assertNotFalse($claim_pos, 'claim() должен вызываться в хендлере');
        $this->assertNotFalse($tx_pos, 'START TRANSACTION должен присутствовать');
        $this->assertLessThan(
            $tx_pos,
            $claim_pos,
            'claim() должен вызываться ДО START TRANSACTION для отсечения retry до БД-работы.'
        );
    }

    public function test_handler_uses_dedicated_scope(): void
    {
        $body = $this->method_body();

        $this->assertStringContainsString(
            "'admin_payout_update'",
            $body,
            'Scope должен быть уникальным для этого хендлера — admin_payout_update.'
        );
    }

    public function test_handler_stores_result_before_success_response(): void
    {
        $body = $this->method_body();

        // store_result должен быть позиционно перед последним wp_send_json_success.
        $store_pos   = strrpos($body, 'Cashback_Idempotency::store_result');
        $success_pos = strrpos($body, 'wp_send_json_success');

        $this->assertNotFalse($store_pos, 'store_result() отсутствует — идемпотентность не сохранится для retry');
        $this->assertNotFalse($success_pos, 'wp_send_json_success отсутствует');
        $this->assertLessThan(
            $success_pos,
            $store_pos,
            'store_result() должен быть ДО wp_send_json_success (вызывается только на happy path).'
        );
    }

    public function test_handler_releases_claim_on_rollback(): void
    {
        $body = $this->method_body();

        // В catch-ветках должен быть forget() — иначе retry на transient-ошибке
        // навсегда застрянет со статусом 409/stored-null.
        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\?Throwable\s+\$e\s*\)\s*\{[^}]*Cashback_Idempotency::forget/s',
            $body,
            'В catch-блоке должен вызываться Cashback_Idempotency::forget() для освобождения слота на retry.'
        );
    }

    public function test_handler_returns_409_when_concurrent_claim_fails(): void
    {
        $body = $this->method_body();

        // При параллельном POST с одним request_id второй должен получить 409,
        // чтобы клиент понял: это не «ошибка» а «уже обрабатывается».
        // Проверяем что рядом в коде есть 'in_progress' и 409 — внутри одного claim-false блока.
        $this->assertMatchesRegularExpression(
            "/Cashback_Idempotency::claim[\s\S]{0,800}?'in_progress'[\s\S]{0,300}?\),\s*409\)/",
            $body,
            'При claim=false хендлер должен вернуть 409 с code=in_progress.'
        );
    }

    public function test_handler_preserves_backward_compat_for_missing_request_id(): void
    {
        $body = $this->method_body();

        // Если request_id пустой — хендлер должен работать как раньше, без early-return.
        // Признак: проверка $idem_request_id !== '' перед каждым вызовом helper'а.
        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*\\\$idem_request_id\s*!==\s*''\s*\)/",
            $body,
            'Вызовы helper\'а должны быть под $idem_request_id !== \'\' — backward-compat для старых клиентов.'
        );
    }

    // ────────────────────────────────────────────────────────────
    // JS: парный клиентский патч
    // ────────────────────────────────────────────────────────────

    public function test_js_generates_uuid_and_sends_request_id(): void
    {
        $js = file_get_contents(self::JS_FILE);
        $this->assertIsString($js, 'admin-payout-detail.js must be readable');

        // crypto.randomUUID fallback присутствует (как в cashback-contact-form.js).
        $this->assertStringContainsString(
            'crypto.randomUUID',
            $js,
            'JS должен использовать crypto.randomUUID() для генерации request_id.'
        );

        // request_id прокидывается в postData для update_payout_request.
        $this->assertMatchesRegularExpression(
            "/action:\s*'update_payout_request'[\s\S]*?request_id:\s*requestId/",
            $js,
            'JS должен пробросить request_id в postData для update_payout_request.'
        );
    }

    public function test_js_resets_request_id_after_success(): void
    {
        $js = file_get_contents(self::JS_FILE);
        $this->assertIsString($js);

        $this->assertStringContainsString(
            "removeData('cb-request-id')",
            $js,
            'После успешной отправки JS должен сбросить cb-request-id, чтобы следующий клик получил свежий UUID.'
        );
    }
}
