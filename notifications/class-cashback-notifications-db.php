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
     * Неожиданные migration-ошибки, накопленные в рамках request.
     *
     * @var array<int,array{migration:string,error:string}>
     */
    private static array $migration_errors = array();

    /**
     * Регистрация admin_notices выполняется один раз на request.
     */
    private static bool $admin_notice_registered = false;

    /**
     * Классификатор DDL-ошибок MySQL/MariaDB (паттерн из 12f, Claims_DB).
     * true для идемпотентно-ожидаемых состояний.
     */
    private static function is_known_ddl_error( string $error ): bool {
        if ($error === '') {
            return true;
        }

        $known_markers = array(
            'Duplicate',         // 1060/1061/FK duplicate
            'check constraint',
            'Check constraint',
            'already exists',
        );

        foreach ($known_markers as $marker) {
            if (stripos($error, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Эскалация непредвиденной migration-ошибки: error_log + admin_notices (one-shot).
     */
    private static function report_migration_error( string $migration, string $error ): void {
        self::$migration_errors[] = array(
            'migration' => $migration,
            'error'     => $error,
        );

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging: post-mortem grep [Notifications] Migration error.
        error_log('[Notifications] Migration error in ' . $migration . ': ' . $error);

        if (!self::$admin_notice_registered && function_exists('add_action')) {
            self::$admin_notice_registered = true;
            add_action('admin_notices', array( self::class, 'render_migration_errors_notice' ));
        }
    }

    /**
     * Admin-notice: только имена миграций (information-disclosure guard).
     */
    public static function render_migration_errors_notice(): void {
        if (empty(self::$migration_errors)) {
            return;
        }
        if (!current_user_can('activate_plugins')) {
            return;
        }

        $migrations = array_values(array_unique(array_map(
            static fn( array $e ): string => $e['migration'],
            self::$migration_errors
        )));
        $list       = implode(', ', $migrations);

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
            esc_html__('Cashback Notifications:', 'cashback-plugin'),
            esc_html(sprintf(
                /* translators: %s is a comma-separated list of migration names */
                __('обнаружены ошибки миграций: %s. Детали — в error_log (grep [Notifications] Migration error).', 'cashback-plugin'),
                $list
            ))
        );
    }

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

        // Кампании массовых email-рассылок (создаются администратором).
        $sql_broadcast_campaigns = "CREATE TABLE IF NOT EXISTS `{$prefix}cashback_broadcast_campaigns` (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_uuid CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body_html LONGTEXT NOT NULL,
            audience_filters TEXT DEFAULT NULL,
            total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
            sent_count INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_uuid (campaign_uuid),
            KEY idx_status_created (status, created_at)
        ) {$charset_collate};";

        // Очередь получателей кампании.
        // 12g ADR (F-23-004/005): processing_token per-batch ownership token для
        // защиты от collision параллельных воркеров; last_error TEXT вместо
        // error VARCHAR(255) — не обрезает длинные SMTP stack traces.
        $sql_broadcast_queue = "CREATE TABLE IF NOT EXISTS `{$prefix}cashback_broadcast_queue` (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(190) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            error VARCHAR(255) DEFAULT NULL,
            last_error TEXT DEFAULT NULL,
            processing_token CHAR(36) DEFAULT NULL,
            processed_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_campaign_user (campaign_id, user_id),
            KEY idx_campaign_status (campaign_id, status),
            KEY idx_processing_token (processing_token)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_prefs);
        dbDelta($sql_queue);
        dbDelta($sql_broadcast_campaigns);
        dbDelta($sql_broadcast_queue);
    }

    /**
     * Миграция для существующих установок: добавляет processing_token / last_error /
     * idx_processing_token. Closes F-23-004 / F-23-005. Pattern — как в Claims_DB
     * (SHOW COLUMNS guard + явная эскалация unexpected-ошибок через report_migration_error).
     */
    public static function migrate_add_processing_token_and_last_error(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_broadcast_queue';

        // Таблицы может не быть на свежей инсталляции, где create_tables() ещё не прошёл
        // (redundant call из bootstrap — ничего не делаем).
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ($exists !== $table) {
            return;
        }

        $col_token = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'processing_token' ) );
        if (empty($col_token)) {
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN `processing_token` CHAR(36) DEFAULT NULL AFTER `error`', $table ) );
            if ($wpdb->last_error && !self::is_known_ddl_error($wpdb->last_error)) {
                self::report_migration_error('broadcast_queue:add_processing_token', $wpdb->last_error);
            }

            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY `idx_processing_token` (`processing_token`)', $table ) );
            if ($wpdb->last_error && !self::is_known_ddl_error($wpdb->last_error)) {
                self::report_migration_error('broadcast_queue:add_processing_token_key', $wpdb->last_error);
            }
        }

        $col_last_error = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'last_error' ) );
        if (empty($col_last_error)) {
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN `last_error` TEXT DEFAULT NULL AFTER `error`', $table ) );
            if ($wpdb->last_error && !self::is_known_ddl_error($wpdb->last_error)) {
                self::report_migration_error('broadcast_queue:add_last_error', $wpdb->last_error);
            }
        }
    }

    /**
     * Проверить, включено ли уведомление для пользователя
     *
     * @param int    $user_id           ID пользователя
     * @param string $notification_type Тип уведомления
     * @return bool true если включено (по умолчанию — включено)
     */
    public static function is_enabled( int $user_id, string $notification_type ): bool {
        // Критичные для авторизации уведомления нельзя отключить — всегда включены.
        if (in_array($notification_type, self::get_required_notification_types(), true)) {
            return true;
        }

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
            'INSERT INTO %i (user_id, notification_type, enabled)
             VALUES (%d, %s, %d)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)',
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
        // iter-25 F-25-002: all-or-nothing сохранение набора предпочтений.
        // Без транзакции частичный сбой оставлял бы смешанное состояние флагов.
        global $wpdb;

        $wpdb->query('START TRANSACTION');
        $table = $wpdb->prefix . 'cashback_notification_preferences';

        foreach ($prefs as $type => $enabled) {
            $result = $wpdb->query( $wpdb->prepare(
                'INSERT INTO %i (user_id, notification_type, enabled)
                 VALUES (%d, %s, %d)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)',
                $table,
                $user_id,
                $type,
                $enabled ? 1 : 0
            ) );

            if ($result === false) {
                $wpdb->query('ROLLBACK');
                return;
            }
        }

        $wpdb->query('COMMIT');
    }

    /**
     * Список доступных типов уведомлений для пользователя
     *
     * @return array<string, string> [slug => label]
     */
    public static function get_user_notification_types(): array {
        return array(
            'transaction_new'         => __('Новая транзакция (покупка через партнёра)', 'cashback-plugin'),
            'transaction_status'      => __('Изменение статуса транзакции', 'cashback-plugin'),
            'cashback_credited'       => __('Начисление кэшбэка на баланс', 'cashback-plugin'),
            'ticket_reply'            => __('Ответ на тикет поддержки', 'cashback-plugin'),
            'claim_created'           => __('Заявка на неначисленный кэшбэк', 'cashback-plugin'),
            'claim_status'            => __('Изменение статуса заявки', 'cashback-plugin'),
            'affiliate_referral'      => __('Регистрация нового реферала', 'cashback-plugin'),
            'affiliate_commission'    => __('Начисление партнёрского вознаграждения', 'cashback-plugin'),
            'payout_refunded'         => __('Отмена заявки на выплату и возврат средств на баланс', 'cashback-plugin'),
            'broadcast'               => __('Массовые рассылки от администрации', 'cashback-plugin'),
            'social_confirm_link'     => __('Подтверждение привязки соцсети (обязательно)', 'cashback-plugin'),
            'social_verify_email'     => __('Подтверждение email при регистрации через соцсеть (обязательно)', 'cashback-plugin'),
            'social_account_linked'   => __('Соцсеть привязана к аккаунту', 'cashback-plugin'),
            'social_account_unlinked' => __('Соцсеть отвязана от аккаунта', 'cashback-plugin'),
        );
    }

    /**
     * Slug-и уведомлений, которые нельзя отключить в ЛК (критичные для flow авторизации).
     *
     * Используется `Cashback_Notifications_Frontend` для рендеринга чекбоксов как disabled+checked.
     *
     * @return array<int, string>
     */
    public static function get_required_notification_types(): array {
        return array( 'social_confirm_link', 'social_verify_email' );
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
                'user_registered'         => __('Регистрация нового пользователя', 'cashback-plugin'),
                'ticket_admin_alert'      => __('Уведомления администратору о тикетах', 'cashback-plugin'),
                'claim_admin_alert'       => __('Уведомления администратору о заявках', 'cashback-plugin'),
                'fraud_admin_digest'      => __('Фрод-дайджест администратору', 'cashback-plugin'),
                'health_check_report'     => __('Отчёт health-check (диагностика)', 'cashback-plugin'),
                'api_sync_report'         => __('Отчёт о синхронизации CPA-кампаний', 'cashback-plugin'),
                'contact_form_submission' => __('Сообщения из формы обратной связи', 'cashback-plugin'),
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
        // Критичные для авторизации уведомления нельзя отключить глобально — иначе
        // молча ломается double opt-in соцлогина (ветка B / D), а админ-UI об этом
        // никак не сигнализирует. Симметрично с is_enabled().
        if (in_array($notification_type, self::get_required_notification_types(), true)) {
            return true;
        }

        // phpcs:ignore WordPressVIPMinimum.Performance.TaxonomyMetaInOptions.PossibleTermMetaInOptions -- Plugin option key for notification-type toggle; not taxonomy term meta.
        $val = get_option('cashback_notify_' . $notification_type, '');

        // Если опция не существует (пустая строка при default='') — считаем включённой
        if ($val === '') {
            return true;
        }

        return (bool) (int) $val;
    }
}
