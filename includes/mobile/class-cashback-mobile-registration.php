<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Регистрация и верификация email для мобильного клиента.
 *
 * Flow регистрации:
 *   1) POST /auth/register {email, password, captcha_token?, referral_code?}
 *   2) Проверка rate-limit (critical) + CAPTCHA (если требуется по IP-score).
 *   3) Валидация email + политика пароля.
 *   4) Если email уже есть — возвращаем 200 с generic-ответом (anti-enumeration).
 *   5) wp_insert_user() + мета `cashback_social_pending = 1` → login заблокирован
 *      до подтверждения (через существующий фильтр `block_pending_login`).
 *   6) Выпуск одноразового токена (TTL 24ч) + письмо со ссылкой deep-link.
 *
 * Flow верификации:
 *   1) POST /auth/verify-email {token}
 *   2) Consume token (atomic single-use) → user_id.
 *   3) delete_user_meta pending → issue JWT pair.
 *
 * @since 1.1.0
 */
class Cashback_Mobile_Registration {

    private const PENDING_META  = 'cashback_social_pending';
    private const REFERRAL_META = 'cashback_mobile_referral_code';

    /**
     * Зарегистрировать пользователя.
     *
     * Возвращает generic-ответ независимо от существования email — чтобы избежать
     * user-enumeration. Реальная отправка письма происходит только при успешном
     * wp_insert_user().
     *
     * @param array $payload   ['email', 'password', 'referral_code'?].
     * @param array $context   ['ip', 'user_agent'].
     * @return array Generic-ответ для клиента.
     * @throws Exception Never throws — все ошибки оборачиваются в generic success.
     */
    public static function register( array $payload, array $context ): array {
        $email    = is_string($payload['email'] ?? null) ? trim(strtolower($payload['email'])) : '';
        $password = is_string($payload['password'] ?? null) ? (string) $payload['password'] : '';

        // Валидация email обязательна — это не утечка, т.к. без корректного email регистрация бессмысленна.
        if ('' === $email || !is_email($email)) {
            return array(
                'ok'    => false,
                'error' => new WP_Error('rest_invalid_email', __('Valid email is required.', 'cashback'), array( 'status' => 400 )),
            );
        }

        // Валидация пароля — явная, так как клиент должен знать min-length/complexity.
        $policy = Cashback_Password_Policy::validate($password, array( 'email' => $email ));
        if (is_wp_error($policy)) {
            return array(
                'ok'    => false,
                'error' => $policy,
            );
        }

        // Если пользователь уже существует — тихо выходим с generic success.
        $existing_id = email_exists($email);
        if ($existing_id) {
            // Не раскрываем факт существования. Но для уже существующих НЕверифицированных
            // пользователей — повторно шлём письмо (может быть, первое не дошло).
            if ((int) get_user_meta((int) $existing_id, self::PENDING_META, true) === 1) {
                try {
                    self::dispatch_verify_email((int) $existing_id, $email, $context);
                } catch (\Throwable $e) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
                    error_log('[Cashback] verify-email resend failed: ' . $e->getMessage());
                }
            }
            return array(
				'ok'     => true,
				'status' => 'pending',
			);
        }

        // Создаём нового юзера.
        $login = self::generate_unique_login_from_email($email);

        $user_id = wp_insert_user(array(
            'user_login'           => $login,
            'user_email'           => $email,
            'user_pass'            => $password,
            'role'                 => class_exists('WooCommerce') ? 'customer' : get_option('default_role', 'subscriber'),
            'display_name'         => self::display_name_from_email($email),
            'show_admin_bar_front' => false,
        ));

        if (is_wp_error($user_id)) {
            // Не раскрываем подробности. В логе оставляем для админа.
            // Логируем только error_code (get_error_message WP core содержит email/username в тексте).
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback] Registration failed: ' . $user_id->get_error_code());
            return array(
				'ok'     => true,
				'status' => 'pending',
			);
        }

        $user_id = (int) $user_id;

        // Флаг pending — блокирует password-login через Cashback_Social_Auth_Account_Manager::block_pending_login.
        update_user_meta($user_id, self::PENDING_META, 1);

        // Опциональный реф-код — сохраняем meta для обработки в affiliate-пайплайне.
        $referral_code = isset($payload['referral_code']) ? (string) $payload['referral_code'] : '';
        if ('' !== $referral_code) {
            $referral_code = preg_replace('/[^a-zA-Z0-9_\-]/', '', $referral_code);
            if ('' !== $referral_code) {
                update_user_meta($user_id, self::REFERRAL_META, substr($referral_code, 0, 64));
            }
        }

        try {
            self::dispatch_verify_email($user_id, $email, $context);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback] verify-email send failed: ' . $e->getMessage());
        }

        return array(
			'ok'     => true,
			'status' => 'pending',
		);
    }

    /**
     * Подтвердить email по одноразовому токену и выдать JWT-пару.
     *
     * @param string $token       Plaintext из письма.
     * @param array  $device_info Устройство (см. Cashback_JWT_Auth::issue_token_pair).
     * @return array|WP_Error
     */
    public static function verify_email( string $token, array $device_info ) {
        $user_id = Cashback_Auth_Action_Store::consume($token, Cashback_Auth_Action_Store::PURPOSE_EMAIL_VERIFY);
        if (null === $user_id) {
            return new WP_Error(
                'rest_invalid_verification_token',
                __('Verification link is invalid or has expired.', 'cashback'),
                array( 'status' => 400 )
            );
        }

        $user = get_user_by('id', $user_id);
        if (!$user instanceof WP_User) {
            return new WP_Error('rest_invalid_verification_token', __('Verification link is invalid.', 'cashback'), array( 'status' => 400 ));
        }

        if (class_exists('Cashback_User_Status') && Cashback_User_Status::is_user_banned($user_id)) {
            return new WP_Error('rest_user_banned', __('User is banned.', 'cashback'), array( 'status' => 403 ));
        }

        delete_user_meta($user_id, self::PENDING_META);

        /**
         * Хук для интеграции (реф-программа, WC-customer sync и т.п.).
         *
         * @param int $user_id Активированный пользователь.
         */
        do_action('cashback_mobile_user_verified', $user_id);

        // Выпускаем access+refresh пару как при login.
        return Cashback_JWT_Auth::issue_token_pair($user_id, $device_info);
    }

    /**
     * Выпустить токен verify_email и отправить письмо со ссылкой.
     *
     * Deep-link формат: {CB_MOBILE_VERIFY_URL}?token=XXX
     * Если CB_MOBILE_VERIFY_URL не задан — fallback на site_url + query.
     *
     * @throws RuntimeException
     */
    private static function dispatch_verify_email( int $user_id, string $email, array $context ): void {
        $plaintext = Cashback_Auth_Action_Store::issue(
            $user_id,
            Cashback_Auth_Action_Store::PURPOSE_EMAIL_VERIFY,
            $context
        );

        $base = defined('CB_MOBILE_VERIFY_URL') ? (string) constant('CB_MOBILE_VERIFY_URL') : '';
        if ('' === $base) {
            $base = site_url('/mobile/verify-email');
        }
        $link = add_query_arg('token', rawurlencode($plaintext), $base);

        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject   = sprintf(
            /* translators: %s: site name */
            __('[%s] Confirm your email', 'cashback'),
            $site_name
        );

        $message = sprintf(
            /* translators: %1$s: verification link, %2$d: hours until expiration */
            __("Welcome!\n\nPlease confirm your email address by opening this link:\n\n%1\$s\n\nThe link expires in %2\$d hours.\n\nIf you did not sign up, ignore this message.", 'cashback'),
            esc_url_raw($link),
            (int) ( Cashback_Auth_Action_Store::TTL_EMAIL_VERIFY / HOUR_IN_SECONDS )
        );

        if (class_exists('Cashback_Email_Sender')) {
            Cashback_Email_Sender::get_instance()->send($email, $subject, $message, 'mobile_verify_email', $user_id);
            return;
        }

        wp_mail($email, $subject, $message);
    }

    /**
     * Сгенерировать уникальный login из email.
     * Тот же паттерн, что в Cashback_Social_Auth_Account_Manager.
     */
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
