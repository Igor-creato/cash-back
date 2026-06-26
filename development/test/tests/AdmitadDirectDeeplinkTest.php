<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('adapters')]
#[Group('admitad')]
#[Group('direct-product-link')]
final class AdmitadDirectDeeplinkTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/includes/class-cashback-outbound-http-guard.php';
        require_once $root . '/includes/oauth/class-oauth2-client-credentials-helper.php';
        require_once $root . '/includes/adapters/interface-cashback-network-adapter.php';
        require_once $root . '/includes/adapters/abstract-cashback-network-adapter.php';
        require_once $root . '/includes/adapters/class-admitad-adapter.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_http_calls']             = array();
        $GLOBALS['_cb_test_http_response_callback'] = null;
        $GLOBALS['_cb_test_transients']             = array();
        $GLOBALS['_cb_test_cache']                  = array();
        Cashback_Outbound_HTTP_Guard::invalidate_cache();
    }

    public function test_builds_product_deeplink_with_ulp_and_tracking_subids(): void
    {
        $this->queue_responses(array(
            $this->http_response(200, '{"is_affiliate_product":true,"deeplink":"https:\/\/ad.admitad.com\/g\/abc\/"}'),
        ));

        $result = $this->adapter()->create_deeplink(
            array(),
            array('api_base_url' => 'https://api.admitad.com', 'api_website_id' => '42', 'validate_links' => false),
            '29562',
            'https://www.aliexpress.ru/item/100500',
            array('subid' => 'directclick123', 'subid1' => 'directclick123')
        );

        self::assertSame(true, $result['success']);
        self::assertSame('https://ad.admitad.com/g/abc/', $result['url']);
        self::assertSame('https://api.admitad.com/deeplink/42/advcampaign/29562/?ulp=https%3A%2F%2Fwww.aliexpress.ru%2Fitem%2F100500&subid=directclick123&subid1=directclick123', $GLOBALS['_cb_test_http_calls'][0]['url']);
        self::assertSame('Bearer test-token', $GLOBALS['_cb_test_http_calls'][0]['args']['headers']['Authorization']);
    }

    public function test_non_affiliate_product_and_validate_error_fail_closed(): void
    {
        $this->queue_responses(array(
            $this->http_response(200, '{"is_affiliate_product":false,"deeplink":"https:\/\/ad.admitad.com\/g\/abc\/"}'),
        ));

        $not_affiliate = $this->adapter()->create_deeplink(
            array(),
            array('api_base_url' => 'https://api.admitad.com', 'api_website_id' => '42', 'validate_links' => false),
            '29562',
            'https://www.aliexpress.ru/item/100500',
            array('subid1' => 'directclick123')
        );
        self::assertSame(false, $not_affiliate['success']);
        self::assertSame('admitad_not_affiliate_product', $not_affiliate['reason_code']);

        $this->queue_responses(array(
            $this->http_response(200, '{"is_affiliate_product":true,"deeplink":"https:\/\/ad.admitad.com\/g\/abc\/"}'),
            $this->http_response(500, '{"error":"validate failed"}'),
        ));

        $validate_failed = $this->adapter()->create_deeplink(
            array(),
            array('api_base_url' => 'https://api.admitad.com', 'api_website_id' => '42', 'validate_links' => true),
            '29562',
            'https://www.aliexpress.ru/item/100500',
            array('subid1' => 'directclick123')
        );
        self::assertSame(false, $validate_failed['success']);
        self::assertSame('admitad_validate_failed', $validate_failed['reason_code']);
        self::assertStringContainsString('/validate_links/?link=', $GLOBALS['_cb_test_http_calls'][2]['url']);
        self::assertStringNotContainsString('links=', $GLOBALS['_cb_test_http_calls'][2]['url']);
    }

    private function adapter(): Cashback_Admitad_Adapter
    {
        return new class extends Cashback_Admitad_Adapter {
            public function build_auth_headers(array $credentials, array $network_config): ?array
            {
                unset($credentials, $network_config);
                return array('Authorization' => 'Bearer test-token');
            }
        };
    }

    private function http_response(int $code, string $body): array
    {
        return array(
            'body'     => $body,
            'response' => array('code' => $code, 'message' => 'HTTP ' . $code),
            'headers'  => array(),
        );
    }

    private function queue_responses(array $responses): void
    {
        $queue = $responses;
        $GLOBALS['_cb_test_http_response_callback'] = static function () use (&$queue): array {
            return array_shift($queue) ?? array(
                'body'     => '',
                'response' => array('code' => 500, 'message' => 'HTTP 500'),
                'headers'  => array(),
            );
        };
    }
}
