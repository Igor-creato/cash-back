<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

final class Cashback_Price_Comparison_CPA_Bridge {

	private const NETWORKS = array(
		'admitad' => array(
			'label'                => 'Admitad',
			'required_credentials' => array( 'client_id', 'client_secret' ),
			'capabilities'         => array(
				'product_feeds' => true,
				'deeplink'      => true,
			),
		),
		'advcake' => array(
			'label'                => 'AdvCake',
			'required_credentials' => array( 'api_key' ),
			'capabilities'         => array(
				'product_feeds' => true,
				'deeplink'      => true,
			),
		),
	);

	private ?object $api_client;
	/** @var array<string,object> */
	private array $adapters;

	/**
	 * @param array<string,object> $adapters
	 */
	public function __construct( ?object $api_client = null, array $adapters = array() ) {
		if ($api_client === null && class_exists('Cashback_API_Client') && method_exists('Cashback_API_Client', 'get_instance')) {
			$api_client = Cashback_API_Client::get_instance();
		}

		$this->api_client = $api_client;
		$this->adapters   = $adapters;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function list_network_statuses(): array {
		$statuses = array();

		foreach (array_keys(self::NETWORKS) as $slug) {
			$statuses[] = $this->network_status($slug);
		}

		return $statuses;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function list_feed_descriptors(): array {
		$descriptors = array();

		foreach (array_keys(self::NETWORKS) as $network) {
			$status = $this->network_status($network);
			if (empty($status['configured'])) {
				$descriptors[] = array(
					'network'            => $network,
					'configured'         => false,
					'available'          => false,
					'feed_url_secret'    => true,
					'feed_health_code'   => (string) ( $status['credential_health_code'] ?? 'unavailable' ),
				);
				continue;
			}

			$context = $this->network_context($network);
			if ($context === null) {
				$descriptors[] = array(
					'network'          => $network,
					'configured'       => true,
					'available'        => false,
					'feed_url_secret'  => true,
					'feed_health_code' => 'network_lookup_failed',
				);
				continue;
			}

			$network_descriptors = $this->descriptors_from_context($network, $context['config'], $context['credentials']);
			if ($network_descriptors === array()) {
				$descriptors[] = array(
					'network'          => $network,
					'configured'       => true,
					'available'        => false,
					'feed_url_secret'  => true,
					'feed_health_code' => 'feed_descriptor_not_found',
				);
				continue;
			}

			array_push($descriptors, ...$network_descriptors);
		}

		return $descriptors;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>|WP_Error
	 */
	public function create_deeplink( array $payload ) {
		$network    = $this->canonical_network_slug((string) ( $payload['network'] ?? '' ));
		$source_url = $this->sanitize_http_url((string) ( $payload['source_url'] ?? $payload['url'] ?? '' ));
		if (!isset(self::NETWORKS[ $network ])) {
			return $this->bad_request('unsupported_network', 'Unsupported CPA network.');
		}
		if ($source_url === '') {
			return $this->bad_request('invalid_source_url', 'Invalid source_url.');
		}

		$context = $this->network_context($network);
		if ($context === null || !$this->has_required_credentials($network, $context['credentials'])) {
			return new WP_Error(
				'savello_internal_cpa_credentials_unavailable',
				'CPA credentials are unavailable.',
				array( 'status' => 503 )
			);
		}

		$offer_id = sanitize_text_field((string) ( $payload['offer_id'] ?? $payload['campaign_id'] ?? $payload['program_id'] ?? '' ));
		if ($offer_id === '') {
			return $this->bad_request('missing_offer_id', 'CPA offer_id is required.');
		}

		$tracking = $this->tracking_from_payload($network, $payload);
		$adapter  = $this->adapter_for_network($network);
		if ($adapter === null || !method_exists($adapter, 'create_deeplink')) {
			return new WP_Error(
				'savello_internal_cpa_adapter_unavailable',
				'CPA adapter is unavailable.',
				array( 'status' => 503 )
			);
		}

		if ($network === 'admitad') {
			$result = $adapter->create_deeplink($context['credentials'], $context['config'], $offer_id, $source_url, $tracking);
		} else {
			$template_url = sanitize_text_field((string) ( $payload['template_url'] ?? $context['config']['product_url'] ?? '' ));
			$result       = $adapter->create_deeplink($context['credentials'], $context['config'], $offer_id, $source_url, $tracking, $template_url, true);
		}

		if (!is_array($result) || empty($result['success']) || empty($result['url'])) {
			return new WP_Error(
				'savello_internal_cpa_deeplink_failed',
				'CPA deeplink failed.',
				array(
					'status'      => 502,
					'reason_code' => is_array($result) ? (string) ( $result['reason_code'] ?? 'deeplink_failed' ) : 'deeplink_failed',
				)
			);
		}

		$affiliate_url = $this->sanitize_http_url((string) $result['url']);
		if ($affiliate_url === '') {
			return new WP_Error(
				'savello_internal_cpa_deeplink_invalid',
				'CPA deeplink response was invalid.',
				array( 'status' => 502 )
			);
		}

		return array(
			'status'        => 'ok',
			'network'       => $network,
			'affiliate_url' => $affiliate_url,
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_feed_content( array $payload ) {
		$network = $this->canonical_network_slug((string) ( $payload['network'] ?? '' ));
		$feed_id = sanitize_key((string) ( $payload['feed_id'] ?? '' ));
		if (!isset(self::NETWORKS[ $network ])) {
			return $this->bad_request('unsupported_network', 'Unsupported CPA network.');
		}
		if ($feed_id === '') {
			return $this->bad_request('missing_feed_id', 'CPA feed_id is required.');
		}

		$context = $this->network_context($network);
		if ($context === null || !$this->has_required_credentials($network, $context['credentials'])) {
			return new WP_Error(
				'savello_internal_cpa_credentials_unavailable',
				'CPA credentials are unavailable.',
				array( 'status' => 503 )
			);
		}

		foreach ($this->feed_rows_from_context($context['config'], $context['credentials']) as $index => $row) {
			if ($this->safe_feed_id($network, $row, $index) !== $feed_id) {
				continue;
			}

			$url = $this->sanitize_http_url($this->first_scalar($row, array( 'url', 'feed_url', 'download_url', 'products_xml_link', 'products_csv_link', 'link' )));
			if ($url === '') {
				break;
			}

			$response = wp_remote_get($url, array( 'timeout' => 60 ));
			if (is_wp_error($response)) {
				return new WP_Error(
					'savello_internal_cpa_feed_download_failed',
					'CPA feed download failed.',
					array( 'status' => 502 )
				);
			}

			$code = (int) wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);
			if ($code < 200 || $code >= 300 || !is_string($body) || $body === '') {
				return new WP_Error(
					'savello_internal_cpa_feed_download_failed',
					'CPA feed download failed.',
					array( 'status' => 502 )
				);
			}

			$content_type = wp_remote_retrieve_header($response, 'content-type');
			return array(
				'status'         => 'ok',
				'network'        => $network,
				'feed_id'        => $feed_id,
				'content_type'   => is_string($content_type) && $content_type !== '' ? $content_type : 'application/octet-stream',
				'content_base64' => base64_encode($body),
			);
		}

		return new WP_Error(
			'savello_internal_cpa_feed_not_found',
			'CPA feed was not found.',
			array( 'status' => 404 )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function network_status( string $slug ): array {
		$network = $this->canonical_network_slug($slug);

		if (!isset(self::NETWORKS[ $network ])) {
			return $this->status($network, false, 'unsupported_network');
		}

		if ($this->api_client === null || !method_exists($this->api_client, 'get_network_config')) {
			return $this->status($network, false, 'api_client_unavailable');
		}

		try {
			$config = $this->api_client->get_network_config($network);
		} catch (\Throwable $e) {
			unset($e);
			return $this->status($network, false, 'network_lookup_failed');
		}

		if (!is_array($config) || (int) ( $config['id'] ?? 0 ) <= 0) {
			return $this->status($network, false, 'network_not_found');
		}

		$credentials = $this->credentials_for_network((int) $config['id']);
		if ($credentials === null) {
			return $this->status($network, false, 'missing_credentials', $config);
		}

		$configured = $this->has_required_credentials($network, $credentials);

		return $this->status(
			$network,
			$configured,
			$configured ? 'configured' : 'incomplete_credentials',
			$config
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function credentials_for_network( int $network_id ): ?array {
		if ($this->api_client === null || !method_exists($this->api_client, 'get_credentials')) {
			return null;
		}

		try {
			$credentials = $this->api_client->get_credentials($network_id);
		} catch (\Throwable $e) {
			unset($e);
			return null;
		}

		return is_array($credentials) ? $credentials : null;
	}

	/**
	 * @return array{config:array<string,mixed>,credentials:array<string,mixed>}|null
	 */
	private function network_context( string $slug ): ?array {
		$network = $this->canonical_network_slug($slug);
		if (!isset(self::NETWORKS[ $network ]) || $this->api_client === null || !method_exists($this->api_client, 'get_network_config')) {
			return null;
		}

		try {
			$config = $this->api_client->get_network_config($network);
		} catch (\Throwable $e) {
			unset($e);
			return null;
		}
		if (!is_array($config) || (int) ( $config['id'] ?? 0 ) <= 0) {
			return null;
		}

		$credentials = $this->credentials_for_network((int) $config['id']);
		if ($credentials === null) {
			return null;
		}

		return array(
			'config'      => $config,
			'credentials' => $credentials,
		);
	}

	/**
	 * @param array<string,mixed> $credentials
	 */
	private function has_required_credentials( string $network, array $credentials ): bool {
		foreach (self::NETWORKS[ $network ]['required_credentials'] as $field) {
			if (!isset($credentials[ $field ]) || !is_scalar($credentials[ $field ]) || trim((string) $credentials[ $field ]) === '') {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 */
	private function status( string $network, bool $configured, string $health_code, array $config = array() ): array {
		$definition = self::NETWORKS[ $network ] ?? array(
			'label'        => $network,
			'capabilities' => array(
				'product_feeds' => false,
				'deeplink'      => false,
			),
		);

		$label = isset($config['name']) && is_scalar($config['name']) && trim((string) $config['name']) !== ''
			? trim((string) $config['name'])
			: (string) $definition['label'];

		return array(
			'network'                => $network,
			'label'                  => $label,
			'configured'             => $configured,
			'capabilities'           => $definition['capabilities'],
			'credential_health_code' => $health_code,
		);
	}

	private function canonical_network_slug( string $slug ): string {
		$slug = strtolower(trim($slug));
		$slug = (string) preg_replace('/[^a-z0-9_.-]/', '', $slug);

		return match ($slug) {
			'adm' => 'admitad',
			'adv', 'advcake.ru' => 'advcake',
			default => $slug,
		};
	}

	/**
	 * @param array<string,mixed> $config
	 * @param array<string,mixed> $credentials
	 * @return array<int,array<string,mixed>>
	 */
	private function descriptors_from_context( string $network, array $config, array $credentials ): array {
		$descriptors = array();
		foreach ($this->feed_rows_from_context($config, $credentials) as $index => $row) {
			$url = $this->first_scalar($row, array( 'url', 'feed_url', 'download_url', 'products_xml_link', 'products_csv_link', 'link' ));
			$format = strtolower($this->first_scalar($row, array( 'format', 'type' )));
			if ($format === '' && $url !== '') {
				$path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
				if (str_ends_with($path, '.xml') || str_ends_with($path, '.yml')) {
					$format = 'xml';
				} elseif (str_ends_with($path, '.csv')) {
					$format = 'csv';
				}
			}

			$descriptors[] = array_filter(
				array(
					'network'          => $network,
					'feed_id'          => $this->safe_feed_id($network, $row, $index),
					'name'             => $this->first_scalar($row, array( 'name', 'title' )),
					'format'           => $format,
					'configured'       => true,
					'available'        => $url !== '',
					'feed_url_secret'  => true,
					'feed_health_code' => $url !== '' ? 'configured' : 'feed_url_missing',
				),
				static fn( $value ): bool => $value !== ''
			);
		}

		return $descriptors;
	}

	/**
	 * @param array<string,mixed> $config
	 * @param array<string,mixed> $credentials
	 * @return array<int,array<string,mixed>>
	 */
	private function feed_rows_from_context( array $config, array $credentials ): array {
		$rows = array_merge(
			$this->normalize_feed_rows($credentials['feeds_info'] ?? null),
			$this->normalize_feed_rows($credentials['feeds'] ?? null),
			$this->normalize_feed_rows($credentials['common_feeds'] ?? null),
			$this->normalize_feed_rows($config['feeds_info'] ?? null),
			$this->normalize_feed_rows($config['feeds'] ?? null),
			$this->normalize_feed_rows($config['common_feeds'] ?? null)
		);

		foreach (array( 'products_xml_link' => 'xml', 'products_csv_link' => 'csv', 'feed_url' => '' ) as $key => $format) {
			foreach (array( $credentials, $config ) as $source) {
				if (isset($source[ $key ]) && is_scalar($source[ $key ]) && trim((string) $source[ $key ]) !== '') {
					$rows[] = array(
						'id'     => $key,
						'name'   => $key,
						'format' => $format,
						'url'    => (string) $source[ $key ],
					);
				}
			}
		}

		return $rows;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_feed_rows( mixed $value ): array {
		if (is_string($value) && trim($value) !== '') {
			$decoded = json_decode($value, true);
			if (is_array($decoded)) {
				$value = $decoded;
			}
		}
		if (!is_array($value)) {
			return array();
		}

		if (array_is_list($value)) {
			return array_values(array_filter($value, 'is_array'));
		}

		return array( $value );
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<int,string>   $keys
	 */
	private function first_scalar( array $row, array $keys ): string {
		foreach ($keys as $key) {
			if (isset($row[ $key ]) && is_scalar($row[ $key ])) {
				return trim((string) $row[ $key ]);
			}
		}
		return '';
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function safe_feed_id( string $network, array $row, int $index ): string {
		$id = $this->first_scalar($row, array( 'id', 'feed_id', 'offer_id', 'campaign_id', 'name' ));
		$id = sanitize_key($id);

		return $id !== '' ? $id : $network . '-feed-' . ( $index + 1 );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,string>
	 */
	private function tracking_from_payload( string $network, array $payload ): array {
		$tracking = array();
		if (isset($payload['tracking']) && is_array($payload['tracking'])) {
			foreach ($payload['tracking'] as $key => $value) {
				$name = sanitize_key((string) $key);
				if ($name !== '' && is_scalar($value)) {
					$tracking[ $name ] = sanitize_text_field((string) $value);
				}
			}
		}

		$click_id = sanitize_text_field((string) ( $payload['click_id'] ?? '' ));
		if ($click_id !== '') {
			$tracking[ $network === 'advcake' ? 'sub1' : 'subid' ] = $click_id;
		}

		return $tracking;
	}

	private function adapter_for_network( string $network ): ?object {
		if (isset($this->adapters[ $network ])) {
			return $this->adapters[ $network ];
		}

		$root = dirname(__DIR__, 2);
		foreach (array(
			'/includes/class-cashback-outbound-http-guard.php',
			'/includes/oauth/class-oauth2-client-credentials-helper.php',
			'/includes/adapters/interface-cashback-network-adapter.php',
			'/includes/adapters/abstract-cashback-network-adapter.php',
			$network === 'admitad'
				? '/includes/adapters/class-admitad-adapter.php'
				: '/includes/adapters/class-cashback-advcake-adapter.php',
		) as $relative) {
			$path = $root . $relative;
			if (file_exists($path)) {
				require_once $path;
			}
		}

		if ($network === 'admitad' && class_exists('Cashback_Admitad_Adapter')) {
			return new Cashback_Admitad_Adapter();
		}
		if ($network === 'advcake' && class_exists('Cashback_Advcake_Adapter')) {
			return new Cashback_Advcake_Adapter();
		}

		return null;
	}

	private function sanitize_http_url( string $url ): string {
		$url    = esc_url_raw($url);
		$scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));

		return in_array($scheme, array( 'http', 'https' ), true) ? $url : '';
	}

	private function bad_request( string $reason_code, string $message ): WP_Error {
		return new WP_Error(
			'savello_internal_cpa_' . $reason_code,
			$message,
			array( 'status' => 400 )
		);
	}
}
