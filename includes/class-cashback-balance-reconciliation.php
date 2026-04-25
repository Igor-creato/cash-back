<?php
/**
 * Ежедневная сверка баланса: ledger vs кэш (Группа 14, шаг E).
 *
 * Action Scheduler action `cashback_daily_balance_reconciliation` вызывает
 * Mariadb_Plugin::validate_user_balance_consistency() для батчей по 500
 * пользователей. Найденные расхождения пишет в cashback_audit_log через
 * Cashback_Encryption::write_audit_log (action = 'balance_consistency_mismatch').
 *
 * Job — read-only: НЕ пытается авто-чинить расхождения. Админ читает audit-log,
 * диагностирует, применяет корректировку через adjustment (Шаг F) или руками.
 *
 * Концепция пагинации per-run: сохраняем last_processed_user_id в option
 * 'cashback_balance_reconciliation_cursor'. При достижении конца таблицы
 * сбрасываем в 0 — следующий запуск пробегает сначала.
 *
 * @since 1.2.1 (Group 14)
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cashback_Balance_Reconciliation {

    const HOOK_NAME          = 'cashback_daily_balance_reconciliation';
    const AS_GROUP           = 'cashback';
    const BATCH_SIZE         = 500;
    const CURSOR_OPTION      = 'cashback_balance_reconciliation_cursor';
    const LAST_SUMMARY_OPT   = 'cashback_balance_reconciliation_last_summary';

    public static function init(): void {
        // Void-обёртка для action callback: PHPStan+phpstan-wordpress ругается на
        // do_action-коллбеки, возвращающие значение (actions ≠ filters). run() сам
        // возвращает summary-массив — используется из admin manual-run (Группа 15),
        // поэтому сигнатуру не меняем, а оборачиваем.
        add_action( self::HOOK_NAME, array( self::class, 'run_hook' ) );
        add_action( 'init', array( self::class, 'maybe_schedule' ) );
    }

    /**
     * Void-адаптер для WP action callback. Сам run() возвращает structured summary,
     * который нужен вызывающему коду (admin manual-run), но AS-hook игнорирует return.
     */
    public static function run_hook(): void {
        self::run();
    }

    public static function maybe_schedule(): void {
        if (
            function_exists( 'as_has_scheduled_action' )
            && function_exists( 'as_schedule_recurring_action' )
            && ! as_has_scheduled_action( self::HOOK_NAME )
        ) {
            // Первый запуск — через час после деплоя, чтобы не конкурировать с активацией.
            as_schedule_recurring_action(
                time() + HOUR_IN_SECONDS,
                DAY_IN_SECONDS,
                self::HOOK_NAME,
                array(),
                self::AS_GROUP
            );
        }
    }

    /**
     * Пробегает батч пользователей и фиксирует расхождения в audit-log.
     * Цикл: cursor → SELECT 500 профилей с id > cursor → validate_user_balance_consistency
     * на каждом → если mismatch, пишем audit-запись. Обновляем cursor. При пустом
     * батче — сбрасываем cursor и выходим (reconciliation round завершён).
     *
     * @return array{batch:int, mismatches:int, scanned:int, completed_round:bool}
     */
    public static function run(): array {
        global $wpdb;

        $profile_table = $wpdb->prefix . 'cashback_user_profile';
        $cursor        = (int) get_option( self::CURSOR_OPTION, 0 );

        if ( ! class_exists( 'Mariadb_Plugin' ) ) {
            return array( 'batch' => 0, 'mismatches' => 0, 'scanned' => 0, 'completed_round' => false );
        }

        $user_ids = $wpdb->get_col( $wpdb->prepare(
            'SELECT user_id FROM %i
             WHERE user_id > %d
               AND status != %s
             ORDER BY user_id ASC
             LIMIT %d',
            $profile_table,
            $cursor,
            'deleted',
            self::BATCH_SIZE
        ) );

        if ( empty( $user_ids ) ) {
            // Round завершён — сбрасываем cursor, фиксируем summary + сканируем
            // зависшие approved claims (Шаг B, Группа 14).
            update_option( self::CURSOR_OPTION, 0, false );
            $stale_claims = self::check_stale_approved_claims();
            $summary      = array(
                'finished_at'       => Cashback_Time::now_mysql(),
                'total_mismatches'  => (int) get_option( '_cashback_reconcil_run_mismatches', 0 ),
                'total_scanned'     => (int) get_option( '_cashback_reconcil_run_scanned', 0 ),
                'stale_approved_claims' => $stale_claims,
            );
            update_option( self::LAST_SUMMARY_OPT, $summary, false );
            delete_option( '_cashback_reconcil_run_mismatches' );
            delete_option( '_cashback_reconcil_run_scanned' );

            return array( 'batch' => 0, 'mismatches' => 0, 'scanned' => 0, 'completed_round' => true );
        }

        $mismatches = 0;
        $scanned    = 0;
        $last_id    = $cursor;

        foreach ( $user_ids as $uid ) {
            $uid = (int) $uid;
            ++$scanned;
            $last_id = $uid;

            $result = Mariadb_Plugin::validate_user_balance_consistency( $uid );

            if ( empty( $result['consistent'] ) ) {
                ++$mismatches;
                if ( class_exists( 'Cashback_Encryption' ) ) {
                    $details = array(
                        'issues' => $result['details']['issues'] ?? array(),
                        'ledger' => $result['details']['ledger'] ?? array(),
                        'cache'  => $result['details']['cache'] ?? array(),
                    );
                    Cashback_Encryption::write_audit_log(
                        'balance_consistency_mismatch',
                        0, // system actor
                        'user',
                        $uid,
                        $details
                    );
                }
            }
        }

        update_option( self::CURSOR_OPTION, $last_id, false );

        // Аккумулируем для summary (сбрасывается в конце round'а).
        update_option(
            '_cashback_reconcil_run_mismatches',
            (int) get_option( '_cashback_reconcil_run_mismatches', 0 ) + $mismatches,
            false
        );
        update_option(
            '_cashback_reconcil_run_scanned',
            (int) get_option( '_cashback_reconcil_run_scanned', 0 ) + $scanned,
            false
        );

        return array(
            'batch'           => count( $user_ids ),
            'mismatches'      => $mismatches,
            'scanned'         => $scanned,
            'completed_round' => false,
        );
    }

    /**
     * Находит approved claims, по которым за 14+ дней так и не появилась
     * парная transaction (ни через api-sync, ни через ручной INSERT админа).
     * Для каждого — audit-лог `claim_approved_no_transaction` (system actor).
     *
     * Бизнес-контракт (Шаг B плана): claim approved = только статус-маркер,
     * деньги приходят через api-sync из CPA-сети либо через ручной INSERT
     * админом. Если через 14 дней парной транзакции нет — значит процесс
     * сбоит, и нужна админская обработка.
     *
     * @return int Кол-во найденных alerts.
     */
    public static function check_stale_approved_claims(): int {
        global $wpdb;

        $claims_table = $wpdb->prefix . 'cashback_claims';
        $tx_table     = $wpdb->prefix . 'cashback_transactions';

        // Проверяем наличие таблицы — plugin-install мог пропустить claims-модуль.
        $claims_exists = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $claims_table
        ) );
        if ( ! $claims_exists ) {
            return 0;
        }

        // ВАЖНО: wp_cashback_claims PK называется claim_id (не id),
        // сумма заказа — order_value (не amount). См. claims/class-claims-db.php.
        // Баг из Группы 14: раньше здесь были c.id/c.amount → AS-job валился
        // на этом запросе и никогда не писал claim_approved_no_transaction.
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT c.claim_id, c.user_id, c.click_id, c.merchant_name, c.order_value, c.updated_at
             FROM %i c
             LEFT JOIN %i t ON t.user_id = c.user_id AND t.click_id = c.click_id
             WHERE c.status = %s
               AND c.updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY)
               AND t.id IS NULL
             LIMIT 500',
            $claims_table,
            $tx_table,
            'approved'
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            return 0;
        }

        $count = 0;
        foreach ( $rows as $row ) {
            if ( class_exists( 'Cashback_Encryption' ) ) {
                Cashback_Encryption::write_audit_log(
                    'claim_approved_no_transaction',
                    0, // system actor
                    'claim',
                    (int) $row['claim_id'],
                    array(
                        'user_id'       => (int) $row['user_id'],
                        'click_id'      => (string) $row['click_id'],
                        'merchant_name' => (string) $row['merchant_name'],
                        'amount'        => (string) $row['order_value'],
                        'approved_at'   => (string) $row['updated_at'],
                        'days_stale'    => '>=14',
                    )
                );
                ++$count;
            }
        }

        return $count;
    }
}
