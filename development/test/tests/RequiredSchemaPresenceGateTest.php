<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression-тест: schema-presence gate для v6/v7 миграционных артефактов.
 *
 * Codex adversarial-review round 11 (2026-05-10): runtime-код в админке
 * напрямую обращается к колонкам и enum-значениям, добавленным миграциями
 * v6 (`ban_reason_admin`) и v7 (`frozen_balance_admin`, ledger.type
 * включает `'payout_unfreeze'`). Если transient DDL failure оставил их
 * unapplied, плагин (после round 10 revert init-gate'а) всё равно
 * инициализируется → admin кликает «Decline payout» → MySQL error 1054
 * «Unknown column 'frozen_balance_admin'» → silent UPDATE failure,
 * deception для оператора.
 *
 * Inventory check (round 7) проверяет ТОЛЬКО имена триггеров, не columns
 * и не enum-values. Этот тест требует отдельного physical-state gate'а:
 *
 *   1. Helper `cashback_check_required_schema_present()` существует.
 *   2. Helper проверяет `ban_reason_admin` (v6), `frozen_balance_admin` (v7),
 *      и enum `'payout_unfreeze'` в `cashback_balance_ledger.type` (v7).
 *   3. `init()` вызывает helper после `cashback_check_triggers_present()` и
 *      ДО `initialize_components()` — на missing artifact делает admin
 *      notice + early return.
 *   4. Helper упомянут в plugin-bootstrap (require'ится тот же файл, где
 *      cashback-triggers-inventory.php — оба относятся к init-gate'у).
 */
#[Group('database')]
#[Group('regression')]
#[Group('schema')]
final class RequiredSchemaPresenceGateTest extends TestCase
{
    private function plugin_src(): string
    {
        $path    = dirname(__DIR__, 3) . '/cashback-plugin.php';
        $content = file_get_contents($path);
        $this->assertIsString($content);
        return $content;
    }

    public function test_helper_function_defined(): void
    {
        $this->assertTrue(
            function_exists('cashback_check_required_schema_present')
                || $this->find_helper_in_includes(),
            'cashback_check_required_schema_present() должен быть определён ' .
            '(в includes/cashback-triggers-inventory.php или отдельном файле).'
        );
    }

    private function find_helper_in_includes(): bool
    {
        $candidates = array(
            dirname(__DIR__, 3) . '/includes/cashback-triggers-inventory.php',
            dirname(__DIR__, 3) . '/includes/cashback-required-schema.php',
        );
        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }
            $src = file_get_contents($path);
            if (preg_match('/function\s+cashback_check_required_schema_present\s*\(/', (string) $src) === 1) {
                return true;
            }
        }
        return false;
    }

    public function test_helper_probes_ban_reason_admin_column(): void
    {
        $src = $this->load_helper_src();

        $this->assertMatchesRegularExpression(
            "/'ban_reason_admin'/",
            $src,
            'helper должен проверять колонку ban_reason_admin (v6 artifact, ' .
            'используется в admin/users-management.php SELECT/UPDATE).'
        );

        $this->assertMatchesRegularExpression(
            "/'cashback_user_profile'/",
            $src,
            'helper должен ссылаться на cashback_user_profile (где живёт ban_reason_admin).'
        );
    }

    public function test_helper_probes_frozen_balance_admin_column(): void
    {
        $src = $this->load_helper_src();

        $this->assertMatchesRegularExpression(
            "/'frozen_balance_admin'/",
            $src,
            'helper должен проверять колонку frozen_balance_admin (v7 artifact, ' .
            'используется в admin/payouts.php при decline/unfreeze).'
        );

        $this->assertMatchesRegularExpression(
            "/'cashback_user_balance'/",
            $src,
            'helper должен ссылаться на cashback_user_balance (где живёт frozen_balance_admin).'
        );
    }

    public function test_helper_probes_payout_unfreeze_enum(): void
    {
        $src = $this->load_helper_src();

        $this->assertMatchesRegularExpression(
            "/payout_unfreeze/",
            $src,
            'helper должен проверять enum-value `payout_unfreeze` в ' .
            'cashback_balance_ledger.type (v7 artifact, используется ledger reversal).'
        );
    }

    public function test_helper_uses_information_schema(): void
    {
        $src = $this->load_helper_src();

        $this->assertMatchesRegularExpression(
            '/information_schema\.COLUMNS/i',
            $src,
            'helper должен probe-ить INFORMATION_SCHEMA.COLUMNS — это authoritative ' .
            'источник физического состояния схемы (не runtime SQL-error).'
        );
    }

    public function test_init_calls_schema_check_before_components(): void
    {
        $src   = $this->plugin_src();
        $start = strpos($src, 'public function init()');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 12000);

        $migrations_pos     = strpos($body, '$this->maybe_run_migrations()');
        $trigger_check_pos  = strpos($body, 'cashback_check_triggers_present(');
        $schema_check_pos   = strpos($body, 'cashback_check_required_schema_present(');
        $components_pos     = strpos($body, '$this->initialize_components()');

        $this->assertNotFalse($schema_check_pos, 'init() должен вызывать cashback_check_required_schema_present()');
        $this->assertNotFalse($migrations_pos);
        $this->assertNotFalse($trigger_check_pos);
        $this->assertNotFalse($components_pos);

        $this->assertLessThan(
            $schema_check_pos,
            $migrations_pos,
            'maybe_run_migrations() должен идти ДО schema-presence check (чтобы свежие колонки попали в проверку).'
        );
        $this->assertLessThan(
            $components_pos,
            $schema_check_pos,
            'schema-presence check должен идти ДО initialize_components() — на missing v6/v7 артефакте плагин не должен регистрировать write-paths.'
        );
    }

    /**
     * Codex round 12 (2026-05-10): init() НЕ должен делать `return;` при
     * schema gap'е v6/v7. Артефакты — admin-only (ban_reason_admin,
     * frozen_balance_admin, payout_unfreeze enum); глобальный disable
     * убивает frontend/cron/webhook (включая prod-критичные пути приёма
     * постбэков от CPA-сетей). Защита должна быть per-handler, не глобальная.
     * Init() ограничивается admin notice + error_log — оператор видит
     * предупреждение, остальной плагин работает.
     */
    public function test_init_does_not_global_disable_on_schema_missing(): void
    {
        $src   = $this->plugin_src();
        $start = strpos($src, 'public function init()');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 12000);

        $check_pos = strpos($body, 'cashback_check_required_schema_present(');
        $this->assertNotFalse($check_pos);

        // Окно от helper-call до initialize_components: между ними НЕ должно
        // быть `return;`. Если есть — init early-exit отключает frontend/cron.
        $components_pos = strpos($body, '$this->initialize_components()');
        $this->assertNotFalse($components_pos);
        $window = substr($body, $check_pos, $components_pos - $check_pos);

        $this->assertDoesNotMatchRegularExpression(
            '/\$schema_error\s*!==\s*null[\s\S]{0,1500}return\s*;/',
            $window,
            'init() НЕ должен делать early `return;` при schema gap (Codex round 12): '
            . 'артефакты admin-only — глобальный disable убивает frontend/cron/webhook. '
            . 'Допустимы admin notice + error_log, но НЕ блокировка initialize_components().'
        );
    }

    public function test_init_still_shows_admin_notice_on_schema_missing(): void
    {
        $src   = $this->plugin_src();
        $start = strpos($src, 'public function init()');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 12000);

        $check_pos = strpos($body, 'cashback_check_required_schema_present(');
        $this->assertNotFalse($check_pos);
        $tail = substr($body, $check_pos, 1500);

        $this->assertMatchesRegularExpression(
            '/admin_notices/',
            $tail,
            'init() должен по-прежнему показывать admin notice при schema gap '
            . '(operator visibility), даже если плагин не блокируется.'
        );
    }

    /**
     * Codex round 12 (2026-05-10): probe сейчас кастит `(int) get_var()` и
     * `(string) get_var()` без проверки `$wpdb->last_error`. На transient
     * INFORMATION_SCHEMA failure (DB lock-wait, restricted access, replication
     * lag) helper false-positively reports artifact as missing → плагин
     * disabled без real schema problem. Probe ДОЛЖЕН проверять last_error
     * и fail-open на query failure.
     */
    public function test_helper_checks_last_error_after_information_schema_probe(): void
    {
        $src = $this->load_helper_src();

        // В теле helper'а после `get_var` должен быть check на $wpdb->last_error.
        $this->assertMatchesRegularExpression(
            '/get_var\([\s\S]{0,500}\$wpdb->last_error/',
            $src,
            'helper должен проверять $wpdb->last_error после каждого get_var() '
            . '(Codex round 12 #2): иначе transient INFORMATION_SCHEMA failure '
            . 'превращается в false-positive «artifact missing» и full-plugin disable.'
        );
    }

    /**
     * Codex round 15 (2026-05-10): enum probe должен различать
     * `last_error !== ''` (transient query failure → fail-open) и
     * `raw === null && last_error === ''` (real missing column/table →
     * SELECT успешен, но 0 строк → артефакт ОТСУТСТВУЕТ).
     *
     * Если оба случая трактовать одинаково (continue → не помечать
     * missing), то на real schema drift гейт пропустит → плагин
     * инициализируется с missing payout_unfreeze enum → unfreeze handler
     * упирается в SQL error на runtime.
     */
    public function test_enum_probe_marks_missing_when_no_error_and_null_raw(): void
    {
        if (!function_exists('cashback_check_required_schema_present')) {
            require_once dirname(__DIR__, 3) . '/includes/cashback-triggers-inventory.php';
        }
        $this->assertTrue(function_exists('cashback_check_required_schema_present'));

        global $wpdb;
        $orig_wpdb = $wpdb ?? null;

        // Fake wpdb: get_var возвращает null, last_error пустой — real
        // missing column scenario (information_schema.COLUMNS не нашёл строки).
        $wpdb = new class('wp_') {
            public string $prefix;
            public string $last_error = '';
            public function __construct( string $prefix ) {
                $this->prefix = $prefix;
            }
            public function prepare( string $query, ...$args ): string {
                return $query;
            }
            public function get_var( string $query ) {
                // Reset last_error (как делает реальный wpdb перед каждым запросом).
                $this->last_error = '';
                // Симулируем successful SELECT с пустым result-set: get_var
                // возвращает null, last_error остаётся пустым.
                return null;
            }
        };

        try {
            $result = cashback_check_required_schema_present();
            $this->assertNotNull(
                $result,
                'Когда last_error пустой и raw=null (real missing column/enum), '
                . 'helper ДОЛЖЕН вернуть non-null с описанием missing-артефактов '
                . '(Codex round 15 #1). Без этого плагин инициализируется на '
                . 'real schema drift — payout_unfreeze enum probe конфликтует '
                . 'с transient probe failure.'
            );
        } finally {
            $wpdb = $orig_wpdb;
        }
    }

    public function test_helper_fails_open_on_probe_failure_behavioral(): void
    {
        if (!function_exists('cashback_check_required_schema_present')) {
            require_once dirname(__DIR__, 3) . '/includes/cashback-triggers-inventory.php';
        }
        $this->assertTrue(function_exists('cashback_check_required_schema_present'));

        global $wpdb;
        $orig_wpdb = $wpdb ?? null;

        // Инжектим fake $wpdb который имитирует probe failure: get_var возвращает
        // null, $wpdb->last_error содержит ошибку. Если helper check'ает last_error
        // — он должен fail-open и вернуть null. Если не check'ает (round 12 baseline)
        // — он false-positively сочтёт artifact missing.
        // Codex round 15 (2026-05-10): probe code resets $wpdb->last_error
        // ДО каждого get_var, поэтому transient failure симулируем тем, что
        // get_var ВНУТРИ ставит last_error (как делает реальный wpdb когда
        // SQL-сервер вернул ошибку).
        $wpdb = new class('wp_') {
            public string $prefix;
            public string $last_error = '';
            public function __construct( string $prefix ) {
                $this->prefix = $prefix;
            }
            public function prepare( string $query, ...$args ): string {
                return $query;
            }
            public function get_var( string $query ) {
                // Real wpdb behavior: на transient SQL error пишет last_error и возвращает null.
                $this->last_error = 'transient: information_schema unavailable';
                return null;
            }
        };

        try {
            $result = cashback_check_required_schema_present();
            $this->assertNull(
                $result,
                'При transient probe failure (last_error непустой) helper '
                . 'должен fail-open и вернуть null — иначе init() с false-positive '
                . 'отключит весь плагин на временном DB-сбое (Codex round 12 #2).'
            );
        } finally {
            $wpdb = $orig_wpdb;
        }
    }

    /**
     * Codex round 16 (2026-05-10): catalog должен использовать НАЗВАННЫЕ
     * artifact-keys (v6_ban_reason_admin, v7_frozen_balance_admin,
     * v7_payout_unfreeze), чтобы handler'ы могли запросить только нужный
     * subset. Aggregate bundle (round 11-15 baseline) приводил к тому что
     * missing payout artifact 503'ил user-admin страницу и vice versa.
     */
    public function test_artifacts_catalog_uses_named_keys(): void
    {
        if (!function_exists('cashback_required_schema_artifacts')) {
            require_once dirname(__DIR__, 3) . '/includes/cashback-triggers-inventory.php';
        }
        $this->assertTrue(function_exists('cashback_required_schema_artifacts'));

        $catalog = cashback_required_schema_artifacts();
        $this->assertIsArray($catalog);

        $expected_keys = array(
            'v6_ban_reason_admin',
            'v7_frozen_balance_admin',
            'v7_payout_unfreeze',
        );
        foreach ($expected_keys as $key) {
            $this->assertArrayHasKey(
                $key,
                $catalog,
                sprintf(
                    'Catalog должен иметь stable named key `%s` — handlers ' .
                    'используют их для subset-фильтрации (Codex round 16 #1).',
                    $key
                )
            );
        }

        // Sequential numeric keys = ad-hoc bundle (round 11 baseline).
        // После round 16 — string-keyed associative.
        foreach (array_keys($catalog) as $key) {
            $this->assertIsString(
                $key,
                'Catalog keys должны быть string (named), не sequential int.'
            );
        }
    }

    public function test_helper_accepts_artifact_keys_subset_behavioral(): void
    {
        if (!function_exists('cashback_check_required_schema_present')) {
            require_once dirname(__DIR__, 3) . '/includes/cashback-triggers-inventory.php';
        }

        // Симулируем: v6 ban_reason_admin отсутствует, v7 frozen_balance_admin
        // и payout_unfreeze enum присутствуют. Через $present_override.
        $override = array(
            'cashback_user_profile' => array(),  // ban_reason_admin missing
            'cashback_user_balance' => array( 'frozen_balance_admin' ),
            '__enum:cashback_balance_ledger.type' => "enum('payout_unfreeze','adjustment')",
        );

        // Запрос только v7-артефактов — не должен жаловаться на v6.
        $result_v7_only = cashback_check_required_schema_present(
            $override,
            array( 'v7_frozen_balance_admin', 'v7_payout_unfreeze' )
        );
        $this->assertNull(
            $result_v7_only,
            'При subset=[v7_*] и присутствующих v7-артефактах, helper должен ' .
            'вернуть null — даже если v6 missing (Codex round 16 #1: payout ' .
            'handler не должен 503 на user-admin schema gap).'
        );

        // Запрос только v6 — должен жаловаться.
        $result_v6_only = cashback_check_required_schema_present(
            $override,
            array( 'v6_ban_reason_admin' )
        );
        $this->assertNotNull(
            $result_v6_only,
            'При subset=[v6_*] и missing ban_reason_admin, helper ДОЛЖЕН ' .
            'вернуть error string.'
        );

        // Полный bundle (default) — тоже жалуется (v6 missing).
        $result_full = cashback_check_required_schema_present($override);
        $this->assertNotNull(
            $result_full,
            'Без subset (полный bundle) — error string когда любой artifact missing.'
        );
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function admin_handler_subset_provider(): array
    {
        return array(
            'render_users_page → v6 only' => array(
                'admin/users-management.php',
                'public function render_users_page(',
                'v6_ban_reason_admin',
            ),
            'handle_update_user_profile → v6 only' => array(
                'admin/users-management.php',
                'public function handle_update_user_profile(',
                'v6_ban_reason_admin',
            ),
            'handle_get_user_profile → v6 only' => array(
                'admin/users-management.php',
                'public function handle_get_user_profile(',
                'v6_ban_reason_admin',
            ),
            'handle_update_payout_request → v7_frozen_balance_admin only' => array(
                'admin/payouts.php',
                'public function handle_update_payout_request(',
                'v7_frozen_balance_admin',
            ),
            'handle_payout_unfreeze → v7 frozen+enum' => array(
                'admin/payouts.php',
                'public function handle_payout_unfreeze(',
                'v7_payout_unfreeze',
            ),
        );
    }

    /**
     * @dataProvider admin_handler_subset_provider
     */
    public function test_admin_handler_passes_specific_artifact_subset(string $relative_path, string $signature, string $expected_key): void
    {
        $path = dirname(__DIR__, 3) . '/' . $relative_path;
        $src  = (string) file_get_contents($path);

        $start = strpos($src, $signature);
        $this->assertNotFalse($start);
        $brace_open = strpos($src, '{', $start);
        $depth      = 0;
        $end        = $brace_open;
        $len        = strlen($src);
        for ($i = $brace_open; $i < $len; $i++) {
            $ch = $src[ $i ];
            if ($ch === '{') {
                ++$depth;
            } elseif ($ch === '}') {
                --$depth;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        $body = substr($src, $brace_open, $end - $brace_open + 1);

        // Каждый handler должен передавать `$expected_key` в helper.
        $needle = sprintf(
            "/cashback_check_required_schema_present\\s*\\([^)]*'%s'[^)]*\\)/",
            preg_quote($expected_key, '/')
        );
        $this->assertMatchesRegularExpression(
            $needle,
            $body,
            sprintf(
                'Handler должен вызывать cashback_check_required_schema_present() ' .
                'с subset=[\'%s\'] (Codex round 16 #1: granular gates вместо bundle).',
                $expected_key
            )
        );
    }

    /**
     * Codex round 13 (2026-05-10): point-of-use guards в admin handlers.
     * Init() ограничен warning-only (round 12), значит каждый handler,
     * который SELECT/UPDATE'ит v6/v7 артефакты, должен сам вызывать
     * cashback_check_required_schema_present() и отказывать в работе при
     * missing schema, иначе runtime SQL error 1054 уйдёт пользователю.
     *
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function admin_handler_guard_provider(): array
    {
        return array(
            'users-management::render_users_page' => array(
                'admin/users-management.php',
                'public function render_users_page(',
                'render_users_page',
            ),
            'users-management::handle_update_user_profile' => array(
                'admin/users-management.php',
                'public function handle_update_user_profile(',
                'handle_update_user_profile',
            ),
            'users-management::handle_get_user_profile' => array(
                'admin/users-management.php',
                'public function handle_get_user_profile(',
                'handle_get_user_profile',
            ),
            'payouts::handle_update_payout_request' => array(
                'admin/payouts.php',
                'public function handle_update_payout_request(',
                'handle_update_payout_request',
            ),
            'payouts::handle_payout_unfreeze' => array(
                'admin/payouts.php',
                'public function handle_payout_unfreeze(',
                'handle_payout_unfreeze',
            ),
        );
    }

    /**
     * Codex round 15 (2026-05-10): handle_update_payout_request обрабатывает
     * множество status transitions (waiting/processing/paid/failed/declined/
     * needs_retry + metadata-edits). Только переход в `declined` использует
     * frozen_balance_admin (v7). Top-level guard блокировал ВСЕ payout edits
     * — operators теряют recovery paths (paid/failed/needs_retry) на missing
     * v7 schema. Guard должен быть scoped к declined branch.
     */
    public function test_payout_handler_guard_scoped_to_declined_branch(): void
    {
        $path = dirname(__DIR__, 3) . '/admin/payouts.php';
        $src  = (string) file_get_contents($path);

        $start = strpos($src, 'public function handle_update_payout_request(');
        $this->assertNotFalse($start);
        $brace_open = strpos($src, '{', $start);
        $depth      = 0;
        $end        = $brace_open;
        $len        = strlen($src);
        for ($i = $brace_open; $i < $len; $i++) {
            $ch = $src[ $i ];
            if ($ch === '{') {
                ++$depth;
            } elseif ($ch === '}') {
                --$depth;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        $body = substr($src, $brace_open, $end - $brace_open + 1);

        $check_pos = strpos($body, 'cashback_check_required_schema_present(');
        $this->assertNotFalse(
            $check_pos,
            'handle_update_payout_request должен по-прежнему вызывать helper '
            . '(в declined branch).'
        );

        // Codex round 15: guard НЕ должен быть сразу после nonce/caps checks
        // (handler entry). Проверяем, что ДО первого вызова helper'а
        // встречается строковый литерал 'declined' — это означает, что guard
        // сидит внутри branch'а, гейтящего по status.
        $head_before_check = substr($body, 0, $check_pos);
        $this->assertMatchesRegularExpression(
            "/'declined'/",
            $head_before_check,
            'Schema-guard в handle_update_payout_request ДОЛЖЕН быть ПОСЛЕ '
            . 'парсинга/валидации `$status === \'declined\'` — без этого '
            . 'top-level guard блокирует все payout edits (paid/failed/'
            . 'needs_retry/metadata) даже когда v7 не нужен (Codex round 15 #2).'
        );
    }

    /**
     * @dataProvider admin_handler_guard_provider
     */
    public function test_admin_handler_calls_required_schema_check(string $relative_path, string $signature, string $method_name): void
    {
        $path = dirname(__DIR__, 3) . '/' . $relative_path;
        $src  = (string) file_get_contents($path);
        $this->assertNotEmpty($src, "Файл {$relative_path} должен быть читаемым");

        $start = strpos($src, $signature);
        $this->assertNotFalse(
            $start,
            sprintf('Метод `%s` должен существовать в %s', $signature, $relative_path)
        );

        // Извлекаем тело метода по балансу `{`/`}`.
        $brace_open = strpos($src, '{', $start);
        $this->assertNotFalse($brace_open);
        $depth = 0;
        $end   = $brace_open;
        $len   = strlen($src);
        for ($i = $brace_open; $i < $len; $i++) {
            $ch = $src[ $i ];
            if ($ch === '{') {
                ++$depth;
            } elseif ($ch === '}') {
                --$depth;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        $body = substr($src, $brace_open, $end - $brace_open + 1);

        // Codex round 16: handler может передавать subset через второй параметр,
        // поэтому регекс допускает любые аргументы внутри скобок.
        $this->assertMatchesRegularExpression(
            '/cashback_check_required_schema_present\s*\(/',
            $body,
            sprintf(
                '%s ДОЛЖЕН вызывать cashback_check_required_schema_present() — '
                . 'без этого missing v6/v7 schema превращается в SQL error 1054 '
                . '«Unknown column» вместо чистого admin error message (Codex round 13 #1).',
                $method_name
            )
        );
    }

    private function load_helper_src(): string
    {
        $candidates = array(
            dirname(__DIR__, 3) . '/includes/cashback-triggers-inventory.php',
            dirname(__DIR__, 3) . '/includes/cashback-required-schema.php',
        );
        $combined = '';
        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }
            $combined .= (string) file_get_contents($path);
        }
        $this->assertNotEmpty(
            $combined,
            'хотя бы один из кандидатов helper-файла должен существовать.'
        );
        return $combined;
    }
}
