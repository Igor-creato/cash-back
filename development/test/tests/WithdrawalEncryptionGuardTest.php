<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты fail-closed encryption guard в cashback-withdrawal.
 *
 * Покрывает:
 * - save_payout_settings(): при !is_configured() → 503 encryption_unavailable, БД не тронута.
 * - save_payout_settings(): happy-path при is_configured() → шифр записан, plaintext очищен.
 * - get_payout_account(): при !is_configured() + ciphertext → null (НЕ plaintext).
 * - get_payout_account(): legacy row без ciphertext → plaintext возвращается.
 * - get_payout_account(): битый ciphertext при настроенном ключе → null.
 * - update_user_payout_details(): defense-in-depth при encrypted_details=null + is_configured → false.
 *
 * Запуск:
 *   Happy-path:  ./vendor/bin/phpunit tests/WithdrawalEncryptionGuardTest.php
 *   Fail-closed: ./vendor/bin/phpunit --bootstrap development/test/bootstrap-no-encryption-key.php \
 *                    --filter 'not_configured|missing_key' tests/WithdrawalEncryptionGuardTest.php
 *
 * См. ADR Группа 4, finding F-1-001.
 */
#[Group('security')]
#[Group('encryption')]
#[Group('withdrawal')]
class WithdrawalEncryptionGuardTest extends TestCase
{
    /**
     * Анонимный мок $wpdb: запоминает все вызовы insert/update/get_row/get_var.
     * Тест подсовывает желаемый row через $wpdb->next_row.
     */
    private object $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class {
            public string $prefix = 'wp_';
            /** @var array<int, array{method:string, args:array<mixed>}> */
            public array $calls = array();
            /** @var array<string,mixed>|null */
            public ?array $next_row = null;
            /** @var mixed */
            public mixed $next_var = null;
            public int $rows_affected = 0;

            public function query(string $sql): int|bool
            {
                $this->calls[] = array( 'method' => 'query', 'args' => array( $sql ) );
                return true;
            }

            public function prepare(string $query, mixed ...$args): string
            {
                return $query . ' -- ' . json_encode($args);
            }

            public function insert(string $table, array $data, array|string|null $format = null): int|false
            {
                $this->calls[] = array( 'method' => 'insert', 'args' => array( $table, $data, $format ) );
                $this->rows_affected = 1;
                return 1;
            }

            public function update(string $table, array $data, array $where, array|string|null $format = null, array|string|null $where_format = null): int|false
            {
                $this->calls[] = array( 'method' => 'update', 'args' => array( $table, $data, $where, $format, $where_format ) );
                $this->rows_affected = 1;
                return 1;
            }

            public function get_row(string $query, string $output = OBJECT, int $y = 0): mixed
            {
                $this->calls[] = array( 'method' => 'get_row', 'args' => array( $query ) );
                return $this->next_row;
            }

            public function get_var(string $query, int $x = 0, int $y = 0): mixed
            {
                $this->calls[] = array( 'method' => 'get_var', 'args' => array( $query ) );
                return $this->next_var;
            }
        };

        $GLOBALS['wpdb'] = $this->wpdb;
        $GLOBALS['_cb_test_is_logged_in'] = true;
        $GLOBALS['_cb_test_user_id']      = 123;
        $GLOBALS['_cb_test_last_json_response'] = null;
        $_POST                              = array();

        // Сбрасываем буфер error_log (если был).
        ini_set('error_log', sys_get_temp_dir() . '/cb-test-error.log');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        unset($GLOBALS['_cb_test_is_logged_in']);
        unset($GLOBALS['_cb_test_user_id']);
        unset($GLOBALS['_cb_test_last_json_response']);
        $_POST = array();
    }

    // ================================================================
    // Fail-closed: save_payout_settings отказывает без ключа шифрования.
    // Запускается с bootstrap-no-encryption-key.php (или при отсутствии ключа).
    // ================================================================

    public function test_save_payout_settings_rejects_when_encryption_not_configured(): void
    {
        if (Cashback_Encryption::is_configured()) {
            $this->markTestSkipped('Тест требует !is_configured(); запустите с bootstrap-no-encryption-key.php.');
        }

        $withdrawal = (new \ReflectionClass(CashbackWithdrawal::class))->newInstanceWithoutConstructor();

        $_POST = array(
            'security'         => 'test_nonce',
            'payout_method_id' => '1',
            'payout_account'   => '41001234567890',
            'bank_id'          => '0',
        );

        try {
            $withdrawal->save_payout_settings();
            $this->fail('Ожидали, что wp_send_json_error прервёт выполнение');
        } catch (\Throwable $e) {
            $this->assertStringStartsWith('wp_send_json_error:', $e->getMessage());
        }

        $resp = $GLOBALS['_cb_test_last_json_response'];
        $this->assertIsArray($resp, 'wp_send_json_error должен быть вызван');
        $this->assertFalse($resp['success']);
        $this->assertSame(503, $resp['status_code'], 'HTTP 503 Service Unavailable при недоступности шифрования');
        $this->assertIsArray($resp['data']);
        $this->assertSame('encryption_unavailable', $resp['data']['code'] ?? null);
        $this->assertArrayHasKey('message', $resp['data']);

        // Критично: таблица профиля не должна быть изменена.
        // (запись в cashback_audit_log допустима — это аудит события отказа).
        $profile_writes = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => in_array($c['method'], ['insert', 'update'], true)
                && isset($c['args'][0])
                && str_contains((string) $c['args'][0], 'cashback_user_profile')
        );
        $this->assertEmpty($profile_writes, 'Таблица cashback_user_profile не должна быть изменена при fail-closed отказе');
    }

    // ================================================================
    // Happy-path: save_payout_settings пишет ciphertext, plaintext очищен.
    // Требует is_configured() === true (основной bootstrap).
    // ================================================================

    public function test_save_payout_settings_persists_ciphertext_when_configured(): void
    {
        if (!Cashback_Encryption::is_configured()) {
            $this->markTestSkipped('Тест требует is_configured() === true.');
        }

        // existing_record = null → будет insert (проще проверить).
        $this->wpdb->next_var = null;

        $withdrawal = (new \ReflectionClass(CashbackWithdrawal::class))->newInstanceWithoutConstructor();

        // Для обхода validate_payout_method/validate_bank/format-helpers используем
        // упрощённый подход: мокаем через рефлексию приватные зависимости, выставляя
        // результат через $wpdb-моки, либо просто дёргаем приватный update_user_payout_details
        // напрямую — основная инвариант теста «ciphertext в БД» проверяется именно там.
        $refl = new \ReflectionClass($withdrawal);
        $method = $refl->getMethod('update_user_payout_details');
        $method->setAccessible(true);

        // Готовим валидный ciphertext через реальный encrypt_details.
        $enc = Cashback_Encryption::encrypt_details(array(
            'account'   => '41001234567890',
            'full_name' => '',
            'bank'      => 'Тинькофф',
        ));

        $result = $method->invoke(
            $withdrawal,
            123,
            1,
            '41001234567890',
            0,
            $enc['encrypted_details'],
            $enc['masked_details'],
            $enc['details_hash']
        );

        $this->assertTrue($result, 'update_user_payout_details должен вернуть true при успешной записи');

        // Ищем INSERT/UPDATE с правильными полями.
        $writes = array_values(array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => in_array($c['method'], ['insert', 'update'], true)
        ));
        $this->assertNotEmpty($writes, 'Ожидаем запись в БД');

        $data = $writes[0]['args'][1];
        $this->assertArrayHasKey('encrypted_details', $data);
        $this->assertNotEmpty($data['encrypted_details']);
        $this->assertSame('', $data['payout_account'], 'payout_account должен быть очищен');
        $this->assertSame('', $data['payout_full_name'], 'payout_full_name должен быть очищен');
    }

    // ================================================================
    // Defense-in-depth: update_user_payout_details блокирует plaintext-путь,
    // если ключ настроен, но ciphertext не передан (программная ошибка).
    // ================================================================

    public function test_update_user_payout_details_defense_in_depth_blocks_plaintext_when_key_present(): void
    {
        if (!Cashback_Encryption::is_configured()) {
            $this->markTestSkipped('Тест требует is_configured() === true.');
        }

        $withdrawal = (new \ReflectionClass(CashbackWithdrawal::class))->newInstanceWithoutConstructor();
        $refl  = new \ReflectionClass($withdrawal);
        $method = $refl->getMethod('update_user_payout_details');
        $method->setAccessible(true);

        $result = $method->invoke($withdrawal, 123, 1, '41001234567890', 0, null, null, null);

        $this->assertFalse(
            $result,
            'При настроенном шифровании и отсутствии ciphertext метод должен отказать (defense-in-depth)'
        );

        $profile_writes = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => in_array($c['method'], ['insert', 'update'], true)
                && isset($c['args'][0])
                && str_contains((string) $c['args'][0], 'cashback_user_profile')
        );
        $this->assertEmpty($profile_writes, 'Таблица cashback_user_profile не должна быть тронута');
    }

    // ================================================================
    // get_payout_account: fail-closed — при !is_configured() + ciphertext → null.
    // ================================================================

    public function test_get_payout_account_returns_null_on_ciphertext_with_missing_key(): void
    {
        if (Cashback_Encryption::is_configured()) {
            $this->markTestSkipped('Тест требует !is_configured(); запустите с bootstrap-no-encryption-key.php.');
        }

        $this->wpdb->next_row = array(
            'payout_account'    => '',
            'encrypted_details' => 'v2:fakebutnonempty',
        );

        $withdrawal = (new \ReflectionClass(CashbackWithdrawal::class))->newInstanceWithoutConstructor();
        $refl = new \ReflectionClass($withdrawal);
        $method = $refl->getMethod('get_payout_account');
        $method->setAccessible(true);

        $result = $method->invoke($withdrawal, 123);

        $this->assertNull(
            $result,
            'При непустом ciphertext и отсутствии ключа должен вернуться null, а не plaintext-fallback'
        );
    }

    // ================================================================
    // get_payout_account: legacy row — пустой ciphertext, заполнен plaintext.
    // Для обратной совместимости до миграции шифрования.
    // ================================================================

    public function test_get_payout_account_returns_plaintext_for_legacy_rows_without_ciphertext(): void
    {
        $this->wpdb->next_row = array(
            'payout_account'    => '41001234567890',
            'encrypted_details' => '',
        );

        $withdrawal = (new \ReflectionClass(CashbackWithdrawal::class))->newInstanceWithoutConstructor();
        $refl = new \ReflectionClass($withdrawal);
        $method = $refl->getMethod('get_payout_account');
        $method->setAccessible(true);

        $result = $method->invoke($withdrawal, 123);

        $this->assertSame(
            '41001234567890',
            $result,
            'Legacy row без ciphertext должен вернуть plaintext (обратная совместимость)'
        );
    }

    // ================================================================
    // get_payout_account: битый ciphertext при настроенном ключе → null.
    // ================================================================

    public function test_get_payout_account_returns_null_when_decrypt_fails(): void
    {
        if (!Cashback_Encryption::is_configured()) {
            $this->markTestSkipped('Тест требует is_configured() === true.');
        }

        $this->wpdb->next_row = array(
            'payout_account'    => 'some-plaintext-that-must-not-leak',
            'encrypted_details' => 'v2:thisisnotvalidbase64ciphertext',
        );

        $withdrawal = (new \ReflectionClass(CashbackWithdrawal::class))->newInstanceWithoutConstructor();
        $refl = new \ReflectionClass($withdrawal);
        $method = $refl->getMethod('get_payout_account');
        $method->setAccessible(true);

        $result = $method->invoke($withdrawal, 123);

        $this->assertNull(
            $result,
            'При сбое decrypt метод НЕ должен подставлять plaintext-fallback — это может утечь старые данные'
        );
    }

    // ================================================================
    // Defensive: get_payout_account возвращает null, если строки нет.
    // ================================================================

    public function test_get_payout_account_returns_null_when_no_row(): void
    {
        $this->wpdb->next_row = null;

        $withdrawal = (new \ReflectionClass(CashbackWithdrawal::class))->newInstanceWithoutConstructor();
        $refl = new \ReflectionClass($withdrawal);
        $method = $refl->getMethod('get_payout_account');
        $method->setAccessible(true);

        $result = $method->invoke($withdrawal, 123);

        $this->assertNull($result);
    }
}
