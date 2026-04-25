<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Legal_Consent_Manager — нормализация request_id, валидация
 * типов, version-compare логика needs_reconsent. Поведение, не зависящее от
 * wpdb (record/has с полным БД-флоу — отдельный integration-тест в Phase 1.9
 * через FakeWpdb / для Phase 5 в admin-журнале).
 */
#[Group('legal')]
#[Group('legal-consent-manager')]
final class LegalConsentManagerTest extends TestCase
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
        $GLOBALS['_cb_test_options']    = array();
        $GLOBALS['_cb_test_transients'] = array();
    }

    // ────────────────────────────────────────────────────────────
    // normalize_request_id
    // ────────────────────────────────────────────────────────────

    public function test_normalize_accepts_uuid_with_dashes(): void
    {
        $normalized = Cashback_Legal_Consent_Manager::normalize_request_id(
            'A1B2C3D4-E5F6-4A7B-8C9D-E0F1A2B3C4D5'
        );
        $this->assertSame('a1b2c3d4e5f64a7b8c9de0f1a2b3c4d5', $normalized);
    }

    public function test_normalize_accepts_hex_without_dashes(): void
    {
        $raw        = '018f1e2a3b4c7d8e9fa0b1c2d3e4f506';
        $normalized = Cashback_Legal_Consent_Manager::normalize_request_id($raw);
        $this->assertSame($raw, $normalized);
    }

    public function test_normalize_rejects_short_input(): void
    {
        $this->assertSame('', Cashback_Legal_Consent_Manager::normalize_request_id('short'));
    }

    public function test_normalize_rejects_non_hex(): void
    {
        $this->assertSame(
            '',
            Cashback_Legal_Consent_Manager::normalize_request_id('ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ')
        );
    }

    public function test_normalize_lowercases(): void
    {
        $this->assertSame(
            'abcdef0123456789abcdef0123456789',
            Cashback_Legal_Consent_Manager::normalize_request_id('ABCDEF0123456789ABCDEF0123456789')
        );
    }

    // ────────────────────────────────────────────────────────────
    // is_valid_consent_type
    // ────────────────────────────────────────────────────────────

    public function test_is_valid_consent_type_for_known_types(): void
    {
        foreach (Cashback_Legal_Documents::all_types() as $type) {
            $this->assertTrue(
                Cashback_Legal_Consent_Manager::is_valid_consent_type($type),
                "Type {$type} должен быть valid"
            );
        }
    }

    public function test_is_valid_consent_type_rejects_unknown(): void
    {
        $this->assertFalse(Cashback_Legal_Consent_Manager::is_valid_consent_type('arbitrary'));
        $this->assertFalse(Cashback_Legal_Consent_Manager::is_valid_consent_type(''));
        $this->assertFalse(
            Cashback_Legal_Consent_Manager::is_valid_consent_type('pd_consent; DROP TABLE')
        );
    }

    // ────────────────────────────────────────────────────────────
    // generate_request_id
    // ────────────────────────────────────────────────────────────

    public function test_generate_request_id_produces_normalizable_value(): void
    {
        $generated = Cashback_Legal_Consent_Manager::generate_request_id();
        $normalized = Cashback_Legal_Consent_Manager::normalize_request_id($generated);
        $this->assertNotSame('', $normalized);
        $this->assertGreaterThanOrEqual(32, strlen($normalized));
    }

    public function test_generate_request_id_returns_unique_values(): void
    {
        $ids = array();
        for ($i = 0; $i < 50; $i++) {
            $ids[] = Cashback_Legal_Consent_Manager::generate_request_id();
        }
        $this->assertCount(50, array_unique($ids));
    }

    // ────────────────────────────────────────────────────────────
    // record_consent / has_active_consent — guards
    // ────────────────────────────────────────────────────────────

    public function test_record_consent_returns_false_for_invalid_type(): void
    {
        $result = Cashback_Legal_Consent_Manager::record_consent(
            42,
            'arbitrary_type',
            'registration',
            Cashback_Legal_Consent_Manager::generate_request_id()
        );
        $this->assertFalse($result);
    }

    public function test_record_consent_returns_false_for_invalid_request_id(): void
    {
        $result = Cashback_Legal_Consent_Manager::record_consent(
            42,
            'pd_consent',
            'registration',
            'invalid'
        );
        $this->assertFalse($result);
    }

    public function test_has_active_consent_false_for_zero_user(): void
    {
        $this->assertFalse(Cashback_Legal_Consent_Manager::has_active_consent(0, 'pd_consent'));
    }

    public function test_has_active_consent_false_for_invalid_type(): void
    {
        $this->assertFalse(Cashback_Legal_Consent_Manager::has_active_consent(42, 'arbitrary'));
    }

    public function test_get_pending_reconsent_types_empty_for_zero_user(): void
    {
        $this->assertSame(array(), Cashback_Legal_Consent_Manager::get_pending_reconsent_types(0));
    }

    public function test_clear_reconsent_cache_handles_zero_user(): void
    {
        // Не должен бросать — просто noop.
        Cashback_Legal_Consent_Manager::clear_reconsent_cache(0);
        $this->assertTrue(true);
    }
}
