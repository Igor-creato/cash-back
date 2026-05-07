<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional тесты на Cashback_Cashback_Display_Calculator (v12, Этап 7).
 *
 * Проверяем чистые расчётные методы (compute_from_tariffs, formatting) и
 * effective_rate (через wpdb stub). Полный e2e (resolve preferred → cache →
 * render HTML) покрыт structural-проверкой подмены в wc-affiliate-url-params.php.
 *
 * @group shop-import
 * @group display-calculator
 */
#[Group('shop-import')]
#[Group('display-calculator')]
final class ShopDisplayCalculatorTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        require_once dirname(__DIR__) . '/Shop_Test_Wpdb_Stub.php';

        if (!class_exists('Cashback_Shop_Options')) {
            require_once self::$plugin_root . '/includes/class-cashback-shop-options.php';
        }
        if (!class_exists('Cashback_Cashback_Display_Calculator')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-cashback-display-calculator.php';
        }
    }

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new Shop_Test_Wpdb_Stub();
        $GLOBALS['_cb_test_options']  = array();
        $GLOBALS['_cb_test_filters']  = array();
        $GLOBALS['_cb_test_user_id']  = 0;
        $GLOBALS['_cb_test_is_logged_in'] = false;
    }

    private function tariff_row(string $type, float $size, string $currency = 'RUB'): array
    {
        return array(
            'tariff_id'    => $type . '-' . (int) ($size * 100),
            'name'         => 'X',
            'tariff_type'  => $type,
            'payment_size' => $size,
            'currency'     => $currency,
            'is_default'   => 0,
            'is_deleted'   => 0,
        );
    }

    // ============================================================
    // compute_from_tariffs — single PERCENT
    // ============================================================

    public function test_single_percent_at_60_user_rate(): void
    {
        // Admitad 10% × user 60% = 6%.
        $r = Cashback_Cashback_Display_Calculator::compute_from_tariffs(
            array($this->tariff_row('percent', 10.0)),
            60.0
        );
        $this->assertSame('percent', $r['type']);
        $this->assertFalse($r['is_multi']);
        $this->assertSame(6.0, $r['value']);
        $this->assertSame('6%', $r['formatted']);
    }

    public function test_single_percent_at_65_user_rate_returns_decimal(): void
    {
        // 10% × 65% = 6.5%.
        $r = Cashback_Cashback_Display_Calculator::compute_from_tariffs(
            array($this->tariff_row('percent', 10.0)),
            65.0
        );
        $this->assertSame(6.5, $r['value']);
        $this->assertSame('6.5%', $r['formatted']);
    }

    public function test_percent_strips_trailing_zeros(): void
    {
        // 50% × 60% = 30 → "30%" не "30.00%".
        $r = Cashback_Cashback_Display_Calculator::compute_from_tariffs(
            array($this->tariff_row('percent', 50.0)),
            60.0
        );
        $this->assertSame('30%', $r['formatted']);
    }

    // ============================================================
    // compute_from_tariffs — multi PERCENT (Joom-class)
    // ============================================================

    public function test_multi_percent_uses_max_with_do_prefix(): void
    {
        // Joom: max=15.05% × 60% = 9.03%.
        $r = Cashback_Cashback_Display_Calculator::compute_from_tariffs(
            array(
                $this->tariff_row('percent', 9.49),
                $this->tariff_row('percent', 15.05),
                $this->tariff_row('percent', 1.55),
                $this->tariff_row('percent', 3.92),
                $this->tariff_row('percent', 7.94),
                $this->tariff_row('percent', 11.86),
            ),
            60.0
        );
        $this->assertSame('percent', $r['type']);
        $this->assertTrue($r['is_multi']);
        $this->assertSame(9.03, $r['value']);
        $this->assertSame('до 9.03%', $r['formatted']);
    }

    // ============================================================
    // compute_from_tariffs — single FIX
    // ============================================================

    public function test_single_fix_at_60_user_rate(): void
    {
        // Тариф 100 ₽ × 60% = 60 ₽.
        $r = Cashback_Cashback_Display_Calculator::compute_from_tariffs(
            array($this->tariff_row('fix', 100.0, 'RUB')),
            60.0
        );
        $this->assertSame('fix', $r['type']);
        $this->assertFalse($r['is_multi']);
        $this->assertSame(60.0, $r['value']);
        $this->assertSame('60 ₽', $r['formatted']);
        $this->assertSame('RUB', $r['currency']);
    }

    public function test_single_fix_eur_currency_symbol(): void
    {
        $r = Cashback_Cashback_Display_Calculator::compute_from_tariffs(
            array($this->tariff_row('fix', 200.0, 'EUR')),
            50.0
        );
        $this->assertSame('100 €', $r['formatted']);
        $this->assertSame('EUR', $r['currency']);
    }

    public function test_unknown_currency_falls_back_to_code(): void
    {
        $r = Cashback_Cashback_Display_Calculator::compute_from_tariffs(
            array($this->tariff_row('fix', 100.0, 'GBP')),
            60.0
        );
        $this->assertSame('60 GBP', $r['formatted']);
    }

    // ============================================================
    // compute_from_tariffs — multi FIX
    // ============================================================

    public function test_multi_fix_uses_max_with_do_prefix(): void
    {
        $r = Cashback_Cashback_Display_Calculator::compute_from_tariffs(
            array(
                $this->tariff_row('fix', 100.0),
                $this->tariff_row('fix', 200.0),
            ),
            60.0
        );
        $this->assertSame('fix', $r['type']);
        $this->assertTrue($r['is_multi']);
        $this->assertSame(120.0, $r['value']);
        $this->assertSame('до 120 ₽', $r['formatted']);
    }

    // ============================================================
    // mixed PERCENT + FIX → берём только PERCENT (по плану)
    // ============================================================

    public function test_mixed_percent_and_fix_keeps_only_percent(): void
    {
        $r = Cashback_Cashback_Display_Calculator::compute_from_tariffs(
            array(
                $this->tariff_row('percent', 10.0),
                $this->tariff_row('fix', 500.0),
                $this->tariff_row('percent', 5.0),
            ),
            60.0
        );
        $this->assertSame('percent', $r['type']);
        $this->assertTrue($r['is_multi']);
        $this->assertSame(6.0, $r['value']); // max(10, 5) × 60% = 6%
        $this->assertSame('до 6%', $r['formatted']);
    }

    // ============================================================
    // empty tariffs — пустой результат (легаси-фолбэк)
    // ============================================================

    public function test_empty_tariffs_returns_empty(): void
    {
        $r = Cashback_Cashback_Display_Calculator::compute_from_tariffs(array(), 60.0);
        $this->assertSame(array(), $r);
    }

    public function test_unknown_tariff_type_filtered_out(): void
    {
        $r = Cashback_Cashback_Display_Calculator::compute_from_tariffs(
            array(array('tariff_type' => 'click', 'payment_size' => 5.0, 'currency' => 'RUB')),
            60.0
        );
        $this->assertSame(array(), $r, 'unknown type → empty');
    }

    // ============================================================
    // effective_rate — guest vs logged-in
    // ============================================================

    public function test_effective_rate_for_guest_uses_option(): void
    {
        update_option('cashback_guest_display_rate', 70.0);
        $rate = Cashback_Cashback_Display_Calculator::effective_rate(0);
        $this->assertSame(70.0, $rate);
    }

    public function test_effective_rate_for_guest_default_when_no_option(): void
    {
        // Нет опции → DEFAULT_GUEST_RATE = 60.0.
        $rate = Cashback_Cashback_Display_Calculator::effective_rate(0);
        $this->assertSame(60.0, $rate);
    }

    public function test_effective_rate_for_logged_in_uses_user_profile(): void
    {
        global $wpdb;
        $wpdb->next_get_var = '85.5'; // имитируем cashback_user_profile.cashback_rate

        $rate = Cashback_Cashback_Display_Calculator::effective_rate(123);
        $this->assertSame(85.5, $rate);
    }

    public function test_effective_rate_clamps_user_rate(): void
    {
        global $wpdb;
        $wpdb->next_get_var = '999';

        $rate = Cashback_Cashback_Display_Calculator::effective_rate(123);
        $this->assertSame(100.0, $rate, 'user rate clamped к 100');
    }

    public function test_effective_rate_falls_back_when_user_profile_missing(): void
    {
        global $wpdb;
        $wpdb->next_get_var = null;
        update_option('cashback_guest_display_rate', 75.0);

        // Нет profile-row → fallback на guest_rate.
        $rate = Cashback_Cashback_Display_Calculator::effective_rate(123);
        $this->assertSame(75.0, $rate);
    }

    // ============================================================
    // Constants
    // ============================================================

    public function test_constants_exposed(): void
    {
        $this->assertSame('cashback_display', Cashback_Cashback_Display_Calculator::CACHE_GROUP);
        $this->assertSame('cb_disp', Cashback_Cashback_Display_Calculator::CACHE_PREFIX);
        $this->assertSame('cashback_use_dynamic_display', Cashback_Cashback_Display_Calculator::FILTER_ENABLE);
    }

    // ============================================================
    // Frontend подмена — структурная проверка wc-affiliate-url-params.php
    // ============================================================

    public function test_wc_affiliate_url_params_calls_calculator(): void
    {
        $php = file_get_contents(self::$plugin_root . '/wc-affiliate-url-params.php');
        $this->assertStringContainsString(
            'Cashback_Cashback_Display_Calculator::render',
            $php,
            'wc-affiliate-url-params.php должен вызывать Calculator::render для динамического display'
        );
        // Проверяем что класс вызывается ровно в 2 местах — get_cashback_html
        // (приватный) и render_cashback_html (статический).
        $count = substr_count($php, 'Cashback_Cashback_Display_Calculator::render');
        $this->assertGreaterThanOrEqual(2, $count, 'обе точки должны быть подменены');
    }

    public function test_wc_affiliate_url_params_keeps_legacy_fallback(): void
    {
        $php = file_get_contents(self::$plugin_root . '/wc-affiliate-url-params.php');
        // Legacy чтение _cashback_display_value оставлено для не-импортированных
        // продуктов (Calculator вернёт '' → падаем в legacy).
        $this->assertStringContainsString("'_cashback_display_value'", $php);
    }

    // ============================================================
    // Структурная проверка: dynamic-render обязан включать label-span.
    // Регрессия 2026-05-08: после включения dynamic-display метка
    // "Кэшбэк" перестала отображаться на карточках — render_uncached
    // выводил только value без обёртки с label, в отличие от legacy
    // get_cashback_html / render_cashback_html / legacy_fallback.
    // ============================================================

    public function test_render_uncached_emits_label_span(): void
    {
        $php = file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-cashback-display-calculator.php');
        $this->assertStringContainsString(
            'cashback-display__label',
            $php,
            'render_uncached должен оборачивать метку в <span class="cashback-display__label">'
        );
        $this->assertStringContainsString(
            'cashback-display__value',
            $php,
            'render_uncached должен оборачивать значение в <span class="cashback-display__value">'
        );
        $this->assertStringContainsString(
            "'_cashback_display_label'",
            $php,
            'render_uncached должен читать post_meta _cashback_display_label'
        );
        $this->assertMatchesRegularExpression(
            "/\\\$label\s*=\s*'Кэшбэк';/u",
            $php,
            'fallback-метка должна быть "Кэшбэк" (как в legacy_fallback / render_cashback_html)'
        );
    }
}
