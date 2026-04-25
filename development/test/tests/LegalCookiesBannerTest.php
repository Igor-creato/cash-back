<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Legal_Cookies_Banner (Phase 4).
 *
 * Покрывает:
 *  - handle_ajax с invalid choice → wp_send_json_error
 *  - handle_ajax granted → запись в журнал с user_id=NULL для гостя
 *  - handle_ajax rejected → запись с action=revoked
 *  - handle_ajax для авторизованного → user_id=current
 *  - normalize_request_id принимает client UUID
 */
#[Group('legal')]
#[Group('legal-cookies-banner')]
final class LegalCookiesBannerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/legal/class-cashback-legal-db.php';
        require_once $plugin_root . '/legal/class-cashback-legal-documents.php';
        require_once $plugin_root . '/legal/class-cashback-legal-operator.php';
        require_once $plugin_root . '/legal/class-cashback-legal-consent-manager.php';

        if (!function_exists('get_post_status')) {
            function get_post_status( int $post_id ) {
                return $GLOBALS['_cb_test_post_statuses'][ $post_id ] ?? false;
            }
        }
        if (!function_exists('get_permalink')) {
            function get_permalink( int $post_id ): string {
                return 'http://localhost/?p=' . $post_id;
            }
        }
        if (!function_exists('is_checkout_pay_page')) {
            function is_checkout_pay_page(): bool {
                return false;
            }
        }

        require_once $plugin_root . '/legal/class-cashback-legal-pages-installer.php';
        require_once $plugin_root . '/legal/class-cashback-legal-cookies-banner.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']             = array();
        $GLOBALS['_cb_test_legal_inserted_rows'] = array();
        $GLOBALS['_cb_test_user_id']             = 0;
        $_POST                                   = array();

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public int $insert_id = 0;
            private int $next_id = 300;

            public function suppress_errors( bool $suppress = true ) {
                return false;
            }

            public function insert( string $table, array $data, $format = null ) {
                $GLOBALS['_cb_test_legal_inserted_rows'][] = $data;
                $this->insert_id = $this->next_id++;
                return 1;
            }
        };
    }

    private function dispatch_ajax(): array
    {
        $_POST['nonce'] = 'test_nonce_' . md5(Cashback_Legal_Cookies_Banner::AJAX_ACTION_RECORD);
        try {
            Cashback_Legal_Cookies_Banner::handle_ajax();
        } catch (\Throwable $e) {
            // wp_send_json_*  бросает Cashback_Test_Halt_Signal — это нормально.
        }
        return $GLOBALS['_cb_test_last_json_response'] ?? array();
    }

    public function test_invalid_choice_returns_error(): void
    {
        $_POST['choice']     = 'maybe';
        $_POST['request_id'] = bin2hex(random_bytes(16));
        $response = $this->dispatch_ajax();
        $this->assertFalse($response['success'] ?? true);
        $this->assertSame('invalid_choice', $response['data']['code'] ?? '');
        $this->assertSame(array(), $GLOBALS['_cb_test_legal_inserted_rows']);
    }

    public function test_granted_writes_log_with_null_user_for_guest(): void
    {
        $_POST['choice']     = 'granted';
        $_POST['request_id'] = bin2hex(random_bytes(16));
        $GLOBALS['_cb_test_user_id'] = 0;

        $this->dispatch_ajax();

        $this->assertCount(1, $GLOBALS['_cb_test_legal_inserted_rows']);
        $row = $GLOBALS['_cb_test_legal_inserted_rows'][0];
        $this->assertSame('cookies', $row['consent_type']);
        $this->assertSame('granted', $row['action']);
        $this->assertNull($row['user_id']);
        $this->assertSame('cookies_banner', $row['source']);
        $this->assertNull($row['revoked_at']);
    }

    public function test_rejected_writes_revoked_action(): void
    {
        $_POST['choice']     = 'rejected';
        $_POST['request_id'] = bin2hex(random_bytes(16));
        $GLOBALS['_cb_test_user_id'] = 0;

        $this->dispatch_ajax();

        $this->assertCount(1, $GLOBALS['_cb_test_legal_inserted_rows']);
        $row = $GLOBALS['_cb_test_legal_inserted_rows'][0];
        $this->assertSame('revoked', $row['action']);
        $this->assertNotNull($row['revoked_at']);
    }

    public function test_authenticated_user_id_recorded(): void
    {
        $_POST['choice']     = 'granted';
        $_POST['request_id'] = bin2hex(random_bytes(16));
        $GLOBALS['_cb_test_user_id'] = 777;

        $this->dispatch_ajax();

        $row = $GLOBALS['_cb_test_legal_inserted_rows'][0];
        $this->assertSame(777, $row['user_id']);
    }

    public function test_invalid_request_id_replaced_with_generated(): void
    {
        $_POST['choice']     = 'granted';
        $_POST['request_id'] = 'invalid';
        $GLOBALS['_cb_test_user_id'] = 0;

        $this->dispatch_ajax();

        $row = $GLOBALS['_cb_test_legal_inserted_rows'][0];
        // Generated request_id должен быть hex 32 символа.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $row['request_id']);
    }
}
