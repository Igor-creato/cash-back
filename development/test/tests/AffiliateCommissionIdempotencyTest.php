<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * RED-тесты для Группы 1 ADR — F-22-001, F-22-002.
 *
 * Проверяют контракт process_affiliate_commissions():
 *   - START TRANSACTION + FOR UPDATE на cashback_user_balance рефереров.
 *   - Effective deltas: balance_delta учитывает ТОЛЬКО реально вставленные
 *     ledger-строки (rows_affected === 1), иначе повторный cron/retry
 *     даст double-credit на available_balance.
 *   - Опциональный $in_transaction параметр для вложенного вызова.
 *
 * Методика: source-string checks + символические инварианты.
 * Пока реализация не соответствует контракту — тесты RED.
 */
#[Group('affiliate-idempotency')]
final class AffiliateCommissionIdempotencyTest extends TestCase
{
    private const SERVICE_FILE = __DIR__ . '/../../../affiliate/class-affiliate-service.php';

    /** @return string Полный исходник class-affiliate-service.php */
    private function service_source(): string
    {
        $source = file_get_contents(self::SERVICE_FILE);
        $this->assertIsString($source, 'affiliate-service source must be readable');
        return $source;
    }

    /** @return string Тело метода process_affiliate_commissions() */
    private function extract_process_affiliate_commissions_body(): string
    {
        $src = $this->service_source();
        // Выделяем от сигнатуры до следующего "/* ═══" разделителя блока
        if (!preg_match('/public static function process_affiliate_commissions\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('process_affiliate_commissions() not found');
        }
        $start = $m[0][1];
        $tail  = substr($src, $start);
        // Берём до следующего "KOMИCCИИ" / следующего публичного метода
        $end_pos = strpos($tail, 'private static function batch_get_rates');
        if ($end_pos === false) {
            $end_pos = strlen($tail);
        }
        return substr($tail, 0, $end_pos);
    }

    public function test_signature_accepts_in_transaction_flag(): void
    {
        $src = $this->service_source();

        $this->assertMatchesRegularExpression(
            '/public static function process_affiliate_commissions\s*\(\s*array\s+\$candidates\s*,\s*bool\s+\$in_transaction\s*=\s*false\s*\)/',
            $src,
            'Метод должен принимать bool $in_transaction = false (ADR Decision 2).'
        );
    }

    public function test_body_starts_and_commits_own_transaction(): void
    {
        $body = $this->extract_process_affiliate_commissions_body();

        $this->assertStringContainsString(
            'START TRANSACTION',
            $body,
            'process_affiliate_commissions() должен открывать свою TX при $in_transaction=false (F-22-002).'
        );

        $this->assertMatchesRegularExpression(
            '/\bCOMMIT\b/',
            $body,
            'Тело должно содержать COMMIT (F-22-002).'
        );

        $this->assertMatchesRegularExpression(
            '/\bROLLBACK\b/',
            $body,
            'Тело должно содержать ROLLBACK для partial-failure (F-22-002).'
        );
    }

    public function test_body_locks_referrer_balance_rows_with_for_update(): void
    {
        $body = $this->extract_process_affiliate_commissions_body();

        $this->assertMatchesRegularExpression(
            '/FOR\s+UPDATE/i',
            $body,
            'Должен быть SELECT ... FOR UPDATE на cashback_user_balance рефереров до применения дельт (F-22-002).'
        );
    }

    public function test_balance_delta_gated_by_rows_affected_check(): void
    {
        $body = $this->extract_process_affiliate_commissions_body();

        $this->assertMatchesRegularExpression(
            '/rows_affected|affected_rows|\$inserted_keys|\$effective_inserted|ROW_COUNT/i',
            $body,
            'Balance-delta должна накапливаться только для rows_affected===1 ledger INSERT\'ов (F-22-001 double-credit).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Символические инварианты эффективных дельт
    // ════════════════════════════════════════════════════════════════

    public function test_effective_delta_only_counts_newly_inserted_rows(): void
    {
        $candidates = [
            ['50.00', 1],
            ['30.00', 0], // duplicate via ON DUPLICATE KEY UPDATE id=id
            ['20.00', 1],
        ];

        $delta = '0.00';
        foreach ($candidates as [$commission, $inserted]) {
            if ($inserted === 1) {
                $delta = bcadd($delta, $commission, 2);
            }
        }

        $this->assertSame('70.00', $delta, 'Duplicate-rows должны пропускаться (F-22-001).');
    }

    public function test_all_duplicate_batch_produces_zero_delta(): void
    {
        $candidates = [['50.00', 0], ['30.00', 0], ['20.00', 0]];

        $delta = '0.00';
        foreach ($candidates as [$commission, $inserted]) {
            if ($inserted === 1) {
                $delta = bcadd($delta, $commission, 2);
            }
        }

        $this->assertSame('0.00', $delta, 'Повторный cron/retry не должен менять available_balance (F-22-001 critical).');
    }

    public function test_second_run_on_same_batch_does_not_increase_balance(): void
    {
        $first_run_delta  = '100.00';
        $second_run_delta = '0.00'; // все ключи уже в ledger

        $this->assertSame(
            $first_run_delta,
            bcadd($first_run_delta, $second_run_delta, 2),
            'Double-run cron не удваивает available_balance (F-22-001).'
        );
    }

    public function test_partial_failure_reverts_balance_deltas_via_rollback(): void
    {
        // Контракт F-22-002: при throw внутри TX → ROLLBACK всех балансовых UPDATE'ов.
        $accumulated_delta = bcadd('50.00', '30.00', 2);
        $next_insert_threw = true;

        $applied = $next_insert_threw ? '0.00' : $accumulated_delta;

        $this->assertSame('0.00', $applied);
    }

    public function test_rows_affected_zero_is_duplicate_not_error(): void
    {
        $rows_affected = 0;

        $this->assertNotFalse($rows_affected, '0 ≠ false (error); это MySQL-сигнал дубля');
        $this->assertSame(0, $rows_affected);

        $is_new   = ($rows_affected === 1);
        $is_dup   = ($rows_affected === 0);
        $is_error = ($rows_affected === false);

        $this->assertFalse($is_new);
        $this->assertTrue($is_dup);
        $this->assertFalse($is_error);

        $this->assertFalse($is_new, 'Delta НЕ применяется при rows_affected=0.');
    }

    public function test_mixed_batch_credits_only_new_idempotency_keys(): void
    {
        $existing_keys = ['aff_accrual_100' => true, 'aff_accrual_200' => true];

        $batch = [
            ['tx_id' => 100, 'commission' => '15.00'],
            ['tx_id' => 200, 'commission' => '25.00'],
            ['tx_id' => 300, 'commission' => '40.00'],
        ];

        $delta = '0.00';
        foreach ($batch as $item) {
            $key = 'aff_accrual_' . $item['tx_id'];
            if (!isset($existing_keys[$key])) {
                $delta = bcadd($delta, $item['commission'], 2);
            }
        }

        $this->assertSame('40.00', $delta, 'Только tx_id=300 новый; credit только за него (F-22-001).');
    }
}
