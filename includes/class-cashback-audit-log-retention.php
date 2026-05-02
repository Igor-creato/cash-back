<?php
/**
 * Daily retention cleanup для cashback_audit_log (P2 prod-readiness, 2026-05-02).
 *
 * Audit-log пишется бесконечно для админ-driven, system и user events
 * (Encryption::write_audit_log). Без cleanup-cron таблица растёт годами.
 *
 * Срок хранения: 1825 дней = 5 лет — нижняя граница, продиктованная
 * 152-ФЗ ст. 5 ч. 7 (минимум до достижения цели обработки) и 161-ФЗ
 * ст. 27 + НК ст. 23 (финансовая первичка). Override через filter
 * `cashback_audit_log_retention_days` (например, для регуляторов с более
 * длинными требованиями).
 *
 * Single-runner guard: MySQL GET_LOCK с timeout=0 — если другой DELETE
 * уже идёт, текущий run возвращает skipped без RELEASE_LOCK. Batch-LIMIT
 * 5000 защищает OLTP от длинного row-lock. AS-job daily, на пустой
 * таблице (или при retention'е, который ещё не накопил старых записей)
 * — no-op.
 *
 * Job — DESTRUCTIVE: явно помечен read-write на cashback_audit_log.
 * Другие таблицы (balance_ledger, consent_log, payout_requests) НЕ
 * трогает. Каждый прогон с deleted>0 пишет audit-запись
 * `audit_log_retention_purge` для метаданных (само-аудитируемая чистка).
 *
 * @since 1.7.1 (prod-readiness CONCERN C1)
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cashback_Audit_Log_Retention {

    public const HOOK_NAME      = 'cashback_audit_log_retention_cleanup';
    public const AS_GROUP       = 'cashback';
    public const RETENTION_DAYS = 1825;
    public const BATCH_LIMIT    = 5000;
    public const LOCK_NAME      = 'cashback_audit_log_retention';

    public static function init(): void {
        add_action( self::HOOK_NAME, array( self::class, 'run_hook' ) );
        add_action( 'init', array( self::class, 'maybe_schedule' ) );
    }

    /** Void-обёртка для AS callback (паттерн Cashback_Balance_Reconciliation). */
    public static function run_hook(): void {
        self::run();
    }

    public static function maybe_schedule(): void {
        if (
            function_exists( 'as_has_scheduled_action' )
            && function_exists( 'as_schedule_recurring_action' )
            && ! as_has_scheduled_action( self::HOOK_NAME )
        ) {
            // Первый запуск — через 5 минут после init, чтобы не конкурировать
            // с активацией plugin'а / другими AS-job'ами на старте.
            as_schedule_recurring_action(
                time() + 5 * MINUTE_IN_SECONDS,
                DAY_IN_SECONDS,
                self::HOOK_NAME,
                array(),
                self::AS_GROUP
            );
        }
    }

    /**
     * Удаляет audit-log записи старше retention. Защищён single-runner LOCK.
     *
     * @return array{deleted:int, retention_days:int, skipped:bool}
     */
    public static function run(): array {
        global $wpdb;

        $retention_days = (int) apply_filters(
            'cashback_audit_log_retention_days',
            self::RETENTION_DAYS
        );
        if ( $retention_days < 1 ) {
            // Defense-in-depth: запрет на нулевое/отрицательное retention.
            $retention_days = self::RETENTION_DAYS;
        }

        $audit_table = $wpdb->prefix . 'cashback_audit_log';

        // Защита: если таблицы нет — выходим тихо (паттерн audit-trail-reconciliation).
        $exists = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $audit_table
        ) );
        if ( ! $exists ) {
            return array(
                'deleted'        => 0,
                'retention_days' => $retention_days,
                'skipped'        => true,
            );
        }

        // Single-runner guard: MySQL GET_LOCK timeout=0.
        $lock_name = $wpdb->prefix . self::LOCK_NAME;
        $acquired  = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $lock_name,
            0
        ) );
        if ( $acquired !== 1 ) {
            // Другой прогон уже идёт — выходим без RELEASE.
            return array(
                'deleted'        => 0,
                'retention_days' => $retention_days,
                'skipped'        => true,
            );
        }

        try {
            $deleted = (int) $wpdb->query( $wpdb->prepare(
                'DELETE FROM %i
                 WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
                 LIMIT %d',
                $audit_table,
                $retention_days,
                self::BATCH_LIMIT
            ) );
        } finally {
            // RELEASE_LOCK всегда, даже при exception.
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }

        if ( $deleted > 0 && class_exists( 'Cashback_Encryption' ) ) {
            // Само-аудитируемая чистка: фиксируем сам факт purge для прозрачности.
            try {
                Cashback_Encryption::write_audit_log(
                    'audit_log_retention_purge',
                    0, // system actor
                    'audit_log',
                    null,
                    array(
                        'deleted'        => $deleted,
                        'retention_days' => $retention_days,
                        'batch_limit'    => self::BATCH_LIMIT,
                    )
                );
            } catch ( \Throwable $e ) {
                // Audit-write не критичен для самого retention-цикла. Не блокируем.
                unset( $e );
            }
        }

        return array(
            'deleted'        => $deleted,
            'retention_days' => $retention_days,
            'skipped'        => false,
        );
    }
}
