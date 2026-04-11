<?php
/**
 * Affiliate Module — Database & Settings.
 *
 * Создаёт 4 таблицы, управляет настройками модуля.
 * Паттерн: статические методы (как support-db.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Affiliate_DB
{
    /* ───────── Настройки модуля ───────── */

    /**
     * Включён ли модуль партнёрской программы.
     */
    public static function is_module_enabled(): bool
    {
        return (bool) get_option('cashback_affiliate_module_enabled', 0);
    }

    public static function set_module_enabled(bool $enabled): void
    {
        update_option('cashback_affiliate_module_enabled', $enabled ? 1 : 0);
    }

    /**
     * Глобальная ставка комиссии (% от кешбэка).
     */
    public static function get_global_rate(): string
    {
        return (string) get_option('cashback_affiliate_global_rate', '10.00');
    }

    public static function set_global_rate(string $rate): void
    {
        $rate = max(0, min(100, (float) $rate));
        update_option('cashback_affiliate_global_rate', number_format($rate, 2, '.', ''));
    }

    /**
     * Срок жизни cookie (дни).
     */
    public static function get_cookie_ttl_days(): int
    {
        return (int) get_option('cashback_affiliate_cookie_ttl', 30);
    }

    public static function set_cookie_ttl_days(int $days): void
    {
        update_option('cashback_affiliate_cookie_ttl', max(1, min(365, $days)));
    }

    /**
     * Включена ли антифрод-проверка при привязке рефералов.
     */
    public static function is_antifraud_enabled(): bool
    {
        return (bool) get_option('cashback_affiliate_antifraud_enabled', 1);
    }

    public static function set_antifraud_enabled(bool $enabled): void
    {
        update_option('cashback_affiliate_antifraud_enabled', $enabled ? 1 : 0);
    }

    /**
     * URL страницы правил партнёрской программы.
     */
    public static function get_rules_page_url(): string
    {
        return (string) get_option('cashback_affiliate_rules_url', '');
    }

    public static function set_rules_page_url(string $url): void
    {
        update_option('cashback_affiliate_rules_url', esc_url_raw($url));
    }

    /* ───────── Reference ID ───────── */

    /**
     * Генерация уникального ID начисления: AF-XXXXXXXX
     */
    public static function generate_affiliate_reference_id(): string
    {
        $charset     = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $charset_len = 31;
        $id_length   = 8;

        $random_bytes = random_bytes($id_length);
        $result       = 'AF-';

        for ($i = 0; $i < $id_length; $i++) {
            $result .= $charset[ord($random_bytes[$i]) % $charset_len];
        }

        return $result;
    }

    /* ───────── Создание таблиц ───────── */

    /**
     * Создаёт 4 таблицы модуля.
     * Вызывается из CashbackPlugin::activate().
     */
    public static function create_tables(): void
    {
        global $wpdb;
        $prefix          = $wpdb->prefix;
        $charset_collate = $wpdb->get_charset_collate();

        // 1. Профили участников партнёрской программы
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$prefix}cashback_affiliate_profiles` (
                `user_id` bigint(20) unsigned NOT NULL COMMENT 'WP user ID',
                `referred_by_user_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Реферер (immutable после установки)',
                `referral_click_id` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'Click ID при регистрации',
                `affiliate_rate` decimal(5,2) DEFAULT NULL COMMENT 'Индивидуальная ставка (NULL = глобальная)',
                `affiliate_status` enum('active','disabled') NOT NULL DEFAULT 'active' COMMENT 'Статус в партнёрской программе',
                `affiliate_frozen_amount` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Замороженная сумма при отключении',
                `referred_at` datetime DEFAULT NULL COMMENT 'Дата привязки реферала',
                `disabled_at` datetime DEFAULT NULL COMMENT 'Дата отключения от партнёрки',
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`),
                KEY `idx_referred_by` (`referred_by_user_id`),
                KEY `idx_affiliate_status` (`affiliate_status`),
                KEY `idx_referral_click_id` (`referral_click_id`)
            ) ENGINE=InnoDB {$charset_collate} COMMENT='Профили участников партнёрской программы';"
        );

        // 2. Клики по реферальным ссылкам
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$prefix}cashback_affiliate_clicks` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `click_id` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'UUIDv7 (32 hex)',
                `referrer_id` bigint(20) unsigned NOT NULL COMMENT 'Кто поделился ссылкой',
                `ip_address` varchar(45) NOT NULL COMMENT 'IP посетителя',
                `user_agent` text DEFAULT NULL,
                `referer_url` text DEFAULT NULL COMMENT 'HTTP referer',
                `landing_url` text DEFAULT NULL COMMENT 'Страница перехода',
                `registered_user_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ID зарегистрированного пользователя',
                `registered_at` datetime DEFAULT NULL COMMENT 'Дата регистрации посетителя',
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_click_id` (`click_id`),
                KEY `idx_referrer_id` (`referrer_id`),
                KEY `idx_registered_user` (`registered_user_id`),
                KEY `idx_created_at` (`created_at`),
                KEY `idx_ip_address` (`ip_address`)
            ) ENGINE=InnoDB {$charset_collate} COMMENT='Клики по реферальным ссылкам';"
        );

        // 3. Начисления партнёрских комиссий
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$prefix}cashback_affiliate_accruals` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `reference_id` varchar(16) NOT NULL COMMENT 'AF-XXXXXXXX',
                `referrer_id` bigint(20) unsigned NOT NULL COMMENT 'Кому начислена комиссия',
                `referred_user_id` bigint(20) unsigned NOT NULL COMMENT 'Чья покупка',
                `transaction_id` bigint(20) unsigned NOT NULL COMMENT 'Исходная транзакция кешбэка',
                `cashback_amount` decimal(18,2) NOT NULL COMMENT 'Сумма кешбэка (основание для расчёта)',
                `commission_rate` decimal(5,2) NOT NULL COMMENT 'Применённая ставка (%)',
                `commission_amount` decimal(18,2) NOT NULL COMMENT 'Сумма комиссии',
                `status` enum('pending','available','frozen','paid','declined') NOT NULL DEFAULT 'pending',
                `idempotency_key` varchar(64) NOT NULL COMMENT 'aff_accrual_{transaction_id}',
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_idempotency_key` (`idempotency_key`),
                UNIQUE KEY `uk_reference_id` (`reference_id`),
                KEY `idx_referrer_id` (`referrer_id`),
                KEY `idx_referred_user_id` (`referred_user_id`),
                KEY `idx_transaction_id` (`transaction_id`),
                KEY `idx_status` (`status`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB {$charset_collate} COMMENT='Начисления партнёрских комиссий';"
        );

        // Таблица cashback_affiliate_ledger удалена — affiliate операции хранятся
        // в едином cashback_balance_ledger (типы: affiliate_accrual, affiliate_freeze, affiliate_unfreeze).

        // Фаза 2: FK constraints (ошибки подавляются — constraint может уже существовать)
        $suppress = $wpdb->suppress_errors(true);

        $constraints = [
            "ALTER TABLE `{$prefix}cashback_affiliate_profiles`
                ADD CONSTRAINT `fk_aff_profile_user` FOREIGN KEY (`user_id`)
                REFERENCES `{$prefix}users` (`ID`) ON DELETE CASCADE",
            "ALTER TABLE `{$prefix}cashback_affiliate_profiles`
                ADD CONSTRAINT `fk_aff_profile_referrer` FOREIGN KEY (`referred_by_user_id`)
                REFERENCES `{$prefix}users` (`ID`) ON DELETE SET NULL",

            "ALTER TABLE `{$prefix}cashback_affiliate_clicks`
                ADD CONSTRAINT `fk_aff_click_referrer` FOREIGN KEY (`referrer_id`)
                REFERENCES `{$prefix}users` (`ID`) ON DELETE CASCADE",

            "ALTER TABLE `{$prefix}cashback_affiliate_accruals`
                ADD CONSTRAINT `fk_aff_accrual_referrer` FOREIGN KEY (`referrer_id`)
                REFERENCES `{$prefix}users` (`ID`) ON DELETE RESTRICT",
            "ALTER TABLE `{$prefix}cashback_affiliate_accruals`
                ADD CONSTRAINT `fk_aff_accrual_referred` FOREIGN KEY (`referred_user_id`)
                REFERENCES `{$prefix}users` (`ID`) ON DELETE RESTRICT",
            "ALTER TABLE `{$prefix}cashback_affiliate_accruals`
                ADD CONSTRAINT `fk_aff_accrual_tx` FOREIGN KEY (`transaction_id`)
                REFERENCES `{$prefix}cashback_transactions` (`id`) ON DELETE RESTRICT",

            // CHECK constraints
            "ALTER TABLE `{$prefix}cashback_affiliate_profiles`
                ADD CONSTRAINT `chk_aff_frozen_amount` CHECK (`affiliate_frozen_amount` >= 0)",
            "ALTER TABLE `{$prefix}cashback_affiliate_profiles`
                ADD CONSTRAINT `chk_aff_rate_range` CHECK (`affiliate_rate` IS NULL OR `affiliate_rate` BETWEEN 0.00 AND 100.00)",
            "ALTER TABLE `{$prefix}cashback_affiliate_accruals`
                ADD CONSTRAINT `chk_aff_commission_rate` CHECK (`commission_rate` BETWEEN 0.00 AND 100.00)",
            "ALTER TABLE `{$prefix}cashback_affiliate_accruals`
                ADD CONSTRAINT `chk_aff_commission_amount` CHECK (`commission_amount` >= 0)",
        ];

        foreach ($constraints as $sql) {
            $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $wpdb->suppress_errors($suppress);
    }

    /**
     * Миграция: добавление статусов pending/declined в ENUM.
     * Безопасна для повторного запуска.
     */
    public static function migrate_accruals_pending_statuses(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_affiliate_accruals';

        // Проверяем текущий ENUM — если pending уже есть, пропускаем
        $col = $wpdb->get_row($wpdb->prepare(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'status'",
            DB_NAME,
            $table
        ));

        if ($col && strpos($col->COLUMN_TYPE, "'pending'") !== false) {
            return; // уже мигрировано
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "ALTER TABLE `{$table}`
             MODIFY COLUMN `status` ENUM('pending','available','frozen','paid','declined') NOT NULL DEFAULT 'pending'"
        );
    }

    /**
     * Инициализация affiliate-профиля для нового пользователя.
     * Вызывается при регистрации (user_register hook).
     */
    public static function ensure_profile(int $user_id): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_affiliate_profiles';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM `{$table}` WHERE user_id = %d LIMIT 1",
            $user_id
        ));

        if (!$exists) {
            $wpdb->insert(
                $table,
                ['user_id' => $user_id],
                ['%d']
            );
        }
    }
}
