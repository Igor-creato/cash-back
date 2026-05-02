<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Legal_My_Account (UX-cleanup 1.4.0):
 *  - render_consent_section выводит ровно 2 toggle (marketing + tech_data) с
 *    правильным начальным состоянием (через has_active_consent)
 *  - AJAX cashback_legal_toggle_consent: nonce, auth, whitelist, idempotent,
 *    rate-limit
 *  - Bootstrap подгружает Cashback_Legal_My_Account и вызывает init()
 *  - reconsent-flow: bump major по pd_consent → pending содержит pd_consent;
 *    bump major по marketing → pending пуст (опциональное не блокирует)
 */
#[Group('legal')]
#[Group('my-account-consent-toggles')]
final class MyAccountConsentTogglesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/legal/class-cashback-legal-db.php';
        require_once $plugin_root . '/legal/class-cashback-legal-documents.php';
        require_once $plugin_root . '/legal/class-cashback-legal-operator.php';
        require_once $plugin_root . '/legal/class-cashback-legal-consent-manager.php';

        if (!function_exists('esc_url')) {
            function esc_url( string $u ): string { return $u; }
        }
        if (!function_exists('cashback_generate_uuid7')) {
            function cashback_generate_uuid7( bool $with_dashes = true ): string {
                $hex = bin2hex(random_bytes(16));
                if (!$with_dashes) {
                    return $hex;
                }
                return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
                    . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
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
        if (!function_exists('wp_kses')) {
            function wp_kses( string $content, $allowed = array() ): string { return $content; }
        }

        require_once $plugin_root . '/legal/class-cashback-legal-pages-installer.php';
        require_once $plugin_root . '/legal/class-cashback-legal-my-account.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']                 = array();
        $GLOBALS['_cb_test_transients']              = array();
        $GLOBALS['_cb_test_legal_inserted_rows']     = array();
        $GLOBALS['_cb_test_is_logged_in']            = true;
        $GLOBALS['_cb_test_user_id']                 = 42;
        $GLOBALS['_cb_test_active_consents']         = array(); // user_id|type → bool
        $GLOBALS['_cb_test_last_json_response']      = null;
        $_POST                                       = array();

        // Stub wpdb с поддержкой has_active_consent (через get_row + get_var).
        $GLOBALS['wpdb'] = new class {
            public string $prefix     = 'wp_';
            public string $last_error = '';
            public int $insert_id     = 0;
            private int $next_id      = 500;

            public function suppress_errors( bool $suppress = true ) {
                return false;
            }

            public function insert( string $table, array $data, $format = null ) {
                $GLOBALS['_cb_test_legal_inserted_rows'][] = array_merge(
                    array( '_table' => $table ),
                    $data
                );
                $this->insert_id = $this->next_id++;
                return 1;
            }

            public function prepare( string $q, ...$args ): string {
                // get_last_active_granted: первый prepare с "action = 'granted'".
                if (strpos($q, "action = 'granted'") !== false) {
                    $uid  = (int) ( $args[1] ?? 0 );
                    $type = (string) ( $args[2] ?? '' );
                    return 'GRANTED:' . $uid . ':' . $type;
                }
                if (strpos($q, "action IN ('revoked', 'superseded')") !== false) {
                    return 'SUPERSEDED_CHECK';
                }
                return 'GENERIC';
            }

            public function get_row( string $q, $output = ARRAY_A, int $y = 0 ) {
                if (strpos($q, 'GRANTED:') === 0) {
                    $parts = explode(':', $q, 3);
                    $uid   = (int) ( $parts[1] ?? 0 );
                    $type  = (string) ( $parts[2] ?? '' );
                    $key   = $uid . '|' . $type;
                    if (!empty($GLOBALS['_cb_test_active_consents'][ $key ])) {
                        return array(
                            'id'               => 1,
                            'user_id'          => $uid,
                            'consent_type'     => $type,
                            'action'           => 'granted',
                            'document_version' => '1.0.0',
                            'granted_at'       => '2026-01-01 00:00:00',
                        );
                    }
                }
                return null;
            }

            public function get_var( string $q ) {
                return null;
            }
        };
    }

    // ────────────────────────────────────────────────────────────
    // render_consent_section
    // ────────────────────────────────────────────────────────────

    public function test_render_outputs_two_toggles(): void
    {
        ob_start();
        Cashback_Legal_My_Account::render_consent_section();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('cashback-legal-consents', $html);
        $this->assertStringContainsString('data-consent-type="marketing"', $html);
        $this->assertStringContainsString('data-consent-type="tech_data"', $html);
        // Ровно 2 чекбокса в секции.
        $this->assertSame(
            2,
            preg_match_all('/<input[^>]+type="checkbox"[^>]+data-consent-type=/', $html)
        );
    }

    public function test_render_marks_marketing_checked_when_active(): void
    {
        $GLOBALS['_cb_test_active_consents'] = array(
            '42|marketing' => true,
        );

        ob_start();
        Cashback_Legal_My_Account::render_consent_section();
        $html = (string) ob_get_clean();

        // Чекбокс marketing — checked, tech_data — нет.
        $this->assertMatchesRegularExpression(
            '/<input[^>]+data-consent-type="marketing"[^>]+checked/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]+data-consent-type="tech_data"[^>]+checked/',
            $html
        );
    }

    public function test_render_skips_when_user_not_logged_in(): void
    {
        $GLOBALS['_cb_test_is_logged_in'] = false;
        $GLOBALS['_cb_test_user_id']      = 0;

        ob_start();
        Cashback_Legal_My_Account::render_consent_section();
        $html = (string) ob_get_clean();

        $this->assertSame('', trim($html), 'Не залогиненному ничего не выводим');
    }

    // ────────────────────────────────────────────────────────────
    // handle_ajax_toggle
    // ────────────────────────────────────────────────────────────

    private function invoke_ajax(): void
    {
        try {
            Cashback_Legal_My_Account::handle_ajax_toggle();
        } catch (Cashback_Test_Halt_Signal $e) {
            // wp_send_json_* signal — нормальное прерывание AJAX-handler'а.
        }
    }

    public function test_ajax_rejects_guest(): void
    {
        $GLOBALS['_cb_test_is_logged_in'] = false;
        $GLOBALS['_cb_test_user_id']      = 0;
        $_POST = array(
            'nonce'        => 'irrelevant',
            'consent_type' => 'marketing',
            'enabled'      => '1',
        );

        $this->invoke_ajax();

        $resp = $GLOBALS['_cb_test_last_json_response'];
        $this->assertNotNull($resp);
        $this->assertFalse($resp['success']);
        $this->assertSame(401, $resp['status_code']);
    }

    public function test_ajax_rejects_invalid_consent_type(): void
    {
        $_POST = array(
            'nonce'        => 'irrelevant',
            'consent_type' => 'pd_consent', // не в whitelist toggle'ов
            'enabled'      => '0',
        );

        $this->invoke_ajax();

        $resp = $GLOBALS['_cb_test_last_json_response'];
        $this->assertFalse($resp['success']);
        $this->assertSame(400, $resp['status_code']);
    }

    public function test_ajax_grants_marketing_when_enabled(): void
    {
        $_POST = array(
            'nonce'        => 'irrelevant',
            'consent_type' => 'marketing',
            'enabled'      => '1',
        );

        $this->invoke_ajax();

        $resp = $GLOBALS['_cb_test_last_json_response'];
        $this->assertTrue($resp['success']);
        $this->assertSame('marketing', $resp['data']['consent_type']);
        $this->assertTrue($resp['data']['enabled']);

        // В журнале — granted-строка.
        $rows = array_values(array_filter(
            $GLOBALS['_cb_test_legal_inserted_rows'],
            static fn(array $r): bool => isset($r['consent_type']) && $r['action'] === 'granted'
        ));
        $this->assertCount(1, $rows);
        $this->assertSame('marketing', $rows[0]['consent_type']);
        $this->assertSame('my_account', $rows[0]['source']);
    }

    public function test_ajax_revokes_marketing_when_disabled(): void
    {
        $GLOBALS['_cb_test_active_consents'] = array(
            '42|marketing' => true,
        );

        $_POST = array(
            'nonce'        => 'irrelevant',
            'consent_type' => 'marketing',
            'enabled'      => '0',
        );

        $this->invoke_ajax();

        $resp = $GLOBALS['_cb_test_last_json_response'];
        $this->assertTrue($resp['success']);
        $this->assertFalse($resp['data']['enabled']);

        $rows = array_values(array_filter(
            $GLOBALS['_cb_test_legal_inserted_rows'],
            static fn(array $r): bool => isset($r['consent_type']) && $r['action'] === 'revoked'
        ));
        $this->assertCount(1, $rows);
        $this->assertSame('marketing', $rows[0]['consent_type']);
    }

    public function test_ajax_idempotent_when_state_already_matches(): void
    {
        $GLOBALS['_cb_test_active_consents'] = array(
            '42|tech_data' => true,
        );

        $_POST = array(
            'nonce'        => 'irrelevant',
            'consent_type' => 'tech_data',
            'enabled'      => '1', // уже включён
        );

        $this->invoke_ajax();

        $resp = $GLOBALS['_cb_test_last_json_response'];
        $this->assertTrue($resp['success']);
        $this->assertTrue(!empty($resp['data']['noop']));

        // Никаких записей в журнал.
        $rows = array_values(array_filter(
            $GLOBALS['_cb_test_legal_inserted_rows'],
            static fn(array $r): bool => isset($r['consent_type'])
        ));
        $this->assertCount(0, $rows);
    }

    // ────────────────────────────────────────────────────────────
    // Bootstrap wiring
    // ────────────────────────────────────────────────────────────

    public function test_my_account_class_exists_and_has_init(): void
    {
        $this->assertTrue(class_exists('Cashback_Legal_My_Account'));
        $this->assertTrue(method_exists('Cashback_Legal_My_Account', 'init'));
        $this->assertTrue(method_exists('Cashback_Legal_My_Account', 'render_consent_section'));
        $this->assertTrue(method_exists('Cashback_Legal_My_Account', 'handle_ajax_toggle'));
    }

    // ────────────────────────────────────────────────────────────
    // Reconsent-interaction (опц. согласия НЕ блокируют юзера)
    // ────────────────────────────────────────────────────────────

    public function test_reconsent_pending_pure_for_optional_types_only(): void
    {
        // Bump major по marketing — get_pending_reconsent_types() должен вернуть [].
        $GLOBALS['_cb_test_options'][ Cashback_Legal_Documents::VERSIONS_OPTION ] = wp_json_encode(
            array( Cashback_Legal_Documents::TYPE_MARKETING => '2.0.0' )
        );
        // Юзер «согласовал» marketing v1.0.0 — стаб get_row вернёт row с document_version=1.0.0.
        $GLOBALS['_cb_test_active_consents'] = array(
            '42|marketing' => true,
        );

        $pending = Cashback_Legal_Consent_Manager::get_pending_reconsent_types(42);
        $this->assertNotContains(
            Cashback_Legal_Documents::TYPE_MARKETING,
            $pending,
            'marketing — опциональное, не должно блокировать reconsent-flow'
        );
    }
}
