<?php
/**
 * Schema migration для таблицы rate-limit counters (Группа 7 ADR, шаг 3).
 *
 * Создаёт {$wpdb->prefix}cashback_rate_limit_counters — хранилище для атомарного
 * INSERT ... ON DUPLICATE KEY UPDATE (см. Cashback_Rate_Limit_SQL_Counter).
 *
 * Flow:
 *   1. Idempotent: при установленной opt cashback_rate_limit_v1_applied — skip.
 *   2. CREATE TABLE IF NOT EXISTS — повторный вызов без opt'а тоже безопасен.
 *   3. После успеха — set applied-флаг.
 *
 * @package Cashback
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Cashback_Rate_Limit_Migration')) {
    final class Cashback_Rate_Limit_Migration {
        public const OPTION_APPLIED = 'cashback_rate_limit_v1_applied';
        public const TABLE_SUFFIX   = 'cashback_rate_limit_counters';

        private object $wpdb;
        /** @var callable|null */
        private $logger;

        public function __construct( object $wpdb, ?callable $logger = null ) {
            $this->wpdb   = $wpdb;
            $this->logger = $logger;
        }

        /**
         * @return array{
         *   applied: bool,
         *   already_applied: bool,
         *   table_created: bool,
         *   ddl_executed: array<int, string>
         * }
         */
        public function run(): array {
            if ((bool) get_option(self::OPTION_APPLIED, false)) {
                return array(
                    'applied'         => false,
                    'already_applied' => true,
                    'table_created'   => false,
                    'ddl_executed'    => array(),
                );
            }

            $this->emit('run.start', array());

            $table          = $this->wpdb->prefix . self::TABLE_SUFFIX;
            $existed_before = $this->table_exists($table);

            $ddl = sprintf(
                'CREATE TABLE IF NOT EXISTS `%s` (
                    scope_key VARCHAR(64) NOT NULL,
                    window_started_at INT UNSIGNED NOT NULL,
                    hits INT UNSIGNED NOT NULL DEFAULT 0,
                    expires_at INT UNSIGNED NOT NULL,
                    PRIMARY KEY (scope_key),
                    KEY idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                $table
            );

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- DDL-миграция: literal имя таблицы в backticks, без user-input.
            $this->wpdb->query($ddl);

            update_option(self::OPTION_APPLIED, true, true);

            $this->emit('run.end', array( 'table_created' => ! $existed_before ));

            return array(
                'applied'         => true,
                'already_applied' => false,
                'table_created'   => ! $existed_before,
                'ddl_executed'    => array( $ddl ),
            );
        }

        private function table_exists( string $table ): bool {
            $sql = $this->wpdb->prepare('SHOW TABLES LIKE %s', $table);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql подготовлен через $wpdb->prepare.
            $rows = $this->wpdb->get_results($sql, ARRAY_A);
            return ! empty($rows);
        }

        /** @param array<string, mixed> $ctx */
        private function emit( string $event, array $ctx ): void {
            if (null !== $this->logger) {
                ( $this->logger )($event, $ctx);
                return;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fallback для non-WP runner'а.
            $payload = function_exists('wp_json_encode') ? wp_json_encode($ctx) : json_encode($ctx);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional schema-migration diagnostic logging (group 7, step 3).
            error_log('[cashback-rate-limit-v1] ' . $event . ' ' . ( is_string($payload) ? $payload : '' ));
        }
    }
}
