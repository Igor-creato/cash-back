<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit + source-grep тесты F-2-001 п.4: whitelist для имён CPA-параметров.
 *
 * Покрывает:
 *   - Cashback_Click_Session_Service::is_valid_affiliate_param_name — positive/negative.
 *   - Проверка, что whitelist вызывается во всех трёх save-путях
 *     (product meta, network bulk, network single) + defensive re-check в сервисе.
 */
#[Group('security')]
#[Group('f-2-001')]
#[Group('param-whitelist')]
final class AffiliateParamNameWhitelistTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Cashback_Click_Session_Service')) {
            require_once dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php';
        }
    }

    // =====================================================================
    // 1. Unit: is_valid_affiliate_param_name
    // =====================================================================

    #[DataProvider('valid_names')]
    public function test_accepts_valid_name( string $name ): void
    {
        $this->assertTrue(Cashback_Click_Session_Service::is_valid_affiliate_param_name($name), "Valid name rejected: {$name}");
    }

    public static function valid_names(): array
    {
        return array(
            'lowercase'              => array( 'subid' ),
            'uppercase'              => array( 'SUBID' ),
            'mixed_case'             => array( 'subId' ),
            'with_underscore'        => array( 'sub_id' ),
            'with_dash'              => array( 'sub-id' ),
            'digits_only'            => array( '12345' ),
            'alphanumeric'           => array( 'utm2' ),
            'max_length_32'          => array( str_repeat('a', 32) ),
            'single_char'            => array( 'x' ),
            'mix'                    => array( 'Offer_ID-01' ),
        );
    }

    #[DataProvider('invalid_names')]
    public function test_rejects_invalid_name( string $name, string $reason ): void
    {
        $this->assertFalse(Cashback_Click_Session_Service::is_valid_affiliate_param_name($name), $reason);
    }

    public static function invalid_names(): array
    {
        return array(
            'empty'             => array( '', 'Empty string не должна проходить.' ),
            'too_long'          => array( str_repeat('a', 33), 'Длина > 32 отклоняется.' ),
            'space'             => array( 'sub id', 'Пробел в имени.' ),
            'leading_space'     => array( ' subid', 'Leading whitespace.' ),
            'trailing_space'    => array( 'subid ', 'Trailing whitespace.' ),
            'cyrillic'          => array( 'параметр', 'Не-ASCII.' ),
            'dot'               => array( 'utm.id', 'Точка запрещена.' ),
            'equals'            => array( 'utm=id', 'Query-separator.' ),
            'ampersand'         => array( 'utm&id', 'Query-separator.' ),
            'semicolon'         => array( 'utm;id', 'URL-separator.' ),
            'slash'             => array( 'utm/id', 'Slash запрещён.' ),
            'question_mark'     => array( 'utm?', 'Query-start запрещён.' ),
            'newline'           => array( "utm\nid", 'Control character.' ),
            'null_byte'         => array( "utm\0id", 'Null byte.' ),
            'javascript'        => array( 'javascript:', 'XSS-like ключ.' ),
            'html_entity'       => array( 'utm&amp;id', 'Entity.' ),
            'bracket'           => array( 'utm[]', 'Array syntax.' ),
            'at_sign'           => array( '@utm', 'Special char.' ),
            'percent'           => array( 'utm%20', 'URL-encoded.' ),
        );
    }

    // =====================================================================
    // 2. Source-grep: whitelist вызывается во всех save-путях
    // =====================================================================

    public function test_product_save_enforces_whitelist(): void
    {
        $wc_source = (string) file_get_contents(dirname(__DIR__, 3) . '/wc-affiliate-url-params.php');

        self::assertStringContainsString(
            'Cashback_Click_Session_Service::is_valid_affiliate_param_name',
            $wc_source,
            'П.4: save_custom_fields должен вызывать is_valid_affiliate_param_name при сохранении _affiliate_product_params.'
        );
    }

    public function test_network_admin_save_enforces_whitelist(): void
    {
        $partner_source = (string) file_get_contents(dirname(__DIR__, 3) . '/partner/partner-management.php');

        // Bulk save + single update — оба должны вызывать валидатор.
        $occurrences = substr_count($partner_source, 'Cashback_Click_Session_Service::is_valid_affiliate_param_name');
        self::assertGreaterThanOrEqual(
            2,
            $occurrences,
            'П.4: partner-management должен валидировать param_name в ОБОИХ AJAX-хендлерах (bulk save + single update).'
        );
    }

    public function test_service_build_affiliate_url_has_defensive_recheck(): void
    {
        $service_source = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php');

        // Defensive re-check: в build_affiliate_url должен быть вызов валидатора (self::) + continue.
        self::assertMatchesRegularExpression(
            '/self::is_valid_affiliate_param_name[\s\S]{0,500}continue/',
            $service_source,
            'П.4: build_affiliate_url должен делать defensive re-check param_name — на случай данных из до-whitelist периода.'
        );
    }
}
