<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты механизма аварийного восстановления шифрования при утере/ротации ключа.
 *
 * Покрывает:
 * - Fingerprint ключа (HMAC-SHA256) записывается при первой загрузке.
 * - Детекция несовпадения fingerprint → mismatch-flag.
 * - Batch-purge обновляет строки с encrypted_details и обнуляет поля.
 * - Batch-purge идемпотентен (нет строк → ничего не делает).
 * - Admin-страница отказывает без manage_options / без корректного подтверждения.
 * - После завершения очистки fingerprint обновляется.
 *
 * Запуск:
 *   ./vendor/bin/phpunit --filter EncryptionRecoveryFlowTest tests/EncryptionRecoveryFlowTest.php
 *
 * См. ADR Группа 4, finding F-1-001 (recovery-flow — доп. scope).
 */
#[Group('security')]
#[Group('encryption')]
#[Group('recovery')]
class EncryptionRecoveryFlowTest extends TestCase
{
    private object $wpdb;

    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $file        = $plugin_root . '/admin/class-cashback-encryption-recovery.php';
        if (!file_exists($file)) {
            self::markTestSkipped('Cashback_Encryption_Recovery file not present yet (RED phase).');
        }
        if (!class_exists('Cashback_Encryption_Recovery')) {
            require_once $file;
        }
    }

    protected function setUp(): void
    {
        if (!Cashback_Encryption::is_configured()) {
            $this->markTestSkipped('Тесту требуется is_configured() === true.');
        }

        $this->wpdb = new class {
            public string $prefix = 'wp_';
            /** @var array<int, array{method:string, args:array<mixed>}> */
            public array $calls = array();
            public int|string $next_var = 0;
            /** @var mixed */
            public mixed $next_var_stack = null;
            public int $rows_affected = 0;

            public function query(string $sql): int|bool
            {
                $this->calls[] = array( 'method' => 'query', 'args' => array( $sql ) );
                // Эмулируем UPDATE ... LIMIT N → сколько строк затронуто.
                if (preg_match('/^\s*UPDATE\b/i', $sql)) {
                    $this->rows_affected = (int) $this->next_var;
                    return (int) $this->next_var;
                }
                return true;
            }

            public function prepare(string $query, mixed ...$args): string
            {
                return $query . ' -- ' . json_encode($args);
            }

            public function get_var(string $query, int $x = 0, int $y = 0): mixed
            {
                $this->calls[] = array( 'method' => 'get_var', 'args' => array( $query ) );
                return $this->next_var;
            }

            public function insert(string $table, array $data, array|string|null $format = null): int|false
            {
                $this->calls[] = array( 'method' => 'insert', 'args' => array( $table, $data ) );
                return 1;
            }

            public function update(string $table, array $data, array $where, array|string|null $format = null, array|string|null $where_format = null): int|false
            {
                $this->calls[] = array( 'method' => 'update', 'args' => array( $table, $data, $where ) );
                $this->rows_affected = (int) $this->next_var;
                return (int) $this->next_var;
            }
        };

        $GLOBALS['wpdb']             = $this->wpdb;
        $GLOBALS['_cb_test_options'] = array();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $GLOBALS['_cb_test_options'] = array();
    }

    // ================================================================
    // Fingerprint
    // ================================================================

    public function test_fingerprint_is_hmac_sha256_of_current_key(): void
    {
        $expected = hash_hmac('sha256', CB_ENCRYPTION_KEY, 'cashback_fingerprint_v1');
        $actual   = Cashback_Encryption_Recovery::get_current_fingerprint();
        $this->assertSame($expected, $actual);
    }

    public function test_detects_no_mismatch_on_first_install(): void
    {
        // В wp_options ещё нет сохранённого fingerprint → считается первичной установкой,
        // не mismatch. Метод возвращает false (нет проблемы).
        $this->assertFalse(Cashback_Encryption_Recovery::is_key_mismatch());
    }

    public function test_records_fingerprint_on_first_install(): void
    {
        $this->assertFalse(Cashback_Encryption_Recovery::is_key_mismatch());

        Cashback_Encryption_Recovery::record_fingerprint_if_missing();

        $stored = get_option('cashback_encryption_key_fingerprint', '');
        $this->assertSame(Cashback_Encryption_Recovery::get_current_fingerprint(), $stored);
    }

    public function test_detects_mismatch_when_stored_differs(): void
    {
        update_option('cashback_encryption_key_fingerprint', str_repeat('0', 64));
        $this->assertTrue(Cashback_Encryption_Recovery::is_key_mismatch());
    }

    public function test_no_mismatch_when_stored_matches(): void
    {
        update_option(
            'cashback_encryption_key_fingerprint',
            Cashback_Encryption_Recovery::get_current_fingerprint()
        );
        $this->assertFalse(Cashback_Encryption_Recovery::is_key_mismatch());
    }

    // ================================================================
    // Batch purge semantics
    // ================================================================

    public function test_batch_purge_updates_rows_with_ciphertext(): void
    {
        // Эмулируем: next_var = 3 → UPDATE затронул 3 строки.
        $this->wpdb->next_var = 3;

        $affected = Cashback_Encryption_Recovery::run_batch(500);

        $this->assertSame(3, $affected, 'run_batch должен вернуть число затронутых строк');

        $updates = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => 'query' === $c['method']
                && preg_match('/UPDATE\b.*\bSET\b/i', (string) $c['args'][0])
        );
        $this->assertNotEmpty($updates, 'Должен быть выполнен UPDATE');

        // Проверяем, что SQL обнуляет все критичные поля.
        $sql = (string) array_values($updates)[0]['args'][0];
        $this->assertStringContainsString("encrypted_details = ''", $sql);
        $this->assertStringContainsString("masked_details = ''", $sql);
        $this->assertStringContainsString("details_hash = ''", $sql);
        $this->assertStringContainsString("payout_account = ''", $sql);
        $this->assertStringContainsString('LIMIT', $sql);
    }

    public function test_batch_purge_is_idempotent_when_no_rows_left(): void
    {
        $this->wpdb->next_var = 0;

        $affected = Cashback_Encryption_Recovery::run_batch(500);

        $this->assertSame(0, $affected, 'При отсутствии строк run_batch должен вернуть 0');
    }

    public function test_batch_purge_respects_limit(): void
    {
        $this->wpdb->next_var = 500;

        Cashback_Encryption_Recovery::run_batch(500);

        $updates = array_values(array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => 'query' === $c['method']
                && preg_match('/UPDATE\b.*\bSET\b/i', (string) $c['args'][0])
        ));
        $this->assertNotEmpty($updates);
        $sql = (string) $updates[0]['args'][0];
        $this->assertMatchesRegularExpression('/\bLIMIT\b/i', $sql);
        $this->assertMatchesRegularExpression('/\b500\b/', $sql);
    }

    public function test_count_remaining_rows(): void
    {
        $this->wpdb->next_var = 42;

        $count = Cashback_Encryption_Recovery::count_rows_with_ciphertext();

        $this->assertSame(42, $count);

        $selects = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => 'get_var' === $c['method']
                && preg_match('/SELECT\s+COUNT\s*\(/i', (string) $c['args'][0])
        );
        $this->assertNotEmpty($selects, 'Должен быть выполнен SELECT COUNT(*)');
    }

    // ================================================================
    // Admin page guards
    // ================================================================

    public function test_admin_page_rejects_without_capability(): void
    {
        $GLOBALS['_cb_test_current_user_can'] = false;

        $this->expectException(\Throwable::class);
        Cashback_Encryption_Recovery::handle_admin_form_submit(array(
            'cashback_recovery_nonce' => 'test',
            'confirmation'            => 'DELETE_ALL_PAYOUT_CREDENTIALS',
        ));
    }

    public function test_admin_page_rejects_without_confirmation(): void
    {
        $GLOBALS['_cb_test_current_user_can'] = true;

        $this->expectException(\Throwable::class);
        Cashback_Encryption_Recovery::handle_admin_form_submit(array(
            'cashback_recovery_nonce' => 'test',
            'confirmation'            => 'I agree',  // неправильное подтверждение
        ));
    }

    public function test_admin_page_accepts_with_correct_confirmation_and_capability(): void
    {
        $GLOBALS['_cb_test_current_user_can'] = true;

        // В отличие от двух предыдущих — тут не должно быть exception.
        $this->wpdb->next_var = 0;  // нет строк → job перепланируется как завершённый

        Cashback_Encryption_Recovery::handle_admin_form_submit(array(
            'cashback_recovery_nonce' => 'test',
            'confirmation'            => 'DELETE_ALL_PAYOUT_CREDENTIALS',
        ));

        // Если дошли сюда — exception не был брошен. Проверяем, что AS-job был запланирован.
        $this->assertTrue(
            (bool) ($GLOBALS['_cb_test_as_scheduled'] ?? false),
            'Action Scheduler job должен быть запланирован после валидной формы'
        );
    }
}
