<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты Cashback_Frontend_Performance — defer некритичных cashback CSS.
 *
 * Цели:
 *  1. Класс Cashback_Frontend_Performance существует и подключён в
 *     load_dependencies() (require_file в cashback-plugin.php).
 *  2. Метод init() public static, регистрирует style_loader_tag фильтр + wp_head action.
 *  3. defer_non_critical_css() для allowlisted handle превращает rel=stylesheet в
 *     rel=preload + as=style + onload-swap.
 *  4. defer_non_critical_css() добавляет <noscript>-fallback с rel=stylesheet.
 *  5. defer_non_critical_css() для не-allowlisted handle возвращает тег без изменений.
 *  6. defer_non_critical_css() идемпотентен — повторное применение к уже
 *     преобразованному тегу не ломает разметку.
 *  7. defer_non_critical_css() не падает на невалидном/неожиданном входном теге.
 *  8. inline_critical_cookies_banner_rule() выводит правило `.is-hidden { display:none }`.
 */
#[Group('performance')]
#[Group('frontend')]
class CashbackFrontendPerformanceTest extends TestCase
{
    private string $plugin_root;

    protected function setUp(): void
    {
        $this->plugin_root = dirname(__DIR__, 3);

        $file = $this->plugin_root . '/includes/class-cashback-frontend-performance.php';
        if (file_exists($file) && !class_exists('Cashback_Frontend_Performance')) {
            require_once $file;
        }
    }

    // ================================================================
    // Класс + метод init()
    // ================================================================

    public function test_class_exists(): void
    {
        self::assertTrue(
            class_exists('Cashback_Frontend_Performance'),
            'Cashback_Frontend_Performance class must exist'
        );
    }

    public function test_init_is_public_static(): void
    {
        self::assertTrue(class_exists('Cashback_Frontend_Performance'));
        self::assertTrue(method_exists('Cashback_Frontend_Performance', 'init'));

        $ref = new ReflectionMethod('Cashback_Frontend_Performance', 'init');
        self::assertTrue($ref->isPublic(), 'init() должен быть public');
        self::assertTrue($ref->isStatic(), 'init() должен быть static');
    }

    public function test_class_is_wired_into_plugin_load_dependencies(): void
    {
        $plugin_file = $this->plugin_root . '/cashback-plugin.php';
        self::assertFileExists($plugin_file);

        $content = (string) file_get_contents($plugin_file);
        self::assertStringContainsString(
            'class-cashback-frontend-performance.php',
            $content,
            'cashback-plugin.php должен подключать includes/class-cashback-frontend-performance.php'
        );
        self::assertStringContainsString(
            'Cashback_Frontend_Performance::init()',
            $content,
            'cashback-plugin.php должен вызывать Cashback_Frontend_Performance::init()'
        );
    }

    // ================================================================
    // defer_non_critical_css()
    // ================================================================

    public function test_defer_returns_unchanged_for_non_allowlisted_handle(): void
    {
        self::assertTrue(class_exists('Cashback_Frontend_Performance'));

        $tag    = "<link rel='stylesheet' id='some-other-handle-css' href='https://example.com/foo.css' media='all' />\n";
        $result = Cashback_Frontend_Performance::defer_non_critical_css($tag, 'some-other-handle');

        self::assertSame($tag, $result, 'Для handle вне allowlist тег должен остаться неизменным');
    }

    public function test_defer_transforms_cookies_banner_to_preload_swap(): void
    {
        self::assertTrue(class_exists('Cashback_Frontend_Performance'));

        $tag    = "<link rel='stylesheet' id='cashback-legal-cookies-banner-css' "
            . "href='https://example.com/wp-content/plugins/cash-back/assets/css/cookies-banner.css' media='all' />\n";
        $result = Cashback_Frontend_Performance::defer_non_critical_css($tag, 'cashback-legal-cookies-banner');

        self::assertStringContainsString('rel="preload"', $result, 'rel должен стать preload');
        self::assertStringContainsString('as="style"', $result, 'as=style должен присутствовать');
        self::assertStringContainsString('onload=', $result, 'onload-swap должен быть в теге');
        self::assertStringContainsString("this.rel='stylesheet'", $result, 'onload-swap возвращает rel в stylesheet');
        self::assertStringContainsString('cookies-banner.css', $result, 'href должен сохраниться');
    }

    public function test_defer_transforms_bot_protection_to_preload_swap(): void
    {
        self::assertTrue(class_exists('Cashback_Frontend_Performance'));

        $tag    = "<link rel='stylesheet' id='cashback-bot-protection-css' "
            . "href='https://example.com/wp-content/plugins/cash-back/assets/css/cashback-bot-protection.css' media='all' />\n";
        $result = Cashback_Frontend_Performance::defer_non_critical_css($tag, 'cashback-bot-protection');

        self::assertStringContainsString('rel="preload"', $result, 'rel должен стать preload');
        self::assertStringContainsString('as="style"', $result, 'as=style должен быть в теге');
        self::assertStringContainsString('onload=', $result, 'onload-swap должен быть');
        self::assertStringContainsString('cashback-bot-protection.css', $result);
    }

    public function test_defer_appends_noscript_fallback_with_stylesheet(): void
    {
        self::assertTrue(class_exists('Cashback_Frontend_Performance'));

        $tag    = "<link rel='stylesheet' id='cashback-legal-cookies-banner-css' "
            . "href='https://example.com/cookies-banner.css' media='all' />";
        $result = Cashback_Frontend_Performance::defer_non_critical_css($tag, 'cashback-legal-cookies-banner');

        self::assertStringContainsString('<noscript>', $result, '<noscript>-fallback должен присутствовать');
        self::assertStringContainsString('</noscript>', $result, '<noscript> должен быть закрыт');

        // Внутри <noscript> — исходный тег (rel='stylesheet'), для пользователей с JS off.
        $matches = array();
        preg_match('#<noscript>(.+?)</noscript>#s', $result, $matches);
        self::assertNotEmpty($matches, '<noscript>-блок должен быть найден');
        self::assertStringContainsString("rel='stylesheet'", (string) $matches[1], 'fallback в <noscript> = rel=stylesheet');
        self::assertStringNotContainsString('rel="preload"', (string) $matches[1], 'в <noscript> не должно быть rel="preload"');
    }

    public function test_defer_idempotent_on_already_preloaded_tag(): void
    {
        self::assertTrue(class_exists('Cashback_Frontend_Performance'));

        $tag = "<link rel='preload' as='style' onload=\"this.onload=null;this.rel='stylesheet'\" "
            . "id='cashback-legal-cookies-banner-css' href='https://example.com/cookies-banner.css' />";
        $result = Cashback_Frontend_Performance::defer_non_critical_css($tag, 'cashback-legal-cookies-banner');

        self::assertSame($tag, $result, 'Тег с уже rel=preload не должен переписываться повторно');
    }

    public function test_defer_safe_on_unexpected_tag(): void
    {
        self::assertTrue(class_exists('Cashback_Frontend_Performance'));

        // Неожиданный шаблон тега (без rel=stylesheet) — функция не должна падать
        // и должна вернуть исходный $tag.
        $weird   = '<!-- weird tag without rel attribute -->';
        $result  = Cashback_Frontend_Performance::defer_non_critical_css($weird, 'cashback-legal-cookies-banner');
        self::assertSame($weird, $result, 'На необычном теге функция возвращает вход без изменений');
    }

    // ================================================================
    // inline_critical_cookies_banner_rule()
    // ================================================================

    public function test_inline_critical_rule_outputs_is_hidden_display_none(): void
    {
        self::assertTrue(class_exists('Cashback_Frontend_Performance'));

        ob_start();
        Cashback_Frontend_Performance::inline_critical_cookies_banner_rule();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('<style', $output, 'Вывод должен содержать <style>');
        self::assertStringContainsString('.cashback-legal-cookies-banner.is-hidden', $output, 'Должен быть селектор is-hidden');
        self::assertStringContainsString('display:none', $output, 'Правило должно содержать display:none');
        self::assertStringContainsString('</style>', $output, '<style> должен быть закрыт');
    }
}
