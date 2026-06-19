<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('price-assistant-account-ui')]
final class PriceAssistantAccountUiTest extends TestCase
{
    private string $class_file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->class_file = dirname(__DIR__, 3) . '/includes/class-price-assistant-account.php';
        $GLOBALS['_cb_test_enqueued_scripts'] = array();
        $GLOBALS['_cb_test_enqueued_styles'] = array();
        $GLOBALS['_cb_test_localized_scripts'] = array();
        $GLOBALS['_cb_test_options'] = array();
        $GLOBALS['_cb_test_is_account_page'] = true;

        update_option('price_monitor_marketplace_ozon_enabled', 1);
        update_option('price_monitor_marketplace_wildberries_enabled', 1);
        update_option('price_monitor_marketplace_yandex_market_enabled', 1);

        require_once dirname(__DIR__, 3) . '/includes/services/class-price-assistant-proxy-client.php';
        require_once dirname(__DIR__, 3) . '/includes/rest/class-price-assistant-rest-controller.php';
    }

    public function test_account_endpoint_renders_marketplace_authorization_buttons_without_credentials(): void
    {
        self::assertFileExists($this->class_file, 'Price Assistant account UI class must exist.');
        require_once $this->class_file;

        $account = new Cashback_Price_Assistant_Account();

        ob_start();
        $account->render_endpoint();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('data-marketplace="ozon"', $html);
        self::assertStringContainsString('data-marketplace="wildberries"', $html);
        self::assertStringContainsString('data-marketplace="yandex_market"', $html);
        self::assertStringContainsString('data-price-assistant-status="connected"', $html);
        self::assertStringContainsString('data-price-assistant-status="sync ok"', $html);
        self::assertStringContainsString('data-price-assistant-status="reconnect_required"', $html);
        self::assertStringContainsString('data-price-assistant-status="disconnected"', $html);
        self::assertStringContainsString('data-price-assistant-add-form', $html);
        self::assertStringContainsString('data-price-assistant-region-form', $html);
        self::assertStringContainsString('data-price-assistant-manual-list', $html);
        self::assertStringContainsString('data-price-assistant-collection-list="cart"', $html);
        self::assertStringContainsString('data-price-assistant-collection-list="favorites"', $html);
        self::assertStringContainsString('data-price-assistant-chart', $html);
        self::assertStringContainsString('data-price-assistant-compare', $html);
        self::assertStringContainsString('data-price-assistant-target-price', $html);
        self::assertStringContainsString('data-price-assistant-target-effective-price', $html);
        self::assertStringContainsString('data-price-assistant-disconnect', $html);
        self::assertStringContainsString('data-price-assistant-delete-import', $html);
        self::assertStringNotContainsString('type="password"', strtolower($html));
        self::assertStringNotContainsString('password', strtolower($html));
    }

    public function test_account_menu_label_is_russian_and_preserves_logout_position(): void
    {
        self::assertFileExists($this->class_file, 'Price Assistant account UI class must exist.');
        require_once $this->class_file;

        $account = new Cashback_Price_Assistant_Account();
        $items = $account->add_menu_item(array(
            'dashboard'       => 'Dashboard',
            'customer-logout' => 'Logout',
        ));

        self::assertSame('Мониторинг цен', $items['price-assistant']);
        self::assertSame(array( 'dashboard', 'price-assistant', 'customer-logout' ), array_keys($items));
    }

    public function test_account_endpoint_schedules_one_shot_rewrite_flush_after_update(): void
    {
        self::assertFileExists($this->class_file, 'Price Assistant account UI class must exist.');
        require_once $this->class_file;

        $account = new Cashback_Price_Assistant_Account();

        self::assertTrue(
            method_exists($account, 'maybe_schedule_rewrite_flush'),
            'Price Assistant account endpoint must schedule a one-shot rewrite flush after code updates to avoid /my-account/price-assistant/ 404.'
        );

        $account->maybe_schedule_rewrite_flush();

        self::assertNotFalse(get_transient('cashback_flush_rewrite_rules'));
        self::assertSame('price-assistant-account-v1', get_option('cashback_price_assistant_rewrite_version'));
    }

    public function test_enqueue_assets_localizes_rest_and_connector_contract(): void
    {
        self::assertFileExists($this->class_file, 'Price Assistant account UI class must exist.');
        require_once $this->class_file;

        $account = new Cashback_Price_Assistant_Account();
        $account->enqueue_assets();

        self::assertArrayHasKey('cashback-price-assistant-account', $GLOBALS['_cb_test_enqueued_scripts']);
        self::assertArrayHasKey('CashbackPriceAssistantAccount', $GLOBALS['_cb_test_localized_scripts']['cashback-price-assistant-account']);

        $config = $GLOBALS['_cb_test_localized_scripts']['cashback-price-assistant-account']['CashbackPriceAssistantAccount'];
        self::assertSame('https://savelloclub.test/wp-json/cashback/v1/price-assistant', $config['restBase']);
        self::assertNotSame('', $config['nonce']);
        self::assertArrayHasKey('marketplaces', $config);
        self::assertArrayHasKey('ozon', $config['marketplaces']);
        self::assertSame(array(), $config['marketplaces']['ozon']['allowlist']['cookies']);
        self::assertSame(array(), $config['marketplaces']['ozon']['allowlist']['tokens']);
        self::assertArrayHasKey('login', $config['marketplaces']['ozon']['page_urls']);
        self::assertArrayHasKey('cart', $config['marketplaces']['ozon']['page_urls']);
        self::assertArrayHasKey('favorites', $config['marketplaces']['ozon']['page_urls']);
        self::assertArrayNotHasKey('hmac_secret', $config);
        self::assertArrayNotHasKey('price_monitor_base_url', $config);
        self::assertArrayNotHasKey('cookies', $config);
        self::assertArrayNotHasKey('tokens', $config);
        self::assertArrayNotHasKey('ciphertext', $config);
    }
}
