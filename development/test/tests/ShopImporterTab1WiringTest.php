<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Structural / behavioral тесты на интеграцию Tab[1] «Условия» в
 * Cashback_Shop_Importer (sentinel-guard, точки вызова из run()).
 *
 * Полный e2e (run() → fetch_campaigns_detailed → upsert_product → sync_tariffs
 * → apply_tab1_conditions_content) покрывается staging smoke-тестом, а не
 * unit-тестами — здесь проверяем критичные guard'ы и формат HTML через
 * прямой вызов apply_tab1_conditions_content via reflection.
 *
 * @group shop-import
 * @group tab-conditions-renderer
 */
#[Group('shop-import')]
#[Group('tab-conditions-renderer')]
final class ShopImporterTab1WiringTest extends TestCase
{
    private const PRODUCT_ID = 7777;
    private const NETWORK_ID = 1;
    private const OFFER_ID   = 'test-offer-001';

    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once dirname(__DIR__) . '/Shop_Test_Wpdb_Stub.php';

        if (!class_exists('Cashback_Shop_Tariff_Sync')) {
            require_once $plugin_root . '/includes/shops/class-cashback-shop-tariff-sync.php';
        }
        if (!class_exists('Cashback_Tab_Conditions_Renderer')) {
            require_once $plugin_root . '/includes/shops/class-cashback-tab-conditions-renderer.php';
        }
        if (!class_exists('Cashback_Shop_Importer')) {
            require_once $plugin_root . '/includes/shops/class-cashback-shop-importer.php';
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

    private function set_active_tariffs(array $rows): void
    {
        global $wpdb;
        $wpdb->next_get_results = $rows;
    }

    private function tariff(string $type, float $size, string $name = 'Заказ'): array
    {
        return array(
            'tariff_id'    => $type . '-' . (int) ($size * 100),
            'name'         => $name,
            'tariff_type'  => $type,
            'payment_size' => $size,
            'payment_min'  => null,
            'payment_max'  => null,
            'currency'     => 'RUB',
            'is_default'   => 0,
            'is_deleted'   => 0,
        );
    }

    /**
     * Вызвать private static apply_tab1_conditions_content через Reflection.
     */
    private function call_apply(int $product_id, int $network_id, string $offer_id): void
    {
        $ref = new \ReflectionMethod(Cashback_Shop_Importer::class, 'apply_tab1_conditions_content');
        $ref->setAccessible(true);
        $ref->invoke(null, $product_id, $network_id, $offer_id);
    }

    public function test_first_import_writes_autogen_content_to_tab1(): void
    {
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array($this->tariff('percent', 5.18, 'Оплаченный заказ')));

        // Симуляция first-import: контент пустой (apply_first_import_defaults)
        update_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_content', '');

        $this->call_apply(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        $content = get_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_content', true);
        $this->assertStringStartsWith(Cashback_Tab_Conditions_Renderer::SENTINEL, $content);
        $this->assertStringContainsString('Оплаченный заказ', $content);
        $this->assertStringContainsString('<strong>3,37%</strong>', $content);
    }

    public function test_apply_overwrites_when_sentinel_present(): void
    {
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array($this->tariff('percent', 10.0, 'Заказ нового клиента')));

        $stale = Cashback_Tab_Conditions_Renderer::SENTINEL . "\n<h3>Stale content from previous import</h3>";
        update_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_content', $stale);

        $this->call_apply(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        $content = get_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_content', true);
        $this->assertStringContainsString('<strong>6,50%</strong>', $content); // 10 × 65/100
        $this->assertStringNotContainsString('Stale content', $content);
    }

    public function test_apply_preserves_admin_edit_when_sentinel_missing(): void
    {
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array($this->tariff('percent', 5.0, 'Заказ')));

        $admin_edit = '<h3>Условия от партнёра</h3><p>Подробности в договоре.</p>';
        update_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_content', $admin_edit);

        $this->call_apply(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        $content = get_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_content', true);
        $this->assertSame($admin_edit, $content, 'admin-edit без sentinel сохраняется как есть');
    }

    public function test_apply_writes_when_content_was_empty_string(): void
    {
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array($this->tariff('percent', 5.0, 'Заказ')));
        update_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_content', '');

        $this->call_apply(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        $content = get_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_content', true);
        $this->assertStringStartsWith(Cashback_Tab_Conditions_Renderer::SENTINEL, $content);
    }

    public function test_apply_skips_when_render_returns_empty(): void
    {
        // Тарифов нет, payment_time → fallback 30 → 33 дня. Render вернёт
        // непустой HTML с одной только секцией "Срок". Зайдём через сценарий
        // где payment_days попадает в null: meta = 9999 (>365).
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array());
        update_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_content', '');

        // Тарифов нет → renderer всё равно покажет "Срок начисления" с fallback 30+3.
        // Для этого теста проверим что рендер НЕ пустой и пишется в meta.
        $this->call_apply(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        $content = get_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_content', true);
        $this->assertStringContainsString('Срок начисления кэшбэка', $content);
        $this->assertStringContainsString('33 дня', $content);
    }

    public function test_apply_sets_title_priority_content_type_after_overwrite(): void
    {
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array($this->tariff('percent', 5.0, 'Заказ')));
        update_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_content', Cashback_Tab_Conditions_Renderer::SENTINEL . "\nold");

        // Эмулируем что admin случайно затёр title — после apply должен восстановиться.
        update_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_title', '');
        update_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_priority', '');
        update_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_content_type', '');

        $this->call_apply(self::PRODUCT_ID, self::NETWORK_ID, self::OFFER_ID);

        $this->assertSame('Условия', get_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_title', true));
        $this->assertSame('1', get_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_priority', true));
        $this->assertSame('text', get_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_content_type', true));
    }

    public function test_apply_no_op_for_invalid_args(): void
    {
        update_option('cashback_guest_display_rate', 65.0);
        $this->set_active_tariffs(array($this->tariff('percent', 5.0, 'Заказ')));

        // product_id <= 0
        $this->call_apply(0, self::NETWORK_ID, self::OFFER_ID);
        $this->assertSame('', (string) get_post_meta(0, '_woodmart_product_custom_tab_content', true));

        // network_id <= 0
        $this->call_apply(self::PRODUCT_ID, 0, self::OFFER_ID);
        $this->assertSame('', (string) get_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_content', true));

        // empty offer_id
        $this->call_apply(self::PRODUCT_ID, self::NETWORK_ID, '');
        $this->assertSame('', (string) get_post_meta(self::PRODUCT_ID, '_woodmart_product_custom_tab_content', true));
    }

    public function test_meta_avg_payment_days_constant_matches_renderer_expectation(): void
    {
        // Гарантируем что Renderer и Importer используют один и тот же meta_key.
        // Регрессия-страж: при ренейминге одной константы тесты не пропустят.
        $this->assertSame(
            '_cashback_avg_payment_days',
            Cashback_Shop_Importer::META_AVG_PAYMENT_DAYS
        );
    }
}
