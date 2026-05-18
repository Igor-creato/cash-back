<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Тесты real-time фидбэка кнопки «Импортировать сейчас»
 * (AJAX-запуск + polling строк лога без перезагрузки).
 *
 * @group shop-import
 * @group admin-ui
 */
#[Group('shop-import')]
#[Group('admin-ui')]
final class ShopImportRealtimeStructuralTest extends TestCase
{
    private static string $plugin_root;
    private static string $admin_file;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
        self::$admin_file  = self::$plugin_root . '/admin/class-cashback-shop-import-admin.php';

        if (!class_exists('Cashback_Shop_Import_Admin')) {
            require_once self::$admin_file;
        }
    }

    // ============================================================
    // is_run_complete() — чистая логика завершённости run'а
    // ============================================================

    /**
     * @return array<string, array{0: array<int, array<string, mixed>>, 1: bool, 2: bool}>
     */
    public static function runCompletionProvider(): array
    {
        return array(
            'empty rows'                 => array( array(), false, false ),
            'one row not finished'       => array( array( array( 'finished_at' => '' ) ), false, false ),
            'one row finished, no pend'  => array( array( array( 'finished_at' => '2026-05-18 13:00:00' ) ), false, true ),
            'all finished, AS pending'   => array(
                array( array( 'finished_at' => '2026-05-18 13:00:00' ) ),
                true,
                false,
            ),
            'mixed finished/unfinished'  => array(
                array(
                    array( 'finished_at' => '2026-05-18 13:00:00' ),
                    array( 'finished_at' => '' ),
                ),
                false,
                false,
            ),
            'all finished multi-page'    => array(
                array(
                    array( 'finished_at' => '2026-05-18 13:00:00' ),
                    array( 'finished_at' => '2026-05-18 13:05:00' ),
                ),
                false,
                true,
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    #[DataProvider('runCompletionProvider')]
    public function test_is_run_complete(array $rows, bool $hasPending, bool $expected): void
    {
        $this->assertSame(
            $expected,
            Cashback_Shop_Import_Admin::is_run_complete($rows, $hasPending)
        );
    }

    // ============================================================
    // Структурные: регистрация AJAX + методы + ассеты
    // ============================================================

    public function test_ajax_constants(): void
    {
        $this->assertSame('cashback_shop_import_ajax', Cashback_Shop_Import_Admin::AJAX_NONCE);
        $this->assertSame('cashback_shop_import_trigger_ajax', Cashback_Shop_Import_Admin::AJAX_TRIGGER_ACTION);
        $this->assertSame('cashback_shop_import_status', Cashback_Shop_Import_Admin::AJAX_STATUS_ACTION);
    }

    public function test_required_methods_exist(): void
    {
        foreach (
            array(
                'ajax_trigger',
                'ajax_status',
                'enqueue_assets',
                'is_run_complete',
            ) as $method
        ) {
            $this->assertTrue(
                method_exists('Cashback_Shop_Import_Admin', $method),
                "Метод {$method} должен существовать"
            );
        }
    }

    public function test_ajax_and_enqueue_registered_in_init(): void
    {
        $php = (string) file_get_contents(self::$admin_file);
        $this->assertStringContainsString("wp_ajax_' . self::AJAX_TRIGGER_ACTION", $php);
        $this->assertStringContainsString("wp_ajax_' . self::AJAX_STATUS_ACTION", $php);
        $this->assertStringContainsString("add_action('admin_enqueue_scripts'", $php);
    }

    public function test_render_log_row_is_single_source(): void
    {
        $php = (string) file_get_contents(self::$admin_file);
        // Вызывается минимум дважды: в render_page() и в ajax_status().
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($php, 'self::render_log_row('),
            'render_log_row должен быть единым источником разметки строки'
        );
        $this->assertStringContainsString('private static function render_log_row(', $php);
    }

    public function test_nonce_verified_in_ajax_handlers(): void
    {
        $php = (string) file_get_contents(self::$admin_file);
        $this->assertSame(
            2,
            substr_count($php, "check_ajax_referer(self::AJAX_NONCE, 'nonce')"),
            'Оба AJAX-хендлера должны проверять nonce'
        );
        $this->assertSame(
            2,
            substr_count($php, "wp_send_json_error(array( 'message' => 'Недостаточно прав' ))"),
            'Оба AJAX-хендлера должны проверять capability'
        );
    }

    // ============================================================
    // Ассеты (JS/CSS) присутствуют и связаны
    // ============================================================

    public function test_js_asset_exists_and_wired(): void
    {
        $js_path = self::$plugin_root . '/admin/js/shop-import.js';
        $this->assertFileExists($js_path);
        $js = (string) file_get_contents($js_path);
        $this->assertStringContainsString('cashbackShopImport', $js);
        $this->assertStringContainsString('cashback-shop-import-form', $js);
        $this->assertStringContainsString('data-run', $js);
        $this->assertStringContainsString('statusAction', $js);
        $this->assertStringContainsString('triggerAction', $js);
    }

    public function test_css_asset_exists(): void
    {
        $css_path = self::$plugin_root . '/admin/css/shop-import.css';
        $this->assertFileExists($css_path);
        $css = (string) file_get_contents($css_path);
        $this->assertStringContainsString('cashback-import-status--pending', $css);
    }

    public function test_enqueue_uses_versioned_assets(): void
    {
        $php = (string) file_get_contents(self::$admin_file);
        $this->assertStringContainsString("admin/js/shop-import.js", $php);
        $this->assertStringContainsString("admin/css/shop-import.css", $php);
        $this->assertStringContainsString("'cashbackShopImport'", $php);
    }

    public function test_progressive_enhancement_form_preserved(): void
    {
        $php = (string) file_get_contents(self::$admin_file);
        // admin_post fallback не удалён.
        $this->assertStringContainsString(
            "add_action('admin_post_' . self::ADMIN_POST_ACTION",
            $php
        );
        $this->assertStringContainsString('class="cashback-shop-import-form"', $php);
    }
}
