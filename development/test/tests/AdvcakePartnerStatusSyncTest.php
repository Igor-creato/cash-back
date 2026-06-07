<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

// Stub WP_Post at file level — PHP запрещает декларации класса внутри методов.
if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_status = '';
        public string $post_type = '';
    }
}

/**
 * Тесты Cashback_Advcake_Partner_Status_Sync::process_batch().
 *
 * Покрытие:
 *  - decode JSON и URL-encoded payload'ов;
 *  - matchинг WC-product через find_product_by_offer;
 *  - stopped → draft; active → API verification, publish only when active && available;
 *  - mark row processing_status=ok / click_not_found / error;
 *  - идемпотентность (повторный запуск не двинет уже помеченные rows);
 *  - graceful skip когда сети нет или колонка event_type отсутствует.
 *
 * @group webhooks
 * @group advcake
 */
#[Group('webhooks')]
#[Group('advcake')]
final class AdvcakePartnerStatusSyncTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        // Минимальные стабы WP-функций, не покрытых bootstrap.php.
        if (!function_exists('get_post')) {
            function get_post( int $post_id ): ?WP_Post
            {
                $store = $GLOBALS['_cb_test_posts'] ?? array();
                if (!isset($store[ $post_id ])) {
                    return null;
                }
                $row              = $store[ $post_id ];
                $post             = new WP_Post();
                $post->ID         = (int) ( $row->ID ?? $post_id );
                $post->post_status = (string) ( $row->post_status ?? '' );
                $post->post_type   = (string) ( $row->post_type ?? 'product' );
                return $post;
            }
        }
        if (!function_exists('wp_update_post')) {
            function wp_update_post( array $postarr, bool $wp_error = false )
            {
                $id = (int) ( $postarr['ID'] ?? 0 );
                if ($id <= 0) {
                    return 0;
                }
                if (!isset($GLOBALS['_cb_test_posts'][ $id ])) {
                    return 0;
                }
                if (isset($postarr['post_status'])) {
                    $GLOBALS['_cb_test_posts'][ $id ]->post_status = (string) $postarr['post_status'];
                }
                return $id;
            }
        }

        $files = array(
            '/includes/shops/class-cashback-shop-importer.php',
            '/includes/shops/class-cashback-product-cpa-status-service.php',
            '/includes/class-cashback-advcake-partner-status-sync.php',
        );
        foreach ($files as $rel) {
            $path = self::$plugin_root . $rel;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options'] = array();
        $GLOBALS['_cb_test_posts']   = array();
        $GLOBALS['_cb_test_post_meta'] = array();
        $GLOBALS['_cb_test_meta']      = array();
        if (class_exists('Cashback_Advcake_Partner_Status_Sync')) {
            Cashback_Advcake_Partner_Status_Sync::set_test_campaign_resolver(null);
        }
    }

    protected function tearDown(): void
    {
        if (class_exists('Cashback_Advcake_Partner_Status_Sync')) {
            Cashback_Advcake_Partner_Status_Sync::set_test_campaign_resolver(null);
        }
        parent::tearDown();
    }

    /**
     * Mock $wpdb: эмулирует cashback_webhooks + cashback_affiliate_networks
     * + postmeta lookup для find_product_by_offer.
     *
     * @param array<int,array{id:int,payload:string,marker:?string}> $rows
     * @param array<int,array{network_id:int,offer_id:string,product_id:int}> $products
     */
    private function make_wpdb_mock(
        int $advcake_network_id,
        array $rows,
        array $products,
        bool $event_type_column_exists = true
    ): object {
        return new class($advcake_network_id, $rows, $products, $event_type_column_exists) {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public string $postmeta;
            public string $posts;

            /** @var array<int,array> */
            public array $update_calls = array();

            public function __construct(
                private int $advcake_network_id,
                public array $rows,
                public array $products,
                private bool $event_type_column_exists
            ) {
                $this->postmeta = 'wp_postmeta';
                $this->posts    = 'wp_posts';
            }

            public function prepare(string $query, mixed ...$args): string
            {
                $this->_last_args = $args;
                $i = 0;
                return preg_replace_callback('/%[sid]/', function ($m) use (&$i, $args) {
                    $val = $args[$i++] ?? '';
                    if ($m[0] === '%s') {
                        return "'" . addslashes((string) $val) . "'";
                    }
                    if ($m[0] === '%i') {
                        return '`' . str_replace('`', '', (string) $val) . '`';
                    }
                    return (string) (int) $val;
                }, $query);
            }

            /** @var array<int,mixed> */
            public array $_last_args = array();

            public function get_var(string $query): mixed
            {
                // v4.3.4: advisory lock around process_batch — stub default возвращает
                // 1 (lock acquired), это пропускает критическую секцию. Чтобы протестировать
                // lock_busy сценарий — конкретный тест может переопределить через
                // $wpdb_mock->force_lock_acquired = 0.
                if (stripos($query, 'GET_LOCK(') !== false || stripos($query, 'RELEASE_LOCK(') !== false) {
                    return property_exists($this, 'force_lock_acquired') ? $this->force_lock_acquired : 1;
                }
                if (strpos($query, 'cashback_affiliate_networks') !== false
                    && strpos($query, 'SELECT id') !== false) {
                    return $this->advcake_network_id;
                }
                if (strpos($query, 'COLUMNS') !== false) {
                    return $this->event_type_column_exists ? 1 : 0;
                }
                // find_product_by_offer: JOIN postmeta x2 → ищем по последним 4 args.
                if (strpos($query, 'JOIN') !== false && strpos($query, 'postmeta') !== false) {
                    // args order: META_NETWORK_ID, $network_id, META_OFFER_ID, $offer_id
                    $args = $this->_last_args;
                    if (count($args) >= 4) {
                        $network_id = (int) $args[1];
                        $offer_id   = (string) $args[3];
                        foreach ($this->products as $p) {
                            if ((int) $p['network_id'] === $network_id && (string) $p['offer_id'] === $offer_id) {
                                return (string) $p['product_id'];
                            }
                        }
                    }
                    return null;
                }
                return null;
            }

            public function get_results(string $query): array
            {
                if (strpos($query, 'cashback_webhooks') !== false
                    && strpos($query, "event_type") !== false) {
                    $out = array();
                    foreach ($this->rows as $row) {
                        if ($row['marker'] === null) {
                            $out[] = (object) array(
                                'id'      => $row['id'],
                                'payload' => $row['payload'],
                            );
                        }
                    }
                    return $out;
                }
                return array();
            }

            public function update(string $table, array $data, array $where, array $df = array(), array $wf = array()): int
            {
                $this->update_calls[] = array(
                    'table' => $table,
                    'data'  => $data,
                    'where' => $where,
                );
                if ($table === 'wp_cashback_webhooks' && isset($where['id'])) {
                    $row_id = (int) $where['id'];
                    foreach ($this->rows as $idx => $row) {
                        if ((int) $row['id'] === $row_id) {
                            $this->rows[ $idx ]['marker'] = $data['processing_status'] ?? 'ok';
                        }
                    }
                }
                return 1;
            }
        };
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function make_post(int $id, string $status): void
    {
        $GLOBALS['_cb_test_posts'][ $id ] = (object) array(
            'ID'          => $id,
            'post_status' => $status,
            'post_type'   => 'product',
        );
    }

    // ------------------------------------------------------------------
    // 1. Happy path: stopped → draft.
    // ------------------------------------------------------------------

    public function test_stopped_status_flips_post_to_draft(): void
    {
        $this->make_post(555, 'publish');

        $rows = array(
            array(
                'id'      => 1,
                'payload' => '{"offer_id":"6","status":"stopped"}',
                'marker'  => null,
            ),
        );
        $products = array(
            array( 'network_id' => 42, 'offer_id' => '6', 'product_id' => 555 ),
        );
        $wpdb     = $this->make_wpdb_mock(42, $rows, $products);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = Cashback_Advcake_Partner_Status_Sync::process_batch();

        $this->assertSame(1, $stats['processed']);
        $this->assertSame(1, $stats['ok']);
        $this->assertSame('draft', $GLOBALS['_cb_test_posts'][555]->post_status);

        // Row помечена ok.
        $this->assertSame('ok', $wpdb->rows[0]['marker']);
    }

    // ------------------------------------------------------------------
    // 2. active-webhook публикует только после подтверждения /offers API.
    // ------------------------------------------------------------------

    public function test_active_status_reactivates_only_when_api_confirms_active_and_available(): void
    {
        $this->make_post(777, 'draft');
        update_post_meta(777, '_cashback_auto_deactivated', '1');
        update_post_meta(777, '_cashback_auto_publish_enabled', '1');
        $resolver_called = false;
        Cashback_Advcake_Partner_Status_Sync::set_test_campaign_resolver(static function () use (&$resolver_called) {
            $resolver_called = true;
            return array(
                'success'  => true,
                'active'   => true,
                'campaign' => array(
                    'id'                => '99',
                    'name'              => 'Advcake Example',
                    'status'            => 'active',
                    'connection_status' => 'available',
                    'is_active'         => true,
                ),
            );
        });

        $rows = array(
            array(
                'id'      => 2,
                'payload' => 'offer_id=99&status=active',
                'marker'  => null,
            ),
        );
        $products = array(
            array( 'network_id' => 42, 'offer_id' => '99', 'product_id' => 777 ),
        );
        $wpdb     = $this->make_wpdb_mock(42, $rows, $products);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = Cashback_Advcake_Partner_Status_Sync::process_batch();

        $this->assertSame(1, $stats['ok']);
        $this->assertTrue($resolver_called);
        $this->assertSame(
            'publish',
            $GLOBALS['_cb_test_posts'][777]->post_status,
            'partner_status=active публикует только после /offers active && available.'
        );
        $this->assertSame('ok', $wpdb->rows[0]['marker']);
    }

    public function test_active_status_stays_draft_when_api_says_unavailable(): void
    {
        $this->make_post(778, 'draft');
        update_post_meta(778, '_cashback_auto_deactivated', '1');
        update_post_meta(778, '_cashback_auto_publish_enabled', '1');
        Cashback_Advcake_Partner_Status_Sync::set_test_campaign_resolver(static function () {
            return array(
                'success'  => true,
                'active'   => false,
                'campaign' => array(
                    'id'                => '100',
                    'name'              => 'Unavailable',
                    'status'            => 'active',
                    'connection_status' => 'unavailable',
                    'is_active'         => false,
                ),
            );
        });

        $rows = array(
            array(
                'id'      => 22,
                'payload' => 'offer_id=100&status=active',
                'marker'  => null,
            ),
        );
        $products = array(
            array( 'network_id' => 42, 'offer_id' => '100', 'product_id' => 778 ),
        );
        $wpdb     = $this->make_wpdb_mock(42, $rows, $products);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = Cashback_Advcake_Partner_Status_Sync::process_batch();

        $this->assertSame(1, $stats['ok']);
        $this->assertSame('draft', $GLOBALS['_cb_test_posts'][778]->post_status);
        $this->assertSame('ok', $wpdb->rows[0]['marker']);
    }

    public function test_active_status_api_error_leaves_row_retryable(): void
    {
        $this->make_post(779, 'draft');
        update_post_meta(779, '_cashback_auto_deactivated', '1');
        update_post_meta(779, '_cashback_auto_publish_enabled', '1');
        Cashback_Advcake_Partner_Status_Sync::set_test_campaign_resolver(static function () {
            return array('success' => false, 'error' => 'HTTP 500');
        });

        $rows = array(
            array(
                'id'      => 23,
                'payload' => 'offer_id=101&status=active',
                'marker'  => null,
            ),
        );
        $products = array(
            array( 'network_id' => 42, 'offer_id' => '101', 'product_id' => 779 ),
        );
        $wpdb     = $this->make_wpdb_mock(42, $rows, $products);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = Cashback_Advcake_Partner_Status_Sync::process_batch();

        $this->assertSame(1, $stats['error']);
        $this->assertNull($wpdb->rows[0]['marker'], 'API error leaves row retryable');
        $this->assertSame('draft', $GLOBALS['_cb_test_posts'][779]->post_status);
    }

    // ------------------------------------------------------------------
    // 3. Продукт не найден — row помечается click_not_found.
    // ------------------------------------------------------------------

    public function test_unknown_offer_marks_row_not_found(): void
    {
        $rows = array(
            array(
                'id'      => 3,
                'payload' => '{"offer_id":"999","status":"stopped"}',
                'marker'  => null,
            ),
        );
        $products = array(); // нет матчей.
        $wpdb     = $this->make_wpdb_mock(42, $rows, $products);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = Cashback_Advcake_Partner_Status_Sync::process_batch();

        $this->assertSame(0, $stats['ok']);
        $this->assertSame(1, $stats['not_found']);
        $this->assertSame('click_not_found', $wpdb->rows[0]['marker']);
    }

    // ------------------------------------------------------------------
    // 4. Невалидный payload (без offer_id) — error.
    // ------------------------------------------------------------------

    public function test_invalid_payload_marks_error(): void
    {
        $rows = array(
            array(
                'id'      => 4,
                'payload' => '{"foo":"bar"}',
                'marker'  => null,
            ),
        );
        $wpdb            = $this->make_wpdb_mock(42, $rows, array());
        $GLOBALS['wpdb'] = $wpdb;

        $stats = Cashback_Advcake_Partner_Status_Sync::process_batch();

        $this->assertSame(1, $stats['error']);
        $this->assertSame('error', $wpdb->rows[0]['marker']);
    }

    // ------------------------------------------------------------------
    // 5. Идемпотентность: уже помеченные rows не возвращаются повторно.
    // ------------------------------------------------------------------

    public function test_already_processed_rows_are_skipped(): void
    {
        $this->make_post(555, 'publish');

        $rows = array(
            array(
                'id'      => 5,
                'payload' => '{"offer_id":"6","status":"stopped"}',
                'marker'  => 'ok', // уже помечена
            ),
        );
        $products = array(
            array( 'network_id' => 42, 'offer_id' => '6', 'product_id' => 555 ),
        );
        $wpdb            = $this->make_wpdb_mock(42, $rows, $products);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = Cashback_Advcake_Partner_Status_Sync::process_batch();

        $this->assertSame(0, $stats['processed']);
        $this->assertSame('publish', $GLOBALS['_cb_test_posts'][555]->post_status, 'post_status не должен меняться повторно');
    }

    // ------------------------------------------------------------------
    // 6. Graceful skip когда seed-row Advcake отсутствует.
    // ------------------------------------------------------------------

    public function test_no_advcake_network_returns_empty_stats(): void
    {
        $wpdb            = $this->make_wpdb_mock(0, array(), array());
        $GLOBALS['wpdb'] = $wpdb;

        $stats = Cashback_Advcake_Partner_Status_Sync::process_batch();
        $this->assertSame(0, $stats['processed']);
    }

    // ------------------------------------------------------------------
    // 7. Graceful skip когда колонка event_type отсутствует (db_version < 14).
    // ------------------------------------------------------------------

    public function test_no_event_type_column_returns_empty_stats(): void
    {
        $wpdb            = $this->make_wpdb_mock(42, array(), array(), event_type_column_exists: false);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = Cashback_Advcake_Partner_Status_Sync::process_batch();
        $this->assertSame(0, $stats['processed']);
    }

    // ------------------------------------------------------------------
    // 8. offer_id с не-цифровым значением — error (защита от alias-injection).
    // ------------------------------------------------------------------

    public function test_non_numeric_offer_id_marks_error(): void
    {
        $rows = array(
            array(
                'id'      => 6,
                'payload' => '{"offer_id":"demo-alias","status":"stopped"}',
                'marker'  => null,
            ),
        );
        $wpdb            = $this->make_wpdb_mock(42, $rows, array());
        $GLOBALS['wpdb'] = $wpdb;

        $stats = Cashback_Advcake_Partner_Status_Sync::process_batch();
        $this->assertSame(1, $stats['error']);
    }
}
