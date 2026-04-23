<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

// Минимальные моки wp_timezone_string / wp_timezone — parse_api_date использует их
// для конвертации Unix-timestamp в локальный MySQL-datetime. Оба определяются
// здесь, потому что bootstrap.php их не содержит, а класс Cashback_API_Client
// упадёт с Call to undefined function, если вызвать parse_api_date через
// рефлексию до определения.
//
// Значение берётся из $GLOBALS['_cb_test_wp_timezone_name'] (string, например
// 'Europe/Moscow'). По умолчанию — UTC (детерминизм в CI без системного TZ).

if (!function_exists('wp_timezone_string')) {
    function wp_timezone_string(): string
    {
        return (string) ( $GLOBALS['_cb_test_wp_timezone_name'] ?? 'UTC' );
    }
}

if (!function_exists('wp_timezone')) {
    function wp_timezone(): DateTimeZone
    {
        return new DateTimeZone((string) ( $GLOBALS['_cb_test_wp_timezone_name'] ?? 'UTC' ));
    }
}

/**
 * Группа 12e ADR — api-client parse_api_date tech migration.
 *
 * Closes F-8-004 (parse_api_date: mutable DateTime + wp_timezone_string()).
 *
 * Цель батча — syntactic hardening:
 *  - DateTime → DateTimeImmutable (неизменяемость даты на всём пути сборки).
 *  - wp_timezone_string() → wp_timezone() (прямое получение DateTimeZone,
 *    без прохода через строку с parsing errors при odd-named TZ).
 *
 * Внешняя логика СОХРАНЯЕТСЯ бит-в-бит (behaviour guard через regression-тесты).
 * Поведенческие баги с timezone-stripping ISO 8601 (когда API возвращает "+03:00",
 * а мы его отбрасываем) осознанно оставлены — это отдельный breaking-change,
 * требующий координации с CPA-адаптерами.
 *
 * Вызов через рефлексию — метод `protected static`, публичный API не расширяем.
 */
#[Group('security')]
#[Group('group12')]
#[Group('api-client')]
#[Group('date-parsing')]
class ApiClientParseDateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        if (!class_exists('Cashback_API_Client')) {
            require_once $plugin_root . '/includes/class-cashback-api-client.php';
        }
    }

    protected function setUp(): void
    {
        // Дефолтный TZ для детерминизма. Переопределяется в тестах, где
        // нужно проверить именно конвертацию UTC→local.
        $GLOBALS['_cb_test_wp_timezone_name'] = 'UTC';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_cb_test_wp_timezone_name']);
    }

    private function invoke( string $date_str ): ?string
    {
        $method = new ReflectionMethod('Cashback_API_Client', 'parse_api_date');
        $method->setAccessible(true);

        $result = $method->invoke(null, $date_str);

        return is_string($result) ? $result : null;
    }

    // =====================================================================
    // Source-grep: технологические маркеры миграции
    // =====================================================================

    private function read_method_body(): string
    {
        $plugin_root = dirname(__DIR__, 3);
        $src         = (string) file_get_contents($plugin_root . '/includes/class-cashback-api-client.php');

        $pos = strpos($src, 'function parse_api_date(');
        self::assertIsInt($pos, 'parse_api_date() должен присутствовать в api-client');

        $brace_open = strpos($src, '{', $pos);
        self::assertIsInt($brace_open, 'Открывающая скобка parse_api_date() не найдена');

        $depth = 0;
        $len   = strlen($src);
        for ($i = $brace_open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace_open, $i - $brace_open + 1);
                }
            }
        }

        self::fail('Закрывающая скобка parse_api_date() не найдена');
    }

    public function test_uses_date_time_immutable_not_mutable_date_time(): void
    {
        $body = $this->read_method_body();

        self::assertMatchesRegularExpression(
            '/\bDateTimeImmutable\b/',
            $body,
            'F-8-004: parse_api_date должен использовать DateTimeImmutable для неизменяемости на пути сборки.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/\bnew\s+DateTime\s*\(/',
            $body,
            'F-8-004: прямое `new DateTime(` заменяется на `new DateTimeImmutable(`.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/\bDateTime::createFromFormat\b/',
            $body,
            'F-8-004: `DateTime::createFromFormat` заменяется на `DateTimeImmutable::createFromFormat`.'
        );
    }

    public function test_uses_wp_timezone_not_wp_timezone_string(): void
    {
        $body = $this->read_method_body();

        self::assertMatchesRegularExpression(
            '/\bwp_timezone\s*\(\s*\)/',
            $body,
            'F-8-004: parse_api_date должен использовать wp_timezone() (DateTimeZone), не wp_timezone_string().'
        );
        self::assertDoesNotMatchRegularExpression(
            '/\bwp_timezone_string\s*\(\s*\)/',
            $body,
            'F-8-004: wp_timezone_string() заменяется на wp_timezone() — прямой DateTimeZone.'
        );
    }

    // =====================================================================
    // Behavioural regression: Unix timestamp (10/13 digits) + timezone conversion
    // =====================================================================

    public function test_unix_timestamp_10_digits_uses_wp_timezone_utc(): void
    {
        $GLOBALS['_cb_test_wp_timezone_name'] = 'UTC';
        // 2024-01-01 00:00:00 UTC
        self::assertSame('2024-01-01 00:00:00', $this->invoke('1704067200'));
    }

    public function test_unix_timestamp_10_digits_converted_to_moscow(): void
    {
        $GLOBALS['_cb_test_wp_timezone_name'] = 'Europe/Moscow';
        // 2024-01-01 00:00:00 UTC = 2024-01-01 03:00:00 Moscow (UTC+3, без DST)
        self::assertSame('2024-01-01 03:00:00', $this->invoke('1704067200'));
    }

    public function test_unix_timestamp_13_digits_milliseconds_converted(): void
    {
        $GLOBALS['_cb_test_wp_timezone_name'] = 'UTC';
        // 2024-01-01 00:00:00.000 UTC = ms-timestamp 1704067200000
        self::assertSame('2024-01-01 00:00:00', $this->invoke('1704067200000'));
    }

    // =====================================================================
    // Behavioural regression: ISO / MySQL / русский формат
    // =====================================================================

    public function test_iso8601_with_t_separator_returns_mysql_format(): void
    {
        self::assertSame('2024-01-15 10:30:00', $this->invoke('2024-01-15T10:30:00'));
    }

    public function test_iso8601_with_timezone_suffix_strips_tz_preserving_wall_time(): void
    {
        // Current behavior: "+03:00" отбрасывается, wall-time сохраняется.
        // Это defense-in-depth-артефакт исторического решения (см. docblock 12e);
        // breaking-fix на реальный TZ-aware parsing требует координации с адаптерами.
        self::assertSame('2024-01-15 10:30:00', $this->invoke('2024-01-15T10:30:00+03:00'));
    }

    public function test_iso8601_with_z_suffix(): void
    {
        self::assertSame('2024-01-15 10:30:00', $this->invoke('2024-01-15T10:30:00Z'));
    }

    public function test_mysql_format_unchanged(): void
    {
        self::assertSame('2024-01-15 10:30:00', $this->invoke('2024-01-15 10:30:00'));
    }

    public function test_russian_format_with_time(): void
    {
        self::assertSame('2024-01-15 10:30:00', $this->invoke('15.01.2024 10:30:00'));
    }

    public function test_russian_format_date_only_defaults_to_midnight(): void
    {
        // Y-m-d format без H:i:s → createFromFormat заполняет текущим H:i:s.
        // Regression-guard: независимо от того, какой сейчас час, date-часть верная.
        $result = $this->invoke('15.01.2024');
        self::assertIsString($result);
        self::assertStringStartsWith('2024-01-15', $result);
    }

    public function test_y_m_d_only(): void
    {
        $result = $this->invoke('2024-01-15');
        self::assertIsString($result);
        self::assertStringStartsWith('2024-01-15', $result);
    }

    // =====================================================================
    // Edge cases
    // =====================================================================

    public function test_empty_string_returns_null(): void
    {
        self::assertNull($this->invoke(''));
    }

    public function test_whitespace_only_returns_null(): void
    {
        self::assertNull($this->invoke('   '));
    }

    public function test_garbage_returns_null(): void
    {
        self::assertNull($this->invoke('not-a-date'));
    }

    public function test_leading_trailing_whitespace_trimmed(): void
    {
        self::assertSame('2024-01-15 10:30:00', $this->invoke('  2024-01-15 10:30:00  '));
    }
}
