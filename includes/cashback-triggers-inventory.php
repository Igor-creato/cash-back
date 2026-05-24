<?php

/**
 * Trigger inventory check: отказывается инициализировать write-paths плагина,
 * если хотя бы один обязательный MariaDB-триггер отсутствует в БД.
 *
 * Codex adversarial-review round 7 (2026-05-10): после удаления
 * Cashback_Trigger_Fallbacks (round 5) плагин полагается на DB-триггеры для
 * критичных финансовых инвариантов:
 *   - status transitions (cashback_tr_validate_status_transition[_unregistered])
 *   - payout immutability (tr_prevent_*_paid_payout, tr_prevent_*_failed_payout)
 *   - fail_reason invariant (tr_payout_require_fail_reason_*)
 *   - ban freeze/unfreeze (tr_freeze_balance_on_ban, tr_unfreeze_*, tr_clear_*)
 *   - cashback calculation (calculate_cashback_before_insert/update)
 *
 * Если хотя бы один отсутствует (failed recreate, ручной DROP оператором БД,
 * restore из бэкапа без триггеров) — write-операции пройдут без schema-уровневой
 * защиты. Этот gate в CashbackPlugin::init() запускается на каждый request:
 * SELECT TRIGGER_NAME из information_schema.TRIGGERS, mismatch → admin notice
 * + early return из init(), хуки/AJAX/REST не регистрируются.
 *
 * @package Cashback
 */

declare(strict_types=1);

defined('ABSPATH') || die('No script kiddies please!');

if (!function_exists('cashback_required_triggers')) {
    /**
     * Полный список триггеров, которые `Mariadb_Plugin::create_triggers()`
     * создаёт через `CREATE OR REPLACE TRIGGER`. Имена БЕЗ префикса (префикс
     * добавляется на runtime через `$wpdb->prefix`).
     *
     * Должен совпадать с массивом `$triggers` в [mariadb.php](mariadb.php).
     * Структурный тест в development/test/tests/CodexRound6FixesTest.php
     * (или новый round-7 тест) валидирует синхронизацию.
     *
     * @return string[]
     */
    function cashback_required_triggers(): array {
        return array(
            // Cashback calculation (BEFORE INSERT/UPDATE)
            'calculate_cashback_before_insert',
            'calculate_cashback_before_insert_unregistered',
            'calculate_cashback_before_update',
            'calculate_cashback_before_update_unregistered',

            // Status state-machine validation
            'cashback_tr_prevent_delete_final_status',
            'cashback_tr_validate_status_transition',
            'cashback_tr_validate_status_transition_unregistered',

            // Payout immutability (paid/failed)
            'tr_prevent_delete_paid_payout',
            'tr_prevent_update_paid_payout',
            'tr_prevent_delete_failed_payout',
            'tr_prevent_update_failed_payout',

            // fail_reason invariant
            'tr_payout_require_fail_reason_ins',
            'tr_payout_require_fail_reason_upd',

            // Ban/unban balance lifecycle
            'tr_banned_user_update_banned_at',
            'tr_freeze_balance_on_ban',
            'tr_clear_ban_on_unban',
            'tr_unfreeze_balance_on_unban',

            // Webhook payload integrity
            'tr_webhook_payload_hash',
        );
    }
}

if (!function_exists('cashback_required_schema_artifacts')) {
    /**
     * Каталог ОБЯЗАТЕЛЬНЫХ schema-артефактов из миграций v6/v7 с НАЗВАННЫМИ
     * ключами. Handler'ы передают subset нужных артефактов в
     * `cashback_check_required_schema_present()` через второй параметр
     * `$artifact_keys`, чтобы missing-payout-артефакт не 503'ил user-admin
     * страницу и vice versa.
     *
     * Codex adversarial-review round 11 (2026-05-10): добавлен helper.
     * Codex round 16 (2026-05-10): keyed catalog для granular subset gates
     * (aggregate bundle блокировал unrelated handlers).
     *
     * @return array<string,array{type:string,table:string,column:string,enum_value?:string}>
     */
    function cashback_required_schema_artifacts(): array {
        return array(
            // v6: ban_reason_admin (admin-only причина блокировки).
            // Used by: admin/users-management.php (render_users_page,
            // handle_update_user_profile, handle_get_user_profile).
            'v6_ban_reason_admin' => array(
                'type'   => 'column',
                'table'  => 'cashback_user_profile',
                'column' => 'ban_reason_admin',
            ),
            // v7: frozen_balance_admin (bucket для declined-выплат, требует
            // ручной разморозки). Used by: admin/payouts.php
            // (handle_update_payout_request decline branch + handle_payout_unfreeze).
            'v7_frozen_balance_admin' => array(
                'type'   => 'column',
                'table'  => 'cashback_user_balance',
                'column' => 'frozen_balance_admin',
            ),
            // v7: enum-значение 'payout_unfreeze' в cashback_balance_ledger.type
            // (compensating-запись при unfreeze). Used by:
            // admin/payouts.php (handle_payout_unfreeze).
            'v7_payout_unfreeze' => array(
                'type'       => 'enum_value',
                'table'      => 'cashback_balance_ledger',
                'column'     => 'type',
                'enum_value' => 'payout_unfreeze',
            ),
        );
    }
}

if (!function_exists('cashback_check_required_schema_present')) {
    /**
     * Physical-state gate для миграционных артефактов v6/v7. В отличие от
     * `cashback_check_triggers_present()` (который проверяет имена триггеров),
     * этот helper probe'ит INFORMATION_SCHEMA — авторитетный источник
     * физического состояния схемы.
     *
     * Codex adversarial-review round 11 (2026-05-10):
     *   - runtime-код в admin/users-management.php и admin/payouts.php напрямую
     *     SELECT'ит/UPDATE'ит ban_reason_admin, frozen_balance_admin, и
     *     INSERT'ит ledger-записи с type='payout_unfreeze';
     *   - после round 10 revert init-gate'а на `$trigger_migration_failed`,
     *     transient migration failure → плагин init'ится без этих артефактов
     *     → admin кликает Decline payout → SQL error 1054 «Unknown column»;
     *   - этот gate ловит реальное отсутствие schema, а не throw из миграции —
     *     transient throws с сохранившейся схемой не блокируют плагин.
     *
     * @param array<string,array<int,string>>|null $present_override Опциональная
     *        мапа `table => [column1, column2, …]` для подмены БД-проверок
     *        в unit-тестах. enum_value отдаём через ключ `__enum:table.column`
     *        со значением CSV списка значений.
     * @param array<int,string>|null $artifact_keys Опциональный subset
     *        named-keys из `cashback_required_schema_artifacts()`. Codex
     *        round 16 (2026-05-10): без subset'а helper проверял весь
     *        bundle и блокировал unrelated handlers (missing payout-артефакт
     *        503'ил user admin page). С subset каждый handler гейтит только
     *        свои артефакты.
     *
     * @return string|null Локализованное сообщение об ошибке либо null.
     */
    function cashback_check_required_schema_present( ?array $present_override = null, ?array $artifact_keys = null ): ?string {
        global $wpdb;

        $catalog = cashback_required_schema_artifacts();
        if ($artifact_keys !== null) {
            $catalog = array_intersect_key($catalog, array_flip($artifact_keys));
        }

        $missing = array();
        foreach ($catalog as $artifact) {
            $type    = (string) ( $artifact['type'] ?? '' );
            $table   = (string) ( $artifact['table'] ?? '' );
            $column  = (string) ( $artifact['column'] ?? '' );

            if ($type === 'column') {
                if ($present_override !== null) {
                    $columns = (array) ( $present_override[ $table ] ?? array() );
                    $exists  = in_array($column, $columns, true);
                } else {
                    if (!is_object($wpdb) || !isset($wpdb->prefix)) {
                        // Test/bootstrap or transient early-load without wpdb:
                        // fail-open like other inconclusive probes, never mark
                        // schema missing without a real DB answer.
                        continue;
                    }
                    $full_table = $wpdb->prefix . $table;
                    // Codex round 12 (2026-05-10): probe ДОЛЖЕН различать
                    // confirmed-missing и transient query failure. Сбрасываем
                    // last_error до probe — иначе пред. ошибка из чужого кода
                    // нас введёт в заблуждение.
                    $wpdb->last_error = '';
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- runtime invariant probe.
                    $raw   = $wpdb->get_var($wpdb->prepare(
                        'SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME = %s
                            AND COLUMN_NAME = %s',
                        $full_table,
                        $column
                    ));
                    $err = (string) ( $wpdb->last_error ?? '' );
                    if ($err !== '' || $raw === null) {
                        // Probe failure (lock-wait, restricted access, transient).
                        // Fail-open: НЕ помечаем как missing, иначе init disable
                        // плагина на временном DB-сбое (round 12 #2). Логируем.
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log(sprintf(
                            '[Cashback] required-schema probe inconclusive for %s.%s: %s',
                            $table,
                            $column,
                            $err !== '' ? $err : 'NULL result'
                        ));
                        continue;
                    }
                    $exists = ( (int) $raw > 0 );
                }
                if (!$exists) {
                    $missing[] = sprintf('%s.%s', $table, $column);
                }
            } elseif ($type === 'enum_value') {
                $enum_value = (string) ( $artifact['enum_value'] ?? '' );
                if ($present_override !== null) {
                    $values_csv = (string) ( $present_override[ '__enum:' . $table . '.' . $column ] ?? '' );
                    $exists     = ( strpos($values_csv, "'" . $enum_value . "'") !== false )
                        || in_array($enum_value, explode(',', $values_csv), true);
                } else {
                    if (!is_object($wpdb) || !isset($wpdb->prefix)) {
                        // See column branch: no real DB answer means
                        // inconclusive, not missing.
                        continue;
                    }
                    $full_table = $wpdb->prefix . $table;
                    // Codex round 12 (2026-05-10): см. column branch выше.
                    $wpdb->last_error = '';
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- runtime invariant probe.
                    $raw = $wpdb->get_var($wpdb->prepare(
                        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME = %s
                            AND COLUMN_NAME = %s',
                        $full_table,
                        $column
                    ));
                    $err = (string) ( $wpdb->last_error ?? '' );
                    if ($err !== '') {
                        // Codex round 12: transient query failure → fail-open.
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log(sprintf(
                            '[Cashback] required-schema probe inconclusive for %s.%s enum: %s',
                            $table,
                            $column,
                            $err
                        ));
                        continue;
                    }
                    // Codex round 15 (2026-05-10): SELECT успешен, но 0 строк
                    // (raw === null) → таблица/колонка реально отсутствует.
                    // information_schema всегда есть, RDBMS-агностично; null
                    // здесь ≠ transient failure (last_error пуст), это прямой
                    // сигнал «artifact missing». Если оба случая (transient
                    // null и real null) трактовать одинаково, real schema
                    // drift проходит мимо guard'а.
                    $exists = ( $raw !== null )
                        && ( strpos((string) $raw, "'" . $enum_value . "'") !== false );
                }
                if (!$exists) {
                    $missing[] = sprintf('%s.%s enum value `%s`', $table, $column, $enum_value);
                }
            }
        }

        if (empty($missing)) {
            return null;
        }

        return sprintf(
            /* translators: 1: missing artifact count, 2: total required count, 3: comma-separated list */
            __(
                'Cashback Plugin: %1$d of %2$d required schema artifacts (v6/v7 migrations) are missing: %3$s. Admin write-paths (ban with admin reason, payout decline/unfreeze) would hit «Unknown column» SQL errors. Re-activate the plugin to retry migrations.',
                'cashback-plugin'
            ),
            count($missing),
            count($catalog),
            implode(', ', $missing)
        );
    }
}

if (!function_exists('cashback_check_triggers_present')) {
    /**
     * Проверяет наличие всех обязательных триггеров в `information_schema.TRIGGERS`.
     *
     * @param string[]|null $present_override Список реально присутствующих имён
     *                                        триггеров (с префиксом). Если задан —
     *                                        используется вместо реального запроса
     *                                        к БД. Нужно для unit-тестов.
     *
     * @return string|null Локализованное сообщение об ошибке либо null, если
     *                     все триггеры на месте.
     */
    function cashback_check_triggers_present( ?array $present_override = null ): ?string {
        global $wpdb;

        $expected_short = cashback_required_triggers();
        $expected_full  = array_map(
            static fn( string $name ): string => $wpdb->prefix . $name,
            $expected_short
        );

        if ($present_override !== null) {
            $present = array_map('strval', $present_override);
        } else {
            // PHPCS не распознаёт WordPress-паттерн динамических %s-placeholder'ов
            // для IN(...): $placeholders содержит ТОЛЬКО литералы '%s,%s,...',
            // ни одного user-data байта. Реальные значения подставляются через
            // $wpdb->prepare() variadic-аргументами с экранированием.
            $placeholders = implode(',', array_fill(0, count($expected_full), '%s'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders — литеральные %s, реальные значения через variadic prepare.
            $sql = "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME IN ({$placeholders})";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- runtime invariant probe; $sql проходит через $wpdb->prepare выше.
            $rows = $wpdb->get_col($wpdb->prepare($sql, ...$expected_full));
            $present = is_array($rows) ? $rows : array();
        }

        $missing = array_values(array_diff($expected_full, $present));
        if (empty($missing)) {
            return null;
        }

        // Показываем первые 3 missing — этого достаточно для диагностики,
        // полный список оставляет в логах через caller'а.
        return sprintf(
            /* translators: 1: missing trigger count, 2: total required count, 3: comma-separated sample of missing triggers */
            __(
                'Cashback Plugin: %1$d of %2$d required MariaDB triggers are missing (%3$s). Schema-level financial integrity guards (status transitions, payout immutability, fail_reason invariant, ban freeze/unfreeze) are not active. Re-activate the plugin to recreate triggers.',
                'cashback-plugin'
            ),
            count($missing),
            count($expected_full),
            implode(', ', array_slice($missing, 0, 3))
        );
    }
}
