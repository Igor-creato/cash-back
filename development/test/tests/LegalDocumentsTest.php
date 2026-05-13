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

    public function test_all_types_contains_canonical_slugs(): void
    {
        $types = Cashback_Legal_Documents::all_types();
        // VULN-04: восьмой тип tech_data добавлен (149-ФЗ ст. 10).
        // Phase 7 (2026-05-09): девятый тип affiliate_program (партнёрская программа).
        $this->assertCount(9, $types);
        $this->assertContains('pd_policy', $types);
        $this->assertContains('pd_consent', $types);
        $this->assertContains('payment_pd', $types);
        $this->assertContains('terms_offer', $types);
        $this->assertContains('marketing', $types);
        $this->assertContains('cookies_policy', $types);
        $this->assertContains('contact_form_pd', $types);
        $this->assertContains('tech_data', $types);
        $this->assertContains('affiliate_program', $types);
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

    /**
     * get_rendered() должен prepend'ить `<h1 class="cashback-legal-document__title">`
     * с заголовком из get_meta()['title'] для каждого из 9 типов. Это нужно
     * чтобы на публичных страницах документ имел название независимо от того,
     * выводит ли тема `the_title()` (некоторые темы прячут page-title в Custom
     * Loop / шаблонах без `wp_head()`-блока).
     */
    public function test_get_rendered_prepends_h1_title_for_each_type(): void
    {
        foreach (Cashback_Legal_Documents::all_types() as $type) {
            $meta     = Cashback_Legal_Documents::get_meta($type);
            $title    = (string) $meta['title'];
            $rendered = Cashback_Legal_Documents::get_rendered($type);

            $this->assertNotSame('', $rendered, "Type {$type}: rendered must not be empty");
            $this->assertStringStartsWith(
                '<h1 class="cashback-legal-document__title">',
                $rendered,
                "Type {$type}: rendered output must start with the document title <h1>"
            );
            $this->assertStringContainsString(
                $title,
                $rendered,
                "Type {$type}: rendered output must contain the title text from get_meta()"
            );
        }
    }

    /**
     * Заголовок должен идти РОВНО ПЕРЕД телом документа (включая обёртку
     * `<div class="cashback-legal-document">`), не дублироваться и не
     * вмешиваться внутрь `<h2>`-разделов тела.
     */
    public function test_get_rendered_title_appears_once_and_before_body(): void
    {
        $rendered = Cashback_Legal_Documents::get_rendered('pd_policy');

        // Только один <h1> — заголовок документа. Внутри тела только <h2>+.
        $this->assertSame(1, substr_count($rendered, '<h1 '), 'Title <h1> must appear exactly once');

        // Заголовок должен идти ДО открытия `<div class="cashback-legal-document"`.
        $title_pos = strpos($rendered, '<h1 class="cashback-legal-document__title">');
        $body_pos  = strpos($rendered, '<div class="cashback-legal-document ');
        $this->assertNotFalse($title_pos);
        $this->assertNotFalse($body_pos);
        $this->assertLessThan($body_pos, $title_pos, 'Title must come before body div');
    }
}
