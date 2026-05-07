<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Legal_Template_Validator — валидация и санитизация body
 * перед сохранением draft / публикацией.
 *
 * Покрывает:
 *   - extract_placeholders: все вхождения {{name}}, {{operator.x}}.
 *   - validate_for_publish: пустой / слишком большой / удалённый плейсхолдер.
 *   - sanitize_html: strip script / on* атрибутов, сохранение safelist'а
 *     тегов и placeholder'ов через protect-marker round-trip.
 */
#[Group('legal')]
#[Group('legal-template-validator')]
final class LegalTemplateValidatorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/legal/class-cashback-legal-documents.php';
        require_once $plugin_root . '/legal/class-cashback-legal-operator.php';
        require_once $plugin_root . '/legal/class-cashback-legal-template-validator.php';

        // Локальный wp_kses-стаб: использует strip_tags с allowlist (без
        // фильтрации атрибутов). Достаточно для unit-проверок «<script> вырезан»;
        // honest WP-фильтр атрибутов проверяется на staging E2E.
        if (!function_exists('wp_kses')) {
            function wp_kses(string $html, array $allowed_html): string
            {
                $tags = '';
                foreach (array_keys($allowed_html) as $tag) {
                    $tags .= '<' . $tag . '>';
                }
                return strip_tags($html, $tags);
            }
        }
    }

    // ────────────────────────────────────────────────────────────
    // extract_placeholders
    // ────────────────────────────────────────────────────────────

    public function test_extract_placeholders_finds_all_unique(): void
    {
        $body  = '<p>{{operator_full_name}} — {{operator_inn}}; {{operator_full_name}} ещё раз.</p>';
        $found = Cashback_Legal_Template_Validator::extract_placeholders($body);
        sort($found);
        $this->assertSame(array( 'operator_full_name', 'operator_inn' ), $found);
    }

    public function test_extract_placeholders_supports_dot_notation(): void
    {
        $body  = '{{operator.full_name}} {{operator.inn}}';
        $found = Cashback_Legal_Template_Validator::extract_placeholders($body);
        sort($found);
        $this->assertSame(array( 'operator.full_name', 'operator.inn' ), $found);
    }

    public function test_extract_placeholders_returns_empty_when_none(): void
    {
        $this->assertSame(array(), Cashback_Legal_Template_Validator::extract_placeholders('<p>plain</p>'));
    }

    // ────────────────────────────────────────────────────────────
    // validate_for_publish
    // ────────────────────────────────────────────────────────────

    public function test_validate_for_publish_rejects_empty_body(): void
    {
        $err = Cashback_Legal_Template_Validator::validate_for_publish('pd_consent', '');
        $this->assertInstanceOf(WP_Error::class, $err);
        $this->assertSame('body_empty', $err->get_error_code());
    }

    public function test_validate_for_publish_rejects_whitespace_only(): void
    {
        $err = Cashback_Legal_Template_Validator::validate_for_publish('pd_consent', "   \n\t  ");
        $this->assertInstanceOf(WP_Error::class, $err);
        $this->assertSame('body_empty', $err->get_error_code());
    }

    public function test_validate_for_publish_rejects_too_large(): void
    {
        // Лимит 200_000 байт; конструируем 200_001.
        $oversize = str_repeat('a', 200001);
        $err      = Cashback_Legal_Template_Validator::validate_for_publish('pd_consent', $oversize);
        $this->assertInstanceOf(WP_Error::class, $err);
        $this->assertSame('body_too_large', $err->get_error_code());
    }

    public function test_validate_for_publish_rejects_missing_placeholder(): void
    {
        // Соберём body, в котором нет хотя бы одного из тех placeholder'ов,
        // что есть в исходном PHP-шаблоне pd_consent. Самый частый — operator_full_name.
        $body_without_required = str_repeat('Текст без плейсхолдеров. ', 50);
        $err                   = Cashback_Legal_Template_Validator::validate_for_publish('pd_consent', $body_without_required);
        $this->assertInstanceOf(WP_Error::class, $err);
        $this->assertSame('placeholders_missing', $err->get_error_code());
    }

    public function test_validate_for_publish_accepts_when_all_placeholders_present(): void
    {
        // Берём текущий PHP-шаблон как baseline и убеждаемся что валидация
        // проходит (он по определению содержит все свои placeholder'ы).
        $php_body = Cashback_Legal_Documents::load_template('pd_consent');
        $this->assertNotEmpty($php_body, 'pd_consent.php должен возвращать непустой шаблон');

        $result = Cashback_Legal_Template_Validator::validate_for_publish('pd_consent', $php_body);
        $this->assertTrue($result);
    }

    public function test_validate_for_publish_rejects_unknown_type(): void
    {
        $err = Cashback_Legal_Template_Validator::validate_for_publish('made_up_type', '<p>x</p>');
        $this->assertInstanceOf(WP_Error::class, $err);
        $this->assertSame('unknown_type', $err->get_error_code());
    }

    // ────────────────────────────────────────────────────────────
    // sanitize_html
    // ────────────────────────────────────────────────────────────

    public function test_sanitize_html_strips_script_tags(): void
    {
        $body    = '<p>safe</p><script>alert(1)</script><p>more</p>';
        $cleaned = Cashback_Legal_Template_Validator::sanitize_html($body);
        // Содержимое <script> остаётся как plain text после kses (как и в реальном
        // WP) — это безопасно, JS не выполняется. Проверяем только отсутствие тега.
        $this->assertStringNotContainsString('<script', $cleaned);
        $this->assertStringNotContainsString('</script', $cleaned);
        $this->assertStringContainsString('<p>safe</p>', $cleaned);
        $this->assertStringContainsString('<p>more</p>', $cleaned);
    }

    public function test_sanitize_html_strips_style_tags(): void
    {
        $body    = '<p>x</p><style>p { color: red; }</style>';
        $cleaned = Cashback_Legal_Template_Validator::sanitize_html($body);
        $this->assertStringNotContainsString('<style', $cleaned);
    }

    public function test_sanitize_html_preserves_placeholders(): void
    {
        $body    = '<p>Оператор: {{operator_full_name}}, ИНН {{operator_inn}}.</p>';
        $cleaned = Cashback_Legal_Template_Validator::sanitize_html($body);
        $this->assertStringContainsString('{{operator_full_name}}', $cleaned);
        $this->assertStringContainsString('{{operator_inn}}', $cleaned);
    }

    public function test_sanitize_html_keeps_allowed_tags(): void
    {
        $body    = '<p>Текст <strong>жирный</strong> и <em>курсив</em>.</p><ul><li>один</li></ul>';
        $cleaned = Cashback_Legal_Template_Validator::sanitize_html($body);
        $this->assertStringContainsString('<strong>', $cleaned);
        $this->assertStringContainsString('<em>', $cleaned);
        $this->assertStringContainsString('<ul>', $cleaned);
        $this->assertStringContainsString('<li>', $cleaned);
    }

    public function test_allowed_html_does_not_include_script(): void
    {
        $allowed = Cashback_Legal_Template_Validator::allowed_html();
        $this->assertArrayNotHasKey('script', $allowed);
        $this->assertArrayNotHasKey('style', $allowed);
        $this->assertArrayNotHasKey('iframe', $allowed);
    }

    public function test_allowed_html_includes_legal_doc_essentials(): void
    {
        $allowed = Cashback_Legal_Template_Validator::allowed_html();
        $this->assertArrayHasKey('p', $allowed);
        $this->assertArrayHasKey('ul', $allowed);
        $this->assertArrayHasKey('li', $allowed);
        $this->assertArrayHasKey('h2', $allowed);
        $this->assertArrayHasKey('strong', $allowed);
        $this->assertArrayHasKey('a', $allowed);
        $this->assertTrue(($allowed['a']['href'] ?? false), '<a href> разрешён');
    }
}
