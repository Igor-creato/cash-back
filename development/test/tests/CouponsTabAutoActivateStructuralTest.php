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
        if (!function_exists('wp_unslash')) {
            function wp_unslash(mixed $value): mixed {
                return is_string($value) ? stripslashes($value) : $value;
            }
        }
        if (!function_exists('sanitize_html_class')) {
            function sanitize_html_class(string $class, string $fallback = ''): string {
                $sanitized = preg_replace('/[^A-Za-z0-9_\-]/', '', $class);
                return $sanitized === '' ? $fallback : $sanitized;
            }
        }
    }

    protected function tearDown(): void
    {
        unset($_GET['cb_tab']);
        parent::tearDown();
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

    public function test_js_uses_instant_scroll_not_smooth(): void
    {
        $js_file = self::$plugin_root . '/assets/js/cashback-coupons-tab.js';
        $source  = (string) file_get_contents($js_file);

        // Скролл — мгновенный (behavior:'auto'), не плавный. Пользователь
        // только что загрузил страницу, smooth-анимация добавляет видимую задержку.
        $this->assertMatchesRegularExpression(
            "/behavior:\\s*['\"]auto['\"]/",
            $source,
            "Скролл должен быть мгновенным (behavior:'auto')"
        );
        $this->assertDoesNotMatchRegularExpression(
            "/behavior:\\s*['\"]smooth['\"]/",
            $source,
            "Плавный скролл (behavior:'smooth') убран — он добавлял визуальную задержку"
        );
    }

    public function test_js_no_legacy_retry_timeouts(): void
    {
        $js_file = self::$plugin_root . '/assets/js/cashback-coupons-tab.js';
        $source  = (string) file_get_contents($js_file);

        // Legacy retry-задержки 700/1100/1800 ms убраны. Они компенсировали
        // WoodMart's reset на $(document).ready — теперь корректный таб
        // выставляется сервером (priority=-100) и подстрахован MutationObserver.
        $this->assertDoesNotMatchRegularExpression(
            '/setTimeout\([^,]+,\s*700\s*\)/',
            $source,
            'setTimeout(700ms) удалён'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/setTimeout\([^,]+,\s*1100\s*\)/',
            $source,
            'setTimeout(1100ms) удалён'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/setTimeout\([^,]+,\s*1800\s*\)/',
            $source,
            'setTimeout(1800ms) удалён'
        );
    }

    public function test_js_uses_mutation_observer_safety_net(): void
    {
        $js_file = self::$plugin_root . '/assets/js/cashback-coupons-tab.js';
        $source  = (string) file_get_contents($js_file);

        $this->assertStringContainsString(
            'MutationObserver',
            $source,
            'JS должен использовать MutationObserver как safety-net вместо setTimeout-ретраев'
        );
        $this->assertMatchesRegularExpression(
            '/observer\.disconnect\(\)/',
            $source,
            'Observer должен disconnect после первой успешной активации (idempotent)'
        );
        // Должен остаться ровно один setTimeout — hard-disconnect через 3000ms.
        $this->assertSame(
            1,
            preg_match_all('/setTimeout\(/', $source) ?: 0,
            'Должен остаться ровно один setTimeout (hard-disconnect 3000ms)'
        );
    }

    public function test_priority_override_when_cb_tab_coupons_present(): void
    {
        if (!class_exists('Cashback_Promocodes_Bootstrap')) {
            $this->markTestSkipped('Cashback_Promocodes_Bootstrap missing');
        }
        $_GET['cb_tab'] = 'coupons';

        $tabs = array(
            'description' => array(
                'title'    => 'Описание',
                'content'  => 'Простой текст',
                'priority' => 10,
            ),
            'coupons' => array(
                'title'    => 'Купоны',
                'content'  => '[cashback_promocodes]',
                'priority' => 50,
            ),
        );

        $out = Cashback_Promocodes_Bootstrap::apply_shortcodes_to_extra_tabs($tabs);

        $this->assertSame(
            -100,
            $out['coupons']['priority'] ?? null,
            'Таб с шорткодом [cashback_promocodes] должен получить priority=-100 при ?cb_tab=coupons'
        );
        $this->assertSame(
            10,
            $out['description']['priority'] ?? null,
            'Чужие табы priority не трогаем'
        );
    }

    public function test_priority_not_overridden_when_cb_tab_absent(): void
    {
        if (!class_exists('Cashback_Promocodes_Bootstrap')) {
            $this->markTestSkipped('Cashback_Promocodes_Bootstrap missing');
        }
        unset($_GET['cb_tab']);

        $tabs = array(
            'coupons' => array(
                'title'    => 'Купоны',
                'content'  => '[cashback_promocodes]',
                'priority' => 50,
            ),
        );

        $out = Cashback_Promocodes_Bootstrap::apply_shortcodes_to_extra_tabs($tabs);

        $this->assertSame(
            50,
            $out['coupons']['priority'] ?? null,
            'Без ?cb_tab=coupons priority оставляем как есть (поведение по умолчанию)'
        );
    }

    public function test_priority_not_overridden_for_invalid_cb_tab(): void
    {
        if (!class_exists('Cashback_Promocodes_Bootstrap')) {
            $this->markTestSkipped('Cashback_Promocodes_Bootstrap missing');
        }
        $_GET['cb_tab'] = 'description'; // не coupons

        $tabs = array(
            'coupons' => array(
                'title'    => 'Купоны',
                'content'  => '[cashback_promocodes]',
                'priority' => 50,
            ),
        );

        $out = Cashback_Promocodes_Bootstrap::apply_shortcodes_to_extra_tabs($tabs);

        $this->assertSame(
            50,
            $out['coupons']['priority'] ?? null,
            'При ?cb_tab≠coupons priority остаётся неизменным'
        );
    }

    public function test_priority_override_only_for_promocodes_tab(): void
    {
        if (!class_exists('Cashback_Promocodes_Bootstrap')) {
            $this->markTestSkipped('Cashback_Promocodes_Bootstrap missing');
        }
        $_GET['cb_tab'] = 'coupons';

        $tabs = array(
            'random' => array(
                'title'    => 'Random',
                'content'  => 'Просто текст',
                'priority' => 20,
            ),
        );

        $out = Cashback_Promocodes_Bootstrap::apply_shortcodes_to_extra_tabs($tabs);

        $this->assertSame(
            20,
            $out['random']['priority'] ?? null,
            'Таб без [cashback_promocodes] priority не меняем'
        );
    }
}
