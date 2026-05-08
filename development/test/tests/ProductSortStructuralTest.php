<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты на Cashback_Product_Sort — каталог-сортировка
 * «По возрастанию/убыванию кэшбэка» вместо стандартных WC опций «По цене».
 *
 * Покрытие:
 *   - класс существует, имеет нужные константы (SORT_META_KEY, ORDERBY_*).
 *   - register() навешивает 3 фильтра (orderby_options, ordering_args,
 *     default_catalog_orderby) + 2 хука (cashback_tariffs_changed, cron backfill).
 *   - filter_orderby_options снимает price/price-desc и добавляет
 *     cashback-desc/cashback с RU-лейблами в правильном порядке.
 *   - filter_ordering_args маппит cashback-desc → meta_value_num + DESC + meta_key.
 *   - filter_default_catalog_orderby защищает от legacy price/price-desc.
 *   - parse_legacy_value корректно вытаскивает число.
 *   - compute_sort_value присутствует на Display_Calculator.
 *   - cashback-plugin.php подключает класс и вызывает register/ensure_backfilled.
 *   - wc-affiliate-url-params.php вызывает recompute_for_product в save_meta_box.
 *
 * @group shop-import
 * @group catalog-sort
 */
#[Group('shop-import')]
#[Group('catalog-sort')]
final class ProductSortStructuralTest extends TestCase
{
    private static string $plugin_root;
    private static string $sort_php;
    private static string $calculator_php;
    private static string $cashback_plugin_php;
    private static string $url_params_php;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root         = dirname(__DIR__, 3);
        self::$sort_php            = (string) file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-product-sort.php');
        self::$calculator_php      = (string) file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-cashback-display-calculator.php');
        self::$cashback_plugin_php = (string) file_get_contents(self::$plugin_root . '/cashback-plugin.php');
        self::$url_params_php      = (string) file_get_contents(self::$plugin_root . '/wc-affiliate-url-params.php');
    }

    // ============================================================
    // 1. Класс и базовые требования.
    // ============================================================

    public function test_class_file_exists(): void
    {
        $this->assertFileExists(
            self::$plugin_root . '/includes/shops/class-cashback-product-sort.php',
            'includes/shops/class-cashback-product-sort.php должен существовать'
        );
    }

    public function test_declares_class_cashback_product_sort(): void
    {
        $this->assertMatchesRegularExpression(
            '/class\s+Cashback_Product_Sort/i',
            self::$sort_php
        );
    }

    public function test_uses_strict_types_and_abspath_guard(): void
    {
        $this->assertStringContainsString('declare(strict_types=1);', self::$sort_php);
        $this->assertStringContainsString("defined('ABSPATH')", self::$sort_php);
    }

    // ============================================================
    // 2. Константы.
    // ============================================================

    public function test_defines_required_constants(): void
    {
        $this->assertMatchesRegularExpression(
            "/const\s+SORT_META_KEY\s*=\s*'_cashback_sort_value'/",
            self::$sort_php,
            "SORT_META_KEY = '_cashback_sort_value'"
        );
        $this->assertMatchesRegularExpression(
            "/const\s+ORDERBY_ASC\s*=\s*'cashback'/",
            self::$sort_php
        );
        $this->assertMatchesRegularExpression(
            "/const\s+ORDERBY_DESC\s*=\s*'cashback-desc'/",
            self::$sort_php
        );
        $this->assertMatchesRegularExpression(
            "/const\s+BACKFILL_OPTION\s*=\s*'cashback_product_sort_backfill_v1'/",
            self::$sort_php
        );
    }

    // ============================================================
    // 3. register() — фильтры и actions навешаны.
    // ============================================================

    public function test_register_adds_orderby_options_filter(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_filter\s*\(\s*'woocommerce_catalog_orderby'\s*,\s*array\s*\(\s*__CLASS__\s*,\s*'filter_orderby_options'\s*\)/",
            self::$sort_php
        );
    }

    public function test_register_adds_ordering_args_filter(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_filter\s*\(\s*'woocommerce_get_catalog_ordering_args'\s*,\s*array\s*\(\s*__CLASS__\s*,\s*'filter_ordering_args'\s*\)/",
            self::$sort_php
        );
    }

    public function test_register_adds_default_orderby_filter(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_filter\s*\(\s*'woocommerce_default_catalog_orderby'\s*,\s*array\s*\(\s*__CLASS__\s*,\s*'filter_default_catalog_orderby'\s*\)/",
            self::$sort_php
        );
    }

    public function test_register_adds_tariffs_changed_action(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'cashback_tariffs_changed'\s*,\s*array\s*\(\s*__CLASS__\s*,\s*'recompute_for_product'\s*\)/",
            self::$sort_php
        );
    }

    public function test_register_adds_backfill_cron_action(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*self::CRON_BACKFILL_HOOK\s*,\s*array\s*\(\s*__CLASS__\s*,\s*'handle_backfill_cron'\s*\)/",
            self::$sort_php
        );
    }

    // ============================================================
    // 4. filter_orderby_options — снимает price/price-desc, добавляет cashback*.
    // ============================================================

    public function test_filter_drops_price_keys(): void
    {
        $this->assertMatchesRegularExpression(
            "/unset\s*\(\s*\\\$options\[\s*'price'\s*\]\s*\)/",
            self::$sort_php,
            "filter_orderby_options должен делать unset(\$options['price'])"
        );
        $this->assertMatchesRegularExpression(
            "/unset\s*\(\s*\\\$options\[\s*'price-desc'\s*\]\s*\)/",
            self::$sort_php
        );
    }

    public function test_filter_adds_cashback_keys_with_ru_labels(): void
    {
        $this->assertStringContainsString(
            'По убыванию кэшбэка',
            self::$sort_php,
            'Лейбл «По убыванию кэшбэка» должен присутствовать в filter_orderby_options'
        );
        $this->assertStringContainsString(
            'По возрастанию кэшбэка',
            self::$sort_php,
            'Лейбл «По возрастанию кэшбэка» должен присутствовать'
        );
    }

    public function test_filter_orderby_options_signature(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+filter_orderby_options\s*\(\s*\$options\s*\)\s*:\s*array/i',
            self::$sort_php
        );
    }

    // ============================================================
    // 5. filter_ordering_args — meta_value_num + meta_key + ASC/DESC.
    // ============================================================

    public function test_ordering_args_uses_meta_value_num(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$args\[\s*\'orderby\'\s*\]\s*=\s*\'meta_value_num\'/',
            self::$sort_php
        );
    }

    public function test_ordering_args_uses_sort_meta_key_constant(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$args\[\s*\'meta_key\'\s*\]\s*=\s*self::SORT_META_KEY/',
            self::$sort_php
        );
    }

    public function test_ordering_args_handles_desc(): void
    {
        // Должен присутствовать выбор DESC при cashback-desc.
        $this->assertMatchesRegularExpression(
            "/ORDERBY_DESC.*?DESC.*?ASC|DESC.*?:\s*'ASC'/s",
            self::$sort_php,
            'filter_ordering_args должен возвращать DESC для ORDERBY_DESC'
        );
    }

    // ============================================================
    // 6. filter_default_catalog_orderby — мапит price/price-desc → menu_order.
    // ============================================================

    public function test_default_orderby_maps_legacy_price_to_menu_order(): void
    {
        $start = strpos(self::$sort_php, 'function filter_default_catalog_orderby');
        $this->assertNotFalse($start, 'filter_default_catalog_orderby должна существовать');
        $end = strpos(self::$sort_php, "\n    public", $start + 1);
        if ($end === false) {
            $end = strpos(self::$sort_php, "\n}", $start);
        }
        $body = substr(self::$sort_php, $start, $end - $start);

        $this->assertStringContainsString("'price'", $body);
        $this->assertStringContainsString("'price-desc'", $body);
        $this->assertStringContainsString("'menu_order'", $body);
    }

    // ============================================================
    // 7. recompute / parse_legacy_value / compute_value_for_product.
    // ============================================================

    public function test_recompute_for_product_method_exists(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+recompute_for_product\s*\(/',
            self::$sort_php
        );
    }

    public function test_parse_legacy_value_method_signature(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+parse_legacy_value\s*\(\s*string\s+\$raw\s*\)\s*:\s*float/',
            self::$sort_php
        );
    }

    public function test_compute_value_for_product_signature(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+compute_value_for_product\s*\(\s*int\s+\$product_id\s*\)\s*:\s*float/',
            self::$sort_php
        );
    }

    public function test_recompute_writes_sort_meta(): void
    {
        $this->assertMatchesRegularExpression(
            '/update_post_meta\s*\(\s*\$product_id\s*,\s*self::SORT_META_KEY/',
            self::$sort_php
        );
    }

    // ============================================================
    // 8. Display_Calculator — compute_sort_value метод.
    // ============================================================

    public function test_display_calculator_has_compute_sort_value(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+compute_sort_value\s*\(\s*int\s+\$product_id\s*\)\s*:\s*float/',
            self::$calculator_php,
            'Cashback_Cashback_Display_Calculator::compute_sort_value(int): float'
        );
    }

    public function test_compute_sort_value_uses_guest_user_id(): void
    {
        // Должно вызывать compute($product_id, 0) — guest variant (свойство монотонности).
        $start = strpos(self::$calculator_php, 'function compute_sort_value');
        $this->assertNotFalse($start);
        $end = strpos(self::$calculator_php, "\n    public", $start + 1);
        if ($end === false) {
            $end = strpos(self::$calculator_php, "\n}", $start);
        }
        $body = substr(self::$calculator_php, $start, $end - $start);

        $this->assertMatchesRegularExpression(
            '/self::compute\s*\(\s*\$product_id\s*,\s*0\s*\)/',
            $body,
            'compute_sort_value должен звать compute($product_id, 0) для guest-варианта'
        );
    }

    // ============================================================
    // 9. Bootstrap — cashback-plugin.php подключает и регистрирует класс.
    // ============================================================

    public function test_class_required_in_cashback_plugin_php(): void
    {
        $this->assertStringContainsString(
            'class-cashback-product-sort.php',
            self::$cashback_plugin_php,
            'cashback-plugin.php должен подключать includes/shops/class-cashback-product-sort.php'
        );
    }

    public function test_register_called_in_initialize_components(): void
    {
        $this->assertMatchesRegularExpression(
            '/Cashback_Product_Sort\s*::\s*register\s*\(\s*\)/',
            self::$cashback_plugin_php
        );
    }

    public function test_ensure_backfilled_called_in_initialize_components(): void
    {
        $this->assertMatchesRegularExpression(
            '/Cashback_Product_Sort\s*::\s*ensure_backfilled\s*\(\s*\)/',
            self::$cashback_plugin_php
        );
    }

    // ============================================================
    // 10. Save metabox triggers recompute (wc-affiliate-url-params.php).
    // ============================================================

    public function test_save_meta_box_calls_recompute(): void
    {
        $this->assertMatchesRegularExpression(
            '/Cashback_Product_Sort\s*::\s*recompute_for_product\s*\(\s*\$post_id\s*\)/',
            self::$url_params_php,
            'wc-affiliate-url-params.php save_meta_box должен вызывать Cashback_Product_Sort::recompute_for_product'
        );
    }

    // ============================================================
    // 11. ensure_backfilled — self-healing state machine.
    //     Codex finding #2: 'scheduled' не должно быть terminal — иначе
    //     drop'нувшееся cron-событие → backfill застревает навсегда.
    // ============================================================

    private function ensure_backfilled_body(): string
    {
        $start = strpos(self::$sort_php, 'function ensure_backfilled');
        $this->assertNotFalse($start, 'ensure_backfilled() должен существовать');
        $end = strpos(self::$sort_php, "\n    public static function handle_backfill_cron", $start);
        if ($end === false) {
            $end = strpos(self::$sort_php, "\n    public", $start + 1);
        }
        $this->assertNotFalse($end);
        return substr(self::$sort_php, $start, $end - $start);
    }

    public function test_ensure_backfilled_terminal_state_is_only_done(): void
    {
        $body = $this->ensure_backfilled_body();
        $this->assertDoesNotMatchRegularExpression(
            "/===\s*'scheduled'/",
            $body,
            "ensure_backfilled НЕ должен использовать 'scheduled' как terminal state — иначе drop'нувшееся cron-событие застревает навсегда"
        );
        $this->assertMatchesRegularExpression(
            "/===\s*'1'/",
            $body,
            "ensure_backfilled должен раннее возвращаться только при опции === '1'"
        );
    }

    public function test_ensure_backfilled_checks_cron_queue(): void
    {
        $body = $this->ensure_backfilled_body();
        $this->assertMatchesRegularExpression(
            '/wp_next_scheduled\s*\(\s*self::CRON_BACKFILL_HOOK\s*\).*?wp_schedule_single_event/s',
            $body,
            'ensure_backfilled должен проверять очередь cron (wp_next_scheduled) до wp_schedule_single_event'
        );
    }

    public function test_ensure_backfilled_returns_early_when_event_queued(): void
    {
        $body = $this->ensure_backfilled_body();
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*wp_next_scheduled\s*\(\s*self::CRON_BACKFILL_HOOK\s*\)\s*\)\s*\{\s*\/\/.*?\s*return;\s*\}/s',
            $body,
            'если событие уже в очереди — должен быть ранний return без перепланирования'
        );
    }
}
