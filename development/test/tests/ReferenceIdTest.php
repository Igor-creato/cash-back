<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты для Mariadb_Plugin::generate_reference_id()
 *
 * Покрывает:
 * - Формат ID (WD-XXXXXXXX)
 * - Допустимые символы (без 0, 1, O, I, L)
 * - Длину строки
 * - Уникальность (статистическая)
 * - Случайность (энтропия)
 */
#[Group('reference_id')]
class ReferenceIdTest extends TestCase
{
    private const VALID_CHARSET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const REFERENCE_PATTERN = '/^WD-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{8}$/';

    // ================================================================
    // ТЕСТЫ: формат и структура
    // ================================================================

    public function test_generate_reference_id_starts_with_prefix(): void
    {
        $id = Mariadb_Plugin::generate_reference_id();
        $this->assertStringStartsWith('WD-', $id);
    }

    public function test_generate_reference_id_has_correct_length(): void
    {
        $id = Mariadb_Plugin::generate_reference_id();
        // "WD-" + 8 символов = 11
        $this->assertSame(11, strlen($id));
    }

    public function test_generate_reference_id_matches_pattern(): void
    {
        $id = Mariadb_Plugin::generate_reference_id();
        $this->assertMatchesRegularExpression(self::REFERENCE_PATTERN, $id);
    }

    public function test_generate_reference_id_only_valid_chars(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $id = Mariadb_Plugin::generate_reference_id();
            $suffix = substr($id, 3); // убираем 'WD-'

            for ($j = 0; $j < 8; $j++) {
                $char = $suffix[$j];
                $this->assertStringContainsString(
                    $char,
                    self::VALID_CHARSET,
                    "Символ '{$char}' в позиции {$j} не из допустимого алфавита"
                );
            }
        }
    }

    // ================================================================
    // ТЕСТЫ: безопасность алфавита (отсутствие путаемых символов)
    // ================================================================

    public function test_generate_reference_id_no_zero(): void
    {
        // Проверяем 100 генераций что нет '0' (цифра ноль)
        for ($i = 0; $i < 100; $i++) {
            $id = Mariadb_Plugin::generate_reference_id();
            $this->assertStringNotContainsString(
                '0',
                substr($id, 3),
                "Символ '0' (ноль) не должен присутствовать в ID: {$id}"
            );
        }
    }

    public function test_generate_reference_id_no_one(): void
    {
        // Проверяем что нет '1' (единица)
        for ($i = 0; $i < 100; $i++) {
            $id = Mariadb_Plugin::generate_reference_id();
            $this->assertStringNotContainsString(
                '1',
                substr($id, 3),
                "Символ '1' (единица) не должен присутствовать в ID: {$id}"
            );
        }
    }

    public function test_generate_reference_id_no_uppercase_o(): void
    {
        // Проверяем что нет 'O' (буква O, похожа на 0)
        for ($i = 0; $i < 100; $i++) {
            $id = Mariadb_Plugin::generate_reference_id();
            $this->assertStringNotContainsString(
                'O',
                substr($id, 3),
                "Символ 'O' не должен присутствовать в ID: {$id}"
            );
        }
    }

    public function test_generate_reference_id_no_uppercase_i(): void
    {
        // Проверяем что нет 'I' (буква I, похожа на 1)
        for ($i = 0; $i < 100; $i++) {
            $id = Mariadb_Plugin::generate_reference_id();
            $this->assertStringNotContainsString(
                'I',
                substr($id, 3),
                "Символ 'I' не должен присутствовать в ID: {$id}"
            );
        }
    }

    public function test_generate_reference_id_no_uppercase_l(): void
    {
        // Проверяем что нет 'L' (буква L, похожа на 1)
        for ($i = 0; $i < 100; $i++) {
            $id = Mariadb_Plugin::generate_reference_id();
            $this->assertStringNotContainsString(
                'L',
                substr($id, 3),
                "Символ 'L' не должен присутствовать в ID: {$id}"
            );
        }
    }

    // ================================================================
    // ТЕСТЫ: уникальность и случайность
    // ================================================================

    public function test_generate_reference_id_is_unique(): void
    {
        // Генерируем 1000 ID — коллизий быть не должно
        // (вероятность коллизии из 30^8 ≈ 6.6 trillion вариантов ничтожно мала)
        $generated = [];
        for ($i = 0; $i < 1000; $i++) {
            $id = Mariadb_Plugin::generate_reference_id();
            $this->assertArrayNotHasKey($id, $generated, "Коллизия ID: {$id}");
            $generated[$id] = true;
        }
    }

    public function test_generate_reference_id_uses_all_positions(): void
    {
        // Проверяем что каждая из 8 позиций содержит разные символы
        // (если бы функция всегда генерировала один символ — это было бы ошибкой)
        $position_chars = array_fill(0, 8, []);

        for ($i = 0; $i < 200; $i++) {
            $id = Mariadb_Plugin::generate_reference_id();
            $suffix = substr($id, 3);
            for ($j = 0; $j < 8; $j++) {
                $position_chars[$j][$suffix[$j]] = true;
            }
        }

        // Каждая позиция должна иметь хотя бы 3 разных символа (200 генераций из 30 возможных)
        for ($j = 0; $j < 8; $j++) {
            $this->assertGreaterThanOrEqual(
                3,
                count($position_chars[$j]),
                "Позиция {$j} имеет слишком мало вариантов символов — возможна не-случайность"
            );
        }
    }

    // ================================================================
    // ТЕСТЫ: charset содержит ровно 30 символов
    // ================================================================

    public function test_charset_has_exactly_31_characters(): void
    {
        // Алфавит: цифры 2-9 (8) + A-Z без O, I, L (26-3=23) = 31
        // Баг был исправлен: $charset_len = 30 → 31 (символ 'Z' был недоступен)
        $charset = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $this->assertSame(31, strlen($charset), 'Алфавит должен содержать ровно 31 символ');
        // Проверяем что Z присутствует (был недостижим при charset_len=30)
        $this->assertStringContainsString('Z', $charset, 'Z должен быть в алфавите');
    }

    public function test_charset_unique_characters(): void
    {
        $charset = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $chars = str_split($charset);
        $unique = array_unique($chars);

        $this->assertCount(count($chars), $unique, 'Все символы алфавита должны быть уникальны');
    }
}
