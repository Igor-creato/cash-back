<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_SC_Auth_Pages_Menu_Filter
 *
 * Условная замена пунктов «Вход» и «Регистрация» в WP-меню для авторизованных
 * пользователей: оба пункта удаляются, на их место вставляется один пункт с
 * именем юзера, ведущий на /my-account/.
 *
 * Точка интеграции — фильтр `wp_get_nav_menu_items` (массив пунктов до рендера).
 * Применяется ко всем меню сайта (header, footer, mobile) — гарантирует
 * consistency между разными расположениями.
 *
 * Идентификация пунктов входа/регистрации работает двумя путями:
 *   - type='post_type' и object_id совпадает с sc_auth_pages_(login|register)_id
 *     (юзер добавил пункт через Pages в menu builder)
 *   - type='custom' и url совпадает с Login/Register::get_*_url() (юзер добавил
 *     через Custom Link); сравнение нормализует trailing slash + игнорирует query/hash.
 *
 * Filters для расширения:
 *   - sc_auth_pages_menu_replace_enabled (bool, default true) — глобальный disable
 *   - sc_auth_pages_menu_user_label (string $label, WP_User $user) — override текста
 *   - sc_auth_pages_menu_user_url (string $url, WP_User $user) — override target URL
 *
 * @since 1.3.0
 */
class Cashback_SC_Auth_Pages_Menu_Filter {

    /**
     * Колбэк фильтра wp_get_nav_menu_items (priority 10, 3 args).
     *
     * @param mixed $items Массив пунктов меню (object[]) или иное (parano-fallback).
     * @param mixed $menu  WP_Term объект меню (не используем — фильтруемся для всех).
     * @param mixed $args  wp_nav_menu args (не используем).
     * @return mixed Модифицированный массив или исходный.
     */
    public static function filter_items( $items, $menu = null, $args = null ) {
        if (!is_array($items) || $items === array()) {
            return $items;
        }
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return $items;
        }
        if (function_exists('is_admin') && is_admin()) {
            // Не трогаем админку — там Appearance → Menus редактируется.
            return $items;
        }
        if (!(bool) apply_filters('sc_auth_pages_menu_replace_enabled', true, $menu, $args)) {
            return $items;
        }

        $login_id    = (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_LOGIN_PAGE_ID, 0);
        $register_id = (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_REGISTER_PAGE_ID, 0);

        $login_url    = '';
        $register_url = '';
        if (class_exists('Cashback_SC_Auth_Pages_Login')) {
            $login_url = self::normalize_url(Cashback_SC_Auth_Pages_Login::get_login_url());
        }
        if (class_exists('Cashback_SC_Auth_Pages_Register')) {
            $register_url = self::normalize_url(Cashback_SC_Auth_Pages_Register::get_register_url());
        }

        $removed_position = null;
        $filtered         = array();

        foreach ($items as $item) {
            if (self::is_auth_item($item, $login_id, $register_id, $login_url, $register_url)) {
                if ($removed_position === null) {
                    $removed_position = (int) ($item->menu_order ?? 0);
                }
                continue;
            }
            $filtered[] = $item;
        }

        if ($removed_position === null) {
            // В этом меню ничего из наших — оригинал.
            return $items;
        }

        $filtered[] = self::build_user_item($removed_position);

        usort($filtered, static function ( $a, $b ): int {
            return ((int) ($a->menu_order ?? 0)) <=> ((int) ($b->menu_order ?? 0));
        });

        return $filtered;
    }

    /**
     * Нормализация URL для сравнения: scheme+host+path без trailing slash, без query/hash.
     */
    public static function normalize_url( string $url ): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        // wp_parse_url стабильнее parse_url по версиям PHP; в тестовом окружении он мокнут.
        $parts = function_exists('wp_parse_url')
            ? wp_parse_url($url)
            // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- fallback только если wp_parse_url отсутствует.
            : parse_url($url);
        if (!is_array($parts)) {
            return rtrim($url, '/');
        }
        $scheme = (string) ($parts['scheme'] ?? '');
        $host   = (string) ($parts['host'] ?? '');
        $path   = rtrim((string) ($parts['path'] ?? ''), '/');
        return ($scheme !== '' ? $scheme . '://' : '') . $host . $path;
    }

    /**
     * Является ли пункт меню ссылкой на /login/ или /register/?
     */
    private static function is_auth_item( $item, int $login_id, int $register_id, string $login_url, string $register_url ): bool {
        if (!is_object($item)) {
            return false;
        }
        $type      = (string) ($item->type ?? '');
        $object_id = (int) ($item->object_id ?? 0);

        if ($type === 'post_type' && $object_id > 0) {
            if (($login_id > 0 && $object_id === $login_id) || ($register_id > 0 && $object_id === $register_id)) {
                return true;
            }
        }

        if ($type === 'custom') {
            $url = self::normalize_url((string) ($item->url ?? ''));
            if ($url !== '') {
                if ($login_url !== '' && $url === $login_url) {
                    return true;
                }
                if ($register_url !== '' && $url === $register_url) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Построение нового custom-пункта меню «Имя юзера → /my-account/».
     */
    private static function build_user_item( int $menu_order ): stdClass {
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;

        $display_name = '';
        $login        = '';
        if (is_object($user)) {
            $display_name = (string) ($user->display_name ?? '');
            $login        = (string) ($user->user_login ?? '');
        }
        $label = $display_name !== '' ? $display_name : $login;
        $label = (string) apply_filters('sc_auth_pages_menu_user_label', $label, $user);

        $url = class_exists('Cashback_SC_Auth_Pages_Redirect_Helper')
            ? Cashback_SC_Auth_Pages_Redirect_Helper::get_my_account_url()
            : (function_exists('home_url') ? (string) home_url('/my-account/') : '/my-account/');
        $url = (string) apply_filters('sc_auth_pages_menu_user_url', $url, $user);

        $item                       = new stdClass();
        $item->ID                   = -1;
        $item->title                = $label;
        $item->url                  = $url;
        $item->menu_item_parent     = '0';
        $item->object_id            = -1;
        $item->object               = 'custom';
        $item->type                 = 'custom';
        $item->type_label           = __('Custom Link', 'cashback-plugin');
        $item->target               = '';
        $item->attr_title           = '';
        $item->description          = '';
        $item->classes              = array( 'menu-item', 'menu-item-type-custom', 'menu-item-sc-auth-user' );
        $item->xfn                  = '';
        $item->current              = false;
        $item->current_item_ancestor = false;
        $item->current_item_parent  = false;
        $item->menu_order           = $menu_order;
        return $item;
    }
}
