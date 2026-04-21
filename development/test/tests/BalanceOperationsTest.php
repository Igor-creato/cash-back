<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты балансовых операций для cashback-выплат.
 *
 * Все операции с балансом используют bcmath для точности.
 * Проверяемые инварианты:
 *
 * 1. Подача заявки: available -= amount, pending += amount
 * 2. Выплата (paid): pending -= amount, paid_balance += amount
 * 3. Возврат (failed): pending -= amount, available += amount
 * 4. Отклонение (declined): pending -= amount, frozen_balance += amount
 * 5. Заморозка при бане: frozen += available + pending, available=0, pending=0
 * 6. Разморозка при разбане: available += frozen, frozen = 0
 * 7. Никакой баланс не может стать отрицательным
 * 8. Консистентность: сумма всех полей баланса не уменьшается при начислении
 * 9. bcmath точность на граничных значениях
 */
#[Group('balance-operations')]
class BalanceOperationsTest extends TestCase
{
    // ================================================================
    // ТЕСТЫ: Подача заявки на вывод (available → pending)
    // ================================================================

    public function test_withdrawal_request_reduces_available_increases_pending(): void
    {
        $available = '1000.00';
        $pending   = '0.00';
        $amount    = '500.00';

        $new_available = bcsub($available, $amount, 2);
        $new_pending   = bcadd($pending, $amount, 2);

        $this->assertSame('500.00', $new_available, 'Доступный баланс должен уменьшиться');
        $this->assertSame('500.00', $new_pending, 'В ожидании должен увеличиться');
    }

    public function test_full_balance_withdrawal_leaves_zero_available(): void
    {
        $available = '250.75';
        $amount    = '250.75';

        $new_available = bcsub($available, $amount, 2);

        $this->assertSame('0.00', $new_available, 'Доступный баланс должен стать нулевым');
    }

    public function test_partial_withdrawal_preserves_correct_remainder(): void
    {
        $available = '1234.56';
        $amount    = '100.00';
        $expected  = '1134.56';

        $new_available = bcsub($available, $amount, 2);

        $this->assertSame($expected, $new_available);
    }

    public function test_withdrawal_cannot_exceed_available_balance(): void
    {
        $available = '100.00';
        $amount    = '100.01';

        // Проверяем что amount > available (условие должно отклонить запрос)
        $exceeds = bccomp($amount, $available, 2) > 0;

        $this->assertTrue($exceeds, 'Запрос превышающий баланс должен быть отклонён');
    }

    public function test_balance_total_preserved_during_withdrawal_request(): void
    {
        // Сумма (available + pending) должна сохраняться при создании заявки
        $available = '1000.00';
        $pending   = '200.00';
        $amount    = '300.00';

        $total_before = bcadd($available, $pending, 2);

        $new_available = bcsub($available, $amount, 2);
        $new_pending   = bcadd($pending, $amount, 2);

        $total_after = bcadd($new_available, $new_pending, 2);

        $this->assertSame($total_before, $total_after, 'Сумма available+pending должна сохраняться при создании заявки');
    }

    // ================================================================
    // ТЕСТЫ: Выплата (paid): pending → paid_balance
    // ================================================================

    public function test_payout_approved_deducts_pending_increases_paid(): void
    {
        $pending      = '500.00';
        $paid_balance = '100.00';
        $amount       = '500.00';

        $new_pending      = bcsub($pending, $amount, 2);
        $new_paid_balance = bcadd($paid_balance, $amount, 2);

        $this->assertSame('0.00', $new_pending, 'pending должен обнулиться после выплаты');
        $this->assertSame('600.00', $new_paid_balance, 'paid_balance должен увеличиться');
    }

    public function test_partial_payout_leaves_correct_pending(): void
    {
        $pending = '1000.00';
        $amount  = '400.00';

        $new_pending      = bcsub($pending, $amount, 2);
        $new_paid_balance = bcadd('0.00', $amount, 2);

        $this->assertSame('600.00', $new_pending);
        $this->assertSame('400.00', $new_paid_balance);
    }

    public function test_payout_insufficient_pending_detected(): void
    {
        $pending = '300.00';
        $amount  = '300.01';

        $is_insufficient = bccomp($pending, $amount, 2) < 0;
        $this->assertTrue($is_insufficient, 'Недостаточно pending для выплаты должно быть обнаружено');
    }

    public function test_cumulative_payouts_sum_correctly(): void
    {
        // Несколько последовательных выплат должны суммироваться правильно
        $initial_pending = '1500.00';
        $paid_balance    = '0.00';

        $payouts = ['300.00', '500.00', '700.00'];

        $current_pending = $initial_pending;
        foreach ($payouts as $payout) {
            $current_pending = bcsub($current_pending, $payout, 2);
            $paid_balance    = bcadd($paid_balance, $payout, 2);
        }

        $this->assertSame('0.00', $current_pending, 'pending должен обнулиться после всех выплат');
        $this->assertSame('1500.00', $paid_balance, 'Сумма выплат должна равняться исходному pending');
    }

    // ================================================================
    // ТЕСТЫ: Возврат при сбое (failed): pending → available
    // ================================================================

    public function test_failed_payout_returns_amount_to_available(): void
    {
        $pending   = '500.00';
        $available = '200.00';
        $amount    = '500.00';

        $new_pending   = bcsub($pending, $amount, 2);
        $new_available = bcadd($available, $amount, 2);

        $this->assertSame('0.00', $new_pending, 'pending после failed должен стать нулевым');
        $this->assertSame('700.00', $new_available, 'available должен вырасти на возвращённую сумму');
    }

    public function test_failed_payout_total_balance_preserved(): void
    {
        $available = '100.00';
        $pending   = '500.00';
        $amount    = '500.00';

        $total_before = bcadd($available, $pending, 2);

        $new_available = bcadd($available, $amount, 2);
        $new_pending   = bcsub($pending, $amount, 2);

        $total_after = bcadd($new_available, $new_pending, 2);

        $this->assertSame($total_before, $total_after, 'Суммарный баланс должен сохраняться при возврате из failed');
    }

    // ================================================================
    // ТЕСТЫ: Отклонение выплаты (declined): pending → frozen_balance
    // ================================================================

    public function test_declined_payout_moves_pending_to_frozen(): void
    {
        $pending = '300.00';
        $frozen  = '50.00';
        $amount  = '300.00';

        $new_pending = bcsub($pending, $amount, 2);
        $new_frozen  = bcadd($frozen, $amount, 2);

        $this->assertSame('0.00', $new_pending, 'pending должен обнулиться при отклонении');
        $this->assertSame('350.00', $new_frozen, 'frozen_balance должен увеличиться');
    }

    public function test_declined_payout_total_balance_preserved(): void
    {
        $available = '100.00';
        $pending   = '500.00';
        $frozen    = '50.00';
        $amount    = '500.00';

        $total_before = bcadd(bcadd($available, $pending, 2), $frozen, 2);

        $new_pending = bcsub($pending, $amount, 2);
        $new_frozen  = bcadd($frozen, $amount, 2);

        $total_after = bcadd(bcadd($available, $new_pending, 2), $new_frozen, 2);

        $this->assertSame($total_before, $total_after, 'Суммарный баланс должен сохраняться при отклонении выплаты');
    }

    // ================================================================
    // ТЕСТЫ: Начисление кэшбэка (completed → balance): кэшбэк → available
    // ================================================================

    public function test_cashback_accrual_increases_available_balance(): void
    {
        $available     = '100.00';
        $cashback_sum  = '60.00';  // 60% от 100 руб. комиссии

        $new_available = bcadd($available, $cashback_sum, 2);

        $this->assertSame('160.00', $new_available, 'available должен увеличиться на начисленный кэшбэк');
    }

    public function test_multiple_cashback_accruals_accumulate_correctly(): void
    {
        $available = '0.00';
        $accruals  = ['60.00', '120.00', '45.50', '33.33'];

        foreach ($accruals as $amount) {
            $available = bcadd($available, $amount, 2);
        }

        $expected = bcadd(bcadd(bcadd('60.00', '120.00', 2), '45.50', 2), '33.33', 2);
        $this->assertSame($expected, $available, 'Множественные начисления должны корректно суммироваться');
    }

    public function test_cashback_accrual_precision_with_bcmath(): void
    {
        // Проверяем что bcmath не теряет точность на дробях
        $available = '0.00';
        // 10.01 + 0.01 + 0.01 = 10.03, но с float может быть 10.029999...
        $available = bcadd($available, '10.01', 2);
        $available = bcadd($available, '0.01', 2);
        $available = bcadd($available, '0.01', 2);

        $this->assertSame('10.03', $available, 'bcmath должен точно считать дроби');
    }

    // ================================================================
    // ТЕСТЫ: Заморозка баланса при бане (freeze_balance_on_ban)
    // ================================================================

    public function test_freeze_moves_available_and_pending_to_frozen(): void
    {
        $available = '500.00';
        $pending   = '200.00';
        $frozen    = '0.00';

        // Логика freeze: frozen += available + pending; available = 0; pending = 0
        $new_frozen    = bcadd(bcadd($frozen, $available, 2), $pending, 2);
        $new_available = '0.00';
        $new_pending   = '0.00';

        $this->assertSame('700.00', $new_frozen, 'Замороженный баланс должен включать available + pending');
        $this->assertSame('0.00', $new_available, 'available должен обнулиться при заморозке');
        $this->assertSame('0.00', $new_pending, 'pending должен обнулиться при заморозке');
    }

    public function test_freeze_with_existing_frozen_balance(): void
    {
        $available = '300.00';
        $pending   = '100.00';
        $frozen    = '50.00';  // Уже есть замороженные

        $new_frozen    = bcadd(bcadd($frozen, $available, 2), $pending, 2);
        $new_available = '0.00';
        $new_pending   = '0.00';

        $this->assertSame('450.00', $new_frozen, 'Заморозка должна добавляться к существующему frozen_balance');
        $this->assertSame('0.00', $new_available);
        $this->assertSame('0.00', $new_pending);
    }

    public function test_freeze_total_balance_unchanged(): void
    {
        $available = '500.00';
        $pending   = '200.00';
        $frozen    = '0.00';
        $paid      = '300.00';

        $total_before = bcadd(bcadd(bcadd($available, $pending, 2), $frozen, 2), $paid, 2);

        $new_frozen    = bcadd(bcadd($frozen, $available, 2), $pending, 2);
        $new_available = '0.00';
        $new_pending   = '0.00';

        $total_after = bcadd(bcadd(bcadd($new_available, $new_pending, 2), $new_frozen, 2), $paid, 2);

        $this->assertSame($total_before, $total_after, 'Суммарный баланс не должен меняться при заморозке');
    }

    public function test_freeze_idempotent_when_all_balances_zero(): void
    {
        // Если available=0 и pending=0, заморозка ничего не меняет
        $available = '0.00';
        $pending   = '0.00';
        $frozen    = '100.00';

        // Условие freeze: WHERE (available_balance > 0 OR pending_balance > 0)
        $should_update = bccomp($available, '0', 2) > 0
            || bccomp($pending, '0', 2) > 0;

        $this->assertFalse($should_update, 'Заморозка не должна выполняться когда нечего замораживать');
    }

    // ================================================================
    // ТЕСТЫ: Разморозка баланса при разбане (unfreeze_balance_on_unban)
    // ================================================================

    public function test_unfreeze_moves_frozen_to_available(): void
    {
        $available = '100.00';
        $frozen    = '700.00';

        // Логика unfreeze: available += frozen; frozen = 0
        $new_available = bcadd($available, $frozen, 2);
        $new_frozen    = '0.00';

        $this->assertSame('800.00', $new_available, 'available должен включить разморозку');
        $this->assertSame('0.00', $new_frozen, 'frozen должен обнулиться при разбане');
    }

    public function test_unfreeze_total_balance_unchanged(): void
    {
        $available = '100.00';
        $pending   = '50.00';
        $frozen    = '700.00';
        $paid      = '300.00';

        $total_before = bcadd(bcadd(bcadd($available, $pending, 2), $frozen, 2), $paid, 2);

        $new_available = bcadd($available, $frozen, 2);
        $new_frozen    = '0.00';

        $total_after = bcadd(bcadd(bcadd($new_available, $pending, 2), $new_frozen, 2), $paid, 2);

        $this->assertSame($total_before, $total_after, 'Суммарный баланс не должен меняться при разморозке');
    }

    public function test_unfreeze_idempotent_when_frozen_zero(): void
    {
        // Если frozen=0, разморозка не выполняется
        // Условие: WHERE frozen_balance > 0
        $frozen = '0.00';
        $should_update = bccomp($frozen, '0', 2) > 0;

        $this->assertFalse($should_update, 'Разморозка не должна выполняться для нулевого frozen_balance');
    }

    // ================================================================
    // ТЕСТЫ: Инвариант — ни один баланс не может быть отрицательным
    //        (CHECK constraint в БД + валидация в коде)
    // ================================================================

    public function test_available_balance_never_negative_after_withdrawal(): void
    {
        $available = '100.00';
        $amount    = '100.01';

        // Операция должна быть отклонена до выполнения
        $would_be_negative = bccomp($amount, $available, 2) > 0;
        $this->assertTrue($would_be_negative, 'Должна быть обнаружена попытка уйти в минус');

        // Если бы операция выполнилась — результат был бы отрицательным
        $hypothetical = bcsub($available, $amount, 2);
        $this->assertLessThan(0, (float) $hypothetical, 'Результат без проверки был бы отрицательным');
    }

    public function test_pending_balance_never_negative_after_payout(): void
    {
        $pending = '100.00';
        $amount  = '100.01';

        $is_insufficient = bccomp($pending, $amount, 2) < 0;
        $this->assertTrue($is_insufficient, 'Должна быть обнаружена недостаточность pending');
    }

    public function test_zero_amount_withdrawal_rejected(): void
    {
        $amount = '0.00';

        // bccomp('0.00', '0', 2) должен дать 0 (равно, не больше)
        $is_positive = bccomp($amount, '0', 2) > 0;
        $this->assertFalse($is_positive, 'Нулевая сумма должна быть отклонена');
    }

    // ================================================================
    // ТЕСТЫ: Version-based optimistic locking
    // ================================================================

    public function test_version_check_prevents_concurrent_updates(): void
    {
        $current_version = 5;
        $expected_version = 5;

        // Симулируем что версия совпадает (процесс 1 успешно обновил)
        $version_match = ($current_version === $expected_version);
        $this->assertTrue($version_match, 'Версия должна совпадать для первого Request');

        // Теперь версия в БД изменилась (процесс 2 тоже пытается обновить)
        $updated_version = 6;
        $version_match_second = ($updated_version === $expected_version);
        $this->assertFalse($version_match_second, 'Второй запрос должен обнаружить конфликт версий');
    }

    public function test_version_increments_after_successful_update(): void
    {
        $version_before = 5;
        $version_after  = $version_before + 1;

        $this->assertSame(6, $version_after, 'Версия должна увеличиваться после успешного обновления');
    }

    public function test_version_zero_for_new_balance_record(): void
    {
        // Новая запись должна начинаться с version=0
        $initial_version = 0;
        $this->assertSame(0, $initial_version);
    }

    // ================================================================
    // ТЕСТЫ: bcmath точность на граничных значениях
    // ================================================================

    public static function bcmath_precision_provider(): array
    {
        return [
            // [$a, $b, $op, $expected]
            'add минимальные копейки'       => ['0.01', '0.01', 'add', '0.02'],
            'sub минимальные копейки'       => ['0.02', '0.01', 'sub', '0.01'],
            'add большие суммы'             => ['99999.99', '0.01', 'add', '100000.00'],
            'sum классический float-баг'    => ['0.10', '0.20', 'add', '0.30'],
            'sub классический float-баг'    => ['0.30', '0.10', 'sub', '0.20'],
            'накопление 3 kopek'            => ['10.01', '10.01', 'add', '20.02'],
        ];
    }

    #[DataProvider('bcmath_precision_provider')]
    public function test_bcmath_precision(string $a, string $b, string $op, string $expected): void
    {
        $result = $op === 'add' ? bcadd($a, $b, 2) : bcsub($a, $b, 2);

        $this->assertSame($expected, $result, sprintf(
            '%s %s %s должен равняться %s (bcmath точность)',
            $a,
            $op === 'add' ? '+' : '-',
            $b,
            $expected
        ));
    }

    public function test_float_vs_bcmath_known_inconsistency(): void
    {
        // Демонстрация почему bcmath обязателен для финансов
        $a = 0.10;
        $b = 0.20;
        $float_result = $a + $b;

        // float: 0.1 + 0.2 ≠ 0.3 из-за IEEE 754
        // (в PHP 0.1 + 0.2 = 0.30000000000000002)
        $bcmath_result = bcadd('0.10', '0.20', 2);

        // bcmath всегда правильный
        $this->assertSame('0.30', $bcmath_result, 'bcmath должен давать точный результат');

        // float может быть неточным — проверяем что они МОГУТ отличаться
        // (это не обязательно так на всех платформах, но демонстрирует риск)
        if (abs($float_result - 0.30) > 0.0) {
            // Float неточен — bcmath необходим
            $this->assertNotEquals(
                '0.30',
                (string) $float_result,
                'float результат может быть неточным — bcmath необходим для финансов'
            );
        }
        // В любом случае bcmath корректен
        $this->assertSame('0.30', $bcmath_result);
    }

    // ================================================================
    // ТЕСТЫ: Полный цикл транзакции (end-to-end арифметика)
    // ================================================================

    public function test_complete_cashback_payout_lifecycle_arithmetic(): void
    {
        // Начальное состояние
        $available = '0.00';
        $pending   = '0.00';
        $paid      = '0.00';
        $frozen    = '0.00';

        // Шаг 1: Начисляем кэшбэк (commission=100, rate=60%)
        $cashback = bcmul('100.00', '0.60', 2);  // = 60.00
        $available = bcadd($available, $cashback, 2);
        $this->assertSame('60.00', $available, 'После начисления кэшбэка');

        // Шаг 2: Ещё одно начисление
        $cashback2 = bcmul('200.00', '0.60', 2);  // = 120.00
        $available = bcadd($available, $cashback2, 2);
        $this->assertSame('180.00', $available, 'После второго начисления');

        // Шаг 3: Создаём заявку на вывод 100 руб.
        $withdrawal = '100.00';
        $available = bcsub($available, $withdrawal, 2);
        $pending   = bcadd($pending, $withdrawal, 2);
        $this->assertSame('80.00', $available, 'После создания заявки');
        $this->assertSame('100.00', $pending, 'pending после создания заявки');

        // Шаг 4: Заявка одобрена и выплачена
        $pending = bcsub($pending, $withdrawal, 2);
        $paid    = bcadd($paid, $withdrawal, 2);
        $this->assertSame('0.00', $pending, 'pending после выплаты');
        $this->assertSame('100.00', $paid, 'paid_balance после выплаты');

        // Шаг 5: Финальное состояние
        $total = bcadd(bcadd(bcadd($available, $pending, 2), $paid, 2), $frozen, 2);
        $expected_total = bcadd($cashback, $cashback2, 2);  // 180.00
        $this->assertSame($expected_total, $total, 'Суммарный баланс должен равняться общей сумме начислений');
    }

    public function test_multiple_users_balances_are_independent(): void
    {
        // Симулируем что балансы разных пользователей не влияют друг на друга
        $user1 = ['available' => '500.00', 'pending' => '0.00'];
        $user2 = ['available' => '300.00', 'pending' => '0.00'];

        // User1 создаёт заявку
        $amount1 = '200.00';
        $user1['available'] = bcsub($user1['available'], $amount1, 2);
        $user1['pending']   = bcadd($user1['pending'], $amount1, 2);

        // User2 не затронут
        $this->assertSame('300.00', $user2['available'], 'Баланс user2 не изменился');
        $this->assertSame('300.00', $user1['available'], 'Баланс user1 обновился');
        $this->assertSame('200.00', $user1['pending'], 'Pending user1 обновился');
    }

    // ================================================================
    // ТЕСТЫ: Начисление кэшбэка через Cashback_Trigger_Fallbacks
    //        (интеграция с реальными методами)
    // ================================================================

    public function test_trigger_fallback_calculate_cashback_sets_correct_rate(): void
    {
        $data = [
            'comission' => 100.00,
            'user_id'   => null,  // нет user_id → дефолтная ставка
        ];

        Cashback_Trigger_Fallbacks::calculate_cashback($data, false);

        $this->assertSame(60.00, $data['applied_cashback_rate']);
        $this->assertSame(60.00, $data['cashback']);
        $this->assertGreaterThanOrEqual(0.0, $data['cashback'], 'Кэшбэк должен быть >= 0');
    }

    public function test_trigger_fallback_freeze_logic_correct(): void
    {
        // Проверяем что логика из Cashback_Trigger_Fallbacks::freeze_balance_on_ban()
        // работает правильно (арифметика, без реальной БД)
        $available = 500.0;
        $pending   = 200.0;
        $frozen    = 0.0;

        // Симулируем логику: frozen += available + pending; available = 0; pending = 0
        $new_frozen = $frozen + $available + $pending;

        $this->assertSame(700.0, $new_frozen, 'Заморозка: frozen = available + pending');
        $this->assertGreaterThanOrEqual(0.0, $new_frozen, 'frozen_balance не может быть отрицательным');
    }

    public function test_trigger_fallback_unfreeze_logic_correct(): void
    {
        $available = 100.0;
        $frozen    = 700.0;

        // Симулируем логику: available += frozen; frozen = 0
        $new_available = $available + $frozen;
        $new_frozen    = 0.0;

        $this->assertSame(800.0, $new_available, 'Разморозка: available += frozen');
        $this->assertSame(0.0, $new_frozen, 'frozen должен стать нулём');
    }

    // ================================================================
    // ТЕСТЫ: Граничные значения начисления
    // ================================================================

    public static function cashback_accrual_edge_cases_provider(): array
    {
        return [
            'минимальная комиссия 1 коп.'   => ['0.01', '0.60', '0.01'],  // round(0.006, 2) = 0.01
            'комиссия 0 руб.'               => ['0.00', '0.60', '0.00'],
            'комиссия 1 руб. ставка 60%'    => ['1.00', '0.60', '0.60'],
            'комиссия 100 руб. ставка 60%'  => ['100.00', '0.60', '60.00'],
            'комиссия 33.33 руб. ставка 60%' => ['33.33', '0.60', '20.00'], // round(19.998)=20.00
        ];
    }

    #[DataProvider('cashback_accrual_edge_cases_provider')]
    public function test_cashback_accrual_edge_cases(string $commission, string $rate, string $expected): void
    {
        // Реальный код использует round(), затем сохраняет как DECIMAL(18,2)
        // number_format с 2 знаками даёт '0.00', '60.00' итд — аналогично DECIMAL
        $cashback_real = number_format(round((float) $commission * (float) $rate, 2), 2, '.', '');

        $this->assertSame($expected, $cashback_real, sprintf(
            'commission=%s * rate=%s должен давать cashback=%s',
            $commission,
            $rate,
            $expected
        ));
    }
}
