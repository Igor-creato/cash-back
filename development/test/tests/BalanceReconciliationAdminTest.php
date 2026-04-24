<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты Группы 15, шаг S1 (admin-страница сверки баланса).
 *
 * Source-based: читают исходники и проверяют наличие ключевых конструкций.
 * Гарантируют, что admin-класс зарегистрирован как подстраница `cashback-overview`,
 * имеет правильный capability, показывает summary из option и подключён в
 * cashback-plugin.php (load_dependencies + initialize_components).
 */
#[Group('ledger')]
#[Group('group-15')]
final class BalanceReconciliationAdminTest extends TestCase
{
    private function source(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $content = file_get_contents($path);
        $this->assertIsString($content, "{$rel} must be readable");
        return $content;
    }

    // =========================================================================
    // S1.A — class-cashback-balance-reconciliation-admin.php существует
    // =========================================================================

    public function test_admin_class_file_exists(): void
    {
        $path = dirname(__DIR__, 3) . '/admin/class-cashback-balance-reconciliation-admin.php';
        $this->assertFileExists($path);
    }

    public function test_admin_class_declares_expected_class_name(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertMatchesRegularExpression(
            '/class\s+Cashback_Balance_Reconciliation_Admin\b/',
            $src,
            'Класс Cashback_Balance_Reconciliation_Admin должен быть объявлен'
        );
    }

    public function test_admin_class_declares_admin_page_slug_constant(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertMatchesRegularExpression(
            "/ADMIN_PAGE_SLUG\s*=\s*'cashback-balance-reconciliation'/",
            $src,
            'Slug подстраницы должен быть cashback-balance-reconciliation'
        );
    }

    public function test_admin_class_uses_cashback_overview_as_parent_menu(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertMatchesRegularExpression(
            "/PARENT_MENU_SLUG\s*=\s*'cashback-overview'/",
            $src,
            'Parent-slug должен быть cashback-overview (toplevel menu плагина)'
        );
    }

    public function test_admin_class_registers_submenu_page_on_admin_menu(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'admin_menu'\s*,/",
            $src,
            'init() должен подписаться на admin_menu'
        );
        $this->assertStringContainsString(
            'add_submenu_page(',
            $src,
            'register_admin_page должен вызывать add_submenu_page'
        );
    }

    public function test_admin_class_requires_manage_options_capability(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        // Capability manage_options должен встречаться минимум дважды:
        // в add_submenu_page() и в render_page() (защита от прямого URL).
        $count = substr_count($src, "'manage_options'");
        $this->assertGreaterThanOrEqual(
            2,
            $count,
            'manage_options должен использоваться и в submenu-регистрации, и в render_page'
        );
    }

    public function test_admin_class_reads_last_summary_option_in_render(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertStringContainsString(
            "get_option( Cashback_Balance_Reconciliation::LAST_SUMMARY_OPT",
            $src,
            'render_page должен читать option через константу LAST_SUMMARY_OPT reconciliation-класса'
        );
    }

    public function test_admin_class_has_init_static_method(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+init\s*\(\s*\)\s*:\s*void/',
            $src,
            'init() должен быть public static (по паттерну Cashback_Key_Rotation)'
        );
    }

    // =========================================================================
    // Интеграция в cashback-plugin.php
    // =========================================================================

    public function test_plugin_bootstrap_requires_admin_class_file(): void
    {
        $src = $this->source('cashback-plugin.php');
        $this->assertStringContainsString(
            "admin/class-cashback-balance-reconciliation-admin.php",
            $src,
            'load_dependencies должен require admin-класс сверки баланса'
        );
    }

    public function test_plugin_bootstrap_inits_admin_class_in_is_admin_branch(): void
    {
        $src = $this->source('cashback-plugin.php');
        $this->assertMatchesRegularExpression(
            '/is_admin\(\)[\s\S]{0,400}Cashback_Balance_Reconciliation_Admin::init\(\)/',
            $src,
            'Cashback_Balance_Reconciliation_Admin::init() должен вызываться в is_admin()-ветке'
        );
    }
}
