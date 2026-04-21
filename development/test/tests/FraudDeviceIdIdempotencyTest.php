<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit-тесты для safety-net helper'ов Cashback_Fraud_Device_Id (Группа 6 ADR, шаг 3c).
 *
 * После наложения UNIQUE(user_id, session_date, device_id) fallback INSERT
 * в record() может получить Duplicate entry при cross-process race.
 * Helper'ы распознают этот случай и находят существующую запись для merge UPDATE.
 *
 * Покрывает только точку изменения. Полная orchestration record() (IP intelligence,
 * SELECT FOR UPDATE, UPDATE/INSERT транзакция) — из зоны integration-тестов.
 */
#[Group('dedup')]
#[Group('fraud-device-idempotency')]
final class FraudDeviceIdIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Cashback_Fraud_Device_Id')) {
            require_once dirname(__DIR__, 3) . '/antifraud/class-fraud-device-id.php';
        }
    }

    // ────────────────────────────────────────────────────────────
    // classify_fraud_device_insert_error()
    // ────────────────────────────────────────────────────────────

    public function test_duplicate_uk_user_session_device_classified(): void
    {
        $err = "Duplicate entry '42-2026-04-21-abc123' for key 'uk_user_session_device'";

        $this->assertSame(
            'duplicate_user_session',
            Cashback_Fraud_Device_Id::classify_fraud_device_insert_error($err)
        );
    }

    public function test_duplicate_other_unique_key_classified_as_other(): void
    {
        $err = "Duplicate entry 'X' for key 'idx_device'";

        $this->assertSame('other', Cashback_Fraud_Device_Id::classify_fraud_device_insert_error($err));
    }

    public function test_generic_error_classified_as_other(): void
    {
        $err = 'Incorrect integer value: NULL for column user_id at row 1';

        $this->assertSame('other', Cashback_Fraud_Device_Id::classify_fraud_device_insert_error($err));
    }

    public function test_empty_string_classified_as_other(): void
    {
        $this->assertSame('other', Cashback_Fraud_Device_Id::classify_fraud_device_insert_error(''));
    }

    // ────────────────────────────────────────────────────────────
    // find_existing_by_user_session_device()
    // ────────────────────────────────────────────────────────────

    public function test_find_existing_returns_id_when_found(): void
    {
        $wpdb = new Fraud_Device_Idempotency_Wpdb_Stub();
        $wpdb->existing[42]['2026-04-21']['a1b2c3d4-5e6f-7890-abcd-ef1234567890'] = 555;

        $result = Cashback_Fraud_Device_Id::find_existing_by_user_session_device(
            $wpdb,
            42,
            'a1b2c3d4-5e6f-7890-abcd-ef1234567890',
            '2026-04-21 10:00:00'
        );

        $this->assertSame(555, $result);
    }

    public function test_find_existing_returns_null_when_not_found(): void
    {
        $wpdb = new Fraud_Device_Idempotency_Wpdb_Stub();

        $result = Cashback_Fraud_Device_Id::find_existing_by_user_session_device(
            $wpdb,
            42,
            'a1b2c3d4-5e6f-7890-abcd-ef1234567890',
            '2026-04-21 10:00:00'
        );

        $this->assertNull($result);
    }
}

// ────────────────────────────────────────────────────────────
// In-memory $wpdb stub
// ────────────────────────────────────────────────────────────

final class Fraud_Device_Idempotency_Wpdb_Stub
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var array<int, array<string, array<string, int>>> user_id → date → device_id → row_id */
    public array $existing = array();

    public function prepare(string $query, mixed ...$args): string
    {
        $i = 0;
        return (string) preg_replace_callback('/%[isdf]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            return is_string($v) ? "'" . str_replace("'", "\\'", $v) . "'" : (string) $v;
        }, $query);
    }

    public function get_var(string $sql): string|int|null
    {
        // lookup: WHERE user_id = N AND session_date = DATE('YYYY-MM-DD HH:MM:SS') AND device_id = 'UUID'
        if (preg_match(
            "/WHERE\\s+user_id\\s*=\\s*(\\d+)\\s+AND\\s+session_date\\s*=\\s*DATE\\('(\\d{4}-\\d{2}-\\d{2})[^']*'\\)\\s+AND\\s+device_id\\s*=\\s*'([^']+)'/i",
            $sql,
            $m
        )) {
            $user_id   = (int) $m[1];
            $date      = $m[2];
            $device_id = $m[3];
            return $this->existing[$user_id][$date][$device_id] ?? null;
        }
        return null;
    }
}
