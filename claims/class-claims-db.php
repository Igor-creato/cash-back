<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claims Database Manager
 *
 * Creates and manages tables for the Missing Cashback Claim System:
 * - cashback_claims
 * - cashback_claim_events
 */
class Cashback_Claims_DB {

    /**
     * Неожиданные migration-ошибки, накопленные в рамках текущего request.
     * Формат: array<array{migration:string, error:string}>.
     *
     * @var array<int,array{migration:string,error:string}>
     */
    private static array $migration_errors = array();

    /**
     * Регистрация admin_notices выполняется один раз на request.
     */
    private static bool $admin_notice_registered = false;

    /**
     * Классификатор DDL-ошибок MySQL/MariaDB. 12f ADR (F-20-001).
     *
     * true для идемпотентно-ожидаемых состояний: пустая строка (нет ошибки),
     * "Duplicate*" (коды 1060 duplicate column / 1061 duplicate key / FK duplicate),
     * "check constraint"/"Check constraint"/"already exists" (3822/1826).
     *
     * wpdb экспонирует ошибку только строкой — substring-match достаточен
     * для защиты от drift между MySQL/MariaDB формулировками.
     */
    private static function is_known_ddl_error( string $error ): bool {
        if ($error === '') {
            return true;
        }

        $known_markers = array(
            'Duplicate',         // 1060 duplicate column, 1061 duplicate key, duplicate FK constraint name
            'check constraint',  // 3822 duplicate check constraint (lowercase variant)
            'Check constraint',  // capitalised variant
            'already exists',    // 1826 duplicate FK constraint name (alt wording)
        );

        foreach ($known_markers as $marker) {
            if (stripos($error, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Эскалировать непредвиденную migration-ошибку: error_log + admin_notices
     * hook (one-shot). 12f ADR (F-20-001).
     */
    private static function report_migration_error( string $migration, string $error ): void {
        self::$migration_errors[] = array(
            'migration' => $migration,
            'error'     => $error,
        );

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging: post-mortem grep [Claims] Migration error.
        error_log('[Claims] Migration error in ' . $migration . ': ' . $error);

        if (!self::$admin_notice_registered && function_exists('add_action')) {
            self::$admin_notice_registered = true;
            add_action('admin_notices', array( self::class, 'render_migration_errors_notice' ));
        }
    }

    /**
     * Admin-notice callback со списком migration-имен. Raw SQL и текст ошибки
     * в UI не попадают — только имя миграции (information-disclosure guard).
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
            esc_html__('Cashback Claims:', 'cashback-plugin'),
            esc_html(sprintf(
                /* translators: %s is a comma-separated list of migration names */
                __('обнаружены ошибки миграций: %s. Детали — в error_log (grep [Claims] Migration error).', 'cashback-plugin'),
                $list
            ))
        );
    }

    public static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $table_claims = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_claims` (
            `claim_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned NOT NULL,
            `click_id` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'UUID клика из cashback_click_log',
            `merchant_id` int unsigned DEFAULT NULL COMMENT 'ID оффера/магазина (offer_id)',
            `merchant_name` varchar(255) DEFAULT NULL COMMENT 'Название магазина',
            `product_id` bigint(20) unsigned NOT NULL COMMENT 'ID товара WooCommerce',
            `product_name` varchar(255) DEFAULT NULL COMMENT 'Название товара',
            `order_id` varchar(255) NOT NULL COMMENT 'Номер заказа пользователя',
            `order_value` decimal(10,2) NOT NULL COMMENT 'Сумма заказа',
            `order_date` datetime NOT NULL COMMENT 'Дата заказа пользователя',
            `comment` text DEFAULT NULL COMMENT 'Комментарий пользователя',
            `evidence_urls` text DEFAULT NULL COMMENT 'JSON массив URL доказательств',
            `status` enum('draft','submitted','sent_to_network','approved','declined') NOT NULL DEFAULT 'draft',
            `probability_score` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Вероятность одобрения 0-100',
            `scoring_breakdown` text DEFAULT NULL COMMENT 'JSON разложение probability_score по 5 факторам (F-20-002)',
            `is_suspicious` tinyint(1) NOT NULL DEFAULT 0,
            `suspicious_reasons` text DEFAULT NULL COMMENT 'JSON массив причин',
            `ip_address` varchar(45) NOT NULL COMMENT 'IP при создании заявки',
            `user_agent` text DEFAULT NULL COMMENT 'User-Agent при создании',
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`claim_id`),
            UNIQUE KEY `uk_click_user` (`click_id`, `user_id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_status` (`status`),
            KEY `idx_merchant_id` (`merchant_id`),
            KEY `idx_order_id` (`order_id`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_probability` (`probability_score`),
            KEY `idx_suspicious` (`is_suspicious`),
            KEY `idx_user_status` (`user_id`, `status`),
            KEY `idx_user_created` (`user_id`, `created_at` DESC)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Заявки на неначисленный кэшбэк';";

        $table_events = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_claim_events` (
            `event_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `claim_id` bigint(20) unsigned NOT NULL,
            `status` varchar(50) NOT NULL COMMENT 'Статус на момент события',
            `note` text DEFAULT NULL COMMENT 'Комментарий админа/системы',
            `actor_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ID пользователя (0 = система, NULL = админ)',
            `actor_type` enum('user','admin','system') NOT NULL DEFAULT 'system',
            `is_read` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = прочитано пользователем',
            `is_read_admin` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = прочитано администратором',
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`event_id`),
            KEY `idx_claim_id` (`claim_id`),
            KEY `idx_status` (`status`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_claim_created` (`claim_id`, `created_at` DESC),
            KEY `idx_unread` (`is_read`, `actor_type`),
            KEY `idx_unread_admin` (`is_read_admin`, `actor_type`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='История изменений заявок на кэшбэк';";

        $tables = array(
            'cashback_claims'       => $table_claims,
            'cashback_claim_events' => $table_events,
        );

        $failed = array();
        foreach ($tables as $name => $sql) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL from local array (CREATE TABLE), no user input.
            $result = $wpdb->query($sql);
            if ($result === false) {
                $failed[] = $name . ': ' . $wpdb->last_error;
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log("[Claims] Failed to create table {$name}: " . $wpdb->last_error);
            }
        }

        if (!empty($failed)) {
            throw new Exception(esc_html('Failed to create claims tables: ' . implode('; ', $failed)));
        }

        self::add_constraints();

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log('[Claims] Tables created successfully');
    }

    private static function add_constraints(): void {
        global $wpdb;

        $constraints = array(
            "ALTER TABLE `{$wpdb->prefix}cashback_claims`
                ADD CONSTRAINT `fk_claims_user` FOREIGN KEY (`user_id`)
                REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE CASCADE",
            "ALTER TABLE `{$wpdb->prefix}cashback_claims`
                ADD CONSTRAINT `chk_probability_range`
                CHECK (`probability_score` BETWEEN 0.00 AND 100.00)",
            "ALTER TABLE `{$wpdb->prefix}cashback_claim_events`
                ADD CONSTRAINT `fk_events_claim` FOREIGN KEY (`claim_id`)
                REFERENCES `{$wpdb->prefix}cashback_claims` (`claim_id`) ON DELETE CASCADE",
        );

        $suppress = $wpdb->suppress_errors(true);
        foreach ($constraints as $sql) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL from local array (ALTER TABLE constraints), no user input.
            $wpdb->query($sql);
            // 12f ADR (F-20-001): классифицируем через is_known_ddl_error вместо
            // substring-match на "Duplicate" — покрываем также check-constraint и FK-duplicate.
            if ($wpdb->last_error && !self::is_known_ddl_error($wpdb->last_error)) {
                self::report_migration_error('add_constraints', $wpdb->last_error);
            }
        }
        $wpdb->suppress_errors($suppress);
    }

    /**
     * Add is_read column if missing (migration for existing installs).
     */
    public static function migrate_add_is_read(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_claim_events';

        $col = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'is_read' ) );

        if (empty($col)) {
            // 12f ADR (F-20-001): после каждого DDL проверяем $wpdb->last_error
            // и эскалируем через report_migration_error, если не idempotent-expected.
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `is_read` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = прочитано пользователем' AFTER `actor_type`", $table ) );
            if ($wpdb->last_error && !self::is_known_ddl_error($wpdb->last_error)) {
                self::report_migration_error('migrate_add_is_read:add_column', $wpdb->last_error);
            }

            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY `idx_unread` (`is_read`, `actor_type`)', $table ) );
            if ($wpdb->last_error && !self::is_known_ddl_error($wpdb->last_error)) {
                self::report_migration_error('migrate_add_is_read:add_key', $wpdb->last_error);
            }

            // Mark all existing events as read so users don't get flooded
            $wpdb->query( $wpdb->prepare( 'UPDATE %i SET `is_read` = 1', $table ) );
            if ($wpdb->last_error) {
                // UPDATE не имеет "known" duplicate-semantics — любая ошибка = unexpected.
                self::report_migration_error('migrate_add_is_read:backfill', $wpdb->last_error);
            }

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Claims] Migration: added is_read column to claim_events');
        }
    }

    /**
     * Add is_read_admin column if missing (migration for existing installs).
     */
    public static function migrate_add_is_read_admin(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_claim_events';

        $col = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'is_read_admin' ) );

        if (empty($col)) {
            // 12f ADR (F-20-001): эскалация непредвиденных DDL-ошибок (см. migrate_add_is_read).
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `is_read_admin` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = прочитано администратором' AFTER `is_read`", $table ) );
            if ($wpdb->last_error && !self::is_known_ddl_error($wpdb->last_error)) {
                self::report_migration_error('migrate_add_is_read_admin:add_column', $wpdb->last_error);
            }

            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY `idx_unread_admin` (`is_read_admin`, `actor_type`)', $table ) );
            if ($wpdb->last_error && !self::is_known_ddl_error($wpdb->last_error)) {
                self::report_migration_error('migrate_add_is_read_admin:add_key', $wpdb->last_error);
            }

            // Mark user-authored events from the last 30 days as unread; older ones stay read
            $wpdb->query( $wpdb->prepare( 'UPDATE %i SET `is_read_admin` = 0 WHERE `actor_type` = %s AND `created_at` >= (NOW() - INTERVAL 30 DAY)', $table, 'user' ) );
            if ($wpdb->last_error) {
                self::report_migration_error('migrate_add_is_read_admin:backfill', $wpdb->last_error);
            }

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Claims] Migration: added is_read_admin column to claim_events');
        }
    }

    /**
     * Add scoring_breakdown column if missing (F-20-002 follow-up).
     *
     * Хранит JSON-разложение probability_score по 5 факторам
     * (time/merchant/user/consistency/risk) — чтобы админ в detail-карточке
     * видел, почему заявка получила такую цифру. Legacy-строки остаются
     * с NULL (admin UI их обрабатывает gracefully).
     */
    public static function migrate_add_scoring_breakdown(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_claims';

        $col = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'scoring_breakdown' ) );

        if (empty($col)) {
            // 12f ADR (F-20-001): классифицируем DDL-ошибки через is_known_ddl_error,
            // непредвиденные эскалируем через report_migration_error.
            $wpdb->query( $wpdb->prepare(
                "ALTER TABLE %i ADD COLUMN `scoring_breakdown` text DEFAULT NULL COMMENT 'JSON разложение probability_score по 5 факторам (F-20-002)' AFTER `probability_score`",
                $table
            ) );
            if ($wpdb->last_error && !self::is_known_ddl_error($wpdb->last_error)) {
                self::report_migration_error('migrate_add_scoring_breakdown:add_column', $wpdb->last_error);
            }

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Claims] Migration: added scoring_breakdown column to cashback_claims');
        }
    }

    /**
     * Get count of unread user events for admin across a set of claims.
     */
    public static function get_unread_events_count_admin(): int {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_claim_events';
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE actor_type = %s AND is_read_admin = 0',
            $table,
            'user'
        ) );
    }

    /**
     * Mark all user events of a claim as read by admin.
     */
    public static function mark_admin_events_read( int $claim_id ): int {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_claim_events';
        return (int) $wpdb->query( $wpdb->prepare(
            'UPDATE %i SET is_read_admin = 1 WHERE claim_id = %d AND actor_type = %s AND is_read_admin = 0',
            $table,
            $claim_id,
            'user'
        ) );
    }

    /**
     * Get count of unread admin/system events for a user across all their claims.
     */
    public static function get_unread_events_count( int $user_id ): int {
        global $wpdb;

        $events_table = $wpdb->prefix . 'cashback_claim_events';
        $claims_table = $wpdb->prefix . 'cashback_claims';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM %i e
             INNER JOIN %i c ON c.claim_id = e.claim_id
             WHERE c.user_id = %d AND e.actor_type IN ('admin', 'system') AND e.is_read = 0",
            $events_table,
            $claims_table,
            $user_id
        ) );
    }

    /**
     * Mark all events for a user's claims as read.
     */
    public static function mark_user_events_read( int $user_id ): int {
        global $wpdb;

        $events_table = $wpdb->prefix . 'cashback_claim_events';
        $claims_table = $wpdb->prefix . 'cashback_claims';
        return (int) $wpdb->query( $wpdb->prepare(
            'UPDATE %i e
             INNER JOIN %i c ON c.claim_id = e.claim_id
             SET e.is_read = 1
             WHERE c.user_id = %d AND e.actor_type IN (%s, %s) AND e.is_read = 0',
            $events_table,
            $claims_table,
            $user_id,
            'admin',
            'system'
        ) );
    }

    public static function drop_tables(): void {
        global $wpdb;

        $tables = array(
            "{$wpdb->prefix}cashback_claim_events",
            "{$wpdb->prefix}cashback_claims",
        );

        foreach ($tables as $table) {
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
        }
    }
}
