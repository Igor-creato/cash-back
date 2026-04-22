<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты подшага 3.3: count_*, start_migration(), handle_start (через start_migration).
 *
 * DB-backed count_* (payout_requests, user_profile и т.д.) покрываются через
 * wpdb-стаб, который возвращает заранее заданный результат на COUNT(*)-запросы.
 */
#[Group('key-rotation')]
class KeyRotationStartMigrationTest extends TestCase
{
    /** @var string */
    private string $tmp_dir = '';

    protected function setUp(): void
    {
        $this->tmp_dir = sys_get_temp_dir() . '/cb-kr-start-' . uniqid('', true);
        mkdir($this->tmp_dir, 0700, true);

        $primary_path = $this->tmp_dir . '/.cashback-encryption-key.php';
        $new_path     = $this->tmp_dir . '/.cashback-encryption-key.new.php';
        $prev_path    = $this->tmp_dir . '/.cashback-encryption-key.previous.php';

        add_filter('cashback_key_rotation_primary_key_path',  function () use ($primary_path) { return $primary_path; });
        add_filter('cashback_key_rotation_new_key_path',      function () use ($new_path) { return $new_path; });
        add_filter('cashback_key_rotation_previous_key_path', function () use ($prev_path) { return $prev_path; });

        // Сбрасываем state и options между тестами.
        delete_option(Cashback_Key_Rotation::STATE_OPTION);
        delete_option('cashback_captcha_server_key');
        delete_option('cashback_social_provider_yandex');
        delete_option('cashback_social_provider_vkid');
        $GLOBALS['_cb_test_as_scheduled'] = false;
        unset($GLOBALS['wpdb']);
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
        delete_option('cashback_captcha_server_key');
        delete_option('cashback_social_provider_yandex');
        delete_option('cashback_social_provider_vkid');
        unset($GLOBALS['wpdb']);
    }

    // ================================================================
    // count_options_captcha
    // ================================================================

    public function test_count_options_captcha_zero_when_missing(): void
    {
        $counts = Cashback_Key_Rotation::count_all_phases();
        $this->assertSame(0, $counts['options_captcha']);
    }

    public function test_count_options_captcha_zero_when_plaintext(): void
    {
        // Значение сохранено как plaintext (до миграции options_encrypted_v1).
        update_option('cashback_captcha_server_key', 'plain-key-value', false);
        $counts = Cashback_Key_Rotation::count_all_phases();
        $this->assertSame(0, $counts['options_captcha']);
    }

    public function test_count_options_captcha_one_when_ciphertext(): void
    {
        $ciphertext = Cashback_Encryption::encrypt_if_needed('secret-captcha-key');
        update_option('cashback_captcha_server_key', $ciphertext, false);
        $counts = Cashback_Key_Rotation::count_all_phases();
        $this->assertSame(1, $counts['options_captcha']);
    }

    // ================================================================
    // count_options_social
    // ================================================================

    public function test_count_options_social_zero_when_no_providers(): void
    {
        $counts = Cashback_Key_Rotation::count_all_phases();
        $this->assertSame(0, $counts['options_social']);
    }

    public function test_count_options_social_counts_only_providers_with_encrypted_secret(): void
    {
        // Yandex: client_secret_encrypted есть и имеет v2-префикс.
        update_option('cashback_social_provider_yandex', array(
            'client_id'                => 'yid',
            'client_secret_encrypted'  => Cashback_Encryption::encrypt('yandex-secret'),
        ), false);
        // VKID: client_secret_encrypted пустой — не считается.
        update_option('cashback_social_provider_vkid', array(
            'client_id'                => 'vkid',
            'client_secret_encrypted'  => '',
        ), false);

        $counts = Cashback_Key_Rotation::count_all_phases();
        $this->assertSame(1, $counts['options_social']);
    }

    public function test_count_options_social_counts_both_providers(): void
    {
        update_option('cashback_social_provider_yandex', array(
            'client_secret_encrypted' => Cashback_Encryption::encrypt('s1'),
        ), false);
        update_option('cashback_social_provider_vkid', array(
            'client_secret_encrypted' => Cashback_Encryption::encrypt('s2'),
        ), false);

        $counts = Cashback_Key_Rotation::count_all_phases();
        $this->assertSame(2, $counts['options_social']);
    }

    // ================================================================
    // count_* с wpdb-стабом (db-backed фазы)
    // ================================================================

    public function test_db_backed_counts_use_wpdb(): void
    {
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $options = 'wp_options';
            /** @var array<string,int> */
            public array $counts = array(
                'wp_cashback_affiliate_networks' => 3,
                'wp_cashback_social_tokens'      => 5,
                'wp_cashback_social_pending'     => 2,
                'wp_cashback_payout_requests'    => 7,
                'wp_cashback_user_profile'       => 11,
                'wp_options'                     => 4, // EPN refresh tokens
            );

            public function prepare(string $query, ...$args): string
            {
                // Stub: подставляем аргументы вручную для match по таблице.
                // Первый аргумент — имя таблицы (для %i).
                return $query . '|' . implode('|', array_map('strval', $args));
            }

            public function get_var(string $query): int
            {
                foreach ($this->counts as $table => $count) {
                    if (str_contains($query, '|' . $table . '|') || str_ends_with($query, '|' . $table)) {
                        return $count;
                    }
                }
                return 0;
            }
        };

        $counts = Cashback_Key_Rotation::count_all_phases();

        $this->assertSame(3,  $counts['affiliate_networks']);
        $this->assertSame(5,  $counts['social_tokens']);
        $this->assertSame(2,  $counts['social_pending']);
        $this->assertSame(7,  $counts['payout_requests']);
        $this->assertSame(11, $counts['user_profile']);
        $this->assertSame(4,  $counts['options_epn']);
    }

    // ================================================================
    // start_migration: guards
    // ================================================================

    public function test_start_migration_refuses_when_not_staging(): void
    {
        Cashback_Key_Rotation::save_state(array( 'state' => 'idle' ));

        $result = Cashback_Key_Rotation::start_migration(Cashback_Key_Rotation::CONFIRMATION_START);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('idle', $result['message']);
        $this->assertSame('idle', Cashback_Key_Rotation::get_state()['state']);
        $this->assertFalse($GLOBALS['_cb_test_as_scheduled']);
    }

    public function test_start_migration_refuses_on_wrong_confirmation(): void
    {
        Cashback_Key_Rotation::save_state(array( 'state' => 'staging' ));

        $result = Cashback_Key_Rotation::start_migration('NOPE');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('подтверждение', $result['message']);
        $this->assertSame('staging', Cashback_Key_Rotation::get_state()['state']);
        $this->assertFalse($GLOBALS['_cb_test_as_scheduled']);
    }

    public function test_start_migration_rejects_lowercase_confirmation(): void
    {
        // hash_equals регистрозависим — защищаем от «case-insensitive UX», которая
        // ослабила бы guard.
        Cashback_Key_Rotation::save_state(array( 'state' => 'staging' ));

        $result = Cashback_Key_Rotation::start_migration(strtolower(Cashback_Key_Rotation::CONFIRMATION_START));

        $this->assertFalse($result['ok']);
        $this->assertSame('staging', Cashback_Key_Rotation::get_state()['state']);
    }

    // ================================================================
    // start_migration: happy path
    // ================================================================

    public function test_start_migration_transitions_state_and_enqueues_batch(): void
    {
        Cashback_Key_Rotation::save_state(array(
            'state'        => 'staging',
            'started_at'   => '2026-04-22 09:00:00',
            'initiator_id' => 7,
        ));

        // CB_ENCRYPTION_KEY_NEW определена в bootstrap → guard is_key_role_configured проходит.
        $result = Cashback_Key_Rotation::start_migration(Cashback_Key_Rotation::CONFIRMATION_START);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertArrayHasKey('totals', $result);
        foreach (Cashback_Key_Rotation::PHASES as $phase) {
            $this->assertArrayHasKey($phase, $result['totals']);
            $this->assertIsInt($result['totals'][ $phase ]);
        }

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('migrating', $state['state']);
        $this->assertSame(Cashback_Key_Rotation::PHASES[0], $state['current_phase']);
        // started_at сохраняется из staging (не перезаписывается).
        $this->assertSame('2026-04-22 09:00:00', $state['started_at']);

        // Первый batch запланирован.
        $this->assertIsArray($GLOBALS['_cb_test_as_scheduled']);
        $this->assertSame(Cashback_Key_Rotation::AS_HOOK_MIGRATE, $GLOBALS['_cb_test_as_scheduled']['hook']);
        $this->assertSame(Cashback_Key_Rotation::AS_GROUP,        $GLOBALS['_cb_test_as_scheduled']['group']);
    }

    public function test_start_migration_preserves_totals_in_progress(): void
    {
        Cashback_Key_Rotation::save_state(array( 'state' => 'staging' ));
        $ciphertext = Cashback_Encryption::encrypt_if_needed('captcha-key');
        update_option('cashback_captcha_server_key', $ciphertext, false);

        $result = Cashback_Key_Rotation::start_migration(Cashback_Key_Rotation::CONFIRMATION_START);
        $this->assertTrue($result['ok']);

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame(1, $state['progress']['options_captcha']['total']);
        $this->assertSame(0, $state['progress']['options_captcha']['done']);
        $this->assertSame(0, $state['progress']['options_captcha']['failed']);
    }
}
