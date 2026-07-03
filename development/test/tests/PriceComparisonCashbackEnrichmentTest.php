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
}
