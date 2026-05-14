<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Registration_Gate
 *
 * Единый guard для всех путей регистрации новых пользователей в плагине.
 * Источник правды — стандартная WP-опция `users_can_register` (она же чекбокс
 * Settings → General → Членство → Любой может зарегистрироваться).
 *
 * Используется в:
 *  - Cashback_SC_Auth_Pages_Register::maybe_handle (POST /register/)
 *  - Cashback_SC_Auth_Pages_Shortcodes::render_register (GET /register/)
 *  - Cashback_Social_Auth_Account_Manager (callback dispatch + email_prompt
 *    + register_consent_submission + create_pending_user_and_link)
 *  - Cashback_Social_Auth_Router (permission_callback для HTML-routes)
 *  - Cashback_Social_Auth_Renderer (скрытие кнопок на /register/ context)
 *  - templates/form-login.php (скрытие ссылок «Регистрация» на /login/)
 *
 * @since 4.1.0
 */
final class Cashback_Registration_Gate {

    public const ERROR_CODE = 'registration_disabled';

    /**
     * Разрешена ли регистрация новых пользователей.
     *
     * @return bool true если опция включена, false иначе.
     */
    public static function is_allowed(): bool {
        return (int) get_option('users_can_register', 0) === 1;
    }

    /**
     * Сообщение для гостя при попытке регистрации.
     */
    public static function denial_message(): string {
        return __('Регистрация новых пользователей временно недоступна. Пожалуйста, попробуйте позже.', 'cashback-plugin');
    }

    /**
     * WP_Error для REST-роутов (permission_callback).
     */
    public static function denial_wp_error(): WP_Error {
        return new WP_Error(
            self::ERROR_CODE,
            self::denial_message(),
            array( 'status' => 403 )
        );
    }
}
