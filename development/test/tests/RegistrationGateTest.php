<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Registration_Gate.
 *
 * Покрывает:
 *  - is_allowed() читает users_can_register (1 → true, 0 → false, отсутствует → false)
 *  - denial_message() возвращает локализованную строку
 *  - denial_wp_error() имеет правильный code и HTTP-статус 403
 */
#[Group('registration-gate')]
final class RegistrationGateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/includes/auth/class-cashback-registration-gate.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options'] = array();
    }

    public function test_is_allowed_returns_true_when_option_is_1(): void
    {
        update_option('users_can_register', 1);
        $this->assertTrue(Cashback_Registration_Gate::is_allowed());
    }

    public function test_is_allowed_returns_false_when_option_is_0(): void
    {
        update_option('users_can_register', 0);
        $this->assertFalse(Cashback_Registration_Gate::is_allowed());
    }

    public function test_is_allowed_returns_false_when_option_missing(): void
    {
        $this->assertFalse(Cashback_Registration_Gate::is_allowed());
    }

    public function test_is_allowed_returns_false_for_non_int_truthy(): void
    {
        // Любое значение кроме int(1) трактуется как «не разрешено».
        // (Защита от тайных string '1' через update_option где-нибудь в legacy.)
        update_option('users_can_register', '1');
        $this->assertTrue(Cashback_Registration_Gate::is_allowed(), 'string "1" приводится к int(1)');

        update_option('users_can_register', true);
        $this->assertTrue(Cashback_Registration_Gate::is_allowed(), 'bool true приводится к int(1)');

        update_option('users_can_register', 2);
        $this->assertFalse(Cashback_Registration_Gate::is_allowed(), 'int(2) не равен int(1)');
    }

    public function test_denial_message_returns_non_empty_localized_string(): void
    {
        $msg = Cashback_Registration_Gate::denial_message();
        $this->assertIsString($msg);
        $this->assertNotEmpty($msg);
        $this->assertStringContainsString('Регистрация', $msg);
    }

    public function test_denial_wp_error_has_registration_disabled_code_and_403_status(): void
    {
        $err = Cashback_Registration_Gate::denial_wp_error();
        $this->assertInstanceOf(WP_Error::class, $err);
        $this->assertSame('registration_disabled', $err->get_error_code());
        $this->assertSame(Cashback_Registration_Gate::denial_message(), $err->get_error_message());
        $data = $err->get_error_data();
        $this->assertIsArray($data);
        $this->assertSame(403, $data['status'] ?? null);
    }

    public function test_error_code_constant_matches_wp_error(): void
    {
        $this->assertSame(
            Cashback_Registration_Gate::ERROR_CODE,
            Cashback_Registration_Gate::denial_wp_error()->get_error_code()
        );
    }
}
