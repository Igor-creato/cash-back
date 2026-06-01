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

    public function prepare(string $query, mixed ...$args): string
    {
        return $query . ' /* ' . wp_json_encode($args) . ' */';
    }

    public function get_results(string $sql, string $output = ARRAY_A): array
    {
        unset($output);
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
        if (str_contains($sql, 'MAX') && str_contains($sql, 'cashback_shop_tariffs')) {
            return '2026-06-01 09:55:00';
        }
        if (str_contains($sql, 'MAX') && str_contains($sql, 'posts')) {
            return '2026-06-01 09:50:00';
        }
        return null;
    }
}
