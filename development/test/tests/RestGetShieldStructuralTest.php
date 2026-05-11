<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тест cashback_apply_rest_per_page_shield() — раннее снятие $_GET['per_page']
 * для REST-запросов, чтобы предотвратить утечку в WoodMart setcookie('shop_per_page').
 *
 * Контекст бага: WoodMart-функция на любой запрос с $_GET['per_page'] (включая
 * REST API) шлёт Set-Cookie: shop_per_page=<value>; path=/. Расширение делает
 * background-fetch /wp-json/cashback/v1/me/transactions?per_page=5 — браузер
 * получает cookie, оно живёт сессионно и заставляет storefront WoodMart
 * рендерить только 5 карточек на странице каталога магазинов.
 *
 * Фикс — на самом старте загрузки плагина снимаем $_GET['per_page'] для
 * REST-запросов. WP_REST_Request читает per_page из своих args (через
 * WP_REST_Server), не из $_GET — контракт REST не ломается.
 */
#[Group('rest')]
#[Group('shield')]
class RestGetShieldStructuralTest extends TestCase
{
    private string $plugin_root;

    protected function setUp(): void
    {
        $this->plugin_root = dirname(__DIR__, 3);

        $file = $this->plugin_root . '/includes/cashback-rest-per-page-shield.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }

    public function test_shield_file_exists(): void
    {
        self::assertFileExists(
            $this->plugin_root . '/includes/cashback-rest-per-page-shield.php',
            'includes/cashback-rest-per-page-shield.php должен существовать'
        );
    }

    public function test_shield_function_exists(): void
    {
        self::assertTrue(
            function_exists('cashback_apply_rest_per_page_shield'),
            'Функция cashback_apply_rest_per_page_shield() должна быть определена'
        );
    }

    public function test_shield_unsets_per_page_for_rest_request(): void
    {
        $get = array( 'per_page' => '5', 'page' => '1', 'foo' => 'bar' );
        cashback_apply_rest_per_page_shield('/wp-json/cashback/v1/me/transactions?per_page=5', $get);

        self::assertArrayNotHasKey(
            'per_page',
            $get,
            'На REST-запросе $_GET[per_page] должен быть снят, чтобы WoodMart не успел поставить cookie'
        );
        self::assertArrayHasKey('page', $get, 'Другие GET-ключи не должны быть тронуты');
        self::assertArrayHasKey('foo', $get, 'Другие GET-ключи не должны быть тронуты');
    }

    public function test_shield_unsets_per_page_for_any_rest_route(): void
    {
        // Не cashback-namespace — всё равно снимаем, чтобы не было гарантий
        // от поведения сторонних REST-эндпоинтов.
        $get = array( 'per_page' => '24' );
        cashback_apply_rest_per_page_shield('/wp-json/wp/v2/posts?per_page=24', $get);

        self::assertArrayNotHasKey(
            'per_page',
            $get,
            'Защита применяется к любому /wp-json/* route, не только cashback/v1'
        );
    }

    public function test_shield_leaves_per_page_for_frontend_shop(): void
    {
        $get = array( 'per_page' => '24' );
        cashback_apply_rest_per_page_shield('/shop/?per_page=24', $get);

        self::assertArrayHasKey(
            'per_page',
            $get,
            'На frontend-shop легитимный per_page=24 должен сохраниться (WoodMart тулбар)'
        );
        self::assertSame('24', $get['per_page']);
    }

    public function test_shield_leaves_per_page_for_homepage(): void
    {
        $get = array( 'per_page' => '12' );
        cashback_apply_rest_per_page_shield('/', $get);

        self::assertArrayHasKey('per_page', $get);
        self::assertSame('12', $get['per_page']);
    }

    public function test_shield_safe_on_null_request_uri(): void
    {
        $get = array( 'per_page' => '5' );
        cashback_apply_rest_per_page_shield(null, $get);

        // Когда REQUEST_URI неизвестен — безопасный default (frontend), не снимаем.
        self::assertArrayHasKey('per_page', $get);
    }

    public function test_shield_safe_on_empty_get(): void
    {
        $get = array();
        cashback_apply_rest_per_page_shield('/wp-json/cashback/v1/me/transactions', $get);

        self::assertSame(array(), $get, 'Пустой $_GET должен остаться пустым');
    }

    public function test_shield_idempotent(): void
    {
        $get = array( 'per_page' => '5' );
        cashback_apply_rest_per_page_shield('/wp-json/cashback/v1/me/transactions', $get);
        cashback_apply_rest_per_page_shield('/wp-json/cashback/v1/me/transactions', $get);

        self::assertArrayNotHasKey('per_page', $get);
    }

    public function test_shield_handles_wp_json_anywhere_in_uri(): void
    {
        // Хотя WP REST по умолчанию монтируется на /wp-json/, в тестовом
        // окружении может быть subdir-инсталляция: /sub/wp-json/...
        $get = array( 'per_page' => '5' );
        cashback_apply_rest_per_page_shield('/sub/wp-json/cashback/v1/stores', $get);

        self::assertArrayNotHasKey('per_page', $get);
    }

    public function test_plugin_file_invokes_shield_early(): void
    {
        $plugin_file = $this->plugin_root . '/cashback-plugin.php';
        self::assertFileExists($plugin_file);

        $content = (string) file_get_contents($plugin_file);

        self::assertStringContainsString(
            'cashback-rest-per-page-shield.php',
            $content,
            'cashback-plugin.php должен подключать includes/cashback-rest-per-page-shield.php'
        );
        self::assertStringContainsString(
            'cashback_apply_rest_per_page_shield',
            $content,
            'cashback-plugin.php должен вызывать cashback_apply_rest_per_page_shield() на старте загрузки'
        );
    }
}
