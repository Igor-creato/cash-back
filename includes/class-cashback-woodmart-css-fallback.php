<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Woodmart_Css_Fallback
 *
 * Defense-in-depth для WoodMart Header Builder / Theme Settings customizer CSS.
 *
 * Проблема: WoodMart 8.x хранит сгенерированный customizer CSS как файл в
 * uploads/ (`xts-default_header-{TS}.css` и `xts-theme_settings_default-{TS}.css`).
 * На странице файл подключается через <link rel="stylesheet">. Сгенерированный
 * CSS дублируется в опции `xts-{name}-css-data`.
 *
 * Между моментом обновления опции `xts-{name}-file-data` (с новым timestamp)
 * и физическим созданием файла на диске (или после его удаления внешним
 * процессом) есть окно. В этом окне:
 *  - PHP проверяет file_exists() → может вернуть true из-за opcache stat-cache
 *  - HTML рендерится с <link href=".../xts-...-{T2}.css">
 *  - HTML кэшируется nginx fastcgi_cache на 30 мин
 *  - Браузер делает GET → 404 → top-bar остаётся без фона/высоты
 *  - Пользователь видит «пропавший верх сайта» до истечения TTL кэша
 *
 * Этот модуль страхует:
 *  - На wp_print_styles priority 999 (после WoodMart's Frontend::styles prio 200
 *    и enqueue хуков) проходим по двум известным style handles
 *  - Если зарегистрированный handle указывает на отсутствующий физ.файл,
 *    переключаем на inline CSS из соответствующей DB-опции `xts-{name}-css-data`
 *  - Опция всегда актуальна (WoodMart пишет её в той же транзакции, что и
 *    file-data), поэтому inline fallback гарантированно содержит правильный
 *    набор правил
 *
 * Не зависит от темы: если WoodMart не активен или handles не зарегистрированы,
 * метод тихо ничего не делает (idempotent no-op).
 *
 * @since 1.2.1
 */
class Cashback_Woodmart_Css_Fallback {

    /**
     * Style handles, которые WoodMart регистрирует через Styles_Storage::file_css().
     *
     * Сопоставление handle → имя storage (используется как префикс для опций).
     * При расширении (если WoodMart добавит новые storage-слоты) — дополнить.
     *
     * @var array<string,string>
     */
    private const HANDLES = array(
        'xts-style-default_header'           => 'default_header',
        'xts-style-theme_settings_default'   => 'theme_settings_default',
    );

    /**
     * Регистрация хука. Идемпотентно: повторный вызов не дублирует action.
     */
    public static function register(): void {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('wp_enqueue_scripts', array( __CLASS__, 'force_footer_styles_on_cashback_pages' ), 100);
        // priority 999 — после WoodMart's Frontend->styles() (prio 200) и
        // wp_enqueue_scripts callback'ов плагинов; до фактического print
        // styles темой/ядром.
        add_action('wp_print_styles', array( __CLASS__, 'maybe_fallback_to_inline' ), 999);
    }

    /**
     * На авто-страницах плагина WoodMart может не иметь прогретого
     * `wd_page_css_files`, поэтому footer-base догружается поздно из footer.php.
     * Форсируем его заранее в head только для наших публичных страниц.
     */
    public static function force_footer_styles_on_cashback_pages(): void {
        if ((function_exists('is_admin') && is_admin()) || !self::is_cashback_public_page()) {
            return;
        }
        if (!function_exists('woodmart_force_enqueue_style')) {
            return;
        }

        woodmart_force_enqueue_style('footer-base');
    }

    /**
     * Проверить каждый известный handle: если файл отсутствует — переключить на inline.
     */
    public static function maybe_fallback_to_inline(): void {
        if (function_exists('is_admin') && is_admin()) {
            return;
        }
        global $wp_styles;
        if (!is_object($wp_styles)) {
            return;
        }

        foreach (self::HANDLES as $handle => $storage_name) {
            self::process_handle($handle, $storage_name);
        }
    }

    /**
     * Проверить конкретный handle и применить fallback при необходимости.
     */
    private static function process_handle( string $handle, string $storage_name ): void {
        global $wp_styles;

        if (!isset($wp_styles->registered[ $handle ])) {
            return;
        }

        $registered = $wp_styles->registered[ $handle ];
        $src        = isset($registered->src) ? (string) $registered->src : '';
        if ($src === '') {
            return;
        }

        $file_data = get_option('xts-' . $storage_name . '-file-data');
        if (!is_array($file_data) || empty($file_data['path'])) {
            return;
        }

        $path = (string) $file_data['path'];
        // wp_upload_dir() — единственный надёжный способ резолвить тот же
        // basedir, что использует Styles_Storage::get_file_path().
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
        if (!is_array($uploads) || empty($uploads['basedir'])) {
            return;
        }
        $abs_path = (string) $uploads['basedir'] . $path;

        // clearstatcache на конкретный путь — без аргументов делает global flush
        // и заметно дороже; нам важен именно этот файл.
        clearstatcache(true, $abs_path);
        if (file_exists($abs_path) && is_readable($abs_path) && filesize($abs_path) > 0) {
            // Файл доступен — WoodMart штатно его подключит, наш fallback не нужен.
            return;
        }

        $css = (string) get_option('xts-' . $storage_name . '-css-data');
        if (trim($css) === '') {
            // Опция тоже пуста — нечего подставить, тихо отступаем (избегаем
            // деградации до пустого <style>).
            return;
        }

        // Подменяем источник: deregister + register без src + add_inline_style.
        // wp_dequeue + reregister гарантирует, что WP не пытается грузить
        // несуществующий файл, но порядок зависимостей сохраняется.
        $version = isset($registered->ver) ? $registered->ver : null;
        $deps    = isset($registered->deps) && is_array($registered->deps) ? $registered->deps : array();

        wp_deregister_style($handle);
        wp_register_style($handle, false, $deps, $version);
        wp_enqueue_style($handle);
        wp_add_inline_style($handle, $css);

        if (function_exists('error_log') && defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging guarded by WP_DEBUG.
            error_log(sprintf(
                '[Cashback] WoodMart CSS fallback to inline for handle "%s": file %s missing/unreadable, used DB option (%d bytes)',
                $handle,
                $abs_path,
                strlen($css)
            ));
        }
    }

    /**
     * Определить публичные страницы плагина, где контент рендерится шорткодом.
     */
    private static function is_cashback_public_page(): bool {
        if (function_exists('is_singular') && !is_singular()) {
            return false;
        }

        $post_id = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
        if ($post_id > 0 && function_exists('get_post_meta')) {
            $legal_type = (string) get_post_meta($post_id, '_cashback_legal_type', true);
            if ($legal_type !== '') {
                return true;
            }
        }

        if (!function_exists('get_post') || !function_exists('has_shortcode')) {
            return false;
        }

        $post = get_post($post_id > 0 ? $post_id : null);
        if (!$post instanceof WP_Post) {
            return false;
        }

        foreach (array( 'cashback_legal_doc', 'cashback_contact_form' ) as $shortcode) {
            if (has_shortcode($post->post_content, $shortcode)) {
                return true;
            }
        }

        return false;
    }
}
