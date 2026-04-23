<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * RED-тесты для Группы 8 ADR — Step 1, F-8-001.
 *
 * Проверяют контракт sync_update_local():
 *   - сигнатура принимает опциональный $in_transaction = false;
 *   - тело обёрнуто в START TRANSACTION / COMMIT / ROLLBACK под $owns_tx;
 *   - SELECT ... FOR UPDATE берётся до $wpdb->update();
 *   - строка перечитывается под локом (guard'ы работают на свежем состоянии);
 *   - приватный retry_on_sync_deadlock с лимитом попыток и детекцией deadlock;
 *   - все существующие guard'ы сохранены (balance / completed→waiting / validate_status_transition);
 *   - на ошибке UPDATE: ++$update_errors + ROLLBACK + error_log;
 *   - set колонок UPDATE не изменился;
 *   - ни один callsite не передаёт $in_transaction = true (все они вне TX).
 *
 * Методика: source-string + regex checks (bootstrap не поднимает реальную БД).
 */
#[Group('api-client-sync')]
final class ApiClientSyncAtomicityTest extends TestCase
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

        // Ищем открывающую скобку тела после сигнатуры
        $brace = strpos($src, '{', $pos);
        $this->assertIsInt($brace, 'Opening brace must follow signature of ' . $method_name);

        // Идём по скобкам, пока не найдём закрывающую на том же уровне
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

    // ════════════════════════════════════════════════════════════════
    // 1. Сигнатура принимает $in_transaction = false
    // ════════════════════════════════════════════════════════════════

    public function test_sync_update_local_signature_accepts_in_transaction_flag(): void
    {
        $src = $this->read_source();

        $this->assertMatchesRegularExpression(
            '/function\s+sync_update_local\s*\(([^)]*\bbool\s+\$in_transaction\s*=\s*false\b[^)]*)\)/s',
            $src,
            'sync_update_local должен принимать опциональный bool $in_transaction = false (Group 8 Step 1).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 2. TX-обёртка: START TRANSACTION / COMMIT / ROLLBACK под $owns_tx
    // ════════════════════════════════════════════════════════════════

    public function test_sync_update_local_wraps_update_in_transaction(): void
    {
        $src  = $this->read_source();
        $body = $this->extract_method_body($src, 'sync_update_local');

        $this->assertMatchesRegularExpression(
            '/START\s+TRANSACTION/i',
            $body,
            'sync_update_local должен открывать START TRANSACTION (F-8-001).'
        );
        $this->assertMatchesRegularExpression(
            '/[\'"]COMMIT[\'"]/',
            $body,
            'sync_update_local должен иметь COMMIT в теле метода (F-8-001).'
        );
        $this->assertMatchesRegularExpression(
            '/[\'"]ROLLBACK[\'"]/',
            $body,
            'sync_update_local должен иметь ROLLBACK в теле метода (F-8-001).'
        );
        $this->assertMatchesRegularExpression(
            '/\$owns_tx\s*=\s*!\s*\$in_transaction/',
            $body,
            'TX-контракт: $owns_tx = !$in_transaction — ownership-флаг для COMMIT/ROLLBACK.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 3. SELECT ... FOR UPDATE идёт до $wpdb->update(...)
    // ════════════════════════════════════════════════════════════════

    public function test_sync_update_local_acquires_for_update_before_update(): void
    {
        $src  = $this->read_source();
        $body = $this->extract_method_body($src, 'sync_update_local');

        $for_update_pos = stripos($body, 'FOR UPDATE');
        $this->assertIsInt(
            $for_update_pos,
            'sync_update_local должен брать SELECT ... FOR UPDATE (F-8-001).'
        );

        $update_call_pos = strpos($body, '$wpdb->update(');
        $this->assertIsInt(
            $update_call_pos,
            '$wpdb->update(...) должен присутствовать в теле метода.'
        );

        $this->assertLessThan(
            $update_call_pos,
            $for_update_pos,
            'FOR UPDATE должен идти ДО $wpdb->update() — чтобы лок был взят перед записью.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 4. Строка перечитывается под локом (guard'ы на свежей строке)
    // ════════════════════════════════════════════════════════════════

    public function test_sync_update_local_rereads_row_under_lock(): void
    {
        $src  = $this->read_source();
        $body = $this->extract_method_body($src, 'sync_update_local');

        // Ожидаем get_row + prepare с SELECT ... WHERE id = %d ... FOR UPDATE
        $this->assertMatchesRegularExpression(
            '/SELECT\s+[^;]*\bWHERE\s+id\s*=\s*%d[^;]*\bFOR\s+UPDATE/is',
            $body,
            'Строка должна перечитываться под FOR UPDATE по id (guards на committed-данных).'
        );
        $this->assertMatchesRegularExpression(
            '/\$wpdb->get_row\s*\(/',
            $body,
            'Перечитывание строки должно использовать $wpdb->get_row().'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 5. retry_on_sync_deadlock: приватный хелпер с детекцией deadlock
    // ════════════════════════════════════════════════════════════════

    public function test_sync_update_local_retries_on_deadlock(): void
    {
        $src = $this->read_source();

        $this->assertMatchesRegularExpression(
            '/private\s+function\s+retry_on_sync_deadlock\s*\(\s*callable\s+\$\w+\s*,\s*int\s+\$max\s*=\s*3\s*\)/',
            $src,
            'Должен быть приватный retry_on_sync_deadlock(callable $callback, int $max = 3).'
        );

        $retry_body = $this->extract_method_body($src, 'retry_on_sync_deadlock');

        $has_deadlock_detection = (bool) preg_match('/(?:deadlock|1213|1205|lock\s+wait)/i', $retry_body);
        $this->assertTrue(
            $has_deadlock_detection,
            'retry_on_sync_deadlock должен распознавать deadlock (MySQL 1213) или lock wait timeout (1205).'
        );

        $has_retry_loop = (bool) preg_match('/(?:while|for)\s*\(/', $retry_body);
        $this->assertTrue(
            $has_retry_loop,
            'retry_on_sync_deadlock должен содержать цикл ретраев.'
        );

        $this->assertMatchesRegularExpression(
            '/\$max\b/',
            $retry_body,
            'Лимит попыток $max должен использоваться в теле retry_on_sync_deadlock.'
        );

        // Sync_update_local должен фактически использовать retry-хелпер (под $owns_tx).
        $body = $this->extract_method_body($src, 'sync_update_local');
        $this->assertMatchesRegularExpression(
            '/retry_on_sync_deadlock\s*\(/',
            $body,
            'sync_update_local должен вызывать retry_on_sync_deadlock когда $owns_tx.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 6. Guard'ы сохранены (защита от регресса)
    // ════════════════════════════════════════════════════════════════

    public function test_sync_update_local_preserves_guards(): void
    {
        $src  = $this->read_source();
        $body = $this->extract_method_body($src, 'sync_update_local');

        $this->assertStringContainsString(
            "'balance'",
            $body,
            "Guard 'balance' (финальный статус) должен быть сохранён."
        );
        $this->assertMatchesRegularExpression(
            "/'completed'[^;]{0,120}'waiting'/s",
            $body,
            "Guard 'completed → waiting' (защита от понижения) должен быть сохранён."
        );
        $this->assertStringContainsString(
            'validate_status_transition(',
            $body,
            'Вызов validate_status_transition() должен быть сохранён.'
        );
        // Группа 10 ADR (F-8-003): float-epsilon `abs($api_payment - ...) >= 0.001`
        // заменён на bit-exact Money::equals() — проверяем, что commission-сравнение
        // сохранилось в новой форме (api_payment → Cashback_Money → equals).
        $this->assertMatchesRegularExpression(
            '/\$api_payment_money\s*->\s*equals\s*\(\s*\$fresh_comission_money\s*\)/',
            $body,
            'Сравнение commission через Cashback_Money::equals должно быть сохранено.'
        );
        $this->assertStringContainsString(
            'resolve_funds_ready(',
            $body,
            'Вызов resolve_funds_ready() должен быть сохранён.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 7. На ошибке UPDATE: ++$update_errors + ROLLBACK + error_log
    // ════════════════════════════════════════════════════════════════

    public function test_sync_update_local_rolls_back_and_logs_on_update_error(): void
    {
        $src  = $this->read_source();
        $body = $this->extract_method_body($src, 'sync_update_local');

        $this->assertMatchesRegularExpression(
            '/\$wpdb->last_error/',
            $body,
            'Проверка $wpdb->last_error должна быть сохранена.'
        );
        $this->assertMatchesRegularExpression(
            '/\+\+\$update_errors/',
            $body,
            '++$update_errors должен инкрементироваться на SQL-ошибке.'
        );
        $this->assertMatchesRegularExpression(
            '/error_log\s*\(/',
            $body,
            'error_log() должен логировать SQL-ошибку UPDATE.'
        );

        // ROLLBACK должен быть в ветке ошибки (или в общем catch).
        $this->assertMatchesRegularExpression(
            '/[\'"]ROLLBACK[\'"]/',
            $body,
            'ROLLBACK должен вызываться в ветке ошибки / catch (TX cleanup).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 8. Набор колонок UPDATE не изменился
    // ════════════════════════════════════════════════════════════════

    public function test_sync_update_local_does_not_change_update_columns(): void
    {
        $src  = $this->read_source();
        $body = $this->extract_method_body($src, 'sync_update_local');

        foreach (
            [
                'order_status',
                'comission',
                'sum_order',
                'api_verified',
                'funds_ready',
                'cashback',
            ] as $column
        ) {
            $this->assertStringContainsString(
                "'" . $column . "'",
                $body,
                "Колонка UPDATE '{$column}' не должна быть потеряна при рефакторе."
            );
        }
    }

    // ════════════════════════════════════════════════════════════════
    // 9. Callsite-семантика: ни один caller не передаёт $in_transaction = true
    // ════════════════════════════════════════════════════════════════

    public function test_sync_update_local_callers_pass_false_or_omit_in_transaction(): void
    {
        $src = $this->read_source();

        // Найти все вызовы $this->sync_update_local( ... )
        preg_match_all(
            '/\$this->sync_update_local\s*\((?<args>.*?)\)\s*;/s',
            $src,
            $matches
        );

        $this->assertNotEmpty(
            $matches['args'],
            'В источнике должны быть вызовы $this->sync_update_local(...).'
        );
        $this->assertGreaterThanOrEqual(
            5,
            count($matches['args']),
            'Ожидаем минимум 5 callsite (sync x3, health-check x1, cross-table guard x1).'
        );

        foreach ($matches['args'] as $i => $args) {
            $this->assertStringNotContainsString(
                'true',
                $args,
                "Callsite #{$i} sync_update_local(...) не должен передавать \$in_transaction=true — " .
                'все текущие callers вне TX (cron/admin top-level).'
            );
        }
    }
}
