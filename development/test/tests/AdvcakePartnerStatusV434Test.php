<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты v4.3.4 hardening для Cashback_Advcake_Partner_Status_Sync.
 *
 * Закрывает audit-findings:
 *   - P-2: is_active=0 gate для kill-switch'а
 *   - C-1: advisory lock против concurrent process_batch
 *   - C-1 sub: _cashback_auto_deactivated meta-coordination
 *
 * @group advcake
 * @group concurrency
 * @group v4-3-4
 */
#[Group('advcake')]
#[Group('concurrency')]
#[Group('v4-3-4')]
final class AdvcakePartnerStatusV434Test extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        if (!class_exists('Cashback_Shop_Importer')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-shop-importer.php';
        }
        if (!class_exists('Cashback_Product_Cpa_Status_Service')) {
            require_once self::$plugin_root . '/includes/shops/class-cashback-product-cpa-status-service.php';
        }
        if (!class_exists('Cashback_Advcake_Partner_Status_Sync')) {
            require_once self::$plugin_root . '/includes/class-cashback-advcake-partner-status-sync.php';
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_posts']     = array();
        $GLOBALS['_cb_test_post_meta'] = array();
    }

    /**
     * Создаёт WP_Post stub и регистрирует его в bootstrap'ом mocked get_post().
     * Возвращает product_id.
     */
    private function register_post(string $status): int
    {
        static $next_id = 9000;
        $id   = ++$next_id;
        $post = new WP_Post();
        $post->ID          = $id;
        $post->post_status = $status;
        $post->post_type   = 'product';
        $GLOBALS['_cb_test_posts'][ $id ] = $post;
        return $id;
    }

    /**
     * @param array{advcake_id?:int,is_active?:bool,rows?:array,products?:array,event_type?:bool,lock?:int} $opts
     */
    private function wpdb_mock(array $opts): object
    {
        return new class(
            $opts['advcake_id'] ?? 9,
            $opts['is_active']  ?? true,
            $opts['rows']       ?? array(),
            $opts['products']   ?? array(),
            $opts['event_type'] ?? true,
            $opts['lock']       ?? 1
        ) {
            public string $prefix = 'wp_';
            public string $postmeta = 'wp_postmeta';
            public string $posts = 'wp_posts';
            public array $updates = array();
            public array $mark_calls = array();
            public array $_last_args = array();

            public function __construct(
                private int $advcake_id,
                private bool $is_active,
                private array $rows,
                private array $products,
                private bool $event_type,
                private int $lock
            ) {}

            public function prepare(string $query, mixed ...$args): string
            {
                $this->_last_args = $args;
                $i = 0;
                return preg_replace_callback('/%[sid]/', function ($m) use (&$i, $args) {
                    $val = $args[$i++] ?? '';
                    if ($m[0] === '%s') return "'" . addslashes((string) $val) . "'";
                    if ($m[0] === '%i') return '`' . str_replace('`', '', (string) $val) . '`';
                    return (string) (int) $val;
                }, $query);
            }

            public function get_var(string $query): mixed
            {
                if (stripos($query, 'GET_LOCK(') !== false || stripos($query, 'RELEASE_LOCK(') !== false) {
                    return $this->lock;
                }
                if (strpos($query, 'cashback_affiliate_networks') !== false) {
                    if (!$this->is_active) {
                        return 0;
                    }
                    return $this->advcake_id;
                }
                if (strpos($query, 'COLUMNS') !== false) {
                    return $this->event_type ? 1 : 0;
                }
                if (strpos($query, 'JOIN') !== false && strpos($query, 'postmeta') !== false) {
                    $args = $this->_last_args;
                    $offer_id = (string) ( $args[3] ?? '' );
                    foreach ($this->products as $p) {
                        if ((string) $p['offer_id'] === $offer_id) {
                            return (string) $p['product_id'];
                        }
                    }
                    return null;
                }
                return null;
            }

            public function get_results(string $query): array
            {
                $out = array();
                foreach ($this->rows as $row) {
                    $out[] = (object) array( 'id' => $row['id'], 'payload' => $row['payload'] );
                }
                return $out;
            }

            public function update(string $table, array $data, array $where, mixed $format = null, mixed $where_format = null): int
            {
                $this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
                return 1;
            }

            public function query(string $sql): mixed { return 1; }
        };
    }

    public function test_p2_is_active_zero_skips_processing(): void
    {
        $product_id = $this->register_post('publish');
        $GLOBALS['wpdb'] = $this->wpdb_mock(array(
            'is_active' => false,  // kill-switch
            'rows'      => array(
                array( 'id' => 1, 'payload' => 'offer_id=403&status=stopped' ),
            ),
            'products'  => array(
                array( 'offer_id' => '403', 'product_id' => $product_id ),
            ),
        ));

        $stats = Cashback_Advcake_Partner_Status_Sync::process_batch();

        $this->assertSame(0, $stats['processed'], 'is_active=0 → no rows processed');
        $this->assertSame('publish', $GLOBALS['_cb_test_posts'][ $product_id ]->post_status, 'post_status НЕ изменён (kill-switch активен)');
    }

    public function test_c1_lock_busy_returns_zero_stats(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb_mock(array(
            'lock' => 0,  // GET_LOCK returns 0 → concurrent run skip
            'rows' => array(
                array( 'id' => 1, 'payload' => 'offer_id=403&status=stopped' ),
            ),
        ));

        $stats = Cashback_Advcake_Partner_Status_Sync::process_batch();

        $this->assertSame(0, $stats['processed']);
        $this->assertSame(0, $stats['ok']);
        $this->assertSame(0, $stats['error']);
    }

    public function test_meta_set_on_draft_flip(): void
    {
        $product_id = $this->register_post('publish');
        $GLOBALS['wpdb'] = $this->wpdb_mock(array(
            'rows'     => array( array( 'id' => 11, 'payload' => 'offer_id=403&status=stopped' ) ),
            'products' => array( array( 'offer_id' => '403', 'product_id' => $product_id ) ),
        ));

        Cashback_Advcake_Partner_Status_Sync::process_batch();

        $this->assertSame('draft', $GLOBALS['_cb_test_posts'][ $product_id ]->post_status);
        $this->assertSame('1', (string) get_post_meta($product_id, '_cashback_auto_deactivated', true));
        $this->assertSame('advcake', (string) get_post_meta($product_id, '_cashback_deactivated_network', true));
        $this->assertSame('advcake_partner_status', (string) get_post_meta($product_id, '_cashback_deactivated_source', true));
        $this->assertNotEmpty((string) get_post_meta($product_id, '_cashback_deactivated_at', true));
        $this->assertSame('', (string) get_post_meta($product_id, '_cashback_auto_deactivated_source', true));
        $this->assertSame('', (string) get_post_meta($product_id, '_cashback_auto_deactivated_at', true));
    }

    public function test_active_postback_does_not_publish_or_clear_meta(): void
    {
        $product_id = $this->register_post('draft');
        update_post_meta($product_id, '_cashback_auto_deactivated', '1');
        update_post_meta($product_id, '_cashback_deactivated_source', 'check_campaign_statuses');

        $GLOBALS['wpdb'] = $this->wpdb_mock(array(
            'rows'     => array( array( 'id' => 12, 'payload' => 'offer_id=403&status=active' ) ),
            'products' => array( array( 'offer_id' => '403', 'product_id' => $product_id ) ),
        ));

        $stats = Cashback_Advcake_Partner_Status_Sync::process_batch();

        $this->assertSame(1, $stats['ok']);
        $this->assertSame('draft', $GLOBALS['_cb_test_posts'][ $product_id ]->post_status);
        $this->assertSame('1', (string) get_post_meta($product_id, '_cashback_auto_deactivated', true));
        $this->assertSame('check_campaign_statuses', (string) get_post_meta($product_id, '_cashback_deactivated_source', true));
    }

    public function test_same_status_no_meta_change(): void
    {
        $product_id = $this->register_post('publish');
        update_post_meta($product_id, '_cashback_auto_deactivated', 'admin_set_marker');

        $GLOBALS['wpdb'] = $this->wpdb_mock(array(
            'rows'     => array( array( 'id' => 13, 'payload' => 'offer_id=403&status=active' ) ),
            'products' => array( array( 'offer_id' => '403', 'product_id' => $product_id ) ),
        ));

        Cashback_Advcake_Partner_Status_Sync::process_batch();

        // post_status уже publish → flip-блок не выполняется, meta остаётся как было.
        $this->assertSame('admin_set_marker', (string) get_post_meta($product_id, '_cashback_auto_deactivated', true));
    }
}
