<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_User_Anonymizer::anonymize() — soft-delete для юзеров с финансовой
 * историей. PII скрабится, числа/статусы в финансовых таблицах НЕ трогаются.
 *
 * Контекст: 152-ФЗ ст.9 ч.4 (право на удаление PII) vs 115-ФЗ + ФЗ «О бухучёте» +
 * НК ст.23 + 161-ФЗ (хранение фин. первички ≥ 5 лет) — компромисс через
 * анонимизацию. См. obsidian/knowledge/patterns/user-anonymization.md.
 */
#[Group('legal')]
#[Group('user-anonymization')]
final class UserAnonymizationTest extends TestCase
{
    private object $wpdb;

    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        if (!class_exists('Cashback_Legal_DB')) {
            require_once $plugin_root . '/legal/class-cashback-legal-db.php';
        }
        if (!class_exists('Cashback_Legal_Documents')) {
            require_once $plugin_root . '/legal/class-cashback-legal-documents.php';
        }

        $anonymizer_file = $plugin_root . '/includes/class-cashback-user-anonymizer.php';
        if (!file_exists($anonymizer_file)) {
            // RED phase: класса ещё нет.
            self::markTestSkipped('class-cashback-user-anonymizer.php not yet implemented');
        }
        if (!class_exists('Cashback_User_Anonymizer')) {
            require_once $anonymizer_file;
        }

        // Стабы WP-функций, отсутствующие в bootstrap.php.
        if (!function_exists('user_can')) {
            // phpcs:disable Squiz.PHP.NonExecutableCode.Unreachable
            eval('function user_can(int $user_id, string $capability, mixed ...$args): bool {
                $map = $GLOBALS["_cb_test_user_can_map"] ?? array();
                return (bool) ($map[$user_id][$capability] ?? false);
            }');
            // phpcs:enable
        }
        if (!function_exists('wp_hash_password')) {
            eval('function wp_hash_password(string $password): string { return \'$P$B\' . md5($password); }');
        }
        if (!function_exists('wp_generate_password')) {
            eval('function wp_generate_password(int $length = 12, bool $special = true, bool $extra_special = false): string {
                return bin2hex(random_bytes(max(1, (int) ($length / 2))));
            }');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_user_can_map'] = array();
        $GLOBALS['_cb_test_options']      = array();

        $this->wpdb = $this->createWpdbStub();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    private function createWpdbStub(): object
    {
        return new class {
            public string $prefix = 'wp_';
            public string $users = 'wp_users';
            public string $usermeta = 'wp_usermeta';
            public string $last_error = '';
            public int $rows_affected = 0;
            public int $insert_id = 0;
            /** @var array<int, array{method:string, sql?:string, args?:array<mixed>, table?:string, data?:array<string,mixed>, where?:array<string,mixed>}> */
            public array $calls = array();
            /** @var array<int, mixed> FIFO очередь для get_var. */
            public array $next_var_queue = array();
            /** @var array<int, mixed> FIFO очередь для get_row. */
            public array $next_row_queue = array();
            /** @var array<int, mixed> FIFO очередь для get_results. */
            public array $next_results_queue = array();
            public int|bool $next_query_result = 1;

            public function suppress_errors(bool $suppress = true): bool
            {
                return false;
            }

            public function esc_like(string $text): string
            {
                return addcslashes($text, '_%\\');
            }

            public function get_charset_collate(): string
            {
                return '';
            }

            public function query(string $sql): int|bool
            {
                $this->calls[] = array('method' => 'query', 'sql' => $sql);
                $this->rows_affected = (int) $this->next_query_result;
                return $this->next_query_result;
            }

            public function prepare(string $q, mixed ...$args): string
            {
                $flat = array();
                foreach ($args as $a) {
                    if (is_array($a)) {
                        foreach ($a as $v) {
                            $flat[] = $v;
                        }
                    } else {
                        $flat[] = $a;
                    }
                }
                $out = '';
                $i = 0;
                $len = strlen($q);
                $idx = 0;
                while ($i < $len) {
                    if ($q[$i] === '%' && $i + 1 < $len) {
                        $spec = $q[$i + 1];
                        if (in_array($spec, array('d', 's', 'i', 'f'), true) && array_key_exists($idx, $flat)) {
                            $v = $flat[$idx];
                            if ($spec === 'd') {
                                $out .= (string) (int) $v;
                            } elseif ($spec === 'i') {
                                $out .= '`' . (string) $v . '`';
                            } elseif ($spec === 'f') {
                                $out .= (string) (float) $v;
                            } else {
                                $out .= "'" . str_replace("'", "''", (string) $v) . "'";
                            }
                            $idx++;
                            $i += 2;
                            continue;
                        }
                    }
                    $out .= $q[$i];
                    $i++;
                }
                return $out;
            }

            public function get_var(string $q, int $col = 0, int $row = 0): mixed
            {
                $this->calls[] = array('method' => 'get_var', 'sql' => $q);
                if (!empty($this->next_var_queue)) {
                    return array_shift($this->next_var_queue);
                }
                return null;
            }

            public function get_row(string $q, string $output = 'OBJECT', int $y = 0): mixed
            {
                $this->calls[] = array('method' => 'get_row', 'sql' => $q);
                if (!empty($this->next_row_queue)) {
                    $row = array_shift($this->next_row_queue);
                    return $row;
                }
                return null;
            }

            public function get_results(string $q, string $output = 'OBJECT'): mixed
            {
                $this->calls[] = array('method' => 'get_results', 'sql' => $q);
                if (!empty($this->next_results_queue)) {
                    return array_shift($this->next_results_queue);
                }
                return array();
            }

            /**
             * @param array<string,mixed> $data
             * @param array<int,string>|string $format
             */
            public function insert(string $table, array $data, mixed $format = null): int|false
            {
                $this->calls[] = array('method' => 'insert', 'table' => $table, 'data' => $data);
                $this->insert_id = 1;
                return 1;
            }

            /**
             * @param array<string,mixed> $data
             * @param array<string,mixed> $where
             * @param array<int,string>|string $format
             * @param array<int,string>|string $where_format
             */
            public function update(string $table, array $data, array $where, mixed $format = null, mixed $where_format = null): int|false
            {
                $this->calls[] = array('method' => 'update', 'table' => $table, 'data' => $data, 'where' => $where);
                return 1;
            }
        };
    }

    /**
     * Возвращает все SQL-строки query() — для regex-инспекции.
     *
     * @return array<int, string>
     */
    private function querySqls(): array
    {
        $out = array();
        foreach ($this->wpdb->calls as $call) {
            if ($call['method'] === 'query' && isset($call['sql'])) {
                $out[] = (string) $call['sql'];
            }
        }
        return $out;
    }

    /**
     * Возвращает все SQL-строки query() + insert/update таблиц — для regex-инспекции.
     *
     * @return array<int, string>
     */
    private function allWriteOps(): array
    {
        $out = array();
        foreach ($this->wpdb->calls as $call) {
            if ($call['method'] === 'query' && isset($call['sql'])) {
                $out[] = (string) $call['sql'];
            } elseif ($call['method'] === 'insert' && isset($call['table'])) {
                $out[] = 'INSERT INTO ' . $call['table'];
            } elseif ($call['method'] === 'update' && isset($call['table'])) {
                $out[] = 'UPDATE ' . $call['table'];
            }
        }
        return $out;
    }

    // ────────────────────────────────────────────────────────────
    // anonymize() — happy path
    // ────────────────────────────────────────────────────────────

    public function test_anonymize_returns_ok_for_user_with_financial_history(): void
    {
        $result = Cashback_User_Anonymizer::anonymize(32, 1, 'РКН-запрос #2026-04-29');

        $this->assertIsArray($result);
        $this->assertTrue($result['ok'], 'anonymize должен вернуть ok=true: errors=' . implode('; ', $result['errors'] ?? array()));
        $this->assertGreaterThan(0, $result['tables_scrubbed']);
    }

    public function test_anonymize_overwrites_wp_users_pii_fields(): void
    {
        Cashback_User_Anonymizer::anonymize(32, 1, 'reason ≥20 chars пожалуйста');

        $sqls = $this->querySqls();
        $users_updates = array_filter(
            $sqls,
            static fn(string $s): bool => preg_match('/UPDATE\s+`wp_users`/i', $s) === 1
        );

        $this->assertNotEmpty($users_updates, 'Должен быть UPDATE wp_users');
        $sql = (string) array_values($users_updates)[0];

        $this->assertStringContainsString("'deleted_user_32'", $sql, 'user_login → deleted_user_<ID>');
        $this->assertStringContainsString("'deleted_32@anon.local'", $sql, 'user_email → deleted_<ID>@anon.local');
        $this->assertStringContainsString("'Deleted User #32'", $sql, 'display_name → Deleted User #<ID>');
    }

    public function test_anonymize_deletes_pii_user_meta_keys(): void
    {
        Cashback_User_Anonymizer::anonymize(32, 1, 'reason ≥20 chars пожалуйста');

        $sqls = $this->querySqls();
        $usermeta_deletes = array_filter(
            $sqls,
            static fn(string $s): bool => preg_match('/DELETE\s+FROM\s+`wp_usermeta`/i', $s) === 1
        );

        $this->assertNotEmpty($usermeta_deletes, 'Должны быть DELETE из wp_usermeta');

        $combined = implode("\n", $usermeta_deletes);
        // Точные ключи PII
        $this->assertMatchesRegularExpression('/meta_key\s*=\s*\'first_name\'/', $combined, 'first_name удаляется');
        $this->assertMatchesRegularExpression('/meta_key\s*=\s*\'last_name\'/', $combined, 'last_name удаляется');
        // Префиксы (LIKE 'billing_%' и 'shipping_%')
        $this->assertMatchesRegularExpression('/meta_key\s+LIKE\s+\'billing\\\\_%\'/', $combined, 'billing_* удаляется через LIKE');
        $this->assertMatchesRegularExpression('/meta_key\s+LIKE\s+\'shipping\\\\_%\'/', $combined, 'shipping_* удаляется через LIKE');
    }

    public function test_anonymize_sets_profile_status_to_deleted(): void
    {
        Cashback_User_Anonymizer::anonymize(32, 1, 'reason ≥20 chars пожалуйста');

        $sqls = $this->querySqls();
        $profile_updates = array_filter(
            $sqls,
            static fn(string $s): bool => preg_match('/UPDATE\s+`wp_cashback_user_profile`/i', $s) === 1
        );

        $this->assertNotEmpty($profile_updates, 'Должен быть UPDATE cashback_user_profile');
        $sql = (string) array_values($profile_updates)[0];

        $this->assertStringContainsString("status = 'deleted'", $sql, 'status → deleted');
        $this->assertStringContainsString('encrypted_details = NULL', $sql, 'encrypted_details → NULL');
        $this->assertStringContainsString('payout_account = NULL', $sql, 'payout_account → NULL');
        $this->assertStringContainsString('payout_full_name = NULL', $sql, 'payout_full_name → NULL');
    }

    public function test_anonymize_clears_payout_request_pii(): void
    {
        Cashback_User_Anonymizer::anonymize(32, 1, 'reason ≥20 chars пожалуйста');

        $sqls = $this->querySqls();
        $payout_updates = array_filter(
            $sqls,
            static fn(string $s): bool => preg_match('/UPDATE\s+`wp_cashback_payout_requests`/i', $s) === 1
        );

        $this->assertNotEmpty($payout_updates, 'Должен быть UPDATE cashback_payout_requests');
        $sql = (string) array_values($payout_updates)[0];

        $this->assertStringContainsString('encrypted_details = NULL', $sql);
        $this->assertStringContainsString('payout_account = NULL', $sql);
        // Числа и статусы НЕ трогаются — total_amount/status не должны быть в SET.
        $this->assertStringNotContainsString('total_amount', $sql, 'total_amount НЕ должен модифицироваться');
        $this->assertStringNotContainsString('status', $sql, 'status НЕ должен модифицироваться');
    }

    public function test_anonymize_anonymizes_fingerprint_ip_and_ua(): void
    {
        Cashback_User_Anonymizer::anonymize(32, 1, 'reason ≥20 chars пожалуйста');

        $sqls = $this->querySqls();
        $fp_updates = array_filter(
            $sqls,
            static fn(string $s): bool => preg_match('/UPDATE\s+`wp_cashback_user_fingerprints`/i', $s) === 1
        );

        $this->assertNotEmpty($fp_updates, 'Должен быть UPDATE cashback_user_fingerprints');
        $sql = (string) array_values($fp_updates)[0];

        $this->assertStringContainsString("'0.0.0.0'", $sql, 'ip_address → 0.0.0.0');
        $this->assertStringContainsString('user_agent_hash', $sql, 'user_agent_hash также скрабится');
    }

    public function test_anonymize_does_not_touch_financial_tables(): void
    {
        Cashback_User_Anonymizer::anonymize(32, 1, 'reason ≥20 chars пожалуйста');

        $writes = $this->allWriteOps();

        // Транзакции — числа и статусы хранятся ≥5 лет.
        foreach (
            array(
                'cashback_transactions',
                'cashback_balance_ledger',
                'cashback_user_balance',
                'cashback_affiliate_accruals',
                'cashback_claims',
            ) as $forbidden
        ) {
            foreach ($writes as $sql) {
                if (preg_match('/(UPDATE|DELETE\s+FROM|INSERT\s+INTO)\s+`?wp_' . preg_quote($forbidden, '/') . '`?/i', $sql) === 1) {
                    $this->fail("Анонимизация НЕ должна писать в финансовую таблицу wp_{$forbidden}: {$sql}");
                }
            }
        }
        $this->assertTrue(true, 'Финансовые таблицы не модифицируются');
    }

    public function test_anonymize_writes_audit_log_with_action_user_anonymized(): void
    {
        Cashback_User_Anonymizer::anonymize(32, 7, 'reason ≥20 chars пожалуйста');

        // Cashback_Encryption::write_audit_log → $wpdb->insert(prefix.'cashback_audit_log', ...)
        $audit_inserts = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => $c['method'] === 'insert' && ($c['table'] ?? '') === 'wp_cashback_audit_log'
        );

        $this->assertNotEmpty($audit_inserts, 'Должен быть INSERT в cashback_audit_log');
        $insert = array_values($audit_inserts)[0];

        $this->assertSame('user_anonymized', $insert['data']['action']);
        $this->assertSame(7, (int) $insert['data']['actor_id']);
        $this->assertSame('user', $insert['data']['entity_type']);
        $this->assertSame(32, (int) $insert['data']['entity_id']);
        $this->assertNotEmpty($insert['data']['details']);
    }

    public function test_anonymize_inserts_revoked_consent_log_for_each_consent_type(): void
    {
        $result = Cashback_User_Anonymizer::anonymize(32, 1, 'reason ≥20 chars пожалуйста');

        $consent_inserts = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => $c['method'] === 'insert' && ($c['table'] ?? '') === 'wp_cashback_consent_log'
        );

        $expected_types = Cashback_Legal_Documents::consent_types();
        $this->assertCount(
            count($expected_types),
            $consent_inserts,
            'Должна быть запись revoke в cashback_consent_log для каждого consent_type'
        );

        foreach ($consent_inserts as $insert) {
            $this->assertSame('revoked', $insert['data']['action'], 'action=revoked');
            $this->assertSame(32, (int) $insert['data']['user_id']);
            $this->assertSame('admin_anonymize', $insert['data']['source']);
            $this->assertNotEmpty($insert['data']['request_id'], 'request_id обязателен для UNIQUE');
        }

        $this->assertSame(count($expected_types), $result['consents_revoked']);
    }
}
