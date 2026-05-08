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

    public function test_catalog_visibility_falls_back_to_deterministic_anchor(): void
    {
        $vis_php = (string) file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-catalog-visibility.php');

        $start = strpos($vis_php, 'function sync_group');
        $this->assertNotFalse($start);
        $end = strpos($vis_php, "\n    public", $start + 1);
        $this->assertNotFalse($end);
        $body = substr($vis_php, $start, $end - $start);

        // Round 7 (R7-1): валидация effective идёт через publishable members
        // (не active), чтобы draft preferred не делал catalog пустым.
        $this->assertMatchesRegularExpression(
            '/in_array\s*\(\s*\$effective\s*,\s*\$publishable\s*,\s*true\s*\)/',
            $body,
            'sync_group должен валидировать effective preferred против publishable members'
        );

        // Codex adversarial finding (split-brain): fallback теперь делегирован
        // в Cashback_Shop_Group_Resolver::pick_fallback_member, чтобы catalog-
        // visibility и resolve_preferred выбирали ОДИН и тот же anchor при NULL
        // preferred. Локальный sort + sorted_members[0] больше не используется —
        // выбор инкапсулирован в helper'е.
        $this->assertStringContainsString(
            'Cashback_Shop_Group_Resolver::pick_fallback_member',
            $body,
            'sync_group должен использовать общий helper pick_fallback_member вместо локального sort'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/sort\s*\(\s*\$sorted_members\s*,\s*SORT_NUMERIC\s*\)/',
            $body,
            'локальный sort больше не используется — helper отвечает за выбор'
        );

        // НЕ должно быть «mark_visible всех + return» (старая логика).
        $this->assertDoesNotMatchRegularExpression(
            '/foreach\s*\(\s*\$members\s+as\s+\$member_id\s*\)\s*\{\s*self::mark_visible\(\(int\)\s*\$member_id\)\s*;\s*\}\s*return\s*;/s',
            $body,
            'старый fallback (mark_visible всех) приводил к дублям в каталоге'
        );
    }

    // ============================================================
    // 6.1 pick_fallback_member — общий helper для deterministic anchor.
    // ============================================================

    public function test_resolver_defines_get_publishable_members_helper(): void
    {
        // Codex Round 5: helper для publishable-only fallback (regression
        // fix — раньше pick_fallback_member выбирал draft member и hide'ил
        // published sibling, гости не видели ничего).
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+get_publishable_members\s*\(\s*int\s+\$group_id\s*\)\s*:\s*array/',
            self::$resolver_php,
            'get_publishable_members должен быть public static array helper'
        );

        $start = strpos(self::$resolver_php, 'function get_publishable_members');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        }
        $body = substr(self::$resolver_php, $start, $end - $start);

        // SQL должен фильтровать строго по post_status = 'publish'.
        $this->assertMatchesRegularExpression(
            '/post_status\s*=\s*%s/i',
            $body,
            'helper фильтрует post_status параметризованно'
        );
        $this->assertStringContainsString(
            "'publish'",
            $body,
            'helper передаёт literal publish как параметр'
        );
    }

    public function test_resolve_preferred_validates_against_publishable_members(): void
    {
        // Codex Round 7 (R7-1): валидация pin/preferred в resolve_preferred
        // должна идти через get_publishable_members, не get_active_members.
        // Иначе draft member, выигравший recompute_preferred scoring,
        // делает catalog пустым (sync_group hide_meta'ит publish, draft
        // скрывается WC default-фильтром).
        $start = strpos(self::$resolver_php, 'function resolve_preferred');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        }
        $body = substr(self::$resolver_php, $start, $end - $start);

        $this->assertStringContainsString(
            'get_publishable_members',
            $body,
            'resolve_preferred должен валидировать через publishable, не active members'
        );
    }

    public function test_sync_group_validates_against_publishable_members(): void
    {
        // R7-1 mirror на catalog visibility.
        $vis_php = (string) file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-catalog-visibility.php');
        $start = strpos($vis_php, 'function sync_group');
        $this->assertNotFalse($start);
        $end = strpos($vis_php, "\n    public", $start + 1);
        $this->assertNotFalse($end);
        $body = substr($vis_php, $start, $end - $start);

        $this->assertStringContainsString(
            'get_publishable_members',
            $body,
            'sync_group должен валидировать effective против publishable members'
        );
    }

    public function test_finalize_backfill_state_schedules_cron_on_count_failure(): void
    {
        // Codex Round 7 (R7-2): на COUNT failure finalize_backfill_state
        // должен запланировать cron event (как main SELECT handler), иначе
        // option='scheduled' без actual cron event → backfill stalls.
        $start = strpos(self::$resolver_php, 'function finalize_backfill_state');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n}", $start);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        }
        $body = substr(self::$resolver_php, $start, $end - $start);

        // wp_schedule_single_event должен быть вызван в error path (после COUNT failure).
        $this->assertStringContainsString(
            'wp_schedule_single_event',
            $body,
            'finalize_backfill_state должен schedule cron event на COUNT failure'
        );
    }

    public function test_pick_fallback_member_uses_publishable_first_then_active(): void
    {
        $start = strpos(self::$resolver_php, 'function pick_fallback_member');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        }
        $body = substr(self::$resolver_php, $start, $end - $start);

        $publishable_pos = strpos($body, 'get_publishable_members');
        $active_pos      = strpos($body, 'get_active_members');

        $this->assertNotFalse(
            $publishable_pos,
            'pick_fallback_member должен использовать get_publishable_members'
        );
        $this->assertNotFalse(
            $active_pos,
            'pick_fallback_member должен fall through к get_active_members при пустом publishable'
        );
        $this->assertLessThan(
            $active_pos,
            $publishable_pos,
            'publishable preference: проверяется ДО active fallback'
        );
    }

    public function test_resolver_defines_pick_fallback_member_helper(): void
    {
        // Метод существует, public static, принимает int $group_id, возвращает int.
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+pick_fallback_member\s*\(\s*int\s+\$group_id\s*\)\s*:\s*int/',
            self::$resolver_php,
            'pick_fallback_member должен быть public static int helper'
        );

        // Round 5: helper делегирует выбор в pick_first_usable_member,
        // которому передаются publishable / active candidates. Sort logic
        // живёт в pick_first_usable_member.
        $start = strpos(self::$resolver_php, 'function pick_first_usable_member');
        $this->assertNotFalse($start, 'pick_first_usable_member helper должен существовать');
        $end = strpos(self::$resolver_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n    public", $start + 1);
            if ($end === false) {
                $end = strpos(self::$resolver_php, "\n    private", $start + 1);
            }
        }
        $body = substr(self::$resolver_php, $start, $end - $start);

        $this->assertMatchesRegularExpression(
            '/sort\s*\(.+?SORT_NUMERIC\s*\)/s',
            $body,
            'pick_first_usable_member sort\'ит members по SORT_NUMERIC для deterministic min'
        );

        // pick_fallback_member ссылается на active members как tier 4.
        $pf_start = strpos(self::$resolver_php, 'function pick_fallback_member');
        $pf_end   = strpos(self::$resolver_php, "\n    /**", $pf_start + 1);
        $pf_body  = substr(self::$resolver_php, $pf_start, $pf_end - $pf_start);
        $this->assertStringContainsString('get_active_members', $pf_body);
    }

    public function test_resolve_preferred_uses_pick_fallback_member_when_group_has_no_preferred(): void
    {
        // resolve_preferred при group exists + preferred=NULL должен делегировать
        // выбор fallback'а в pick_fallback_member (а не возвращать $product_id).
        // Это закрывает split-brain между catalog-visibility и resolve_preferred.
        $start = strpos(self::$resolver_php, 'function resolve_preferred');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        }
        $body = substr(self::$resolver_php, $start, $end - $start);

        $this->assertStringContainsString(
            'pick_fallback_member',
            $body,
            'resolve_preferred должен делегировать NULL-preferred fallback в общий helper'
        );
    }

    // ============================================================
    // 7. Tariff-sync race fix (Cashback_Shop_Group_Resolver::on_tariffs_changed).
    // ============================================================

    public function test_resolver_on_tariffs_changed_method_exists(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+on_tariffs_changed\s*\(\s*\$product_id\s*\)\s*:\s*void/',
            self::$resolver_php
        );
    }

    public function test_on_tariffs_changed_calls_recompute_preferred(): void
    {
        $start = strpos(self::$resolver_php, 'function on_tariffs_changed');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        }
        $body = substr(self::$resolver_php, $start, $end - $start);

        $this->assertStringContainsString('get_group_for_product', $body);
        $this->assertStringContainsString('recompute_preferred', $body);
    }

    public function test_bootstrap_registers_tariffs_changed_listener(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'cashback_tariffs_changed'\s*,\s*array\s*\(\s*'Cashback_Shop_Group_Resolver'\s*,\s*'on_tariffs_changed'\s*\)/",
            self::$cashback_plugin_php,
            'cashback_tariffs_changed listener должен быть зарегистрирован для закрытия race-condition'
        );
    }

    // ============================================================
    // 8. Migration v13 НЕ содержит unbounded synchronous backfill loop.
    //    Codex finding: backfill в миграции на user-request thrash'ит сайт.
    //    Перенесён в Cashback_Shop_Group_Resolver::ensure_preferred_backfilled.
    // ============================================================

    public function test_migration_v13_does_NOT_contain_synchronous_backfill_loop(): void
    {
        $start = strpos(self::$mariadb_php, 'function migrate_cleanup_ghost_members_v13');
        $this->assertNotFalse($start);
        $end = strpos(self::$mariadb_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$mariadb_php, "\n    public", $start + 1);
        }
        $body = substr(self::$mariadb_php, $start, $end - $start);

        $this->assertDoesNotMatchRegularExpression(
            '/foreach\s*\(\s*\$stale_group_ids/',
            $body,
            'Migration v13 НЕ должна содержать unbounded foreach по группам — backfill вынесен в wp-cron'
        );
    }

    // ============================================================
    // 9. Resumable backfill: ensure_preferred_backfilled + handle_preferred_backfill_cron.
    // ============================================================

    public function test_resolver_defines_preferred_backfill_constants(): void
    {
        $this->assertMatchesRegularExpression(
            "/const\s+PREFERRED_BACKFILL_OPTION\s*=\s*'cashback_group_preferred_backfill_v1'/",
            self::$resolver_php
        );
        $this->assertMatchesRegularExpression(
            "/const\s+PREFERRED_BACKFILL_CRON_HOOK\s*=\s*'cashback_group_preferred_backfill'/",
            self::$resolver_php
        );
        $this->assertMatchesRegularExpression(
            '/const\s+PREFERRED_BACKFILL_BATCH\s*=\s*\d+/',
            self::$resolver_php
        );
    }

    public function test_ensure_preferred_backfilled_is_self_healing(): void
    {
        $start = strpos(self::$resolver_php, 'function ensure_preferred_backfilled');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        }
        $body = substr(self::$resolver_php, $start, $end - $start);

        // Терминальное состояние — только 'done'.
        $this->assertMatchesRegularExpression(
            "/===\s*'done'/",
            $body,
            "ensure_preferred_backfilled должен возвращаться рано только при опции === 'done'"
        );
        // Self-healing — проверка очереди wp-cron перед планированием.
        $this->assertMatchesRegularExpression(
            '/wp_next_scheduled\s*\(\s*self::PREFERRED_BACKFILL_CRON_HOOK\s*\).*?wp_schedule_single_event/s',
            $body
        );
    }

    public function test_handle_preferred_backfill_cron_is_resumable(): void
    {
        $body = $this->backfill_handler_body();

        // SELECT с LIMIT и cursor (id > %d).
        $this->assertMatchesRegularExpression(
            '/preferred_product_id\s+IS\s+NULL\s+AND\s+id\s*>\s*%d/i',
            $body,
            'handle_preferred_backfill_cron должен использовать cursor (id > %d) для resumability'
        );
        $this->assertMatchesRegularExpression(
            '/LIMIT\s+%d/i',
            $body
        );
        // Должен планировать следующее событие, если batch-full.
        $this->assertStringContainsString('wp_schedule_single_event', $body);
        // Должен мечать 'done' когда нет больше групп.
        $this->assertMatchesRegularExpression(
            "/'done'/",
            $body
        );
        // Codex adversarial finding (backfill done semantics): должен также
        // поддерживать 'partial' для no-tariff групп где recompute оставил NULL.
        $this->assertMatchesRegularExpression(
            "/'partial'/",
            $body,
            'cron должен писать partial когда после прохода остаются NULL-группы'
        );
    }

    public function test_backfill_writes_partial_when_unresolvable_remain(): void
    {
        $body = $this->backfill_handler_body();

        // Перед записью 'done' должен быть total-COUNT (без cursor) на остатки
        // preferred IS NULL. Если > 0 — пишем 'partial' вместо 'done'.
        $this->assertMatchesRegularExpression(
            '/SELECT\s+COUNT\s*\(\s*\*\s*\)\s+FROM\s+%i\s+WHERE\s+preferred_product_id\s+IS\s+NULL/i',
            $body,
            'cron должен делать total-COUNT остатков NULL перед финализацией'
        );
        // Ветка partial должна писать в PREFERRED_BACKFILL_OPTION.
        $this->assertMatchesRegularExpression(
            "/update_option\s*\(\s*self::PREFERRED_BACKFILL_OPTION\s*,\s*'partial'/",
            $body,
            'cron должен сохранять status partial через update_option'
        );
    }

    public function test_handle_preferred_backfill_cron_resets_last_error_before_get_col(): void
    {
        // Codex Round 4 (high): $wpdb->last_error sticky между запросами.
        // Без явного сброса перед get_col() предыдущая (чужая) ошибка
        // унаследуется и handler ложно посчитает успешный SELECT failed.
        // Scope только handle_preferred_backfill_cron (до finalize_backfill_state),
        // иначе reset из finalize забирает first-occurrence.
        $start = strpos(self::$resolver_php, 'function handle_preferred_backfill_cron');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, 'function finalize_backfill_state', $start + 1);
        $this->assertNotFalse($end, 'finalize_backfill_state должен идти после handle (Round 3)');
        $body = substr(self::$resolver_php, $start, $end - $start);

        // Ожидаем: $wpdb->last_error = ''; ВНУТРИ метода (до условной ветки).
        $this->assertMatchesRegularExpression(
            '/\$wpdb->last_error\s*=\s*[\'"][\'"]/',
            $body,
            'handle_preferred_backfill_cron должен сбрасывать $wpdb->last_error перед SELECT'
        );

        // Reset должен быть ДО $wpdb->get_col() вызова, не после.
        // Ищем именно вызов на $wpdb (не упоминание get_col в комментариях).
        $reset_pos = strpos($body, "last_error = ''");
        if ($reset_pos === false) {
            $reset_pos = strpos($body, 'last_error = ""');
        }
        $get_col_pos = strpos($body, '$wpdb->get_col(');
        $this->assertNotFalse($reset_pos, 'reset last_error должен быть в теле handle');
        $this->assertNotFalse($get_col_pos, '$wpdb->get_col() должен быть в теле handle');
        $this->assertLessThan(
            $get_col_pos,
            $reset_pos,
            'reset last_error должен быть ДО $wpdb->get_col()'
        );
    }

    public function test_resolve_preferred_validates_pin_and_preferred_against_active_members(): void
    {
        // Codex Round 4 (medium): resolve_preferred возвращал stale pin/preferred
        // слепо. Должен валидировать через in_array($effective, $members, true).
        // Round 7 (R7-1): источник validation — get_publishable_members, не active.
        $start = strpos(self::$resolver_php, 'function resolve_preferred');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        }
        $body = substr(self::$resolver_php, $start, $end - $start);

        $this->assertStringContainsString(
            'get_publishable_members',
            $body,
            'resolve_preferred должен load publishable_members для валидации (Round 7)'
        );
        $this->assertMatchesRegularExpression(
            '/in_array\s*\(.*\$members.*true\s*\)/',
            $body,
            'resolve_preferred должен валидировать effective против publishable_members'
        );

        // Stale pin НЕ должен возвращаться напрямую — должен быть fall-through.
        // Старый паттерн "if (\$pin > 0) return \$pin;" больше не должен присутствовать.
        $this->assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*\$pin\s*>\s*0\s*\)\s*\{\s*return\s+\$pin\s*;\s*\}/',
            $body,
            'старая ветка blind-return pin без validation должна быть удалена'
        );
    }

    public function test_finalize_backfill_state_guards_against_count_query_failure(): void
    {
        // Codex adversarial finding (high): get_var может вернуть null/false,
        // (int) null === 0 → ветка 'done' срабатывала ложно при transient
        // DB-error и навсегда замораживала backfill. finalize_backfill_state
        // должен ту же дисциплину last_error, что основной handler.
        $start = strpos(self::$resolver_php, 'function finalize_backfill_state');
        $this->assertNotFalse($start, 'finalize_backfill_state должен существовать');
        $end = strpos(self::$resolver_php, "\n}", $start);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        }
        $body = substr(self::$resolver_php, $start, $end - $start);

        $this->assertStringContainsString(
            'last_error',
            $body,
            'finalize_backfill_state должен проверять last_error перед интерпретацией результата'
        );
        // На failure пишем 'scheduled' (как основной handler) — НЕ 'done' и НЕ 'partial'.
        $this->assertMatchesRegularExpression(
            "/'scheduled'/",
            $body,
            'на DB-failure finalize_backfill_state должен оставлять scheduled'
        );
    }

    public function test_ensure_preferred_backfilled_uses_back_off_for_partial(): void
    {
        $start = strpos(self::$resolver_php, 'function ensure_preferred_backfilled');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n    /**", $start + 1);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        }
        $body = substr(self::$resolver_php, $start, $end - $start);

        // partial → schedule с back-off (3600s), чтобы не молотить cron на
        // нерешаемых группах между tariff sync'ами.
        $this->assertMatchesRegularExpression(
            "/'partial'/",
            $body,
            'ensure_preferred_backfilled должен распознавать partial и использовать back-off delay'
        );
        $this->assertMatchesRegularExpression(
            '/3600/',
            $body,
            'partial должен иметь delay 3600s (1h back-off)'
        );
    }

    /**
     * Helper: тело handle_preferred_backfill_cron целиком.
     */
    private function backfill_handler_body(): string
    {
        $start = strpos(self::$resolver_php, 'function handle_preferred_backfill_cron');
        $this->assertNotFalse($start);
        $end = strpos(self::$resolver_php, "\n}", $start + 1);
        if ($end === false) {
            $end = strpos(self::$resolver_php, "\n    public", $start + 1);
        }
        return substr(self::$resolver_php, $start, $end - $start);
    }

    public function test_backfill_handler_separates_db_failure_from_empty_result(): void
    {
        $body = $this->backfill_handler_body();

        // Должна быть проверка $wpdb->last_error.
        $this->assertStringContainsString('last_error', $body);
        // На ошибке БД — НЕ пишем 'done', а оставляем 'scheduled' для retry.
        $this->assertMatchesRegularExpression(
            "/last_error.*?'scheduled'/s",
            $body,
            'При DB failure должна писаться scheduled, не done'
        );
        // Empty result обрабатывается отдельно — empty($ids) проверяется ПОСЛЕ
        // db_error check (нельзя склеивать ! is_array($ids) || empty($ids)).
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*empty\s*\(\s*\$ids\s*\)\s*\)/',
            $body,
            'empty($ids) должен быть отдельным if-блоком после db_error check'
        );
    }

    public function test_backfill_handler_aborts_batch_on_exception(): void
    {
        $body = $this->backfill_handler_body();

        // catch блок должен делать break, а не просто log+continue.
        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\?Throwable\s+\$e\s*\)\s*\{[^}]*?break\s*;/s',
            $body,
            'catch должен делать break чтобы не двигать cursor дальше failed group'
        );
        // Должен быть флаг $batch_failed.
        $this->assertStringContainsString('$batch_failed', $body);
    }

    public function test_backfill_cursor_advances_only_on_success(): void
    {
        $body = $this->backfill_handler_body();

        // $max_id обновляется ПОСЛЕ try (не в catch и не до).
        // Это гарантируется тем что break в catch выходит из цикла до $max_id присваивания.
        $this->assertMatchesRegularExpression(
            '/recompute_preferred\s*\(\s*\$gid\s*\)\s*;\s*\}\s*catch.*?\}\s*if\s*\(\s*\$gid\s*>\s*\$max_id\s*\)/s',
            $body,
            '$max_id должен обновляться ПОСЛЕ catch (success-only path)'
        );
    }

    public function test_bootstrap_registers_preferred_backfill_cron(): void
    {
        $this->assertMatchesRegularExpression(
            '/add_action\s*\(\s*Cashback_Shop_Group_Resolver::PREFERRED_BACKFILL_CRON_HOOK\s*,\s*array\s*\(\s*\'Cashback_Shop_Group_Resolver\'\s*,\s*\'handle_preferred_backfill_cron\'\s*\)/',
            self::$cashback_plugin_php
        );
        $this->assertMatchesRegularExpression(
            '/Cashback_Shop_Group_Resolver::ensure_preferred_backfilled\s*\(\s*\)/',
            self::$cashback_plugin_php
        );
    }

    // ============================================================
    // 10. Importer fires cashback_tariffs_changed на soft_deleted (не только upsert).
    // ============================================================

    public function test_importer_fires_action_on_soft_deleted(): void
    {
        $importer_php = (string) file_get_contents(self::$plugin_root . '/includes/shops/class-cashback-shop-importer.php');

        // Должно быть условие $upserted > 0 || $soft_deleted > 0.
        $this->assertMatchesRegularExpression(
            '/\$upserted\s*>\s*0\s*\|\|\s*\$soft_deleted\s*>\s*0/',
            $importer_php,
            'cashback_tariffs_changed должен фириться и при soft_deleted (CPA-сеть удалила тарифы)'
        );
    }
}
