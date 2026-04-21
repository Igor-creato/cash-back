<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты admin UI-warning при попытке расшифровать реквизиты processing-заявки
 * при несовпадающем fingerprint ключа, и ручного manual_refund AJAX.
 *
 * Покрывает:
 * - handle_decrypt_payout_details: при key_mismatch → wp_send_json_error
 *   с code='key_mismatch' + сообщение "Ключ шифрования изменён"
 *   + флаг can_manual_refund=true.
 * - handle_decrypt_payout_details: при сбое decrypt → то же поведение.
 * - handle_manual_encryption_refund: требует nonce + manage_options, вызывает
 *   cancel_payout_with_refund с reason='manual_encryption_recovery' и actor_id
 *   текущего админа.
 *
 * См. ADR Группа 4b, finding F-1-002 (Commit 2).
 */
#[Group('security')]
#[Group('encryption')]
#[Group('recovery')]
#[Group('payouts')]
class PayoutProcessingWarningTest extends TestCase
{
    private object $wpdb;

    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        if (!isset($GLOBALS['wpdb'])) {
            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
                public function query(string $sql): int|bool { return true; }
                public function prepare(string $q, mixed ...$a): string { return $q; }
                public function get_row(string $q, string $o = OBJECT, int $y = 0): mixed { return null; }
                public function get_var(string $q, int $x = 0, int $y = 0): mixed { return null; }
                public function get_results(string $q, string $o = OBJECT): array { return array(); }
                public function insert(string $t, array $d, $f = null): int|false { return 1; }
                public function update(string $t, array $d, array $w, $f = null, $wf = null): int|false { return 1; }
            };
        }

        if (!class_exists('Cashback_Payouts_Admin')) {
            require_once $plugin_root . '/admin/payouts.php';
        }
        if (!class_exists('Cashback_Encryption_Recovery')) {
            require_once $plugin_root . '/admin/class-cashback-encryption-recovery.php';
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
            /** @var array<string,array<string,mixed>|null> */
            public array $rows_by_sql_fragment = array();
            public int|string $next_var = 0;

            public function query(string $sql): int|bool
            {
                $this->calls[] = array( 'method' => 'query', 'args' => array( $sql ) );
                return true;
            }
            public function prepare(string $query, mixed ...$args): string
            {
                return $query . ' -- ' . json_encode($args);
            }
            public function get_row(string $query, string $output = OBJECT, int $y = 0): mixed
            {
                $this->calls[] = array( 'method' => 'get_row', 'args' => array( $query ) );
                foreach ($this->rows_by_sql_fragment as $fragment => $row) {
                    if (str_contains($query, $fragment)) {
                        return $row;
                    }
                }
                return null;
            }
            public function get_var(string $query, int $x = 0, int $y = 0): mixed
            {
                $this->calls[] = array( 'method' => 'get_var', 'args' => array( $query ) );
                return $this->next_var;
            }
            public function get_results(string $query, string $output = OBJECT): array
            {
                $this->calls[] = array( 'method' => 'get_results', 'args' => array( $query ) );
                return array();
            }
            public function insert(string $table, array $data, $format = null): int|false
            {
                $this->calls[] = array( 'method' => 'insert', 'args' => array( $table, $data ) );
                return 1;
            }
            public function update(string $table, array $data, array $where, $format = null, $wf = null): int|false
            {
                $this->calls[] = array( 'method' => 'update', 'args' => array( $table, $data, $where ) );
                return 1;
            }
        };

        $GLOBALS['wpdb']                        = $this->wpdb;
        $GLOBALS['_cb_test_current_user_can']   = true;
        $GLOBALS['_cb_test_is_logged_in']       = true;
        $GLOBALS['_cb_test_user_id']            = 42;  // admin
        $GLOBALS['_cb_test_options']            = array();
        $GLOBALS['_cb_test_last_json_response'] = null;
        $_POST                                  = array();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $_POST = array();
    }

    private function adminInstance(): Cashback_Payouts_Admin
    {
        $admin = (new \ReflectionClass(Cashback_Payouts_Admin::class))
            ->newInstanceWithoutConstructor();
        $tn = new \ReflectionProperty($admin, 'table_name');
        $tn->setAccessible(true);
        $tn->setValue($admin, $this->wpdb->prefix . 'cashback_payout_requests');
        return $admin;
    }

    private function callAndCatchWpSendJson( callable $fn ): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            // wp_send_json_error/wp_send_json_success/wp_die throws Cashback_Test_Halt_Signal
            // (stub в bootstrap). Продолжаем проверять $GLOBALS['_cb_test_last_json_response'].
        }
    }

    // ================================================================
    // handle_decrypt_payout_details — UI warning при key_mismatch
    // ================================================================

    public function test_decrypt_returns_key_mismatch_warning_when_fingerprint_differs(): void
    {
        // Сохранённый fingerprint — отличается от текущего → is_key_mismatch() === true.
        update_option(
            'cashback_encryption_key_fingerprint',
            str_repeat('f', 64)  // заведомо отличается от текущего HMAC
        );

        // Payout row: processing с непустым ciphertext.
        $this->wpdb->rows_by_sql_fragment['cashback_payout_requests'] = array(
            'id'                => 42,
            'user_id'           => 777,
            'status'            => 'processing',
            'encrypted_details' => 'v2:arbitraryciphertext',
            'payout_account'    => 'leaked-if-leaked',
        );

        $_POST['nonce']     = 'test_nonce_decrypt_payout_details_nonce';
        $_POST['payout_id'] = '42';

        $admin = $this->adminInstance();
        $this->callAndCatchWpSendJson(fn() => $admin->handle_decrypt_payout_details());

        $resp = $GLOBALS['_cb_test_last_json_response'];
        $this->assertIsArray($resp, 'Ожидаем JSON-ответ');
        $this->assertFalse($resp['success'], 'Это должен быть error-ответ');
        $this->assertSame(
            'key_mismatch',
            $resp['data']['code'] ?? null,
            'Ожидаем code=key_mismatch при несовпадении fingerprint'
        );
        $this->assertTrue(
            (bool) ($resp['data']['can_manual_refund'] ?? false),
            'Ожидаем флаг can_manual_refund=true'
        );
    }

    public function test_decrypt_returns_key_mismatch_warning_when_decrypt_throws(): void
    {
        // Fingerprint совпадает, но ciphertext битый → decrypt throws.
        update_option(
            'cashback_encryption_key_fingerprint',
            Cashback_Encryption_Recovery::get_current_fingerprint()
        );

        $this->wpdb->rows_by_sql_fragment['cashback_payout_requests'] = array(
            'id'                => 43,
            'user_id'           => 777,
            'status'            => 'processing',
            'encrypted_details' => 'v2:!!!broken-base64!!!',
            'payout_account'    => '',
        );

        $_POST['nonce']     = 'test_nonce_decrypt_payout_details_nonce';
        $_POST['payout_id'] = '43';

        $admin = $this->adminInstance();
        $this->callAndCatchWpSendJson(fn() => $admin->handle_decrypt_payout_details());

        $resp = $GLOBALS['_cb_test_last_json_response'];
        $this->assertFalse($resp['success']);
        $this->assertSame('key_mismatch', $resp['data']['code'] ?? null);
    }

    public function test_decrypt_succeeds_when_key_matches_and_decrypt_ok(): void
    {
        // Fingerprint совпадает, ciphertext валидный.
        update_option(
            'cashback_encryption_key_fingerprint',
            Cashback_Encryption_Recovery::get_current_fingerprint()
        );

        $enc = Cashback_Encryption::encrypt_details(array(
            'account'   => '41001234567890',
            'full_name' => '',
            'bank'      => 'Тинькофф',
        ));

        $this->wpdb->rows_by_sql_fragment['cashback_payout_requests'] = array(
            'id'                => 44,
            'user_id'           => 777,
            'status'            => 'processing',
            'encrypted_details' => $enc['encrypted_details'],
            'payout_account'    => '',
        );

        $_POST['nonce']     = 'test_nonce_decrypt_payout_details_nonce';
        $_POST['payout_id'] = '44';

        $admin = $this->adminInstance();
        $this->callAndCatchWpSendJson(fn() => $admin->handle_decrypt_payout_details());

        $resp = $GLOBALS['_cb_test_last_json_response'];
        $this->assertTrue($resp['success'], 'Обычный flow должен возвращать success');
        $this->assertSame('41001234567890', $resp['data']['account'] ?? '');
    }

    // ================================================================
    // handle_manual_encryption_refund — ручной возврат админом
    // ================================================================

    public function test_manual_refund_rejects_without_manage_options(): void
    {
        $GLOBALS['_cb_test_current_user_can'] = false;

        $_POST['nonce']     = 'test_nonce_cashback_manual_encryption_refund';
        $_POST['payout_id'] = '42';

        $admin = $this->adminInstance();
        $this->callAndCatchWpSendJson(fn() => $admin->handle_manual_encryption_refund());

        $resp = $GLOBALS['_cb_test_last_json_response'];
        $this->assertIsArray($resp);
        $this->assertFalse($resp['success']);
    }

    public function test_manual_refund_cancels_and_writes_audit_with_manual_reason(): void
    {
        $GLOBALS['_cb_test_current_user_can'] = true;

        // Заявка в processing → можно отменить (cancel_payout_with_refund принимает processing).
        $this->wpdb->rows_by_sql_fragment['cashback_payout_requests'] = array(
            'id'           => 55,
            'user_id'      => 777,
            'total_amount' => '1000.00',
            'status'       => 'processing',
            'refunded_at'  => null,
        );
        $this->wpdb->rows_by_sql_fragment['cashback_user_balance'] = array(
            'pending_balance' => '1000.00',
        );

        $_POST['nonce']     = 'test_nonce_cashback_manual_encryption_refund';
        $_POST['payout_id'] = '55';

        $admin = $this->adminInstance();
        $this->callAndCatchWpSendJson(fn() => $admin->handle_manual_encryption_refund());

        // Audit-log с действием payout_cancelled_encryption_recovery и reason в details.
        $audit_inserts = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => 'insert' === $c['method']
                && str_contains((string) $c['args'][0], 'cashback_audit_log')
                && 'payout_cancelled_encryption_recovery' === ($c['args'][1]['action'] ?? null)
                && 42 === (int) ($c['args'][1]['actor_id'] ?? 0)
                && str_contains((string) ($c['args'][1]['details'] ?? ''), 'manual_encryption_recovery')
        );
        $this->assertNotEmpty(
            $audit_inserts,
            'Ожидаем audit-log с action=payout_cancelled_encryption_recovery, actor_id=текущий admin, reason=manual_encryption_recovery в details'
        );
    }

    public function test_manual_refund_rejects_nonexistent_payout(): void
    {
        $GLOBALS['_cb_test_current_user_can'] = true;

        // Нет rows_by_sql_fragment['cashback_payout_requests'] → get_row вернёт null.
        $_POST['nonce']     = 'test_nonce_cashback_manual_encryption_refund';
        $_POST['payout_id'] = '999';

        $admin = $this->adminInstance();
        $this->callAndCatchWpSendJson(fn() => $admin->handle_manual_encryption_refund());

        $resp = $GLOBALS['_cb_test_last_json_response'];
        $this->assertFalse($resp['success']);
    }
}
