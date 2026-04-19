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

        // Таблица кластеров связанных аккаунтов (Этап 5).
        // Хранит результаты периодического union-find прохода по device/visitor/payment/phone/email рёбрам.
        // cluster_uid — детерминистический SHA-1 от sorted user_ids → UPSERT при следующем cron-прогоне.
        $table_clusters = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_fraud_account_clusters` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `cluster_uid` char(36) NOT NULL COMMENT 'Deterministic UUID v5-style from sorted user_ids',
            `user_ids` longtext NOT NULL COMMENT 'JSON array of user IDs',
            `user_count` int(10) unsigned NOT NULL,
            `link_reasons` text NOT NULL COMMENT 'JSON array of {reason, value, strength}',
            `score` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Confidence 0..100',
            `primary_reason` varchar(32) NOT NULL COMMENT 'Strongest link reason: device_id|visitor_id|payment_hash|phone|email_normalized',
            `detected_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            `status` varchar(16) NOT NULL DEFAULT 'open' COMMENT 'open|reviewing|confirmed|dismissed',
            `review_note` text DEFAULT NULL,
            `reviewed_by` bigint(20) unsigned DEFAULT NULL,
            `reviewed_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_cluster_uid` (`cluster_uid`),
            KEY `idx_status` (`status`),
            KEY `idx_score` (`score`),
            KEY `idx_detected` (`detected_at`),
            KEY `idx_primary_reason` (`primary_reason`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Account clusters detected by union-find on device/payment/email links';";

        // Таблица persistent device IDs (evercookie + FingerprintJS visitor IDs).
        // Параллельная legacy cashback_user_fingerprints; user_id NULL разрешён для guest-визитов.
        $table_device_ids = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}cashback_fraud_device_ids` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `device_id` char(36) NOT NULL COMMENT 'UUID v4 from LocalStorage/IndexedDB/Cookie',
            `visitor_id` varchar(64) DEFAULT NULL COMMENT 'FingerprintJS OSS visitor ID',
            `user_id` bigint(20) unsigned DEFAULT NULL,
            `fingerprint_hash` char(64) DEFAULT NULL COMMENT 'Legacy SHA-256 fingerprint, обратная совместимость',
            `components_hash` char(64) DEFAULT NULL COMMENT 'SHA-256 от состава FingerprintJS компонент',
            `ip_address` varchar(45) NOT NULL,
            `asn` int(10) unsigned DEFAULT NULL,
            `connection_type` varchar(32) DEFAULT NULL,
            `user_agent_hash` char(64) DEFAULT NULL,
            `confidence_score` decimal(3,2) DEFAULT NULL COMMENT 'FingerprintJS confidence (0..1)',
            `first_seen` datetime NOT NULL,
            `last_seen` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_device` (`device_id`),
            KEY `idx_visitor` (`visitor_id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_device_user` (`device_id`, `user_id`),
            KEY `idx_visitor_user` (`visitor_id`, `user_id`),
            KEY `idx_last_seen` (`last_seen`)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Persistent device IDs + FingerprintJS visitor IDs';";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Порядок важен: alerts -> signals -> fingerprints -> device_ids -> clusters
        dbDelta($table_alerts);
        dbDelta($table_signals);
        dbDelta($table_fingerprints);
        dbDelta($table_device_ids);
        dbDelta($table_clusters);

        // Добавляем FK вручную (dbDelta не создаёт FK)
        self::add_foreign_keys();

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
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
            'fk_device_ids_user'  => array(
                'table' => $wpdb->prefix . 'cashback_fraud_device_ids',
                // ON DELETE SET NULL: запись device остаётся как исторический сигнал даже после удаления юзера
                'sql'   => "ALTER TABLE `{$wpdb->prefix}cashback_fraud_device_ids`
                          ADD CONSTRAINT `fk_device_ids_user`
                          FOREIGN KEY (`user_id`) REFERENCES `{$wpdb->prefix}users` (`ID`) ON DELETE SET NULL",
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
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
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
        $fp_table   = $wpdb->prefix . 'cashback_user_fingerprints';
        $deleted_fp = $wpdb->query($wpdb->prepare(
            'DELETE FROM %i
             WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
            $fp_table,
            $retention_days
        ));

        // Очистка persistent device_ids — делегируем классу, который знает свою схему
        if (class_exists('Cashback_Fraud_Device_Id')) {
            Cashback_Fraud_Device_Id::purge_old($retention_days);
        }

        // Удаление dismissed-алертов старше 90 дней
        $alerts_table   = $wpdb->prefix . 'cashback_fraud_alerts';
        $deleted_alerts = $wpdb->query($wpdb->prepare(
            'DELETE FROM %i
             WHERE status = %s
             AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
            $alerts_table,
            'dismissed',
            90
        ));

        // Удаление старых кластеров аккаунтов (purge по updated_at, ретеншн 180 дней).
        // Кластеры со статусом open удаляются вместе со всеми — после такого срока они
        // в любом случае пересчитываются заново при следующем cron-проходе.
        $clusters_table   = $wpdb->prefix . 'cashback_fraud_account_clusters';
        $deleted_clusters = $wpdb->query($wpdb->prepare(
            'DELETE FROM %i
             WHERE updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
            $clusters_table,
            180
        ));

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log(sprintf(
            'Cashback Fraud DB: Cleanup completed — %d fingerprints, %d dismissed alerts, %d clusters removed',
            $deleted_fp ?: 0,
            $deleted_alerts ?: 0,
            $deleted_clusters ?: 0
        ));
    }
}

// WP Cron handler
add_action('cashback_fraud_cleanup_cron', function (): void {
    Cashback_Fraud_DB::cleanup_old_data();
});
