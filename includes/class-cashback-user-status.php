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
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$table} WHERE user_id = %d",
            $user_id
        ));

        return $status === 'banned';
    }

    /**
     * Получает информацию о бане пользователя
     *
     * @param int $user_id ID пользователя
     * @return array|null Массив с полями ['banned_at' => datetime, 'ban_reason' => string] или null
     */
    public static function get_ban_info( int $user_id ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_user_profile';
        $info  = $wpdb->get_row($wpdb->prepare(
            "SELECT banned_at, ban_reason FROM {$table}
             WHERE user_id = %d AND status = 'banned'",
            $user_id
        ), ARRAY_A);

        return $info ?: null;
    }

    /**
     * Генерирует сообщение для забаненного пользователя
     *
     * @param array|null $ban_info Информация о бане из get_ban_info()
     * @return string Сообщение для пользователя
     */
    public static function get_banned_message( ?array $ban_info = null ): string {
        if ($ban_info && !empty($ban_info['ban_reason'])) {
            return sprintf(
                __('Ваш аккаунт заблокирован. Причина: %s. Для разблокировки обратитесь к администратору.', 'cashback-plugin'),
                esc_html($ban_info['ban_reason'])
            );
        }

        return __('Ваш аккаунт заблокирован. Для разблокировки обратитесь к администратору.', 'cashback-plugin');
    }
}
