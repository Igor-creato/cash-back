<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты на cleanup ghost member-records:
 *   - hook on_before_delete_post / detach_member в Cashback_Shop_Group_Resolver;
 *   - defense-in-depth INNER JOIN wp_posts в get_active_members;
 *   - migrate_cleanup_ghost_members_v13 в mariadb.php;
 *   - bootstrap-регистрация хуков и вызов миграции в cashback-plugin.php.
 *
 * @group shop-import
 * @group cleanup
 */
#[Group('shop-import')]
#[Group('cleanup')]
final class ShopGroupResolverDeleteHookStructuralTest extends TestCase
{
    private static string $plugin_root;
    private static string $resolver_php;
    private static string $mariadb_php;
    private static string $cashback_plugin_php;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root         = dirname(__DIR__, 3);
        self::$resolver_php        = (string) file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-shop-group-resolver.php');
        self::$mariadb_php         = (string) file_get_contents(self::$plugin_root . '/mariadb.php');
        self::$cashback_plugin_php = (string) file_get_contents(self::$plugin_root . '/cashback-plugin.php');
    }

    // ============================================================
    // 1. on_before_delete_post / detach_member методы в Group_Resolver.
    // ============================================================

    public function test_on_before_delete_post_method_signature(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+on_before_delete_post\s*\(\s*int\s+\$post_id\s*,\s*\$post\s*=\s*null\s*\)\s*:\s*void/',
            self::$resolver_php
        );
    }

    public function test_on_before_delete_post_filters_by_post_type(): void
    {
        $start = strpos(self::$resolver_php, 'function on_before_delete_post');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n}", $start);
        }
        $body = substr(self::$resolver_php, $start, $end - $start);

        $this->assertStringContainsString("'product'", $body);
        $this->assertStringContainsString('detach_member', $body);
    }

    public function test_detach_member_method_signature(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+detach_member\s*\(\s*int\s+\$product_id\s*\)\s*:\s*void/',
            self::$resolver_php
        );
    }

    public function test_detach_member_recomputes_or_deletes_empty_group(): void
    {
        $start = strpos(self::$resolver_php, 'function detach_member');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n}", $start);
        }
        $body = substr(self::$resolver_php, $start, $end - $start);

        // Должен делать DELETE из members.
        $this->assertMatchesRegularExpression(
            '/->delete\s*\(\s*\$members_table/',
            $body
        );
        // При пустой группе — DELETE из groups.
        $this->assertMatchesRegularExpression(
            '/->delete\s*\(\s*\$groups_table/',
            $body
        );
        // Иначе — recompute_preferred.
        $this->assertStringContainsString('recompute_preferred', $body);
    }

    // ============================================================
    // 2. get_active_members — INNER JOIN с wp_posts.
    // ============================================================

    public function test_get_active_members_joins_wp_posts(): void
    {
        $start = strpos(self::$resolver_php, 'function get_active_members');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n}", $start);
        }
        $body = substr(self::$resolver_php, $start, $end - $start);

        $this->assertMatchesRegularExpression(
            '/INNER\s+JOIN\s+%i\s+AS\s+p\s+ON\s+p\.ID\s*=\s*m\.product_id/i',
            $body
        );
        $this->assertStringContainsString('$wpdb->posts', $body);
        $this->assertMatchesRegularExpression(
            '/post_status\s+NOT\s+IN\s*\(\s*"trash"\s*,\s*"auto-draft"\s*\)/i',
            $body
        );
    }

    // ============================================================
    // 3. migrate_cleanup_ghost_members_v13 в mariadb.php.
    // ============================================================

    public function test_migration_method_exists_with_v13_signature(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+function\s+migrate_cleanup_ghost_members_v13\s*\(\s*\)\s*:\s*void/',
            self::$mariadb_php
        );
    }

    public function test_migration_idempotent_via_db_version(): void
    {
        $start = strpos(self::$mariadb_php, 'function migrate_cleanup_ghost_members_v13');
        $this->assertNotFalse($start);
        $end = strpos(self::$mariadb_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$mariadb_php, "\n    public", $start + 1);
        }
        $body = substr(self::$mariadb_php, $start, $end - $start);

        // Fast-path return при cashback_db_version >= 13.
        $this->assertMatchesRegularExpression(
            "/cashback_db_version.*?>=\s*13/s",
            $body
        );
        // update_option('cashback_db_version', 13, false) в конце.
        $this->assertMatchesRegularExpression(
            "/update_option\s*\(\s*'cashback_db_version'\s*,\s*13\s*,\s*false\s*\)/",
            $body
        );
    }

    public function test_migration_deletes_e2e_networks(): void
    {
        $start = strpos(self::$mariadb_php, 'function migrate_cleanup_ghost_members_v13');
        $this->assertNotFalse($start);
        $end = strpos(self::$mariadb_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$mariadb_php, "\n    public", $start + 1);
        }
        $body = substr(self::$mariadb_php, $start, $end - $start);

        $this->assertStringContainsString("'e2e%'", $body);
        $this->assertStringContainsString('wp_delete_post', $body);
    }

    public function test_migration_cleans_orphan_members_and_empty_groups(): void
    {
        $start = strpos(self::$mariadb_php, 'function migrate_cleanup_ghost_members_v13');
        $this->assertNotFalse($start);
        $end = strpos(self::$mariadb_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$mariadb_php, "\n    public", $start + 1);
        }
        $body = substr(self::$mariadb_php, $start, $end - $start);

        // DELETE m FROM ... LEFT JOIN ... WHERE p.ID IS NULL.
        $this->assertMatchesRegularExpression(
            '/DELETE\s+m\s+FROM\s+%i\s+AS\s+m\s+LEFT\s+JOIN\s+%i\s+AS\s+p/is',
            $body
        );
        // DELETE g FROM ... LEFT JOIN members ... WHERE m.product_id IS NULL.
        $this->assertMatchesRegularExpression(
            '/DELETE\s+g\s+FROM\s+%i\s+AS\s+g\s+LEFT\s+JOIN\s+%i\s+AS\s+m/is',
            $body
        );
    }

    // ============================================================
    // 4. Bootstrap — cashback-plugin.php.
    // ============================================================

    public function test_bootstrap_registers_before_delete_post_hook(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'before_delete_post'\s*,\s*array\s*\(\s*'Cashback_Shop_Group_Resolver'\s*,\s*'on_before_delete_post'\s*\)/",
            self::$cashback_plugin_php
        );
    }

    public function test_bootstrap_does_NOT_register_wp_trash_post_hook(): void
    {
        // Trash должен быть обратимым через WP «Восстановить» — destructive
        // cleanup на trash приводил бы к потере группы при routine admin-операции.
        // Trashed members отфильтровываются через INNER JOIN в get_active_members.
        $this->assertDoesNotMatchRegularExpression(
            "/add_action\s*\(\s*'wp_trash_post'\s*,\s*array\s*\(\s*'Cashback_Shop_Group_Resolver'\s*,\s*'on_before_delete_post'\s*\)/",
            self::$cashback_plugin_php,
            'wp_trash_post НЕ должен вызывать destructive cleanup (Codex finding #2: trash reversible)'
        );
    }

    public function test_bootstrap_invokes_migration(): void
    {
        $this->assertMatchesRegularExpression(
            '/Mariadb_Plugin::get_instance\(\)->migrate_cleanup_ghost_members_v13\s*\(\s*\)/',
            self::$cashback_plugin_php
        );
    }

    // ============================================================
    // 5. Stale-pin handling (Codex finding #1).
    // ============================================================

    public function test_recompute_preferred_validates_pin_against_active_members(): void
    {
        $start = strpos(self::$resolver_php, 'function recompute_preferred');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        }
        $body = substr(self::$resolver_php, $start, $end - $start);

        // Pin перебивает score-логику ТОЛЬКО если pin in active_members.
        $this->assertMatchesRegularExpression(
            '/in_array\s*\(\s*\$pin_id\s*,\s*\$members\s*,\s*true\s*\)/',
            $body,
            'recompute_preferred должен валидировать pin против active members'
        );
        // Stale pin должен очищаться.
        $this->assertStringContainsString('clear_pin', $body);
    }

    public function test_detach_member_clears_pin_for_pinned_product(): void
    {
        $start = strpos(self::$resolver_php, 'function detach_member');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        }
        $body = substr(self::$resolver_php, $start, $end - $start);

        // detach_member читает pin_product_id перед delete и сравнивает с product_id.
        $this->assertStringContainsString('pin_product_id', $body);
        $this->assertMatchesRegularExpression(
            '/\$pin_id\s*===\s*\$product_id/',
            $body,
            'detach_member должен сравнивать pin с удаляемым product'
        );
        $this->assertMatchesRegularExpression(
            '/clear_pin\s*\(\s*\$group_id\s*\)/',
            $body
        );
    }

    public function test_clear_pin_helper_exists(): void
    {
        $this->assertMatchesRegularExpression(
            '/private\s+static\s+function\s+clear_pin\s*\(\s*int\s+\$group_id\s*\)\s*:\s*void/',
            self::$resolver_php
        );
    }

    // ============================================================
    // 6. Catalog visibility — graceful fallback при stale preferred.
    // ============================================================

    public function test_catalog_visibility_falls_back_when_effective_not_in_members(): void
    {
        $vis_php = (string) file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-catalog-visibility.php');

        $start = strpos($vis_php, 'function sync_group');
        $this->assertNotFalse($start);
        $end = strpos($vis_php, "\n    public", $start + 1);
        $this->assertNotFalse($end);
        $body = substr($vis_php, $start, $end - $start);

        // Должна быть проверка in_array($effective, $members, true).
        $this->assertMatchesRegularExpression(
            '/in_array\s*\(\s*\$effective\s*,\s*\$members\s*,\s*true\s*\)/',
            $body,
            'sync_group должен валидировать effective preferred против active members'
        );
        // При stale effective — все members visible (graceful fallback).
        $this->assertMatchesRegularExpression(
            '/mark_visible.*?return/s',
            $body,
            'При stale effective sync_group должен mark_visible всех и выйти'
        );
    }
}
