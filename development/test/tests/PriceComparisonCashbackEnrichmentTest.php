<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('price-comparison')]
final class PriceComparisonCashbackEnrichmentTest extends TestCase {

    public static function setUpBeforeClass(): void {
        require_once dirname(__DIR__, 3) . '/includes/price-comparison/class-cashback-price-comparison-client.php';
        require_once dirname(__DIR__, 3) . '/includes/price-comparison/class-cashback-price-comparison-service.php';
    }

    protected function setUp(): void {
        $GLOBALS['_cb_test_user_meta'] = array();
    }

    public function test_cashback_available_item_uses_existing_activation_result(): void {
        $client = new Price_Comparison_Fake_Client(array(
            array(
                'title'        => 'iPhone 15',
                'url'          => 'https://custom.example/products/sku-1',
                'price'        => 80000,
                'currency'     => 'RUB',
                'store_domain' => 'custom.example',
            ),
        ));
        $resolver = static function ( array $payload ): array {
            self::assertSame('https://custom.example/products/sku-1', $payload['direct_url']);
            self::assertSame(77, $payload['user_id']);

            return array(
                'cashback_available'  => true,
                'button_text'         => 'Активировать кэшбэк',
                'activation_page_url' => 'https://savelloclub.test/?cashback_go=1',
                'cashback_url'        => 'https://network.example/deeplink',
            );
        };

        $service = new Cashback_Price_Comparison_Service($client, $resolver);
        $result  = $service->search('Москва', 'iphone', 77);

        self::assertSame('Активировать кэшбэк', $result['items'][0]['action_label']);
        self::assertSame('https://savelloclub.test/?cashback_go=1', $result['items'][0]['action_url']);
        self::assertSame('available', $result['items'][0]['cashback_status']);
    }

    public function test_cashback_enrichment_prefers_product_url_over_backend_action_url(): void {
        $client = new Price_Comparison_Fake_Client(array(
            array(
                'title'        => 'Samsung S25',
                'url'          => 'https://merchant.example/products/source-s25',
                'action_url'   => 'https://backend.example/clickout/stale-s25',
                'price'        => 90000,
                'currency'     => 'RUB',
                'store_domain' => 'merchant.example',
            ),
        ));
        $resolver = static function ( array $payload ): array {
            self::assertSame('https://merchant.example/products/source-s25', $payload['direct_url']);

            return array(
                'cashback_available'  => true,
                'button_text'         => 'Активировать кэшбэк',
                'activation_page_url' => 'https://savelloclub.test/?cashback_go=1&click_id=product-url',
            );
        };

        $service = new Cashback_Price_Comparison_Service($client, $resolver);
        $result  = $service->search('Москва', 'samsung', 77);

        self::assertSame('Активировать кэшбэк', $result['items'][0]['action_label']);
        self::assertSame('https://savelloclub.test/?cashback_go=1&click_id=product-url', $result['items'][0]['action_url']);
        self::assertSame('available', $result['items'][0]['cashback_status']);
    }

    public function test_cashback_enrichment_uses_backend_action_url_when_url_is_missing(): void {
        $client = new Price_Comparison_Fake_Client(array(
            array(
                'title'        => 'GROHE plate',
                'action_url'   => 'https://shop.grohe.ru/catalog/product-1',
                'price'        => 110,
                'currency'     => 'RUB',
                'store_domain' => 'grohe-russia.shop',
            ),
        ));
        $resolver = static function ( array $payload ): array {
            self::assertSame('https://shop.grohe.ru/catalog/product-1', $payload['direct_url']);

            return array(
                'cashback_available'  => true,
                'button_text'         => 'Активировать кэшбэк',
                'activation_page_url' => 'https://savelloclub.test/?cashback_go=1&click_id=abc',
            );
        };

        $service = new Cashback_Price_Comparison_Service($client, $resolver);
        $result  = $service->search('Москва', 'grohe', 77);

        self::assertSame('Активировать кэшбэк', $result['items'][0]['action_label']);
        self::assertSame('https://savelloclub.test/?cashback_go=1&click_id=abc', $result['items'][0]['action_url']);
        self::assertSame('available', $result['items'][0]['cashback_status']);
    }

    public function test_cashback_lookup_failure_keeps_buy_button_and_safe_note(): void {
        $client = new Price_Comparison_Fake_Client(array(
            array(
                'title'        => 'iPhone 15',
                'url'          => 'https://unknown.example/products/sku-1',
                'price'        => 80000,
                'currency'     => 'RUB',
                'store_domain' => 'unknown.example',
            ),
        ));
        $resolver = static fn(): WP_Error => new WP_Error(
            'temporary_cashback_error',
            'internal secret should not leak',
            array( 'status' => 503 )
        );

        $service = new Cashback_Price_Comparison_Service($client, $resolver);
        $result  = $service->search('Москва', 'iphone', 77);

        self::assertSame('Купить', $result['items'][0]['action_label']);
        self::assertSame('https://unknown.example/products/sku-1', $result['items'][0]['action_url']);
        self::assertSame('unknown', $result['items'][0]['cashback_status']);
        self::assertSame('Кэшбэк не определён', $result['items'][0]['cashback_note']);
        self::assertStringNotContainsString('internal secret', wp_json_encode($result));
    }

    public function test_search_saves_valid_city_for_current_user(): void {
        $client  = new Price_Comparison_Fake_Client(array());
        $service = new Cashback_Price_Comparison_Service($client, static fn(): array => array());

        $service->search(' Пенза ', 'телевизор', 77);

        self::assertSame(
            'Пенза',
            $GLOBALS['_cb_test_user_meta'][77]['cashback_price_comparison_city'] ?? null
        );
    }

    public function test_live_search_poll_enriches_returned_items(): void {
        $client = new Price_Comparison_Fake_Client(array(
            array(
                'title'        => 'Телевизор TCL 55C645',
                'url'          => 'https://fixture.test/tcl-55',
                'price'        => 39990,
                'currency'     => 'RUB',
                'store_domain' => 'fixture.test',
            ),
        ));
        $resolver = static fn(): array => array(
            'cashback_available'  => true,
            'button_text'         => 'Активировать кэшбэк',
            'activation_page_url' => 'https://savelloclub.test/?cashback_go=1',
        );

        $service = new Cashback_Price_Comparison_Service($client, $resolver);
        $result  = $service->get_live_search('run_1234', 77);

        self::assertSame('ok', $result['status']);
        self::assertSame('Активировать кэшбэк', $result['items'][0]['action_label']);
        self::assertSame('https://savelloclub.test/?cashback_go=1', $result['items'][0]['action_url']);
    }
}

final class Price_Comparison_Fake_Client {

    private array $items;

    public function __construct( array $items ) {
        $this->items = $items;
    }

    public function search( array $payload ): array {
        unset($payload);

        return array(
            'status' => 'ok',
            'items'  => $this->items,
            'meta'   => array(
                'total'    => count($this->items),
                'warnings' => array(),
            ),
        );
    }

    public function start_live_search( array $payload ): array {
        unset($payload);

        return array(
            'status'   => 'accepted',
            'run_id'   => 'run_1234',
            'poll_url' => '/api/v1/live-search/runs/run_1234',
        );
    }

    public function get_live_search( string $run_id ): array {
        unset($run_id);

        return array(
            'status' => 'ok',
            'items'  => $this->items,
            'meta'   => array( 'warnings' => array() ),
        );
    }
}
