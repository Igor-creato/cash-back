<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P0.1: has_financial_history() — корректные имена колонок в SQL.
 *
 * До фикса partner-accruals SQL обращался к несуществующим
 * `referrer_user_id` и `user_id`. После фикса — `referrer_id` и
 * `referred_user_id` (см. affiliate-db.php CREATE TABLE и F-22-003).
 *
 * Покрытие:
 *  - invalid input → false
 *  - все таблицы пустые → false
 *  - hit на main-table user_id → true
 *  - hit на affiliate_accruals.referrer_id → true
 *  - hit на affiliate_accruals.referred_user_id → true
 *  - non-zero balance row → true
 */
#[Group('legal')]
#[Group('user-anonymization')]
final class UserHasFinancialHistoryTest extends TestCase
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
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            /** @var array<int, array<string,mixed>> */
            public array $calls = array();
            /** @var array<int, mixed> FIFO */
            public array $next_var_queue = array();
            /** @var array<int, mixed> FIFO */
            public array $next_row_queue = array();

            public function suppress_errors(bool $suppress = true): bool { return false; }
            public function esc_like(string $text): string { return addcslashes($text, '_%\\'); }
            public function query(string $sql): int|bool { $this->calls[] = array('method'=>'query','sql'=>$sql); return 1; }

            public function prepare(string $q, mixed ...$args): string
            {
                $flat = array();
                foreach ($args as $a) { if (is_array($a)) { foreach ($a as $v) { $flat[] = $v; } } else { $flat[] = $a; } }
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
                if (!empty($this->next_row_queue)) {
                    return array_shift($this->next_row_queue);
                }
                return null;
            }

            public function get_results(string $q, string $o = 'OBJECT'): mixed { return array(); }
            public function insert(string $t, array $d, mixed $f = null): int|false { return 1; }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function test_returns_false_for_invalid_user_id(): void
    {
        $this->assertFalse(Cashback_User_Anonymizer::has_financial_history(0));
        $this->assertFalse(Cashback_User_Anonymizer::has_financial_history(-5));
    }

    public function test_returns_false_when_all_tables_empty_and_balance_zero(): void
    {
        // 4 main tables (balance_ledger, transactions, payout_requests, claims) — все 0.
        // affiliate_accruals — 0. balance row — null.
        $this->wpdb->next_var_queue = array(0, 0, 0, 0, 0);

        $this->assertFalse(Cashback_User_Anonymizer::has_financial_history(32));
    }

    public function test_returns_true_on_main_table_hit(): void
    {
        // Первая main-таблица (cashback_balance_ledger) даёт hit → возврат сразу.
        $this->wpdb->next_var_queue = array(1);

        $this->assertTrue(Cashback_User_Anonymizer::has_financial_history(32));
    }

    public function test_returns_true_on_affiliate_accruals_hit(): void
    {
        // 4 main tables = 0, accruals = 1.
        $this->wpdb->next_var_queue = array(0, 0, 0, 0, 1);

        $this->assertTrue(Cashback_User_Anonymizer::has_financial_history(32));
    }

    public function test_affiliate_accruals_sql_uses_correct_column_names(): void
    {
        // 4 main = 0, accruals = 0, balance = null.
        $this->wpdb->next_var_queue = array(0, 0, 0, 0, 0);

        Cashback_User_Anonymizer::has_financial_history(32);

        // Найдём SQL-запрос affiliate_accruals и проверим колонки.
        $accruals_sqls = array_values(array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => $c['method'] === 'get_var' && isset($c['sql'])
                && preg_match('/wp_cashback_affiliate_accruals/i', (string) $c['sql']) === 1
        ));
        $this->assertNotEmpty($accruals_sqls, 'Должен быть SQL по cashback_affiliate_accruals');
        $sql = (string) $accruals_sqls[0]['sql'];

        $this->assertStringNotContainsStringIgnoringCase(
            'referrer_user_id',
            $sql,
            'НЕ должно быть несуществующей колонки referrer_user_id (P0.1 fix)'
        );
        $this->assertMatchesRegularExpression(
            '/referrer_id\s*=\s*32/i',
            $sql,
            'WHERE referrer_id=32 — реальная колонка'
        );
        $this->assertMatchesRegularExpression(
            '/referred_user_id\s*=\s*32/i',
            $sql,
            'WHERE referred_user_id=32 — реальная колонка'
        );
    }

    public function test_returns_true_when_balance_row_has_non_zero_available(): void
    {
        // 4 main + accruals = 0, потом get_row с available_balance > 0.
        $this->wpdb->next_var_queue = array(0, 0, 0, 0, 0);
        $this->wpdb->next_row_queue = array(
            array(
                'available_balance'           => '100.50',
                'pending_balance'             => '0.00',
                'paid_balance'                => '0.00',
                'frozen_balance'              => '0.00',
                'frozen_balance_ban'          => '0.00',
                'frozen_balance_payout'       => '0.00',
                'frozen_pending_balance_ban'  => '0.00',
            ),
        );

        $this->assertTrue(Cashback_User_Anonymizer::has_financial_history(32));
    }

    public function test_returns_false_when_balance_row_all_zero(): void
    {
        $this->wpdb->next_var_queue = array(0, 0, 0, 0, 0);
        $this->wpdb->next_row_queue = array(
            array(
                'available_balance'           => '0.00',
                'pending_balance'             => '0.00',
                'paid_balance'                => '0.00',
                'frozen_balance'              => '0.00',
                'frozen_balance_ban'          => '0.00',
                'frozen_balance_payout'       => '0.00',
                'frozen_pending_balance_ban'  => '0.00',
            ),
        );

        $this->assertFalse(Cashback_User_Anonymizer::has_financial_history(32));
    }
}
