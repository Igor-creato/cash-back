<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard: сравнения `expires_at` со временем должны быть **UTC-консистентны**.
 *
 * Production incident (post-commit 54f98a0): колонка `expires_at` в
 * `cashback_click_sessions` пишется через `gmdate('Y-m-d H:i:s.u', ...)` (UTC),
 * но SELECT-ы сравнивали её с `NOW()` — MariaDB возвращает server local time.
 * На серверах с timezone != UTC dedup всегда падал → каждый клик = новая сессия,
 * промежуточная страница активации не находила row → redirect на home_url.
 *
 * Fix: использовать `UTC_TIMESTAMP()` (и `UTC_TIMESTAMP(6)` для микросекунд)
 * в SELECT/UPDATE, которые оперируют `expires_at` / `last_tap_at`.
 *
 * Этот тест fail'ит CI при попытке вернуть `NOW()` рядом с `expires_at`
 * (в продакшен-файлах потока). Разрешены:
 *   - `UTC_TIMESTAMP()` / `UTC_TIMESTAMP(6)` — корректно;
 *   - `DATE_SUB(UTC_TIMESTAMP(), ...)` — корректно (для threshold-расчётов);
 *   - `NOW()` в `get_spam_stats` и аналитике (там сравнивается с `created_at`
 *     DEFAULT CURRENT_TIMESTAMP — local-to-local, внутренне консистентно) —
 *     вне scope этого guard'а.
 */
#[Group('security')]
#[Group('f-2-001')]
#[Group('timezone-consistency')]
final class SessionTimezoneConsistencyTest extends TestCase
{
    public static function production_files(): array
    {
        $root = dirname(__DIR__, 3);
        return array(
            'service'      => array( $root . '/includes/class-cashback-click-session-service.php' ),
            'wc_affiliate' => array( $root . '/wc-affiliate-url-params.php' ),
            'rest_api'     => array( $root . '/includes/class-cashback-rest-api.php' ),
        );
    }

    #[DataProvider('production_files')]
    public function test_no_expires_at_vs_now( string $path ): void
    {
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        // Ловим `expires_at > NOW(` и `expires_at >= NOW(` (с любыми пробелами).
        // UTC_TIMESTAMP разрешён.
        if (preg_match_all('/expires_at\s*>=?\s*NOW\s*\(/i', $content, $matches, PREG_OFFSET_CAPTURE) === 0) {
            $this->assertTrue(true);
            return;
        }

        $report = array();
        foreach ($matches[0] as $m) {
            [$text, $offset] = $m;
            $line = substr_count(substr($content, 0, $offset), "\n") + 1;
            $report[] = sprintf('  %s:%d — "%s"', basename($path), $line, trim($text));
        }

        self::fail(
            "expires_at сравнивается с NOW() — это server local time, а expires_at пишется "
            . "через gmdate() (UTC). Используй UTC_TIMESTAMP() для timezone-safe сравнения.\n"
            . implode("\n", $report)
        );
    }

    #[DataProvider('production_files')]
    public function test_last_tap_at_uses_utc( string $path ): void
    {
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        // `last_tap_at = NOW()` или `last_tap_at = NOW(6)` — неконсистентно с gmdate-written expires_at.
        if (preg_match_all('/last_tap_at\s*=\s*NOW\s*\(/i', $content, $matches, PREG_OFFSET_CAPTURE) === 0) {
            $this->assertTrue(true);
            return;
        }

        $report = array();
        foreach ($matches[0] as $m) {
            [$text, $offset] = $m;
            $line = substr_count(substr($content, 0, $offset), "\n") + 1;
            $report[] = sprintf('  %s:%d — "%s"', basename($path), $line, trim($text));
        }

        self::fail(
            "last_tap_at пишется через NOW() — если в будущем появится SELECT по last_tap_at "
            . "против UTC_TIMESTAMP() — будет timezone mismatch. Используй UTC_TIMESTAMP(6).\n"
            . implode("\n", $report)
        );
    }

    /**
     * Positive assertion: сервис фактически использует UTC_TIMESTAMP в критичных местах.
     * Страховка от случайного массового rollback через sed/replace.
     */
    public function test_service_uses_utc_timestamp_in_dedup_lookup(): void
    {
        $path = dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php';
        $src  = (string) file_get_contents($path);

        self::assertMatchesRegularExpression(
            '/expires_at\s*>\s*UTC_TIMESTAMP\s*\(\s*\)/i',
            $src,
            'SELECT FOR UPDATE dedup в service должен использовать UTC_TIMESTAMP() — защита от timezone bug.'
        );
        self::assertMatchesRegularExpression(
            '/last_tap_at\s*=\s*UTC_TIMESTAMP\s*\(\s*6\s*\)/i',
            $src,
            'UPDATE last_tap_at должен писать UTC (UTC_TIMESTAMP(6)) для консистентности с expires_at.'
        );
    }
}
