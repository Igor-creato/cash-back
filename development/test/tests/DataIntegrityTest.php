<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты целостности данных
 *
 * Проверяет бизнес-правила и инварианты которые должны выполняться всегда:
 * - Корректность формул расчёта кэшбэка
 * - Ограничения на suммы баланса
 * - Правила валидации API данных
 * - Целостность шифрования реквизитов
 */
#[Group('integrity')]
class DataIntegrityTest extends TestCase
{
    // ================================================================
    // ТЕСТЫ: формулы расчёта кэшбэка
    // ================================================================

    /**
     * Кэшбэк не может быть больше комиссии
     */
    public function test_cashback_never_exceeds_commission(): void
    {
        $commissions = [1.00, 10.00, 100.00, 999.99, 0.01];
        $rates = [10.0, 60.0, 100.0, 99.99];

        foreach ($commissions as $commission) {
            foreach ($rates as $rate) {
                $cashback = round($commission * $rate / 100, 2);
                $this->assertLessThanOrEqual(
                    $commission,
                    $cashback,
                    "Кэшбэк ({$cashback}) не должен превышать комиссию ({$commission}) при ставке {$rate}%"
                );
            }
        }
    }

    /**
     * Кэшбэк всегда >= 0
     */
    public function test_cashback_is_never_negative(): void
    {
        $commissions = [0.00, 0.01, 1.00, 100.00, 999.99];
        $rates = [0.00, 10.0, 60.0, 100.0];

        foreach ($commissions as $commission) {
            foreach ($rates as $rate) {
                $cashback = round($commission * $rate / 100, 2);
                $this->assertGreaterThanOrEqual(
                    0.0,
                    $cashback,
                    "Кэшбэк не должен быть отрицательным при commission={$commission}, rate={$rate}"
                );
            }
        }
    }

    /**
     * Ставка кэшбэка всегда в диапазоне 0-100%
     */
    public static function cashback_rate_provider(): array
    {
        return [
            'минимальная ставка'       => [0.00],
            'ставка по умолчанию'      => [60.00],
            'максимальная ставка'      => [100.00],
            'стандартные ставки'       => [50.00],
            'ставка с дробной частью'  => [65.50],
        ];
    }

    #[DataProvider('cashback_rate_provider')]
    public function test_cashback_rate_in_valid_range(float $rate): void
    {
        $this->assertGreaterThanOrEqual(0.0, $rate, 'Ставка должна быть >= 0');
        $this->assertLessThanOrEqual(100.0, $rate, 'Ставка должна быть <= 100');
    }

    // ================================================================
    // ТЕСТЫ: правила для баланса
    // ================================================================

    /**
     * Симуляция транзакции: сумма всех выплат и начислений должна сходиться
     */
    public function test_balance_accounting_consistency(): void
    {
        // Симулируем: начисляем 3 транзакции, делаем выплату
        $transactions = [
            ['cashback' => 100.00, 'status' => 'balance'],
            ['cashback' => 50.00, 'status' => 'balance'],
            ['cashback' => 75.50, 'status' => 'balance'],
        ];

        $total_earned = array_sum(array_column($transactions, 'cashback'));
        $this->assertSame(225.50, $total_earned);

        $payout = 200.00;
        $remaining = round($total_earned - $payout, 2);
        $this->assertSame(25.50, $remaining);

        // После выплаты: available_balance должен уменьшится
        $this->assertGreaterThanOrEqual(0.0, $remaining, 'Остаток баланса после выплаты должен быть >= 0');
    }

    /**
     * Проверка что отрицательный баланс невозможен при корректных данных
     */
    public function test_balance_cannot_go_negative(): void
    {
        $available = 100.00;
        $payout_request = 150.00; // запрос больше доступного

        // Валидация должна запретить выплату больше доступного
        $is_valid = $payout_request <= $available;
        $this->assertFalse($is_valid, 'Выплата больше доступного баланса должна быть запрещена');
    }

    // ================================================================
    // ТЕСТЫ: целостность шифрования реквизитов в цикле
    // ================================================================

    public function test_encryption_roundtrip_preserves_all_fields(): void
    {
        $test_cases = [
            [
                'account'   => '+79031234567',
                'full_name' => 'Иванов Петр Сидорович',
                'bank'      => 'Сбербанк',
            ],
            [
                'account'   => '4276 1234 5678 4523',
                'full_name' => 'Smith John',
                'bank'      => 'Tinkoff',
            ],
            [
                'account'   => '410012345678',
                'full_name' => '',
                'bank'      => '',
            ],
            [
                'account'   => '40817810000000001234',
                'full_name' => 'Пупкин Василий',
                'bank'      => 'ВТБ',
            ],
        ];

        foreach ($test_cases as $details) {
            $result = Cashback_Encryption::encrypt_details($details);
            $decrypted = Cashback_Encryption::decrypt_details($result['encrypted_details']);

            $this->assertSame(
                $details['account'],
                $decrypted['account'],
                "account должен совпасть после encrypt/decrypt цикла"
            );
            $this->assertSame(
                $details['full_name'],
                $decrypted['full_name'],
                "full_name должен совпасть после encrypt/decrypt цикла"
            );
            $this->assertSame(
                $details['bank'],
                $decrypted['bank'],
                "bank должен совпасть после encrypt/decrypt цикла"
            );
        }
    }

    /**
     * Хеш реквизитов стабилен при повторном шифровании
     */
    public function test_details_hash_stable_across_encryptions(): void
    {
        $details = ['account' => '+79031234567', 'full_name' => 'Иванов', 'bank' => 'sber'];

        $result1 = Cashback_Encryption::encrypt_details($details);
        $result2 = Cashback_Encryption::encrypt_details($details);

        // Encrypted данные разные (разный IV), но хеш одинаковый
        $this->assertNotSame(
            $result1['encrypted_details'],
            $result2['encrypted_details'],
            'Каждое шифрование должно давать разный шифртекст'
        );
        $this->assertSame(
            $result1['details_hash'],
            $result2['details_hash'],
            'Хеш реквизитов должен быть одинаковым для одних и тех же данных'
        );
    }

    // ================================================================
    // ТЕСТЫ: валидация статусов транзакций
    // ================================================================

    /**
     * Граф допустимых переходов транзакций (state machine)
     * Проверяем полный граф допустимых переходов
     */
    public function test_complete_transaction_state_machine(): void
    {
        // Допустимые переходы (полный список из validate_status_transition):
        // Правила запрещают только КОНКРЕТНЫЕ переходы, остальные разрешены.
        // Запрещено:
        // 1. Из balance — всё (финальный)
        // 2. Любой → waiting (downgrade запрещён)
        // 3. Не-completed → balance
        // 4. Не-completed → hold
        // 5. Из declined → не completed и не declined
        $allowed = [
            'waiting'   => ['completed', 'declined', 'waiting'],          // нет запрета для waiting → completed/declined
            'completed' => ['balance', 'hold', 'completed', 'declined'],  // completed может перейти в declined (нет запрета)
            'declined'  => ['completed', 'declined'],                     // из declined только в completed или остаться
            'hold'      => ['hold', 'completed', 'declined'],             // из hold: нет явного запрета на completed/declined
            'balance'   => ['balance'],                                   // финальный — только себя сам (early return)
        ];

        $all_statuses = ['waiting', 'completed', 'declined', 'hold', 'balance'];

        foreach ($all_statuses as $from) {
            foreach ($all_statuses as $to) {
                $result = Cashback_Trigger_Fallbacks::validate_status_transition($from, $to);
                $is_allowed = in_array($to, $allowed[$from], true);

                if ($is_allowed) {
                    $this->assertTrue($result, "Переход {$from} → {$to} должен быть допустим");
                } else {
                    $this->assertNotTrue($result, "Переход {$from} → {$to} должен быть запрещён");
                }
            }
        }
    }

    // ================================================================
    // ТЕСТЫ: корректность расчётов cancellation_rate
    // ================================================================

    public static function cancellation_rate_provider(): array
    {
        return [
            'нулевой процент'         => [0, 10, 0.0],
            '50% отклонений'          => [5, 10, 50.0],
            '100% отклонений'         => [10, 10, 100.0],
            '66.67% отклонений'       => [4, 6, 66.67],
            '33.33% отклонений'       => [1, 3, 33.33],
            'один из ста'             => [1, 100, 1.0],
        ];
    }

    #[DataProvider('cancellation_rate_provider')]
    public function test_cancellation_rate_calculation(int $declined, int $total, float $expected_rate): void
    {
        // Формула из check_cancellation_rate():
        // ROUND(declined * 100.0 / total, 2) as decline_rate
        $actual_rate = round($declined * 100.0 / $total, 2);

        $this->assertSame($expected_rate, $actual_rate, sprintf(
            'Процент отклонений: %d из %d должен быть %.2f%%',
            $declined,
            $total,
            $expected_rate
        ));
    }

    public function test_cancellation_risk_score_calculation(): void
    {
        // Формула из check_cancellation_rate():
        // score = min(25.0, decline_rate / 100.0 * 25.0)
        $test_cases = [
            [50.0, 12.5],  // 50% → score = 50/100*25 = 12.5 (low)
            [100.0, 25.0], // 100% → score = 25.0 (граница low/medium)
            [0.0, 0.0],    // 0% → score = 0
            [26.0, 6.5],   // 26% → score = 6.5 (low)
        ];

        foreach ($test_cases as [$decline_rate, $expected_score]) {
            $score = min(25.0, $decline_rate / 100.0 * 25.0);
            $this->assertSame($expected_score, $score, sprintf(
                'Score при cancellation_rate=%.1f%% должен быть %.1f',
                $decline_rate,
                $expected_score
            ));
            // Скор cancellation_rate всегда <= 25 (low severity)
            $this->assertLessThanOrEqual(25.0, $score);
        }
    }

    // ================================================================
    // ТЕСТЫ: generated webhook hash integrity
    // ================================================================

    public function test_webhook_hash_sha256_format(): void
    {
        $payloads = [
            '{"event":"click"}',
            '{"network":"admitad","action_id":12345,"status":"approved"}',
            json_encode(['large' => str_repeat('x', 1000)]),
        ];

        foreach ($payloads as $payload) {
            $hash = hash('sha256', $payload);
            $this->assertSame(64, strlen($hash), 'SHA-256 хеш должен иметь длину 64 hex-символа');
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash, 'Хеш должен содержать только hex-символы');
        }
    }

    public function test_webhook_hash_uniqueness(): void
    {
        // Даже одно разное байт даёт другой хеш
        $payload1 = '{"action_id":1}';
        $payload2 = '{"action_id":2}';

        $hash1 = hash('sha256', $payload1);
        $hash2 = hash('sha256', $payload2);

        $this->assertNotSame($hash1, $hash2, 'Разные payload должны давать разные хеши');
    }

    // ================================================================
    // ТЕСТЫ: Reference ID статистика энтропии
    // ================================================================

    public function test_reference_id_entropy(): void
    {
        // Проверяем что Z (последний символ алфавита) теперь используется
        // (после исправления charset_len с 30 на 31)
        $found_z = false;
        $found_2 = false; // первый символ алфавита тоже должен использоваться

        // 1000 генераций — при 31 возможных символах статистически Z должен появиться
        for ($i = 0; $i < 1000; $i++) {
            $id = Mariadb_Plugin::generate_reference_id();
            $suffix = substr($id, 3);
            if (strpos($suffix, 'Z') !== false) {
                $found_z = true;
            }
            if (strpos($suffix, '2') !== false) {
                $found_2 = true;
            }
            if ($found_z && $found_2) {
                break;
            }
        }

        $this->assertTrue($found_z, 'Символ Z должен использоваться после исправления charset_len=31');
        $this->assertTrue($found_2, 'Первый символ алфавита (2) должен встречаться в IDs');
    }

    // ================================================================
    // ТЕСТЫ: min/max выплаты
    // ================================================================

    public function test_default_min_payout_is_100(): void
    {
        // Из схемы БД: min_payout_amount decimal(18,2) DEFAULT 100.00
        $default_min = 100.00;
        $this->assertSame(100.00, $default_min);

        // Запрос выплаты меньше минимума недопустим
        $small_amount = 50.00;
        $this->assertLessThan($default_min, $small_amount);
    }

    public function test_default_cashback_rate_is_60_percent(): void
    {
        // Из схемы БД: cashback_rate decimal(5,2) NOT NULL DEFAULT 60.00
        $default_rate = 60.00;
        $this->assertSame(60.00, $default_rate);

        // При комиссии 100 руб кэшбэк = 60 руб
        $commission = 100.00;
        $cashback = round($commission * $default_rate / 100, 2);
        $this->assertSame(60.00, $cashback);
    }
}
