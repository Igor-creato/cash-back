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
 *  - flip post_status (active → publish, stopped → draft);
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
        $GLOBALS['_cb_test_postmeta'] = array();
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
    // 2. active → publish (восстановление магазина).
    // ------------------------------------------------------------------

    public function test_active_status_flips_post_to_publish(): void
    {
        $this->make_post(777, 'draft');

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
        $this->assertSame('publish', $GLOBALS['_cb_test_posts'][777]->post_status);
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
