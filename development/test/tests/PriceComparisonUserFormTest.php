<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('price-comparison')]
final class PriceComparisonUserFormTest extends TestCase {

    public static function setUpBeforeClass(): void {
        require_once dirname(__DIR__, 3) . '/includes/price-comparison/class-cashback-price-comparison-account.php';
    }

    protected function setUp(): void {
        $GLOBALS['_cb_test_enqueued_scripts']  = array();
        $GLOBALS['_cb_test_enqueued_styles']   = array();
        $GLOBALS['_cb_test_localized_scripts'] = array();
    }

    public function test_account_page_renders_required_city_and_query_fields(): void {
        $account = new Cashback_Price_Comparison_Account();

        ob_start();
        $account->render_page();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Сравнить цену', $html);
        self::assertStringContainsString('name="city"', $html);
        self::assertStringContainsString('name="query"', $html);
        self::assertStringContainsString('Город', $html);
        self::assertStringContainsString('Название товара', $html);
        self::assertStringContainsString('Поиск', $html);
    }

    public function test_account_assets_localize_rest_nonce_and_no_backend_secret(): void {
        $account = new Cashback_Price_Comparison_Account();
        $account->enqueue_assets();

        self::assertArrayHasKey('cashback-price-comparison', $GLOBALS['_cb_test_enqueued_scripts']);
        self::assertArrayHasKey('cashback-price-comparison', $GLOBALS['_cb_test_enqueued_styles']);

        $config = $GLOBALS['_cb_test_localized_scripts']['cashback-price-comparison']['CashbackPriceComparison'];
        self::assertSame(
            'https://savelloclub.test/wp-json/cashback/v1/price-comparison/search',
            $config['restUrl']
        );
        self::assertSame(wp_create_nonce('wp_rest'), $config['nonce']);
        self::assertSame('Товаров не нашлось', $config['copy']['notFound']);
        self::assertArrayNotHasKey('hmacSecret', $config);
        self::assertArrayNotHasKey('backendBaseUrl', $config);
    }

    public function test_price_comparison_endpoint_is_registered_before_rewrite_flush(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/cashback-plugin.php');

        self::assertMatchesRegularExpression(
            "/add_rewrite_endpoint\\(\\s*'price-comparison'\\s*,\\s*EP_ROOT\\s*\\|\\s*EP_PAGES\\s*\\);.*flush_rewrite_rules\\(/s",
            $source,
            'Новый my-account endpoint должен попадать в rewrite rules при activation/rewrite flush.'
        );
        self::assertStringContainsString(
            'cashback_price_comparison_rewrite_2026_07_03_done',
            $source,
            'Existing installs после git deploy должны получить одноразовый flush без re-activation.'
        );
    }
}
