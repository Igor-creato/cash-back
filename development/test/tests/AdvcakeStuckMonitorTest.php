<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional тесты на Cashback_Advcake_Stuck_Monitor (v4.3.4).
 *
 * Закрывает audit-findings F-1 (missing payment_status в XML) и F-2
 * (unknown payment_status value) — оба сценария проявляются одинаково:
 * order_status='completed' AND api_verified=1 AND funds_ready=0 AND age >=72h.
 *
 * @group advcake
 * @group monitoring
 */
#[Group('advcake')]
#[Group('monitoring')]
final class AdvcakeStuckMonitorTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        // Email Sender stub ДО загрузки monitor'а (class_exists('Cashback_Email_Sender')
        // в monitor'е увидит наш stub).
        require_once dirname(__DIR__) . '/Stub_Email_Sender.php';
        require_once dirname(__DIR__) . '/Shop_Test_Wpdb_Stub.php';

        if (!class_exists('Cashback_Advcake_Stuck_Monitor')) {
            require_once self::$plugin_root . '/includes/class-cashback-advcake-stuck-monitor.php';
        }
    }

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new Shop_Test_Wpdb_Stub();
        $GLOBALS['_cb_test_transients']             = array();
        $GLOBALS['_cb_test_options']                = array();
        $GLOBALS['_cb_test_as_scheduled']           = false;
        if (class_exists('Cashback_Email_Sender')) {
            Cashback_Email_Sender::reset();
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows  — rows для SELECT
     */
    private function set_select_rows(array $rows): void
    {
        global $wpdb;
        $objects = array_map(static function (array $r): object {
            return (object) $r;
        }, $rows);
        $wpdb->next_get_results = $objects;
    }

    private function sample_stuck_row(int $id = 100, int $age_hours = 80): array
    {
        return array(
            'id'           => $id,
            'uniq_id'      => 'test-stuck-' . $id,
            'partner'      => 'advcake',
            'order_number' => 'ORD-' . $id,
            'comission'    => '75.00',
            'currency'     => 'RUB',
            'created_at'   => date('Y-m-d H:i:s', time() - $age_hours * 3600),
            'age_hours'    => $age_hours,
        );
    }

    public function test_no_stuck_rows_clears_notice_and_no_email(): void
    {
        set_transient(Cashback_Advcake_Stuck_Monitor::NOTICE_KEY, array( 'count' => 5 ), DAY_IN_SECONDS);
        $this->set_select_rows(array());

        $result = Cashback_Advcake_Stuck_Monitor::check();

        $this->assertSame(0, $result);
        $this->assertFalse(get_transient(Cashback_Advcake_Stuck_Monitor::NOTICE_KEY));
        $this->assertSame(array(), Cashback_Email_Sender::$sent_calls);
    }

    public function test_stuck_rows_set_notice_with_count_and_sample(): void
    {
        $rows = array(
            $this->sample_stuck_row(101, 80),
            $this->sample_stuck_row(102, 81),
            $this->sample_stuck_row(103, 82),
            $this->sample_stuck_row(104, 83),
            $this->sample_stuck_row(105, 84),
            $this->sample_stuck_row(106, 85),
        );
        $this->set_select_rows($rows);

        $result = Cashback_Advcake_Stuck_Monitor::check();

        $this->assertSame(6, $result);
        $notice = get_transient(Cashback_Advcake_Stuck_Monitor::NOTICE_KEY);
        $this->assertIsArray($notice);
        $this->assertSame(6, $notice['count']);
        $this->assertCount(5, $notice['sample'], 'Sample обрезается до 5 для notice payload');
    }

    public function test_stuck_rows_send_admin_email_with_correct_subject_and_body(): void
    {
        $rows = array(
            $this->sample_stuck_row(201, 90),
            $this->sample_stuck_row(202, 91),
        );
        $this->set_select_rows($rows);

        Cashback_Advcake_Stuck_Monitor::check();

        $this->assertCount(1, Cashback_Email_Sender::$sent_calls);
        $sent = Cashback_Email_Sender::$sent_calls[0];
        $this->assertStringContainsString('Advcake', $sent['subject']);
        $this->assertStringContainsString('2 застрявших', $sent['subject']);
        $this->assertSame('advcake_stuck_transactions', $sent['type']);
        $this->assertStringContainsString('test-stuck-201', $sent['message']);
        $this->assertStringContainsString('test-stuck-202', $sent['message']);
        $this->assertStringContainsString('funds_ready=0', $sent['message']);
        $this->assertStringContainsString('whitelist', $sent['message']);
    }

    public function test_email_throttle_prevents_second_email_within_12h(): void
    {
        $rows = array( $this->sample_stuck_row(301, 95) );
        $this->set_select_rows($rows);

        Cashback_Advcake_Stuck_Monitor::check();
        $this->assertCount(1, Cashback_Email_Sender::$sent_calls, 'Первый check шлёт email');

        $this->set_select_rows($rows);
        Cashback_Advcake_Stuck_Monitor::check();
        $this->assertCount(1, Cashback_Email_Sender::$sent_calls, 'Второй check (под throttle) — БЕЗ нового email');
        $this->assertSame(1, get_transient(Cashback_Advcake_Stuck_Monitor::EMAIL_THROTTLE));
    }

    public function test_notice_render_outputs_count_and_threshold(): void
    {
        set_transient(
            Cashback_Advcake_Stuck_Monitor::NOTICE_KEY,
            array( 'count' => 7, 'sample' => array() ),
            DAY_IN_SECONDS
        );

        ob_start();
        Cashback_Advcake_Stuck_Monitor::notice();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('Cashback Advcake', $html);
        $this->assertStringContainsString('7', $html);
        $this->assertStringContainsString('72', $html);  // STUCK_AGE_HOURS
    }

    public function test_notice_no_output_when_transient_missing(): void
    {
        ob_start();
        Cashback_Advcake_Stuck_Monitor::notice();
        $html = (string) ob_get_clean();

        $this->assertSame('', $html);
    }

    public function test_register_idempotent_uses_as_scheduler(): void
    {
        $GLOBALS['_cb_test_as_scheduled'] = false;

        Cashback_Advcake_Stuck_Monitor::register();

        $scheduled = $GLOBALS['_cb_test_as_scheduled'];
        $this->assertIsArray($scheduled);
        $this->assertSame(Cashback_Advcake_Stuck_Monitor::HOOK_NAME, $scheduled['hook']);
        $this->assertSame(Cashback_Advcake_Stuck_Monitor::CRON_GROUP, $scheduled['group']);
    }

    public function test_sample_in_notice_limited_to_5(): void
    {
        $rows = array();
        for ($i = 1; $i <= 50; $i++) {
            $rows[] = $this->sample_stuck_row($i, 75);
        }
        $this->set_select_rows($rows);

        Cashback_Advcake_Stuck_Monitor::check();

        $notice = get_transient(Cashback_Advcake_Stuck_Monitor::NOTICE_KEY);
        $this->assertSame(50, $notice['count']);
        $this->assertCount(5, $notice['sample'], 'Sample обрезается до 5 даже при 50+ stuck-tx');
    }

    public function test_email_includes_up_to_10_tx_in_list(): void
    {
        $rows = array();
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = $this->sample_stuck_row($i, 75);
        }
        $this->set_select_rows($rows);

        Cashback_Advcake_Stuck_Monitor::check();

        $sent = Cashback_Email_Sender::$sent_calls[0];
        // Подсчёт <li>tx_id=N — должно быть ровно 10 (EMAIL_SAMPLE).
        $li_count = substr_count($sent['message'], '<li>tx_id=');
        $this->assertSame(10, $li_count, 'Email содержит max 10 sample-rows');
    }
}
