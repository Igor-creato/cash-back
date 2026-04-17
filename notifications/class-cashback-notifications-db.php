<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Notifications DB — таблица предпочтений уведомлений пользователей
 */
class Cashback_Notifications_DB {

    /**
     * Создание таблицы предпочтений уведомлений
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $prefix          = $wpdb->prefix;

        $sql_prefs = "CREATE TABLE IF NOT EXISTS `{$prefix}cashback_notification_preferences` (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            notification_type VARCHAR(50) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_type (user_id, notification_type),
            KEY idx_user_id (user_id)
        ) {$charset_collate};";

        // Очередь уведомлений — заполняется MySQL триггерами при INSERT/UPDATE транзакций
        // Обрабатывается WP Cron (process_queue)
        $sql_queue = "CREATE TABLE IF NOT EXISTS `{$prefix}cashback_notification_queue` (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(50) NOT NULL,
            transaction_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            old_status VARCHAR(50) DEFAULT NULL,
            new_status VARCHAR(50) DEFAULT NULL,
            extra_data TEXT DEFAULT NULL,
            processed TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_unprocessed (processed, created_at),
            KEY idx_transaction (transaction_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_prefs);
        dbDelta($sql_queue);
    }

    /**
     * Проверить, включено ли уведомление для пользователя
     *
     * @param int    $user_id           ID пользователя
     * @param string $notification_type Тип уведомления
     * @return bool true если включено (по умолчанию — включено)
     */
    public static function is_enabled( int $user_id, string $notification_type ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_notification_preferences';

        $enabled = $wpdb->get_var( $wpdb->prepare(
            'SELECT enabled FROM %i WHERE user_id = %d AND notification_type = %s',
            $table,
            $user_id,
            $notification_type
        ) );

        // Если записи нет — уведомление включено по умолчанию
        if ($enabled === null) {
            return true;
        }

        return (bool) (int) $enabled;
    }

    /**
     * Получить все предпочтения пользователя
     *
     * @param int $user_id ID пользователя
     * @return array<string, bool> [notification_type => enabled]
     */
    public static function get_user_preferences( int $user_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_notification_preferences';

        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT notification_type, enabled FROM %i WHERE user_id = %d',
            $table,
            $user_id
        ), ARRAY_A );

        $prefs = array();
        if ($rows) {
            foreach ($rows as $row) {
                $prefs[ $row['notification_type'] ] = (bool) (int) $row['enabled'];
            }
        }

        return $prefs;
    }

    /**
     * Сохранить предпочтение пользователя
     *
     * @param int    $user_id           ID пользователя
     * @param string $notification_type Тип уведомления
     * @param bool   $enabled           Включено/выключено
     */
    public static function set_preference( int $user_id, string $notification_type, bool $enabled ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_notification_preferences';

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO %i (user_id, notification_type, enabled)
             VALUES (%d, %s, %d)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)",
            $table,
            $user_id,
            $notification_type,
            $enabled ? 1 : 0
        ) );
    }

    /**
     * Массовое сохранение предпочтений пользователя
     *
     * @param int                $user_id ID пользователя
     * @param array<string,bool> $prefs   [notification_type => enabled]
     */
    public static function save_preferences( int $user_id, array $prefs ): void {
        foreach ($prefs as $type => $enabled) {
            self::set_preference($user_id, $type, $enabled);
        }
    }

    /**
     * Список доступных типов уведомлений для пользователя
     *
     * @return array<string, string> [slug => label]
     */
    public static function get_user_notification_types(): array {
        return array(
            'transaction_new'      => __('Новая транзакция (покупка через партнёра)', 'cashback-plugin'),
            'transaction_status'   => __('Изменение статуса транзакции', 'cashback-plugin'),
            'cashback_credited'    => __('Начисление кэшбэка на баланс', 'cashback-plugin'),
            'ticket_reply'         => __('Ответ на тикет поддержки', 'cashback-plugin'),
            'claim_created'        => __('Заявка на неначисленный кэшбэк', 'cashback-plugin'),
            'claim_status'         => __('Изменение статуса заявки', 'cashback-plugin'),
            'affiliate_referral'   => __('Регистрация нового реферала', 'cashback-plugin'),
            'affiliate_commission' => __('Начисление партнёрского вознаграждения', 'cashback-plugin'),
        );
    }

    /**
     * Список типов уведомлений для администратора (глобальное вкл/выкл)
     *
     * @return array<string, string> [slug => label]
     */
    public static function get_all_notification_types(): array {
        return array_merge(
            self::get_user_notification_types(),
            array(
                'user_registered'    => __('Регистрация нового пользователя', 'cashback-plugin'),
                'ticket_admin_alert' => __('Уведомления администратору о тикетах', 'cashback-plugin'),
                'claim_admin_alert'  => __('Уведомления администратору о заявках', 'cashback-plugin'),
            )
        );
    }

    /**
     * Проверить, включён ли тип уведомления глобально (админ-настройка)
     *
     * @param string $notification_type Тип уведомления
     * @return bool
     */
    public static function is_globally_enabled( string $notification_type ): bool {
        $val = get_option('cashback_notify_' . $notification_type, '');

        // Если опция не существует (пустая строка при default='') — считаем включённой
        if ($val === '') {
            return true;
        }

        return (bool) (int) $val;
    }
}
