<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты для префикса reference_id в cashback_unregistered_transactions.
 *
 * Префикс `TU-` обеспечивает кросс-табличную уникальность с cashback_transactions (`TX-`):
 * одинаковый reference_id не может одновременно существовать в обеих таблицах по конструкции префикса.
 *
 * Тесты выполняются source-inspection'ом (чтение mariadb.php) — без реальной БД.
 * Интеграционный прогон триггера выполняется вручную на dev-БД (см. план верификации).
 */
#[Group('reference_id')]
#[Group('unregistered')]
class UnregisteredReferenceIdPrefixTest extends TestCase
{
    private const MARIADB_FILE = __DIR__ . '/../../../mariadb.php';

    private static function source(): string
    {
        static $cached = null;
        if ($cached === null) {
            $cached = (string) file_get_contents(self::MARIADB_FILE);
            if ($cached === '') {
                throw new RuntimeException('Cannot read mariadb.php');
            }
        }
        return $cached;
    }

    // ================================================================
    // ТЕСТЫ: триггер генерирует TU- для unregistered
    // ================================================================

    public function test_unregistered_trigger_uses_tu_prefix(): void
    {
        $src = self::source();

        // Извлекаем тело триггера calculate_cashback_before_insert_unregistered
        $pattern = '/CREATE TRIGGER `\{?\$?safe_prefix\}?calculate_cashback_before_insert_unregistered.*?END;/s';
        $this->assertSame(
            1,
            preg_match($pattern, $src, $matches),
            'Триггер calculate_cashback_before_insert_unregistered не найден в mariadb.php'
        );

        $trigger_body = $matches[0];

        $this->assertStringContainsString(
            "CONCAT('TU-',",
            $trigger_body,
            'Триггер unregistered должен генерировать reference_id с префиксом TU-'
        );

        $this->assertStringNotContainsString(
            "CONCAT('TX-',",
            $trigger_body,
            'Триггер unregistered не должен использовать TX- (это префикс для cashback_transactions)'
        );
    }

    public function test_registered_trigger_still_uses_tx_prefix(): void
    {
        $src = self::source();

        // Триггер для cashback_transactions (без _unregistered)
        $pattern = '/CREATE TRIGGER `\{?\$?safe_prefix\}?calculate_cashback_before_insert`.*?END;/s';
        $this->assertSame(
            1,
            preg_match($pattern, $src, $matches),
            'Триггер calculate_cashback_before_insert не найден в mariadb.php'
        );

        $trigger_body = $matches[0];

        $this->assertStringContainsString(
            "CONCAT('TX-',",
            $trigger_body,
            'Триггер registered должен сохранять префикс TX-'
        );
    }

    // ================================================================
    // ТЕСТЫ: backfill в миграции различает таблицы
    // ================================================================

    public function test_backfill_migration_uses_table_aware_prefix(): void
    {
        $src = self::source();

        // Проверяем, что в методе migrate_add_transaction_reference_id() переменная
        // $ref_prefix выбирается на основе имени таблицы.
        $this->assertMatchesRegularExpression(
            '/\$ref_prefix\s*=\s*\$is_unregistered\s*\?\s*[\'"]TU-[\'"]\s*:\s*[\'"]TX-[\'"]/',
            $src,
            'Backfill-миграция должна выбирать TU-/TX- по таблице (поиск $ref_prefix = $is_unregistered ? TU- : TX-)'
        );

        // И что backfill использует $ref_prefix, а не хардкод 'TX-'.
        $this->assertStringContainsString(
            '$ref_prefix . strtoupper(substr(md5(',
            $src,
            'Backfill должен конкатенировать $ref_prefix с генерируемым суффиксом'
        );
    }

    // ================================================================
    // ТЕСТЫ: миграция перехода TX- → TU-
    // ================================================================

    public function test_migration_function_exists(): void
    {
        $src = self::source();

        $this->assertMatchesRegularExpression(
            '/public\s+function\s+migrate_unregistered_reference_id_prefix\s*\(\s*\)\s*:\s*void/',
            $src,
            'Должна существовать публичная функция migrate_unregistered_reference_id_prefix(): void'
        );
    }

    public function test_migration_targets_unregistered_table_only(): void
    {
        $src = self::source();

        // Извлекаем тело функции migrate_unregistered_reference_id_prefix().
        $pattern = '/public\s+function\s+migrate_unregistered_reference_id_prefix\s*\(\s*\)\s*:\s*void\s*\{(.*?)\n    \}/s';
        $this->assertSame(
            1,
            preg_match($pattern, $src, $matches),
            'Тело migrate_unregistered_reference_id_prefix() не найдено'
        );

        $body = $matches[1];

        $this->assertStringContainsString(
            "cashback_unregistered_transactions",
            $body,
            'Миграция должна работать с таблицей cashback_unregistered_transactions'
        );

        $this->assertStringContainsString(
            "'TX-%'",
            $body,
            'Миграция должна фильтровать записи по reference_id LIKE TX-%'
        );

        $this->assertStringContainsString(
            "'TU-'",
            $body,
            'Миграция должна генерировать новые reference_id с префиксом TU-'
        );

        // Убедимся, что миграция НЕ трогает cashback_transactions (registered).
        $this->assertStringNotContainsString(
            "prefix . 'cashback_transactions'",
            $body,
            'Миграция НЕ должна затрагивать cashback_transactions (только unregistered)'
        );
    }

    public function test_migration_is_idempotent_fast_path(): void
    {
        $src = self::source();

        $pattern = '/public\s+function\s+migrate_unregistered_reference_id_prefix\s*\(\s*\)\s*:\s*void\s*\{(.*?)\n    \}/s';
        preg_match($pattern, $src, $matches);
        $body = $matches[1] ?? '';

        // Должна быть проверка наличия TX- записей с ранним возвратом (fast-path).
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$tx_count\s*===\s*0\s*\)\s*\{\s*return;/s',
            $body,
            'Миграция должна иметь fast-path при отсутствии TX- записей'
        );
    }

    // ================================================================
    // ТЕСТЫ: формат TU-XXXXXXXX (PHP-side генерация, имитирует триггер)
    // ================================================================

    public function test_tu_format_regex(): void
    {
        // Имитируем генерацию префикса (PHP-side аналог SQL `CONCAT('TU-', UPPER(LEFT(MD5(...), 8)))`).
        for ($i = 0; $i < 50; $i++) {
            $simulated = 'TU-' . strtoupper(substr(md5(uniqid('', true) . mt_rand()), 0, 8));

            $this->assertMatchesRegularExpression(
                '/^TU-[0-9A-F]{8}$/',
                $simulated,
                'Симулированный TU- reference_id должен соответствовать /^TU-[0-9A-F]{8}$/'
            );

            $this->assertSame(11, strlen($simulated), 'Длина TU-XXXXXXXX должна быть 11');
        }
    }

    // ================================================================
    // ТЕСТЫ: миграция зарегистрирована в активаторе и maybe_run_migrations
    // ================================================================

    public function test_migration_registered_in_activator(): void
    {
        $src = self::source();

        $this->assertStringContainsString(
            '$instance->migrate_unregistered_reference_id_prefix()',
            $src,
            'Вызов migrate_unregistered_reference_id_prefix() должен быть зарегистрирован в активаторе'
        );
    }

    public function test_migration_registered_in_plugin_bootstrap(): void
    {
        $plugin_src = (string) file_get_contents(__DIR__ . '/../../../cashback-plugin.php');
        $this->assertNotSame('', $plugin_src, 'Cannot read cashback-plugin.php');

        $this->assertStringContainsString(
            'migrate_unregistered_reference_id_prefix',
            $plugin_src,
            'maybe_run_migrations() должен вызывать migrate_unregistered_reference_id_prefix'
        );
    }
}
