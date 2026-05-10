<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression-тест: trigger inventory gate в CashbackPlugin::init().
 *
 * Codex adversarial-review round 7 (2026-05-10): после удаления
 * Cashback_Trigger_Fallbacks (round 5) плагин полагается на DB-триггеры для
 * финансовых инвариантов. Если recreate_triggers() упал в maybe_run_migrations
 * (или триггеры физически отсутствуют по любой другой причине), плагин раньше
 * продолжал инициализацию и регистрировал write-paths без schema-уровневой
 * защиты — silent corruption window.
 *
 * Тест проверяет:
 *   1. Helper `cashback_required_triggers()` — список из 18 имён, синхронен
 *      с массивом `$triggers` в [mariadb.php](mariadb.php).
 *   2. Helper `cashback_check_triggers_present()` — корректно отличает
 *      complete inventory от missing.
 *   3. CashbackPlugin::init() имеет gate ПОСЛЕ maybe_run_migrations и ДО
 *      initialize_components: на missing-trigger делает admin notice +
 *      early return.
 */
#[Group('database')]
#[Group('regression')]
#[Group('triggers')]
final class TriggerInventoryGateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/cashback-triggers-inventory.php';
    }

    private function plugin_src(): string
    {
        $path    = dirname(__DIR__, 3) . '/cashback-plugin.php';
        $content = file_get_contents($path);
        $this->assertIsString($content, 'cashback-plugin.php must be readable');
        return $content;
    }

    private function mariadb_src(): string
    {
        $path    = dirname(__DIR__, 3) . '/mariadb.php';
        $content = file_get_contents($path);
        $this->assertIsString($content, 'mariadb.php must be readable');
        return $content;
    }

    // =========================================================================
    // 1. cashback_required_triggers() синхронен с create_triggers() DDL
    // =========================================================================

    public function test_required_triggers_list_is_18_names(): void
    {
        $required = cashback_required_triggers();
        $this->assertCount(
            18,
            $required,
            'Список обязательных триггеров должен содержать ровно 18 имён '
            . '(должно совпадать с create_triggers DDL в mariadb.php)'
        );
        $this->assertSame(
            array_unique($required),
            $required,
            'Все имена триггеров должны быть уникальными'
        );
    }

    public function test_required_triggers_match_mariadb_create_ddl(): void
    {
        $src = $this->mariadb_src();
        // Извлекаем все имена триггеров из CREATE OR REPLACE TRIGGER массива в create_triggers()
        preg_match_all(
            '/CREATE OR REPLACE TRIGGER `\{?\$?safe_prefix\}?([a-z_]+)`/',
            $src,
            $matches
        );
        $ddl_triggers = array_unique($matches[1] ?? array());
        sort($ddl_triggers);

        $required = cashback_required_triggers();
        sort($required);

        $this->assertSame(
            $required,
            $ddl_triggers,
            'cashback_required_triggers() должен совпадать с триггерами в '
            . 'mariadb.php::create_triggers() (sync invariant — иначе gate '
            . 'будет требовать триггеры которые DDL не создаёт)'
        );
    }

    // =========================================================================
    // 2. cashback_check_triggers_present() — behavioral
    // =========================================================================

    public function test_returns_null_when_all_triggers_present(): void
    {
        global $wpdb;
        // Mock $wpdb->prefix если не установлен.
        if (!isset($wpdb->prefix)) {
            $wpdb        = new stdClass();
            $wpdb->prefix = 'wp_';
        }

        $required_full = array_map(
            static fn( string $n ): string => $wpdb->prefix . $n,
            cashback_required_triggers()
        );

        $result = cashback_check_triggers_present($required_full);
        $this->assertNull(
            $result,
            'При наличии всех 18 триггеров helper должен вернуть null'
        );
    }

    public function test_returns_error_when_status_validation_trigger_missing(): void
    {
        global $wpdb;
        if (!isset($wpdb->prefix)) {
            $wpdb        = new stdClass();
            $wpdb->prefix = 'wp_';
        }

        $required_full = array_map(
            static fn( string $n ): string => $wpdb->prefix . $n,
            cashback_required_triggers()
        );
        // Удаляем status-validation триггер из present-list — симулируем
        // ручной DROP TRIGGER оператором.
        $present = array_filter(
            $required_full,
            static fn( string $name ): bool => strpos($name, 'cashback_tr_validate_status_transition') === false
        );

        $result = cashback_check_triggers_present(array_values($present));
        $this->assertIsString(
            $result,
            'При отсутствии status-validation триггера helper должен вернуть error string'
        );
        $this->assertStringContainsString(
            'missing',
            $result,
            'Error message должен упоминать missing-триггеры'
        );
        $this->assertStringContainsString(
            'cashback_tr_validate_status_transition',
            $result,
            'Error message должен называть конкретный отсутствующий триггер'
        );
    }

    public function test_returns_error_when_all_triggers_missing(): void
    {
        $result = cashback_check_triggers_present(array());
        $this->assertIsString($result);
        $this->assertStringContainsString(
            '18 of 18',
            $result,
            'При полном отсутствии триггеров message должен показать 18 of 18 missing'
        );
    }

    public function test_message_mentions_financial_invariants(): void
    {
        $result = cashback_check_triggers_present(array());
        $this->assertIsString($result);
        // Сообщение должно ссылаться на schema-level защиту — это критично для
        // оператора чтобы понять опасность игнорирования gate'а.
        $this->assertStringContainsString(
            'financial integrity',
            $result,
            'Error message должен явно упоминать schema-level financial integrity guards'
        );
        $this->assertStringContainsString(
            'Re-activate',
            $result,
            'Error message должен указывать на re-activate как путь восстановления'
        );
    }

    // =========================================================================
    // 3. CashbackPlugin::init() gate structural
    // =========================================================================

    public function test_init_gate_calls_inventory_check_after_migrations(): void
    {
        $src   = $this->plugin_src();
        $start = strpos($src, 'public function init()');
        $this->assertNotFalse($start, 'init() должен существовать');
        $body = substr($src, $start, 8000);

        $migrations_pos = strpos($body, '$this->maybe_run_migrations()');
        $check_pos      = strpos($body, 'cashback_check_triggers_present(');
        $components_pos = strpos($body, '$this->initialize_components()');

        $this->assertNotFalse(
            $migrations_pos,
            'init() должен вызывать $this->maybe_run_migrations()'
        );
        $this->assertNotFalse(
            $check_pos,
            'init() должен вызывать cashback_check_triggers_present() — '
            . 'без этого Codex round 7 gap остаётся открытым'
        );
        $this->assertNotFalse(
            $components_pos,
            'init() должен вызывать $this->initialize_components()'
        );

        $this->assertLessThan(
            $check_pos,
            $migrations_pos,
            'maybe_run_migrations() должен идти ДО trigger inventory check '
            . '(чтобы свежие триггеры из миграции были учтены)'
        );
        $this->assertLessThan(
            $components_pos,
            $check_pos,
            'trigger inventory check должен идти ДО initialize_components() — '
            . 'если триггеры missing, write-paths НЕ должны регистрироваться'
        );
    }

    public function test_init_gate_returns_early_on_inventory_failure(): void
    {
        $src   = $this->plugin_src();
        $start = strpos($src, 'public function init()');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 8000);

        // После trigger check должен быть `return;` для early-exit.
        $check_pos = strpos($body, 'cashback_check_triggers_present(');
        $this->assertNotFalse($check_pos);
        $tail = substr($body, $check_pos, 1500);

        $this->assertMatchesRegularExpression(
            "/return\s*;/",
            $tail,
            'gate должен делать early return при missing-trigger — '
            . 'без этого initialize_components() запустится несмотря на error'
        );
        $this->assertMatchesRegularExpression(
            "/admin_notices/",
            $tail,
            'gate должен показывать admin notice (visible UX, не silent log)'
        );
    }

    public function test_helper_required_in_plugin(): void
    {
        $this->assertMatchesRegularExpression(
            "#require_once\s+__DIR__\s*\.\s*'/includes/cashback-triggers-inventory\.php'#",
            $this->plugin_src(),
            'cashback-plugin.php должен подключать includes/cashback-triggers-inventory.php'
        );
    }
}
