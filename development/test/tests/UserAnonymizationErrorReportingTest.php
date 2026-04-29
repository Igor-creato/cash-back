<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P0.4: Error collection вместо silent swallow.
 *
 * До фикса все scrub_/delete_-helper'ы возвращали 1 безусловно, а $wpdb->last_error
 * игнорировался. Админ видел `tables_scrubbed=12, ok=true`, даже если
 * половина SQL'ей падала с Unknown column / Table doesn't exist.
 *
 * После фикса:
 *  - каждый helper после $wpdb->query() проверяет $wpdb->last_error;
 *  - non-empty error пушится в внутренний buffer (table+error);
 *  - tables_scrubbed считает только успешные;
 *  - анонимизация всё равно коммитится (partial=true), но в audit_log
 *    details включается ['errors' => [...], 'partial' => true].
 */
#[Group('legal')]
#[Group('user-anonymization')]
final class UserAnonymizationErrorReportingTest extends TestCase
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

        $this->wpdb = $this->createWpdbStub();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    /**
     * wpdb-stub с программируемой очередью last_error по индексу query()-вызова.
     *
     * $next_errors_for_query[N] = 'error string' — N-ый query() выставит last_error.
     */
    private function createWpdbStub(): object
    {
        return new class {
            public string $prefix = 'wp_';
            public string $users = 'wp_users';
            public string $usermeta = 'wp_usermeta';
            public string $last_error = '';
            public int $insert_id = 0;
            /** @var array<int, array<string,mixed>> */
            public array $calls = array();
            /** @var array<int, mixed> */
            public array $next_var_queue = array();
            /** @var array<int, string> N → error для N-го query() */
            public array $next_errors_for_query = array();
            public int $query_counter = 0;

            public function suppress_errors(bool $suppress = true): bool { return false; }
            public function esc_like(string $text): string { return addcslashes($text, '_%\\'); }

            public function query(string $sql): int|bool
            {
                $idx = $this->query_counter++;
                $this->calls[] = array('method' => 'query', 'sql' => $sql);
                if (isset($this->next_errors_for_query[$idx])) {
                    $this->last_error = $this->next_errors_for_query[$idx];
                    return false;
                }
                // Сбрасывать last_error НЕ нужно — production wpdb так и работает,
                // anonymizer сам делает $wpdb->last_error = '' перед каждым query.
                return 1;
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
                            if ($spec === 'd') { $out .= (string)(int)$v; }
                            elseif ($spec === 'i') { $out .= '`' . (string)$v . '`'; }
                            elseif ($spec === 'f') { $out .= (string)(float)$v; }
                            else { $out .= "'" . str_replace("'","''",(string)$v) . "'"; }
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
                if (!empty($this->next_var_queue)) {
                    return array_shift($this->next_var_queue);
                }
                return null;
            }

            public function get_row(string $q, string $o = 'OBJECT', int $y = 0): mixed
            {
                $this->calls[] = array('method' => 'get_row', 'sql' => $q);
                return null;
            }

            public function get_results(string $q, string $o = 'OBJECT'): mixed
            {
                $this->calls[] = array('method' => 'get_results', 'sql' => $q);
                return array();
            }

            public function insert(string $t, array $d, mixed $f = null): int|false
            {
                $this->calls[] = array('method' => 'insert', 'table' => $t, 'data' => $d);
                $this->insert_id = 1;
                return 1;
            }
        };
    }

    public function test_anonymize_returns_partial_with_errors_when_one_table_fails(): void
    {
        // Симулируем: один SQL фейлит на schema mismatch. Подберём произвольный
        // index — на 5-ый query (cashback_user_profile UPDATE примерно). Точный
        // индекс не критичен — важно что anonymize() распознаёт last_error.
        $this->wpdb->next_errors_for_query[3] = "Unknown column 'foo' in 'WHERE'";

        $result = Cashback_User_Anonymizer::anonymize(32, 1, 'reason ≥20 chars пожалуйста');

        $this->assertTrue($result['ok'], 'partial-fail всё равно ok=true (PII частично стёрт, rollback опасен)');
        $this->assertArrayHasKey('partial', $result);
        $this->assertTrue($result['partial'], 'partial должен быть true когда есть ошибки');
        $this->assertNotEmpty($result['errors'], 'errors должен содержать запись о фейле');
    }

    public function test_anonymize_errors_have_table_and_error_keys(): void
    {
        $this->wpdb->next_errors_for_query[3] = "Unknown column 'sender_id'";

        $result = Cashback_User_Anonymizer::anonymize(32, 1, 'reason ≥20 chars пожалуйста');

        foreach ($result['errors'] as $err) {
            $this->assertIsArray($err, 'error должен быть массивом {table, error}');
            $this->assertArrayHasKey('table', $err);
            $this->assertArrayHasKey('error', $err);
            $this->assertIsString($err['table']);
            $this->assertIsString($err['error']);
        }
    }

    public function test_anonymize_tables_scrubbed_excludes_failed_tables(): void
    {
        // Без ошибок — tables_scrubbed = 11 (все helpers).
        $result_clean = Cashback_User_Anonymizer::anonymize(32, 1, 'reason ≥20 chars пожалуйста');
        $clean_count = (int) $result_clean['tables_scrubbed'];

        // Со сломанным первым query — tables_scrubbed строго меньше.
        $this->wpdb = $this->createWpdbStub();
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->wpdb->next_errors_for_query[3] = "Unknown column 'foo'";

        $result_partial = Cashback_User_Anonymizer::anonymize(32, 1, 'reason ≥20 chars пожалуйста');
        $partial_count = (int) $result_partial['tables_scrubbed'];

        $this->assertLessThan(
            $clean_count,
            $partial_count,
            'tables_scrubbed при partial-fail должен быть меньше чем при clean-run'
        );
    }

    public function test_anonymize_writes_errors_to_audit_log_details_when_partial(): void
    {
        $this->wpdb->next_errors_for_query[3] = "Unknown column 'foo'";

        Cashback_User_Anonymizer::anonymize(32, 7, 'reason ≥20 chars пожалуйста');

        $audit_inserts = array_values(array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => $c['method'] === 'insert' && ($c['table'] ?? '') === 'wp_cashback_audit_log'
        ));
        $this->assertNotEmpty($audit_inserts, 'audit-запись должна быть даже при partial');

        $details_raw = $audit_inserts[0]['data']['details'] ?? '';
        $details_str = is_string($details_raw) ? $details_raw : (string) wp_json_encode($details_raw);

        $this->assertStringContainsString('partial', $details_str, 'audit details должен содержать partial-маркер');
        $this->assertStringContainsString('errors', $details_str, 'audit details должен содержать ключ errors');
    }
}
