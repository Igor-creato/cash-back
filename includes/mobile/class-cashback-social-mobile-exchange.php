<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Обмен социального auth_code на JWT-пару (мобильный клиент, Expo + PKCE).
 *
 * Flow:
 *   1) Expo-клиент открывает провайдера через `expo-auth-session` с PKCE (S256).
 *   2) Провайдер редиректит обратно в приложение по custom-scheme (например `com.savello.cashback:/oauth/yandex`).
 *   3) Клиент получает `auth_code` + имеет сохранённый `code_verifier`.
 *   4) POST /cashback/v2/auth/social/{provider}/exchange с полями:
 *        - auth_code      (required)
 *        - code_verifier  (required, PKCE S256)
 *        - redirect_uri   (required, должен совпадать с переданным провайдеру)
 *        - vk_device_id   (required для provider=vkid)
 *   5) Сервер обменивает code → access_token → fetch_user_info (trusted email).
 *   6) По (provider, external_id) ищем существующую связь → login.
 *   7) Иначе ищем юзера по verified email → создаём связь → login.
 *   8) Иначе создаём нового юзера (email уже verified провайдером) → связь → login.
 *   9) Шифрованно сохраняем refresh_token провайдера (для последующих API-вызовов).
 *  10) Выдаём JWT-пару (идентично /auth/login).
 *
 * Security (fintech):
 *  - Провайдер ДОЛЖЕН вернуть email. Без email — 422 (клиент должен запросить scope).
 *  - Если email существует у забаненного юзера — 403.
 *  - Провайдер verified email автоматически; auto-link допустим (провайдер отвечает за
 *    верификацию владения). На аудит пишем action `cashback_social_mobile_linked`.
 *  - PKCE code_verifier — mandatory (не менее 43 символов, по RFC 7636).
 *  - Отдельные rate-limit ключи per provider.
 *
 * @since 1.1.0
 */
class Cashback_Social_Mobile_Exchange {

    public const SUPPORTED_PROVIDERS = array( 'yandex', 'vkid' );

    /**
     * Обменять auth_code на JWT-пару.
     *
     * @param string $provider_id  'yandex'|'vkid'.
     * @param array  $payload      ['auth_code','code_verifier','redirect_uri','vk_device_id'?].
     * @param array  $device_info  Устройство для refresh-токена JWT (см. Cashback_JWT_Auth::issue_token_pair).
     * @param array  $request_ctx  ['ip','user_agent'].
     * @return array|WP_Error JWT-пара или ошибка.
     */
    public static function exchange( string $provider_id, array $payload, array $device_info, array $request_ctx ) {
        if (!in_array($provider_id, self::SUPPORTED_PROVIDERS, true)) {
            return new WP_Error('rest_unsupported_provider', __('Unsupported social provider.', 'cashback'), array( 'status' => 400 ));
        }
        if (!class_exists('Cashback_Social_Auth_Providers')) {
            return new WP_Error('rest_social_unavailable', __('Social auth is not available.', 'cashback'), array( 'status' => 503 ));
        }

        $auth_code     = isset($payload['auth_code']) ? (string) $payload['auth_code'] : '';
        $code_verifier = isset($payload['code_verifier']) ? (string) $payload['code_verifier'] : '';
        $redirect_uri  = isset($payload['redirect_uri']) ? (string) $payload['redirect_uri'] : '';

        if ('' === $auth_code || '' === $code_verifier || '' === $redirect_uri) {
            return new WP_Error('rest_missing_params', __('auth_code, code_verifier and redirect_uri are required.', 'cashback'), array( 'status' => 400 ));
        }
        // RFC 7636: code_verifier — 43..128 символов из [A-Z a-z 0-9 -._~].
        if (strlen($code_verifier) < 43 || strlen($code_verifier) > 128 || !preg_match('/^[A-Za-z0-9\-._~]+$/', $code_verifier)) {
            return new WP_Error('rest_invalid_code_verifier', __('Invalid PKCE code_verifier.', 'cashback'), array( 'status' => 400 ));
        }

        $provider = Cashback_Social_Auth_Providers::instance()->get($provider_id);
        if (null === $provider) {
            return new WP_Error('rest_provider_not_configured', __('Provider is not configured.', 'cashback'), array( 'status' => 503 ));
        }

        $extra = array();
        if ('vkid' === $provider_id) {
            $vk_device_id = isset($payload['vk_device_id']) ? (string) $payload['vk_device_id'] : '';
            if ('' === $vk_device_id) {
                return new WP_Error('rest_missing_vk_device_id', __('vk_device_id is required for VK ID flow.', 'cashback'), array( 'status' => 400 ));
            }
            $extra['device_id'] = $vk_device_id;
        }

        // 1. exchange_code.
        try {
            $token_set = $provider->exchange_code($auth_code, $code_verifier, $redirect_uri, $extra);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][Social][' . $provider_id . '] exchange_code failed: ' . $e->getMessage());
            return new WP_Error('exchange_failed', __('OAuth code exchange failed.', 'cashback'), array( 'status' => 400 ));
        }

        $access_token = isset($token_set['access_token']) ? (string) $token_set['access_token'] : '';
        if ('' === $access_token) {
            return new WP_Error('exchange_failed', __('OAuth exchange returned no access token.', 'cashback'), array( 'status' => 400 ));
        }

        // 2. fetch_user_info.
        try {
            $profile = $provider->fetch_user_info($access_token, $extra);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][Social][' . $provider_id . '] fetch_user_info failed: ' . $e->getMessage());
            return new WP_Error('profile_fetch_failed', __('Failed to fetch user profile.', 'cashback'), array( 'status' => 400 ));
        }

        $external_id = isset($profile['external_id']) ? (string) $profile['external_id'] : '';
        $email       = isset($profile['email']) ? trim(strtolower((string) $profile['email'])) : '';
        $name        = isset($profile['name']) ? (string) $profile['name'] : '';
        $avatar      = isset($profile['avatar']) ? (string) $profile['avatar'] : '';

        if ('' === $external_id) {
            return new WP_Error('profile_no_external_id', __('Provider returned no external_id.', 'cashback'), array( 'status' => 400 ));
        }
        if ('' === $email || !is_email($email)) {
            return new WP_Error(
                'email_required',
                __('Provider did not return a verified email. Please grant email scope.', 'cashback'),
                array( 'status' => 422 )
            );
        }

        // 3. Find existing link.
        $link = Cashback_Social_Auth_DB::find_link($provider_id, $external_id);
        if (is_array($link) && ( (int) ( $link['user_id'] ?? 0 ) ) > 0) {
            $user_id = (int) $link['user_id'];

            if (class_exists('Cashback_User_Status') && Cashback_User_Status::is_user_banned($user_id)) {
                return new WP_Error('rest_user_banned', __('User is banned.', 'cashback'), array( 'status' => 403 ));
            }

            self::persist_tokens((int) $link['id'], $token_set);
            Cashback_Social_Auth_DB::touch_last_login((int) $link['id'], (string) ( $request_ctx['ip'] ?? '' ), (string) ( $request_ctx['user_agent'] ?? '' ));

            return Cashback_JWT_Auth::issue_token_pair($user_id, $device_info);
        }

        // 4. Find by email.
        $existing_user = get_user_by('email', $email);
        if ($existing_user instanceof WP_User) {
            $user_id = (int) $existing_user->ID;

            if (class_exists('Cashback_User_Status') && Cashback_User_Status::is_user_banned($user_id)) {
                return new WP_Error('rest_user_banned', __('User is banned.', 'cashback'), array( 'status' => 403 ));
            }

            $link_id = self::create_link($user_id, $provider_id, $external_id, $email, $name, $avatar, $request_ctx);
            if ($link_id <= 0) {
                return new WP_Error('link_failed', __('Could not link social account.', 'cashback'), array( 'status' => 500 ));
            }
            self::persist_tokens($link_id, $token_set);

            // Провайдер уже верифицировал email — снимаем pending если был.
            delete_user_meta($user_id, 'cashback_social_pending');

            do_action('cashback_social_mobile_linked', $user_id, $provider_id, $external_id);

            return Cashback_JWT_Auth::issue_token_pair($user_id, $device_info);
        }

        // 5. Create new user (email verified провайдером — pending не ставим).
        $user_id = self::create_user_from_social($email, $name);
        if ($user_id instanceof WP_Error) {
            return $user_id;
        }

        $link_id = self::create_link($user_id, $provider_id, $external_id, $email, $name, $avatar, $request_ctx);
        if ($link_id <= 0) {
            return new WP_Error('link_failed', __('Could not link social account.', 'cashback'), array( 'status' => 500 ));
        }
        self::persist_tokens($link_id, $token_set);

        do_action('cashback_social_mobile_user_created', $user_id, $provider_id, $external_id);

        return Cashback_JWT_Auth::issue_token_pair($user_id, $device_info);
    }

    /**
     * Создать запись о связке social account ↔ WP user.
     *
     * @return int link_id или 0.
     */
    private static function create_link(
        int $user_id,
        string $provider_id,
        string $external_id,
        string $email,
        string $name,
        string $avatar,
        array $request_ctx
    ): int {
        return Cashback_Social_Auth_DB::save_link(array(
            'user_id'            => $user_id,
            'provider'           => $provider_id,
            'external_id'        => $external_id,
            'email_at_link_time' => mb_substr($email, 0, 191),
            'display_name'       => mb_substr($name, 0, 191),
            'avatar_url'         => mb_substr($avatar, 0, 2048),
            'linked_at'          => current_time('mysql'),
            'last_login_at'      => current_time('mysql'),
            'link_ip'            => mb_substr((string) ( $request_ctx['ip'] ?? '' ), 0, 45),
            'link_user_agent'    => mb_substr((string) ( $request_ctx['user_agent'] ?? '' ), 0, 255),
        ));
    }

    /**
     * Зашифровать и сохранить refresh_token провайдера.
     * Не фатально при сбое — логируем и продолжаем.
     */
    private static function persist_tokens( int $link_id, array $token_set ): void {
        if (!class_exists('Cashback_Social_Auth_Token_Store')) {
            return;
        }
        $refresh = isset($token_set['refresh_token']) ? (string) $token_set['refresh_token'] : '';
        if ('' === $refresh) {
            return;
        }

        $access_expires_at = null;
        if (isset($token_set['expires_in']) && is_numeric($token_set['expires_in'])) {
            $access_expires_at = gmdate('Y-m-d H:i:s', time() + (int) $token_set['expires_in']);
        }
        $scope = isset($token_set['scope']) ? (string) $token_set['scope'] : '';

        try {
            Cashback_Social_Auth_Token_Store::save_tokens($link_id, $refresh, $access_expires_at, $scope);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][Social] persist_tokens failed: ' . $e->getMessage());
        }
    }

    /**
     * Создать WP user из социального профиля.
     * Email уже verified провайдером — без pending.
     *
     * @return int|WP_Error
     */
    private static function create_user_from_social( string $email, string $name ) {
        $login = self::generate_unique_login_from_email($email);

        $user_id = wp_insert_user(array(
            'user_login'           => $login,
            'user_email'           => $email,
            'user_pass'            => wp_generate_password(32, true, true),
            'role'                 => class_exists('WooCommerce') ? 'customer' : get_option('default_role', 'subscriber'),
            'display_name'         => '' !== $name ? sanitize_text_field($name) : self::display_name_from_email($email),
            'show_admin_bar_front' => false,
        ));

        if (is_wp_error($user_id)) {
            // Логируем только error_code (get_error_message WP core содержит email/username в тексте).
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][Social] wp_insert_user failed: ' . $user_id->get_error_code());
            return new WP_Error('rest_user_create_failed', __('Could not create user account.', 'cashback'), array( 'status' => 500 ));
        }

        return (int) $user_id;
    }

    private static function generate_unique_login_from_email( string $email ): string {
        $base = sanitize_user(strstr($email, '@', true) ?: 'user', true);
        $base = '' === $base ? 'user' : $base;
        $base = substr($base, 0, 40);

        $login   = $base;
        $attempt = 0;
        while (username_exists($login) && $attempt < 20) {
            ++$attempt;
            $login = $base . '_' . wp_generate_password(6, false);
        }
        if (username_exists($login)) {
            $login = $base . '_' . wp_generate_password(12, false);
        }
        return $login;
    }

    private static function display_name_from_email( string $email ): string {
        $local = strstr($email, '@', true);
        return $local ? sanitize_text_field($local) : 'User';
    }
}
