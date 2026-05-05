<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты на Cashback_Coupons_Icons_Admin — settings + UI блока на вкладке
 * «Шорткоды» админки плагина.
 *
 * Покрывает:
 *  - register_setting именем 'cashback_coupons_icons_settings' и
 *    группой 'cashback_coupons_icons_group'
 *  - sanitize: положительный attachment_id, 0, не-image, отсутствующий ключ
 *  - render_section() выводит <details> + 3 picker-поля + form action="options.php"
 *
 * @group promocodes
 * @group coupons-icons
 * @group admin
 */
#[Group('promocodes')]
#[Group('coupons-icons')]
#[Group('admin')]
final class CouponsIconsAdminTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        $files = array(
            '/includes/promocodes/class-cashback-coupons-icons-admin.php',
        );
        foreach ($files as $f) {
            $path = self::$plugin_root . $f;
            if (!file_exists($path)) {
                self::markTestSkipped("File missing: {$f}");
            }
            require_once $path;
        }

        if (!function_exists('register_setting')) {
            function register_setting(string $option_group, string $option_name, array $args = array()): void {
                $GLOBALS['_cb_test_registered_settings'][] = array(
                    'group' => $option_group,
                    'name'  => $option_name,
                    'args'  => $args,
                );
            }
        }
        if (!function_exists('wp_attachment_is_image')) {
            function wp_attachment_is_image(int $aid): bool {
                $store = $GLOBALS['_cb_test_attachments_image'] ?? array();
                return !empty($store[$aid]);
            }
        }
        if (!function_exists('wp_get_attachment_image_url')) {
            function wp_get_attachment_image_url(int $aid, string $size = 'thumbnail'): string|false {
                $store = $GLOBALS['_cb_test_attachments'] ?? array();
                return isset($store[$aid]) ? (string) $store[$aid] : false;
            }
        }
        if (!function_exists('settings_fields')) {
            function settings_fields(string $option_group): void {
                echo '<input type="hidden" name="option_page" value="' . htmlspecialchars($option_group, ENT_QUOTES) . '" />';
            }
        }
        if (!function_exists('submit_button')) {
            function submit_button(string $text = 'Сохранить'): void {
                echo '<button type="submit" class="button button-primary">' . htmlspecialchars($text, ENT_QUOTES) . '</button>';
            }
        }
        if (!function_exists('esc_js')) {
            function esc_js(string $text): string {
                return addslashes($text);
            }
        }
        if (!function_exists('esc_url')) {
            function esc_url(string $url): string {
                return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            }
        }
        if (!function_exists('current_user_can')) {
            function current_user_can(string $cap): bool {
                return true;
            }
        }
        if (!function_exists('esc_html_e')) {
            function esc_html_e(string $text, string $domain = ''): void {
                echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }
        if (!function_exists('esc_attr__')) {
            function esc_attr__(string $text, string $domain = ''): string {
                return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_registered_settings']  = array();
        $GLOBALS['_cb_test_attachments']          = array();
        $GLOBALS['_cb_test_attachments_image']    = array();
        $GLOBALS['_cb_test_options']              = array();
    }

    public function test_register_setting_called_with_expected_group_and_name(): void
    {
        Cashback_Coupons_Icons_Admin::register_settings();

        $found = array_filter(
            $GLOBALS['_cb_test_registered_settings'],
            fn($s) => $s['group'] === 'cashback_coupons_icons_group'
                  && $s['name']  === 'cashback_coupons_icons_settings'
        );
        $this->assertNotEmpty($found);

        $first = array_values($found)[0];
        $this->assertArrayHasKey('sanitize_callback', $first['args']);
        $this->assertIsCallable($first['args']['sanitize_callback']);
    }

    public function test_sanitize_keeps_positive_image_attachment_ids(): void
    {
        $GLOBALS['_cb_test_attachments_image'][101] = true;
        $GLOBALS['_cb_test_attachments_image'][102] = true;
        $GLOBALS['_cb_test_attachments_image'][103] = true;

        $out = Cashback_Coupons_Icons_Admin::sanitize(array(
            'discount'      => '101',
            'gift'          => 102,
            'free_shipping' => 103,
        ));

        $this->assertSame(101, $out['discount']);
        $this->assertSame(102, $out['gift']);
        $this->assertSame(103, $out['free_shipping']);
    }

    public function test_sanitize_zeros_invalid_attachment_ids(): void
    {
        // 200 — не-image, 0 — пусто, missing key.
        $GLOBALS['_cb_test_attachments_image'][101] = true;
        $GLOBALS['_cb_test_attachments_image'][200] = false; // не-image

        $out = Cashback_Coupons_Icons_Admin::sanitize(array(
            'discount' => 101,
            'gift'     => 200,
            // free_shipping отсутствует
        ));

        $this->assertSame(101, $out['discount']);
        $this->assertSame(0,   $out['gift']);
        $this->assertSame(0,   $out['free_shipping']);
    }

    public function test_sanitize_with_non_array_returns_zero_set(): void
    {
        $out = Cashback_Coupons_Icons_Admin::sanitize('garbage');

        $this->assertSame(0, $out['discount']);
        $this->assertSame(0, $out['gift']);
        $this->assertSame(0, $out['free_shipping']);
    }

    public function test_render_section_outputs_form_with_options_action(): void
    {
        $GLOBALS['_cb_test_options']['cashback_coupons_icons_settings'] = array(
            'discount'      => 0,
            'gift'          => 0,
            'free_shipping' => 0,
        );

        ob_start();
        Cashback_Coupons_Icons_Admin::render_section();
        $html = ob_get_clean();

        $this->assertNotEmpty($html);
        $this->assertStringContainsString('cashback_coupons_icons', $html);
        $this->assertStringContainsString('action="options.php"', $html);
        $this->assertStringContainsString('cb-shortcode-section', $html);
    }

    public function test_render_section_includes_three_picker_buttons(): void
    {
        $GLOBALS['_cb_test_options']['cashback_coupons_icons_settings'] = array(
            'discount' => 0, 'gift' => 0, 'free_shipping' => 0,
        );

        ob_start();
        Cashback_Coupons_Icons_Admin::render_section();
        $html = ob_get_clean();

        // По одной кнопке-picker'у на каждый icon_type (в JS этот атрибут тоже встречается, но без button).
        $this->assertSame(3, preg_match_all('/<button[^>]*data-cashback-coupons-icon-picker=/i', $html));
        $this->assertStringContainsString('cashback_coupons_icons_settings[discount]', $html);
        $this->assertStringContainsString('cashback_coupons_icons_settings[gift]', $html);
        $this->assertStringContainsString('cashback_coupons_icons_settings[free_shipping]', $html);
    }

    public function test_render_section_outputs_preview_when_attachment_set(): void
    {
        $GLOBALS['_cb_test_options']['cashback_coupons_icons_settings'] = array(
            'discount'      => 101,
            'gift'          => 0,
            'free_shipping' => 0,
        );
        $GLOBALS['_cb_test_attachments'][101] = 'https://example.test/uploads/coupon.svg';

        ob_start();
        Cashback_Coupons_Icons_Admin::render_section();
        $html = ob_get_clean();

        $this->assertStringContainsString('https://example.test/uploads/coupon.svg', $html);
    }
}
