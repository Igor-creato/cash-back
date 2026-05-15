<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

// parse_api_date (unix-ts ветка) дергает wp_timezone(); bootstrap её не
// содержит. Guard'ы — на случай запуска этого файла в одиночку (--filter).
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
 * Codex fintech-review v4.4.4 — F-2 + F-3.
 *
 * F-3: fallback action_date/click_time должен выбирать первое УСПЕШНО
 * РАСПАРСЕННОЕ поле, а не первое непустое. Иначе present-but-garbage
 * (напр. Admitad conversion_time=846 — длительность, не дата) блокирует
 * деградацию к настоящим datetime-полям → action_date NULL/перекос hold.
 *
 * F-2: значение, пришедшее как Unix-timestamp, уже абсолютный момент —
 * parse_api_date локализует его через wp_timezone(). Повторная
 * network→UTC конверсия (api_datetime_to_utc) сдвинула бы его на часы,
 * если site-tz != network-tz. Конвертировать ТОЛЬКО naive-строки сети.
 *
 * Контракт инкапсулирован в Cashback_API_Client::resolve_api_datetime().
 */
#[Group('api-client')]
final class ApiClientResolveApiDatetimeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        if (!class_exists('Cashback_API_Client')) {
            require_once $root . '/includes/class-cashback-api-client.php';
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_wp_timezone_name'] = 'UTC';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_cb_test_wp_timezone_name']);
    }

    private function resolve(array $candidates, string $tz): ?string
    {
        $m = new ReflectionMethod('Cashback_API_Client', 'resolve_api_datetime');
        $m->setAccessible(true);
        return $m->invoke(null, $candidates, $tz);
    }

    // ── F-3: первый РАСПАРСЕННЫЙ, не первый непустой ────────────────

    public function test_skips_present_but_unparseable_field(): void
    {
        // conversion_time=846 present но не дата → деградируем к реальному
        // action_date; tz=Moscow → MSK→UTC (15:23:28 → 12:23:28).
        self::assertSame(
            '2026-05-15 12:23:28',
            $this->resolve(array('846', '2026-05-15 15:23:28', ''), 'Europe/Moscow')
        );
    }

    public function test_all_unparseable_returns_null(): void
    {
        self::assertNull($this->resolve(array('846', 'not-a-date', ''), 'Europe/Moscow'));
    }

    public function test_empty_candidates_return_null(): void
    {
        self::assertNull($this->resolve(array('', '', ''), 'Europe/Moscow'));
    }

    public function test_first_parseable_wins_over_later(): void
    {
        self::assertSame(
            '2026-01-10 09:00:00',
            $this->resolve(array('2026-01-10 12:00:00', '2026-01-10 13:00:00'), 'Europe/Moscow')
        );
    }

    // ── F-2: unix-ts НЕ подвергается повторной network→UTC ─────────

    public function test_unix_timestamp_not_double_converted(): void
    {
        $GLOBALS['_cb_test_wp_timezone_name'] = 'UTC';
        // 1704067200 = 2024-01-01 00:00:00 UTC. parse_api_date уже даёт
        // абсолютный момент; network-tz='Europe/Moscow' НЕ должен сдвинуть.
        self::assertSame(
            '2024-01-01 00:00:00',
            $this->resolve(array('1704067200'), 'Europe/Moscow')
        );
    }

    public function test_unix_ts_ms_not_double_converted(): void
    {
        $GLOBALS['_cb_test_wp_timezone_name'] = 'UTC';
        self::assertSame(
            '2024-01-01 00:00:00',
            $this->resolve(array('1704067200000'), 'Europe/Moscow')
        );
    }

    public function test_naive_string_still_converted_msk_to_utc(): void
    {
        self::assertSame(
            '2026-05-15 12:09:22',
            $this->resolve(array('2026-05-15 15:09:22'), 'Europe/Moscow')
        );
    }

    public function test_empty_tz_no_conversion_for_naive(): void
    {
        self::assertSame(
            '2026-05-15 15:23:28',
            $this->resolve(array('2026-05-15 15:23:28'), '')
        );
    }

    // ── insert_missing_transaction делегирует resolver'у ───────────

    public function test_insert_missing_transaction_uses_resolver_for_both(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/includes/class-cashback-api-client.php'
        );
        $pos = strpos($src, 'function insert_missing_transaction(');
        self::assertIsInt($pos);
        $brace = strpos($src, '{', $pos);
        $depth = 0;
        $len   = strlen($src);
        $body  = '';
        for ($i = $brace; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $body = substr($src, $brace, $i - $brace + 1);
                    break;
                }
            }
        }
        self::assertMatchesRegularExpression(
            '/\$action_date_mysql\s*=\s*self::resolve_api_datetime\s*\(/',
            $body,
            'action_date должен резолвиться через resolve_api_datetime (F-2+F-3).'
        );
        self::assertMatchesRegularExpression(
            '/\$click_time_mysql\s*=\s*self::resolve_api_datetime\s*\(/',
            $body,
            'click_time должен резолвиться через resolve_api_datetime симметрично.'
        );
    }
}
