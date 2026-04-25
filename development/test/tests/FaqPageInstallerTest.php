<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Faq_Page_Installer — idempotent создание /faq/.
 *
 * Покрываем чистые методы без полного wp_insert_post:
 *   - get_page_id() / get_url() при отсутствии записи → 0 / ''
 *   - get_url() пуст для удалённой страницы (page_id есть, но get_post_status=false)
 *   - get_url() возвращает permalink для существующей страницы
 *   - install() при наличии mock'а wp_insert_post сохраняет page_id
 */
#[Group('faq')]
#[Group('faq-page-installer')]
final class FaqPageInstallerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

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
        if (!function_exists('wp_insert_post')) {
            function wp_insert_post( array $postarr, bool $wp_error = false ) {
                $next_id = ($GLOBALS['_cb_test_next_post_id'] ?? 100);
                $GLOBALS['_cb_test_next_post_id'] = $next_id + 1;
                $GLOBALS['_cb_test_post_statuses'][ $next_id ] = $postarr['post_status'] ?? 'publish';
                $GLOBALS['_cb_test_inserted_posts'][ $next_id ] = $postarr;
                return $next_id;
            }
        }

        require_once $plugin_root . '/faq/class-cashback-faq-content.php';
        require_once $plugin_root . '/faq/class-cashback-faq-shortcode.php';
        require_once $plugin_root . '/faq/class-cashback-faq-page-installer.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']         = array();
        $GLOBALS['_cb_test_post_statuses']   = array();
        $GLOBALS['_cb_test_inserted_posts']  = array();
        $GLOBALS['_cb_test_next_post_id']    = 100;
        $GLOBALS['_cb_test_current_user_can'] = true;
    }

    public function test_get_page_id_returns_zero_when_not_installed(): void
    {
        $this->assertSame(0, Cashback_Faq_Page_Installer::get_page_id());
    }

    public function test_get_url_returns_empty_when_not_installed(): void
    {
        $this->assertSame('', Cashback_Faq_Page_Installer::get_url());
    }

    public function test_get_url_returns_empty_when_page_deleted(): void
    {
        $GLOBALS['_cb_test_options'][ Cashback_Faq_Page_Installer::PAGE_ID_OPTION ] = 200;
        // post_statuses[200] не задан → get_post_status вернёт false.
        $this->assertSame('', Cashback_Faq_Page_Installer::get_url());
    }

    public function test_get_url_returns_permalink_when_page_exists(): void
    {
        $GLOBALS['_cb_test_options'][ Cashback_Faq_Page_Installer::PAGE_ID_OPTION ] = 200;
        $GLOBALS['_cb_test_post_statuses'][200] = 'publish';

        $url = Cashback_Faq_Page_Installer::get_url();
        $this->assertStringContainsString('?p=200', $url);
    }

    public function test_install_inserts_page_with_correct_attributes(): void
    {
        $page_id = Cashback_Faq_Page_Installer::install();

        $this->assertGreaterThan(0, $page_id);
        $this->assertArrayHasKey($page_id, $GLOBALS['_cb_test_inserted_posts']);

        $post = $GLOBALS['_cb_test_inserted_posts'][ $page_id ];
        $this->assertSame(Cashback_Faq_Page_Installer::PAGE_SLUG, $post['post_name']);
        $this->assertSame('publish', $post['post_status']);
        $this->assertSame('page', $post['post_type']);
        $this->assertStringContainsString('[' . Cashback_Faq_Shortcode::SHORTCODE_TAG . ']', $post['post_content']);
    }

    public function test_maybe_install_creates_page_on_first_run(): void
    {
        Cashback_Faq_Page_Installer::maybe_install();

        $page_id = Cashback_Faq_Page_Installer::get_page_id();
        $this->assertGreaterThan(0, $page_id);
        $this->assertTrue((bool) get_option(Cashback_Faq_Page_Installer::INSTALL_FLAG_OPTION));
    }

    public function test_maybe_install_is_noop_when_page_exists(): void
    {
        // Первый прогон — создаёт.
        Cashback_Faq_Page_Installer::maybe_install();
        $first_id = Cashback_Faq_Page_Installer::get_page_id();
        $count_before = count($GLOBALS['_cb_test_inserted_posts']);

        // Второй прогон — должен быть noop.
        Cashback_Faq_Page_Installer::maybe_install();
        $second_id = Cashback_Faq_Page_Installer::get_page_id();
        $count_after = count($GLOBALS['_cb_test_inserted_posts']);

        $this->assertSame($first_id, $second_id);
        $this->assertSame($count_before, $count_after, 'Повторный install не должен вставлять новую страницу');
    }

    public function test_maybe_install_recreates_when_page_deleted(): void
    {
        // Симулируем флаг install + page_id, но страница "удалена" (нет в post_statuses).
        update_option(Cashback_Faq_Page_Installer::INSTALL_FLAG_OPTION, true);
        update_option(Cashback_Faq_Page_Installer::PAGE_ID_OPTION, 50);
        // post_statuses[50] не задан.

        Cashback_Faq_Page_Installer::maybe_install();

        $new_id = Cashback_Faq_Page_Installer::get_page_id();
        $this->assertNotSame(50, $new_id);
        $this->assertGreaterThan(0, $new_id);
    }

    public function test_maybe_install_skips_for_unprivileged_user(): void
    {
        $GLOBALS['_cb_test_current_user_can'] = false;
        Cashback_Faq_Page_Installer::maybe_install();
        $this->assertSame(0, Cashback_Faq_Page_Installer::get_page_id());
    }
}
