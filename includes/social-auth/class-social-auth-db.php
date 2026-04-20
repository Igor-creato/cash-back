<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Управление таблицами модуля социальной авторизации.
 *
 * Таблицы:
 *  - cashback_social_links   — связка WP user ↔ provider/external_id
 *  - cashback_social_tokens  — refresh_token (AES-256-GCM) для каждой связки
 *  - cashback_social_pending — промежуточные состояния (confirm_link, email_prompt, email_verify)
 *
 * @since 1.1.0
 */
class Cashback_Social_Auth_DB {

    /**
     * Имя таблицы связок.
     */
    public static function table_links(): string {
        global $wpdb;
        return $wpdb->prefix . 'cashback_social_links';
    }

    /**
     * Имя таблицы токенов.
     */
    public static function table_tokens(): string {
        global $wpdb;
        return $wpdb->prefix . 'cashback_social_tokens';
    }

    /**
     * Имя таблицы pending-состояний.
     */
    public static function table_pending(): string {
        global $wpdb;
        return $wpdb->prefix . 'cashback_social_pending';
    }

    /**
     * Создать/обновить все таблицы модуля.
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $t_links         = self::table_links();
        $t_tokens        = self::table_tokens();
        $t_pending       = self::table_pending();

        // dbDelta требует КОНКРЕТНОГО форматирования: не менять отступы и пробелы вокруг `KEY`.
        $sql_links = "CREATE TABLE {$t_links} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            provider varchar(16) NOT NULL,
            sub_provider varchar(16) DEFAULT NULL,
            external_id varchar(64) NOT NULL,
            email_at_link_time varchar(255) DEFAULT NULL,
            display_name varchar(255) DEFAULT NULL,
            avatar_url varchar(512) DEFAULT NULL,
            linked_at datetime NOT NULL,
            last_login_at datetime DEFAULT NULL,
            link_ip varchar(45) DEFAULT NULL,
            link_user_agent varchar(255) DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_prov_ext (provider, external_id),
            KEY idx_user (user_id)
        ) {$charset_collate};";

        $sql_tokens = "CREATE TABLE {$t_tokens} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            link_id bigint(20) unsigned NOT NULL,
            refresh_token_encrypted text NOT NULL,
            access_token_expires_at datetime DEFAULT NULL,
            scopes varchar(255) DEFAULT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_link (link_id)
        ) {$charset_collate};";

        $sql_pending = "CREATE TABLE {$t_pending} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token char(64) NOT NULL,
            kind varchar(16) NOT NULL,
            payload_json longtext NOT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            consumed_at datetime DEFAULT NULL,
            ip varchar(45) DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_token (token),
            KEY idx_expires (expires_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_links);
        dbDelta($sql_tokens);
        dbDelta($sql_pending);

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log('[cashback-social] DB tables created/updated');
    }

    /**
     * Удалить таблицы (используется из uninstall).
     */
    public static function drop_tables(): void {
        global $wpdb;
        $t_pending = self::table_pending();
        $t_tokens  = self::table_tokens();
        $t_links   = self::table_links();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Uninstall: DROP TABLE with trusted prefixed names.
        $wpdb->query("DROP TABLE IF EXISTS {$t_pending}");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Uninstall: DROP TABLE with trusted prefixed names.
        $wpdb->query("DROP TABLE IF EXISTS {$t_tokens}");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Uninstall: DROP TABLE with trusted prefixed names.
        $wpdb->query("DROP TABLE IF EXISTS {$t_links}");
    }

    // =========================================================================
    // Links
    // =========================================================================

    /**
     * Найти связку по provider + external_id.
     *
     * @return array<string, mixed>|null
     */
    public static function find_link( string $provider, string $external_id ): ?array {
        global $wpdb;
        $table = self::table_links();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only helper for social auth flow.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
                "SELECT * FROM {$table} WHERE provider = %s AND external_id = %s LIMIT 1",
                $provider,
                $external_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Найти связку по id.
     *
     * @return array<string, mixed>|null
     */
    public static function get_link( int $link_id ): ?array {
        global $wpdb;
        $table = self::table_links();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only helper for social auth flow.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
                "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
                $link_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Получить все связки пользователя.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_links_for_user( int $user_id ): array {
        global $wpdb;
        $table = self::table_links();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only helper for social auth flow.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY linked_at ASC",
                $user_id
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * Создать связку.
     *
     * @param array<string, mixed> $data
     * @return int link_id (0 при ошибке)
     */
    public static function save_link( array $data ): int {
        global $wpdb;

        $defaults = array(
            'user_id'            => 0,
            'provider'           => '',
            'sub_provider'       => null,
            'external_id'        => '',
            'email_at_link_time' => null,
            'display_name'       => null,
            'avatar_url'         => null,
            'linked_at'          => current_time('mysql'),
            'last_login_at'      => null,
            'link_ip'            => null,
            'link_user_agent'    => null,
        );

        $data = array_merge($defaults, $data);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned table.
        $ok = $wpdb->insert(
            self::table_links(),
            $data,
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Обновить last_login_at (и опционально link_ip/link_user_agent) для связки.
     *
     * Если передана непустая строка $ip — обновится link_ip; аналогично $user_agent.
     * Пустые строки трактуются как «не менять».
     */
    public static function touch_last_login( int $link_id, string $ip = '', string $user_agent = '' ): void {
        global $wpdb;

        if ($link_id <= 0) {
            return;
        }

        $data    = array( 'last_login_at' => current_time('mysql') );
        $formats = array( '%s' );

        if ($ip !== '') {
            $data['link_ip'] = substr($ip, 0, 45);
            $formats[]       = '%s';
        }
        if ($user_agent !== '') {
            $data['link_user_agent'] = substr($user_agent, 0, 255);
            $formats[]               = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned table.
        $wpdb->update(
            self::table_links(),
            $data,
            array( 'id' => $link_id ),
            $formats,
            array( '%d' )
        );
    }

    /**
     * Удалить связку и каскадно связанный refresh_token в единой транзакции.
     * Вызывающие не обязаны отдельно звать Token_Store::delete() — каскад
     * гарантирован здесь, чтобы после unlink не оставалось orphaned секретов.
     */
    public static function delete_link( int $link_id ): bool {
        global $wpdb;

        if ($link_id <= 0) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction boundary.
        $wpdb->query('START TRANSACTION');

        try {
            if (class_exists('Cashback_Social_Auth_Token_Store')) {
                Cashback_Social_Auth_Token_Store::delete($link_id);
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned table.
            $ok = $wpdb->delete(
                self::table_links(),
                array( 'id' => $link_id ),
                array( '%d' )
            );

            if ($ok === false) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback.
                $wpdb->query('ROLLBACK');
                return false;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit.
            $wpdb->query('COMMIT');
            return (bool) $ok;
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback on error.
            $wpdb->query('ROLLBACK');
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[cashback-social] delete_link failed: ' . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // Pending
    // =========================================================================

    /**
     * Создать pending-запись. Payload шифруется AES-256-GCM.
     *
     * @param string              $kind       confirm_link|email_prompt|email_verify
     * @param array<string,mixed> $payload    Будет JSON-кодирован и зашифрован.
     * @param string              $ip
     * @param int                 $ttl_minutes TTL в минутах (default 15).
     * @return string Токен (hex 64 символа) или пустая строка при ошибке.
     */
    public static function save_pending( string $kind, array $payload, string $ip, int $ttl_minutes = 15 ): string {
        global $wpdb;

        if (!class_exists('Cashback_Encryption') || !Cashback_Encryption::is_configured()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[cashback-social] save_pending aborted: encryption not configured');
            return '';
        }

        try {
            $encrypted = Cashback_Encryption::encrypt((string) wp_json_encode($payload));
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[cashback-social] save_pending encrypt error: ' . $e->getMessage());
            return '';
        }

        $token      = bin2hex(random_bytes(32));
        $now        = current_time('mysql');
        $expires_at = gmdate('Y-m-d H:i:s', time() + ( $ttl_minutes * MINUTE_IN_SECONDS ));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned table.
        $ok = $wpdb->insert(
            self::table_pending(),
            array(
                'token'        => $token,
                'kind'         => $kind,
                'payload_json' => $encrypted,
                'created_at'   => $now,
                'expires_at'   => $expires_at,
                'consumed_at'  => null,
                'ip'           => $ip,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        return $ok ? $token : '';
    }

    /**
     * Потребить pending-запись (одноразовое чтение).
     *
     * Возвращает массив [token, kind, payload, created_at, expires_at, ip] с расшифрованным payload,
     * или null если токен не найден, истёк, уже использован.
     *
     * @return array<string, mixed>|null
     */
    public static function consume_pending( string $token ): ?array {
        global $wpdb;

        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return null;
        }

        $table = self::table_pending();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read within atomic consume.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
                "SELECT * FROM {$table} WHERE token = %s LIMIT 1",
                $token
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        if (!empty($row['consumed_at'])) {
            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        // Пометить как потреблённый атомарно. expires_at добавлен в WHERE, чтобы
        // закрыть TOCTOU между SELECT-проверкой истечения и UPDATE: если токен
        // истёк между проверкой и записью, UPDATE не выполнится.
        // expires_at хранится в UTC (см. save_pending → gmdate), поэтому и
        // сравнение ведём в UTC.
        $now_utc = gmdate('Y-m-d H:i:s');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic mark-consumed.
        $updated = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
                "UPDATE {$table} SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL AND expires_at >= %s",
                current_time('mysql'),
                (int) $row['id'],
                $now_utc
            )
        );

        if (!$updated) {
            // Кто-то другой уже потребил запись между SELECT и UPDATE, либо токен успел истечь.
            return null;
        }

        try {
            $decrypted = Cashback_Encryption::decrypt((string) $row['payload_json']);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[cashback-social] consume_pending decrypt error: ' . $e->getMessage());
            return null;
        }

        $payload = json_decode($decrypted, true);
        if (!is_array($payload)) {
            $payload = array();
        }

        return array(
            'id'         => (int) $row['id'],
            'token'      => (string) $row['token'],
            'kind'       => (string) $row['kind'],
            'payload'    => $payload,
            'created_at' => (string) $row['created_at'],
            'expires_at' => (string) $row['expires_at'],
            'ip'         => $row['ip'] !== null ? (string) $row['ip'] : null,
        );
    }

    /**
     * Удалить истёкшие/потреблённые pending-записи старше суток.
     * Предполагается вызов из cron.
     */
    public static function cleanup_expired_pending(): int {
        global $wpdb;
        $table  = self::table_pending();
        $cutoff = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Housekeeping cron.
        $affected = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
                "DELETE FROM {$table} WHERE expires_at < %s OR consumed_at IS NOT NULL AND consumed_at < %s",
                $cutoff,
                $cutoff
            )
        );

        return (int) $affected;
    }
}
