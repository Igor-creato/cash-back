<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты подшага 3.5b: table-updater'ы (affiliate_networks/social_tokens/
 * social_pending/payout_requests/user_profile) через generic
 * rotate_table_enc_column().
 */
#[Group('key-rotation')]
class KeyRotationTableUpdaterTest extends TestCase
{
    protected function setUp(): void
    {
        delete_option(Cashback_Key_Rotation::STATE_OPTION);
        $GLOBALS['_cb_test_filters'] = array();
        unset($GLOBALS['wpdb']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $GLOBALS['_cb_test_filters'] = array();
    }

    /**
     * In-memory wpdb-стаб для произвольной таблицы с PK + encrypted column.
     *
     * Поддерживает:
     *  - prepare(): наивная сериализация query + args через '|'
     *  - query(): транзакции no-op
     *  - get_col(): SELECT <pk> FROM ... WHERE <enc> IS NOT NULL AND <pk> > X ORDER BY <pk> LIMIT N
     *  - get_var(): SELECT <enc> FROM ... WHERE <pk> = X FOR UPDATE
     *  - update(): обновляет ячейку по pk
     */
    private function make_wpdb( string $table, string $pk_col, string $enc_col, array $rows ): object
    {
        return new class($table, $pk_col, $enc_col, $rows) {
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

            public function get_col( string $query ): array
            {
                // Парсим cursor (первый %d аргумент) и limit (последний аргумент).
                $parts = explode('||', $query);
                // parts[0]=sql, parts[1]=table, parts[2]=cursor, ..., parts[-1]=limit
                $cursor = (int) ( $parts[2] ?? 0 );
                $limit  = (int) ( $parts[ count($parts) - 1 ] ?? 100 );

                $out = array();
                foreach ($this->rows as $row) {
                    $pk_val = (int) ( $row[ $this->pk_col ] ?? 0 );
                    $enc    = (string) ( $row[ $this->enc_col ] ?? '' );
                    if ($pk_val <= $cursor || $enc === '') {
                        continue;
                    }
                    // Учёт extra_where для social_pending (consumed_at IS NULL AND expires_at >= X).
                    if (str_contains($query, 'consumed_at IS NULL AND expires_at >=')) {
                        if (!empty($row['consumed_at'])) {
                            continue;
                        }
                        $expires = (string) ( $row['expires_at'] ?? '9999-12-31 00:00:00' );
                        $now_arg = (string) ( $parts[3] ?? '' );
                        if ($expires < $now_arg) {
                            continue;
                        }
                    }
                    $out[] = $pk_val;
                }
                sort($out);
                return array_slice($out, 0, $limit);
            }

            public function get_var( string $query ): ?string
            {
                // SELECT <enc> FROM %i WHERE <pk> = %d FOR UPDATE
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

    // ================================================================
    // user_profile
    // ================================================================

    public function test_user_profile_rotates_batch_and_returns_cursor(): void
    {
        $rows = array(
            array( 'user_id' => 10, 'encrypted_details' => Cashback_Encryption::encrypt(wp_json_encode(array( 'account' => 'A' ))) ),
            array( 'user_id' => 11, 'encrypted_details' => Cashback_Encryption::encrypt(wp_json_encode(array( 'account' => 'B' ))) ),
            array( 'user_id' => 12, 'encrypted_details' => '' ), // пропускается
        );
        $wpdb = $this->make_wpdb('wp_cashback_user_profile', 'user_id', 'encrypted_details', $rows);
        $GLOBALS['wpdb'] = $wpdb;

        $result = Cashback_Key_Rotation::run_phase_batch('user_profile', 0, 100);

        $this->assertSame(2, $result['processed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(11, $result['next_cursor']);
        $this->assertFalse($result['has_more']);

        // Значения изменены (новые IV/tag), но расшифровываются в то же содержимое.
        $this->assertSame('A', json_decode(Cashback_Encryption::decrypt((string) $wpdb->rows[0]['encrypted_details']), true)['account']);
        $this->assertSame('B', json_decode(Cashback_Encryption::decrypt((string) $wpdb->rows[1]['encrypted_details']), true)['account']);
    }

    public function test_user_profile_has_more_when_batch_size_reached(): void
    {
        $rows = array();
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = array(
                'user_id'           => $i,
                'encrypted_details' => Cashback_Encryption::encrypt('user' . $i),
            );
        }
        $GLOBALS['wpdb'] = $this->make_wpdb('wp_cashback_user_profile', 'user_id', 'encrypted_details', $rows);

        // batch=2 → 3 батча (2+2+1)
        $r1 = Cashback_Key_Rotation::run_phase_batch('user_profile', 0, 2);
        $this->assertSame(2, $r1['processed']);
        $this->assertTrue($r1['has_more']);
        $this->assertSame(2, $r1['next_cursor']);

        $r2 = Cashback_Key_Rotation::run_phase_batch('user_profile', 2, 2);
        $this->assertSame(2, $r2['processed']);
        $this->assertTrue($r2['has_more']);
        $this->assertSame(4, $r2['next_cursor']);

        $r3 = Cashback_Key_Rotation::run_phase_batch('user_profile', 4, 2);
        $this->assertSame(1, $r3['processed']);
        $this->assertFalse($r3['has_more']);
        $this->assertSame(5, $r3['next_cursor']);
    }

    public function test_user_profile_counts_failed_on_corrupt_ciphertext(): void
    {
        // Битый ciphertext — rotate_value() бросит.
        $rows = array(
            array( 'user_id' => 1, 'encrypted_details' => 'v2:CORRUPTED_BASE64_DATA' ),
        );
        $GLOBALS['wpdb'] = $this->make_wpdb('wp_cashback_user_profile', 'user_id', 'encrypted_details', $rows);

        $result = Cashback_Key_Rotation::run_phase_batch('user_profile', 0, 100);

        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertFalse($result['has_more']);
    }

    // ================================================================
    // affiliate_networks
    // ================================================================

    public function test_affiliate_networks_rotates_api_credentials(): void
    {
        $rows = array(
            array( 'id' => 1, 'api_credentials' => Cashback_Encryption::encrypt(wp_json_encode(array( 'client_id' => 'x' ))) ),
            array( 'id' => 2, 'api_credentials' => Cashback_Encryption::encrypt(wp_json_encode(array( 'client_id' => 'y' ))) ),
        );
        $wpdb = $this->make_wpdb('wp_cashback_affiliate_networks', 'id', 'api_credentials', $rows);
        $GLOBALS['wpdb'] = $wpdb;

        $result = Cashback_Key_Rotation::run_phase_batch('affiliate_networks', 0, 100);

        $this->assertSame(2, $result['processed']);
        $this->assertFalse($result['has_more']);
        $this->assertSame('x', json_decode(Cashback_Encryption::decrypt((string) $wpdb->rows[0]['api_credentials']), true)['client_id']);
    }

    // ================================================================
    // social_tokens
    // ================================================================

    public function test_social_tokens_rotates_refresh_tokens(): void
    {
        $rows = array(
            array( 'id' => 5, 'refresh_token_encrypted' => Cashback_Encryption::encrypt('refresh-1') ),
        );
        $wpdb = $this->make_wpdb('wp_cashback_social_tokens', 'id', 'refresh_token_encrypted', $rows);
        $GLOBALS['wpdb'] = $wpdb;

        $result = Cashback_Key_Rotation::run_phase_batch('social_tokens', 0, 100);

        $this->assertSame(1, $result['processed']);
        $this->assertSame('refresh-1', Cashback_Encryption::decrypt((string) $wpdb->rows[0]['refresh_token_encrypted']));
    }

    // ================================================================
    // social_pending: extra_where filter (consumed_at IS NULL, expires_at >= now)
    // ================================================================

    public function test_social_pending_skips_consumed_and_expired(): void
    {
        $future = gmdate('Y-m-d H:i:s', time() + 3600);
        $past   = gmdate('Y-m-d H:i:s', time() - 3600);

        $rows = array(
            array( 'id' => 1, 'payload_json' => Cashback_Encryption::encrypt('live-payload'), 'consumed_at' => null, 'expires_at' => $future ),
            array( 'id' => 2, 'payload_json' => Cashback_Encryption::encrypt('consumed'),     'consumed_at' => '2026-01-01 00:00:00', 'expires_at' => $future ),
            array( 'id' => 3, 'payload_json' => Cashback_Encryption::encrypt('expired'),      'consumed_at' => null, 'expires_at' => $past ),
        );
        $wpdb = $this->make_wpdb('wp_cashback_social_pending', 'id', 'payload_json', $rows);
        $GLOBALS['wpdb'] = $wpdb;

        $result = Cashback_Key_Rotation::run_phase_batch('social_pending', 0, 100);

        // Только id=1 (активная запись) обработана; consumed/expired пропущены.
        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('live-payload', Cashback_Encryption::decrypt((string) $wpdb->rows[0]['payload_json']));
        // Остальные не тронуты.
        $this->assertSame('consumed', Cashback_Encryption::decrypt((string) $wpdb->rows[1]['payload_json']));
    }

    // ================================================================
    // payout_requests
    // ================================================================

    public function test_payout_requests_rotates_encrypted_details(): void
    {
        $rows = array(
            array( 'id' => 100, 'encrypted_details' => Cashback_Encryption::encrypt(wp_json_encode(array( 'account' => 'X' ))) ),
        );
        $wpdb = $this->make_wpdb('wp_cashback_payout_requests', 'id', 'encrypted_details', $rows);
        $GLOBALS['wpdb'] = $wpdb;

        $result = Cashback_Key_Rotation::run_phase_batch('payout_requests', 0, 100);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(100, $result['next_cursor']);
    }

    // ================================================================
    // Empty table / no wpdb
    // ================================================================

    public function test_table_updater_noop_when_no_wpdb(): void
    {
        unset($GLOBALS['wpdb']);
        $result = Cashback_Key_Rotation::run_phase_batch('user_profile', 0, 100);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['failed']);
    }

    public function test_table_updater_noop_on_empty_table(): void
    {
        $GLOBALS['wpdb'] = $this->make_wpdb('wp_cashback_user_profile', 'user_id', 'encrypted_details', array());
        $result = Cashback_Key_Rotation::run_phase_batch('user_profile', 0, 100);
        $this->assertSame(0, $result['processed']);
        $this->assertFalse($result['has_more']);
    }
}
