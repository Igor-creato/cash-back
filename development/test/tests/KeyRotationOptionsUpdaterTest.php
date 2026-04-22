<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты подшага 3.5a: per-phase updater'ы для wp_options (captcha/epn/social).
 *
 * Используют in-memory wpdb-стаб с поддержкой START TRANSACTION/COMMIT/ROLLBACK
 * (как no-op) и CRUD по имитированной таблице wp_options.
 */
#[Group('key-rotation')]
class KeyRotationOptionsUpdaterTest extends TestCase
{
    protected function setUp(): void
    {
        delete_option(Cashback_Key_Rotation::STATE_OPTION);
        $GLOBALS['_cb_test_as_scheduled'] = false;
        $GLOBALS['_cb_test_filters']      = array();
        unset($GLOBALS['wpdb']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $GLOBALS['_cb_test_filters']      = array();
        $GLOBALS['_cb_test_as_scheduled'] = false;
    }

    /**
     * Создаёт in-memory wpdb с заполненной wp_options-таблицей.
     *
     * @param array<int,array{option_id:int,option_name:string,option_value:string}> $rows
     */
    private function make_wpdb( array $rows ): object
    {
        return new class($rows) {
            public string $prefix  = 'wp_';
            public string $options = 'wp_options';
            public ?string $last_error = null;

            /** @var array<int,array{option_id:int,option_name:string,option_value:string}> */
            public array $rows = array();

            /** @var string[] */
            public array $log = array();

            public function __construct( array $rows ) {
                $this->rows = array_values($rows);
            }

            public function prepare( string $query, ...$args ): string
            {
                // Простая подстановка по порядку — достаточно для тестов.
                return $query . '|' . implode('|', array_map('strval', $args));
            }

            public function query( string $sql ): int
            {
                $upper = strtoupper(trim(explode('|', $sql)[0] ?? $sql));
                $this->log[] = $upper;
                if (in_array($upper, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true)) {
                    return 1;
                }
                return 0;
            }

            public function get_var( string $query ): ?string
            {
                if (!str_contains($query, 'SELECT option_value FROM')) {
                    return null;
                }
                $parts = explode('|', $query);
                // Аргументы: [0]=sql, [1]=tableName, [2]=optionName.
                $name  = $parts[2] ?? '';
                foreach ($this->rows as $row) {
                    if ($row['option_name'] === $name) {
                        return (string) $row['option_value'];
                    }
                }
                return null;
            }

            public function get_results( string $query ): array
            {
                if (!str_contains($query, 'SELECT option_id, option_name FROM')) {
                    return array();
                }
                // Аргументы: [1]=table, [2]=LIKE pattern, [3]=cursor, [4]=limit.
                $parts  = explode('|', $query);
                $like   = $parts[2] ?? '';
                $cursor = (int) ( $parts[3] ?? 0 );
                $limit  = (int) ( $parts[4] ?? 100 );
                $re     = '/^' . str_replace('%', '.*', preg_quote($like, '/')) . '$/';

                $matches = array();
                foreach ($this->rows as $row) {
                    if ($row['option_id'] <= $cursor) {
                        continue;
                    }
                    if ($row['option_value'] === '') {
                        continue;
                    }
                    if (!preg_match($re, $row['option_name'])) {
                        continue;
                    }
                    $matches[] = (object) array(
                        'option_id'   => $row['option_id'],
                        'option_name' => $row['option_name'],
                    );
                }
                usort($matches, static fn( $a, $b ): int => $a->option_id <=> $b->option_id);
                return array_slice($matches, 0, $limit);
            }

            /**
             * @param array<string,string> $data
             * @param array<string,string> $where
             */
            public function update( string $table, array $data, array $where, array $data_formats = array(), array $where_formats = array() ): int|false
            {
                unset($table, $data_formats, $where_formats);
                foreach ($this->rows as &$row) {
                    $match = true;
                    foreach ($where as $col => $val) {
                        if (( $row[ $col ] ?? null ) !== $val) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        foreach ($data as $col => $val) {
                            $row[ $col ] = $val;
                        }
                        return 1;
                    }
                }
                return 0;
            }
        };
    }

    private function assertTransactionCommitted( object $wpdb ): void
    {
        $this->assertContains('START TRANSACTION', $wpdb->log);
        $this->assertContains('COMMIT', $wpdb->log);
        $this->assertNotContains('ROLLBACK', $wpdb->log);
    }

    // ================================================================
    // options_captcha
    // ================================================================

    public function test_captcha_rotates_existing_ciphertext(): void
    {
        $original  = 'secret-captcha-server-key';
        $prefixed  = Cashback_Encryption::encrypt_if_needed($original); // ENC:v1:v2:...
        $wpdb      = $this->make_wpdb(array(
            array( 'option_id' => 1, 'option_name' => 'cashback_captcha_server_key', 'option_value' => $prefixed ),
        ));
        $GLOBALS['wpdb'] = $wpdb;

        $result = Cashback_Key_Rotation::run_phase_batch('options_captcha', 0, 100);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);
        $this->assertFalse($result['has_more']);
        $this->assertTransactionCommitted($wpdb);

        // Значение в БД изменилось (новый IV/tag), но расшифровывается в тот же plaintext.
        $new_value = $wpdb->rows[0]['option_value'];
        $this->assertNotSame($prefixed, $new_value);
        $this->assertTrue(Cashback_Encryption::is_option_ciphertext($new_value));
        $this->assertSame($original, Cashback_Encryption::decrypt_if_ciphertext($new_value));
    }

    public function test_captcha_noop_when_option_missing(): void
    {
        $GLOBALS['wpdb'] = $this->make_wpdb(array());

        $result = Cashback_Key_Rotation::run_phase_batch('options_captcha', 0, 100);

        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['failed']);
    }

    public function test_captcha_noop_when_plaintext(): void
    {
        $wpdb = $this->make_wpdb(array(
            array( 'option_id' => 1, 'option_name' => 'cashback_captcha_server_key', 'option_value' => 'plain-text' ),
        ));
        $GLOBALS['wpdb'] = $wpdb;

        $result = Cashback_Key_Rotation::run_phase_batch('options_captcha', 0, 100);

        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['failed']);
        // Plaintext не тронут.
        $this->assertSame('plain-text', $wpdb->rows[0]['option_value']);
    }

    // ================================================================
    // options_epn
    // ================================================================

    public function test_epn_rotates_multiple_tokens_and_advances_cursor(): void
    {
        $wpdb = $this->make_wpdb(array(
            array( 'option_id' => 10, 'option_name' => 'cashback_epn_refresh_aaa', 'option_value' => Cashback_Encryption::encrypt_if_needed('tok1') ),
            array( 'option_id' => 11, 'option_name' => 'cashback_epn_refresh_bbb', 'option_value' => Cashback_Encryption::encrypt_if_needed('tok2') ),
            array( 'option_id' => 12, 'option_name' => 'unrelated_option',        'option_value' => 'x' ),
        ));
        $GLOBALS['wpdb'] = $wpdb;

        $result = Cashback_Key_Rotation::run_phase_batch('options_epn', 0, 100);

        $this->assertSame(2, $result['processed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(11, $result['next_cursor']);
        $this->assertFalse($result['has_more']);

        // Оба EPN токена переключились.
        $this->assertSame('tok1', Cashback_Encryption::decrypt_if_ciphertext($wpdb->rows[0]['option_value']));
        $this->assertSame('tok2', Cashback_Encryption::decrypt_if_ciphertext($wpdb->rows[1]['option_value']));
    }

    public function test_epn_has_more_when_batch_size_reached(): void
    {
        $wpdb = $this->make_wpdb(array(
            array( 'option_id' => 1, 'option_name' => 'cashback_epn_refresh_aaa', 'option_value' => Cashback_Encryption::encrypt_if_needed('t1') ),
            array( 'option_id' => 2, 'option_name' => 'cashback_epn_refresh_bbb', 'option_value' => Cashback_Encryption::encrypt_if_needed('t2') ),
            array( 'option_id' => 3, 'option_name' => 'cashback_epn_refresh_ccc', 'option_value' => Cashback_Encryption::encrypt_if_needed('t3') ),
        ));
        $GLOBALS['wpdb'] = $wpdb;

        // batch_size=2 → должен обработать первые две, has_more=true.
        $result = Cashback_Key_Rotation::run_phase_batch('options_epn', 0, 2);

        $this->assertSame(2, $result['processed']);
        $this->assertSame(2, $result['next_cursor']);
        $this->assertTrue($result['has_more']);

        // Добивание следующим батчем с cursor=2.
        $result2 = Cashback_Key_Rotation::run_phase_batch('options_epn', 2, 2);
        $this->assertSame(1, $result2['processed']);
        $this->assertFalse($result2['has_more']);
        $this->assertSame(3, $result2['next_cursor']);
    }

    // ================================================================
    // options_social
    // ================================================================

    public function test_social_rotates_yandex_then_vkid(): void
    {
        $yandex_secret = 'yandex-client-secret';
        $vkid_secret   = 'vkid-client-secret';

        $wpdb = $this->make_wpdb(array(
            array(
                'option_id'    => 100,
                'option_name'  => 'cashback_social_provider_yandex',
                'option_value' => serialize(array(
                    'client_id'               => 'yid',
                    'client_secret_encrypted' => Cashback_Encryption::encrypt($yandex_secret),
                )),
            ),
            array(
                'option_id'    => 101,
                'option_name'  => 'cashback_social_provider_vkid',
                'option_value' => serialize(array(
                    'client_id'               => 'vid',
                    'client_secret_encrypted' => Cashback_Encryption::encrypt($vkid_secret),
                )),
            ),
        ));
        $GLOBALS['wpdb'] = $wpdb;

        // Первый вызов: yandex (cursor=0).
        $r1 = Cashback_Key_Rotation::run_phase_batch('options_social', 0, 100);
        $this->assertSame(1, $r1['processed']);
        $this->assertSame(1, $r1['next_cursor']);
        $this->assertTrue($r1['has_more']);

        // Второй вызов: vkid (cursor=1).
        $r2 = Cashback_Key_Rotation::run_phase_batch('options_social', 1, 100);
        $this->assertSame(1, $r2['processed']);
        $this->assertSame(2, $r2['next_cursor']);
        $this->assertFalse($r2['has_more']);

        // Оба секрета расшифровываются в оригинал.
        $yandex_after = unserialize($wpdb->rows[0]['option_value']);
        $vkid_after   = unserialize($wpdb->rows[1]['option_value']);
        $this->assertSame($yandex_secret, Cashback_Encryption::decrypt((string) $yandex_after['client_secret_encrypted']));
        $this->assertSame($vkid_secret,   Cashback_Encryption::decrypt((string) $vkid_after['client_secret_encrypted']));
    }

    public function test_social_noop_when_option_missing(): void
    {
        $wpdb = $this->make_wpdb(array());
        $GLOBALS['wpdb'] = $wpdb;

        $result = Cashback_Key_Rotation::run_phase_batch('options_social', 0, 100);

        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['failed']);
    }

    public function test_social_noop_when_secret_empty(): void
    {
        $wpdb = $this->make_wpdb(array(
            array(
                'option_id'    => 100,
                'option_name'  => 'cashback_social_provider_yandex',
                'option_value' => serialize(array( 'client_id' => 'x', 'client_secret_encrypted' => '' )),
            ),
        ));
        $GLOBALS['wpdb'] = $wpdb;

        $result = Cashback_Key_Rotation::run_phase_batch('options_social', 0, 100);

        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['failed']);
    }

    // ================================================================
    // rotate_option_ciphertext (Cashback_Encryption helper)
    // ================================================================

    public function test_rotate_option_ciphertext_round_trip(): void
    {
        $plaintext = 'sensitive-key-42';
        $prefixed  = Cashback_Encryption::encrypt_if_needed($plaintext);
        $rotated   = Cashback_Encryption::rotate_option_ciphertext($prefixed);

        // Отличается от исходного (новый IV/tag).
        $this->assertNotSame($prefixed, $rotated);
        // По-прежнему является wp_option-ciphertext'ом.
        $this->assertTrue(Cashback_Encryption::is_option_ciphertext($rotated));
        // И расшифровывается в тот же plaintext.
        $this->assertSame($plaintext, Cashback_Encryption::decrypt_if_ciphertext($rotated));
    }

    public function test_rotate_option_ciphertext_passthrough_plaintext(): void
    {
        // Не-ciphertext значение не трогается.
        $this->assertSame('plain', Cashback_Encryption::rotate_option_ciphertext('plain'));
        $this->assertSame('',      Cashback_Encryption::rotate_option_ciphertext(''));
    }
}
