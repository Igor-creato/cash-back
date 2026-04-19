<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Хранилище OAuth refresh_token для соц-связок.
 * Шифрование — AES-256-GCM через Cashback_Encryption.
 */
class Cashback_Social_Auth_Token_Store {

    /**
     * Сохранить (UPSERT) refresh_token для связки.
     */
    public static function save_tokens( int $link_id, string $refresh_token, ?string $access_expires_at, string $scopes ): bool {
        global $wpdb;

        if ($link_id <= 0 || $refresh_token === '') {
            return false;
        }

        if (!class_exists('Cashback_Encryption') || !Cashback_Encryption::is_configured()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[cashback-social] token-store: encryption not configured — skipping');
            return false;
        }

        try {
            $encrypted = Cashback_Encryption::encrypt($refresh_token);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[cashback-social] token-store encrypt failed: ' . $e->getMessage());
            return false;
        }

        $table = Cashback_Social_Auth_DB::table_tokens();
        $now   = current_time('mysql');

        // MySQL upsert через ON DUPLICATE KEY UPDATE (UNIQUE по link_id).
        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
            "INSERT INTO {$table} (link_id, refresh_token_encrypted, access_token_expires_at, scopes, updated_at)
             VALUES (%d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                refresh_token_encrypted = VALUES(refresh_token_encrypted),
                access_token_expires_at = VALUES(access_token_expires_at),
                scopes = VALUES(scopes),
                updated_at = VALUES(updated_at)",
            $link_id,
            $encrypted,
            $access_expires_at !== null ? $access_expires_at : null,
            $scopes,
            $now
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is the result of $wpdb->prepare() above.
        $ok = $wpdb->query($sql);

        return $ok !== false;
    }

    /**
     * Получить расшифрованный refresh_token, либо null.
     */
    public static function get_refresh_token( int $link_id ): ?string {
        global $wpdb;

        if ($link_id <= 0) {
            return null;
        }
        if (!class_exists('Cashback_Encryption') || !Cashback_Encryption::is_configured()) {
            return null;
        }

        $table = Cashback_Social_Auth_DB::table_tokens();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only helper.
        $enc = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
                "SELECT refresh_token_encrypted FROM {$table} WHERE link_id = %d LIMIT 1",
                $link_id
            )
        );

        if (!$enc) {
            return null;
        }

        try {
            return Cashback_Encryption::decrypt((string) $enc);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[cashback-social] token-store decrypt failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Удалить хранимые токены связки.
     */
    public static function delete( int $link_id ): bool {
        global $wpdb;

        if ($link_id <= 0) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to plugin-owned table.
        $ok = $wpdb->delete(
            Cashback_Social_Auth_DB::table_tokens(),
            array( 'link_id' => $link_id ),
            array( '%d' )
        );
        return (bool) $ok;
    }
}
