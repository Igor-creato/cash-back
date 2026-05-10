<?php

/**
 * DB capability gate: проверяет совместимость подключённой БД.
 *
 * Вынесено из cashback-plugin.php в отдельный файл, чтобы парсер версии можно
 * было покрыть unit-тестами на разных форматах `SELECT VERSION()` без загрузки
 * всего плагина. См. development/test/tests/DbCapabilityGateTest.php.
 *
 * @package Cashback
 */

declare(strict_types=1);

defined('ABSPATH') || die('No script kiddies please!');

// Constant fallback — обычно определяется в cashback-plugin.php, но тесты могут
// загружать этот файл в изоляции.
if (!defined('CASHBACK_MIN_MARIADB_VERSION')) {
    define('CASHBACK_MIN_MARIADB_VERSION', '10.1.4');
}

if (!function_exists('cashback_check_db_capabilities')) {
    /**
     * Проверяет, что подключённая БД — MariaDB версии не ниже CASHBACK_MIN_MARIADB_VERSION.
     *
     * Плагин полагается на CREATE OR REPLACE TRIGGER (MariaDB 10.1.4+) для атомарного
     * пересоздания триггеров без gap-окна, в котором концурентные DML могли бы пройти
     * без проверки финансовых инвариантов (payout immutability, fail_reason invariant,
     * status transitions, ban freeze/unfreeze). MySQL не поддерживает этот синтаксис.
     *
     * @param string|null $version_override Если передано — используется вместо
     *                                      `SELECT VERSION()` (нужно для unit-тестов
     *                                      парсера без mock'а $wpdb).
     * @return string|null Локализованное сообщение об ошибке либо null если БД совместима.
     */
    function cashback_check_db_capabilities( ?string $version_override = null ): ?string {
        if ($version_override !== null) {
            $version_string = $version_override;
        } else {
            global $wpdb;
            // SELECT VERSION() возвращает строку вида:
            //   - MariaDB:                '11.8.2-MariaDB-1:11.8.2+maria~ubu2404'
            //   - MariaDB (компактно):    '10.5.0-MariaDB'
            //   - MariaDB MySQL-compat:   '5.5.5-10.5.13-MariaDB-1:10.5.13+maria~focal'
            //                              ↑ fake legacy-prefix, реальная версия — 10.5.13
            //   - MySQL:                  '8.0.32' / '5.7.42-log'
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- runtime capability probe.
            $version_string = (string) $wpdb->get_var('SELECT VERSION()');
        }

        if ($version_string === '') {
            return __(
                'Cashback Plugin: cannot detect database server version (SELECT VERSION() returned empty).',
                'cashback-plugin'
            );
        }

        if (stripos($version_string, 'mariadb') === false) {
            return sprintf(
                /* translators: %s: detected DB server version string */
                __(
                    'Cashback Plugin requires MariaDB; detected MySQL or unknown database (%s). Atomic CREATE OR REPLACE TRIGGER is not supported on MySQL — schema-level financial integrity guards (ledger triggers, payout immutability, fail_reason invariant) cannot be installed safely.',
                    'cashback-plugin'
                ),
                $version_string
            );
        }

        // Якорим версию на токен MariaDB (а не на начало строки): MariaDB при
        // включённой MySQL-совместимости префиксует ответ '5.5.5-' (fake-prefix
        // для старых клиентов). Регулярка `^\d+\.\d+\.\d+` на таком ответе вернула
        // бы '5.5.5' и завернула бы совместимый сервер. Берём версию, стоящую
        // ПЕРЕД токеном `MariaDB` (опционально через separator-блок).
        if (preg_match('/(\d+\.\d+\.\d+)[^\d]*MariaDB/i', $version_string, $matches) !== 1) {
            return sprintf(
                /* translators: %s: detected MariaDB version string */
                __('Cashback Plugin: cannot parse MariaDB version from %s.', 'cashback-plugin'),
                $version_string
            );
        }

        $mariadb_version = $matches[1];
        if (version_compare($mariadb_version, CASHBACK_MIN_MARIADB_VERSION, '<')) {
            return sprintf(
                /* translators: 1: detected MariaDB version, 2: minimum required MariaDB version */
                __(
                    'Cashback Plugin requires MariaDB %2$s or higher (CREATE OR REPLACE TRIGGER support). Detected MariaDB %1$s.',
                    'cashback-plugin'
                ),
                $mariadb_version,
                CASHBACK_MIN_MARIADB_VERSION
            );
        }

        return null;
    }
}
