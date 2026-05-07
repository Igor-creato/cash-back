<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_Template_Editor
 *
 * Скрытая admin-подстраница cashback-legal-edit?type=<consent_type> + AJAX
 * endpoint'ы для CodeMirror-редактора текстов юр.документов:
 *
 *   - load: GET draft + published.
 *   - save_draft: сохранить рабочую копию (без bump version).
 *   - discard_draft: отменить рабочую копию, вернуться к published.
 *   - preview: рендер с подставленными реквизитами оператора.
 *   - publish: атомарно bump major + draft → published + старый published →
 *     superseded + scheduled re-consent. Optimistic concurrency через
 *     expected_published_hash + idempotency_key (UUID) от клиента.
 *
 * @since 1.7.0 (UI редактирования юр.документов)
 */
class Cashback_Legal_Template_Editor {

    public const PAGE_SLUG     = 'cashback-legal-edit';
    public const CAPABILITY    = 'manage_options';
    public const NONCE_ACTION  = 'cashback_legal_template_editor';
    public const ASSET_HANDLE  = 'cashback-legal-template-editor';

    public const AJAX_LOAD          = 'cashback_legal_tpl_load';
    public const AJAX_SAVE_DRAFT    = 'cashback_legal_tpl_save_draft';
    public const AJAX_DISCARD_DRAFT = 'cashback_legal_tpl_discard_draft';
    public const AJAX_PREVIEW       = 'cashback_legal_tpl_preview';
    public const AJAX_PUBLISH       = 'cashback_legal_tpl_publish';

    public static function init(): void {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('admin_menu', array( __CLASS__, 'register_hidden_submenu' ), 31);
        add_action('admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ));

        add_action('wp_ajax_' . self::AJAX_LOAD, array( __CLASS__, 'ajax_load' ));
        add_action('wp_ajax_' . self::AJAX_SAVE_DRAFT, array( __CLASS__, 'ajax_save_draft' ));
        add_action('wp_ajax_' . self::AJAX_DISCARD_DRAFT, array( __CLASS__, 'ajax_discard_draft' ));
        add_action('wp_ajax_' . self::AJAX_PREVIEW, array( __CLASS__, 'ajax_preview' ));
        add_action('wp_ajax_' . self::AJAX_PUBLISH, array( __CLASS__, 'ajax_publish' ));
    }

    /**
     * Подстраница без пункта в меню — открывается только по прямой ссылке
     * со страницы «Документы и версии».
     */
    public static function register_hidden_submenu(): void {
        // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPressVIPMinimum.Functions.RestrictedFunctions.add_submenu_page_add_submenu_page -- Hidden submenu (parent=null) — стандартный WP-pattern.
        add_submenu_page(
            'cashback-overview',
            __('Редактирование текста юр.документа', 'cashback-plugin'),
            '', // пустой menu_title — невидим в меню
            self::CAPABILITY,
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'), '', array( 'response' => 403 ));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation параметр; сами действия защищены AJAX-nonce.
        $type = isset($_GET['type']) ? sanitize_key((string) wp_unslash($_GET['type'])) : '';

        if (!in_array($type, Cashback_Legal_Documents::all_types(), true)) {
            wp_safe_redirect(admin_url('admin.php?page=' . Cashback_Legal_Admin::PAGE_SLUG_VERSIONS . '&cashback_legal_flash=invalid_type'));
            exit;
        }

        Cashback_Legal_Template_Storage::seed_if_missing($type);

        $meta              = Cashback_Legal_Documents::get_meta($type);
        $title             = isset($meta['title']) ? (string) $meta['title'] : $type;
        $published_body    = (string) (Cashback_Legal_Template_Storage::get_active_body($type) ?? '');
        $published_hash    = $published_body !== '' ? hash('sha256', $published_body) : '';
        $published_version = Cashback_Legal_Documents::get_active_version($type);
        $draft             = Cashback_Legal_Template_Storage::get_draft($type);
        $required_phs      = Cashback_Legal_Template_Validator::required_placeholders_for_type($type);

        $view = dirname(__DIR__) . '/admin/views/template-editor.php';
        if (!file_exists($view)) {
            wp_die(esc_html__('View не найден.', 'cashback-plugin'));
        }
        include $view;
    }

    public static function enqueue_assets( string $hook = '' ): void {
        if ($hook !== 'cashback_page_' . self::PAGE_SLUG) {
            return;
        }
        if (!function_exists('wp_enqueue_code_editor')) {
            return;
        }

        $plugin_root = dirname(__DIR__, 2);
        $plugin_file = $plugin_root . '/cashback-plugin.php';
        $css_path    = $plugin_root . '/legal/admin/css/template-editor.css';
        $js_path     = $plugin_root . '/legal/admin/js/template-editor.js';
        $css_url     = plugins_url('legal/admin/css/template-editor.css', $plugin_file);
        $js_url      = plugins_url('legal/admin/js/template-editor.js', $plugin_file);

        $css_ver = file_exists($css_path) ? (string) filemtime($css_path) : '1.7.0';
        $js_ver  = file_exists($js_path) ? (string) filemtime($js_path) : '1.7.0';

        $cm_settings = wp_enqueue_code_editor(array( 'type' => 'text/html' ));

        wp_register_style(self::ASSET_HANDLE, $css_url, array(), $css_ver);
        wp_register_script(self::ASSET_HANDLE, $js_url, array( 'wp-codemirror' ), $js_ver, true);

        wp_localize_script(self::ASSET_HANDLE, 'CashbackLegalTemplateEditor', array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce(self::NONCE_ACTION),
            'cmSettings' => $cm_settings ? $cm_settings : null,
            'actions'    => array(
                'load'     => self::AJAX_LOAD,
                'save'     => self::AJAX_SAVE_DRAFT,
                'discard'  => self::AJAX_DISCARD_DRAFT,
                'preview'  => self::AJAX_PREVIEW,
                'publish'  => self::AJAX_PUBLISH,
            ),
            'i18n'       => array(
                /* translators: %s — UTC timestamp последнего сохранения. */
                'savedAt'         => __('Сохранено: %s', 'cashback-plugin'),
                'unsaved'         => __('Несохранённые изменения', 'cashback-plugin'),
                /* translators: %s — текст ошибки от сервера. */
                'savingError'     => __('Ошибка сохранения: %s', 'cashback-plugin'),
                /* translators: %s — целевая semver-версия (например, 2.0.0). */
                'publishConfirm'  => __('Введите PUBLISH %s для подтверждения публикации:', 'cashback-plugin'),
                'publishMismatch' => __('Текст не совпадает с подтверждением.', 'cashback-plugin'),
                'beforeUnload'    => __('Есть несохранённые изменения. Покинуть страницу?', 'cashback-plugin'),
            ),
        ));

        wp_enqueue_style(self::ASSET_HANDLE);
        wp_enqueue_script(self::ASSET_HANDLE);
    }

    // ────────────────────────────────────────────────────────────
    // AJAX handlers
    // ────────────────────────────────────────────────────────────

    public static function ajax_load(): void {
        self::ensure_capability();
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $type = self::request_type();
        if ($type === '') {
            wp_send_json_error(array( 'code' => 'invalid_type', 'message' => 'Неизвестный тип.' ), 400);
        }

        Cashback_Legal_Template_Storage::seed_if_missing($type);

        $published = (string) (Cashback_Legal_Template_Storage::get_active_body($type) ?? '');
        $hash      = $published !== '' ? hash('sha256', $published) : '';
        $draft     = Cashback_Legal_Template_Storage::get_draft($type);

        wp_send_json_success(array(
            'type'             => $type,
            'published_body'   => $published,
            'published_hash'   => $hash,
            'published_version' => Cashback_Legal_Documents::get_active_version($type),
            'draft' => $draft !== null ? array(
                'body'       => (string) ($draft['body_html'] ?? ''),
                'hash'       => (string) ($draft['body_hash'] ?? ''),
                'created_at' => (string) ($draft['created_at'] ?? ''),
                'created_by' => (int) ($draft['created_by'] ?? 0),
            ) : null,
        ));
    }

    public static function ajax_save_draft(): void {
        self::ensure_capability();
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $type = self::request_type();
        if ($type === '') {
            wp_send_json_error(array( 'code' => 'invalid_type', 'message' => 'Неизвестный тип.' ), 400);
        }

        $body = isset($_POST['body']) && is_string($_POST['body'])
            ? (string) wp_unslash($_POST['body']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw HTML body, sanitize через Validator::sanitize_html ниже.
            : '';

        $body = Cashback_Legal_Template_Validator::sanitize_html($body);

        $check = Cashback_Legal_Template_Validator::validate_for_draft($type, $body);
        if ($check instanceof WP_Error) {
            wp_send_json_error(array(
                'code'    => $check->get_error_code(),
                'message' => $check->get_error_message(),
            ), 400);
        }

        $result = Cashback_Legal_Template_Storage::save_draft($type, $body, get_current_user_id());
        if ($result instanceof WP_Error) {
            wp_send_json_error(array(
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ), 400);
        }

        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'legal_template_draft_saved',
                get_current_user_id(),
                'legal_template_versions',
                (int) ($result['id'] ?? 0),
                array(
                    'consent_type' => $type,
                    'body_hash'    => (string) ($result['hash'] ?? ''),
                    'body_size'    => strlen($body),
                )
            );
        }

        wp_send_json_success(array(
            'hash'       => (string) ($result['hash'] ?? ''),
            'saved_at'   => (string) ($result['created_at'] ?? gmdate('Y-m-d H:i:s')),
        ));
    }

    public static function ajax_discard_draft(): void {
        self::ensure_capability();
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $type = self::request_type();
        if ($type === '') {
            wp_send_json_error(array( 'code' => 'invalid_type', 'message' => 'Неизвестный тип.' ), 400);
        }

        $ok = Cashback_Legal_Template_Storage::discard_draft($type);

        if ($ok && class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'legal_template_draft_discarded',
                get_current_user_id(),
                'legal_template_versions',
                null,
                array( 'consent_type' => $type )
            );
        }

        wp_send_json_success(array( 'discarded' => $ok ));
    }

    public static function ajax_preview(): void {
        self::ensure_capability();
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $type = self::request_type();
        if ($type === '') {
            wp_send_json_error(array( 'code' => 'invalid_type', 'message' => 'Неизвестный тип.' ), 400);
        }

        $body = isset($_POST['body']) && is_string($_POST['body'])
            ? (string) wp_unslash($_POST['body']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw HTML body, sanitize через Validator::sanitize_html ниже.
            : '';

        $body = Cashback_Legal_Template_Validator::sanitize_html($body);

        $rendered = class_exists('Cashback_Legal_Operator')
            ? Cashback_Legal_Operator::render_placeholders($body)
            : $body;

        wp_send_json_success(array( 'rendered_html' => $rendered ));
    }

    public static function ajax_publish(): void {
        self::ensure_capability();
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $type = self::request_type();
        if ($type === '') {
            wp_send_json_error(array( 'code' => 'invalid_type', 'message' => 'Неизвестный тип.' ), 400);
        }

        $idempotency_key = isset($_POST['idempotency_key'])
            ? sanitize_text_field((string) wp_unslash($_POST['idempotency_key']))
            : '';
        $expected_hash = isset($_POST['expected_published_hash'])
            ? sanitize_text_field((string) wp_unslash($_POST['expected_published_hash']))
            : '';

        $result = Cashback_Legal_Template_Storage::publish_draft(
            $type,
            get_current_user_id(),
            $idempotency_key,
            $expected_hash
        );

        if ($result instanceof WP_Error) {
            wp_send_json_error(array(
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ), 400);
        }

        wp_send_json_success($result);
    }

    // ────────────────────────────────────────────────────────────
    // helpers
    // ────────────────────────────────────────────────────────────

    private static function ensure_capability(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array( 'code' => 'forbidden', 'message' => 'Недостаточно прав.' ), 403);
        }
    }

    private static function request_type(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверяется в caller'е через check_ajax_referer.
        $raw = isset($_POST['type']) ? sanitize_key((string) wp_unslash($_POST['type'])) : '';
        if (!class_exists('Cashback_Legal_Documents')) {
            return '';
        }
        return in_array($raw, Cashback_Legal_Documents::all_types(), true) ? $raw : '';
    }
}
