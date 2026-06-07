<?php
/**
 * Обработчик postback-событий Admitad о статусе программы и сотрудничества.
 *
 * @package CashbackPlugin
 */

declare(strict_types=1);

if (!defined('ABSPATH') && !defined('PHPUNIT_RUNNING')) {
	exit;
}

class Cashback_Admitad_Status_Postback_Sync {
	public const HOOK_NAME = 'cashback_admitad_status_postback_sync';

	private const AS_GROUP = 'cashback';
	private const INTERVAL = 5 * MINUTE_IN_SECONDS;
	private const BATCH_LIMIT = 200;

	private const META_PROGRAM_STATUS = '_cashback_admitad_program_status';
	private const META_PROGRAM_STATUS_AT = '_cashback_admitad_program_status_at';
	private const META_PARTNERSHIP_STATUS = '_cashback_admitad_partnership_status';
	private const META_PARTNERSHIP_STATUS_AT = '_cashback_admitad_partnership_status_at';
	private const META_STATUS_SOURCE = '_cashback_admitad_status_source';

	/** @var callable|null */
	private static $test_campaign_resolver = null;

	public static function init(): void {
		add_action(self::HOOK_NAME, array( self::class, 'run' ));
		add_action('init', array( self::class, 'maybe_schedule' ));
	}

	public static function run(): void {
		self::process_batch();
	}

	public static function maybe_schedule(): void {
		if (function_exists('as_has_scheduled_action')
			&& function_exists('as_schedule_recurring_action')
			&& !as_has_scheduled_action(self::HOOK_NAME)
		) {
			as_schedule_recurring_action(time(), self::INTERVAL, self::HOOK_NAME, array(), self::AS_GROUP);
		}
	}

	/**
	 * Test-only injection point. Production passes null.
	 */
	public static function set_test_campaign_resolver( ?callable $resolver ): void {
		self::$test_campaign_resolver = $resolver;
	}

	/**
	 * @return array{processed:int,ok:int,not_found:int,error:int}
	 */
	public static function process_batch(): array {
		global $wpdb;

		$stats = array( 'processed' => 0, 'ok' => 0, 'not_found' => 0, 'error' => 0 );
		$lock  = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', 'cashback_admitad_status_postback_sync', 0));
		if ($lock !== 1) {
			return $stats;
		}

		try {
			return self::process_batch_locked($stats);
		} finally {
			$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'cashback_admitad_status_postback_sync'));
		}
	}

	/**
	 * @param array{processed:int,ok:int,not_found:int,error:int} $stats
	 * @return array{processed:int,ok:int,not_found:int,error:int}
	 */
	private static function process_batch_locked( array $stats ): array {
		global $wpdb;

		$network = self::resolve_admitad_network();
		if ($network === null) {
			return $stats;
		}

		if (!self::has_event_type_column()) {
			return $stats;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, payload, event_type FROM %i
				  WHERE network_slug IN (%s, %s)
				    AND event_type IN (%s, %s)
				    AND processing_status IS NULL
				  ORDER BY id ASC
				  LIMIT %d',
				$wpdb->prefix . 'cashback_webhooks',
				'admitad',
				'adm',
				'program_status',
				'partnership_status',
				self::BATCH_LIMIT
			)
		);
		if (!is_array($rows) || $rows === array()) {
			return $stats;
		}

		foreach ($rows as $row) {
			++$stats['processed'];
			$row_id     = (int) ($row->id ?? 0);
			$event_type = strtolower((string) ($row->event_type ?? ''));
			$data       = self::decode_payload((string) ($row->payload ?? ''));
			if ($row_id <= 0 || $data === null) {
				self::mark_row($row_id, 'error');
				++$stats['error'];
				continue;
			}

			$offer_id = self::extract_offer_id($data);
			if ($offer_id === '') {
				self::mark_row($row_id, 'error');
				++$stats['error'];
				continue;
			}

			$product_id = Cashback_Shop_Importer::find_product_by_offer($network['id'], $offer_id);
			if ($product_id <= 0) {
				self::mark_row($row_id, 'click_not_found');
				++$stats['not_found'];
				continue;
			}

			$result = self::apply_event($product_id, $network['slug'], $offer_id, $event_type, $data);
			if ($result === 'retry') {
				++$stats['error'];
				continue;
			}
			if ($result === 'error') {
				self::mark_row($row_id, 'error');
				++$stats['error'];
				continue;
			}

			self::mark_row($row_id, 'ok');
			++$stats['ok'];
		}

		return $stats;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private static function apply_event( int $product_id, string $network_slug, string $offer_id, string $event_type, array $data ): string {
		$offer_name = self::extract_scalar($data, 'offer_name');
		if ($event_type === 'program_status') {
			$status = self::normalize_program_status(self::extract_scalar($data, 'offer_status'));
			if ($status === '') {
				return 'error';
			}
			self::save_program_status($product_id, $status, 'webhook');
			if ($status !== 'active') {
				$reason = sprintf('Программа Admitad отключена (offer_id: %s, status: %s)', $offer_id, $status);
				Cashback_Product_Cpa_Status_Service::deactivate_product($product_id, 'admitad', $offer_id, $reason, 'admitad_program_status');
				return 'ok';
			}
		} elseif ($event_type === 'partnership_status') {
			$status = self::normalize_partnership_status(self::extract_scalar($data, 'partnership_status'));
			if ($status === '') {
				return 'error';
			}
			self::save_partnership_status($product_id, $status, 'webhook');
			if ($status !== 'accepted') {
				$reason = sprintf('Сотрудничество с программой Admitad отключено (offer_id: %s, status: %s)', $offer_id, $status);
				Cashback_Product_Cpa_Status_Service::deactivate_product($product_id, 'admitad', $offer_id, $reason, 'admitad_partnership_status');
				return 'ok';
			}
		} else {
			return 'error';
		}

		$state = self::resolve_effective_state($product_id, $network_slug, $offer_id);
		if (!($state['success'] ?? false)) {
			return 'retry';
		}
		if (($state['active'] ?? false) !== true) {
			return 'ok';
		}

		Cashback_Product_Cpa_Status_Service::reactivate_product_if_autopublish_enabled(
			$product_id,
			'admitad',
			$offer_id,
			$offer_name !== '' ? $offer_name : ('Admitad campaign ' . $offer_id)
		);
		return 'ok';
	}

	/**
	 * @return array{success:bool,active?:bool,error?:string}
	 */
	private static function resolve_effective_state( int $product_id, string $network_slug, string $offer_id ): array {
		$program     = (string) get_post_meta($product_id, self::META_PROGRAM_STATUS, true);
		$partnership = (string) get_post_meta($product_id, self::META_PARTNERSHIP_STATUS, true);

		if ($program !== '' && $program !== 'active') {
			return array( 'success' => true, 'active' => false );
		}
		if ($partnership !== '' && $partnership !== 'accepted') {
			return array( 'success' => true, 'active' => false );
		}
		if ($program === 'active' && $partnership === 'accepted') {
			return array( 'success' => true, 'active' => true );
		}

		$api = self::verify_campaign_via_api($network_slug, $offer_id);
		if (!($api['success'] ?? false)) {
			return array( 'success' => false, 'error' => (string) ($api['error'] ?? 'API verification failed') );
		}

		$campaign = $api['campaign'] ?? null;
		if (!is_array($campaign)) {
			return array( 'success' => true, 'active' => false );
		}

		$status = self::normalize_program_status((string) ($campaign['status'] ?? ''));
		$conn_raw = self::normalize_connection_status((string) ($campaign['connection_status'] ?? ''));
		if ($status !== '') {
			self::save_program_status($product_id, $status, 'api');
		}
		if ($conn_raw !== '') {
			self::save_partnership_status($product_id, $conn_raw === 'active' ? 'accepted' : 'denied', 'api');
		}

		return array( 'success' => true, 'active' => $status === 'active' && $conn_raw === 'active' );
	}

	/**
	 * @return array{success:bool,campaign?:array<string,mixed>|null,error?:string}
	 */
	private static function verify_campaign_via_api( string $network_slug, string $offer_id ): array {
		if (self::$test_campaign_resolver !== null) {
			return (array) call_user_func(self::$test_campaign_resolver, $network_slug, $offer_id);
		}
		if (!class_exists('Cashback_API_Client')) {
			return array( 'success' => false, 'error' => 'Cashback_API_Client unavailable' );
		}

		$client  = Cashback_API_Client::get_instance();
		$results = $client->check_campaign_statuses($network_slug);
		$result  = $results[$network_slug] ?? null;
		if (!is_array($result) || !($result['success'] ?? false)) {
			return array( 'success' => false, 'error' => (string) ($result['error'] ?? 'campaign check failed') );
		}

		$snapshot = get_option('cashback_campaign_status_' . $network_slug, array());
		$campaigns = is_array($snapshot) ? ($snapshot['campaigns'] ?? array()) : array();
		if (!is_array($campaigns)) {
			$campaigns = array();
		}
		foreach ($campaigns as $campaign) {
			if (is_array($campaign) && (string) ($campaign['id'] ?? '') === $offer_id) {
				return array( 'success' => true, 'campaign' => $campaign );
			}
		}
		return array( 'success' => true, 'campaign' => null );
	}

	/**
	 * @return array{id:int,slug:string}|null
	 */
	private static function resolve_admitad_network(): ?array {
		global $wpdb;

		if (!is_object($wpdb)) {
			return null;
		}

		if (method_exists($wpdb, 'get_row')) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, slug FROM %i WHERE slug IN (%s, %s) AND is_active = 1 ORDER BY id LIMIT 1',
					$wpdb->prefix . 'cashback_affiliate_networks',
					'admitad',
					'adm'
				),
				ARRAY_A
			);
			if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
				return array(
					'id'   => (int) $row['id'],
					'slug' => (string) ($row['slug'] ?? 'admitad'),
				);
			}
		}

		$row_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE slug IN (%s, %s) AND is_active = 1 ORDER BY id LIMIT 1',
				$wpdb->prefix . 'cashback_affiliate_networks',
				'admitad',
				'adm'
			)
		);
		$id = (int) $row_id;
		if ($id <= 0) {
			return null;
		}
		return array( 'id' => $id, 'slug' => 'admitad' );
	}

	private static function has_event_type_column(): bool {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS
				  WHERE TABLE_SCHEMA = DATABASE()
				    AND TABLE_NAME = %s
				    AND COLUMN_NAME = %s',
				$wpdb->prefix . 'cashback_webhooks',
				'event_type'
			)
		) > 0;
	}

	private static function decode_payload( string $payload ): ?array {
		if ($payload === '') {
			return null;
		}
		$trimmed = ltrim($payload);
		if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
			$decoded = json_decode($payload, true);
			return is_array($decoded) ? $decoded : null;
		}
		$parsed = array();
		parse_str($payload, $parsed);
		return $parsed === array() ? null : $parsed;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private static function extract_offer_id( array $data ): string {
		$value = self::extract_scalar($data, 'offer_id');
		return preg_match('/^\d+$/', $value) ? $value : '';
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private static function extract_scalar( array $data, string $key ): string {
		if (!array_key_exists($key, $data) || !is_scalar($data[$key])) {
			return '';
		}
		return trim((string) $data[$key]);
	}

	private static function normalize_program_status( string $status ): string {
		$status = strtolower(trim($status));
		return in_array($status, array( 'active', 'denied', 'disabled', 'dead' ), true) ? $status : '';
	}

	private static function normalize_partnership_status( string $status ): string {
		$status = strtolower(trim($status));
		return in_array($status, array( 'accepted', 'denied' ), true) ? $status : '';
	}

	private static function normalize_connection_status( string $status ): string {
		$status = strtolower(trim($status));
		return in_array($status, array( 'active', 'pending', 'declined', 'suspend', 'disabled' ), true) ? $status : '';
	}

	private static function save_program_status( int $product_id, string $status, string $source ): void {
		update_post_meta($product_id, self::META_PROGRAM_STATUS, $status);
		update_post_meta($product_id, self::META_PROGRAM_STATUS_AT, self::now_mysql());
		update_post_meta($product_id, self::META_STATUS_SOURCE, $source);
	}

	private static function save_partnership_status( int $product_id, string $status, string $source ): void {
		update_post_meta($product_id, self::META_PARTNERSHIP_STATUS, $status);
		update_post_meta($product_id, self::META_PARTNERSHIP_STATUS_AT, self::now_mysql());
		update_post_meta($product_id, self::META_STATUS_SOURCE, $source);
	}

	private static function now_mysql(): string {
		if (class_exists('Cashback_Time')) {
			return Cashback_Time::now_mysql();
		}
		return (string) current_time('mysql', true);
	}

	private static function mark_row( int $row_id, string $status ): void {
		if ($row_id <= 0) {
			return;
		}
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'cashback_webhooks',
			array( 'processing_status' => $status ),
			array( 'id' => $row_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
