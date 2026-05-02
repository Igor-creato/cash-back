<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тест VULN-04 (149-ФЗ): новый consent_type `tech_data`.
 *
 * Покрывает:
 *   - константа Cashback_Legal_Documents::TYPE_TECH_DATA = 'tech_data'
 *   - all_types() / consent_types() включают tech_data
 *   - get_meta('tech_data') возвращает полную мету (slug, title, template_path, is_consent)
 *   - seed_versions() идемпотентен: повторный вызов не дублирует и не теряет
 *     версии (G6 — миграция должна быть идемпотентной для legacy-юзеров)
 *   - Cashback_Legal_Registration_Checkboxes::FIELD_TECH_DATA константа существует
 *   - source-based: registration-checkboxes пишет consent для tech_data при checked
 */
#[Group('legal')]
#[Group('legal-compliance')]
#[Group('tech-data')]
final class TechDataConsentTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/legal/class-cashback-legal-db.php';
        require_once $plugin_root . '/legal/class-cashback-legal-documents.php';
        require_once $plugin_root . '/legal/class-cashback-legal-operator.php';
        require_once $plugin_root . '/legal/class-cashback-legal-consent-manager.php';
        require_once $plugin_root . '/legal/class-cashback-legal-registration-checkboxes.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options'] = array();
    }

    public function test_type_tech_data_constant_exists(): void
    {
        $this->assertTrue(
            defined('Cashback_Legal_Documents::TYPE_TECH_DATA'),
            'Cashback_Legal_Documents::TYPE_TECH_DATA должна быть объявлена (VULN-04)'
        );
        $this->assertSame('tech_data', Cashback_Legal_Documents::TYPE_TECH_DATA);
    }

    public function test_all_types_includes_tech_data(): void
    {
        $this->assertContains(
            Cashback_Legal_Documents::TYPE_TECH_DATA,
            Cashback_Legal_Documents::all_types(),
            'tech_data должен быть в all_types() — иначе is_valid_consent_type() отвергнет вставку'
        );
    }

    public function test_consent_types_includes_tech_data(): void
    {
        $this->assertContains(
            Cashback_Legal_Documents::TYPE_TECH_DATA,
            Cashback_Legal_Documents::consent_types(),
            'tech_data должен быть в consent_types() — это checkbox, требующий явного согласия subject'
        );
    }

    public function test_get_meta_returns_full_meta_for_tech_data(): void
    {
        $meta = Cashback_Legal_Documents::get_meta(Cashback_Legal_Documents::TYPE_TECH_DATA);

        $this->assertNotEmpty($meta, 'get_meta(tech_data) не должна возвращать пустой массив');
        $this->assertSame('tech-data', $meta['slug']);
        $this->assertNotEmpty($meta['title']);
        $this->assertSame('legal/templates/tech-data.php', $meta['template_path']);
        $this->assertTrue($meta['is_consent']);
        $this->assertTrue($meta['is_public']);
    }

    public function test_seed_versions_idempotent_with_tech_data(): void
    {
        // G6: повторный вызов seed_versions() не должен мутировать опцию,
        // если все типы уже засеяны — иначе re-activation сбрасывает version map.
        Cashback_Legal_Documents::seed_versions();
        $first = get_option(Cashback_Legal_Documents::VERSIONS_OPTION);

        $this->assertIsArray($first);
        $this->assertArrayHasKey(Cashback_Legal_Documents::TYPE_TECH_DATA, $first);
        $this->assertSame('1.0.0', $first[Cashback_Legal_Documents::TYPE_TECH_DATA]);

        // Симулируем bump tech_data до 2.0.0
        Cashback_Legal_Documents::set_active_version(
            Cashback_Legal_Documents::TYPE_TECH_DATA,
            '2.0.0'
        );

        Cashback_Legal_Documents::seed_versions();
        $after = get_option(Cashback_Legal_Documents::VERSIONS_OPTION);

        $this->assertSame(
            '2.0.0',
            $after[Cashback_Legal_Documents::TYPE_TECH_DATA],
            'seed_versions() не должна сбрасывать уже установленную версию (G6 idempotent)'
        );
    }

    public function test_field_tech_data_constant_exists(): void
    {
        $this->assertTrue(
            defined('Cashback_Legal_Registration_Checkboxes::FIELD_TECH_DATA'),
            'Cashback_Legal_Registration_Checkboxes::FIELD_TECH_DATA должна быть объявлена (VULN-04)'
        );
    }

    public function test_registration_does_not_write_tech_data_consent(): void
    {
        // UX-cleanup 1.4.0: tech_data toggle переехал с формы регистрации в ЛК
        // (Cashback_Legal_My_Account). Регистрация НЕ должна писать TYPE_TECH_DATA
        // в журнал — это делается явным opt-in через AJAX в ЛК.
        $path = dirname(__DIR__, 3) . '/legal/class-cashback-legal-registration-checkboxes.php';
        $src  = (string) file_get_contents($path);

        $this->assertSame(
            0,
            preg_match(
                '/\$write\s*\(\s*Cashback_Legal_Documents::TYPE_TECH_DATA/s',
                $src
            ),
            'save_consents_on_registration() НЕ должен писать tech_data — он переехал в ЛК (Cashback_Legal_My_Account)'
        );

        // Сам toggle должен быть в новом классе.
        $my_account = dirname(__DIR__, 3) . '/legal/class-cashback-legal-my-account.php';
        $this->assertFileExists($my_account, 'Cashback_Legal_My_Account отвечает за tech_data toggle');
        $my_src = (string) file_get_contents($my_account);
        $this->assertStringContainsString('TYPE_TECH_DATA', $my_src);
    }

    public function test_default_template_file_exists(): void
    {
        $template = dirname(__DIR__, 3) . '/legal/templates/tech-data.php';
        $this->assertFileExists(
            $template,
            'Шаблон legal/templates/tech-data.php должен существовать (VULN-04 — 149-ФЗ ст. 10)'
        );

        // Шаблон должен возвращать строку с placeholder'ами оператора
        $rendered = include $template;
        $this->assertIsString($rendered);
        $this->assertStringContainsString('{{operator_full_name}}', $rendered);
        $this->assertStringContainsString('149-ФЗ', $rendered, 'Шаблон должен ссылаться на 149-ФЗ');
    }
}
