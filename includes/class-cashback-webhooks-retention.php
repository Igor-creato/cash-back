<?php

/**
 * Cashback_Webhooks_Retention — daily cron, удаляет старые processed
 * записи из `cashback_webhooks`.
 *
 * Closes audit-finding P-3 (Advcake v4.3.4): таблица webhooks без retention
 * растёт бесконечно. Receiver пишет каждый постбэк (transaction + partner_status),
 * на нагрузке 1-10K/день за 12 мес — 1-4M rows.
 *
 * Логика: DELETE rows где `received_at < UTC_NOW() - INTERVAL N DAY` И
 * `processing_status IS NOT NULL` (in-flight rows с status=NULL не трогаем —
 * это webhook'и которые AS-handler ещё не обработал; их retention делает
 * сам handler через mark_row).
 *
 * Конфигурируется через filter `cashback_webhooks_retention_days` (default 90,
 * min 7 для защиты от случайного truncate).
 *
 * @package CashbackPlugin
 * @since   12.4.0
 */

declare(strict_types=1);

if (!defined('ABSPATH') && !defined('PHPUNIT_RUNNING')) {
    exit;
}

if (class_exists('Cashback_Webhooks_Retention', false)) {
    return;
}

final class Cashback_Webhooks_Retention {

    public const HOOK_NAME      = 'cashback_webhooks_retention';
    public const CRON_GROUP     = 'cashback';
    public const DEFAULT_DAYS   = 90;
    public const MIN_DAYS       = 7;
    public const BATCH_LIMIT    = 5000;
    public const SAFETY_LOOPS   = 100;  // максимум 500K rows за прогон

    public static function register(): void {
        add_action(self::HOOK_NAME, array( self::class, 'run' ));

        if (!function_exists('as_schedule_recurring_action') || !function_exists('as_has_scheduled_action')) {
            return;
        }
        if (!as_has_scheduled_action(self::HOOK_NAME, array(), self::CRON_GROUP)) {
            // 04:00 UTC завтра, период = сутки. as_schedule_recurring_action
            // сама пересчитает на следующий тик после run().
            $next_run = strtotime('tomorrow 04:00 UTC');
            if ($next_run === false) {
                $next_run = time() + DAY_IN_SECONDS;
            }
            as_schedule_recurring_action($next_run, DAY_IN_SECONDS, self::HOOK_NAME, array(), self::CRON_GROUP);
        }
    }

    /**
     * Прогон retention. Возвращает суммарно удалённых rows.
     */
    public static function run(): int {
        global $wpdb;

        $days = (int) apply_filters('cashback_webhooks_retention_days', self::DEFAULT_DAYS);
        if ($days < self::MIN_DAYS) {
            $days = self::MIN_DAYS;
        }

        $table = $wpdb->prefix . 'cashback_webhooks';
        $deleted_total = 0;

        for ($i = 0; $i < self::SAFETY_LOOPS; $i++) {
            // %i placeholder для table-name (WP 6.2+) — closes phpcs NotPrepared.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $r = $wpdb->query($wpdb->prepare(
                'DELETE FROM %i
                  WHERE received_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
                    AND processing_status IS NOT NULL
                  LIMIT %d',
                $table,
                $days,
                self::BATCH_LIMIT
            ));
            if ($r === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
                error_log('[Cashback Webhooks Retention] DELETE failed: ' . $wpdb->last_error);
                break;
            }
            $deleted_total += (int) $r;
            if ((int) $r < self::BATCH_LIMIT) {
                break;
            }
        }

        if ($deleted_total > 0) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log(sprintf(
                '[Cashback Webhooks Retention] Deleted=%d (threshold=%d days)',
                $deleted_total,
                $days
            ));
        }

        return $deleted_total;
    }
}
