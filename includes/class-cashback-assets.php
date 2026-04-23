<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Централизованный enqueue-хелпер для общих фронтенд-ассетов.
 *
 * Группа 9 ADR: XSS / DOMPurify enqueue. Один handle 'cashback-safe-html'
 * (зависит от 'dompurify') реализует window.cashbackSafeHtml(), используемый
 * в claims/history/affiliate/support AJAX-renderers.
 *
 * Использование в модуле:
 *   add_action('wp_enqueue_scripts', function () {
 *       Cashback_Assets::enqueue_safe_html();
 *   });
 *
 * Безопасно вызывать несколько раз (идемпотентно). Handle dompurify
 * регистрируется однократно, после чего enqueue становится no-op для wp_scripts.
 *
 * Замечание о fail-closed: assets/js/safe-html.js возвращает '' при отсутствии
 * window.DOMPurify (плюс console.error). Это гарантирует, что даже при сбое
 * загрузки purify.min.js сырой HTML НЕ попадёт в DOM.
 */
class Cashback_Assets {

    private const DOMPURIFY_HANDLE  = 'dompurify';
    private const DOMPURIFY_VERSION = '3.3.2';
    private const DOMPURIFY_SRC     = 'support/assets/js/purify.min.js';

    private const SAFE_HTML_HANDLE  = 'cashback-safe-html';
    private const SAFE_HTML_VERSION = '1.2.0';
    private const SAFE_HTML_SRC     = 'assets/js/safe-html.js';

    /**
     * Enqueue DOMPurify + cashback-safe-html обёртку. Идемпотентно.
     *
     * wp_enqueue_script дедупирует по handle на уровне WP_Scripts — повторный
     * вызов с теми же параметрами не создаёт второго экземпляра.
     */
    public static function enqueue_safe_html(): void {
        $plugin_root_file = dirname(__DIR__) . '/cashback-plugin.php';

        wp_enqueue_script(
            self::DOMPURIFY_HANDLE,
            plugins_url(self::DOMPURIFY_SRC, $plugin_root_file),
            array(),
            self::DOMPURIFY_VERSION,
            true
        );

        wp_enqueue_script(
            self::SAFE_HTML_HANDLE,
            plugins_url(self::SAFE_HTML_SRC, $plugin_root_file),
            array( self::DOMPURIFY_HANDLE ),
            self::SAFE_HTML_VERSION,
            true
        );
    }

    /**
     * Handle обёртки — можно указывать в deps других скриптов модуля,
     * чтобы safe-html был загружен до их выполнения.
     */
    public static function safe_html_handle(): string {
        return self::SAFE_HTML_HANDLE;
    }
}
