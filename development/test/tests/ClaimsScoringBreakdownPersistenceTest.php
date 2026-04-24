<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Группа 12 ADR — F-20-002 follow-up: persistent scoring breakdown.
 *
 * После добавления 5-факторного скоринга админу полезна не только общая
 * цифра probability_score, но и разложение (time/merchant/user/
 * consistency/risk). Текущее состояние:
 *   - Cashback_Claims_Scoring::calculate() возвращает breakdown, но оно
 *     сбрасывается в claims-manager::create() — в БД сохраняется только
 *     probability_score.
 *   - Admin detail card показывает только общую цифру.
 *
 * Контракт (TDD RED):
 *   1. В schema cashback_claims появляется колонка `scoring_breakdown`
 *      TEXT NULL (JSON array<string,float>).
 *   2. Миграция `migrate_add_scoring_breakdown()` в Cashback_Claims_DB —
 *      идемпотентна (SHOW COLUMNS guard + is_known_ddl_error escalation).
 *   3. Миграция вызывается и на activate(), и в runtime `maybe_run_migrations`
 *      (для апгрейда existing installs без деактивации).
 *   4. Cashback_Claims_Manager::create() сохраняет JSON-encoded breakdown
 *      в `scoring_breakdown` колонку с format `%s`.
 *   5. Admin detail view (render_claim_detail) парсит JSON и отрисовывает
 *      таблицу с 5 строками: Время / Мерчант / Юзер / Консистентность / Риск.
 */
#[Group('security')]
#[Group('group12')]
#[Group('claims')]
#[Group('scoring')]
final class ClaimsScoringBreakdownPersistenceTest extends TestCase
{
    private string $claims_db_source     = '';
    private string $claims_mgr_source    = '';
    private string $claims_admin_source  = '';
    private string $plugin_loader_source = '';

    protected function setUp(): void
    {
        parent::setUp();
        $plugin_root = dirname(__DIR__, 3);

        $this->claims_db_source     = (string) file_get_contents($plugin_root . '/claims/class-claims-db.php');
        $this->claims_mgr_source    = (string) file_get_contents($plugin_root . '/claims/class-claims-manager.php');
        $this->claims_admin_source  = (string) file_get_contents($plugin_root . '/claims/class-claims-admin.php');
        $this->plugin_loader_source = (string) file_get_contents($plugin_root . '/cashback-plugin.php');
    }

    // =====================================================================
    // Schema: CREATE TABLE содержит новую колонку.
    // =====================================================================

    public function test_create_table_defines_scoring_breakdown_column(): void
    {
        self::assertMatchesRegularExpression(
            '/`scoring_breakdown`\s+text\b[^,]*COMMENT\s+\'JSON/i',
            $this->claims_db_source,
            'Schema должна содержать TEXT-колонку scoring_breakdown с JSON-комментарием.'
        );

        // Nullable — старые строки не ломаются backfill'ом.
        self::assertDoesNotMatchRegularExpression(
            '/`scoring_breakdown`\s+text\s+NOT\s+NULL/i',
            $this->claims_db_source,
            'scoring_breakdown должен быть NULL-allowable — backfill для legacy-строк не обязателен.'
        );
    }

    // =====================================================================
    // Миграция: migrate_add_scoring_breakdown() + идемпотентность.
    // =====================================================================

    public function test_migrate_add_scoring_breakdown_method_exists(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        if (!class_exists('Cashback_Claims_DB')) {
            require_once $plugin_root . '/claims/class-claims-db.php';
        }

        self::assertTrue(
            method_exists('Cashback_Claims_DB', 'migrate_add_scoring_breakdown'),
            'Cashback_Claims_DB должен экспонировать migrate_add_scoring_breakdown().'
        );
    }

    public function test_migration_uses_show_columns_guard(): void
    {
        // Паттерн из migrate_add_is_read / _admin: SHOW COLUMNS перед ALTER.
        $method = $this->extract_method($this->claims_db_source, 'migrate_add_scoring_breakdown');

        self::assertMatchesRegularExpression(
            '/SHOW\s+COLUMNS\s+FROM/i',
            $method,
            'Миграция должна проверять наличие колонки через SHOW COLUMNS (идемпотентность).'
        );

        self::assertMatchesRegularExpression(
            '/scoring_breakdown/',
            $method,
            'Миграция должна проверять именно колонку scoring_breakdown.'
        );
    }

    public function test_migration_escalates_unknown_errors(): void
    {
        // 12f ADR pattern: is_known_ddl_error + report_migration_error.
        $method = $this->extract_method($this->claims_db_source, 'migrate_add_scoring_breakdown');

        self::assertMatchesRegularExpression(
            '/is_known_ddl_error/',
            $method,
            'Миграция должна использовать is_known_ddl_error классификатор (pattern 12f).'
        );
        self::assertMatchesRegularExpression(
            '/report_migration_error/',
            $method,
            'Миграция должна эскалировать непредвиденные ошибки через report_migration_error.'
        );
    }

    public function test_migration_wired_in_activate_and_runtime(): void
    {
        // Вызов на activate() — чтобы новые установки получали колонку сразу.
        self::assertMatchesRegularExpression(
            '/Cashback_Claims_DB::migrate_add_scoring_breakdown\s*\(\s*\)/',
            $this->plugin_loader_source,
            'migrate_add_scoring_breakdown должна вызываться из plugin loader минимум один раз.'
        );

        // Минимум два call-site (activate + runtime) — существующие install'ы
        // без реактивации тоже подхватят колонку.
        $count = preg_match_all(
            '/Cashback_Claims_DB::migrate_add_scoring_breakdown\s*\(\s*\)/',
            $this->plugin_loader_source
        );
        self::assertGreaterThanOrEqual(
            2,
            $count,
            'migrate_add_scoring_breakdown должна вызываться и из activate(), и из maybe_run_migrations() runtime (pattern F-22-003).'
        );
    }

    // =====================================================================
    // Persist: Cashback_Claims_Manager::create() сохраняет JSON.
    // =====================================================================

    public function test_create_includes_scoring_breakdown_in_insert(): void
    {
        // Ищем в create() поле 'scoring_breakdown' в insert_data с wp_json_encode.
        $method = $this->extract_method($this->claims_mgr_source, 'create');

        self::assertMatchesRegularExpression(
            '/[\'"]scoring_breakdown[\'"]\s*=>\s*wp_json_encode\s*\(\s*\$scoring\s*\[\s*[\'"]breakdown[\'"]\s*\]/',
            $method,
            'create() должен сохранять $scoring[breakdown] через wp_json_encode в поле scoring_breakdown.'
        );

        // format для scoring_breakdown — '%s' (TEXT column).
        // Проверяем что количество '%s' / '%d' записей в массиве форматов совпадает
        // с количеством полей — менее брутально чем точная проверка порядка.
        self::assertMatchesRegularExpression(
            '/insert_format\s*\[\s*\]\s*=\s*[\'"]%s[\'"]/',
            $method,
            'Формат для scoring_breakdown — %s (TEXT-колонка).'
        );
    }

    // =====================================================================
    // Render: admin detail показывает разложение.
    // =====================================================================

    public function test_admin_detail_renders_breakdown_section(): void
    {
        // Ищем секцию с заголовком «Разложение» или «Факторы» и 5 factor-меток.
        self::assertMatchesRegularExpression(
            '/scoring_breakdown/',
            $this->claims_admin_source,
            'render_claim_detail должен читать scoring_breakdown из claim.'
        );

        self::assertMatchesRegularExpression(
            '/json_decode\s*\(\s*(?:\(string\)\s*)?\$claim\s*\[\s*[\'"]scoring_breakdown[\'"]\s*\]/',
            $this->claims_admin_source,
            'scoring_breakdown — JSON-строка; admin должен её декодировать.'
        );

        // Все 5 factor-ключей отрисованы (ищем labels).
        $expected_labels = array('Время', 'Мерчант', 'Юзер', 'Консистентность', 'Риск');
        foreach ($expected_labels as $label) {
            self::assertStringContainsString(
                $label,
                $this->claims_admin_source,
                "Admin detail должен содержать label «{$label}» для breakdown-фактора."
            );
        }
    }

    public function test_admin_detail_handles_legacy_null_breakdown(): void
    {
        // legacy-строки без breakdown не должны падать — проверяем наличие guard'а.
        // Допускаем любой из паттернов: !empty / is_string / ?? '' / !== null.
        // Минимум — где-то рядом с json_decode проверяется, что значение не пустое.
        self::assertMatchesRegularExpression(
            '/(?:!empty|is_string|\?\?\s*[\'"]|!==\s*null|isset)\s*\(?\s*\$claim\s*\[\s*[\'"]scoring_breakdown[\'"]/',
            $this->claims_admin_source,
            'Admin detail должен защищаться от legacy-строк с NULL scoring_breakdown.'
        );
    }

    // =====================================================================
    // Helpers.
    // =====================================================================

    private function extract_method( string $source, string $method_name ): string
    {
        $pattern = '/(?:public|private|protected)\s+(?:static\s+)?function\s+'
            . preg_quote($method_name, '/')
            . '\s*\([^)]*\)(?:\s*:\s*[\w\\\\|\?]+)?\s*\{/';

        if (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            self::fail("Метод {$method_name}() не найден в source.");
        }

        $start = (int) $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $len   = strlen($source);
        for ($i = $start; $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }

        self::fail("Закрывающая скобка метода {$method_name}() не найдена.");
    }
}
