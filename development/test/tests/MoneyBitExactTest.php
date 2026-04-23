<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * E2E bit-exact тесты граничных значений для Cashback_Money — ADR Группа 10, Step 5.
 *
 * Цель: доказать, что BCMath-based Money (выбранный путь A: DECIMAL(18,2)+BCMath)
 * даёт абсолютную точность на классических float-trap-значениях (0.01/0.99/
 * 1e6/negative/accumulation). Это контрактные тесты на поведение helper'а при
 * реалистичных денежных сценариях.
 *
 * Отличие от CashbackMoneyTest: там — unit-тесты каждого метода API. Здесь —
 * сценарные цепочки операций (100x add 0.01, 5.5% комиссия, reconciliation-like
 * accumulation), которые должны сходиться бит-в-бит против арифметики DECIMAL
 * в MySQL.
 *
 * Запуск:
 *   ./vendor/bin/phpunit --filter MoneyBitExactTest tests/MoneyBitExactTest.php
 */
#[Group('security')]
#[Group('group10')]
#[Group('money')]
#[Group('bit-exact')]
class MoneyBitExactTest extends TestCase
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
    // Группа 1: 0.01 accumulation — классический float-trap
    // ================================================================

    public function test_100_kopecks_accumulate_to_exactly_1_ruble(): void
    {
        // Float: 100 * 0.01 ≠ 1.0 (артефакты double). BCMath: ровно 1.00.
        $total = Cashback_Money::zero();
        for ($i = 0; $i < 100; ++$i) {
            $total = $total->add(Cashback_Money::from_string('0.01'));
        }
        self::assertSame('1.00', $total->to_string());
    }

    public function test_1000_kopecks_accumulate_to_exactly_10_rubles(): void
    {
        $total = Cashback_Money::zero();
        for ($i = 0; $i < 1000; ++$i) {
            $total = $total->add(Cashback_Money::from_string('0.01'));
        }
        self::assertSame('10.00', $total->to_string());
    }

    public function test_fractional_accumulation_mixed(): void
    {
        // 0.1 + 0.2 + ... + 0.9 = 4.5, не 4.4999999 (float)
        $total = Cashback_Money::zero();
        for ($cents = 1; $cents <= 9; ++$cents) {
            $total = $total->add(Cashback_Money::from_string(sprintf('0.%d0', $cents)));
        }
        self::assertSame('4.50', $total->to_string());
    }

    // ================================================================
    // Группа 2: Subtraction boundaries
    // ================================================================

    public function test_1_ruble_minus_1_kopeck_equals_99_kopeck(): void
    {
        $one    = Cashback_Money::from_string('1.00');
        $kopeck = Cashback_Money::from_string('0.01');
        self::assertSame('0.99', $one->sub($kopeck)->to_string());
    }

    public function test_99_kopecks_minus_98_kopecks_equals_1_kopeck(): void
    {
        $a = Cashback_Money::from_string('0.99');
        $b = Cashback_Money::from_string('0.98');
        self::assertSame('0.01', $a->sub($b)->to_string());
    }

    public function test_round_trip_through_zero_preserves_value(): void
    {
        // (-x).add(x) == 0 для любого canonical money-value.
        $amounts = array( '0.01', '0.99', '1.50', '123.45', '999999.99' );
        foreach ($amounts as $amt) {
            $m = Cashback_Money::from_string($amt);
            self::assertTrue(
                $m->negate()->add($m)->is_zero(),
                "negate(\$m).add(\$m) must be zero for {$amt}"
            );
        }
    }

    // ================================================================
    // Группа 3: Commission rate — реалистичный cashback flow
    // ================================================================

    public function test_cashback_5_5_percent_of_1000(): void
    {
        // 1000.00 * 5.5% = 55.00, не 54.9999... (float)
        $order      = Cashback_Money::from_string('1000.00');
        $commission = $order->mul_rate('5.5');
        self::assertSame('55.00', $commission->to_string());
    }

    public function test_cashback_small_order_rounding(): void
    {
        // 10.00 * 5.5% = 0.55 ровно.
        $order      = Cashback_Money::from_string('10.00');
        $commission = $order->mul_rate('5.5');
        self::assertSame('0.55', $commission->to_string());
    }

    public function test_cashback_truncates_sub_kopeck(): void
    {
        // 1.00 * 3.333% = 0.03333 → truncate(2) → 0.03.
        // Задокументированное поведение (BCMath default): half-away-from-zero
        // rounding НЕ применяется, используется truncate toward zero.
        $order      = Cashback_Money::from_string('1.00');
        $commission = $order->mul_rate('3.333');
        self::assertSame('0.03', $commission->to_string());
    }

    public function test_zero_cashback_from_zero_rate(): void
    {
        $order      = Cashback_Money::from_string('1234.56');
        $commission = $order->mul_rate('0');
        self::assertTrue($commission->is_zero());
    }

    // ================================================================
    // Группа 4: Large numbers — DECIMAL(18,2) boundary
    // ================================================================

    public function test_1_million_sub_1_kopeck(): void
    {
        $a = Cashback_Money::from_string('1000000.00');
        $b = Cashback_Money::from_string('0.01');
        self::assertSame('999999.99', $a->sub($b)->to_string());
    }

    public function test_max_decimal_18_2_add_boundary(): void
    {
        // DECIMAL(18,2) вмещает до 9999999999999999.99. Тестируем близко к границе.
        $near_max = Cashback_Money::from_string('9999999999999998.99');
        $kopeck   = Cashback_Money::from_string('0.01');
        self::assertSame('9999999999999999.00', $near_max->add($kopeck)->to_string());
    }

    // ================================================================
    // Группа 5: Negative values — refunds / reversals
    // ================================================================

    public function test_refund_via_negative_add(): void
    {
        // Ledger-reversal pattern: balance 100, refund 30 = balance 70.
        $balance = Cashback_Money::from_string('100.00');
        $refund  = Cashback_Money::from_string('-30.00');
        self::assertSame('70.00', $balance->add($refund)->to_string());
    }

    public function test_overdraft_yields_negative_balance(): void
    {
        $balance   = Cashback_Money::from_string('50.00');
        $withdraw  = Cashback_Money::from_string('75.00');
        $overdraft = $balance->sub($withdraw);
        self::assertTrue($overdraft->is_negative());
        self::assertSame('-25.00', $overdraft->to_string());
    }

    public function test_canonical_zero_after_mul_rate_zero(): void
    {
        // -0.00 canonical артефакт BCMath: конвертируем в 0.00.
        $negative_amount = Cashback_Money::from_string('-1.00');
        self::assertSame('0.00', $negative_amount->mul_rate('0')->to_string());
    }

    // ================================================================
    // Группа 6: Affiliate commission scenario — имитация реального flow
    // ================================================================

    public function test_affiliate_commission_chain(): void
    {
        // User cashback 500 ₽, referrer rate 10% → referrer commission 50 ₽.
        // При 20 покупках — ровно 1000 ₽ комиссии реферера.
        $total_commission = Cashback_Money::zero();
        for ($i = 0; $i < 20; ++$i) {
            $user_cashback      = Cashback_Money::from_string('500.00');
            $referrer_comission = $user_cashback->mul_rate('10');
            $total_commission   = $total_commission->add($referrer_comission);
        }
        self::assertSame('1000.00', $total_commission->to_string());
    }

    public function test_payout_balance_reconciliation(): void
    {
        // available 10000 ₽ → withdraw 3 раза по 2500.50 → остаток 2498.50.
        $balance   = Cashback_Money::from_string('10000.00');
        $withdraw  = Cashback_Money::from_string('2500.50');
        $remaining = $balance->sub($withdraw)->sub($withdraw)->sub($withdraw);
        self::assertSame('2498.50', $remaining->to_string());
    }

    // ================================================================
    // Группа 7: wpdb-boundary contract — to_db_value округляется правильно
    // ================================================================

    public function test_to_db_value_matches_canonical_regex(): void
    {
        $samples = array( '0.00', '0.01', '1.00', '999999.99', '-0.01', '-123.45' );
        foreach ($samples as $s) {
            $db_value = Cashback_Money::from_string($s)->to_db_value();
            self::assertMatchesRegularExpression(
                '/^-?\d+\.\d{2}$/',
                $db_value,
                "to_db_value must match canonical regex for input {$s}"
            );
        }
    }

    public function test_arithmetic_result_always_canonical(): void
    {
        // Любая последовательность add/sub/mul_rate/negate на Money должна давать
        // canonical decimal-string на выходе to_db_value() — защита wpdb-границы.
        $m = Cashback_Money::from_string('100.00')
            ->add(Cashback_Money::from_string('0.01'))
            ->sub(Cashback_Money::from_string('0.99'))
            ->mul_rate('50')
            ->negate();
        self::assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $m->to_db_value());
    }
}
