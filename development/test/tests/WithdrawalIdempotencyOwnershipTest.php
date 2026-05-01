<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Регрессионный тест: idempotency-fast-path SELECT в process_cashback_withdrawal
 * обязан фильтровать по user_id, иначе любой авторизованный юзер может swap'нуть
 * чужой idempotency_key и получить ответ с чужим reference_id/total_amount
 * (info-disclosure ≈ IDOR).
 *
 * Закрывает F-S5-IDOR-01 из E2E run-h (отчёт 2026-04-30-e2e-fintech-full-h.md):
 * `SELECT id, reference_id, total_amount, status FROM ... WHERE idempotency_key = %s`
 * без `AND user_id = %d` возвращал чужую заявку при подмене ключа со стороны
 * клиента (cookie-стороннего хранения idempotency_key).
 *
 * Source-based regression: предохраняет от случайного отката фильтра
 * при будущих рефакторингах. Поведенческие проверки делаются E2E (Stage 5.9)
 * + integration (если поднимут wp+wpdb fixture).
 */
#[Group('withdrawal')]
#[Group('security')]
final class WithdrawalIdempotencyOwnershipTest extends TestCase
{
    private function withdrawal_source(): string
    {
        $path = dirname(__DIR__, 3) . '/cashback-withdrawal.php';
        $contents = file_get_contents($path);
        $this->assertIsString($contents, 'cashback-withdrawal.php must be readable');
        return $contents;
    }

    /**
     * Извлекает SQL-строку (или строки) для idempotency-fast-path SELECT
     * из тела файла. Возвращает массив "сырых" SQL-фрагментов, которые встречаются
     * в `$wpdb->prepare(...)` вызовах с `WHERE idempotency_key = %s`.
     *
     * @return string[]
     */
    private function idempotency_fast_path_sql(string $src): array
    {
        $matches = [];
        $found = preg_match_all(
            '/\$wpdb->prepare\s*\(\s*([\'"])(SELECT[^"\']*WHERE\s+idempotency_key\s*=\s*%s[^"\']*)\1/si',
            $src,
            $matches
        );
        $this->assertNotFalse($found, 'preg_match_all сломался');
        $this->assertGreaterThanOrEqual(
            1,
            $found,
            'Не нашли idempotency-fast-path SELECT в cashback-withdrawal.php'
        );
        return $matches[2];
    }

    public function test_idempotency_select_filters_by_user_id(): void
    {
        $src = $this->withdrawal_source();
        $sqls = $this->idempotency_fast_path_sql($src);

        foreach ($sqls as $sql) {
            $this->assertMatchesRegularExpression(
                '/idempotency_key\s*=\s*%s\s+AND\s+user_id\s*=\s*%d/i',
                $sql,
                'F-S5-IDOR-01: idempotency-fast-path SELECT обязан фильтровать `AND user_id = %d`. Найден небезопасный SQL: ' . $sql
            );
        }
    }

    public function test_idempotency_prepare_passes_user_id_argument(): void
    {
        $src = $this->withdrawal_source();

        // Полный fast-path вызов: $wpdb->prepare('SELECT ... idempotency_key = %s AND user_id = %d', $table, $key, $user_id)
        $this->assertMatchesRegularExpression(
            '/\$wpdb->prepare\(\s*[\'"]SELECT[^\'"]+idempotency_key\s*=\s*%s\s+AND\s+user_id\s*=\s*%d[^\'"]*[\'"]\s*,'
            . '\s*\$[A-Za-z_][A-Za-z0-9_]*'      // table arg (%i placeholder)
            . '\s*,\s*\$[A-Za-z_][A-Za-z0-9_]*'  // idempotency_key arg
            . '\s*,\s*\$[A-Za-z_][A-Za-z0-9_]*'  // user_id arg
            . '\s*\)/s',
            $src,
            'F-S5-IDOR-01: вызов `$wpdb->prepare(...)` для idempotency-fast-path должен передавать $user_id третьим аргументом (после $table и $idempotency_key)'
        );
    }
}
