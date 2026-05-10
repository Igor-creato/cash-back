<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression-тест: `Cashback_User_Status::get_ban_info()` НЕ селектит
 * `ban_reason_admin` (v6 column).
 *
 * Codex adversarial-review round 14 (2026-05-10): метод вызывается из
 * frontend-paths (фильтр `wp_authenticate_user` через `block_banned_login`,
 * personal cabinet через `cashback-withdrawal.php`). Если v6 миграция
 * упала transient'но и колонка ban_reason_admin отсутствует, SELECT
 * упирается в SQL error 1054 «Unknown column» → login сломан для ВСЕХ
 * пользователей (не только banned), личный кабинет 500.
 *
 * Round 12 fix (init warning-only вместо hard-block) был построен на
 * ложной предпосылке «артефакты admin-only». Минимальный fix:
 * убрать `ban_reason_admin` из SELECT — поле никогда не читается из
 * return-значения (callers используют только `banned_at` и `ban_reason`
 * через get_banned_message), оно осталось как dead branch с момента
 * OBS-06 fix.
 */
#[Group('database')]
#[Group('regression')]
#[Group('schema')]
final class UserStatusGetBanInfoSchemaToleranceTest extends TestCase
{
    private function user_status_src(): string
    {
        $path    = dirname(__DIR__, 3) . '/includes/class-cashback-user-status.php';
        $content = file_get_contents($path);
        $this->assertIsString($content);
        return $content;
    }

    public function test_get_ban_info_does_not_select_ban_reason_admin(): void
    {
        $src = $this->user_status_src();

        // Извлекаем тело статического метода get_ban_info.
        $start = strpos($src, 'public static function get_ban_info(');
        $this->assertNotFalse($start, 'get_ban_info() должен существовать');

        $brace_open = strpos($src, '{', $start);
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
        $body = substr($src, $brace_open, $end - $brace_open + 1);

        $this->assertDoesNotMatchRegularExpression(
            '/SELECT[\s\S]+ban_reason_admin[\s\S]+FROM/i',
            $body,
            'get_ban_info() НЕ должен SELECT-ить ban_reason_admin: метод вызывается '
            . 'из frontend-path (block_banned_login на wp_authenticate_user, '
            . 'cashback-withdrawal.php), и при missing v6 колонке SQL error 1054 '
            . 'ломает login и личный кабинет (Codex round 14).'
        );

        $this->assertMatchesRegularExpression(
            '/SELECT[\s\S]+banned_at[\s\S]+ban_reason[\s\S]+FROM/i',
            $body,
            'get_ban_info() должен по-прежнему SELECT-ить banned_at и ban_reason '
            . '(нужны для audit-log и user-facing message).'
        );
    }
}
