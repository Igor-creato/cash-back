<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты для Cashback_Encryption
 *
 * Покрывает:
 * - AES-256-GCM шифрование/дешифрование (v2)
 * - AES-256-CBC legacy дешифрование (v1)
 * - Маскировку номеров счётов и ФИО
 * - SHA-256 хеширование реквизитов
 * - Получение IP клиента
 * - Определение legacy-формата
 */
#[Group('encryption')]
class EncryptionTest extends TestCase
{
    // ================================================================
    // ТЕСТЫ: is_configured()
    // ================================================================

    public function test_is_configured_with_valid_key(): void
    {
        // CB_ENCRYPTION_KEY определён в bootstrap.php как 64 hex-символа
        $this->assertTrue(Cashback_Encryption::is_configured());
    }

    // ================================================================
    // ТЕСТЫ: encrypt() / decrypt() — AES-256-GCM (v2)
    // ================================================================

    public function test_encrypt_returns_v2_prefix(): void
    {
        $encrypted = Cashback_Encryption::encrypt('hello world');
        $this->assertStringStartsWith('v2:', $encrypted);
    }

    public function test_encrypt_decrypt_roundtrip(): void
    {
        $plaintext = 'Test message для шифрования';
        $encrypted = Cashback_Encryption::encrypt($plaintext);
        $decrypted = Cashback_Encryption::decrypt($encrypted);

        $this->assertSame($plaintext, $decrypted);
    }

    public function test_encrypt_produces_different_ciphertexts(): void
    {
        // Каждое шифрование должно давать разный IV → разный шифртекст
        $plaintext = 'same plaintext';
        $enc1 = Cashback_Encryption::encrypt($plaintext);
        $enc2 = Cashback_Encryption::encrypt($plaintext);

        $this->assertNotSame($enc1, $enc2);
        // Но оба должны декодироваться в одинаковый открытый текст
        $this->assertSame($plaintext, Cashback_Encryption::decrypt($enc1));
        $this->assertSame($plaintext, Cashback_Encryption::decrypt($enc2));
    }

    public function test_encrypt_empty_string(): void
    {
        $encrypted = Cashback_Encryption::encrypt('');
        $decrypted = Cashback_Encryption::decrypt($encrypted);
        $this->assertSame('', $decrypted);
    }

    public function test_encrypt_unicode_string(): void
    {
        $plaintext = 'Иванов Пётр Сидорович +79031234567 🔐';
        $encrypted = Cashback_Encryption::encrypt($plaintext);
        $decrypted = Cashback_Encryption::decrypt($encrypted);

        $this->assertSame($plaintext, $decrypted);
    }

    public function test_encrypt_long_string(): void
    {
        $plaintext = str_repeat('ABCD1234', 1000); // 8000 символов
        $encrypted = Cashback_Encryption::encrypt($plaintext);
        $decrypted = Cashback_Encryption::decrypt($encrypted);

        $this->assertSame($plaintext, $decrypted);
    }

    public function test_decrypt_with_tampered_data_throws(): void
    {
        $encrypted = Cashback_Encryption::encrypt('secret data');
        // Меняем один байт в шифртексте — GCM auth tag должен отклонить
        $tampered = $encrypted . 'X';

        $this->expectException(\RuntimeException::class);
        Cashback_Encryption::decrypt($tampered);
    }

    public function test_decrypt_with_invalid_data_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        Cashback_Encryption::decrypt('v2:not-valid-base64!!!!');
    }

    public function test_decrypt_v2_with_wrong_tag_throws(): void
    {
        // Создаём валидный зашифрованный текст, затем изменяем auth tag
        $encrypted = Cashback_Encryption::encrypt('original');
        $base64_part = substr($encrypted, 3); // убираем 'v2:'
        $data = base64_decode($base64_part, true);

        // Инвертируем несколько байт в auth tag (позиции 12-27)
        $data[14] = chr(ord($data[14]) ^ 0xFF);
        $tampered = 'v2:' . base64_encode($data);

        $this->expectException(\RuntimeException::class);
        Cashback_Encryption::decrypt($tampered);
    }

    // ================================================================
    // ТЕСТЫ: is_legacy_encrypted()
    // ================================================================

    public function test_is_legacy_encrypted_with_v1_prefix(): void
    {
        $this->assertTrue(Cashback_Encryption::is_legacy_encrypted('v1:somebase64data'));
    }

    public function test_is_legacy_encrypted_with_v2_prefix(): void
    {
        $encrypted = Cashback_Encryption::encrypt('test');
        $this->assertFalse(Cashback_Encryption::is_legacy_encrypted($encrypted));
    }

    public function test_is_legacy_encrypted_with_empty_string(): void
    {
        $this->assertFalse(Cashback_Encryption::is_legacy_encrypted(''));
    }

    public function test_is_legacy_encrypted_with_short_base64(): void
    {
        // 3 символа base64 = 2 байта — слишком мало для IV (16 байт)
        $this->assertFalse(Cashback_Encryption::is_legacy_encrypted('abc='));
    }

    public function test_is_legacy_encrypted_with_long_enough_base64(): void
    {
        // 17 байт минимум (16 IV + 1 ciphertext) = 24 символа base64
        $long_base64 = base64_encode(str_repeat('X', 17));
        $this->assertTrue(Cashback_Encryption::is_legacy_encrypted($long_base64));
    }

    // ================================================================
    // ТЕСТЫ: mask_account()
    // ================================================================

    public static function mask_account_provider(): array
    {
        // Логика mask_account:
        // - Пустая строка → ''
        // - Длина ≤ 4 → без изменений
        // - С пробелами (формат карты) → все группы кроме последней заменяются на '****'
        // - Без пробелов → все символы кроме последних 4 заменяются на '*'
        return [
            'пустая строка'               => ['', ''],
            'короткий (4 и меньше)'       => ['1234', '1234'],
            'телефон без пробелов'         => ['+79031234567', '********4567'], // 12 символов - 4 = 8 звёзд
            'ЮMoney кошелёк'              => ['410012345678', '********5678'], // 12 символов - 4 = 8 звёзд
            'карта с пробелами'           => ['4276 1234 5678 4523', '**** **** **** 4523'],
            'три группы пробелов'         => ['1234 5678 9012', '**** **** 9012'],
            'длинный счёт без пробелов'   => ['40817810000000001234', '****************1234'], // 20-4=16 звёзд
            '5 символов'                  => ['12345', '*2345'],
            '6 символов'                  => ['123456', '**3456'],
        ];
    }

    #[DataProvider('mask_account_provider')]
    public function test_mask_account(string $input, string $expected): void
    {
        $this->assertSame($expected, Cashback_Encryption::mask_account($input));
    }

    // ================================================================
    // ТЕСТЫ: mask_name()
    // ================================================================

    public static function mask_name_provider(): array
    {
        return [
            'пустая строка'              => ['', ''],
            'одно слово'                 => ['Иванов', 'И****'],
            'два слова'                  => ['Иванов Петр', 'И**** П****'],
            'три слова (полное ФИО)'     => ['Иванов Петр Сидорович', 'И**** П**** С****'],
            'лишние пробелы'             => ['  Иванов  Петр  ', 'И**** П****'],
            'латиница'                   => ['John Doe', 'J**** D****'],
            'одна буква'                 => ['А', 'А****'],
        ];
    }

    #[DataProvider('mask_name_provider')]
    public function test_mask_name(string $input, string $expected): void
    {
        $this->assertSame($expected, Cashback_Encryption::mask_name($input));
    }

    // ================================================================
    // ТЕСТЫ: hash_details()
    // ================================================================

    public function test_hash_details_returns_sha256_string(): void
    {
        $details = ['account' => '+79031234567', 'full_name' => 'Иванов Петр', 'bank' => 'sber'];
        $hash = Cashback_Encryption::hash_details($details);

        $this->assertSame(64, strlen($hash)); // SHA-256 = 64 hex символа
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_hash_details_is_deterministic(): void
    {
        $details = ['account' => '+79031234567', 'full_name' => 'Иванов Петр', 'bank' => 'sber'];

        $hash1 = Cashback_Encryption::hash_details($details);
        $hash2 = Cashback_Encryption::hash_details($details);

        $this->assertSame($hash1, $hash2);
    }

    public function test_hash_details_normalizes_case(): void
    {
        $details1 = ['account' => '+79031234567', 'full_name' => 'Иванов Петр', 'bank' => 'SBER'];
        $details2 = ['account' => '+79031234567', 'full_name' => 'иванов петр', 'bank' => 'sber'];

        $hash1 = Cashback_Encryption::hash_details($details1);
        $hash2 = Cashback_Encryption::hash_details($details2);

        $this->assertSame($hash1, $hash2, 'Хеш должен быть одинаков независимо от регистра');
    }

    public function test_hash_details_normalizes_spaces_in_account(): void
    {
        $details1 = ['account' => '4276 1234 5678 4523', 'full_name' => '', 'bank' => ''];
        $details2 = ['account' => '4276123456784523', 'full_name' => '', 'bank' => ''];

        $hash1 = Cashback_Encryption::hash_details($details1);
        $hash2 = Cashback_Encryption::hash_details($details2);

        $this->assertSame($hash1, $hash2, 'Пробелы в account должны удаляться перед хешированием');
    }

    public function test_hash_details_different_for_different_accounts(): void
    {
        $details1 = ['account' => '+79031234567', 'full_name' => 'Иванов', 'bank' => 'sber'];
        $details2 = ['account' => '+79037654321', 'full_name' => 'Иванов', 'bank' => 'sber'];

        $hash1 = Cashback_Encryption::hash_details($details1);
        $hash2 = Cashback_Encryption::hash_details($details2);

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_hash_details_different_banks_give_different_hash(): void
    {
        // Одинаковый счёт в разных банках — разные хеши (важно для антифрода)
        $details1 = ['account' => '+79031234567', 'full_name' => 'Иванов', 'bank' => 'sber'];
        $details2 = ['account' => '+79031234567', 'full_name' => 'Иванов', 'bank' => 'tinkoff'];

        $hash1 = Cashback_Encryption::hash_details($details1);
        $hash2 = Cashback_Encryption::hash_details($details2);

        $this->assertNotSame($hash1, $hash2, 'Разные банки с одним счётом должны давать разные хеши');
    }

    public function test_hash_details_order_independent(): void
    {
        // Порядок ключей в массиве не должен влиять на хеш (используется ksort)
        $details1 = ['account' => '+79031234567', 'bank' => 'sber', 'full_name' => 'Иванов'];
        $details2 = ['full_name' => 'Иванов', 'account' => '+79031234567', 'bank' => 'sber'];

        $hash1 = Cashback_Encryption::hash_details($details1);
        $hash2 = Cashback_Encryption::hash_details($details2);

        $this->assertSame($hash1, $hash2);
    }

    // ================================================================
    // ТЕСТЫ: encrypt_details() / decrypt_details()
    // ================================================================

    public function test_encrypt_details_returns_required_keys(): void
    {
        $details = [
            'account'   => '+79031234567',
            'full_name' => 'Иванов Петр Сидорович',
            'bank'      => 'Сбербанк',
        ];

        $result = Cashback_Encryption::encrypt_details($details);

        $this->assertArrayHasKey('encrypted_details', $result);
        $this->assertArrayHasKey('masked_details', $result);
        $this->assertArrayHasKey('details_hash', $result);
    }

    public function test_encrypt_decrypt_details_roundtrip(): void
    {
        $details = [
            'account'   => '+79031234567',
            'full_name' => 'Иванов Петр Сидорович',
            'bank'      => 'Сбербанк',
        ];

        $encrypted = Cashback_Encryption::encrypt_details($details);
        $decrypted = Cashback_Encryption::decrypt_details($encrypted['encrypted_details']);

        $this->assertSame($details['account'], $decrypted['account']);
        $this->assertSame($details['full_name'], $decrypted['full_name']);
        $this->assertSame($details['bank'], $decrypted['bank']);
    }

    public function test_encrypt_details_masked_account(): void
    {
        $details = [
            'account'   => '+79031234567',
            'full_name' => 'Иванов Петр',
            'bank'      => 'sber',
        ];

        $result = Cashback_Encryption::encrypt_details($details);
        $masked = json_decode($result['masked_details'], true);

        $this->assertNotSame($details['account'], $masked['account'], 'Аккаунт должен быть замаскирован');
        // mask_account('+79031234567') — 12 символов без пробелов → последние 4 видны
        $this->assertStringEndsWith('4567', $masked['account']);
        // Аккаунт заменён на звёздочки кроме последних 4
        $this->assertSame('********4567', $masked['account']);
    }

    public function test_encrypt_details_masked_fullname(): void
    {
        $details = [
            'account'   => '+79031234567',
            'full_name' => 'Иванов Петр Сидорович',
            'bank'      => 'sber',
        ];

        $result = Cashback_Encryption::encrypt_details($details);
        $masked = json_decode($result['masked_details'], true);

        // ФИО должно начинаться с первой буквы каждого слова
        $this->assertStringStartsWith('И****', $masked['full_name']);
        $this->assertStringContainsString('П****', $masked['full_name']);
        $this->assertStringContainsString('С****', $masked['full_name']);
    }

    public function test_encrypt_details_hash_is_sha256(): void
    {
        $details = ['account' => '+79031234567', 'full_name' => 'Иванов', 'bank' => 'sber'];
        $result = Cashback_Encryption::encrypt_details($details);

        $this->assertSame(64, strlen($result['details_hash']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['details_hash']);
    }

    // ================================================================
    // ТЕСТЫ: get_masked_account()
    // ================================================================

    public function test_get_masked_account_from_json(): void
    {
        $masked_json = json_encode(['account' => '********4567', 'full_name' => 'И****', 'bank' => 'sber']);
        $result = Cashback_Encryption::get_masked_account($masked_json);

        $this->assertSame('********4567', $result);
    }

    public function test_get_masked_account_fallback_to_plaintext(): void
    {
        // mask_account('+79031234567') = '********4567' (12 символов, без пробелов)
        $result = Cashback_Encryption::get_masked_account(null, '+79031234567');
        $this->assertSame('********4567', $result);
    }

    public function test_get_masked_account_both_null_returns_empty(): void
    {
        $result = Cashback_Encryption::get_masked_account(null, null);
        $this->assertSame('', $result);
    }

    public function test_get_masked_account_invalid_json_falls_back(): void
    {
        // mask_account('+79031234567') = '********4567' (12 символов без пробелов)
        $result = Cashback_Encryption::get_masked_account('not-json', '+79031234567');
        $this->assertSame('********4567', $result);
    }

    // ================================================================
    // ТЕСТЫ: get_client_ip()
    // ================================================================

    public function test_get_client_ip_returns_remote_addr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['HTTP_X_REAL_IP']);

        $ip = Cashback_Encryption::get_client_ip();
        $this->assertSame('192.0.2.1', $ip);
    }

    public function test_get_client_ip_with_trusted_proxy(): void
    {
        if (!defined('CASHBACK_TRUSTED_PROXIES')) {
            // Определить константу в этом тесте нельзя, пропускаем
            $this->markTestSkipped('CASHBACK_TRUSTED_PROXIES cannot be defined in test scope');
        }
    }

    public function test_get_client_ip_invalid_remote_addr_returns_zero(): void
    {
        $_SERVER['REMOTE_ADDR'] = 'invalid-ip';
        $ip = Cashback_Encryption::get_client_ip();
        $this->assertSame('0.0.0.0', $ip);
    }

    public function test_get_client_ip_ipv6(): void
    {
        $_SERVER['REMOTE_ADDR'] = '2001:db8::1';
        $ip = Cashback_Encryption::get_client_ip();
        $this->assertSame('2001:db8::1', $ip);
    }

    /**
     * Очистка $_SERVER после тестов IP
     */
    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['HTTP_X_REAL_IP']);
    }
}
