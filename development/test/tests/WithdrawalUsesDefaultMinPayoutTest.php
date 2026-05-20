<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Structural-тест CashbackWithdrawal::get_min_payout_amount() — fallback при
 * отсутствии записи в wp_cashback_user_profile должен брать значение из
 * Cashback_User_Defaults (а не из hardcoded литерала 100.00).
 *
 * Race condition: пользователь авторизован, но строка профиля ещё не создана
 * (или была удалена). В этом случае метод должен вернуть актуальный глобальный
 * дефолт, а не legacy 100.00.
 */
#[Group('user-defaults')]
final class WithdrawalUsesDefaultMinPayoutTest extends TestCase
{
    private static string $method_body = '';

    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $path        = $plugin_root . '/cashback-withdrawal.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::$method_body = self::extract_method($source, 'get_min_payout_amount');
    }

    private static function extract_method(string $source, string $method_name): string
    {
        $tokens = token_get_all($source);
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $name = null;
            $j    = $i + 1;
            for (; $j < $count; $j++) {
                if (is_array($tokens[$j])) {
                    if ($tokens[$j][0] === T_WHITESPACE) {
                        continue;
                    }
                    if ($tokens[$j][0] === T_STRING) {
                        $name = $tokens[$j][1];
                    }
                    break;
                }
            }
            if ($name !== $method_name) {
                continue;
            }

            $depth   = 0;
            $body    = '';
            $started = false;
            for ($k = $j + 1; $k < $count; $k++) {
                $t    = $tokens[$k];
                $text = is_array($t) ? $t[1] : $t;
                if (!$started) {
                    if ($text === '{') {
                        $started = true;
                        $depth   = 1;
                        $body   .= $text;
                    }
                    continue;
                }
                $body .= $text;
                if ($text === '{') {
                    $depth++;
                } elseif ($text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return $body;
                    }
                }
            }
            self::fail("Closing brace of {$method_name}() not found");
        }
        self::fail("{$method_name}() not found");
    }

    public function test_calls_get_default_min_payout(): void
    {
        $this->assertStringContainsString(
            'Cashback_User_Defaults::get_default_min_payout',
            self::$method_body,
            'get_min_payout_amount() fallback должен звать Cashback_User_Defaults::get_default_min_payout().'
        );
    }

    public function test_guarded_by_class_exists(): void
    {
        $this->assertMatchesRegularExpression(
            "/class_exists\s*\(\s*['\"]Cashback_User_Defaults['\"]\s*\)/",
            self::$method_body,
            'Должен быть guard class_exists() — defense-in-depth для случая, когда helper не подключён.'
        );
    }

    public function test_hard_fallback_literal_remains(): void
    {
        // Если helper-класс не подключён в bootstrap — fall back на '100.00'.
        $this->assertStringContainsString(
            "'100.00'",
            self::$method_body,
            'Должен сохраняться literal-fallback 100.00 для bootstrap-edge-case.'
        );
    }

    public function test_returns_float(): void
    {
        $this->assertMatchesRegularExpression(
            '/return\s*\(\s*float\s*\)/',
            self::$method_body,
            'Метод должен возвращать float (cast перед return).'
        );
    }
}
