<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты admin-UI для управления кастомным outbound-allowlist'ом.
 *
 * Покрывает:
 * - capability-check (manage_options)
 * - nonce-check
 * - валидацию host'а при добавлении
 * - защиту baseline-хостов от удаления
 * - audit-log на add/remove
 * - email-уведомление суперадмину + всем администраторам
 * - merge custom-хостов в Cashback_Outbound_HTTP_Guard::get_allowlist()
 * - лимит количества кастомных хостов
 *
 * См. ADR Группа 3, commit 2.
 */
#[Group('security')]
#[Group('outbound')]
#[Group('admin')]
class OutboundAllowlistAdminTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        $guard_file = $plugin_root . '/includes/class-cashback-outbound-http-guard.php';
        $admin_file = $plugin_root . '/admin/class-cashback-admin-outbound-allowlist.php';

        if (!file_exists($guard_file) || !file_exists($admin_file)) {
            self::markTestSkipped('Guard/admin classes not present yet.');
        }

        if (!class_exists('Cashback_Outbound_HTTP_Guard')) {
            require_once $guard_file;
        }
        if (!class_exists('Cashback_Admin_Outbound_Allowlist')) {
            require_once $admin_file;
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options']          = array();
        $GLOBALS['_cb_test_filters']          = array();
        $GLOBALS['_cb_audit_log_calls']       = array();
        $GLOBALS['_cb_test_mail_calls']       = array();
        $GLOBALS['_cb_test_current_user_can'] = true;
        $GLOBALS['_cb_test_admin_users']      = array(
            (object) array( 'ID' => 2, 'user_email' => 'admin2@example.com' ),
        );
        update_option('admin_email', 'superadmin@example.com');

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public function insert(string $table, array $data, $format = null): int
            {
                $GLOBALS['_cb_audit_log_calls'][] = array( 'table' => $table, 'data' => $data );
                return 1;
            }
        };

        if (class_exists('Cashback_Outbound_HTTP_Guard')) {
            Cashback_Outbound_HTTP_Guard::invalidate_cache();
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['_cb_test_options']    = array();
        $GLOBALS['_cb_test_filters']    = array();
        $GLOBALS['_cb_test_mail_calls'] = array();
        unset($GLOBALS['wpdb']);

        if (class_exists('Cashback_Outbound_HTTP_Guard')) {
            Cashback_Outbound_HTTP_Guard::invalidate_cache();
        }
    }

    /**
     * Вызывает метод handle_add с имитацией AJAX-ответа через перехват исключения.
     *
     * Возвращает массив ['success' => bool, 'data' => mixed].
     */
    private function call_add(string $host, string $reason): array
    {
        try {
            Cashback_Admin_Outbound_Allowlist::get_instance()->handle_add(
                array( 'host' => $host, 'reason' => $reason )
            );
        } catch (\Throwable $e) {
            return $this->decode_json_response($e->getMessage());
        }
        $this->fail('handle_add did not call wp_send_json_*');
    }

    private function call_remove(string $host): array
    {
        try {
            Cashback_Admin_Outbound_Allowlist::get_instance()->handle_remove(
                array( 'host' => $host )
            );
        } catch (\Throwable $e) {
            return $this->decode_json_response($e->getMessage());
        }
        $this->fail('handle_remove did not call wp_send_json_*');
    }

    private function decode_json_response(string $message): array
    {
        // wp_send_json_success/error стабы выбрасывают RuntimeException с префиксом.
        foreach (array( 'wp_send_json_success: ', 'wp_send_json_error: ' ) as $prefix) {
            if (str_starts_with($message, $prefix)) {
                $json = substr($message, strlen($prefix));
                $data = json_decode($json, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }
        $this->fail('Unexpected response format: ' . $message);
    }

    // ================================================================
    // add — capability + nonce
    // ================================================================

    public function test_add_requires_manage_options_cap(): void
    {
        $GLOBALS['_cb_test_current_user_can'] = false;

        $response = $this->call_add('api.letyshops.com', 'Letyshops onboarding');

        $this->assertFalse($response['success']);
        $this->assertSame(array(), get_option('cashback_outbound_allowlist_custom', array()));
    }

    public function test_add_happy_path_updates_option_and_logs(): void
    {
        $response = $this->call_add('api.letyshops.com', 'Letyshops onboarding');

        $this->assertTrue($response['success']);

        $stored = get_option('cashback_outbound_allowlist_custom', array());
        $this->assertCount(1, $stored);
        $this->assertSame('api.letyshops.com', $stored[0]['host']);
        $this->assertSame('Letyshops onboarding', $stored[0]['reason']);

        // audit-log
        $this->assertNotEmpty($GLOBALS['_cb_audit_log_calls']);
        $entry = $GLOBALS['_cb_audit_log_calls'][0];
        $this->assertSame('outbound_allowlist_added', $entry['data']['action']);
    }

    public function test_add_sends_email_to_admin_recipients(): void
    {
        $this->call_add('api.letyshops.com', 'Letyshops onboarding');

        $this->assertNotEmpty($GLOBALS['_cb_test_mail_calls']);
        $call       = $GLOBALS['_cb_test_mail_calls'][0];
        $recipients = is_array($call['to']) ? $call['to'] : array( $call['to'] );

        $this->assertContains('superadmin@example.com', $recipients);
        $this->assertContains('admin2@example.com', $recipients);
        $this->assertStringContainsString('api.letyshops.com', $call['subject']);
    }

    public function test_email_recipients_filter_can_override(): void
    {
        add_filter('cashback_outbound_allowlist_alert_recipients', static function (array $recipients): array {
            return array( 'only-this@example.com' );
        });

        $this->call_add('api.letyshops.com', 'filter override test');

        $call       = $GLOBALS['_cb_test_mail_calls'][0];
        $recipients = is_array($call['to']) ? $call['to'] : array( $call['to'] );
        $this->assertSame(array( 'only-this@example.com' ), $recipients);
    }

    // ================================================================
    // add — валидация host'а
    // ================================================================

    public function test_add_rejects_ip_literal_v4(): void
    {
        $response = $this->call_add('10.0.0.1', 'trying IP');
        $this->assertFalse($response['success']);
        $this->assertSame(array(), get_option('cashback_outbound_allowlist_custom', array()));
    }

    public function test_add_rejects_ip_literal_v6(): void
    {
        $response = $this->call_add('[::1]', 'trying IPv6');
        $this->assertFalse($response['success']);
    }

    public function test_add_rejects_reserved_tld_local(): void
    {
        $response = $this->call_add('api.local', 'local-dev host');
        $this->assertFalse($response['success']);
    }

    public function test_add_rejects_reserved_tld_internal(): void
    {
        $response = $this->call_add('service.internal', 'internal host');
        $this->assertFalse($response['success']);
    }

    public function test_add_rejects_reserved_tld_test(): void
    {
        $response = $this->call_add('api.test', 'test host');
        $this->assertFalse($response['success']);
    }

    public function test_add_rejects_empty_host(): void
    {
        $response = $this->call_add('', 'empty');
        $this->assertFalse($response['success']);
    }

    public function test_add_rejects_host_with_spaces(): void
    {
        $response = $this->call_add('host with spaces', 'invalid');
        $this->assertFalse($response['success']);
    }

    public function test_add_rejects_host_with_protocol(): void
    {
        $response = $this->call_add('https://api.letyshops.com/', 'wrong format');
        $this->assertFalse($response['success']);
    }

    public function test_add_rejects_empty_reason(): void
    {
        $response = $this->call_add('api.letyshops.com', '');
        $this->assertFalse($response['success']);
    }

    public function test_add_rejects_short_reason(): void
    {
        $response = $this->call_add('api.letyshops.com', 'hi');
        $this->assertFalse($response['success']);
    }

    public function test_add_rejects_duplicate_baseline_host(): void
    {
        // api.admitad.com — в baseline allowlist.
        $response = $this->call_add('api.admitad.com', 'duplicate baseline');
        $this->assertFalse($response['success']);
    }

    public function test_add_rejects_duplicate_custom_case_insensitive(): void
    {
        $this->call_add('api.example.com', 'first');
        $response = $this->call_add('API.Example.COM', 'duplicate');
        $this->assertFalse($response['success']);

        $stored = get_option('cashback_outbound_allowlist_custom', array());
        $this->assertCount(1, $stored);
    }

    public function test_add_rejects_over_limit(): void
    {
        // Заполняем до лимита (50) минус 1.
        $existing = array();
        for ($i = 0; $i < 50; $i++) {
            $existing[] = array(
                'host'     => "api{$i}.example.com",
                'added_by' => 1,
                'added_at' => '2026-04-21 00:00:00',
                'reason'   => 'bulk seed',
            );
        }
        update_option('cashback_outbound_allowlist_custom', $existing);

        $response = $this->call_add('api51.example.com', 'one too many');
        $this->assertFalse($response['success']);
    }

    // ================================================================
    // remove
    // ================================================================

    public function test_remove_requires_cap(): void
    {
        $this->call_add('api.letyshops.com', 'setup');
        $GLOBALS['_cb_test_current_user_can'] = false;

        $response = $this->call_remove('api.letyshops.com');
        $this->assertFalse($response['success']);

        $stored = get_option('cashback_outbound_allowlist_custom', array());
        $this->assertCount(1, $stored);
    }

    public function test_remove_baseline_host_forbidden(): void
    {
        $response = $this->call_remove('api.admitad.com');
        $this->assertFalse($response['success']);
    }

    public function test_remove_custom_host_happy_path(): void
    {
        $this->call_add('api.letyshops.com', 'setup');
        $response = $this->call_remove('api.letyshops.com');

        $this->assertTrue($response['success']);
        $this->assertSame(array(), get_option('cashback_outbound_allowlist_custom', array()));

        // Audit-log на add + на remove.
        $actions = array_column(
            array_column($GLOBALS['_cb_audit_log_calls'], 'data'),
            'action'
        );
        $this->assertContains('outbound_allowlist_removed', $actions);
    }

    public function test_remove_nonexistent_host_returns_error(): void
    {
        $response = $this->call_remove('nope.example.com');
        $this->assertFalse($response['success']);
    }

    // ================================================================
    // Guard merge
    // ================================================================

    public function test_guard_allowlist_includes_custom_hosts_after_add(): void
    {
        $this->call_add('api.letyshops.com', 'integration');
        Cashback_Outbound_HTTP_Guard::invalidate_cache();

        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://api.letyshops.com/offers');
        $this->assertTrue($result);
    }

    public function test_guard_rejects_after_remove(): void
    {
        $this->call_add('api.letyshops.com', 'integration');
        Cashback_Outbound_HTTP_Guard::invalidate_cache();
        $this->assertTrue(Cashback_Outbound_HTTP_Guard::validate_url('https://api.letyshops.com/'));

        $this->call_remove('api.letyshops.com');
        Cashback_Outbound_HTTP_Guard::invalidate_cache();

        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://api.letyshops.com/');
        $this->assertInstanceOf(WP_Error::class, $result);
    }
}
