<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Admitad program_status / partnership_status postback sync.
 *
 * @group admitad
 * @group webhooks
 */
#[Group('admitad')]
#[Group('webhooks')]
final class AdmitadStatusPostbackSyncTest extends TestCase {
	private static string $plugin_root;

	public static function setUpBeforeClass(): void {
		self::$plugin_root = dirname(__DIR__, 3);
		foreach (array(
			'/includes/shops/class-cashback-shop-importer.php',
			'/includes/shops/class-cashback-product-cpa-status-service.php',
			'/includes/class-cashback-admitad-status-postback-sync.php',
		) as $rel) {
			$path = self::$plugin_root . $rel;
			if (file_exists($path)) {
				require_once $path;
			}
		}
	}

	protected function setUp(): void {
		$GLOBALS['_cb_test_posts']     = array();
		$GLOBALS['_cb_test_post_meta'] = array();
		$GLOBALS['_cb_test_meta']      = array();
		if (class_exists('Cashback_Admitad_Status_Postback_Sync')
			&& method_exists('Cashback_Admitad_Status_Postback_Sync', 'set_test_campaign_resolver')
		) {
			Cashback_Admitad_Status_Postback_Sync::set_test_campaign_resolver(null);
		}
	}

	private function register_post(int $id, string $status): void {
		$post              = new WP_Post();
		$post->ID          = $id;
		$post->post_status = $status;
		$post->post_type   = 'product';
		$GLOBALS['_cb_test_posts'][ $id ] = $post;
	}

	/**
	 * @param array<int,array{id:int,payload:string,event_type:string,marker:?string}> $rows
	 * @param array<int,array{network_id:int,offer_id:string,product_id:int}> $products
	 */
	private function wpdb_mock(array $rows, array $products, int $network_id = 77): object {
		return new class($rows, $products, $network_id) {
			public string $prefix = 'wp_';
			public string $postmeta = 'wp_postmeta';
			public string $posts = 'wp_posts';
			public array $_last_args = array();
			public array $updates = array();

			public function __construct(
				public array $rows,
				private array $products,
				private int $network_id
			) {}

			public function prepare(string $query, mixed ...$args): string {
				$this->_last_args = $args;
				$i                = 0;
				return preg_replace_callback('/%[sid]/', function ($m) use (&$i, $args) {
					$val = $args[ $i++ ] ?? '';
					if ($m[0] === '%s') {
						return "'" . addslashes((string) $val) . "'";
					}
					if ($m[0] === '%i') {
						return '`' . str_replace('`', '', (string) $val) . '`';
					}
					return (string) (int) $val;
				}, $query);
			}

			public function get_var(string $query): mixed {
				if (stripos($query, 'GET_LOCK(') !== false || stripos($query, 'RELEASE_LOCK(') !== false) {
					return 1;
				}
				if (strpos($query, 'cashback_affiliate_networks') !== false) {
					return $this->network_id;
				}
				if (strpos($query, 'COLUMNS') !== false) {
					return 1;
				}
				if (strpos($query, 'JOIN') !== false && strpos($query, 'postmeta') !== false) {
					$args       = $this->_last_args;
					$network_id = (int) ($args[1] ?? 0);
					$offer_id   = (string) ($args[3] ?? '');
					foreach ($this->products as $p) {
						if ((int) $p['network_id'] === $network_id && (string) $p['offer_id'] === $offer_id) {
							return (string) $p['product_id'];
						}
					}
				}
				return null;
			}

			public function get_results(string $query): array {
				if (strpos($query, 'cashback_webhooks') === false) {
					return array();
				}
				$out = array();
				foreach ($this->rows as $row) {
					if ($row['marker'] !== null) {
						continue;
					}
					$out[] = (object) array(
						'id'         => $row['id'],
						'payload'    => $row['payload'],
						'event_type' => $row['event_type'],
					);
				}
				return $out;
			}

			public function update(string $table, array $data, array $where, mixed $format = null, mixed $where_format = null): int {
				$this->updates[] = compact('table', 'data', 'where');
				foreach ($this->rows as $idx => $row) {
					if ((int) $row['id'] === (int) ($where['id'] ?? 0)) {
						$this->rows[ $idx ]['marker'] = $data['processing_status'] ?? 'ok';
					}
				}
				return 1;
			}
		};
	}

	public function test_program_status_disabled_deactivates_product(): void {
		self::assertTrue(class_exists('Cashback_Admitad_Status_Postback_Sync'));
		$this->register_post(501, 'publish');
		$GLOBALS['wpdb'] = $this->wpdb_mock(
			array(array('id' => 1, 'payload' => 'offer_id=2381&offer_status=disabled', 'event_type' => 'program_status', 'marker' => null)),
			array(array('network_id' => 77, 'offer_id' => '2381', 'product_id' => 501))
		);

		$stats = Cashback_Admitad_Status_Postback_Sync::process_batch();

		$this->assertSame(1, $stats['ok']);
		$this->assertSame('draft', $GLOBALS['_cb_test_posts'][501]->post_status);
		$this->assertSame('disabled', (string) get_post_meta(501, '_cashback_admitad_program_status', true));
		$this->assertSame('admitad', (string) get_post_meta(501, '_cashback_deactivated_network', true));
	}

	public function test_any_negative_program_status_deactivates_product(): void {
		self::assertTrue(class_exists('Cashback_Admitad_Status_Postback_Sync'));

		foreach (array('denied', 'disabled', 'dead') as $idx => $status) {
			$product_id = 520 + $idx;
			$row_id     = 20 + $idx;
			$this->register_post($product_id, 'publish');
			$GLOBALS['wpdb'] = $this->wpdb_mock(
				array(array('id' => $row_id, 'payload' => 'offer_id=2381&offer_status=' . $status, 'event_type' => 'program_status', 'marker' => null)),
				array(array('network_id' => 77, 'offer_id' => '2381', 'product_id' => $product_id))
			);

			$stats = Cashback_Admitad_Status_Postback_Sync::process_batch();

			$this->assertSame(1, $stats['ok'], 'program_status=' . $status);
			$this->assertSame('draft', $GLOBALS['_cb_test_posts'][ $product_id ]->post_status, 'program_status=' . $status);
			$this->assertSame($status, (string) get_post_meta($product_id, '_cashback_admitad_program_status', true));
		}
	}

	public function test_partnership_denied_deactivates_product(): void {
		self::assertTrue(class_exists('Cashback_Admitad_Status_Postback_Sync'));
		$this->register_post(502, 'publish');
		$GLOBALS['wpdb'] = $this->wpdb_mock(
			array(array('id' => 2, 'payload' => 'offer_id=2381&partnership_status=denied', 'event_type' => 'partnership_status', 'marker' => null)),
			array(array('network_id' => 77, 'offer_id' => '2381', 'product_id' => 502))
		);

		$stats = Cashback_Admitad_Status_Postback_Sync::process_batch();

		$this->assertSame(1, $stats['ok']);
		$this->assertSame('draft', $GLOBALS['_cb_test_posts'][502]->post_status);
		$this->assertSame('denied', (string) get_post_meta(502, '_cashback_admitad_partnership_status', true));
	}

	public function test_positive_event_does_not_publish_when_other_signal_is_denied(): void {
		self::assertTrue(class_exists('Cashback_Admitad_Status_Postback_Sync'));
		$this->register_post(503, 'draft');
		update_post_meta(503, '_cashback_auto_deactivated', '1');
		update_post_meta(503, '_cashback_auto_publish_enabled', '1');
		update_post_meta(503, '_cashback_admitad_partnership_status', 'denied');

		$GLOBALS['wpdb'] = $this->wpdb_mock(
			array(array('id' => 3, 'payload' => 'offer_id=2381&offer_status=active', 'event_type' => 'program_status', 'marker' => null)),
			array(array('network_id' => 77, 'offer_id' => '2381', 'product_id' => 503))
		);

		$stats = Cashback_Admitad_Status_Postback_Sync::process_batch();

		$this->assertSame(1, $stats['ok']);
		$this->assertSame('draft', $GLOBALS['_cb_test_posts'][503]->post_status);
	}

	public function test_accepted_partnership_does_not_publish_when_program_is_disabled(): void {
		self::assertTrue(class_exists('Cashback_Admitad_Status_Postback_Sync'));
		$this->register_post(509, 'draft');
		update_post_meta(509, '_cashback_auto_deactivated', '1');
		update_post_meta(509, '_cashback_auto_publish_enabled', '1');
		update_post_meta(509, '_cashback_admitad_program_status', 'disabled');

		$GLOBALS['wpdb'] = $this->wpdb_mock(
			array(array('id' => 13, 'payload' => 'offer_id=2381&partnership_status=accepted', 'event_type' => 'partnership_status', 'marker' => null)),
			array(array('network_id' => 77, 'offer_id' => '2381', 'product_id' => 509))
		);

		$stats = Cashback_Admitad_Status_Postback_Sync::process_batch();

		$this->assertSame(1, $stats['ok']);
		$this->assertSame('draft', $GLOBALS['_cb_test_posts'][509]->post_status);
		$this->assertSame('accepted', (string) get_post_meta(509, '_cashback_admitad_partnership_status', true));
	}

	public function test_positive_signals_reactivate_only_when_autopublish_enabled(): void {
		self::assertTrue(class_exists('Cashback_Admitad_Status_Postback_Sync'));
		$this->register_post(504, 'draft');
		update_post_meta(504, '_cashback_auto_deactivated', '1');
		update_post_meta(504, '_cashback_admitad_program_status', 'active');

		$GLOBALS['wpdb'] = $this->wpdb_mock(
			array(array('id' => 4, 'payload' => 'offer_id=2381&partnership_status=accepted', 'event_type' => 'partnership_status', 'marker' => null)),
			array(array('network_id' => 77, 'offer_id' => '2381', 'product_id' => 504))
		);

		$stats = Cashback_Admitad_Status_Postback_Sync::process_batch();
		$this->assertSame(1, $stats['ok']);
		$this->assertSame('draft', $GLOBALS['_cb_test_posts'][504]->post_status);

		$GLOBALS['wpdb']->rows[0]['marker'] = null;
		update_post_meta(504, '_cashback_auto_publish_enabled', '1');

		$stats = Cashback_Admitad_Status_Postback_Sync::process_batch();
		$this->assertSame(1, $stats['ok']);
		$this->assertSame('publish', $GLOBALS['_cb_test_posts'][504]->post_status);
	}

	public function test_positive_event_with_missing_other_signal_retries_on_api_error(): void {
		self::assertTrue(class_exists('Cashback_Admitad_Status_Postback_Sync'));
		$this->register_post(505, 'draft');
		update_post_meta(505, '_cashback_auto_deactivated', '1');
		update_post_meta(505, '_cashback_auto_publish_enabled', '1');
		Cashback_Admitad_Status_Postback_Sync::set_test_campaign_resolver(static function () {
			return array('success' => false, 'error' => 'HTTP 500');
		});

		$GLOBALS['wpdb'] = $this->wpdb_mock(
			array(array('id' => 5, 'payload' => 'offer_id=2381&offer_status=active', 'event_type' => 'program_status', 'marker' => null)),
			array(array('network_id' => 77, 'offer_id' => '2381', 'product_id' => 505))
		);

		$stats = Cashback_Admitad_Status_Postback_Sync::process_batch();

		$this->assertSame(1, $stats['error']);
		$this->assertNull($GLOBALS['wpdb']->rows[0]['marker'], 'API error leaves row retryable');
		$this->assertSame('draft', $GLOBALS['_cb_test_posts'][505]->post_status);
	}

	public function test_positive_event_with_missing_other_signal_uses_api_to_reactivate(): void {
		self::assertTrue(class_exists('Cashback_Admitad_Status_Postback_Sync'));
		$this->register_post(506, 'draft');
		update_post_meta(506, '_cashback_auto_deactivated', '1');
		update_post_meta(506, '_cashback_auto_publish_enabled', '1');
		Cashback_Admitad_Status_Postback_Sync::set_test_campaign_resolver(static function () {
			return array(
				'success'  => true,
				'campaign' => array(
					'id'                => '2381',
					'name'              => 'Example',
					'status'            => 'active',
					'connection_status' => 'active',
				),
			);
		});

		$GLOBALS['wpdb'] = $this->wpdb_mock(
			array(array('id' => 6, 'payload' => 'offer_id=2381&offer_status=active', 'event_type' => 'program_status', 'marker' => null)),
			array(array('network_id' => 77, 'offer_id' => '2381', 'product_id' => 506))
		);

		$stats = Cashback_Admitad_Status_Postback_Sync::process_batch();

		$this->assertSame(1, $stats['ok']);
		$this->assertSame('publish', $GLOBALS['_cb_test_posts'][506]->post_status);
		$this->assertSame('active', (string) get_post_meta(506, '_cashback_admitad_program_status', true));
		$this->assertSame('accepted', (string) get_post_meta(506, '_cashback_admitad_partnership_status', true));
	}

	public function test_repeated_program_status_transitions_are_processed_in_order(): void {
		self::assertTrue(class_exists('Cashback_Admitad_Status_Postback_Sync'));
		$this->register_post(507, 'publish');
		update_post_meta(507, '_cashback_auto_deactivated', '1');
		update_post_meta(507, '_cashback_auto_publish_enabled', '1');
		update_post_meta(507, '_cashback_admitad_partnership_status', 'accepted');

		$GLOBALS['wpdb'] = $this->wpdb_mock(
			array(
				array('id' => 7, 'payload' => 'offer_id=2381&offer_status=disabled', 'event_type' => 'program_status', 'marker' => null),
				array('id' => 8, 'payload' => 'offer_id=2381&offer_status=active', 'event_type' => 'program_status', 'marker' => null),
				array('id' => 9, 'payload' => 'offer_id=2381&offer_status=disabled', 'event_type' => 'program_status', 'marker' => null),
				array('id' => 10, 'payload' => 'offer_id=2381&offer_status=active', 'event_type' => 'program_status', 'marker' => null),
			),
			array(array('network_id' => 77, 'offer_id' => '2381', 'product_id' => 507))
		);

		$stats = Cashback_Admitad_Status_Postback_Sync::process_batch();

		$this->assertSame(4, $stats['ok']);
		$this->assertSame('publish', $GLOBALS['_cb_test_posts'][507]->post_status);
		$this->assertSame('active', (string) get_post_meta(507, '_cashback_admitad_program_status', true));
		foreach ($GLOBALS['wpdb']->rows as $row) {
			$this->assertSame('ok', $row['marker']);
		}
	}

	public function test_immediate_duplicate_same_status_is_harmless_noop(): void {
		self::assertTrue(class_exists('Cashback_Admitad_Status_Postback_Sync'));
		$this->register_post(508, 'publish');

		$GLOBALS['wpdb'] = $this->wpdb_mock(
			array(
				array('id' => 11, 'payload' => 'offer_id=2381&offer_status=disabled', 'event_type' => 'program_status', 'marker' => null),
				array('id' => 12, 'payload' => 'offer_id=2381&offer_status=disabled', 'event_type' => 'program_status', 'marker' => null),
			),
			array(array('network_id' => 77, 'offer_id' => '2381', 'product_id' => 508))
		);

		$stats = Cashback_Admitad_Status_Postback_Sync::process_batch();

		$this->assertSame(2, $stats['ok']);
		$this->assertSame('draft', $GLOBALS['_cb_test_posts'][508]->post_status);
		$this->assertSame('disabled', (string) get_post_meta(508, '_cashback_admitad_program_status', true));
		foreach ($GLOBALS['wpdb']->rows as $row) {
			$this->assertSame('ok', $row['marker']);
		}
	}
}
