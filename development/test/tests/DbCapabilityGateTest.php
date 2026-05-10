<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Gate на MariaDB 10.1.4+ при активации, в init() и в runtime maybe_run_migrations.
 *
 * Контекст (Codex adversarial-review 2026-05-10): после перехода на
 * `CREATE OR REPLACE TRIGGER` плагин зависит от MariaDB-only синтаксиса.
 * Если БД — MySQL (любая версия) или MariaDB <10.1.4, триггеры не создадутся,
 * и schema-level защита финансовых инвариантов (payout immutability,
 * fail_reason invariant, status transitions, ban freeze/unfreeze) тихо
 * исчезнет.
 *
 * Тест проверяет:
 *   1. Константа CASHBACK_MIN_MARIADB_VERSION = '10.1.4' определена.
 *   2. Функция cashback_check_db_capabilities() парсит VERSION() корректно
 *      на разных форматах (включая MySQL-compat fake-prefix '5.5.5-').
 *   3. Activation hook (cashback_check_requirements) hard-fail'ит wp_die.
 *   4. CashbackPlugin::init() блокирует initialize_components() на
 *      несовместимой БД (защита existing-installs где БД мигрировали под
 *      живым плагином без re-activation).
 *   5. maybe_run_migrations() (runtime path) тоже имеет guard как defense-in-depth.
 */
#[Group('database')]
#[Group('activation')]
final class DbCapabilityGateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Загружаем helper-файл изолированно — без cashback-plugin.php (у того
        // много side-effect'ов на load).
        require_once dirname(__DIR__, 3) . '/includes/cashback-db-capability.php';
    }

    private function plugin_src(): string
    {
        $path    = dirname(__DIR__, 3) . '/cashback-plugin.php';
        $content = file_get_contents($path);
        $this->assertIsString($content, 'cashback-plugin.php must be readable');
        return $content;
    }

    // =========================================================================
    // Структурные проверки исходника
    // =========================================================================

    public function test_min_mariadb_version_constant_defined(): void
    {
        $this->assertMatchesRegularExpression(
            "/define\(\s*'CASHBACK_MIN_MARIADB_VERSION'\s*,\s*'10\.1\.4'\s*\)/",
            $this->plugin_src(),
            "CASHBACK_MIN_MARIADB_VERSION должна быть = '10.1.4' "
            . '(минимальная версия с CREATE OR REPLACE TRIGGER)'
        );
    }

    public function test_capability_helper_required_in_plugin(): void
    {
        $this->assertMatchesRegularExpression(
            "#require_once\s+__DIR__\s*\.\s*'/includes/cashback-db-capability\.php'#",
            $this->plugin_src(),
            'cashback-plugin.php должен подключать includes/cashback-db-capability.php '
            . '(функция нужна и в activation hook, и в init())'
        );
    }

    public function test_capability_check_function_signature(): void
    {
        $this->assertTrue(
            function_exists('cashback_check_db_capabilities'),
            'cashback_check_db_capabilities() должна быть объявлена'
        );

        $reflection = new ReflectionFunction('cashback_check_db_capabilities');
        $this->assertCount(
            1,
            $reflection->getParameters(),
            'функция должна принимать опциональный $version_override для unit-тестов'
        );
        $return_type = $reflection->getReturnType();
        $this->assertNotNull($return_type, 'функция должна иметь объявленный return type');
        $this->assertSame(
            '?string',
            (string) $return_type,
            'функция должна возвращать ?string (null = совместимо, string = ошибка)'
        );
    }

    public function test_activation_check_calls_db_capabilities(): void
    {
        $body = $this->source_between(
            'function cashback_check_requirements()',
            "register_activation_hook(__FILE__, 'cashback_check_requirements')"
        );
        $this->assertMatchesRegularExpression(
            "/cashback_check_db_capabilities\s*\(\s*\)/",
            $body,
            'cashback_check_requirements() (activation hook) должен вызывать cashback_check_db_capabilities()'
        );
        $this->assertMatchesRegularExpression(
            "/wp_die\(/",
            $body,
            'activation check должен hard-fail через wp_die при несовместимой БД'
        );
    }

    public function test_init_gate_blocks_initialize_components(): void
    {
        $src   = $this->plugin_src();
        $start = strpos($src, 'public function init()');
        $this->assertNotFalse($start, 'CashbackPlugin::init должен существовать');
        $end_marker = '$this->initialize_components();';
        $end        = strpos($src, $end_marker, $start);
        $this->assertNotFalse($end, '$this->initialize_components() должен вызываться в init()');

        $head = substr($src, $start, $end - $start);

        // Gate должен проверить capabilities ДО initialize_components.
        $this->assertMatchesRegularExpression(
            "/cashback_check_db_capabilities\s*\(\s*\)/",
            $head,
            'init() должен вызывать cashback_check_db_capabilities() ДО initialize_components(). '
            . 'Codex finding #1 (2026-05-10): иначе хуки/AJAX/REST зарегистрируются '
            . 'на стейле schema когда миграции пропущены.'
        );
        $this->assertMatchesRegularExpression(
            "/admin_notices/",
            $head,
            'init() gate должен показывать admin notice при отказе'
        );
        $this->assertMatchesRegularExpression(
            "/return\s*;/",
            $head,
            'init() gate должен делать early return — без этого плагин '
            . 'зарегистрирует хуки и побежит на стейле schema'
        );
    }

    public function test_init_gate_unregisters_dependent_plugins_loaded_callbacks(): void
    {
        // Codex adversarial-review #2 (2026-05-10): early return из init() оставляет
        // зарегистрированные __construct'ом plugins_loaded callbacks с приоритетами
        // 100/110/115/118. Cashback_Encryption и Cashback_Cron_State подгружаются
        // только из load_dependencies() — на gate-fail классы не определены и
        // callback падает в 'Class not found' fatal вместо graceful admin notice.
        // Тест проверяет что все 4 callback'а явно сняты remove_action перед return.
        $src   = $this->plugin_src();
        $start = strpos($src, 'public function init()');
        $this->assertNotFalse($start, 'init() должен существовать');
        $body = substr($src, $start, 4000);

        $required_unregistrations = array(
            'Cashback_Encryption.*migrate_plaintext_options'  => 'class из load_dependencies()',
            'CashbackPlugin.*migrate_schema_idempotency_v1'   => 'safety-cleanup',
            'CashbackPlugin.*migrate_rate_limit_v1'           => 'safety-cleanup',
            'Cashback_Cron_State.*migrate_v1'                 => 'class из load_dependencies()',
        );

        foreach ($required_unregistrations as $callback_pattern => $reason) {
            $this->assertMatchesRegularExpression(
                "/remove_action\(\s*'plugins_loaded'\s*,[\s\S]*?{$callback_pattern}/",
                $body,
                "init() gate должен снимать plugins_loaded callback {$callback_pattern} ({$reason})"
            );
        }
    }

    public function test_init_gate_is_request_scoped_not_permanent(): void
    {
        // Codex adversarial-review round 5 #2 (2026-05-10): gate срабатывает
        // per-request на основе runtime-state (`SELECT VERSION()`), который
        // может временно фальшивить при connection-blip. Permanent side-effect
        // (например wp_clear_scheduled_hook) от транзиентного сбоя стирает
        // schedule навсегда — БД восстановилась, но GC-cron больше не работает
        // до ручного re-activate.
        //
        // Поэтому gate должен делать ТОЛЬКО request-scoped remove_action,
        // НЕ wp_clear_scheduled_hook. На следующем successful request handler
        // регистрируется заново через __construct → cron восстанавливается
        // автоматически.
        $src   = $this->plugin_src();
        $start = strpos($src, 'public function init()');
        $this->assertNotFalse($start, 'init() должен существовать');
        $body = substr($src, $start, 4000);

        // Снят handler — request-scoped, чтобы in-process вызов не упал на missing class.
        $this->assertMatchesRegularExpression(
            "/remove_action\(\s*'cashback_rate_limit_gc_cron'\s*,[\s\S]*?rate_limit_gc_cron_handler/",
            $body,
            'init() gate должен снимать handler cashback_rate_limit_gc_cron (request-scoped)'
        );

        // Не должно быть wp_clear_scheduled_hook — это permanent state mutation
        // от runtime-probe, что превращает транзиентный сбой в постоянный outage.
        $this->assertDoesNotMatchRegularExpression(
            "/wp_clear_scheduled_hook\(\s*'cashback_rate_limit_gc_cron'\s*\)/",
            $body,
            'init() gate НЕ должен вызывать wp_clear_scheduled_hook — это permanent '
            . 'side-effect от runtime-probe, превращающий транзиентный DB-сбой в '
            . 'постоянный outage GC-cron (Codex round 5 finding #2)'
        );
    }

    public function test_runtime_guard_in_maybe_run_migrations(): void
    {
        $src   = $this->plugin_src();
        $start = strpos($src, 'private function maybe_run_migrations');
        $this->assertNotFalse($start, 'maybe_run_migrations должен существовать в cashback-plugin.php');
        $head = substr($src, $start, 1500);

        $this->assertMatchesRegularExpression(
            "/cashback_check_db_capabilities\s*\(\s*\)/",
            $head,
            'maybe_run_migrations() должен иметь defense-in-depth guard'
        );
    }

    // =========================================================================
    // Behavioral: парсер версии на разных форматах VERSION()
    // (Codex finding #2 — 2026-05-10: regex /^\d+\.\d+\.\d+/ ловил fake-prefix
    //  '5.5.5-' и завернул бы compat-сервер. Якорим на токен MariaDB.)
    // =========================================================================

    /**
     * @return array<string, array{0:string, 1:bool, 2?:string}>
     *   - key — описание сценария
     *   - [0] — version string из SELECT VERSION()
     *   - [1] — true если ожидаем null (совместимо), false если ожидаем error
     *   - [2] — опционально: фрагмент текста ошибки для assertion
     */
    public static function version_string_provider(): array
    {
        return array(
            // === COMPATIBLE: должен вернуть null ===
            'MariaDB 11.8.2 — production deploy формат' => array(
                '11.8.2-MariaDB-1:11.8.2+maria~ubu2404',
                true,
            ),
            'MariaDB 10.5.13 — компактный формат с suffix' => array(
                '10.5.13-MariaDB-1:10.5.13+maria~focal',
                true,
            ),
            'MariaDB 10.6.4 — без build suffix' => array(
                '10.6.4-MariaDB',
                true,
            ),
            'MariaDB 12.0.0 — будущая major версия' => array(
                '12.0.0-MariaDB',
                true,
            ),
            'MariaDB 10.5.13 с MySQL-compat fake-prefix (Codex finding #2)' => array(
                '5.5.5-10.5.13-MariaDB-1:10.5.13+maria~focal',
                true,
            ),
            'MariaDB 11.0.1 с fake-prefix' => array(
                '5.5.5-11.0.1-MariaDB',
                true,
            ),
            'MariaDB 10.1.4 — точно граничная версия (минимум)' => array(
                '10.1.4-MariaDB',
                true,
            ),

            // === INCOMPATIBLE: MySQL ===
            'MySQL 8.0.32' => array(
                '8.0.32',
                false,
                'detected MySQL or unknown database',
            ),
            'MySQL 5.7.42 с suffix' => array(
                '5.7.42-log',
                false,
                'detected MySQL or unknown database',
            ),

            // === INCOMPATIBLE: MariaDB слишком старая ===
            'MariaDB 10.0.38 — ниже минимума 10.1.4' => array(
                '10.0.38-MariaDB',
                false,
                'requires MariaDB 10.1.4',
            ),
            'MariaDB 10.1.3 — на 1 patch ниже минимума' => array(
                '10.1.3-MariaDB',
                false,
                'requires MariaDB 10.1.4',
            ),
            'MariaDB 5.5.0 (legacy)' => array(
                '5.5.0-MariaDB',
                false,
                'requires MariaDB 10.1.4',
            ),
            'MariaDB 10.0.38 с fake-prefix' => array(
                '5.5.5-10.0.38-MariaDB',
                false,
                'requires MariaDB 10.1.4',
            ),

            // === EDGE: пустой и unparseable ===
            'пустая строка (SELECT VERSION() failure)' => array(
                '',
                false,
                'cannot detect database server version',
            ),
            'строка с MariaDB-токеном но без распознаваемой версии' => array(
                'MariaDB-build-info',
                false,
                'cannot parse MariaDB version',
            ),
        );
    }

    #[DataProvider('version_string_provider')]
    public function test_capability_check_parses_version_correctly(
        string $version,
        bool $expect_compatible,
        string $expected_error_fragment = ''
    ): void {
        $result = cashback_check_db_capabilities($version);

        if ($expect_compatible) {
            $this->assertNull(
                $result,
                "версия '{$version}' должна быть распознана как совместимая (null), получено: "
                . var_export($result, true)
            );
        } else {
            $this->assertIsString(
                $result,
                "версия '{$version}' должна быть отклонена со строкой ошибки, получено null"
            );
            if ($expected_error_fragment !== '') {
                $this->assertStringContainsString(
                    $expected_error_fragment,
                    $result,
                    "ошибка для '{$version}' должна содержать '{$expected_error_fragment}'"
                );
            }
        }
    }

    public function test_mysql_compat_prefix_does_not_false_negative(): void
    {
        // Главный edge case из Codex finding #2: старая регулярка /^\d+\.\d+\.\d+/
        // ловила бы '5.5.5' из MySQL-compat префикса и сравнивала бы с минимумом
        // 10.1.4 → отказала бы в активации совместимому серверу.
        $this->assertNull(
            cashback_check_db_capabilities('5.5.5-10.5.13-MariaDB-1:10.5.13+maria~focal'),
            "MariaDB 10.5.13 с MySQL-compat fake-prefix '5.5.5-' должна распознаваться "
            . 'как совместимая (Codex adversarial-review 2026-05-10, finding #2)'
        );
    }

    /**
     * Возвращает срез исходника между двумя якорями.
     */
    private function source_between(string $start_anchor, string $end_anchor): string
    {
        $src   = $this->plugin_src();
        $start = strpos($src, $start_anchor);
        $end   = strpos($src, $end_anchor, $start === false ? 0 : $start);
        $this->assertNotFalse($start, "anchor '{$start_anchor}' не найден");
        $this->assertNotFalse($end, "anchor '{$end_anchor}' не найден");
        return substr($src, $start, $end - $start);
    }
}
