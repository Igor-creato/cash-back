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
        self::assertStringContainsString('data-price-assistant-search-form', $html);
        self::assertStringContainsString('data-price-assistant-search-results', $html);
        self::assertStringContainsString('data-price-assistant-marketplace-tabs', $html);
        self::assertMatchesRegularExpression(
            '/<nav[^>]+class="[^"]*cashback-price-assistant__tabs[^"]*cashback-support-tabs[^"]*"/',
            $html,
            'Price Assistant marketplace tabs must reuse the shared account tab skin.'
        );
        self::assertStringNotContainsString('data-price-assistant-tab="all"', $html);
        self::assertMatchesRegularExpression(
            '/<button[^>]+class="[^"]*cashback-support-tab[^"]*active[^"]*is-active[^"]*"[^>]+data-price-assistant-tab="ozon"/',
            $html,
            'The first enabled marketplace tab must expose both shared active and legacy is-active classes.'
        );
        self::assertStringContainsString('data-price-assistant-tab="wildberries"', $html);
        self::assertStringContainsString('data-price-assistant-tab="ozon"', $html);
        self::assertStringContainsString('data-price-assistant-tab="yandex_market"', $html);
        self::assertStringContainsString('data-price-assistant-source="ozon"', $html);
        self::assertStringContainsString('data-price-assistant-source="wildberries"', $html);
        self::assertStringContainsString('data-price-assistant-source="yandex_market"', $html);
        self::assertStringContainsString('data-price-assistant-settings', $html);
        self::assertStringContainsString('name="track_cart"', $html);
        self::assertStringContainsString('name="track_favorites"', $html);
        self::assertStringContainsString('name="track_manual"', $html);
        self::assertStringContainsString('name="track_all"', $html);
        self::assertStringNotContainsString('cashback-price-assistant__statuses', $html);
        self::assertStringNotContainsString('data-price-assistant-status=', $html);
        self::assertStringContainsString('data-price-assistant-add-form', $html);
        self::assertStringContainsString('data-price-assistant-region-form', $html);
        self::assertStringContainsString('data-price-assistant-manual-list', $html);
        self::assertStringContainsString('data-price-assistant-collection-list="cart"', $html);
        self::assertStringContainsString('data-price-assistant-collection-list="favorites"', $html);
        self::assertStringContainsString('data-price-assistant-pagination', $html);
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

        self::assertArrayHasKey('cashback-price-assistant-account', $GLOBALS['_cb_test_enqueued_styles']);
        self::assertSame(
            array( 'cashback-account-base' ),
            $GLOBALS['_cb_test_enqueued_styles']['cashback-price-assistant-account']['deps'],
            'Price Assistant CSS must load after the shared account base CSS that owns tab styles.'
        );
        self::assertArrayHasKey('cashback-price-assistant-account', $GLOBALS['_cb_test_enqueued_scripts']);
        self::assertContains(
            'cashback-pagination',
            $GLOBALS['_cb_test_enqueued_scripts']['cashback-price-assistant-account']['deps'],
            'Price Assistant account JS must reuse the shared pagination helper.'
        );
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

    public function test_account_script_keeps_last_payloads_and_filters_by_active_marketplace(): void
    {
        $script_path = dirname(__DIR__, 3) . '/assets/js/price-assistant-account.js';
        $script      = file_get_contents($script_path);

        self::assertIsString($script, 'Price Assistant account script must be readable.');
        self::assertStringContainsString('watchlistItems: []', $script);
        self::assertStringContainsString('collections: []', $script);
        self::assertStringContainsString('searchData: null', $script);
        self::assertStringContainsString('activeCollectionType: "cart"', $script);
        self::assertStringContainsString('const ITEMS_PER_PAGE = 10', $script);
        self::assertStringContainsString('CashbackPagination.build', $script);
        self::assertStringContainsString('.page-numbers[data-page]', $script);
        self::assertStringContainsString('function sourceMatchesActiveTab', $script);
        self::assertStringContainsString('function renderActiveWatchlist', $script);
        self::assertStringContainsString('function renderActiveCollections', $script);
        self::assertStringContainsString('function renderActiveSearchResults', $script);
        self::assertStringContainsString('normalizeSource(source) === "wb"', $script);
        self::assertStringContainsString('normalizeSource(source) === "yandex"', $script);
        self::assertStringContainsString('button.classList.toggle("active"', $script);
    }
}
