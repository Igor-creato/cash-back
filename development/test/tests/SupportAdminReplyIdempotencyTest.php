<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit-тесты для safety-net helper'ов Cashback_Support_Admin (Группа 6 ADR, шаг 3d).
 *
 * После наложения UNIQUE(request_id) на cashback_support_messages handle_admin_reply
 * получает schema-level защиту от дубля сообщения даже при истечении transient'а.
 * Helper'ы различают этот случай и находят существующее message_id для replay.
 */
#[Group('dedup')]
#[Group('support-admin-idempotency')]
final class SupportAdminReplyIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // admin-support.php создаёт инстанс на top-level; ставим минимальный $wpdb-stub до require_once.
        if (!class_exists('Cashback_Support_Admin')) {
            global $wpdb;
            if (!isset($wpdb) || !is_object($wpdb)) {
                $wpdb = new Support_Admin_Bootstrap_Wpdb_Stub();
            }
            require_once dirname(__DIR__, 3) . '/support/admin-support.php';
        }
    }

    // ────────────────────────────────────────────────────────────
    // classify_support_message_insert_error()
    // ────────────────────────────────────────────────────────────

    public function test_duplicate_uk_request_id_classified(): void
    {
        $err = "Duplicate entry 'a1b2c3d4-e5f6-7a8b-9c0d-e1f2a3b4c5d6' for key 'uk_request_id'";

        $this->assertSame(
            'duplicate_request_id',
            Cashback_Support_Admin::classify_support_message_insert_error($err)
        );
    }

    public function test_duplicate_other_unique_key_classified_as_other(): void
    {
        $err = "Duplicate entry '42-99' for key 'idx_ticket_id'";

        $this->assertSame('other', Cashback_Support_Admin::classify_support_message_insert_error($err));
    }

    public function test_generic_error_classified_as_other(): void
    {
        $err = 'Incorrect integer value: NULL for column ticket_id at row 1';

        $this->assertSame('other', Cashback_Support_Admin::classify_support_message_insert_error($err));
    }

    public function test_empty_string_classified_as_other(): void
    {
        $this->assertSame('other', Cashback_Support_Admin::classify_support_message_insert_error(''));
    }

    // ────────────────────────────────────────────────────────────
    // find_existing_message_by_request_id()
    // ────────────────────────────────────────────────────────────

    public function test_find_existing_returns_message_id_when_found(): void
    {
        $wpdb = new Support_Admin_Lookup_Wpdb_Stub();
        $wpdb->existing['a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5'] = 4242;

        $result = Cashback_Support_Admin::find_existing_message_by_request_id(
            $wpdb,
            'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5'
        );

        $this->assertSame(4242, $result);
    }

    public function test_find_existing_returns_null_when_not_found(): void
    {
        $wpdb = new Support_Admin_Lookup_Wpdb_Stub();

        $result = Cashback_Support_Admin::find_existing_message_by_request_id(
            $wpdb,
            'a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5'
        );

        $this->assertNull($result);
    }
}

// ────────────────────────────────────────────────────────────
// Минимальный $wpdb stub для top-level `new Cashback_Support_Admin()`.
// ────────────────────────────────────────────────────────────

final class Support_Admin_Bootstrap_Wpdb_Stub
{
    public string $prefix = 'wp_';
}

// ────────────────────────────────────────────────────────────
// Lookup stub для find_existing_message_by_request_id().
// ────────────────────────────────────────────────────────────

final class Support_Admin_Lookup_Wpdb_Stub
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var array<string, int> request_id → message_id */
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
        if (preg_match("/WHERE\\s+request_id\\s*=\\s*'([^']+)'/i", $sql, $m)) {
            return $this->existing[$m[1]] ?? null;
        }
        return null;
    }
}
