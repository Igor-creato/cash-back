<?php

/**
 * Cashback_Logs_Retention — ежедневная батч-очистка лог/аудит/очередь-таблиц,
 * которые до сих пор только INSERT'ились и росли бесконечно.
 *
 * Закрывает дыру retention'а для 11 таблиц (4 группы по решению владельца):
 *   - Логи интеграций: sync_log, click_log, click_sessions, promocode_clicks
 *   - Партнёрка:       affiliate_clicks, affiliate_audit
 *   - Операционные:    cron_state, rate_history, claim_events
 *   - Рассылки:        broadcast_queue, broadcast_campaigns
 *
 * НЕ трогает: финансы (balance_ledger / transactions / payout_requests),
 * пользователей, fraud_alerts/fraud_signals (open/confirmed — материалы
 * расследований; dismissed уже чистятся отдельно 90d), consent_log
 * (намеренно append-only по 152-ФЗ).
 *
 * Срок хранения: единый дефолт 180 дней. Override:
 *   - глобально:  filter `cashback_logs_retention_days` (int)
 *   - по таблице: filter `cashback_logs_retention_days_{key}` (int)
 * Оба клампятся к MIN_DAYS=30 (защита от случайного near-truncate;
 * чтобы выключить очистку без деплоя — вернуть фильтром PHP_INT_MAX).
 *
 * Логика per-table: батчевый DELETE rows старше N дней по своей date-колонке,
 * с extra_where для защиты «активных» данных (open click-session, running
 * cron, активная заявка, pending broadcast-получатель). LIMIT BATCH_LIMIT
 * защищает OLTP от длинного row-lock; SAFETY_LOOPS ограничивает прогон.
 *
 * Single-runner guard: MySQL GET_LOCK timeout=0 на весь прогон (паттерн
 * Cashback_Audit_Log_Retention). Отсутствующие таблицы (модуль ещё не
 * создал) — тихий skip. AS-job daily @ 04:30 UTC (сдвиг от webhooks 04:00).
 *
 * @package CashbackPlugin
 * @since   4.4.15
 */

declare(strict_types=1);

if (!defined('ABSPATH') && !defined('PHPUNIT_RUNNING')) {
    exit;
}

if (class_exists('Cashback_Logs_Retention', false)) {
    return;
}

final class Cashback_Logs_Retention {

    public const HOOK_NAME    = 'cashback_logs_retention';
    public const CRON_GROUP   = 'cashback';
    public const DEFAULT_DAYS = 180;
    public const MIN_DAYS     = 30;
    public const BATCH_LIMIT  = 5000;
    public const SAFETY_LOOPS = 100;   // максимум 500K rows на таблицу за прогон
    public const LOCK_NAME    = 'cashback_logs_retention';

    /**
     * Конфиг очистки. Все значения — внутренние константы (не user input):
     *   table_suffix — имя таблицы без $wpdb->prefix
     *   date_col     — DATETIME-колонка, по которой считается возраст
     *   extra_where  — доп. SQL-условие (без ведущего AND) или '' —
     *                  защищает «активные»/in-flight строки от удаления
     *
     * @return array<string, array{table_suffix:string, date_col:string, extra_where:string}>
     */
    private static function config(): array {
        global $wpdb;

        $claims_table = $wpdb->prefix . 'cashback_claims';

        return array(
            // --- Логи интеграций ---
            'sync_log'            => array(
                'table_suffix' => 'cashback_sync_log',
                'date_col'     => 'synced_at',
                'extra_where'  => '',
            ),
            'click_log'           => array(
                // PHP-фолбэк к MySQL-событию cashback_ev_cleanup_click_log (6 мес):
                // на хостингах с выключенными MySQL-events это единственная очистка.
                'table_suffix' => 'cashback_click_log',
                'date_col'     => 'created_at',
                'extra_where'  => '',
            ),
            'click_sessions'      => array(
                'table_suffix' => 'cashback_click_sessions',
                'date_col'     => 'created_at',
                // Не удаляем открытые окна активации (ещё могут конвертнуться).
                'extra_where'  => "status <> 'active'",
            ),
            'promocode_clicks'    => array(
                'table_suffix' => 'cashback_promocode_clicks',
                'date_col'     => 'created_at',
                'extra_where'  => '',
            ),
            // --- Партнёрка ---
            'affiliate_clicks'    => array(
                'table_suffix' => 'cashback_affiliate_clicks',
                'date_col'     => 'created_at',
                'extra_where'  => '',
            ),
            'affiliate_audit'     => array(
                'table_suffix' => 'cashback_affiliate_audit',
                'date_col'     => 'created_at',
                'extra_where'  => '',
            ),
            // --- Операционные ---
            'cron_state'          => array(
                'table_suffix' => 'cashback_cron_state',
                // started_at NOT NULL всегда; finished_at NULL у упавших прогонов.
                // Чистим по started_at, защищая только реально running-этапы.
                'date_col'     => 'started_at',
                'extra_where'  => "status <> 'running'",
            ),
            'rate_history'        => array(
                'table_suffix' => 'cashback_rate_history',
                'date_col'     => 'created_at',
                'extra_where'  => '',
            ),
            'claim_events'        => array(
                'table_suffix' => 'cashback_claim_events',
                'date_col'     => 'created_at',
                // Только история заявок в терминальном статусе (approved/declined).
                // Активные (draft/submitted/sent_to_network) сохраняют полный лог.
                // $claims_table из $wpdb->prefix — безопасно (как все DDL плагина).
                'extra_where'  => "claim_id IN (SELECT claim_id FROM `{$claims_table}` WHERE status IN ('approved','declined'))",
            ),
            // --- Рассылки ---
            'broadcast_queue'     => array(
                'table_suffix' => 'cashback_broadcast_queue',
                // processed_at NULL у pending-получателей → '< дата' их исключает.
                'date_col'     => 'processed_at',
                'extra_where'  => '',
            ),
            'broadcast_campaigns' => array(
                'table_suffix' => 'cashback_broadcast_campaigns',
                // completed_at NULL у незавершённых кампаний → '< дата' их исключает.
                'date_col'     => 'completed_at',
                'extra_where'  => '',
            ),
        );
    }

    public static function register(): void {
        add_action(self::HOOK_NAME, array( self::class, 'run_hook' ));

        if (!function_exists('as_schedule_recurring_action') || !function_exists('as_has_scheduled_action')) {
            return;
        }
        if (!as_has_scheduled_action(self::HOOK_NAME, array(), self::CRON_GROUP)) {
            // 04:30 UTC завтра — сдвиг от Cashback_Webhooks_Retention (04:00),
            // чтобы не конкурировать за disk I/O на крупных DELETE.
            $next_run = strtotime('tomorrow 04:30 UTC');
            if ($next_run === false) {
                $next_run = time() + DAY_IN_SECONDS;
            }
            as_schedule_recurring_action($next_run, DAY_IN_SECONDS, self::HOOK_NAME, array(), self::CRON_GROUP);
        }
    }

    /**
     * Action Scheduler callback (void). Wrapper над {@see run()} для контракта
     * «Action callback returns nothing» (PHPStan rule).
     */
    public static function run_hook(): void {
        self::run();
    }

    /**
     * Прогон retention по всем таблицам конфига.
     *
     * @return array<string, int> [table_key => deleted_rows] (skipped — отсутствует в массиве)
     */
    public static function run(): array {
        global $wpdb;

        $default_days = (int) apply_filters('cashback_logs_retention_days', self::DEFAULT_DAYS);

        // Single-runner guard на весь прогон.
        $lock_name = $wpdb->prefix . self::LOCK_NAME;
        $acquired  = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 0));
        if ($acquired !== 1) {
            return array();
        }

        $results = array();

        try {
            foreach (self::config() as $key => $cfg) {
                $table = $wpdb->prefix . $cfg['table_suffix'];

                // Таблицы может не быть (модуль не активирован / minimal install).
                $exists = $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                    $table
                ));
                if (!$exists) {
                    continue;
                }

                $days = (int) apply_filters("cashback_logs_retention_days_{$key}", $default_days);
                if ($days < self::MIN_DAYS) {
                    $days = self::MIN_DAYS;
                }

                // $days уже clamped к MIN_DAYS (int) — инлайним прямо в SQL,
                // чтобы не плодить %d-плейсхолдер внутри интерполируемого
                // $where (phpcs не видит его → miscount + ложный NotPrepared).
                $where = sprintf(
                    '`%s` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)',
                    $cfg['date_col'],
                    $days
                );
                if ($cfg['extra_where'] !== '') {
                    $where .= ' AND ' . $cfg['extra_where'];
                }

                $deleted_total = 0;
                for ($i = 0; $i < self::SAFETY_LOOPS; $i++) {
                    // $where собран из внутренних констант self::config()
                    // (date_col/extra_where) + clamped int $days — без user input.
                    // Имя таблицы через %i, LIMIT через %d.
                    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    $r = $wpdb->query($wpdb->prepare(
                        "DELETE FROM %i WHERE {$where} LIMIT %d",
                        $table,
                        self::BATCH_LIMIT
                    ));
                    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    if ($r === false) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
                        error_log(sprintf(
                            '[Cashback Logs Retention] DELETE failed for %s: %s',
                            $key,
                            $wpdb->last_error
                        ));
                        break;
                    }
                    $deleted_total += (int) $r;
                    if ((int) $r < self::BATCH_LIMIT) {
                        break;
                    }
                }

                if ($deleted_total > 0) {
                    $results[$key] = $deleted_total;
                }
            }
        } finally {
            // RELEASE_LOCK всегда, даже при exception.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }

        if (!empty($results)) {
            $summary = array();
            foreach ($results as $k => $n) {
                $summary[] = "{$k}={$n}";
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log(sprintf(
                '[Cashback Logs Retention] Deleted: %s (default_threshold=%d days)',
                implode(' ', $summary),
                $default_days
            ));
        }

        return $results;
    }
}
