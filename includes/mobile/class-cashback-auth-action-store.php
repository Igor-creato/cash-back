<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Хранилище одноразовых auth-токенов: email_verify, password_reset.
 *
 * Безопасность:
 *  - Plaintext токен (32 байта, URL-safe base64) возвращается клиенту только один раз.
 *  - В БД — SHA-256 хеш (256-битная энтропия, radix-attack неприменима).
 *  - TTL: email_verify 24ч, password_reset 30мин.
 *  - Single-use: поле `used_at` выставляется после потребления.
 *  - При выпуске нового токена того же назначения все предыдущие активные
 *    инвалидируются (invalidated_at) — защита от race/stockpile.
 *
 * @since 1.1.0
 */
class Cashback_Auth_Action_Store {

    public const PURPOSE_EMAIL_VERIFY   = 'email_verify';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public const TTL_EMAIL_VERIFY   = 24 * HOUR_IN_SECONDS;
    public const TTL_PASSWORD_RESET = 30 * MINUTE_IN_SECONDS;

    /**
     * Выпустить новый одноразовый токен.
     *
     * Предыдущие активные токены того же пользователя и назначения инвалидируются.
     *
     * @param int    $user_id     WP user ID.
     * @param string $purpose     self::PURPOSE_*.
     * @param array  $request_ctx ['ip' => string, 'user_agent' => string].
     * @return string Plaintext токен (URL-safe base64, ~43 символа).
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public static function issue( int $user_id, string $purpose, array $request_ctx = array() ): string {
        if ($user_id <= 0) {
            throw new InvalidArgumentException('user_id must be positive');
        }
        if (!in_array($purpose, array( self::PURPOSE_EMAIL_VERIFY, self::PURPOSE_PASSWORD_RESET ), true)) {
            throw new InvalidArgumentException('Unsupported auth action purpose');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cashback_auth_actions';
        $now   = current_time('mysql', true); // UTC
        $ttl   = self::PURPOSE_EMAIL_VERIFY === $purpose ? self::TTL_EMAIL_VERIFY : self::TTL_PASSWORD_RESET;

        // Инвалидируем все активные токены этого user+purpose.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- targeted invalidation of prior active tokens.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$wpdb->prefix}cashback_auth_actions`
                 SET `invalidated_at` = %s
                 WHERE `user_id` = %d AND `purpose` = %s AND `used_at` IS NULL AND `invalidated_at` IS NULL",
                $now,
                $user_id,
                $purpose
            )
        );

        // Plaintext: 32 байта → base64url (43 символа без padding).
        $plaintext = self::generate_plaintext();
        $hash      = self::hash_plaintext($plaintext);

        $expires_at = gmdate('Y-m-d H:i:s', time() + $ttl);

        $data    = array(
            'user_id'    => $user_id,
            'purpose'    => $purpose,
            'token_hash' => $hash,
            'ip_address' => isset($request_ctx['ip']) ? mb_substr((string) $request_ctx['ip'], 0, 45) : null,
            'user_agent' => isset($request_ctx['user_agent']) ? mb_substr((string) $request_ctx['user_agent'], 0, 255) : null,
            'created_at' => $now,
            'expires_at' => $expires_at,
        );
        $formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- write path, single row insert.
        $ok = $wpdb->insert($table, $data, $formats);
        if (false === $ok) {
            throw new RuntimeException('Failed to persist auth action: ' . esc_html((string) $wpdb->last_error));
        }

        return $plaintext;
    }

    /**
     * Потребить токен: найти по хешу, проверить статус, пометить used_at.
     *
     * Возвращает user_id при успехе или null. Все операции через один SELECT + UPDATE
     * с WHERE-условиями, чтобы избежать TOCTOU — если кто-то параллельно уже пометил
     * использование, наш UPDATE вернёт 0 затронутых строк.
     *
     * @param string $plaintext Токен из письма.
     * @param string $purpose   Ожидаемое назначение (защита от cross-purpose usage).
     * @return int|null user_id или null при любой ошибке валидации.
     */
    public static function consume( string $plaintext, string $purpose ): ?int {
        if ('' === $plaintext) {
            return null;
        }
        if (!in_array($purpose, array( self::PURPOSE_EMAIL_VERIFY, self::PURPOSE_PASSWORD_RESET ), true)) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cashback_auth_actions';
        $hash  = self::hash_plaintext($plaintext);
        $now   = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- token lookup.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT `id`, `user_id`, `purpose`, `expires_at`, `used_at`, `invalidated_at`
                 FROM `{$wpdb->prefix}cashback_auth_actions`
                 WHERE `token_hash` = %s LIMIT 1",
                $hash
            )
        );

        if (!$row) {
            return null;
        }
        if ((string) $row->purpose !== $purpose) {
            return null;
        }
        if (null !== $row->used_at) {
            return null;
        }
        if (null !== $row->invalidated_at) {
            return null;
        }
        if (strtotime((string) $row->expires_at . ' UTC') <= time()) {
            return null;
        }

        // Атомарный single-use: UPDATE только если used_at всё ещё NULL.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic single-use guard.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$wpdb->prefix}cashback_auth_actions`
                 SET `used_at` = %s
                 WHERE `id` = %d AND `used_at` IS NULL AND `invalidated_at` IS NULL",
                $now,
                (int) $row->id
            )
        );

        if (1 !== $updated) {
            return null; // Кто-то потребил параллельно.
        }

        return (int) $row->user_id;
    }

    /**
     * Удалить просроченные / использованные / инвалидированные токены.
     * Вызывается из крон-очистки.
     *
     * @return int Количество удалённых строк.
     */
    public static function purge_stale(): int {
        global $wpdb;
        $now = current_time('mysql', true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- maintenance cleanup.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$wpdb->prefix}cashback_auth_actions`
                 WHERE `expires_at` < %s
                    OR `used_at` IS NOT NULL
                    OR `invalidated_at` IS NOT NULL",
                $now
            )
        );
        return is_numeric($deleted) ? (int) $deleted : 0;
    }

    /**
     * Сгенерировать криптостойкий plaintext-токен.
     * 32 байта (256 бит энтропии) → URL-safe base64 без padding (~43 символа).
     */
    public static function generate_plaintext(): string {
        $raw = random_bytes(32);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function hash_plaintext( string $plaintext ): string {
        return hash('sha256', $plaintext);
    }
}
