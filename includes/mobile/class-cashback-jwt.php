<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Кодирование/декодирование JSON Web Tokens (RFC 7519) — только алгоритм HS256.
 *
 * Используется `Cashback_JWT_Auth` для подписи access-токенов мобильного приложения.
 * Поддерживает grace-период ротации секретов: при `decode()` передаётся список секретов,
 * токен валиден если подпись совпала с любым из них.
 *
 * Время — строго UTC (unix timestamp). `leeway` учитывается при проверке `exp`/`nbf`/`iat`.
 *
 * @since 1.1.0
 */
class Cashback_JWT {

    public const ALG = 'HS256';

    /** Допустимое отклонение часов (секунды) при проверке exp/nbf/iat. */
    public const DEFAULT_LEEWAY = 30;

    /**
     * Закодировать payload в compact-формат JWS.
     *
     * @param array<string,mixed> $payload Claims (должен содержать `exp`).
     * @param string              $secret  Секрет для HMAC-SHA256.
     * @return string `header.payload.signature`
     * @throws InvalidArgumentException Если секрет пустой или payload не сериализуется.
     */
    public static function encode( array $payload, string $secret ): string {
        if ('' === $secret) {
            throw new InvalidArgumentException('JWT secret is empty');
        }

        $header   = array(
            'alg' => self::ALG,
            'typ' => 'JWT',
        );
        $segments = array(
            self::b64url_encode(self::json_encode($header)),
            self::b64url_encode(self::json_encode($payload)),
        );

        $signing_input = implode('.', $segments);
        $signature     = hash_hmac('sha256', $signing_input, $secret, true);
        $segments[]    = self::b64url_encode($signature);

        return implode('.', $segments);
    }

    /**
     * Проверить подпись и срок действия, вернуть claims.
     *
     * @param string        $jwt     Compact-строка.
     * @param array<string> $secrets Список допустимых секретов (первый — актуальный; остальные — для grace-ротации).
     * @param int           $leeway  Допустимое отклонение часов, секунды.
     * @return array<string,mixed> Декодированные claims.
     * @throws RuntimeException При любой ошибке валидации (структура, подпись, истечение).
     */
    public static function decode( string $jwt, array $secrets, int $leeway = self::DEFAULT_LEEWAY ): array {
        if (empty($secrets)) {
            throw new InvalidArgumentException('JWT secrets list is empty');
        }

        $parts = explode('.', $jwt);
        if (3 !== count($parts)) {
            throw new RuntimeException('Malformed JWT');
        }

        [ $b64_header, $b64_payload, $b64_signature ] = $parts;

        $header_json = self::b64url_decode($b64_header);
        $header      = json_decode($header_json, true);
        if (!is_array($header) || ( $header['alg'] ?? '' ) !== self::ALG || ( $header['typ'] ?? '' ) !== 'JWT') {
            throw new RuntimeException('Unsupported JWT header');
        }

        $signature = self::b64url_decode($b64_signature);
        if ('' === $signature) {
            throw new RuntimeException('Empty JWT signature');
        }

        $signing_input = $b64_header . '.' . $b64_payload;
        $matched       = false;
        foreach ($secrets as $secret) {
            if ('' === (string) $secret) {
                continue;
            }
            $expected = hash_hmac('sha256', $signing_input, (string) $secret, true);
            if (hash_equals($expected, $signature)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            throw new RuntimeException('Invalid JWT signature');
        }

        $payload_json = self::b64url_decode($b64_payload);
        $payload      = json_decode($payload_json, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Malformed JWT payload');
        }

        $now = time();

        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && $now + $leeway < (int) $payload['nbf']) {
            throw new RuntimeException('JWT not yet valid (nbf)');
        }

        if (isset($payload['iat']) && is_numeric($payload['iat']) && $now + $leeway < (int) $payload['iat']) {
            throw new RuntimeException('JWT issued in the future (iat)');
        }

        if (!isset($payload['exp']) || !is_numeric($payload['exp'])) {
            throw new RuntimeException('JWT missing exp claim');
        }
        if ($now - $leeway >= (int) $payload['exp']) {
            throw new RuntimeException('JWT expired');
        }

        return $payload;
    }

    /**
     * URL-safe base64 без padding (RFC 7515, §2).
     */
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

    private static function json_encode( array $data ): string {
        $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $json) {
            throw new InvalidArgumentException('Failed to encode JWT payload');
        }
        return $json;
    }
}
