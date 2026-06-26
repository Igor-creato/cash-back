<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

if (!function_exists('shortcode_atts')) {
    function shortcode_atts(array $pairs, array $atts, string $shortcode = ''): array
    {
        unset($shortcode);
        return array_merge($pairs, $atts);
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $callback): bool
    {
        $GLOBALS['_cb_test_shortcodes'][ $tag ] = $callback;
        return true;
    }
}

#[Group('direct-product-link')]
final class DirectProductLinkUserFacingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_rest_routes']        = array();
        $GLOBALS['_cb_test_shortcodes']         = array();
        $GLOBALS['_cb_test_enqueued_scripts']   = array();
        $GLOBALS['_cb_test_localized_scripts']  = array();
    }

    public function test_rest_api_registers_user_direct_product_link_route(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/class-cashback-rest-api.php';

        Cashback_REST_API::get_instance()->register_routes();

        self::assertArrayHasKey('cashback/v1/product-link/resolve', $GLOBALS['_cb_test_rest_routes']);
        $route = $GLOBALS['_cb_test_rest_routes']['cashback/v1/product-link/resolve'];
        self::assertSame('POST', $route['args']['methods']);
        self::assertSame('direct_url', array_key_first($route['args']['args']));
        self::assertSame(true, $route['args']['args']['direct_url']['required']);
    }

    public function test_shortcode_renders_direct_link_form_and_localizes_rest_config(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/class-cashback-shortcodes.php';
        $ref = new ReflectionClass(Cashback_Shortcodes::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $shortcodes = Cashback_Shortcodes::get_instance();
        $html       = $shortcodes->render_product_link_form(array());

        self::assertStringContainsString(
            "add_shortcode('cashback_product_link_form'",
            file_get_contents(dirname(__DIR__, 3) . '/includes/class-cashback-shortcodes.php')
        );
        self::assertStringContainsString('data-cashback-product-link-form', $html);
        self::assertStringContainsString('name="direct_url"', $html);
        self::assertStringContainsString('Проверить кэшбэк', $html);
        self::assertStringContainsString('Кэшбэк не начисляется по этому товару', $html);
        self::assertArrayHasKey('cashback-product-link-form', $GLOBALS['_cb_test_enqueued_scripts']);
        self::assertSame(
            'https://savelloclub.test/wp-json/cashback/v1/product-link/resolve',
            $GLOBALS['_cb_test_localized_scripts']['cashback-product-link-form']['CashbackProductLinkForm']['endpoint']
        );

        $script = file_get_contents(dirname(__DIR__, 3) . '/assets/js/cashback-product-link-form.js');
        self::assertIsString($script);
        self::assertStringContainsString('Активировать кэшбэк', $script);
        self::assertStringContainsString('Перейти в магазин', $script);
        self::assertStringContainsString('Кэшбэк не начисляется по этому товару', $script);
    }
}
