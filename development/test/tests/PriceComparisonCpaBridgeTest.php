<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('price-comparison')]
final class PriceComparisonCpaBridgeTest extends TestCase {

	public static function setUpBeforeClass(): void {
		$file = dirname(__DIR__, 3) . '/includes/price-comparison/class-cashback-price-comparison-cpa-bridge.php';
		if (file_exists($file)) {
			require_once $file;
		}
	}

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_cb_test_http_calls']             = array();
		$GLOBALS['_cb_test_http_response_callback'] = null;
		$GLOBALS['_cb_test_http_response']          = array(
			'body'     => '',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'headers'  => array(),
		);
	}

	public function test_network_statuses_use_existing_credentials_without_leaking_secret_material(): void {
		self::assertTrue(
			class_exists('Cashback_Price_Comparison_CPA_Bridge'),
			'CPA bridge class should be loadable.'
		);

		$client = new Price_Comparison_Cpa_Bridge_Fake_Api_Client(
			array(
				'admitad' => array(
					'id'   => 11,
					'name' => 'Admitad',
				),
				'advcake' => array(
					'id'   => 22,
					'name' => 'AdvCake',
				),
			),
			array(
				11 => array(
					'client_id'     => 'unit-admitad-client-id',
					'client_secret' => 'unit-admitad-client-secret',
				),
				22 => array(
					'api_key' => 'unit-advcake-api-key',
				),
			)
		);

		$bridge   = new Cashback_Price_Comparison_CPA_Bridge($client);
		$statuses = $this->index_by_network($bridge->list_network_statuses());

		self::assertTrue($statuses['admitad']['configured']);
		self::assertSame('configured', $statuses['admitad']['credential_health_code']);
		self::assertSame(
			array(
				'product_feeds' => true,
				'deeplink'      => true,
			),
			$statuses['admitad']['capabilities']
		);

		self::assertTrue($statuses['advcake']['configured']);
		self::assertSame('configured', $statuses['advcake']['credential_health_code']);
		self::assertSame(
			array(
				'product_feeds' => true,
				'deeplink'      => true,
			),
			$statuses['advcake']['capabilities']
		);

		self::assertSame(array(11, 22), $client->credential_reads);

		$json = (string) wp_json_encode($statuses);
		foreach (
			array(
				'api_key',
				'client_secret',
				'client_id',
				'password',
				'token',
				'unit-admitad-client-id',
				'unit-admitad-client-secret',
				'unit-advcake-api-key',
			) as $secret_fragment
		) {
			self::assertStringNotContainsString($secret_fragment, $json);
		}
	}

	public function test_advcake_feed_descriptors_are_discovered_from_common_feeds_api_without_leaking_url_or_token(): void {
		$GLOBALS['_cb_test_http_response'] = array(
			'body'     => (string) wp_json_encode(array(
				'total' => 1,
				'feeds' => array(
					array(
						'feed_id'        => 56,
						'format'         => 'yml',
						'offer'          => 'merchant.example',
						'title'          => 'Common product feed',
						'url'            => 'https://feeds.example/catalog.yml?pass=feed-secret',
						'offer_id'       => 240,
						'products_count' => 1234,
						'last_update'    => '2026-07-04 09:00:00',
					),
				),
			)),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'headers'  => array( 'content-type' => 'application/json' ),
		);

		$bridge      = new Cashback_Price_Comparison_CPA_Bridge($this->fake_advcake_client());
		$descriptors = $bridge->list_feed_descriptors();
		$advcake     = $this->only_advcake_available_descriptors($descriptors);

		self::assertCount(1, $advcake);
		self::assertSame('56', $advcake[0]['feed_id']);
		self::assertSame('Common product feed', $advcake[0]['name']);
		self::assertSame('yml', $advcake[0]['format']);
		self::assertSame('merchant.example', $advcake[0]['store_domain']);
		self::assertSame('merchant.example', $advcake[0]['store_name']);
		self::assertTrue($advcake[0]['available']);
		self::assertSame('configured', $advcake[0]['feed_health_code']);

		self::assertCount(1, $GLOBALS['_cb_test_http_calls']);
		$request_url = (string) $GLOBALS['_cb_test_http_calls'][0]['url'];
		self::assertStringStartsWith('https://api.advcake.com/common-feeds?', $request_url);
		self::assertStringContainsString('pass=unit-advcake-api-key', $request_url);

		$json = (string) wp_json_encode($descriptors);
		foreach (array( 'unit-advcake-api-key', 'https://feeds.example', 'feed-secret', 'pass=' ) as $secret_fragment) {
			self::assertStringNotContainsString($secret_fragment, $json);
		}
	}

	public function test_advcake_feed_content_downloads_discovered_feed_without_returning_feed_url(): void {
		$GLOBALS['_cb_test_http_response_callback'] = static function ( string $url, array $args ): array {
			unset($args);
			if (str_contains($url, 'common-feeds')) {
				return array(
					'body'     => (string) wp_json_encode(array(
						'feeds' => array(
							array(
								'feed_id' => 56,
								'format'  => 'yml',
								'title'   => 'Common product feed',
								'url'     => 'https://feeds.example/catalog.yml?pass=feed-secret',
							),
						),
					)),
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'headers'  => array( 'content-type' => 'application/json' ),
				);
			}

			if ($url === 'https://feeds.example/catalog.yml?pass=feed-secret') {
				return array(
					'body'     => '<yml_catalog><shop><offers></offers></shop></yml_catalog>',
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'headers'  => array( 'content-type' => 'application/xml' ),
				);
			}

			return array(
				'body'     => '',
				'response' => array( 'code' => 404, 'message' => 'Not Found' ),
				'headers'  => array(),
			);
		};

		$bridge = new Cashback_Price_Comparison_CPA_Bridge($this->fake_advcake_client());
		$result = $bridge->get_feed_content(array(
			'network' => 'advcake',
			'feed_id' => '56',
		));

		self::assertIsArray($result);
		self::assertSame('ok', $result['status']);
		self::assertSame('application/xml', $result['content_type']);
		self::assertSame(
			'<yml_catalog><shop><offers></offers></shop></yml_catalog>',
			base64_decode((string) $result['content_base64'], true)
		);
		self::assertCount(2, $GLOBALS['_cb_test_http_calls']);

		$json = (string) wp_json_encode($result);
		self::assertStringNotContainsString('https://feeds.example', $json);
		self::assertStringNotContainsString('feed-secret', $json);
		self::assertStringNotContainsString('pass=', $json);
	}

	public function test_missing_credentials_return_non_secret_health_codes(): void {
		self::assertTrue(
			class_exists('Cashback_Price_Comparison_CPA_Bridge'),
			'CPA bridge class should be loadable.'
		);

		$client = new Price_Comparison_Cpa_Bridge_Fake_Api_Client(
			array(
				'admitad' => array(
					'id'   => 33,
					'name' => 'Admitad',
				),
				'advcake' => array(
					'id'   => 44,
					'name' => 'AdvCake',
				),
			),
			array(
				33 => array(
					'client_id' => 'unit-admitad-client-id',
				),
				44 => null,
			)
		);

		$bridge = new Cashback_Price_Comparison_CPA_Bridge($client);

		$admitad = $bridge->network_status('admitad');
		self::assertFalse($admitad['configured']);
		self::assertSame('incomplete_credentials', $admitad['credential_health_code']);

		$advcake = $bridge->network_status('advcake');
		self::assertFalse($advcake['configured']);
		self::assertSame('missing_credentials', $advcake['credential_health_code']);

		$unknown = $bridge->network_status('unknown-network');
		self::assertFalse($unknown['configured']);
		self::assertSame('unsupported_network', $unknown['credential_health_code']);

		$json = (string) wp_json_encode(array( $admitad, $advcake, $unknown ));
		self::assertStringNotContainsString('unit-admitad-client-id', $json);
		self::assertStringNotContainsString('client_id', $json);
	}

	/**
	 * @param array<int,array<string,mixed>> $statuses
	 * @return array<string,array<string,mixed>>
	 */
	private function index_by_network( array $statuses ): array {
		$indexed = array();
		foreach ($statuses as $status) {
			$indexed[(string) $status['network']] = $status;
		}
		return $indexed;
	}

	private function fake_advcake_client(): Price_Comparison_Cpa_Bridge_Fake_Api_Client {
		return new Price_Comparison_Cpa_Bridge_Fake_Api_Client(
			array(
				'admitad' => null,
				'advcake' => array(
					'id'   => 22,
					'name' => 'AdvCake',
				),
			),
			array(
				22 => array(
					'api_key' => 'unit-advcake-api-key',
				),
			)
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $descriptors
	 * @return array<int,array<string,mixed>>
	 */
	private function only_advcake_available_descriptors( array $descriptors ): array {
		return array_values(array_filter(
			$descriptors,
			static fn( array $descriptor ): bool => ( $descriptor['network'] ?? '' ) === 'advcake'
				&& !empty($descriptor['available'])
		));
	}
}

final class Price_Comparison_Cpa_Bridge_Fake_Api_Client {

	/** @var array<string,array<string,mixed>|null> */
	private array $configs;

	/** @var array<int,array<string,mixed>|null> */
	private array $credentials;

	/** @var array<int,int> */
	public array $credential_reads = array();

	/**
	 * @param array<string,array<string,mixed>|null> $configs
	 * @param array<int,array<string,mixed>|null>    $credentials
	 */
	public function __construct( array $configs, array $credentials ) {
		$this->configs     = $configs;
		$this->credentials = $credentials;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get_network_config( string $slug ): ?array {
		return $this->configs[ $slug ] ?? null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get_credentials( int $network_id ): ?array {
		$this->credential_reads[] = $network_id;
		return $this->credentials[ $network_id ] ?? null;
	}
}
