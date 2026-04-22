<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * RED-тесты для Группы 8 ADR — Step 3, F-8-005.
 *
 * Проверяют контракт checkpoint-based cron:
 *   - новая таблица `cashback_cron_state` с нужными колонками;
 *   - helper `Cashback_Cron_State` c API begin_run / begin_stage / finish_stage / get_recent_runs;
 *   - `Cashback_API_Cron::run_sync()` генерирует run_id один раз и оборачивает
 *     5 этапов (background_sync, auto_transfer, process_ready, affiliate_pending, check_campaigns)
 *     в begin_stage/finish_stage (success/failed);
 *   - admin-страница `admin/cron-history.php` с add_submenu_page('cashback-overview', ...);
 *   - bootstrap: require_file helper + admin page.
 *
 * Методика: source-string + regex checks (bootstrap не поднимает реальную БД).
 */
#[Group('cron-state')]
final class CronStateCheckpointsTest extends TestCase
{
    private const MARIADB_FILE     = __DIR__ . '/../../../mariadb.php';
    private const CRON_FILE        = __DIR__ . '/../../../includes/class-cashback-api-cron.php';
    private const HELPER_FILE      = __DIR__ . '/../../../includes/class-cashback-cron-state.php';
    private const ADMIN_FILE       = __DIR__ . '/../../../admin/cron-history.php';
    private const BOOTSTRAP_FILE   = __DIR__ . '/../../../cashback-plugin.php';

    private function read(string $path): string
    {
        $src = file_get_contents($path);
        $this->assertIsString($src, 'Source must be readable: ' . $path);
        return $src;
    }

    // ════════════════════════════════════════════════════════════════
    // 1. SCHEMA — cashback_cron_state создаётся в mariadb.php
    // ════════════════════════════════════════════════════════════════

    public function test_cron_state_table_create_statement_exists(): void
    {
        $src = $this->read(self::MARIADB_FILE);

        $this->assertMatchesRegularExpression(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS[^;]*cashback_cron_state/i',
            $src,
            'mariadb.php должен содержать CREATE TABLE IF NOT EXISTS ...cashback_cron_state (F-8-005).'
        );
    }

    public function test_cron_state_table_has_required_columns(): void
    {
        $src = $this->read(self::MARIADB_FILE);
        $pos = stripos($src, 'cashback_cron_state');
        $this->assertIsInt($pos, 'Таблица cashback_cron_state должна быть определена в mariadb.php.');

        $block = substr($src, $pos, 3000);

        foreach (array('run_id', 'stage', 'status', 'started_at', 'finished_at', 'duration_ms', 'metrics_json', 'error_message') as $col) {
            $this->assertMatchesRegularExpression(
                '/`' . preg_quote($col, '/') . '`/',
                $block,
                "Таблица cashback_cron_state должна содержать колонку `{$col}`."
            );
        }

        $this->assertMatchesRegularExpression(
            '/KEY\s+`?\w*run\w*`?\s*\(\s*`run_id`/i',
            $block,
            'Таблица cashback_cron_state должна иметь индекс по run_id.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 2. HELPER — Cashback_Cron_State класс и API
    // ════════════════════════════════════════════════════════════════

    public function test_cron_state_helper_file_exists(): void
    {
        $this->assertFileExists(
            self::HELPER_FILE,
            'includes/class-cashback-cron-state.php должен существовать (F-8-005 helper).'
        );
    }

    public function test_cron_state_helper_class_defines_expected_api(): void
    {
        $this->assertFileExists(self::HELPER_FILE);
        $src = $this->read(self::HELPER_FILE);

        $this->assertMatchesRegularExpression(
            '/class\s+Cashback_Cron_State\b/',
            $src,
            'Файл должен объявлять класс Cashback_Cron_State.'
        );

        // begin_run(): string — генерит UUID v7 для всего запуска
        $this->assertMatchesRegularExpression(
            '/function\s+begin_run\s*\(\s*\)\s*:\s*string/',
            $src,
            'Cashback_Cron_State::begin_run(): string должен существовать.'
        );

        // begin_stage($run_id, $stage): int — INSERT + return id
        $this->assertMatchesRegularExpression(
            '/function\s+begin_stage\s*\(\s*string\s+\$run_id\s*,\s*string\s+\$stage\s*\)\s*:\s*int/',
            $src,
            'Cashback_Cron_State::begin_stage(string $run_id, string $stage): int должен существовать.'
        );

        // finish_stage($state_id, $status, $metrics = [], $error = '')
        $this->assertMatchesRegularExpression(
            '/function\s+finish_stage\s*\(\s*int\s+\$state_id\s*,\s*string\s+\$status\b/',
            $src,
            'Cashback_Cron_State::finish_stage(int $state_id, string $status, ...) должен существовать.'
        );

        // get_recent_runs(int $limit = 50): array — для admin-страницы
        $this->assertMatchesRegularExpression(
            '/function\s+get_recent_runs\s*\(\s*int\s+\$limit\s*=\s*50\s*\)\s*:\s*array/',
            $src,
            'Cashback_Cron_State::get_recent_runs(int $limit = 50): array должен существовать.'
        );
    }

    public function test_cron_state_helper_uses_uuid_v7_generator(): void
    {
        $this->assertFileExists(self::HELPER_FILE);
        $src = $this->read(self::HELPER_FILE);

        $this->assertMatchesRegularExpression(
            '/cashback_generate_uuid7\s*\(/',
            $src,
            'begin_run() должен использовать cashback_generate_uuid7() (существующий helper).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 3. run_sync() ИСПОЛЬЗУЕТ CHECKPOINT-HELPER
    // ════════════════════════════════════════════════════════════════

    public function test_run_sync_generates_run_id_once(): void
    {
        $src = $this->read(self::CRON_FILE);

        $this->assertMatchesRegularExpression(
            '/Cashback_Cron_State\s*::\s*begin_run\s*\(/',
            $src,
            'run_sync() должен один раз вызвать Cashback_Cron_State::begin_run().'
        );

        $count = preg_match_all('/Cashback_Cron_State\s*::\s*begin_run\s*\(/', $src);
        $this->assertSame(
            1,
            $count,
            'begin_run() должен вызываться ровно один раз за run_sync (run_id — общий на все 5 этапов).'
        );
    }

    public function test_run_sync_wraps_all_five_stages(): void
    {
        $src = $this->read(self::CRON_FILE);

        $begin_count = preg_match_all('/Cashback_Cron_State\s*::\s*begin_stage\s*\(/', $src);
        $this->assertGreaterThanOrEqual(
            5,
            $begin_count,
            'begin_stage() должен вызываться минимум 5 раз (по числу этапов cron).'
        );

        $finish_count = preg_match_all('/Cashback_Cron_State\s*::\s*finish_stage\s*\(/', $src);
        $this->assertGreaterThanOrEqual(
            5,
            $finish_count,
            'finish_stage() должен вызываться минимум 5 раз (по числу этапов cron).'
        );
    }

    public function test_run_sync_stage_names_cover_existing_pipeline(): void
    {
        $src = $this->read(self::CRON_FILE);

        // Ожидаемые имена этапов — stable идентификаторы для истории.
        foreach (
            array(
                'background_sync',
                'auto_transfer',
                'process_ready',
                'affiliate_pending',
                'check_campaigns',
            ) as $stage
        ) {
            $this->assertMatchesRegularExpression(
                "/['\"]" . preg_quote($stage, '/') . "['\"]/",
                $src,
                "В run_sync() должен быть stage-маркер '{$stage}' для checkpoint-записи."
            );
        }
    }

    public function test_run_sync_finishes_failed_stage_on_exception(): void
    {
        $src = $this->read(self::CRON_FILE);

        // finish_stage(..., 'failed', ...) должен вызываться из catch-блока.
        $this->assertMatchesRegularExpression(
            "/finish_stage\s*\([^)]*['\"]failed['\"]/s",
            $src,
            "В run_sync() failing-ветка должна вызывать finish_stage(..., 'failed', ...)."
        );

        // И 'success' после успешного этапа.
        $this->assertMatchesRegularExpression(
            "/finish_stage\s*\([^)]*['\"]success['\"]/s",
            $src,
            "В run_sync() успешная ветка должна вызывать finish_stage(..., 'success', ...)."
        );
    }

    public function test_run_sync_preserves_global_lock_and_try_catch(): void
    {
        $src = $this->read(self::CRON_FILE);

        // Lock-схема не ломается.
        $this->assertStringContainsString(
            'Cashback_Lock::acquire',
            $src,
            'run_sync() должен по-прежнему захватывать глобальный Cashback_Lock.'
        );
        $this->assertStringContainsString(
            'Cashback_Lock::release',
            $src,
            'run_sync() должен по-прежнему освобождать глобальный Cashback_Lock в finally.'
        );

        // Aggregated result option — тоже не трогаем.
        $this->assertStringContainsString(
            'cashback_last_sync_result',
            $src,
            'Опция cashback_last_sync_result должна сохраняться для admin-совместимости.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 4. ADMIN PAGE — Cron History
    // ════════════════════════════════════════════════════════════════

    public function test_admin_cron_history_file_exists(): void
    {
        $this->assertFileExists(
            self::ADMIN_FILE,
            'admin/cron-history.php должен существовать (Full Step 3).'
        );
    }

    public function test_admin_cron_history_registers_submenu(): void
    {
        $this->assertFileExists(self::ADMIN_FILE);
        $src = $this->read(self::ADMIN_FILE);

        $this->assertMatchesRegularExpression(
            "/add_submenu_page\s*\(\s*['\"]cashback-overview['\"]/",
            $src,
            "admin/cron-history.php должен регистрировать submenu под 'cashback-overview'."
        );
        $this->assertStringContainsString(
            'manage_options',
            $src,
            "admin/cron-history.php должен требовать capability 'manage_options'."
        );
    }

    public function test_admin_cron_history_uses_shared_pagination(): void
    {
        $this->assertFileExists(self::ADMIN_FILE);
        $src = $this->read(self::ADMIN_FILE);

        $this->assertMatchesRegularExpression(
            '/Cashback_Pagination\s*::\s*render\s*\(/',
            $src,
            'admin/cron-history.php должен использовать общий Cashback_Pagination::render() (project convention).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 5. BOOTSTRAP — require_file для helper и admin-страницы
    // ════════════════════════════════════════════════════════════════

    public function test_bootstrap_requires_cron_state_helper(): void
    {
        $src = $this->read(self::BOOTSTRAP_FILE);

        $this->assertStringContainsString(
            "includes/class-cashback-cron-state.php",
            $src,
            'cashback-plugin.php load_dependencies() должен require_file(class-cashback-cron-state.php).'
        );
    }

    public function test_bootstrap_requires_cron_history_admin(): void
    {
        $src = $this->read(self::BOOTSTRAP_FILE);

        $this->assertStringContainsString(
            'admin/cron-history.php',
            $src,
            'cashback-plugin.php load_dependencies() должен require_file(admin/cron-history.php) под is_admin() веткой.'
        );
    }
}
