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

    // =========================================================================
    // S1.B — таблицы расхождений + stuck claims + Pagination
    // =========================================================================

    public function test_admin_class_queries_mismatches_from_audit_log(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertStringContainsString(
            "cashback_audit_log",
            $src,
            'S1.B: класс должен читать расхождения из cashback_audit_log'
        );
        $this->assertStringContainsString(
            "'balance_consistency_mismatch'",
            $src,
            'S1.B: фильтр по action=balance_consistency_mismatch обязателен'
        );
    }

    public function test_admin_class_queries_stuck_claims_from_audit_log(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertStringContainsString(
            "'claim_approved_no_transaction'",
            $src,
            'S1.B: фильтр по action=claim_approved_no_transaction обязателен для stuck-claims'
        );
    }

    public function test_admin_class_uses_table_placeholder_for_audit_log_select(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        // Безопасность: SELECT должен использовать %i для имени таблицы, не конкатенацию в SQL.
        $this->assertMatchesRegularExpression(
            '/SELECT[\s\S]{0,200}FROM\s+%i/',
            $src,
            'S1.B: SELECT из audit_log должен использовать %i placeholder'
        );
    }

    public function test_admin_class_uses_cashback_pagination_render(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertStringContainsString(
            'Cashback_Pagination::render',
            $src,
            'S1.B: таблицы должны использовать Cashback_Pagination (переиспользуемый helper)'
        );
    }

    public function test_admin_class_selects_mismatches_with_order_by_created_at_desc(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertMatchesRegularExpression(
            '/ORDER\s+BY\s+created_at\s+DESC/i',
            $src,
            'S1.B: SELECT должен сортировать расхождения по created_at DESC'
        );
    }

    public function test_admin_class_uses_prepare_for_audit_log_select(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertMatchesRegularExpression(
            '/\$wpdb->prepare\s*\(/',
            $src,
            'S1.B: SELECT с user-input (page offset, action string) должен использовать prepare'
        );
    }

    public function test_admin_class_renders_mismatch_table_header(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertStringContainsString(
            'wp-list-table',
            $src,
            'S1.B: таблица расхождений должна использовать WP-native класс wp-list-table'
        );
    }

    // =========================================================================
    // S1.C — manual-run кнопка (admin_post + single-lock + redirect-flash)
    // =========================================================================

    public function test_admin_class_registers_admin_post_manual_run_hook(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'admin_post_cashback_reconcil_manual_run'\s*,/",
            $src,
            'S1.C: admin_post_cashback_reconcil_manual_run должен быть зарегистрирован в init()'
        );
    }

    public function test_admin_class_has_handle_manual_run_method(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+handle_manual_run\s*\(\s*\)\s*:\s*void/',
            $src,
            'S1.C: handle_manual_run должен быть public static function(): void'
        );
    }

    public function test_admin_class_checks_nonce_in_manual_run(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertStringContainsString(
            'check_admin_referer',
            $src,
            'S1.C: handle_manual_run должен валидировать nonce через check_admin_referer'
        );
    }

    public function test_admin_class_uses_get_lock_for_concurrent_runs(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertStringContainsString(
            "GET_LOCK('cashback_reconcil_manual', 0)",
            $src,
            'S1.C: параллельные нажатия должны блокироваться GET_LOCK(..., 0)'
        );
        $this->assertStringContainsString(
            "RELEASE_LOCK('cashback_reconcil_manual')",
            $src,
            'S1.C: лок должен освобождаться через RELEASE_LOCK'
        );
    }

    public function test_admin_class_calls_reconciliation_run_in_manual_handler(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertStringContainsString(
            'Cashback_Balance_Reconciliation::run()',
            $src,
            'S1.C: handle_manual_run должен вызывать Cashback_Balance_Reconciliation::run()'
        );
    }

    public function test_admin_class_caps_manual_run_batches(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        // Защита от бесконечного цикла на огромных таблицах — max_batches константа.
        $this->assertMatchesRegularExpression(
            '/MANUAL_RUN_MAX_BATCHES\s*=\s*20/',
            $src,
            'S1.C: максимум батчей в manual-run должен быть 20 (защита от таймаута)'
        );
    }

    public function test_admin_class_redirects_with_flash_after_manual_run(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        $this->assertStringContainsString(
            'wp_safe_redirect',
            $src,
            'S1.C: handle_manual_run должен редиректить через wp_safe_redirect'
        );
        $this->assertMatchesRegularExpression(
            '/set_transient\s*\(\s*[\'"]cashback_reconcil_flash_/',
            $src,
            'S1.C: flash-сообщение через transient cashback_reconcil_flash_<user_id>'
        );
    }

    public function test_render_page_has_manual_run_form(): void
    {
        $src = $this->source('admin/class-cashback-balance-reconciliation-admin.php');
        // Форма POST-ит на admin-post.php — URL строится через admin_url().
        $this->assertMatchesRegularExpression(
            "/admin_url\(\s*['\"]admin-post\.php['\"]\s*\)/",
            $src,
            'S1.C: форма должна POST-ить на admin_url(admin-post.php)'
        );
        $this->assertStringContainsString(
            'cashback_reconcil_manual_run',
            $src,
            'S1.C: форма должна иметь hidden input action=cashback_reconcil_manual_run'
        );
        $this->assertStringContainsString(
            'wp_nonce_field',
            $src,
            'S1.C: форма должна содержать wp_nonce_field'
        );
    }
}
