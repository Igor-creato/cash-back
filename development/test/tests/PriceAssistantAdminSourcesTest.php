<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('price-assistant-admin-sources')]
final class PriceAssistantAdminSourcesTest extends TestCase
{
    private const SECRET = 'price-monitor-secret';

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('add_submenu_page')) {
            function add_submenu_page($parent, $title, $menu, $cap, $slug, $callback): string
            {
                $GLOBALS['_cb_test_submenu_pages'][] = compact('parent', 'title', 'menu', 'cap', 'slug', 'callback');
                return 'cashback_page_' . (string) $slug;
            }
        }
        if (!function_exists('wp_enqueue_media')) {
            function wp_enqueue_media(): void
            {
                $GLOBALS['_cb_test_media_enqueued'] = true;
            }
        }

        $root = dirname(__DIR__, 3);
		self::assertFileExists($root . '/includes/rest/class-cashback-price-assistant-admin-rest-controller.php');
        self::assertFileExists($root . '/admin/class-cashback-price-assistant-admin.php');

        require_once $root . '/includes/services/class-price-assistant-proxy-client.php';
		require_once $root . '/includes/rest/class-cashback-price-assistant-admin-rest-controller.php';
        require_once $root . '/admin/class-cashback-price-assistant-admin.php';

        $GLOBALS['_cb_test_options'] = array();
        $GLOBALS['_cb_test_http_calls'] = array();
        $GLOBALS['_cb_test_http_response_callback'] = null;
        $GLOBALS['_cb_test_submenu_pages'] = array();
        $GLOBALS['_cb_test_current_user_can'] = true;
        $GLOBALS['_cb_test_enqueued_scripts'] = array();
        $GLOBALS['_cb_test_localized_scripts'] = array();

        update_option('price_monitor_enabled', 1);
        update_option('price_monitor_base_url', 'https://price-monitor.test');
        update_option('price_monitor_site_id', 'savelloclub.test');
        update_option('price_monitor_hmac_secret', self::SECRET);
    }

    public function test_admin_page_registers_under_cashback_overview_with_russian_labels(): void
    {
        $admin = new Cashback_Price_Assistant_Admin();

        $admin->register_menu();
        ob_start();
        $admin->render_page();
        $html = (string) ob_get_clean();

        if (isset($GLOBALS['_cb_test_submenu_pages'][0])) {
            self::assertSame('cashback-overview', $GLOBALS['_cb_test_submenu_pages'][0]['parent']);
            self::assertSame('Источники Price Assistant', $GLOBALS['_cb_test_submenu_pages'][0]['title']);
            self::assertSame('Источники Price Assistant', $GLOBALS['_cb_test_submenu_pages'][0]['menu']);
            self::assertSame('manage_options', $GLOBALS['_cb_test_submenu_pages'][0]['cap']);
            self::assertSame('cashback-price-assistant-sources', $GLOBALS['_cb_test_submenu_pages'][0]['slug']);
        } else {
            $root         = dirname(__DIR__, 3);
            $admin_source = file_get_contents($root . '/admin/class-cashback-price-assistant-admin.php');

            self::assertIsString($admin_source);
            self::assertStringContainsString("'cashback-overview'", $admin_source);
            self::assertStringContainsString("'Источники Price Assistant'", $admin_source);
            self::assertStringContainsString("'manage_options'", $admin_source);
            self::assertStringContainsString("'cashback-price-assistant-sources'", $admin_source);
        }

        foreach ($this->expectedTabs() as $label) {
            self::assertStringContainsString($label, $html);
        }
        self::assertStringContainsString('URL главной страницы магазина', $html);
        self::assertStringContainsString('Название магазина', $html);
        self::assertStringContainsString('Логотип магазина', $html);
        self::assertStringContainsString('data-pa-logo-upload', $html);
        self::assertStringContainsString('data-pa-logo-remove', $html);
        self::assertStringContainsString('data-pa-logo-preview', $html);
        self::assertStringContainsString('name="editing_store_id"', $html);
        self::assertStringContainsString('data-pa-store-submit-label', $html);
        self::assertStringContainsString('data-pa-store-cancel-edit', $html);
        self::assertStringContainsString('data-pa-store-pagination', $html);
        self::assertStringContainsString('name="display_name"', $html);
        self::assertStringContainsString('name="logo_url"', $html);
        self::assertMatchesRegularExpression('/<input[^>]+name="display_name"[^>]+required/s', $html);
        self::assertStringContainsString('Сохранить магазин', $html);
        self::assertStringContainsString('Отменить изменения', $html);
        self::assertStringNotContainsString('data-pa-action="add-store"', $html);
        self::assertStringNotContainsString('data-pa-action="refresh"', $html);
        self::assertStringNotContainsString('type="checkbox"', $html);
        self::assertStringNotContainsString('Сохранить источник', $html);
        self::assertStringNotContainsString('data-pa-source-form', $html);
        self::assertStringNotContainsString('data-pa-store-select', $html);
        self::assertStringNotContainsString('Код источника', $html);
        self::assertStringNotContainsString('Шаблон поиска', $html);
        self::assertStringContainsString('мониторинг работает по включённым магазинам', $html);
        self::assertStringContainsString('Корзина и избранное только Ozon/Wildberries/Яндекс Маркет', $html);
        self::assertStringNotContainsString('price_monitor_hmac_secret', $html);
        self::assertStringNotContainsString(self::SECRET, $html);
        self::assertStringNotContainsString('https://price-monitor.test', $html);
        self::assertStringNotContainsString('cookie', strtolower($html));
        self::assertStringNotContainsString('token', strtolower($html));
    }

    public function test_admin_assets_use_wordpress_media_for_logo_upload(): void
    {
        $root         = dirname(__DIR__, 3);
        $admin_source = file_get_contents($root . '/admin/class-cashback-price-assistant-admin.php');
        $script       = file_get_contents($root . '/admin/js/price-assistant-admin.js');

        self::assertIsString($admin_source);
        self::assertIsString($script);
        self::assertStringContainsString('wp_enqueue_media()', $admin_source);
        self::assertStringContainsString('wp.media', $script);
        self::assertStringContainsString('library: { type: "image" }', $script);
        self::assertStringContainsString('data-pa-logo-upload', $script);
        self::assertStringContainsString('data-pa-logo-preview', $script);
        self::assertStringContainsString('logo_url: form.get(\'logo_url\') || null', $script);
        self::assertStringContainsString('display_name: form.get(\'display_name\')', $script);
        self::assertStringContainsString('cashback-pa-store-logo', $script);
    }

    public function test_admin_assets_depend_on_shared_pagination_helper(): void
    {
        $admin = new Cashback_Price_Assistant_Admin();

        $admin->enqueue_assets('cashback-overview_page_cashback-price-assistant-sources');

        self::assertArrayHasKey('cashback-price-assistant-admin', $GLOBALS['_cb_test_enqueued_scripts']);
        self::assertContains(
            'cashback-pagination',
            $GLOBALS['_cb_test_enqueued_scripts']['cashback-price-assistant-admin']['deps']
        );
    }

    public function test_admin_rest_permission_requires_manage_options_and_wp_rest_nonce(): void
    {
        $controller = new Cashback_Price_Assistant_Admin_REST_Controller();
        $request = $this->request('GET', '/cashback/v1/price-assistant/admin/stores');
        $request->set_header('X-WP-Nonce', '');

        $missing_nonce = $controller->check_admin_permission($request);

        self::assertInstanceOf(WP_Error::class, $missing_nonce);
        self::assertSame('price_assistant_admin_nonce_required', $missing_nonce->get_error_code());
        self::assertSame(403, $missing_nonce->get_error_data()['status'] ?? null);

        $GLOBALS['_cb_test_current_user_can'] = false;
        $request->set_header('X-WP-Nonce', 'test_nonce_' . md5('wp_rest'));

        $missing_capability = $controller->check_admin_permission($request);

        self::assertInstanceOf(WP_Error::class, $missing_capability);
        self::assertSame('price_assistant_admin_forbidden', $missing_capability->get_error_code());
        self::assertSame(403, $missing_capability->get_error_data()['status'] ?? null);
    }

    public function test_admin_rest_proxies_to_hmac_backend_without_secret_display(): void
    {
        $GLOBALS['_cb_test_http_response'] = array(
            'body' => '{"items":[{"store_id":1,"store_code":"dns","display_name":"DNS","sources":[]}]}',
            'response' => array('code' => 200, 'message' => 'OK'),
            'headers' => array(),
        );
        $controller = new Cashback_Price_Assistant_Admin_REST_Controller(
            new Cashback_Price_Assistant_Proxy_Client(static fn(): int => 1781516800)
        );

        $request = $this->request('GET', '/cashback/v1/price-assistant/admin/stores');
        $request->set_param('page', '2');
        $request->set_param('per_page', '20');

        $response = $controller->proxy_get_stores($request);
        $encoded = wp_json_encode($response->get_data());

        self::assertSame(200, $response->get_status());
        self::assertSame(
            'https://price-monitor.test/v1/price-assistant/admin/stores?page=2&per_page=20',
            $GLOBALS['_cb_test_http_calls'][0]['url']
        );
        self::assertSame('GET', $GLOBALS['_cb_test_http_calls'][0]['args']['method']);
        self::assertArrayHasKey('X-Savello-Signature', $GLOBALS['_cb_test_http_calls'][0]['args']['headers']);
        self::assertStringNotContainsString(self::SECRET, (string) $encoded);
        self::assertStringNotContainsString('price-monitor.test', (string) $encoded);
    }

    public function test_admin_routes_are_registered_for_all_sections(): void
    {
        $GLOBALS['_cb_test_rest_routes'] = array();
        $controller = new Cashback_Price_Assistant_Admin_REST_Controller();

        $controller->register_routes();

        foreach (
            array(
                '/price-assistant/admin/stores',
                '/price-assistant/admin/stores/(?P<store_id>\d+)',
                '/price-assistant/admin/stores/(?P<store_id>\d+)/sources',
                '/price-assistant/admin/stores/(?P<store_id>\d+)/sources/(?P<source_id>\d+)',
                '/price-assistant/admin/source-health',
                '/price-assistant/admin/fetch-attempts',
                '/price-assistant/admin/sync-diagnostics',
                '/price-assistant/admin/quarantine',
                '/price-assistant/admin/proxy-economics',
                '/price-assistant/admin/matching-diagnostics',
            ) as $route
        ) {
            self::assertArrayHasKey('cashback/v1' . $route, $GLOBALS['_cb_test_rest_routes']);
        }
    }

    private function request(string $method, string $route, array $body = array()): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $route);
        $request->set_header('X-WP-Nonce', 'test_nonce_' . md5('wp_rest'));
        if ($body !== array()) {
            $request->set_body((string) wp_json_encode($body));
            foreach ($body as $key => $value) {
                $request->set_param((string) $key, $value);
            }
        }
        return $request;
    }

    /**
     * @return list<string>
     */
    private function expectedTabs(): array
    {
        return array(
            'Магазины',
            'Здоровье источников',
            'Попытки загрузки',
            'Диагностика синхронизации',
            'Карантин',
            'Экономика прокси',
            'Диагностика сопоставления',
        );
    }
}
