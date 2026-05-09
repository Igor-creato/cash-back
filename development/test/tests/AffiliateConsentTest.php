<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тест нового consent_type `affiliate_program` (партнёрская программа).
 *
 * Покрывает:
 *   - константа Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM = 'affiliate_program'
 *   - all_types() / consent_types() включают affiliate_program
 *   - get_meta('affiliate_program') возвращает полную мету (slug, title, template_path,
 *     is_consent=true, is_required=false — opt-in)
 *   - seed_versions() идемпотентен с affiliate_program (G6: миграция legacy-юзеров)
 *   - Шаблон legal/templates/affiliate-program.php существует и возвращает HTML
 *     с обязательными плейсхолдерами и обязательными правовыми формулировками
 *   - Cashback_Affiliate_Activation::is_activated() корректно использует
 *     Cashback_Legal_Consent_Manager::has_active_consent() с правильным consent_type
 *   - source-based: класс активации регистрирует wp-ajax cashback_affiliate_activate
 *   - source-based: Cashback_Affiliate_Frontend::endpoint_content() имеет gate
 *     на is_activated() ДО рендера реферальной ссылки
 */
#[Group('legal')]
#[Group('legal-compliance')]
#[Group('affiliate-program')]
final class AffiliateConsentTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/legal/class-cashback-legal-db.php';
        require_once $plugin_root . '/legal/class-cashback-legal-documents.php';
        require_once $plugin_root . '/legal/class-cashback-legal-operator.php';
        require_once $plugin_root . '/legal/class-cashback-legal-consent-manager.php';
        require_once $plugin_root . '/affiliate/class-affiliate-activation.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options'] = array();
    }

    public function test_type_affiliate_program_constant_exists(): void
    {
        $this->assertTrue(
            defined('Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM'),
            'Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM должна быть объявлена'
        );
        $this->assertSame('affiliate_program', Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM);
    }

    public function test_all_types_includes_affiliate_program(): void
    {
        $this->assertContains(
            Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM,
            Cashback_Legal_Documents::all_types(),
            'affiliate_program должен быть в all_types() — иначе is_valid_consent_type() отвергнет вставку в журнал'
        );
    }

    public function test_consent_types_includes_affiliate_program(): void
    {
        $this->assertContains(
            Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM,
            Cashback_Legal_Documents::consent_types(),
            'affiliate_program должен быть в consent_types() — это checkbox с явным opt-in акцептом'
        );
    }

    public function test_get_meta_returns_full_meta_for_affiliate_program(): void
    {
        $meta = Cashback_Legal_Documents::get_meta(Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM);

        $this->assertNotEmpty($meta, 'get_meta(affiliate_program) не должна возвращать пустой массив');
        $this->assertSame('affiliate-program', $meta['slug']);
        $this->assertNotEmpty($meta['title']);
        $this->assertSame('legal/templates/affiliate-program.php', $meta['template_path']);
        $this->assertTrue($meta['is_consent']);
        $this->assertTrue($meta['is_public']);
        $this->assertFalse(
            $meta['is_required'],
            'affiliate_program — opt-in, не должно блокировать регистрацию (is_required=false)'
        );
    }

    public function test_seed_versions_idempotent_with_affiliate_program(): void
    {
        Cashback_Legal_Documents::seed_versions();
        $first = get_option(Cashback_Legal_Documents::VERSIONS_OPTION);

        $this->assertIsArray($first);
        $this->assertArrayHasKey(Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM, $first);
        $this->assertSame('1.0.0', $first[Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM]);

        // Симулируем bump до 2.0.0 — повторный seed_versions() не должен сбросить.
        Cashback_Legal_Documents::set_active_version(
            Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM,
            '2.0.0'
        );
        Cashback_Legal_Documents::seed_versions();
        $after = get_option(Cashback_Legal_Documents::VERSIONS_OPTION);

        $this->assertSame(
            '2.0.0',
            $after[Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM],
            'seed_versions() не должна сбрасывать уже установленную версию (G6 idempotent)'
        );
    }

    public function test_default_template_file_exists_and_has_required_placeholders(): void
    {
        $template = dirname(__DIR__, 3) . '/legal/templates/affiliate-program.php';
        $this->assertFileExists(
            $template,
            'Шаблон legal/templates/affiliate-program.php должен существовать'
        );

        $rendered = include $template;
        $this->assertIsString($rendered);
        $this->assertNotSame('', trim($rendered));

        // Обязательные плейсхолдеры — проверяет валидатор перед publish.
        $required_placeholders = array(
            '{{operator_full_name}}',
            '{{operator_ogrn}}',
            '{{operator_inn}}',
            '{{operator_legal_address}}',
            '{{operator_contact_email}}',
            '{{site_name}}',
            '{{site_url}}',
            '{{document_version}}',
            '{{effective_date}}',
        );
        foreach ($required_placeholders as $ph) {
            $this->assertStringContainsString(
                $ph,
                $rendered,
                'Шаблон должен содержать обязательный плейсхолдер ' . $ph
            );
        }
    }

    public function test_default_template_contains_required_legal_assertions(): void
    {
        $template = dirname(__DIR__, 3) . '/legal/templates/affiliate-program.php';
        $rendered = include $template;
        $this->assertIsString($rendered);

        // 5 правовых позиций сервиса должны быть отражены в тексте оферты партнёрской программы.
        // Список tuples [needle, reason] — не ассоциативный массив, чтобы PHP не
        // конвертировал числовые ключи (226/437) в int.
        $required_phrases = array(
            array( 'бонусное вознаграждение', 'Должен использоваться термин «бонусное вознаграждение» (а не «денежное вознаграждение»)' ),
            array( 'не является налоговым агентом', 'Должна быть прямая декларация, что Сервис не налоговый агент' ),
            array( 'не является', 'Должна быть декларация о неотносимости Сервиса к финансовым организациям' ),
            array( '161-фз', 'Должна быть ссылка на 161-ФЗ для квалификации статуса' ),
            array( 'не участвует', 'Должна быть декларация о неучастии Сервиса в расчётах между Пользователями и Партнёрами (Интернет-магазинами)' ),
            array( 'статьи 226', 'Должна быть ссылка на ст. 226 НК РФ (налоговый агент)' ),
            array( 'статьи 437', 'Должна быть ссылка на ст. 437 ГК РФ (публичная оферта)' ),
        );
        $haystack_lower = mb_strtolower($rendered);
        foreach ($required_phrases as $pair) {
            list($needle, $reason) = $pair;
            $needle_lower = mb_strtolower($needle);
            $this->assertStringContainsString(
                $needle_lower,
                $haystack_lower,
                $reason . ' (искомая подстрока: «' . $needle . '»)'
            );
        }

        // Запрещённые формулировки.
        $this->assertStringNotContainsString(
            'денежное вознаграждение',
            mb_strtolower($rendered),
            'Запрещён термин «денежное вознаграждение» — должно быть «бонусное вознаграждение»'
        );
    }

    public function test_default_template_has_lawyer_review_marker(): void
    {
        $template = dirname(__DIR__, 3) . '/legal/templates/affiliate-program.php';
        $rendered = include $template;
        $this->assertIsString($rendered);
        $this->assertStringContainsString(
            'LAWYER_REVIEW_REQUIRED',
            $rendered,
            'Плашка LAWYER_REVIEW_REQUIRED обязательна до утверждения юристом'
        );
    }

    public function test_activation_class_exists_with_required_api(): void
    {
        $this->assertTrue(
            class_exists('Cashback_Affiliate_Activation'),
            'Класс Cashback_Affiliate_Activation должен быть загружен'
        );
        $this->assertTrue(
            method_exists('Cashback_Affiliate_Activation', 'is_activated'),
            'Cashback_Affiliate_Activation::is_activated() должен существовать (UI gate)'
        );
        $this->assertTrue(
            method_exists('Cashback_Affiliate_Activation', 'render_activation_form'),
            'Cashback_Affiliate_Activation::render_activation_form() должен существовать'
        );
        $this->assertTrue(
            method_exists('Cashback_Affiliate_Activation', 'handle_ajax_activate'),
            'Cashback_Affiliate_Activation::handle_ajax_activate() должен существовать (wp-ajax handler)'
        );
        $this->assertTrue(
            method_exists('Cashback_Affiliate_Activation', 'init'),
            'Cashback_Affiliate_Activation::init() должен существовать (регистрация хуков)'
        );
    }

    public function test_activation_uses_correct_consent_type_in_source(): void
    {
        // Source-based: проверяем, что is_activated() читает консент именно
        // affiliate_program (а не другой тип, и не bypass'ит проверку).
        $path = dirname(__DIR__, 3) . '/affiliate/class-affiliate-activation.php';
        $src  = (string) file_get_contents($path);

        $this->assertSame(
            1,
            preg_match(
                '/Cashback_Legal_Consent_Manager::has_active_consent\s*\(\s*\$user_id\s*,\s*Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM\s*\)/s',
                $src
            ),
            'is_activated() должен вызывать has_active_consent($user_id, TYPE_AFFILIATE_PROGRAM)'
        );

        $this->assertSame(
            1,
            preg_match(
                '/Cashback_Legal_Consent_Manager::record_consent\s*\(\s*\$user_id\s*,\s*Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM\s*,/s',
                $src
            ),
            'handle_ajax_activate() должен вызывать record_consent($user_id, TYPE_AFFILIATE_PROGRAM, …)'
        );

        // Проверяем, что регистрируется wp-ajax с правильным action.
        $this->assertSame(
            1,
            preg_match(
                "/add_action\\(\\s*'wp_ajax_'\\s*\\.\\s*self::AJAX_ACTION/s",
                $src
            ),
            'init() должен регистрировать wp-ajax обработчик'
        );
    }

    public function test_frontend_endpoint_has_activation_gate(): void
    {
        // Source-based: проверяем, что Frontend::endpoint_content() вызывает
        // is_activated() и при false возвращает форму активации ДО рендера
        // реферальной ссылки.
        $path = dirname(__DIR__, 3) . '/affiliate/class-affiliate-frontend.php';
        $src  = (string) file_get_contents($path);

        $this->assertSame(
            1,
            preg_match(
                '/Cashback_Affiliate_Activation::is_activated\s*\(\s*\$user_id\s*\)/s',
                $src
            ),
            'endpoint_content() должен вызывать Cashback_Affiliate_Activation::is_activated($user_id)'
        );

        $this->assertSame(
            1,
            preg_match(
                '/Cashback_Affiliate_Activation::render_activation_form\s*\(\s*\$user_id\s*\)/s',
                $src
            ),
            'endpoint_content() должен рендерить форму активации, если консент отсутствует'
        );

        // Gate должен срабатывать ДО рендера реферальной ссылки. Проверяем
        // взаимный порядок маркеров: is_activated → return; → get_referral_link.
        $gate_pos = strpos($src, 'Cashback_Affiliate_Activation::is_activated');
        $link_pos = strpos($src, 'get_referral_link');
        $this->assertNotFalse($gate_pos);
        $this->assertNotFalse($link_pos);
        $this->assertLessThan(
            $link_pos,
            $gate_pos,
            'Gate is_activated() должен находиться в коде ДО get_referral_link() (иначе ссылка показывается до акцепта)'
        );
    }

    public function test_pages_installer_does_not_skip_affiliate_program(): void
    {
        // Pages_Installer пропускает только TYPE_CONTACT_FORM_PD. affiliate_program
        // должен получить публичную страницу /affiliate-program/ при следующей
        // активации (через detect_missing_pages в admin_init).
        $path = dirname(__DIR__, 3) . '/legal/class-cashback-legal-pages-installer.php';
        $src  = (string) file_get_contents($path);

        $this->assertSame(
            0,
            preg_match(
                '/===\s*Cashback_Legal_Documents::TYPE_AFFILIATE_PROGRAM/s',
                $src
            ),
            'Pages_Installer не должен исключать affiliate_program из создания публичной страницы'
        );
    }
}
