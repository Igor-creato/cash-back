<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit-тесты для helper'ов Cashback_Claims_Manager, используемых в create()
 * для schema-level idempotency (Группа 6 ADR, шаг 3a).
 *
 * Покрывает:
 *   - classify_insert_error(): распознаёт какой именно UNIQUE получил Duplicate entry.
 *   - lookup_existing_claim_by_idempotency(): SELECT existing claim_id по (user_id, key).
 *
 * Полная orchestration create() (eligibility/antifraud/scoring/log_event/hooks)
 * тестируется в integration-layer отдельно; здесь — только точка изменения.
 */
#[Group('dedup')]
#[Group('claims-idempotency')]
final class ClaimsCreateIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Cashback_Claims_Manager')) {
            require_once dirname(__DIR__, 3) . '/claims/class-claims-manager.php';
        }
    }

    // ────────────────────────────────────────────────────────────
    // classify_insert_error()
    // ────────────────────────────────────────────────────────────

    public function test_duplicate_click_user_classified(): void
    {
        $err = "Duplicate entry 'abc123-42' for key 'uk_click_user'";

        $this->assertSame('duplicate_click', Cashback_Claims_Manager::classify_insert_error($err));
    }

    public function test_duplicate_user_idempotency_classified(): void
    {
        $err = "Duplicate entry '42-a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5' for key 'uk_user_idempotency'";

        $this->assertSame('duplicate_idempotency', Cashback_Claims_Manager::classify_insert_error($err));
    }

    public function test_duplicate_merchant_order_classified(): void
    {
        $err = "Duplicate entry '100-ORDER-A' for key 'uk_merchant_order'";

        $this->assertSame('duplicate_order', Cashback_Claims_Manager::classify_insert_error($err));
    }

    public function test_generic_error_classified_as_other(): void
    {
        $err = 'Incorrect integer value: NULL for column user_id at row 1';

        $this->assertSame('other', Cashback_Claims_Manager::classify_insert_error($err));
    }

    public function test_empty_string_classified_as_other(): void
    {
        $this->assertSame('other', Cashback_Claims_Manager::classify_insert_error(''));
    }

    // ────────────────────────────────────────────────────────────
    // lookup_existing_claim_by_idempotency()
    // ────────────────────────────────────────────────────────────

    public function test_lookup_returns_claim_id_when_found(): void
    {
        $wpdb = new Claims_Idempotency_Wpdb_Stub();
        $wpdb->existing[42]['a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5'] = 777;

        $result = Cashback_Claims_Manager::lookup_existing_claim_by_idempotency(
            $wpdb,
            42,
            'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5'
        );

        $this->assertSame(777, $result);
    }

    public function test_lookup_returns_null_when_not_found(): void
    {
        $wpdb = new Claims_Idempotency_Wpdb_Stub();

        $result = Cashback_Claims_Manager::lookup_existing_claim_by_idempotency(
            $wpdb,
            42,
            'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5'
        );

        $this->assertNull($result);
    }
}

// ────────────────────────────────────────────────────────────
// In-memory $wpdb stub: lookup by (user_id, idempotency_key).
// ────────────────────────────────────────────────────────────

final class Claims_Idempotency_Wpdb_Stub
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var array<int, array<string, int>> user_id → key → claim_id */
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
        if (preg_match("/WHERE\\s+user_id\\s*=\\s*(\\d+)\\s+AND\\s+idempotency_key\\s*=\\s*'([^']+)'/i", $sql, $m)) {
            $user_id = (int) $m[1];
            $key     = $m[2];
            return $this->existing[$user_id][$key] ?? null;
        }
        return null;
    }
}
