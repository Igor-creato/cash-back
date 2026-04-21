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
    /** Предыдущее значение $GLOBALS['wpdb'] — восстанавливается в tearDown. */
    private mixed $previous_wpdb = null;

    protected function setUp(): void
    {
        // Сброс in-process cache статических audit-записей, чтобы тесты не влияли друг на друга.
        $ref = new \ReflectionProperty(Cashback_Encryption::class, 'reported_decrypt_failures');
        $ref->setAccessible(true);
        $ref->setValue(null, array());

        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previous_wpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->previous_wpdb;
        }
    }

    /**
     * Установить минимальный $wpdb-мок, собирающий insert() вызовы в audit-лог.
     */
    private function installAuditWpdbMock(): object
    {
        $wpdb = new class {
            public string $prefix = 'wp_';
            /** @var array<int, array{table:string, data:array<string,mixed>}> */
            public array $inserts = array();

            public function insert(string $table, array $data, array|string|null $format = null): int|false
            {
                $this->inserts[] = array( 'table' => $table, 'data' => $data );
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

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

    public function test_decrypt_if_ciphertext_returns_empty_on_tampered_ciphertext(): void
    {
        // GCM auth tag отклоняет искажение → fail-safe возвращает ''.
        // Ранее кидал RuntimeException; для wp_option-секретов WSOD на фронте
        // хуже, чем «фича выключилась», и contract перешёл на fail-safe + audit лог.
        if (!Cashback_Encryption::is_configured()) {
            $this->markTestSkipped('Тесту требуется is_configured() === true.');
        }
        $this->installAuditWpdbMock();

        $encrypted = Cashback_Encryption::encrypt_if_needed('sensitive');
        $tampered  = $encrypted . 'X';

        $this->assertSame('', Cashback_Encryption::decrypt_if_ciphertext($tampered));
    }

    public function test_decrypt_if_ciphertext_returns_empty_on_invalid_ciphertext_with_prefix(): void
    {
        // Префикс есть, но payload мусорный → fail-safe возвращает ''.
        if (!Cashback_Encryption::is_configured()) {
            $this->markTestSkipped('Тесту требуется is_configured() === true.');
        }
        $this->installAuditWpdbMock();

        $this->assertSame('', Cashback_Encryption::decrypt_if_ciphertext('ENC:v1:not-base64-!!!'));
    }

    public function test_decrypt_if_ciphertext_returns_empty_when_key_not_configured(): void
    {
        // Запускается с bootstrap-no-encryption-key.php.
        if (Cashback_Encryption::is_configured()) {
            $this->markTestSkipped('Тест требует !is_configured(); запустите с bootstrap-no-encryption-key.php.');
        }
        $this->installAuditWpdbMock();

        $this->assertSame('', Cashback_Encryption::decrypt_if_ciphertext('ENC:v1:anything-goes-here'));
    }

    public function test_decrypt_if_ciphertext_returns_plaintext_when_not_ciphertext_and_no_key(): void
    {
        // Без ключа plaintext (legacy wp_option без префикса) всё равно проходит как есть.
        if (Cashback_Encryption::is_configured()) {
            $this->markTestSkipped('Тест требует !is_configured(); запустите с bootstrap-no-encryption-key.php.');
        }

        $this->assertSame('plain value', Cashback_Encryption::decrypt_if_ciphertext('plain value'));
        $this->assertSame('', Cashback_Encryption::decrypt_if_ciphertext(''));
    }

    public function test_decrypt_if_ciphertext_writes_audit_log_on_decrypt_failure(): void
    {
        // Первый вызов пишет audit-запись; повторный с тем же значением — нет (in-process cache).
        if (!Cashback_Encryption::is_configured()) {
            $this->markTestSkipped('Тесту требуется is_configured() === true.');
        }
        $wpdb = $this->installAuditWpdbMock();

        $encrypted = Cashback_Encryption::encrypt_if_needed('captcha-key');
        $tampered  = $encrypted . 'X';

        $this->assertSame('', Cashback_Encryption::decrypt_if_ciphertext($tampered));
        $this->assertSame('', Cashback_Encryption::decrypt_if_ciphertext($tampered));

        $audit_inserts = array_values(array_filter(
            $wpdb->inserts,
            static fn(array $c): bool => str_contains($c['table'], 'cashback_audit_log')
                && ( $c['data']['action'] ?? null ) === 'option_decrypt_failed'
        ));

        $this->assertCount(
            1,
            $audit_inserts,
            'Audit-запись option_decrypt_failed должна писаться один раз на одинаковое значение'
        );
        $this->assertSame('cashback_encryption', $audit_inserts[0]['data']['entity_type'] ?? null);
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
