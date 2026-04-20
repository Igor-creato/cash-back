<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Хранилище Expo push-токенов мобильных устройств.
 *
 * Операции:
 *   - register(user_id, expo_token, device_id, ...) — UPSERT по expo_token.
 *   - revoke_by_device(user_id, device_id)          — при logout или удалении устройства.
 *   - get_active_tokens_for_user(user_id)           — для таргетной рассылки.
 *   - increment_failures(expo_token)                — выбраковка битых токенов (DeviceNotRegistered).
 *   - delete_by_expo_token(token)                   — финальное удаление после N неудач или явного ответа провайдера.
 *
 * Формат Expo: `ExponentPushToken[xxxxxxxx]` (строгая валидация).
 *
 * @since 1.1.0
 */
class Cashback_Push_Token_Store {

    public const MAX_FAILURES = 3;

    /**
     * @return int|WP_Error row id or WP_Error
     */
    public static function register( int $user_id, string $expo_token, string $device_id, string $platform, array $extra = array() ) {
        if ($user_id <= 0) {
            return new WP_Error('invalid_user', 'Invalid user', array( 'status' => 400 ));
        }
        if (!self::is_valid_expo_token($expo_token)) {
            return new WP_Error('invalid_expo_token', 'Invalid Expo push token format', array( 'status' => 400 ));
        }
        if ('' === $device_id) {
            return new WP_Error('invalid_device_id', 'device_id is required', array( 'status' => 400 ));
        }
        if (!in_array($platform, array( 'ios', 'android' ), true)) {
            return new WP_Error('invalid_platform', 'platform must be ios or android', array( 'status' => 400 ));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cashback_push_tokens';
        $now   = current_time('mysql', true);

        $app_version = isset($extra['app_version']) ? mb_substr((string) $extra['app_version'], 0, 32) : null;
        $locale      = isset($extra['locale']) ? mb_substr((string) $extra['locale'], 0, 8) : null;

        // UPSERT: expo_token UNIQUE; перезаписываем user_id, device_id и прочее на свежее.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- idempotent register.
        $existing = $wpdb->get_row(
            $wpdb->prepare('SELECT id, user_id FROM %i WHERE expo_token = %s', $table, $expo_token),
            ARRAY_A
        );

        if ($existing) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- refresh.
            $wpdb->update(
                $table,
                array(
                    'user_id'       => $user_id,
                    'device_id'     => mb_substr($device_id, 0, 128),
                    'platform'      => $platform,
                    'app_version'   => $app_version,
                    'locale'        => $locale,
                    'failure_count' => 0,
                    'last_used_at'  => $now,
                ),
                array( 'id' => (int) $existing['id'] ),
                array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' ),
                array( '%d' )
            );
            return (int) $existing['id'];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- insert.
        $ok = $wpdb->insert(
            $table,
            array(
                'user_id'       => $user_id,
                'expo_token'    => $expo_token,
                'device_id'     => mb_substr($device_id, 0, 128),
                'platform'      => $platform,
                'app_version'   => $app_version,
                'locale'        => $locale,
                'failure_count' => 0,
                'created_at'    => $now,
                'last_used_at'  => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        if (false === $ok) {
            return new WP_Error('push_token_save_failed', esc_html((string) $wpdb->last_error), array( 'status' => 500 ));
        }

        do_action('cashback_push_token_registered', $user_id, $expo_token);

        return (int) $wpdb->insert_id;
    }

    /**
     * Отозвать push-токен по device_id (при logout с устройства).
     */
    public static function revoke_by_device( int $user_id, string $device_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_push_tokens';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-owned delete.
        $n = $wpdb->delete(
            $table,
            array(
                'user_id'   => $user_id,
                'device_id' => $device_id,
            ),
            array( '%d', '%s' )
        );
        return is_numeric($n) ? (int) $n : 0;
    }

    /**
     * Активные токены пользователя (без выброшенных).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_active_tokens_for_user( int $user_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_push_tokens';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- dispatch target.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, expo_token, platform, locale FROM %i
                 WHERE user_id = %d AND failure_count < %d
                 ORDER BY last_used_at DESC',
                $table,
                $user_id,
                self::MAX_FAILURES
            ),
            ARRAY_A
        );
        $out  = array();
        foreach ((array) $rows as $row) {
            $out[] = array(
                'id'         => (int) $row['id'],
                'expo_token' => (string) $row['expo_token'],
                'platform'   => (string) $row['platform'],
                'locale'     => (string) ( $row['locale'] ?? '' ),
            );
        }
        return $out;
    }

    public static function increment_failures( string $expo_token ): void {
        if ('' === $expo_token) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_push_tokens';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- failure accounting.
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET failure_count = failure_count + 1 WHERE expo_token = %s',
                $table,
                $expo_token
            )
        );
    }

    public static function delete_by_expo_token( string $expo_token ): void {
        if ('' === $expo_token) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_push_tokens';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cleanup.
        $wpdb->delete($table, array( 'expo_token' => $expo_token ), array( '%s' ));
    }

    /**
     * Валидация формата Expo push-токена:
     *   ExponentPushToken[xxxxxxxx]  (с 2023 — этот формат).
     *   Или тестовый: ExpoPushToken[...]  (реже).
     */
    public static function is_valid_expo_token( string $token ): bool {
        return (bool) preg_match('/^Expo(nent)?PushToken\[[A-Za-z0-9_\-]+\]$/', $token);
    }
}
