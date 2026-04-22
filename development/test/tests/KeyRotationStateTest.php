<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты state-machine и generate_new_key() в Cashback_Key_Rotation.
 *
 * Покрывает только шаг 3.1 / 3.2 плана (каркас + staging-генерация).
 * Фазовые batch-job, finalize, rollback покрываются в последующих тестах.
 */
#[Group('key-rotation')]
class KeyRotationStateTest extends TestCase
{
    /** @var string */
    private string $tmp_dir = '';

    protected function setUp(): void
    {
        // Изоляция файлов ключей: каждый тест работает в своей tmp-директории.
        $this->tmp_dir = sys_get_temp_dir() . '/cb-key-rotation-' . uniqid('', true);
        mkdir($this->tmp_dir, 0700, true);

        $dir = $this->tmp_dir;
        $new_path = $dir . '/.cashback-encryption-key.new.php';
        $prev_path = $dir . '/.cashback-encryption-key.previous.php';
        $primary_path = $dir . '/.cashback-encryption-key.php';

        add_filter('cashback_key_rotation_primary_key_path',  function () use ($primary_path) { return $primary_path; });
        add_filter('cashback_key_rotation_new_key_path',      function () use ($new_path) { return $new_path; });
        add_filter('cashback_key_rotation_previous_key_path', function () use ($prev_path) { return $prev_path; });

        // Сбрасываем state-option между тестами.
        delete_option(Cashback_Key_Rotation::STATE_OPTION);
    }

    protected function tearDown(): void
    {
        // Чистим tmp-файлы.
        foreach (glob($this->tmp_dir . '/.cashback-encryption-key*') ?: array() as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp_dir);

        // Сбрасываем фильтры.
        $GLOBALS['_cb_test_filters'] = array();

        delete_option(Cashback_Key_Rotation::STATE_OPTION);
    }

    // ================================================================
    // State helpers
    // ================================================================

    public function test_get_state_returns_idle_when_missing(): void
    {
        delete_option(Cashback_Key_Rotation::STATE_OPTION);
        $state = Cashback_Key_Rotation::get_state();

        $this->assertSame('idle', $state['state']);
        $this->assertNull($state['started_at']);
        $this->assertNull($state['current_phase']);
        $this->assertSame(0, $state['initiator_id']);

        foreach (Cashback_Key_Rotation::PHASES as $phase) {
            $this->assertArrayHasKey($phase, $state['progress']);
            $this->assertSame(0, $state['progress'][$phase]['total']);
            $this->assertSame(0, $state['progress'][$phase]['done']);
            $this->assertSame(0, $state['progress'][$phase]['failed']);
        }
    }

    public function test_save_state_round_trips_through_json(): void
    {
        $input = array(
            'state'         => 'migrating',
            'started_at'    => '2026-04-22 10:00:00',
            'initiator_id'  => 42,
            'current_phase' => 'user_profile',
            'progress'      => array(
                'user_profile' => array( 'total' => 100, 'done' => 50, 'failed' => 1 ),
            ),
        );
        Cashback_Key_Rotation::save_state($input);

        $restored = Cashback_Key_Rotation::get_state();
        $this->assertSame('migrating', $restored['state']);
        $this->assertSame('2026-04-22 10:00:00', $restored['started_at']);
        $this->assertSame(42, $restored['initiator_id']);
        $this->assertSame('user_profile', $restored['current_phase']);
        $this->assertSame(100, $restored['progress']['user_profile']['total']);
        $this->assertSame(50,  $restored['progress']['user_profile']['done']);
        $this->assertSame(1,   $restored['progress']['user_profile']['failed']);

        // Другие фазы нормализованы до нулей.
        $this->assertSame(0, $restored['progress']['options_captcha']['total']);
    }

    public function test_invalid_state_name_falls_back_to_idle(): void
    {
        update_option(Cashback_Key_Rotation::STATE_OPTION, json_encode(array( 'state' => 'hacked' )), false);
        $this->assertSame('idle', Cashback_Key_Rotation::get_state()['state']);
    }

    public function test_invalid_phase_in_current_phase_is_nulled(): void
    {
        update_option(
            Cashback_Key_Rotation::STATE_OPTION,
            json_encode(array( 'state' => 'migrating', 'current_phase' => 'nonexistent' )),
            false
        );
        $this->assertNull(Cashback_Key_Rotation::get_state()['current_phase']);
    }

    public function test_state_accepts_array_as_well_as_json(): void
    {
        // get_option иногда возвращает уже распарсенный массив (mu-plugin каст, др.).
        update_option(Cashback_Key_Rotation::STATE_OPTION, array( 'state' => 'staging' ), false);
        $this->assertSame('staging', Cashback_Key_Rotation::get_state()['state']);
    }

    // ================================================================
    // generate_new_key
    // ================================================================

    public function test_generate_new_key_happy_path(): void
    {
        $result = Cashback_Key_Rotation::generate_new_key();

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertArrayHasKey('new_fingerprint', $result);
        $this->assertSame(64, strlen($result['new_fingerprint']));

        // Файл создан.
        $this->assertFileExists(Cashback_Key_Rotation::get_new_key_path());

        // State перешёл в staging.
        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('staging', $state['state']);
        $this->assertNotNull($state['started_at']);

        // Содержимое файла: валидный PHP с define CB_ENCRYPTION_KEY_NEW.
        $contents = file_get_contents(Cashback_Key_Rotation::get_new_key_path());
        $this->assertStringContainsString("define('CB_ENCRYPTION_KEY_NEW'", $contents);
        $this->assertStringContainsString("if (!defined('ABSPATH'))", $contents);
    }

    public function test_generate_new_key_refuses_when_not_idle(): void
    {
        Cashback_Key_Rotation::save_state(array( 'state' => 'migrating' ));

        $result = Cashback_Key_Rotation::generate_new_key();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('migrating', $result['message']);
        $this->assertFileDoesNotExist(Cashback_Key_Rotation::get_new_key_path());
    }

    public function test_generate_new_key_refuses_when_staging_file_exists(): void
    {
        // Создаём «зависший» staging-файл.
        file_put_contents(Cashback_Key_Rotation::get_new_key_path(), '<?php // stale');

        $result = Cashback_Key_Rotation::generate_new_key();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('staging', strtolower($result['message']));
    }

    public function test_generate_new_key_fingerprint_matches_encryption_helper(): void
    {
        // Определена ли у нас NEW-константа на момент теста из bootstrap? Да — defined('CB_ENCRYPTION_KEY_NEW').
        // Но метод generate_new_key() будет пытаться сгенерировать НОВЫЙ ключ и записать его в файл.
        // Сам process-скоуп константу переопределить не сможет (define() молча откажется).
        // Проверяем: generate ok, но fingerprint остаётся от константы, сгенерированной bootstrap'ом.
        $result = Cashback_Key_Rotation::generate_new_key();

        $this->assertTrue($result['ok']);
        $this->assertSame(
            Cashback_Encryption::get_fingerprint(Cashback_Encryption::KEY_ROLE_NEW),
            $result['new_fingerprint']
        );
    }
}
