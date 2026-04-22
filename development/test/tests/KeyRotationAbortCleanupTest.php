<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты подшага 3.9: abort_during_staging + cleanup_previous_key.
 */
#[Group('key-rotation')]
class KeyRotationAbortCleanupTest extends TestCase
{
    /** @var string */
    private string $tmp_dir = '';

    protected function setUp(): void
    {
        $this->tmp_dir = sys_get_temp_dir() . '/cb-kr-abort-' . uniqid('', true);
        mkdir($this->tmp_dir, 0700, true);

        $primary_path  = $this->tmp_dir . '/.cashback-encryption-key.php';
        $new_path      = $this->tmp_dir . '/.cashback-encryption-key.new.php';
        $previous_path = $this->tmp_dir . '/.cashback-encryption-key.previous.php';

        add_filter('cashback_key_rotation_primary_key_path',  function () use ($primary_path) { return $primary_path; });
        add_filter('cashback_key_rotation_new_key_path',      function () use ($new_path) { return $new_path; });
        add_filter('cashback_key_rotation_previous_key_path', function () use ($previous_path) { return $previous_path; });

        delete_option(Cashback_Key_Rotation::STATE_OPTION);
        delete_option(Cashback_Key_Rotation::CLEANUP_DEADLINE_OPTION);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp_dir . '/.cashback-encryption-key*') ?: array() as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp_dir);

        $GLOBALS['_cb_test_filters'] = array();
        delete_option(Cashback_Key_Rotation::STATE_OPTION);
        delete_option(Cashback_Key_Rotation::CLEANUP_DEADLINE_OPTION);
    }

    // ================================================================
    // abort_during_staging
    // ================================================================

    public function test_abort_removes_new_file_and_returns_to_idle(): void
    {
        // staging state + .new.php на диске
        file_put_contents(Cashback_Key_Rotation::get_new_key_path(),
            "<?php\ndefine('CB_ENCRYPTION_KEY_NEW', '" . str_repeat('c', 64) . "');\n"
        );
        Cashback_Key_Rotation::save_state(array(
            'state'        => 'staging',
            'started_at'   => '2026-04-22 10:00:00',
            'initiator_id' => 5,
        ));

        $result = Cashback_Key_Rotation::abort_during_staging();

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertFileDoesNotExist(Cashback_Key_Rotation::get_new_key_path());

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('idle', $state['state']);
        $this->assertNull($state['started_at']);
    }

    public function test_abort_refuses_when_state_migrating(): void
    {
        Cashback_Key_Rotation::save_state(array( 'state' => 'migrating' ));

        $result = Cashback_Key_Rotation::abort_during_staging();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('staging', $result['message']);
        $this->assertSame('migrating', Cashback_Key_Rotation::get_state()['state']);
    }

    public function test_abort_refuses_when_done_progress_present(): void
    {
        // Искусственно портим state: staging но с done>0 → отказываем.
        Cashback_Key_Rotation::save_state(array(
            'state'    => 'staging',
            'progress' => array(
                'user_profile' => array( 'total' => 10, 'done' => 5, 'failed' => 0, 'cursor' => 0 ),
            ),
        ));

        $result = Cashback_Key_Rotation::abort_during_staging();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('rollback', $result['message']);
    }

    public function test_abort_works_when_new_file_already_missing(): void
    {
        // Файла нет (например, admin удалил вручную) — state меняется, unlink no-op.
        Cashback_Key_Rotation::save_state(array( 'state' => 'staging' ));

        $result = Cashback_Key_Rotation::abort_during_staging();

        $this->assertTrue($result['ok']);
        $this->assertSame('idle', Cashback_Key_Rotation::get_state()['state']);
    }

    // ================================================================
    // cleanup_previous_key (AS_HOOK_CLEANUP handler)
    // ================================================================

    public function test_cleanup_deletes_previous_when_deadline_passed(): void
    {
        file_put_contents(Cashback_Key_Rotation::get_previous_key_path(),
            "<?php\ndefine('CB_ENCRYPTION_KEY_PREVIOUS', '" . str_repeat('a', 64) . "');\n"
        );
        Cashback_Key_Rotation::save_state(array(
            'state'        => 'completed',
            'finalized_at' => date('Y-m-d H:i:s', time() - 10 * 86400),
        ));
        update_option(Cashback_Key_Rotation::CLEANUP_DEADLINE_OPTION, time() - 3600, false); // уже просрочен

        Cashback_Key_Rotation::cleanup_previous_key();

        $this->assertFileDoesNotExist(Cashback_Key_Rotation::get_previous_key_path());
        $this->assertFalse(get_option(Cashback_Key_Rotation::CLEANUP_DEADLINE_OPTION));
    }

    public function test_cleanup_noop_before_deadline(): void
    {
        file_put_contents(Cashback_Key_Rotation::get_previous_key_path(),
            "<?php\ndefine('CB_ENCRYPTION_KEY_PREVIOUS', '" . str_repeat('a', 64) . "');\n"
        );
        Cashback_Key_Rotation::save_state(array( 'state' => 'completed' ));
        update_option(Cashback_Key_Rotation::CLEANUP_DEADLINE_OPTION, time() + 3600, false); // ещё не пришло

        Cashback_Key_Rotation::cleanup_previous_key();

        $this->assertFileExists(Cashback_Key_Rotation::get_previous_key_path());
        $this->assertSame(time() + 3600, (int) get_option(Cashback_Key_Rotation::CLEANUP_DEADLINE_OPTION));
    }

    public function test_cleanup_noop_when_state_not_completed(): void
    {
        // Например, rollback случился и state=idle — previous тоже уже удалён rollback'ом.
        Cashback_Key_Rotation::save_state(array( 'state' => 'idle' ));
        // CLEANUP_DEADLINE снят rollback'ом

        Cashback_Key_Rotation::cleanup_previous_key();

        $this->assertSame('idle', Cashback_Key_Rotation::get_state()['state']);
    }

    public function test_cleanup_clears_deadline_option_even_if_file_missing(): void
    {
        // state=completed, deadline просрочен, но файла уже нет.
        Cashback_Key_Rotation::save_state(array( 'state' => 'completed' ));
        update_option(Cashback_Key_Rotation::CLEANUP_DEADLINE_OPTION, time() - 3600, false);

        Cashback_Key_Rotation::cleanup_previous_key();

        // Option всё равно сброшен для консистентности.
        $this->assertFalse(get_option(Cashback_Key_Rotation::CLEANUP_DEADLINE_OPTION));
    }
}
