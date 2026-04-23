<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Группа 12f ADR — version-matcher для ALTER-ошибок в claims-db.
 *
 * Closes F-20-001 (claims-db: ALTER TABLE ошибки обрабатывались либо substring-match
 * на "Duplicate" без понимания семантики, либо silent-ignore в migrate_add_*).
 *
 * Сценарий проблемы (до рефактора):
 *  1. add_constraints() ловит только "Duplicate" substring → игнорирует идемпотентные
 *     повторы. Другие ошибки (permission denied, lock wait timeout, syntax) логируются
 *     как "warning" в error_log, но админ их не видит.
 *  2. migrate_add_is_read / _admin: SHOW COLUMNS guard защищает от double-ALTER, но
 *     ошибки САМОГО ALTER (если он всё же упал) игнорируются полностью.
 *
 * Контракт (TDD RED):
 *  1. Приватный helper `is_known_ddl_error(string $error): bool` — классификатор.
 *     true для: пустая строка, 'Duplicate*', 'Check constraint'/'check constraint' (уже есть).
 *  2. Приватный helper `report_migration_error(string $migration, string $error)`:
 *     error_log с префиксом [Claims] + регистрация admin_notices hook.
 *  3. add_constraints() использует is_known_ddl_error (не substring 'Duplicate').
 *  4. migrate_add_is_read / _admin проверяют $wpdb->last_error после ALTER и
 *     эскалируют неизвестные ошибки через report_migration_error.
 *  5. Публичный render_migration_errors_notice (admin_notices callback) с
 *     capability-guard на activate_plugins.
 *  6. Regression: happy-path CREATE TABLE / миграция без ошибок — не регистрируют
 *     admin_notice.
 */
#[Group('security')]
#[Group('group12')]
#[Group('claims')]
#[Group('migration')]
class ClaimsDbMigrationErrorHandlingTest extends TestCase
{
    private string $claims_db_source = '';

    protected function setUp(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $path        = $plugin_root . '/claims/class-claims-db.php';

        self::assertFileExists($path, 'claims-db должен присутствовать');

        $content = file_get_contents($path);
        self::assertNotFalse($content, 'Не удалось прочитать claims-db');
        $this->claims_db_source = $content;
    }

    private function extract_method( string $method_name ): string
    {
        $pattern = '/(?:public|private|protected)\s+(?:static\s+)?function\s+'
            . preg_quote($method_name, '/')
            . '\s*\([^)]*\)(?:\s*:\s*\w+)?\s*\{/';

        if (preg_match($pattern, $this->claims_db_source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            self::fail('Метод ' . $method_name . '() не найден в claims-db');
        }

        $start = (int) $m[0][1];
        $brace = strpos($this->claims_db_source, '{', $start);
        if ($brace === false) {
            self::fail('Нет открывающей скобки у ' . $method_name);
        }

        $depth = 0;
        $len   = strlen($this->claims_db_source);
        for ($i = $brace; $i < $len; $i++) {
            if ($this->claims_db_source[$i] === '{') {
                $depth++;
            } elseif ($this->claims_db_source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($this->claims_db_source, $brace, $i - $brace + 1);
                }
            }
        }

        self::fail('Нет закрывающей скобки у ' . $method_name);
    }

    // =====================================================================
    // 1. Helper is_known_ddl_error присутствует
    // =====================================================================

    public function test_is_known_ddl_error_helper_exists(): void
    {
        self::assertMatchesRegularExpression(
            '/function\s+is_known_ddl_error\s*\(\s*string\s+\$\w+\s*\)\s*:\s*bool/',
            $this->claims_db_source,
            'F-20-001: должен быть helper `is_known_ddl_error(string $error): bool` — '
            . 'классификатор DDL-ошибок (duplicate-key/column, check-constraint).'
        );
    }

    // =====================================================================
    // 2. Helper report_migration_error присутствует
    // =====================================================================

    public function test_report_migration_error_helper_exists(): void
    {
        self::assertMatchesRegularExpression(
            '/function\s+report_migration_error\s*\(\s*string\s+\$\w+\s*,\s*string\s+\$\w+\s*\)\s*:\s*void/',
            $this->claims_db_source,
            'F-20-001: должен быть helper `report_migration_error(string $migration, string $error): void` — '
            . 'единая точка эскалации (error_log + admin_notices).'
        );
    }

    // =====================================================================
    // 3. add_constraints использует новый классификатор
    // =====================================================================

    public function test_add_constraints_uses_classifier(): void
    {
        $body = $this->extract_method('add_constraints');

        self::assertMatchesRegularExpression(
            '/self::is_known_ddl_error\s*\(/',
            $body,
            'F-20-001: add_constraints() должен использовать is_known_ddl_error вместо substring-match на "Duplicate".'
        );

        self::assertDoesNotMatchRegularExpression(
            "/strpos\s*\(\s*\\\$wpdb->last_error\s*,\s*['\"]Duplicate['\"]\s*\)/",
            $body,
            'F-20-001: substring-match на "Duplicate" должен быть удалён из add_constraints в пользу is_known_ddl_error.'
        );
    }

    public function test_add_constraints_escalates_unknown_errors(): void
    {
        $body = $this->extract_method('add_constraints');

        self::assertMatchesRegularExpression(
            '/self::report_migration_error\s*\(/',
            $body,
            'F-20-001: неизвестные ошибки add_constraints должны эскалироваться через report_migration_error.'
        );
    }

    // =====================================================================
    // 4. migrate_add_is_read проверяет ошибки после ALTER
    // =====================================================================

    public function test_migrate_add_is_read_checks_errors(): void
    {
        $body = $this->extract_method('migrate_add_is_read');

        self::assertMatchesRegularExpression(
            '/\$wpdb->last_error/',
            $body,
            'F-20-001: migrate_add_is_read должен читать $wpdb->last_error после ALTER.'
        );
        self::assertMatchesRegularExpression(
            '/self::(is_known_ddl_error|report_migration_error)\s*\(/',
            $body,
            'F-20-001: migrate_add_is_read должен классифицировать ошибку через is_known_ddl_error '
            . 'и эскалировать unexpected через report_migration_error.'
        );
    }

    public function test_migrate_add_is_read_admin_checks_errors(): void
    {
        $body = $this->extract_method('migrate_add_is_read_admin');

        self::assertMatchesRegularExpression(
            '/\$wpdb->last_error/',
            $body,
            'F-20-001: migrate_add_is_read_admin должен читать $wpdb->last_error после ALTER.'
        );
        self::assertMatchesRegularExpression(
            '/self::(is_known_ddl_error|report_migration_error)\s*\(/',
            $body,
            'F-20-001: migrate_add_is_read_admin должен классифицировать/эскалировать как migrate_add_is_read.'
        );
    }

    // =====================================================================
    // 5. Публичный admin_notice callback с capability guard
    // =====================================================================

    public function test_render_migration_errors_notice_exists(): void
    {
        self::assertMatchesRegularExpression(
            '/public\s+static\s+function\s+render_migration_errors_notice\s*\(\s*\)\s*:\s*void/',
            $this->claims_db_source,
            'F-20-001: должен быть публичный callback render_migration_errors_notice для admin_notices.'
        );
    }

    public function test_notice_is_guarded_by_activate_plugins(): void
    {
        $body = $this->extract_method('render_migration_errors_notice');

        self::assertMatchesRegularExpression(
            "/current_user_can\s*\(\s*['\"]activate_plugins['\"]\s*\)/",
            $body,
            'F-20-001: render_migration_errors_notice должен быть guarded через current_user_can(activate_plugins) '
            . '— не раскрывать migration-errors низко-привилегированным ролям.'
        );
    }

    public function test_report_migration_error_registers_admin_notice_once(): void
    {
        $body = $this->extract_method('report_migration_error');

        self::assertMatchesRegularExpression(
            "/add_action\s*\(\s*['\"]admin_notices['\"]/",
            $body,
            'F-20-001: report_migration_error должен регистрировать admin_notices hook.'
        );
        self::assertMatchesRegularExpression(
            '/error_log\s*\(/',
            $body,
            'F-20-001: report_migration_error должен писать в error_log для post-mortem-grep.'
        );
    }

    // =====================================================================
    // 6. Классификатор распознаёт известные ошибки
    // =====================================================================

    public function test_is_known_ddl_error_recognises_duplicate_key_and_column(): void
    {
        $body = $this->extract_method('is_known_ddl_error');

        // Должны упоминаться оба паттерна: "Duplicate" (1060/1061/FK) и check-constraint (1059/3819/3822).
        self::assertMatchesRegularExpression(
            "/['\"]Duplicate['\"]/",
            $body,
            'F-20-001: is_known_ddl_error должен распознавать "Duplicate" (MySQL 1060 column / 1061 key / FK).'
        );
        self::assertMatchesRegularExpression(
            "/['\"](check\s+constraint|Check\s+constraint|already\s+exists)['\"]/",
            $body,
            'F-20-001: is_known_ddl_error должен распознавать "check constraint"/"already exists" (3822/1826).'
        );
    }
}
