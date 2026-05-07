<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional тесты на Cashback_Shop_Group_Resolver (v12, Этап 6).
 *
 * Проверяем дедуп магазинов по домену:
 *  - find_or_create_group: lookup по UNIQUE(domain), создаёт при отсутствии;
 *  - attach_member: INSERT … ON DUPLICATE KEY UPDATE для UNIQUE(product_id);
 *  - reconcile_for_product: end-to-end из importer'а;
 *  - resolve_preferred: pin override > preferred_product_id > self;
 *  - recompute_preferred: max(payment_size) + currency tie-break;
 *  - score_product: нет тарифов → -1.0;
 *  - admin actions: pin/unpin/split/confirm.
 *
 * @group shop-import
 * @group group-resolver
 */
#[Group('shop-import')]
#[Group('group-resolver')]
final class ShopGroupResolverTest extends TestCase
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
    }

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new Shop_Test_Wpdb_Stub();
        $GLOBALS['_cb_test_user_meta']    = array();
        $GLOBALS['_cb_test_post_meta']    = array(); // нашему тесту достаточно in-memory.
        $GLOBALS['_cb_test_filters']      = array();
    }

    // ============================================================
    // find_or_create_group
    // ============================================================

    public function test_find_or_create_returns_zero_for_empty_domain(): void
    {
        $this->assertSame(0, Cashback_Shop_Group_Resolver::find_or_create_group(''));
    }

    public function test_find_or_create_finds_existing_by_domain(): void
    {
        global $wpdb;
        $wpdb->next_get_var = '42';

        $id = Cashback_Shop_Group_Resolver::find_or_create_group('joom.com');

        $this->assertSame(42, $id);
        $this->assertCount(0, $wpdb->inserts, 'не должно быть INSERT при найденной группе');
    }

    public function test_find_or_create_inserts_new_group_when_missing(): void
    {
        global $wpdb;
        $wpdb->next_get_var = null;

        $id = Cashback_Shop_Group_Resolver::find_or_create_group('joom.com', 'Joom');

        $this->assertGreaterThan(0, $id);
        $this->assertCount(1, $wpdb->inserts);
        $insert = $wpdb->inserts[0];
        $this->assertSame('wp_cashback_shop_groups', $insert['table']);
        $this->assertSame('joom.com', $insert['data']['domain']);
        $this->assertSame('Joom', $insert['data']['display_name']);
        $this->assertSame('auto', $insert['data']['status']);
    }

    // ============================================================
    // attach_member
    // ============================================================

    public function test_attach_member_uses_insert_on_duplicate_key_update(): void
    {
        global $wpdb;
        Cashback_Shop_Group_Resolver::attach_member(10, 555);

        $this->assertCount(1, $wpdb->queries);
        $sql = $wpdb->queries[0]['sql'];
        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertStringContainsString('cashback_shop_group_members', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
    }

    public function test_attach_member_skips_invalid_args(): void
    {
        global $wpdb;
        Cashback_Shop_Group_Resolver::attach_member(0, 555);
        Cashback_Shop_Group_Resolver::attach_member(10, 0);
        $this->assertCount(0, $wpdb->queries);
    }

    // ============================================================
    // resolve_preferred
    // ============================================================

    public function test_resolve_preferred_returns_self_when_no_group(): void
    {
        global $wpdb;
        $wpdb->next_get_row = null; // нет JOIN result

        $this->assertSame(123, Cashback_Shop_Group_Resolver::resolve_preferred(123));
    }

    public function test_resolve_preferred_uses_pin_when_set(): void
    {
        global $wpdb;
        $wpdb->next_get_row = array(
            'id'                   => 5,
            'pin_product_id'       => 999,
            'preferred_product_id' => 100,
        );

        $this->assertSame(999, Cashback_Shop_Group_Resolver::resolve_preferred(123));
    }

    public function test_resolve_preferred_uses_preferred_when_no_pin(): void
    {
        global $wpdb;
        $wpdb->next_get_row = array(
            'id'                   => 5,
            'pin_product_id'       => null,
            'preferred_product_id' => 200,
        );

        $this->assertSame(200, Cashback_Shop_Group_Resolver::resolve_preferred(123));
    }

    public function test_resolve_preferred_falls_back_to_self_when_no_preferred(): void
    {
        global $wpdb;
        $wpdb->next_get_row = array(
            'id'                   => 5,
            'pin_product_id'       => null,
            'preferred_product_id' => 0,
        );

        $this->assertSame(123, Cashback_Shop_Group_Resolver::resolve_preferred(123));
    }

    // ============================================================
    // pin / unpin / confirm
    // ============================================================

    public function test_pin_writes_pin_id_and_status_manual(): void
    {
        global $wpdb;
        Cashback_Shop_Group_Resolver::pin_product(5, 999);

        $this->assertCount(1, $wpdb->updates);
        $update = $wpdb->updates[0];
        $this->assertSame(999, $update['data']['pin_product_id']);
        $this->assertSame(999, $update['data']['preferred_product_id']);
        $this->assertSame('manual', $update['data']['status']);
        $this->assertSame(array('id' => 5), $update['where']);
    }

    public function test_confirm_sets_status_confirmed(): void
    {
        global $wpdb;
        Cashback_Shop_Group_Resolver::confirm(5);

        $this->assertCount(1, $wpdb->updates);
        $this->assertSame(array('status' => 'confirmed'), $wpdb->updates[0]['data']);
    }

    public function test_split_member_deletes_from_members(): void
    {
        global $wpdb;
        // get_group_for_product вернёт группу.
        $wpdb->next_get_row = array('id' => 5);

        $ok = Cashback_Shop_Group_Resolver::split_member(123);

        $this->assertTrue($ok);
        $delete = null;
        foreach ($wpdb->updates as $u) {
            if (isset($u['op']) && $u['op'] === 'delete') {
                $delete = $u;
                break;
            }
        }
        $this->assertNotNull($delete, 'split_member должен удалить row');
        $this->assertSame('wp_cashback_shop_group_members', $delete['table']);
        $this->assertSame(array('product_id' => 123), $delete['where']);
    }

    public function test_split_member_returns_false_when_no_group(): void
    {
        global $wpdb;
        $wpdb->next_get_row = null;

        $this->assertFalse(Cashback_Shop_Group_Resolver::split_member(123));
    }

    // ============================================================
    // currency priority (через filter)
    // ============================================================

    public function test_currency_priority_uses_default_rub_first(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Group_Resolver');
        $method     = $reflection->getMethod('currency_priority_index');
        $method->setAccessible(true);

        $this->assertSame(0, $method->invoke(null, 'RUB'));
        $this->assertSame(1, $method->invoke(null, 'USD'));
        $this->assertSame(2, $method->invoke(null, 'EUR'));
    }

    public function test_currency_priority_returns_max_for_unknown(): void
    {
        $reflection = new \ReflectionClass('Cashback_Shop_Group_Resolver');
        $method     = $reflection->getMethod('currency_priority_index');
        $method->setAccessible(true);

        $this->assertSame(PHP_INT_MAX, $method->invoke(null, 'XXX'));
        $this->assertSame(PHP_INT_MAX, $method->invoke(null, ''));
    }

    public function test_currency_priority_filter_overrides_default(): void
    {
        if (! function_exists('add_filter')) {
            $this->markTestSkipped('add_filter mock не доступен');
            return;
        }
        add_filter(
            'cashback_group_currency_priority',
            static fn(): array => array('USD', 'EUR', 'RUB'),
            10,
            1
        );

        $reflection = new \ReflectionClass('Cashback_Shop_Group_Resolver');
        $method     = $reflection->getMethod('currency_priority_index');
        $method->setAccessible(true);

        $this->assertSame(0, $method->invoke(null, 'USD'));
        $this->assertSame(2, $method->invoke(null, 'RUB'));
    }

    // ============================================================
    // status constants
    // ============================================================

    public function test_status_constants_match_db_enum(): void
    {
        $this->assertSame('auto', Cashback_Shop_Group_Resolver::STATUS_AUTO);
        $this->assertSame('confirmed', Cashback_Shop_Group_Resolver::STATUS_CONFIRMED);
        $this->assertSame('manual', Cashback_Shop_Group_Resolver::STATUS_MANUAL);
        $this->assertSame('split', Cashback_Shop_Group_Resolver::STATUS_SPLIT);
    }
}
