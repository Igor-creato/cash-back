<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Legal_Pages_Installer (Phase 2).
 *
 * Полный install требует WP runtime (wp_insert_post). Здесь покрываем
 * чистые методы:
 *  - detect_missing_pages при пустом map → все типы кроме contact_form_pd
 *  - detect_missing_pages игнорирует contact_form_pd (нет публичной страницы)
 *  - get_url_for_type → '' для несуществующего page_id
 *  - get_url_for_type → URL для существующего page
 */
#[Group('legal')]
#[Group('legal-pages-installer')]
final class LegalPagesInstallerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/legal/class-cashback-legal-documents.php';

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
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']       = array();
        $GLOBALS['_cb_test_post_statuses'] = array();
    }

    public function test_detect_missing_pages_returns_all_public_types_when_map_empty(): void
    {
        $missing = Cashback_Legal_Pages_Installer::detect_missing_pages();
        $expected_count = count(Cashback_Legal_Documents::all_types()) - 1; // -1 за contact_form_pd
        $this->assertCount($expected_count, $missing);
        $this->assertNotContains(
            Cashback_Legal_Documents::TYPE_CONTACT_FORM_PD,
            $missing,
            'contact_form_pd не должен попадать в публичные страницы'
        );
        $this->assertContains(Cashback_Legal_Documents::TYPE_PD_POLICY, $missing);
    }

    public function test_detect_missing_pages_skips_existing_published_pages(): void
    {
        $GLOBALS['_cb_test_options'][ Cashback_Legal_Pages_Installer::PAGES_MAP_OPTION ] = array(
            Cashback_Legal_Documents::TYPE_PD_POLICY  => 50,
            Cashback_Legal_Documents::TYPE_PD_CONSENT => 51,
        );
        $GLOBALS['_cb_test_post_statuses'] = array(
            50 => 'publish',
            51 => 'publish',
        );

        $missing = Cashback_Legal_Pages_Installer::detect_missing_pages();
        $this->assertNotContains(Cashback_Legal_Documents::TYPE_PD_POLICY, $missing);
        $this->assertNotContains(Cashback_Legal_Documents::TYPE_PD_CONSENT, $missing);
        $this->assertContains(Cashback_Legal_Documents::TYPE_PAYMENT_PD, $missing);
    }

    public function test_detect_missing_pages_treats_deleted_page_as_missing(): void
    {
        $GLOBALS['_cb_test_options'][ Cashback_Legal_Pages_Installer::PAGES_MAP_OPTION ] = array(
            Cashback_Legal_Documents::TYPE_PD_POLICY => 50,
        );
        // Опция указывает на page_id 50, но get_post_status вернёт false (страница удалена).
        $missing = Cashback_Legal_Pages_Installer::detect_missing_pages();
        $this->assertContains(Cashback_Legal_Documents::TYPE_PD_POLICY, $missing);
    }

    public function test_get_url_for_type_returns_empty_when_map_missing(): void
    {
        $url = Cashback_Legal_Pages_Installer::get_url_for_type(Cashback_Legal_Documents::TYPE_PD_POLICY);
        $this->assertSame('', $url);
    }

    public function test_get_url_for_type_returns_permalink_when_mapped(): void
    {
        $GLOBALS['_cb_test_options'][ Cashback_Legal_Pages_Installer::PAGES_MAP_OPTION ] = array(
            Cashback_Legal_Documents::TYPE_TERMS_OFFER => 77,
        );
        $url = Cashback_Legal_Pages_Installer::get_url_for_type(Cashback_Legal_Documents::TYPE_TERMS_OFFER);
        $this->assertStringContainsString('?p=77', $url);
    }
}
