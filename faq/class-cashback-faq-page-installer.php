<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Faq_Page_Installer
 *
 * Идемпотентное создание публичной WP-страницы /faq/ с шорткодом
 * [cashback_faq] на admin_init. Если флаг install установлен, но страница
 * удалена — пересоздаёт.
 *
 * Паттерн скопирован с Cashback_Legal_Pages_Installer (legal-модуль).
 *
 * @since 1.7.0
 */
class Cashback_Faq_Page_Installer {

    public const INSTALL_FLAG_OPTION = 'cashback_faq_page_installed_v1';
    public const PAGE_ID_OPTION      = 'cashback_faq_page_id';
    public const PAGE_SLUG           = 'faq';

    public static function init(): void {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('admin_init', array( __CLASS__, 'maybe_install' ));
    }

    /**
     * Idempotent: проверяет флаг и существование страницы. Если flag есть,
     * но страница удалена — пересоздаёт.
     */
    public static function maybe_install(): void {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return;
        }

        $installed = (bool) get_option(self::INSTALL_FLAG_OPTION, false);
        $page_id   = (int) get_option(self::PAGE_ID_OPTION, 0);

        if ($installed && $page_id > 0 && get_post_status($page_id) !== false) {
            return;
        }

        $new_id = self::install();
        if ($new_id > 0) {
            update_option(self::PAGE_ID_OPTION, $new_id, false);
            update_option(self::INSTALL_FLAG_OPTION, true, false);
        }
    }

    /**
     * Создаёт страницу. Возвращает page_id или 0 при ошибке.
     */
    public static function install(): int {
        if (!function_exists('wp_insert_post')) {
            return 0;
        }

        $post_id = wp_insert_post(array(
            'post_title'     => __('Вопросы и ответы', 'cashback-plugin'),
            'post_name'      => self::PAGE_SLUG,
            'post_content'   => '[' . Cashback_Faq_Shortcode::SHORTCODE_TAG . ']',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
            'meta_input'     => array(
                '_cashback_faq_page' => 1,
            ),
        ), true);

        if (function_exists('is_wp_error') && is_wp_error($post_id)) {
            return 0;
        }
        return (int) $post_id;
    }

    /**
     * Текущий page_id страницы FAQ (или 0 если ещё не создана).
     */
    public static function get_page_id(): int {
        return (int) get_option(self::PAGE_ID_OPTION, 0);
    }

    /**
     * Permalink на FAQ-страницу. Пустая строка если страницы нет.
     */
    public static function get_url(): string {
        $page_id = self::get_page_id();
        if ($page_id <= 0) {
            return '';
        }
        if (!function_exists('get_post_status') || get_post_status($page_id) === false) {
            return '';
        }
        if (!function_exists('get_permalink')) {
            return '';
        }
        $url = get_permalink($page_id);
        return is_string($url) ? $url : '';
    }
}
