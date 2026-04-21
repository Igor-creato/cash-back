<?php
/**
 * Schema-level idempotency migration (Группа 6 ADR, шаг 2).
 *
 * Накладывает UNIQUE-ключи на 3 таблицы:
 *   - cashback_fraud_device_ids: ADD session_date DATE GENERATED + UNIQUE(user_id, session_date, device_id)
 *   - cashback_claims:           ADD idempotency_key CHAR(36) + UNIQUE(user_id, idempotency_key) + UNIQUE(merchant_id, order_id)
 *   - cashback_support_messages: ADD request_id CHAR(36) + UNIQUE(request_id)
 *
 * Flow:
 *   1. Idempotent: при установленной opt cashback_schema_idempotency_v1_applied — skip.
 *   2. Pre-check дублей по каждому будущему UNIQUE → при наличии abort + set blocked-флаг.
 *   3. Clean DB → DDL выполняются по шагам; каждый шаг проверяет SHOW COLUMNS / SHOW INDEX.
 *   4. После успеха set applied-флаг, очистить blocked-флаг.
 *
 * @package Cashback
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Cashback_Schema_Idempotency_Migration')) {
    final class Cashback_Schema_Idempotency_Migration {
        public const OPTION_APPLIED = 'cashback_schema_idempotency_v1_applied';
        public const OPTION_BLOCKED = 'cashback_schema_idempotency_v1_blocked';

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
         *   aborted_reason: string|null,
         *   duplicate_checks: array<string, int>,
         *   ddl_executed: array<int, string>
         * }
         */
        public function run(): array {
            if ((bool) get_option(self::OPTION_APPLIED, false)) {
                return array(
                    'applied'          => false,
                    'already_applied'  => true,
                    'aborted_reason'   => null,
                    'duplicate_checks' => array(),
                    'ddl_executed'     => array(),
                );
            }

            $this->emit('run.start', array());

            $duplicate_checks = $this->pre_check_duplicates();
            $has_duplicates   = array_sum($duplicate_checks) > 0;

            if ($has_duplicates) {
                update_option(
                    self::OPTION_BLOCKED,
                    array(
                        'duplicate_checks' => $duplicate_checks,
                        'blocked_at'       => time(),
                    ),
                    false
                );
                $this->emit('run.aborted', array( 'duplicate_checks' => $duplicate_checks ));

                return array(
                    'applied'          => false,
                    'already_applied'  => false,
                    'aborted_reason'   => 'duplicates_found',
                    'duplicate_checks' => $duplicate_checks,
                    'ddl_executed'     => array(),
                );
            }

            $ddl_executed = $this->execute_ddl();

            update_option(self::OPTION_APPLIED, true, true);
            delete_option(self::OPTION_BLOCKED);

            $this->emit('run.end', array( 'ddl_executed' => count($ddl_executed) ));

            return array(
                'applied'          => true,
                'already_applied'  => false,
                'aborted_reason'   => null,
                'duplicate_checks' => $duplicate_checks,
                'ddl_executed'     => $ddl_executed,
            );
        }

        /**
         * @return array<string, int>
         */
        private function pre_check_duplicates(): array {
            $fraud_table  = $this->wpdb->prefix . 'cashback_fraud_device_ids';
            $claims_table = $this->wpdb->prefix . 'cashback_claims';

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL-миграция: literal имя таблицы в backticks, без user-input.
            $fraud_count = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM (SELECT 1 FROM `{$fraud_table}` WHERE user_id IS NOT NULL GROUP BY user_id, DATE(first_seen), device_id HAVING COUNT(*) > 1) t" );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL-миграция: literal имя таблицы в backticks, без user-input.
            $claims_count = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM (SELECT 1 FROM `{$claims_table}` WHERE merchant_id IS NOT NULL GROUP BY merchant_id, order_id HAVING COUNT(*) > 1) t" );

            return array(
                'cashback_fraud_device_ids'      => $fraud_count,
                'cashback_claims_merchant_order' => $claims_count,
            );
        }

        /**
         * @return array<int, string>
         */
        private function execute_ddl(): array {
            $steps = array(
                array(
                    'table' => 'cashback_fraud_device_ids',
                    'type'  => 'column',
                    'name'  => 'session_date',
                    'ddl'   => 'ADD COLUMN `session_date` DATE GENERATED ALWAYS AS (DATE(`first_seen`)) STORED',
                ),
                array(
                    'table' => 'cashback_fraud_device_ids',
                    'type'  => 'index',
                    'name'  => 'uk_user_session_device',
                    'ddl'   => 'ADD UNIQUE KEY `uk_user_session_device` (`user_id`, `session_date`, `device_id`)',
                ),
                array(
                    'table' => 'cashback_claims',
                    'type'  => 'column',
                    'name'  => 'idempotency_key',
                    'ddl'   => 'ADD COLUMN `idempotency_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL',
                ),
                array(
                    'table' => 'cashback_claims',
                    'type'  => 'index',
                    'name'  => 'uk_user_idempotency',
                    'ddl'   => 'ADD UNIQUE KEY `uk_user_idempotency` (`user_id`, `idempotency_key`)',
                ),
                array(
                    'table' => 'cashback_claims',
                    'type'  => 'index',
                    'name'  => 'uk_merchant_order',
                    'ddl'   => 'ADD UNIQUE KEY `uk_merchant_order` (`merchant_id`, `order_id`)',
                ),
                array(
                    'table' => 'cashback_support_messages',
                    'type'  => 'column',
                    'name'  => 'request_id',
                    'ddl'   => 'ADD COLUMN `request_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL',
                ),
                array(
                    'table' => 'cashback_support_messages',
                    'type'  => 'index',
                    'name'  => 'uk_request_id',
                    'ddl'   => 'ADD UNIQUE KEY `uk_request_id` (`request_id`)',
                ),
            );

            $executed = array();
            foreach ($steps as $step) {
                $full_table = $this->wpdb->prefix . $step['table'];

                if ('column' === $step['type'] && $this->column_exists($step['table'], $step['name'])) {
                    $this->emit('step.skipped', array(
						'reason' => 'column_exists',
						'table'  => $step['table'],
						'name'   => $step['name'],
					));
                    continue;
                }
                if ('index' === $step['type'] && $this->index_exists($step['table'], $step['name'])) {
                    $this->emit('step.skipped', array(
						'reason' => 'index_exists',
						'table'  => $step['table'],
						'name'   => $step['name'],
					));
                    continue;
                }

                $sql = "ALTER TABLE `{$full_table}` {$step['ddl']}";
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- DDL-миграция: literal имена таблиц/колонок в backticks, без user-input.
                $this->wpdb->query($sql);
                $executed[] = $sql;
                $this->emit('step.executed', array(
					'table' => $step['table'],
					'name'  => $step['name'],
				));
            }

            return $executed;
        }

        private function column_exists( string $table_suffix, string $column ): bool {
            $full = $this->wpdb->prefix . $table_suffix;
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW COLUMNS: literal имя таблицы, $column — из allowlist steps[] в этом классе.
            $rows = $this->wpdb->get_results( "SHOW COLUMNS FROM `{$full}` LIKE '{$column}'", ARRAY_A );
            return !empty($rows);
        }

        private function index_exists( string $table_suffix, string $key_name ): bool {
            $full = $this->wpdb->prefix . $table_suffix;
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW INDEX: literal имя таблицы, $key_name — из allowlist steps[] в этом классе.
            $rows = $this->wpdb->get_results( "SHOW INDEX FROM `{$full}` WHERE Key_name = '{$key_name}'", ARRAY_A );
            return !empty($rows);
        }

        /** @param array<string, mixed> $ctx */
        private function emit( string $event, array $ctx ): void {
            if (null !== $this->logger) {
                ( $this->logger )($event, $ctx);
                return;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fallback только если wp_json_encode() не определён (non-WP runner для unit-тестов).
            $payload = function_exists('wp_json_encode') ? wp_json_encode($ctx) : json_encode($ctx);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional schema-migration diagnostic logging (group 6, step 2).
            error_log('[cashback-schema-idempotency-v1] ' . $event . ' ' . ( is_string($payload) ? $payload : '' ));
        }
    }
}
