<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Legal_Operator (Phase 1).
 *
 * Покрывает:
 *   - get_all / set_all — round-trip
 *   - is_configured — true только когда заполнены required_fields
 *   - render_placeholders — подстановка {{operator_*}} и оставление пустых полей
 *     как видимых маркеров
 *   - get_missing_required_fields — диагностика для admin-warning
 */
#[Group('legal')]
#[Group('legal-operator')]
final class LegalOperatorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/legal/class-cashback-legal-operator.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options'] = array();
    }

    public function test_is_configured_false_when_empty(): void
    {
        $this->assertFalse(Cashback_Legal_Operator::is_configured());
    }

    public function test_is_configured_true_when_required_set(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'ООО «Кэшбэк-Тест»',
            'org_form'      => 'ЮЛ',
            'ogrn'          => '1234567890123',
            'inn'           => '1234567890',
            'legal_address' => '123456, Москва, ул. Тестовая, д. 1',
            'contact_email' => 'support@example.com',
        ));
        $this->assertTrue(Cashback_Legal_Operator::is_configured());
    }

    public function test_is_configured_false_when_one_required_missing(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'ООО «Кэшбэк-Тест»',
            'org_form'      => 'ЮЛ',
            'ogrn'          => '1234567890123',
            'inn'           => '1234567890',
            'legal_address' => '', // пусто
            'contact_email' => 'support@example.com',
        ));
        $this->assertFalse(Cashback_Legal_Operator::is_configured());
    }

    public function test_get_missing_required_fields_returns_diff(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name' => 'X',
            // org_form / ogrn / inn / legal_address / contact_email — пусто
        ));
        $missing = Cashback_Legal_Operator::get_missing_required_fields();
        $this->assertContains('org_form', $missing);
        $this->assertContains('ogrn', $missing);
        $this->assertContains('inn', $missing);
        $this->assertContains('legal_address', $missing);
        $this->assertContains('contact_email', $missing);
        $this->assertNotContains('full_name', $missing);
    }

    public function test_set_all_round_trip(): void
    {
        $data = array(
            'full_name'           => 'ООО «Кэшбэк-Тест»',
            'short_name'          => 'Кэшбэк',
            'org_form'            => 'ЮЛ',
            'ogrn'                => '1234567890123',
            'inn'                 => '1234567890',
            'kpp'                 => '770101001',
            'legal_address'       => '123456, Москва',
            'postal_address'      => '123456, Москва, а/я 1',
            'contact_email'       => 'support@example.com',
            'contact_phone'       => '+7 495 000-00-00',
            'dpo_name'            => 'Иванов И.И.',
            'dpo_email'           => 'dpo@example.com',
            'rkn_registration_id' => '12-21-000000',
        );
        Cashback_Legal_Operator::set_all($data);

        foreach ($data as $field => $value) {
            $this->assertSame($value, Cashback_Legal_Operator::get($field), "Field {$field} round-trip failed");
        }
    }

    public function test_render_placeholders_replaces_operator_fields(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'ООО «Кэшбэк-Тест»',
            'org_form'      => 'ЮЛ',
            'ogrn'          => '1234567890123',
            'inn'           => '1234567890',
            'legal_address' => '123456, Москва',
            'contact_email' => 'support@example.com',
        ));

        $template = 'Оператор: {{operator_full_name}}, ОГРН {{operator_ogrn}}, ИНН {{operator_inn}}, e-mail: {{operator_contact_email}}';
        $rendered = Cashback_Legal_Operator::render_placeholders($template);

        $this->assertStringContainsString('ООО «Кэшбэк-Тест»', $rendered);
        $this->assertStringContainsString('1234567890123', $rendered);
        $this->assertStringContainsString('1234567890', $rendered);
        $this->assertStringContainsString('support@example.com', $rendered);
        $this->assertStringNotContainsString('{{operator_full_name}}', $rendered);
    }

    public function test_render_placeholders_leaves_unset_fields_as_visible_markers(): void
    {
        // Если оператор не настроен — плейсхолдеры остаются видимыми, чтобы
        // юрист/админ сразу видел "не заполнено".
        $template = 'Оператор: {{operator_full_name}}, телефон: {{operator_contact_phone}}';
        $rendered = Cashback_Legal_Operator::render_placeholders($template);
        $this->assertStringContainsString('{{operator_full_name}}', $rendered);
        $this->assertStringContainsString('{{operator_contact_phone}}', $rendered);
    }

    public function test_render_placeholders_falls_back_short_name_to_full_name(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'ООО «Кэшбэк-Тест»',
            'org_form'      => 'ЮЛ',
            'ogrn'          => '1234567890123',
            'inn'           => '1234567890',
            'legal_address' => '123456, Москва',
            'contact_email' => 'support@example.com',
            // short_name не задан — подставляется full_name
        ));
        $rendered = Cashback_Legal_Operator::render_placeholders('Краткое: {{operator_short_name}}');
        $this->assertStringContainsString('ООО «Кэшбэк-Тест»', $rendered);
    }

    public function test_render_placeholders_falls_back_dpo_email_to_contact_email(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'ООО «Кэшбэк-Тест»',
            'org_form'      => 'ЮЛ',
            'ogrn'          => '1234567890123',
            'inn'           => '1234567890',
            'legal_address' => '123456, Москва',
            'contact_email' => 'support@example.com',
        ));
        $rendered = Cashback_Legal_Operator::render_placeholders('DPO: {{operator_dpo_email}}');
        $this->assertStringContainsString('support@example.com', $rendered);
    }

    public function test_render_placeholders_supports_extra_overrides(): void
    {
        $rendered = Cashback_Legal_Operator::render_placeholders(
            'Версия: {{document_version}}, Дата: {{effective_date}}',
            array(
                '{{document_version}}' => '2.0.0',
                '{{effective_date}}'   => '2026-04-25',
            )
        );
        $this->assertStringContainsString('2.0.0', $rendered);
        $this->assertStringContainsString('2026-04-25', $rendered);
    }

    public function test_render_placeholders_replaces_current_year(): void
    {
        $rendered = Cashback_Legal_Operator::render_placeholders('© {{current_year}}');
        $this->assertStringContainsString('© ' . gmdate('Y'), $rendered);
    }

    public function test_email_field_is_sanitized(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'contact_email' => 'not-an-email',
            'dpo_email'     => 'invalid space@example.com',
        ));
        $this->assertSame('', Cashback_Legal_Operator::get('contact_email'));
    }
}
