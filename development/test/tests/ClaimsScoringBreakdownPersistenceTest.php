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

    public function test_migration_uses_raw_query_not_prepare_with_i_placeholder(): void
    {
        // Bug fix: $wpdb->prepare('ALTER TABLE %i ...') отвергается wpdb-валидатором
        // на некоторых WP версиях с длинными COMMENT + non-ASCII. Рабочие миграции
        // (migrate_f22_003_attribution_model, create_tables) используют raw query
        // с инлайн-интерполяцией $wpdb->prefix.
        $method = $this->extract_method($this->claims_db_source, 'migrate_add_scoring_breakdown');

        // ALTER TABLE не должен оборачиваться в $wpdb->prepare (избегаем %i-рантайм-отвержения).
        self::assertDoesNotMatchRegularExpression(
            '/\$wpdb->prepare\s*\(\s*[\'"]ALTER\s+TABLE\s+%i/i',
            $method,
            'ALTER TABLE должен быть raw query (без prepare/%i) — см. migrate_f22_003 pattern.'
        );

        // ALTER TABLE должен быть raw SQL с backticks (инлайн или через промежуточную
        // переменную) — pattern migrate_f22_003: "ALTER TABLE `{$table}` ADD COLUMN ...".
        self::assertMatchesRegularExpression(
            '/[\'"]ALTER\s+TABLE\s+`/i',
            $method,
            'ALTER TABLE должен использовать raw SQL с backticks вокруг $table (migrate_f22_003 pattern).'
        );

        // $wpdb->query() должен вызываться где-то в методе (строка или переменная).
        self::assertMatchesRegularExpression(
            '/\$wpdb->query\s*\(/',
            $method,
            'Миграция должна вызывать $wpdb->query() для ALTER TABLE.'
        );
    }

    public function test_migration_post_verifies_column_presence(): void
    {
        // Bug fix: error_log("added") не должен срабатывать, если ALTER реально не
        // создал колонку. Нужен второй SHOW COLUMNS после ALTER для проверки.
        $method = $this->extract_method($this->claims_db_source, 'migrate_add_scoring_breakdown');

        $count = preg_match_all('/SHOW\s+COLUMNS\s+FROM/i', $method);
        self::assertGreaterThanOrEqual(
            2,
            $count,
            'migrate_add_scoring_breakdown должна post-verify: 1-й SHOW COLUMNS = guard, 2-й = проверка что ALTER реально прошёл.'
        );
    }

    public function test_has_scoring_breakdown_column_helper_exists(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        if (!class_exists('Cashback_Claims_DB')) {
            require_once $plugin_root . '/claims/class-claims-db.php';
        }

        self::assertTrue(
            method_exists('Cashback_Claims_DB', 'has_scoring_breakdown_column'),
            'Cashback_Claims_DB должен экспонировать has_scoring_breakdown_column(): bool.'
        );
    }

    public function test_create_is_tolerant_to_missing_column(): void
    {
        // Defense-in-depth: если миграция не прошла (например, DDL silently failed
        // на старой WP версии), create() не должен падать. scoring_breakdown
        // добавляется в insert_data ТОЛЬКО если колонка существует.
        $method = $this->extract_method($this->claims_mgr_source, 'create');

        self::assertMatchesRegularExpression(
            '/Cashback_Claims_DB::has_scoring_breakdown_column\s*\(\s*\)/',
            $method,
            'create() должен проверять наличие колонки через has_scoring_breakdown_column() перед добавлением в insert_data.'
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
        // Ищем в create() поле scoring_breakdown с wp_json_encode($scoring[breakdown]).
        // Поддерживаем оба синтаксиса: array-literal (=>) и index-assign ([...] = ...),
        // т.к. conditional include (см. test_create_is_tolerant_to_missing_column)
        // использует второй.
        $method = $this->extract_method($this->claims_mgr_source, 'create');

        self::assertMatchesRegularExpression(
            '/(?:[\'"]scoring_breakdown[\'"]\s*=>|\[\s*[\'"]scoring_breakdown[\'"]\s*\]\s*=)\s*wp_json_encode\s*\(\s*\$scoring\s*\[\s*[\'"]breakdown[\'"]\s*\]/',
            $method,
            'create() должен сохранять $scoring[breakdown] через wp_json_encode в поле scoring_breakdown.'
        );

        // format для scoring_breakdown — '%s' (TEXT column).
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
    // UX fix: строка «Риск» инвертируется на render (label остаётся «Риск»,
    // но цифра 0 = чистый юзер, 100 = критический; цвета флипнуты).
    // Storage в breakdown['risk'] — без изменений (score_risk_factor * 100,
    // «высокое = чистый», консистентно с остальными 4 факторами в формуле).
    // =====================================================================

    public function test_risk_row_inverts_display_value(): void
    {
        // Source-grep: в render_claim_detail должен быть расчёт вида `100 - ...`
        // применённый к breakdown['risk'] или к переменной, полученной из него.
        self::assertMatchesRegularExpression(
            '/100(?:\.0)?\s*-\s*(?:\(\s*float\s*\)\s*)?\$\w+(?:\s*\[\s*[\'"]risk[\'"]\s*\])?/',
            $this->claims_admin_source,
            'render_claim_detail должен инвертировать risk-значение: `100 - $raw` (или аналог).'
        );

        // Проверяем что инверсия применяется именно к risk-ключу: должна
        // быть ветка `$key === \'risk\'` (или аналогичная), в которой
        // появляется выражение `100 -`.
        self::assertMatchesRegularExpression(
            '/\$key\s*===?\s*[\'"]risk[\'"]/',
            $this->claims_admin_source,
            'Инверсия должна быть ограничена ключом risk (branch по `$key === \'risk\'`).'
        );
    }

    public function test_risk_row_uses_inverted_color_thresholds(): void
    {
        // В risk-ветке цвет ≥70 должен быть красным #d63638, а не зелёным #2a8f2a.
        // Ищем: сначала `$key === 'risk'` блок, затем в пределах ~400 символов
        // после — выражение `>= 70` связанное с красным.
        if (preg_match(
            '/\$key\s*===?\s*[\'"]risk[\'"].*?\}/s',
            $this->claims_admin_source,
            $m
        ) !== 1) {
            self::fail('Не нашёл блок `if ($key === \'risk\')` в render_claim_detail.');
        }
        $risk_branch = $m[0];

        self::assertMatchesRegularExpression(
            '/>=?\s*70[^?]*\?\s*[\'"]#d63638[\'"]/',
            $risk_branch,
            'В risk-ветке ≥70 должен соответствовать красному #d63638 (флип цвета).'
        );
    }

    public function test_other_rows_keep_standard_color_direction(): void
    {
        // В else-ветке (не risk) ≥70 должен оставаться зелёным #2a8f2a — как и раньше.
        // Ищем любой фрагмент, где >=70 ? '#2a8f2a' (зелёный) — это признак того,
        // что для non-risk факторов цветовая схема не изменилась.
        self::assertMatchesRegularExpression(
            '/>=?\s*70[^?]*\?\s*[\'"]#2a8f2a[\'"]/',
            $this->claims_admin_source,
            'Для non-risk факторов ≥70 остаётся зелёным #2a8f2a (схема не меняется).'
        );
    }

    public function test_storage_semantics_unchanged_in_scoring(): void
    {
        // Regression guard: breakdown['risk'] в class-claims-scoring.php всё ещё
        // хранится как $risk_score * 100, где $risk_score = 1 - user_risk_score/100
        // (т.е. high = clean). Инверсия — только на render, storage не тронут.
        $plugin_root = dirname(__DIR__, 3);
        $scoring_src = (string) file_get_contents($plugin_root . '/claims/class-claims-scoring.php');

        self::assertMatchesRegularExpression(
            '/[\'"]risk[\'"]\s*=>\s*round\s*\(\s*\$risk_score\s*\*\s*100/',
            $scoring_src,
            'breakdown[\'risk\'] должен оставаться $risk_score * 100 (storage не меняется, инверсия только в render).'
        );

        self::assertMatchesRegularExpression(
            '/1(?:\.0)?\s*-\s*\(\s*\$risk\s*\/\s*100(?:\.0)?\s*\)/',
            $scoring_src,
            'score_risk_factor должен сохранять формулу 1 - risk/100 (high = clean).'
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
