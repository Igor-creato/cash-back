<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Математический тест инвариантов validate_user_balance_consistency (Группа 14, шаг A).
 *
 * Воспроизводит формулу Mariadb_Plugin::validate_user_balance_consistency()
 * для ban_freeze/ban_unfreeze случаев, без загрузки самого класса (он тянет
 * десятки зависимостей). Цель — убедиться, что ledger-суммы при включённых
 * ban_freeze/ban_unfreeze дают total == cache.total в ключевых сценариях:
 *   1. Свежий ban с available+pending → frozen_balance.
 *   2. Unban → возврат в available+pending.
 *   3. Идемпотентный повтор ban (повторный Cashback_Ban_Ledger::write_freeze_entry
 *      не создаст новую запись благодаря ON DUPLICATE KEY UPDATE, поэтому суммы
 *      в ledger одинаковы).
 */
#[Group('ledger')]
#[Group('ban')]
#[Group('group-14')]
class BanLedgerConsistencyTest extends TestCase
{
    /**
     * @param array<string,string> $sums Ledger sums: ключ=тип, значение=signed amount string
     * @return array{available:string, pending:string, paid:string, frozen:string, total:string}
     */
    private function compute_ledger_projection( array $sums ): array
    {
        $defaults = array(
            'accrual'            => '0.00',
            'payout_hold'        => '0.00',
            'payout_complete'    => '0.00',
            'payout_cancel'      => '0.00',
            'payout_declined'    => '0.00',
            'adjustment'         => '0.00',
            'affiliate_accrual'  => '0.00',
            'affiliate_reversal' => '0.00',
            'affiliate_freeze'   => '0.00',
            'affiliate_unfreeze' => '0.00',
            'ban_freeze'         => '0.00',
            'ban_unfreeze'       => '0.00',
        );
        $s        = array_merge($defaults, $sums);

        $abs_hold     = bcmul($s['payout_hold'], '-1', 2);
        $abs_complete = bcmul($s['payout_complete'], '-1', 2);
        $abs_declined = bcmul($s['payout_declined'], '-1', 2);

        $aff_net    = bcadd(
            bcadd($s['affiliate_accrual'], $s['affiliate_reversal'], 2),
            bcadd($s['affiliate_freeze'], $s['affiliate_unfreeze'], 2),
            2
        );
        $aff_frozen = bcadd(bcmul($s['affiliate_freeze'], '-1', 2), bcmul($s['affiliate_unfreeze'], '-1', 2), 2);
        if (bccomp($aff_frozen, '0', 2) < 0) {
            $aff_frozen = '0.00';
        }

        $ban_net    = bcadd($s['ban_freeze'], $s['ban_unfreeze'], 2);
        $ban_frozen = bcsub(bcmul($s['ban_freeze'], '-1', 2), $s['ban_unfreeze'], 2);
        if (bccomp($ban_frozen, '0', 2) < 0) {
            $ban_frozen = '0.00';
        }

        $available = bcadd(
            bcadd(
                bcadd(
                    bcadd(
                        bcadd($s['accrual'], $s['payout_hold'], 2),
                        $s['payout_cancel'],
                        2
                    ),
                    $s['adjustment'],
                    2
                ),
                $aff_net,
                2
            ),
            $ban_net,
            2
        );

        $pending = bcsub(
            bcsub(bcsub($abs_hold, $abs_complete, 2), $abs_declined, 2),
            $s['payout_cancel'],
            2
        );

        $paid   = $abs_complete;
        $frozen = bcadd(bcadd($abs_declined, $aff_frozen, 2), $ban_frozen, 2);

        return array(
            'available' => $available,
            'pending'   => $pending,
            'paid'      => $paid,
            'frozen'    => $frozen,
            'total'     => bcadd(bcadd(bcadd($available, $pending, 2), $paid, 2), $frozen, 2),
        );
    }

    public function test_fresh_ban_with_available_only_matches_cache_total(): void
    {
        // Сценарий: 1 transaction начислена (accrual=100), пользователь забанен
        // (available 100 → frozen_balance_ban 100). ban_freeze=-100.
        $proj = $this->compute_ledger_projection(array(
            'accrual'    => '100.00',
            'ban_freeze' => '-100.00',
        ));

        // Ожидаемый кэш после триггера:
        //   available=0, pending=0, frozen_balance=100, paid=0.
        $cache_total = bcadd(bcadd(bcadd('0.00', '0.00', 2), '0.00', 2), '100.00', 2);

        $this->assertSame($cache_total, $proj['total'], 'Ledger total должен совпадать с cache total');
        $this->assertSame('100.00', $proj['frozen'], 'ledger_frozen включает ban_frozen=100');
        $this->assertSame('0.00', $proj['available'], 'available обнулён через ban_net=-100');
    }

    public function test_fresh_ban_with_available_and_pending_matches_cache_total(): void
    {
        // Сценарий: accrual=100, hold=50 (→ pending=50, available=50), ban.
        // ban_freeze замораживает оба бакета = -(50+50) = -100.
        $proj = $this->compute_ledger_projection(array(
            'accrual'     => '100.00',
            'payout_hold' => '-50.00',
            'ban_freeze'  => '-100.00',
        ));

        // После ban триггер: available=0, pending=0, frozen=100.
        $cache_total = '100.00';

        $this->assertSame($cache_total, $proj['total'], 'total сохраняется при ban');
        $this->assertSame('100.00', $proj['frozen'], 'frozen теперь 100 (весь ban-объём)');
    }

    public function test_unban_restores_available_and_pending(): void
    {
        // Сценарий продолжения выше: разбан → ban_unfreeze=+100.
        $proj = $this->compute_ledger_projection(array(
            'accrual'      => '100.00',
            'payout_hold'  => '-50.00',
            'ban_freeze'   => '-100.00',
            'ban_unfreeze' => '100.00',
        ));

        // После unban триггер: available=50, pending=50, frozen=0.
        $this->assertSame('50.00', $proj['available']);
        $this->assertSame('50.00', $proj['pending']);
        $this->assertSame('0.00', $proj['frozen']);
        $this->assertSame('100.00', $proj['total']);
    }

    public function test_ban_on_zero_balance_user_does_not_distort_total(): void
    {
        // Пользователь без баланса, забанен. ban_freeze пропускается
        // (Cashback_Ban_Ledger не создаёт запись при нулевой сумме).
        $proj = $this->compute_ledger_projection(array());

        $this->assertSame('0.00', $proj['total']);
    }

    public function test_partial_unban_is_invalid_state_but_math_survives(): void
    {
        // Defensive: если по какой-то причине ban_unfreeze частичный (10 из 100),
        // математика должна оставаться согласованной: ban_frozen=90, available=-90+10=-80
        // (unreachable в реале, но invariant total сохраняется).
        $proj = $this->compute_ledger_projection(array(
            'accrual'      => '100.00',
            'ban_freeze'   => '-100.00',
            'ban_unfreeze' => '10.00',
        ));

        $this->assertSame('90.00', $proj['frozen']);
        $this->assertSame('10.00', $proj['available']);
        $this->assertSame('100.00', $proj['total']);
    }

    public function test_ban_freeze_combines_with_affiliate_freeze_in_frozen_total(): void
    {
        // Параллельно ban_freeze и affiliate_freeze — оба должны суммироваться в frozen.
        $proj = $this->compute_ledger_projection(array(
            'accrual'          => '100.00',
            'affiliate_accrual' => '20.00',
            'affiliate_freeze' => '-20.00',
            'ban_freeze'       => '-100.00',
        ));

        $this->assertSame('120.00', $proj['frozen'], 'frozen = ban_frozen(100) + aff_frozen(20)');
        $this->assertSame('0.00', $proj['available']);
        $this->assertSame('120.00', $proj['total']);
    }
}
