<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты подшага 3.6: sanity_check_pass перед переходом в state=migrated.
 *
 * Sanity-pass ищет записи с OLD-шифртекстом (TOCTOU race между batch'ем и admin-save)
 * через try_decrypt_with_role('new') и ротирует их через rotate_table_row.
 * Максимум SANITY_MAX_ITERATIONS (3) итераций.
 */
#[Group('key-rotation')]
class KeyRotationSanityPassTest extends TestCase
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
        delete_option(Cashback_Key_Rotation::STATE_OPTION);
    }

    /**
     * Минимальный wpdb-стаб для sanity: поддерживает SELECT pk AS sanity_pk, enc AS sanity_enc
     * с фильтрами по cursor, и одиночный SELECT FOR UPDATE + UPDATE (через update()).
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function make_wpdb( string $table, string $pk, string $enc, array $rows ): object
    {
        return new class($table, $pk, $enc, $rows) {
            public string $prefix  = 'wp_';
            public string $options = 'wp_options';
            public ?string $last_error = null;

            public string $table;
            public string $pk_col;
            public string $enc_col;
            /** @var array<int,array<string,mixed>> */
            public array $rows = array();
            /** @var string[] */
            public array $log = array();

            public function __construct( string $table, string $pk, string $enc, array $rows ) {
                $this->table   = $table;
                $this->pk_col  = $pk;
                $this->enc_col = $enc;
                $this->rows    = array_values($rows);
            }

            public function prepare( string $query, ...$args ): string
            {
                return $query . '||' . implode('||', array_map('strval', $args));
            }

            public function query( string $sql ): int
            {
                $upper = strtoupper(trim(explode('||', $sql)[0] ?? $sql));
                $this->log[] = $upper;
                if (in_array($upper, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true)) {
                    return 1;
                }
                return 0;
            }

            public function get_results( string $query ): array
            {
                // Sanity SELECT pk AS sanity_pk, enc AS sanity_enc FROM ... WHERE ... > %d ORDER BY ... LIMIT %d
                if (!str_contains($query, 'AS sanity_pk')) {
                    return array();
                }
                $parts  = explode('||', $query);
                $cursor = (int) ( $parts[2] ?? 0 );
                $limit  = (int) ( $parts[ count($parts) - 1 ] ?? 100 );

                $out = array();
                foreach ($this->rows as $row) {
                    $pk_val = (int) ( $row[ $this->pk_col ] ?? 0 );
                    $enc    = (string) ( $row[ $this->enc_col ] ?? '' );
                    if ($pk_val <= $cursor || $enc === '') {
                        continue;
                    }
                    $out[] = (object) array(
                        'sanity_pk'  => $pk_val,
                        'sanity_enc' => $enc,
                    );
                }
                usort($out, static fn( $a, $b ): int => $a->sanity_pk <=> $b->sanity_pk);
                return array_slice($out, 0, $limit);
            }

            public function get_var( string $query ): ?string
            {
                // rotate_table_row выполняет SELECT enc FROM ... WHERE pk = %d FOR UPDATE
                if (!str_contains($query, 'FOR UPDATE')) {
                    return null;
                }
                $parts  = explode('||', $query);
                $pk_val = (int) ( $parts[2] ?? 0 );
                foreach ($this->rows as $row) {
                    if ((int) ( $row[ $this->pk_col ] ?? 0 ) === $pk_val) {
                        return (string) ( $row[ $this->enc_col ] ?? '' );
                    }
                }
                return null;
            }

            public function update( string $table, array $data, array $where, array $data_formats = array(), array $where_formats = array() ): int|false
            {
                unset($table, $data_formats, $where_formats);
                foreach ($this->rows as &$row) {
                    $match = true;
                    foreach ($where as $col => $val) {
                        if ((int) ( $row[ $col ] ?? null ) !== (int) $val) {
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

    /**
     * Ставит состояние migrating + sanity_active=true с указанной текущей фазой sanity.
     */
    private function set_sanity_state( string $sanity_phase = 'affiliate_networks' ): void
    {
        Cashback_Key_Rotation::save_state(array(
            'state'                => 'migrating',
            'sanity_active'        => true,
            'sanity_iteration'     => 1,
            'sanity_current_phase' => $sanity_phase,
            'sanity_cursor'        => 0,
        ));
    }

    // ================================================================
    // Guards
    // ================================================================

    public function test_sanity_silent_exit_when_not_active(): void
    {
        Cashback_Key_Rotation::save_state(array( 'state' => 'migrating', 'sanity_active' => false ));

        Cashback_Key_Rotation::run_sanity_batch();

        $this->assertFalse($GLOBALS['_cb_test_as_scheduled']);
        $this->assertFalse(Cashback_Key_Rotation::get_state()['sanity_active']);
    }

    public function test_sanity_self_heals_invalid_current_phase(): void
    {
        Cashback_Key_Rotation::save_state(array(
            'state'                => 'migrating',
            'sanity_active'        => true,
            'sanity_iteration'     => 1,
            'sanity_current_phase' => null, // невалидная
        ));

        Cashback_Key_Rotation::run_sanity_batch();

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame(Cashback_Key_Rotation::SANITY_PHASES[0], $state['sanity_current_phase']);
        $this->assertIsArray($GLOBALS['_cb_test_as_scheduled']);
    }

    // ================================================================
    // Happy path: одна фаза, все записи уже NEW → ничего не ротируем
    // ================================================================

    public function test_sanity_skips_rows_already_new(): void
    {
        $rows = array(
            array( 'id' => 1, 'api_credentials' => Cashback_Encryption::encrypt_with_role('cred1', Cashback_Encryption::KEY_ROLE_NEW) ),
            array( 'id' => 2, 'api_credentials' => Cashback_Encryption::encrypt_with_role('cred2', Cashback_Encryption::KEY_ROLE_NEW) ),
        );
        $wpdb = $this->make_wpdb('wp_cashback_affiliate_networks', 'id', 'api_credentials', $rows);
        $GLOBALS['wpdb'] = $wpdb;

        $this->set_sanity_state('affiliate_networks');

        Cashback_Key_Rotation::run_sanity_batch();

        // Записи не ротированы (UPDATE не вызывался). Проверим, что в логе НЕТ COMMIT от rotate_table_row
        // (SELECT FOR UPDATE + UPDATE + COMMIT не срабатывали).
        $this->assertNotContains('START TRANSACTION', $wpdb->log, 'rotate_table_row не должен вызываться для NEW-записей');

        // Фаза affiliate_networks закончена → advance на social_tokens.
        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame(0, $state['sanity_iteration_reencrypted']);
        $this->assertSame('social_tokens', $state['sanity_current_phase']);
    }

    // ================================================================
    // Sanity detects OLD rows and re-rotates them
    // ================================================================

    public function test_sanity_reencrypts_rows_still_encrypted_old(): void
    {
        // "OLD" эмулируем с помощью KEY_ROLE_PRIMARY — decrypt NEW-ролью вернёт null,
        // поскольку constants different.
        $old_ciphertext = Cashback_Encryption::encrypt_with_role('secret-x', Cashback_Encryption::KEY_ROLE_PRIMARY);
        // Sanity должен обнаружить: try_decrypt_with_role('new') → null (PRIMARY≠NEW),
        // rotate_table_row перешифрует на write-key (NEW во время sanity).

        $rows = array(
            array( 'id' => 10, 'api_credentials' => $old_ciphertext ),
        );
        $wpdb = $this->make_wpdb('wp_cashback_affiliate_networks', 'id', 'api_credentials', $rows);
        $GLOBALS['wpdb'] = $wpdb;

        $this->set_sanity_state('affiliate_networks');

        Cashback_Key_Rotation::run_sanity_batch();

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame(1, $state['sanity_iteration_reencrypted']);
        // Запись перезашифрована — теперь расшифровывается NEW.
        $new_value = (string) $wpdb->rows[0]['api_credentials'];
        $this->assertNotSame($old_ciphertext, $new_value);
        $this->assertSame('secret-x', Cashback_Encryption::try_decrypt_with_role($new_value, Cashback_Encryption::KEY_ROLE_NEW));
    }

    // ================================================================
    // Finalization: all phases done → state=migrated
    // ================================================================

    public function test_sanity_finalizes_to_migrated_on_clean_pass(): void
    {
        // Пустые таблицы → нечего ротировать.
        $GLOBALS['wpdb'] = $this->make_wpdb('wp_cashback_affiliate_networks', 'id', 'api_credentials', array());

        // Ставим последнюю sanity-фазу.
        $last_sanity_phase = Cashback_Key_Rotation::SANITY_PHASES[ count(Cashback_Key_Rotation::SANITY_PHASES) - 1 ];
        Cashback_Key_Rotation::save_state(array(
            'state'                        => 'migrating',
            'sanity_active'                => true,
            'sanity_iteration'             => 1,
            'sanity_current_phase'         => $last_sanity_phase,
            'sanity_cursor'                => 0,
            'sanity_iteration_reencrypted' => 0,
        ));

        Cashback_Key_Rotation::run_sanity_batch();

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('migrated', $state['state'], 'Чистый проход → state=migrated');
        $this->assertFalse($state['sanity_active']);
        $this->assertSame(0, $state['sanity_unresolved']);
    }

    public function test_sanity_reruns_iteration_when_reencrypted_and_under_max(): void
    {
        // Проходим последнюю sanity-фазу с reencrypted > 0 на итерации 1 → должна пойти итерация 2.
        $GLOBALS['wpdb'] = $this->make_wpdb('wp_cashback_user_profile', 'user_id', 'encrypted_details', array());

        $last_sanity_phase = Cashback_Key_Rotation::SANITY_PHASES[ count(Cashback_Key_Rotation::SANITY_PHASES) - 1 ];
        Cashback_Key_Rotation::save_state(array(
            'state'                        => 'migrating',
            'sanity_active'                => true,
            'sanity_iteration'             => 1,
            'sanity_current_phase'         => $last_sanity_phase,
            'sanity_cursor'                => 0,
            'sanity_iteration_reencrypted' => 5, // эмулируем: 5 re-encrypted в этой итерации
        ));

        Cashback_Key_Rotation::run_sanity_batch();

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('migrating', $state['state']);
        $this->assertTrue($state['sanity_active']);
        $this->assertSame(2, $state['sanity_iteration']);
        $this->assertSame(Cashback_Key_Rotation::SANITY_PHASES[0], $state['sanity_current_phase']);
        $this->assertSame(0, $state['sanity_iteration_reencrypted']); // сброшено для новой итерации
        $this->assertIsArray($GLOBALS['_cb_test_as_scheduled']);
        $this->assertSame(Cashback_Key_Rotation::AS_HOOK_SANITY, $GLOBALS['_cb_test_as_scheduled']['hook']);
    }

    public function test_sanity_unresolved_after_max_iterations(): void
    {
        // Последняя итерация (3) всё ещё имеет reencrypted > 0 → unresolved, state=migrated с warning.
        $GLOBALS['wpdb'] = $this->make_wpdb('wp_cashback_user_profile', 'user_id', 'encrypted_details', array());

        $last_sanity_phase = Cashback_Key_Rotation::SANITY_PHASES[ count(Cashback_Key_Rotation::SANITY_PHASES) - 1 ];
        Cashback_Key_Rotation::save_state(array(
            'state'                        => 'migrating',
            'sanity_active'                => true,
            'sanity_iteration'             => Cashback_Key_Rotation::SANITY_MAX_ITERATIONS,
            'sanity_current_phase'         => $last_sanity_phase,
            'sanity_cursor'                => 0,
            'sanity_iteration_reencrypted' => 7,
        ));

        Cashback_Key_Rotation::run_sanity_batch();

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('migrated', $state['state']);
        $this->assertFalse($state['sanity_active']);
        $this->assertSame(7, $state['sanity_unresolved']);
    }

    // ================================================================
    // Phase advancement
    // ================================================================

    public function test_sanity_advances_to_next_phase_when_current_drained(): void
    {
        $GLOBALS['wpdb'] = $this->make_wpdb('wp_cashback_social_tokens', 'id', 'refresh_token_encrypted', array());

        $this->set_sanity_state('affiliate_networks');
        Cashback_Key_Rotation::run_sanity_batch();

        $state = Cashback_Key_Rotation::get_state();
        $this->assertSame('social_tokens', $state['sanity_current_phase']);
        $this->assertSame(0, $state['sanity_cursor']);
        $this->assertIsArray($GLOBALS['_cb_test_as_scheduled']);
    }
}
