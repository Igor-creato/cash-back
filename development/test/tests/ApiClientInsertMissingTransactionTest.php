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
    // 2. action_date — устойчивый резолвер кандидатов (F-3 Codex v4.4.4)
    // ════════════════════════════════════════════════════════════════

    public function test_insert_missing_transaction_action_date_has_raw_fallback_chain(): void
    {
        $body = $this->extract_method_body(
            $this->read_source(self::API_CLIENT_FILE),
            'insert_missing_transaction'
        );

        // action_date резолвится через resolve_api_datetime по семантически
        // верным кандидатам: mapped → каноническое raw 'action_date'
        // (первый РАСПАРСЕННЫЙ, не первый непустой — F-3).
        $this->assertMatchesRegularExpression(
            "/\\\$action_date_mysql\s*=\s*self::resolve_api_datetime\s*\(\s*array\(\s*"
            . "\\\$action\[\s*\\\$fm_action_date\s*\]\s*\?\?\s*''\s*,\s*"
            . "\\\$action\['action_date'\]\s*\?\?\s*''\s*,?\s*\)/s",
            $body,
            'action_date должен резолвиться через resolve_api_datetime по [mapped, raw action_date].'
        );
        // Старый голый parse_api_date по одному полю не должен присутствовать.
        $this->assertDoesNotMatchRegularExpression(
            "/\\\$action_date_mysql\s*=\s*self::parse_api_date\(/",
            $body,
            'Прямой parse_api_date для action_date заменён на resolve_api_datetime.'
        );
        // F-3: closing_date / action_time НЕ используются как fallback —
        // это «дата закрытия», не время покупки; честный NULL > выдуманная дата.
        $action_date_call = '';
        if (preg_match('/\$action_date_mysql\s*=\s*self::resolve_api_datetime\s*\(.*?\);/s', $body, $mm)) {
            $action_date_call = $mm[0];
        }
        $this->assertNotSame('', $action_date_call, 'action_date resolve-вызов должен находиться.');
        $this->assertStringNotContainsString(
            'closing_date',
            $action_date_call,
            'F-3: closing_date не должен быть fallback для action_date.'
        );
        $this->assertStringNotContainsString(
            'action_time',
            $action_date_call,
            'F-3: action_time не должен быть fallback для action_date.'
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

    // ════════════════════════════════════════════════════════════════
    // 5. F-1 Codex: канонизация сети в ajax_add_transaction
    // ════════════════════════════════════════════════════════════════

    public function test_admin_add_transaction_canonicalizes_network_and_rejects_unknown(): void
    {
        $body = $this->extract_method_body(
            $this->read_source(self::ADMIN_VALIDATION_FILE),
            'ajax_add_transaction'
        );

        // Резолв alias→canonical через адаптер при промахе get_network_config.
        $this->assertMatchesRegularExpression(
            "/\\\$adapter\s*=\s*\\\$client->get_adapter\(\\\$network\)/",
            $body,
            'F-1: при промахе config — резолвить alias через адаптер.'
        );
        // ОТКАЗ, если сеть не резолвится (нет canonical slug) — не вставляем
        // строку с неканоническим partner/idempotency_key (double-credit).
        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*!\\\$network_config\s*\|\|\s*empty\(\\\$network_config\['slug'\]\)\s*\)\s*\{\s*wp_send_json_error/s",
            $body,
            'F-1: неразрешимая сеть → wp_send_json_error (reject), без INSERT.'
        );
        // $network перезаписывается каноническим slug ДО idempotency_key.
        $pos_canon = strpos($body, '$network        = (string) $network_config[\'slug\'];');
        $pos_key   = strpos($body, "hash('sha256', strtolower(\$network)");
        $this->assertIsInt($pos_canon, 'F-1: $network должен переназначаться на canonical slug.');
        $this->assertIsInt($pos_key, 'idempotency_key hash должен присутствовать.');
        $this->assertLessThan(
            $pos_key,
            $pos_canon,
            'F-1: канонизация $network должна идти ДО вычисления idempotency_key.'
        );
    }
}
