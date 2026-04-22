<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * RED-тесты для Группы 8 ADR — Step 2, F-8-002.
 *
 * Проверяют контракт decline_stale_missing_transactions():
 *   - батч 100 (не 500), чтобы окно лока было коротким;
 *   - каждый батч в своей TX (START TRANSACTION / COMMIT);
 *   - SELECT ... FOR UPDATE до UPDATE;
 *   - перечитывание статуса под локом (чтобы UPDATE шёл только на
 *     всё ещё stale-строки, не трогая уже перешедшие в balance/declined);
 *   - ROLLBACK + error_log на SQL-ошибке батча;
 *   - диапазон статусов (waiting / hold / completed) не сужается — product
 *     пояснение: completed НЕ является accrual-статусом (accrual = balance),
 *     CPA может развернуть completed → declined;
 *   - pagination-protection (pagination_limit_hit + earliest_api_date) сохранён;
 *   - retry_on_sync_deadlock переиспользуется из Step 1.
 *
 * Методика: source-string + regex checks (bootstrap не поднимает реальную БД).
 */
#[Group('api-client-decline-stale')]
final class ApiClientDeclineStaleAtomicityTest extends TestCase
{
    private const API_CLIENT_FILE = __DIR__ . '/../../../includes/class-cashback-api-client.php';

    private function read_source(): string
    {
        $src = file_get_contents(self::API_CLIENT_FILE);
        $this->assertIsString($src, 'Source must be readable: ' . self::API_CLIENT_FILE);
        return $src;
    }

    private function extract_method_body(string $src, string $method_name): string
    {
        $pos = strpos($src, 'function ' . $method_name . '(');
        $this->assertIsInt($pos, $method_name . '() method must exist in source.');

        $brace = strpos($src, '{', $pos);
        $this->assertIsInt($brace, 'Opening brace must follow signature of ' . $method_name);

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

        $this->fail('Could not find closing brace for ' . $method_name);
    }

    /**
     * Объединённое тело публичного метода + его приватного батч-хелпера.
     * Публичный метод отвечает за SELECT/анализ/pagination; атомарная часть
     * (TX + FOR UPDATE + UPDATE + retry) делегирована в run_auto_decline_batches.
     */
    private function combined_body(string $src): string
    {
        $public  = $this->extract_method_body($src, 'decline_stale_missing_transactions');
        $helper  = $this->extract_method_body($src, 'run_auto_decline_batches');
        return $public . "\n" . $helper;
    }

    // ════════════════════════════════════════════════════════════════
    // 1. Batch size — 100, не 500
    // ════════════════════════════════════════════════════════════════

    public function test_decline_stale_uses_batch_size_100(): void
    {
        $body = $this->combined_body($this->read_source());

        $this->assertMatchesRegularExpression(
            '/array_chunk\s*\(\s*\$\w+\s*,\s*100\s*\)/',
            $body,
            'Батч-размер для UPDATE должен быть 100 (F-8-002: короткое окно лока).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/array_chunk\s*\(\s*\$\w+\s*,\s*500\s*\)/',
            $body,
            'Батч 500 должен быть заменён на 100 (ADR Group 8 Step 2).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 2. Каждый батч в своей TX
    // ════════════════════════════════════════════════════════════════

    public function test_decline_stale_wraps_each_batch_in_transaction(): void
    {
        $body = $this->combined_body($this->read_source());

        $this->assertMatchesRegularExpression(
            '/START\s+TRANSACTION/i',
            $body,
            'decline_stale_missing_transactions должен открывать TX для батча.'
        );
        $this->assertMatchesRegularExpression(
            '/[\'"]COMMIT[\'"]/',
            $body,
            'COMMIT должен завершать TX батча.'
        );
        $this->assertMatchesRegularExpression(
            '/[\'"]ROLLBACK[\'"]/',
            $body,
            'ROLLBACK должен быть в ветке ошибки TX батча.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 3. FOR UPDATE до UPDATE
    // ════════════════════════════════════════════════════════════════

    public function test_decline_stale_acquires_for_update_before_batch_update(): void
    {
        $body = $this->combined_body($this->read_source());

        $for_update_pos = stripos($body, 'FOR UPDATE');
        $this->assertIsInt(
            $for_update_pos,
            'decline_stale_missing_transactions должен брать SELECT ... FOR UPDATE на батче (F-8-002).'
        );

        // UPDATE должен идти после FOR UPDATE (в теле foreach-чанка).
        $update_pos = strpos(
            $body,
            "SET order_status = 'declined'"
        );
        if ($update_pos === false) {
            $update_pos = strpos($body, "SET order_status='declined'");
        }
        $this->assertIsInt(
            $update_pos,
            "UPDATE ... SET order_status='declined' должен присутствовать в методе."
        );

        $this->assertLessThan(
            $update_pos,
            $for_update_pos,
            'FOR UPDATE должен идти ДО UPDATE SET order_status="declined" внутри батча.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 4. Re-read статуса под локом
    // ════════════════════════════════════════════════════════════════

    public function test_decline_stale_rereads_status_under_lock(): void
    {
        $body = $this->combined_body($this->read_source());

        // SELECT под FOR UPDATE должен возвращать order_status (чтобы фильтровать).
        $this->assertMatchesRegularExpression(
            '/SELECT[^;]*\border_status[^;]*\bFROM[^;]*\bWHERE[^;]*\bFOR\s+UPDATE/is',
            $body,
            'SELECT ... FOR UPDATE должен возвращать order_status для re-check под локом.'
        );

        // Должен существовать PHP-фильтр стейт-полей ПОСЛЕ SELECT FOR UPDATE
        // (либо WHERE order_status IN (...) в финальном UPDATE).
        $has_status_gate_in_update = (bool) preg_match(
            "/UPDATE[^;]*SET\s+order_status\s*=\s*'declined'[^;]*WHERE[^;]*order_status\s+IN/is",
            $body
        );
        $has_php_filter_before_update = (bool) preg_match(
            "/foreach[^{]*\{[^{}]*order_status[^{}]*'(?:waiting|hold|completed)'/is",
            $body
        );

        $this->assertTrue(
            $has_status_gate_in_update || $has_php_filter_before_update,
            'Под локом должна быть проверка «статус ещё в waiting/hold/completed» ' .
            'либо в WHERE финального UPDATE, либо в PHP-фильтре после SELECT FOR UPDATE.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 5. ROLLBACK + error_log на ошибке батча
    // ════════════════════════════════════════════════════════════════

    public function test_decline_stale_rolls_back_batch_on_error(): void
    {
        $body = $this->combined_body($this->read_source());

        $this->assertMatchesRegularExpression(
            '/\$wpdb->last_error/',
            $body,
            'Проверка $wpdb->last_error должна быть при обработке батча.'
        );
        $this->assertMatchesRegularExpression(
            '/error_log\s*\(/',
            $body,
            'error_log() должен логировать ошибки батча.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 6. Диапазон статусов НЕ сужен (waiting / hold / completed)
    // ════════════════════════════════════════════════════════════════

    public function test_decline_stale_preserves_status_scope(): void
    {
        $body = $this->combined_body($this->read_source());

        foreach (array('waiting', 'hold', 'completed') as $status) {
            $this->assertStringContainsString(
                "'" . $status . "'",
                $body,
                "Статус '{$status}' должен остаться в scope auto-decline " .
                '(completed НЕ является accrual — accrual это balance).'
            );
        }
    }

    // ════════════════════════════════════════════════════════════════
    // 7. Pagination-protection сохранён
    // ════════════════════════════════════════════════════════════════

    public function test_decline_stale_preserves_pagination_protection(): void
    {
        $body = $this->combined_body($this->read_source());

        $this->assertStringContainsString(
            'pagination_limit_hit',
            $body,
            'Флаг pagination_limit_hit не должен быть потерян при рефакторе.'
        );
        $this->assertStringContainsString(
            'earliest_api_date',
            $body,
            'earliest_api_date pagination-guard не должен быть потерян при рефакторе.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 8. retry_on_sync_deadlock переиспользуется
    // ════════════════════════════════════════════════════════════════

    public function test_decline_stale_retries_on_deadlock(): void
    {
        $body = $this->combined_body($this->read_source());

        $this->assertMatchesRegularExpression(
            '/retry_on_sync_deadlock\s*\(/',
            $body,
            'decline_stale_missing_transactions должен использовать retry_on_sync_deadlock ' .
            '(единый helper с Step 1).'
        );
    }
}
