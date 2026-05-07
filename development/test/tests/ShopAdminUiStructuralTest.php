<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты Admin UI для shop importer (v12, Этап 8).
 *
 * Проверяем что:
 *   - 3 admin-класса существуют с правильными PAGE_SLUG / NONCE_ACTION;
 *   - sanitize-callbacks Settings clamp'ят значения в нужный диапазон;
 *   - admin-классы зарегистрированы в cashback-plugin.php;
 *   - metabox в wc-affiliate-url-params.php содержит _manual_advertiser_rate
 *     + _rate_locked поля.
 *
 * @group shop-import
 * @group admin-ui
 */
#[Group('shop-import')]
#[Group('admin-ui')]
final class ShopAdminUiStructuralTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        if (!class_exists('Cashback_Shop_Options')) {
            require_once self::$plugin_root . '/includes/class-cashback-shop-options.php';
        }
        if (!class_exists('Cashback_Settings_Admin')) {
            require_once self::$plugin_root . '/admin/class-cashback-settings-admin.php';
        }
        if (!class_exists('Cashback_Shop_Import_Admin')) {
            require_once self::$plugin_root . '/admin/class-cashback-shop-import-admin.php';
        }
        if (!class_exists('Cashback_Shop_Groups_Admin')) {
            require_once self::$plugin_root . '/admin/class-cashback-shop-groups-admin.php';
        }
    }

    public function test_settings_admin_constants(): void
    {
        $this->assertSame('cashback-settings', Cashback_Settings_Admin::PAGE_SLUG);
        $this->assertSame('cashback_settings_group', Cashback_Settings_Admin::OPTION_GROUP);
    }

    public function test_import_admin_constants(): void
    {
        $this->assertSame('cashback-shop-import', Cashback_Shop_Import_Admin::PAGE_SLUG);
        $this->assertSame('cashback_shop_import_run', Cashback_Shop_Import_Admin::NONCE_ACTION);
        $this->assertSame('cashback_shop_import_trigger', Cashback_Shop_Import_Admin::ADMIN_POST_ACTION);
    }

    public function test_groups_admin_constants(): void
    {
        $this->assertSame('cashback-shop-groups', Cashback_Shop_Groups_Admin::PAGE_SLUG);
        $this->assertSame('cashback_shop_group_action', Cashback_Shop_Groups_Admin::ADMIN_POST_ACTION);
    }

    // ============================================================
    // Settings sanitize callbacks
    // ============================================================

    public function test_sanitize_guest_rate_clamps_above_100(): void
    {
        $this->assertSame(100.0, Cashback_Settings_Admin::sanitize_guest_rate(150));
    }

    public function test_sanitize_guest_rate_clamps_below_zero(): void
    {
        $this->assertSame(0.0, Cashback_Settings_Admin::sanitize_guest_rate(-10));
    }

    public function test_sanitize_guest_rate_passthrough_valid(): void
    {
        $this->assertSame(65.5, Cashback_Settings_Admin::sanitize_guest_rate('65.5'));
    }

    public function test_sanitize_guest_rate_falls_back_to_default_for_garbage(): void
    {
        $this->assertSame(60.0, Cashback_Settings_Admin::sanitize_guest_rate('garbage'));
    }

    public function test_sanitize_cache_ttl_clamps_to_60_86400(): void
    {
        $this->assertSame(60, Cashback_Settings_Admin::sanitize_int_range_60_86400(0));
        $this->assertSame(86400, Cashback_Settings_Admin::sanitize_int_range_60_86400(99999));
        $this->assertSame(3600, Cashback_Settings_Admin::sanitize_int_range_60_86400(3600));
    }

    public function test_sanitize_batch_size_clamps_to_10_500(): void
    {
        $this->assertSame(10, Cashback_Settings_Admin::sanitize_int_range_10_500(0));
        $this->assertSame(500, Cashback_Settings_Admin::sanitize_int_range_10_500(9999));
        $this->assertSame(100, Cashback_Settings_Admin::sanitize_int_range_10_500(100));
    }

    public function test_sanitize_throttle_clamps_to_0_5000(): void
    {
        $this->assertSame(0, Cashback_Settings_Admin::sanitize_int_range_0_5000(-100));
        $this->assertSame(5000, Cashback_Settings_Admin::sanitize_int_range_0_5000(99999));
        $this->assertSame(200, Cashback_Settings_Admin::sanitize_int_range_0_5000(200));
    }

    // ============================================================
    // Регистрация в cashback-plugin.php
    // ============================================================

    public function test_admin_classes_loaded_in_plugin_php(): void
    {
        $php = file_get_contents(self::$plugin_root . '/cashback-plugin.php');
        $this->assertStringContainsString('class-cashback-settings-admin.php', $php);
        $this->assertStringContainsString('class-cashback-shop-import-admin.php', $php);
        $this->assertStringContainsString('class-cashback-shop-groups-admin.php', $php);

        // ::init() вызовы.
        $this->assertStringContainsString('Cashback_Settings_Admin::init()', $php);
        $this->assertStringContainsString('Cashback_Shop_Import_Admin::init()', $php);
        $this->assertStringContainsString('Cashback_Shop_Groups_Admin::init()', $php);
    }

    // ============================================================
    // Metabox: _manual_advertiser_rate + _rate_locked
    // ============================================================

    public function test_metabox_has_manual_override_field(): void
    {
        $php = file_get_contents(self::$plugin_root . '/wc-affiliate-url-params.php');
        $this->assertStringContainsString("'_manual_advertiser_rate'", $php);
        $this->assertStringContainsString("'_rate_locked'", $php);
    }

    public function test_metabox_save_persists_manual_rate(): void
    {
        $php = file_get_contents(self::$plugin_root . '/wc-affiliate-url-params.php');
        // Save handler должен update_post_meta для обоих новых ключей.
        $this->assertMatchesRegularExpression(
            '/update_post_meta\([^,]+,\s*\'_manual_advertiser_rate\'/i',
            $php
        );
        $this->assertMatchesRegularExpression(
            '/update_post_meta\([^,]+,\s*\'_rate_locked\'/i',
            $php
        );
    }

    public function test_metabox_save_busts_calculator_cache(): void
    {
        $php = file_get_contents(self::$plugin_root . '/wc-affiliate-url-params.php');
        $this->assertStringContainsString(
            'Cashback_Cashback_Display_Calculator::bust_cache_for_product',
            $php,
            'metabox save должен сбрасывать display cache при изменении ставок'
        );
    }
}
