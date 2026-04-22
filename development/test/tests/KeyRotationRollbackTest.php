<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты подшага 3.8: start_rollback() / run_rollback_batch() / reverse-sanity.
 *
 * Проверяет:
 *  - guards (state!=completed, expired cleanup_at, отсутствие previous, wrong confirmation)
 *  - file swap: previous → primary; primary → new.php; fingerprint обратно на OLD
 *  - state → rolling_back с fresh progress и первым AS_HOOK_ROLLBACK батчем
 *  - finalize reverse-sanity: удаление .new.php, unschedule cleanup, state=idle
 */
#[Group('key-rotation')]
class KeyRotationRollbackTest extends TestCase
{
    /** @var string */
    private string $tmp_dir = '';

    protected function setUp(): void
    {
        $this->tmp_dir = sys_get_temp_dir() . '/cb-kr-rollback-' . uniqid('', true);
        mkdir($this->tmp_dir, 0700, true);

        $primary_path  = $this->tmp_dir . '/.cashback-encryption-key.php';
        $new_path      = $this->tmp_dir . '/.cashback-encryption-key.new.php';
        $previous_path = $this->tmp_dir . '/.cashback-encryption-key.previous.php';

        add_filter('cashback_key_rotation_primary_key_path',  function () use ($primary_path) { return $primary_path; });
        add_filter('cashback_key_rotation_new_key_path',      function () use ($new_path) { return $new_path; });
        add_filter('cashback_key_rotation_previous_key_path', function () use ($previous_path) { return $previous_path; });

        delete_option(Cashback_Key_Rotation::STATE_OPTION);
        delete_option(Cashback_Key_Rotation::CLEANUP_DEADLINE_OPTION);
        delete_option('cashback_encryption_key_fingerprint');
        $GLOBALS['_cb_test_as_scheduled']   = false;
        $GLOBALS['_cb_test_as_unscheduled'] = array();
        unset($GLOBALS['wpdb']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp_dir . '/.cashback-encryption-key*') ?: array() as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp_dir);

        unset($GLOBALS['wpdb']);
        $GLOBALS['_cb_test_filters']        = array();
        $GLOBALS['_cb_test_as_scheduled']   = false;
        $GLOBALS['_cb_test_as_unscheduled'] = array();
        delete_option(Cashback_Key_Rotation::STATE_OPTION);
        delete_option(Cashback_Key_Rotation::CLEANUP_DEADLINE_OPTION);
        delete_option('cashback_encryption_key_fingerprint');
    }

    /**
     * Готовит post-finalize-состояние: primary-файл содержит NEW-hex, previous — OLD-hex,
     * .new.php отсутствует. State=completed.
     */
    private function setup_post_finalize( string $old_hex, string $new_hex, int $cleanup_at ): void
    {
        file_put_contents(Cashback_Key_Rotation::get_primary_key_path(),
            "<?php\nif (!defined('ABSPATH')) { exit; }\ndefine('CB_ENCRYPTION_KEY', '{$new_hex}');\n"
        );
        file_put_contents(Cashback_Key_Rotation::get_previous_key_path(),
            "<?php\nif (!defined('ABSPATH')) { exit; }\ndefine('CB_ENCRYPTION_KEY_PREVIOUS', '{$old_hex}');\n"
        );
        update_option(Cashback_Key_Rotation::CLEANUP_DEADLINE_OPTION, $cleanup_at, false);
        Cashback_Key_Rotation::save_state(array(
            'state'        => 'completed',
            'finalized_at' => date('Y-m-d H:i:s', time() - 3600),
            'initiator_id' => 5,
        ));
    }

    // ================================================================
    // Guards
    // ================================================================

    public function test_rollback_refuses_when_not_completed(): void
    {
        Cashback_Key_Rotation::save_state(array( 'state' => 'migrating' ));

        $result = Cashback_Key_Rotation::start_rollback(Cashback_Key_Rotation::CONFIRMATION_ROLLBACK);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('migrating', $result['message']);
    }

    public function test_rollback_refuses_on_wrong_confirmation(): void
    {
        $this->setup_post_finalize(str_repeat('a', 64), str_repeat('b', 64), time() + 3600);

        $result = Cashback_Key_Rotation::start_rollback('NOPE');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('подтверждение', $result['message']);
    }

    public function test_rollback_refuses_when_cleanup_window_expired(): void
    {
        $this->setup_post_finalize(str_repeat('a', 64), str_repeat('b', 64), time() - 60);

        $result = Cashback_Key_Rotation::start_rollback(Cashback_Key_Rotation::CONFIRMATION_ROLLBACK);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Окно отката', $result['message']);
    }

    public function test_rollback_refuses_when_previous_file_missing(): void
    {
        // primary есть, previous нет
        file_put_contents(Cashback_Key_Rotation::get_primary_key_path(),
            "<?php\ndefine('CB_ENCRYPTION_KEY', '" . str_repeat('b', 64) . "');\n"
        );
        Cashback_Key_Rotation::save_state(array( 'state' => 'completed' ));
        update_option(Cashback_Key_Rotation::CLEANUP_DEADLINE_OPTION, time() + 3600, false);

        $result = Cashback_Key_Rotation::start_rollback(Cashback_Key_Rotation::CONFIRMATION_ROLLBACK);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Файлы ключей', $result['message']);
    }

    public function test_rollback_refuses_when_new_file_already_exists(): void
    {
        $this->setup_post_finalize(str_repeat('a', 64), str_repeat('b', 64), time() + 3600);
        file_put_contents(Cashback_Key_Rotation::get_new_key_path(),
            "<?php\ndefine('CB_ENCRYPTION_KEY_NEW', '" . str_repeat('c', 64) . "');\n"
        );

        $result = Cashback_Key_Rotation::start_rollback(Cashback_Key_Rotation::CONFIRMATION_ROLLBACK);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('.new.php', $result['message']);
    }

    // ================================================================
    // Happy path: swap files
    // ================================================================

    public function test_rollback_swaps_files_and_updates_fingerprint(): void
    {
        $old_hex = str_repeat('a', 64);
        $new_hex = str_repeat('b', 64);
        $this->setup_post_finalize($old_hex, $new_hex, time() + 3600);

        $result = Cashback_Key_Rotation::start_rollback(Cashback_Key_Rotation::CONFIRMATION_ROLLBACK);

        $this->assertTrue($result['ok'], $result['message']);

        // primary теперь содержит OLD-hex.
        $primary_content = file_get_contents(Cashback_Key_Rotation::get_primary_key_path());
        $this->assertStringContainsString("define('CB_ENCRYPTION_KEY', '{$old_hex}')", $primary_content);

        // .new.php создан с NEW-hex (для batch-переработки обратно).
        $new_content = file_get_contents(Cashback_Key_Rotation::get_new_key_path());
        $this->assertStringContainsString("define('CB_ENCRYPTION_KEY_NEW', '{$new_hex}')", $new_content);

        // previous удалён.
        $this->assertFileDoesNotExist(Cashback_Key_Rotation::get_previous_key_path());

        // Fingerprint обновлён на OLD-hex.
        $expected_fp = hash_hmac('sha256', $old_hex, 'cashback_fingerprint_v1');
        $this->assertSame($expected_fp, (string) get_option('cashback_encryption_key_fingerprint'));

        // CLEANUP_DEADLINE снят + unschedule AS_HOOK_CLEANUP.
        $this->assertFalse(get_option(Cashback_Key_Rotation::CLEANUP_DEADLINE_OPTION));
        $this->assertCount(1, $GLOBALS['_cb_test_as_unscheduled']);
        $this->assertSame(Cashback_Key_Rotation::AS_HOOK_CLEANUP, $GLOBALS['_cb_test_as_unscheduled'][0]['hook']);
    }

    public function test_rollback_transitions_state_and_enqueues_batch(): void
    {
        $this->setup_post_finalize(str_repeat('a', 64), str_repeat('b', 64), time() + 3600);

        Cashback_Key_Rotation::start_rollback(Cashback_Key_Rotation::CONFIRMATION_ROLLBACK);

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('rolling_back', $state['state']);
        $this->assertSame(Cashback_Key_Rotation::PHASES[0], $state['current_phase']);
        foreach (Cashback_Key_Rotation::PHASES as $phase) {
            $this->assertSame(0, $state['progress'][ $phase ]['done']);
            $this->assertSame(0, $state['progress'][ $phase ]['failed']);
        }

        $this->assertIsArray($GLOBALS['_cb_test_as_scheduled']);
        $this->assertSame(Cashback_Key_Rotation::AS_HOOK_ROLLBACK, $GLOBALS['_cb_test_as_scheduled']['hook']);
    }

    // ================================================================
    // run_rollback_batch: state guard + phase advancement + → reverse sanity
    // ================================================================

    public function test_rollback_batch_silent_exit_when_state_not_rolling_back(): void
    {
        Cashback_Key_Rotation::save_state(array( 'state' => 'completed' ));

        Cashback_Key_Rotation::run_rollback_batch();

        $this->assertFalse($GLOBALS['_cb_test_as_scheduled']);
    }

    public function test_rollback_batch_advances_phase_when_updater_no_op(): void
    {
        Cashback_Key_Rotation::save_state(array(
            'state'         => 'rolling_back',
            'current_phase' => 'options_captcha',
        ));

        Cashback_Key_Rotation::run_rollback_batch();

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('rolling_back', $state['state']);
        $this->assertSame('options_epn', $state['current_phase']);
    }

    public function test_rollback_batch_triggers_reverse_sanity_after_last_phase(): void
    {
        $last_phase = Cashback_Key_Rotation::PHASES[ count(Cashback_Key_Rotation::PHASES) - 1 ];
        Cashback_Key_Rotation::save_state(array(
            'state'         => 'rolling_back',
            'current_phase' => $last_phase,
        ));

        Cashback_Key_Rotation::run_rollback_batch();

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('rolling_back', $state['state']);
        $this->assertTrue($state['sanity_active']);
        $this->assertSame('reverse', $state['sanity_direction']);
        $this->assertSame(Cashback_Key_Rotation::SANITY_PHASES[0], $state['sanity_current_phase']);
        $this->assertIsArray($GLOBALS['_cb_test_as_scheduled']);
        $this->assertSame(Cashback_Key_Rotation::AS_HOOK_SANITY, $GLOBALS['_cb_test_as_scheduled']['hook']);
    }

    // ================================================================
    // finalize_sanity_iteration в reverse-направлении
    // ================================================================

    public function test_reverse_sanity_finalization_cleans_up_and_returns_to_idle(): void
    {
        // Готовим: .new.php существует (остался от rollback swap), state=rolling_back+sanity.
        file_put_contents(Cashback_Key_Rotation::get_new_key_path(),
            "<?php\ndefine('CB_ENCRYPTION_KEY_NEW', '" . str_repeat('c', 64) . "');\n"
        );

        $last_sanity_phase = Cashback_Key_Rotation::SANITY_PHASES[ count(Cashback_Key_Rotation::SANITY_PHASES) - 1 ];
        Cashback_Key_Rotation::save_state(array(
            'state'                        => 'rolling_back',
            'sanity_active'                => true,
            'sanity_iteration'             => 1,
            'sanity_current_phase'         => $last_sanity_phase,
            'sanity_cursor'                => 0,
            'sanity_iteration_reencrypted' => 0,
            'sanity_direction'             => 'reverse',
            'initiator_id'                 => 5,
        ));

        Cashback_Key_Rotation::run_sanity_batch();

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('idle', $state['state'], 'После reverse-sanity state должен быть idle');
        $this->assertFalse($state['sanity_active']);
        $this->assertFileDoesNotExist(Cashback_Key_Rotation::get_new_key_path());
        // unschedule AS_HOOK_CLEANUP вызван.
        $this->assertGreaterThanOrEqual(1, count($GLOBALS['_cb_test_as_unscheduled']));
    }
}
