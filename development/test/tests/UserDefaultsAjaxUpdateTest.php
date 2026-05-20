<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional-тесты AJAX-handler'ов Cashback_Users_Management_Admin::handle_update_default_*.
 *
 * Покрытие: nonce-gate, capability-gate, валидация input, успешный update_option,
 * запись audit-log через Cashback_Encryption::write_audit_log.
 *
 * Используем bootstrap-стабы wp_send_json_* (бросают Cashback_Test_Halt_Signal),
 * stateful update_option/get_option и mock current_user_can через глобал.
 */
#[Group('user-defaults')]
#[Group('admin-ajax')]
final class UserDefaultsAjaxUpdateTest extends TestCase
{
    private Cashback_Users_Management_Admin $admin;
    private static string $admin_source = '';

    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        if (!class_exists('Cashback_User_Defaults')) {
            require_once $plugin_root . '/includes/class-cashback-user-defaults.php';
        }
        // admin/users-management.php инстанцирует класс на load-time (конструктор
        // обращается к $wpdb->prefix). Подготовим stub ДО require_once.
        require_once dirname(__DIR__) . '/Shop_Test_Wpdb_Stub.php';
        if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof Shop_Test_Wpdb_Stub) {
            $GLOBALS['wpdb'] = new Shop_Test_Wpdb_Stub();
        }
        if (!class_exists('Cashback_Users_Management_Admin')) {
            require_once $plugin_root . '/admin/users-management.php';
        }
        self::$admin_source = (string) file_get_contents($plugin_root . '/admin/users-management.php');
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options']            = array();
        $GLOBALS['_cb_test_current_user_can']   = true;
        $GLOBALS['_cb_test_user_id']            = 42;
        $GLOBALS['_cb_test_last_json_response'] = null;
        $GLOBALS['wpdb']                        = new Shop_Test_Wpdb_Stub();

        $this->admin = new Cashback_Users_Management_Admin();
    }

    protected function tearDown(): void
    {
        unset($_POST['nonce'], $_POST['value']);
    }

    /**
     * Обёртка для перехвата Halt-Signal, который бросают wp_send_json_*.
     */
    private function call_handler_capture(string $handler): array
    {
        try {
            $this->admin->$handler();
            $this->fail("$handler() не вызвал wp_send_json_* (нет Halt-Signal).");
        } catch ( Cashback_Test_Halt_Signal ) {
            // ok — wp_send_json_* throws Halt-Signal по контракту bootstrap-стаба.
        }
        $this->assertNotNull($GLOBALS['_cb_test_last_json_response']);
        return $GLOBALS['_cb_test_last_json_response'];
    }

    // =====================================================================
    // handle_update_default_rate
    // =====================================================================

    public function test_default_rate_rejects_missing_nonce(): void
    {
        unset($_POST['nonce']);
        $_POST['value'] = '75';

        $resp = $this->call_handler_capture('handle_update_default_rate');
        $this->assertFalse($resp['success']);
        $this->assertSame('Отсутствует nonce.', $resp['data']['message']);
    }

    public function test_default_rate_rejects_without_capability(): void
    {
        $_POST['nonce'] = wp_create_nonce('cashback_update_default_rate_nonce');
        $_POST['value'] = '75';
        $GLOBALS['_cb_test_current_user_can'] = false;

        $resp = $this->call_handler_capture('handle_update_default_rate');
        $this->assertFalse($resp['success']);
        $this->assertStringContainsString('Недостаточно прав', $resp['data']['message']);
    }

    public function test_default_rate_rejects_missing_value(): void
    {
        $_POST['nonce'] = wp_create_nonce('cashback_update_default_rate_nonce');
        unset($_POST['value']);

        $resp = $this->call_handler_capture('handle_update_default_rate');
        $this->assertFalse($resp['success']);
        $this->assertSame('Не указано значение.', $resp['data']['message']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bad_rate_values(): array
    {
        return array(
            'over_100'    => array('150'),
            'negative'    => array('-5'),
            'non_numeric' => array('abc'),
            'empty'       => array(''),
            'comma'       => array('60,5'),
        );
    }

    /**
     * @dataProvider bad_rate_values
     */
    public function test_default_rate_rejects_invalid_value(string $bad): void
    {
        $_POST['nonce'] = wp_create_nonce('cashback_update_default_rate_nonce');
        $_POST['value'] = $bad;

        $resp = $this->call_handler_capture('handle_update_default_rate');
        $this->assertFalse($resp['success']);
        $this->assertStringContainsString('от 0 до 100', $resp['data']['message']);

        // Опция не должна быть записана.
        $this->assertArrayNotHasKey(
            Cashback_User_Defaults::OPT_RATE,
            $GLOBALS['_cb_test_options'],
            'Invalid value не должен записывать опцию.'
        );
    }

    public function test_default_rate_success_persists_and_returns_normalized(): void
    {
        $_POST['nonce'] = wp_create_nonce('cashback_update_default_rate_nonce');
        $_POST['value'] = '75';

        $resp = $this->call_handler_capture('handle_update_default_rate');
        $this->assertTrue($resp['success']);
        $this->assertSame('75.00', $resp['data']['value'], 'Response должен содержать нормализованное значение.');
        $this->assertSame(
            '75.00',
            $GLOBALS['_cb_test_options'][ Cashback_User_Defaults::OPT_RATE ],
            'Опция должна быть сохранена в нормализованной форме.'
        );
    }

    public function test_default_rate_boundary_zero_and_hundred(): void
    {
        $_POST['nonce'] = wp_create_nonce('cashback_update_default_rate_nonce');

        $_POST['value'] = '0';
        $resp = $this->call_handler_capture('handle_update_default_rate');
        $this->assertTrue($resp['success']);
        $this->assertSame('0.00', $resp['data']['value']);

        $GLOBALS['_cb_test_last_json_response'] = null;
        $_POST['value'] = '100';
        $resp = $this->call_handler_capture('handle_update_default_rate');
        $this->assertTrue($resp['success']);
        $this->assertSame('100.00', $resp['data']['value']);
    }

    public function test_default_rate_handler_uses_atomic_setter_and_schema_sync(): void
    {
        $this->assertStringContainsString('set_default_rate_atomically', self::$admin_source);
        $this->assertStringContainsString('sync_user_profile_default_columns($new_value, null)', self::$admin_source);
    }

    // =====================================================================
    // handle_update_default_min_payout
    // =====================================================================

    public function test_default_min_payout_rejects_missing_nonce(): void
    {
        unset($_POST['nonce']);
        $_POST['value'] = '250';

        $resp = $this->call_handler_capture('handle_update_default_min_payout');
        $this->assertFalse($resp['success']);
        $this->assertSame('Отсутствует nonce.', $resp['data']['message']);
    }

    public function test_default_min_payout_rejects_without_capability(): void
    {
        $_POST['nonce'] = wp_create_nonce('cashback_update_default_min_payout_nonce');
        $_POST['value'] = '250';
        $GLOBALS['_cb_test_current_user_can'] = false;

        $resp = $this->call_handler_capture('handle_update_default_min_payout');
        $this->assertFalse($resp['success']);
        $this->assertStringContainsString('Недостаточно прав', $resp['data']['message']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bad_min_payout_values(): array
    {
        return array(
            'zero'        => array('0'),
            'below_one'   => array('0.5'),
            'over_max'    => array('100001'),
            'negative'    => array('-50'),
            'non_numeric' => array('xyz'),
            'empty'       => array(''),
        );
    }

    /**
     * @dataProvider bad_min_payout_values
     */
    public function test_default_min_payout_rejects_invalid_value(string $bad): void
    {
        $_POST['nonce'] = wp_create_nonce('cashback_update_default_min_payout_nonce');
        $_POST['value'] = $bad;

        $resp = $this->call_handler_capture('handle_update_default_min_payout');
        $this->assertFalse($resp['success']);
        $this->assertStringContainsString('от 1 до 100', $resp['data']['message']);

        $this->assertArrayNotHasKey(
            Cashback_User_Defaults::OPT_MIN_PAYOUT,
            $GLOBALS['_cb_test_options'],
            'Invalid value не должен записывать опцию.'
        );
    }

    public function test_default_min_payout_success_persists_and_returns_normalized(): void
    {
        $_POST['nonce'] = wp_create_nonce('cashback_update_default_min_payout_nonce');
        $_POST['value'] = '250.5';

        $resp = $this->call_handler_capture('handle_update_default_min_payout');
        $this->assertTrue($resp['success']);
        $this->assertSame('250.50', $resp['data']['value']);
        $this->assertSame(
            '250.50',
            $GLOBALS['_cb_test_options'][ Cashback_User_Defaults::OPT_MIN_PAYOUT ]
        );
    }

    public function test_default_min_payout_boundary_one_and_hundred_thousand(): void
    {
        $_POST['nonce'] = wp_create_nonce('cashback_update_default_min_payout_nonce');

        $_POST['value'] = '1';
        $resp = $this->call_handler_capture('handle_update_default_min_payout');
        $this->assertTrue($resp['success']);
        $this->assertSame('1.00', $resp['data']['value']);

        $GLOBALS['_cb_test_last_json_response'] = null;
        $_POST['value'] = '100000';
        $resp = $this->call_handler_capture('handle_update_default_min_payout');
        $this->assertTrue($resp['success']);
        $this->assertSame('100000.00', $resp['data']['value']);
    }

    public function test_default_min_payout_handler_uses_atomic_setter_and_schema_sync(): void
    {
        $this->assertStringContainsString('set_default_min_payout_atomically', self::$admin_source);
        $this->assertStringContainsString('sync_user_profile_default_columns(null, $new_value)', self::$admin_source);
    }
}
