<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_User_Anonymizer::anonymize() — guard от случайной анонимизации
 * админа. WP сам не даёт удалить last super-admin, но мы дополнительно
 * блокируем анонимизацию любого юзера с manage_options.
 */
#[Group('legal')]
#[Group('user-anonymization')]
final class UserAnonymizeBlocksAdminTest extends TestCase
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
            public function query(string $sql): int|bool { $this->calls[] = array('method'=>'query','sql'=>$sql); return $this->next_query_result; }
            public function prepare(string $q, mixed ...$args): string
            {
                $flat = array();
                foreach ($args as $a) { if (is_array($a)) { foreach ($a as $v) { $flat[] = $v; } } else { $flat[] = $a; } }
                $out=''; $i=0; $len=strlen($q); $idx=0;
                while ($i<$len) {
                    if ($q[$i]==='%' && $i+1<$len) {
                        $spec=$q[$i+1];
                        if (in_array($spec,array('d','s','i','f'),true) && array_key_exists($idx,$flat)) {
                            $v=$flat[$idx];
                            if ($spec==='d') $out.=(string)(int)$v;
                            elseif ($spec==='i') $out.='`'.(string)$v.'`';
                            elseif ($spec==='f') $out.=(string)(float)$v;
                            else $out.="'".str_replace("'","''",(string)$v)."'";
                            $idx++; $i+=2; continue;
                        }
                    }
                    $out.=$q[$i]; $i++;
                }
                return $out;
            }
            public function get_var(string $q, int $col=0, int $row=0): mixed { $this->calls[]=array('method'=>'get_var','sql'=>$q); return null; }
            public function get_row(string $q, string $o='OBJECT', int $y=0): mixed { $this->calls[]=array('method'=>'get_row','sql'=>$q); return null; }
            public function get_results(string $q, string $o='OBJECT'): mixed { $this->calls[]=array('method'=>'get_results','sql'=>$q); return array(); }
            public function insert(string $t, array $d, mixed $f=null): int|false { $this->calls[]=array('method'=>'insert','table'=>$t,'data'=>$d); $this->insert_id=1; return 1; }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function test_anonymize_returns_error_for_admin_user(): void
    {
        // Админ юзер: user_can(15, 'manage_options') = true.
        $GLOBALS['_cb_test_user_can_map'][15] = array('manage_options' => true);

        $result = Cashback_User_Anonymizer::anonymize(15, 1, 'test reason ≥ 20 symbols');

        $this->assertIsArray($result);
        $this->assertFalse($result['ok'], 'anonymize должен вернуть ok=false для админа');

        // P0.4: errors теперь массив структур {table, error}.
        $error_codes = array_column($result['errors'], 'error');
        $this->assertContains(
            'cannot_anonymize_admin',
            $error_codes,
            'errors должен содержать запись с error=cannot_anonymize_admin'
        );
    }

    public function test_anonymize_does_not_modify_db_for_admin_user(): void
    {
        $GLOBALS['_cb_test_user_can_map'][15] = array('manage_options' => true);

        Cashback_User_Anonymizer::anonymize(15, 1, 'test reason ≥ 20 symbols');

        // Никаких UPDATE/DELETE/INSERT не должно быть выполнено.
        foreach ($this->wpdb->calls as $call) {
            if ($call['method'] === 'query' && isset($call['sql'])) {
                $sql = (string) $call['sql'];
                if (preg_match('/^(UPDATE|DELETE\s+FROM|INSERT\s+INTO|TRUNCATE)/i', ltrim($sql)) === 1) {
                    $this->fail("Не должно быть DML для админа: {$sql}");
                }
            }
            if ($call['method'] === 'insert') {
                $this->fail("Не должно быть INSERT для админа: {$call['table']}");
            }
            if ($call['method'] === 'update') {
                $this->fail("Не должно быть UPDATE для админа: {$call['table']}");
            }
        }
        $this->assertTrue(true, 'Никаких DML операций для админа');
    }

    public function test_anonymize_proceeds_for_non_admin(): void
    {
        // Non-admin: user_can(42, 'manage_options') = false.
        $GLOBALS['_cb_test_user_can_map'] = array();

        $result = Cashback_User_Anonymizer::anonymize(42, 1, 'test reason ≥ 20 symbols');

        // Для non-admin — anonymize выполняется (ok=true либо ошибки уровня
        // legal_db, но не cannot_anonymize_admin).
        $error_codes = array_column($result['errors'] ?? array(), 'error');
        $this->assertNotContains('cannot_anonymize_admin', $error_codes);
    }
}
