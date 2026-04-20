<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Сессия для OAuth-flow: state, PKCE, referral snapshot.
 *
 * Данные хранятся в transient, ссылка на transient — в HMAC-подписанной cookie
 * `cashback_social_state_{provider}` (HttpOnly, SameSite=Lax, Secure для https).
 * Cookie содержит hex-токен + HMAC, поэтому подделать state, не зная SECRET, нельзя.
 * Сам state проверяется через hash_equals (constant-time).
 */
class Cashback_Social_Auth_Session {

    private const COOKIE_PREFIX    = 'cashback_social_state_';
    private const TRANSIENT_PREFIX = 'cashback_social_session_';
    private const TTL_SECONDS      = 600; // 10 минут

    /**
     * Пытаемся стартовать PHP-сессию если headers ещё не отправлены — это
     * повышает совместимость с окружениями, где transient/cookie ограничены.
     * При невозможности старта работаем только через transient + cookie.
     */
    public static function start_session(): void {
        if (headers_sent()) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        // Не поднимаем сессию без нужды — просто отмечаем возможность.
        // Реально активная сессия здесь не обязательна, transient достаточно.
    }

    /**
     * Генерация CSRF state.
     */
    public static function generate_state(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Генерация PKCE пары (code_verifier + code_challenge S256).
     *
     * @return array{verifier:string, challenge:string, method:string}
     */
    public static function generate_pkce(): array {
        // 64 hex symbols = 256 bits энтропии. Для base64url-верифаера RFC 7636
        // допускает 43..128 символов; hex удовлетворяет ограничениям алфавита.
        $verifier  = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $hash      = hash('sha256', $verifier, true);
        $challenge = rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
        return array(
            'verifier'  => $verifier,
            'challenge' => $challenge,
            'method'    => 'S256',
        );
    }

    /**
     * Сохранить session-данные.
     *
     * @param array<string, mixed> $data Должно содержать state, code_verifier, redirect_after_login…
     * @return bool true если cookie удалось выставить.
     */
    public static function store( string $provider, array $data ): bool {
        $provider = self::sanitize_provider($provider);
        if ($provider === '') {
            return false;
        }

        $cookie_token  = bin2hex(random_bytes(16)); // 32 hex chars
        $transient_key = self::TRANSIENT_PREFIX . $provider . '_' . $cookie_token;

        $signature = self::sign($cookie_token);
        if ($signature === '') {
            // Fail-closed: без корректного секрета подписывать cookie нельзя.
            return false;
        }

        set_transient($transient_key, $data, self::TTL_SECONDS);

        $cookie_value = $cookie_token . '.' . $signature;

        if (headers_sent()) {
            return false;
        }

        $secure = is_ssl();
        setcookie(
            self::COOKIE_PREFIX . $provider,
            $cookie_value,
            array(
                'expires'  => time() + self::TTL_SECONDS,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Update local $_COOKIE for same-request access.
        $_COOKIE[ self::COOKIE_PREFIX . $provider ] = $cookie_value;
        return true;
    }

    /**
     * Загрузить сессию и проверить state.
     *
     * @return array<string, mixed>|null
     */
    public static function load_and_verify( string $provider, string $incoming_state ): ?array {
        $provider = self::sanitize_provider($provider);
        if ($provider === '') {
            return null;
        }

        $cookie_name = self::COOKIE_PREFIX . $provider;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw cookie parsed & validated below.
        $raw = isset($_COOKIE[ $cookie_name ]) ? (string) $_COOKIE[ $cookie_name ] : '';

        if ($raw === '' || strpos($raw, '.') === false) {
            return null;
        }

        list( $cookie_token, $signature ) = explode('.', $raw, 2);

        if (!preg_match('/^[a-f0-9]{32}$/', $cookie_token)) {
            return null;
        }

        $expected = self::sign($cookie_token);
        if ($expected === '' || !hash_equals($expected, $signature)) {
            return null;
        }

        $transient_key = self::TRANSIENT_PREFIX . $provider . '_' . $cookie_token;
        $data          = get_transient($transient_key);
        if (!is_array($data)) {
            return null;
        }

        $stored_state = isset($data['state']) ? (string) $data['state'] : '';
        if ($stored_state === '' || !hash_equals($stored_state, $incoming_state)) {
            return null;
        }

        return $data;
    }

    /**
     * Очистить сессию после callback.
     */
    public static function clear( string $provider ): void {
        $provider = self::sanitize_provider($provider);
        if ($provider === '') {
            return;
        }
        $cookie_name = self::COOKIE_PREFIX . $provider;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed below.
        $raw = isset($_COOKIE[ $cookie_name ]) ? (string) $_COOKIE[ $cookie_name ] : '';

        if ($raw !== '' && strpos($raw, '.') !== false) {
            list( $cookie_token ) = explode('.', $raw, 2);
            if (preg_match('/^[a-f0-9]{32}$/', $cookie_token)) {
                delete_transient(self::TRANSIENT_PREFIX . $provider . '_' . $cookie_token);
            }
        }

        if (!headers_sent()) {
            setcookie(
                $cookie_name,
                '',
                array(
                    'expires'  => time() - 3600,
                    'path'     => '/',
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                )
            );
        }
        unset($_COOKIE[ $cookie_name ]);
    }

    /**
     * Снять снимок реферальных cookies для восстановления при wp_insert_user.
     *
     * @return array<string, string>
     */
    public static function capture_referral_snapshot(): array {
        $snapshot = array();
        $keys     = array( 'cashback_ref', 'cashback_ref_sig', 'cashback_affiliate_data' );
        foreach ($keys as $key) {
            if (!isset($_COOKIE[ $key ])) {
                continue;
            }
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Stored verbatim to replay on callback; affiliate layer validates signature.
            $snapshot[ $key ] = (string) $_COOKIE[ $key ];
        }
        return $snapshot;
    }

    /**
     * Восстановить реферальные cookies перед созданием юзера — только в $_COOKIE,
     * чтобы существующие хуки плагина (affiliate, user_register) их увидели.
     *
     * @param array<string, string> $snapshot
     */
    public static function restore_referral_snapshot( array $snapshot ): void {
        foreach ($snapshot as $key => $value) {
            if (!in_array($key, array( 'cashback_ref', 'cashback_ref_sig', 'cashback_affiliate_data' ), true)) {
                continue;
            }
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Trusted snapshot taken from same request.
            $_COOKIE[ $key ] = $value;
        }
    }

    /**
     * HMAC подписи cookie-токена.
     * Использует CB_ENCRYPTION_KEY как секрет; запасной вариант — AUTH_KEY.
     * Fail-closed: возвращает пустую строку, если секрет не настроен; вызывающие
     * code-paths (store/load_and_verify) обязаны трактовать '' как ошибку.
     */
    private static function sign( string $token ): string {
        $secret = defined('CB_ENCRYPTION_KEY') ? (string) CB_ENCRYPTION_KEY : '';
        if ($secret === '' && defined('AUTH_KEY')) {
            $secret = (string) AUTH_KEY;
        }
        if ($secret === '') {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[cashback-social] session::sign aborted — no secret configured (CB_ENCRYPTION_KEY/AUTH_KEY)');
            return '';
        }
        return hash_hmac('sha256', $token, $secret);
    }

    private static function sanitize_provider( string $provider ): string {
        $provider = strtolower(preg_replace('/[^a-z0-9]/i', '', $provider) ?? '');
        if (!in_array($provider, array( 'yandex', 'vkid' ), true)) {
            return '';
        }
        return $provider;
    }
}
