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
