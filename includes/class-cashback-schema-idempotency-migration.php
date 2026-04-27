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
 *   1. Self-heal: при OPTION_APPLIED=true проверяем целостность schema; если broken → reset флага.
 *   2. Idempotent: при установленной opt cashback_schema_idempotency_v1_applied — skip.
 *   3. Pre-check дублей по каждому будущему UNIQUE → при наличии abort + set blocked-флаг.
 *   4. Clean DB → DDL выполняются по шагам; каждый шаг проверяет SHOW COLUMNS / SHOW INDEX.
 *      Шаги для несуществующих таблиц skip-аются (reason='table_missing'); applied=true НЕ ставится.
 *   5. После полного успеха set applied-флаг, очистить blocked-флаг.
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
            // Self-heal: applied=true, но schema на существующих таблицах неполна → reset.
            if ((bool) get_option(self::OPTION_APPLIED, false)) {
                if ($this->is_schema_broken()) {
                    delete_option(self::OPTION_APPLIED);
                    $this->emit('self_heal.triggered', array());
                } else {
                    return array(
                        'applied'          => false,
                        'already_applied'  => true,
                        'aborted_reason'   => null,
                        'duplicate_checks' => array(),
                        'ddl_executed'     => array(),
                    );
                }
            }

            $this->emit('run.start', array());

            $duplicate_checks = $this->pre_check_duplicates();
            $has_duplicates   = array_sum($duplicate_checks) > 0;

            if ($has_duplicates) {
                update_option(
                    self::OPTION_BLOCKED,
                    array(
                        'reason'           => 'duplicates_found',
                        'duplicate_checks' => $duplicate_checks,
                        'blocked_at'       => time(),
                    ),
                    false
                );
                $this->emit('run.aborted', array(
                    'reason'           => 'duplicates_found',
                    'duplicate_checks' => $duplicate_checks,
                ));

                return array(
                    'applied'          => false,
                    'already_applied'  => false,
                    'aborted_reason'   => 'duplicates_found',
                    'duplicate_checks' => $duplicate_checks,
                    'ddl_executed'     => array(),
                );
            }

            $ddl_result  = $this->execute_ddl();
            $has_missing = !empty($ddl_result['missing']);

            if ($has_missing) {
                $missing_tables = array_values(array_unique($ddl_result['missing']));
                update_option(
                    self::OPTION_BLOCKED,
                    array(
                        'reason'         => 'missing_tables',
                        'missing_tables' => $missing_tables,
                        'blocked_at'     => time(),
                    ),
                    false
                );
                $this->emit('run.aborted', array(
                    'reason'         => 'missing_tables',
                    'missing_tables' => $missing_tables,
                ));

                return array(
                    'applied'          => false,
                    'already_applied'  => false,
                    'aborted_reason'   => 'missing_tables',
                    'duplicate_checks' => $duplicate_checks,
                    'ddl_executed'     => $ddl_result['executed'],
                );
            }

            update_option(self::OPTION_APPLIED, true, true);
            delete_option(self::OPTION_BLOCKED);

            $this->emit('run.end', array( 'ddl_executed' => count($ddl_result['executed']) ));

            return array(
                'applied'          => true,
                'already_applied'  => false,
                'aborted_reason'   => null,
                'duplicate_checks' => $duplicate_checks,
                'ddl_executed'     => $ddl_result['executed'],
            );
        }

        /**
         * @return array<string, int>
         */
        private function pre_check_duplicates(): array {
            $fraud_table  = $this->wpdb->prefix . 'cashback_fraud_device_ids';
            $claims_table = $this->wpdb->prefix . 'cashback_claims';

            $fraud_count = 0;
            if ($this->table_exists('cashback_fraud_device_ids')) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL-миграция: literal имя таблицы в backticks, без user-input.
                $fraud_count = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM (SELECT 1 FROM `{$fraud_table}` WHERE user_id IS NOT NULL GROUP BY user_id, DATE(first_seen), device_id HAVING COUNT(*) > 1) t" );
            }

            $claims_count = 0;
            if ($this->table_exists('cashback_claims')) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL-миграция: literal имя таблицы в backticks, без user-input.
                $claims_count = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM (SELECT 1 FROM `{$claims_table}` WHERE merchant_id IS NOT NULL GROUP BY merchant_id, order_id HAVING COUNT(*) > 1) t" );
            }

            return array(
                'cashback_fraud_device_ids'      => $fraud_count,
                'cashback_claims_merchant_order' => $claims_count,
            );
        }

        /**
         * @return array{executed: array<int, string>, missing: array<int, string>}
         */
        private function execute_ddl(): array {
            $steps = $this->get_steps();

            $executed = array();
            $missing  = array();
            foreach ($steps as $step) {
                $full_table = $this->wpdb->prefix . $step['table'];

                if (!$this->table_exists($step['table'])) {
                    $missing[] = $step['table'];
                    $this->emit('step.skipped', array(
						'reason' => 'table_missing',
						'table'  => $step['table'],
						'name'   => $step['name'],
					));
                    continue;
                }

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

            return array(
                'executed' => $executed,
                'missing'  => $missing,
            );
        }

        /**
         * Schema считается «broken», если хотя бы для одной из существующих
         * таблиц отсутствует ожидаемая колонка/индекс. Если таблица отсутствует
         * целиком — это НЕ broken (модуль ещё не дошёл до создания), миграция
         * поднимется штатно через missing_tables-fallback.
         */
        private function is_schema_broken(): bool {
            foreach ($this->get_steps() as $step) {
                if (!$this->table_exists($step['table'])) {
                    continue;
                }
                if ('column' === $step['type'] && !$this->column_exists($step['table'], $step['name'])) {
                    return true;
                }
                if ('index' === $step['type'] && !$this->index_exists($step['table'], $step['name'])) {
                    return true;
                }
            }
            return false;
        }

        /**
         * @return array<int, array{table: string, type: string, name: string, ddl: string}>
         */
        private function get_steps(): array {
            return array(
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
        }

        private function table_exists( string $table_suffix ): bool {
            $full = $this->wpdb->prefix . $table_suffix;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- INFORMATION_SCHEMA-проверка для DDL-guard'а.
            $count = (int) $this->wpdb->get_var( $this->wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $full
            ));
            return $count > 0;
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
