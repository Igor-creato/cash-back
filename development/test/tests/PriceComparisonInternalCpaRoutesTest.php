<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('price-comparison')]
#[Group('internal-rest-api')]
final class PriceComparisonInternalCpaRoutesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		require_once dirname(__DIR__, 3) . '/includes/services/class-internal-hmac-auth-service.php';
		require_once dirname(__DIR__, 3) . '/includes/services/class-cashback-internal-api-service.php';
		require_once dirname(__DIR__, 3) . '/includes/price-comparison/class-cashback-price-comparison-cpa-bridge.php';
		require_once dirname(__DIR__, 3) . '/includes/rest/class-cashback-internal-rest-controller.php';

		$GLOBALS['_cb_test_rest_routes'] = array();
		$GLOBALS['_cb_test_options']     = array();
	}

	public function test_cpa_routes_are_registered_with_internal_hmac_permission_callbacks(): void {
		$controller = new Savello_Cashback_Internal_REST_Controller(
			null,
			null,
			new Price_Comparison_Internal_Cpa_Routes_Fake_Bridge()
		);
		$controller->register_routes();

		foreach (
			array(
				'/price-comparison/cpa/networks',
				'/price-comparison/cpa/feeds',
				'/price-comparison/cpa/feed-content',
				'/price-comparison/cpa/deeplink',
			) as $route
		) {
			$key = 'savello-internal/v1' . $route;
			self::assertArrayHasKey($key, $GLOBALS['_cb_test_rest_routes']);
			self::assertIsCallable($GLOBALS['_cb_test_rest_routes'][ $key ]['args']['callback'] ?? null);
			self::assertIsCallable($GLOBALS['_cb_test_rest_routes'][ $key ]['args']['permission_callback'] ?? null);

			$permission = call_user_func(
				$GLOBALS['_cb_test_rest_routes'][ $key ]['args']['permission_callback'],
				new WP_REST_Request('GET', $key)
			);
			self::assertInstanceOf(WP_Error::class, $permission);
			self::assertContains($permission->get_error_data()['status'] ?? null, array( 401, 403 ));
		}
	}

	public function test_cpa_networks_and_feeds_return_redacted_bridge_payloads(): void {
		$controller = new Savello_Cashback_Internal_REST_Controller(
			null,
			null,
			new Price_Comparison_Internal_Cpa_Routes_Fake_Bridge()
		);

		$networks = $controller->cpa_networks(new WP_REST_Request('GET', '/savello-internal/v1/price-comparison/cpa/networks'));
		self::assertInstanceOf(WP_REST_Response::class, $networks);
		self::assertSame(
			array(
				array(
					'network'                => 'admitad',
					'label'                  => 'Admitad',
					'configured'             => true,
					'capabilities'           => array(
						'product_feeds' => true,
						'deeplink'      => true,
					),
					'credential_health_code' => 'configured',
				),
			),
			$networks->get_data()['items']
		);

		$feeds = $controller->cpa_feeds(new WP_REST_Request('GET', '/savello-internal/v1/price-comparison/cpa/feeds'));
		self::assertInstanceOf(WP_REST_Response::class, $feeds);
		self::assertTrue($feeds->get_data()['items'][0]['feed_url_secret']);

		$json = (string) wp_json_encode(array( $networks->get_data(), $feeds->get_data() ));
		foreach (array( 'unit-secret', 'token=', 'pass=', 'api_key', 'client_secret' ) as $secret_fragment) {
			self::assertStringNotContainsString($secret_fragment, $json);
		}
	}

	public function test_cpa_deeplink_validates_network_and_source_url_before_bridge_success_response(): void {
		$bridge     = new Price_Comparison_Internal_Cpa_Routes_Fake_Bridge();
		$controller = new Savello_Cashback_Internal_REST_Controller(null, null, $bridge);

		$bad_network = $this->json_request(array(
			'network'    => 'unknown',
			'source_url' => 'https://shop.example/product/1',
		));
		$bad_network_result = $controller->cpa_deeplink($bad_network);
		self::assertInstanceOf(WP_Error::class, $bad_network_result);
		self::assertSame(400, $bad_network_result->get_error_data()['status'] ?? null);

		$bad_url = $this->json_request(array(
			'network'    => 'admitad',
			'source_url' => 'javascript:alert(1)',
		));
		$bad_url_result = $controller->cpa_deeplink($bad_url);
		self::assertInstanceOf(WP_Error::class, $bad_url_result);
		self::assertSame(400, $bad_url_result->get_error_data()['status'] ?? null);

		$success = $controller->cpa_deeplink($this->json_request(array(
			'network'    => 'admitad',
			'source_url' => 'https://shop.example/product/1',
			'click_id'   => '<b>click-1</b>',
		)));
		self::assertInstanceOf(WP_REST_Response::class, $success);
		self::assertSame(
			array(
				'status'        => 'ok',
				'network'       => 'admitad',
				'affiliate_url' => 'https://affiliate.example/deeplink',
			),
			$success->get_data()
		);
		self::assertSame('click-1', $bridge->last_payload['click_id'] ?? null);
	}

	public function test_cpa_feed_content_returns_base64_without_exposing_feed_url(): void {
		$bridge     = new Price_Comparison_Internal_Cpa_Routes_Fake_Bridge();
		$controller = new Savello_Cashback_Internal_REST_Controller(null, null, $bridge);

		$response = $controller->cpa_feed_content($this->json_request(array(
			'network'      => 'admitad',
			'store_domain' => 'merchant.test',
			'offer_id'     => 'campaign-10',
			'feed_id'      => 'feed-csv',
		)));

		self::assertInstanceOf(WP_REST_Response::class, $response);
		self::assertSame('ok', $response->get_data()['status']);
		self::assertSame('text/csv', $response->get_data()['content_type']);
		self::assertSame("id;title\n1;Phone\n", base64_decode($response->get_data()['content_base64'], true));
		self::assertSame('feed-csv', $bridge->last_payload['feed_id'] ?? null);

		$json = (string) wp_json_encode($response->get_data());
		self::assertStringNotContainsString('https://feed.example', $json);
		self::assertStringNotContainsString('pass=', $json);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function json_request( array $payload ): WP_REST_Request {
		$request = new WP_REST_Request('POST', '/savello-internal/v1/price-comparison/cpa/deeplink');
		$request->set_body((string) wp_json_encode($payload));

		return $request;
	}
}

final class Price_Comparison_Internal_Cpa_Routes_Fake_Bridge {

	/** @var array<string,mixed> */
	public array $last_payload = array();

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function list_network_statuses(): array {
		return array(
			array(
				'network'                => 'admitad',
				'label'                  => 'Admitad',
				'configured'             => true,
				'capabilities'           => array(
					'product_feeds' => true,
					'deeplink'      => true,
				),
				'credential_health_code' => 'configured',
			),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function list_feed_descriptors(): array {
		return array(
			array(
				'network'         => 'admitad',
				'feed_id'         => 'admitad-products',
				'format'          => 'xml',
				'feed_url_secret' => true,
			),
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function create_deeplink( array $payload ): array {
		$this->last_payload = $payload;

		return array(
			'status'        => 'ok',
			'network'       => (string) $payload['network'],
			'affiliate_url' => 'https://affiliate.example/deeplink',
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function get_feed_content( array $payload ): array {
		$this->last_payload = $payload;

		return array(
			'status'         => 'ok',
			'content_type'   => 'text/csv',
			'content_base64' => base64_encode("id;title\n1;Phone\n"),
		);
	}
}
