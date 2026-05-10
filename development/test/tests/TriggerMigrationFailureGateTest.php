<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression-тест: trigger-migration v5/v6/v7 поведение при сбое.
 *
 * История:
 *   - Codex round 7 (2026-05-10): добавлен `cashback_check_triggers_present()`
 *     gate в init() — единственный init-blocker для missing-trigger.
 *   - Codex round 8 (2026-05-10): миграции v5/v6/v7 теперь throw'ят
 *     RuntimeException вместо silent `return;` после `$wpdb->query === false`.
 *     Throws ловятся catch-блоками `maybe_run_migrations()` и логируются
 *     через `register_trigger_failure_notice()` (admin notice).
 *   - Codex round 10 (2026-05-10): дополнительный `$trigger_migration_failed`
 *     gate (round 8/9) ОТКАЗАН — он блокировал плагин на любом transient
 *     SQL-сбое (lock-wait timeout, metadata lock), включая read-only paths.
 *     Inventory check (round 7) — единственный авторитет, потому что
 *     MariaDB CREATE OR REPLACE TRIGGER drop-then-create non-atomic: при
 *     сбое CREATE триггер ОТСУТСТВУЕТ, и inventory check его поймает.
 *
 * Тест проверяет:
 *   1. Миграции v5/v6/v7 throw'ят RuntimeException на `$wpdb->query === false`
 *      (round 8 invariant — без throws catch'и не сработают).
 *   2. catch-блоки v5/v6/v7 в `maybe_run_migrations()` вызывают
 *      `register_trigger_failure_notice($e)` (admin notice persists).
 *   3. catch-блоки НЕ устанавливают флаг и НЕ делают `return;` —
 *      transient throw'ы не должны блокировать остальные миграции и init().
 *   4. init() НЕ имеет дополнительного gate'а на trigger_migration_failed —
 *      только inventory check (round 7) блокирует init.
 */
#[Group('database')]
#[Group('regression')]
#[Group('triggers')]
final class TriggerMigrationFailureGateTest extends TestCase
{
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

    /**
     * Извлекает тело публичного метода Mariadb_Plugin (между `public function NAME()` и
     * закрывающей `}` метода). Простой эвристический парсер по балансу `{`/`}`.
     */
    private function extract_method_body(string $src, string $method_name): string
    {
        $sig = 'public function ' . $method_name . '(';
        $pos = strpos($src, $sig);
        $this->assertNotFalse($pos, "Метод {$method_name} должен существовать в mariadb.php");

        $brace_open = strpos($src, '{', $pos);
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
        return substr($src, $brace_open, $end - $brace_open + 1);
    }

    private function extract_method_body_visibility(string $src, string $signature_prefix): string
    {
        $pos = strpos($src, $signature_prefix);
        $this->assertNotFalse($pos, "Сигнатура `{$signature_prefix}` должна присутствовать");

        $brace_open = strpos($src, '{', $pos);
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
        return substr($src, $brace_open, $end - $brace_open + 1);
    }

    // =========================================================================
    // 1. Миграции v5/v6/v7 throw'ят RuntimeException на query failure (round 8)
    // =========================================================================

    /**
     * @return array<string,array{0:string}>
     */
    public static function migration_methods_provider(): array
    {
        return array(
            'v5 fail_reason' => array( 'migrate_payout_require_fail_reason_v5' ),
            'v6 split_ban'   => array( 'migrate_split_ban_reason_v6' ),
            'v7 unfreeze'    => array( 'migrate_payout_unfreeze_v7' ),
        );
    }

    #[DataProvider('migration_methods_provider')]
    public function test_migration_throws_runtime_exception_on_query_failure(string $method): void
    {
        $body = $this->extract_method_body($this->mariadb_src(), $method);

        $this->assertMatchesRegularExpression(
            '/throw\s+new\s+\\\\?RuntimeException/',
            $body,
            sprintf(
                '%s должен бросать RuntimeException при $wpdb->query === false '
                . '(а не silent return), иначе catch в maybe_run_migrations() '
                . 'не сработает и admin notice не будет показан.',
                $method
            )
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\$\w+_result\s*===\s*false[\s\S]{0,400}error_log\([^)]*\);\s*return;/m',
            $body,
            sprintf(
                'В %s остался silent `return;` после error_log при '
                . 'query-failure. Без `throw` сбой проходит мимо catch.',
                $method
            )
        );
    }

    // =========================================================================
    // 2. catch-блоки v5/v6/v7 регистрируют admin notice (round 8 invariant)
    // =========================================================================

    public function test_v5_v6_v7_catches_register_admin_notice(): void
    {
        $body = $this->extract_method_body_visibility(
            $this->plugin_src(),
            'private function maybe_run_migrations('
        );

        $version_anchors = array(
            'v5' => 'Payout fail_reason trigger',
            'v6' => 'Split ban_reason',
            'v7' => 'Payout unfreeze v7',
        );
        foreach ($version_anchors as $version => $anchor) {
            $needle = sprintf(
                '/%s[\s\S]{0,1500}register_trigger_failure_notice\s*\(\s*\$e\s*\)/',
                preg_quote($anchor, '/')
            );
            $this->assertMatchesRegularExpression(
                $needle,
                $body,
                sprintf(
                    'catch для %s в maybe_run_migrations() должен вызывать '
                    . 'register_trigger_failure_notice($e) — без этого admin '
                    . 'не увидит persistent notice о сбое миграции.',
                    $version
                )
            );
        }
    }

    // =========================================================================
    // 3. Codex round 16 (2026-05-10): family-A cascade abort через flag
    //    (вместо method-level `return;` round 11) — v5/v6/v7 каскадно
    //    защищены от version-skew, но v8+ независим от family-A failure.
    // =========================================================================

    /**
     * Codex round 16 (2026-05-10): round 11 `return;` в catch'ах v5/v6/v7
     * starve'ил v8+ (промокоды, shop importer) на любом trigger-migration
     * failure. v8+ — отдельный feature family, не зависит от v5/v6/v7
     * financial invariants. Правильный design — family-A flag: внутри
     * v5/v6/v7 каскад abort'ится (защита от version-skew, round 11), но
     * v8+ запускается независимо (round 16 #3).
     */
    public function test_family_a_flag_pattern_isolates_v8_plus(): void
    {
        $body = $this->extract_method_body_visibility(
            $this->plugin_src(),
            'private function maybe_run_migrations('
        );

        // v8+ должен запускаться независимо от v5/v6/v7 failure: ищем
        // вызов migrate_promocodes_v8 и проверяем, что между ним и
        // последним catch'ем v7 НЕТ паттерна `return;` верхнего уровня.
        $v7_anchor   = strpos($body, 'Payout unfreeze v7');
        $v8_call     = strpos($body, 'migrate_promocodes_v8');
        $this->assertNotFalse($v7_anchor, 'v7 anchor должен быть');
        $this->assertNotFalse($v8_call, 'v8 call должен быть');

        $window = substr($body, $v7_anchor, $v8_call - $v7_anchor);

        // catch для v7 НЕ должен содержать `return;` верхнего уровня.
        // (Допускается `return;` внутри try-блока самой v7-миграции, но
        // в catch — не должно быть.)
        $this->assertDoesNotMatchRegularExpression(
            '/register_trigger_failure_notice\s*\(\s*\$e\s*\)\s*;[\s\S]{0,200}return\s*;\s*\}/',
            $window,
            'catch для v7 НЕ должен делать `return;` — это блокирует v8+ ' .
            '(промокоды) на любом trigger-migration failure (Codex round 16 #3).'
        );
    }

    public function test_family_a_flag_variable_present(): void
    {
        $body = $this->extract_method_body_visibility(
            $this->plugin_src(),
            'private function maybe_run_migrations('
        );

        // Family-A flag должен быть объявлен и использоваться:
        // declare → caught (set true) → checked before next migration.
        $this->assertMatchesRegularExpression(
            '/\$\w*family\w*\s*=\s*false/',
            $body,
            'maybe_run_migrations() должен иметь family-A flag (например ' .
            '`$family_a_failed = false`) для каскадного abort\'а v5/v6/v7 ' .
            'без блокировки v8+ (Codex round 16 #3).'
        );
    }

    public function test_v5_v6_v7_catches_set_family_flag_for_cascade_abort(): void
    {
        $body = $this->extract_method_body_visibility(
            $this->plugin_src(),
            'private function maybe_run_migrations('
        );

        $version_anchors = array(
            'v5' => 'Payout fail_reason trigger',
            'v6' => 'Split ban_reason',
            'v7' => 'Payout unfreeze v7',
        );
        foreach ($version_anchors as $version => $anchor) {
            // catch блок ДОЛЖЕН устанавливать family-flag после register_*notice.
            $needle = sprintf(
                '/%s[\s\S]{0,1500}register_trigger_failure_notice\s*\(\s*\$e\s*\)\s*;[\s\S]{0,300}\$\w*family\w*\s*=\s*true/',
                preg_quote($anchor, '/')
            );
            $this->assertMatchesRegularExpression(
                $needle,
                $body,
                sprintf(
                    'catch для %s ДОЛЖЕН устанавливать family-flag (например ' .
                    '`$family_a_failed = true`) — это останавливает каскад v5/v6/v7 ' .
                    '(round 11 version-skew), при этом НЕ блокируя v8+ независимые ' .
                    'миграции (round 16).',
                    $version
                )
            );

            // catch НЕ должен делать `return;` метод-уровневый.
            $start = strpos($body, $anchor);
            $this->assertNotFalse($start);
            $catch_window = substr($body, $start, 800);
            $this->assertDoesNotMatchRegularExpression(
                '/register_trigger_failure_notice\s*\(\s*\$e\s*\)\s*;[\s\S]{0,200}return\s*;\s*\}/',
                $catch_window,
                sprintf(
                    'catch для %s НЕ должен делать `return;` (round 11 reverted) — ' .
                    'это блокировало v8+ (промокоды, shop importer) на любом ' .
                    'trigger-migration failure (Codex round 16 #3).',
                    $version
                )
            );

            // Round 8/9 gate-флаг (отдельный property) — остаётся отказанным.
            $this->assertDoesNotMatchRegularExpression(
                '/\$this->trigger_migration_failed\s*=\s*true/',
                $catch_window,
                sprintf(
                    'catch для %s НЕ должен устанавливать $this->trigger_migration_failed — '
                    . 'init-gate отказан в round 10/12. Family-flag — local variable, не property.',
                    $version
                )
            );
        }
    }

    // =========================================================================
    // 4. init() НЕ имеет дополнительного gate'а на trigger_migration_failed
    // =========================================================================

    public function test_init_has_no_trigger_migration_failed_gate(): void
    {
        $src   = $this->plugin_src();
        $start = strpos($src, 'public function init()');
        $this->assertNotFalse($start, 'init() должен существовать');
        $body = substr($src, $start, 12000);

        $this->assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*\$this->trigger_migration_failed/',
            $body,
            'init() НЕ должен иметь gate на $this->trigger_migration_failed — '
            . 'это блокировало бы read-only paths на transient migration throw '
            . '(Codex round 10 availability regression). Inventory check '
            . '(cashback_check_triggers_present, round 7) — единственный init-blocker.'
        );

        // Inventory check (round 7) ОБЯЗАТЕЛЬНО остаётся.
        $this->assertMatchesRegularExpression(
            '/cashback_check_triggers_present\s*\(\s*\)/',
            $body,
            'init() ДОЛЖЕН вызывать cashback_check_triggers_present() — '
            . 'это единственный авторитет на missing-trigger gap (round 7).'
        );
    }

    public function test_trigger_migration_failed_property_removed(): void
    {
        $src = $this->plugin_src();

        $this->assertDoesNotMatchRegularExpression(
            '/(private|protected)\s+(\?bool\s+|bool\s+)?\$trigger_migration_failed/',
            $src,
            'Свойство $trigger_migration_failed должно быть удалено (Codex round 10): '
            . 'gate-pattern reverted, свойство стало dead code.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/(private|protected)\s+\??string\s+\$trigger_migration_failure_message/',
            $src,
            'Свойство $trigger_migration_failure_message должно быть удалено (Codex round 10): '
            . 'gate-pattern reverted.'
        );
    }
}
