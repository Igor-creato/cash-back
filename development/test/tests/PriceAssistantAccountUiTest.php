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
        self::assertStringContainsString('data-price-assistant-view-tabs', $html);
        self::assertStringContainsString('data-price-assistant-view="link"', $html);
        self::assertStringContainsString('data-price-assistant-view="cart"', $html);
        self::assertStringContainsString('data-price-assistant-view="compare"', $html);
        self::assertStringContainsString('Мониторинг по ссылке', $html);
        self::assertStringContainsString('Мониторинг по корзине', $html);
        self::assertStringContainsString('Сравнение цен', $html);
        self::assertMatchesRegularExpression(
            '/<section[^>]+data-price-assistant-panel="link"(?![^>]*hidden)/',
            $html,
            'Product-link monitoring panel must be visible by default so added watchlist cards render in the cabinet.'
        );
        self::assertMatchesRegularExpression(
            '/<section[^>]+data-price-assistant-panel="cart"[^>]+hidden/',
            $html,
            'Cart monitoring panel must be behind the second tab by default.'
        );
        self::assertMatchesRegularExpression(
            '/<section[^>]+data-price-assistant-panel="compare"[^>]+hidden/',
            $html,
            'Comparison/search panel must be behind the third tab by default.'
        );
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
            '/<button[^>]+class="[^"]*cashback-support-tab[^"]*active[^"]*is-active[^"]*"[^>]+data-price-assistant-tab="wildberries"/',
            $html,
            'The first enabled marketplace tab must expose both shared active and legacy is-active classes.'
        );
        self::assertStringContainsString('data-price-assistant-tab="wildberries"', $html);
        self::assertStringContainsString('data-price-assistant-tab="ozon"', $html);
        self::assertStringContainsString('data-price-assistant-tab="yandex_market"', $html);
        self::assertStringContainsString('data-price-assistant-source="ozon"', $html);
        self::assertStringContainsString('data-price-assistant-source="wildberries"', $html);
        self::assertStringContainsString('data-price-assistant-source="yandex_market"', $html);
        self::assertStringNotContainsString('data-price-assistant-settings', $html);
        self::assertStringNotContainsString('name="track_cart"', $html);
        self::assertStringNotContainsString('name="track_favorites"', $html);
        self::assertStringNotContainsString('name="track_manual"', $html);
        self::assertStringNotContainsString('name="track_all"', $html);
        self::assertStringNotContainsString('cashback-price-assistant__statuses', $html);
        self::assertStringNotContainsString('data-price-assistant-status=', $html);
        self::assertStringContainsString('data-price-assistant-add-form', $html);
        self::assertStringContainsString('data-cashback-product-link-form', $html);
        self::assertStringContainsString('name="direct_url"', $html);
        self::assertStringContainsString('Проверить кэшбэк', $html);
        self::assertStringNotContainsString('data-price-assistant-region-form', $html);
        self::assertStringContainsString('data-price-assistant-manual-list', $html);
        self::assertStringContainsString('data-price-assistant-collection-list="cart"', $html);
        self::assertStringContainsString('data-price-assistant-collection-list="favorites"', $html);
        self::assertStringContainsString('data-price-assistant-pagination', $html);
        self::assertStringContainsString('data-price-assistant-chart', $html);
        self::assertStringContainsString('data-price-assistant-compare', $html);
        self::assertStringContainsString('data-price-assistant-target-price', $html);
        self::assertStringNotContainsString('data-price-assistant-target-effective-price', $html);
        self::assertStringNotContainsString('name="target_effective_price"', $html);
        self::assertStringContainsString('cashback-btn-primary', $html);
        self::assertStringNotContainsString('cashback-price-assistant__primary-button', $html);
        self::assertStringContainsString('Не авторизован', $html);
        self::assertStringNotContainsString('disconnected', $html);
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
        self::assertArrayHasKey('cashback-product-link-form', $GLOBALS['_cb_test_enqueued_scripts']);
        self::assertContains(
            'cashback-pagination',
            $GLOBALS['_cb_test_enqueued_scripts']['cashback-price-assistant-account']['deps'],
            'Price Assistant account JS must reuse the shared pagination helper.'
        );
        self::assertArrayHasKey('CashbackPriceAssistantAccount', $GLOBALS['_cb_test_localized_scripts']['cashback-price-assistant-account']);
        self::assertArrayHasKey('CashbackProductLinkForm', $GLOBALS['_cb_test_localized_scripts']['cashback-product-link-form']);

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

        $product_link_config = $GLOBALS['_cb_test_localized_scripts']['cashback-product-link-form']['CashbackProductLinkForm'];
        self::assertSame('https://savelloclub.test/wp-json/cashback/v1/product-link/resolve', $product_link_config['endpoint']);
        self::assertNotSame('', $product_link_config['nonce']);
    }

    public function test_ozon_connection_is_blocked_until_official_browser_oauth_exists(): void
    {
        self::assertFileExists($this->class_file, 'Price Assistant account UI class must exist.');
        require_once $this->class_file;

        $account = new Cashback_Price_Assistant_Account();
        $account->enqueue_assets();

        $config = $GLOBALS['_cb_test_localized_scripts']['cashback-price-assistant-account']['CashbackPriceAssistantAccount'];
        self::assertSame('requires_official_access', $config['marketplaces']['ozon']['access_status']);
        self::assertFalse($config['marketplaces']['ozon']['enabled']);
        self::assertStringContainsString('официальный доступ Ozon', $config['marketplaces']['ozon']['disabled_reason']);

        ob_start();
        $account->render_endpoint();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('data-marketplace-card="ozon"', $html);
        self::assertStringContainsString('data-marketplace-access-status="requires_official_access"', $html);
        self::assertStringContainsString('Требуется официальный доступ Ozon', $html);
        self::assertMatchesRegularExpression(
            '/<button[^>]+class="[^"]*cashback-price-assistant__connect[^"]*"[^>]+data-marketplace="ozon"[^>]+disabled/',
            $html,
            'Ozon connect button must not be usable until official consumer OAuth/API access is approved.'
        );
    }

    public function test_account_script_keeps_last_payloads_and_filters_by_active_marketplace(): void
    {
        $script_path = dirname(__DIR__, 3) . '/assets/js/price-assistant-account.js';
        $script      = file_get_contents($script_path);

        self::assertIsString($script, 'Price Assistant account script must be readable.');
        self::assertStringContainsString('watchlistItems: []', $script);
        self::assertStringContainsString('activeView: "link"', $script);
        self::assertStringContainsString('collections: []', $script);
        self::assertStringContainsString('searchData: null', $script);
        self::assertStringContainsString('activeCollectionType: "cart"', $script);
        self::assertStringContainsString('const ITEMS_PER_PAGE = 10', $script);
        self::assertStringContainsString('CashbackPagination.build', $script);
        self::assertStringContainsString('.page-numbers[data-page]', $script);
        self::assertStringContainsString('function sourceMatchesActiveTab', $script);
        self::assertStringContainsString('function applyActiveView', $script);
        self::assertStringContainsString('function renderActiveWatchlist', $script);
        self::assertStringContainsString('function renderActiveCollections', $script);
        self::assertStringContainsString('function renderActiveSearchResults', $script);
        self::assertStringContainsString('function loadInlineChart', $script);
        self::assertStringContainsString('function productImageUrl', $script);
        self::assertStringContainsString('function sourceLogoUrl', $script);
        self::assertStringContainsString('function renderStoreBrand', $script);
        self::assertStringContainsString('function productPrice', $script);
        self::assertStringContainsString('function buyProduct', $script);
        self::assertStringContainsString('data-price-assistant-item-chart', $script);
        self::assertStringContainsString('data-price-assistant-delete-card', $script);
        self::assertStringContainsString('title", "Удалить"', $script);
        self::assertStringContainsString('cashback-price-assistant__chart-price-label', $script);
        self::assertStringContainsString('cashback-price-assistant__chart-axis-label', $script);
        self::assertStringContainsString('cashback-price-assistant__item-store-logo', $script);
        self::assertStringContainsString('cashback-price-assistant__item-store-name', $script);
        self::assertStringContainsString('image.addEventListener("error"', $script);
        self::assertStringContainsString('appendAction(actions, "buy", "Купить", "cashback-btn-primary")', $script);
        self::assertStringContainsString('renderWatchlist(state.watchlistItems)', $script);
        self::assertStringNotContainsString('appendAction(actions, "save-targets"', $script);
        self::assertStringNotContainsString('appendAction(actions, "compare"', $script);
        self::assertStringNotContainsString('appendAction(actions, "cashback"', $script);
        self::assertStringNotContainsString('appendAction(actions, "remove-manual"', $script);
        self::assertStringContainsString('normalizeSource(source) === "wb"', $script);
        self::assertStringContainsString('normalizeSource(source) === "yandex"', $script);
        self::assertStringContainsString('button.classList.toggle("active"', $script);
    }

    public function test_account_styles_use_shared_button_skin_without_yellow_search_hero(): void
    {
        $style_path = dirname(__DIR__, 3) . '/assets/css/price-assistant-account.css';
        $styles     = file_get_contents($style_path);
        $base_styles = file_get_contents(dirname(__DIR__, 3) . '/assets/css/cashback-account-base.css');

        self::assertIsString($styles, 'Price Assistant account styles must be readable.');
        self::assertIsString($base_styles, 'Shared account base styles must be readable.');
        self::assertStringContainsString('.cashback-btn-primary', $base_styles);
        self::assertStringContainsString('.cashback-price-assistant__delete-card', $styles);
        self::assertStringNotContainsString('.cashback-price-assistant__primary-button', $styles);
        self::assertStringContainsString('.cashback-price-assistant__inline-chart', $styles);
        self::assertStringContainsString('.cashback-price-assistant__item-footer', $styles);
        self::assertStringContainsString('.cashback-price-assistant__item-store-logo', $styles);
        self::assertStringContainsString('.cashback-price-assistant__item-store-name', $styles);
        self::assertStringContainsString('.cashback-price-assistant__chart-extreme-line', $styles);
        self::assertStringContainsString('.cashback-price-assistant__chart-extreme-label', $styles);
        self::assertStringContainsString('.cashback-price-assistant__chart-price-label', $styles);
        self::assertStringContainsString('.cashback-price-assistant__chart-axis-label', $styles);
        self::assertStringContainsString('object-fit: contain', $styles);
        self::assertStringContainsString('grid-template-columns: repeat(3, 264px);', $styles);
        self::assertStringContainsString('width: 264px;', $styles);
        self::assertStringContainsString('left: auto;', $styles);
        self::assertStringContainsString('inset-inline-end: 10px;', $styles);
        self::assertStringContainsString('font-size: 14px;', $styles);
        self::assertStringContainsString('font-size: 16px;', $styles);
        self::assertStringContainsString('height: 118px;', $styles);
        self::assertMatchesRegularExpression(
            '/\.cashback-price-assistant__chart-line\s*\{[^}]*stroke-width:\s*5;/s',
            $styles,
            'The in-card price chart line must be thick enough to read.'
        );
        self::assertMatchesRegularExpression(
            '/\.cashback-price-assistant__chart-extreme-label\s*\{[^}]*font-size:\s*18px;/s',
            $styles,
            'The min/max labels inside the chart must use 18px text.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.cashback-price-assistant__chart-(?:average|extreme-line)\s*\{[^}]*stroke-dasharray/s',
            $styles,
            'The visible chart track must be solid, not dashed.'
        );
        self::assertStringNotContainsString('max-height: 180px', $styles);
        self::assertStringContainsString('cashback-price-assistant__form--link', $styles);
        self::assertStringNotContainsString('linear-gradient(180deg, #f5c84d', $styles);
    }
}
