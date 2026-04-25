<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Legal_Reviews_Notice (Phase 6).
 */
#[Group('legal')]
#[Group('legal-reviews-notice')]
final class LegalReviewsNoticeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        if (!function_exists('is_singular')) {
            function is_singular( $type = '' ): bool {
                if ($type === '') {
                    return ! empty($GLOBALS['_cb_test_is_singular_any']);
                }
                $current = $GLOBALS['_cb_test_singular_type'] ?? '';
                return $current === $type;
            }
        }
        if (!function_exists('is_product')) {
            function is_product(): bool {
                return ! empty($GLOBALS['_cb_test_is_product']);
            }
        }

        require_once $plugin_root . '/legal/class-cashback-legal-reviews-notice.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_singular_type']    = '';
        $GLOBALS['_cb_test_is_product']       = false;
        $GLOBALS['_cb_test_is_logged_in']     = false;
        $GLOBALS['_cb_test_options']          = array();
        $GLOBALS['_cb_test_current_user_can'] = false;
    }

    public function test_logged_in_remark_renders_on_product_page(): void
    {
        $GLOBALS['_cb_test_is_product'] = true;
        ob_start();
        Cashback_Legal_Reviews_Notice::render_logged_in_remark();
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('cashback-legal-remark', $out);
        $this->assertStringContainsString('152-ФЗ', $out);
    }

    public function test_logged_in_remark_silent_outside_product(): void
    {
        $GLOBALS['_cb_test_is_product'] = false;
        ob_start();
        Cashback_Legal_Reviews_Notice::render_logged_in_remark();
        $this->assertSame('', (string) ob_get_clean());
    }

    public function test_guest_warning_silent_when_registration_required(): void
    {
        $GLOBALS['_cb_test_is_product']                  = true;
        $GLOBALS['_cb_test_is_logged_in']                = false;
        $GLOBALS['_cb_test_options']['comment_registration'] = 1;
        $GLOBALS['_cb_test_current_user_can']             = true;
        ob_start();
        Cashback_Legal_Reviews_Notice::render_guest_warning_for_admin();
        $this->assertSame('', (string) ob_get_clean());
    }

    public function test_guest_warning_renders_for_admin_when_guests_allowed(): void
    {
        $GLOBALS['_cb_test_is_product']                  = true;
        $GLOBALS['_cb_test_is_logged_in']                = false;
        $GLOBALS['_cb_test_options']['comment_registration'] = 0;
        $GLOBALS['_cb_test_current_user_can']             = true;
        ob_start();
        Cashback_Legal_Reviews_Notice::render_guest_warning_for_admin();
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('cashback-legal-admin-warning', $out);
    }

    public function test_guest_warning_silent_for_non_admin(): void
    {
        $GLOBALS['_cb_test_is_product']                  = true;
        $GLOBALS['_cb_test_is_logged_in']                = false;
        $GLOBALS['_cb_test_options']['comment_registration'] = 0;
        $GLOBALS['_cb_test_current_user_can']             = false;
        ob_start();
        Cashback_Legal_Reviews_Notice::render_guest_warning_for_admin();
        $this->assertSame('', (string) ob_get_clean());
    }

    public function test_guest_warning_silent_for_logged_in_user(): void
    {
        $GLOBALS['_cb_test_is_product']                  = true;
        $GLOBALS['_cb_test_is_logged_in']                = true;
        $GLOBALS['_cb_test_options']['comment_registration'] = 0;
        $GLOBALS['_cb_test_current_user_can']             = true;
        ob_start();
        Cashback_Legal_Reviews_Notice::render_guest_warning_for_admin();
        $this->assertSame('', (string) ob_get_clean());
    }
}
