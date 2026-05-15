<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Behavioral тесты на Cashback_Shop_Dup_Status_Sync — enforcement post_status
 * для draft-модели дедупа магазинов (Этап 2).
 *
 * Инвариант: в группе дублей опубликован только preferred (лучшая ставка);
 * остальные → draft с маркером _cashback_dup_demoted. Авто-публикация
 * preferred — только если он был демоутнут нами И включён флаг
 * «Автопубликация» И кампания активна.
 *
 * @group shop-import
 * @group dup-status-sync
 */
#[Group('shop-import')]
#[Group('dup-status-sync')]
final class ShopDupStatusSyncTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
        require_once dirname(__DIR__) . '/Shop_Test_Wpdb_Stub.php';
        if (!class_exists('Cashback_Shop_Tariff_Sync')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-shop-tariff-sync.php';
        }
        if (!class_exists('Cashback_Shop_Group_Resolver')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-shop-group-resolver.php';
        }
        if (!class_exists('Cashback_Product_Autopublish')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-product-autopublish.php';
        }
        if (!class_exists('Cashback_Shop_Dup_Status_Sync')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-shop-dup-status-sync.php';
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_post_meta'] = array();
        $GLOBALS['_cb_test_options']   = array();
        $GLOBALS['_cb_test_posts']     = array();
        $this->installStub();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new Shop_Test_Wpdb_Stub();
        unset($GLOBALS['_dup_group'], $GLOBALS['_dup_active'], $GLOBALS['_dup_publishable']);
    }

    /**
     * Stub маршрутизирует:
     *  - get_row(cashback_shop_groups)             → $GLOBALS['_dup_group'];
     *  - get_results(... post_status = 'publish')  → $GLOBALS['_dup_publishable'];
     *  - get_results(cashback_shop_group_members)  → $GLOBALS['_dup_active'].
     */
    private function installStub(): void
    {
        $stub = new class extends Shop_Test_Wpdb_Stub {
            public function get_row(mixed $sql, mixed $output = ARRAY_A): mixed
            {
                if (str_contains((string) $sql, 'cashback_shop_groups')) {
                    return $GLOBALS['_dup_group'] ?? null;
                }
                return null;
            }

            public function get_results(mixed $sql, mixed $output = ARRAY_A): mixed
            {
                $s = (string) $sql;
                if (str_contains($s, "post_status = 'publish'")) {
                    return $GLOBALS['_dup_publishable'] ?? array();
                }
                if (str_contains($s, 'cashback_shop_group_members')) {
                    return $GLOBALS['_dup_active'] ?? array();
                }
                return array();
            }
        };
        $GLOBALS['wpdb'] = $stub;
    }

    private function makePost(int $id, string $status): void
    {
        $p             = new WP_Post();
        $p->ID         = $id;
        $p->post_status = $status;
        $p->post_type  = 'product';
        $GLOBALS['_cb_test_posts'][ $id ] = $p;
    }

    private function postStatus(int $id): string
    {
        return (string) ($GLOBALS['_cb_test_posts'][ $id ]->post_status ?? '');
    }

    // ============================================================
    // Демоут опубликованных не-preferred.
    // ============================================================

    public function test_demotes_published_non_preferred_to_draft_with_markers(): void
    {
        $GLOBALS['_dup_group']       = array('id' => 1155, 'pin_product_id' => null, 'preferred_product_id' => 4175);
        $GLOBALS['_dup_active']      = array(array('product_id' => 4175), array('product_id' => 4296));
        $GLOBALS['_dup_publishable'] = array(array('product_id' => 4175), array('product_id' => 4296));
        $this->makePost(4175, 'publish');
        $this->makePost(4296, 'publish');
        update_post_meta(4175, '_affiliate_network_id', '1');

        Cashback_Shop_Dup_Status_Sync::sync_group_status(1155);

        $this->assertSame('draft', $this->postStatus(4296), 'не-preferred publish → draft');
        $this->assertSame('publish', $this->postStatus(4175), 'preferred не трогаем');
        $this->assertSame('1', (string) get_post_meta(4296, '_cashback_dup_demoted', true));
        $this->assertSame('1', (string) get_post_meta(4296, '_cashback_dup_winner_network', true));
        $this->assertSame('4175', (string) get_post_meta(4296, '_cashback_dup_winner_product', true));
        $this->assertNotSame('', (string) get_post_meta(4296, '_cashback_dup_demoted_at', true));
    }

    public function test_does_not_demote_already_draft_non_preferred(): void
    {
        $GLOBALS['_dup_group']       = array('id' => 301, 'pin_product_id' => null, 'preferred_product_id' => 4121);
        $GLOBALS['_dup_active']      = array(array('product_id' => 4121), array('product_id' => 4286));
        $GLOBALS['_dup_publishable'] = array(array('product_id' => 4121)); // 4286 уже draft
        $this->makePost(4121, 'publish');
        $this->makePost(4286, 'draft');
        update_post_meta(4121, '_affiliate_network_id', '1');

        Cashback_Shop_Dup_Status_Sync::sync_group_status(301);

        $this->assertSame('draft', $this->postStatus(4286));
        // Маркер НЕ ставим (мы его не демоутили — он уже был draft).
        $this->assertSame('', (string) get_post_meta(4286, '_cashback_dup_demoted', true));
    }

    // ============================================================
    // Tariff-sync race guard (Codex/финтех): НЕ трогать post_status,
    // когда нет надёжно определённого preferred (recompute вернул 0 —
    // напр. тарифы ещё не синканы) или preferred вне active. Иначе
    // хороший publish-товар ушёл бы в draft по fallback-догадке без
    // авто-возврата.
    // ============================================================

    public function test_sync_skips_status_when_no_resolved_preferred(): void
    {
        $GLOBALS['_dup_group']       = array('id' => 1155, 'pin_product_id' => null, 'preferred_product_id' => null);
        $GLOBALS['_dup_active']      = array(array('product_id' => 4175), array('product_id' => 4296));
        $GLOBALS['_dup_publishable'] = array(array('product_id' => 4175), array('product_id' => 4296));
        $this->makePost(4175, 'publish');
        $this->makePost(4296, 'publish');

        Cashback_Shop_Dup_Status_Sync::sync_group_status(1155);

        $this->assertSame('publish', $this->postStatus(4175), 'нет preferred → не демоутим');
        $this->assertSame('publish', $this->postStatus(4296), 'нет preferred → не демоутим');
        $this->assertSame('', (string) get_post_meta(4296, '_cashback_dup_demoted', true));
    }

    public function test_sync_skips_status_when_preferred_not_in_active(): void
    {
        // preferred указывает на удалённый/trashed товар (нет в active).
        $GLOBALS['_dup_group']       = array('id' => 1155, 'pin_product_id' => null, 'preferred_product_id' => 999);
        $GLOBALS['_dup_active']      = array(array('product_id' => 4175), array('product_id' => 4296));
        $GLOBALS['_dup_publishable'] = array(array('product_id' => 4175), array('product_id' => 4296));
        $this->makePost(4175, 'publish');
        $this->makePost(4296, 'publish');

        Cashback_Shop_Dup_Status_Sync::sync_group_status(1155);

        $this->assertSame('publish', $this->postStatus(4175), 'stale preferred → не демоутим');
        $this->assertSame('publish', $this->postStatus(4296), 'stale preferred → не демоутим');
    }

    // ============================================================
    // Авто-публикация preferred — строго по условиям.
    // ============================================================

    public function test_does_not_promote_fresh_draft_without_marker(): void
    {
        // Свежий импорт: preferred draft, без _cashback_dup_demoted. Первую
        // публикацию делает админ вручную — авто-publish не должен срабатывать.
        $GLOBALS['_dup_group']       = array('id' => 50, 'pin_product_id' => null, 'preferred_product_id' => 900);
        $GLOBALS['_dup_active']      = array(array('product_id' => 900));
        $GLOBALS['_dup_publishable'] = array();
        $this->makePost(900, 'draft');
        update_post_meta(900, '_cashback_auto_publish_enabled', '1');

        Cashback_Shop_Dup_Status_Sync::sync_group_status(50);

        $this->assertSame('draft', $this->postStatus(900), 'fresh draft без маркера не публикуется авто');
    }

    public function test_promotes_preferred_when_demoted_and_autopublish_on(): void
    {
        // 4296 раньше демоутнут нами (_cashback_dup_demoted=1), теперь стал
        // preferred, флаг автопубликации включён, кампания активна → publish.
        $GLOBALS['_dup_group']       = array('id' => 1155, 'pin_product_id' => null, 'preferred_product_id' => 4296);
        $GLOBALS['_dup_active']      = array(array('product_id' => 4175), array('product_id' => 4296));
        $GLOBALS['_dup_publishable'] = array(array('product_id' => 4175)); // 4296 ещё draft
        $this->makePost(4175, 'publish');
        $this->makePost(4296, 'draft');
        update_post_meta(4296, '_cashback_dup_demoted', '1');
        update_post_meta(4296, '_cashback_dup_demoted_at', '2026-05-10 00:00:00');
        update_post_meta(4296, '_cashback_auto_publish_enabled', '1');
        update_post_meta(4296, '_affiliate_network_id', '9');

        Cashback_Shop_Dup_Status_Sync::sync_group_status(1155);

        $this->assertSame('publish', $this->postStatus(4296), 'preferred демоутнутый + autopublish → publish');
        $this->assertSame('', (string) get_post_meta(4296, '_cashback_dup_demoted', true), 'маркеры очищены');
        $this->assertSame('', (string) get_post_meta(4296, '_cashback_dup_winner_product', true));
        // Старый победитель 4175 теперь не-preferred publish → демоут.
        $this->assertSame('draft', $this->postStatus(4175));
        $this->assertSame('1', (string) get_post_meta(4175, '_cashback_dup_demoted', true));
    }

    public function test_does_not_promote_when_campaign_deactivated(): void
    {
        $GLOBALS['_dup_group']       = array('id' => 1155, 'pin_product_id' => null, 'preferred_product_id' => 4296);
        $GLOBALS['_dup_active']      = array(array('product_id' => 4296));
        $GLOBALS['_dup_publishable'] = array();
        $this->makePost(4296, 'draft');
        update_post_meta(4296, '_cashback_dup_demoted', '1');
        update_post_meta(4296, '_cashback_auto_publish_enabled', '1');
        update_post_meta(4296, '_cashback_auto_deactivated', '1'); // кампания неактивна

        Cashback_Shop_Dup_Status_Sync::sync_group_status(1155);

        $this->assertSame('draft', $this->postStatus(4296), 'деактивированный кампанией не публикуется');
    }

    public function test_does_not_promote_when_autopublish_off(): void
    {
        $GLOBALS['_dup_group']       = array('id' => 1155, 'pin_product_id' => null, 'preferred_product_id' => 4296);
        $GLOBALS['_dup_active']      = array(array('product_id' => 4296));
        $GLOBALS['_dup_publishable'] = array();
        $this->makePost(4296, 'draft');
        update_post_meta(4296, '_cashback_dup_demoted', '1');
        // _cashback_auto_publish_enabled отсутствует → OFF.

        Cashback_Shop_Dup_Status_Sync::sync_group_status(1155);

        $this->assertSame('draft', $this->postStatus(4296), 'OFF → только авто-демоут, publish вручную');
    }

    // ============================================================
    // Backfill — только демоут, без авто-публикации.
    // ============================================================

    public function test_backfill_group_demotes_but_never_promotes(): void
    {
        $GLOBALS['_dup_group']       = array('id' => 1155, 'pin_product_id' => null, 'preferred_product_id' => 4296);
        $GLOBALS['_dup_active']      = array(array('product_id' => 4175), array('product_id' => 4296));
        $GLOBALS['_dup_publishable'] = array(array('product_id' => 4175));
        $this->makePost(4175, 'publish');
        $this->makePost(4296, 'draft');
        // Все условия для promote выполнены — но backfill НЕ публикует.
        update_post_meta(4296, '_cashback_dup_demoted', '1');
        update_post_meta(4296, '_cashback_auto_publish_enabled', '1');

        Cashback_Shop_Dup_Status_Sync::sync_group_status(1155, false); // allow_promote=false

        $this->assertSame('draft', $this->postStatus(4296), 'backfill не публикует preferred');
        $this->assertSame('draft', $this->postStatus(4175), 'но демоутит опубликованного не-preferred');
    }

    // ============================================================
    // Ручная публикация дубля админом → очистка маркеров.
    // ============================================================

    public function test_manual_publish_clears_dup_markers(): void
    {
        $this->makePost(4296, 'publish');
        update_post_meta(4296, '_cashback_dup_demoted', '1');
        update_post_meta(4296, '_cashback_dup_demoted_at', '2026-05-10 00:00:00');
        update_post_meta(4296, '_cashback_dup_winner_network', '1');
        update_post_meta(4296, '_cashback_dup_winner_product', '4175');

        $post             = new WP_Post();
        $post->ID         = 4296;
        $post->post_type  = 'product';
        $post->post_status = 'publish';

        Cashback_Shop_Dup_Status_Sync::on_transition_clear_dup_markers('publish', 'draft', $post);

        $this->assertSame('', (string) get_post_meta(4296, '_cashback_dup_demoted', true));
        $this->assertSame('', (string) get_post_meta(4296, '_cashback_dup_winner_product', true));
    }

    public function test_transition_clear_ignores_non_publish(): void
    {
        update_post_meta(4296, '_cashback_dup_demoted', '1');
        $post             = new WP_Post();
        $post->ID         = 4296;
        $post->post_type  = 'product';
        $post->post_status = 'draft';

        Cashback_Shop_Dup_Status_Sync::on_transition_clear_dup_markers('draft', 'publish', $post);

        $this->assertSame('1', (string) get_post_meta(4296, '_cashback_dup_demoted', true), 'не →publish — не трогаем');
    }

    // ============================================================
    // notice_context — данные для admin-уведомления о дубле (Этап 3).
    // ============================================================

    public function test_notice_context_null_when_product_not_in_group(): void
    {
        $GLOBALS['_dup_group'] = null; // get_group_for_product → нет группы

        $this->assertNull(Cashback_Shop_Dup_Status_Sync::notice_context(4296));
    }

    public function test_notice_context_null_when_product_is_effective_preferred(): void
    {
        $GLOBALS['_dup_group']  = array(
            'id'                   => 1155,
            'domain'               => 'skillfactory.ru',
            'pin_product_id'       => null,
            'preferred_product_id' => 4175,
        );
        $GLOBALS['_dup_active'] = array(array('product_id' => 4175), array('product_id' => 4296));

        // 4175 — сам preferred → уведомление не нужно.
        $this->assertNull(Cashback_Shop_Dup_Status_Sync::notice_context(4175));
    }

    public function test_notice_context_null_when_group_has_single_member(): void
    {
        $GLOBALS['_dup_group']  = array(
            'id'                   => 70,
            'domain'               => 'solo.ru',
            'pin_product_id'       => null,
            'preferred_product_id' => 900,
        );
        $GLOBALS['_dup_active'] = array(array('product_id' => 900));

        $this->assertNull(Cashback_Shop_Dup_Status_Sync::notice_context(900));
    }

    public function test_notice_context_returns_winner_for_non_preferred(): void
    {
        $GLOBALS['_dup_group']  = array(
            'id'                   => 1155,
            'domain'               => 'skillfactory.ru',
            'pin_product_id'       => null,
            'preferred_product_id' => 4175,
        );
        $GLOBALS['_dup_active'] = array(array('product_id' => 4175), array('product_id' => 4296));
        update_post_meta(4175, '_affiliate_network_id', '1');

        $ctx = Cashback_Shop_Dup_Status_Sync::notice_context(4296);

        $this->assertIsArray($ctx);
        $this->assertSame('skillfactory.ru', $ctx['domain']);
        $this->assertSame(4175, $ctx['winner_id']);
        $this->assertSame(1, $ctx['winner_network_id']);
    }
}
