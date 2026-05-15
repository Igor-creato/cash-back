<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Codex F-4 (residual debt closure): перенос строки из
 * cashback_unregistered_transactions в cashback_transactions проверял
 * дубль по ТОЧНОМУ `uniq_id = %s AND partner = %s`. При смешанных
 * формах partner ('adm' vs 'Admitad' для одного логического действия)
 * дубль не распознавался → INSERT отбивался жёстким UNIQUE/idx
 * (денег НЕ задваивает), но «висящая» unregistered-строка не
 * вычищалась → накопление мусора.
 *
 * Фикс: дубль определяется по `idempotency_key` — каноническому
 * cross-path ключу sha256(lower(slug)|action_id), который НЕ зависит
 * от строки partner и идентичен для webhook/cron/admin. Старое
 * uniq_id+partner оставлено как fallback для legacy-строк без
 * idempotency_key (нет регрессии).
 *
 * Методика: source-string + brace-balanced extract (как
 * ApiClientSyncAtomicityTest) — оба пути делают реальный $wpdb.
 */
#[Group('api-client')]
#[Group('idempotency')]
final class TransferDuplicateCanonicalCheckTest extends TestCase
{
    private const API_CLIENT_FILE = __DIR__ . '/../../../includes/class-cashback-api-client.php';
    private const ADMIN_TX_FILE   = __DIR__ . '/../../../admin/transactions.php';

    private function method_body(string $file, string $method): string
    {
        $src = file_get_contents($file);
        $this->assertIsString($src, 'readable: ' . $file);
        $pos = strpos($src, 'function ' . $method . '(');
        $this->assertIsInt($pos, $method . '() must exist in ' . basename($file));
        $brace = strpos($src, '{', $pos);
        $this->assertIsInt($brace);
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
        $this->fail('closing brace not found for ' . $method);
    }

    /**
     * Извлечь SQL дубль-проверки (SELECT COUNT(*) ... idempotency / uniq_id).
     */
    private function dup_check_sql(string $body): string
    {
        // Между 'SELECT COUNT(*)' и закрывающей '))' / ');' вызова get_var.
        $this->assertMatchesRegularExpression(
            '/SELECT\s+COUNT\(\*\)\s+FROM/i',
            $body,
            'дубль-проверка SELECT COUNT(*) должна присутствовать'
        );
        $start = stripos($body, 'SELECT COUNT(*)');
        return substr($body, $start, 400);
    }

    public function test_auto_transfer_dup_check_uses_idempotency_key(): void
    {
        $body = $this->method_body(self::API_CLIENT_FILE, 'auto_transfer_unregistered');
        $sql  = $this->dup_check_sql($body);

        $this->assertMatchesRegularExpression(
            '/idempotency_key\s*=\s*%s/i',
            $sql,
            'auto_transfer: дубль должен матчиться по каноническому idempotency_key.'
        );
        // Fallback на legacy-строки без ключа сохранён (нет регрессии).
        $this->assertMatchesRegularExpression(
            '/uniq_id\s*=\s*%s\s*AND\s*partner\s*=\s*%s/i',
            $sql,
            'auto_transfer: uniq_id+partner оставлен как fallback.'
        );
        $this->assertMatchesRegularExpression(
            '/\bOR\b/i',
            $sql,
            'auto_transfer: условия idempotency_key и uniq_id+partner объединены через OR.'
        );
        // Codex F-4 follow-up: legacy uniq_id+partner leg должен быть
        // пуст-gated (AND %s != '' AND %s != ''), иначе при пустых
        // uniq_id/partner он ложно матчит мусорную строку → потеря строки.
        // Итого 3 гейта: 1 (idempotency-leg) + 2 (legacy-leg).
        $this->assertSame(
            3,
            substr_count($sql, "%s != ''"),
            'auto_transfer: idempotency-leg (1) + legacy-leg (2) должны иметь != \'\'-гейты.'
        );
    }

    public function test_manual_transfer_dup_check_uses_idempotency_key(): void
    {
        $body = $this->method_body(self::ADMIN_TX_FILE, 'handle_transfer_unregistered');
        $sql  = $this->dup_check_sql($body);

        $this->assertMatchesRegularExpression(
            '/idempotency_key\s*=\s*%s/i',
            $sql,
            'manual transfer: дубль должен матчиться по каноническому idempotency_key.'
        );
        $this->assertMatchesRegularExpression(
            '/uniq_id\s*=\s*%s\s*AND\s*partner\s*=\s*%s/i',
            $sql,
            'manual transfer: uniq_id+partner оставлен как fallback.'
        );
        $this->assertMatchesRegularExpression(
            '/\bOR\b/i',
            $sql,
            'manual transfer: idempotency_key OR uniq_id+partner.'
        );
        // Legacy-leg пуст-gated так же, как в auto_transfer (3 гейта всего).
        $this->assertSame(
            3,
            substr_count($sql, "%s != ''"),
            'manual transfer: idempotency-leg (1) + legacy-leg (2) должны иметь != \'\'-гейты.'
        );
    }
}
