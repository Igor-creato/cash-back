<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('internal-rest-api')]
final class InternalApiServiceTest extends TestCase
{
    private Internal_Api_Wpdb_Stub $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/includes/class-cashback-shop-options.php';
        require_once dirname(__DIR__, 3) . '/includes/shops/class-cashback-shop-tariff-sync.php';
        require_once dirname(__DIR__, 3) . '/includes/adapters/interface-cashback-network-adapter.php';
        require_once dirname(__DIR__, 3) . '/includes/adapters/abstract-cashback-network-adapter.php';
        require_once dirname(__DIR__, 3) . '/includes/adapters/class-admitad-adapter.php';
        require_once dirname(__DIR__, 3) . '/includes/adapters/class-epn-adapter.php';
        require_once dirname(__DIR__, 3) . '/includes/adapters/class-cashback-advcake-adapter.php';
        require_once dirname(__DIR__, 3) . '/includes/class-cashback-api-client.php';
        require_once dirname(__DIR__, 3) . '/includes/services/class-cashback-internal-api-service.php';

        $GLOBALS['_cb_test_options'] = array(
            'cashback_guest_display_rate' => 70,
        );
        $GLOBALS['_cb_test_post_meta'] = array();
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

        $this->wpdb = new Internal_Api_Wpdb_Stub();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function test_merchants_default_to_active_and_status_all_includes_disabled(): void
    {
        $service = new Savello_Cashback_Internal_API_Service();

        $active = $service->get_merchants(array());
        self::assertCount(4, $active['items']);
        self::assertSame('101', $active['items'][0]['merchant_id']);
        self::assertSame(array('aliexpress.ru'), $active['items'][0]['domains']);
        self::assertIsArray($active['items'][0]['domain_aliases']);

        $all = $service->get_merchants(array( 'status' => 'all', 'limit' => 999 ));
        self::assertCount(5, $all['items']);
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

    #[Group('direct-product-link')]
    public function test_direct_product_link_uses_db_tracking_params_and_logs_click(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php';

        $service = new Savello_Cashback_Internal_API_Service();
        $click_id = '0123456789abcdef0123456789abcdef';

        $result = $service->resolve_direct_product_link(array(
            'direct_url'        => 'https://custom.example/products/sku-1',
            'click_id'          => $click_id,
            'client_request_id' => '550e8400-e29b-41d4-a716-446655440000',
            'ip_address'        => '127.0.0.1',
            'user_agent'        => 'phpunit',
        ));

        self::assertTrue($result['cashback_available']);
        self::assertSame('Активировать кэшбэк', $result['button_text']);
        self::assertSame($click_id, $result['click_id']);
        self::assertArrayHasKey('activation_page_url', $result);
        self::assertStringContainsString('cashback_go=1', $result['activation_page_url']);
        self::assertStringContainsString('click_id=' . $click_id, $result['activation_page_url']);
        self::assertStringContainsString('sub1=' . $click_id, $result['cashback_url']);
        self::assertStringContainsString('sub2=unregistered', $result['cashback_url']);
        self::assertDoesNotMatchRegularExpression('/(?:^|[?&])subid\d?=/', $result['cashback_url']);
        self::assertStringNotContainsString('click_ref=', $result['cashback_url']);
        self::assertStringNotContainsString('user_ref=', $result['cashback_url']);
        self::assertGreaterThanOrEqual(1, $this->wpdb->insert_count);
        self::assertSame($result['cashback_url'], $this->wpdb->insert_rows[0]['affiliate_url']);
        self::assertSame('550e8400e29b41d4a716446655440000', $this->wpdb->insert_rows[0]['client_request_id']);
    }

    #[Group('direct-product-link')]
    public function test_advcake_direct_product_link_uses_cakelink_even_when_stored_affiliate_url_exists(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php';

        $returned_url = 'https://www.static-advcake.example/products/sku-1?advcake_params=from-api';
        $GLOBALS['_cb_test_http_response'] = array(
            'body'     => (string) wp_json_encode(array(
                'success' => true,
                'data'    => array(
                    'url' => $returned_url,
                ),
            )),
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'headers'  => array(),
        );

        $service  = new Savello_Cashback_Internal_API_Service();
        $click_id = '11111111111111111111111111111111';

        $result = $service->resolve_direct_product_link(array(
            'direct_url'        => 'https://static-advcake.example/products/sku-1',
            'click_id'          => $click_id,
            'client_request_id' => '550e8400-e29b-41d4-a716-446655440001',
            'ip_address'        => '127.0.0.1',
            'user_agent'        => 'phpunit',
        ));

        self::assertTrue($result['cashback_available']);
        self::assertSame('cakelink', $result['link_type']);
        self::assertSame($returned_url, $result['cashback_url']);
        self::assertSame($returned_url, $result['url']);
        self::assertStringNotContainsString('sub1=', $result['cashback_url']);
        self::assertStringNotContainsString('sub2=', $result['cashback_url']);
        self::assertSame($result['cashback_url'], $this->wpdb->insert_rows[0]['affiliate_url']);

        self::assertCount(1, $GLOBALS['_cb_test_http_calls']);
        $request_url = (string) $GLOBALS['_cb_test_http_calls'][0]['url'];
        self::assertSame('cakelink.ru', wp_parse_url($request_url, PHP_URL_HOST));
        parse_str((string) wp_parse_url($request_url, PHP_URL_QUERY), $params);
        self::assertSame('https://static-advcake.example/products/sku-1', $params['dl']);
        self::assertSame($click_id, $params['sub1']);
        self::assertSame('unregistered', $params['sub2']);
        self::assertArrayHasKey('pass', $params);
    }

    #[Group('direct-product-link')]
    public function test_direct_product_link_fails_closed_when_network_tracking_params_are_empty(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php';

        $service = new Savello_Cashback_Internal_API_Service();

        $result = $service->resolve_direct_product_link(array(
            'direct_url' => 'https://empty-tracking.example/product',
            'click_id'   => 'fedcba9876543210fedcba9876543210',
        ));

        self::assertFalse($result['cashback_available']);
        self::assertSame('tracking_unavailable', $result['reason_code']);
        self::assertSame('https://empty-tracking.example/product', $result['url']);
        self::assertSame(0, $this->wpdb->insert_count);
    }

    #[Group('direct-product-link')]
    public function test_click_log_normalizes_overlong_client_request_id_before_insert(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php';

        $method = new ReflectionMethod(Cashback_Click_Session_Service::class, 'log_click');
        $method->setAccessible(true);

        $logged = $method->invoke(null, array(
            'click_id'           => '11111111111111111111111111111111',
            'click_session_id'   => 42,
            'client_request_id'  => '550e8400-e29b-41d4-a716-446655440000-extra-ui-state',
            'is_session_primary' => 1,
            'user_id'            => 1,
            'product_id'         => 103,
            'cpa_network'        => 'advcake',
            'offer_id'           => '1111',
            'affiliate_url'      => 'https://go.redav.online/hash?sub1=11111111111111111111111111111111',
            'ip_address'         => '127.0.0.1',
            'user_agent'         => 'phpunit',
            'referer'            => 'https://mnogomebeli.com/item/',
            'spam_click'         => 0,
        ));

        self::assertTrue($logged);
        self::assertSame(1, $this->wpdb->insert_count);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $this->wpdb->insert_rows[0]['client_request_id']);
        self::assertSame(32, strlen($this->wpdb->insert_rows[0]['client_request_id']));
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
    public string $users = 'wp_users';
    public int $insert_count = 0;
    public int $insert_id = 5001;
    public string $last_error = '';
    /** @var array<int,array<string,mixed>> */
    public array $insert_rows = array();

    public function prepare(string $query, mixed ...$args): string
    {
        return $query . ' /* ' . wp_json_encode($args) . ' */';
    }

    public function get_results(string $sql, string $output = ARRAY_A): array
    {
        unset($output);
        if (str_contains($sql, 'cashback_affiliate_network_params')) {
            if (str_contains($sql, '"4"') || str_contains($sql, ',4]')) {
                return array(
                    array( 'param_name' => 'sub1', 'param_type' => 'uuid' ),
                    array( 'param_name' => 'sub2', 'param_type' => 'user' ),
                );
            }
            if (str_contains($sql, '"5"') || str_contains($sql, ',5]')) {
                return array();
            }
            return array(
                array( 'param_name' => 'subid1', 'param_type' => 'uuid' ),
                array( 'param_name' => 'subid2', 'param_type' => 'user' ),
            );
        }

        if (str_contains($sql, 'cashback_shop_tariffs')) {
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
            return array(
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
                array(
                    'ID'           => '103',
                    'post_title'   => 'Custom Tracking Shop',
                    'post_status'  => 'publish',
                    'network_id'   => '4',
                    'network_name' => 'AdvCake',
                    'network_slug' => 'advcake',
                    'network_active' => '1',
                    'offer_id'     => 'custom-offer',
                    'store_domain' => 'custom.example',
                    'currency'     => 'RUB',
                    'status_raw'   => 'active',
                    'product_url'  => 'https://go.custom.example/click?dl={dl}&sub1={sub1}&sub2={sub2}',
                    'cashback_enabled' => '1',
                    'api_base_url' => 'https://api.advcake.test',
                    'api_token_endpoint' => '',
                    'api_website_id' => '',
                    'updated_at'   => '2026-06-01 09:50:00',
                ),
                array(
                    'ID'           => '104',
                    'post_title'   => 'Empty Tracking Shop',
                    'post_status'  => 'publish',
                    'network_id'   => '5',
                    'network_name' => 'AdvCake',
                    'network_slug' => 'advcake',
                    'network_active' => '1',
                    'offer_id'     => 'empty-offer',
                    'store_domain' => 'empty-tracking.example',
                    'currency'     => 'RUB',
                    'status_raw'   => 'active',
                    'product_url'  => 'https://go.empty.example/click?dl={dl}',
                    'cashback_enabled' => '1',
                    'api_base_url' => 'https://api.advcake.test',
                    'api_token_endpoint' => '',
                    'api_website_id' => '',
                    'updated_at'   => '2026-06-01 09:50:00',
                ),
                array(
                    'ID'           => '105',
                    'post_title'   => 'Static AdvCake Shop',
                    'post_status'  => 'publish',
                    'network_id'   => '4',
                    'network_name' => 'AdvCake',
                    'network_slug' => 'advcake',
                    'network_active' => '1',
                    'offer_id'     => 'static-offer',
                    'store_domain' => 'static-advcake.example',
                    'currency'     => 'RUB',
                    'status_raw'   => 'active',
                    'product_url'  => 'https://go.static-advcake.example/click?erid=test-erid&m=31',
                    'cashback_enabled' => '1',
                    'api_base_url' => 'https://api.advcake.test',
                    'api_token_endpoint' => '',
                    'api_website_id' => '',
                    'updated_at'   => '2026-06-01 09:50:00',
                ),
            );
        }

        return array();
    }

    public function get_row(string $sql, string $output = ARRAY_A): ?array
    {
        unset($output);
        if (str_contains($sql, 'cashback_affiliate_networks') && str_contains($sql, 'advcake')) {
            return array(
                'id'                 => '4',
                'name'               => 'AdvCake',
                'slug'               => 'advcake',
                'api_base_url'       => 'https://api.advcake.test',
                'api_user_field'     => 'sub2',
                'api_click_field'    => 'sub1',
                'api_website_id'     => '',
                'api_status_map'     => '',
                'api_field_map'      => '',
                'api_credentials'    => $this->advcake_credentials_ciphertext(),
                'is_active'          => '1',
            );
        }
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
        if (str_contains($sql, 'cashback_affiliate_networks') && str_contains($sql, 'api_credentials')) {
            if (str_contains($sql, '"4"') || str_contains($sql, ',4]')) {
                return $this->advcake_credentials_ciphertext();
            }
            return null;
        }
        if (str_contains($sql, 'cashback_affiliate_networks')) {
            if (str_contains($sql, '"4"') || str_contains($sql, ',4]')) {
                return 'advcake';
            }
            if (str_contains($sql, '"5"') || str_contains($sql, ',5]')) {
                return 'advcake';
            }
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
        if (str_contains($sql, 'INSERT INTO')) {
            ++$this->insert_id;
        }
        return 1;
    }

    private function advcake_credentials_ciphertext(): string
    {
        static $ciphertext = null;
        if ($ciphertext === null) {
            $ciphertext = Cashback_Encryption::encrypt((string) wp_json_encode(array(
                'api_key' => 'unitTestAdvcakePass_123',
            )));
        }
        return $ciphertext;
    }

    public function insert(string $table, array $data, array $format = array()): int|bool
    {
        unset($format);
        if (str_contains($table, 'cashback_click_log')) {
            ++$this->insert_count;
            $this->insert_rows[] = $data;
        }
        return 1;
    }
}
