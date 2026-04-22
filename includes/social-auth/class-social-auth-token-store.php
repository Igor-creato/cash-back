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

        $table = Cashback_Social_Auth_DB::table_tokens();
        $now   = current_time('mysql');

        // Row-lock TX: encrypt + UPSERT под удержанием FOR UPDATE на link_id.
        // Закрывает TOCTOU-race с batch-job'ом ротации (фаза social_tokens):
        // write-key для encrypt() выбирается под lock'ом, batch не может одновременно
        // перешифровать эту запись.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Row-lock TX begin.
        $wpdb->query('START TRANSACTION');
        try {
            // SELECT FOR UPDATE по UNIQUE link_id: существующая строка → row-lock,
            // отсутствующая → gap-lock в REPEATABLE READ (предотвращает race с
            // параллельным INSERT той же связки).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SELECT FOR UPDATE inside row-lock TX.
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT id FROM %i WHERE link_id = %d FOR UPDATE',
                    $table,
                    $link_id
                )
            );

            // Encrypt под удержанием lock'а.
            try {
                $encrypted = Cashback_Encryption::encrypt($refresh_token);
            } catch (\Throwable $enc_e) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback encrypt failure.
                $wpdb->query('ROLLBACK');
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[cashback-social] token-store encrypt failed: ' . $enc_e->getMessage());
                return false;
            }

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

            if ($ok === false) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback UPSERT failure.
                $wpdb->query('ROLLBACK');
                return false;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit.
            $wpdb->query('COMMIT');
            return true;
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback on error.
            $wpdb->query('ROLLBACK');
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[cashback-social] token-store save_tokens failed: ' . $e->getMessage());
            return false;
        }
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
