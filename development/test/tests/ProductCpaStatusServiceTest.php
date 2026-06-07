<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Общий helper для авто-deactivate/reactivate CPA-магазинов.
 *
 * @group shops
 * @group campaign-status
 */
#[Group('shops')]
#[Group('campaign-status')]
final class ProductCpaStatusServiceTest extends TestCase {
	private static string $plugin_root;

	public static function setUpBeforeClass(): void {
		self::$plugin_root = dirname(__DIR__, 3);
		$path              = self::$plugin_root . '/includes/shops/class-cashback-product-cpa-status-service.php';
		if (file_exists($path)) {
			require_once $path;
		}
	}

	protected function setUp(): void {
		$GLOBALS['_cb_test_posts']     = array();
		$GLOBALS['_cb_test_post_meta'] = array();
		$GLOBALS['_cb_test_meta']      = array();
	}

	private function make_post(int $product_id, string $status): void {
		$post              = new WP_Post();
		$post->ID          = $product_id;
		$post->post_status = $status;
		$post->post_type   = 'product';

		$GLOBALS['_cb_test_posts'][ $product_id ] = $post;
	}

	public function test_deactivate_product_sets_draft_and_canonical_markers(): void {
		self::assertTrue(class_exists('Cashback_Product_Cpa_Status_Service'));
		$this->make_post(101, 'publish');

		$result = Cashback_Product_Cpa_Status_Service::deactivate_product(
			101,
			'admitad',
			'2381',
			'Кампания отключена Admitad',
			'admitad_program_status'
		);

		$this->assertTrue($result);
		$this->assertSame('draft', $GLOBALS['_cb_test_posts'][101]->post_status);
		$this->assertSame('1', (string) get_post_meta(101, '_cashback_auto_deactivated', true));
		$this->assertSame('Кампания отключена Admitad', (string) get_post_meta(101, '_cashback_deactivation_reason', true));
		$this->assertNotSame('', (string) get_post_meta(101, '_cashback_deactivated_at', true));
		$this->assertSame('admitad', (string) get_post_meta(101, '_cashback_deactivated_network', true));
		$this->assertSame('admitad_program_status', (string) get_post_meta(101, '_cashback_deactivated_source', true));
	}

	public function test_reactivate_product_requires_autopublish_enabled(): void {
		self::assertTrue(class_exists('Cashback_Product_Cpa_Status_Service'));
		$this->make_post(102, 'draft');
		update_post_meta(102, '_cashback_auto_deactivated', '1');
		update_post_meta(102, '_cashback_deactivation_reason', 'was off');
		update_post_meta(102, '_cashback_deactivated_at', '2026-06-03 00:00:00');
		update_post_meta(102, '_cashback_deactivated_network', 'admitad');

		$result = Cashback_Product_Cpa_Status_Service::reactivate_product_if_autopublish_enabled(
			102,
			'admitad',
			'2381',
			'Example campaign'
		);

		$this->assertFalse($result);
		$this->assertSame('draft', $GLOBALS['_cb_test_posts'][102]->post_status);
		$this->assertSame('1', (string) get_post_meta(102, '_cashback_auto_deactivated', true));

		update_post_meta(102, '_cashback_auto_publish_enabled', '1');

		$result = Cashback_Product_Cpa_Status_Service::reactivate_product_if_autopublish_enabled(
			102,
			'admitad',
			'2381',
			'Example campaign'
		);

		$this->assertTrue($result);
		$this->assertSame('publish', $GLOBALS['_cb_test_posts'][102]->post_status);
		$this->assertSame('', (string) get_post_meta(102, '_cashback_auto_deactivated', true));
		$this->assertSame('', (string) get_post_meta(102, '_cashback_deactivation_reason', true));
		$this->assertSame('', (string) get_post_meta(102, '_cashback_deactivated_at', true));
		$this->assertSame('', (string) get_post_meta(102, '_cashback_deactivated_network', true));
	}

	public function test_clear_deactivation_markers_removes_canonical_and_legacy_source_markers(): void {
		self::assertTrue(class_exists('Cashback_Product_Cpa_Status_Service'));
		$this->make_post(103, 'publish');
		update_post_meta(103, '_cashback_auto_deactivated', '1');
		update_post_meta(103, '_cashback_deactivation_reason', 'reason');
		update_post_meta(103, '_cashback_deactivated_at', '2026-06-03 00:00:00');
		update_post_meta(103, '_cashback_deactivated_network', 'advcake');
		update_post_meta(103, '_cashback_deactivated_source', 'advcake_partner_status');
		update_post_meta(103, '_cashback_auto_deactivated_at', 'legacy');
		update_post_meta(103, '_cashback_auto_deactivated_source', 'legacy');

		Cashback_Product_Cpa_Status_Service::clear_deactivation_markers(103);

		$this->assertSame('', (string) get_post_meta(103, '_cashback_auto_deactivated', true));
		$this->assertSame('', (string) get_post_meta(103, '_cashback_deactivated_source', true));
		$this->assertSame('', (string) get_post_meta(103, '_cashback_auto_deactivated_at', true));
		$this->assertSame('', (string) get_post_meta(103, '_cashback_auto_deactivated_source', true));
	}
}
