<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_User_Anonymizer::on_pre_delete_user() — обработчик WP-хука
 * pre_delete_user (priority 5).
 *
 * Контекст: WP-админ заходит в /wp-admin/users.php → «Удалить» → штатный WP
 * пытается DELETE FROM wp_users → падает на FK fk_balance_user. Мы перехватываем
 * хук priority 5 и:
 *   - для админа — early return (WP сам не даст удалить super-admin)
 *   - для юзера с фин-историей — wp_die с инструкцией использовать «Анонимизировать»
 *   - для empty user — выполняем hard_delete_plugin_rows и пускаем дальше
 */
#[Group('legal')]
#[Group('user-anonymization')]
final class UserDeleteFinancialHistoryBlockedTest extends TestCase
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
            /** @var array<int, mixed> */
            public array $next_var_queue = array();
            public int|bool $next_query_result = 1;

            public function suppress_errors(bool $s = true): bool { return false; }
            public function esc_like(string $t): string { return addcslashes($t, '_%\\'); }
            public function query(string $sql): int|bool { $this->calls[]=array('method'=>'query','sql'=>$sql); return $this->next_query_result; }
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
            public function get_var(string $q, int $col=0, int $row=0): mixed
            {
                $this->calls[]=array('method'=>'get_var','sql'=>$q);
                if (!empty($this->next_var_queue)) {
                    return array_shift($this->next_var_queue);
                }
                return null;
            }
            public function get_row(string $q, string $o='OBJECT', int $y=0): mixed { $this->calls[]=array('method'=>'get_row','sql'=>$q); return null; }
            public function get_results(string $q, string $o='OBJECT'): mixed { $this->calls[]=array('method'=>'get_results','sql'=>$q); return array(); }
            public function insert(string $t, array $d, mixed $f=null): int|false { $this->calls[]=array('method'=>'insert','table'=>$t,'data'=>$d); $this->insert_id=1; return 1; }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function test_pre_delete_user_blocks_when_user_has_financial_history(): void
    {
        // Имитируем что в первой же финансовой таблице есть строки → has_financial_history=true.
        // Очерёдь get_var: для каждого SELECT EXISTS возвращаем 1.
        $this->wpdb->next_var_queue = array(1);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/(анонимизи|wp_die)/iu');

        Cashback_User_Anonymizer::on_pre_delete_user(32, null, null);
    }

    public function test_pre_delete_user_calls_hard_delete_for_empty_user(): void
    {
        // has_financial_history=false: все get_var возвращают 0/null,
        // get_row для balance также NULL.
        $this->wpdb->next_var_queue = array(0, 0, 0, 0, 0);

        // Ожидаем что throw НЕ произойдёт.
        Cashback_User_Anonymizer::on_pre_delete_user(99, null, null);

        // Должны быть DELETE-запросы плагиновых таблиц (hard_delete_plugin_rows).
        $delete_sqls = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => $c['method'] === 'query'
                && isset($c['sql'])
                && preg_match('/DELETE\s+FROM/i', (string) $c['sql']) === 1
        );

        $this->assertNotEmpty($delete_sqls, 'Для empty user должны быть DELETE-запросы (hard_delete_plugin_rows)');
    }

    public function test_pre_delete_user_skips_admin(): void
    {
        // Админ — early return, WP сам не даст удалить.
        $GLOBALS['_cb_test_user_can_map'][1] = array('manage_options' => true);

        Cashback_User_Anonymizer::on_pre_delete_user(1, null, null);

        // Никаких DELETE / has_financial_history запросов быть не должно.
        $dml = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => in_array($c['method'], array('query','insert','update','delete'), true)
        );
        $this->assertEmpty($dml, 'Для админа никакой DML не выполняется');
    }
}
