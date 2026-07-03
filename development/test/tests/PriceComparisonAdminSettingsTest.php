<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('price-comparison')]
final class PriceComparisonAdminSettingsTest extends TestCase {

    public static function setUpBeforeClass(): void {
        if (!function_exists('add_submenu_page')) {
            function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, callable $callback ): string {
                $GLOBALS['_cb_test_admin_submenus'][ $menu_slug ] = compact('parent_slug', 'page_title', 'menu_title', 'capability', 'callback');
                return $menu_slug;
            }
        }
        if (!function_exists('register_setting')) {
            function register_setting( string $option_group, string $option_name, array $args = array() ): bool {
                $GLOBALS['_cb_test_registered_settings'][ $option_name ] = compact('option_group', 'option_name', 'args');
                return true;
            }
        }
        if (!function_exists('checked')) {
            function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
                $result = $checked === $current ? ' checked="checked"' : '';
                if ($display) {
                    echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub mirrors WP helper output.
                }
                return $result;
            }
        }
        if (!function_exists('selected')) {
            function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
                $result = $selected === $current ? ' selected="selected"' : '';
                if ($display) {
                    echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub mirrors WP helper output.
                }
                return $result;
            }
        }
        if (!function_exists('settings_fields')) {
            function settings_fields( string $option_group ): void {
                echo '<input type="hidden" name="option_page" value="' . esc_attr($option_group) . '" />';
            }
        }
        if (!function_exists('submit_button')) {
            function submit_button( string $text = 'Сохранить' ): void {
                echo '<button type="submit">' . esc_html($text) . '</button>';
            }
        }
        if (!function_exists('esc_url')) {
            function esc_url( string $url ): string {
                return esc_attr($url);
            }
        }

        require_once dirname(__DIR__, 3) . '/includes/price-comparison/class-cashback-price-comparison-client.php';
        require_once dirname(__DIR__, 3) . '/admin/class-cashback-price-comparison-admin.php';
    }

    protected function setUp(): void {
        $GLOBALS['_cb_test_admin_submenus']       = array();
        $GLOBALS['_cb_test_registered_settings'] = array();
        $GLOBALS['_cb_test_http_calls']           = array();
        $GLOBALS['_cb_test_options']              = array(
            Cashback_Price_Comparison_Client::OPTION_ENABLED     => 1,
            Cashback_Price_Comparison_Client::OPTION_BASE_URL    => 'https://price-service.test',
            Cashback_Price_Comparison_Client::OPTION_HMAC_SECRET => 'secret-value',
            Cashback_Price_Comparison_Client::OPTION_TIMEOUT     => 5,
        );
        $GLOBALS['_cb_test_http_response']        = array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode(array(
                'status' => 'ok',
                'items'  => array(
                    array(
                        'id'            => 7,
                        'domain'        => 'ozon.ru',
                        'display_name'  => 'Ozon',
                        'active'        => true,
                        'source_type'   => 'custom',
                        'offer_count'   => 12,
                        'import_status' => array(
                            'status'         => 'success',
                            'imported_count' => 12,
                            'skipped_count'  => 1,
                        ),
                    ),
                ),
            )),
        );
    }

    public function test_register_menu_adds_russian_price_comparison_page(): void {
        Cashback_Price_Comparison_Admin::register_menu();

        $menu = $GLOBALS['_cb_test_admin_submenus'][ Cashback_Price_Comparison_Admin::PAGE_SLUG ] ?? null;
        if (is_array($menu)) {
            self::assertSame('cashback-overview', $menu['parent_slug']);
            self::assertSame('Сравнение цен', $menu['page_title']);
            self::assertSame('Сравнение цен', $menu['menu_title']);
            self::assertSame('manage_options', $menu['capability']);
            return;
        }

        $source = self::method_source(Cashback_Price_Comparison_Admin::class, 'register_menu');
        self::assertStringContainsString("add_submenu_page(\n            'cashback-overview'", $source);
        self::assertStringContainsString("'Сравнение цен'", $source);
        self::assertStringContainsString("'manage_options'", $source);
        self::assertStringContainsString('self::PAGE_SLUG', $source);
    }

    public function test_init_registers_store_admin_post_action(): void {
        $source = self::method_source(Cashback_Price_Comparison_Admin::class, 'init');

        self::assertStringContainsString('admin_post_cashback_price_comparison_store', $source);
        self::assertStringContainsString('handle_store_action', $source);
    }

    public function test_register_settings_keeps_secret_out_of_rest_and_sanitizes_values(): void {
        Cashback_Price_Comparison_Admin::register_settings();

        $secret = self::registered_setting(Cashback_Price_Comparison_Client::OPTION_HMAC_SECRET);
        self::assertFalse($secret['args']['show_in_rest']);
        self::assertSame(
            'abc123',
            call_user_func($secret['args']['sanitize_callback'], '<b>abc123</b>')
        );

        $timeout = self::registered_setting(Cashback_Price_Comparison_Client::OPTION_TIMEOUT);
        self::assertSame(15, call_user_func($timeout['args']['sanitize_callback'], 99));
        self::assertSame(1, call_user_func($timeout['args']['sanitize_callback'], -5));
    }

    public function test_render_page_includes_store_crud_controls_and_status(): void {
        ob_start();
        try {
            Cashback_Price_Comparison_Admin::render_page();
            $html = ob_get_clean();
        } catch (Throwable $throwable) {
            ob_end_clean();
            throw $throwable;
        }

        self::assertStringContainsString('Магазины поиска', $html);
        self::assertStringContainsString('name="domain"', $html);
        self::assertStringContainsString('name="display_name"', $html);
        self::assertStringContainsString('name="logo_url"', $html);
        self::assertStringContainsString('name="source_type"', $html);
        self::assertStringContainsString('admitad', $html);
        self::assertStringContainsString('advcake', $html);
        self::assertStringContainsString('custom', $html);
        self::assertStringContainsString('disabled', $html);
        self::assertStringContainsString('Последний импорт', $html);
        self::assertStringContainsString('Ozon', $html);
        self::assertStringContainsString('12', $html);
        self::assertStringContainsString('Деактивировать', $html);
        self::assertStringNotContainsString('secret-value', $html);
    }

    /**
     * Other test files may define the global register_setting() stub first.
     *
     * @return array{option_group?: string, option_name?: string, group?: string, name?: string, args: array}
     */
    private static function registered_setting( string $option_name ): array {
        $settings = $GLOBALS['_cb_test_registered_settings'] ?? array();
        if (isset($settings[ $option_name ]) && is_array($settings[ $option_name ])) {
            return $settings[ $option_name ];
        }

        foreach ($settings as $setting) {
            if (!is_array($setting)) {
                continue;
            }
            $registered_name = $setting['option_name'] ?? $setting['name'] ?? null;
            if ($registered_name === $option_name) {
                return $setting;
            }
        }

        self::fail('Registered setting not found: ' . $option_name);
    }

    private static function method_source( string $class_name, string $method_name ): string {
        $method = new ReflectionMethod($class_name, $method_name);
        $file   = $method->getFileName();
        self::assertIsString($file);
        $source = file($file);
        self::assertIsArray($source);

        return implode(
            '',
            array_slice(
                $source,
                (int) $method->getStartLine() - 1,
                (int) $method->getEndLine() - (int) $method->getStartLine() + 1
            )
        );
    }
}
