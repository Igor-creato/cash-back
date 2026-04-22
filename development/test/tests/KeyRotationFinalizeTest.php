<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты подшага 3.7: finalize() — swap ключевых файлов.
 *
 * Проверяет:
 *  - guards (state!=migrated, отсутствие файлов, previous уже существует);
 *  - atomic swap: primary → previous, new → primary, .new.php удалён;
 *  - обновление cashback_encryption_key_fingerprint на NEW-ключ;
 *  - cashback_key_rotation_cleanup_at в wp_option + AS_HOOK_CLEANUP schedule;
 *  - state → completed, finalized_at выставлен.
 */
#[Group('key-rotation')]
class KeyRotationFinalizeTest extends TestCase
{
    /** @var string */
    private string $tmp_dir = '';

    protected function setUp(): void
    {
        $this->tmp_dir = sys_get_temp_dir() . '/cb-kr-finalize-' . uniqid('', true);
        mkdir($this->tmp_dir, 0700, true);

        $primary_path = $this->tmp_dir . '/.cashback-encryption-key.php';
        $new_path     = $this->tmp_dir . '/.cashback-encryption-key.new.php';
        $prev_path    = $this->tmp_dir . '/.cashback-encryption-key.previous.php';

        add_filter('cashback_key_rotation_primary_key_path',  function () use ($primary_path) { return $primary_path; });
        add_filter('cashback_key_rotation_new_key_path',      function () use ($new_path) { return $new_path; });
        add_filter('cashback_key_rotation_previous_key_path', function () use ($prev_path) { return $prev_path; });

        delete_option(Cashback_Key_Rotation::STATE_OPTION);
        delete_option(Cashback_Key_Rotation::CLEANUP_DEADLINE_OPTION);
        delete_option('cashback_encryption_key_fingerprint');
        $GLOBALS['_cb_test_as_scheduled'] = false;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp_dir . '/.cashback-encryption-key*') ?: array() as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp_dir);

        $GLOBALS['_cb_test_filters']      = array();
        $GLOBALS['_cb_test_as_scheduled'] = false;
        delete_option(Cashback_Key_Rotation::STATE_OPTION);
        delete_option(Cashback_Key_Rotation::CLEANUP_DEADLINE_OPTION);
        delete_option('cashback_encryption_key_fingerprint');
    }

    /**
     * Создаёт пару key-файлов (primary + new) с заданными hex-значениями.
     */
    private function write_key_files( string $primary_hex, string $new_hex ): void
    {
        $primary = Cashback_Key_Rotation::get_primary_key_path();
        $new     = Cashback_Key_Rotation::get_new_key_path();

        file_put_contents($primary,
            "<?php\nif (!defined('ABSPATH')) { exit; }\ndefine('CB_ENCRYPTION_KEY', '{$primary_hex}');\n"
        );
        file_put_contents($new,
            "<?php\nif (!defined('ABSPATH')) { exit; }\ndefine('CB_ENCRYPTION_KEY_NEW', '{$new_hex}');\n"
        );
    }

    private function set_migrated_state(): void
    {
        Cashback_Key_Rotation::save_state(array(
            'state'           => 'migrated',
            'started_at'      => '2026-04-01 10:00:00',
            'sanity_active'   => false,
        ));
    }

    // ================================================================
    // Guards
    // ================================================================

    public function test_finalize_refuses_when_not_migrated(): void
    {
        Cashback_Key_Rotation::save_state(array( 'state' => 'migrating' ));

        $result = Cashback_Key_Rotation::finalize();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('migrating', $result['message']);
    }

    public function test_finalize_refuses_when_new_key_missing(): void
    {
        $this->set_migrated_state();
        file_put_contents(Cashback_Key_Rotation::get_primary_key_path(),
            "<?php\ndefine('CB_ENCRYPTION_KEY', '" . str_repeat('a', 64) . "');\n"
        );
        // .new.php не создан
        $result = Cashback_Key_Rotation::finalize();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Staging', $result['message']);
    }

    public function test_finalize_refuses_when_previous_key_exists(): void
    {
        $this->set_migrated_state();
        $this->write_key_files(str_repeat('a', 64), str_repeat('b', 64));
        file_put_contents(Cashback_Key_Rotation::get_previous_key_path(),
            "<?php\ndefine('CB_ENCRYPTION_KEY_PREVIOUS', '" . str_repeat('c', 64) . "');\n"
        );

        $result = Cashback_Key_Rotation::finalize();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Previous', $result['message']);
    }

    public function test_finalize_refuses_when_key_file_hex_invalid(): void
    {
        $this->set_migrated_state();
        // Кривой формат primary-файла
        file_put_contents(Cashback_Key_Rotation::get_primary_key_path(), "<?php // без define\n");
        file_put_contents(Cashback_Key_Rotation::get_new_key_path(),
            "<?php\ndefine('CB_ENCRYPTION_KEY_NEW', '" . str_repeat('b', 64) . "');\n"
        );

        $result = Cashback_Key_Rotation::finalize();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('hex-ключ', $result['message']);
    }

    // ================================================================
    // Happy path
    // ================================================================

    public function test_finalize_swaps_key_files_atomically(): void
    {
        $this->set_migrated_state();
        $primary_hex = str_repeat('a', 64);
        $new_hex     = str_repeat('b', 64);
        $this->write_key_files($primary_hex, $new_hex);

        $result = Cashback_Key_Rotation::finalize();

        $this->assertTrue($result['ok'], $result['message']);

        // Primary теперь содержит NEW hex.
        $primary_content = file_get_contents(Cashback_Key_Rotation::get_primary_key_path());
        $this->assertStringContainsString("define('CB_ENCRYPTION_KEY', '{$new_hex}')", $primary_content);

        // Previous содержит OLD hex.
        $previous_content = file_get_contents(Cashback_Key_Rotation::get_previous_key_path());
        $this->assertStringContainsString("define('CB_ENCRYPTION_KEY_PREVIOUS', '{$primary_hex}')", $previous_content);

        // .new.php удалён.
        $this->assertFileDoesNotExist(Cashback_Key_Rotation::get_new_key_path());

        // Никаких .tmp не осталось.
        $this->assertFileDoesNotExist(Cashback_Key_Rotation::get_primary_key_path() . '.tmp');
        $this->assertFileDoesNotExist(Cashback_Key_Rotation::get_previous_key_path() . '.tmp');
    }

    public function test_finalize_updates_fingerprint_to_new_key(): void
    {
        $this->set_migrated_state();
        $new_hex = str_repeat('b', 64);
        $this->write_key_files(str_repeat('a', 64), $new_hex);

        Cashback_Key_Rotation::finalize();

        $expected_fp = hash_hmac('sha256', $new_hex, 'cashback_fingerprint_v1');
        $this->assertSame($expected_fp, (string) get_option('cashback_encryption_key_fingerprint'));
    }

    public function test_finalize_schedules_cleanup_in_7_days(): void
    {
        $this->set_migrated_state();
        $this->write_key_files(str_repeat('a', 64), str_repeat('b', 64));

        $before = time();
        $result = Cashback_Key_Rotation::finalize();
        $after  = time();

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('cleanup_at', $result);

        $expected_min = $before + 7 * 86400;
        $expected_max = $after + 7 * 86400;
        $this->assertGreaterThanOrEqual($expected_min, $result['cleanup_at']);
        $this->assertLessThanOrEqual($expected_max, $result['cleanup_at']);

        // wp_option совпадает с возвращённым cleanup_at.
        $this->assertSame($result['cleanup_at'], (int) get_option(Cashback_Key_Rotation::CLEANUP_DEADLINE_OPTION));

        // AS_HOOK_CLEANUP запланирован.
        $this->assertIsArray($GLOBALS['_cb_test_as_scheduled']);
        $this->assertSame(Cashback_Key_Rotation::AS_HOOK_CLEANUP, $GLOBALS['_cb_test_as_scheduled']['hook']);
        $this->assertSame($result['cleanup_at'], (int) $GLOBALS['_cb_test_as_scheduled']['timestamp']);
    }

    public function test_finalize_transitions_state_to_completed(): void
    {
        $this->set_migrated_state();
        $this->write_key_files(str_repeat('a', 64), str_repeat('b', 64));

        Cashback_Key_Rotation::finalize();

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('completed', $state['state']);
        $this->assertNotNull($state['finalized_at']);
        $this->assertNull($state['current_phase']);
    }
}
