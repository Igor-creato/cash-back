<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурный тест: BEFORE INSERT/UPDATE триггеры на cashback_payout_requests
 * требуют непустой fail_reason для статусов declined/failed.
 *
 * Закрывает P2-A1-2 из E2E прогона 2026-04-29: defense-in-depth — нельзя
 * через raw UPDATE/INSERT перевести заявку в declined/failed без fail_reason.
 *
 * Source-based: парсит mariadb.php / cashback-plugin.php и проверяет:
 *   1. CREATE TRIGGER tr_payout_require_fail_reason_ins / _upd присутствуют;
 *   2. SIGNAL SQLSTATE '45000' с понятным MESSAGE_TEXT;
 *   3. условие покрывает declined+failed и NULL+пустую fail_reason;
 *   4. drop_triggers list содержит новые имена (idempotent recreate);
 *   5. миграция migrate_payout_require_fail_reason_v5 существует, идемпотентна
 *      через cashback_db_version, выполняет backfill и регистрируется в
 *      activate() + maybe_run_migrations().
 */
#[Group('payouts')]
#[Group('audit-trail')]
final class PayoutFailReasonTriggerTest extends TestCase
{
    private function source(string $rel): string
    {
        $path    = dirname(__DIR__, 3) . '/' . $rel;
        $content = file_get_contents($path);
        $this->assertIsString($content, "{$rel} must be readable");
        return $content;
    }

    private function mariadb_src(): string
    {
        return $this->source('mariadb.php');
    }

    private function plugin_src(): string
    {
        return $this->source('cashback-plugin.php');
    }

    // =========================================================================
    // Триггеры в create_triggers() (для fresh installs и recreate)
    // =========================================================================

    public function test_create_triggers_includes_require_fail_reason_insert(): void
    {
        $this->assertMatchesRegularExpression(
            '/CREATE TRIGGER\s+`?\{?\$?safe_prefix\}?tr_payout_require_fail_reason_ins`?\s+BEFORE\s+INSERT\s+ON\s+`?\{?\$?safe_prefix\}?cashback_payout_requests/i',
            $this->mariadb_src(),
            'create_triggers() должен содержать BEFORE INSERT trigger tr_payout_require_fail_reason_ins'
        );
    }

    public function test_create_triggers_includes_require_fail_reason_update(): void
    {
        $this->assertMatchesRegularExpression(
            '/CREATE TRIGGER\s+`?\{?\$?safe_prefix\}?tr_payout_require_fail_reason_upd`?\s+BEFORE\s+UPDATE\s+ON\s+`?\{?\$?safe_prefix\}?cashback_payout_requests/i',
            $this->mariadb_src(),
            'create_triggers() должен содержать BEFORE UPDATE trigger tr_payout_require_fail_reason_upd'
        );
    }

    public function test_drop_triggers_lists_new_names_for_idempotent_recreate(): void
    {
        $src = $this->mariadb_src();
        $this->assertStringContainsString(
            "'tr_payout_require_fail_reason_ins'",
            $src,
            'drop_triggers list должен содержать tr_payout_require_fail_reason_ins для idempotent recreate'
        );
        $this->assertStringContainsString(
            "'tr_payout_require_fail_reason_upd'",
            $src,
            'drop_triggers list должен содержать tr_payout_require_fail_reason_upd для idempotent recreate'
        );
    }

    /**
     * Возвращает body первого CREATE TRIGGER tr_payout_require_fail_reason_ins
     * (от 'CREATE TRIGGER' до закрывающего "END;" / "END\"" / 'END,').
     */
    private function trigger_body(string $name): string
    {
        $src    = $this->mariadb_src();
        $needle = 'CREATE TRIGGER `{$safe_prefix}' . $name . '`';
        $start  = strpos($src, $needle);
        $this->assertNotFalse($start, "{$needle} должен присутствовать в mariadb.php");
        // ~2000 символов хватит на body одного триггера.
        return substr($src, $start, 2000);
    }

    public function test_trigger_signal_uses_sqlstate_45000(): void
    {
        $body = $this->trigger_body('tr_payout_require_fail_reason_ins');
        $this->assertMatchesRegularExpression(
            "/SIGNAL\s+SQLSTATE\s+'45000'/i",
            $body,
            'триггер должен бросать SIGNAL SQLSTATE 45000 при пустом fail_reason'
        );
        $this->assertMatchesRegularExpression(
            "/MESSAGE_TEXT\s*=\s*'fail_reason\s+required[^']*declined[^']*failed[^']*'/i",
            $body,
            "MESSAGE_TEXT должен содержать осмысленное описание (упоминая 'fail_reason', 'declined' и 'failed')"
        );
    }

    public function test_trigger_condition_covers_declined_and_failed(): void
    {
        $body = $this->trigger_body('tr_payout_require_fail_reason_ins');
        $this->assertMatchesRegularExpression(
            "/NEW\.status\s+IN\s*\(\s*'declined'\s*,\s*'failed'\s*\)/i",
            $body,
            "триггер INSERT должен срабатывать на NEW.status IN ('declined','failed')"
        );
        $body_upd = $this->trigger_body('tr_payout_require_fail_reason_upd');
        $this->assertMatchesRegularExpression(
            "/NEW\.status\s+IN\s*\(\s*'declined'\s*,\s*'failed'\s*\)/i",
            $body_upd,
            "триггер UPDATE должен срабатывать на NEW.status IN ('declined','failed')"
        );
    }

    public function test_trigger_condition_covers_null_and_empty_fail_reason(): void
    {
        $body = $this->trigger_body('tr_payout_require_fail_reason_ins');
        $this->assertMatchesRegularExpression(
            "/NEW\.fail_reason\s+IS\s+NULL\s+OR\s+NEW\.fail_reason\s*=\s*''/i",
            $body,
            'триггер должен покрывать оба пустых случая: IS NULL и пустая строка'
        );
    }

    // =========================================================================
    // Миграция migrate_payout_require_fail_reason_v5 (для existing installs)
    // =========================================================================

    public function test_migration_method_exists(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+function\s+migrate_payout_require_fail_reason_v5\s*\(\s*\)\s*:\s*void/',
            $this->mariadb_src(),
            'метод migrate_payout_require_fail_reason_v5 должен быть public function(): void'
        );
    }

    public function test_migration_idempotent_via_db_version_5(): void
    {
        $src = $this->mariadb_src();
        // Fast-path: if (cashback_db_version >= 5) return;
        $this->assertMatchesRegularExpression(
            "/get_option\(\s*'cashback_db_version'[\s\S]{0,200}?>=\s*5/",
            $src,
            "миграция должна быть идемпотентной: fast-path при cashback_db_version >= 5"
        );
        // Bump в конце
        $this->assertMatchesRegularExpression(
            "/update_option\s*\(\s*'cashback_db_version'\s*,\s*5\s*,\s*false\s*\)/",
            $src,
            'миграция должна поднимать cashback_db_version до 5 в конце'
        );
    }

    /**
     * Возвращает body метода миграции migrate_payout_require_fail_reason_v5
     * (от объявления `public function ...` до конца файла или ~10k символов).
     */
    private function migration_body(): string
    {
        $src   = $this->mariadb_src();
        $start = strpos($src, 'public function migrate_payout_require_fail_reason_v5');
        $this->assertNotFalse(
            $start,
            'declaration `public function migrate_payout_require_fail_reason_v5` должна присутствовать'
        );
        return substr($src, $start, 10000);
    }

    public function test_migration_backfills_legacy_rows(): void
    {
        $body = $this->migration_body();
        // 1. В body миграции есть UPDATE-запрос (через wpdb->query + UPDATE %i).
        $this->assertMatchesRegularExpression(
            "/\\\$wpdb->query\(\\\$wpdb->prepare\(\s*[\"']UPDATE\s+%i/i",
            $body,
            'миграция должна выполнять UPDATE для backfill legacy строк'
        );
        // 2. Целевая таблица — cashback_payout_requests (через $payouts_table).
        $this->assertStringContainsString(
            "cashback_payout_requests",
            $body,
            'миграция должна работать с таблицей cashback_payout_requests'
        );
        // 3. WHERE-условие точное: status IN ('declined','failed') AND пустой fail_reason.
        $this->assertMatchesRegularExpression(
            "/WHERE\s+status\s+IN\s*\(\s*'declined'\s*,\s*'failed'\s*\)\s+AND\s+\(\s*fail_reason\s+IS\s+NULL\s+OR\s+fail_reason\s*=\s*''\s*\)/i",
            $body,
            "миграция должна backfill'ить только legacy строки со статусом declined/failed и пустым fail_reason"
        );
    }

    public function test_migration_drops_then_creates_triggers(): void
    {
        $body = $this->migration_body();
        $this->assertMatchesRegularExpression(
            "/DROP\s+TRIGGER\s+IF\s+EXISTS[\s\S]+?tr_payout_require_fail_reason_ins/i",
            $body,
            'миграция должна DROP TRIGGER IF EXISTS перед CREATE (idempotent)'
        );
        $this->assertMatchesRegularExpression(
            "/CREATE\s+TRIGGER[\s\S]+?tr_payout_require_fail_reason_ins[\s\S]+?BEFORE\s+INSERT/i",
            $body,
            'миграция должна CREATE TRIGGER tr_payout_require_fail_reason_ins'
        );
        $this->assertMatchesRegularExpression(
            "/CREATE\s+TRIGGER[\s\S]+?tr_payout_require_fail_reason_upd[\s\S]+?BEFORE\s+UPDATE/i",
            $body,
            'миграция должна CREATE TRIGGER tr_payout_require_fail_reason_upd'
        );
    }

    public function test_migration_registered_in_activate(): void
    {
        $this->assertStringContainsString(
            '$instance->migrate_payout_require_fail_reason_v5();',
            $this->mariadb_src(),
            'миграция должна вызываться в Mariadb_Plugin::activate() для fresh installs'
        );
    }

    public function test_migration_registered_in_runtime_maybe_run_migrations(): void
    {
        $this->assertMatchesRegularExpression(
            '/migrate_payout_require_fail_reason_v5\s*\(\s*\)/',
            $this->plugin_src(),
            'миграция должна вызываться в CashbackPlugin::maybe_run_migrations() (runtime для existing installs, паттерн F-22-003)'
        );
    }
}
