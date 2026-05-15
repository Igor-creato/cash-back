<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Регрессии «потерянный вебхук при API-валидации»: дозаписанная строка
 * расходилась со строкой webhook-receiver по двум полям.
 *
 *  1. partner: insert_missing_transaction писал strtolower($slug) ('adm'),
 *     а receiver и 1210 строк — имя сети ('Admitad'). Идентичность
 *     дедупа на LOWER() не ломается, но колонка визуально рассинхронена
 *     и любой группировщик по точному partner дробит сеть. Фикс — писать
 *     уже вычисленный $network_name ($config['name'] ?? $slug).
 *
 *  2. action_date: при кривом api_field_map в БД
 *     (conversion_time→action_date; у Admitad нет conversion_time)
 *     $action[$fm_action_date] пустой → parse_api_date('') → NULL.
 *     Сосед click_time уже имеет устойчивую цепочку fallback'ов; для
 *     action_date добавляем зеркальную (raw action_date / closing_date /
 *     action_time) — корректность независимо от карты в БД.
 *
 *  Регрессия-guard: idempotency_key остаётся strtolower($slug)|action_id
 *  (cross-path канонический ключ, зеркало Python-receiver — НЕ partner).
 *
 *  Тот же partner-баг во втором месте: admin ajax_add_transaction
 *  (class-cashback-admin-api-validation.php) писал strtolower($network).
 *
 *  Методика: source-string + brace-balanced extract (bootstrap без БД,
 *  insert_missing_transaction делает реальный $wpdb->insert). Совпадает
 *  с ApiClientSyncAtomicityTest / ApiClientParseDateTest.
 */
#[Group('api-client')]
final class ApiClientInsertMissingTransactionTest extends TestCase
{
    private const API_CLIENT_FILE = __DIR__ . '/../../../includes/class-cashback-api-client.php';
    private const ADMIN_VALIDATION_FILE = __DIR__ . '/../../../admin/class-cashback-admin-api-validation.php';

    private function read_source(string $file): string
    {
        $src = file_get_contents($file);
        $this->assertIsString($src, 'Source must be readable: ' . $file);
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

    // ════════════════════════════════════════════════════════════════
    // 1. partner = имя сети (insert_missing_transaction)
    // ════════════════════════════════════════════════════════════════

    public function test_insert_missing_transaction_partner_uses_network_name(): void
    {
        $body = $this->extract_method_body(
            $this->read_source(self::API_CLIENT_FILE),
            'insert_missing_transaction'
        );

        $this->assertMatchesRegularExpression(
            "/'partner'\s*=>\s*\\\$network_name\b/",
            $body,
            'insert_missing_transaction должен писать $network_name (= $config[\'name\'] ?? $slug), '
            . 'чтобы строка совпадала с webhook-receiver (имя сети "Admitad", не slug "adm").'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/'partner'\s*=>\s*strtolower\(\\\$slug\)/",
            $body,
            'insert_missing_transaction НЕ должен писать strtolower($slug) в колонку partner.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 2. action_date — устойчивая цепочка fallback'ов
    // ════════════════════════════════════════════════════════════════

    public function test_insert_missing_transaction_action_date_has_raw_fallback_chain(): void
    {
        $body = $this->extract_method_body(
            $this->read_source(self::API_CLIENT_FILE),
            'insert_missing_transaction'
        );

        // Зеркало паттерна click_time: $action[$fm_action_date] ?? raw action_date ?? closing_date ?? action_time
        $this->assertMatchesRegularExpression(
            "/\\\$action\[\s*\\\$fm_action_date\s*\]\s*\?\?\s*\\\$action\['action_date'\]\s*\?\?\s*\\\$action\['closing_date'\]/",
            $body,
            'action_date должен иметь fallback на raw поля Admitad (action_date / closing_date / action_time), '
            . 'иначе кривой api_field_map (conversion_time→action_date) даёт NULL.'
        );
        // Голый вариант без fallback больше не должен присутствовать.
        $this->assertDoesNotMatchRegularExpression(
            "/parse_api_date\(\s*\(string\)\s*\(\s*\\\$action\[\s*\\\$fm_action_date\s*\]\s*\?\?\s*''\s*\)\s*\)/",
            $body,
            'Голый $action[$fm_action_date] ?? \'\' без fallback должен быть заменён цепочкой.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 3. Регрессия: idempotency_key НЕ затронут (cross-path канон. ключ)
    // ════════════════════════════════════════════════════════════════

    public function test_insert_missing_transaction_idempotency_key_still_uses_slug(): void
    {
        $body = $this->extract_method_body(
            $this->read_source(self::API_CLIENT_FILE),
            'insert_missing_transaction'
        );

        $this->assertMatchesRegularExpression(
            "/hash\(\s*'sha256'\s*,\s*strtolower\(\\\$slug\)\s*\.\s*'\|'\s*\.\s*\\\$action_id\s*\)/",
            $body,
            'idempotency_key обязан остаться sha256(lower(slug)|action_id) — зеркало Python-receiver, '
            . 'не зависит от значения колонки partner.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 4. Тот же partner-баг: admin ajax_add_transaction
    // ════════════════════════════════════════════════════════════════

    public function test_admin_add_transaction_partner_uses_network_name(): void
    {
        $body = $this->extract_method_body(
            $this->read_source(self::ADMIN_VALIDATION_FILE),
            'ajax_add_transaction'
        );

        $this->assertMatchesRegularExpression(
            "/'partner'\s*=>\s*\\\$network_config\['name'\]/",
            $body,
            'ajax_add_transaction должен писать имя сети из $network_config[\'name\'], '
            . 'чтобы ручная дозапись совпадала с webhook-receiver.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/'partner'\s*=>\s*strtolower\(\\\$network\)/",
            $body,
            'ajax_add_transaction НЕ должен писать strtolower($network) в колонку partner.'
        );
        // Регрессия: idempotency_key здесь тоже остаётся на slug ($network).
        $this->assertMatchesRegularExpression(
            "/hash\(\s*'sha256'\s*,\s*strtolower\(\\\$network\)\s*\.\s*'\|'\s*\.\s*\\\$action_id\s*\)/",
            $body,
            'idempotency_key в ajax_add_transaction обязан остаться sha256(lower($network)|action_id).'
        );
    }
}
