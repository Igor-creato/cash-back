<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты реестра юр. документов (Phase 1 системы согласий по 152-ФЗ/38-ФЗ/161-ФЗ).
 *
 * Покрывает:
 *   - all_types / consent_types — стабильность списка
 *   - get_meta — обязательные поля для каждого типа
 *   - seed_versions — идемпотентно, не перезаписывает существующие
 *   - get_active_version / set_active_version — round-trip через wp_options
 *   - bump_major — 1.0.0 → 2.0.0 → 3.0.0
 */
#[Group('legal')]
#[Group('legal-documents')]
final class LegalDocumentsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/legal/class-cashback-legal-documents.php';
        require_once $plugin_root . '/legal/class-cashback-legal-operator.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options'] = array();
    }

    public function test_all_types_contains_seven_canonical_slugs(): void
    {
        $types = Cashback_Legal_Documents::all_types();
        $this->assertCount(7, $types);
        $this->assertContains('pd_policy', $types);
        $this->assertContains('pd_consent', $types);
        $this->assertContains('payment_pd', $types);
        $this->assertContains('terms_offer', $types);
        $this->assertContains('marketing', $types);
        $this->assertContains('cookies_policy', $types);
        $this->assertContains('contact_form_pd', $types);
    }

    public function test_consent_types_excludes_pd_policy(): void
    {
        // pd_policy — публичный документ, без чекбокса
        $consent_types = Cashback_Legal_Documents::consent_types();
        $this->assertNotContains('pd_policy', $consent_types);
        $this->assertContains('pd_consent', $consent_types);
    }

    public function test_get_meta_returns_required_fields_for_each_type(): void
    {
        foreach (Cashback_Legal_Documents::all_types() as $type) {
            $meta = Cashback_Legal_Documents::get_meta($type);
            $this->assertNotEmpty($meta, "Meta пустая для типа {$type}");
            $this->assertArrayHasKey('slug', $meta);
            $this->assertArrayHasKey('title', $meta);
            $this->assertArrayHasKey('template_path', $meta);
            $this->assertArrayHasKey('is_consent', $meta);
            $this->assertArrayHasKey('is_required', $meta);
        }
    }

    public function test_pd_consent_is_required(): void
    {
        $meta = Cashback_Legal_Documents::get_meta('pd_consent');
        $this->assertTrue($meta['is_required']);
        $this->assertTrue($meta['is_consent']);
    }

    public function test_marketing_is_consent_but_not_required(): void
    {
        // 38-ФЗ: согласие на рекламу обязательно отдельным чекбоксом, но
        // его отказ не блокирует регистрацию.
        $meta = Cashback_Legal_Documents::get_meta('marketing');
        $this->assertTrue($meta['is_consent']);
        $this->assertFalse($meta['is_required']);
    }

    public function test_get_active_version_returns_default_when_unset(): void
    {
        $version = Cashback_Legal_Documents::get_active_version('pd_consent');
        $this->assertSame('1.0.0', $version);
    }

    public function test_set_active_version_round_trip(): void
    {
        Cashback_Legal_Documents::set_active_version('terms_offer', '2.5.0');
        $this->assertSame('2.5.0', Cashback_Legal_Documents::get_active_version('terms_offer'));
    }

    public function test_set_active_version_rejects_unknown_type(): void
    {
        Cashback_Legal_Documents::set_active_version('unknown_type', '99.0.0');
        // Опция не сохраняется — get_active_version вернёт default для known
        // типов, а unknown_type вообще не запишется.
        $stored = $GLOBALS['_cb_test_options'][ Cashback_Legal_Documents::VERSIONS_OPTION ] ?? array();
        $this->assertArrayNotHasKey('unknown_type', is_array($stored) ? $stored : array());
    }

    public function test_seed_versions_initializes_all_types(): void
    {
        Cashback_Legal_Documents::seed_versions();
        $stored = $GLOBALS['_cb_test_options'][ Cashback_Legal_Documents::VERSIONS_OPTION ] ?? array();
        $this->assertIsArray($stored);
        foreach (Cashback_Legal_Documents::all_types() as $type) {
            $this->assertSame('1.0.0', $stored[ $type ] ?? '');
        }
    }

    public function test_seed_versions_does_not_overwrite_existing(): void
    {
        // Юзер вручную выставил 3.0.0 для pd_consent — seed не должен затирать.
        Cashback_Legal_Documents::set_active_version('pd_consent', '3.0.0');
        Cashback_Legal_Documents::seed_versions();
        $this->assertSame('3.0.0', Cashback_Legal_Documents::get_active_version('pd_consent'));
        // Остальные типы получили дефолт.
        $this->assertSame('1.0.0', Cashback_Legal_Documents::get_active_version('terms_offer'));
    }

    public function test_bump_major_increments_first_segment(): void
    {
        Cashback_Legal_Documents::set_active_version('pd_consent', '1.0.0');
        $new = Cashback_Legal_Documents::bump_major('pd_consent');
        $this->assertSame('2.0.0', $new);
        $this->assertSame('2.0.0', Cashback_Legal_Documents::get_active_version('pd_consent'));

        $new = Cashback_Legal_Documents::bump_major('pd_consent');
        $this->assertSame('3.0.0', $new);
    }

    public function test_bump_major_resets_minor_and_patch(): void
    {
        // Если кто-то поставил 1.5.7 — bump major должен вернуть 2.0.0,
        // не 2.5.7. Это семантика "обнулённой ветки".
        Cashback_Legal_Documents::set_active_version('terms_offer', '1.5.7');
        $new = Cashback_Legal_Documents::bump_major('terms_offer');
        $this->assertSame('2.0.0', $new);
    }
}
