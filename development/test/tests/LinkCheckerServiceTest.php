<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('link-checker')]
final class LinkCheckerServiceTest extends TestCase {

    public static function setUpBeforeClass(): void {
        if (!function_exists('get_permalink')) {
            function get_permalink( int $post_id = 0 ): string {
                return 'https://savelloclub.test/store/' . $post_id . '/';
            }
        }

        require_once dirname(__DIR__, 3) . '/includes/class-cashback-shop-options.php';
        require_once dirname(__DIR__, 3) . '/includes/shops/class-cashback-shop-tariff-sync.php';
        require_once dirname(__DIR__, 3) . '/includes/shops/class-cashback-cashback-display-calculator.php';
        require_once dirname(__DIR__, 3) . '/includes/shops/class-cashback-shop-importer.php';
        require_once dirname(__DIR__, 3) . '/includes/shops/class-cashback-tab-conditions-renderer.php';
        require_once dirname(__DIR__, 3) . '/includes/link-checker/class-cashback-link-checker-url-validator.php';
        require_once dirname(__DIR__, 3) . '/includes/link-checker/class-cashback-link-checker-service.php';
    }

    protected function setUp(): void {
        global $wpdb;

        $wpdb = new LinkCheckerServiceWpdbStub();

        $GLOBALS['_cb_test_meta']                  = array();
        $GLOBALS['_cb_test_link_checker_products'] = array();
        $GLOBALS['_cb_test_link_checker_tariffs']  = array();
        $GLOBALS['_cb_test_options']               = array(
            'cashback_guest_display_rate' => '60',
        );
        $GLOBALS['_cb_test_http_calls']             = array();
    }

    public function test_check_returns_available_store_with_display_cashback(): void {
        $this->seed_product(array(
            'ID'             => 501,
            'post_title'     => 'Ozon',
            'post_status'    => 'publish',
            'network_id'     => 7,
            'offer_id'       => 'ozon-123',
            'store_domain'   => 'https://www.ozon.ru',
            'network_slug'   => 'admitad',
            'network_name'   => 'Admitad',
            'network_active' => 1,
        ));
        $GLOBALS['_cb_test_link_checker_tariffs'] = array(
            array(
                'tariff_id'    => 'base',
                'name'         => 'Оплаченный заказ на сайте',
                'tariff_type'  => 'percent',
                'payment_size' => '10',
                'currency'     => 'RUB',
                'is_deleted'   => 0,
            ),
        );
        $GLOBALS['_cb_test_meta'][501]['_woodmart_product_custom_tab_content'] =
            Cashback_Tab_Conditions_Renderer::SENTINEL . "\n"
            . '<h3><strong>Условия начисления</strong></h3>' . "\n"
            . '<p>Оплаченный заказ на сайте — категория аксессуары: <strong>6,00%</strong></p>';

        $result = ( new Cashback_Link_Checker_Service() )->check('https://www.ozon.ru/product/123', 0);

        self::assertSame('available', $result['status']);
        self::assertTrue($result['cashback_available']);
        self::assertTrue($result['activation_required']);
        self::assertSame(501, $result['store']['product_id']);
        self::assertSame('ozon.ru', $result['store']['domain']);
        self::assertSame('admitad', $result['store']['network']);
        self::assertSame('6%', $result['cashback']['value']);
        self::assertArrayHasKey('conditions_html', $result);
        self::assertStringContainsString('Условия начисления', $result['conditions_html']);
        self::assertStringContainsString('Оплаченный заказ на сайте', $result['conditions_html']);
        self::assertStringContainsString('<strong>6,00%</strong>', $result['conditions_html']);
        self::assertStringNotContainsString(Cashback_Tab_Conditions_Renderer::SENTINEL, $result['conditions_html']);
        self::assertStringContainsString('Кэшбэк доступен', $result['message']);
    }

    public function test_check_builds_conditions_html_with_renderer_when_product_tab_is_empty(): void {
        $this->seed_product(array(
            'ID'             => 502,
            'post_title'     => 'Renderer Shop',
            'post_status'    => 'publish',
            'network_id'     => 7,
            'offer_id'       => 'renderer-123',
            'store_domain'   => 'renderer-shop.example',
            'network_slug'   => 'admitad',
            'network_name'   => 'Admitad',
            'network_active' => 1,
        ));
        $GLOBALS['_cb_test_link_checker_tariffs'] = array(
            array(
                'tariff_id'    => 'base',
                'name'         => 'Оплаченный заказ на сайте',
                'tariff_type'  => 'percent',
                'payment_size' => '10',
                'currency'     => 'RUB',
                'is_deleted'   => 0,
            ),
        );

        $result = ( new Cashback_Link_Checker_Service() )->check('https://renderer-shop.example/product/123', 0);

        self::assertSame('available', $result['status']);
        self::assertArrayHasKey('conditions_html', $result);
        self::assertStringContainsString('<h3><strong>Условия начисления</strong></h3>', $result['conditions_html']);
        self::assertStringContainsString('Оплаченный заказ на сайте: <strong>6,00%</strong>', $result['conditions_html']);
        self::assertStringContainsString('Срок начисления кэшбэка', $result['conditions_html']);
        self::assertStringNotContainsString(Cashback_Tab_Conditions_Renderer::SENTINEL, $result['conditions_html']);
    }

    public function test_check_returns_not_available_for_unconnected_domain(): void {
        $result = ( new Cashback_Link_Checker_Service() )->check('https://unknown-shop.example/item', 0);

        self::assertSame('not_available', $result['status']);
        self::assertFalse($result['cashback_available']);
        self::assertFalse($result['activation_required']);
        self::assertSame('https://unknown-shop.example/item', $result['direct_url']);
        self::assertSame('Магазин не подключен, кэшбэк не начислится', $result['warning']);
        self::assertSame('Перейти', $result['button_text']);
        self::assertStringContainsString('не подключён', $result['message']);
    }

    public function test_activate_does_not_call_cakelink_for_unconnected_domain(): void {
        $result = ( new Cashback_Link_Checker_Service() )->activate(
            'https://unknown-shop.example/item',
            '550e8400e29b41d4a716446655440000',
            0
        );

        self::assertIsArray($result);
        self::assertFalse($result['cashback_available']);
        self::assertSame('merchant_not_found', $result['reason_code']);
        self::assertSame(array(), $GLOBALS['_cb_test_http_calls']);
    }

    public function test_activate_does_not_call_cakelink_for_invalid_url_scheme(): void {
        $result = ( new Cashback_Link_Checker_Service() )->activate(
            'javascript:alert(1)',
            '550e8400e29b41d4a716446655440000',
            0
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('invalid_url', $result->get_error_code());
        self::assertSame(array(), $GLOBALS['_cb_test_http_calls']);
    }

    public function test_check_returns_partner_no_commission_when_no_active_tariff(): void {
        $this->seed_product(array(
            'ID'             => 777,
            'post_title'     => 'No Tariff',
            'post_status'    => 'publish',
            'network_id'     => 9,
            'offer_id'       => 'no-rate',
            'store_domain'   => 'shop.example',
            'network_slug'   => 'advcake',
            'network_name'   => 'AdvCake',
            'network_active' => 1,
        ));

        $result = ( new Cashback_Link_Checker_Service() )->check('https://shop.example/product', 0);

        self::assertSame('partner_no_commission', $result['status']);
        self::assertFalse($result['cashback_available']);
        self::assertFalse($result['activation_required']);
        self::assertSame(777, $result['store']['product_id']);
    }

    public function test_check_returns_not_available_for_inactive_network(): void {
        $this->seed_product(array(
            'ID'             => 888,
            'post_title'     => 'Paused Shop',
            'post_status'    => 'publish',
            'network_id'     => 10,
            'offer_id'       => 'paused',
            'store_domain'   => 'paused.example',
            'network_slug'   => 'admitad',
            'network_name'   => 'Admitad',
            'network_active' => 0,
        ));

        $result = ( new Cashback_Link_Checker_Service() )->check('https://paused.example/item', 0);

        self::assertSame('not_available', $result['status']);
        self::assertFalse($result['cashback_available']);
        self::assertStringContainsString('временно недоступен', $result['message']);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function seed_product( array $row ): void {
        $GLOBALS['_cb_test_link_checker_products'][] = $row;

        $id = (int) $row['ID'];
        $GLOBALS['_cb_test_meta'][ $id ] = array(
            '_affiliate_network_id'     => (string) $row['network_id'],
            '_offer_id'                 => (string) $row['offer_id'],
            '_store_domain'             => (string) $row['store_domain'],
            '_cashback_display_label'   => 'Кэшбэк',
            '_cashback_campaign_status_raw' => (string) ( $row['status_raw'] ?? '' ),
        );
    }
}

final class LinkCheckerServiceWpdbStub {
    public string $prefix   = 'wp_';
    public string $posts    = 'wp_posts';
    public string $postmeta = 'wp_postmeta';

    public function prepare( string $query, mixed ...$args ): string {
        return $query . ' -- ' . wp_json_encode($args);
    }

    public function get_results( string $query, string $output = ARRAY_A ): array {
        unset($output);

        if (str_contains($query, 'cashback_shop_tariffs')) {
            return $GLOBALS['_cb_test_link_checker_tariffs'] ?? array();
        }

        return $GLOBALS['_cb_test_link_checker_products'] ?? array();
    }

    public function get_var( string $query ): mixed {
        unset($query);
        return null;
    }
}
