<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты подшага 3.4: run_migrate_batch() фазовый dispatcher.
 *
 * Real per-phase updater'ы появятся в 3.5 — здесь dispatcher проверяется
 * на stub'ах (фильтр cashback_key_rotation_phase_batch_result инжектит результаты).
 */
#[Group('key-rotation')]
class KeyRotationDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        delete_option(Cashback_Key_Rotation::STATE_OPTION);
        $GLOBALS['_cb_test_as_scheduled'] = false;
        $GLOBALS['_cb_test_filters']      = array();
    }

    protected function tearDown(): void
    {
        $GLOBALS['_cb_test_filters']      = array();
        $GLOBALS['_cb_test_as_scheduled'] = false;
        delete_option(Cashback_Key_Rotation::STATE_OPTION);
    }

    /**
     * Ставит состояние migrating с current_phase=$phase и нулевыми progress/cursor.
     */
    private function set_migrating_state( string $phase = 'options_captcha', int $total_batches = 0 ): void
    {
        $progress = array();
        foreach (Cashback_Key_Rotation::PHASES as $p) {
            $progress[ $p ] = array( 'total' => 0, 'done' => 0, 'failed' => 0, 'cursor' => 0 );
        }
        Cashback_Key_Rotation::save_state(array(
            'state'         => 'migrating',
            'current_phase' => $phase,
            'progress'      => $progress,
            'total_batches' => $total_batches,
        ));
    }

    // ================================================================
    // Guards
    // ================================================================

    public function test_dispatcher_silent_exit_when_state_not_migrating(): void
    {
        Cashback_Key_Rotation::save_state(array( 'state' => 'idle' ));

        Cashback_Key_Rotation::run_migrate_batch();

        $this->assertSame('idle', Cashback_Key_Rotation::get_state()['state']);
        $this->assertFalse($GLOBALS['_cb_test_as_scheduled'], 'не должен re-scheduleить при state != migrating');
    }

    public function test_dispatcher_self_heals_when_current_phase_invalid(): void
    {
        // Несогласованное состояние: migrating, но current_phase=null.
        Cashback_Key_Rotation::save_state(array(
            'state'         => 'migrating',
            'current_phase' => null,
        ));

        Cashback_Key_Rotation::run_migrate_batch();

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('migrating', $state['state']);
        $this->assertSame(Cashback_Key_Rotation::PHASES[0], $state['current_phase']);
        $this->assertIsArray($GLOBALS['_cb_test_as_scheduled']);
        $this->assertSame(Cashback_Key_Rotation::AS_HOOK_MIGRATE, $GLOBALS['_cb_test_as_scheduled']['hook']);
    }

    // ================================================================
    // Phase progression с no-op stub'ом
    // ================================================================

    public function test_dispatcher_advances_phase_when_updater_no_op(): void
    {
        $this->set_migrating_state('options_captcha');

        Cashback_Key_Rotation::run_migrate_batch();

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('migrating', $state['state']);
        // Фаза продвинулась на следующую (options_epn).
        $this->assertSame('options_epn', $state['current_phase']);
        // Re-schedule запланирован.
        $this->assertIsArray($GLOBALS['_cb_test_as_scheduled']);
        $this->assertSame(Cashback_Key_Rotation::AS_HOOK_MIGRATE, $GLOBALS['_cb_test_as_scheduled']['hook']);
    }

    public function test_dispatcher_transitions_to_migrated_after_last_phase(): void
    {
        $last_phase = Cashback_Key_Rotation::PHASES[ count(Cashback_Key_Rotation::PHASES) - 1 ];
        $this->set_migrating_state($last_phase);

        Cashback_Key_Rotation::run_migrate_batch();

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('migrated', $state['state']);
        $this->assertNull($state['current_phase']);
    }

    public function test_dispatcher_runs_all_phases_to_migrated(): void
    {
        // Прогоняем n вызовов — ровно столько, сколько фаз.
        $this->set_migrating_state(Cashback_Key_Rotation::PHASES[0]);

        $max_iterations = count(Cashback_Key_Rotation::PHASES) + 2; // запас
        $iterations     = 0;
        while (Cashback_Key_Rotation::get_state()['state'] === 'migrating' && $iterations < $max_iterations) {
            Cashback_Key_Rotation::run_migrate_batch();
            ++$iterations;
        }

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('migrated', $state['state']);
        $this->assertSame(count(Cashback_Key_Rotation::PHASES), $iterations);
        $this->assertSame(count(Cashback_Key_Rotation::PHASES), $state['total_batches']);
    }

    // ================================================================
    // has_more=true: re-schedule без смены фазы
    // ================================================================

    public function test_dispatcher_reschedules_without_advancing_when_has_more(): void
    {
        $this->set_migrating_state('user_profile');

        add_filter(
            'cashback_key_rotation_phase_batch_result',
            static function ( array $default, string $phase ) {
                return array(
                    'processed'   => 50,
                    'failed'      => 2,
                    'next_cursor' => 123,
                    'has_more'    => true,
                );
            },
            10,
            4
        );

        Cashback_Key_Rotation::run_migrate_batch();

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('migrating', $state['state']);
        // Фаза НЕ переключилась.
        $this->assertSame('user_profile', $state['current_phase']);
        $this->assertSame(50,  $state['progress']['user_profile']['done']);
        $this->assertSame(2,   $state['progress']['user_profile']['failed']);
        $this->assertSame(123, $state['progress']['user_profile']['cursor']);
        $this->assertSame(1,   $state['total_batches']);
        $this->assertIsArray($GLOBALS['_cb_test_as_scheduled']);
    }

    // ================================================================
    // Error path: throw → last_error, без re-schedule
    // ================================================================

    public function test_dispatcher_records_last_error_and_does_not_reschedule(): void
    {
        $this->set_migrating_state('user_profile');

        add_filter(
            'cashback_key_rotation_phase_batch_result',
            static function () {
                throw new \RuntimeException('DB gone away');
            },
            10,
            4
        );

        Cashback_Key_Rotation::run_migrate_batch();

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('migrating', $state['state']);
        $this->assertSame('DB gone away', $state['last_error']);
        // Не re-scheduleим: UI покажет кнопку «Повторить».
        $this->assertFalse($GLOBALS['_cb_test_as_scheduled']);
    }

    // ================================================================
    // batch_size_for_phase
    // ================================================================

    public function test_batch_size_per_phase(): void
    {
        $this->assertSame(
            Cashback_Key_Rotation::BATCH_SIZE_PROFILES,
            Cashback_Key_Rotation::batch_size_for_phase('user_profile')
        );
        $this->assertSame(
            Cashback_Key_Rotation::BATCH_SIZE_PAYOUTS,
            Cashback_Key_Rotation::batch_size_for_phase('payout_requests')
        );
        $this->assertSame(
            Cashback_Key_Rotation::BATCH_SIZE_SMALL,
            Cashback_Key_Rotation::batch_size_for_phase('options_captcha')
        );
        $this->assertSame(
            Cashback_Key_Rotation::BATCH_SIZE_SMALL,
            Cashback_Key_Rotation::batch_size_for_phase('affiliate_networks')
        );
    }

    // ================================================================
    // run_phase_batch stub поведение
    // ================================================================

    public function test_run_phase_batch_stub_defaults(): void
    {
        $result = Cashback_Key_Rotation::run_phase_batch('user_profile', 42, 100);

        $this->assertSame(0,     $result['processed']);
        $this->assertSame(0,     $result['failed']);
        $this->assertSame(42,    $result['next_cursor']); // cursor сохраняется
        $this->assertFalse($result['has_more']);
    }

    public function test_run_phase_batch_unknown_phase_returns_defaults(): void
    {
        $result = Cashback_Key_Rotation::run_phase_batch('nope', 0, 100);

        $this->assertSame(0,    $result['processed']);
        $this->assertFalse($result['has_more']);
    }

    public function test_run_phase_batch_respects_filter_override(): void
    {
        add_filter(
            'cashback_key_rotation_phase_batch_result',
            static fn( array $default ) => array(
                'processed'   => 5,
                'failed'      => 1,
                'next_cursor' => 99,
                'has_more'    => true,
            ),
            10,
            4
        );

        $result = Cashback_Key_Rotation::run_phase_batch('user_profile', 10, 100);

        $this->assertSame(5,  $result['processed']);
        $this->assertSame(1,  $result['failed']);
        $this->assertSame(99, $result['next_cursor']);
        $this->assertTrue($result['has_more']);
    }
}
