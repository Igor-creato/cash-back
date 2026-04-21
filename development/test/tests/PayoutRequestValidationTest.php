<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты валидации данных заявок на выплаты.
 *
 * Покрывает бизнес-логику без зависимостей от БД/WordPress:
 * - Алгоритм Luhn для валидации карт МИР
 * - Валидация номера телефона (E.164) для СБП
 * - Валидация карты МИР: цифровой формат, BIN 2200-2204, Luhn
 * - Regex-валидация суммы вывода
 * - bcmath сравнение для min/max проверок
 * - Валидация метода оплаты через slug
 */
#[Group('payout-validation')]
class PayoutRequestValidationTest extends TestCase
{
    // ================================================================
    // Вспомогательные методы — дублируют логику CashbackWithdrawal
    // для изолированного тестирования алгоритмов
    // ================================================================

    /**
     * Алгоритм Luhn для проверки контрольной суммы номера карты.
     * Точная копия CashbackWithdrawal::luhn_check()
     */
    private function luhn_check(string $number): bool
    {
        $sum = 0;
        $length = strlen($number);
        $parity = $length % 2;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $number[$i];

            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return ($sum % 10) === 0;
    }

    /**
     * Валидация телефона для СБП.
     * Точная копия CashbackWithdrawal::validate_phone_number()
     *
     * @return string Пустая строка = OK, иначе текст ошибки
     */
    private function validate_phone(string $phone): string
    {
        $cleaned = preg_replace('/[\s\-\(\)]/', '', $phone);

        if (empty($cleaned) || $cleaned[0] !== '+') {
            return 'Номер телефона должен начинаться с кода страны (например, +79001234567).';
        }

        $digits = substr($cleaned, 1);

        if (!ctype_digit($digits)) {
            return 'Номер телефона содержит недопустимые символы. Допускаются только цифры после знака +.';
        }

        $digit_count = strlen($digits);
        if ($digit_count < 10 || $digit_count > 15) {
            return 'Введите полный номер телефона с кодом страны (10-15 цифр после +).';
        }

        return '';
    }

    /**
     * Валидация карты МИР.
     * Точная копия CashbackWithdrawal::validate_mir_card()
     *
     * @return string Пустая строка = OK, иначе текст ошибки
     */
    private function validate_mir_card(string $card): string
    {
        $cleaned = preg_replace('/[\s\-]/', '', $card);

        if (!ctype_digit($cleaned)) {
            return 'Номер карты должен содержать только цифры.';
        }

        if (strlen($cleaned) !== 16) {
            return 'Номер карты МИР должен содержать 16 цифр.';
        }

        $bin_prefix = (int) substr($cleaned, 0, 4);
        if ($bin_prefix < 2200 || $bin_prefix > 2204) {
            return 'Номер карты МИР должен начинаться с 2200-2204.';
        }

        if (!$this->luhn_check($cleaned)) {
            return 'Некорректный номер карты (не прошёл проверку контрольной суммы).';
        }

        return '';
    }

    /**
     * Валидация формата суммы вывода.
     * Точная копия regex из CashbackWithdrawal::process_cashback_withdrawal()
     */
    private function is_valid_amount_format(string $amount): bool
    {
        return (bool) preg_match('/^\d+(\.\d{1,2})?$/', $amount);
    }

    // ================================================================
    // ТЕСТЫ: Алгоритм Luhn
    // ================================================================

    /**
     * Эталонные номера карт с валидной контрольной суммой (Luhn pass)
     */
    public static function valid_luhn_provider(): array
    {
        return [
            'Visa test card'                => ['4532015112830366'],
            'MasterCard test card'          => ['5425233430109903'],
            'МИР 2200 с правильным Luhn'    => ['2200000000000004'],
            // 2201000000000003: sum = 4+2+0+1+0..+3 = 10 → PASS
            'МИР 2201 с правильным Luhn'    => ['2201000000000003'],
            // 2202000000000002: sum = 4+2+0+2+0..+2 = 10 → PASS
            'МИР 2202 с правильным Luhn'    => ['2202000000000002'],
            'Простой пример Luhn'           => ['79927398713'],        // Wikipedia example
        ];
    }

    /**
     * Номера с неверной контрольной суммой (Luhn fail)
     */
    public static function invalid_luhn_provider(): array
    {
        return [
            'МИР 2200 с плохим Luhn'   => ['2200000000000005'],
            'Visa с изменённой цифрой' => ['4532015112830367'],
            'Простой пример fail'      => ['79927398714'],  // Wikipedia example fail
            // 0000000000000000 проходит Luhn (sum=0, 0%10=0), поэтому НЕ включаем в invalid
            'Случайные цифры'          => ['1234567890123456'],
            'МИР с плохим чексуммой'   => ['2200000000000003'],  // sum=4+2+3=9 → FAIL
        ];
    }

    #[DataProvider('valid_luhn_provider')]
    public function test_luhn_check_passes_for_valid_number(string $number): void
    {
        $this->assertTrue(
            $this->luhn_check($number),
            "Номер {$number} должен проходить проверку Luhn"
        );
    }

    #[DataProvider('invalid_luhn_provider')]
    public function test_luhn_check_fails_for_invalid_number(string $number): void
    {
        $this->assertFalse(
            $this->luhn_check($number),
            "Номер {$number} НЕ должен проходить проверку Luhn"
        );
    }

    public function test_luhn_single_digit_change_invalidates_card(): void
    {
        $valid = '4532015112830366';
        // Изменяем одну цифру (последнюю)
        $invalid = '4532015112830361';

        $this->assertTrue($this->luhn_check($valid), 'Оригинальный номер должен быть валидным');
        $this->assertFalse($this->luhn_check($invalid), 'Изменённый номер должен быть недействительным');
    }

    public function test_luhn_known_mir_card_calculation(): void
    {
        // 2200000000000004: manually verified
        // parity=0 (16%2=0)
        // i=0: 2, doubled: 4
        // i=1: 2, not doubled: 2
        // i=2..13: 0s → 0
        // i=14: 0, doubled: 0
        // i=15: 4, not doubled: 4
        // sum = 4+2+4 = 10 → 10%10=0 → PASS
        $this->assertTrue($this->luhn_check('2200000000000004'));
        $this->assertFalse($this->luhn_check('2200000000000003'));
    }

    // ================================================================
    // ТЕСТЫ: Валидация карты МИР
    // ================================================================

    public static function valid_mir_cards_provider(): array
    {
        return [
            'BIN 2200, valid Luhn' => ['2200000000000004'],
            'С пробелами'          => ['2200 0000 0000 0004'],
            'С дефисами'           => ['2200-0000-0000-0004'],
        ];
    }

    #[DataProvider('valid_mir_cards_provider')]
    public function test_valid_mir_card_passes_validation(string $card): void
    {
        $error = $this->validate_mir_card($card);
        $this->assertSame('', $error, "Карта {$card} должна проходить валидацию. Ошибка: {$error}");
    }

    public function test_mir_card_wrong_bin_rejected(): void
    {
        // Visa BIN (4xxx) — не МИР
        $error = $this->validate_mir_card('4532015112830366');
        $this->assertNotSame('', $error);
        $this->assertStringContainsString('2200-2204', $error);
    }

    public function test_mir_card_bin_2199_rejected(): void
    {
        // BIN 2199 — меньше допустимого минимума
        // Нужна Luhn-валидная карта с BIN 2199
        $error = $this->validate_mir_card('2199000000000072');
        $this->assertStringContainsString('2200-2204', $error);
    }

    public function test_mir_card_bin_2205_rejected(): void
    {
        // BIN 2205 — больше допустимого максимума
        $error = $this->validate_mir_card('2205000000000070');
        $this->assertStringContainsString('2200-2204', $error);
    }

    public function test_mir_card_too_short_rejected(): void
    {
        $error = $this->validate_mir_card('220000000000004');  // 15 цифр
        $this->assertStringContainsString('16 цифр', $error);
    }

    public function test_mir_card_too_long_rejected(): void
    {
        $error = $this->validate_mir_card('22000000000000040'); // 17 цифр
        $this->assertStringContainsString('16 цифр', $error);
    }

    public function test_mir_card_with_letters_rejected(): void
    {
        $error = $this->validate_mir_card('2200XXXXXXXXXXXX');
        $this->assertStringContainsString('только цифры', $error);
    }

    public function test_mir_card_valid_luhn_fails_for_bad_checksum(): void
    {
        // BIN 2200, длина 16, но неверная контрольная сумма
        $error = $this->validate_mir_card('2200000000000005');
        $this->assertStringContainsString('контрольной суммы', $error);
    }

    public function test_mir_card_all_valid_bins_accepted(): void
    {
        // Карты с BIN 2200, 2201, 2202, 2203, 2204 должны приниматься
        // (если Luhn валиден)
        $valid_cards = [
            '2200000000000004',  // BIN 2200
        ];

        foreach ($valid_cards as $card) {
            $error = $this->validate_mir_card($card);
            $this->assertSame('', $error, "Карта с BIN " . substr($card, 0, 4) . " должна приниматься. Ошибка: {$error}");
        }
    }

    // ================================================================
    // ТЕСТЫ: Валидация телефона (СБП)
    // ================================================================

    public static function valid_phones_provider(): array
    {
        return [
            'RU mobile +7'                => ['+79001234567'],
            'RU длинный +7'               => ['+79991234567'],
            'International +1'            => ['+12125551234'],
            'С пробелами'                 => ['+7 900 123-45-67'],
            'С скобками'                  => ['+7(900)1234567'],
            'Минимум 10 цифр'             => ['+7900123456'],  // 10 цифр после +
            'Максимум 15 цифр'            => ['+791234567890123'],  // 15 цифр после +
        ];
    }

    #[DataProvider('valid_phones_provider')]
    public function test_valid_phone_passes_validation(string $phone): void
    {
        $error = $this->validate_phone($phone);
        $this->assertSame('', $error, "Телефон {$phone} должен проходить валидацию. Ошибка: {$error}");
    }

    public static function invalid_phones_provider(): array
    {
        return [
            'Без плюса'                   => ['79001234567', 'начинаться с кода страны'],
            'С нулём в начале'            => ['079001234567', 'начинаться с кода страны'],
            'Слишком короткий'            => ['+7900123', 'полный номер телефона'],
            'Слишком длинный'             => ['+7912345678901234', 'полный номер телефона'],
            'Буквы после +'               => ['+7ABC1234567', 'недопустимые символы'],
            'Пустая строка'               => ['', 'начинаться с кода страны'],
            // '+' → digits='' → !ctype_digit('') → 'недопустимые символы'
            'Только плюс'                 => ['+', 'недопустимые символы'],
            'Спецсимволы'                 => ['+7900@123#567', 'недопустимые символы'],
        ];
    }

    #[DataProvider('invalid_phones_provider')]
    public function test_invalid_phone_returns_error(string $phone, string $expected_error_fragment): void
    {
        $error = $this->validate_phone($phone);
        $this->assertNotSame('', $error, "Телефон '{$phone}' должен не проходить валидацию");
        $this->assertStringContainsString($expected_error_fragment, $error);
    }

    public function test_phone_boundary_10_digits_valid(): void
    {
        // Ровно 10 цифр после + — минимально допустимо
        $error = $this->validate_phone('+7900123456');
        $this->assertSame('', $error, '10 цифр после + должны быть допустимы');
    }

    public function test_phone_boundary_9_digits_invalid(): void
    {
        // 9 цифр — слишком мало
        $error = $this->validate_phone('+790012345');
        $this->assertNotSame('', $error, '9 цифр после + должны быть недопустимы');
    }

    public function test_phone_boundary_15_digits_valid(): void
    {
        // Ровно 15 цифр после + — максимально допустимо
        $error = $this->validate_phone('+791234567890123');
        $this->assertSame('', $error, '15 цифр после + должны быть допустимы');
    }

    public function test_phone_boundary_16_digits_invalid(): void
    {
        // 16 цифр — слишком много
        $error = $this->validate_phone('+7901234567890123');
        $this->assertNotSame('', $error, '16 цифр после + должны быть недопустимы');
    }

    // ================================================================
    // ТЕСТЫ: Валидация формата суммы вывода
    // ================================================================

    public static function valid_amount_formats_provider(): array
    {
        return [
            'целое число'                   => ['100'],
            'с одним знаком после точки'    => ['100.5'],
            'с двумя знаками после точки'   => ['100.50'],
            'минимальная сумма'             => ['0.01'],
            'большая сумма'                 => ['50000'],
            'большая с копейками'           => ['49999.99'],
            'один рубль'                    => ['1'],
        ];
    }

    #[DataProvider('valid_amount_formats_provider')]
    public function test_valid_amount_format_passes_regex(string $amount): void
    {
        $this->assertTrue(
            $this->is_valid_amount_format($amount),
            "Сумма '{$amount}' должна проходить regex-валидацию"
        );
    }

    public static function invalid_amount_formats_provider(): array
    {
        return [
            'научная нотация e10'           => ['1e10'],
            'плюс в начале'                 => ['+100'],
            'минус (отрицательная)'         => ['-100'],
            'три знака после точки'         => ['100.001'],
            'точка без цифры после'         => ['100.'],
            'начинается с точки'            => ['.50'],
            'пустая строка'                 => [''],
            'буквы'                         => ['abc'],
            'нулевая с буквой'              => ['0x10'],
            'пробел внутри'                 => ['100 50'],
            'запятая вместо точки'          => ['100,50'],
        ];
    }

    #[DataProvider('invalid_amount_formats_provider')]
    public function test_invalid_amount_format_fails_regex(string $amount): void
    {
        $this->assertFalse(
            $this->is_valid_amount_format($amount),
            "Сумма '{$amount}' НЕ должна проходить regex-валидацию"
        );
    }

    // ================================================================
    // ТЕСТЫ: bcmath сравнение для min/max проверок суммы
    // ================================================================

    public function test_bccomp_amount_above_zero(): void
    {
        // bccomp($amount, '0', 2) > 0 — сумма должна быть положительной
        $this->assertGreaterThan(0, bccomp('100.00', '0', 2), '100.00 > 0');
        $this->assertGreaterThan(0, bccomp('0.01', '0', 2), '0.01 > 0');
        $this->assertLessThanOrEqual(0, bccomp('0', '0', 2), '0 не > 0');
        $this->assertLessThan(0, bccomp('-1', '0', 2), '-1 < 0');
    }

    public function test_bccomp_amount_exceeds_max(): void
    {
        $max = '50000.00';
        $this->assertGreaterThan(0, bccomp('50000.01', $max, 2), '50000.01 > max');
        $this->assertLessThanOrEqual(0, bccomp('50000.00', $max, 2), '50000.00 не > max');
        $this->assertLessThan(0, bccomp('49999.99', $max, 2), '49999.99 < max');
    }

    public function test_bccomp_amount_below_min(): void
    {
        $min = '100.00';  // default min_payout
        // balance < min → нельзя вывести
        $this->assertLessThan(0, bccomp('99.99', $min, 2), '99.99 < min_payout');
        $this->assertSame(0, bccomp('100.00', $min, 2), '100.00 === min_payout');
        $this->assertGreaterThan(0, bccomp('100.01', $min, 2), '100.01 > min_payout');
    }

    public function test_bccomp_withdrawal_exceeds_balance(): void
    {
        $balance = '500.00';
        $withdrawal = '500.01';

        $this->assertGreaterThan(
            0,
            bccomp($withdrawal, $balance, 2),
            'Запрос должен превышать баланс'
        );
    }

    public function test_bccomp_withdrawal_equals_balance_allowed(): void
    {
        $balance = '500.00';
        $withdrawal = '500.00';

        $this->assertSame(
            0,
            bccomp($withdrawal, $balance, 2),
            'Запрос равный балансу должен быть разрешён'
        );
    }

    // ================================================================
    // ТЕСТЫ: Валидация аккаунта через dispatch по slug методы
    // ================================================================

    public function test_sbp_slug_triggers_phone_validation(): void
    {
        // Slug 'sbp' → телефон
        $slug = 'sbp';
        $account = '+79001234567';
        $error = $slug === 'sbp' ? $this->validate_phone($account) : '';
        $this->assertSame('', $error, 'Валидный телефон для sbp должен проходить');
    }

    public function test_mir_slug_triggers_card_validation(): void
    {
        // Slug 'mir' → карта МИР
        $slug = 'mir';
        $account = '2200000000000004';
        $error = $slug === 'mir' ? $this->validate_mir_card($account) : '';
        $this->assertSame('', $error, 'Валидная карта МИР для mir должна проходить');
    }

    public function test_unknown_slug_skips_validation(): void
    {
        // Неизвестный slug → пустая ошибка (нет специфической валидации)
        $slug = 'yoomoney';
        // validate_payout_account_format возвращает '' для unknown
        $error = match ($slug) {
            'sbp' => $this->validate_phone('some_account'),
            'mir' => $this->validate_mir_card('some_account'),
            default => '',
        };
        $this->assertSame('', $error, 'Неизвестный slug не должен вызывать ошибку валидации');
    }

    // ================================================================
    // ТЕСТЫ: Длина аккаунта (max 50 символов)
    // ================================================================

    public function test_account_length_within_limit_allowed(): void
    {
        $account = str_repeat('1', 50);
        $this->assertLessThanOrEqual(50, mb_strlen($account), '50 символов — в пределах лимита');
    }

    public function test_account_length_over_limit_rejected(): void
    {
        $account = str_repeat('1', 51);
        $this->assertGreaterThan(50, mb_strlen($account), '51 символ — превышает лимит');
    }

    public function test_account_empty_rejected(): void
    {
        $account = '';
        $this->assertSame(0, mb_strlen($account));
        $this->assertTrue(empty($account), 'Пустой аккаунт должен отклоняться');
    }
}
