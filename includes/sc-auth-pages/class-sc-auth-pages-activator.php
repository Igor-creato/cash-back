<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_SC_Auth_Pages_Activator
 *
 * Идемпотентный upsert WP-страниц /login/ и /register/ при активации плагина.
 *
 * Стратегия:
 *   1. Если в опции уже сохранён ID и страница существует и опубликована — оставляем как есть.
 *   2. Если в опции пусто, но страница со slug login/register уже существует — подхватываем её ID.
 *   3. Иначе создаём новую страницу с шорткодом [sc_login] / [sc_register].
 *
 * Это гарантирует:
 *   - повторная активация не плодит дубликатов;
 *   - админ может вручную отредактировать содержимое страницы — мы её не перезатрём;
 *   - смена slug через filter sc_auth_pages_login_slug / sc_auth_pages_register_slug
 *     создаст новую страницу при следующей активации (старая останется как «осиротевшая»,
 *     админ удалит вручную).
 *
 * @since 1.3.0
 */
class Cashback_SC_Auth_Pages_Activator {

    public const OPTION_LOGIN_PAGE_ID    = 'sc_auth_pages_login_id';
    public const OPTION_REGISTER_PAGE_ID = 'sc_auth_pages_register_id';
    public const OPTION_DB_VERSION       = 'sc_auth_pages_db_version';
    public const DB_VERSION              = '1';

    /**
     * Точка входа активации модуля. Вызывается из основного activate() плагина.
     */
    public static function activate(): void {
        self::upsert_login_page();
        self::upsert_register_page();
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    /**
     * Создание / поиск страницы /login/.
     */
    public static function upsert_login_page(): int {
        $slug    = self::filter_slug('login', 'sc_auth_pages_login_slug');
        $title   = (string) apply_filters('sc_auth_pages_login_title', __('Вход', 'cashback-plugin'));
        $content = (string) apply_filters('sc_auth_pages_login_content', '[sc_login]');

        return self::upsert_page($slug, $title, $content, self::OPTION_LOGIN_PAGE_ID);
    }

    /**
     * Создание / поиск страницы /register/.
     */
    public static function upsert_register_page(): int {
        $slug    = self::filter_slug('register', 'sc_auth_pages_register_slug');
        $title   = (string) apply_filters('sc_auth_pages_register_title', __('Регистрация', 'cashback-plugin'));
        $content = (string) apply_filters('sc_auth_pages_register_content', '[sc_register]');

        return self::upsert_page($slug, $title, $content, self::OPTION_REGISTER_PAGE_ID);
    }

    /**
     * Универсальный upsert: сначала проверяет сохранённый ID, затем slug, иначе создаёт.
     *
     * @return int ID страницы (0 если создать не удалось).
     */
    private static function upsert_page( string $slug, string $title, string $content, string $option_key ): int {
        $existing_id = (int) get_option($option_key, 0);

        if ($existing_id > 0 && self::is_publish_status(get_post_status($existing_id))) {
            return $existing_id;
        }

        $page = self::resolve_existing_page_by_slug($slug);
        if ($page !== null) {
            update_option($option_key, $page);
            return $page;
        }

        $created = wp_insert_post(array(
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ), true);

        if (is_wp_error($created) || !is_int($created) || $created <= 0) {
            return 0;
        }

        update_option($option_key, $created);
        return $created;
    }

    /**
     * Найти существующую страницу по slug. Использует get_page_by_path если доступна,
     * иначе возвращает null. Внешние мокабельные функции изолированы здесь.
     */
    private static function resolve_existing_page_by_slug( string $slug ): ?int {
        if (!function_exists('get_page_by_path')) {
            return null;
        }

        $page = get_page_by_path($slug, OBJECT, 'page');

        if ($page === null) {
            return null;
        }

        // get_page_by_path возвращает WP_Post, у которого ID — int (not-nullable).
        $id = (int) $page->ID;
        return $id > 0 ? $id : null;
    }

    /**
     * Sanitize slug + filter override.
     */
    private static function filter_slug( string $default_slug, string $filter_name ): string {
        $slug = (string) apply_filters($filter_name, $default_slug);
        $slug = function_exists('sanitize_title') ? sanitize_title($slug) : sanitize_key($slug);
        return $slug !== '' ? $slug : $default_slug;
    }

    /**
     * Признак опубликованной страницы. wp_insert_post возвращает 0 при ошибке.
     */
    private static function is_publish_status( $status ): bool {
        return $status === 'publish';
    }

    /**
     * Удаление опций модуля при uninstall (для register_uninstall_hook,
     * если в будущем понадобится). Сами страницы НЕ удаляем — их мог отредактировать
     * админ; пусть вручную чистит.
     */
    public static function uninstall(): void {
        delete_option(self::OPTION_LOGIN_PAGE_ID);
        delete_option(self::OPTION_REGISTER_PAGE_ID);
        delete_option(self::OPTION_DB_VERSION);
    }
}
