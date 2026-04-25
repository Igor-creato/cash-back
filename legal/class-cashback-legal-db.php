<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_DB
 *
 * Append-only журнал юридических согласий (152-ФЗ ст. 9 ч. 4 — обязанность
 * оператора подтвердить факт получения согласия). Также — точка миграции схемы.
 *
 * Таблица wp_cashback_consent_log хранит каждое юридически значимое действие:
 * granted (получено), revoked (отозвано), superseded (помечено устаревшим при
 * bump major-версии документа). Существующая запись никогда не апдейтится —
 * любая смена состояния = новая строка. Это защищает доказательную базу:
 * историю невозможно «переписать» в результате компрометации админки.
 *
 * Идемпотентность миграции:
 *   - SHOW TABLES guard перед CREATE.
 *   - cashback_legal_db_version в options для fast-path.
 *   - Повторный install_table() безопасен (CREATE TABLE IF NOT EXISTS).
 *
 * @since 1.3.0
 */
class Cashback_Legal_DB {

    public const TABLE_SLUG = 'cashback_consent_log';

    public const DB_VERSION_OPTION = 'cashback_legal_db_version';
    public const CURRENT_DB_VERSION = '1.0';

    /**
     * Возвращает полное имя таблицы с префиксом WP.
     */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    /**
     * Создание таблицы. Идемпотентно — CREATE TABLE IF NOT EXISTS.
     *
     * Вызывается из:
     *   - register_activation_hook (через Cashback_Legal_Pages_Installer::install)
     *   - maybe_run_migrations() в cashback-plugin.php (для existing installs без re-activation)
     */
    public static function install_table(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table           = self::table_name();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Static DDL ниже; имя таблицы из $wpdb->prefix, не user-controlled (ALTER TABLE %i ненадёжно с non-ASCII COMMENT'ами).
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL COMMENT 'NULL для гостей (cookie-баннер)',
            `consent_type` VARCHAR(32) NOT NULL COMMENT 'pd_processing|payment_pd|marketing|cookies|terms_offer|contact_form_pd',
            `action` VARCHAR(16) NOT NULL COMMENT 'granted|revoked|superseded',
            `document_version` VARCHAR(20) NOT NULL,
            `document_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 от текста на момент акцепта',
            `document_url` VARCHAR(500) DEFAULT NULL,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `user_agent` TEXT DEFAULT NULL,
            `request_id` CHAR(64) NOT NULL COMMENT 'idempotency key (uuid v4/v7 hex no dashes)',
            `source` VARCHAR(32) NOT NULL COMMENT 'registration|profile|payout|contact|cookies_banner|social_auth|reconsent_modal|admin_bump',
            `granted_at` DATETIME NOT NULL COMMENT 'UTC',
            `revoked_at` DATETIME DEFAULT NULL,
            `extra_meta` LONGTEXT DEFAULT NULL COMMENT 'JSON',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_request_id` (`request_id`),
            KEY `idx_user_type_time` (`user_id`, `consent_type`, `granted_at`),
            KEY `idx_type_time` (`consent_type`, `granted_at`),
            KEY `idx_action_type` (`action`, `consent_type`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Журнал юридических согласий (152-ФЗ ст.9 ч.4)';";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL with $wpdb->prefix.
        $result = $wpdb->query($sql);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($result === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log('[Cashback Legal] Failed to create consent_log table: ' . $wpdb->last_error);
            return;
        }

        update_option(self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION, false);
    }

    /**
     * Runtime-миграция (вызывается из CashbackPlugin::maybe_run_migrations).
     *
     * Fast-path по cashback_legal_db_version, аналог Cashback_Affiliate_DB::migrate_f22_003_attribution_model.
     */
    public static function migrate(): void {
        $current_version = (string) get_option(self::DB_VERSION_OPTION, '0.0');
        if (version_compare($current_version, self::CURRENT_DB_VERSION, '>=')) {
            return;
        }

        self::install_table();
    }

    /**
     * Низкоуровневый INSERT строки в журнал.
     *
     * Идемпотентность: UNIQUE на request_id отбрасывает дубликат, метод
     * возвращает false — повторный submit с тем же request_id безопасен.
     *
     * @param array<string, mixed> $row Поля таблицы (без id).
     * @return int|false ID вставленной строки или false при ошибке (включая duplicate request_id).
     */
    public static function insert_log_row( array $row ) {
        global $wpdb;

        $table = self::table_name();

        $defaults = array(
            'user_id'          => null,
            'consent_type'     => '',
            'action'           => 'granted',
            'document_version' => '1.0.0',
            'document_hash'    => '',
            'document_url'     => null,
            'ip_address'       => null,
            'user_agent'       => null,
            'request_id'       => '',
            'source'           => 'registration',
            'granted_at'       => gmdate('Y-m-d H:i:s'),
            'revoked_at'       => null,
            'extra_meta'       => null,
        );
        $row = array_merge($defaults, $row);

        // wpdb->insert защищает от SQLi автопрепарацией; UNIQUE на request_id
        // даст $wpdb->last_error при повторе — caller-у вернётся false.
        // Ошибки suppressим — duplicate key это легитимный idempotent case.
        $suppress = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert(
            $table,
            array(
                'user_id'          => $row['user_id'],
                'consent_type'     => (string) $row['consent_type'],
                'action'           => (string) $row['action'],
                'document_version' => (string) $row['document_version'],
                'document_hash'    => (string) $row['document_hash'],
                'document_url'     => $row['document_url'],
                'ip_address'       => $row['ip_address'],
                'user_agent'       => $row['user_agent'],
                'request_id'       => (string) $row['request_id'],
                'source'           => (string) $row['source'],
                'granted_at'       => (string) $row['granted_at'],
                'revoked_at'       => $row['revoked_at'],
                'extra_meta'       => $row['extra_meta'],
            ),
            array(
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            )
        );
        $wpdb->suppress_errors($suppress);

        if ($inserted === false) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Возвращает последнюю запись для (user_id, consent_type) с action='granted'
     * без последующего revoked/superseded (LATEST granted, не отменённый).
     *
     * @return array<string, mixed>|null
     */
    public static function get_last_active_granted( int $user_id, string $consent_type ): ?array {
        global $wpdb;
        $table = self::table_name();

        // Берём последний granted, потом проверяем что после него нет revoked/superseded
        // того же user_id+type с granted_at >= нашего.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i
                 WHERE user_id = %d AND consent_type = %s AND action = 'granted'
                 ORDER BY granted_at DESC, id DESC
                 LIMIT 1",
                $table,
                $user_id,
                $consent_type
            ),
            ARRAY_A
        );

        if (!is_array($row) || empty($row)) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $supersession = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM %i
                 WHERE user_id = %d AND consent_type = %s
                   AND action IN ('revoked', 'superseded')
                   AND (granted_at > %s OR (granted_at = %s AND id > %d))
                 LIMIT 1",
                $table,
                $user_id,
                $consent_type,
                (string) $row['granted_at'],
                (string) $row['granted_at'],
                (int) $row['id']
            )
        );

        if ($supersession) {
            return null;
        }

        return $row;
    }

    /**
     * Запрос журнала с фильтрами (для admin-страницы).
     *
     * @param array<string, mixed> $filters user_id|consent_type|action|date_from|date_to
     * @param int $limit
     * @param int $offset
     * @return array<int, array<string, mixed>>
     */
    public static function query_log( array $filters = array(), int $limit = 50, int $offset = 0 ): array {
        global $wpdb;
        $table = self::table_name();

        $where      = array( '1=1' );
        $where_args = array();

        if (!empty($filters['user_id'])) {
            $where[]      = 'user_id = %d';
            $where_args[] = (int) $filters['user_id'];
        }
        if (!empty($filters['consent_type'])) {
            $where[]      = 'consent_type = %s';
            $where_args[] = (string) $filters['consent_type'];
        }
        if (!empty($filters['action'])) {
            $where[]      = 'action = %s';
            $where_args[] = (string) $filters['action'];
        }
        if (!empty($filters['date_from'])) {
            $where[]      = 'granted_at >= %s';
            $where_args[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[]      = 'granted_at <= %s';
            $where_args[] = (string) $filters['date_to'];
        }

        $sql        = "SELECT * FROM `{$table}` WHERE " . implode(' AND ', $where) .
            ' ORDER BY granted_at DESC, id DESC LIMIT %d OFFSET %d';
        $where_args[] = max(1, $limit);
        $where_args[] = max(0, $offset);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Условия prepared через prepare().
        $rows = $wpdb->get_results($wpdb->prepare($sql, $where_args), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /**
     * Удаление таблицы (только для uninstall.php — ВАЖНО: не вызывать обычно;
     * журнал согласий хранится 3 года после отзыва по ст. 196 ГК).
     */
    public static function drop_table(): void {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Static DDL; имя таблицы из $wpdb->prefix, не user-controlled.
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        delete_option(self::DB_VERSION_OPTION);
    }
}
