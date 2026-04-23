<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Группа 12c ADR — TX + SELECT FOR UPDATE в repair-хендлерах admin-API-validation.
 *
 * Closes F-4-002 (admin-api-validation: UPDATE без лока на SELECT → TOCTOU race
 * при параллельной правке той же транзакции из разных admin-сессий или
 * пересечения с API-sync cron'ом).
 *
 * Сценарий race (до рефактора):
 *   t0: Admin A SELECT transaction id=42, status=waiting
 *   t1: Admin B SELECT transaction id=42, status=waiting
 *   t2: Admin A UPDATE status=completed  ← проходит
 *   t3: Admin B UPDATE status=declined   ← проходит, хотя validate_status_transition
 *       в A уже перевёл в completed. Фактический UPDATE B перезаписывает A.
 *
 * Контракт (TDD RED) для `ajax_edit_transaction` и `ajax_overwrite_transaction`:
 *  1. START TRANSACTION до SELECT.
 *  2. SELECT ... FOR UPDATE — guards (validate_status_transition) работают на
 *     committed-состоянии под локом.
 *  3. COMMIT после успешного UPDATE.
 *  4. ROLLBACK при ошибке UPDATE (либо перед wp_send_json_error).
 *
 * `ajax_add_transaction` (INSERT) вне скоупа 12c: INSERT атомарен, race-protection
 * обеспечивается UNIQUE idempotency_key (схема Группы 6). FOR UPDATE там не нужен.
 *
 * Паттерн — из Группы 8 (sync_update_local, строки 2080–2128 api-client'а),
 * адаптирован к admin-repair (без deadlock-retry — admin может кликнуть заново).
 */
#[Group('security')]
#[Group('group12')]
#[Group('atomicity')]
class AdminApiValidationForUpdateTest extends TestCase
{
    private string $source = '';

    protected function setUp(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $path        = $plugin_root . '/admin/class-cashback-admin-api-validation.php';

        self::assertFileExists($path, 'admin-api-validation должен присутствовать');

        $content = file_get_contents($path);
        self::assertNotFalse($content, 'Не удалось прочитать admin-api-validation');
        $this->source = $content;
    }

    /**
     * Извлечь тело метода по имени (до закрывающей }, матч по индентации 4 пробела).
     */
    private function extract_method( string $method_name ): string
    {
        $pattern = '/public\s+function\s+' . preg_quote($method_name, '/')
            . '\s*\([^)]*\)\s*:\s*void\s*\{([\s\S]*?)^\s{4}\}/m';

        if (preg_match($pattern, $this->source, $m) !== 1) {
            self::fail('Не найден метод ' . $method_name . '() в admin-api-validation — структура изменилась?');
        }
        return $m[1];
    }

    // =====================================================================
    // ajax_edit_transaction — 4 проверки
    // =====================================================================

    public function test_edit_transaction_starts_transaction(): void
    {
        $body = $this->extract_method('ajax_edit_transaction');
        self::assertMatchesRegularExpression(
            "/\\\$wpdb->query\s*\(\s*['\"]START TRANSACTION['\"]\s*\)/",
            $body,
            'F-4-002: ajax_edit_transaction должен открывать TX до SELECT + UPDATE — иначе race с параллельной правкой.'
        );
    }

    public function test_edit_transaction_select_uses_for_update(): void
    {
        $body = $this->extract_method('ajax_edit_transaction');
        self::assertMatchesRegularExpression(
            "/FOR\s+UPDATE/i",
            $body,
            'F-4-002: SELECT в ajax_edit_transaction должен использовать FOR UPDATE — '
            . 'guards (validate_status_transition) должны работать на locked-committed строке.'
        );
    }

    public function test_edit_transaction_commits_on_success(): void
    {
        $body = $this->extract_method('ajax_edit_transaction');
        self::assertMatchesRegularExpression(
            "/\\\$wpdb->query\s*\(\s*['\"]COMMIT['\"]\s*\)/",
            $body,
            'F-4-002: ajax_edit_transaction должен делать COMMIT после успешного UPDATE.'
        );
    }

    public function test_edit_transaction_rolls_back_on_error(): void
    {
        $body = $this->extract_method('ajax_edit_transaction');
        self::assertMatchesRegularExpression(
            "/\\\$wpdb->query\s*\(\s*['\"]ROLLBACK['\"]\s*\)/",
            $body,
            'F-4-002: ajax_edit_transaction должен делать ROLLBACK при ошибке UPDATE — '
            . 'иначе открытая TX утечёт.'
        );
    }

    // =====================================================================
    // ajax_overwrite_transaction — 4 проверки
    // =====================================================================

    public function test_overwrite_transaction_starts_transaction(): void
    {
        $body = $this->extract_method('ajax_overwrite_transaction');
        self::assertMatchesRegularExpression(
            "/\\\$wpdb->query\s*\(\s*['\"]START TRANSACTION['\"]\s*\)/",
            $body,
            'F-4-002: ajax_overwrite_transaction должен открывать TX до SELECT + UPDATE.'
        );
    }

    public function test_overwrite_transaction_select_uses_for_update(): void
    {
        $body = $this->extract_method('ajax_overwrite_transaction');
        self::assertMatchesRegularExpression(
            "/FOR\s+UPDATE/i",
            $body,
            'F-4-002: SELECT в ajax_overwrite_transaction должен использовать FOR UPDATE.'
        );
    }

    public function test_overwrite_transaction_commits_on_success(): void
    {
        $body = $this->extract_method('ajax_overwrite_transaction');
        self::assertMatchesRegularExpression(
            "/\\\$wpdb->query\s*\(\s*['\"]COMMIT['\"]\s*\)/",
            $body,
            'F-4-002: ajax_overwrite_transaction должен делать COMMIT после успешного UPDATE.'
        );
    }

    public function test_overwrite_transaction_rolls_back_on_error(): void
    {
        $body = $this->extract_method('ajax_overwrite_transaction');
        self::assertMatchesRegularExpression(
            "/\\\$wpdb->query\s*\(\s*['\"]ROLLBACK['\"]\s*\)/",
            $body,
            'F-4-002: ajax_overwrite_transaction должен делать ROLLBACK при ошибке UPDATE.'
        );
    }

    // =====================================================================
    // Regression guards
    // =====================================================================

    public function test_add_transaction_kept_without_tx_wrapper(): void
    {
        // INSERT с UNIQUE idempotency_key атомарен — TX-оборачивание не нужно.
        // Regression: если в будущем случайно добавят START TRANSACTION в
        // ajax_add_transaction, явно падаем — пусть будет осознанное решение,
        // а не случайная правка.
        $body = $this->extract_method('ajax_add_transaction');
        self::assertDoesNotMatchRegularExpression(
            "/\\\$wpdb->query\s*\(\s*['\"]START TRANSACTION['\"]\s*\)/",
            $body,
            'Regression: ajax_add_transaction НЕ должен оборачиваться в TX — '
            . 'INSERT атомарен, UNIQUE idempotency_key (Группа 6) решает конкурентность. '
            . 'Если нужно — сначала обнови этот тест + commit message.'
        );
    }
}
