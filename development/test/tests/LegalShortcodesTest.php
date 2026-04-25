<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Legal_Shortcodes (Phase 2).
 *
 * Покрывает базовые контракты без полного WP-runtime:
 *  - render_doc для неизвестного типа → ''
 *  - render_operator при незаполненных реквизитах для гостя → ''
 *  - render_operator при незаполненных реквизитах для админа → warning-block
 *  - render_operator при заполненных реквизитах → HTML с данными
 *  - render_footer_block при незаполненных реквизитах для гостя → ''
 */
#[Group('legal')]
#[Group('legal-shortcodes')]
final class LegalShortcodesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/legal/class-cashback-legal-documents.php';
        require_once $plugin_root . '/legal/class-cashback-legal-operator.php';

        // Минимальные стабы WP-функций, нужные шорткодам и не объявленные в bootstrap.
        if (!function_exists('shortcode_atts')) {
            function shortcode_atts( array $pairs, array $atts, string $shortcode = '' ): array {
                return array_merge($pairs, $atts);
            }
        }
        if (!function_exists('add_shortcode')) {
            function add_shortcode( string $tag, callable $callback ): bool {
                return true;
            }
        }
        if (!function_exists('wp_kses_post')) {
            function wp_kses_post( string $content ): string {
                return $content;
            }
        }
        if (!function_exists('esc_url')) {
            function esc_url( string $url ): string {
                return $url;
            }
        }
        if (!function_exists('get_permalink')) {
            function get_permalink( int $post_id ): string {
                return 'http://localhost/?p=' . $post_id;
            }
        }
        if (!function_exists('get_post_status')) {
            function get_post_status( int $post_id ) {
                return $GLOBALS['_cb_test_post_statuses'][ $post_id ] ?? false;
            }
        }

        require_once $plugin_root . '/legal/class-cashback-legal-pages-installer.php';
        require_once $plugin_root . '/legal/class-cashback-legal-shortcodes.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']         = array();
        $GLOBALS['_cb_test_current_user_can'] = false;
        $GLOBALS['_cb_test_post_statuses']    = array();
    }

    public function test_render_doc_returns_empty_for_unknown_type(): void
    {
        $output = Cashback_Legal_Shortcodes::render_doc(array( 'type' => 'unknown' ));
        $this->assertSame('', $output);
    }

    public function test_render_doc_returns_html_for_known_type(): void
    {
        $output = Cashback_Legal_Shortcodes::render_doc(array( 'type' => 'pd_consent' ));
        // Без реквизитов оператора шаблон вернётся с placeholder'ами.
        $this->assertNotSame('', $output);
        $this->assertStringContainsString('cashback-legal-document', $output);
    }

    public function test_render_doc_renders_operator_data_when_configured(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'ООО «Тест»',
            'org_form'      => 'ЮЛ',
            'ogrn'          => '1234567890123',
            'inn'           => '1234567890',
            'legal_address' => 'Москва',
            'contact_email' => 'admin@example.com',
        ));
        $output = Cashback_Legal_Shortcodes::render_doc(array( 'type' => 'pd_consent' ));
        $this->assertStringContainsString('ООО «Тест»', $output);
        $this->assertStringContainsString('1234567890123', $output);
    }

    public function test_render_operator_empty_for_guest_when_unconfigured(): void
    {
        $GLOBALS['_cb_test_current_user_can'] = false;
        $output = Cashback_Legal_Shortcodes::render_operator();
        $this->assertSame('', $output);
    }

    public function test_render_operator_warning_for_admin_when_unconfigured(): void
    {
        $GLOBALS['_cb_test_current_user_can'] = true;
        $output = Cashback_Legal_Shortcodes::render_operator();
        $this->assertStringContainsString('cashback-legal-not-configured', $output);
    }

    public function test_render_operator_renders_data_when_configured(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'ООО «Тест»',
            'org_form'      => 'ЮЛ',
            'ogrn'          => '1234567890123',
            'inn'           => '1234567890',
            'legal_address' => '123456, Москва',
            'contact_email' => 'admin@example.com',
        ));
        $output = Cashback_Legal_Shortcodes::render_operator();
        $this->assertStringContainsString('ООО «Тест»', $output);
        $this->assertStringContainsString('1234567890123', $output);
        $this->assertStringContainsString('123456, Москва', $output);
        $this->assertStringContainsString('admin@example.com', $output);
    }

    public function test_render_footer_block_empty_for_guest_when_unconfigured(): void
    {
        $GLOBALS['_cb_test_current_user_can'] = false;
        $output = Cashback_Legal_Shortcodes::render_footer_block();
        $this->assertSame('', $output);
    }

    public function test_render_footer_block_includes_links_when_pages_exist(): void
    {
        Cashback_Legal_Operator::set_all(array(
            'full_name'     => 'ООО «Тест»',
            'org_form'      => 'ЮЛ',
            'ogrn'          => '1234567890123',
            'inn'           => '1234567890',
            'legal_address' => '123456, Москва',
            'contact_email' => 'admin@example.com',
        ));
        // Эмулируем наличие WP-pages с post_status='publish'.
        $GLOBALS['_cb_test_options'][ Cashback_Legal_Pages_Installer::PAGES_MAP_OPTION ] = array(
            Cashback_Legal_Documents::TYPE_PD_POLICY      => 100,
            Cashback_Legal_Documents::TYPE_PD_CONSENT     => 101,
            Cashback_Legal_Documents::TYPE_PAYMENT_PD     => 102,
            Cashback_Legal_Documents::TYPE_TERMS_OFFER    => 103,
            Cashback_Legal_Documents::TYPE_MARKETING      => 104,
            Cashback_Legal_Documents::TYPE_COOKIES_POLICY => 105,
        );
        $GLOBALS['_cb_test_post_statuses'] = array(
            100 => 'publish',
            101 => 'publish',
            102 => 'publish',
            103 => 'publish',
            104 => 'publish',
            105 => 'publish',
        );

        $output = Cashback_Legal_Shortcodes::render_footer_block();
        $this->assertStringContainsString('cashback-legal-footer', $output);
        $this->assertStringContainsString('1234567890123', $output);
        $this->assertStringContainsString('?p=100', $output);
        $this->assertStringContainsString('?p=105', $output);
    }
}
