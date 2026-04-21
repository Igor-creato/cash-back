<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты отмены активных заявок на выплату при утере / ротации ключа шифрования.
 *
 * Покрывает:
 * - Cashback_Payouts_Admin::cancel_payout_with_refund() — публичный helper,
 *   переводит waiting → failed с возвратом pending → available, пишет ledger + audit.
 * - Cashback_Encryption_Recovery::cancel_waiting_payouts_batch() — батчевая обёртка.
 * - run_batch_action_handler() — двухфазный: cancel waiting → purge реквизитов.
 *
 * См. ADR Группа 4b, finding F-1-002.
 */
#[Group('security')]
#[Group('encryption')]
#[Group('recovery')]
#[Group('payouts')]
class PayoutEncryptionRecoveryTest extends TestCase
{
    private object $wpdb;

    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        // Глобальный $wpdb stub нужен, т.к. нижняя строка admin/payouts.php
        // автоматически делает `new Cashback_Payouts_Admin()` при подключении.
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

        $payouts_file = $plugin_root . '/admin/payouts.php';
        if (!file_exists($payouts_file)) {
            self::markTestSkipped('admin/payouts.php not found');
        }
        if (!class_exists('Cashback_Payouts_Admin')) {
            require_once $payouts_file;
        }

        $recovery_file = $plugin_root . '/admin/class-cashback-encryption-recovery.php';
        if (!class_exists('Cashback_Encryption_Recovery')) {
            require_once $recovery_file;
        }
    }

    private function adminInstance(): Cashback_Payouts_Admin
    {
        $admin = (new \ReflectionClass(Cashback_Payouts_Admin::class))
            ->newInstanceWithoutConstructor();
        // Выставляем table_name через рефлексию (конструктор пропущен).
        $tn = new \ReflectionProperty($admin, 'table_name');
        $tn->setAccessible(true);
        $tn->setValue($admin, $this->wpdb->prefix . 'cashback_payout_requests');
        return $admin;
    }

    protected function setUp(): void
    {
        if (!Cashback_Encryption::is_configured()) {
            $this->markTestSkipped('Тесту требуется is_configured() === true.');
        }

        $this->wpdb = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            /** @var array<int, array{method:string, args:array<mixed>}> */
            public array $calls = array();
            /** @var array<string,array<string,mixed>|null> */
            public array $rows_by_sql_fragment = array();
            public int|string $next_var = 0;
            /** @var list<array<string,mixed>> */
            public array $next_get_results = array();
            public int $rows_affected = 0;

            public function query(string $sql): int|bool
            {
                $this->calls[] = array( 'method' => 'query', 'args' => array( $sql ) );
                if (preg_match('/^\s*(START\s+TRANSACTION|COMMIT|ROLLBACK)/i', $sql)) {
                    return true;
                }
                if (preg_match('/^\s*UPDATE\b/i', $sql)) {
                    return 1;
                }
                if (preg_match('/^\s*INSERT\b/i', $sql)) {
                    return 1;
                }
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
                return $this->next_get_results;
            }

            public function insert(string $table, array $data, array|string|null $format = null): int|false
            {
                $this->calls[] = array( 'method' => 'insert', 'args' => array( $table, $data ) );
                return 1;
            }

            public function update(string $table, array $data, array $where, array|string|null $format = null, array|string|null $where_format = null): int|false
            {
                $this->calls[] = array( 'method' => 'update', 'args' => array( $table, $data, $where ) );
                return 1;
            }
        };

        $GLOBALS['wpdb']             = $this->wpdb;
        $GLOBALS['_cb_test_options'] = array();
        $GLOBALS['_cb_test_user_id'] = 777;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $GLOBALS['_cb_test_options'] = array();
    }

    // Вспомогательный: создаёт row payout_request для get_row FOR UPDATE.
    private function setPayoutRow( array $fields ): void {
        $this->wpdb->rows_by_sql_fragment['cashback_payout_requests'] = array_merge(
            array(
                'id'           => 42,
                'user_id'      => 777,
                'total_amount' => '500.00',
                'status'       => 'waiting',
                'refunded_at'  => null,
            ),
            $fields
        );
    }

    private function setBalanceRow( string $pending = '500.00' ): void {
        $this->wpdb->rows_by_sql_fragment['cashback_user_balance'] = array(
            'pending_balance' => $pending,
        );
    }

    // ================================================================
    // cancel_payout_with_refund — базовое поведение
    // ================================================================

    public function test_cancel_payout_with_refund_transitions_waiting_to_failed(): void
    {
        $this->setPayoutRow(array( 'status' => 'waiting' ));
        $this->setBalanceRow('500.00');

        $admin = $this->adminInstance();
        $ok    = $admin->cancel_payout_with_refund(42, 'encryption_recovery', 1);

        $this->assertTrue($ok);

        // Должен быть UPDATE status → failed
        $updates = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => 'update' === $c['method']
                && isset($c['args'][1]['status'])
                && 'failed' === $c['args'][1]['status']
        );
        $this->assertNotEmpty($updates, 'Ожидаем UPDATE status=failed');
    }

    public function test_cancel_payout_with_refund_moves_pending_to_available(): void
    {
        $this->setPayoutRow(array( 'status' => 'waiting', 'total_amount' => '500.00' ));
        $this->setBalanceRow('500.00');

        $admin = $this->adminInstance();
        $admin->cancel_payout_with_refund(42, 'encryption_recovery', 1);

        // Должен быть UPDATE балансовой таблицы с pending → available.
        $balance_updates = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => 'query' === $c['method']
                && str_contains((string) $c['args'][0], 'cashback_user_balance')
                && preg_match('/UPDATE.*pending_balance\s*=\s*pending_balance\s*-/is', (string) $c['args'][0])
                && preg_match('/available_balance\s*=\s*available_balance\s*\+/is', (string) $c['args'][0])
        );
        $this->assertNotEmpty($balance_updates, 'Ожидаем UPDATE cashback_user_balance: pending → available');
    }

    public function test_cancel_payout_with_refund_writes_ledger_payout_cancel(): void
    {
        $this->setPayoutRow(array( 'status' => 'waiting' ));
        $this->setBalanceRow('500.00');

        $admin = $this->adminInstance();
        $admin->cancel_payout_with_refund(42, 'encryption_recovery', 1);

        $ledger_inserts = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => 'query' === $c['method']
                && preg_match('/INSERT\s+INTO\s+.*cashback_balance_ledger.*payout_cancel/is', (string) $c['args'][0])
        );
        $this->assertNotEmpty($ledger_inserts, 'Ожидаем INSERT в cashback_balance_ledger типа payout_cancel');
    }

    public function test_cancel_payout_with_refund_is_idempotent_when_already_refunded(): void
    {
        // refunded_at уже заполнен → повтор: true без нового UPDATE balance.
        $this->setPayoutRow(array(
            'status'      => 'waiting',
            'refunded_at' => '2026-04-21 12:00:00',
        ));

        $admin = $this->adminInstance();
        $ok    = $admin->cancel_payout_with_refund(42, 'encryption_recovery', 1);

        $this->assertTrue($ok);

        $balance_updates = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => 'query' === $c['method']
                && preg_match('/UPDATE.*cashback_user_balance/i', (string) $c['args'][0])
        );
        $this->assertEmpty($balance_updates, 'При refunded_at != null не должно быть повторного UPDATE баланса');
    }

    public function test_cancel_payout_with_refund_rejects_final_statuses(): void
    {
        foreach (array( 'paid', 'declined' ) as $final_status) {
            $this->wpdb->calls = array();
            $this->setPayoutRow(array( 'status' => $final_status ));

            $admin = $this->adminInstance();
            $ok    = $admin->cancel_payout_with_refund(42, 'encryption_recovery', 1);

            $this->assertFalse(
                $ok,
                "Отмена должна возвращать false для финального статуса '{$final_status}'"
            );
        }
    }

    public function test_cancel_payout_with_refund_writes_audit_log(): void
    {
        $this->setPayoutRow(array( 'status' => 'waiting' ));
        $this->setBalanceRow('500.00');
        $GLOBALS['_cb_audit_log_calls'] = array();

        $admin = $this->adminInstance();
        $admin->cancel_payout_with_refund(42, 'encryption_recovery', 1);

        // Найти INSERT в cashback_audit_log (делается из Cashback_Encryption::write_audit_log)
        $audit_inserts = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => 'insert' === $c['method']
                && isset($c['args'][0])
                && str_contains((string) $c['args'][0], 'cashback_audit_log')
                && isset($c['args'][1]['action'])
                && 'payout_cancelled_encryption_recovery' === $c['args'][1]['action']
        );
        $this->assertNotEmpty($audit_inserts, 'Ожидаем audit-log entry с action=payout_cancelled_encryption_recovery');
    }

    // ================================================================
    // cancel_waiting_payouts_batch (Cashback_Encryption_Recovery)
    // ================================================================

    public function test_cancel_waiting_payouts_batch_returns_count_cancelled(): void
    {
        // Эмулируем 3 waiting заявки.
        $this->wpdb->next_get_results = array(
            (object) array( 'id' => 1 ),
            (object) array( 'id' => 2 ),
            (object) array( 'id' => 3 ),
        );
        // Для каждой заявки: FOR UPDATE получит row → waiting + pending.
        $this->setPayoutRow(array( 'status' => 'waiting' ));
        $this->setBalanceRow('500.00');

        $count = Cashback_Encryption_Recovery::cancel_waiting_payouts_batch(100);

        $this->assertSame(3, $count, 'Batch должен вернуть число отменённых заявок');
    }

    public function test_cancel_waiting_payouts_batch_skips_final_statuses(): void
    {
        // SELECT вернёт IDs, но FOR UPDATE покажет уже изменённый статус.
        $this->wpdb->next_get_results = array( (object) array( 'id' => 1 ) );
        $this->setPayoutRow(array( 'status' => 'paid' ));  // гонка: статус уже перешёл

        $count = Cashback_Encryption_Recovery::cancel_waiting_payouts_batch(100);

        $this->assertSame(0, $count, 'Заявка с финальным статусом не должна быть пересчитана');
    }

    public function test_cancel_waiting_payouts_batch_is_idempotent_when_no_rows(): void
    {
        $this->wpdb->next_get_results = array();  // нет заявок

        $count = Cashback_Encryption_Recovery::cancel_waiting_payouts_batch(100);

        $this->assertSame(0, $count);
    }

    public function test_cancel_waiting_payouts_batch_respects_limit(): void
    {
        // Проверяем, что SELECT ограничен LIMIT.
        Cashback_Encryption_Recovery::cancel_waiting_payouts_batch(50);

        $selects = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => 'get_results' === $c['method']
                && preg_match('/LIMIT\s+50/i', (string) $c['args'][0])
        );
        $this->assertNotEmpty($selects, 'SELECT должен включать LIMIT 50');
    }

    // ================================================================
    // Двухфазная интеграция в run_batch_action_handler
    // ================================================================

    public function test_run_batch_action_handler_cancels_waitings_before_purging_profiles(): void
    {
        // Phase A: есть waiting → cancel_waiting_payouts_batch вернёт > 0, purge НЕ запускается.
        $this->wpdb->next_get_results = array( (object) array( 'id' => 1 ) );
        $this->wpdb->next_var         = 1;  // count_waiting_payouts() → >0
        $this->setPayoutRow(array( 'status' => 'waiting' ));
        $this->setBalanceRow('500.00');

        $GLOBALS['_cb_test_as_scheduled'] = false;

        Cashback_Encryption_Recovery::run_batch_action_handler();

        // UPDATE cashback_user_profile (purge) НЕ должен быть вызван на фазе A.
        $profile_purges = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => 'query' === $c['method']
                && preg_match('/UPDATE.*cashback_user_profile.*encrypted_details\s*=\s*\'\'/i', (string) $c['args'][0])
        );
        $this->assertEmpty(
            $profile_purges,
            'Пока есть waiting заявки, purge реквизитов не должен запускаться'
        );

        // AS job должен быть перепланирован.
        $this->assertNotFalse($GLOBALS['_cb_test_as_scheduled'], 'Ожидаем перепланирование AS-job');
    }
}
