<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Legal_Registration_Checkboxes (Phase 3).
 *
 * Покрывает:
 *  - render_checkboxes выводит 3 чекбокса (PD/offer/marketing) + hidden request_id
 *  - validate_checkboxes требует отметки PD и offer; marketing — нет
 *  - save_consents_on_registration пишет 2 записи (когда marketing=off)
 *    или 3 записи (когда marketing=on) с одним «префиксом» request_id
 *
 * Полные wpdb-операции мочиатся через стаб $GLOBALS['wpdb']:
 * insert_log_row → собирает в $GLOBALS['_cb_test_legal_inserted_rows'].
 */
#[Group('legal')]
#[Group('legal-registration-checkboxes')]
final class LegalRegistrationCheckboxesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/legal/class-cashback-legal-db.php';
        require_once $plugin_root . '/legal/class-cashback-legal-documents.php';
        require_once $plugin_root . '/legal/class-cashback-legal-operator.php';
        require_once $plugin_root . '/legal/class-cashback-legal-consent-manager.php';

        if (!function_exists('cashback_generate_uuid7')) {
            // Стаб с правильной структурой UUID v7 (без полной загрузки cashback-plugin.php
            // — он требует register_activation_hook, недоступной в bootstrap'е тестов).
            function cashback_generate_uuid7( bool $with_dashes = true ): string {
                $hex = bin2hex(random_bytes(16));
                if (!$with_dashes) {
                    return $hex;
                }
                return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
                    . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
            }
        }

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
        require_once $plugin_root . '/legal/class-cashback-legal-registration-checkboxes.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']             = array();
        $GLOBALS['_cb_test_legal_inserted_rows'] = array();
        $_POST                                   = array();

        // Stub $wpdb с capture insert_log_row через Cashback_Legal_DB::insert_log_row.
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public int $insert_id = 0;

            /** @var int */
            private int $next_id = 100;

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

    public function test_render_checkboxes_outputs_three_inputs_and_hidden_uuid(): void
    {
        ob_start();
        Cashback_Legal_Registration_Checkboxes::render_checkboxes();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('cashback_legal_consent_pd', $html);
        $this->assertStringContainsString('cashback_legal_consent_offer', $html);
        $this->assertStringContainsString('cashback_legal_consent_marketing', $html);
        $this->assertStringContainsString('cashback_legal_request_id', $html);
        // Обязательные чекбоксы — required-атрибут в HTML.
        $this->assertGreaterThanOrEqual(2, substr_count($html, ' required'));
    }

    public function test_validate_fails_when_pd_unchecked(): void
    {
        $errors = new WP_Error();
        $_POST  = array(
            Cashback_Legal_Registration_Checkboxes::FIELD_TERMS_OFFER => '1',
        );
        $result = Cashback_Legal_Registration_Checkboxes::validate_checkboxes($errors);
        $this->assertSame('cashback_legal_pd_required', $result->get_error_code());
    }

    public function test_validate_fails_when_offer_unchecked(): void
    {
        $errors = new WP_Error();
        $_POST  = array(
            Cashback_Legal_Registration_Checkboxes::FIELD_PD_PROCESSING => '1',
        );
        $result = Cashback_Legal_Registration_Checkboxes::validate_checkboxes($errors);
        $this->assertSame('cashback_legal_offer_required', $result->get_error_code());
    }

    public function test_validate_passes_when_both_required_checked(): void
    {
        $errors = new WP_Error();
        $_POST  = array(
            Cashback_Legal_Registration_Checkboxes::FIELD_PD_PROCESSING => '1',
            Cashback_Legal_Registration_Checkboxes::FIELD_TERMS_OFFER   => '1',
        );
        $result = Cashback_Legal_Registration_Checkboxes::validate_checkboxes($errors);
        $this->assertSame('', $result->get_error_code());
    }

    public function test_save_writes_two_rows_when_marketing_unchecked(): void
    {
        $_POST = array(
            Cashback_Legal_Registration_Checkboxes::FIELD_PD_PROCESSING => '1',
            Cashback_Legal_Registration_Checkboxes::FIELD_TERMS_OFFER   => '1',
            Cashback_Legal_Registration_Checkboxes::FIELD_REQUEST_ID    => bin2hex(random_bytes(16)),
        );

        Cashback_Legal_Registration_Checkboxes::save_consents_on_registration(42);

        $rows = $GLOBALS['_cb_test_legal_inserted_rows'];
        $this->assertCount(2, $rows);
        $types = array_column($rows, 'consent_type');
        $this->assertContains('pd_consent', $types);
        $this->assertContains('terms_offer', $types);
        $this->assertNotContains('marketing', $types);
    }

    public function test_save_writes_three_rows_when_marketing_checked(): void
    {
        $_POST = array(
            Cashback_Legal_Registration_Checkboxes::FIELD_PD_PROCESSING => '1',
            Cashback_Legal_Registration_Checkboxes::FIELD_TERMS_OFFER   => '1',
            Cashback_Legal_Registration_Checkboxes::FIELD_MARKETING     => '1',
            Cashback_Legal_Registration_Checkboxes::FIELD_REQUEST_ID    => bin2hex(random_bytes(16)),
        );

        Cashback_Legal_Registration_Checkboxes::save_consents_on_registration(43);

        $rows = $GLOBALS['_cb_test_legal_inserted_rows'];
        $this->assertCount(3, $rows);
        $types = array_column($rows, 'consent_type');
        $this->assertContains('marketing', $types);
    }

    public function test_save_skips_when_user_id_zero(): void
    {
        Cashback_Legal_Registration_Checkboxes::save_consents_on_registration(0);
        $this->assertSame(array(), $GLOBALS['_cb_test_legal_inserted_rows']);
    }

    public function test_save_uses_unique_request_id_per_consent_type(): void
    {
        $_POST = array(
            Cashback_Legal_Registration_Checkboxes::FIELD_PD_PROCESSING => '1',
            Cashback_Legal_Registration_Checkboxes::FIELD_TERMS_OFFER   => '1',
            Cashback_Legal_Registration_Checkboxes::FIELD_MARKETING     => '1',
            Cashback_Legal_Registration_Checkboxes::FIELD_REQUEST_ID    => str_repeat('a', 32),
        );

        Cashback_Legal_Registration_Checkboxes::save_consents_on_registration(50);

        $rows = $GLOBALS['_cb_test_legal_inserted_rows'];
        $request_ids = array_column($rows, 'request_id');
        $this->assertCount(3, $request_ids);
        $this->assertCount(3, array_unique($request_ids), 'Каждый consent_type должен иметь свой request_id для UNIQUE constraint');
    }
}
