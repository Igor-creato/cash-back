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
