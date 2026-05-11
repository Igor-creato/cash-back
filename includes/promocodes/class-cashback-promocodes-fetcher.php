<?php

/**
 * Fetcher промокодов: cron + manual refresh.
 *
 * Cron (Action Scheduler): 6ч интервал, hook 'cashback_promocodes_fetch_all'.
 * Manual: kнопка в metabox товара (Шаг 7) и в admin-странице (Шаг 8).
 *
 * fetch_all() — Lock + soft-fail per-network: одна сетевая ошибка не валит
 * остальные. Пара (network_id, advcampaign_id) → adapter->fetch_coupons → repo->upsert.
 *
 * @package CashbackPlugin
 * @since   7.2.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Promocodes_Fetcher {

    /** Cron hook (Action Scheduler). */
    public const CRON_HOOK    = 'cashback_promocodes_fetch_all';

    /** Action Scheduler group. */
    public const CRON_GROUP   = 'cashback';

    /** Period: 6 часов. */
    private const REFRESH_PERIOD = 6 * HOUR_IN_SECONDS;

    public function __construct(
        private Cashback_Coupons_Adapter_Registry $registry,
        private Cashback_Promocodes_Repository $repository
    ) {}

    /**
     * Зарегистрировать рекуррентный AS-job (вызывается один раз при init).
     */
    public function register_cron(): void {
        if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
            return;
        }
        if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::CRON_HOOK, array(), self::CRON_GROUP ) ) {
            return;
        }
        as_schedule_recurring_action(
            time() + 60,
            self::REFRESH_PERIOD,
            self::CRON_HOOK,
            array(),
            self::CRON_GROUP
        );
    }

    /**
     * Загрузить промокоды для всех активных сетей.
     *
     * @return array{networks_processed:int,networks_skipped:int,networks_failed:int,total_upserted:int}
     */
    public function fetch_all(): array {
        $summary = array(
            'networks_processed' => 0,
            'networks_skipped'   => 0,
            'networks_failed'    => 0,
            'total_upserted'     => 0,
        );

        $lock_acquired = false;
        if ( class_exists( 'Cashback_Lock' ) ) {
            $lock_acquired = Cashback_Lock::acquire( 0 );
            if ( ! $lock_acquired ) {
                return $summary;
            }
        }

        try {
            $networks = $this->load_active_networks();

            foreach ( $networks as $network ) {
                try {
                    $adapter = $this->registry->get_for_network( $network );
                    if ( $adapter === null ) {
                        ++$summary['networks_skipped'];
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log( '[Cashback Promocodes] no adapter for network ' . ( $network->slug ?? '' ) );
                        continue;
                    }

                    $network_id = (int) $network->id;
                    $pairs      = $this->load_product_pairs( $network_id );

                    $pair_upserted_any = false;
                    foreach ( $pairs as $pair ) {
                        try {
                            $advcampaign_id = (string) $pair->advcampaign_id;
                            if ( $advcampaign_id === '' ) {
                                continue;
                            }
                            $coupons = $adapter->fetch_coupons( $advcampaign_id );
                            $result  = $this->repository->upsert_for_campaign( $network_id, $advcampaign_id, $coupons );
                            $pair_upserted = (int) ( $result['upserted'] ?? 0 );
                            $summary['total_upserted'] += $pair_upserted;
                            if ( $pair_upserted > 0 || ( (int) ( $result['deactivated'] ?? 0 ) ) > 0 ) {
                                $pair_upserted_any = true;
                            }
                        } catch ( \Throwable $e ) {
                            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                            error_log( '[Cashback Promocodes] fetch_for_pair failed: ' . $e->getMessage() );
                        }
                    }
                    // Сигналим REST API инвалидировать кэш промокодов в popup
                    // расширения — bump общей версии транзиентов.
                    if ( $pair_upserted_any && function_exists( 'do_action' ) ) {
                        do_action( 'cashback_promocodes_upserted_after_cron', $network_id );
                    }
                    ++$summary['networks_processed'];
                } catch ( \Throwable $e ) {
                    ++$summary['networks_processed'];
                    ++$summary['networks_failed'];
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log( '[Cashback Promocodes] network ' . ( $network->slug ?? '' ) . ' failed: ' . $e->getMessage() );
                }
            }
        } finally {
            if ( $lock_acquired && class_exists( 'Cashback_Lock' ) ) {
                Cashback_Lock::release();
            }
        }

        return $summary;
    }

    /**
     * Manual refresh — по конкретному (network_id, advcampaign_id).
     *
     * @return array{upserted:int,deactivated:int,error?:string}
     */
    public function fetch_for_product_pair( int $network_id, string $advcampaign_id ): array {
        $network = $this->load_network( $network_id );
        if ( $network === null ) {
            return array( 'upserted' => 0, 'deactivated' => 0, 'error' => 'network_not_found' );
        }

        $adapter = $this->registry->get_for_network( $network );
        if ( $adapter === null ) {
            return array( 'upserted' => 0, 'deactivated' => 0, 'error' => 'no_adapter_for_network' );
        }

        try {
            $coupons = $adapter->fetch_coupons( $advcampaign_id );
            $result  = $this->repository->upsert_for_campaign( $network_id, $advcampaign_id, $coupons );
            $changed = ( (int) ( $result['upserted'] ?? 0 ) ) > 0
                || ( (int) ( $result['deactivated'] ?? 0 ) ) > 0;
            if ( $changed && function_exists( 'do_action' ) ) {
                do_action( 'cashback_promocodes_upserted_after_cron', $network_id );
            }
            return $result;
        } catch ( \Throwable $e ) {
            return array( 'upserted' => 0, 'deactivated' => 0, 'error' => $e->getMessage() );
        }
    }

    /**
     * @return array<int,object>
     */
    private function load_active_networks(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_affiliate_networks';
        $sql   = $wpdb->prepare( 'SELECT * FROM %i WHERE is_active = 1 ORDER BY sort_order, name', $table );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared.
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : array();
    }

    private function load_network( int $network_id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_affiliate_networks';
        $sql   = $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $table, $network_id );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared.
        $rows = $wpdb->get_results( $sql );
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return null;
        }
        return $rows[0];
    }

    /**
     * Получить уникальные пары (advcampaign_id) для всех WC products
     * привязанных к конкретной сети через postmeta '_offer_id' + '_affiliate_network_id'.
     *
     * @return array<int,object>
     */
    private function load_product_pairs( int $network_id ): array {
        global $wpdb;
        // postmeta-based JOIN: пробрасываем product_id, _offer_id, _affiliate_network_id.
        $sql = $wpdb->prepare(
            "SELECT DISTINCT
                offer_meta.post_id      AS product_id,
                CAST(net_meta.meta_value AS UNSIGNED) AS network_id,
                offer_meta.meta_value   AS advcampaign_id
              FROM {$wpdb->postmeta} offer_meta
              JOIN {$wpdb->postmeta} net_meta
                ON net_meta.post_id = offer_meta.post_id
               AND net_meta.meta_key = '_affiliate_network_id'
              WHERE offer_meta.meta_key = '_offer_id'
                AND offer_meta.meta_value <> ''
                AND CAST(net_meta.meta_value AS UNSIGNED) = %d",
            $network_id
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared.
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : array();
    }
}
