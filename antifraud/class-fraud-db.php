<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Управление таблицами антифрод-модуля
 *
 * @since 1.2.0
 */
class Cashback_Fraud_DB {

    /**
     * Создание всех таблиц антифрод-модуля
     *
     * @return void
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Таблица алертов (создаём первой, т.к. signals ссылается на неё)
        $table_alerts = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_fraud_alerts` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned NOT NULL,
            `alert_type` varchar(50) NOT NULL COMMENT 'multi_account_ip, multi_account_fp, shared_details, cancellation_rate, velocity, amount_anomaly, new_account_risk',
            `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
            `risk_score` decimal(5,2) NOT NULL DEFAULT 0.00,
            `status` enum('open','reviewing','confirmed','dismissed') NOT NULL DEFAULT 'open',
            `summary` text NOT NULL,
            `details` longtext DEFAULT NULL COMMENT 'JSON evidence',
            `reviewed_by` bigint(20) unsigned DEFAULT NULL,
            `reviewed_at` datetime DEFAULT NULL,
            `review_note` text DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_status` (`user_id`, `status`),
            KEY `idx_status_created` (`status`, `created_at` DESC),
            KEY `idx_alert_type` (`alert_type`),
            KEY `idx_severity` (`severity`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Fraud detection alerts';";

        // Таблица сигналов (составные части алертов)
        $table_signals = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_fraud_signals` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `alert_id` bigint(20) unsigned NOT NULL,
            `signal_type` varchar(50) NOT NULL COMMENT 'ip_match, fingerprint_match, shared_details, cancellation_rate, velocity, amount_spike, new_account_withdrawal',
            `weight` decimal(5,2) NOT NULL DEFAULT 1.00,
            `evidence` longtext NOT NULL COMMENT 'JSON',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_alert_id` (`alert_id`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Individual fraud signals composing alerts';";

        // Таблица fingerprints и IP
        $table_fingerprints = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_user_fingerprints` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) unsigned NOT NULL,
            `ip_address` varchar(45) NOT NULL,
            `fingerprint_hash` char(64) DEFAULT NULL COMMENT 'SHA-256 browser fingerprint',
            `user_agent_hash` char(64) NOT NULL COMMENT 'SHA-256 of User-Agent',
            `event_type` enum('login','page_view','withdrawal','registration') NOT NULL DEFAULT 'login',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_ip_address` (`ip_address`),
            KEY `idx_fingerprint` (`fingerprint_hash`),
            KEY `idx_ip_user` (`ip_address`, `user_id`),
            KEY `idx_fingerprint_user` (`fingerprint_hash`, `user_id`),
            KEY `idx_created` (`created_at`),
            KEY `idx_created_ip_user` (`created_at`, `ip_address`, `user_id`),
            KEY `idx_created_fp_user` (`created_at`, `fingerprint_hash`, `user_id`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='User session fingerprints for multi-account detection';";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Порядок важен: alerts -> signals -> fingerprints
        dbDelta($table_alerts);
        dbDelta($table_signals);
        dbDelta($table_fingerprints);

        // Добавляем FK вручную (dbDelta не создаёт FK)
        self::add_foreign_keys();

        error_log('Cashback Fraud DB: Tables created successfully');
    }

    /**
     * Добавление FK constraints (если ещё не существуют)
     *
     * @return void
     */
    private static function add_foreign_keys(): void {
        global $wpdb;

        $fk_map = array(
            'fk_fraud_alert_user' => array(
                'table' => $wpdb->prefix . 'cashback_fraud_alerts',
                'sql'   => "ALTER TABLE `{$wpdb->prefix}cashback_fraud_alerts`
                          ADD CONSTRAINT `fk_fraud_alert_user`
                          FOREIGN KEY (`user_id`) REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE CASCADE",
            ),
            'fk_signal_alert'     => array(
                'table' => $wpdb->prefix . 'cashback_fraud_signals',
                'sql'   => "ALTER TABLE `{$wpdb->prefix}cashback_fraud_signals`
                          ADD CONSTRAINT `fk_signal_alert`
                          FOREIGN KEY (`alert_id`) REFERENCES `{$wpdb->prefix}cashback_fraud_alerts` (`id`) ON DELETE CASCADE",
            ),
            'fk_fingerprint_user' => array(
                'table' => $wpdb->prefix . 'cashback_user_fingerprints',
                'sql'   => "ALTER TABLE `{$wpdb->prefix}cashback_user_fingerprints`
                          ADD CONSTRAINT `fk_fingerprint_user`
                          FOREIGN KEY (`user_id`) REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE CASCADE",
            ),
        );

        foreach ($fk_map as $fk_name => $info) {
            $exists = $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_NAME = %s AND TABLE_SCHEMA = DATABASE()',
                $fk_name
            ));

            if (!$exists) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static ALTER TABLE from local array, table names from $wpdb->prefix.
                $result = $wpdb->query($info['sql']);
                if ($result === false) {
                    error_log("Cashback Fraud DB: Failed to add FK {$fk_name}: " . $wpdb->last_error);
                }
            }
        }
    }

    /**
     * Очистка старых данных fingerprints и dismissed-алертов
     * Вызывается из WP Cron ежедневно
     *
     * @return void
     */
    public static function cleanup_old_data(): void {
        global $wpdb;

        $retention_days = 180;
        if (class_exists('Cashback_Fraud_Settings')) {
            $retention_days = Cashback_Fraud_Settings::get_fingerprint_retention_days();
        }

        // Удаление старых fingerprints
        $deleted_fp = $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->prefix}cashback_user_fingerprints`
             WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ));

        // Удаление dismissed-алертов старше 90 дней
        $deleted_alerts = $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->prefix}cashback_fraud_alerts`
             WHERE status = 'dismissed'
             AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            90
        ));

        error_log(sprintf(
            'Cashback Fraud DB: Cleanup completed — %d fingerprints, %d dismissed alerts removed',
            $deleted_fp ?: 0,
            $deleted_alerts ?: 0
        ));
    }
}

// WP Cron handler
add_action('cashback_fraud_cleanup_cron', function (): void {
    Cashback_Fraud_DB::cleanup_old_data();
});
