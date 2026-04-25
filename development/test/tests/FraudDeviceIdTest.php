<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once dirname(__DIR__, 3) . '/antifraud/class-fraud-device-id.php';

/**
 * In-memory wpdb stub. Поддерживает минимально необходимый набор методов для
 * Cashback_Fraud_Device_Id: prepare/insert/update/query (TX no-op)/get_var/get_results.
 */
final class FraudDeviceIdWpdbStub
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public int $insert_id = 0;
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    private int $next_id = 1;

    public function get_charset_collate(): string
    {
        return '';
    }

    public function prepare(string $query, mixed ...$args): string
    {
        // Простая интерполяция: %i / %s / %d / %f. Возвращаем строку — нам важна её передача
        // в query/get_var, а реальную БД мы не дёргаем.
        $i = 0;
        return preg_replace_callback('/%[isdf]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            return is_string($v) ? "'" . str_replace("'", "\\'", $v) . "'" : (string) $v;
        }, $query) ?? $query;
    }

    public function query(string $sql)
    {
        if (preg_match('/^\s*(START TRANSACTION|COMMIT|ROLLBACK)/i', $sql)) {
            return true;
        }
        if (preg_match('/^\s*DELETE FROM\s+\'?wp_cashback_fraud_device_ids\'?\s+WHERE last_seen\s*<\s*DATE_SUB\(UTC_TIMESTAMP\(\),\s*INTERVAL\s+(\d+)\s+DAY\)/i', $sql, $m)) {
            $cutoff = time() - ((int) $m[1]) * 86400;
            $deleted = 0;
            foreach ($this->rows as $id => $row) {
                if (strtotime((string) $row['last_seen']) < $cutoff) {
                    unset($this->rows[$id]);
                    $deleted++;
                }
            }
            return $deleted;
        }
        return 0;
    }

    public function get_var(string $sql)
    {
        // Поддерживаем только SELECT id ... LIMIT 1 FOR UPDATE из record()
        // и COUNT(DISTINCT user_id) из count_users_per_device.
        if (preg_match("/SELECT id FROM .* WHERE device_id = '([^']+)'.*ip_address = '([^']+)'/s", $sql, $m)) {
            $device_id = $m[1];
            $ip = $m[2];
            preg_match('/visitor_id = \'([^\']*)\'/', $sql, $vm);
            $visitor = $vm[1] ?? '';

            foreach ($this->rows as $id => $row) {
                if ($row['device_id'] !== $device_id || $row['ip_address'] !== $ip) continue;
                $row_visitor = $row['visitor_id'] ?? '';
                if (($row_visitor ?? '') !== $visitor && !($row_visitor === null && $visitor === '')) continue;
                if (strtotime((string) $row['last_seen']) >= time() - 86400) {
                    return (string) $id;
                }
            }
            return null;
        }

        if (preg_match("/SELECT COUNT\\(DISTINCT user_id\\).*device_id = '([^']+)'.*INTERVAL (\\d+) DAY/s", $sql, $m)) {
            $device_id = $m[1];
            $days = (int) $m[2];
            $cutoff = time() - $days * 86400;
            $set = [];
            foreach ($this->rows as $row) {
                if ($row['device_id'] === $device_id && $row['user_id'] !== null && strtotime((string) $row['last_seen']) >= $cutoff) {
                    $set[$row['user_id']] = true;
                }
            }
            return (string) count($set);
        }
        return null;
    }

    public function get_results(string $sql, $output = ARRAY_A)
    {
        if (preg_match("/SELECT ip_address AS ip.*device_id = '([^']+)'.*INTERVAL (\\d+) HOUR/s", $sql, $m)) {
            $device_id = $m[1];
            $hours = (int) $m[2];
            $cutoff = time() - $hours * 3600;
            $by_ip = [];
            foreach ($this->rows as $row) {
                if ($row['device_id'] !== $device_id) continue;
                if (strtotime((string) $row['last_seen']) < $cutoff) continue;
                $ip = $row['ip_address'];
                if (!isset($by_ip[$ip]) || strtotime((string) $by_ip[$ip]['last_seen']) < strtotime((string) $row['last_seen'])) {
                    $by_ip[$ip] = [
                        'ip' => $ip,
                        'asn' => $row['asn'],
                        'last_seen' => $row['last_seen'],
                    ];
                }
            }
            return array_values($by_ip);
        }
        if (preg_match("/SELECT \\* FROM .* WHERE device_id = '([^']+)'/", $sql, $m)) {
            $out = [];
            foreach ($this->rows as $row) {
                if ($row['device_id'] === $m[1]) $out[] = $row;
            }
            return $out;
        }
        if (preg_match("/SELECT \\* FROM .* WHERE user_id = (\\d+).*LIMIT (\\d+)/s", $sql, $m)) {
            $uid = (int) $m[1];
            $limit = (int) $m[2];
            $out = [];
            foreach ($this->rows as $row) {
                if ((int) ($row['user_id'] ?? 0) === $uid) $out[] = $row;
            }
            usort($out, fn($a, $b) => strcmp((string) $b['last_seen'], (string) $a['last_seen']));
            return array_slice($out, 0, $limit);
        }
        return [];
    }

    public function insert(string $table, array $data, $format = null): int|false
    {
        $id = $this->next_id++;
        $data['id'] = $id;
        $this->rows[$id] = $data;
        $this->insert_id = $id;
        $this->last_error = '';
        return 1;
    }

    public function update(string $table, array $data, array $where, $format = null, $where_format = null): int|false
    {
        $id = (int) ($where['id'] ?? 0);
        if (!isset($this->rows[$id])) return 0;
        $this->rows[$id] = array_merge($this->rows[$id], $data);
        $this->last_error = '';
        return 1;
    }
}

#[Group('fraud')]
class FraudDeviceIdTest extends TestCase
{
    private FraudDeviceIdWpdbStub $wpdb;

    protected function setUp(): void
    {
        global $wpdb;
        $this->wpdb = new FraudDeviceIdWpdbStub();
        $wpdb = $this->wpdb;
    }

    // ================================================================
    // validate_uuid_v4
    // ================================================================

    public static function uuid_v4_provider(): array
    {
        return [
            'valid lowercase'         => ['8d4a8e92-4c2b-4f7a-9b3e-1234567890ab', true],
            'valid uppercase'         => ['8D4A8E92-4C2B-4F7A-9B3E-1234567890AB', true],
            'valid mixed case'        => ['8d4a8e92-4C2B-4f7a-9B3e-1234567890Ab', true],
            'wrong version (v1)'      => ['8d4a8e92-4c2b-1f7a-9b3e-1234567890ab', false],
            'wrong variant'           => ['8d4a8e92-4c2b-4f7a-7b3e-1234567890ab', false],
            'too short'               => ['8d4a8e92-4c2b-4f7a-9b3e-1234567890a', false],
            'no dashes'               => ['8d4a8e924c2b4f7a9b3e1234567890ab', false],
            'empty'                   => ['', false],
            'plain text'              => ['not-a-uuid-at-all', false],
            'extra junk at end'       => ['8d4a8e92-4c2b-4f7a-9b3e-1234567890abXX', false],
        ];
    }

    #[DataProvider('uuid_v4_provider')]
    public function test_validate_uuid_v4(string $uuid, bool $expected): void
    {
        $this->assertSame($expected, Cashback_Fraud_Device_Id::validate_uuid_v4($uuid));
    }

    // ================================================================
    // record() — happy path + UPSERT + graceful без IP intelligence
    // ================================================================

    public function test_record_inserts_new_device(): void
    {
        $id = Cashback_Fraud_Device_Id::record(
            [
                'device_id' => '8d4a8e92-4c2b-4f7a-9b3e-1234567890ab',
                'visitor_id' => 'visitorABC123',
                'fingerprint_hash' => str_repeat('a', 64),
                'components_hash' => str_repeat('b', 64),
                'confidence' => 0.85,
            ],
            42,
            '203.0.113.10',
            'Mozilla/5.0'
        );

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        $this->assertCount(1, $this->wpdb->rows);
        $row = array_values($this->wpdb->rows)[0];
        $this->assertSame('8d4a8e92-4c2b-4f7a-9b3e-1234567890ab', $row['device_id']);
        $this->assertSame('visitorABC123', $row['visitor_id']);
        $this->assertSame(42, $row['user_id']);
        $this->assertSame(0.85, $row['confidence_score']);
        // Без класса Cashback_Fraud_Ip_Intelligence — asn/connection_type должны быть null
        $this->assertNull($row['asn']);
        $this->assertNull($row['connection_type']);
    }

    public function test_record_rejects_invalid_uuid(): void
    {
        $result = Cashback_Fraud_Device_Id::record(
            ['device_id' => 'not-a-uuid'],
            1,
            '203.0.113.10',
            'UA'
        );
        $this->assertFalse($result);
        $this->assertCount(0, $this->wpdb->rows);
    }

    public function test_record_upserts_same_device_visitor_ip(): void
    {
        $payload = [
            'device_id' => '8d4a8e92-4c2b-4f7a-9b3e-1234567890ab',
            'visitor_id' => 'visitorABC123',
        ];

        $id1 = Cashback_Fraud_Device_Id::record($payload, 42, '203.0.113.10', 'UA');
        $id2 = Cashback_Fraud_Device_Id::record($payload, 42, '203.0.113.10', 'UA');

        $this->assertSame($id1, $id2, 'Повторный визит должен обновлять существующую запись');
        $this->assertCount(1, $this->wpdb->rows);
    }

    public function test_record_creates_separate_row_for_different_ip(): void
    {
        $payload = ['device_id' => '8d4a8e92-4c2b-4f7a-9b3e-1234567890ab'];

        Cashback_Fraud_Device_Id::record($payload, 42, '203.0.113.10', 'UA');
        Cashback_Fraud_Device_Id::record($payload, 42, '198.51.100.5', 'UA');

        $this->assertCount(2, $this->wpdb->rows);
    }

    public function test_record_rejects_when_only_invalid_data(): void
    {
        $result = Cashback_Fraud_Device_Id::record(
            ['device_id' => ''],
            null,
            '203.0.113.10',
            'UA'
        );
        $this->assertFalse($result);
    }

    // ================================================================
    // count_users_per_device
    // ================================================================

    public function test_count_users_per_device_counts_distinct_users(): void
    {
        $device = '8d4a8e92-4c2b-4f7a-9b3e-1234567890ab';

        Cashback_Fraud_Device_Id::record(['device_id' => $device], 100, '203.0.113.10', 'UA');
        Cashback_Fraud_Device_Id::record(['device_id' => $device], 200, '203.0.113.11', 'UA');
        Cashback_Fraud_Device_Id::record(['device_id' => $device], 100, '203.0.113.12', 'UA'); // дубль user_id

        $this->assertSame(2, Cashback_Fraud_Device_Id::count_users_per_device($device, 30));
    }

    public function test_count_users_per_device_returns_zero_for_invalid_uuid(): void
    {
        $this->assertSame(0, Cashback_Fraud_Device_Id::count_users_per_device('garbage', 30));
    }

    // ================================================================
    // count_distinct_ips_per_device
    // ================================================================

    public function test_count_distinct_ips_per_device(): void
    {
        $device = '8d4a8e92-4c2b-4f7a-9b3e-1234567890ab';

        for ($i = 1; $i <= 6; $i++) {
            Cashback_Fraud_Device_Id::record(
                ['device_id' => $device],
                42,
                '203.0.113.' . $i,
                'UA'
            );
        }

        $ips = Cashback_Fraud_Device_Id::count_distinct_ips_per_device($device, 24);
        $this->assertCount(6, $ips);
        $this->assertArrayHasKey('ip', $ips[0]);
        $this->assertArrayHasKey('asn', $ips[0]);
        $this->assertArrayHasKey('last_seen', $ips[0]);
    }

    // ================================================================
    // purge_old
    // ================================================================

    public function test_purge_old_deletes_outdated_rows(): void
    {
        Cashback_Fraud_Device_Id::record(
            ['device_id' => '8d4a8e92-4c2b-4f7a-9b3e-1234567890ab'],
            42,
            '203.0.113.10',
            'UA'
        );

        // Подменяем last_seen на старое значение, имитируя устаревшую запись
        foreach ($this->wpdb->rows as $id => $row) {
            $this->wpdb->rows[$id]['last_seen'] = date('Y-m-d H:i:s', time() - 200 * 86400);
        }

        $deleted = Cashback_Fraud_Device_Id::purge_old(180);
        $this->assertSame(1, $deleted);
        $this->assertCount(0, $this->wpdb->rows);
    }

    public function test_purge_old_keeps_recent_rows(): void
    {
        Cashback_Fraud_Device_Id::record(
            ['device_id' => '8d4a8e92-4c2b-4f7a-9b3e-1234567890ab'],
            42,
            '203.0.113.10',
            'UA'
        );

        $deleted = Cashback_Fraud_Device_Id::purge_old(180);
        $this->assertSame(0, $deleted);
        $this->assertCount(1, $this->wpdb->rows);
    }
}
