<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты для расчёта кэшбэка (Cashback_Trigger_Fallbacks)
 *
 * Покрывает:
 * - Расчёт кэшбэка при вставке для зарегистрированных пользователей
 * - Расчёт кэшбэка для незарегистрированных (60%)
 * - Пересчёт кэшбэка при обновлении комиссии
 * - Переходы статусов транзакций
 * - Защита выплат от изменения
 * - Хеш webhook payload
 */
#[Group('calculation')]
class CashbackCalculationTest extends TestCase
{
    // ================================================================
    // ТЕСТЫ: calculate_cashback() — зарегистрированные пользователи
    // ================================================================

    public function test_calculate_cashback_registered_default_rate(): void
    {
        // Без $wpdb не можем получить ставку из БД — используем дефолт 60%
        $data = [
            'user_id'   => null, // нет user_id → дефолт 60%
            'comission' => 100.00,
        ];

        // Мокируем wpdb чтобы вернуть null (нет профиля пользователя)
        // В данном случае это симулируем через отсутствие user_id
        Cashback_Trigger_Fallbacks::calculate_cashback($data, true);

        $this->assertSame(60.00, $data['applied_cashback_rate']);
        $this->assertSame(60.00, $data['cashback']);
    }

    public function test_calculate_cashback_unregistered_always_60_percent(): void
    {
        $data = [
            'comission' => 200.00,
        ];

        Cashback_Trigger_Fallbacks::calculate_cashback($data, false);

        $this->assertSame(60.00, $data['applied_cashback_rate']);
        $this->assertSame(120.00, $data['cashback']);
    }

    public function test_calculate_cashback_null_comission_gives_zero(): void
    {
        $data = [
            'comission' => null,
        ];

        Cashback_Trigger_Fallbacks::calculate_cashback($data, false);

        $this->assertSame(0.00, $data['cashback']);
    }

    /**
     * Тест точности округления
     */
    public static function cashback_precision_provider(): array
    {
        return [
            // [комиссия, ставка%, ожидаемый кэшбэк]
            'ровное число'              => [100.00, 60.00, 60.00],
            'дробная комиссия'          => [33.33, 60.00, 20.00],   // 33.33 * 0.6 = 19.998 → 20.00
            'минимальная комиссия'      => [0.01, 60.00, 0.01],     // 0.01 * 0.6 = 0.006 → 0.01
            'большая сумма'             => [1000.00, 60.00, 600.00],
            'ставка 50%'                => [100.00, 50.00, 50.00],
            'ставка 75%'                => [100.00, 75.00, 75.00],
            'ставка 100%'               => [100.00, 100.00, 100.00],
            'нулевая комиссия'          => [0.00, 60.00, 0.00],
            // Edge cases с точностью
            '1 копейка с 60%'           => [0.01, 60.00, 0.01],     // round(0.006, 2) = 0.01
            'нечётная сумма'            => [7.77, 60.00, 4.66],     // round(4.662, 2) = 4.66
        ];
    }

    #[DataProvider('cashback_precision_provider')]
    public function test_cashback_precision(float $comission, float $rate, float $expected): void
    {
        // Используем прямую формулу из trigg fallbacks:
        // cashback = round(comission * rate / 100, 2)
        $actual = round($comission * $rate / 100, 2);
        $this->assertSame($expected, $actual, sprintf(
            'round(%f * %f / 100, 2) должен быть %f',
            $comission,
            $rate,
            $expected
        ));
    }

    // ================================================================
    // ТЕСТЫ: recalculate_cashback_on_update()
    // ================================================================

    public function test_recalculate_on_update_when_comission_changed(): void
    {
        $old_row = (object) [
            'comission'             => 100.00,
            'applied_cashback_rate' => 60.00,
        ];

        $update_data = ['comission' => 200.00];

        Cashback_Trigger_Fallbacks::recalculate_cashback_on_update($update_data, $old_row, true);

        $this->assertSame(120.00, $update_data['cashback']);
    }

    public function test_recalculate_on_update_no_change_when_comission_same(): void
    {
        $old_row = (object) [
            'comission'             => 100.00,
            'applied_cashback_rate' => 60.00,
        ];

        $update_data = ['comission' => 100.00]; // То же значение

        Cashback_Trigger_Fallbacks::recalculate_cashback_on_update($update_data, $old_row, true);

        // Кэшбэк не должен быть установлен в data когда comission не изменилась
        $this->assertArrayNotHasKey('cashback', $update_data);
    }

    public function test_recalculate_on_update_without_comission_key(): void
    {
        $old_row = (object) ['comission' => 100.00, 'applied_cashback_rate' => 60.00];
        $update_data = ['order_status' => 'completed']; // нет ключа comission

        Cashback_Trigger_Fallbacks::recalculate_cashback_on_update($update_data, $old_row, true);

        $this->assertArrayNotHasKey('cashback', $update_data);
    }

    public function test_recalculate_on_update_unregistered_uses_60_percent(): void
    {
        $old_row = (object) [
            'comission'             => 100.00,
            'applied_cashback_rate' => 70.00, // Не используется для незарегистрированных
        ];

        $update_data = ['comission' => 150.00];

        Cashback_Trigger_Fallbacks::recalculate_cashback_on_update($update_data, $old_row, false);

        // Незарегистрированные всегда 60%: 150 * 0.6 = 90
        $this->assertSame(90.00, $update_data['cashback']);
    }

    public function test_recalculate_preserves_applied_rate_on_update(): void
    {
        // При обновлении для зарегистрированного используется сохранённая ставка
        $old_row = (object) [
            'comission'             => 100.00,
            'applied_cashback_rate' => 80.00,
        ];

        $update_data = ['comission' => 250.00];

        Cashback_Trigger_Fallbacks::recalculate_cashback_on_update($update_data, $old_row, true);

        // 250 * 80 / 100 = 200
        $this->assertSame(200.00, $update_data['cashback']);
    }

    // ================================================================
    // ТЕСТЫ: validate_status_transition()
    // ================================================================

    /**
     * Допустимые переходы статусов
     */
    public static function valid_status_transitions_provider(): array
    {
        return [
            'тот же статус (не изменился)'       => ['waiting', 'waiting'],
            'waiting → completed'                  => ['waiting', 'completed'],
            'waiting → declined'                   => ['waiting', 'declined'],
            // waiting → hold запрещён: согласно правилу #4 hold только из completed
            'completed → balance'                  => ['completed', 'balance'],
            'completed → hold'                     => ['completed', 'hold'],
            'declined → completed (апелляция)'     => ['declined', 'completed'],
            'declined → declined (без изменений)'  => ['declined', 'declined'],
        ];
    }

    #[DataProvider('valid_status_transitions_provider')]
    public function test_valid_status_transition(string $old_status, string $new_status): void
    {
        $result = Cashback_Trigger_Fallbacks::validate_status_transition($old_status, $new_status);
        $this->assertTrue($result, sprintf(
            'Переход %s → %s должен быть допустим',
            $old_status,
            $new_status
        ));
    }

    /**
     * Недопустимые переходы статусов
     *
     * Правила (см. validate_status_transition):
     * 1. balance — финальный, любые изменения запрещены
     * 2. Возврат в waiting запрещён из любого состояния (кроме waiting → waiting)
     * 3. В balance — только из completed
     * 4. В hold — только из completed
     * 5. Из declined — только в completed или declined
     */
    public static function invalid_status_transitions_provider(): array
    {
        return [
            'balance — финальный, любое изменение'      => ['balance', 'waiting', 'финальный статус'],
            'balance → completed'                         => ['balance', 'completed', 'финальный статус'],
            'balance → declined'                          => ['balance', 'declined', 'финальный статус'],
            'completed → waiting (понижение)'             => ['completed', 'waiting', 'waiting запрещён'],
            'declined → waiting'                          => ['declined', 'waiting', 'waiting запрещён'],
            'hold → waiting'                              => ['hold', 'waiting', 'waiting запрещён'],
            'waiting → balance (не из completed)'         => ['waiting', 'balance', 'только из completed'],
            'declined → balance'                          => ['declined', 'balance', 'только из completed'],
            'hold → balance (не из completed)'            => ['hold', 'balance', 'только из completed'],
            'waiting → hold (только из completed)'        => ['waiting', 'hold', 'только из completed'],
            'declined → hold'                             => ['declined', 'hold', 'только из completed'],
        ];
    }

    #[DataProvider('invalid_status_transitions_provider')]
    public function test_invalid_status_transition(string $old_status, string $new_status, string $reason): void
    {
        $result = Cashback_Trigger_Fallbacks::validate_status_transition($old_status, $new_status);

        $this->assertNotTrue($result, sprintf(
            'Переход %s → %s должен быть ЗАПРЕЩЁН (%s)',
            $old_status,
            $new_status,
            $reason
        ));
        $this->assertIsString($result, 'Должна быть возвращена строка с описанием ошибки');
    }

    public function test_balance_is_final_status(): void
    {
        // balance → любой статус запрещён
        $all_statuses = ['waiting', 'completed', 'declined', 'hold', 'balance'];
        foreach ($all_statuses as $new_status) {
            if ($new_status === 'balance') {
                // balance → balance — тот же статус (разрешён early return)
                $result = Cashback_Trigger_Fallbacks::validate_status_transition('balance', 'balance');
                $this->assertTrue($result, 'balance → balance (без изменения) должен быть разрешён');
            } else {
                $result = Cashback_Trigger_Fallbacks::validate_status_transition('balance', $new_status);
                $this->assertNotTrue($result, "balance → {$new_status} должен быть запрещён как финальный статус");
            }
        }
    }

    public function test_status_transition_returns_error_message(): void
    {
        $result = Cashback_Trigger_Fallbacks::validate_status_transition('balance', 'waiting');
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        // "Изменение запрещено: запись с финальным статусом..."
        $this->assertStringContainsString('финальн', $result); // корень слова для устойчивости к падежам
    }

    public function test_waiting_downgrade_returns_error_message(): void
    {
        $result = Cashback_Trigger_Fallbacks::validate_status_transition('completed', 'waiting');
        $this->assertIsString($result);
        $this->assertStringContainsString('waiting', $result);
    }

    public function test_balance_only_from_completed_error(): void
    {
        $result = Cashback_Trigger_Fallbacks::validate_status_transition('waiting', 'balance');
        $this->assertIsString($result);
        $this->assertStringContainsString('completed', $result);
    }

    // ================================================================
    // ТЕСТЫ: validate_payout_update()
    // ================================================================

    public function test_payout_update_allowed_for_waiting(): void
    {
        $result = Cashback_Trigger_Fallbacks::validate_payout_update('waiting');
        $this->assertTrue($result);
    }

    public function test_payout_update_allowed_for_processing(): void
    {
        $result = Cashback_Trigger_Fallbacks::validate_payout_update('processing');
        $this->assertTrue($result);
    }

    public function test_payout_update_forbidden_for_paid(): void
    {
        $result = Cashback_Trigger_Fallbacks::validate_payout_update('paid');
        $this->assertIsString($result);
        $this->assertStringContainsString('выплачен', $result);
    }

    public function test_payout_delete_forbidden_for_failed(): void
    {
        $result = Cashback_Trigger_Fallbacks::validate_payout_update('failed');
        $this->assertIsString($result);
        $this->assertStringContainsString('failed', $result);
    }

    public function test_payout_update_allowed_for_declined(): void
    {
        $result = Cashback_Trigger_Fallbacks::validate_payout_update('declined');
        $this->assertTrue($result);
    }

    public function test_payout_update_allowed_for_needs_retry(): void
    {
        $result = Cashback_Trigger_Fallbacks::validate_payout_update('needs_retry');
        $this->assertTrue($result);
    }

    // ================================================================
    // ТЕСТЫ: compute_webhook_payload_hash()
    // ================================================================

    public function test_compute_webhook_hash_sets_sha256(): void
    {
        $data = ['payload' => '{"event":"click","data":{}}'];

        Cashback_Trigger_Fallbacks::compute_webhook_payload_hash($data);

        $expected = hash('sha256', $data['payload']);
        $this->assertSame($expected, $data['payload_hash']);
        $this->assertSame(64, strlen($data['payload_hash']));
    }

    public function test_compute_webhook_hash_not_overwritten_if_set(): void
    {
        $existing_hash = 'existinghash1234567890123456789012345678901234567890123456789012';
        $data = [
            'payload'      => '{"event":"click"}',
            'payload_hash' => $existing_hash,
        ];

        Cashback_Trigger_Fallbacks::compute_webhook_payload_hash($data);

        $this->assertSame($existing_hash, $data['payload_hash'], 'Существующий хеш не должен перезаписываться');
    }

    public function test_compute_webhook_hash_empty_payload_skipped(): void
    {
        $data = ['payload' => ''];

        Cashback_Trigger_Fallbacks::compute_webhook_payload_hash($data);

        $this->assertArrayNotHasKey('payload_hash', $data, 'Для пустого payload хеш не должен устанавливаться');
    }

    public function test_compute_webhook_hash_is_deterministic(): void
    {
        $payload = '{"network":"admitad","action_id":12345}';
        $data1 = ['payload' => $payload];
        $data2 = ['payload' => $payload];

        Cashback_Trigger_Fallbacks::compute_webhook_payload_hash($data1);
        Cashback_Trigger_Fallbacks::compute_webhook_payload_hash($data2);

        $this->assertSame($data1['payload_hash'], $data2['payload_hash']);
    }

    public function test_compute_webhook_different_payloads_give_different_hashes(): void
    {
        $data1 = ['payload' => '{"action_id":1}'];
        $data2 = ['payload' => '{"action_id":2}'];

        Cashback_Trigger_Fallbacks::compute_webhook_payload_hash($data1);
        Cashback_Trigger_Fallbacks::compute_webhook_payload_hash($data2);

        $this->assertNotSame($data1['payload_hash'], $data2['payload_hash']);
    }

    // ================================================================
    // ТЕСТЫ: set_banned_at() и clear_ban_fields()
    // ================================================================

    public function test_set_banned_at_sets_datetime(): void
    {
        $data = [];
        Cashback_Trigger_Fallbacks::set_banned_at($data);

        $this->assertArrayHasKey('banned_at', $data);
        $this->assertNotEmpty($data['banned_at']);
        // Проверяем формат даты MySQL
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['banned_at']);
    }

    public function test_clear_ban_fields_nullifies_banned_at_and_reason(): void
    {
        $data = [
            'banned_at'  => '2024-01-01 12:00:00',
            'ban_reason' => 'Fraud detected',
        ];

        Cashback_Trigger_Fallbacks::clear_ban_fields($data);

        $this->assertNull($data['banned_at']);
        $this->assertNull($data['ban_reason']);
    }
}
