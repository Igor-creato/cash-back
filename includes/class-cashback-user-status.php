<?php
/**
 * Класс для проверки статуса пользователя (banned)
 *
 * Централизованная проверка статуса пользователя для блокировки
 * забаненных пользователей от доступа к функционалу кэшбэк-сервиса.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс Cashback_User_Status
 */
class Cashback_User_Status {

    /**
     * Проверяет, забанен ли пользователь
     *
     * @param int $user_id ID пользователя
     * @return bool true если забанен
     */
    public static function is_user_banned( int $user_id ): bool {
        global $wpdb;

        $table  = $wpdb->prefix . 'cashback_user_profile';
        $status = $wpdb->get_var( $wpdb->prepare(
            'SELECT status FROM %i WHERE user_id = %d',
            $table,
            $user_id
        ) );

        return $status === 'banned';
    }

    /**
     * Получает информацию о бане пользователя.
     *
     * Возвращаются ОБА поля: `ban_reason` (публичная причина — для пользователя)
     * и `ban_reason_admin` (внутренняя — только для админов). Закрывает OBS-06
     * (E2E run B 2026-04-30): админ-комментарии не должны утекать пользователю.
     *
     * Колонка `ban_reason_admin` добавлена в миграции v6 — на legacy инсталляциях
     * до v6 поле может отсутствовать, читаем через массив-доступ с null-coalesce.
     *
     * @param int $user_id ID пользователя
     * @return array{banned_at:?string,ban_reason:?string,ban_reason_admin:?string}|null
     */
    public static function get_ban_info( int $user_id ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_user_profile';
        $info  = $wpdb->get_row( $wpdb->prepare(
            "SELECT banned_at, ban_reason, ban_reason_admin FROM %i
             WHERE user_id = %d AND status = 'banned'",
            $table,
            $user_id
        ), ARRAY_A );

        if ( ! $info ) {
            return null;
        }

        // Гарантируем наличие ключей даже если миграция v6 ещё не прошла.
        $info['ban_reason']       = isset( $info['ban_reason'] ) ? (string) $info['ban_reason'] : '';
        $info['ban_reason_admin'] = isset( $info['ban_reason_admin'] ) ? (string) $info['ban_reason_admin'] : '';

        return $info;
    }

    /**
     * Генерирует ПУБЛИЧНОЕ сообщение для забаненного пользователя.
     *
     * Использует только `ban_reason` (публичная причина). Поле `ban_reason_admin`
     * НИКОГДА не показывается пользователю. Если публичная причина пустая —
     * показываем generic message (OBS-06 fix + UX choice 2026-04-30).
     *
     * @param array|null $ban_info Информация о бане из get_ban_info()
     * @return string Сообщение для пользователя
     */
    public static function get_banned_message( ?array $ban_info = null ): string {
        if ( $ban_info && ! empty( $ban_info['ban_reason'] ) ) {
            return sprintf(
                /* translators: %s: публичная причина блокировки аккаунта */
                __('Ваш аккаунт заблокирован. Причина: %s. Для разблокировки обратитесь к администратору.', 'cashback-plugin'),
                esc_html( $ban_info['ban_reason'] )
            );
        }

        return __('Ваш аккаунт заблокирован. Для разблокировки обратитесь к администратору.', 'cashback-plugin');
    }

    /**
     * Фильтр `wp_authenticate_user`: блокирует логин забаненных пользователей.
     *
     * Срабатывает ПОСЛЕ проверки пароля, ДО установки auth-cookie. Возврат
     * WP_Error отменяет логин и показывает стандартный экран ошибки WP.
     *
     * Закрывает OBS-05 (E2E run B 2026-04-30): banned юзер с status='banned'
     * в `cashback_user_profile` всё равно мог залогиниться и видеть личный
     * кабинет (хоть withdrawal был заблокирован отдельно).
     *
     * @param WP_User|WP_Error $user
     * @return WP_User|WP_Error
     */
    public static function block_banned_login( $user ) {
        // Если на этапе аутентификации уже WP_Error (неверный пароль и пр.) —
        // не вмешиваемся, чтобы не маскировать другие ошибки.
        if ( ! ( $user instanceof WP_User ) ) {
            return $user;
        }

        if ( ! self::is_user_banned( (int) $user->ID ) ) {
            return $user;
        }

        $ban_info = self::get_ban_info( (int) $user->ID );

        return new WP_Error(
            'cashback_user_banned',
            self::get_banned_message( $ban_info )
        );
    }

    /**
     * Фильтр `woocommerce_login_errors`: заменяет generic-сообщение WC
     * на наше специфичное «Ваш аккаунт заблокирован...» для banned юзеров.
     *
     * Закрывает OBS-05 cosmetic (E2E run B 2026-04-30): после `block_banned_login`
     * возвращает WP_Error с кодом `cashback_user_banned`, но WC показывает свой
     * generic «Неверный логин или пароль» (защита от перебора). Здесь
     * проверяем коды ошибок и подставляем читаемое сообщение, если бан.
     *
     * @param WP_Error $errors
     * @return WP_Error
     */
    public static function override_wc_login_error( $errors ) {
        if ( ! ( $errors instanceof WP_Error ) ) {
            return $errors;
        }

        $codes = $errors->get_error_codes();
        if ( empty( $codes ) || ! in_array( 'cashback_user_banned', $codes, true ) ) {
            return $errors;
        }

        // Берём первое сообщение от нашего кода (set'нутого block_banned_login).
        $messages = $errors->get_error_messages( 'cashback_user_banned' );
        if ( empty( $messages ) ) {
            return $errors;
        }

        // Возвращаем WP_Error с ТОЛЬКО нашим сообщением — WC отрисует его
        // на странице логина без подмеса generic-текста.
        return new WP_Error( 'cashback_user_banned', $messages[0] );
    }
}
