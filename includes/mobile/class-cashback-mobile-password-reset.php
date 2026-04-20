<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Забыл пароль / сброс пароля для мобильного клиента.
 *
 * Flow:
 *   1) POST /auth/password/forgot {email, captcha_token?}
 *      → всегда возвращаем 200 generic (anti-enumeration).
 *      → если email существует и не забанен — выпускаем токен reset (TTL 30мин) + письмо.
 *   2) POST /auth/password/reset {token, new_password}
 *      → consume token (atomic single-use) → user_id.
 *      → валидация новой политики пароля (включая, что не равен email/login).
 *      → wp_set_password() — это САМО ПО СЕБЕ инвалидирует все WP-cookie-сессии.
 *      → дополнительно: revoke_all для refresh-JWT → полный logout всех устройств.
 *      → clean_user_cache; не автоматически логиним — клиент должен сделать /auth/login.
 *
 * Critical: при успешном сбросе ВСЕ refresh-токены пользователя отзываются —
 * защита от случая, когда атакующий получил refresh и жертва инициирует reset.
 *
 * @since 1.1.0
 */
class Cashback_Mobile_Password_Reset {

    /**
     * Инициировать сброс пароля. Всегда возвращаем generic-ответ.
     *
     * @param string $email   Email пользователя.
     * @param array  $context ['ip', 'user_agent'].
     * @return array Generic success.
     */
    public static function forgot( string $email, array $context ): array {
        $email = trim(strtolower($email));

        // Пустой или невалидный email — тихо success.
        if ('' === $email || !is_email($email)) {
            return array( 'ok' => true );
        }

        $user = get_user_by('email', $email);
        if (!$user instanceof WP_User) {
            return array( 'ok' => true );
        }

        // Забаненным не шлём (но ответ не меняется).
        if (class_exists('Cashback_User_Status') && Cashback_User_Status::is_user_banned((int) $user->ID)) {
            return array( 'ok' => true );
        }

        try {
            self::dispatch_reset_email((int) $user->ID, $email, $context);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback] password-reset send failed: ' . $e->getMessage());
        }

        return array( 'ok' => true );
    }

    /**
     * Завершить сброс: валидация token + новый пароль.
     *
     * @param string $token        Plaintext из письма.
     * @param string $new_password Новый пароль.
     * @return array|WP_Error
     */
    public static function reset( string $token, string $new_password ) {
        $user_id = Cashback_Auth_Action_Store::consume($token, Cashback_Auth_Action_Store::PURPOSE_PASSWORD_RESET);
        if (null === $user_id) {
            return new WP_Error(
                'rest_invalid_reset_token',
                __('Reset link is invalid or has expired.', 'cashback'),
                array( 'status' => 400 )
            );
        }

        $user = get_user_by('id', $user_id);
        if (!$user instanceof WP_User) {
            return new WP_Error('rest_invalid_reset_token', __('Reset link is invalid.', 'cashback'), array( 'status' => 400 ));
        }

        if (class_exists('Cashback_User_Status') && Cashback_User_Status::is_user_banned($user_id)) {
            return new WP_Error('rest_user_banned', __('User is banned.', 'cashback'), array( 'status' => 403 ));
        }

        // Политика: не равен email/login.
        $policy = Cashback_Password_Policy::validate(
            $new_password,
            array(
                'email' => (string) $user->user_email,
                'login' => (string) $user->user_login,
            )
        );
        if (is_wp_error($policy)) {
            return $policy;
        }

        // Не разрешаем повторить текущий пароль.
        if (wp_check_password($new_password, $user->user_pass, $user_id)) {
            return new WP_Error(
                'weak_password',
                __('New password must differ from the current one.', 'cashback'),
                array( 'status' => 422 )
            );
        }

        // wp_set_password само вызывает clean_user_cache и инвалидирует session_tokens.
        wp_set_password($new_password, $user_id);

        // Отзываем ВСЕ refresh-токены — форсируем повторный логин на всех устройствах.
        if (class_exists('Cashback_Refresh_Token_Store')) {
            Cashback_Refresh_Token_Store::revoke_all_for_user($user_id, 'password_reset');
        }

        /**
         * Хук для аудита / нотификации других систем.
         *
         * @param int $user_id Пользователь, сбросивший пароль.
         */
        do_action('cashback_mobile_password_reset_completed', $user_id);

        // Информационное уведомление.
        try {
            self::dispatch_changed_email($user_id, (string) $user->user_email);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback] password-changed notice failed: ' . $e->getMessage());
        }

        // Клиент должен сделать обычный /auth/login.
        return array( 'ok' => true );
    }

    /**
     * @throws RuntimeException
     */
    private static function dispatch_reset_email( int $user_id, string $email, array $context ): void {
        $plaintext = Cashback_Auth_Action_Store::issue(
            $user_id,
            Cashback_Auth_Action_Store::PURPOSE_PASSWORD_RESET,
            $context
        );

        $base = defined('CB_MOBILE_RESET_URL') ? (string) constant('CB_MOBILE_RESET_URL') : '';
        if ('' === $base) {
            $base = site_url('/mobile/password-reset');
        }
        $link = add_query_arg('token', rawurlencode($plaintext), $base);

        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject   = sprintf(
            /* translators: %s: site name */
            __('[%s] Password reset', 'cashback'),
            $site_name
        );
        $ip      = isset($context['ip']) ? (string) $context['ip'] : '';
        $message = sprintf(
            /* translators: %1$s: reset link, %2$d: minutes until expiration, %3$s: IP address of request */
            __("A password reset was requested for your account.\n\nOpen this link to set a new password:\n\n%1\$s\n\nThe link expires in %2\$d minutes.\n\nRequest IP: %3\$s\nIf it wasn't you — ignore this message; your password will not be changed.", 'cashback'),
            esc_url_raw($link),
            (int) ( Cashback_Auth_Action_Store::TTL_PASSWORD_RESET / MINUTE_IN_SECONDS ),
            '' === $ip ? '-' : $ip
        );

        if (class_exists('Cashback_Email_Sender')) {
            Cashback_Email_Sender::get_instance()->send($email, $subject, $message, 'mobile_password_reset', $user_id);
            return;
        }

        wp_mail($email, $subject, $message);
    }

    private static function dispatch_changed_email( int $user_id, string $email ): void {
        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject   = sprintf(
            /* translators: %s: site name */
            __('[%s] Your password was changed', 'cashback'),
            $site_name
        );
        $message = __(
            "Your account password has just been changed.\n\nAll active sessions have been signed out.\n\nIf this was not you — contact support immediately.",
            'cashback'
        );

        if (class_exists('Cashback_Email_Sender')) {
            Cashback_Email_Sender::get_instance()->send($email, $subject, $message, 'mobile_password_changed', $user_id);
            return;
        }
        wp_mail($email, $subject, $message);
    }
}
