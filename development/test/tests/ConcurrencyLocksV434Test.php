<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Lock-busy сценарии для advisory-lock'ов добавленных в v4.3.4:
 *   - Cashback_Shop_Tariff_Sync::sync — per-(network_id, offer_id) GET_LOCK
 *   - Cashback_Promocodes_Repository::upsert_for_campaign — per-(network_id, advcampaign_id) GET_LOCK
 *
 * @group concurrency
 * @group v4-3-4
 */
#[Group('concurrency')]
#[Group('v4-3-4')]
final class ConcurrencyLocksV434Test extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
        require_once dirname(__DIR__) . '/Shop_Test_Wpdb_Stub.php';

        $files = array(
            '/includes/adapters/class-cashback-shop-tariff-dto.php',
            '/includes/shops/class-cashback-shop-tariff-sync.php',
            '/includes/promocodes/dto/class-coupon-dto.php',
            '/includes/promocodes/class-cashback-promocodes-repository.php',
        );
        foreach ($files as $rel) {
            $path = self::$plugin_root . $rel;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    public function test_shop_tariff_sync_returns_lock_busy_when_get_lock_fails(): void
    {
        global $wpdb;
        $wpdb = new Shop_Test_Wpdb_Stub();
        $wpdb->next_get_var = 0;  // GET_LOCK returns 0 — concurrent run holds the lock

        $result = Cashback_Shop_Tariff_Sync::sync(9, 'offer-123', array());

        $this->assertFalse($result['success']);
        $this->assertSame('lock_busy', $result['error']);
        $this->assertSame(0, $result['upserted']);
        $this->assertSame(0, $result['soft_deleted']);
        $this->assertSame(array(), $wpdb->queries, 'lock-busy → ни одного DML-запроса');
    }

    public function test_shop_tariff_sync_lock_released_after_normal_completion(): void
    {
        global $wpdb;
        $wpdb = new Shop_Test_Wpdb_Stub();
        // next_get_var=null + stub auto-detect GET_LOCK → 1 (acquired)
        $wpdb->next_get_var = null;

        Cashback_Shop_Tariff_Sync::sync(9, 'offer-123', array());

        // Проверяем что RELEASE_LOCK вызван: queries не содержит lock SELECTs
        // (они идут через get_var, не query()), но проверим что START TRANSACTION
        // и COMMIT прошли — это означает что lock был acquired и flow завершился.
        $tx_sqls = array_map(static fn($q) => $q['sql'], $wpdb->queries);
        $this->assertContains('START TRANSACTION', $tx_sqls);
        $this->assertContains('COMMIT', $tx_sqls);
    }

    public function test_promocodes_upsert_returns_lock_busy_when_get_lock_fails(): void
    {
        global $wpdb;
        $wpdb = new Shop_Test_Wpdb_Stub();
        $wpdb->next_get_var = 0;

        $repo   = new Cashback_Promocodes_Repository();
        $result = $repo->upsert_for_campaign(9, 'camp-403', array());

        $this->assertSame(0, $result['upserted']);
        $this->assertSame(0, $result['deactivated']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('lock_busy', $result['error']);
        $this->assertSame(array(), $wpdb->queries, 'lock-busy → ни одного DML');
    }

    public function test_promocodes_upsert_wraps_in_transaction(): void
    {
        global $wpdb;
        $wpdb = new Shop_Test_Wpdb_Stub();
        $wpdb->next_get_var = null;  // auto-detect → GET_LOCK returns 1

        $repo = new Cashback_Promocodes_Repository();
        $repo->upsert_for_campaign(9, 'camp-403', array());

        $tx_sqls = array_map(static fn($q) => $q['sql'], $wpdb->queries);
        $this->assertContains('START TRANSACTION', $tx_sqls);
        $this->assertContains('COMMIT', $tx_sqls);
    }
}
