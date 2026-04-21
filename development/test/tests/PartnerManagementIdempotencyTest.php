<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit-тесты для classify_partner_insert_error() (Группа 6 ADR, шаг 3b).
 *
 * После удаления check-then-insert по slug handle_add_partner полагается
 * на UNIQUE(slug) и распознаёт Duplicate entry из $wpdb->last_error.
 */
#[Group('dedup')]
#[Group('partner-idempotency')]
final class PartnerManagementIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // partner-management.php создаёт global instance на top-level (line 954);
        // constructor читает $wpdb->prefix — ставим минимальный stub до require_once.
        if (!class_exists('Cashback_Partner_Management_Admin')) {
            global $wpdb;
            if (!isset($wpdb) || !is_object($wpdb)) {
                $wpdb = new Partner_Management_Bootstrap_Wpdb_Stub();
            }
            require_once dirname(__DIR__, 3) . '/partner/partner-management.php';
        }
    }

    public function test_duplicate_uniq_slug_classified(): void
    {
        $err = "Duplicate entry 'admitad' for key 'uniq_slug'";

        $this->assertSame(
            'duplicate_slug',
            Cashback_Partner_Management_Admin::classify_partner_insert_error($err)
        );
    }

    public function test_generic_db_error_classified_as_other(): void
    {
        $err = 'Cannot add or update a child row: a foreign key constraint fails';

        $this->assertSame(
            'other',
            Cashback_Partner_Management_Admin::classify_partner_insert_error($err)
        );
    }

    public function test_empty_string_classified_as_other(): void
    {
        $this->assertSame('other', Cashback_Partner_Management_Admin::classify_partner_insert_error(''));
    }
}

// ────────────────────────────────────────────────────────────
// Минимальный $wpdb stub для top-level `new Cashback_Partner_Management_Admin()`.
// ────────────────────────────────────────────────────────────

final class Partner_Management_Bootstrap_Wpdb_Stub
{
    public string $prefix = 'wp_';
}

