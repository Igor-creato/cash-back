<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('link-checker')]
final class AffiliateGuestWarningAssetTest extends TestCase {

    public static function setUpBeforeClass(): void {
        if (!function_exists('remove_action')) {
            function remove_action( string $hook, callable|string $callback, int $priority = 10 ): bool {
                unset($hook, $callback, $priority);
                return true;
            }
        }

        if (!function_exists('get_permalink')) {
            function get_permalink( int $post = 0 ): string {
                $callback = $GLOBALS['_cb_test_get_permalink'] ?? null;
                if ($callback instanceof Closure) {
                    return (string) $callback($post);
                }

                return 'https://savelloclub.test/my-account/';
            }
        }

        if (!function_exists('wc_get_page_id')) {
            function wc_get_page_id( string $page ): int {
                unset($page);
                return 42;
            }
        }

        require_once dirname(__DIR__, 3) . '/wc-affiliate-url-params.php';
    }

    protected function setUp(): void {
        $GLOBALS['_cb_test_enqueued_scripts']  = array();
        $GLOBALS['_cb_test_enqueued_styles']   = array();
        $GLOBALS['_cb_test_localized_scripts'] = array();
        $GLOBALS['_cb_test_is_logged_in']      = false;
        unset($GLOBALS['_cb_test_get_permalink']);
    }

    public function test_guest_warning_asset_uses_cache_busted_helper_for_guests(): void {
        $plugin = new WC_Affiliate_URL_Params();
        $plugin->enqueue_frontend_scripts();

        self::assertArrayHasKey('wc-affiliate-url-params', $GLOBALS['_cb_test_enqueued_scripts']);

        $script = $GLOBALS['_cb_test_enqueued_scripts']['wc-affiliate-url-params'];
        self::assertStringContainsString('assets/js/affiliate-guest-warning.js', $script['src']);
        self::assertStringContainsString('cv=', $script['src']);
        self::assertSame(null, $script['ver']);
        self::assertSame(array( 'jquery' ), $script['deps']);
        self::assertTrue($script['in_footer']);

        $params = $GLOBALS['_cb_test_localized_scripts']['wc-affiliate-url-params']['wcAffiliateParams'];
        self::assertFalse($params['isLoggedIn']);
    }
}
