<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('adapters')]
#[Group('advcake')]
#[Group('direct-product-link')]
final class AdvcakeDirectDeeplinkTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/includes/class-cashback-outbound-http-guard.php';
        require_once $root . '/includes/adapters/interface-cashback-network-adapter.php';
        require_once $root . '/includes/adapters/abstract-cashback-network-adapter.php';
        require_once $root . '/includes/adapters/class-cashback-advcake-adapter.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_http_calls']             = array();
        $GLOBALS['_cb_test_http_response_callback'] = null;
        Cashback_Outbound_HTTP_Guard::invalidate_cache();
    }

    public function test_dynamic_template_replaces_direct_link_and_tracking_subs(): void
    {
        $result = (new Cashback_Advcake_Adapter())->create_deeplink(
            array('api_key' => 'cakepass'),
            array('api_base_url' => 'https://api.advcake.ru'),
            'offer-42',
            'https://shop.advcake.example/product/sku-1?color=red',
            array('sub1' => 'directclick123', 'sub2' => 'partner-token'),
            'https://go.advcake.example/template?dl={dl}&sub1={sub1}&sub2={sub2}',
            true
        );

        self::assertSame(true, $result['success']);
        self::assertSame('dynamic_template', $result['link_type']);
        self::assertSame('https://go.advcake.example/template?dl=https%3A%2F%2Fshop.advcake.example%2Fproduct%2Fsku-1%3Fcolor%3Dred&sub1=directclick123&sub2=partner-token', $result['url']);
    }

    public function test_cakelink_success_and_allow_deep_false_fail_closed(): void
    {
        $GLOBALS['_cb_test_http_response'] = $this->http_response(200, '{"success":true,"url":"https:\/\/go.advcake.example\/cake\/abc"}');

        $cakelink = (new Cashback_Advcake_Adapter())->create_deeplink(
            array('api_key' => 'cakepass'),
            array('api_base_url' => 'https://api.advcake.ru', 'advcake_cakelink_enabled' => true),
            'offer-42',
            'https://shop.advcake.example/product/sku-1',
            array('sub1' => 'directclick123'),
            '',
            true
        );

        self::assertSame(true, $cakelink['success']);
        self::assertSame('cakelink', $cakelink['link_type']);
        self::assertSame('https://go.advcake.example/cake/abc', $cakelink['url']);
        self::assertStringContainsString('https://cakelink.ru/link?', $GLOBALS['_cb_test_http_calls'][0]['url']);
        self::assertStringContainsString('dl=https%3A%2F%2Fshop.advcake.example%2Fproduct%2Fsku-1', $GLOBALS['_cb_test_http_calls'][0]['url']);
        self::assertStringContainsString('sub1=directclick123', $GLOBALS['_cb_test_http_calls'][0]['url']);

        $disabled = (new Cashback_Advcake_Adapter())->create_deeplink(
            array('api_key' => 'cakepass'),
            array('api_base_url' => 'https://api.advcake.ru'),
            'offer-42',
            'https://shop.advcake.example/product/sku-1',
            array('sub1' => 'directclick123'),
            'https://go.advcake.example/template?dl={dl}&sub1={sub1}',
            false
        );

        self::assertSame(false, $disabled['success']);
        self::assertSame('advcake_deeplink_disabled', $disabled['reason_code']);
    }

    private function http_response(int $code, string $body): array
    {
        return array(
            'body'     => $body,
            'response' => array('code' => $code, 'message' => 'HTTP ' . $code),
            'headers'  => array(),
        );
    }
}
