<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Admitad /statistics/actions/ отдаёт action_date/click_date в московском
 * naive-времени БЕЗ tz-маркера (подтверждено read-only probe: API
 * action_date='2026-05-15 15:23:28' == кабинет Admitad, при этом
 * webhook-receiver ту же транзакцию хранит как '2026-05-15 12:23:28' UTC).
 *
 * Чтобы строка реконсиляции совпала со строкой вебхука и с инвариантом
 * utc-everywhere, Admitad-API даты конвертируются MSK→UTC при записи.
 * Источник зоны — адаптер (env-agnostic, без хардкода slug в ядре).
 *
 * parse_api_date намеренно НЕ трогаем (его tz-поведение заморожено,
 * shared с EPN/Advcake) — конвертация делается ПОСЛЕ парсинга, точечно,
 * по таймзоне, которую декларирует адаптер сети.
 */
#[Group('api-client')]
#[Group('adapters')]
#[Group('admitad')]
final class AdmitadApiTimezoneToUtcTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        if (!class_exists('Cashback_API_Client')) {
            require_once $root . '/includes/class-cashback-api-client.php';
        }
        if (!interface_exists('Cashback_Network_Adapter_Interface')) {
            require_once $root . '/includes/adapters/interface-cashback-network-adapter.php';
        }
        if (!class_exists('Cashback_Network_Adapter_Base')) {
            require_once $root . '/includes/adapters/abstract-cashback-network-adapter.php';
        }
        if (!class_exists('Cashback_Admitad_Adapter')) {
            require_once $root . '/includes/class-cashback-outbound-http-guard.php';
            require_once $root . '/includes/oauth/class-oauth2-client-credentials-helper.php';
            require_once $root . '/includes/adapters/class-admitad-adapter.php';
        }
        if (!class_exists('Cashback_Epn_Adapter')) {
            require_once $root . '/includes/adapters/class-epn-adapter.php';
        }
    }

    // ── Адаптер декларирует source-зону ──────────────────────────────

    public function test_admitad_adapter_declares_moscow_api_timezone(): void
    {
        $a = new Cashback_Admitad_Adapter();
        self::assertSame('Europe/Moscow', $a->get_api_datetime_timezone());
    }

    public function test_base_default_timezone_is_empty_no_conversion(): void
    {
        // EPN не переопределяет → дефолт базового класса = '' (трактуем как UTC).
        $e = new Cashback_Epn_Adapter();
        self::assertSame('', $e->get_api_datetime_timezone());
    }

    // ── api_datetime_to_utc (private, reflection) ────────────────────

    private function to_utc(?string $mysql, string $tz): ?string
    {
        $m = new ReflectionMethod('Cashback_API_Client', 'api_datetime_to_utc');
        $m->setAccessible(true);
        return $m->invoke(null, $mysql, $tz);
    }

    public function test_moscow_naive_converted_to_utc(): void
    {
        // 15:23:28 МСК (UTC+3) → 12:23:28 UTC — совпадает со строкой webhook-receiver.
        self::assertSame('2026-05-15 12:23:28', $this->to_utc('2026-05-15 15:23:28', 'Europe/Moscow'));
        self::assertSame('2026-05-15 12:09:22', $this->to_utc('2026-05-15 15:09:22', 'Europe/Moscow'));
    }

    public function test_empty_timezone_returns_value_unchanged(): void
    {
        self::assertSame('2026-05-15 15:23:28', $this->to_utc('2026-05-15 15:23:28', ''));
    }

    public function test_null_and_empty_input_passthrough(): void
    {
        self::assertNull($this->to_utc(null, 'Europe/Moscow'));
        self::assertSame('', $this->to_utc('', 'Europe/Moscow'));
    }

    public function test_unparseable_value_not_fatal_returns_input(): void
    {
        self::assertSame('not-a-date', $this->to_utc('not-a-date', 'Europe/Moscow'));
    }

    // ── insert_missing_transaction применяет конверсию ───────────────

    private function insert_missing_body(): string
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/includes/class-cashback-api-client.php'
        );
        $pos = strpos($src, 'function insert_missing_transaction(');
        self::assertIsInt($pos);
        $brace = strpos($src, '{', $pos);
        self::assertIsInt($brace);
        $depth = 0;
        $len   = strlen($src);
        for ($i = $brace; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }
        self::fail('closing brace not found');
    }

    public function test_insert_missing_transaction_converts_both_dates(): void
    {
        $body = $this->insert_missing_body();

        // С v4.4.4 (Codex F-2/F-3) конверсия MSK→UTC инкапсулирована в
        // resolve_api_datetime($candidates, $api_tz), который вызывает
        // api_datetime_to_utc только для naive-строк (не unix-ts).
        self::assertMatchesRegularExpression(
            '/\$action_date_mysql\s*=\s*self::resolve_api_datetime\s*\(/',
            $body,
            'action_date должен резолвиться через resolve_api_datetime (MSK→UTC по зоне адаптера).'
        );
        self::assertMatchesRegularExpression(
            '/\$click_time_mysql\s*=\s*self::resolve_api_datetime\s*\(/',
            $body,
            'click_time должен резолвиться через resolve_api_datetime симметрично action_date.'
        );
        self::assertMatchesRegularExpression(
            '/\$api_tz\s*=\s*\(\s*\$adapter\s+instanceof\s+Cashback_Network_Adapter_Base\s*\)/',
            $body,
            'Зона API должна браться из адаптера (get_api_datetime_timezone).'
        );
    }
}
