<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional-тесты Cashback_Stuck_Transactions_Monitor (универсальный).
 *
 * Обобщает F-1/F-2 на ВСЕ сети + детектор несматчиваемых строк
 * (uniq_id пуст — класс отказа ef32586). Делает ДВА SELECT'а:
 *   (A) completed+api_verified=1+funds_ready=0 — по всем сетям;
 *   (B) uniq_id IS NULL/'' AND created_by_admin=0 — несматчиваемые.
 *
 * Wpdb-stub дискриминирует результат по содержимому prepared-SQL
 * (Shop_Test_Wpdb_Stub.get_results возвращает один набор на все вызовы,
 * а монитор делает два разных запроса).
 *
 * @group monitoring
 * @group readonly
 */
#[Group('monitoring')]
#[Group('readonly')]
final class StuckTransactionsMonitorTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        require_once dirname(__DIR__) . '/Stub_Email_Sender.php';
        require_once dirname(__DIR__) . '/Shop_Test_Wpdb_Stub.php';

        if (!class_exists('Cashback_Stuck_Transactions_Monitor')) {
            require_once self::$plugin_root . '/includes/class-cashback-stuck-transactions-monitor.php';
        }
    }

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = $this->make_discriminating_wpdb(array(), array());
        $GLOBALS['_cb_test_transients']   = array();
        $GLOBALS['_cb_test_options']      = array();
        $GLOBALS['_cb_test_filters']      = array();
        $GLOBALS['_cb_test_as_scheduled'] = false;
        if (class_exists('Cashback_Email_Sender')) {
            Cashback_Email_Sender::reset();
        }
    }

    /**
     * Wpdb-stub, отдающий разные результаты для запроса (A) и (B) по
     * статическим маркерам prepared-SQL. Логирует выполненный SQL.
     *
     * @param array<int,array<string,mixed>> $confirmed_rows   query (A)
     * @param array<int,array<string,mixed>> $unmatchable_rows query (B)
     */
    private function make_discriminating_wpdb(array $confirmed_rows, array $unmatchable_rows): object
    {
        $to_obj = static fn(array $r): object => (object) $r;

        return new class(array_map($to_obj, $confirmed_rows), array_map($to_obj, $unmatchable_rows)) extends Shop_Test_Wpdb_Stub {
            /** @var array<int,object> */
            public array $confirmed;
            /** @var array<int,object> */
            public array $unmatchable;
            /** @var string[] */
            public array $executed_sql = array();

            /**
             * @param array<int,object> $confirmed
             * @param array<int,object> $unmatchable
             */
            public function __construct(array $confirmed, array $unmatchable)
            {
                $this->confirmed   = $confirmed;
                $this->unmatchable = $unmatchable;
            }

            public function get_results(mixed $sql, mixed $output = ARRAY_A): mixed
            {
                $s                    = (string) $sql;
                $this->executed_sql[] = $s;

                // Запрос (A): подтверждённые, funds_ready=0.
                if (strpos($s, "order_status = 'completed'") !== false
                    && strpos($s, 'funds_ready = 0') !== false) {
                    return $this->confirmed;
                }
                // Запрос (B): несматчиваемые, uniq_id пуст.
                if (strpos($s, 'uniq_id IS NULL') !== false) {
                    return $this->unmatchable;
                }
                return array();
            }
        };
    }

    private function row(int $id, string $partner, int $age = 80): array
    {
        return array(
            'id'           => $id,
            'uniq_id'      => 'u-' . $id,
            'partner'      => $partner,
            'order_number' => 'ORD-' . $id,
            'comission'    => '75.00',
            'currency'     => 'RUB',
            'created_at'   => date('Y-m-d H:i:s', time() - $age * 3600),
            'age_hours'    => $age,
        );
    }

    private function unmatchable_row(int $id, string $partner, int $age = 90): array
    {
        $r            = $this->row($id, $partner, $age);
        $r['uniq_id'] = null;
        return $r;
    }

    public function test_no_stuck_clears_notice_and_no_email(): void
    {
        set_transient(Cashback_Stuck_Transactions_Monitor::NOTICE_KEY, array( 'count' => 9 ), DAY_IN_SECONDS);

        $result = Cashback_Stuck_Transactions_Monitor::check();

        $this->assertSame(0, $result);
        $this->assertFalse(get_transient(Cashback_Stuck_Transactions_Monitor::NOTICE_KEY));
        $this->assertSame(array(), Cashback_Email_Sender::$sent_calls);
    }

    public function test_query_a_confirmed_not_credited_all_networks(): void
    {
        global $wpdb;
        $wpdb = $this->make_discriminating_wpdb(
            array( $this->row(11, 'Admitad'), $this->row(12, 'Advcake') ),
            array()
        );

        $result = Cashback_Stuck_Transactions_Monitor::check();

        $this->assertSame(2, $result);
        $notice = get_transient(Cashback_Stuck_Transactions_Monitor::NOTICE_KEY);
        $this->assertIsArray($notice);
        $this->assertSame(2, $notice['count']);
        $this->assertSame(2, $notice['count_confirmed']);
        $this->assertSame(0, $notice['count_unmatchable']);
    }

    public function test_query_b_unmatchable_uniq_id_is_detected(): void
    {
        global $wpdb;
        $wpdb = $this->make_discriminating_wpdb(
            array(),
            array( $this->unmatchable_row(21, 'Admitad'), $this->unmatchable_row(22, 'Admitad') )
        );

        $result = Cashback_Stuck_Transactions_Monitor::check();

        $this->assertSame(2, $result);
        $notice = get_transient(Cashback_Stuck_Transactions_Monitor::NOTICE_KEY);
        $this->assertSame(0, $notice['count_confirmed']);
        $this->assertSame(2, $notice['count_unmatchable']);
    }

    public function test_both_classes_total_and_email_body(): void
    {
        global $wpdb;
        $wpdb = $this->make_discriminating_wpdb(
            array( $this->row(31, 'Admitad') ),
            array( $this->unmatchable_row(32, 'Advcake') )
        );

        $result = Cashback_Stuck_Transactions_Monitor::check();

        $this->assertSame(2, $result);
        $this->assertCount(1, Cashback_Email_Sender::$sent_calls);
        $sent = Cashback_Email_Sender::$sent_calls[0];
        $this->assertSame('stuck_transactions_universal', $sent['type']);
        $this->assertStringContainsString('2 транзакций', $sent['subject']);
        $this->assertStringContainsString('funds_ready=0', $sent['message']);
        $this->assertStringContainsString('ef32586', $sent['message']);
        $this->assertStringContainsString('uniq_id', $sent['message']);
        // Группировка по partner присутствует.
        $this->assertStringContainsString('Admitad', $sent['message']);
        $this->assertStringContainsString('Advcake', $sent['message']);
    }

    public function test_email_throttled_within_12h(): void
    {
        global $wpdb;
        $rows = array( $this->row(41, 'Admitad') );
        $wpdb = $this->make_discriminating_wpdb($rows, array());
        Cashback_Stuck_Transactions_Monitor::check();
        $this->assertCount(1, Cashback_Email_Sender::$sent_calls);

        $wpdb = $this->make_discriminating_wpdb($rows, array());
        Cashback_Stuck_Transactions_Monitor::check();
        $this->assertCount(1, Cashback_Email_Sender::$sent_calls, 'Второй check под throttle — без нового email');

        $stored = (int) get_option(Cashback_Stuck_Transactions_Monitor::EMAIL_THROTTLE, '0');
        $this->assertGreaterThan(time(), $stored);
    }

    public function test_synthetic_like_filter_adds_not_like_to_query_b(): void
    {
        global $wpdb;
        $wpdb = $this->make_discriminating_wpdb(array(), array( $this->unmatchable_row(51, 'Admitad') ));

        add_filter(
            'cashback_stuck_monitor_synthetic_like_patterns',
            static fn(): array => array( 'FXD94%', 'lt-%' ),
            10,
            1
        );

        Cashback_Stuck_Transactions_Monitor::check();

        $b_sql = '';
        foreach ($wpdb->executed_sql as $s) {
            if (strpos($s, 'uniq_id IS NULL') !== false) {
                $b_sql = $s;
                break;
            }
        }
        $this->assertNotSame('', $b_sql, 'Запрос (B) должен был выполниться');
        $this->assertStringContainsString("order_number NOT LIKE 'FXD94%'", $b_sql);
        $this->assertStringContainsString("order_number NOT LIKE 'lt-%'", $b_sql);
    }

    public function test_notice_render_outputs_breakdown_and_threshold(): void
    {
        set_transient(
            Cashback_Stuck_Transactions_Monitor::NOTICE_KEY,
            array( 'count' => 8, 'count_confirmed' => 3, 'count_unmatchable' => 5, 'sample' => array() ),
            DAY_IN_SECONDS
        );

        ob_start();
        Cashback_Stuck_Transactions_Monitor::notice();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('8', $html);
        $this->assertStringContainsString('72', $html); // STUCK_AGE_HOURS
    }

    public function test_notice_no_output_when_transient_missing(): void
    {
        ob_start();
        Cashback_Stuck_Transactions_Monitor::notice();
        $this->assertSame('', (string) ob_get_clean());
    }

    public function test_register_idempotent_uses_as_scheduler(): void
    {
        $GLOBALS['_cb_test_as_scheduled'] = false;

        Cashback_Stuck_Transactions_Monitor::register();

        $scheduled = $GLOBALS['_cb_test_as_scheduled'];
        $this->assertIsArray($scheduled);
        $this->assertSame(Cashback_Stuck_Transactions_Monitor::HOOK_NAME, $scheduled['hook']);
        $this->assertSame(Cashback_Stuck_Transactions_Monitor::CRON_GROUP, $scheduled['group']);
    }

    public function test_notice_sample_capped_at_5(): void
    {
        global $wpdb;
        $confirmed = array();
        for ($i = 1; $i <= 40; $i++) {
            $confirmed[] = $this->row($i, 'Admitad');
        }
        $wpdb = $this->make_discriminating_wpdb($confirmed, array());

        Cashback_Stuck_Transactions_Monitor::check();

        $notice = get_transient(Cashback_Stuck_Transactions_Monitor::NOTICE_KEY);
        $this->assertSame(40, $notice['count']);
        $this->assertCount(5, $notice['sample']);
    }

    /**
     * Read-only гарантия: тело check() не содержит мутирующих tx-запросов
     * (зеркало DedupSelftestTest read-only-инварианта).
     */
    public function test_check_body_is_strictly_read_only(): void
    {
        $src   = (string) file_get_contents(self::$plugin_root . '/includes/class-cashback-stuck-transactions-monitor.php');
        $start = strpos($src, 'public static function check(');
        $this->assertNotFalse($start);
        $brace = strpos($src, '{', $start);
        $depth = 0;
        $len   = strlen($src);
        $body  = '';
        for ($i = $brace; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $body = substr($src, $brace, $i - $brace + 1);
                    break;
                }
            }
        }
        $this->assertNotSame('', $body);

        foreach (array(
            '/\bINSERT\s+INTO\b/i',
            '/\bUPDATE\s+%i\b/i',
            '/\bUPDATE\s+`/i',
            '/\bDELETE\s+FROM\b/i',
            '/->\s*insert\s*\(/i',
            '/->\s*delete\s*\(/i',
        ) as $forbidden) {
            $this->assertDoesNotMatchRegularExpression(
                $forbidden,
                $body,
                'check() обязан быть строго READ-ONLY (мутация tx запрещена): ' . $forbidden
            );
        }
    }
}
