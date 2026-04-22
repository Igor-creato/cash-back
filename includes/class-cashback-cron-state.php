<?php
/**
 * Checkpoint-хранилище для cashback_api_sync cron (F-8-005, Group 8 Step 3).
 *
 * Каждый прогон run_sync() получает единый run_id (UUID v7), каждый из 5 этапов
 * записывает start / finish в таблицу cashback_cron_state. Используется для:
 *   - мониторинга (admin-страница «Кешбэк → Cron History»);
 *   - диагностики partial-failure cron'а;
 *   - SLA-метрик (duration по этапам).
 *
 * Помни: каждый этап run_sync() уже атомарен сам по себе (Step 1 + Step 2).
 * Этот helper НЕ вводит новую транзакционность — только observability.
 *
 * @package CashbackPlugin
 * @since   5.0.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Cron_State {

    /**
     * Имя таблицы (без префикса).
     */
    public const TABLE = 'cashback_cron_state';

    /**
     * Option-флаг idempotent-миграции.
     */
    public const MIGRATION_OPTION = 'cashback_cron_state_v1_applied';

    /**
     * Сгенерировать новый run_id (UUIDv7 без дефисов).
     *
     * Используется один раз в начале run_sync() — все последующие begin_stage()
     * получают тот же run_id, чтобы весь прогон группировался в admin-истории.
     */
    public static function begin_run(): string {
        return cashback_generate_uuid7(false);
    }

    /**
     * Открыть запись этапа (status=running, started_at=NOW).
     *
     * @param string $run_id UUIDv7 прогона.
     * @param string $stage  Идентификатор этапа: background_sync / auto_transfer /
     *                       process_ready / affiliate_pending / check_campaigns.
     * @return int ID строки cashback_cron_state (для finish_stage). 0 при ошибке INSERT.
     */
    public static function begin_stage( string $run_id, string $stage ): int {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        $ok = $wpdb->insert(
            $table,
            array(
                'run_id'     => $run_id,
                'stage'      => $stage,
                'status'     => 'running',
                'started_at' => current_time('mysql'),
            ),
            array( '%s', '%s', '%s', '%s' )
        );

        if ($ok === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log(sprintf(
                '[Cashback Cron State] begin_stage(%s, %s) INSERT failed: %s',
                $run_id,
                $stage,
                (string) $wpdb->last_error
            ));
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Закрыть запись этапа (status=success|failed, finished_at, duration_ms, metrics, error).
     *
     * @param int    $state_id ID из begin_stage().
     * @param string $status   'success' | 'failed'.
     * @param array  $metrics  Произвольные метрики этапа (кодируются в JSON).
     * @param string $error    Текст ошибки (только для failed).
     */
    public static function finish_stage( int $state_id, string $status, array $metrics = array(), string $error = '' ): void {
        if ($state_id <= 0) {
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT started_at FROM %i WHERE id = %d',
            $table,
            $state_id
        ), ARRAY_A);

        $duration_ms = null;
        if (! empty($row['started_at'])) {
            $started_ts = strtotime((string) $row['started_at']);
            $now_ts     = strtotime(current_time('mysql'));
            if ($started_ts !== false && $now_ts !== false) {
                $duration_ms = max(0, ( $now_ts - $started_ts ) * 1000);
            }
        }

        $metrics_json = wp_json_encode($metrics);
        if (! is_string($metrics_json)) {
            $metrics_json = '{}';
        }

        $wpdb->update(
            $table,
            array(
                'status'        => ( $status === 'success' ? 'success' : 'failed' ),
                'finished_at'   => current_time('mysql'),
                'duration_ms'   => $duration_ms,
                'metrics_json'  => $metrics_json,
                'error_message' => ( $error !== '' ? $error : null ),
            ),
            array( 'id' => $state_id ),
            array( '%s', '%s', '%d', '%s', '%s' ),
            array( '%d' )
        );

        if ($wpdb->last_error) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log(sprintf(
                '[Cashback Cron State] finish_stage(%d) UPDATE failed: %s',
                $state_id,
                (string) $wpdb->last_error
            ));
        }
    }

    /**
     * Получить последние N прогонов (строки из cashback_cron_state).
     *
     * Используется admin-страницей «Cron History».
     *
     * @param int $limit Максимум строк.
     * @return array Список строк (id, run_id, stage, status, started_at, finished_at, duration_ms, metrics_json, error_message).
     */
    public static function get_recent_runs( int $limit = 50 ): array {
        global $wpdb;

        $limit = max(1, min($limit, 500));
        $table = $wpdb->prefix . self::TABLE;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, run_id, stage, status, started_at, finished_at, duration_ms, metrics_json, error_message
             FROM %i
             ORDER BY started_at DESC, id DESC
             LIMIT %d',
            $table,
            $limit
        ), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Подсчитать общее число строк в таблице (для пагинации admin-страницы).
     */
    public static function count_total(): int {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
    }

    /**
     * Idempotent миграция: CREATE TABLE IF NOT EXISTS cashback_cron_state.
     *
     * Вызывается на plugins_loaded (для existing installations без реактивации)
     * и через Mariadb_Plugin::activate() (для новых установок — уже покрыто
     * create_tables() в mariadb.php).
     */
    public static function migrate_v1(): void {
        global $wpdb;

        if (get_option(self::MIGRATION_OPTION) === 'yes') {
            return;
        }

        $table           = $wpdb->prefix . self::TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `run_id` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'UUIDv7 всего прогона run_sync (один на 5 этапов)',
            `stage` varchar(64) NOT NULL COMMENT 'Идентификатор этапа cron',
            `status` enum('running','success','failed') NOT NULL DEFAULT 'running',
            `started_at` datetime NOT NULL,
            `finished_at` datetime DEFAULT NULL,
            `duration_ms` int(11) unsigned DEFAULT NULL,
            `metrics_json` longtext DEFAULT NULL,
            `error_message` text DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_run_id` (`run_id`),
            KEY `idx_started_at` (`started_at`),
            KEY `idx_stage_status` (`stage`,`status`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Checkpoint-история прогонов cashback_api_sync';";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static DDL, $wpdb->prefix is validated internally.
        $wpdb->query($sql);

        update_option(self::MIGRATION_OPTION, 'yes', false);
    }
}
