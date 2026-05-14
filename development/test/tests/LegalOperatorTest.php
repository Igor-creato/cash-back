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
            'inn'           => '1234567890',
            'legal_address' => '123456, Москва, ул. Тестовая, д. 1',
            'contact_email' => 'support@example.com',
            'website_url'   => 'https://example.com',
        ));
        $this->assertTrue(Cashback_Legal_Operator::is_configured());
    }

    public function test_is_configured_false_when_one_required_missing(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'ООО «Кэшбэк-Тест»',
            'org_form'      => 'ЮЛ',
            'inn'           => '1234567890',
            'legal_address' => '', // пусто
            'contact_email' => 'support@example.com',
            'website_url'   => 'https://example.com',
        ));
        $this->assertFalse(Cashback_Legal_Operator::is_configured());
    }

    public function test_get_missing_required_fields_returns_diff(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name' => 'X',
            // org_form / inn / legal_address / contact_email / website_url — пусто
        ));
        $missing = Cashback_Legal_Operator::get_missing_required_fields();
        $this->assertContains('org_form', $missing);
        $this->assertContains('inn', $missing);
        $this->assertContains('legal_address', $missing);
        $this->assertContains('contact_email', $missing);
        $this->assertContains('website_url', $missing);
        $this->assertNotContains('full_name', $missing);
        // ogrn опциональный (отсутствует у самозанятых) — НЕ в required_fields.
        $this->assertNotContains('ogrn', $missing);
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

    /**
     * Самозанятый: ОГРН и КПП пустые → блоки {{#if_ogrn}}…{{/if_ogrn}} и
     * {{#if_kpp}}…{{/if_kpp}} удаляются целиком вместе с обрамлением.
     */
    public function test_render_placeholders_removes_ogrn_block_when_empty(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'Самозанятый Иванов И.И.',
            'org_form'      => 'Самозанятый',
            'inn'           => '770100000001',
            'legal_address' => '123456, Москва',
            'contact_email' => 'support@example.com',
            // ogrn, kpp оставлены пустыми
        ));

        $template = '<li>{{#if_ogrn}}ОГРН/ОГРНИП: {{operator_ogrn}}, {{/if_ogrn}}ИНН: {{operator_inn}}{{#if_kpp}}, КПП: {{operator_kpp}}{{/if_kpp}}</li>';
        $rendered = Cashback_Legal_Operator::render_placeholders($template);

        $this->assertStringNotContainsString('ОГРН/ОГРНИП', $rendered);
        $this->assertStringNotContainsString('КПП', $rendered);
        $this->assertStringNotContainsString('{{operator_ogrn}}', $rendered);
        $this->assertStringNotContainsString('{{operator_kpp}}', $rendered);
        $this->assertStringNotContainsString('{{#if_', $rendered);
        $this->assertSame('<li>ИНН: 770100000001</li>', $rendered);
    }

    /**
     * ЮЛ: ОГРН и КПП заполнены → блоки раскрываются без обёрток, значения подставлены.
     */
    public function test_render_placeholders_keeps_ogrn_and_kpp_blocks_when_filled(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'ООО «Кэшбэк»',
            'org_form'      => 'ЮЛ',
            'ogrn'          => '1234567890123',
            'kpp'           => '770101001',
            'inn'           => '7701000001',
            'legal_address' => '123456, Москва',
            'contact_email' => 'support@example.com',
        ));

        $template = '<li>{{#if_ogrn}}ОГРН/ОГРНИП: {{operator_ogrn}}, {{/if_ogrn}}ИНН: {{operator_inn}}{{#if_kpp}}, КПП: {{operator_kpp}}{{/if_kpp}}</li>';
        $rendered = Cashback_Legal_Operator::render_placeholders($template);

        $this->assertSame('<li>ОГРН/ОГРНИП: 1234567890123, ИНН: 7701000001, КПП: 770101001</li>', $rendered);
        $this->assertStringNotContainsString('{{#if_', $rendered);
        $this->assertStringNotContainsString('{{/if_', $rendered);
    }

    /**
     * ИП: ОГРН заполнен (ОГРНИП), КПП пустой → ОГРН раскрывается, КПП-блок удаляется.
     */
    public function test_render_placeholders_keeps_ogrn_drops_kpp_for_ip(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'ИП Иванов И.И.',
            'org_form'      => 'ИП',
            'ogrn'          => '321770000000001',
            'inn'           => '770100000001',
            'legal_address' => '123456, Москва',
            'contact_email' => 'support@example.com',
            // kpp пуст
        ));

        $template = '<li>{{#if_ogrn}}ОГРН/ОГРНИП: {{operator_ogrn}}, {{/if_ogrn}}ИНН: {{operator_inn}}{{#if_kpp}}, КПП: {{operator_kpp}}{{/if_kpp}}</li>';
        $rendered = Cashback_Legal_Operator::render_placeholders($template);

        $this->assertSame('<li>ОГРН/ОГРНИП: 321770000000001, ИНН: 770100000001</li>', $rendered);
        $this->assertStringNotContainsString('КПП', $rendered);
    }

    /**
     * Блочный паттерн на отдельных строках (pd-policy/tech-data): пустые поля
     * удаляют целые `<li>`, оставляя только заполненные.
     */
    public function test_render_placeholders_removes_standalone_li_blocks_when_empty(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'Самозанятый Иванов И.И.',
            'org_form'      => 'Самозанятый',
            'inn'           => '770100000001',
            'legal_address' => '123456, Москва',
            'contact_email' => 'support@example.com',
        ));

        $template = "<ul>\n"
            . "{{#if_ogrn}}    <li>ОГРН/ОГРНИП: {{operator_ogrn}}</li>\n{{/if_ogrn}}"
            . "    <li>ИНН: {{operator_inn}}</li>\n"
            . "{{#if_kpp}}    <li>КПП: {{operator_kpp}}</li>\n{{/if_kpp}}"
            . "</ul>";
        $rendered = Cashback_Legal_Operator::render_placeholders($template);

        $this->assertStringNotContainsString('ОГРН/ОГРНИП', $rendered);
        $this->assertStringNotContainsString('КПП', $rendered);
        $this->assertStringContainsString('<li>ИНН: 770100000001</li>', $rendered);
    }

    /**
     * Multi-line блок с переносами строк (флаг `s` в regex) — корректно удаляется.
     */
    public function test_render_placeholders_handles_multiline_conditional_blocks(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'Самозанятый',
            'org_form'      => 'Самозанятый',
            'inn'           => '770100000001',
            'legal_address' => '123456, Москва',
            'contact_email' => 'support@example.com',
        ));

        $template = "before\n{{#if_ogrn}}line A\nline B with {{operator_ogrn}}\nline C{{/if_ogrn}}\nafter";
        $rendered = Cashback_Legal_Operator::render_placeholders($template);

        $this->assertSame("before\n\nafter", $rendered);
    }

    /**
     * Телефон оператора опционален (защита от спама — конкуренты телефон в юр.
     * документах не публикуют). При пустом значении блок {{#if_contact_phone}}…
     * {{/if_contact_phone}} удаляется целиком — и standalone `<li>`, и inline
     * сегмент ", телефон: …" из реквизитов в футере terms-offer / affiliate-program.
     */
    public function test_render_placeholders_removes_contact_phone_block_when_empty(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'ООО «Кэшбэк»',
            'org_form'      => 'ЮЛ',
            'inn'           => '7701000001',
            'legal_address' => '123456, Москва',
            'contact_email' => 'support@example.com',
            // contact_phone оставлен пустым
        ));

        // Standalone <li> (pd-policy.php pattern).
        $standalone = "    <li>Контактный e-mail: {{operator_contact_email}}</li>\n"
            . "{{#if_contact_phone}}    <li>Контактный телефон: {{operator_contact_phone}}</li>\n{{/if_contact_phone}}"
            . "    <li>Адрес сайта: {{site_url}}</li>";
        $rendered_standalone = Cashback_Legal_Operator::render_placeholders($standalone);

        $this->assertStringNotContainsString('Контактный телефон', $rendered_standalone);
        $this->assertStringNotContainsString('{{operator_contact_phone}}', $rendered_standalone);
        $this->assertStringNotContainsString('{{#if_', $rendered_standalone);
        $this->assertStringContainsString('<li>Контактный e-mail: support@example.com</li>', $rendered_standalone);

        // Inline сегмент (terms-offer.php / affiliate-program.php pattern).
        $inline   = '<li>E-mail: {{operator_contact_email}}{{#if_contact_phone}}, телефон: {{operator_contact_phone}}{{/if_contact_phone}}</li>';
        $rendered = Cashback_Legal_Operator::render_placeholders($inline);

        $this->assertSame('<li>E-mail: support@example.com</li>', $rendered);
        $this->assertStringNotContainsString('телефон', $rendered);
    }

    /**
     * Телефон заполнен → блок раскрывается без обёртки, значение подставлено.
     */
    public function test_render_placeholders_keeps_contact_phone_block_when_filled(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'ООО «Кэшбэк»',
            'org_form'      => 'ЮЛ',
            'inn'           => '7701000001',
            'legal_address' => '123456, Москва',
            'contact_email' => 'support@example.com',
            'contact_phone' => '+7 (495) 000-00-00',
        ));

        // Standalone.
        $standalone = "{{#if_contact_phone}}    <li>Контактный телефон: {{operator_contact_phone}}</li>\n{{/if_contact_phone}}";
        $this->assertSame(
            "    <li>Контактный телефон: +7 (495) 000-00-00</li>\n",
            Cashback_Legal_Operator::render_placeholders($standalone)
        );

        // Inline.
        $inline = '<li>E-mail: {{operator_contact_email}}{{#if_contact_phone}}, телефон: {{operator_contact_phone}}{{/if_contact_phone}}</li>';
        $this->assertSame(
            '<li>E-mail: support@example.com, телефон: +7 (495) 000-00-00</li>',
            Cashback_Legal_Operator::render_placeholders($inline)
        );
    }

    /**
     * 2026-05-14 (plan immutable-pondering-harbor): пустой `rkn_registration_id`
     * НЕ остаётся видимым плейсхолдером (как было до правки) — подменяется
     * информативной строкой «будет указан после внесения в реестр операторов
     * ПД Роскомнадзора». Это закрывает рекомендацию РКН-аудита: литерал
     * `{{operator_rkn_registration_id}}` нельзя оставлять на опубликованной
     * странице Политики. `is_configured()` НЕ затрагивается — поле уже
     * отсутствует в required_fields() ([line 33–42](legal/class-cashback-legal-operator.php#L33)),
     * чтобы admin-плашка «реквизиты не настроены» не сломалась.
     */
    public function test_render_placeholders_falls_back_rkn_registration_id_when_empty(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'Иванов Иван Иванович',
            'org_form'      => 'физическое лицо, применяющее специальный налоговый режим «Налог на профессиональный доход»',
            'inn'           => '770100000001',
            'legal_address' => '123456, Москва',
            'contact_email' => 'support@example.com',
            'website_url'   => 'https://example.com',
            // rkn_registration_id оставлен пустым — оператор ещё не подал уведомление.
        ));

        $rendered = Cashback_Legal_Operator::render_placeholders('РКН: {{operator_rkn_registration_id}}');

        $this->assertStringContainsString(
            'будет указан после внесения Оператора в реестр операторов персональных данных Роскомнадзора',
            $rendered,
            'Пустой rkn_registration_id должен подменяться информативной строкой, а не оставаться видимым плейсхолдером'
        );
        $this->assertStringNotContainsString(
            '{{operator_rkn_registration_id}}',
            $rendered,
            'Литерал плейсхолдера не должен попадать в опубликованный текст'
        );
    }

    /**
     * При заполненном `rkn_registration_id` подстановка идёт штатно — fallback
     * не активируется.
     */
    public function test_render_placeholders_uses_rkn_registration_id_value_when_filled(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'           => 'Иванов Иван Иванович',
            'org_form'            => 'НПД',
            'inn'                 => '770100000001',
            'legal_address'       => '123456, Москва',
            'contact_email'       => 'support@example.com',
            'website_url'         => 'https://example.com',
            'rkn_registration_id' => '77-25/000000',
        ));

        $rendered = Cashback_Legal_Operator::render_placeholders('РКН: {{operator_rkn_registration_id}}');

        $this->assertStringContainsString('77-25/000000', $rendered);
        $this->assertStringNotContainsString('будет указан после внесения', $rendered);
        $this->assertStringNotContainsString('{{operator_rkn_registration_id}}', $rendered);
    }

    /**
     * Inline-вариант (terms-offer / affiliate-program) при пустом телефоне:
     * запятая и слово "телефон:" не должны болтаться. E-mail закрывается тегом
     * `</li>` без артефактов.
     */
    public function test_render_placeholders_handles_inline_contact_phone_block(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'ИП Иванов И.И.',
            'org_form'      => 'ИП',
            'ogrn'          => '321770000000001',
            'inn'           => '770100000001',
            'legal_address' => '123456, Москва',
            'contact_email' => 'support@example.com',
            // contact_phone пустой
        ));

        // Фрагмент из terms-offer.php / affiliate-program.php "Реквизиты Оператора".
        $fragment = '<li>E-mail: {{operator_contact_email}}{{#if_contact_phone}}, телефон: {{operator_contact_phone}}{{/if_contact_phone}}</li>';
        $rendered = Cashback_Legal_Operator::render_placeholders($fragment);

        $this->assertSame('<li>E-mail: support@example.com</li>', $rendered);
        $this->assertStringNotContainsString(',', $rendered, 'Нет висящей запятой после email');
        $this->assertStringNotContainsString('телефон', $rendered, 'Нет литерала "телефон" без значения');
        $this->assertStringNotContainsString('{{', $rendered, 'Нет остаточных плейсхолдеров');
    }
}
