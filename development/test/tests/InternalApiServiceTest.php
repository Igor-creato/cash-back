<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

#[Group('internal-rest-api')]
final class InternalApiServiceTest extends TestCase
{
    private Internal_Api_Wpdb_Stub $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/includes/class-cashback-shop-options.php';
        require_once dirname(__DIR__, 3) . '/includes/shops/class-cashback-shop-tariff-sync.php';
        require_once dirname(__DIR__, 3) . '/includes/services/class-cashback-internal-api-service.php';

        $GLOBALS['_cb_test_options'] = array(
            'cashback_guest_display_rate' => 70,
        );
        $GLOBALS['_cb_test_post_meta'] = array();
        $GLOBALS['_cb_test_internal_include_advcake'] = false;
        $GLOBALS['_cb_test_posts']     = array(
            101 => (object) array( 'ID' => 101, 'post_status' => 'publish', 'post_type' => 'product', 'post_title' => 'AliExpress' ),
            102 => (object) array( 'ID' => 102, 'post_status' => 'draft', 'post_type' => 'product', 'post_title' => 'Paused Shop' ),
        );

        update_post_meta(101, '_affiliate_network_id', '1');
        update_post_meta(101, '_offer_id', '29562');
        update_post_meta(101, '_store_domain', 'https://www.aliexpress.ru/path');
        update_post_meta(101, '_cashback_campaign_currency', 'RUB');
        update_post_meta(101, '_cashback_campaign_status_raw', 'active');
        update_post_meta(101, '_product_url', 'https://go.example/ali');

        update_post_meta(102, '_affiliate_network_id', '1');
        update_post_meta(102, '_offer_id', '999');
        update_post_meta(102, '_store_domain', 'paused.example');
        update_post_meta(102, '_cashback_campaign_currency', 'RUB');
        update_post_meta(102, '_cashback_campaign_status_raw', 'paused');
        update_post_meta(102, '_product_url', 'https://paused.example/go');

        foreach (array(
            103 => array('advcake.example', 'offer-42'),
            108 => array('zero-range.example', 'zero-range'),
            109 => array('manual-lock.example', 'manual-lock'),
            110 => array('manual-unlocked.example', 'manual-unlocked'),
            111 => array('legacy-rate.example', 'no-tariffs'),
            112 => array('empty-rate.example', 'empty-rate'),
        ) as $product_id => $fixture) {
            update_post_meta($product_id, '_affiliate_network_id', '2');
            update_post_meta($product_id, '_offer_id', $fixture[1]);
            update_post_meta($product_id, '_store_domain', $fixture[0]);
            update_post_meta($product_id, '_cashback_campaign_currency', 'RUB');
            update_post_meta($product_id, '_cashback_campaign_status_raw', 'active');
            update_post_meta($product_id, '_product_url', 'https://go.advcake.example/template?dl={dl}&sub1={sub1}&sub2={sub2}');
        }

        $this->wpdb = new Internal_Api_Wpdb_Stub();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function test_merchants_default_to_active_and_status_all_includes_disabled(): void
    {
        $service = new Savello_Cashback_Internal_API_Service();

        $active = $service->get_merchants(array());
        self::assertCount(1, $active['items']);
        self::assertSame('101', $active['items'][0]['merchant_id']);
        self::assertSame(array('aliexpress.ru'), $active['items'][0]['domains']);
        self::assertIsArray($active['items'][0]['domain_aliases']);

        $all = $service->get_merchants(array( 'status' => 'all', 'limit' => 999 ));
        self::assertCount(2, $all['items']);
        self::assertSame(500, $all['pagination']['limit']);
    }

    public function test_rates_return_exact_range_min_zero_and_unknown_merchant_404(): void
    {
        $service = new Savello_Cashback_Internal_API_Service();
        $rates   = $service->get_merchant_rates('101');

        self::assertCount(3, $rates['rates']);
        self::assertSame(6.92, $rates['rates'][0]['commission_exact']);
        self::assertSame('show_exact_rate', $rates['rates'][0]['display_policy']);
        self::assertSame(0.38, $rates['rates'][1]['commission_min']);
        self::assertSame('show_range_use_min_for_effective_price', $rates['rates'][1]['display_policy']);
        self::assertSame(0.0, $rates['rates'][2]['commission_min']);
        self::assertSame('show_possible_do_not_reduce_effective_price', $rates['rates'][2]['display_policy']);

        $missing = $service->get_merchant_rates('404');
        self::assertInstanceOf(WP_Error::class, $missing);
        self::assertSame('savello_internal_merchant_not_found', $missing->get_error_code());
    }

    public function test_resolve_product_calculates_no_partner_exact_range_and_min_zero(): void
    {
        $service = new Savello_Cashback_Internal_API_Service();

        $no_partner = $service->resolve_product_cashback(array(
            'url'      => 'https://unknown.example/item',
            'price'    => 10000,
            'currency' => 'RUB',
        ));
        self::assertSame('no_partner', $no_partner['cashback_status']);
        self::assertFalse($no_partner['cashback_available']);

        $exact = $service->resolve_product_cashback(array(
            'url'         => 'https://aliexpress.ru/product/123',
            'price'       => 10000,
            'currency'    => 'RUB',
            'category_id' => 'exact',
        ));
        self::assertSame('partner_exact', $exact['cashback_status']);
        self::assertSame(0.7, $exact['user_share']);
        self::assertSame(4.844, $exact['user_cashback_exact_rate']);
        self::assertSame(484.4, $exact['expected_cashback_exact']);
        self::assertSame(9515.6, $exact['effective_price']);

        $range = $service->resolve_product_cashback(array(
            'url'         => 'https://aliexpress.ru/product/123',
            'price'       => 10000,
            'currency'    => 'RUB',
            'category_id' => 'range',
        ));
        self::assertSame('partner_estimated', $range['cashback_status']);
        self::assertSame(26.6, $range['expected_cashback_min']);
        self::assertSame(484.4, $range['expected_cashback_max']);
        self::assertSame(9973.4, $range['effective_price_conservative']);

        $zero = $service->resolve_product_cashback(array(
            'url'         => 'https://aliexpress.ru/product/123',
            'price'       => 10000,
            'currency'    => 'RUB',
            'category_id' => 'zero',
        ));
        self::assertSame(0.0, $zero['expected_cashback_min']);
        self::assertSame(10000.0, $zero['effective_price_conservative']);
        self::assertSame('show_possible_do_not_reduce_effective_price', $zero['display_policy']);
    }

    public function test_resolve_product_rejects_invalid_price_and_currency(): void
    {
        $service = new Savello_Cashback_Internal_API_Service();
        $result  = $service->resolve_product_cashback(array(
            'url'      => 'https://aliexpress.ru/product/123',
            'price'    => -1,
            'currency' => 'rubles',
        ));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('savello_internal_bad_request', $result->get_error_code());
    }

    public function test_deeplink_validates_target_and_sanitizes_subid(): void
    {
        $service = new Savello_Cashback_Internal_API_Service();

        $created = $service->create_deeplink(array(
            'merchant_id' => '101',
            'target_url'  => 'https://aliexpress.ru/product/123',
            'click_id'    => '<b>click_123</b>',
            'subid'       => '<script>x</script>sub',
        ));
        self::assertSame('101', $created['merchant_id']);
        self::assertSame('click_123', $created['click_id']);
        self::assertStringContainsString('cashback_internal_click=click_123', $created['cashback_url']);

        $invalid = $service->create_deeplink(array(
            'merchant_id' => '101',
            'target_url'  => 'javascript:alert(1)',
        ));
        self::assertInstanceOf(WP_Error::class, $invalid);

        $missing = $service->create_deeplink(array(
            'merchant_id' => '404',
            'target_url'  => 'https://example.test',
        ));
        self::assertSame('savello_internal_merchant_not_found', $missing->get_error_code());
    }

    public function test_direct_product_link_returns_unified_cashback_and_fallback_payloads(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php';
        $GLOBALS['_cb_test_internal_include_advcake'] = true;

        $service = new Savello_Cashback_Internal_API_Service();

        $cashback = $service->resolve_direct_product_link(array(
            'direct_url' => 'https://shop.advcake.example/product/sku-1?color=red',
            'source'     => 'microservice',
            'user_id'    => 77,
            'click_id'   => 'directclick123',
        ));

        self::assertSame(true, $cashback['cashback_available']);
        self::assertSame('Активировать кэшбэк', $cashback['button_text']);
        self::assertSame('Advcake Store', $cashback['merchant']);
        self::assertSame('advcake', $cashback['network']);
        self::assertSame('directclick123', $cashback['click_id']);
        self::assertStringContainsString('dl=https%3A%2F%2Fshop.advcake.example%2Fproduct%2Fsku-1%3Fcolor%3Dred', $cashback['url']);
        self::assertStringContainsString('sub1=directclick123', $cashback['url']);
        self::assertSame(1, $this->wpdb->insert_count, 'cashback link must reuse click-session logging.');

        $unknown = $service->resolve_direct_product_link(array(
            'direct_url' => 'https://unknown.example/product/1',
            'source'     => 'user',
            'user_id'    => 77,
        ));

        self::assertSame(false, $unknown['cashback_available']);
        self::assertSame('Перейти в магазин', $unknown['button_text']);
        self::assertSame('https://unknown.example/product/1', $unknown['url']);
        self::assertSame('Кэшбэк не начисляется по этому товару', $unknown['warning']);
        self::assertSame('merchant_not_found', $unknown['reason_code']);

        $inactive = $service->resolve_direct_product_link(array(
            'direct_url' => 'https://paused.example/product/1',
            'source'     => 'user',
            'user_id'    => 77,
        ));
        self::assertSame(false, $inactive['cashback_available']);
        self::assertSame('merchant_inactive', $inactive['reason_code']);

        $disabled = $service->resolve_direct_product_link(array(
            'direct_url' => 'https://disabled-cashback.example/product/1',
            'source'     => 'user',
            'user_id'    => 77,
        ));
        self::assertSame(false, $disabled['cashback_available']);
        self::assertSame('cashback_disabled', $disabled['reason_code']);

        $unsupported = $service->resolve_direct_product_link(array(
            'direct_url' => 'https://no-deeplink.example/product/1',
            'source'     => 'user',
            'user_id'    => 77,
        ));
        self::assertSame(false, $unsupported['cashback_available']);
        self::assertSame('deeplink_not_supported', $unsupported['reason_code']);

        $unsafe = $service->resolve_direct_product_link(array(
            'direct_url' => 'javascript:alert(1)',
            'source'     => 'user',
        ));
        self::assertInstanceOf(WP_Error::class, $unsafe);
        self::assertSame('savello_internal_bad_request', $unsafe->get_error_code());
    }

    public function test_direct_product_link_cashback_rate_uses_user_display_calculator_for_zero_ranges(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php';
        $GLOBALS['_cb_test_internal_include_advcake']    = true;
        $GLOBALS['_cb_test_internal_include_rate_cases'] = true;

        $service = new Savello_Cashback_Internal_API_Service();

        $cashback = $service->resolve_direct_product_link(array(
            'direct_url' => 'https://zero-range.example/catalog/sku-1',
            'source'     => 'user',
            'user_id'    => 77,
            'click_id'   => 'zerorange123',
        ));

        self::assertSame(true, $cashback['cashback_available']);
        self::assertSame('4.5%', $cashback['cashback_rate']);
        self::assertNotSame('0-0%', $cashback['cashback_rate']);
    }

    public function test_direct_product_link_cashback_rate_respects_manual_override_only_when_locked(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php';
        $GLOBALS['_cb_test_internal_include_advcake']    = true;
        $GLOBALS['_cb_test_internal_include_rate_cases'] = true;

        update_post_meta(109, '_rate_locked', '1');
        update_post_meta(109, '_manual_advertiser_rate', '9.99%');
        update_post_meta(110, '_manual_advertiser_rate', '88%');

        $service = new Savello_Cashback_Internal_API_Service();

        $locked = $service->resolve_direct_product_link(array(
            'direct_url' => 'https://manual-lock.example/catalog/sku-1',
            'source'     => 'user',
            'user_id'    => 77,
            'click_id'   => 'manualock123',
        ));

        $unlocked = $service->resolve_direct_product_link(array(
            'direct_url' => 'https://manual-unlocked.example/catalog/sku-1',
            'source'     => 'user',
            'user_id'    => 77,
            'click_id'   => 'manualopen123',
        ));

        self::assertSame('9.99%', $locked['cashback_rate']);
        self::assertSame('6.5%', $unlocked['cashback_rate']);
    }

    public function test_direct_product_link_cashback_rate_uses_legacy_fallback_and_null_when_missing(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php';
        $GLOBALS['_cb_test_internal_include_advcake']    = true;
        $GLOBALS['_cb_test_internal_include_rate_cases'] = true;

        update_post_meta(111, '_cashback_display_value', 'до 5%');

        $service = new Savello_Cashback_Internal_API_Service();

        $legacy = $service->resolve_direct_product_link(array(
            'direct_url' => 'https://legacy-rate.example/catalog/sku-1',
            'source'     => 'user',
            'user_id'    => 77,
            'click_id'   => 'legacyrate123',
        ));

        $missing = $service->resolve_direct_product_link(array(
            'direct_url' => 'https://empty-rate.example/catalog/sku-1',
            'source'     => 'user',
            'user_id'    => 77,
            'click_id'   => 'emptyrate123',
        ));

        self::assertSame('до 5%', $legacy['cashback_rate']);
        self::assertNull($missing['cashback_rate']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[Group('direct-product-link')]
    public function test_direct_product_link_generates_server_observed_admitad_alias_and_advcake_cakelink_urls(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/oauth/class-oauth2-client-credentials-helper.php';
        require_once dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php';

        if (!class_exists('Cashback_API_Client', false)) {
            eval('class Cashback_API_Client {
                public static function get_instance(): self { static $i = null; if ($i === null) { $i = new self(); } return $i; }
                public function get_network_config(string $slug): ?array {
                    if ($slug === "adm" || $slug === "admitad") {
                        return array(
                            "id" => 1,
                            "slug" => "adm",
                            "api_base_url" => "https://api.admitad.com",
                            "api_token_endpoint" => "/token/",
                            "api_website_id" => "2082764",
                            "credentials" => array("client_id" => "admitad-client", "client_secret" => "admitad-secret"),
                        );
                    }
                    if ($slug === "advcake") {
                        return array(
                            "id" => 2,
                            "slug" => "advcake",
                            "api_base_url" => "https://api.advcake.ru",
                            "credentials" => array("api_key" => "cakepass"),
                        );
                    }
                    return null;
                }
            }');
        }

        $GLOBALS['_cb_test_internal_include_server_direct_cases'] = true;
        $GLOBALS['_cb_test_http_calls'] = array();
        $GLOBALS['_cb_test_http_response_callback'] = static function (string $url): array {
            if (str_contains($url, '/token/')) {
                return array(
                    'body'     => '{"access_token":"admitad-token","expires_in":3600}',
                    'response' => array('code' => 200, 'message' => 'OK'),
                    'headers'  => array(),
                );
            }

            if (str_contains($url, '/deeplink/2082764/advcampaign/30582/')) {
                return array(
                    'body'     => '{"is_affiliate_product":true,"link":"https:\/\/ad.admitad.com\/g\/ibox-generated\/"}',
                    'response' => array('code' => 200, 'message' => 'OK'),
                    'headers'  => array(),
                );
            }

            if (str_contains($url, 'https://cakelink.ru/link?')) {
                return array(
                    'body'     => '{"success":true,"data":{"url":"https:\/\/go.redav.online\/generated-mnogomebeli"}}',
                    'response' => array('code' => 200, 'message' => 'OK'),
                    'headers'  => array(),
                );
            }

            return array(
                'body'     => '{}',
                'response' => array('code' => 500, 'message' => 'Unexpected'),
                'headers'  => array(),
            );
        };

        $service = new Savello_Cashback_Internal_API_Service();

        $ibox = $service->resolve_direct_product_link(array(
            'direct_url' => 'https://iboxstore.ru/catalog/kombo-ustroystva/ibox-icon-2',
            'source'     => 'user',
            'user_id'    => 77,
            'click_id'   => 'iboxclick123',
        ));

        self::assertSame(true, $ibox['cashback_available']);
        self::assertSame('https://ad.admitad.com/g/ibox-generated/', $ibox['cashback_url']);
        self::assertSame('iBOX', $ibox['merchant']);
        self::assertSame('iboxclick123', $ibox['click_id']);

        $GLOBALS['_cb_test_http_response_callback'] = static function (string $url): array {
            if (str_contains($url, '/token/')) {
                return array(
                    'body'     => '{"access_token":"admitad-token","expires_in":3600}',
                    'response' => array('code' => 200, 'message' => 'OK'),
                    'headers'  => array(),
                );
            }

            if (str_contains($url, '/deeplink/2082764/advcampaign/30582/')) {
                return array(
                    'body'     => '{"error":"insufficient_scope","error_description":"Access token has insufficient scope: deeplink_generator","status_code":"403"}',
                    'response' => array('code' => 403, 'message' => 'Forbidden'),
                    'headers'  => array(),
                );
            }

            if (str_contains($url, 'https://cakelink.ru/link?')) {
                return array(
                    'body'     => '{"success":true,"data":{"url":"https:\/\/go.redav.online\/generated-mnogomebeli"}}',
                    'response' => array('code' => 200, 'message' => 'OK'),
                    'headers'  => array(),
                );
            }

            return array(
                'body'     => '{}',
                'response' => array('code' => 500, 'message' => 'Unexpected'),
                'headers'  => array(),
            );
        };

        $ibox_scope_fallback = $service->resolve_direct_product_link(array(
            'direct_url' => 'https://iboxstore.ru/catalog/kombo-ustroystva/ibox-icon-2',
            'source'     => 'user',
            'user_id'    => 77,
            'click_id'   => 'iboxscope123',
        ));

        self::assertSame(true, $ibox_scope_fallback['cashback_available']);
        self::assertStringStartsWith('https://codeaven.com/g/4hh84nh1h6998b33a895e6b606b04d/', $ibox_scope_fallback['cashback_url']);
        self::assertStringContainsString('ulp=https%3A%2F%2Fiboxstore.ru%2Fcatalog%2Fkombo-ustroystva%2Fibox-icon-2', $ibox_scope_fallback['cashback_url']);
        self::assertStringContainsString('subid1=iboxscope123', $ibox_scope_fallback['cashback_url']);

        $mnogomebeli = $service->resolve_direct_product_link(array(
            'direct_url' => 'https://mnogomebeli.com/komody/komod-lux/!komod-lux-belyy-sneg/',
            'source'     => 'user',
            'user_id'    => 77,
            'click_id'   => 'cakeclick123',
        ));

        self::assertSame(true, $mnogomebeli['cashback_available']);
        self::assertSame('https://go.redav.online/generated-mnogomebeli', $mnogomebeli['cashback_url']);
        self::assertSame('mnogomebeli.com', $mnogomebeli['merchant']);

        $cakelink_call = array_values(array_filter(
            $GLOBALS['_cb_test_http_calls'],
            static fn(array $call): bool => str_contains((string) $call['url'], 'https://cakelink.ru/link?')
        ))[0] ?? null;
        self::assertIsArray($cakelink_call);
        self::assertStringContainsString('dl=https%3A%2F%2Fmnogomebeli.com%2Fkomody%2Fkomod-lux%2F%21komod-lux-belyy-sneg%2F', $cakelink_call['url']);
        self::assertStringContainsString('sub1=cakeclick123', $cakelink_call['url']);
    }

    public function test_user_limits_return_only_limits_and_cashback_rules(): void
    {
        $service = new Savello_Cashback_Internal_API_Service();

        $limits = $service->get_user_price_monitor_limits('wp:savelloclub.test:77');
        self::assertSame('wp:savelloclub.test:77', $limits['external_user_id']);
        self::assertSame(0.65, $limits['cashback']['user_share']);
        self::assertArrayNotHasKey('email', $limits);
        self::assertArrayNotHasKey('balance', $limits);

        $missing = $service->get_user_price_monitor_limits('wp:savelloclub.test:999');
        self::assertInstanceOf(WP_Error::class, $missing);
        self::assertSame('savello_internal_not_found', $missing->get_error_code());
    }

    public function test_manifest_returns_version_and_timestamps(): void
    {
        $manifest = (new Savello_Cashback_Internal_API_Service())->get_manifest();

        self::assertNotSame('', $manifest['version']);
        self::assertArrayHasKey('generated_at', $manifest);
        self::assertArrayHasKey('merchants_updated_at', $manifest);
        self::assertArrayHasKey('rates_updated_at', $manifest);
    }
}

final class Internal_Api_Wpdb_Stub
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public int $insert_id = 1001;
    public int $insert_count = 0;

    public function prepare(string $query, mixed ...$args): string
    {
        return $query . ' /* ' . wp_json_encode($args) . ' */';
    }

    public function get_results(string $sql, string $output = ARRAY_A): array
    {
        unset($output);
        if (str_contains($sql, 'cashback_shop_tariffs')) {
            if (str_contains($sql, 'zero-range')) {
                return array(
                    array(
                        'tariff_id'    => 'zero-range',
                        'name'         => 'Zero range sale',
                        'tariff_type'  => 'percent',
                        'payment_size' => '6.92',
                        'payment_min'  => '0',
                        'payment_max'  => '0',
                        'currency'     => 'RUB',
                        'is_deleted'   => '0',
                        'updated_at'   => '2026-06-01 09:55:00',
                    ),
                );
            }
            if (str_contains($sql, 'manual-lock') || str_contains($sql, 'manual-unlocked')) {
                return array(
                    array(
                        'tariff_id'    => 'manual',
                        'name'         => 'Manual fixture sale',
                        'tariff_type'  => 'percent',
                        'payment_size' => '10',
                        'payment_min'  => null,
                        'payment_max'  => null,
                        'currency'     => 'RUB',
                        'is_deleted'   => '0',
                        'updated_at'   => '2026-06-01 09:55:00',
                    ),
                );
            }
            if (str_contains($sql, 'no-tariffs') || str_contains($sql, 'empty-rate')) {
                return array();
            }
            return array(
                array(
                    'tariff_id'    => 'exact',
                    'name'         => 'Exact sale',
                    'tariff_type'  => 'percent',
                    'payment_size' => '6.92',
                    'payment_min'  => null,
                    'payment_max'  => null,
                    'currency'     => 'RUB',
                    'is_deleted'   => '0',
                    'updated_at'   => '2026-06-01 09:55:00',
                ),
                array(
                    'tariff_id'    => 'range',
                    'name'         => 'Range sale',
                    'tariff_type'  => 'percent',
                    'payment_size' => '6.92',
                    'payment_min'  => '0.38',
                    'payment_max'  => '6.92',
                    'currency'     => 'RUB',
                    'is_deleted'   => '0',
                    'updated_at'   => '2026-06-01 09:55:00',
                ),
                array(
                    'tariff_id'    => 'zero',
                    'name'         => 'Possible sale',
                    'tariff_type'  => 'percent',
                    'payment_size' => '0.24',
                    'payment_min'  => '0',
                    'payment_max'  => '0.24',
                    'currency'     => 'RUB',
                    'is_deleted'   => '0',
                    'updated_at'   => '2026-06-01 09:55:00',
                ),
            );
        }

        if (str_contains($sql, 'posts')) {
            $rows = array(
                array(
                    'ID'           => '101',
                    'post_title'   => 'AliExpress',
                    'post_status'  => 'publish',
                    'network_id'   => '1',
                    'network_name' => 'Admitad',
                    'network_slug' => 'admitad',
                    'network_active' => '1',
                    'offer_id'     => '29562',
                    'store_domain' => 'https://www.aliexpress.ru/path',
                    'currency'     => 'RUB',
                    'status_raw'   => 'active',
                    'product_url'  => 'https://go.example/ali',
                    'updated_at'   => '2026-06-01 09:50:00',
                ),
                array(
                    'ID'           => '102',
                    'post_title'   => 'Paused Shop',
                    'post_status'  => 'draft',
                    'network_id'   => '1',
                    'network_name' => 'Admitad',
                    'network_slug' => 'admitad',
                    'network_active' => '1',
                    'offer_id'     => '999',
                    'store_domain' => 'paused.example',
                    'currency'     => 'RUB',
                    'status_raw'   => 'paused',
                    'product_url'  => 'https://paused.example/go',
                    'updated_at'   => '2026-06-01 08:00:00',
                ),
            );
            if (!empty($GLOBALS['_cb_test_internal_include_advcake'])) {
                $rows[] = array(
                    'ID'           => '103',
                    'post_title'   => 'Advcake Store',
                    'post_status'  => 'publish',
                    'network_id'   => '2',
                    'network_name' => 'Adv.Cake',
                    'network_slug' => 'advcake',
                    'network_active' => '1',
                    'offer_id'     => 'offer-42',
                    'store_domain' => 'advcake.example',
                    'currency'     => 'RUB',
                    'status_raw'   => 'active',
                    'product_url'  => 'https://go.advcake.example/template?dl={dl}&sub1={sub1}&sub2={sub2}',
                    'api_base_url' => 'https://api.advcake.ru',
                    'api_website_id' => '',
                    'updated_at'   => '2026-06-01 09:58:00',
                );
                $rows[] = array(
                    'ID'           => '104',
                    'post_title'   => 'Cashback Disabled',
                    'post_status'  => 'publish',
                    'network_id'   => '2',
                    'network_name' => 'Adv.Cake',
                    'network_slug' => 'advcake',
                    'network_active' => '1',
                    'offer_id'     => 'offer-disabled',
                    'store_domain' => 'disabled-cashback.example',
                    'currency'     => 'RUB',
                    'status_raw'   => 'active',
                    'product_url'  => 'https://go.advcake.example/template?dl={dl}&sub1={sub1}',
                    'cashback_enabled' => '0',
                    'api_base_url' => 'https://api.advcake.ru',
                    'api_website_id' => '',
                    'updated_at'   => '2026-06-01 09:58:00',
                );
                $rows[] = array(
                    'ID'           => '105',
                    'post_title'   => 'No Deeplink',
                    'post_status'  => 'publish',
                    'network_id'   => '3',
                    'network_name' => 'Generic Network',
                    'network_slug' => 'generic',
                    'network_active' => '1',
                    'offer_id'     => 'offer-nodeep',
                    'store_domain' => 'no-deeplink.example',
                    'currency'     => 'RUB',
                    'status_raw'   => 'active',
                    'product_url'  => '',
                    'cashback_enabled' => '1',
                    'api_base_url' => '',
                    'api_website_id' => '',
                    'updated_at'   => '2026-06-01 09:58:00',
                );
            }
            if (!empty($GLOBALS['_cb_test_internal_include_server_direct_cases'])) {
                $rows[] = array(
                    'ID'             => '106',
                    'post_title'     => 'iBOX',
                    'post_status'    => 'publish',
                    'network_id'     => '1',
                    'network_name'   => 'Admitad',
                    'network_slug'   => 'adm',
                    'network_active' => '1',
                    'offer_id'       => '30582',
                    'store_domain'   => 'iboxstore.ru',
                    'currency'       => 'RUB',
                    'status_raw'     => 'active',
                    'product_url'    => 'https://codeaven.com/g/4hh84nh1h6998b33a895e6b606b04d/?erid=5jtCeReNwxHpfQTFQwvgGrT',
                    'api_base_url'   => 'https://api.admitad.com',
                    'api_token_endpoint' => '/token/',
                    'api_website_id' => '2082764',
                    'updated_at'     => '2026-06-26 19:46:52',
                );
                $rows[] = array(
                    'ID'             => '107',
                    'post_title'     => 'mnogomebeli.com',
                    'post_status'    => 'publish',
                    'network_id'     => '2',
                    'network_name'   => 'Adv.Cake',
                    'network_slug'   => 'advcake',
                    'network_active' => '1',
                    'offer_id'       => '1111',
                    'store_domain'   => 'mnogomebeli.com',
                    'currency'       => 'RUB',
                    'status_raw'     => 'active',
                    'product_url'    => 'https://go.redav.online/20fe219674c95fc1?erid=2VfnxxEEBKF&m=31',
                    'api_base_url'   => 'https://api.advcake.ru',
                    'api_website_id' => '',
                    'updated_at'     => '2026-06-26 19:46:52',
                );
            }
            if (!empty($GLOBALS['_cb_test_internal_include_rate_cases'])) {
                $rows[] = array(
                    'ID'           => '108',
                    'post_title'   => 'Zero Range Store',
                    'post_status'  => 'publish',
                    'network_id'   => '2',
                    'network_name' => 'Adv.Cake',
                    'network_slug' => 'advcake',
                    'network_active' => '1',
                    'offer_id'     => 'zero-range',
                    'store_domain' => 'zero-range.example',
                    'currency'     => 'RUB',
                    'status_raw'   => 'active',
                    'product_url'  => 'https://go.advcake.example/template?dl={dl}&sub1={sub1}&sub2={sub2}',
                    'api_base_url' => 'https://api.advcake.ru',
                    'api_website_id' => '',
                    'updated_at'   => '2026-06-01 09:58:00',
                );
                $rows[] = array(
                    'ID'           => '109',
                    'post_title'   => 'Manual Lock Store',
                    'post_status'  => 'publish',
                    'network_id'   => '2',
                    'network_name' => 'Adv.Cake',
                    'network_slug' => 'advcake',
                    'network_active' => '1',
                    'offer_id'     => 'manual-lock',
                    'store_domain' => 'manual-lock.example',
                    'currency'     => 'RUB',
                    'status_raw'   => 'active',
                    'product_url'  => 'https://go.advcake.example/template?dl={dl}&sub1={sub1}&sub2={sub2}',
                    'api_base_url' => 'https://api.advcake.ru',
                    'api_website_id' => '',
                    'updated_at'   => '2026-06-01 09:58:00',
                );
                $rows[] = array(
                    'ID'           => '110',
                    'post_title'   => 'Manual Unlocked Store',
                    'post_status'  => 'publish',
                    'network_id'   => '2',
                    'network_name' => 'Adv.Cake',
                    'network_slug' => 'advcake',
                    'network_active' => '1',
                    'offer_id'     => 'manual-unlocked',
                    'store_domain' => 'manual-unlocked.example',
                    'currency'     => 'RUB',
                    'status_raw'   => 'active',
                    'product_url'  => 'https://go.advcake.example/template?dl={dl}&sub1={sub1}&sub2={sub2}',
                    'api_base_url' => 'https://api.advcake.ru',
                    'api_website_id' => '',
                    'updated_at'   => '2026-06-01 09:58:00',
                );
                $rows[] = array(
                    'ID'           => '111',
                    'post_title'   => 'Legacy Rate Store',
                    'post_status'  => 'publish',
                    'network_id'   => '2',
                    'network_name' => 'Adv.Cake',
                    'network_slug' => 'advcake',
                    'network_active' => '1',
                    'offer_id'     => 'no-tariffs',
                    'store_domain' => 'legacy-rate.example',
                    'currency'     => 'RUB',
                    'status_raw'   => 'active',
                    'product_url'  => 'https://go.advcake.example/template?dl={dl}&sub1={sub1}&sub2={sub2}',
                    'api_base_url' => 'https://api.advcake.ru',
                    'api_website_id' => '',
                    'updated_at'   => '2026-06-01 09:58:00',
                );
                $rows[] = array(
                    'ID'           => '112',
                    'post_title'   => 'Empty Rate Store',
                    'post_status'  => 'publish',
                    'network_id'   => '2',
                    'network_name' => 'Adv.Cake',
                    'network_slug' => 'advcake',
                    'network_active' => '1',
                    'offer_id'     => 'empty-rate',
                    'store_domain' => 'empty-rate.example',
                    'currency'     => 'RUB',
                    'status_raw'   => 'active',
                    'product_url'  => 'https://go.advcake.example/template?dl={dl}&sub1={sub1}&sub2={sub2}',
                    'api_base_url' => 'https://api.advcake.ru',
                    'api_website_id' => '',
                    'updated_at'   => '2026-06-01 09:58:00',
                );
            }
            return $rows;
        }

        if (str_contains($sql, 'cashback_affiliate_network_params')) {
            if (str_contains($sql, '"2"') || str_contains($sql, ',2') || str_contains($sql, '[2]')) {
                return array(
                    array('param_name' => 'sub1', 'param_type' => 'uuid'),
                    array('param_name' => 'sub2', 'param_type' => 'user'),
                );
            }
            return array(
                array('param_name' => 'subid1', 'param_type' => 'uuid'),
                array('param_name' => 'subid2', 'param_type' => 'user'),
            );
        }

        return array();
    }

    public function get_row(string $sql, string $output = ARRAY_A): ?array
    {
        unset($output);
        if (str_contains($sql, 'cashback_user_profile') && str_contains($sql, '77')) {
            return array(
                'user_id'       => '77',
                'cashback_rate' => '65.00',
                'status'        => 'active',
            );
        }
        return null;
    }

    public function get_var(string $sql): ?string
    {
        if (str_contains($sql, 'cashback_user_profile') && str_contains($sql, '77')) {
            return '65.00';
        }
        if (str_contains($sql, 'cashback_affiliate_networks') && str_contains($sql, '"2"')) {
            return 'advcake';
        }
        if (str_contains($sql, 'cashback_affiliate_networks') && str_contains($sql, '"1"')) {
            return 'admitad';
        }
        if (str_contains($sql, 'MAX') && str_contains($sql, 'cashback_shop_tariffs')) {
            return '2026-06-01 09:55:00';
        }
        if (str_contains($sql, 'MAX') && str_contains($sql, 'posts')) {
            return '2026-06-01 09:50:00';
        }
        return null;
    }

    public function query(string $sql): int|bool
    {
        unset($sql);
        return 1;
    }

    public function insert(string $table, array $data, array $format = array()): int|false
    {
        unset($table, $data, $format);
        $this->insert_count++;
        $this->insert_id++;
        return 1;
    }
}
