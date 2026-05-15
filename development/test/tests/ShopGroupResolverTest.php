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
        $GLOBALS['_cb_test_options']      = array();
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
        // Pin 999 должен быть в active members — иначе fallback (Codex Round 4).
        $wpdb->next_get_results = array(
            array('product_id' => 100),
            array('product_id' => 999),
            array('product_id' => 123),
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
        // Preferred 200 должен быть в active members.
        $wpdb->next_get_results = array(
            array('product_id' => 123),
            array('product_id' => 200),
        );

        $this->assertSame(200, Cashback_Shop_Group_Resolver::resolve_preferred(123));
    }

    // ============================================================
    // Codex Round 4: pin/preferred validation против active_members.
    // ============================================================

    public function test_resolve_preferred_falls_back_when_pin_is_stale(): void
    {
        global $wpdb;
        $wpdb->next_get_row = array(
            'id'                   => 5,
            'pin_product_id'       => 999, // pin указывает на несуществующего/trashed member
            'preferred_product_id' => 0,
        );
        // 999 НЕ в active_members → pin stale → fallback к pick_fallback_member.
        $wpdb->next_get_results = array(
            array('product_id' => 30),
            array('product_id' => 10),
            array('product_id' => 20),
        );

        // Должен вернуть min(10), а не stale pin 999.
        $this->assertSame(10, Cashback_Shop_Group_Resolver::resolve_preferred(30));
    }

    public function test_resolve_preferred_falls_back_when_preferred_is_stale(): void
    {
        global $wpdb;
        $wpdb->next_get_row = array(
            'id'                   => 5,
            'pin_product_id'       => null,
            'preferred_product_id' => 888, // preferred указывает на trashed member
        );
        // 888 НЕ в active_members → fallback.
        $wpdb->next_get_results = array(
            array('product_id' => 20),
            array('product_id' => 10),
        );

        $this->assertSame(10, Cashback_Shop_Group_Resolver::resolve_preferred(10));
    }

    public function test_resolve_preferred_does_not_auto_clear_stale_pin(): void
    {
        global $wpdb;
        $wpdb->next_get_row = array(
            'id'                   => 5,
            'pin_product_id'       => 999,
            'preferred_product_id' => 0,
        );
        $wpdb->next_get_results = array(
            array('product_id' => 10),
            array('product_id' => 20),
        );

        Cashback_Shop_Group_Resolver::resolve_preferred(10);

        // resolve_preferred НЕ должен делать UPDATE/DELETE — это hot path.
        // Auto-clear pin делается асинхронно через recompute_preferred.
        $this->assertCount(
            0,
            $wpdb->updates,
            'resolve_preferred НЕ должен писать в БД (hot path, write-free)'
        );
    }

    // ============================================================
    // Codex Round 4: sticky last_error reset в backfill cron.
    // ============================================================

    public function test_handle_preferred_backfill_cron_resets_stale_last_error(): void
    {
        // Симулируем сценарий: предыдущий запрос (другой плагин/код) оставил
        // last_error выставленным. Без reset'а текущий handler ложно посчитает
        // get_col() как failed. После Round 4 fix — last_error сбрасывается
        // перед запросом, и handler корректно интерпретирует empty result.
        global $wpdb;
        $wpdb->last_error = 'pre-existing error from another query';

        // Стаб не реализует get_col по умолчанию — добавляем через анонимный
        // потомок, чтобы handler не вышел рано на method_exists check.
        $wpdb_with_get_col = new class extends Shop_Test_Wpdb_Stub {
            public string $captured_last_error_at_query_time = '__not_set__';

            public function get_col(mixed $sql): array
            {
                // Снимок last_error на момент запроса — ожидаем, что cron его сбросил.
                $this->captured_last_error_at_query_time = $this->last_error;
                return array(); // empty result, нет NULL-групп.
            }

            public function get_var(mixed $sql): mixed
            {
                return '0'; // total-COUNT = 0 → finalize → 'done'.
            }
        };
        $wpdb_with_get_col->last_error = 'pre-existing error from another query';
        $GLOBALS['wpdb'] = $wpdb_with_get_col;

        Cashback_Shop_Group_Resolver::handle_preferred_backfill_cron();

        $this->assertSame(
            '',
            $wpdb_with_get_col->captured_last_error_at_query_time,
            'handler должен сбрасывать $wpdb->last_error перед get_col()'
        );

        // Restore default stub для других тестов.
        $GLOBALS['wpdb'] = new Shop_Test_Wpdb_Stub();
    }

    public function test_resolve_preferred_falls_back_to_self_when_group_has_no_active_members(): void
    {
        global $wpdb;
        $wpdb->next_get_row = array(
            'id'                   => 5,
            'pin_product_id'       => null,
            'preferred_product_id' => 0,
        );
        // get_active_members → пустой набор (нечего fallback'ить).
        $wpdb->next_get_results = array();

        $this->assertSame(123, Cashback_Shop_Group_Resolver::resolve_preferred(123));
    }

    public function test_resolve_preferred_uses_fallback_member_when_group_has_no_preferred(): void
    {
        global $wpdb;
        $wpdb->next_get_row = array(
            'id'                   => 5,
            'pin_product_id'       => null,
            'preferred_product_id' => 0,
        );
        // Members группы — должен победить minimum (Codex finding #1: deterministic
        // fallback совпадает с catalog-visibility, чтобы не было split-brain).
        $wpdb->next_get_results = array(
            array('product_id' => 30),
            array('product_id' => 10),
            array('product_id' => 20),
        );

        $this->assertSame(10, Cashback_Shop_Group_Resolver::resolve_preferred(30));
    }

    // ============================================================
    // pick_fallback_member — deterministic anchor для NULL-preferred групп.
    // ============================================================

    public function test_pick_fallback_member_returns_min_product_id(): void
    {
        global $wpdb;
        $wpdb->next_get_results = array(
            array('product_id' => 100),
            array('product_id' => 5),
            array('product_id' => 50),
        );

        $this->assertSame(5, Cashback_Shop_Group_Resolver::pick_fallback_member(7));
    }

    public function test_pick_fallback_member_returns_zero_for_empty_group(): void
    {
        global $wpdb;
        $wpdb->next_get_results = array();

        $this->assertSame(0, Cashback_Shop_Group_Resolver::pick_fallback_member(7));
    }

    public function test_pick_fallback_member_returns_zero_for_invalid_group_id(): void
    {
        $this->assertSame(0, Cashback_Shop_Group_Resolver::pick_fallback_member(0));
        $this->assertSame(0, Cashback_Shop_Group_Resolver::pick_fallback_member(-5));
    }

    // ============================================================
    // finalize_backfill_state — DB failure guard (Codex adversarial #1).
    // ============================================================

    public function test_finalize_backfill_state_does_not_finalize_when_count_query_fails(): void
    {
        // Симулируем DB-failure: get_var вернул null И last_error выставлен.
        // (int) null === 0, без guard'а это писало бы 'done' навсегда.
        global $wpdb;
        $wpdb->next_get_var = null;
        $wpdb->last_error   = 'forced fail by stub';

        $captured = array();
        $GLOBALS['_cb_test_options_writes'] = &$captured;

        if (! function_exists('update_option')) {
            $this->markTestSkipped('update_option mock не доступен');
            return;
        }

        $reflection = new \ReflectionClass('Cashback_Shop_Group_Resolver');
        $method     = $reflection->getMethod('finalize_backfill_state');
        $method->setAccessible(true);
        $method->invoke(null, $wpdb, 'wp_cashback_shop_groups', 'cashback_group_preferred_backfill_v1_cursor');

        // На DB-failure status НЕ должен стать 'done'. Допустим 'scheduled'
        // (retryable) — это та же дисциплина, что в основном handler'е.
        $current = (string) get_option('cashback_group_preferred_backfill_v1', '');
        $this->assertNotSame('done', $current, 'на DB-failure НЕЛЬЗЯ финализировать в done');
    }

    public function test_finalize_backfill_state_writes_done_when_no_remaining_nulls(): void
    {
        global $wpdb;
        $wpdb->next_get_var = '0'; // 0 NULL-групп.
        $wpdb->last_error   = '';

        $reflection = new \ReflectionClass('Cashback_Shop_Group_Resolver');
        $method     = $reflection->getMethod('finalize_backfill_state');
        $method->setAccessible(true);
        $method->invoke(null, $wpdb, 'wp_cashback_shop_groups', 'cashback_group_preferred_backfill_v1_cursor');

        $this->assertSame('done', (string) get_option('cashback_group_preferred_backfill_v1', ''));
    }

    public function test_finalize_backfill_state_writes_partial_when_nulls_remain(): void
    {
        global $wpdb;
        $wpdb->next_get_var = '5'; // 5 unresolvable групп.
        $wpdb->last_error   = '';

        $reflection = new \ReflectionClass('Cashback_Shop_Group_Resolver');
        $method     = $reflection->getMethod('finalize_backfill_state');
        $method->setAccessible(true);
        $method->invoke(null, $wpdb, 'wp_cashback_shop_groups', 'cashback_group_preferred_backfill_v1_cursor');

        $this->assertSame('partial', (string) get_option('cashback_group_preferred_backfill_v1', ''));
    }

    // ============================================================
    // pick_fallback_member — preference for usable cashback (Codex adversarial #2).
    // ============================================================

    public function test_pick_fallback_member_prefers_member_with_manual_override(): void
    {
        global $wpdb;
        $wpdb->next_get_results = array(
            array('product_id' => 100),
            array('product_id' => 5),  // min — но без manual override.
            array('product_id' => 50), // имеет manual override.
        );

        // Member 50 имеет _rate_locked=1 + _manual_advertiser_rate.
        update_post_meta(50, '_rate_locked', '1');
        update_post_meta(50, '_manual_advertiser_rate', '12%');

        // Должен победить 50, а не min (5) — ему есть что показать на витрине.
        $this->assertSame(50, Cashback_Shop_Group_Resolver::pick_fallback_member(7));
    }

    public function test_pick_fallback_member_prefers_legacy_display_value_over_min(): void
    {
        global $wpdb;
        $wpdb->next_get_results = array(
            array('product_id' => 100), // имеет legacy display value.
            array('product_id' => 5),   // min — но пустой.
            array('product_id' => 50),  // пустой.
        );

        update_post_meta(100, '_cashback_display_value', '8%');

        // Manual override отсутствует у всех → второй уровень: legacy display value.
        $this->assertSame(100, Cashback_Shop_Group_Resolver::pick_fallback_member(7));
    }

    public function test_pick_fallback_member_falls_back_to_min_when_no_usable_data(): void
    {
        global $wpdb;
        $wpdb->next_get_results = array(
            array('product_id' => 100),
            array('product_id' => 5),
            array('product_id' => 50),
        );
        // post_meta — пусто, ни manual, ни legacy.

        $this->assertSame(5, Cashback_Shop_Group_Resolver::pick_fallback_member(7));
    }

    public function test_pick_fallback_member_manual_override_requires_both_meta_keys(): void
    {
        global $wpdb;
        $wpdb->next_get_results = array(
            array('product_id' => 100),
            array('product_id' => 50),
        );
        // _rate_locked=1, но _manual_advertiser_rate пуст → не usable manual.
        update_post_meta(50, '_rate_locked', '1');
        // У 100 — legacy display value, tier 2 должен его выбрать.
        update_post_meta(100, '_cashback_display_value', '5%');

        // 50 не победит по tier 1 (override без значения), 100 побеждает по tier 2.
        $this->assertSame(100, Cashback_Shop_Group_Resolver::pick_fallback_member(7));
    }

    // ============================================================
    // Codex Round 5: publishable preference в pick_fallback_member.
    // Регрессия: smaller-id draft member побеждал published sibling →
    // sync_group скрывал published, гость не видел ничего в catalog.
    // ============================================================

    public function test_pick_fallback_member_prefers_publishable_over_draft_with_smaller_id(): void
    {
        $stub = new class extends Shop_Test_Wpdb_Stub {
            public function get_results(mixed $sql, mixed $output = ARRAY_A): mixed
            {
                // Publishable query содержит post_status = 'publish'.
                if (str_contains((string) $sql, "post_status = 'publish'")) {
                    return array(array('product_id' => 100));
                }
                // Active query — fallback tier 4.
                return array(
                    array('product_id' => 99),
                    array('product_id' => 100),
                );
            }
        };
        $GLOBALS['wpdb'] = $stub;

        // 99 (draft) имеет smaller id, но 100 publish → tier 3 publishable wins.
        $this->assertSame(100, Cashback_Shop_Group_Resolver::pick_fallback_member(7));

        $GLOBALS['wpdb'] = new Shop_Test_Wpdb_Stub();
    }

    public function test_pick_fallback_member_falls_back_to_all_active_when_no_publishable(): void
    {
        $stub = new class extends Shop_Test_Wpdb_Stub {
            public function get_results(mixed $sql, mixed $output = ARRAY_A): mixed
            {
                if (str_contains((string) $sql, "post_status = 'publish'")) {
                    return array(); // никого не опубликовано.
                }
                return array(array('product_id' => 99));
            }
        };
        $GLOBALS['wpdb'] = $stub;

        // Publishable пусто → tier 4 fallback на active members.
        $this->assertSame(99, Cashback_Shop_Group_Resolver::pick_fallback_member(7));

        $GLOBALS['wpdb'] = new Shop_Test_Wpdb_Stub();
    }

    public function test_pick_fallback_member_publishable_with_draft_manual_override_chooses_publish(): void
    {
        // Draft с manual override НЕ должен побеждать publish без override.
        // Tier 1 publishable: ни один publish member не имеет manual → пропуск.
        // Tier 3 publishable: единственный publish member.
        $stub = new class extends Shop_Test_Wpdb_Stub {
            public function get_results(mixed $sql, mixed $output = ARRAY_A): mixed
            {
                if (str_contains((string) $sql, "post_status = 'publish'")) {
                    return array(array('product_id' => 100));
                }
                return array(
                    array('product_id' => 99),
                    array('product_id' => 100),
                );
            }
        };
        $GLOBALS['wpdb'] = $stub;
        update_post_meta(99, '_rate_locked', '1');
        update_post_meta(99, '_manual_advertiser_rate', '15%');

        $this->assertSame(100, Cashback_Shop_Group_Resolver::pick_fallback_member(7));

        $GLOBALS['wpdb'] = new Shop_Test_Wpdb_Stub();
    }

    // ============================================================
    // Codex Round 7 (R7-1): resolve_preferred должен валидировать pin/preferred
    // против publishable members (не active). Иначе draft/private member,
    // выигравший scoring в recompute_preferred, делает catalog пустым:
    // sync_group ставит hide_meta на все publish, draft скрывается WC default
    // фильтром post_status='publish' → guest catalog без товаров.
    // ============================================================

    public function test_resolve_preferred_falls_back_when_preferred_is_not_publishable(): void
    {
        $stub = new class extends Shop_Test_Wpdb_Stub {
            public ?array $next_get_row = array(
                'id'                   => 5,
                'pin_product_id'       => null,
                'preferred_product_id' => 200, // draft member выиграл scoring
            );

            public function get_results(mixed $sql, mixed $output = ARRAY_A): mixed
            {
                if (str_contains((string) $sql, "post_status = 'publish'")) {
                    return array(array('product_id' => 100)); // publishable subset.
                }
                return array(
                    array('product_id' => 100),
                    array('product_id' => 200),
                );
            }
        };
        $GLOBALS['wpdb'] = $stub;

        // resolve_preferred(100) с группой где preferred=200 (draft).
        // Должен fall through к pick_fallback_member (publishable preference)
        // → возвращает 100 (publishable), не 200 (draft).
        $this->assertSame(
            100,
            Cashback_Shop_Group_Resolver::resolve_preferred(100),
            'preferred=draft не должен возвращаться напрямую — нужен publishable fallback'
        );

        $GLOBALS['wpdb'] = new Shop_Test_Wpdb_Stub();
    }

    public function test_resolve_preferred_falls_back_when_pin_is_not_publishable(): void
    {
        $stub = new class extends Shop_Test_Wpdb_Stub {
            public ?array $next_get_row = array(
                'id'                   => 5,
                'pin_product_id'       => 200, // pin на draft member
                'preferred_product_id' => null,
            );

            public function get_results(mixed $sql, mixed $output = ARRAY_A): mixed
            {
                if (str_contains((string) $sql, "post_status = 'publish'")) {
                    return array(array('product_id' => 100));
                }
                return array(
                    array('product_id' => 100),
                    array('product_id' => 200),
                );
            }
        };
        $GLOBALS['wpdb'] = $stub;

        // pin=200 указывает на draft → fall through к publishable fallback.
        $this->assertSame(100, Cashback_Shop_Group_Resolver::resolve_preferred(100));

        $GLOBALS['wpdb'] = new Shop_Test_Wpdb_Stub();
    }

    public function test_resolve_preferred_returns_preferred_when_publishable(): void
    {
        $stub = new class extends Shop_Test_Wpdb_Stub {
            public ?array $next_get_row = array(
                'id'                   => 5,
                'pin_product_id'       => null,
                'preferred_product_id' => 200,
            );

            public function get_results(mixed $sql, mixed $output = ARRAY_A): mixed
            {
                if (str_contains((string) $sql, "post_status = 'publish'")) {
                    return array(
                        array('product_id' => 100),
                        array('product_id' => 200),
                    );
                }
                return array(
                    array('product_id' => 100),
                    array('product_id' => 200),
                );
            }
        };
        $GLOBALS['wpdb'] = $stub;

        // preferred=200 publishable → return напрямую (не fallback).
        $this->assertSame(200, Cashback_Shop_Group_Resolver::resolve_preferred(100));

        $GLOBALS['wpdb'] = new Shop_Test_Wpdb_Stub();
    }

    public function test_get_publishable_members_filters_only_publish_status(): void
    {
        // Структурный smoke: метод существует, делает SELECT с post_status='publish'.
        $captured = '';
        $stub = new class($captured) extends Shop_Test_Wpdb_Stub {
            private string $captured = '';

            public function __construct(string &$captured)
            {
                $this->captured = '';
            }

            public function get_results(mixed $sql, mixed $output = ARRAY_A): mixed
            {
                if (! isset($GLOBALS['_test_publishable_sql'])) {
                    $GLOBALS['_test_publishable_sql'] = '';
                }
                $GLOBALS['_test_publishable_sql'] = (string) $sql;
                return array(array('product_id' => 42));
            }
        };
        $GLOBALS['wpdb'] = $stub;
        $GLOBALS['_test_publishable_sql'] = '';

        $members = Cashback_Shop_Group_Resolver::get_publishable_members(7);

        $this->assertContains(42, $members);
        $this->assertStringContainsString(
            "post_status = 'publish'",
            $GLOBALS['_test_publishable_sql'],
            'get_publishable_members должен фильтровать post_status = publish'
        );

        $GLOBALS['wpdb'] = new Shop_Test_Wpdb_Stub();
        unset($GLOBALS['_test_publishable_sql']);
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

    // ============================================================
    // rank_product + is_better — percent ≻ fix (Этап 1 dedup best-rate).
    //
    // Корневой баг: score_product() сравнивал payment_size percent и fix
    // как голые числа (16% против 27000₽ → fix «побеждал»). rank_product
    // разделяет типы; percent-товар всегда бьёт fix-only, зеркаля логику
    // калькулятора отображения (mixed → берётся percent, fix отбрасывается).
    // ============================================================

    /**
     * Подменяет $wpdb на stub, маршрутизирующий get_row/get_results по SQL:
     *  - cashback_shop_groups        → группа из $GLOBALS['_rank_group'];
     *  - cashback_shop_group_members → members из $GLOBALS['_rank_members'];
     *  - cashback_shop_tariffs       → тарифы из $GLOBALS['_rank_tariffs'][offer_id].
     */
    private function installRankStub(): void
    {
        $stub = new class extends Shop_Test_Wpdb_Stub {
            public function get_row(mixed $sql, mixed $output = ARRAY_A): mixed
            {
                if (str_contains((string) $sql, 'cashback_shop_groups')) {
                    return $GLOBALS['_rank_group'] ?? null;
                }
                return null;
            }

            public function get_results(mixed $sql, mixed $output = ARRAY_A): mixed
            {
                $s = (string) $sql;
                if (str_contains($s, 'cashback_shop_group_members')) {
                    return $GLOBALS['_rank_members'] ?? array();
                }
                if (str_contains($s, 'cashback_shop_tariffs')) {
                    foreach (($GLOBALS['_rank_tariffs'] ?? array()) as $oid => $rows) {
                        if (str_contains($s, "offer_id = '" . $oid . "'")) {
                            return $rows;
                        }
                    }
                    return array();
                }
                return array();
            }
        };
        $GLOBALS['wpdb'] = $stub;
    }

    private function tearDownRankStub(): void
    {
        $GLOBALS['wpdb'] = new Shop_Test_Wpdb_Stub();
        unset($GLOBALS['_rank_group'], $GLOBALS['_rank_members'], $GLOBALS['_rank_tariffs']);
    }

    public function test_rank_product_returns_null_when_no_tariffs(): void
    {
        $this->installRankStub();
        update_post_meta(4175, '_affiliate_network_id', '1');
        update_post_meta(4175, '_offer_id', '22367');
        $GLOBALS['_rank_tariffs'] = array(); // get_active → []

        $this->assertNull(Cashback_Shop_Group_Resolver::rank_product(4175));
        $this->tearDownRankStub();
    }

    public function test_rank_product_separates_percent_and_fix(): void
    {
        $this->installRankStub();
        update_post_meta(4296, '_affiliate_network_id', '9');
        update_post_meta(4296, '_offer_id', '389');
        // Реальные данные staging для skillfactory.ru / Advcake (товар 4296).
        $GLOBALS['_rank_tariffs'] = array(
            '389' => array(
                array('tariff_type' => 'fix', 'payment_size' => '27000.0000'),
                array('tariff_type' => 'fix', 'payment_size' => '6000.0000'),
                array('tariff_type' => 'percent', 'payment_size' => '12.0000'),
            ),
        );

        $rank = Cashback_Shop_Group_Resolver::rank_product(4296);

        $this->assertIsArray($rank);
        $this->assertTrue($rank['has_percent']);
        $this->assertSame(12.0, $rank['best_percent']);
        $this->assertSame(27000.0, $rank['best_fix']);
        $this->tearDownRankStub();
    }

    public function test_rank_product_fix_only_has_no_percent(): void
    {
        $this->installRankStub();
        update_post_meta(70, '_affiliate_network_id', '9');
        update_post_meta(70, '_offer_id', 'fixoff');
        $GLOBALS['_rank_tariffs'] = array(
            'fixoff' => array(
                array('tariff_type' => 'fix', 'payment_size' => '500.0000'),
            ),
        );

        $rank = Cashback_Shop_Group_Resolver::rank_product(70);

        $this->assertIsArray($rank);
        $this->assertFalse($rank['has_percent']);
        $this->assertSame(0.0, $rank['best_percent']);
        $this->assertSame(500.0, $rank['best_fix']);
        $this->tearDownRankStub();
    }

    public function test_recompute_preferred_percent_beats_fix_skillfactory(): void
    {
        // Регресс-сценарий staging: skillfactory.ru
        //   4175 Admitad  — percent 16% (best_percent=16)
        //   4296 Advcake  — fix 27000 + percent 12% (best_percent=12)
        // Старый score_product выбирал 4296 (27000 > 16). Должен 4175.
        $this->installRankStub();
        $GLOBALS['_rank_group']   = array('id' => 1155, 'pin_product_id' => null, 'preferred_product_id' => null);
        $GLOBALS['_rank_members'] = array(
            array('product_id' => 4175),
            array('product_id' => 4296),
        );
        update_post_meta(4175, '_affiliate_network_id', '1');
        update_post_meta(4175, '_offer_id', '22367');
        update_post_meta(4296, '_affiliate_network_id', '9');
        update_post_meta(4296, '_offer_id', '389');
        $GLOBALS['_rank_tariffs'] = array(
            '22367' => array(
                array('tariff_type' => 'percent', 'payment_size' => '16.0000'),
                array('tariff_type' => 'percent', 'payment_size' => '16.0000'),
            ),
            '389' => array(
                array('tariff_type' => 'fix', 'payment_size' => '27000.0000'),
                array('tariff_type' => 'fix', 'payment_size' => '6000.0000'),
                array('tariff_type' => 'percent', 'payment_size' => '12.0000'),
            ),
        );

        $best = Cashback_Shop_Group_Resolver::recompute_preferred(1155);

        $this->assertSame(4175, $best, 'percent 16% должен победить percent 12% (а не fix 27000)');
        $this->tearDownRankStub();
    }

    public function test_recompute_preferred_percent_only_beats_fix_only(): void
    {
        $this->installRankStub();
        $GLOBALS['_rank_group']   = array('id' => 7, 'pin_product_id' => null, 'preferred_product_id' => null);
        $GLOBALS['_rank_members'] = array(
            array('product_id' => 11),
            array('product_id' => 22),
        );
        update_post_meta(11, '_affiliate_network_id', '1');
        update_post_meta(11, '_offer_id', 'pct');
        update_post_meta(22, '_affiliate_network_id', '9');
        update_post_meta(22, '_offer_id', 'fx');
        $GLOBALS['_rank_tariffs'] = array(
            'pct' => array(array('tariff_type' => 'percent', 'payment_size' => '5.0000')),
            'fx'  => array(array('tariff_type' => 'fix', 'payment_size' => '99999.0000')),
        );

        $best = Cashback_Shop_Group_Resolver::recompute_preferred(7);

        $this->assertSame(11, $best, 'любой percent бьёт fix-only, даже 5% против 99999₽');
        $this->tearDownRankStub();
    }

    public function test_recompute_preferred_percent_vs_percent_higher_wins(): void
    {
        // gb.ru регресс-guard: percent 15.52 vs percent 12 → больший.
        $this->installRankStub();
        $GLOBALS['_rank_group']   = array('id' => 301, 'pin_product_id' => null, 'preferred_product_id' => null);
        $GLOBALS['_rank_members'] = array(
            array('product_id' => 4121),
            array('product_id' => 4286),
        );
        update_post_meta(4121, '_affiliate_network_id', '1');
        update_post_meta(4121, '_offer_id', 'gb1');
        update_post_meta(4286, '_affiliate_network_id', '9');
        update_post_meta(4286, '_offer_id', 'gb9');
        $GLOBALS['_rank_tariffs'] = array(
            'gb1' => array(array('tariff_type' => 'percent', 'payment_size' => '15.5200')),
            'gb9' => array(array('tariff_type' => 'percent', 'payment_size' => '12.0000')),
        );

        $this->assertSame(4121, Cashback_Shop_Group_Resolver::recompute_preferred(301));
        $this->tearDownRankStub();
    }

    public function test_recompute_preferred_fix_vs_fix_currency_tiebreak(): void
    {
        // Оба fix-only, равный payment_size → tie-break по валюте (RUB ≻ USD).
        $this->installRankStub();
        $GLOBALS['_rank_group']   = array('id' => 8, 'pin_product_id' => null, 'preferred_product_id' => null);
        $GLOBALS['_rank_members'] = array(
            array('product_id' => 31),
            array('product_id' => 32),
        );
        update_post_meta(31, '_affiliate_network_id', '1');
        update_post_meta(31, '_offer_id', 'r');
        update_post_meta(31, '_cashback_campaign_currency', 'USD');
        update_post_meta(32, '_affiliate_network_id', '9');
        update_post_meta(32, '_offer_id', 's');
        update_post_meta(32, '_cashback_campaign_currency', 'RUB');
        $GLOBALS['_rank_tariffs'] = array(
            'r' => array(array('tariff_type' => 'fix', 'payment_size' => '100.0000')),
            's' => array(array('tariff_type' => 'fix', 'payment_size' => '100.0000')),
        );

        $this->assertSame(32, Cashback_Shop_Group_Resolver::recompute_preferred(8), 'RUB бьёт USD при равном fix');
        $this->tearDownRankStub();
    }

    public function test_recompute_preferred_exact_tie_is_deterministic_min_id(): void
    {
        // Codex HIGH-1: при полном равенстве (та же ставка, та же валюта)
        // победитель не должен зависеть от порядка строк get_active_members
        // (в draft-модели флип = флаппинг post_status). Детерминированно —
        // меньший product_id.
        $this->installRankStub();
        $GLOBALS['_rank_group'] = array('id' => 60, 'pin_product_id' => null, 'preferred_product_id' => null);
        // Stub отдаёт в порядке убывания — победить всё равно должен 5000.
        $GLOBALS['_rank_members'] = array(
            array('product_id' => 5001),
            array('product_id' => 5000),
        );
        update_post_meta(5001, '_affiliate_network_id', '1');
        update_post_meta(5001, '_offer_id', 'a');
        update_post_meta(5000, '_affiliate_network_id', '9');
        update_post_meta(5000, '_offer_id', 'b');
        $GLOBALS['_rank_tariffs'] = array(
            'a' => array(array('tariff_type' => 'percent', 'payment_size' => '10.0000')),
            'b' => array(array('tariff_type' => 'percent', 'payment_size' => '10.0000')),
        );

        $first  = Cashback_Shop_Group_Resolver::recompute_preferred(60);
        $second = Cashback_Shop_Group_Resolver::recompute_preferred(60);

        $this->assertSame(5000, $first, 'при полном равенстве побеждает меньший product_id');
        $this->assertSame($first, $second, 'результат стабилен между вызовами');
        $this->tearDownRankStub();
    }

    public function test_recompute_preferred_skips_members_without_tariffs(): void
    {
        $this->installRankStub();
        $GLOBALS['_rank_group']   = array('id' => 9, 'pin_product_id' => null, 'preferred_product_id' => null);
        $GLOBALS['_rank_members'] = array(
            array('product_id' => 41), // нет тарифов → rank null → пропуск
            array('product_id' => 42),
        );
        update_post_meta(41, '_affiliate_network_id', '1');
        update_post_meta(41, '_offer_id', 'empty');
        update_post_meta(42, '_affiliate_network_id', '9');
        update_post_meta(42, '_offer_id', 'has');
        $GLOBALS['_rank_tariffs'] = array(
            'has' => array(array('tariff_type' => 'percent', 'payment_size' => '3.0000')),
        );

        $this->assertSame(42, Cashback_Shop_Group_Resolver::recompute_preferred(9));
        $this->tearDownRankStub();
    }
}
