<?php
/**
 * Garbage collector для таблицы cashback_rate_limit_counters (Группа 7 ADR, шаг 10).
 *
 * Чистит строки с истекшим окном (expires_at < UTC_TIMESTAMP()) — кумулятивно они не нужны,
 * при следующем increment() записи пересоздаются с новым window_started_at.
 *
 * Вызывается hourly через WP-Cron (действие cashback_rate_limit_gc_cron).
 * Batch-лимит 5000 строк за запуск — защита от OLTP-лока на больших таблицах.
 *
 * @package Cashback
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Cashback_Rate_Limit_GC')) {
    final class Cashback_Rate_Limit_GC {
        public const BATCH_LIMIT = 5000;

        /**
         * Удалить expired-строки. Возвращает кол-во удалённых записей.
         *
         * @param object   $wpdb wpdb-совместимый объект с методами query/prepare/prefix.
         * @param int|null $now  Опциональный override текущего времени (для тестов).
         */
        public static function collect( object $wpdb, ?int $now = null ): int {
            if (!class_exists('Cashback_Rate_Limit_Migration')) {
                require_once __DIR__ . '/class-cashback-rate-limit-migration.php';
            }

            $now   = $now ?? time();
            $table = $wpdb->prefix . \Cashback_Rate_Limit_Migration::TABLE_SUFFIX;

            $sql = sprintf(
                'DELETE FROM `%s` WHERE expires_at < %%d LIMIT %%d',
                str_replace('`', '', $table)
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql подготовлен через prepare().
            $result = $wpdb->query($wpdb->prepare($sql, $now, self::BATCH_LIMIT));

            return (int) $result;
        }

        /**
         * WP-Cron handler: batch-GC для expired counters.
         */
        public static function cron_handler(): void {
            global $wpdb;
            self::collect($wpdb);
        }
    }
}
