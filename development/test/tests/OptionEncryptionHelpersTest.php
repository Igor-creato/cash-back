<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты для option-encryption helpers в Cashback_Encryption.
 *
 * Покрывает:
 * - encrypt_if_needed() — шифрует plaintext, пустую строку пропускает
 * - decrypt_if_ciphertext() — расшифровывает ENC:v1:, plaintext пропускает
 * - is_option_ciphertext() — чистый префикс-детектор
 *
 * См. ADR Группа 2 (security-refactor-plan-2026-04-21.md), F-7-001 / F-13-001.
 */
#[Group('encryption')]
#[Group('option_encryption')]
class OptionEncryptionHelpersTest extends TestCase
{
    // ================================================================
    // ТЕСТЫ: encrypt_if_needed()
    // ================================================================

    public function test_encrypt_if_needed_wraps_plaintext_with_enc_v1_prefix(): void
    {
        $encrypted = Cashback_Encryption::encrypt_if_needed('secret-value');
        $this->assertStringStartsWith('ENC:v1:', $encrypted);
    }

    public function test_encrypt_if_needed_empty_string_passes_through(): void
    {
        // Пустая строка = «не настроено» — шифровать нечего
        $this->assertSame('', Cashback_Encryption::encrypt_if_needed(''));
    }

    public function test_encrypt_if_needed_each_call_produces_fresh_iv(): void
    {
        // Одинаковый plaintext → разные ciphertext (IV рандомный)
        $enc1 = Cashback_Encryption::encrypt_if_needed('same');
        $enc2 = Cashback_Encryption::encrypt_if_needed('same');
        $this->assertNotSame($enc1, $enc2);
    }

    // ================================================================
    // ТЕСТЫ: decrypt_if_ciphertext()
    // ================================================================

    public function test_decrypt_if_ciphertext_roundtrip(): void
    {
        $plaintext = 'captcha-server-key-12345';
        $encrypted = Cashback_Encryption::encrypt_if_needed($plaintext);
        $this->assertSame($plaintext, Cashback_Encryption::decrypt_if_ciphertext($encrypted));
    }

    public function test_decrypt_if_ciphertext_returns_plaintext_as_is_without_prefix(): void
    {
        // Backward-compat: legacy значение без префикса — вернуть как есть
        $this->assertSame('legacy-plaintext', Cashback_Encryption::decrypt_if_ciphertext('legacy-plaintext'));
    }

    public function test_decrypt_if_ciphertext_empty_string_passes_through(): void
    {
        $this->assertSame('', Cashback_Encryption::decrypt_if_ciphertext(''));
    }

    public function test_decrypt_if_ciphertext_unicode_roundtrip(): void
    {
        $plaintext = 'Секретный ключ 🔐 with spaces';
        $encrypted = Cashback_Encryption::encrypt_if_needed($plaintext);
        $this->assertSame($plaintext, Cashback_Encryption::decrypt_if_ciphertext($encrypted));
    }

    public function test_decrypt_if_ciphertext_throws_on_tampered_ciphertext(): void
    {
        // GCM auth tag должен отклонить искажение
        $encrypted = Cashback_Encryption::encrypt_if_needed('sensitive');
        $tampered  = $encrypted . 'X';

        $this->expectException(\RuntimeException::class);
        Cashback_Encryption::decrypt_if_ciphertext($tampered);
    }

    public function test_decrypt_if_ciphertext_throws_on_invalid_ciphertext_with_prefix(): void
    {
        // Префикс есть, но payload мусорный
        $this->expectException(\RuntimeException::class);
        Cashback_Encryption::decrypt_if_ciphertext('ENC:v1:not-base64-!!!');
    }

    // ================================================================
    // ТЕСТЫ: is_option_ciphertext()
    // ================================================================

    public function test_is_option_ciphertext_true_for_enc_v1_prefix(): void
    {
        $encrypted = Cashback_Encryption::encrypt_if_needed('x');
        $this->assertTrue(Cashback_Encryption::is_option_ciphertext($encrypted));
    }

    public function test_is_option_ciphertext_false_for_plaintext(): void
    {
        $this->assertFalse(Cashback_Encryption::is_option_ciphertext('plaintext'));
        $this->assertFalse(Cashback_Encryption::is_option_ciphertext(''));
        // v2: — это внутренний префикс encrypt(); сам по себе в wp_option не встречается
        $this->assertFalse(Cashback_Encryption::is_option_ciphertext('v2:abcdef'));
    }

    public function test_is_option_ciphertext_case_sensitive(): void
    {
        // Префикс чётко ENC:v1: — любой другой регистр = plaintext
        $this->assertFalse(Cashback_Encryption::is_option_ciphertext('enc:v1:payload'));
    }

    // ================================================================
    // ТЕСТЫ: интеграция helpers ↔ encrypt()/decrypt()
    // ================================================================

    public function test_encrypt_if_needed_payload_is_decryptable_via_decrypt(): void
    {
        // Payload внутри ENC:v1:... — это обычный v2:... encrypt() output
        $plaintext  = 'test';
        $envelope   = Cashback_Encryption::encrypt_if_needed($plaintext);
        $this->assertStringStartsWith('ENC:v1:', $envelope);

        $inner = substr($envelope, strlen('ENC:v1:'));
        $this->assertStringStartsWith('v2:', $inner);
        $this->assertSame($plaintext, Cashback_Encryption::decrypt($inner));
    }
}
