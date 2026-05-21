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

        // Boot-данные для JS. wp_add_inline_script(position='before') выводит
        // блок ДО основного template-editor.js, гарантированно устанавливая
        // window.CashbackLegalTemplateEditorBoot. enqueue_assets() уже
        // зарегистрировал handle к этому моменту.
        wp_add_inline_script(
            self::ASSET_HANDLE,
            'window.CashbackLegalTemplateEditorBoot = ' . wp_json_encode(array(
                'type'             => $type,
                'publishedHash'    => $published_hash,
                'publishedVersion' => (string) $published_version,
                'nextMajor'        => self::next_major((string) $published_version),
            )) . ';',
            'before'
        );

        $view = dirname(__DIR__) . '/admin/views/template-editor.php';
        if (!file_exists($view)) {
            wp_die(esc_html__('View не найден.', 'cashback-plugin'));
        }
        include $view;
    }

    /**
     * @param string $hook  Не используется — гард по $_GET['page'] (см. ниже),
     *                      сигнатура остаётся ради совместимости с add_action.
     */
    public static function enqueue_assets( string $hook = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        // ВАЖНО: WP формирует $hook через '<sanitize_title(parent menu_title)>_page_<slug>'.
        // У нас parent menu_title = "Кэшбэк" → sanitize_title даёт "keshbek" (транслит),
        // поэтому hardcode 'cashback_page_...' никогда не матчился — JS не подгружался,
        // отсюда и историческая нерабочесть кнопок редактора. Используем slug страницы
        // (предсказуемое значение из URL), а не hookname-лотерею.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation параметр; сами действия защищены AJAX-nonce.
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ($page !== self::PAGE_SLUG) {
            return;
        }
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $plugin_root = dirname(__DIR__, 2);
        $plugin_file = $plugin_root . '/cashback-plugin.php';
        $css_path    = $plugin_root . '/legal/admin/css/template-editor.css';
        $js_path     = $plugin_root . '/legal/admin/js/template-editor.js';
        $css_url     = plugins_url('legal/admin/css/template-editor.css', $plugin_file);
        $js_url      = plugins_url('legal/admin/js/template-editor.js', $plugin_file);

        // Версия = max(filemtime ассета, filemtime главного плагин-файла) — последний
        // обновляется при каждом релизе (git pull / GitHub Actions деплой), поэтому
        // браузерный кэш JS инвалидируется при любом bump'е версии плагина, даже если
        // сам template-editor.js не правился.
        $plugin_mtime = file_exists($plugin_file) ? (int) filemtime($plugin_file) : 0;
        $css_mtime    = file_exists($css_path) ? (int) filemtime($css_path) : 0;
        $js_mtime     = file_exists($js_path) ? (int) filemtime($js_path) : 0;
        $css_ver      = (string) max($css_mtime, $plugin_mtime, 1);
        $js_ver       = (string) max($js_mtime, $plugin_mtime, 1);

        wp_register_style(self::ASSET_HANDLE, $css_url, array(), $css_ver);
        // Зависимость на 'editor' — wp.editor.* объект (TinyMCE init/post API);
        // wp_editor() сам подключает 'tinymce' и 'quicktags', но editor.js даёт
        // wp.editor.getContent / wp.editor.initialize которые удобно использовать в JS.
        wp_register_script(self::ASSET_HANDLE, $js_url, array( 'jquery', 'editor' ), $js_ver, true);

        wp_localize_script(self::ASSET_HANDLE, 'CashbackLegalTemplateEditor', array(
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce(self::NONCE_ACTION),
            'editorId'  => 'cashback-legal-template-body',
            'actions'   => array(
                'load'    => self::AJAX_LOAD,
                'save'    => self::AJAX_SAVE_DRAFT,
                'discard' => self::AJAX_DISCARD_DRAFT,
                'preview' => self::AJAX_PREVIEW,
                'publish' => self::AJAX_PUBLISH,
            ),
        ));

        // Boot-данные (тип, hash, версии) добавляются в render_page() через
        // wp_add_inline_script(position='before') — там доступны Storage/Documents
        // и сам $type. Здесь, в hook admin_enqueue_scripts, эти классы ещё не
        // обязательно загружены, плюс нет смысла дёргать DB пока render не идёт.

        wp_enqueue_style(self::ASSET_HANDLE);
        wp_enqueue_script(self::ASSET_HANDLE);
    }

    /**
     * Вычислить целевую (next) major-семвер-версию: incr major + ".0.0".
     * Дублируется в view для backwards-compat (показ в кнопке/диалоге), но
     * authoritative-значение — здесь, в enqueue → boot.nextMajor.
     */
    public static function next_major( string $current ): string {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $current, $m)) {
            return ((int) $m[1] + 1) . '.0.0';
        }
        return '2.0.0';
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

        // F-P2-004: defense-in-depth — Validator::sanitize_html уже отрабатывает,
        // но wp_kses_post матчит published-path (Shortcodes::render_doc) и
        // отлавливает любую markup-щель которая могла бы пройти первый фильтр.
        $rendered = wp_kses_post($rendered);

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
