<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Approval-factor для каталог-sort: умножение sort_value на rate_of_approve.
 *
 * Покрывает Cashback_Cashback_Display_Calculator::get_approval_factor:
 *   - свежая meta → rate/100
 *   - missing/stale → prior 70%
 *   - clamp [0..100]
 *   - filter overrides (prior / floor / stale window)
 *   - wiring структуры: Refresher шлёт do_action, Provider шлёт, Product_Sort подписан + backfill_v2 на месте
 *
 * @group shop-import
 * @group display-calculator
 * @group sort-approval
 */
#[Group('shop-import')]
#[Group('display-calculator')]
#[Group('sort-approval')]
final class ShopDisplayCalculatorApprovalFactorTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        if (! class_exists('Cashback_Shop_Options')) {
            require_once self::$plugin_root . '/includes/class-cashback-shop-options.php';
        }
        if (! class_exists('Cashback_Shop_Rate_Of_Approve_Refresher')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-shop-rate-of-approve-refresher.php';
        }
        if (! class_exists('Cashback_Cashback_Display_Calculator')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-cashback-display-calculator.php';
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_post_meta'] = array();
        $GLOBALS['_cb_test_filters']   = array();
    }

    private function set_rate( int $product_id, $rate, ?int $fetched_at = null ): void
    {
        $now = $fetched_at ?? time();
        update_post_meta($product_id, Cashback_Shop_Rate_Of_Approve_Refresher::META_RATE, (string) $rate);
        update_post_meta($product_id, Cashback_Shop_Rate_Of_Approve_Refresher::META_FETCHED_AT, (string) $now);
    }

    // ============================================================
    // get_approval_factor — основные сценарии
    // ============================================================

    public function test_factor_uses_fresh_rate_from_meta(): void
    {
        // 66.0% → factor = 0.66 (на скриншоте — пример продукта)
        $this->set_rate(101, 66.0);
        $this->assertSame(
            0.66,
            round(Cashback_Cashback_Display_Calculator::get_approval_factor(101), 4)
        );
    }

    public function test_factor_uses_prior_70_when_meta_missing(): void
    {
        // Постмета не задана → prior 70 → factor = 0.7.
        $this->assertSame(
            0.70,
            round(Cashback_Cashback_Display_Calculator::get_approval_factor(202), 4)
        );
    }

    public function test_factor_uses_prior_when_meta_stale(): void
    {
        // fetched_at — 30 дней назад при stale_after_days=7 → используем prior.
        $stale_ts = time() - (30 * 86400);
        $this->set_rate(303, 90.0, $stale_ts);

        $this->assertSame(
            0.70,
            round(Cashback_Cashback_Display_Calculator::get_approval_factor(303), 4),
            'stale meta must fall back to prior'
        );
    }

    public function test_factor_clamps_above_100(): void
    {
        // Битый API: rate=150 → clamp к 100 → factor=1.0.
        $this->set_rate(404, 150.0);
        $this->assertSame(
            1.0,
            round(Cashback_Cashback_Display_Calculator::get_approval_factor(404), 4)
        );
    }

    public function test_factor_clamps_below_zero(): void
    {
        // Битый API: rate=-5 → clamp к 0 → factor=0.0 (без floor).
        $this->set_rate(505, -5.0);
        $this->assertSame(
            0.0,
            round(Cashback_Cashback_Display_Calculator::get_approval_factor(505), 4)
        );
    }

    public function test_factor_zero_when_observed_zero_approval(): void
    {
        // Реальный 0% (все заказы отклонены) — доверяем API.
        $this->set_rate(606, 0.0);
        $this->assertSame(
            0.0,
            round(Cashback_Cashback_Display_Calculator::get_approval_factor(606), 4)
        );
    }

    // ============================================================
    // Filters
    // ============================================================

    public function test_filter_overrides_prior_value(): void
    {
        // Default 70 → override 80.
        add_filter('cashback_sort_approval_prior', static fn() => 80.0);
        $this->assertSame(
            0.80,
            round(Cashback_Cashback_Display_Calculator::get_approval_factor(707), 4)
        );
    }

    public function test_filter_overrides_stale_window(): void
    {
        // По умолчанию 7 дней stale. Расширяем до 90 → значение 30-дневной давности валидно.
        $thirty_days_ago = time() - (30 * 86400);
        $this->set_rate(808, 88.0, $thirty_days_ago);

        add_filter('cashback_sort_approval_stale_after_days', static fn() => 90);

        $this->assertSame(
            0.88,
            round(Cashback_Cashback_Display_Calculator::get_approval_factor(808), 4),
            'rate из 30d при stale_window=90d должен использоваться'
        );
    }

    public function test_filter_floor_raises_observed_low_rate(): void
    {
        // Магазин с реальным 5% approval, floor=30 → effective = max(5,30)=30 → factor=0.30.
        $this->set_rate(909, 5.0);
        add_filter('cashback_sort_approval_floor', static fn() => 30.0);

        $this->assertSame(
            0.30,
            round(Cashback_Cashback_Display_Calculator::get_approval_factor(909), 4)
        );
    }

    public function test_filter_prior_also_clamped(): void
    {
        // Override prior=150 (битый фильтр) → clamp к 100 → factor=1.0.
        add_filter('cashback_sort_approval_prior', static fn() => 150.0);
        $this->assertSame(
            1.0,
            round(Cashback_Cashback_Display_Calculator::get_approval_factor(1010), 4)
        );
    }

    public function test_factor_for_invalid_product_id(): void
    {
        // product_id <= 0 → 1.0 (нейтральный для sort_value=0 в caller).
        $this->assertSame(1.0, Cashback_Cashback_Display_Calculator::get_approval_factor(0));
        $this->assertSame(1.0, Cashback_Cashback_Display_Calculator::get_approval_factor(-7));
    }

    // ============================================================
    // Structural: compute_sort_value умножает на approval_factor
    // ============================================================

    public function test_compute_sort_value_multiplies_by_approval_factor(): void
    {
        $php = (string) file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-cashback-display-calculator.php');
        $this->assertStringContainsString(
            'get_approval_factor',
            $php,
            'compute_sort_value должен звать helper get_approval_factor'
        );
        $this->assertMatchesRegularExpression(
            '/\$base\s*\*\s*self::get_approval_factor\(\$product_id\)/u',
            $php,
            'итоговый sort_value = base × approval_factor'
        );
    }

    public function test_get_approval_factor_uses_refresher_meta_constants(): void
    {
        $php = (string) file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-cashback-display-calculator.php');
        // Проверяем, что helper тянет ключи из Refresher (single source of truth),
        // не дублирует строковые литералы.
        $this->assertStringContainsString(
            'Cashback_Shop_Rate_Of_Approve_Refresher::META_RATE',
            $php
        );
        $this->assertStringContainsString(
            'Cashback_Shop_Rate_Of_Approve_Refresher::META_FETCHED_AT',
            $php
        );
    }

    // ============================================================
    // Structural: action wiring
    // ============================================================

    public function test_refresher_fires_updated_action_on_save(): void
    {
        $php = (string) file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-shop-rate-of-approve-refresher.php');
        $this->assertStringContainsString(
            "do_action('cashback_rate_of_approve_updated', \$product_id)",
            $php,
            'Refresher::save_rate_for_product должен стрелять action для пересчёта sort_value'
        );
    }

    public function test_provider_manual_save_fires_updated_action(): void
    {
        $php = (string) file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-cpa-approval-rate-provider.php');
        $this->assertStringContainsString(
            "do_action('cashback_rate_of_approve_updated', \$product_id)",
            $php,
            'Provider::save_manual_rate должен стрелять action — и при write, и при delete'
        );
        // Должно быть в двух местах — для write и для delete (is_empty).
        $count = substr_count($php, "do_action('cashback_rate_of_approve_updated', \$product_id)");
        $this->assertGreaterThanOrEqual(
            2,
            $count,
            'save_manual_rate шлёт action и при сохранении значения, и при удалении (пустое поле)'
        );
    }

    public function test_product_sort_subscribes_to_rate_of_approve_action(): void
    {
        $php = (string) file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-product-sort.php');
        $this->assertMatchesRegularExpression(
            "/add_action\(\s*'cashback_rate_of_approve_updated'\s*,\s*array\(\s*__CLASS__\s*,\s*'recompute_for_product'\s*\)/u",
            $php,
            'Product_Sort::register подписан на cashback_rate_of_approve_updated → recompute_for_product'
        );
    }

    public function test_product_sort_has_backfill_v2(): void
    {
        $php = (string) file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-product-sort.php');
        $this->assertStringContainsString(
            "BACKFILL_OPTION_V2   = 'cashback_product_sort_backfill_v2'",
            $php,
            'опция-флаг V2 должна существовать'
        );
        $this->assertStringContainsString(
            'public static function ensure_backfilled_v2()',
            $php,
            'ensure_backfilled_v2 объявлен'
        );
        $this->assertStringContainsString(
            'public static function handle_backfill_cron_v2()',
            $php,
            'handle_backfill_cron_v2 объявлен'
        );
    }

    public function test_bootstrap_calls_ensure_backfilled_v2(): void
    {
        $php = (string) file_get_contents(self::$plugin_root . '/cashback-plugin.php');
        $this->assertStringContainsString(
            'Cashback_Product_Sort::ensure_backfilled_v2()',
            $php,
            'cashback-plugin.php должен звать V2 backfill при init'
        );
    }
}
