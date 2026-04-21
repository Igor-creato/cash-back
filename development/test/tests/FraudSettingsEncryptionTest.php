<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты шифрования опции cashback_captcha_server_key.
 *
 * Покрывает:
 * - save_bot_settings() → в wp_options лежит ciphertext (ENC:v1:...)
 * - get_captcha_server_key() → возвращает plaintext
 * - get_all_bot_settings() → возвращает plaintext для админ-формы
 * - Backward-compat: plaintext значения читаются как есть
 * - Пустая строка не шифруется (состояние «не настроено»)
 *
 * См. ADR Группа 2, finding F-7-001.
 */
#[Group('encryption')]
#[Group('option_encryption')]
#[Group('fraud_settings')]
class FraudSettingsEncryptionTest extends TestCase
{
    protected function setUp(): void
    {
        // Чистим option-store перед каждым тестом
        $GLOBALS['_cb_test_options'] = array();
    }

    protected function tearDown(): void
    {
        $GLOBALS['_cb_test_options'] = array();
    }

    // ================================================================
    // save_bot_settings() — шифрование при сохранении
    // ================================================================

    public function test_save_bot_settings_stores_captcha_server_key_as_ciphertext(): void
    {
        Cashback_Fraud_Settings::save_bot_settings(array(
            'captcha_server_key' => 'yc-server-secret-123',
        ));

        $stored = get_option('cashback_captcha_server_key', '');
        $this->assertNotSame('yc-server-secret-123', $stored, 'В wp_options должен лежать НЕ plaintext');
        $this->assertStringStartsWith('ENC:v1:', (string) $stored);
    }

    public function test_save_bot_settings_empty_captcha_server_key_is_not_encrypted(): void
    {
        Cashback_Fraud_Settings::save_bot_settings(array(
            'captcha_server_key' => '',
        ));

        $stored = get_option('cashback_captcha_server_key', null);
        $this->assertSame('', $stored, 'Пустая строка = «не настроено», шифровать нечего');
    }

    public function test_save_bot_settings_does_not_encrypt_client_key(): void
    {
        // Client key — публичный, шифрование не нужно
        Cashback_Fraud_Settings::save_bot_settings(array(
            'captcha_client_key' => 'ysc1_public_key_abc',
        ));

        $this->assertSame('ysc1_public_key_abc', get_option('cashback_captcha_client_key', ''));
    }

    // ================================================================
    // get_captcha_server_key() — прозрачная расшифровка
    // ================================================================

    public function test_get_captcha_server_key_returns_plaintext_after_save(): void
    {
        Cashback_Fraud_Settings::save_bot_settings(array(
            'captcha_server_key' => 'plain-secret',
        ));

        $this->assertSame('plain-secret', Cashback_Fraud_Settings::get_captcha_server_key());
    }

    public function test_get_captcha_server_key_backward_compat_reads_plaintext(): void
    {
        // Симулируем старую установку: в БД лежит plaintext без префикса
        update_option('cashback_captcha_server_key', 'legacy-plaintext');

        $this->assertSame('legacy-plaintext', Cashback_Fraud_Settings::get_captcha_server_key());
    }

    public function test_get_captcha_server_key_empty_when_not_configured(): void
    {
        $this->assertSame('', Cashback_Fraud_Settings::get_captcha_server_key());
    }

    public function test_get_captcha_server_key_roundtrip_unicode(): void
    {
        Cashback_Fraud_Settings::save_bot_settings(array(
            'captcha_server_key' => 'секрет-🔐-с-пробелами',
        ));

        $this->assertSame('секрет-🔐-с-пробелами', Cashback_Fraud_Settings::get_captcha_server_key());
    }

    // ================================================================
    // get_all_bot_settings() — админ-форма видит plaintext
    // ================================================================

    public function test_get_all_bot_settings_returns_plaintext_server_key(): void
    {
        Cashback_Fraud_Settings::save_bot_settings(array(
            'captcha_server_key' => 'form-edit-secret',
        ));

        $all = Cashback_Fraud_Settings::get_all_bot_settings();
        $this->assertArrayHasKey('captcha_server_key', $all);
        $this->assertSame(
            'form-edit-secret',
            $all['captcha_server_key'],
            'Админ-форма редактирует plaintext, get_all_bot_settings() должен расшифровать'
        );
    }

    public function test_get_all_bot_settings_backward_compat_plaintext(): void
    {
        update_option('cashback_captcha_server_key', 'legacy-inline');

        $all = Cashback_Fraud_Settings::get_all_bot_settings();
        $this->assertSame('legacy-inline', $all['captcha_server_key']);
    }

    // ================================================================
    // Инварианты
    // ================================================================

    public function test_resave_same_value_keeps_plaintext_accessible(): void
    {
        // Админ открыл форму (увидел plaintext), сохранил без изменений
        Cashback_Fraud_Settings::save_bot_settings(array( 'captcha_server_key' => 'one' ));
        $plaintext = Cashback_Fraud_Settings::get_captcha_server_key();
        Cashback_Fraud_Settings::save_bot_settings(array( 'captcha_server_key' => $plaintext ));

        $this->assertSame('one', Cashback_Fraud_Settings::get_captcha_server_key());
        // Двойного шифрования не произошло — значение осталось корректным
        $stored = (string) get_option('cashback_captcha_server_key', '');
        $this->assertStringStartsWith('ENC:v1:', $stored);
    }

    public function test_captcha_is_configured_true_after_save(): void
    {
        // Cashback_Captcha::is_configured() должна видеть настроенный ключ,
        // независимо от того что в БД лежит ciphertext
        Cashback_Fraud_Settings::save_bot_settings(array(
            'captcha_client_key' => 'client',
            'captcha_server_key' => 'server',
        ));

        $this->assertTrue(Cashback_Captcha::is_configured());
    }
}
