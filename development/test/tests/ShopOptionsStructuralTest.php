<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты на Cashback_Shop_Options — фасад WP-опций для shop importer.
 *
 * Класс предоставляет typed getters для пяти опций:
 *   - cashback_guest_display_rate (float 0..100, default 60.0)
 *   - cashback_display_cache_ttl (int sec, default 43200 = 12h)
 *   - cashback_shop_import_batch_size (int, default 100)
 *   - cashback_shop_import_throttle_ms (int, default 200)
 *   - cashback_display_rate_version (int, default 1, bumped при invalidation)
 *
 * @group shop-import
 * @group options
 */
#[Group('shop-import')]
#[Group('options')]
final class ShopOptionsStructuralTest extends TestCase
{
    private static string $plugin_root;
    private static string $options_php;
    private static string $cashback_plugin_php;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root         = dirname(__DIR__, 3);
        self::$options_php         = file_get_contents(self::$plugin_root . '/includes/class-cashback-shop-options.php');
        self::$cashback_plugin_php = file_get_contents(self::$plugin_root . '/cashback-plugin.php');
    }

    // ============================================================
    // 1. Класс существует и имеет правильное имя.
    // ============================================================

    public function test_class_file_exists(): void
    {
        $this->assertFileExists(
            self::$plugin_root . '/includes/class-cashback-shop-options.php',
            'Файл includes/class-cashback-shop-options.php должен существовать'
        );
    }

    public function test_declares_class_cashback_shop_options(): void
    {
        $this->assertMatchesRegularExpression(
            '/class\s+Cashback_Shop_Options/i',
            self::$options_php,
            'Должен быть class Cashback_Shop_Options'
        );
    }

    public function test_uses_strict_types_and_abspath_guard(): void
    {
        $this->assertStringContainsString(
            'declare(strict_types=1);',
            self::$options_php,
            'Файл должен содержать declare(strict_types=1);'
        );
        $this->assertStringContainsString(
            "defined('ABSPATH')",
            self::$options_php,
            'Файл должен содержать ABSPATH guard'
        );
    }

    // ============================================================
    // 2. Константы имён опций.
    // ============================================================

    public function test_defines_option_name_constants(): void
    {
        $required_constants = array(
            'OPT_GUEST_DISPLAY_RATE',
            'OPT_DISPLAY_CACHE_TTL',
            'OPT_IMPORT_BATCH_SIZE',
            'OPT_IMPORT_THROTTLE_MS',
            'OPT_DISPLAY_RATE_VERSION',
        );

        foreach ($required_constants as $const) {
            $this->assertMatchesRegularExpression(
                '/(public\s+)?const\s+' . $const . '\s*=/i',
                self::$options_php,
                "Класс должен содержать const {$const}"
            );
        }
    }

    public function test_constant_values_match_planned_option_names(): void
    {
        $this->assertMatchesRegularExpression(
            "/OPT_GUEST_DISPLAY_RATE\s*=\s*'cashback_guest_display_rate'/",
            self::$options_php
        );
        $this->assertMatchesRegularExpression(
            "/OPT_DISPLAY_CACHE_TTL\s*=\s*'cashback_display_cache_ttl'/",
            self::$options_php
        );
        $this->assertMatchesRegularExpression(
            "/OPT_IMPORT_BATCH_SIZE\s*=\s*'cashback_shop_import_batch_size'/",
            self::$options_php
        );
        $this->assertMatchesRegularExpression(
            "/OPT_IMPORT_THROTTLE_MS\s*=\s*'cashback_shop_import_throttle_ms'/",
            self::$options_php
        );
        $this->assertMatchesRegularExpression(
            "/OPT_DISPLAY_RATE_VERSION\s*=\s*'cashback_display_rate_version'/",
            self::$options_php
        );
    }

    // ============================================================
    // 3. Typed getters: возвращают float / int с дефолтами и clamp.
    // ============================================================

    public function test_get_guest_display_rate_method_signature(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+get_guest_display_rate\s*\(\s*\)\s*:\s*float/i',
            self::$options_php,
            'get_guest_display_rate() должна возвращать float'
        );
    }

    public function test_get_display_cache_ttl_method_signature(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+get_display_cache_ttl\s*\(\s*\)\s*:\s*int/i',
            self::$options_php,
            'get_display_cache_ttl() должна возвращать int'
        );
    }

    public function test_get_import_batch_size_method_signature(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+get_import_batch_size\s*\(\s*\)\s*:\s*int/i',
            self::$options_php
        );
    }

    public function test_advcake_has_safe_default_batch_size(): void
    {
        $this->assertMatchesRegularExpression(
            '/(public\s+)?const\s+DEFAULT_ADVCAKE_BATCH_SIZE\s*=\s*20\s*;/i',
            self::$options_php,
            'Advcake должен иметь отдельный безопасный default batch=20, а не общий batch=100.'
        );
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+get_import_batch_size_for_network\s*\(\s*array\s+\$network\s*\)\s*:\s*int/i',
            self::$options_php,
            'Нужен network-aware getter, чтобы Advcake не наследовал общий batch size.'
        );
        $this->assertMatchesRegularExpression(
            "/slug.*advcake/s",
            self::$options_php,
            'Network-aware getter должен распознавать slug=advcake.'
        );
    }

    public function test_get_import_throttle_ms_method_signature(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+get_import_throttle_ms\s*\(\s*\)\s*:\s*int/i',
            self::$options_php
        );
    }

    public function test_get_display_rate_version_method_signature(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+get_display_rate_version\s*\(\s*\)\s*:\s*int/i',
            self::$options_php
        );
    }

    public function test_bump_display_rate_version_method_signature(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+bump_display_rate_version\s*\(\s*\)\s*:\s*int/i',
            self::$options_php,
            'bump_display_rate_version() должна возвращать int (новое значение)'
        );
    }

    // ============================================================
    // 4. Defaults — проверяем что в коде есть числа из плана.
    // ============================================================

    public function test_default_constants_have_planned_values(): void
    {
        // Default-константы должны быть объявлены с правильными значениями.
        $this->assertMatchesRegularExpression(
            '/(public\s+)?const\s+DEFAULT_GUEST_RATE\s*=\s*60(\.0)?\s*;/i',
            self::$options_php,
            'DEFAULT_GUEST_RATE = 60.0'
        );
        $this->assertMatchesRegularExpression(
            '/(public\s+)?const\s+DEFAULT_CACHE_TTL\s*=\s*43200\s*;/i',
            self::$options_php,
            'DEFAULT_CACHE_TTL = 43200 (12h)'
        );
        $this->assertMatchesRegularExpression(
            '/(public\s+)?const\s+DEFAULT_BATCH_SIZE\s*=\s*100\s*;/i',
            self::$options_php,
            'DEFAULT_BATCH_SIZE = 100'
        );
        $this->assertMatchesRegularExpression(
            '/(public\s+)?const\s+DEFAULT_ADVCAKE_BATCH_SIZE\s*=\s*20\s*;/i',
            self::$options_php,
            'DEFAULT_ADVCAKE_BATCH_SIZE = 20'
        );
        $this->assertMatchesRegularExpression(
            '/(public\s+)?const\s+DEFAULT_THROTTLE_MS\s*=\s*200\s*;/i',
            self::$options_php,
            'DEFAULT_THROTTLE_MS = 200'
        );
    }

    public function test_getters_use_default_constants_or_literals(): void
    {
        // Каждый getter должен использовать либо self::DEFAULT_* либо литерал
        // (литерал допустим как альтернатива, но обычно константа лучше).
        $this->assertMatchesRegularExpression(
            '/get_option\s*\(\s*self::OPT_GUEST_DISPLAY_RATE\s*,\s*(self::DEFAULT_GUEST_RATE|60(\.0)?)\s*\)/',
            self::$options_php,
            'get_guest_display_rate fallback на DEFAULT_GUEST_RATE или 60.0'
        );
        $this->assertMatchesRegularExpression(
            '/get_option\s*\(\s*self::OPT_DISPLAY_CACHE_TTL\s*,\s*(self::DEFAULT_CACHE_TTL|43200)\s*\)/',
            self::$options_php
        );
        $this->assertMatchesRegularExpression(
            '/get_option\s*\(\s*self::OPT_IMPORT_BATCH_SIZE\s*,\s*(self::DEFAULT_BATCH_SIZE|100)\s*\)/',
            self::$options_php
        );
        $this->assertMatchesRegularExpression(
            '/get_option\s*\(\s*self::OPT_IMPORT_THROTTLE_MS\s*,\s*(self::DEFAULT_THROTTLE_MS|200)\s*\)/',
            self::$options_php
        );
    }

    // ============================================================
    // 5. Clamp guest rate в диапазоне 0..100 (sanitize).
    // ============================================================

    public function test_guest_rate_clamps_to_range_0_100(): void
    {
        // Должна быть логика max(0, min(100, $value)) или эквивалент.
        // Минимум: max(0 и min(100 присутствуют в коде getter'а.
        $start = strpos(self::$options_php, 'function get_guest_display_rate');
        $this->assertNotFalse($start);

        $end = strpos(self::$options_php, "\n    public", $start + 1);
        if ($end === false) {
            $end = strpos(self::$options_php, "\n}", $start);
        }
        $body = substr(self::$options_php, $start, $end - $start);

        $this->assertMatchesRegularExpression(
            '/(max\s*\(\s*0|min\s*\(\s*100|max\s*\(\s*0\.0|min\s*\(\s*100\.0)/',
            $body,
            'get_guest_display_rate() должна clamp значение в диапазон 0..100'
        );
    }

    // ============================================================
    // 6. Файл подключён в cashback-plugin.php.
    // ============================================================

    public function test_class_loaded_in_cashback_plugin_php(): void
    {
        $this->assertStringContainsString(
            'class-cashback-shop-options.php',
            self::$cashback_plugin_php,
            'cashback-plugin.php должен подключать includes/class-cashback-shop-options.php через require_file'
        );
    }
}
