<?php

/**
 * Safety-backfill: дозаписывает в cashback_promocode_clicks записи, которых там
 * нет, но они есть в cashback_click_log с promocode_id IS NOT NULL.
 *
 * Сценарий гонки: do_activate TX зафиксировал click_log + click_sessions (atomic),
 * но runtime INSERT в stat-таблицу cashback_promocode_clicks из redirect-handler'а
 * упал (DB lock timeout, network spike, etc.). Этот cron через 6 часов добивает
 * недостающее по LEFT JOIN cl ↔ pc на click_id.
 *
 * Cron: Action Scheduler, hook 'cashback_promocodes_click_backfill', период 6 часов.
 * Для запуска нужен системный cron на `wp action-scheduler run` (см. deploy stack).
 *
 * Идемпотентность: LEFT JOIN исключает уже существующие записи; повторный прогон
 * через 6 часов на тех же данных — no-op.
 *
 * @package CashbackPlugin
 * @since   7.4.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cashback_Promocodes_Click_Backfill {

    /** Cron hook (Action Scheduler). */
    public const CRON_HOOK = 'cashback_promocodes_click_backfill';

    /** Action Scheduler group. */
    public const CRON_GROUP = 'cashback';

    /** Период между прогонами: 6 часов. */
    private const PERIOD = 6 * HOUR_IN_SECONDS;

    /** Лимит rows per run — чтобы AS-job не подвисал на больших объёмах. */
    private const BATCH_LIMIT = 1000;

    /** Hook handler регистрируется один раз. */
    public static function init(): void {
        add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
    }

    /** Регистрация рекуррентного AS-job (вызывается из bootstrap при init). */
    public static function register_cron(): void {
        if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
            return;
        }
        if ( function_exists( 'as_has_scheduled_action' )
            && as_has_scheduled_action( self::CRON_HOOK, array(), self::CRON_GROUP ) ) {
            return;
        }
        as_schedule_recurring_action(
            time() + 60,
            self::PERIOD,
            self::CRON_HOOK,
            array(),
            self::CRON_GROUP
        );
    }

    /**
     * Прогон backfill'а.
     *
     * @return array{found:int,inserted:int,skipped:int,errors:int}
     */
    public static function run(): array {
        $summary = array(
            'found'    => 0,
            'inserted' => 0,
            'skipped'  => 0,
            'errors'   => 0,
        );

        global $wpdb;
        $click_log    = $wpdb->prefix . 'cashback_click_log';
        $promo_clicks = $wpdb->prefix . 'cashback_promocode_clicks';

        // Skip если v10 миграция ещё не дошла (нет колонки promocode_id).
        $col_exists = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = %s',
            $click_log,
            'promocode_id'
        ) );
        if ( $col_exists === 0 ) {
            return $summary;
        }

        // SELECT недостающих: click_log с promocode_id IS NOT NULL И отсутствующих
        // в promocode_clicks по click_id (NULL в pc.id означает «нет матча»).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; cron job, no caching needed.
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT cl.click_id, cl.promocode_id, cl.product_id, cl.user_id,
                    cl.ip_address, cl.user_agent, cl.created_at
               FROM %i cl
               LEFT JOIN %i pc ON pc.click_id = cl.click_id
              WHERE cl.promocode_id IS NOT NULL
                AND cl.promocode_id > 0
                AND pc.id IS NULL
              ORDER BY cl.id ASC
              LIMIT %d',
            $click_log,
            $promo_clicks,
            self::BATCH_LIMIT
        ), ARRAY_A );

        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return $summary;
        }

        $summary['found'] = count( $rows );

        if ( ! class_exists( 'Cashback_Promocodes_Tracker' ) ) {
            $summary['errors'] = $summary['found'];
            return $summary;
        }

        foreach ( $rows as $row ) {
            try {
                Cashback_Promocodes_Tracker::record_click_internal(
                    (int) $row['promocode_id'],
                    (int) $row['product_id'] > 0 ? (int) $row['product_id'] : null,
                    (int) $row['user_id'],
                    (string) ( $row['ip_address'] ?? '' ),
                    (string) ( $row['user_agent'] ?? '' ),
                    (string) $row['click_id'],
                    'goto'
                );
                ++$summary['inserted'];
            } catch ( \Throwable $e ) {
                ++$summary['errors'];
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic.
                error_log( '[Cashback Promo Click Backfill] insert failed for click_id=' . $row['click_id'] . ': ' . $e->getMessage() );
            }
        }

        if ( $summary['inserted'] > 0 || $summary['errors'] > 0 ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Telemetry.
            error_log( sprintf(
                '[Cashback Promo Click Backfill] run summary: found=%d inserted=%d errors=%d',
                $summary['found'],
                $summary['inserted'],
                $summary['errors']
            ) );
        }

        return $summary;
    }
}
