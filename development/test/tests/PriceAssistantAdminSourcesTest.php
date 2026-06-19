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

        $root = dirname(__DIR__, 3);
        self::assertFileExists($root . '/includes/rest/class-price-assistant-admin-rest-controller.php');
        self::assertFileExists($root . '/admin/class-cashback-price-assistant-admin.php');

        require_once $root . '/includes/services/class-price-assistant-proxy-client.php';
        require_once $root . '/includes/rest/class-price-assistant-admin-rest-controller.php';
        require_once $root . '/admin/class-cashback-price-assistant-admin.php';

        $GLOBALS['_cb_test_options'] = array();
        $GLOBALS['_cb_test_http_calls'] = array();
        $GLOBALS['_cb_test_http_response_callback'] = null;
        $GLOBALS['_cb_test_submenu_pages'] = array();
        $GLOBALS['_cb_test_current_user_can'] = true;
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

        self::assertSame('cashback-overview', $GLOBALS['_cb_test_submenu_pages'][0]['parent']);
        self::assertSame('Источники Price Assistant', $GLOBALS['_cb_test_submenu_pages'][0]['title']);
        self::assertSame('Источники Price Assistant', $GLOBALS['_cb_test_submenu_pages'][0]['menu']);
        self::assertSame('manage_options', $GLOBALS['_cb_test_submenu_pages'][0]['cap']);
        self::assertSame('cashback-price-assistant-sources', $GLOBALS['_cb_test_submenu_pages'][0]['slug']);

        foreach ($this->expectedTabs() as $label) {
            self::assertStringContainsString($label, $html);
        }
        self::assertStringContainsString('Добавить магазин', $html);
        self::assertStringContainsString('Сохранить источник', $html);
        self::assertStringNotContainsString('price_monitor_hmac_secret', $html);
        self::assertStringNotContainsString(self::SECRET, $html);
        self::assertStringNotContainsString('https://price-monitor.test', $html);
        self::assertStringNotContainsString('cookie', strtolower($html));
        self::assertStringNotContainsString('token', strtolower($html));
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

        $response = $controller->proxy_get_stores($this->request('GET', '/cashback/v1/price-assistant/admin/stores'));
        $encoded = wp_json_encode($response->get_data());

        self::assertSame(200, $response->get_status());
        self::assertSame('https://price-monitor.test/v1/price-assistant/admin/stores', $GLOBALS['_cb_test_http_calls'][0]['url']);
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
