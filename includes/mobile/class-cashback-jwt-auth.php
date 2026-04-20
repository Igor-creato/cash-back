<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Центральная JWT-аутентификация для мобильного REST API (`cashback/v2`).
 *
 * Архитектура:
 *  - `Authorization: Bearer <jwt>` — HS256 access-токен.
 *  - Access живёт 15 минут. Claims: `iss`, `sub`, `iat`, `exp`, `jti`, `fam` (family_id), `typ='access'`.
 *  - Refresh-токен — opaque 64-hex, хранится хешированным в `cashback_refresh_tokens`.
 *  - Ротация refresh: каждый `/auth/refresh` выдаёт новый access+refresh и ревокает старый refresh.
 *  - Reuse-detection: использование уже revoked refresh → отзыв всей family_id.
 *
 * Секреты:
 *  - `CB_JWT_SECRET` — основной (64 hex, wp-config.php).
 *  - `CB_JWT_SECRET_PREV` — предыдущий (опционально, grace-период при ротации).
 *
 * Интеграция с ядром WP:
 *  - Хук `rest_authentication_errors` (приоритет 95) — раньше cookie-nonce check и до
 *    `Cashback_REST_API::authenticate_extension_cookie()`. При валидном Bearer токене выставляет
 *    `wp_set_current_user()` и возвращает `true` (short-circuit nonce).
 *  - Все защищённые endpoints `cashback/v2` используют `permission_callback` =
 *    `Cashback_JWT_Auth::require_authenticated_user`.
 *
 * @since 1.1.0
 */
class Cashback_JWT_Auth {

    public const ISSUER              = 'cashback-plugin';
    public const ACCESS_TTL_SECONDS  = 15 * MINUTE_IN_SECONDS;
    public const TOKEN_TYPE_ACCESS   = 'access';
    private const CURRENT_USER_FLAG  = 'cashback_jwt_current_user_id';
    private const CURRENT_FAMILY_KEY = 'cashback_jwt_current_family';

    private static ?self $instance = null;

    /** @var int|null ID пользователя, авторизованного через Bearer в текущем запросе. */
    private ?int $bearer_user_id = null;

    /** @var string|null family_id токена, из которого извлечён user (нужно при /auth/logout). */
    private ?string $bearer_family = null;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Хуки (вызывается один раз из главного файла плагина).
     */
    public static function init(): void {
        $instance = self::get_instance();
        add_filter('rest_authentication_errors', array( $instance, 'authenticate_bearer' ), 95);
    }

    private function __construct() {}

    /**
     * Фильтр `rest_authentication_errors`: если есть валидный Bearer — выставляет current_user
     * и short-circuit'ит nonce-проверку ядра.
     *
     * @param \WP_Error|null|true $result
     * @return \WP_Error|null|true
     */
    public function authenticate_bearer( $result ) {
        if (null !== $result) {
            return $result;
        }

        $bearer = $this->extract_bearer();
        if (null === $bearer) {
            return $result;
        }

        try {
            $claims = Cashback_JWT::decode($bearer, $this->get_jwt_secrets());
        } catch (\Throwable $e) {
            // Не подпись / истёк / невалиден. Возвращаем WP_Error с 401,
            // чтобы клиент сразу сделал /auth/refresh (а не нарвался на 403 от permission_callback).
            return new \WP_Error(
                'rest_jwt_invalid',
                $e->getMessage(),
                array( 'status' => 401 )
            );
        }

        if (( $claims['typ'] ?? '' ) !== self::TOKEN_TYPE_ACCESS) {
            return new \WP_Error(
                'rest_jwt_invalid_type',
                'Bearer token is not an access token',
                array( 'status' => 401 )
            );
        }

        $user_id = isset($claims['sub']) ? (int) $claims['sub'] : 0;
        if ($user_id <= 0 || !get_userdata($user_id)) {
            return new \WP_Error(
                'rest_jwt_user_not_found',
                'User not found',
                array( 'status' => 401 )
            );
        }

        $this->bearer_user_id = $user_id;
        $this->bearer_family  = isset($claims['fam']) ? (string) $claims['fam'] : null;
        wp_set_current_user($user_id);

        return true;
    }

    /**
     * Permission callback для защищённых маршрутов `cashback/v2`.
     *
     * @return true|\WP_Error
     */
    public static function require_authenticated_user() {
        if (self::get_instance()->bearer_user_id !== null) {
            return true;
        }
        if (is_user_logged_in()) {
            // На случай будущей интеграции с web-куками — оставляем поведение консистентным с v1.
            return true;
        }
        return new \WP_Error(
            'rest_jwt_required',
            'Authorization Bearer token is required',
            array( 'status' => 401 )
        );
    }

    /**
     * Получить ID авторизованного Bearer-юзера (или null).
     */
    public function get_bearer_user_id(): ?int {
        return $this->bearer_user_id;
    }

    /**
     * Получить family_id текущего Bearer-токена (или null).
     */
    public function get_bearer_family_id(): ?string {
        return $this->bearer_family;
    }

    // =========================================================================
    // Высокоуровневые операции: login / refresh / logout / logout_all
    // =========================================================================

    /**
     * Аутентифицировать пользователя по email+password и выпустить пару токенов.
     *
     * @param array{device_id?:?string,device_name?:?string,platform?:?string,app_version?:?string,ip?:?string,user_agent?:?string} $device_info
     * @return array{access:string,refresh:string,access_expires_at:int,refresh_expires_at:string,user_id:int}|\WP_Error
     */
    public static function login( string $email_or_login, string $password, array $device_info ) {
        $email_or_login = trim($email_or_login);
        if ('' === $email_or_login || '' === $password) {
            return new \WP_Error(
                'rest_jwt_missing_credentials',
                'Email and password are required',
                array( 'status' => 400 )
            );
        }

        // `wp_authenticate()` принимает login ИЛИ email, вернёт WP_User или WP_Error.
        $user = wp_authenticate($email_or_login, $password);
        if (is_wp_error($user)) {
            // Не раскрываем причину (не сообщаем "нет такого email" vs "пароль неверный").
            return new \WP_Error(
                'rest_jwt_invalid_credentials',
                'Invalid email or password',
                array( 'status' => 401 )
            );
        }

        if (class_exists('Cashback_User_Status') && method_exists('Cashback_User_Status', 'is_banned') && Cashback_User_Status::is_banned((int) $user->ID)) {
            return new \WP_Error(
                'rest_jwt_user_banned',
                'This account is banned',
                array( 'status' => 403 )
            );
        }

        return self::issue_token_pair((int) $user->ID, $device_info);
    }

    /**
     * Обменять refresh-токен на новую пару (ротация + reuse-detection).
     *
     * @param array<string,?string> $device_info
     * @return array{access:string,refresh:string,access_expires_at:int,refresh_expires_at:string,user_id:int}|\WP_Error
     */
    public static function refresh( string $refresh_plaintext, array $device_info ) {
        $refresh_plaintext = trim($refresh_plaintext);
        if ('' === $refresh_plaintext) {
            return new \WP_Error(
                'rest_jwt_missing_refresh',
                'Refresh token is required',
                array( 'status' => 400 )
            );
        }

        $row = Cashback_Refresh_Token_Store::find_by_plaintext($refresh_plaintext);
        if (null === $row) {
            return new \WP_Error(
                'rest_jwt_refresh_unknown',
                'Refresh token not recognized',
                array( 'status' => 401 )
            );
        }

        // Reuse-detection: токен уже отозван ранее — сигнал компрометации цепочки.
        if (null !== $row->revoked_at) {
            Cashback_Refresh_Token_Store::revoke_family((string) $row->family_id, 'reuse_detected');
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- security audit event.
            error_log(sprintf(
                '[Cashback][JWT] Refresh token reuse detected: user=%d family=%s token_id=%d',
                (int) $row->user_id,
                (string) $row->family_id,
                (int) $row->id
            ));
            return new \WP_Error(
                'rest_jwt_refresh_reused',
                'Refresh token has been revoked',
                array( 'status' => 401 )
            );
        }

        // Срок.
        if (strtotime((string) $row->expires_at . ' UTC') < time()) {
            Cashback_Refresh_Token_Store::revoke((int) $row->id, 'expired');
            return new \WP_Error(
                'rest_jwt_refresh_expired',
                'Refresh token expired',
                array( 'status' => 401 )
            );
        }

        // Пользователь всё ещё существует / не забанен.
        $user_id = (int) $row->user_id;
        if (!get_userdata($user_id)) {
            Cashback_Refresh_Token_Store::revoke((int) $row->id, 'admin');
            return new \WP_Error(
                'rest_jwt_user_not_found',
                'User not found',
                array( 'status' => 401 )
            );
        }
        if (class_exists('Cashback_User_Status') && method_exists('Cashback_User_Status', 'is_banned') && Cashback_User_Status::is_banned($user_id)) {
            Cashback_Refresh_Token_Store::revoke_family((string) $row->family_id, 'admin');
            return new \WP_Error(
                'rest_jwt_user_banned',
                'This account is banned',
                array( 'status' => 403 )
            );
        }

        $rotated = Cashback_Refresh_Token_Store::rotate($row, $device_info);
        Cashback_Refresh_Token_Store::touch_last_used($rotated['id']);

        $access = self::sign_access_token($user_id, $rotated['family_id'], $rotated['id']);
        return array(
            'access'             => $access['token'],
            'access_expires_at'  => $access['expires_at'],
            'refresh'            => $rotated['plaintext'],
            'refresh_expires_at' => $rotated['expires_at'],
            'user_id'            => $user_id,
        );
    }

    /**
     * Отозвать один refresh-токен (logout с текущего устройства).
     */
    public static function logout( string $refresh_plaintext ): bool {
        $row = Cashback_Refresh_Token_Store::find_by_plaintext($refresh_plaintext);
        if (null === $row) {
            return false;
        }
        if (null !== $row->revoked_at) {
            return true;
        }
        Cashback_Refresh_Token_Store::revoke((int) $row->id, 'logout');
        return true;
    }

    /**
     * Отозвать все refresh-токены пользователя (logout со всех устройств).
     */
    public static function logout_all( int $user_id ): int {
        return Cashback_Refresh_Token_Store::revoke_all_for_user($user_id, 'logout_all');
    }

    /**
     * Выпустить полную пару токенов (login + новая family_id).
     *
     * @param array<string,?string> $device_info
     * @return array{access:string,refresh:string,access_expires_at:int,refresh_expires_at:string,user_id:int}
     */
    public static function issue_token_pair( int $user_id, array $device_info ): array {
        $refresh = Cashback_Refresh_Token_Store::issue_new_family($user_id, $device_info);
        $access  = self::sign_access_token($user_id, $refresh['family_id'], $refresh['id']);

        return array(
            'access'             => $access['token'],
            'access_expires_at'  => $access['expires_at'],
            'refresh'            => $refresh['plaintext'],
            'refresh_expires_at' => $refresh['expires_at'],
            'user_id'            => $user_id,
        );
    }

    /**
     * Подписать access-токен.
     *
     * @return array{token:string, expires_at:int}
     */
    private static function sign_access_token( int $user_id, string $family_id, int $refresh_id ): array {
        $now = time();
        $exp = $now + self::ACCESS_TTL_SECONDS;

        $claims = array(
            'iss' => self::ISSUER,
            'sub' => (string) $user_id,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $exp,
            'jti' => cashback_generate_uuid7(false),
            'fam' => $family_id,
            'rid' => $refresh_id,
            'typ' => self::TOKEN_TYPE_ACCESS,
        );

        $secrets = self::get_jwt_secrets();
        $token   = Cashback_JWT::encode($claims, $secrets[0]);

        return array(
            'token'      => $token,
            'expires_at' => $exp,
        );
    }

    /**
     * Получить список допустимых секретов (первый — текущий).
     *
     * @return array<int,string>
     * @throws RuntimeException Если основной секрет не настроен.
     */
    public static function get_jwt_secrets(): array {
        $current = defined('CB_JWT_SECRET') ? (string) CB_JWT_SECRET : '';
        if ('' === $current || strlen($current) < 32) {
            throw new RuntimeException('CB_JWT_SECRET is not configured (must be >=32 chars in wp-config.php)');
        }

        $secrets = array( $current );

        $previous = defined('CB_JWT_SECRET_PREV') ? (string) CB_JWT_SECRET_PREV : '';
        if ('' !== $previous && strlen($previous) >= 32 && $previous !== $current) {
            $secrets[] = $previous;
        }

        return $secrets;
    }

    /**
     * Извлечь Bearer-токен из заголовка Authorization.
     */
    private function extract_bearer(): ?string {
        $header = '';

        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_AUTHORIZATION']));
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            // Apache + FastCGI может не пробрасывать Authorization без SetEnvIf.
            $header = sanitize_text_field(wp_unslash((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        } elseif (function_exists('apache_request_headers')) {
            $all = apache_request_headers();
            if (is_array($all)) {
                foreach ($all as $name => $value) {
                    if (0 === strcasecmp($name, 'Authorization')) {
                        $header = sanitize_text_field((string) $value);
                        break;
                    }
                }
            }
        }

        if ('' === $header) {
            return null;
        }

        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return null;
        }

        return $m[1];
    }
}
