<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Structural-тест Mariadb_Plugin::add_user_to_profile() — INSERT в
 * wp_cashback_user_profile должен явно передавать cashback_rate и
 * min_payout_amount из Cashback_User_Defaults, а не полагаться на DB DEFAULT.
 *
 * Это критично: DB DEFAULT в схеме всегда 60.00 / 100.00 (mariadb.php:415,418),
 * глобальное изменение дефолта через admin UI должно достигать новых записей,
 * иначе фича бесполезна.
 */
#[Group('user-defaults')]
final class AddUserToProfileUsesDefaultsTest extends TestCase
{
    private static string $source = '';
    private static string $method_body = '';

    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $path        = $plugin_root . '/mariadb.php';
        self::assertFileExists($path);
        self::$source = (string) file_get_contents($path);
        self::$method_body = self::extract_method('add_user_to_profile');
    }

    private static function extract_method(string $method_name): string
    {
        $tokens = token_get_all(self::$source);
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

    public function test_insert_query_contains_cashback_rate_column(): void
    {
        $this->assertStringContainsString(
            'cashback_rate',
            self::$method_body,
            'add_user_to_profile() INSERT должен явно содержать колонку cashback_rate.'
        );
    }

    public function test_insert_query_contains_min_payout_amount_column(): void
    {
        $this->assertStringContainsString(
            'min_payout_amount',
            self::$method_body,
            'add_user_to_profile() INSERT должен явно содержать колонку min_payout_amount.'
        );
    }

    public function test_uses_cashback_user_defaults_helper(): void
    {
        $uses_snapshot_helper = str_contains(self::$method_body, 'Cashback_User_Defaults::get_new_user_profile_defaults');
        $uses_direct_helpers  = str_contains(self::$method_body, 'Cashback_User_Defaults::get_default_rate')
            && str_contains(self::$method_body, 'Cashback_User_Defaults::get_default_min_payout');

        $this->assertTrue(
            $uses_snapshot_helper || $uses_direct_helpers,
            'add_user_to_profile() должен брать дефолты из Cashback_User_Defaults (напрямую или через snapshot-helper), а не из литерала.'
        );
    }

    public function test_insert_uses_prepared_placeholders(): void
    {
        // INSERT IGNORE INTO %i (user_id, partner_token, cashback_rate, min_payout_amount, status, created_at)
        // VALUES (%d, %s, %s, %s, 'active', UTC_TIMESTAMP())
        // Должно быть %d для user_id, %s для partner_token / rate / min_payout (decimal stored as string).
        $this->assertMatchesRegularExpression(
            '/INSERT\s+IGNORE\s+INTO\s+%i[\s\S]{0,300}cashback_rate[\s\S]{0,200}VALUES\s*\(\s*%d\s*,\s*%s\s*,\s*%s\s*,\s*%s/i',
            self::$method_body,
            'INSERT должен использовать %i для таблицы, %d для user_id, %s для остальных полей.'
        );
    }

    public function test_defensive_fallback_when_helper_class_missing(): void
    {
        // class_exists guard — на случай вызова add_user_to_profile() из bootstrap-фазы
        // до require_once helper'а. Если guard сломан → fatal "Class not found".
        $this->assertMatchesRegularExpression(
            "/class_exists\s*\(\s*['\"]Cashback_User_Defaults['\"]\s*\)/",
            self::$method_body,
            'Должен быть guard class_exists("Cashback_User_Defaults") для defensive fallback.'
        );
        // Hard fallback на исторические литералы.
        $this->assertStringContainsString(
            '60.00',
            self::$method_body,
            'Hard fallback на литерал 60.00 если helper-класс не подключён.'
        );
        $this->assertStringContainsString(
            '100.00',
            self::$method_body,
            'Hard fallback на литерал 100.00 если helper-класс не подключён.'
        );
    }
}
