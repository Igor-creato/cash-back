<?php
/**
 * Audit-trail cron-сверка ledger ↔ audit_log (E2E follow-up P2-A1-1, вариант B).
 *
 * Закрывает defense-in-depth gap: если кто-то пишет в `cashback_balance_ledger`
 * в обход plugin handler'а (raw SQL, неавторизованный путь, баг), audit_log
 * остаётся пустым и админ не узнаёт об операции.
 *
 * Раз в час (Action Scheduler) cron сверяет ledger ↔ audit_log за окно 25ч.
 * Для admin-driven типов (whitelist: adjustment, payout_*, ban_*) проверяется
 * наличие парной audit-записи в окне ±5 минут от ledger.created_at.
 * Webhook-driven типы (accrual, affiliate_*) пропускаются — там audit на
 * каждую операцию намеренно не пишется (worker'ы CPA-сети).
 *
 * Найденные orphan-записи:
 *   1. Лог в audit_log с action='ledger_entry_without_audit' (system actor),
 *      details содержит ledger_id, type, amount, user_id для расследования.
 *   2. Email админу через Cashback_Email_Sender::send_critical (bypass opt-out)
 *      с топ-10 orphan-записей. Один email за прогон, не спам.
 *
 * Job — read-only: НЕ пытается «починить» mismatch. Решение — за админом.
 *
 * @since 1.7.0 (E2E follow-up A1-1)
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cashback_Audit_Trail_Reconciliation {

    const HOOK_NAME    = 'cashback_audit_trail_reconciliation';
    const AS_GROUP     = 'cashback';
    const WINDOW_HOURS = 25;
    const MATCH_WINDOW_SECONDS = 300; // ±5 минут вокруг ledger.created_at
    const BATCH_LIMIT  = 500;
    const EMAIL_TOP_N  = 10;

    /**
     * Whitelist ledger-типов, для которых audit-запись обязательна.
     * webhook-driven типы (accrual, affiliate_*) — не в whitelist (audit
     * пишется агрегированно через api-sync runner, не за каждую запись).
     */
    const REQUIRED_TYPES = array(
        'adjustment',
        'payout_complete',
        'payout_cancel',
        'payout_declined',
        'ban_freeze',
        'ban_unfreeze',
    );

    public static function init(): void {
        add_action( self::HOOK_NAME, array( self::class, 'run_hook' ) );
        add_action( 'init', array( self::class, 'maybe_schedule' ) );
    }

    /** Void-обёртка для AS callback (тот же паттерн, что у Cashback_Balance_Reconciliation). */
    public static function run_hook(): void {
        self::run();
    }

    public static function maybe_schedule(): void {
        if (
            function_exists( 'as_has_scheduled_action' )
            && function_exists( 'as_schedule_recurring_action' )
            && ! as_has_scheduled_action( self::HOOK_NAME )
        ) {
            // Первый запуск — через 5 минут после init (не сразу, чтобы не
            // конкурировать с активацией plugin'а).
            as_schedule_recurring_action(
                time() + 5 * MINUTE_IN_SECONDS,
                HOUR_IN_SECONDS,
                self::HOOK_NAME,
                array(),
                self::AS_GROUP
            );
        }
    }

    /**
     * Выполняет сверку. Возвращает summary для логирования / тестов.
     *
     * @return array{scanned:int, orphans:int, window_hours:int}
     */
    public static function run(): array {
        global $wpdb;

        $ledger_table = $wpdb->prefix . 'cashback_balance_ledger';
        $audit_table  = $wpdb->prefix . 'cashback_audit_log';

        // Защита: если таблиц нет — выходим тихо.
        $ledger_exists = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $ledger_table
        ) );
        $audit_exists  = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $audit_table
        ) );
        if ( ! $ledger_exists || ! $audit_exists ) {
            return array( 'scanned' => 0, 'orphans' => 0, 'window_hours' => self::WINDOW_HOURS );
        }

        // Plain ASCII placeholders для IN-list (чтобы $wpdb->prepare обработал корректно).
        $type_placeholders = implode( ',', array_fill( 0, count( self::REQUIRED_TYPES ), '%s' ) );
        $match_window      = self::MATCH_WINDOW_SECONDS;

        // ledger entries за окно 25ч + whitelist + LEFT JOIN на audit за ±5 мин.
        // Если match-record НЕТ — это orphan (audit отсутствует там, где должен быть).
        // $type_placeholders / $match_window — числовые / ASCII константы (фиксированный
        // whitelist + integer), не пользовательский ввод. phpcs sniffer не отслеживает
        // array-form prepare() и interpolation констант — отсюда disable-блок.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $sql = $wpdb->prepare(
            "SELECT l.id, l.user_id, l.type, l.amount, l.payout_request_id, l.transaction_id, l.created_at
               FROM %i l
               LEFT JOIN %i a
                 ON a.created_at BETWEEN DATE_SUB(l.created_at, INTERVAL {$match_window} SECOND)
                                     AND DATE_ADD(l.created_at, INTERVAL {$match_window} SECOND)
                AND (
                    (a.entity_type = 'payout_request' AND a.entity_id = l.payout_request_id AND l.payout_request_id IS NOT NULL)
                 OR (a.entity_type = 'user'           AND a.entity_id = l.user_id)
                 OR (a.entity_type = 'transaction'    AND a.entity_id = l.transaction_id   AND l.transaction_id IS NOT NULL)
                )
              WHERE l.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
                AND l.type IN ({$type_placeholders})
                AND a.id IS NULL
              ORDER BY l.created_at DESC
              LIMIT %d",
            array_merge(
                array( $ledger_table, $audit_table, self::WINDOW_HOURS ),
                self::REQUIRED_TYPES,
                array( self::BATCH_LIMIT )
            )
        );

        $orphans = $wpdb->get_results( $sql, ARRAY_A );
        // phpcs:enable
        if ( ! is_array( $orphans ) ) {
            $orphans = array();
        }

        if ( empty( $orphans ) ) {
            return array(
                'scanned'      => 0,
                'orphans'      => 0,
                'window_hours' => self::WINDOW_HOURS,
            );
        }

        $orphan_count = count( $orphans );

        // 1) Аудит-запись для каждого orphan'а.
        if ( class_exists( 'Cashback_Encryption' ) ) {
            foreach ( $orphans as $row ) {
                Cashback_Encryption::write_audit_log(
                    'ledger_entry_without_audit',
                    0, // system actor
                    'ledger',
                    (int) $row['id'],
                    array(
                        'type'              => (string) $row['type'],
                        'amount'            => (string) $row['amount'],
                        'user_id'           => (int) $row['user_id'],
                        'payout_request_id' => isset( $row['payout_request_id'] ) ? (int) $row['payout_request_id'] : 0,
                        'transaction_id'    => isset( $row['transaction_id'] ) ? (int) $row['transaction_id'] : 0,
                        'ledger_created_at' => (string) $row['created_at'],
                        'window_hours'      => self::WINDOW_HOURS,
                    )
                );
            }
        }

        // 2) Email админу — один на прогон, top-N orphan'ов.
        self::send_admin_alert( $orphans );

        return array(
            'scanned'      => $orphan_count,
            'orphans'      => $orphan_count,
            'window_hours' => self::WINDOW_HOURS,
        );
    }

    /**
     * @param array<int,array<string,mixed>> $orphans
     */
    private static function send_admin_alert( array $orphans ): void {
        if ( ! class_exists( 'Cashback_Email_Sender' ) ) {
            return;
        }

        $admin_email = (string) get_option( 'admin_email' );
        if ( $admin_email === '' || ! is_email( $admin_email ) ) {
            return;
        }

        $top   = array_slice( $orphans, 0, self::EMAIL_TOP_N );
        $total = count( $orphans );

        $rows = array();
        foreach ( $top as $row ) {
            $rows[] = sprintf(
                '#%d  %s  user_id=%d  amount=%s  at=%s',
                (int) $row['id'],
                (string) $row['type'],
                (int) $row['user_id'],
                (string) $row['amount'],
                (string) $row['created_at']
            );
        }

        $body = sprintf(
            "Audit-trail сверка нашла %d ledger-записей без парной audit-log:\n\n%s\n\n%s",
            $total,
            implode( "\n", $rows ),
            __( 'Подробности — в admin → Аудит-лог (action=ledger_entry_without_audit).', 'cashback-plugin' )
        );

        Cashback_Email_Sender::get_instance()->send_critical(
            $admin_email,
            sprintf(
                /* translators: %d: count of orphan ledger entries */
                __( '[Cashback] Audit-trail mismatch: %d записей без audit-log', 'cashback-plugin' ),
                $total
            ),
            $body,
            0
        );
    }
}
