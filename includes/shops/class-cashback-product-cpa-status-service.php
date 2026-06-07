<?php
/**
 * Единый writer post_status/meta для CPA-driven деактивации магазинов.
 *
 * @package CashbackPlugin
 */

declare(strict_types=1);

if (!defined('ABSPATH') && !defined('PHPUNIT_RUNNING')) {
	exit;
}

class Cashback_Product_Cpa_Status_Service {
	public const META_AUTO_DEACTIVATED          = '_cashback_auto_deactivated';
	public const META_DEACTIVATION_REASON      = '_cashback_deactivation_reason';
	public const META_DEACTIVATED_AT           = '_cashback_deactivated_at';
	public const META_DEACTIVATED_NETWORK      = '_cashback_deactivated_network';
	public const META_DEACTIVATED_SOURCE       = '_cashback_deactivated_source';
	public const META_AUTOPUBLISH_ENABLED      = '_cashback_auto_publish_enabled';
	public const META_LEGACY_DEACTIVATED_AT    = '_cashback_auto_deactivated_at';
	public const META_LEGACY_DEACTIVATED_SOURCE = '_cashback_auto_deactivated_source';

	public static function deactivate_product(
		int $product_id,
		string $network_slug,
		string $offer_id,
		string $reason,
		string $source = ''
	): bool {
		if ($product_id <= 0) {
			return false;
		}

		$current_post = get_post($product_id);
		if ($current_post instanceof WP_Post && $current_post->post_status !== 'draft') {
			$update = wp_update_post(
				array(
					'ID'          => $product_id,
					'post_status' => 'draft',
				),
				true
			);
			if (self::is_update_failure($update)) {
				return false;
			}
		}

		update_post_meta($product_id, self::META_AUTO_DEACTIVATED, '1');
		update_post_meta($product_id, self::META_DEACTIVATION_REASON, $reason);
		update_post_meta($product_id, self::META_DEACTIVATED_AT, self::now_mysql());
		update_post_meta($product_id, self::META_DEACTIVATED_NETWORK, $network_slug);
		if ($source !== '') {
			update_post_meta($product_id, self::META_DEACTIVATED_SOURCE, $source);
		}

		self::write_audit(
			'store_auto_deactivated',
			$product_id,
			array(
				'network_slug' => $network_slug,
				'offer_id'     => $offer_id,
				'reason'       => $reason,
				'source'       => $source,
			)
		);

		return true;
	}

	public static function reactivate_product_if_autopublish_enabled(
		int $product_id,
		string $network_slug,
		string $offer_id,
		string $campaign_name
	): bool {
		if ($product_id <= 0) {
			return false;
		}

		if ((string) get_post_meta($product_id, self::META_AUTOPUBLISH_ENABLED, true) !== '1') {
			return false;
		}

		$current_post = get_post($product_id);
		if ($current_post instanceof WP_Post && $current_post->post_status !== 'publish') {
			$update = wp_update_post(
				array(
					'ID'          => $product_id,
					'post_status' => 'publish',
				),
				true
			);
			if (self::is_update_failure($update)) {
				return false;
			}
		}

		self::clear_deactivation_markers($product_id);
		self::write_audit(
			'store_auto_reactivated',
			$product_id,
			array(
				'network_slug'  => $network_slug,
				'offer_id'      => $offer_id,
				'campaign_name' => $campaign_name,
			)
		);

		return true;
	}

	public static function clear_deactivation_markers( int $product_id ): void {
		if ($product_id <= 0) {
			return;
		}

		$keys = array(
			self::META_AUTO_DEACTIVATED,
			self::META_DEACTIVATION_REASON,
			self::META_DEACTIVATED_AT,
			self::META_DEACTIVATED_NETWORK,
			self::META_DEACTIVATED_SOURCE,
			self::META_LEGACY_DEACTIVATED_AT,
			self::META_LEGACY_DEACTIVATED_SOURCE,
		);
		foreach ($keys as $key) {
			delete_post_meta($product_id, $key);
		}
	}

	private static function is_update_failure( mixed $update ): bool {
		if (function_exists('is_wp_error') && is_wp_error($update)) {
			return true;
		}
		return is_numeric($update) && (int) $update === 0;
	}

	private static function now_mysql(): string {
		if (class_exists('Cashback_Time')) {
			return Cashback_Time::now_mysql();
		}
		return (string) current_time('mysql', true);
	}

	/**
	 * Audit failures must not break product status sync.
	 *
	 * @param array<string,mixed> $details
	 */
	private static function write_audit( string $action, int $product_id, array $details ): void {
		if (!class_exists('Cashback_Encryption')) {
			return;
		}
		try {
			Cashback_Encryption::write_audit_log($action, 0, 'product', $product_id, $details);
		} catch (\Throwable $e) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic only.
			error_log('[Cashback Product CPA Status] audit failed: ' . $e->getMessage());
		}
	}
}
