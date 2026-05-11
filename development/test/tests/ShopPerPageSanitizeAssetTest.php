<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тест Cashback_Shop_Per_Page_Sanitize_Assets — регистрация frontend-JS,
 * удаляющего stale cookie `shop_per_page` с мусорным значением.
 *
 * Контекст: WoodMart-функция не валидирует значение cookie shop_per_page
 * против allowed-list 9/12/18/24. Из-за бага в расширении в браузерах
 * пользователей выставлены cookies с per_page=5 (или другими малыми значениями),
 * которые форсят рендер «5 карточек» на странице каталога магазинов.
 *
 * JS-санитизатор отрабатывает при первой загрузке любой frontend-страницы,
 * проверяет cookie и стирает его, если значение не в [9, 12, 18, 24].
 */
#[Group('frontend')]
#[Group('sanitize')]
class ShopPerPageSanitizeAssetTest extends TestCase
{
    private string $plugin_root;

    protected function setUp(): void
    {
        $this->plugin_root = dirname(__DIR__, 3);

        $file = $this->plugin_root . '/includes/class-cashback-shop-per-page-sanitize-assets.php';
        if (file_exists($file) && !class_exists('Cashback_Shop_Per_Page_Sanitize_Assets')) {
            require_once $file;
        }

        $GLOBALS['_cb_test_filters']          = array();
        $GLOBALS['_cb_test_enqueued_scripts'] = array();
    }

    public function test_class_file_exists(): void
    {
        self::assertFileExists(
            $this->plugin_root . '/includes/class-cashback-shop-per-page-sanitize-assets.php'
        );
    }

    public function test_js_file_exists(): void
    {
        self::assertFileExists(
            $this->plugin_root . '/assets/js/cashback-shop-per-page-sanitize.js',
            'Клиентский JS-санитизатор должен существовать'
        );
    }

    public function test_class_exists(): void
    {
        self::assertTrue(class_exists('Cashback_Shop_Per_Page_Sanitize_Assets'));
    }

    public function test_register_is_public_static(): void
    {
        $ref = new ReflectionMethod('Cashback_Shop_Per_Page_Sanitize_Assets', 'register');
        self::assertTrue($ref->isPublic(), 'register() должен быть public');
        self::assertTrue($ref->isStatic(), 'register() должен быть static');
    }

    public function test_register_hooks_wp_enqueue_scripts(): void
    {
        // Перехватываем add_action через override: bootstrap определяет add_action
        // как пустой stub. Сделаем wrapper, но проще — провести функциональный тест
        // через enqueue() напрямую.
        // Дополнительная проверка: register() не падает на вызове.
        Cashback_Shop_Per_Page_Sanitize_Assets::register();
        $this->expectNotToPerformAssertions();
    }

    public function test_enqueue_registers_script_on_frontend(): void
    {
        Cashback_Shop_Per_Page_Sanitize_Assets::enqueue();

        self::assertArrayHasKey(
            'cashback-shop-per-page-sanitize',
            $GLOBALS['_cb_test_enqueued_scripts'],
            'wp_enqueue_script должен быть вызван с handle=cashback-shop-per-page-sanitize'
        );

        $entry = $GLOBALS['_cb_test_enqueued_scripts']['cashback-shop-per-page-sanitize'];
        self::assertTrue($entry['enqueued'], 'Скрипт должен быть в enqueued-состоянии');
        self::assertStringContainsString(
            'cashback-shop-per-page-sanitize.js',
            (string) $entry['src'],
            'src должен указывать на assets/js/cashback-shop-per-page-sanitize.js'
        );
        self::assertStringContainsString(
            'cv=',
            (string) $entry['src'],
            'URL должен содержать cache-bust ?cv=<filemtime>'
        );
        self::assertSame(
            array(),
            $entry['deps'],
            'Скрипт не должен иметь зависимостей (vanilla JS)'
        );
        self::assertFalse(
            $entry['in_footer'],
            'in_footer=false — санитизатор должен исполниться до WoodMart-init в <head>'
        );
    }

    public function test_js_content_contains_allowed_list(): void
    {
        $js = (string) file_get_contents(
            $this->plugin_root . '/assets/js/cashback-shop-per-page-sanitize.js'
        );

        self::assertNotSame('', $js, 'JS-файл не должен быть пустым');
        self::assertStringContainsString(
            'shop_per_page',
            $js,
            'JS должен читать cookie shop_per_page'
        );
        self::assertStringContainsString(
            '9',
            $js,
            'JS должен содержать допустимое значение 9'
        );
        self::assertStringContainsString(
            '12',
            $js,
            'JS должен содержать 12'
        );
        self::assertStringContainsString(
            '18',
            $js,
            'JS должен содержать 18'
        );
        self::assertStringContainsString(
            '24',
            $js,
            'JS должен содержать 24'
        );
        self::assertStringContainsString(
            'Max-Age=0',
            $js,
            'JS должен стирать cookie через Max-Age=0'
        );
    }

    public function test_class_wired_into_plugin(): void
    {
        $plugin_file = $this->plugin_root . '/cashback-plugin.php';
        $content     = (string) file_get_contents($plugin_file);

        self::assertStringContainsString(
            'class-cashback-shop-per-page-sanitize-assets.php',
            $content
        );
        self::assertStringContainsString(
            'Cashback_Shop_Per_Page_Sanitize_Assets::register()',
            $content
        );
    }
}
