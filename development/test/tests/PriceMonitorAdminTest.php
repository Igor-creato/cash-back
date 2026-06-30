<?php
/**
 * Tests for the price monitor admin page.
 *
 * @package Cashback
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

// phpcs:ignoreFile PSR1.Files.SideEffects.FoundWithSymbols
if ( ! function_exists( 'add_submenu_page' ) ) {
	/**
	 * Test stub for add_submenu_page().
	 *
	 * @param string   $parent_slug Parent menu slug.
	 * @param string   $page_title  Page title.
	 * @param string   $menu_title  Menu title.
	 * @param string   $capability  Required capability.
	 * @param string   $menu_slug   Menu slug.
	 * @param callable $callback    Page renderer.
	 * @return string
	 */
	function add_submenu_page(
		string $parent_slug,
		string $page_title,
		string $menu_title,
		string $capability,
		string $menu_slug,
		callable $callback
	): string {
		$GLOBALS['_cb_test_submenu_pages'][] = array(
			'parent_slug' => $parent_slug,
			'page_title'  => $page_title,
			'menu_title'  => $menu_title,
			'capability'  => $capability,
			'menu_slug'   => $menu_slug,
			'callback'    => $callback,
		);

		return 'cashback-overview_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	/**
	 * Test stub for check_admin_referer().
	 *
	 * @param string $action    Nonce action.
	 * @param string $query_arg Query argument name.
	 * @return bool
	 */
	function check_admin_referer( string $action = '', string $query_arg = '_wpnonce' ): bool {
		$GLOBALS['_cb_test_admin_nonce_checks'][] = array(
			'action'    => $action,
			'query_arg' => $query_arg,
		);

		return (bool) ( $GLOBALS['_cb_test_admin_nonce_result'] ?? true );
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	/**
	 * Test stub for wp_safe_redirect().
	 *
	 * @param string $location Redirect location.
	 * @param int    $status   HTTP status.
	 * @param string $x_redirect_by Redirect by marker.
	 * @return bool
	 */
	function wp_safe_redirect( string $location, int $status = 302, string $x_redirect_by = 'WordPress' ): bool {
		$GLOBALS['_cb_test_safe_redirects'][] = array(
			'location'      => $location,
			'status'        => $status,
			'x_redirect_by' => $x_redirect_by,
		);

		return true;
	}
}

/**
 * Admin settings page tests.
 */
#[Group( 'price-monitor' )]
final class PriceMonitorAdminTest extends TestCase {

	/**
	 * Resolve class path.
	 */
	private function class_path(): string {
		return dirname( __DIR__, 3 ) . '/admin/class-cashback-price-monitor-admin.php';
	}

	/**
	 * Reset globals for each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_cb_test_options']            = array();
		$GLOBALS['_cb_test_current_user_can']   = true;
		$GLOBALS['_cb_test_submenu_pages']      = array();
		$GLOBALS['_cb_test_admin_nonce_checks'] = array();
		$GLOBALS['_cb_test_admin_nonce_result'] = true;
		$GLOBALS['_cb_test_safe_redirects']     = array();
		$_POST                                  = array();
	}

	/**
	 * Load the admin class.
	 *
	 * @param object $client Spy client.
	 */
	private function load_admin( object $client ): Cashback_Price_Monitor_Admin {
		$path = $this->class_path();

		self::assertFileExists( $path, 'Price monitor admin class must exist before settings UI can be registered.' );

		require_once dirname( __DIR__, 3 ) . '/includes/price-monitor/class-cashback-price-monitor-client.php';
		require_once $path;

		return new Cashback_Price_Monitor_Admin( $client );
	}

	/**
	 * Load a test admin that captures redirects instead of exiting.
	 *
	 * @param object $client Spy client.
	 */
	private function load_redirecting_admin( object $client ): Cashback_Price_Monitor_Admin {
		$path = $this->class_path();

		self::assertFileExists( $path, 'Price monitor admin class must exist before settings UI can be registered.' );

		require_once dirname( __DIR__, 3 ) . '/includes/price-monitor/class-cashback-price-monitor-client.php';
		require_once $path;

		return new class( $client ) extends Cashback_Price_Monitor_Admin {
			/**
			 * Captured redirects.
			 *
			 * @var array<int,array<string,string>>
			 */
			public array $redirects = array();

			/**
			 * Capture redirect query args for assertions.
			 *
			 * @param array<string,string> $query_args Redirect query args.
			 */
			protected function redirect_to_admin_page( array $query_args ): void {
				$this->redirects[] = $query_args;
			}
		};
	}

	/**
	 * Build a spy backend client.
	 *
	 * @param array $calls Recorded backend calls.
	 * @return object
	 */
	private function spy_client( array &$calls ): object {
		return new class( $calls ) {
			/**
			 * Recorded backend calls.
			 *
			 * @var array
			 */
			public array $calls;

			/**
			 * Store the calls array by reference.
			 *
			 * @param array $calls Recorded backend calls.
			 */
			public function __construct( array &$calls ) {
				$this->calls = &$calls;
			}

			/**
			 * Record a backend request and return fixture data.
			 *
			 * @param string      $method          HTTP method.
			 * @param string      $path            Backend path.
			 * @param array       $payload         Request payload.
			 * @param string|null $idempotency_key Optional idempotency key.
			 * @return array
			 */
			public function request( string $method, string $path, array $payload = array(), ?string $idempotency_key = null ): array {
				$this->calls[] = compact( 'method', 'path', 'payload', 'idempotency_key' );

				if ( 'GET' === $method && '/api/v1/admin/settings' === $path ) {
					return array(
						'settings' => array(
							'max_tracked_products_per_user' => 25,
						),
					);
				}

				if ( 'GET' === $method && '/api/v1/admin/sources' === $path ) {
					return array( 'sources' => array() );
				}

				return array(
					'settings' => $payload,
					'sources'  => array( $payload ),
				);
			}
		};
	}

	/**
	 * Ensure the submenu is attached to the existing cashback overview menu.
	 */
	public function test_admin_submenu_is_registered_under_cashback_overview(): void {
		$calls = array();
		$admin = $this->load_admin( $this->spy_client( $calls ) );

		$admin->register_menu();

		self::assertCount( 1, $GLOBALS['_cb_test_submenu_pages'] );
		self::assertSame( 'cashback-overview', $GLOBALS['_cb_test_submenu_pages'][0]['parent_slug'] );
		self::assertSame( 'Мониторинг цен', $GLOBALS['_cb_test_submenu_pages'][0]['menu_title'] );
		self::assertSame( 'manage_options', $GLOBALS['_cb_test_submenu_pages'][0]['capability'] );
	}

	/**
	 * Ensure settings saves require manage_options.
	 */
	public function test_save_settings_requires_manage_options(): void {
		$calls                                = array();
		$admin                                = $this->load_admin( $this->spy_client( $calls ) );
		$GLOBALS['_cb_test_current_user_can'] = false;
		$_POST                                = array(
			'_wpnonce'                      => wp_create_nonce( 'cashback_price_monitor_save_settings' ),
			'backend_url'                   => 'https://backend.example',
			'backend_secret'                => 'secret-value',
			'enabled'                       => '1',
			'max_tracked_products_per_user' => '25',
		);

		try {
			$admin->handle_save_settings();
			self::fail( 'Saving settings without manage_options should stop the request.' );
		} catch ( Cashback_Test_Halt_Signal $signal ) {
			self::assertStringContainsString( 'Недостаточно прав', $signal->getMessage() );
		}

		self::assertSame( array(), $GLOBALS['_cb_test_options'] );
		self::assertSame( array(), $calls );
	}

	/**
	 * Ensure settings saves fail when the nonce is invalid.
	 */
	public function test_save_settings_rejects_invalid_nonce(): void {
		$calls                                  = array();
		$admin                                  = $this->load_admin( $this->spy_client( $calls ) );
		$GLOBALS['_cb_test_admin_nonce_result'] = false;
		$_POST                                  = array(
			'_wpnonce'                      => 'bad-nonce',
			'backend_url'                   => 'https://backend.example',
			'backend_secret'                => 'secret-value',
			'enabled'                       => '1',
			'max_tracked_products_per_user' => '25',
		);

		try {
			$admin->handle_save_settings();
			self::fail( 'Saving settings with an invalid nonce should stop the request.' );
		} catch ( Cashback_Test_Halt_Signal $signal ) {
			self::assertStringContainsString( 'Неверный nonce', $signal->getMessage() );
		}

		self::assertSame( array(), $GLOBALS['_cb_test_options'] );
		self::assertSame( array(), $calls );
	}

	/**
	 * Ensure secret values are stored but not rendered back to the page.
	 */
	public function test_settings_backend_url_is_sanitized_and_secret_is_redacted_in_rendered_html(): void {
		$calls = array();
		$admin = $this->load_admin( $this->spy_client( $calls ) );

		$_POST = array(
			'_wpnonce'                      => wp_create_nonce( 'cashback_price_monitor_save_settings' ),
			'backend_url'                   => 'javascript:alert(1)',
			'backend_secret'                => 'top-secret',
			'enabled'                       => '1',
			'max_tracked_products_per_user' => '25',
		);

		$admin->handle_save_settings();

		self::assertSame( '', get_option( Cashback_Price_Monitor_Client::OPTION_BACKEND_URL, 'fallback' ) );
		self::assertSame( 'top-secret', get_option( Cashback_Price_Monitor_Client::OPTION_SECRET, '' ) );
		self::assertSame( 1, get_option( Cashback_Price_Monitor_Client::OPTION_ENABLED, 0 ) );
		self::assertSame( 25, get_option( Cashback_Price_Monitor_Admin::OPTION_USER_LIMIT, 0 ) );
		self::assertCount( 1, $calls );
		self::assertSame( 'PATCH', $calls[0]['method'] );
		self::assertSame( '/api/v1/admin/settings', $calls[0]['path'] );
		self::assertSame( array( 'max_tracked_products_per_user' => 25 ), $calls[0]['payload'] );

		ob_start();
		$admin->render_page();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '[redacted]', $html );
		self::assertStringNotContainsString( 'top-secret', $html );
	}

	/**
	 * Ensure an empty secret field preserves the saved backend secret.
	 */
	public function test_blank_backend_secret_preserves_existing_secret_option(): void {
		$calls = array();
		$admin = $this->load_admin( $this->spy_client( $calls ) );

		update_option( Cashback_Price_Monitor_Client::OPTION_SECRET, 'persist-me', false );

		$_POST = array(
			'_wpnonce'                      => wp_create_nonce( 'cashback_price_monitor_save_settings' ),
			'backend_url'                   => 'https://backend.example',
			'backend_secret'                => '   ',
			'enabled'                       => '1',
			'max_tracked_products_per_user' => '15',
		);

		$payload = $admin->handle_save_settings();

		self::assertSame( 'persist-me', get_option( Cashback_Price_Monitor_Client::OPTION_SECRET, '' ) );
		self::assertSame( 'persist-me', $payload['backend_secret'] );
		self::assertCount( 1, $calls );
		self::assertSame( array( 'max_tracked_products_per_user' => 15 ), $calls[0]['payload'] );
	}

	/**
	 * Ensure source payloads are normalized before backend submission.
	 */
	public function test_source_payload_is_sanitized_before_client_request(): void {
		$calls = array();
		$admin = $this->load_admin( $this->spy_client( $calls ) );

		$_POST = array(
			'_wpnonce'                 => wp_create_nonce( 'cashback_price_monitor_save_source' ),
			'source_domain'            => ' HTTPS://Shop.Example.com/path ',
			'display_name'             => ' Example Store ',
			'logo_url'                 => ' https://example.com/logo.png ',
			'status'                   => 'ACTIVE',
			'fetch_interval_hours'     => '0',
			'history_retention_days'   => '999',
			'browser_fallback_allowed' => '1',
			'proxy_pool_id'            => ' pool-1 ',
		);

		$admin->handle_save_source();

		self::assertCount( 1, $calls );
		self::assertSame( 'POST', $calls[0]['method'] );
		self::assertSame( '/api/v1/admin/sources', $calls[0]['path'] );
		self::assertSame(
			array(
				'source_domain'            => 'shop.example.com',
				'display_name'             => 'Example Store',
				'logo_url'                 => 'https://example.com/logo.png',
				'status'                   => 'active',
				'fetch_interval_hours'     => 1,
				'history_retention_days'   => 365,
				'browser_fallback_allowed' => true,
				'proxy_pool_id'            => 'pool-1',
			),
			$calls[0]['payload']
		);
	}

	/**
	 * Ensure the admin-post settings handler redirects back to the settings page.
	 */
	public function test_save_settings_request_redirects_back_to_price_monitor_page_with_success_flag(): void {
		$calls = array();
		$admin = $this->load_redirecting_admin( $this->spy_client( $calls ) );

		$_POST = array(
			'_wpnonce'                      => wp_create_nonce( 'cashback_price_monitor_save_settings' ),
			'backend_url'                   => 'https://backend.example',
			'backend_secret'                => 'top-secret',
			'enabled'                       => '1',
			'max_tracked_products_per_user' => '25',
		);

		$admin->handle_save_settings_request();

		self::assertSame(
			array(
				array(
					'page'    => Cashback_Price_Monitor_Admin::PAGE_SLUG,
					'status'  => 'success',
					'message' => 'settings_saved',
				),
			),
			$admin->redirects
		);
	}
}
