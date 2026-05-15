<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Behavioral тесты на Cashback_Shop_Dup_Admin_Notice — баннер в админке
 * «N магазинов-дублей с менее выгодной ставкой» (Этап 3b).
 *
 * @group shop-import
 * @group dup-status-sync
 */
#[Group('shop-import')]
#[Group('dup-status-sync')]
final class ShopDupAdminNoticeTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
        require_once dirname(__DIR__) . '/Shop_Test_Wpdb_Stub.php';
        if (!class_exists('Cashback_Shop_Dup_Admin_Notice')) {
            require_once self::$plugin_root . '/includes/admin/class-cashback-shop-dup-admin-notice.php';
        }
    }

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new Shop_Test_Wpdb_Stub();
        $GLOBALS['_cb_test_user_meta'] = array();
        $GLOBALS['_cb_test_options']   = array();
        $GLOBALS['_cb_test_transients'] = array();
    }

    public function test_count_conflicts_queries_db_when_no_cache(): void
    {
        global $wpdb;
        $wpdb->next_get_var = '3';

        $this->assertSame(3, Cashback_Shop_Dup_Admin_Notice::count_conflicts());
        // Закэшировано в transient.
        $this->assertSame(3, (int) get_transient(Cashback_Shop_Dup_Admin_Notice::TRANSIENT));
    }

    public function test_count_conflicts_uses_cached_transient(): void
    {
        global $wpdb;
        set_transient(Cashback_Shop_Dup_Admin_Notice::TRANSIENT, 7, 3600);
        $wpdb->next_get_var = '99'; // не должно использоваться

        $this->assertSame(7, Cashback_Shop_Dup_Admin_Notice::count_conflicts());
    }

    public function test_should_display_true_when_conflicts_and_not_dismissed(): void
    {
        set_transient(Cashback_Shop_Dup_Admin_Notice::TRANSIENT, 5, 3600);

        $this->assertTrue(Cashback_Shop_Dup_Admin_Notice::should_display(42));
    }

    public function test_should_display_false_when_zero_conflicts(): void
    {
        set_transient(Cashback_Shop_Dup_Admin_Notice::TRANSIENT, 0, 3600);

        $this->assertFalse(Cashback_Shop_Dup_Admin_Notice::should_display(42));
    }

    public function test_should_display_false_when_dismissed_at_current_count(): void
    {
        set_transient(Cashback_Shop_Dup_Admin_Notice::TRANSIENT, 5, 3600);
        update_user_meta(42, Cashback_Shop_Dup_Admin_Notice::META_DISMISSED, 5);

        $this->assertFalse(
            Cashback_Shop_Dup_Admin_Notice::should_display(42),
            'дисмисс на текущем счётчике скрывает баннер'
        );
    }

    public function test_should_display_true_again_when_count_grows_after_dismiss(): void
    {
        set_transient(Cashback_Shop_Dup_Admin_Notice::TRANSIENT, 8, 3600);
        update_user_meta(42, Cashback_Shop_Dup_Admin_Notice::META_DISMISSED, 5); // ранее дисмиснул на 5

        $this->assertTrue(
            Cashback_Shop_Dup_Admin_Notice::should_display(42),
            'счётчик вырос (8 > 5) — показать снова'
        );
    }

    public function test_dismiss_for_user_records_current_count(): void
    {
        set_transient(Cashback_Shop_Dup_Admin_Notice::TRANSIENT, 6, 3600);

        Cashback_Shop_Dup_Admin_Notice::dismiss_for_user(42);

        $this->assertSame(6, (int) get_user_meta(42, Cashback_Shop_Dup_Admin_Notice::META_DISMISSED, true));
        $this->assertFalse(Cashback_Shop_Dup_Admin_Notice::should_display(42));
    }
}
