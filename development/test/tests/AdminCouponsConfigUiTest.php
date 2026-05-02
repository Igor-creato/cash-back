<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурный тест на admin-расширение формы «Настройки API»:
 * наличие 4 новых полей купонов + handler в save_api_settings.
 *
 * @group promocodes
 * @group admin
 */
#[Group('promocodes')]
#[Group('admin')]
final class AdminCouponsConfigUiTest extends TestCase
{
    private static string $admin_php;

    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        self::$admin_php = file_get_contents($plugin_root . '/admin/class-cashback-admin-api-validation.php');
    }

    public function test_form_contains_api_coupons_endpoint_input(): void
    {
        $this->assertMatchesRegularExpression(
            '/name=["\']api_coupons_endpoint["\']/i',
            self::$admin_php,
            'Form should contain input name=api_coupons_endpoint'
        );
    }

    public function test_form_contains_api_coupons_field_map_input(): void
    {
        $this->assertMatchesRegularExpression(
            '/name=["\']api_coupons_field_map["\']/i',
            self::$admin_php
        );
    }

    public function test_form_contains_api_coupons_species_map_input(): void
    {
        $this->assertMatchesRegularExpression(
            '/name=["\']api_coupons_species_map["\']/i',
            self::$admin_php
        );
    }

    public function test_form_contains_api_coupons_pagination_select(): void
    {
        $this->assertMatchesRegularExpression(
            '/name=["\']api_coupons_pagination["\']/i',
            self::$admin_php
        );
        // Должны быть три option-значения.
        $this->assertStringContainsString('offset_limit', self::$admin_php);
        $this->assertStringContainsString('"page"', self::$admin_php);
        $this->assertStringContainsString('"none"', self::$admin_php);
    }

    public function test_save_handler_processes_api_coupons_endpoint(): void
    {
        // Должен быть handler для $_POST['api_coupons_endpoint'].
        $this->assertMatchesRegularExpression(
            '/\$_POST\[\s*["\']api_coupons_endpoint["\']\s*\]/i',
            self::$admin_php
        );
    }

    public function test_save_validates_field_map_as_json(): void
    {
        // В save должна быть json_decode проверка для api_coupons_field_map.
        $this->assertMatchesRegularExpression(
            '/api_coupons_field_map.*json_decode/is',
            self::$admin_php
        );
    }

    public function test_save_validates_species_map_as_json(): void
    {
        $this->assertMatchesRegularExpression(
            '/api_coupons_species_map.*json_decode/is',
            self::$admin_php
        );
    }
}
