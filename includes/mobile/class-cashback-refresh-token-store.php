<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Хранилище refresh-токенов мобильной JWT-сессии.
 *
 * Схема хранения:
 *  - Клиент получает plaintext refresh-токен **один раз** в ответе.
 *  - В БД хранится только SHA-256 хеш (невозможно восстановить plaintext из дампа).
 *  - Все токены одной "сессии" (login → N ротаций → logout) связаны общим `family_id`.
 *  - Ротация: `rotate()` помечает текущий `revoked_at`/`revoked_reason='rotation'` и создаёт новый с тем же `family_id`.
 *  - Reuse-detection: если клиент предъявляет уже `revoked` refresh-токен, вся `family_id` отзывается
 *    (`revoked_reason='reuse_detected'`) — злоумышленник не сможет дальше использовать цепочку.
 *
 * @since 1.1.0
 */
class Cashback_Refresh_Token_Store {

    /** TTL refresh-токена (30 дней). */
    public const REFRESH_TTL_SECONDS = 30 * DAY_IN_SECONDS;

    /** Длина plaintext refresh-токена в байтах (→ 64 hex-символа). */
    private const PLAINTEXT_BYTES = 32;

    /**
     * Выпустить новый refresh-токен (начало новой family_id).
     *
     * @param int                                                $user_id       WP user ID.
     * @param array{device_id?:?string,device_name?:?string,platform?:?string,app_version?:?string,ip?:?string,user_agent?:?string} $device_info
     * @return array{plaintext:string, id:int, family_id:string, expires_at:string}
     */
    public static function issue_new_family( int $user_id, array $device_info ): array {
        $family_id = cashback_generate_uuid7(true);
        return self::insert_token($user_id, $family_id, $device_info);
    }

    /**
     * Ротация: отозвать старый токен и выпустить новый с тем же family_id.
     *
     * Должен вызываться внутри транзакции при `refresh`-операции.
     * При обнаружении reuse (старый уже revoked) — выбрасывает исключение и
     * инициирует `revoke_family()` в caller'е.
     *
     * @param object $current_row Запись из `find_active_by_hash()` (stdClass от $wpdb).
     * @param array<string,?string> $device_info Контекст обновления (IP, UA и т.д.).
     * @return array{plaintext:string, id:int, family_id:string, expires_at:string}
     */
    public static function rotate( object $current_row, array $device_info ): array {
        global $wpdb;

        $new = self::insert_token((int) $current_row->user_id, (string) $current_row->family_id, $device_info);

        $wpdb->update(
            $wpdb->prefix . 'cashback_refresh_tokens',
            array(
                'revoked_at'     => gmdate('Y-m-d H:i:s'),
                'revoked_reason' => 'rotation',
                'replaced_by'    => $new['id'],
                'last_used_at'   => gmdate('Y-m-d H:i:s'),
            ),
            array( 'id' => (int) $current_row->id ),
            array( '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );

        return $new;
    }

    /**
     * Найти активный токен по plaintext (без учёта revoked/expired).
     *
     * @return object|null stdClass из БД или null.
     */
    public static function find_by_plaintext( string $plaintext ): ?object {
        global $wpdb;

        $hash = self::hash_plaintext($plaintext);
        $row  = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom auth table, no WP API available.
                "SELECT * FROM `{$wpdb->prefix}cashback_refresh_tokens` WHERE `token_hash` = %s LIMIT 1",
                $hash
            )
        );

        return $row instanceof \stdClass ? $row : null;
    }

    /**
     * Отозвать один конкретный токен.
     *
     * @param int    $id     Primary key.
     * @param string $reason logout|logout_all|reuse_detected|admin|expired.
     */
    public static function revoke( int $id, string $reason ): void {
        global $wpdb;

        // Не перезаписываем уже проставленный revoked_at (сохраняем изначальную причину).
        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom auth table, targeted revoke.
                "UPDATE `{$wpdb->prefix}cashback_refresh_tokens`
                 SET `revoked_at` = %s, `revoked_reason` = %s
                 WHERE `id` = %d AND `revoked_at` IS NULL",
                gmdate('Y-m-d H:i:s'),
                $reason,
                $id
            )
        );
    }

    /**
     * Отозвать всю цепочку ротаций (все токены с заданным family_id).
     *
     * Используется при reuse-detection и при `logout-all`.
     */
    public static function revoke_family( string $family_id, string $reason ): int {
        global $wpdb;

        $affected = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom auth table, bulk revoke.
                "UPDATE `{$wpdb->prefix}cashback_refresh_tokens`
                 SET `revoked_at` = %s, `revoked_reason` = %s
                 WHERE `family_id` = %s AND `revoked_at` IS NULL",
                gmdate('Y-m-d H:i:s'),
                $reason,
                $family_id
            )
        );

        return (int) $affected;
    }

    /**
     * Отозвать все активные токены пользователя (logout со всех устройств).
     */
    public static function revoke_all_for_user( int $user_id, string $reason = 'logout_all' ): int {
        global $wpdb;

        $affected = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom auth table, bulk revoke.
                "UPDATE `{$wpdb->prefix}cashback_refresh_tokens`
                 SET `revoked_at` = %s, `revoked_reason` = %s
                 WHERE `user_id` = %d AND `revoked_at` IS NULL",
                gmdate('Y-m-d H:i:s'),
                $reason,
                $user_id
            )
        );

        return (int) $affected;
    }

    /**
     * Отметить что токен был успешно использован для `refresh` (аудит).
     */
    public static function touch_last_used( int $id ): void {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'cashback_refresh_tokens',
            array( 'last_used_at' => gmdate('Y-m-d H:i:s') ),
            array( 'id' => $id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * Хеш plaintext для хранения в БД.
     */
    public static function hash_plaintext( string $plaintext ): string {
        return hash('sha256', $plaintext);
    }

    /**
     * Внутренний: создать новую запись токена (общее тело для issue + rotate).
     *
     * @param array<string,?string> $device_info
     * @return array{plaintext:string, id:int, family_id:string, expires_at:string}
     */
    private static function insert_token( int $user_id, string $family_id, array $device_info ): array {
        global $wpdb;

        $plaintext  = bin2hex(random_bytes(self::PLAINTEXT_BYTES));
        $now_ts     = time();
        $issued_at  = gmdate('Y-m-d H:i:s', $now_ts);
        $expires_at = gmdate('Y-m-d H:i:s', $now_ts + self::REFRESH_TTL_SECONDS);

        $data = array(
            'user_id'     => $user_id,
            'family_id'   => $family_id,
            'token_hash'  => self::hash_plaintext($plaintext),
            'device_id'   => self::nullable_string($device_info['device_id'] ?? null, 128),
            'device_name' => self::nullable_string($device_info['device_name'] ?? null, 128),
            'platform'    => self::nullable_platform($device_info['platform'] ?? null),
            'app_version' => self::nullable_string($device_info['app_version'] ?? null, 32),
            'ip_address'  => self::nullable_string($device_info['ip'] ?? null, 45),
            'user_agent'  => self::nullable_string($device_info['user_agent'] ?? null, 255),
            'issued_at'   => $issued_at,
            'expires_at'  => $expires_at,
        );

        $formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

        $ok = $wpdb->insert(
            $wpdb->prefix . 'cashback_refresh_tokens',
            $data,
            $formats
        );

        if (false === $ok) {
            throw new RuntimeException('Failed to persist refresh token: ' . esc_html((string) $wpdb->last_error));
        }

        return array(
            'plaintext'  => $plaintext,
            'id'         => (int) $wpdb->insert_id,
            'family_id'  => $family_id,
            'expires_at' => $expires_at,
        );
    }

    private static function nullable_string( ?string $value, int $max_length ): ?string {
        if (null === $value || '' === $value) {
            return null;
        }
        return mb_substr($value, 0, $max_length);
    }

    private static function nullable_platform( ?string $value ): ?string {
        $value = is_string($value) ? strtolower($value) : '';
        return in_array($value, array( 'ios', 'android', 'web' ), true) ? $value : null;
    }
}
