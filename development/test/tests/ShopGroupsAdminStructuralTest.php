<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты на Cashback_Shop_Groups_Admin — улучшения админ-раздела
 * «Группы магазинов».
 *
 * Покрытие:
 *   - FILTER_MULTI / FILTER_ALL константы.
 *   - render_page парсит $_GET['filter'] через sanitize_key + whitelist.
 *   - render_page рендерит subsubsub-нав со счётчиками.
 *   - fetch_groups / count_groups имеют параметр $filter (default FILTER_MULTI).
 *   - fetch_groups / count_groups содержат correlated subquery по cashback_shop_group_members.
 *   - render_group_row выводит бейдж cashback-group-badge--warning при !pref_id && members.
 *   - render_group_row содержит get_edit_post_link для линка member-row.
 *   - fetch_members_with_titles НЕ содержит fallback '#' . $id для пустого title.
 *   - Inline CSS .cashback-group-badge--warning в render_page.
 *
 * @group shop-import
 * @group admin-ui
 */
#[Group('shop-import')]
#[Group('admin-ui')]
final class ShopGroupsAdminStructuralTest extends TestCase
{
    private static string $plugin_root;
    private static string $admin_php;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
        self::$admin_php   = (string) file_get_contents(self::$plugin_root . '/admin/class-cashback-shop-groups-admin.php');
    }

    // ============================================================
    // 1. Класс и базовые требования.
    // ============================================================

    public function test_class_file_exists(): void
    {
        $this->assertFileExists(
            self::$plugin_root . '/admin/class-cashback-shop-groups-admin.php'
        );
    }

    public function test_uses_strict_types_and_abspath_guard(): void
    {
        $this->assertStringContainsString('declare(strict_types=1);', self::$admin_php);
        $this->assertStringContainsString("defined('ABSPATH')", self::$admin_php);
    }

    // ============================================================
    // 2. Filter-константы и whitelist.
    // ============================================================

    public function test_defines_filter_constants(): void
    {
        $this->assertMatchesRegularExpression(
            "/const\s+FILTER_MULTI\s*=\s*'multi'/",
            self::$admin_php
        );
        $this->assertMatchesRegularExpression(
            "/const\s+FILTER_ALL\s*=\s*'all'/",
            self::$admin_php
        );
    }

    public function test_render_page_uses_sanitize_key_for_filter(): void
    {
        $this->assertMatchesRegularExpression(
            "/sanitize_key\s*\(\s*\(string\)\s*wp_unslash\s*\(\s*\\\$_GET\['filter'\]\s*\)\s*\)/",
            self::$admin_php
        );
    }

    public function test_render_page_whitelists_filter_against_constants(): void
    {
        // Должна быть проверка $filter_raw === self::FILTER_ALL ? FILTER_ALL : FILTER_MULTI.
        $this->assertMatchesRegularExpression(
            '/===\s*self::FILTER_ALL\s*\)\s*\?\s*self::FILTER_ALL\s*:\s*self::FILTER_MULTI/',
            self::$admin_php
        );
    }

    // ============================================================
    // 3. Subsubsub-нав со счётчиками.
    // ============================================================

    public function test_render_page_outputs_subsubsub_nav(): void
    {
        $this->assertMatchesRegularExpression(
            '/<ul\s+class="subsubsub">/',
            self::$admin_php
        );
        // Должны быть оба filter-link.
        $this->assertStringContainsString('С дублями', self::$admin_php);
        $this->assertStringContainsString('Все группы', self::$admin_php);
    }

    public function test_render_page_uses_count_groups_for_both_modes(): void
    {
        $this->assertStringContainsString('count_groups(self::FILTER_MULTI)', self::$admin_php);
        $this->assertStringContainsString('count_groups(self::FILTER_ALL)', self::$admin_php);
    }

    // ============================================================
    // 4. fetch_groups / count_groups сигнатуры + filter SQL.
    // ============================================================

    public function test_fetch_groups_signature_has_filter_param(): void
    {
        $this->assertMatchesRegularExpression(
            '/private\s+static\s+function\s+fetch_groups\s*\(\s*int\s+\$per_page\s*,\s*int\s+\$offset\s*,\s*string\s+\$filter\s*=\s*self::FILTER_MULTI\s*\)/',
            self::$admin_php
        );
    }

    public function test_count_groups_signature_has_filter_param(): void
    {
        $this->assertMatchesRegularExpression(
            '/private\s+static\s+function\s+count_groups\s*\(\s*string\s+\$filter\s*=\s*self::FILTER_MULTI\s*\)/',
            self::$admin_php
        );
    }

    public function test_fetch_groups_uses_members_subquery_for_multi(): void
    {
        $this->assertMatchesRegularExpression(
            '/SELECT\s+COUNT\(\*\)\s+FROM\s+%i\s+AS\s+m\s+WHERE\s+m\.group_id\s*=\s*g\.id\s+AND\s+m\.is_excluded\s*=\s*0/i',
            self::$admin_php
        );
        // Условие "> 1" присутствует.
        $this->assertMatchesRegularExpression(
            '/\)\s*>\s*1/',
            self::$admin_php
        );
    }

    // ============================================================
    // 5. Бейдж «Нет тарифов» + CSS.
    // ============================================================

    public function test_render_group_row_outputs_no_tariffs_badge(): void
    {
        $this->assertStringContainsString('cashback-group-badge--warning', self::$admin_php);
        $this->assertStringContainsString('Нет тарифов', self::$admin_php);
    }

    public function test_badge_only_shown_when_no_preferred_and_has_members(): void
    {
        // Последовательность: if ($pref_id > 0) ... elseif (! empty($members)) ... badge.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$pref_id\s*>\s*0\s*\).*?elseif\s*\(\s*!\s*empty\s*\(\s*\$members\s*\)\s*\)/s',
            self::$admin_php
        );
    }

    public function test_inline_css_for_badge_present(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.cashback-group-badge--warning\s*\{[^}]*background:\s*#fff3cd/',
            self::$admin_php
        );
    }

    // ============================================================
    // 6. Members rendering — edit-link, нет fallback.
    // ============================================================

    public function test_members_use_edit_post_link(): void
    {
        $this->assertStringContainsString('get_edit_post_link', self::$admin_php);
    }

    public function test_fetch_members_with_titles_no_fallback(): void
    {
        $start = strpos(self::$admin_php, 'function fetch_members_with_titles');
        $this->assertNotFalse($start);
        $end = strpos(self::$admin_php, "\n    private", $start + 1);
        if ($end === false) {
            $end = strpos(self::$admin_php, "\n}", $start);
        }
        $body = substr(self::$admin_php, $start, $end - $start);

        // Не должно быть fallback на '#' . $id в title.
        $this->assertDoesNotMatchRegularExpression(
            "/\\\$title\s*!==\s*''\s*\?\s*\\\$title\s*:\s*\(\s*'#'\s*\.\s*\\\$id\s*\)/",
            $body,
            'fetch_members_with_titles НЕ должен делать fallback на #id для пустого title'
        );
    }

    public function test_render_members_skips_dash_when_title_empty(): void
    {
        // В render_group_row для members: '— ' добавляется ТОЛЬКО когда title непустой.
        $start = strpos(self::$admin_php, 'function render_group_row');
        $this->assertNotFalse($start);
        $end = strpos(self::$admin_php, "\n    private", $start + 1);
        if ($end === false) {
            $end = strpos(self::$admin_php, "\n}", $start);
        }
        $body = substr(self::$admin_php, $start, $end - $start);

        // Должна быть условная конкатенация title.
        $this->assertMatchesRegularExpression(
            "/\\\$title\s*!==\s*''/",
            $body,
            'render должен проверять что title непуст перед добавлением " — "'
        );
    }

    // ============================================================
    // 7. Pagination сохраняет filter в URL.
    // ============================================================

    public function test_pagination_passes_filter_via_add_args(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_args.*?'filter'\s*=>\s*\\\$filter/s",
            self::$admin_php
        );
    }
}
