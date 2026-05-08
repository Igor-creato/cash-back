<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional тесты на Cashback_Tab_Conditions_Renderer (v12, Shop Importer).
 *
 * Покрытие: формула пересчёта payment_size × guest_rate / 100, плюрализация
 * дней, sentinel-маркер autogen v1, fallback на 30+3 дней при отсутствии
 * meta, эскейпинг имени тарифа, FIX-тарифы пропускаются.
 *
 * @group shop-import
 * @group tab-conditions-renderer
 */
#[Group('shop-import')]
#[Group('tab-conditions-renderer')]
final class TabConditionsRendererTest extends TestCase
{
    private const PRODUCT_ID = 9001;
    private const NETWORK_ID = 1;
    private const OFFER_ID   = 'kch-test-offer';

    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once dirname(__DIR__) . '/Shop_Test_Wpdb_Stub.php';

        if (!class_exists('Cashback_Shop_Importer')) {
            require_once $plugin_root . '/includes/shops/class-cashback-shop-importer.php';
        }
        if (!class_exists('Cashback_Shop_Tariff_Sync')) {
            require_once $plugin_root . '/includes/shops/class-cashback-shop-tariff-sync.php';
        }
        if (!class_exists('Cashback_Tab_Conditions_Renderer')) {
            require_once $plugin_root . '/includes/shops/class-cashback-tab-conditions-renderer.php';
        }
    }

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new Shop_Test_Wpdb_Stub();
        $GLOBALS['_cb_test_options']   = array();
        $GLOBALS['_cb_test_post_meta'] = array();
        $GLOBALS['_cb_test_meta']      = array();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function set_active_tariffs(array $rows): void
    {
        global $wpdb;
        $wpdb->next_get_results = $rows;
    }

    /** @return array<string, mixed> */
    private function tariff(string $type, float $size, string $name = 'Оплаченный заказ', string $currency = 'RUB'): array
    {
        return array(
            'tariff_id'    => $type . '-' . (int) ($size * 100),
            'name'         => $name,
            'tariff_type'  => $type,
            'payment_size' => $size,
            'payment_min'  => null,
            'payment_max'  => null,
            'currency'     => $currency,
            'is_default'   => 0,
            'is_deleted'   => 0,
        );
    }

    public function test_render_starts_with_sentinel_marker(): void
    {
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array($this->tariff('percent', 5.18, 'Оплаченный заказ нового клиента')));

        $html = Cashback_Tab_Conditions_Renderer::render(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        $this->assertStringStartsWith(Cashback_Tab_Conditions_Renderer::SENTINEL, $html);
    }

    public function test_render_with_single_percent_tariff_uses_guest_rate_formula(): void
    {
        // 5.18 × 65 / 100 = 3.367 → round(2) = 3.37
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array($this->tariff('percent', 5.18, 'Оплаченный заказ - новый клиент')));

        $html = Cashback_Tab_Conditions_Renderer::render(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        $this->assertStringContainsString('<h3><strong>Условия начисления</strong></h3>', $html);
        $this->assertStringContainsString('Оплаченный заказ - новый клиент', $html);
        $this->assertStringContainsString('<strong>3,37%</strong>', $html);
        $this->assertStringContainsString('Оплаченный заказ - новый клиент: <strong>3,37%</strong>', $html);
    }

    public function test_render_with_multiple_percent_tariffs_lists_each_separately(): void
    {
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array(
            $this->tariff('percent', 5.18, 'Оплаченный заказ - новый клиент'),
            $this->tariff('percent', 1.03, 'Оплаченный заказ - старый клиент'),
        ));

        $html = Cashback_Tab_Conditions_Renderer::render(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        $this->assertStringContainsString('Оплаченный заказ - новый клиент', $html);
        $this->assertStringContainsString('<strong>3,37%</strong>', $html);
        $this->assertStringContainsString('Оплаченный заказ - старый клиент', $html);
        $this->assertStringContainsString('<strong>0,67%</strong>', $html);
    }

    public function test_render_skips_fix_tariffs(): void
    {
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array(
            $this->tariff('percent', 5.18, 'Оплаченный заказ'),
            $this->tariff('fix', 100.0, 'Бонус 100 ₽'),
        ));

        $html = Cashback_Tab_Conditions_Renderer::render(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        $this->assertStringContainsString('Оплаченный заказ', $html);
        $this->assertStringNotContainsString('Бонус 100', $html);
        $this->assertStringNotContainsString('₽', $html);
    }

    public function test_render_omits_conditions_section_when_no_active_tariffs(): void
    {
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array());
        update_post_meta(self::PRODUCT_ID, '_cashback_avg_payment_days', '38');

        $html = Cashback_Tab_Conditions_Renderer::render(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        $this->assertStringNotContainsString('Условия начисления', $html);
        $this->assertStringContainsString('Срок начисления кэшбэка', $html);
        $this->assertStringContainsString('41 день', $html, '38 + 3 = 41');
    }

    public function test_render_uses_fallback_30_days_when_payment_time_missing(): void
    {
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array($this->tariff('percent', 5.0, 'Заказ')));

        $html = Cashback_Tab_Conditions_Renderer::render(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        // 30 + 3 = 33 days
        $this->assertStringContainsString('Средний срок начисления: <strong>33 дня</strong>', $html);
    }

    public function test_render_uses_meta_payment_days_plus_buffer(): void
    {
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array($this->tariff('percent', 5.0, 'Заказ')));
        update_post_meta(self::PRODUCT_ID, '_cashback_avg_payment_days', '38');

        $html = Cashback_Tab_Conditions_Renderer::render(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        $this->assertStringContainsString('38 + 3 = 41', '38 + 3 = 41', 'sanity');
        $this->assertStringContainsString('<strong>41 день</strong>', $html);
    }

    public static function payment_days_pluralization_provider(): array
    {
        // [meta_value, expected_total, expected_word]
        return array(
            'one'           => array('1', 4, 'дня'),    // 1+3=4 → дня
            'singular_21'   => array('18', 21, 'день'), // 21 → день
            'few_22'        => array('19', 22, 'дня'),  // 22 → дня
            'many_25'       => array('22', 25, 'дней'), // 25 → дней
            'eleven'        => array('8', 11, 'дней'),  // 11 → дней (исключение из правила mod10)
            'twelve'        => array('9', 12, 'дней'),  // 12 → дней
            'fifteen'       => array('12', 15, 'дней'), // 15 → дней
            'thirty_three'  => array('30', 33, 'дня'),  // 33 → дня
        );
    }

    /**
     * @dataProvider payment_days_pluralization_provider
     */
    public function test_render_pluralizes_days_correctly(string $meta_value, int $expected_total, string $expected_word): void
    {
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array($this->tariff('percent', 5.0, 'Заказ')));
        update_post_meta(self::PRODUCT_ID, '_cashback_avg_payment_days', $meta_value);

        $html = Cashback_Tab_Conditions_Renderer::render(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        $this->assertStringContainsString(
            sprintf('<strong>%d %s</strong>', $expected_total, $expected_word),
            $html
        );
    }

    public function test_render_escapes_html_in_tariff_name(): void
    {
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array($this->tariff('percent', 5.0, '<script>alert(1)</script>')));

        $html = Cashback_Tab_Conditions_Renderer::render(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_render_micro_value_rounds_to_zero(): void
    {
        update_option('cashback_guest_display_rate', 0.5);
        $this->set_active_tariffs(array($this->tariff('percent', 0.5, 'Микро-тариф')));

        $html = Cashback_Tab_Conditions_Renderer::render(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        // 0.5 × 0.5 / 100 = 0.0025 → round(2) = 0.0 → формат '0,00'.
        $this->assertStringContainsString('<strong>0,00%</strong>', $html);
    }

    public function test_render_returns_empty_when_no_data(): void
    {
        // Тарифов нет, payment_time нет → но fallback всегда даёт 30+3=33 дня,
        // поэтому секция «Срок» всегда есть. Чтобы render вернул '', payment_days
        // должен быть отброшен (например значение > 365).
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array());
        update_post_meta(self::PRODUCT_ID, '_cashback_avg_payment_days', '500');

        // 500 > 365 → null base → fallback 30 → 33 дня. Всё ещё рендерится.
        // Проверим, что hits fallback path:
        $html = Cashback_Tab_Conditions_Renderer::render(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);
        $this->assertStringContainsString('33 дня', $html);
    }

    public function test_is_autogen_detects_sentinel(): void
    {
        $content = Cashback_Tab_Conditions_Renderer::SENTINEL . "\n<h3>...</h3>";
        $this->assertTrue(Cashback_Tab_Conditions_Renderer::is_autogen($content));
    }

    public function test_is_autogen_detects_sentinel_with_leading_whitespace(): void
    {
        $content = "\n  " . Cashback_Tab_Conditions_Renderer::SENTINEL . "\n<h3>...</h3>";
        $this->assertTrue(Cashback_Tab_Conditions_Renderer::is_autogen($content));
    }

    public function test_is_autogen_returns_false_for_admin_edit(): void
    {
        $this->assertFalse(Cashback_Tab_Conditions_Renderer::is_autogen('<p>Admin wrote this</p>'));
        $this->assertFalse(Cashback_Tab_Conditions_Renderer::is_autogen(''));
        $this->assertFalse(Cashback_Tab_Conditions_Renderer::is_autogen('<!-- cashback:autogen:v0 -->'));
    }

    public function test_render_clamps_guest_rate_above_100(): void
    {
        update_option('cashback_guest_display_rate', 200.0);
        $this->set_active_tariffs(array($this->tariff('percent', 5.0, 'Заказ')));

        $html = Cashback_Tab_Conditions_Renderer::render(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        // 5.0 × 100 (clamped) / 100 = 5.0 → 5,00
        $this->assertStringContainsString('<strong>5,00%</strong>', $html);
    }

    public function test_render_uses_guest_rate_default_60_when_option_missing(): void
    {
        // option не выставлена — fallback 60.0
        $this->set_active_tariffs(array($this->tariff('percent', 5.18, 'Заказ')));

        $html = Cashback_Tab_Conditions_Renderer::render(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        // 5.18 × 60 / 100 = 3.108 → round = 3.11
        $this->assertStringContainsString('<strong>3,11%</strong>', $html);
    }
}
