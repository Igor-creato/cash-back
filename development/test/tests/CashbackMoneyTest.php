<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit-тесты immutable VO `Cashback_Money` — Группа 10 ADR, Step 3a.
 *
 * Цели:
 *  1. Фиксированный формат money-string: `^-?\d+\.\d{2}$` (точка, ровно 2 знака).
 *  2. Строгая валидация входа (reject `,` как separator, scientific notation, NaN).
 *  3. BCMath-арифметика (scale=2) — bit-exact add/sub/mul_rate/compare.
 *  4. Immutable (ops возвращают новый объект, не мутируют receiver).
 *  5. `from_string` (client boundary) — strict 0–2 знака.
 *     `from_db_value` (DB boundary) — толерантно к legacy 1-знаковой нотации.
 *
 * См. ADR Группа 10, findings F-4-001/004, F-8-003, F-11-002, F-35-004.
 *
 * Запуск:
 *   ./vendor/bin/phpunit --filter CashbackMoneyTest tests/CashbackMoneyTest.php
 */
#[Group('security')]
#[Group('group10')]
#[Group('money')]
class CashbackMoneyTest extends TestCase
{
    protected function setUp(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $file        = $plugin_root . '/includes/class-cashback-money.php';
        if (file_exists($file) && ! class_exists('Cashback_Money')) {
            require_once $file;
        }
    }

    // ================================================================
    // Группа 1: Factory / validation (from_string — client boundary)
    // ================================================================

    public function test_from_string_accepts_canonical_value(): void
    {
        $m = Cashback_Money::from_string('1234.56');
        self::assertSame('1234.56', $m->to_string());
    }

    public function test_from_string_accepts_integer_like(): void
    {
        $m = Cashback_Money::from_string('100');
        self::assertSame('100.00', $m->to_string());
    }

    public function test_from_string_accepts_single_decimal(): void
    {
        $m = Cashback_Money::from_string('1.5');
        self::assertSame('1.50', $m->to_string());
    }

    public function test_from_string_rejects_comma_decimal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Money::from_string('1,5');
    }

    public function test_from_string_rejects_scientific_notation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Money::from_string('1e10');
    }

    public function test_from_string_rejects_nan(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Money::from_string('NaN');
    }

    public function test_from_string_rejects_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Money::from_string('');
    }

    public function test_from_string_rejects_whitespace_only(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Money::from_string('   ');
    }

    public function test_from_string_rejects_non_numeric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Money::from_string('abc');
    }

    public function test_from_string_rejects_multiple_dots(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Money::from_string('1.2.3');
    }

    public function test_from_string_accepts_negative(): void
    {
        $m = Cashback_Money::from_string('-10.50');
        self::assertSame('-10.50', $m->to_string());
    }

    public function test_from_string_strict_three_decimals_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Money::from_string('1.999');
    }

    public function test_from_string_rejects_plus_sign(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Money::from_string('+10.00');
    }

    public function test_from_string_rejects_leading_space(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Money::from_string(' 10.00');
    }

    // ================================================================
    // Группа 1b: from_db_value — DB boundary (tolerant to legacy rows)
    // ================================================================

    public function test_from_db_value_accepts_legacy_single_decimal(): void
    {
        // MySQL DECIMAL(18,2) хранит всегда 2 знака, но исторические строки
        // или «type-juggled» PHP-output могут быть короче. Принимаем 0–2 знака.
        $m = Cashback_Money::from_db_value('100.5');
        self::assertSame('100.50', $m->to_string());
    }

    public function test_from_db_value_accepts_integer_like(): void
    {
        $m = Cashback_Money::from_db_value('100');
        self::assertSame('100.00', $m->to_string());
    }

    public function test_from_db_value_rejects_too_many_decimals(): void
    {
        // DB не может хранить >2 знаков в DECIMAL(18,2); если что-то такое пришло —
        // это data corruption или bug, fail-closed.
        $this->expectException(InvalidArgumentException::class);
        Cashback_Money::from_db_value('1.999');
    }

    public function test_from_db_value_rejects_null(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentional runtime error */
        Cashback_Money::from_db_value(null);
    }

    // ================================================================
    // Группа 1c: zero()
    // ================================================================

    public function test_zero_returns_zero_money(): void
    {
        self::assertSame('0.00', Cashback_Money::zero()->to_string());
    }

    public function test_zero_is_zero(): void
    {
        self::assertTrue(Cashback_Money::zero()->is_zero());
    }

    // ================================================================
    // Группа 2: Arithmetic
    // ================================================================

    public function test_add_basic(): void
    {
        $a = Cashback_Money::from_string('10.00');
        $b = Cashback_Money::from_string('5.50');
        self::assertSame('15.50', $a->add($b)->to_string());
    }

    public function test_add_boundary_precision(): void
    {
        // Классический float-trap: 0.1 + 0.2 ≠ 0.3 в binary float.
        // BCMath-based реализация обязана возвращать ровно 0.30.
        $a = Cashback_Money::from_string('0.10');
        $b = Cashback_Money::from_string('0.20');
        self::assertSame('0.30', $a->add($b)->to_string());
    }

    public function test_add_commutative(): void
    {
        $a = Cashback_Money::from_string('3.33');
        $b = Cashback_Money::from_string('7.77');
        self::assertSame($a->add($b)->to_string(), $b->add($a)->to_string());
    }

    public function test_add_large_numbers(): void
    {
        // DECIMAL(18,2) вмещает 16 цифр до точки — BCMath не переполняется.
        $a = Cashback_Money::from_string('999999999999999.99');
        $b = Cashback_Money::from_string('0.01');
        self::assertSame('1000000000000000.00', $a->add($b)->to_string());
    }

    public function test_sub_basic(): void
    {
        $a = Cashback_Money::from_string('10.00');
        $b = Cashback_Money::from_string('5.50');
        self::assertSame('4.50', $a->sub($b)->to_string());
    }

    public function test_sub_negative_result(): void
    {
        $a = Cashback_Money::from_string('5.00');
        $b = Cashback_Money::from_string('10.00');
        self::assertSame('-5.00', $a->sub($b)->to_string());
    }

    public function test_sub_boundary(): void
    {
        // Float-trap: 1.0 - 0.99 = 0.009999... в binary float.
        $a = Cashback_Money::from_string('1.00');
        $b = Cashback_Money::from_string('0.99');
        self::assertSame('0.01', $a->sub($b)->to_string());
    }

    public function test_mul_rate_basic(): void
    {
        // 100.00 * 5% = 5.00
        $m = Cashback_Money::from_string('100.00');
        self::assertSame('5.00', $m->mul_rate('5')->to_string());
    }

    public function test_mul_rate_percent_with_decimals(): void
    {
        // 100.00 * 5.5% = 5.50
        $m = Cashback_Money::from_string('100.00');
        self::assertSame('5.50', $m->mul_rate('5.5')->to_string());
    }

    public function test_mul_rate_truncates_to_2_decimals(): void
    {
        // 100.00 * 3.333% = 3.333 → truncate (BCMath default, не round) → 3.33.
        // Документируется явно: при необходимости half-up добавится отдельный метод.
        $m = Cashback_Money::from_string('100.00');
        self::assertSame('3.33', $m->mul_rate('3.333')->to_string());
    }

    public function test_mul_rate_zero_rate_gives_zero(): void
    {
        $m = Cashback_Money::from_string('1234.56');
        self::assertTrue($m->mul_rate('0')->is_zero());
    }

    public function test_mul_rate_rejects_invalid_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Money::from_string('100.00')->mul_rate('abc');
    }

    public function test_mul_rate_rejects_comma_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Money::from_string('100.00')->mul_rate('5,5');
    }

    public function test_negate_basic(): void
    {
        $m = Cashback_Money::from_string('10.50');
        self::assertSame('-10.50', $m->negate()->to_string());
    }

    public function test_negate_negative_gives_positive(): void
    {
        $m = Cashback_Money::from_string('-10.50');
        self::assertSame('10.50', $m->negate()->to_string());
    }

    public function test_negate_zero_stays_zero(): void
    {
        // Canonical: -0.00 ≡ 0.00 (no signed zero artefact).
        $m = Cashback_Money::zero();
        self::assertSame('0.00', $m->negate()->to_string());
    }

    // ================================================================
    // Группа 3: Comparison
    // ================================================================

    public function test_compare_equal(): void
    {
        $a = Cashback_Money::from_string('10.00');
        $b = Cashback_Money::from_string('10.00');
        self::assertSame(0, $a->compare($b));
    }

    public function test_compare_less(): void
    {
        $a = Cashback_Money::from_string('5.00');
        $b = Cashback_Money::from_string('10.00');
        self::assertSame(-1, $a->compare($b));
    }

    public function test_compare_greater(): void
    {
        $a = Cashback_Money::from_string('10.00');
        $b = Cashback_Money::from_string('5.00');
        self::assertSame(1, $a->compare($b));
    }

    public function test_equals_same_value(): void
    {
        $a = Cashback_Money::from_string('10.00');
        $b = Cashback_Money::from_string('10.00');
        self::assertTrue($a->equals($b));
    }

    public function test_equals_canonical_form(): void
    {
        // `from_string('10')` должен нормализоваться к `10.00` и быть равен `from_string('10.00')`.
        $a = Cashback_Money::from_string('10');
        $b = Cashback_Money::from_string('10.00');
        self::assertTrue($a->equals($b));
    }

    public function test_is_zero_variations(): void
    {
        self::assertTrue(Cashback_Money::from_string('0.00')->is_zero());
        self::assertTrue(Cashback_Money::from_string('0')->is_zero());
        self::assertTrue(Cashback_Money::from_string('-0.00')->is_zero());
    }

    public function test_is_negative_for_negative(): void
    {
        self::assertTrue(Cashback_Money::from_string('-0.01')->is_negative());
    }

    public function test_is_negative_false_for_zero(): void
    {
        self::assertFalse(Cashback_Money::zero()->is_negative());
    }

    public function test_is_positive_for_positive(): void
    {
        self::assertTrue(Cashback_Money::from_string('0.01')->is_positive());
    }

    public function test_is_positive_false_for_zero(): void
    {
        self::assertFalse(Cashback_Money::zero()->is_positive());
    }

    public function test_is_greater_than(): void
    {
        $a = Cashback_Money::from_string('10.00');
        $b = Cashback_Money::from_string('5.00');
        self::assertTrue($a->is_greater_than($b));
        self::assertFalse($b->is_greater_than($a));
        self::assertFalse($a->is_greater_than($a));
    }

    public function test_is_less_than(): void
    {
        $a = Cashback_Money::from_string('5.00');
        $b = Cashback_Money::from_string('10.00');
        self::assertTrue($a->is_less_than($b));
        self::assertFalse($b->is_less_than($a));
        self::assertFalse($a->is_less_than($a));
    }

    // ================================================================
    // Группа 4: Immutability
    // ================================================================

    public function test_add_does_not_mutate_receiver(): void
    {
        $a = Cashback_Money::from_string('10.00');
        $b = Cashback_Money::from_string('5.00');
        $a->add($b);
        self::assertSame('10.00', $a->to_string(), 'add() must not mutate receiver');
    }

    public function test_sub_does_not_mutate_receiver(): void
    {
        $a = Cashback_Money::from_string('10.00');
        $b = Cashback_Money::from_string('5.00');
        $a->sub($b);
        self::assertSame('10.00', $a->to_string(), 'sub() must not mutate receiver');
    }

    public function test_mul_rate_does_not_mutate_receiver(): void
    {
        $a = Cashback_Money::from_string('100.00');
        $a->mul_rate('5');
        self::assertSame('100.00', $a->to_string(), 'mul_rate() must not mutate receiver');
    }

    public function test_negate_does_not_mutate_receiver(): void
    {
        $a = Cashback_Money::from_string('10.00');
        $a->negate();
        self::assertSame('10.00', $a->to_string(), 'negate() must not mutate receiver');
    }

    // ================================================================
    // Группа 5: Serialization
    // ================================================================

    public function test_to_string_canonical_format(): void
    {
        self::assertSame('0.00', Cashback_Money::from_string('0')->to_string());
        self::assertSame('1.00', Cashback_Money::from_string('1')->to_string());
        self::assertSame('1.50', Cashback_Money::from_string('1.5')->to_string());
        self::assertSame('1.50', Cashback_Money::from_string('1.50')->to_string());
    }

    public function test_to_db_value_matches_to_string(): void
    {
        $m = Cashback_Money::from_string('1234.56');
        self::assertSame($m->to_string(), $m->to_db_value());
    }

    // ================================================================
    // Группа 6: assert_money_string (static)
    // ================================================================

    public function test_assert_money_string_accepts_canonical(): void
    {
        Cashback_Money::assert_money_string('1234.56');
        Cashback_Money::assert_money_string('-10.00');
        Cashback_Money::assert_money_string('0.00');
        self::assertTrue(true, 'no exception thrown');
    }

    public function test_assert_money_string_rejects_float(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentional runtime error */
        Cashback_Money::assert_money_string(1234.56);
    }

    public function test_assert_money_string_rejects_object(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentional runtime error */
        Cashback_Money::assert_money_string(Cashback_Money::zero());
    }

    public function test_assert_money_string_rejects_non_canonical(): void
    {
        // Даже строка, но не в canonical формате (3 знака) — fail.
        $this->expectException(InvalidArgumentException::class);
        Cashback_Money::assert_money_string('1.999');
    }

    public function test_assert_money_string_rejects_comma(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Money::assert_money_string('1,50');
    }
}
