<?php
/**
 * Tests for the price monitor account endpoint.
 *
 * @package Cashback
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Account endpoint registration and rendering tests.
 */
#[Group( 'price-monitor' )]
final class PriceMonitorAccountTest extends TestCase {

	/**
	 * Resolve class path.
	 */
	private function class_path(): string {
		return dirname( __DIR__, 3 ) . '/includes/price-monitor/class-cashback-price-monitor-account.php';
	}

	/**
	 * Reset test globals.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_cb_test_filters']           = array();
		$GLOBALS['_cb_test_enqueued_styles']   = array();
		$GLOBALS['_cb_test_enqueued_scripts']  = array();
		$GLOBALS['_cb_test_localized_scripts'] = array();
		$GLOBALS['_cb_test_is_logged_in']      = true;
		$GLOBALS['_cb_test_is_account_page']   = true;
		$GLOBALS['_cb_test_user_id']           = 77;
		$GLOBALS['wp']                         = (object) array(
			'query_vars' => array(),
		);
	}

	/**
	 * Load the account class.
	 */
	private function load_account(): Cashback_Price_Monitor_Account {
		$path = $this->class_path();

		self::assertFileExists( $path, 'Price monitor account class must exist before the WooCommerce endpoint can be wired.' );

		require_once $path;

		return new Cashback_Price_Monitor_Account();
	}

	/**
	 * Ensure endpoint registration wiring is present.
	 */
	public function test_account_endpoint_registers_query_var_and_menu_item(): void {
		$account = $this->load_account();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local fixture file inside a unit test.
		$source = (string) file_get_contents( $this->class_path() );

		$items   = array(
			'dashboard'       => 'Dashboard',
			'customer-logout' => 'Logout',
		);
		$updated = $account->add_menu_item( $items );
		$vars    = $account->add_query_vars( array( 'dashboard' ) );

		self::assertStringContainsString( 'add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );', $source );
		self::assertContains( 'price-monitor', $vars );
		self::assertSame( 'Мониторинг цен', $updated['price-monitor'] );
		self::assertSame( 'customer-logout', array_key_last( $updated ) );
	}

	/**
	 * Ensure assets only load on the endpoint and include REST config.
	 */
	public function test_assets_enqueue_only_on_price_monitor_endpoint_and_localize_rest_config(): void {
		$account = $this->load_account();

		$account->enqueue_assets();

		self::assertArrayNotHasKey( 'price-monitor-account', $GLOBALS['_cb_test_enqueued_scripts'] );
		self::assertArrayNotHasKey( 'price-monitor-account', $GLOBALS['_cb_test_enqueued_styles'] );

		$GLOBALS['_cb_test_enqueued_styles']   = array();
		$GLOBALS['_cb_test_enqueued_scripts']  = array();
		$GLOBALS['_cb_test_localized_scripts'] = array();
		$GLOBALS['wp']->query_vars             = array( 'price-monitor' => '' );

		$account->enqueue_assets();

		self::assertArrayHasKey( 'price-monitor-account', $GLOBALS['_cb_test_enqueued_scripts'] );
		self::assertArrayHasKey( 'price-monitor-account', $GLOBALS['_cb_test_enqueued_styles'] );
		self::assertSame(
			array( 'cashback-account-base' ),
			$GLOBALS['_cb_test_enqueued_styles']['price-monitor-account']['deps']
		);

		$localized = $GLOBALS['_cb_test_localized_scripts']['price-monitor-account']['CashbackPriceMonitorAccount'] ?? null;

		self::assertIsArray( $localized );
		self::assertSame( 'https://savelloclub.test/wp-json/cashback/v1/price-monitor', $localized['restBase'] );
		self::assertSame( wp_create_nonce( 'wp_rest' ), $localized['nonce'] );
		self::assertTrue( $localized['isLoggedIn'] );
		self::assertSame( 'Мониторинг цен', $localized['i18n']['title'] );
		self::assertSame( 'Магазин не поддерживается', $localized['i18n']['unsupportedStore'] );
		self::assertSame( 'Для данного магазина мониторинг временно недоступен.', $localized['i18n']['monitoringUnavailable'] );
		self::assertSame( 'Товар уже отслеживается', $localized['i18n']['duplicateWatchlistItem'] );
		self::assertSame( 'Достигнут лимит отслеживаемых товаров', $localized['i18n']['limitExceeded'] );
		self::assertArrayNotHasKey( 'price_refresh_interval_hours', $localized );
		self::assertArrayNotHasKey( 'priceRefreshIntervalHours', $localized );
	}

	/**
	 * Ensure the render shell exposes the expected form and container hooks.
	 */
	public function test_render_endpoint_outputs_account_form_shell(): void {
		$account = $this->load_account();

		ob_start();
		$account->render_endpoint();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-price-monitor-account', $html );
		self::assertStringContainsString( 'name="url"', $html );
		self::assertStringContainsString( 'name="target_price_minor"', $html );
		self::assertStringContainsString( 'data-price-monitor-items', $html );
		self::assertStringContainsString( 'Мониторинг цен', $html );
	}
}
