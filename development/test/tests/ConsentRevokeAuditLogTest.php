<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты симметрии audit_log: запись `consent_granted` уже пишется в record_consent
 * (строки 88–109), а `consent_revoked` должна писаться в withdraw_consent.
 *
 * 152-ФЗ ст. 9 evidence-chain: revoke — юр.факт, должен быть в audit-trail
 * (закрытие compliance-gap, найденного при ревью UX-cleanup 1.4.0).
 */
#[Group('legal')]
#[Group('consent-revoke-audit')]
final class ConsentRevokeAuditLogTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/legal/class-cashback-legal-db.php';
        require_once $plugin_root . '/legal/class-cashback-legal-documents.php';
        require_once $plugin_root . '/legal/class-cashback-legal-operator.php';
        require_once $plugin_root . '/legal/class-cashback-legal-consent-manager.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']             = array();
        $GLOBALS['_cb_test_transients']          = array();
        $GLOBALS['_cb_test_legal_inserted_rows'] = array();

        $GLOBALS['wpdb'] = new class {
            public string $prefix     = 'wp_';
            public string $last_error = '';
            public int $insert_id     = 0;
            private int $next_id      = 200;

            public function suppress_errors( bool $suppress = true ) {
                return false;
            }

            public function insert( string $table, array $data, $format = null ) {
                $GLOBALS['_cb_test_legal_inserted_rows'][] = array_merge(
                    array( '_table' => $table ),
                    $data
                );
                $this->insert_id = $this->next_id++;
                return 1;
            }
        };
    }

    private function audit_rows(): array
    {
        return array_values(array_filter(
            $GLOBALS['_cb_test_legal_inserted_rows'],
            static fn(array $row): bool => isset($row['action']) && !isset($row['consent_type'])
        ));
    }

    public function test_withdraw_consent_writes_audit_log_consent_revoked(): void
    {
        $rid = Cashback_Legal_Consent_Manager::generate_request_id();
        $result = Cashback_Legal_Consent_Manager::withdraw_consent(
            42,
            Cashback_Legal_Documents::TYPE_MARKETING,
            'my_account',
            $rid
        );

        $this->assertNotFalse($result, 'withdraw_consent должен вернуть id строки журнала');

        $audit = $this->audit_rows();
        $this->assertCount(1, $audit, 'withdraw_consent должен писать ровно одну строку в audit_log');

        $entry = $audit[0];
        $this->assertSame('consent_revoked', $entry['action']);
        $this->assertSame(42, (int) $entry['actor_id']);
        $this->assertSame('consent_log', $entry['entity_type']);
        $this->assertNotNull($entry['details']);
        $details = json_decode((string) $entry['details'], true);
        $this->assertSame(Cashback_Legal_Documents::TYPE_MARKETING, $details['consent_type']);
        $this->assertSame('my_account', $details['source']);
        $this->assertArrayHasKey('request_id', $details);
    }

    public function test_withdraw_consent_does_not_write_audit_when_invalid_type(): void
    {
        $rid = Cashback_Legal_Consent_Manager::generate_request_id();
        $result = Cashback_Legal_Consent_Manager::withdraw_consent(
            42,
            'arbitrary_type',
            'my_account',
            $rid
        );

        $this->assertFalse($result);
        $this->assertCount(0, $this->audit_rows(), 'invalid type → ни consent_log, ни audit_log');
    }

    public function test_withdraw_consent_audit_includes_request_id(): void
    {
        $rid    = str_repeat('b', 32);
        $result = Cashback_Legal_Consent_Manager::withdraw_consent(
            55,
            Cashback_Legal_Documents::TYPE_TECH_DATA,
            'my_account',
            $rid
        );
        $this->assertNotFalse($result);

        $audit = $this->audit_rows();
        $this->assertCount(1, $audit);
        $details = json_decode((string) $audit[0]['details'], true);
        $this->assertSame($rid, $details['request_id']);
    }
}
