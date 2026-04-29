<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_User_Anonymizer::hard_delete_plugin_rows() — для empty user
 * (без финансовой истории) WP может физически удалить юзера, но FK на
 * cashback_user_balance/profile/etc. блокируют DELETE. Поэтому перед штатным
 * wp_delete_user сначала очищаем плагиновые строки.
 */
#[Group('legal')]
#[Group('user-anonymization')]
final class UserHardDeleteTest extends TestCase
{
    private object $wpdb;

    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        $anonymizer_file = $plugin_root . '/includes/class-cashback-user-anonymizer.php';
        if (!file_exists($anonymizer_file)) {
            self::markTestSkipped('class-cashback-user-anonymizer.php not yet implemented');
        }
        if (!class_exists('Cashback_User_Anonymizer')) {
            require_once $anonymizer_file;
        }

        if (!function_exists('user_can')) {
            eval('function user_can(int $user_id, string $capability, mixed ...$args): bool {
                $map = $GLOBALS["_cb_test_user_can_map"] ?? array();
                return (bool) ($map[$user_id][$capability] ?? false);
            }');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_user_can_map'] = array();

        $this->wpdb = new class {
            public string $prefix = 'wp_';
            public string $users = 'wp_users';
            public string $usermeta = 'wp_usermeta';
            public string $last_error = '';
            public int $insert_id = 0;
            /** @var array<int, array<string,mixed>> */
            public array $calls = array();
            public int|bool $next_query_result = 1;

            public function suppress_errors(bool $suppress = true): bool { return false; }
            public function esc_like(string $text): string { return addcslashes($text, '_%\\'); }

            public function query(string $sql): int|bool
            {
                $this->calls[] = array('method' => 'query', 'sql' => $sql);
                return $this->next_query_result;
            }

            public function prepare(string $q, mixed ...$args): string
            {
                $flat = array();
                foreach ($args as $a) {
                    if (is_array($a)) { foreach ($a as $v) { $flat[] = $v; } } else { $flat[] = $a; }
                }
                $out = ''; $i = 0; $len = strlen($q); $idx = 0;
                while ($i < $len) {
                    if ($q[$i] === '%' && $i + 1 < $len) {
                        $spec = $q[$i + 1];
                        if (in_array($spec, array('d','s','i','f'), true) && array_key_exists($idx, $flat)) {
                            $v = $flat[$idx];
                            if ($spec === 'd') { $out .= (string) (int) $v; }
                            elseif ($spec === 'i') { $out .= '`' . (string) $v . '`'; }
                            elseif ($spec === 'f') { $out .= (string) (float) $v; }
                            else { $out .= "'" . str_replace("'","''", (string) $v) . "'"; }
                            $idx++; $i += 2; continue;
                        }
                    }
                    $out .= $q[$i]; $i++;
                }
                return $out;
            }

            public function get_var(string $q, int $col = 0, int $row = 0): mixed
            {
                $this->calls[] = array('method' => 'get_var', 'sql' => $q);
                return null;
            }

            public function get_row(string $q, string $output = 'OBJECT', int $y = 0): mixed
            {
                $this->calls[] = array('method' => 'get_row', 'sql' => $q);
                return null;
            }

            public function get_results(string $q, string $output = 'OBJECT'): mixed
            {
                $this->calls[] = array('method' => 'get_results', 'sql' => $q);
                return array();
            }

            public function insert(string $table, array $data, mixed $format = null): int|false
            {
                $this->calls[] = array('method' => 'insert', 'table' => $table, 'data' => $data);
                $this->insert_id = 1;
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    /**
     * @return array<int,string>
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

    public function test_hard_delete_returns_ok(): void
    {
        $result = Cashback_User_Anonymizer::hard_delete_plugin_rows(99);

        $this->assertIsArray($result);
        $this->assertTrue($result['ok']);
        $this->assertGreaterThan(0, $result['tables_cleaned']);
    }

    public function test_hard_delete_removes_rows_from_user_profile(): void
    {
        Cashback_User_Anonymizer::hard_delete_plugin_rows(99);

        $sqls = $this->querySqls();
        $matches = array_filter(
            $sqls,
            static fn(string $s): bool => preg_match('/DELETE\s+FROM\s+`wp_cashback_user_profile`\s+WHERE\s+user_id\s*=\s*99/i', $s) === 1
        );
        $this->assertNotEmpty($matches, 'Должен быть DELETE из cashback_user_profile WHERE user_id=99');
    }

    public function test_hard_delete_removes_rows_from_user_balance(): void
    {
        Cashback_User_Anonymizer::hard_delete_plugin_rows(99);

        $sqls = $this->querySqls();
        // Empty user — ledger пустой, balance запись должна быть удалена,
        // чтобы FK fk_balance_user не блокировал wp_delete_user.
        $matches = array_filter(
            $sqls,
            static fn(string $s): bool => preg_match('/DELETE\s+FROM\s+`wp_cashback_user_balance`\s+WHERE\s+user_id\s*=\s*99/i', $s) === 1
        );
        $this->assertNotEmpty($matches, 'Должен быть DELETE из cashback_user_balance');
    }

    public function test_hard_delete_writes_audit_log_with_action_user_hard_deleted(): void
    {
        Cashback_User_Anonymizer::hard_delete_plugin_rows(99);

        $audit_inserts = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => $c['method'] === 'insert' && ($c['table'] ?? '') === 'wp_cashback_audit_log'
        );

        $this->assertNotEmpty($audit_inserts, 'Должен быть INSERT в cashback_audit_log');
        $insert = array_values($audit_inserts)[0];

        $this->assertSame('user_hard_deleted', $insert['data']['action']);
        $this->assertSame(99, (int) $insert['data']['entity_id']);
    }

    public function test_hard_delete_does_not_insert_consent_log(): void
    {
        Cashback_User_Anonymizer::hard_delete_plugin_rows(99);

        $consent_inserts = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => $c['method'] === 'insert' && ($c['table'] ?? '') === 'wp_cashback_consent_log'
        );

        $this->assertEmpty(
            $consent_inserts,
            'Hard-delete для empty user НЕ пишет revoke в consent_log (PD не обрабатывались)'
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // Regression-guard для E2E 2026-04-29: hard_delete_plugin_rows должен
    // использовать ту же schema-aware логику что и delete_social_auth_rows
    // в anonymize() (баги #3 и #4 были в обоих местах, но изначально фиксили
    // только anonymize-путь).
    // ────────────────────────────────────────────────────────────────────

    public function test_hard_delete_does_not_use_user_id_for_social_tokens(): void
    {
        // social_tokens.user_id не существует — должен быть JOIN на social_links.
        Cashback_User_Anonymizer::hard_delete_plugin_rows(99);

        $sqls = $this->querySqls();
        $bad = array_filter(
            $sqls,
            static fn(string $s): bool => preg_match('/DELETE\s+FROM\s+`wp_cashback_social_tokens`\s+WHERE\s+user_id/i', $s) === 1
        );
        $this->assertEmpty($bad, 'social_tokens НЕ должен иметь DELETE WHERE user_id (нет колонки user_id в схеме)');
    }

    public function test_hard_delete_uses_join_for_social_tokens(): void
    {
        Cashback_User_Anonymizer::hard_delete_plugin_rows(99);

        $sqls = $this->querySqls();
        $join = array_filter(
            $sqls,
            static fn(string $s): bool => preg_match('/DELETE\s+t\s+FROM\s+`wp_cashback_social_tokens`/i', $s) === 1
                && preg_match('/INNER\s+JOIN\s+`wp_cashback_social_links`/i', $s) === 1
                && preg_match('/l\.user_id\s*=\s*99/i', $s) === 1
        );
        $this->assertNotEmpty($join, 'social_tokens должен чиститься через JOIN на social_links по l.user_id');
    }

    public function test_hard_delete_does_not_touch_social_pending(): void
    {
        // social_pending не имеет user_id (короткоживущие заявки с TTL).
        Cashback_User_Anonymizer::hard_delete_plugin_rows(99);

        foreach ($this->querySqls() as $sql) {
            $this->assertStringNotContainsStringIgnoringCase(
                'cashback_social_pending',
                $sql,
                'cashback_social_pending не должен фигурировать в hard_delete_plugin_rows'
            );
        }
    }
}
