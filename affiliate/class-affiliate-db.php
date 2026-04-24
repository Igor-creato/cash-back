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

class Cashback_Affiliate_DB {

    /* ───────── Настройки модуля ───────── */

    /**
     * Включён ли модуль партнёрской программы.
     */
    public static function is_module_enabled(): bool {
        return (bool) get_option('cashback_affiliate_module_enabled', 0);
    }

    public static function set_module_enabled( bool $enabled ): void {
        update_option('cashback_affiliate_module_enabled', $enabled ? 1 : 0);
    }

    /**
     * Глобальная ставка комиссии (% от кешбэка).
     */
    public static function get_global_rate(): string {
        return (string) get_option('cashback_affiliate_global_rate', '10.00');
    }

    public static function set_global_rate( string $rate ): void {
        $rate = max(0, min(100, (float) $rate));
        update_option('cashback_affiliate_global_rate', number_format($rate, 2, '.', ''));
    }

    /**
     * Срок жизни cookie (дни).
     */
    public static function get_cookie_ttl_days(): int {
        return (int) get_option('cashback_affiliate_cookie_ttl', 30);
    }

    public static function set_cookie_ttl_days( int $days ): void {
        update_option('cashback_affiliate_cookie_ttl', max(1, min(365, $days)));
    }

    /**
     * Включена ли антифрод-проверка при привязке рефералов.
     */
    public static function is_antifraud_enabled(): bool {
        return (bool) get_option('cashback_affiliate_antifraud_enabled', 1);
    }

    public static function set_antifraud_enabled( bool $enabled ): void {
        update_option('cashback_affiliate_antifraud_enabled', $enabled ? 1 : 0);
    }

    /**
     * URL страницы правил партнёрской программы.
     */
    public static function get_rules_page_url(): string {
        return (string) get_option('cashback_affiliate_rules_url', '');
    }

    public static function set_rules_page_url( string $url ): void {
        update_option('cashback_affiliate_rules_url', esc_url_raw($url));
    }

    /* ───────── Reference ID ───────── */

    /**
     * Генерация уникального ID начисления: AF-XXXXXXXX
     */
    public static function generate_affiliate_reference_id(): string {
        $charset     = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $charset_len = 31;
        $id_length   = 8;

        $random_bytes = random_bytes($id_length);
        $result       = 'AF-';

        for ($i = 0; $i < $id_length; $i++) {
            $result .= $charset[ ord($random_bytes[ $i ]) % $charset_len ];
        }

        return $result;
    }

    /* ───────── Migration error plumbing (F-20-001 pattern) ───────── */

    /** @var array<int, array{migration: string, error: string}> */
    private static array $migration_errors = array();

    /** @var bool */
    private static bool $admin_notice_registered = false;

    /**
     * Классификатор DDL-ошибок MySQL/MariaDB (F-20-001 pattern, shared with claims-db).
     *
     * true для идемпотентно-ожидаемых состояний: пустая строка (нет ошибки),
     * "Duplicate*" (1060/1061/FK duplicate), "check constraint" (3822), "already exists".
     */
    public static function is_known_ddl_error( string $error ): bool {
        if ($error === '') {
            return true;
        }

        $known_markers = array(
            'Duplicate',
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
     * Эскалировать непредвиденную migration-ошибку: error_log + admin_notices hook.
     */
    private static function report_migration_error( string $migration, string $error ): void {
        self::$migration_errors[] = array(
            'migration' => $migration,
            'error'     => $error,
        );

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic: grep '[Affiliate] Migration error'.
        error_log('[Affiliate] Migration error in ' . $migration . ': ' . $error);

        if (!self::$admin_notice_registered && function_exists('add_action')) {
            self::$admin_notice_registered = true;
            add_action('admin_notices', array( self::class, 'render_migration_errors_notice' ));
        }
    }

    /**
     * Admin-notice со списком имён проблемных миграций (raw SQL в UI не попадает).
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
            esc_html__('Cashback Affiliate:', 'cashback-plugin'),
            esc_html(sprintf(
                /* translators: %s is a comma-separated list of migration names */
                __('обнаружены ошибки миграций: %s. Детали — в error_log (grep [Affiliate] Migration error).', 'cashback-plugin'),
                $list
            ))
        );
    }

    /* ───────── Создание таблиц ───────── */

    /**
     * Создаёт 4 таблицы модуля.
     * Вызывается из CashbackPlugin::activate().
     */
    public static function create_tables(): void {
        global $wpdb;
        $prefix          = $wpdb->prefix;
        $charset_collate = $wpdb->get_charset_collate();

        // 1. Профили участников партнёрской программы
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL: $prefix/$charset_collate из $wpdb, без user input.
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
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // 2. Клики по реферальным ссылкам
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL: $prefix/$charset_collate из $wpdb, без user input.
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
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // 3. Начисления партнёрских комиссий
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL: $prefix/$charset_collate из $wpdb, без user input.
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
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Таблица cashback_affiliate_ledger удалена — affiliate операции хранятся
        // в едином cashback_balance_ledger (типы: affiliate_accrual, affiliate_freeze, affiliate_unfreeze).

        // 4. Audit-лог affiliate-модуля (F-22-003 — Группа 12).
        // DDL вынесен в ensure_audit_table() чтобы его можно было идемпотентно
        // вызвать и из миграционного пути (для инсталляций, обновлённых без activation).
        self::ensure_audit_table();

        // Фаза 2: FK constraints (ошибки подавляются — constraint может уже существовать)
        $suppress = $wpdb->suppress_errors(true);

        $constraints = array(
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
        );

        foreach ($constraints as $sql) {
            $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $wpdb->suppress_errors($suppress);
    }

    /**
     * Миграция: добавление статусов pending/declined в ENUM.
     * Безопасна для повторного запуска.
     */
    public static function migrate_accruals_pending_statuses(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_affiliate_accruals';

        // Проверяем текущий ENUM — если pending уже есть, пропускаем
        $col = $wpdb->get_row($wpdb->prepare(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'status'",
            DB_NAME,
            $table
        ));

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- External INFORMATION_SCHEMA column name.
        if ($col && strpos($col->COLUMN_TYPE, "'pending'") !== false) {
            return; // уже мигрировано
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table из $wpdb->prefix, ALTER TABLE — статичный DDL.
        $wpdb->query(
            "ALTER TABLE `{$table}`
             MODIFY COLUMN `status` ENUM('pending','available','frozen','paid','declined') NOT NULL DEFAULT 'pending'"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * F-22-003 — audit-таблица. Идемпотентное CREATE TABLE IF NOT EXISTS.
     * Вызывается из create_tables() (activation) и из migrate_f22_003_*
     * (runtime init для обновлённых инсталляций).
     */
    public static function ensure_audit_table(): void {
        global $wpdb;
        $prefix          = $wpdb->prefix;
        $charset_collate = $wpdb->get_charset_collate();

        // Типизированные поля (не generic JSON) — быстрый query по event_type/target/referrer
        // без JSON_EXTRACT. LONGTEXT для signals/payload (MariaDB JSON = alias LONGTEXT,
        // валидация на уровне PHP). Без UNIQUE на (event_type,target,click_id) —
        // retry/admin actions могут легитимно повторяться.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL: $prefix/$charset_collate из $wpdb.
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$prefix}cashback_affiliate_audit` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `event_type` varchar(64) NOT NULL,
                `actor_user_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Админ при manual_*',
                `target_user_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Invited user',
                `referrer_id` bigint(20) unsigned DEFAULT NULL,
                `rejected_referrer_id` bigint(20) unsigned DEFAULT NULL COMMENT 'NAT collision: отвергнутый кандидат',
                `kept_referrer_id` bigint(20) unsigned DEFAULT NULL COMMENT 'NAT collision: сохранённый первый',
                `click_id` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `partner_token_hash` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `ip_hash` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `ip_subnet_hash` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `ua_hash` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `key_hash` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `confidence` enum('high','medium','low') DEFAULT NULL,
                `reason` varchar(64) DEFAULT NULL,
                `signals` LONGTEXT DEFAULT NULL COMMENT 'JSON array, валидируется в PHP',
                `payload` LONGTEXT DEFAULT NULL COMMENT 'JSON object, валидируется в PHP',
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_event_type` (`event_type`, `created_at`),
                KEY `idx_target_user` (`target_user_id`, `created_at`),
                KEY `idx_referrer` (`referrer_id`, `created_at`)
            ) ENGINE=InnoDB {$charset_collate} COMMENT='Affiliate audit log — F-22-003';"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->last_error && !self::is_known_ddl_error($wpdb->last_error)) {
            self::report_migration_error('ensure_audit_table', $wpdb->last_error);
        }
    }

    /**
     * F-22-003 — Referral Attribution Hardening.
     *
     * Добавляет:
     *   • attribution_source / attribution_confidence / collision_detected
     *   • review_status / referral_reward_eligible / antifraud_signals (LONGTEXT)
     *     в cashback_affiliate_profiles.
     *   • Snapshot-колонки (attribution_source / attribution_confidence /
     *     collision_detected / review_status_at_creation / antifraud_signals)
     *     в cashback_affiliate_accruals — чтобы retro-изменения профиля
     *     не меняли смысл старых выплат.
     *   • idx_review_queue для admin-очереди manual review.
     *   • Backfill existing bindings → cookie/high/none (они уже в available/paid).
     *
     * Идемпотентна: ALTER проходит через is_known_ddl_error(); повторный запуск
     * не изменяет схему. Bumps cashback_affiliate_db_version до '1.2'.
     */
    public static function migrate_f22_003_attribution_model(): void {
        global $wpdb;
        $prefix           = $wpdb->prefix;
        $profiles_table   = $prefix . 'cashback_affiliate_profiles';
        $accruals_table   = $prefix . 'cashback_affiliate_accruals';

        // Audit-таблица создаётся ДО fast-path: install'ы, где db_version
        // уже 1.2 (миграция прогналась без create_tables), могли остаться
        // без audit-таблицы. CREATE TABLE IF NOT EXISTS — дешёвый noop.
        self::ensure_audit_table();

        // Fast-path: если db_version уже ≥ 1.2 — пропускаем все DDL профилей/accruals.
        $current_version = (string) get_option('cashback_affiliate_db_version', '1.0');
        if (version_compare($current_version, '1.2', '>=')) {
            return;
        }

        $profile_alters = array(
            'attribution_source'       => "ALTER TABLE `{$profiles_table}` ADD COLUMN `attribution_source` ENUM('cookie','transient','signed_token') NULL DEFAULT NULL AFTER `referral_click_id`",
            'attribution_confidence'   => "ALTER TABLE `{$profiles_table}` ADD COLUMN `attribution_confidence` ENUM('high','medium','low') NULL DEFAULT NULL AFTER `attribution_source`",
            'collision_detected'       => "ALTER TABLE `{$profiles_table}` ADD COLUMN `collision_detected` TINYINT(1) NOT NULL DEFAULT 0 AFTER `attribution_confidence`",
            'review_status'            => "ALTER TABLE `{$profiles_table}` ADD COLUMN `review_status` ENUM('none','pending','manual_approved','manual_rejected','auto_approved') NOT NULL DEFAULT 'none' AFTER `collision_detected`",
            'referral_reward_eligible' => "ALTER TABLE `{$profiles_table}` ADD COLUMN `referral_reward_eligible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `review_status`",
            'antifraud_signals'        => "ALTER TABLE `{$profiles_table}` ADD COLUMN `antifraud_signals` LONGTEXT NULL AFTER `referral_reward_eligible`",
            'idx_review_queue'         => "ALTER TABLE `{$profiles_table}` ADD INDEX `idx_review_queue` (`review_status`, `attribution_confidence`, `referred_at`)",
        );

        foreach ($profile_alters as $label => $sql) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL with $wpdb->prefix; not user-controlled.
            $wpdb->query($sql);
            if ($wpdb->last_error && !self::is_known_ddl_error($wpdb->last_error)) {
                self::report_migration_error(
                    'migrate_f22_003_attribution_model:profiles:' . $label,
                    $wpdb->last_error
                );
            }
        }

        $accrual_alters = array(
            'attribution_source'        => "ALTER TABLE `{$accruals_table}` ADD COLUMN `attribution_source` ENUM('cookie','transient','signed_token') NULL DEFAULT NULL AFTER `idempotency_key`",
            'attribution_confidence'    => "ALTER TABLE `{$accruals_table}` ADD COLUMN `attribution_confidence` ENUM('high','medium','low') NULL DEFAULT NULL AFTER `attribution_source`",
            'collision_detected'        => "ALTER TABLE `{$accruals_table}` ADD COLUMN `collision_detected` TINYINT(1) NOT NULL DEFAULT 0 AFTER `attribution_confidence`",
            'review_status_at_creation' => "ALTER TABLE `{$accruals_table}` ADD COLUMN `review_status_at_creation` ENUM('none','pending','manual_approved','manual_rejected','auto_approved') NULL DEFAULT NULL AFTER `collision_detected`",
            'antifraud_signals'         => "ALTER TABLE `{$accruals_table}` ADD COLUMN `antifraud_signals` LONGTEXT NULL AFTER `review_status_at_creation`",
        );

        foreach ($accrual_alters as $label => $sql) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static DDL with $wpdb->prefix; not user-controlled.
            $wpdb->query($sql);
            if ($wpdb->last_error && !self::is_known_ddl_error($wpdb->last_error)) {
                self::report_migration_error(
                    'migrate_f22_003_attribution_model:accruals:' . $label,
                    $wpdb->last_error
                );
            }
        }

        // Backfill existing bindings: они уже в available/paid/frozen — безопасно
        // пометить их cookie/high/none (до F-22-003 все привязки приходили
        // только из signed-cookie или IP-transient, оба считаем тогда валидными).
        $wpdb->query($wpdb->prepare(
            "UPDATE %i
             SET attribution_source     = 'cookie',
                 attribution_confidence = 'high',
                 review_status          = 'none'
             WHERE referred_by_user_id IS NOT NULL
               AND attribution_source IS NULL",
            $profiles_table
        ));
        if ($wpdb->last_error) {
            self::report_migration_error(
                'migrate_f22_003_attribution_model:backfill_profiles',
                $wpdb->last_error
            );
        }

        update_option('cashback_affiliate_db_version', '1.2');

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log('[Affiliate] Migration F-22-003: attribution model v1.2 applied');
    }

    /**
     * Инициализация affiliate-профиля для нового пользователя.
     * Вызывается при регистрации (user_register hook).
     */
    public static function ensure_profile( int $user_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_affiliate_profiles';

        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM %i WHERE user_id = %d LIMIT 1',
            $table,
            $user_id
        ));

        if (!$exists) {
            $wpdb->insert(
                $table,
                array( 'user_id' => $user_id ),
                array( '%d' )
            );
        }
    }
}
