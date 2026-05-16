<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты на разовый backfill приоритета Tab[1] «Условия» 80→1 для уже
 * импортированных товаров (Cashback_Shop_Importer::backfill_tab1_priority()).
 *
 * Проверяем: авто-товар с priority='80' → '1'; товар без маркера
 * `_affiliate_network_id` не трогается; идемпотентность (повторный вызов → 0).
 *
 * @group shop-import
 */
#[Group('shop-import')]
final class ShopImporterTab1PriorityBackfillTest extends TestCase
{
    private const PRIORITY_KEY = '_woodmart_product_custom_tab_priority';

    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once dirname(__DIR__) . '/Shop_Test_Wpdb_Stub.php';

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

    public function test_rewrites_priority_80_to_1_for_auto_imported_product(): void
    {
        global $wpdb;
        // SELECT post_id WHERE meta_key='_affiliate_network_id' → только авто-товар.
        $wpdb->next_get_col = array(501);
        update_post_meta(501, self::PRIORITY_KEY, '80');

        $updated = Cashback_Shop_Importer::backfill_tab1_priority();

        $this->assertSame(1, $updated);
        $this->assertSame('1', get_post_meta(501, self::PRIORITY_KEY, true));
    }

    public function test_skips_product_without_affiliate_network_marker(): void
    {
        global $wpdb;
        // get_col не вернул 502 (нет _affiliate_network_id) — товар не трогаем.
        $wpdb->next_get_col = array();
        update_post_meta(502, self::PRIORITY_KEY, '80');

        $updated = Cashback_Shop_Importer::backfill_tab1_priority();

        $this->assertSame(0, $updated);
        $this->assertSame('80', get_post_meta(502, self::PRIORITY_KEY, true));
    }

    public function test_idempotent_second_run_returns_zero(): void
    {
        global $wpdb;
        $wpdb->next_get_col = array(501);
        update_post_meta(501, self::PRIORITY_KEY, '80');

        $first  = Cashback_Shop_Importer::backfill_tab1_priority();
        $second = Cashback_Shop_Importer::backfill_tab1_priority();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame('1', get_post_meta(501, self::PRIORITY_KEY, true));
    }
}
