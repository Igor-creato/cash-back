<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты на Cashback_Catalog_Visibility — скрытие non-preferred
 * членов групп магазинов из основных catalog query.
 *
 * Покрытие:
 *   - Класс существует, имеет нужные константы (HIDE_META_KEY, BACKFILL_OPTION,
 *     CRON_BACKFILL_HOOK).
 *   - register() навешивает pre_get_posts + cashback_group_preferred_changed
 *     listener + cron handler.
 *   - filter_pre_get_posts использует meta_query с NOT EXISTS / != '1' для
 *     HIDE_META_KEY и early return на is_admin/post_type/is_singular(product).
 *   - sync_group использует get_group_row + get_active_members + сравнение с
 *     pin_product_id || preferred_product_id.
 *   - mark_visible/mark_hidden: delete_post_meta / update_post_meta = '1'.
 *   - ensure_backfilled self-healing: terminal только '1', проверка
 *     wp_next_scheduled.
 *   - Group_Resolver fires action cashback_group_preferred_changed в трёх
 *     точках (write_preferred / pin_product / split_member).
 *   - Bootstrap (cashback-plugin.php): require + register + ensure_backfilled.
 *
 * @group shop-import
 * @group catalog-visibility
 */
#[Group('shop-import')]
#[Group('catalog-visibility')]
final class CatalogVisibilityStructuralTest extends TestCase
{
    private static string $plugin_root;
    private static string $vis_php;
    private static string $resolver_php;
    private static string $cashback_plugin_php;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root         = dirname(__DIR__, 3);
        self::$vis_php             = (string) file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-catalog-visibility.php');
        self::$resolver_php        = (string) file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-shop-group-resolver.php');
        self::$cashback_plugin_php = (string) file_get_contents(self::$plugin_root . '/cashback-plugin.php');
    }

    // ============================================================
    // 1. Класс и базовые требования.
    // ============================================================

    public function test_class_file_exists(): void
    {
        $this->assertFileExists(
            self::$plugin_root . '/includes/shops/class-cashback-catalog-visibility.php'
        );
    }

    public function test_declares_class_cashback_catalog_visibility(): void
    {
        $this->assertMatchesRegularExpression(
            '/class\s+Cashback_Catalog_Visibility/i',
            self::$vis_php
        );
    }

    public function test_uses_strict_types_and_abspath_guard(): void
    {
        $this->assertStringContainsString('declare(strict_types=1);', self::$vis_php);
        $this->assertStringContainsString("defined('ABSPATH')", self::$vis_php);
    }

    // ============================================================
    // 2. Константы.
    // ============================================================

    public function test_defines_required_constants(): void
    {
        $this->assertMatchesRegularExpression(
            "/const\s+HIDE_META_KEY\s*=\s*'_cashback_hide_in_catalog'/",
            self::$vis_php
        );
        $this->assertMatchesRegularExpression(
            "/const\s+BACKFILL_OPTION\s*=\s*'cashback_catalog_visibility_backfill_v1'/",
            self::$vis_php
        );
        $this->assertMatchesRegularExpression(
            "/const\s+CRON_BACKFILL_HOOK\s*=\s*'cashback_catalog_visibility_backfill'/",
            self::$vis_php
        );
    }

    // ============================================================
    // 3. register() — навешаны фильтры и actions.
    // ============================================================

    public function test_register_adds_pre_get_posts_filter(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'pre_get_posts'\s*,\s*array\s*\(\s*__CLASS__\s*,\s*'filter_pre_get_posts'\s*\)/",
            self::$vis_php
        );
    }

    public function test_register_adds_preferred_changed_listener(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'cashback_group_preferred_changed'\s*,\s*array\s*\(\s*__CLASS__\s*,\s*'on_group_preferred_changed'\s*\)/",
            self::$vis_php
        );
    }

    public function test_register_adds_backfill_cron_action(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*self::CRON_BACKFILL_HOOK\s*,\s*array\s*\(\s*__CLASS__\s*,\s*'handle_backfill_cron'\s*\)/",
            self::$vis_php
        );
    }

    // ============================================================
    // 4. filter_pre_get_posts — early returns и meta_query.
    // ============================================================

    private function filter_body(): string
    {
        $start = strpos(self::$vis_php, 'function filter_pre_get_posts');
        $this->assertNotFalse($start);
        $end = strpos(self::$vis_php, "\n    public", $start + 1);
        if ($end === false) {
            $end = strpos(self::$vis_php, "\n}", $start);
        }
        return substr(self::$vis_php, $start, $end - $start);
    }

    public function test_filter_skips_in_admin(): void
    {
        $body = $this->filter_body();
        $this->assertStringContainsString('is_admin()', $body);
    }

    public function test_filter_skips_non_product_post_type(): void
    {
        $body = $this->filter_body();
        $this->assertMatchesRegularExpression(
            "/get\s*\(\s*'post_type'\s*\)/",
            $body
        );
        $this->assertStringContainsString("'product'", $body);
    }

    public function test_filter_skips_single_product_page(): void
    {
        $body = $this->filter_body();
        // is_singular() БЕЗ аргумента — с 'product' возвращает false в pre_get_posts
        // (get_queried_object не сформирован), и meta_query фильтр приводил к 404
        // на single product page.
        $this->assertMatchesRegularExpression(
            '/is_singular\s*\(\s*\)/',
            $body,
            'is_singular() должен вызываться без аргумента в pre_get_posts'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/is_singular\s*\(\s*'product'\s*\)/",
            $body,
            "is_singular('product') ломает single product page (404) — get_queried_object null в pre_get_posts"
        );
    }

    public function test_filter_uses_meta_query_not_exists_or_neq(): void
    {
        $body = $this->filter_body();
        $this->assertMatchesRegularExpression(
            "/'compare'\s*=>\s*'NOT EXISTS'/",
            $body
        );
        $this->assertMatchesRegularExpression(
            "/'compare'\s*=>\s*'!='/",
            $body
        );
        $this->assertMatchesRegularExpression(
            '/self::HIDE_META_KEY/',
            $body
        );
    }

    public function test_filter_sets_meta_query_back_to_query(): void
    {
        $body = $this->filter_body();
        $this->assertMatchesRegularExpression(
            "/->set\s*\(\s*'meta_query'/",
            $body
        );
    }

    // ============================================================
    // 5. sync_group — pin > preferred logic + iterate active members.
    // ============================================================

    private function sync_group_body(): string
    {
        $start = strpos(self::$vis_php, 'function sync_group');
        $this->assertNotFalse($start);
        $end = strpos(self::$vis_php, "\n    public", $start + 1);
        if ($end === false) {
            $end = strpos(self::$vis_php, "\n}", $start);
        }
        return substr(self::$vis_php, $start, $end - $start);
    }

    public function test_sync_group_uses_resolver_helpers(): void
    {
        $body = $this->sync_group_body();
        $this->assertStringContainsString('Cashback_Shop_Group_Resolver::get_group_row', $body);
        $this->assertStringContainsString('Cashback_Shop_Group_Resolver::get_active_members', $body);
        // Codex adversarial finding (split-brain): fallback при NULL preferred
        // должен идти через общий helper, а не через локальный sort.
        $this->assertStringContainsString(
            'Cashback_Shop_Group_Resolver::pick_fallback_member',
            $body,
            'sync_group должен делегировать выбор anchor\'а в pick_fallback_member'
        );
    }

    public function test_sync_group_pin_overrides_preferred(): void
    {
        $body = $this->sync_group_body();
        $this->assertStringContainsString("'pin_product_id'", $body);
        $this->assertStringContainsString("'preferred_product_id'", $body);
        // Должна быть логика "pin > 0 ? pin : preferred".
        $this->assertMatchesRegularExpression(
            '/\$pin\s*>\s*0\s*\?\s*\$pin\s*:\s*\$preferred/',
            $body
        );
    }

    public function test_sync_group_calls_mark_visible_and_hidden(): void
    {
        $body = $this->sync_group_body();
        $this->assertStringContainsString('mark_visible', $body);
        $this->assertStringContainsString('mark_hidden', $body);
    }

    // ============================================================
    // 6. mark_visible / mark_hidden.
    // ============================================================

    public function test_mark_visible_deletes_meta(): void
    {
        $this->assertMatchesRegularExpression(
            '/function\s+mark_visible.*?delete_post_meta\s*\(\s*\$product_id\s*,\s*self::HIDE_META_KEY/s',
            self::$vis_php
        );
    }

    public function test_mark_hidden_writes_one(): void
    {
        $this->assertMatchesRegularExpression(
            '/function\s+mark_hidden.*?update_post_meta\s*\(\s*\$product_id\s*,\s*self::HIDE_META_KEY\s*,\s*\'1\'/s',
            self::$vis_php
        );
    }

    // ============================================================
    // 7. ensure_backfilled — self-healing (re-use паттерна Product_Sort).
    // ============================================================

    private function ensure_backfilled_body(): string
    {
        $start = strpos(self::$vis_php, 'function ensure_backfilled');
        $this->assertNotFalse($start);
        $end = strpos(self::$vis_php, "\n    public static function handle_backfill_cron", $start);
        if ($end === false) {
            $end = strpos(self::$vis_php, "\n    public", $start + 1);
        }
        return substr(self::$vis_php, $start, $end - $start);
    }

    public function test_ensure_backfilled_terminal_state_is_only_done(): void
    {
        $body = $this->ensure_backfilled_body();
        $this->assertDoesNotMatchRegularExpression(
            "/===\s*'scheduled'/",
            $body,
            "ensure_backfilled НЕ должен использовать 'scheduled' как terminal state"
        );
        $this->assertMatchesRegularExpression("/===\s*'1'/", $body);
    }

    public function test_ensure_backfilled_checks_cron_queue(): void
    {
        $body = $this->ensure_backfilled_body();
        $this->assertMatchesRegularExpression(
            '/wp_next_scheduled\s*\(\s*self::CRON_BACKFILL_HOOK\s*\).*?wp_schedule_single_event/s',
            $body
        );
    }

    // ============================================================
    // 8. Group_Resolver fires cashback_group_preferred_changed.
    // ============================================================

    public function test_resolver_fires_action_in_write_preferred(): void
    {
        $start = strpos(self::$resolver_php, 'function write_preferred');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n    private", $start + 1);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        }
        $body = substr(self::$resolver_php, $start, $end - $start);
        $this->assertMatchesRegularExpression(
            "/do_action\s*\(\s*'cashback_group_preferred_changed'\s*,\s*\\\$group_id\s*\)/",
            $body
        );
    }

    public function test_resolver_fires_action_in_pin_product(): void
    {
        $start = strpos(self::$resolver_php, 'function pin_product');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        $body = substr(self::$resolver_php, $start, $end - $start);
        $this->assertMatchesRegularExpression(
            "/do_action\s*\(\s*'cashback_group_preferred_changed'\s*,\s*\\\$group_id\s*\)/",
            $body
        );
    }

    public function test_resolver_split_member_calls_mark_visible(): void
    {
        $start = strpos(self::$resolver_php, 'function split_member');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        $body = substr(self::$resolver_php, $start, $end - $start);
        $this->assertMatchesRegularExpression(
            '/Cashback_Catalog_Visibility::mark_visible\s*\(\s*\$product_id\s*\)/',
            $body
        );
    }

    // ============================================================
    // 9. Bootstrap — cashback-plugin.php.
    // ============================================================

    public function test_class_required_in_cashback_plugin_php(): void
    {
        $this->assertStringContainsString(
            'class-cashback-catalog-visibility.php',
            self::$cashback_plugin_php
        );
    }

    public function test_register_called_in_initialize_components(): void
    {
        $this->assertMatchesRegularExpression(
            '/Cashback_Catalog_Visibility\s*::\s*register\s*\(\s*\)/',
            self::$cashback_plugin_php
        );
    }

    public function test_ensure_backfilled_called_in_initialize_components(): void
    {
        $this->assertMatchesRegularExpression(
            '/Cashback_Catalog_Visibility\s*::\s*ensure_backfilled\s*\(\s*\)/',
            self::$cashback_plugin_php
        );
    }
}
