<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Legal_Payout_Consent (Phase 3).
 *
 * Покрывает:
 *  - already_granted: false для user_id=0
 *  - render_checkbox: выводит HTML для нового юзера, ничего — для уже давшего
 *  - enforce_or_error: error при отсутствии чекбокса; success + запись при наличии
 */
#[Group('legal')]
#[Group('legal-payout-consent')]
final class LegalPayoutConsentTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/legal/class-cashback-legal-db.php';
        require_once $plugin_root . '/legal/class-cashback-legal-documents.php';
        require_once $plugin_root . '/legal/class-cashback-legal-operator.php';
        require_once $plugin_root . '/legal/class-cashback-legal-consent-manager.php';

        if (!function_exists('wp_kses')) {
            function wp_kses( string $content, $allowed = array() ): string {
                return $content;
            }
        }
        if (!function_exists('esc_url')) {
            function esc_url( string $url ): string {
                return $url;
            }
        }
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

        require_once $plugin_root . '/legal/class-cashback-legal-pages-installer.php';
        require_once $plugin_root . '/legal/class-cashback-legal-payout-consent.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']             = array();
        $GLOBALS['_cb_test_legal_inserted_rows'] = array();
        $GLOBALS['_cb_test_legal_last_active']   = null;
        $_POST                                   = array();

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public int $insert_id = 0;
            private int $next_id = 200;

            public function suppress_errors( bool $suppress = true ) {
                return false;
            }

            public function insert( string $table, array $data, $format = null ) {
                $GLOBALS['_cb_test_legal_inserted_rows'][] = $data;
                $this->insert_id = $this->next_id++;
                return 1;
            }

            public function prepare( string $q, ...$args ): string {
                return $q;
            }

            public function get_row( string $q, $output = ARRAY_A, int $y = 0 ) {
                return $GLOBALS['_cb_test_legal_last_active'] ?? null;
            }

            public function get_var( string $q ) {
                return null;
            }
        };
    }

    public function test_already_granted_false_for_zero_user(): void
    {
        $this->assertFalse(Cashback_Legal_Payout_Consent::already_granted(0));
    }

    public function test_render_checkbox_outputs_nothing_when_already_granted(): void
    {
        $GLOBALS['_cb_test_legal_last_active'] = array(
            'id'               => 1,
            'document_version' => '1.0.0',
            'granted_at'       => '2026-04-01 00:00:00',
        );
        ob_start();
        Cashback_Legal_Payout_Consent::render_checkbox(99);
        $out = (string) ob_get_clean();
        $this->assertSame('', $out);
    }

    public function test_render_checkbox_outputs_html_for_new_user(): void
    {
        $GLOBALS['_cb_test_legal_last_active'] = null;
        ob_start();
        Cashback_Legal_Payout_Consent::render_checkbox(100);
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('cashback_legal_payment_pd_consent', $out);
        $this->assertStringContainsString('cashback_legal_payment_pd_request_id', $out);
        $this->assertStringContainsString('required', $out);
    }

    public function test_enforce_returns_error_when_user_zero(): void
    {
        $result = Cashback_Legal_Payout_Consent::enforce_or_error(0);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('message', $result);
    }

    public function test_enforce_returns_true_when_already_granted(): void
    {
        $GLOBALS['_cb_test_legal_last_active'] = array(
            'id'               => 5,
            'document_version' => '1.0.0',
            'granted_at'       => '2026-04-01 00:00:00',
        );
        $result = Cashback_Legal_Payout_Consent::enforce_or_error(101);
        $this->assertTrue($result);
        $this->assertSame(array(), $GLOBALS['_cb_test_legal_inserted_rows']);
    }

    public function test_enforce_returns_error_when_checkbox_missing(): void
    {
        $GLOBALS['_cb_test_legal_last_active'] = null;
        $_POST = array();
        $result = Cashback_Legal_Payout_Consent::enforce_or_error(102);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('message', $result);
    }

    public function test_enforce_writes_consent_when_checkbox_provided(): void
    {
        $GLOBALS['_cb_test_legal_last_active'] = null;
        $_POST = array(
            Cashback_Legal_Payout_Consent::FIELD_NAME       => '1',
            Cashback_Legal_Payout_Consent::FIELD_REQUEST_ID => bin2hex(random_bytes(16)),
        );
        $result = Cashback_Legal_Payout_Consent::enforce_or_error(103, 'profile');
        $this->assertTrue($result);
        $this->assertCount(1, $GLOBALS['_cb_test_legal_inserted_rows']);
        $row = $GLOBALS['_cb_test_legal_inserted_rows'][0];
        $this->assertSame('payment_pd', $row['consent_type']);
        $this->assertSame('profile', $row['source']);
        $this->assertSame(103, $row['user_id']);
    }
}
