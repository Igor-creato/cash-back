<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HMAC-signed одноразовые ссылки на скачивание вложений (без DB).
 *
 * Формат токена:   base64url( "$user_id|$resource_kind|$resource_kind_id|$expires|$sig" )
 *   где $sig = hash_hmac('sha256', "$user_id|$resource_kind|$resource_kind_id|$expires", $secret)
 *
 * Секрет: `CB_JWT_SECRET` (переиспользуем — уже требуется для мобильного API).
 *
 * Преимущества vs DB-токен:
 *  - Нет записи при каждом GET detail (меньше I/O).
 *  - Без миграции.
 *  - Stateless: подпись гарантирует целостность.
 *
 * Ограничения:
 *  - Нет возможности отозвать один конкретный URL до expiry (принимаем — TTL 10 мин).
 *  - При ротации секрета — все старые URL инвалидируются (желаемое поведение).
 *
 * @since 1.1.0
 */
class Cashback_Mobile_Signed_URL {

    public const DEFAULT_TTL = 10 * MINUTE_IN_SECONDS;

    /**
     * Сгенерировать подписанный URL для скачивания.
     *
     * @param int    $user_id     Владелец.
     * @param string $resource_kind    Вид ресурса (например, 'ticket_attachment').
     * @param int    $resource_kind_id ID вложения.
     * @param int    $ttl         TTL в секундах (по умолчанию 10 мин).
     * @return string Signed token (передаётся клиенту в response).
     * @throws RuntimeException Если JWT-секрет не сконфигурирован.
     */
    public static function sign( int $user_id, string $resource_kind, int $resource_kind_id, int $ttl = self::DEFAULT_TTL ): string {
        $secret  = self::get_secret();
        $expires = time() + max(60, $ttl);
        $payload = $user_id . '|' . $resource_kind . '|' . $resource_kind_id . '|' . $expires;
        $sig     = hash_hmac('sha256', $payload, $secret);

        return self::b64url_encode($payload . '|' . $sig);
    }

    /**
     * Проверить токен и извлечь (user_id, resource, resource_id).
     *
     * @return array|WP_Error ['user_id','resource','resource_id','expires']
     */
    public static function verify( string $token ) {
        $raw = self::b64url_decode($token);
        if ('' === $raw) {
            return new WP_Error('invalid_token', __('Invalid token.', 'cashback'), array( 'status' => 400 ));
        }
        $parts = explode('|', $raw);
        if (5 !== count($parts)) {
            return new WP_Error('invalid_token', __('Invalid token.', 'cashback'), array( 'status' => 400 ));
        }
        [ $user_id, $resource_kind, $resource_kind_id, $expires, $sig ] = $parts;

        // Вычисляем ожидаемую подпись.
        try {
            $secret = self::get_secret();
        } catch (\Throwable $e) {
            return new WP_Error('server_error', __('Server misconfigured.', 'cashback'), array( 'status' => 500 ));
        }
        $expected = hash_hmac('sha256', $user_id . '|' . $resource_kind . '|' . $resource_kind_id . '|' . $expires, $secret);

        if (!hash_equals($expected, $sig)) {
            return new WP_Error('invalid_token', __('Invalid or tampered token.', 'cashback'), array( 'status' => 400 ));
        }
        if ((int) $expires < time()) {
            return new WP_Error('token_expired', __('Download link has expired.', 'cashback'), array( 'status' => 410 ));
        }

        return array(
            'user_id'     => (int) $user_id,
            'resource'    => (string) $resource_kind,
            'resource_id' => (int) $resource_kind_id,
            'expires'     => (int) $expires,
        );
    }

    /**
     * Сгенерировать полный URL эндпоинта скачивания.
     */
    public static function build_download_url( int $ticket_id, int $attachment_id, string $token ): string {
        return rest_url('cashback/v2/me/tickets/' . $ticket_id . '/attachments/' . $attachment_id)
            . '?' . http_build_query(array( 'token' => $token ));
    }

    // -------------------------------------------------------------------------

    private static function get_secret(): string {
        if (!defined('CB_JWT_SECRET') || '' === (string) constant('CB_JWT_SECRET')) {
            throw new RuntimeException('CB_JWT_SECRET is not configured');
        }
        return (string) constant('CB_JWT_SECRET');
    }

    public static function b64url_encode( string $raw ): string {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64url_decode( string $b64url ): string {
        $padded = strtr($b64url, '-_', '+/');
        $pad    = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($padded, true);
        return is_string($decoded) ? $decoded : '';
    }
}
