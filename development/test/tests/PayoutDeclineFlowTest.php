<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурный тест: F-S9-01 admin decline-fail (P2.3, run-h 2026-04-30).
 *
 * Триггер `tr_payout_require_fail_reason_upd` (mariadb.php:3095) бросает
 * SIGNAL SQLSTATE 45000 «fail_reason required for declined/failed payout
 * status» при UPDATE wp_cashback_payout_requests с status IN ('declined',
 * 'failed') и пустым fail_reason. До фикса handle_update_payout_request
 * не проверял это ДО START TRANSACTION → wpdb->update() возвращал false
 * → throw «Ошибка при обновлении запроса выплаты в базе данных.» →
 * админ видел generic-сообщение без понятного объяснения.
 *
 * Фикс: server-side guard в начале handle_update_payout_request, ПОСЛЕ
 * валидации $allowed_statuses, но ДО `START TRANSACTION`. Возвращает
 * специфический code='fail_reason_required' с осмысленным message и
 * освобождает idempotency-слот.
 *
 * Source-based regression: предохраняет от случайного удаления guard'а
 * при будущих рефакторингах handler'а.
 */
#[Group('payouts')]
#[Group('admin')]
final class PayoutDeclineFlowTest extends TestCase
{
    private function payouts_source(): string
    {
        $path = dirname(__DIR__, 3) . '/admin/payouts.php';
        $c    = file_get_contents($path);
        $this->assertIsString($c, 'admin/payouts.php must be readable');
        return $c;
    }

    /**
     * @return string Body метода handle_update_payout_request от declaration
     *                до конца (~6k символов хватает на handler).
     */
    private function handler_body(): string
    {
        $src   = $this->payouts_source();
        $start = strpos($src, 'public function handle_update_payout_request');
        $this->assertNotFalse(
            $start,
            'admin/payouts.php должен содержать public function handle_update_payout_request'
        );
        // Ищем следующий метод-ограничитель.
        $end = strpos($src, "\n    public function ", $start + 1);
        if ($end === false) {
            $end = strpos($src, "\n    private function ", $start + 1);
        }
        if ($end === false) {
            $end = $start + 6000;
        }
        return substr($src, $start, $end - $start);
    }

    public function test_handler_validates_fail_reason_for_declined_failed_status(): void
    {
        $body = $this->handler_body();

        $this->assertMatchesRegularExpression(
            "/in_array\s*\(\s*\\\$status\s*,\s*array\s*\(\s*'declined'\s*,\s*'failed'\s*\)\s*,\s*true\s*\)/",
            $body,
            'handle_update_payout_request должен иметь guard на status IN (declined, failed) до открытия транзакции (F-S9-01)'
        );
    }

    public function test_handler_uses_specific_error_code_fail_reason_required(): void
    {
        $body = $this->handler_body();

        $this->assertMatchesRegularExpression(
            "/'code'\s*=>\s*'fail_reason_required'/",
            $body,
            'handler должен возвращать специфический error code fail_reason_required для UI'
        );
    }

    public function test_guard_runs_before_start_transaction(): void
    {
        $body = $this->handler_body();

        $check_pos = strpos($body, 'fail_reason_required');
        // Ищем фактический $wpdb->query('START TRANSACTION'), а не упоминание в комментариях.
        $tx_pos = false;
        if (preg_match("/\\\$wpdb->query\s*\(\s*'START\s+TRANSACTION'\s*\)/", $body, $m, PREG_OFFSET_CAPTURE)) {
            $tx_pos = $m[0][1];
        }

        $this->assertNotFalse($check_pos, "fail_reason_required check должен присутствовать в handler");
        $this->assertNotFalse($tx_pos, "\$wpdb->query('START TRANSACTION') должен присутствовать в handler");
        $this->assertLessThan(
            $tx_pos,
            $check_pos,
            'guard fail_reason_required должен срабатывать ДО START TRANSACTION; иначе ROLLBACK от trigger SIGNAL даёт generic error без понятного code'
        );
    }

    public function test_guard_releases_idempotency_slot_on_rejection(): void
    {
        $body = $this->handler_body();

        // Guard должен вызвать Cashback_Idempotency::forget перед wp_send_json_error
        // (паттерн из существующих error-paths в handler'е).
        $check_pos  = strpos($body, 'fail_reason_required');
        $forget_pos = $check_pos !== false
            ? strpos($body, 'Cashback_Idempotency::forget', max(0, $check_pos - 800))
            : false;

        $this->assertNotFalse(
            $forget_pos,
            'guard должен вызвать Cashback_Idempotency::forget перед wp_send_json_error, чтобы повторный POST с тем же request_id не получил кэш-ответ rejection'
        );
    }

    public function test_guard_uses_existing_fail_reason_for_idempotent_self_call(): void
    {
        $body = $this->handler_body();

        // Для идемпотентного повторного POST'а (status уже declined в БД с заполненным
        // fail_reason) guard не должен блокировать: проверяем существующий fail_reason
        // через прямой SELECT (без FOR UPDATE — ещё не в транзакции).
        $this->assertMatchesRegularExpression(
            "/SELECT\s+fail_reason\s+FROM\s+%i\s+WHERE\s+id\s*=\s*%d/i",
            $body,
            'guard должен fall-back читать существующий fail_reason (идемпотентный self-call со status=declined уже в БД и заполненным fail_reason должен пройти guard)'
        );
    }
}
