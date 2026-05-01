<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Регрессионный тест: текст cap-reject должен говорить «попробуйте завтра»
 * (calendar-day cap = 3 заявки в сутки), не «через 24 часа» (sliding window).
 *
 * Закрывает BUG-03 из E2E run-h (отчёт 2026-04-30-e2e-fintech-full-h.md):
 * пользователь видел «Попробуйте через 24 часа», что неверно отражает реальную
 * семантику лимита (cap считается по `created_at >= UTC_TIMESTAMP() - 24h`,
 * но воспринимается пользователем как «попробую через сутки»).
 *
 * Source-based regression: предохраняет от случайного отката строки
 * при будущих рефакторингах. Проверяем именно содержимое файла-handler'а.
 */
#[Group('withdrawal')]
#[Group('ux')]
final class WithdrawalCapTextTest extends TestCase
{
    private function withdrawal_source(): string
    {
        $path = dirname(__DIR__, 3) . '/cashback-withdrawal.php';
        $contents = file_get_contents($path);
        $this->assertIsString($contents, 'cashback-withdrawal.php must be readable');
        return $contents;
    }

    public function test_cap_reject_message_says_tomorrow_not_24h(): void
    {
        $src = $this->withdrawal_source();

        // Извлекаем cap-reject ветку: блок от `if ($recent_requests_count >= 3)` до его закрывающего `}`.
        $pattern = '/if\s*\(\s*\$recent_requests_count\s*>=\s*3\s*\)\s*\{(.*?)\}/s';
        $this->assertSame(
            1,
            preg_match($pattern, $src, $m),
            'Не нашли cap-reject ветку `if ($recent_requests_count >= 3) { ... }` — рефакторинг изменил структуру?'
        );
        $cap_branch = $m[1];

        $this->assertStringNotContainsString(
            'через 24 часа',
            $cap_branch,
            'BUG-03: cap-reject ветка не должна содержать «через 24 часа» — это вводит в заблуждение, лимит — calendar-day cap'
        );

        $this->assertMatchesRegularExpression(
            '/Лимит\s+3\s+заявки\s+в\s+сутки\s+исчерпан\.\s+Попробуйте\s+завтра\./u',
            $cap_branch,
            'BUG-03: ожидается текст «Лимит 3 заявки в сутки исчерпан. Попробуйте завтра.» в cap-reject ветке'
        );
    }
}
