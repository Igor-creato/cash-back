<?php
/**
 * Cashback_Shop_Approval_Rate — % подтверждения заказов магазином за окно.
 *
 * Считает approval_rate = completed / (completed + declined) по локальной
 * выборке cashback_transactions + cashback_unregistered_transactions за
 * WINDOW_DAYS дней. Источник данных — снапшот reconciliation-крона
 * (/statistics/actions/ для Admitad, аналоги для EPN/Advcake), поэтому
 * формула одинакова для всех CPA-сетей.
 *
 * Метрика per-shop: один offer_id = одна CPA-кампания = один магазин.
 *
 * @package CashbackPlugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Cashback_Shop_Approval_Rate {

    public const TABLE_TX         = 'cashback_transactions';
    public const TABLE_TX_UNREG   = 'cashback_unregistered_transactions';
    public const TABLE_NETWORKS   = 'cashback_affiliate_networks';

    public const WINDOW_DAYS  = 30;
    public const MIN_SAMPLE   = 10;
    public const CACHE_PREFIX = 'cb_approval_rate_v1_';
    public const CACHE_TTL    = HOUR_IN_SECONDS;

    /**
     * Расчёт approval rate магазина.
     *
     * @param int    $network_id ID сети в wp_cashback_affiliate_networks
     * @param string $offer_id   advcampaign_id (число в строке, как лежит в post meta `_offer_id`)
     *
     * @return array{
     *   sample:int,
     *   confirmed:int,
     *   declined:int,
     *   rate:?float,
     *   bucket:string,
     *   window_days:int,
     *   min_sample:int
     * }
     */
    public static function for_shop( int $network_id, string $offer_id ): array {
        // Колонка cashback_transactions.offer_id = int unsigned. Сети с
        // нечисловыми ID (Advcake string IDs) — метрика недоступна, чтобы не
        // искать ложно по offer_id=0 после silent (int) cast'а.
        $offer_id_trimmed = trim($offer_id);
        if ($network_id <= 0 || $offer_id_trimmed === '' || ! ctype_digit($offer_id_trimmed)) {
            return self::empty_result();
        }

        $offer_id_int = (int) $offer_id_trimmed;
        if ($offer_id_int <= 0) {
            return self::empty_result();
        }

        $cache_key = self::CACHE_PREFIX . $network_id . '_' . $offer_id_int;
        $cached    = get_transient($cache_key);
        if (is_array($cached) && isset($cached['bucket'])) {
            return $cached;
        }

        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) {
            return self::empty_result();
        }

        $partner_slug = $wpdb->get_var($wpdb->prepare(
            'SELECT slug FROM %i WHERE id = %d LIMIT 1',
            $wpdb->prefix . self::TABLE_NETWORKS,
            $network_id
        ));
        if (! is_string($partner_slug) || $partner_slug === '') {
            return self::empty_result();
        }

        $window_days = self::WINDOW_DAYS;

        $sql = "SELECT
            COALESCE(SUM(CASE WHEN order_status='completed' THEN 1 ELSE 0 END), 0) AS confirmed,
            COALESCE(SUM(CASE WHEN order_status='declined'  THEN 1 ELSE 0 END), 0) AS declined
        FROM (
            SELECT order_status FROM %i WHERE offer_id = %d AND partner = %s AND order_status IN ('completed','declined') AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
            UNION ALL
            SELECT order_status FROM %i WHERE offer_id = %d AND partner = %s AND order_status IN ('completed','declined') AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
        ) AS combined";

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $sql — статичный литерал выше; имена таблиц передаются через %i, остальные значения через %d/%s.
        $row = $wpdb->get_row($wpdb->prepare(
            $sql,
            $wpdb->prefix . self::TABLE_TX,
            $offer_id_int,
            $partner_slug,
            $window_days,
            $wpdb->prefix . self::TABLE_TX_UNREG,
            $offer_id_int,
            $partner_slug,
            $window_days
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        $confirmed = is_array($row) ? (int) ($row['confirmed'] ?? 0) : 0;
        $declined  = is_array($row) ? (int) ($row['declined']  ?? 0) : 0;
        $sample    = $confirmed + $declined;

        if ($sample < self::MIN_SAMPLE) {
            $rate   = null;
            $bucket = 'insufficient';
        } else {
            $rate = ($confirmed / $sample) * 100.0;
            if ($rate < 50.0) {
                $bucket = 'red';
            } elseif ($rate < 80.0) {
                $bucket = 'yellow';
            } else {
                $bucket = 'green';
            }
        }

        $result = array(
            'sample'      => $sample,
            'confirmed'   => $confirmed,
            'declined'    => $declined,
            'rate'        => $rate,
            'bucket'      => $bucket,
            'window_days' => $window_days,
            'min_sample'  => self::MIN_SAMPLE,
        );

        set_transient($cache_key, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * @return array{sample:int,confirmed:int,declined:int,rate:null,bucket:string,window_days:int,min_sample:int}
     */
    private static function empty_result(): array {
        return array(
            'sample'      => 0,
            'confirmed'   => 0,
            'declined'    => 0,
            'rate'        => null,
            'bucket'      => 'insufficient',
            'window_days' => self::WINDOW_DAYS,
            'min_sample'  => self::MIN_SAMPLE,
        );
    }
}
