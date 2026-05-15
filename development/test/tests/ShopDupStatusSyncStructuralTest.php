<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты на draft-модель дедупа (Этап 2-3 + wiring):
 *   - Cashback_Shop_Dup_Status_Sync: константы, register-хуки, reentrancy
 *     guard, demote/promote-инварианты, only-demote backfill;
 *   - метабокс wc-affiliate-url-params.php: вызов render_dup_group_notice;
 *   - Cashback_Shop_Dup_Admin_Notice: admin_notices + dismiss nonce;
 *   - cashback-plugin.php: require + register + ensure_backfilled.
 *
 * @group shop-import
 * @group dup-status-sync
 */
#[Group('shop-import')]
#[Group('dup-status-sync')]
final class ShopDupStatusSyncStructuralTest extends TestCase
{
    private static string $root;
    private static string $sync_php;
    private static string $notice_php;
    private static string $metabox_php;
    private static string $plugin_php;
    private static string $resolver_php;

    public static function setUpBeforeClass(): void
    {
        self::$root         = dirname(__DIR__, 3);
        self::$sync_php     = (string) file_get_contents(self::$root . '/includes/shops/class-cashback-shop-dup-status-sync.php');
        self::$notice_php   = (string) file_get_contents(self::$root . '/includes/admin/class-cashback-shop-dup-admin-notice.php');
        self::$metabox_php  = (string) file_get_contents(self::$root . '/wc-affiliate-url-params.php');
        self::$plugin_php   = (string) file_get_contents(self::$root . '/cashback-plugin.php');
        self::$resolver_php = (string) file_get_contents(self::$root . '/includes/shops/class-cashback-shop-group-resolver.php');
    }

    // ---- Codex review фиксы ----

    public function test_get_active_members_has_deterministic_order(): void
    {
        $body = $this->methodBody(self::$resolver_php, 'get_active_members');
        $this->assertStringContainsString('ORDER BY m.product_id ASC', $body, 'Codex HIGH-1: стабильный порядок строк');
    }

    public function test_recompute_preferred_has_min_id_tiebreak(): void
    {
        $body = $this->methodBody(self::$resolver_php, 'recompute_preferred');
        $this->assertStringContainsString('$product_id < $best_id', $body, 'Codex HIGH-1: детерминированный tie-break по min id');
    }

    public function test_count_conflicts_skips_undecided_groups(): void
    {
        $body = $this->methodBody(self::$notice_php, 'count_conflicts');
        $this->assertStringContainsString(
            'COALESCE(g.pin_product_id, g.preferred_product_id, 0) > 0',
            $body,
            'Codex HIGH-2: не баннерить группы без решённого preferred'
        );
    }

    /**
     * Голая не-агрегированная и не входящая в GROUP BY колонка g.* в HAVING
     * отбивается MariaDB 11.8.6 ошибкой «Unknown column 'g.pin_product_id' in
     * 'HAVING'» — подтверждено на проде и staging 2026-05-16 (запрос падал
     * молча: (int)$wpdb->get_var(null) = 0). Предикат уровня группы обязан
     * стоять в WHERE (до GROUP BY), не в HAVING; g.* допустим в HAVING только
     * внутри агрегата SUM(...).
     */
    public function test_count_conflicts_predicate_in_where_not_having(): void
    {
        // Срезаем SQL-комментарии `-- ...`: иначе strpos ловит ключевые
        // слова в пояснениях (memory feedback «structural-test-regex-comments»).
        $body = (string) preg_replace('/--[^\n]*/', '', $this->methodBody(self::$notice_php, 'count_conflicts'));

        $group_by = strpos($body, 'GROUP BY g.id');
        $this->assertNotFalse($group_by, 'GROUP BY g.id не найден');

        $where_term = strpos($body, 'WHERE COALESCE(g.pin_product_id, g.preferred_product_id, 0) > 0');
        $this->assertNotFalse(
            $where_term,
            'COALESCE(g.pin/preferred) > 0 должен стоять в WHERE'
        );
        $this->assertLessThan(
            $group_by,
            $where_term,
            'WHERE-предикат обязан предшествовать GROUP BY'
        );

        $having = strpos($body, 'HAVING');
        $this->assertNotFalse($having, 'HAVING не найден');
        $this->assertStringNotContainsString(
            'AND COALESCE(g.pin_product_id, g.preferred_product_id, 0) > 0',
            substr($body, $having),
            "голая g.* колонка в HAVING — MariaDB 11.8 'Unknown column ... in HAVING'"
        );
    }

    // ---- Cashback_Shop_Dup_Status_Sync ----

    public function test_sync_class_and_marker_constants(): void
    {
        $this->assertStringContainsString('class Cashback_Shop_Dup_Status_Sync', self::$sync_php);
        $this->assertStringContainsString("META_DEMOTED        = '_cashback_dup_demoted'", self::$sync_php);
        $this->assertStringContainsString("META_WINNER_NETWORK = '_cashback_dup_winner_network'", self::$sync_php);
        $this->assertStringContainsString("META_WINNER_PRODUCT = '_cashback_dup_winner_product'", self::$sync_php);
        $this->assertStringContainsString("BACKFILL_OPTION    = 'cashback_shop_dup_status_backfill_v1'", self::$sync_php);
    }

    public function test_register_hooks_preferred_changed_priority_15(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\(\s*'cashback_group_preferred_changed',\s*array\(\s*__CLASS__,\s*'on_group_preferred_changed'\s*\),\s*15/",
            self::$sync_php,
            'sync должен слушать cashback_group_preferred_changed с priority 15 (после Catalog_Visibility=10)'
        );
        $this->assertStringContainsString("add_action('transition_post_status', array( __CLASS__, 'on_transition_clear_dup_markers' )", self::$sync_php);
        $this->assertStringContainsString("add_action(self::CRON_BACKFILL_HOOK", self::$sync_php);
    }

    public function test_has_reentrancy_guard(): void
    {
        $this->assertStringContainsString('private static bool $syncing = false;', self::$sync_php);
        $this->assertMatchesRegularExpression('/if \(\$group_id <= 0 \|\| self::\$syncing\)/', self::$sync_php);
        $this->assertStringContainsString('self::$syncing = true;', self::$sync_php);
        $this->assertStringContainsString('self::$syncing = false;', self::$sync_php);
    }

    public function test_demote_sets_draft_and_markers(): void
    {
        $body = $this->methodBody(self::$sync_php, 'demote');
        $this->assertStringContainsString("'post_status' => 'draft'", $body);
        $this->assertStringContainsString('self::META_DEMOTED, \'1\'', $body);
        $this->assertStringContainsString('self::META_WINNER_PRODUCT', $body);
    }

    public function test_maybe_promote_gated_by_three_conditions(): void
    {
        $body = $this->methodBody(self::$sync_php, 'maybe_promote');
        // (a) был демоутнут нами
        $this->assertStringContainsString('self::META_DEMOTED, true) !== \'1\'', $body);
        // (b) кампания активна
        $this->assertStringContainsString('self::META_AUTO_DEACTIVATED, true) === \'1\'', $body);
        // (c) флаг автопубликации
        $this->assertStringContainsString('_cashback_auto_publish_enabled', $body);
        $this->assertStringContainsString("'post_status' => 'publish'", $body);
    }

    public function test_backfill_all_only_demotes(): void
    {
        $body = $this->methodBody(self::$sync_php, 'backfill_all');
        $this->assertMatchesRegularExpression(
            '/sync_group_status\(\s*\$gid,\s*false\s*\)/',
            $body,
            'backfill обязан вызывать sync_group_status с allow_promote=false'
        );
    }

    public function test_resolve_effective_validates_against_active_not_publishable(): void
    {
        $body = $this->methodBody(self::$sync_php, 'resolve_effective');
        $this->assertStringContainsString('in_array($effective, $active, true)', $body);
        $this->assertStringContainsString('pick_fallback_member', $body);
        $this->assertStringNotContainsString('get_publishable_members', $body);
    }

    // ---- Метабокс ----

    public function test_metabox_invokes_dup_notice_renderer(): void
    {
        $this->assertStringContainsString('$this->render_dup_group_notice((int) $post->ID);', self::$metabox_php);
        $this->assertStringContainsString('private function render_dup_group_notice( int $product_id ): void', self::$metabox_php);
        $this->assertStringContainsString('Cashback_Shop_Dup_Status_Sync::notice_context($product_id)', self::$metabox_php);
        // Подсказка про автопубликацию обязательно присутствует.
        $this->assertStringContainsString('Автопубликация', self::$metabox_php);
    }

    // ---- Admin notice ----

    public function test_admin_notice_registers_and_dismiss_protected(): void
    {
        $this->assertStringContainsString('class Cashback_Shop_Dup_Admin_Notice', self::$notice_php);
        $this->assertStringContainsString("add_action('admin_notices', array( __CLASS__, 'maybe_render' )", self::$notice_php);
        $this->assertStringContainsString("add_action('cashback_group_preferred_changed', array( __CLASS__, 'flush_cache' )", self::$notice_php);
        // Dismiss защищён nonce + capability.
        $this->assertStringContainsString('wp_verify_nonce($nonce, self::NONCE_ACTION)', self::$notice_php);
        $this->assertStringContainsString('current_user_can(self::CAPABILITY)', self::$notice_php);
    }

    // ---- Bootstrap wiring ----

    public function test_plugin_bootstrap_requires_and_registers(): void
    {
        $this->assertStringContainsString("require_file('includes/shops/class-cashback-shop-dup-status-sync.php')", self::$plugin_php);
        $this->assertStringContainsString("require_file('includes/admin/class-cashback-shop-dup-admin-notice.php')", self::$plugin_php);
        $this->assertStringContainsString('Cashback_Shop_Dup_Status_Sync::register();', self::$plugin_php);
        $this->assertStringContainsString('Cashback_Shop_Dup_Status_Sync::ensure_backfilled();', self::$plugin_php);
        $this->assertStringContainsString('Cashback_Shop_Dup_Admin_Notice::register();', self::$plugin_php);
    }

    /**
     * Извлекает тело метода через подсчёт баланса фигурных скобок
     * (устойчиво к росту метода между раундами — см. memory feedback
     * «structural-test-body-extraction»).
     */
    private function methodBody( string $source, string $method ): string
    {
        $pos = strpos($source, 'function ' . $method . '(');
        $this->assertNotFalse($pos, "метод {$method} не найден");
        $brace = strpos($source, '{', $pos);
        $this->assertNotFalse($brace, "тело {$method} не найдено");

        $depth = 0;
        $len   = strlen($source);
        for ($i = $brace; $i < $len; $i++) {
            if ($source[ $i ] === '{') {
                $depth++;
            } elseif ($source[ $i ] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }
        $this->fail("несбалансированные скобки в {$method}");
    }
}
