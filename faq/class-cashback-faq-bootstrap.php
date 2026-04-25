<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Faq_Bootstrap
 *
 * Точка входа модуля FAQ. Подключается из CashbackPlugin::load_dependencies()
 * (cashback-plugin.php) — паттерн Cashback_Legal_Bootstrap.
 *
 * Состав модуля:
 *  - Cashback_Faq_Content        — захардкоженный набор Q&A
 *  - Cashback_Faq_Shortcode      — шорткод [cashback_faq]
 *  - Cashback_Faq_Page_Installer — idempotent создание /faq/
 *  - Cashback_Faq_Account_Link   — пункт «Помощь» в меню My Account
 *
 * @since 1.7.0
 */
class Cashback_Faq_Bootstrap {

    public const ASSET_HANDLE   = 'cashback-faq';
    public const PLUGIN_VERSION = '1.7.0';

    /**
     * Idempotent init. Повторный вызов — noop.
     */
    public static function init(): void {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $base = __DIR__;

        $files = array(
            'class-cashback-faq-content.php',
            'class-cashback-faq-shortcode.php',
            'class-cashback-faq-page-installer.php',
            'class-cashback-faq-account-link.php',
        );

        foreach ($files as $file) {
            $path = $base . '/' . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }

        if (class_exists('Cashback_Faq_Shortcode')) {
            Cashback_Faq_Shortcode::init();
        }
        if (class_exists('Cashback_Faq_Page_Installer')) {
            Cashback_Faq_Page_Installer::init();
        }
        if (class_exists('Cashback_Faq_Account_Link')) {
            Cashback_Faq_Account_Link::init();
        }

        if (function_exists('add_action')) {
            add_action('wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ));
        }
    }

    /**
     * Подключает CSS только на странице, где используется шорткод
     * [cashback_faq], либо на нашей публичной странице /faq/.
     */
    public static function maybe_enqueue_assets(): void {
        if (!self::should_enqueue()) {
            return;
        }

        $plugin_dir = dirname(__DIR__);
        $rel_path   = 'assets/css/cashback-faq.css';
        $abs_path   = $plugin_dir . '/' . $rel_path;
        $version    = file_exists($abs_path) ? (string) filemtime($abs_path) : self::PLUGIN_VERSION;

        wp_enqueue_style(
            self::ASSET_HANDLE,
            plugins_url($rel_path, $plugin_dir . '/cashback-plugin.php'),
            array(),
            $version
        );
    }

    private static function should_enqueue(): bool {
        global $post;

        if (class_exists('Cashback_Faq_Page_Installer') && $post instanceof WP_Post) {
            $faq_page_id = Cashback_Faq_Page_Installer::get_page_id();
            if ($faq_page_id > 0 && (int) $post->ID === $faq_page_id) {
                return true;
            }
        }

        if (function_exists('has_shortcode') && $post instanceof WP_Post && is_string($post->post_content)) {
            if (has_shortcode($post->post_content, Cashback_Faq_Shortcode::SHORTCODE_TAG)) {
                return true;
            }
        }

        return false;
    }
}
