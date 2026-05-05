<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты шорткода [cashback_display] (Cashback_Shortcodes::render_cashback_display).
 *
 * Метод используется в Woodmart Custom Loop Item Layout для отрисовки
 * кэшбэка отдельным HTML-узлом (не зависит от блока «Цена товара»).
 */
#[Group('shortcodes')]
#[Group('cashback-display')]
final class CashbackDisplayShortcodeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        // Mock'и WP-функций, нужные для render_cashback_display + render_cashback_html.
        // Все обёрнуты в function_exists, чтобы не конфликтовать с другими тестами,
        // которые могли определить те же mock'и.
        if (!function_exists('shortcode_atts')) {
            function shortcode_atts( array $pairs, array $atts, string $shortcode = '' ): array {
                return array_merge($pairs, is_array($atts) ? $atts : array());
            }
        }
        if (!function_exists('add_shortcode')) {
            function add_shortcode( string $tag, callable $callback ): bool {
                $GLOBALS['_cb_test_shortcodes'][ $tag ] = $callback;
                return true;
            }
        }
        if (!function_exists('shortcode_exists')) {
            function shortcode_exists( string $tag ): bool {
                return isset($GLOBALS['_cb_test_shortcodes'][ $tag ]);
            }
        }
        if (!function_exists('get_the_ID')) {
            function get_the_ID() {
                return $GLOBALS['_cb_test_get_the_id'] ?? 0;
            }
        }
        if (!function_exists('get_post_meta')) {
            // Совместимая сигнатура с PromocodesShortcodeTest (тот же $_cb_test_meta global).
            function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
                $store = $GLOBALS['_cb_test_meta'][ $post_id ] ?? array();
                if ('' === $key) {
                    return $store;
                }
                if (!isset($store[ $key ])) {
                    return $single ? '' : array();
                }
                return $single ? $store[ $key ] : array( $store[ $key ] );
            }
        }
        if (!function_exists('sanitize_html_class')) {
            function sanitize_html_class( string $class, string $fallback = '' ): string {
                $class = preg_replace('/[^A-Za-z0-9_-]/', '', $class) ?? '';
                return ('' !== $class) ? $class : $fallback;
            }
        }
        if (!function_exists('wc_get_page_permalink')) {
            function wc_get_page_permalink( string $page ): string {
                return '/test-' . $page;
            }
        }

        require_once $plugin_root . '/wc-affiliate-url-params.php';
        require_once $plugin_root . '/includes/class-cashback-shortcodes.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_shortcodes']  = array();
        $GLOBALS['_cb_test_meta']   = array();
        $GLOBALS['_cb_test_get_the_id']  = 0;

        // Сброс singleton, чтобы конструктор отработал заново и зарегистрировал шорткод.
        $ref      = new ReflectionClass(Cashback_Shortcodes::class);
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    public function test_render_cashback_display_method_is_public(): void
    {
        $ref = new ReflectionClass(Cashback_Shortcodes::class);

        $this->assertTrue(
            $ref->hasMethod('render_cashback_display'),
            'Cashback_Shortcodes должен иметь метод render_cashback_display'
        );

        $method = $ref->getMethod('render_cashback_display');
        $this->assertTrue($method->isPublic(), 'Метод должен быть public для вызова шорткода');
        $this->assertFalse($method->isStatic(), 'Метод вызывается на singleton-инстансе, не статически');
    }

    public function test_constructor_registers_cashback_display_shortcode(): void
    {
        // Структурная проверка: __construct содержит add_shortcode('cashback_display', ...).
        // Без зависимости от mock'ов add_shortcode из других тест-файлов.
        $plugin_root = dirname(__DIR__, 3);
        $source      = file_get_contents($plugin_root . '/includes/class-cashback-shortcodes.php');

        $this->assertNotFalse($source, 'Не удалось прочитать файл класса');
        $this->assertMatchesRegularExpression(
            "/add_shortcode\(\s*['\"]cashback_display['\"]\s*,\s*array\(\s*\\\$this\s*,\s*['\"]render_cashback_display['\"]\s*\)\s*\)/",
            (string) $source,
            'Регистрация [cashback_display] через add_shortcode($this, render_cashback_display) обязательна'
        );

        // Регрессия: старый шорткод [cashback_balance] не должен пропасть.
        $this->assertStringContainsString(
            "add_shortcode('cashback_balance'",
            (string) $source,
            'Регрессия: регистрация [cashback_balance] не должна быть удалена'
        );
    }

    public function test_render_returns_empty_when_no_meta_and_no_attrs(): void
    {
        // ID есть, но мета пустая, value-атрибут не задан — тихо возвращает ''.
        $shortcode = Cashback_Shortcodes::get_instance();
        $output    = $shortcode->render_cashback_display(array( 'id' => '123' ));

        $this->assertSame('', $output);
    }

    public function test_render_uses_meta_when_set(): void
    {
        $GLOBALS['_cb_test_meta'][123] = array(
            '_cashback_display_value' => 'до 7%',
            '_cashback_display_label' => 'Кэшбэк',
        );

        $shortcode = Cashback_Shortcodes::get_instance();
        $output    = $shortcode->render_cashback_display(array( 'id' => '123' ));

        $this->assertStringContainsString('class="cashback-display', $output);
        $this->assertStringContainsString('cashback-display--shortcode', $output);
        $this->assertStringContainsString('до 7%', $output);
        $this->assertStringContainsString('Кэшбэк', $output);
    }

    public function test_render_with_explicit_value_overrides_meta(): void
    {
        // В мете одно значение, но атрибут value=... должен победить.
        $GLOBALS['_cb_test_meta'][123] = array(
            '_cashback_display_value' => '5%',
            '_cashback_display_label' => 'Возврат',
        );

        $shortcode = Cashback_Shortcodes::get_instance();
        $output    = $shortcode->render_cashback_display(array(
            'id'    => '123',
            'value' => 'до 99%',
        ));

        $this->assertStringContainsString('до 99%', $output);
        $this->assertStringNotContainsString('>5%<', $output, 'Мета-значение не должно попадать в HTML при override');
        // Default label если не передан — «Кэшбэк», а не «Возврат» из меты.
        // Override-режим (value!=='') ИГНОРИРУЕТ post-meta полностью.
        $this->assertStringContainsString('Кэшбэк', $output);
    }

    public function test_render_uses_get_the_id_fallback_when_id_zero(): void
    {
        $GLOBALS['_cb_test_get_the_id']    = 555;
        $GLOBALS['_cb_test_meta'][555] = array(
            '_cashback_display_value' => '12%',
            '_cashback_display_label' => 'Кэшбэк',
        );

        $shortcode = Cashback_Shortcodes::get_instance();
        $output    = $shortcode->render_cashback_display(array());

        $this->assertStringContainsString('12%', $output);
    }

    public function test_render_returns_empty_when_no_id_and_no_post_context(): void
    {
        $GLOBALS['_cb_test_get_the_id'] = 0;

        $shortcode = Cashback_Shortcodes::get_instance();
        $output    = $shortcode->render_cashback_display(array());

        $this->assertSame('', $output);
    }

    public function test_label_attribute_overrides_meta_label(): void
    {
        $GLOBALS['_cb_test_meta'][123] = array(
            '_cashback_display_value' => 'до 5%',
            '_cashback_display_label' => 'Кэшбэк',
        );

        $shortcode = Cashback_Shortcodes::get_instance();
        $output    = $shortcode->render_cashback_display(array(
            'id'    => '123',
            'label' => 'Бонус',
        ));

        $this->assertStringContainsString('до 5%', $output);
        $this->assertStringContainsString('Бонус', $output);
        // Изначальный label из меты заменён.
        $this->assertStringNotContainsString('>Кэшбэк<', $output);
    }

    public function test_class_attribute_appends_extra_class(): void
    {
        $GLOBALS['_cb_test_meta'][123] = array(
            '_cashback_display_value' => 'до 5%',
        );

        $shortcode = Cashback_Shortcodes::get_instance();
        $output    = $shortcode->render_cashback_display(array(
            'id'    => '123',
            'class' => 'my-extra-class',
        ));

        $this->assertStringContainsString('my-extra-class', $output);
        $this->assertStringContainsString('cashback-display', $output);
    }
}
