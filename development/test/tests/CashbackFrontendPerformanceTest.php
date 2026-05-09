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
    // defer_non_critical_js() — defer для allowlisted JS-handle'ов
    // ================================================================

    public function test_defer_js_returns_unchanged_for_non_allowlisted(): void
    {
        self::assertTrue(class_exists('Cashback_Frontend_Performance'));

        $tag    = "<script src='https://example.com/foo.js' id='woodmart-theme-js'></script>\n";
        $result = Cashback_Frontend_Performance::defer_non_critical_js($tag, 'woodmart-theme');

        self::assertSame($tag, $result, 'Не allowlisted handle не должен модифицироваться');
    }

    public function test_defer_js_adds_defer_to_cashback_handle(): void
    {
        self::assertTrue(class_exists('Cashback_Frontend_Performance'));

        $tag    = "<script src='https://example.com/cookies-banner.js' id='cashback-legal-cookies-banner-js'></script>\n";
        $result = Cashback_Frontend_Performance::defer_non_critical_js($tag, 'cashback-legal-cookies-banner');

        self::assertNotSame($tag, $result, 'Allowlisted handle должен быть модифицирован');
        self::assertStringContainsString('<script defer', $result, 'defer-атрибут должен быть добавлен');
        self::assertStringContainsString('cookies-banner.js', $result, 'href должен сохраниться');
    }

    public function test_defer_js_adds_defer_to_wc_analytics_handles(): void
    {
        self::assertTrue(class_exists('Cashback_Frontend_Performance'));

        foreach (array( 'sourcebuster-js', 'wc-order-attribution' ) as $handle) {
            $tag    = "<script src='https://example.com/{$handle}.js' id='{$handle}-js'></script>";
            $result = Cashback_Frontend_Performance::defer_non_critical_js($tag, $handle);
            self::assertStringContainsString(
                '<script defer',
                $result,
                "WC analytics handle '{$handle}' должен получить defer"
            );
        }
    }

    public function test_defer_js_skips_inline_scripts(): void
    {
        self::assertTrue(class_exists('Cashback_Frontend_Performance'));

        // Inline script (нет src=).
        $tag    = "<script id='cashback-legal-cookies-banner-js-extra'>var a = 1;</script>\n";
        $result = Cashback_Frontend_Performance::defer_non_critical_js($tag, 'cashback-legal-cookies-banner');

        self::assertSame($tag, $result, 'Inline-script не должен получать defer');
    }

    public function test_defer_js_skips_already_deferred(): void
    {
        self::assertTrue(class_exists('Cashback_Frontend_Performance'));

        $tag    = "<script defer src='https://example.com/foo.js' id='cashback-bot-protection-js'></script>";
        $result = Cashback_Frontend_Performance::defer_non_critical_js($tag, 'cashback-bot-protection');

        self::assertSame($tag, $result, 'Уже defer-нутый тег не должен модифицироваться повторно');
    }

    public function test_defer_js_skips_already_async(): void
    {
        self::assertTrue(class_exists('Cashback_Frontend_Performance'));

        $tag    = "<script async src='https://example.com/foo.js' id='cashback-bot-protection-js'></script>";
        $result = Cashback_Frontend_Performance::defer_non_critical_js($tag, 'cashback-bot-protection');

        self::assertSame($tag, $result, 'async-тег не должен переписываться в defer');
    }

    public function test_defer_js_filter_extends_allowlist(): void
    {
        self::assertTrue(class_exists('Cashback_Frontend_Performance'));

        // Без фильтра — handle не в списке, тег не меняется.
        $tag1    = "<script src='https://example.com/custom.js' id='my-custom-script-js'></script>";
        $result1 = Cashback_Frontend_Performance::defer_non_critical_js($tag1, 'my-custom-script');
        self::assertSame($tag1, $result1, 'До фильтра кастомный handle не в allowlist');

        // С фильтром — попадает в allowlist.
        $filter = static function ( array $handles ): array {
            $handles[] = 'my-custom-script';
            return $handles;
        };
        add_filter('cashback_defer_js_handles', $filter, 10, 1);

        $tag2    = "<script src='https://example.com/custom.js' id='my-custom-script-js'></script>";
        $result2 = Cashback_Frontend_Performance::defer_non_critical_js($tag2, 'my-custom-script');

        // Cleanup сразу, чтобы не утекало в другие тесты.
        remove_filter('cashback_defer_js_handles', $filter, 10);

        self::assertStringContainsString(
            '<script defer',
            $result2,
            'Handle добавленный через filter cashback_defer_js_handles должен получить defer'
        );
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
