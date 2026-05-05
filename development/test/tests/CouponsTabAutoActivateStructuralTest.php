<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тест на маркировку таба «Купоны» в apply_shortcodes_to_extra_tabs:
 * если content таба содержит [cashback_promocodes], в title таба
 * добавляется невидимый <span data-cb-coupons-tab="1" hidden></span>,
 * который ловится JS-активатором assets/js/cashback-coupons-tab.js
 * по query-параметру ?cb_tab=coupons.
 *
 * Структурные ассерты на JS-файл (без браузерного запуска).
 *
 * @group promocodes
 * @group coupons-icons
 */
#[Group('promocodes')]
#[Group('coupons-icons')]
final class CouponsTabAutoActivateStructuralTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
        $files = array(
            '/includes/promocodes/class-cashback-promocodes-bootstrap.php',
            '/includes/promocodes/class-cashback-promocodes-repository.php',
            '/includes/promocodes/class-cashback-promocodes-fetcher.php',
            '/includes/promocodes/class-coupons-adapter-registry.php',
        );
        foreach ($files as $f) {
            $path = self::$plugin_root . $f;
            if (file_exists($path)) {
                require_once $path;
            }
        }

        if (!function_exists('has_shortcode')) {
            function has_shortcode(string $content, string $tag): bool {
                return str_contains($content, '[' . $tag);
            }
        }
        if (!function_exists('do_shortcode')) {
            function do_shortcode(string $content): string {
                return $content;
            }
        }
    }

    public function test_marker_injected_when_content_has_promocodes_shortcode(): void
    {
        if (!class_exists('Cashback_Promocodes_Bootstrap')) {
            $this->markTestSkipped('Cashback_Promocodes_Bootstrap missing');
        }
        $tabs = array(
            'description' => array(
                'title'   => 'Описание',
                'content' => 'Простой текст без шорткодов',
            ),
            'coupons' => array(
                'title'   => 'Купоны',
                'content' => '[cashback_promocodes]',
            ),
        );

        $out = Cashback_Promocodes_Bootstrap::apply_shortcodes_to_extra_tabs($tabs);

        $this->assertStringNotContainsString('data-cb-coupons-tab', $out['description']['title'] ?? '');
        $this->assertStringContainsString('data-cb-coupons-tab', $out['coupons']['title'] ?? '');
    }

    public function test_marker_injection_is_idempotent(): void
    {
        if (!class_exists('Cashback_Promocodes_Bootstrap')) {
            $this->markTestSkipped('Cashback_Promocodes_Bootstrap missing');
        }
        $tabs = array(
            'coupons' => array(
                'title'   => 'Купоны',
                'content' => '[cashback_promocodes]',
            ),
        );

        $first  = Cashback_Promocodes_Bootstrap::apply_shortcodes_to_extra_tabs($tabs);
        $second = Cashback_Promocodes_Bootstrap::apply_shortcodes_to_extra_tabs($first);

        // Маркер не должен дублироваться при повторном вызове.
        $this->assertSame(
            1,
            substr_count((string) ($second['coupons']['title'] ?? ''), 'data-cb-coupons-tab')
        );
    }

    public function test_js_activator_file_exists_and_handles_cb_tab_param(): void
    {
        $js_file = self::$plugin_root . '/assets/js/cashback-coupons-tab.js';
        $this->assertFileExists($js_file);

        $source = (string) file_get_contents($js_file);

        $this->assertStringContainsString('cb_tab', $source, 'JS должен читать query-параметр cb_tab');
        $this->assertStringContainsString('data-cb-coupons-tab', $source, 'JS должен искать маркер в DOM');
        $this->assertStringContainsString('scrollIntoView', $source, 'JS должен скроллить к табам');
        $this->assertMatchesRegularExpression(
            '/click\(\)/',
            $source,
            'JS должен кликнуть по anchor таба (jQuery-делегат WC поймает native click)'
        );
    }
}
