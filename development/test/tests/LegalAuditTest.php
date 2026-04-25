<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Legal_Admin::audit_third_party_forms (Phase 6).
 *
 * Покрывает probe-функции: при отсутствии woodmart-функций — manual_check;
 * при наличии — active/inactive в зависимости от опций. CF7-плагин — детект
 * через is_plugin_active. Гостевые отзывы — через comment_registration option.
 */
#[Group('legal')]
#[Group('legal-audit')]
final class LegalAuditTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        // Минимальные стабы для admin-загрузки.
        if (!function_exists('add_submenu_page')) {
            function add_submenu_page( $parent, $title, $menu, $cap, $slug, $callback ): string {
                return $slug;
            }
        }
        if (!function_exists('check_admin_referer')) {
            function check_admin_referer( $action ): int {
                return 1;
            }
        }
        if (!function_exists('wp_safe_redirect')) {
            function wp_safe_redirect( string $url ): bool {
                $GLOBALS['_cb_test_last_redirect'] = $url;
                return true;
            }
        }
        if (!function_exists('selected')) {
            function selected( $a, $b ): string {
                return $a === $b ? ' selected' : '';
            }
        }
        if (!function_exists('esc_textarea')) {
            function esc_textarea( string $s ): string {
                return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
            }
        }
        if (!function_exists('esc_url')) {
            function esc_url( string $u ): string {
                return $u;
            }
        }
        if (!function_exists('esc_js')) {
            function esc_js( string $s ): string {
                return $s;
            }
        }
        if (!function_exists('get_current_screen')) {
            function get_current_screen() {
                return null;
            }
        }
        if (!function_exists('is_plugin_active')) {
            function is_plugin_active( string $plugin ): bool {
                return ! empty($GLOBALS['_cb_test_active_plugins'][ $plugin ]);
            }
        }
        if (!function_exists('wp_count_posts')) {
            function wp_count_posts( string $type ) {
                $obj = new \stdClass();
                $obj->publish = (int) ( $GLOBALS['_cb_test_post_counts'][ $type ] ?? 0 );
                return $obj;
            }
        }
        if (!defined('ABSPATH_TESTS')) {
            define('ABSPATH_TESTS', '/');
        }

        require_once $plugin_root . '/legal/class-cashback-legal-db.php';
        require_once $plugin_root . '/legal/class-cashback-legal-documents.php';
        require_once $plugin_root . '/legal/class-cashback-legal-operator.php';
        require_once $plugin_root . '/legal/class-cashback-legal-consent-manager.php';
        require_once $plugin_root . '/legal/admin/class-cashback-legal-admin.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']         = array();
        $GLOBALS['_cb_test_active_plugins']  = array();
        $GLOBALS['_cb_test_post_counts']     = array();
        $GLOBALS['_cb_test_current_user_can'] = true;
    }

    public function test_audit_returns_six_modules(): void
    {
        $rows = Cashback_Legal_Admin::audit_third_party_forms();
        $this->assertCount(6, $rows);
        $ids = array_column($rows, 'id');
        $this->assertContains('woodmart_waitlist', $ids);
        $this->assertContains('woodmart_price_tracker', $ids);
        $this->assertContains('woodmart_social_auth', $ids);
        $this->assertContains('contact_form_7', $ids);
        $this->assertContains('elementor_pro_forms', $ids);
        $this->assertContains('wc_guest_reviews', $ids);
    }

    public function test_woodmart_modules_manual_check_without_helper(): void
    {
        // woodmart_get_opt не определена — все 3 woodmart-модуля должны быть manual_check.
        $rows = Cashback_Legal_Admin::audit_third_party_forms();
        $by_id = array();
        foreach ($rows as $r) {
            $by_id[ $r['id'] ] = $r;
        }
        $this->assertSame('manual_check', $by_id['woodmart_waitlist']['status']);
        $this->assertSame('manual_check', $by_id['woodmart_price_tracker']['status']);
        $this->assertSame('manual_check', $by_id['woodmart_social_auth']['status']);
    }

    public function test_cf7_inactive_when_plugin_not_active(): void
    {
        $GLOBALS['_cb_test_active_plugins'] = array();
        $rows = Cashback_Legal_Admin::audit_third_party_forms();
        $by_id = array_column($rows, null, 'id');
        $this->assertSame('inactive', $by_id['contact_form_7']['status']);
    }

    public function test_cf7_active_when_plugin_active_and_forms_exist(): void
    {
        $GLOBALS['_cb_test_active_plugins'] = array(
            'contact-form-7/wp-contact-form-7.php' => true,
        );
        $GLOBALS['_cb_test_post_counts'] = array(
            'wpcf7_contact_form' => 3,
        );
        $rows  = Cashback_Legal_Admin::audit_third_party_forms();
        $by_id = array_column($rows, null, 'id');
        $this->assertSame('active', $by_id['contact_form_7']['status']);
    }

    public function test_cf7_inactive_when_plugin_active_but_no_forms(): void
    {
        $GLOBALS['_cb_test_active_plugins'] = array(
            'contact-form-7/wp-contact-form-7.php' => true,
        );
        $GLOBALS['_cb_test_post_counts'] = array(
            'wpcf7_contact_form' => 0,
        );
        $rows  = Cashback_Legal_Admin::audit_third_party_forms();
        $by_id = array_column($rows, null, 'id');
        $this->assertSame('inactive', $by_id['contact_form_7']['status']);
    }

    public function test_elementor_pro_inactive_when_class_missing(): void
    {
        $rows  = Cashback_Legal_Admin::audit_third_party_forms();
        $by_id = array_column($rows, null, 'id');
        $this->assertSame('inactive', $by_id['elementor_pro_forms']['status']);
    }

    public function test_wc_reviews_inactive_when_registration_required(): void
    {
        $GLOBALS['_cb_test_options']['comment_registration'] = 1;
        $rows  = Cashback_Legal_Admin::audit_third_party_forms();
        $by_id = array_column($rows, null, 'id');
        $this->assertSame('inactive', $by_id['wc_guest_reviews']['status']);
    }

    public function test_wc_reviews_active_when_guests_allowed(): void
    {
        $GLOBALS['_cb_test_options']['comment_registration'] = 0;
        $rows  = Cashback_Legal_Admin::audit_third_party_forms();
        $by_id = array_column($rows, null, 'id');
        $this->assertSame('active', $by_id['wc_guest_reviews']['status']);
    }

    public function test_has_active_third_party_forms_false_default(): void
    {
        $GLOBALS['_cb_test_options']['comment_registration'] = 1;
        $this->assertFalse(Cashback_Legal_Admin::has_active_third_party_forms());
    }

    public function test_has_active_third_party_forms_true_when_guest_reviews_active(): void
    {
        $GLOBALS['_cb_test_options']['comment_registration'] = 0;
        $this->assertTrue(Cashback_Legal_Admin::has_active_third_party_forms());
    }
}
