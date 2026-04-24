<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты Cashback_Ban_Ledger — helper для ledger-записей ban_freeze/ban_unfreeze.
 *
 * Покрывает Группу 14 (ledger-first coverage, шаг A):
 *  - write_freeze_entry пишет ledger amount=-(frozen_*_ban total) при non-zero ban-бакетах,
 *  - write_unfreeze_entry пишет ledger amount=+(frozen_*_ban total),
 *  - оба метода идемпотентны через UNIQUE idempotency_key = ban_{freeze|unfreeze}_{uid}_{ts},
 *  - при нулевых ban-бакетах ничего в ledger не пишется.
 */
#[Group('ledger')]
#[Group('ban')]
#[Group('group-14')]
class BanLedgerHelperTest extends TestCase
{
    private object $wpdb;

    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $helper_file = $plugin_root . '/includes/class-cashback-ban-ledger.php';

        if (!file_exists($helper_file)) {
            self::markTestSkipped('class-cashback-ban-ledger.php not found');
        }
        if (!class_exists('Cashback_Ban_Ledger')) {
            require_once $helper_file;
        }
    }

    protected function setUp(): void
    {
        $this->wpdb = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public int $rows_affected = 0;
            /** @var array<int, array{method:string, args:array<mixed>}> */
            public array $calls = array();
            /** @var array{frozen_balance_ban:string, frozen_pending_balance_ban:string}|null */
            public ?array $next_row = null;
            public int|bool $next_query_result = 1;

            public function query(string $sql): int|bool
            {
                $this->calls[] = array( 'method' => 'query', 'args' => array( $sql ) );
                $this->rows_affected = (int) $this->next_query_result;
                return $this->next_query_result;
            }

            public function prepare(string $q, mixed ...$args): string
            {
                $flat = array();
                foreach ($args as $a) {
                    if (is_array($a)) {
                        foreach ($a as $v) {
                            $flat[] = $v;
                        }
                    } else {
                        $flat[] = $a;
                    }
                }
                // Наивная подстановка placeholders для capture — НЕ для боевого экранирования.
                $positions = array();
                $out       = '';
                $i         = 0;
                $len       = strlen($q);
                $idx       = 0;
                while ($i < $len) {
                    if ($q[ $i ] === '%' && $i + 1 < $len) {
                        $spec = $q[ $i + 1 ];
                        if (in_array($spec, array( 'd', 's', 'i', 'f' ), true) && array_key_exists($idx, $flat)) {
                            $v = $flat[ $idx ];
                            if ($spec === 'd') {
                                $out .= (string) (int) $v;
                            } elseif ($spec === 'i') {
                                $out .= '`' . (string) $v . '`';
                            } elseif ($spec === 'f') {
                                $out .= (string) (float) $v;
                            } else {
                                $out .= "'" . str_replace("'", "''", (string) $v) . "'";
                            }
                            $idx++;
                            $i += 2;
                            continue;
                        }
                    }
                    $out .= $q[ $i ];
                    $i++;
                }
                $this->calls[] = array( 'method' => 'prepare', 'args' => array( $q, $flat ) );
                return $out;
            }

            public function get_row(string $q, string $output = OBJECT, int $y = 0): mixed
            {
                $this->calls[] = array( 'method' => 'get_row', 'args' => array( $q ) );
                if ($this->next_row === null) {
                    return null;
                }
                return (object) $this->next_row;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    // =========================================================
    // write_freeze_entry
    // =========================================================

    public function test_freeze_writes_ledger_with_negative_amount_when_ban_buckets_positive(): void
    {
        $this->wpdb->next_row          = array(
            'frozen_balance_ban'         => '150.00',
            'frozen_pending_balance_ban' => '50.50',
        );
        $this->wpdb->next_query_result = 1;

        $result = Cashback_Ban_Ledger::write_freeze_entry(42, 1714000000);

        $this->assertTrue($result['written'], 'Ожидаем что ledger-запись написана');
        $this->assertSame('-200.50', $result['amount'], 'amount = -(frozen_ban + frozen_pending_ban)');
        $this->assertSame('ban_freeze_42_1714000000', $result['idempotency_key']);

        $insert_calls = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => $c['method'] === 'query'
                && preg_match('/^\s*INSERT\s+INTO.*cashback_balance_ledger/is', (string) $c['args'][0]) === 1
        );
        $this->assertNotEmpty($insert_calls, 'Должен быть хотя бы один INSERT в cashback_balance_ledger');

        $sql = (string) array_values($insert_calls)[0]['args'][0];
        $this->assertStringContainsString("'ban_freeze'", $sql, 'type=ban_freeze');
        $this->assertStringContainsString("'-200.50'", $sql, 'amount=-200.50 (отрицательный)');
        $this->assertStringContainsString("'ban_freeze_42_1714000000'", $sql, 'idempotency_key детерминированный');
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql, 'idempotency через UNIQUE');
    }

    public function test_freeze_skips_ledger_when_both_ban_buckets_zero(): void
    {
        $this->wpdb->next_row = array(
            'frozen_balance_ban'         => '0.00',
            'frozen_pending_balance_ban' => '0.00',
        );

        $result = Cashback_Ban_Ledger::write_freeze_entry(42, 1714000000);

        $this->assertFalse($result['written']);
        $this->assertSame('0.00', $result['amount']);

        $insert_calls = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => $c['method'] === 'query'
                && preg_match('/INSERT\s+INTO.*cashback_balance_ledger/is', (string) $c['args'][0]) === 1
        );
        $this->assertEmpty($insert_calls, 'При нулевых ban-бакетах INSERT в ledger НЕ должен выполняться');
    }

    public function test_freeze_selects_for_update_to_prevent_race(): void
    {
        $this->wpdb->next_row = array(
            'frozen_balance_ban'         => '10.00',
            'frozen_pending_balance_ban' => '0.00',
        );

        Cashback_Ban_Ledger::write_freeze_entry(7, 1700000000);

        $select_call = array_values(array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => $c['method'] === 'get_row'
                && preg_match('/SELECT.*cashback_user_balance/is', (string) $c['args'][0]) === 1
        ));
        $this->assertNotEmpty($select_call, 'Должен быть SELECT по cashback_user_balance');
        $this->assertStringContainsString('FOR UPDATE', (string) $select_call[0]['args'][0]);
    }

    // =========================================================
    // write_unfreeze_entry
    // =========================================================

    public function test_unfreeze_writes_ledger_with_positive_amount_when_ban_buckets_positive(): void
    {
        $this->wpdb->next_row          = array(
            'frozen_balance_ban'         => '75.25',
            'frozen_pending_balance_ban' => '24.75',
        );
        $this->wpdb->next_query_result = 1;

        $result = Cashback_Ban_Ledger::write_unfreeze_entry(42, 1714000000);

        $this->assertTrue($result['written']);
        $this->assertSame('100.00', $result['amount']);
        $this->assertSame('ban_unfreeze_42_1714000000', $result['idempotency_key']);

        $insert_calls = array_values(array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => $c['method'] === 'query'
                && preg_match('/INSERT\s+INTO.*cashback_balance_ledger/is', (string) $c['args'][0]) === 1
        ));
        $this->assertNotEmpty($insert_calls);

        $sql = (string) $insert_calls[0]['args'][0];
        $this->assertStringContainsString("'ban_unfreeze'", $sql);
        $this->assertStringContainsString("'100.00'", $sql, 'amount=+100.00 (положительный)');
        $this->assertStringContainsString("'ban_unfreeze_42_1714000000'", $sql);
    }

    public function test_unfreeze_skips_ledger_when_both_ban_buckets_zero(): void
    {
        $this->wpdb->next_row = array(
            'frozen_balance_ban'         => '0.00',
            'frozen_pending_balance_ban' => '0.00',
        );

        $result = Cashback_Ban_Ledger::write_unfreeze_entry(42, 1714000000);

        $this->assertFalse($result['written']);

        $insert_calls = array_filter(
            $this->wpdb->calls,
            static fn(array $c): bool => $c['method'] === 'query'
                && preg_match('/INSERT\s+INTO.*cashback_balance_ledger/is', (string) $c['args'][0]) === 1
        );
        $this->assertEmpty($insert_calls);
    }

    public function test_idempotency_keys_distinct_for_different_timestamps(): void
    {
        $this->wpdb->next_row = array(
            'frozen_balance_ban'         => '10.00',
            'frozen_pending_balance_ban' => '0.00',
        );

        $r1 = Cashback_Ban_Ledger::write_freeze_entry(42, 1714000000);
        $r2 = Cashback_Ban_Ledger::write_freeze_entry(42, 1714000001);

        $this->assertNotSame($r1['idempotency_key'], $r2['idempotency_key']);
    }

    public function test_freeze_and_unfreeze_idempotency_keys_distinct(): void
    {
        $this->wpdb->next_row = array(
            'frozen_balance_ban'         => '10.00',
            'frozen_pending_balance_ban' => '0.00',
        );

        $freeze   = Cashback_Ban_Ledger::write_freeze_entry(42, 1714000000);
        $unfreeze = Cashback_Ban_Ledger::write_unfreeze_entry(42, 1714000000);

        $this->assertSame('ban_freeze_42_1714000000', $freeze['idempotency_key']);
        $this->assertSame('ban_unfreeze_42_1714000000', $unfreeze['idempotency_key']);
    }

    public function test_freeze_throws_on_sql_error(): void
    {
        $this->wpdb->next_row          = array(
            'frozen_balance_ban'         => '10.00',
            'frozen_pending_balance_ban' => '0.00',
        );
        $this->wpdb->next_query_result = false;
        $this->wpdb->last_error        = 'simulated SQL error';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ban ledger INSERT (freeze) failed');

        Cashback_Ban_Ledger::write_freeze_entry(42, 1714000000);
    }
}
