<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты идемпотентности активатора страниц /login/ и /register/.
 *
 * Покрывает:
 *  - первичная активация: создаёт обе страницы, сохраняет их ID в опциях
 *  - повторная активация: НЕ создаёт дубликатов (re-use существующих)
 *  - admin удалил содержимое, page_status != publish: пересоздание
 *  - filter sc_auth_pages_login_slug меняет slug новой страницы
 */
#[Group('sc-auth-pages')]
#[Group('sc-auth-pages-activator')]
final class SCAuthPagesActivatorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        // get_post_status — есть в LegalRegistrationCheckboxesTest, но порядок setUpBeforeClass
        // непредсказуем. Декларируем только если ещё нет.
        if (!function_exists('get_post_status')) {
            function get_post_status( int $post_id )
            {
                return $GLOBALS['_cb_test_post_statuses'][ $post_id ] ?? false;
            }
        }
        if (!function_exists('get_page_by_path')) {
            function get_page_by_path( string $page_path, mixed $output = OBJECT, mixed $post_type = 'page' )
            {
                $store = $GLOBALS['_cb_test_pages_by_slug'] ?? array();
                if (!isset($store[ $page_path ])) {
                    return null;
                }
                $obj = new \stdClass();
                $obj->ID = (int) $store[ $page_path ];
                return $obj;
            }
        }
        if (!function_exists('wp_insert_post')) {
            function wp_insert_post( array $postarr, bool $wp_error = false )
            {
                $next_id = (int) ($GLOBALS['_cb_test_next_post_id'] ?? 1000);
                $GLOBALS['_cb_test_next_post_id'] = $next_id + 1;
                $GLOBALS['_cb_test_inserted_posts'][] = array_merge($postarr, array( 'ID' => $next_id ));

                if (isset($postarr['post_name'])) {
                    $GLOBALS['_cb_test_pages_by_slug'][ $postarr['post_name'] ] = $next_id;
                }
                if (isset($postarr['post_status'])) {
                    $GLOBALS['_cb_test_post_statuses'][ $next_id ] = $postarr['post_status'];
                }
                return $next_id;
            }
        }
        if (!function_exists('sanitize_title')) {
            function sanitize_title( string $title ): string
            {
                $title = strtolower($title);
                return (string) preg_replace('/[^a-z0-9_\-]/', '', $title);
            }
        }

        require_once $plugin_root . '/includes/sc-auth-pages/class-sc-auth-pages-activator.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_cb_test_options']         = array();
        $GLOBALS['_cb_test_post_statuses']   = array();
        $GLOBALS['_cb_test_pages_by_slug']   = array();
        $GLOBALS['_cb_test_inserted_posts']  = array();
        $GLOBALS['_cb_test_next_post_id']    = 1000;
        $GLOBALS['_cb_test_filters']         = array();
    }

    public function test_first_activation_creates_both_pages_and_saves_options(): void
    {
        Cashback_SC_Auth_Pages_Activator::activate();

        $login_id    = (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_LOGIN_PAGE_ID);
        $register_id = (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_REGISTER_PAGE_ID);

        $this->assertGreaterThan(0, $login_id, 'Login page ID должен быть сохранён в опции');
        $this->assertGreaterThan(0, $register_id, 'Register page ID должен быть сохранён в опции');
        $this->assertNotSame($login_id, $register_id, 'Login и register страницы должны быть разными');

        $this->assertCount(2, $GLOBALS['_cb_test_inserted_posts']);

        $login_post = $this->find_inserted_post('login');
        $this->assertNotNull($login_post);
        $this->assertSame('publish', $login_post['post_status']);
        $this->assertSame('page', $login_post['post_type']);
        $this->assertSame('[sc_login]', $login_post['post_content']);

        $register_post = $this->find_inserted_post('register');
        $this->assertNotNull($register_post);
        $this->assertSame('[sc_register]', $register_post['post_content']);

        $this->assertSame(
            Cashback_SC_Auth_Pages_Activator::DB_VERSION,
            get_option(Cashback_SC_Auth_Pages_Activator::OPTION_DB_VERSION)
        );
    }

    public function test_repeat_activation_does_not_create_duplicates(): void
    {
        Cashback_SC_Auth_Pages_Activator::activate();

        $login_id_first    = (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_LOGIN_PAGE_ID);
        $register_id_first = (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_REGISTER_PAGE_ID);

        // Симулируем повторную активацию
        Cashback_SC_Auth_Pages_Activator::activate();

        $login_id_second    = (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_LOGIN_PAGE_ID);
        $register_id_second = (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_REGISTER_PAGE_ID);

        $this->assertSame($login_id_first, $login_id_second, 'Login ID не должен меняться при повторной активации');
        $this->assertSame($register_id_first, $register_id_second, 'Register ID не должен меняться при повторной активации');

        // wp_insert_post вызвался строго 2 раза (первый раз) и больше не вызывался
        $this->assertCount(2, $GLOBALS['_cb_test_inserted_posts'], 'wp_insert_post не должен вызываться повторно');
    }

    public function test_existing_page_with_same_slug_is_picked_up(): void
    {
        // Имитируем что админ уже создал страницу с slug 'login' до активации
        $GLOBALS['_cb_test_pages_by_slug']['login']    = 555;
        $GLOBALS['_cb_test_post_statuses'][555]        = 'publish';
        $GLOBALS['_cb_test_pages_by_slug']['register'] = 777;
        $GLOBALS['_cb_test_post_statuses'][777]        = 'publish';

        Cashback_SC_Auth_Pages_Activator::activate();

        $this->assertSame(
            555,
            (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_LOGIN_PAGE_ID),
            'Должна подхватиться существующая страница с slug=login'
        );
        $this->assertSame(
            777,
            (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_REGISTER_PAGE_ID)
        );
        $this->assertCount(0, $GLOBALS['_cb_test_inserted_posts'], 'Не должно быть новых wp_insert_post');
    }

    public function test_unpublished_existing_id_triggers_new_creation(): void
    {
        // В опции записан ID, но страница имеет статус 'trash' — пересоздаём.
        update_option(Cashback_SC_Auth_Pages_Activator::OPTION_LOGIN_PAGE_ID, 100);
        $GLOBALS['_cb_test_post_statuses'][100] = 'trash';

        Cashback_SC_Auth_Pages_Activator::activate();

        $login_id = (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_LOGIN_PAGE_ID);
        $this->assertNotSame(100, $login_id, 'Старый ID не должен использоваться для trash-страницы');
        $this->assertGreaterThan(0, $login_id);
    }

    public function test_filter_overrides_login_slug(): void
    {
        add_filter('sc_auth_pages_login_slug', static fn() => 'signin');

        Cashback_SC_Auth_Pages_Activator::activate();

        $login_post = $this->find_inserted_post('signin');
        $this->assertNotNull($login_post, 'Должна создаться страница с slug=signin');
    }

    public function test_uninstall_removes_options_but_not_pages(): void
    {
        Cashback_SC_Auth_Pages_Activator::activate();

        Cashback_SC_Auth_Pages_Activator::uninstall();

        $this->assertFalse(get_option(Cashback_SC_Auth_Pages_Activator::OPTION_LOGIN_PAGE_ID, false));
        $this->assertFalse(get_option(Cashback_SC_Auth_Pages_Activator::OPTION_REGISTER_PAGE_ID, false));
        $this->assertFalse(get_option(Cashback_SC_Auth_Pages_Activator::OPTION_DB_VERSION, false));

        // Сами posts не удалялись (тест: они всё ещё в _cb_test_inserted_posts).
        $this->assertCount(2, $GLOBALS['_cb_test_inserted_posts']);
    }

    /**
     * Найти первый созданный пост по slug.
     *
     * @return array<string,mixed>|null
     */
    private function find_inserted_post( string $slug ): ?array
    {
        foreach ($GLOBALS['_cb_test_inserted_posts'] as $post) {
            if (($post['post_name'] ?? '') === $slug) {
                return $post;
            }
        }
        return null;
    }
}
