<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_Reconsent_Modal
 *
 * Frontend-модал на template_redirect: для авторизованных пользователей,
 * у которых в журнале есть granted ранее текущей major-версии документа,
 * рендерится блокирующий модал с обновлёнными чекбоксами. До акцепта —
 * JS блокирует переходы по любым ссылкам кроме /my-account/ и wp_logout.
 *
 * AJAX endpoint cashback_legal_reconsent_submit пишет новые granted-записи,
 * сбрасывает transient pending_reconsent.
 *
 * @since 1.3.0
 */
class Cashback_Legal_Reconsent_Modal {

    public const AJAX_ACTION   = 'cashback_legal_reconsent_submit';
    public const SCRIPT_HANDLE = 'cashback-legal-reconsent-modal';
    public const STYLE_HANDLE  = 'cashback-legal-reconsent-modal';

    /**
     * Глобальный флаг: нужно отрендерить модал на этой странице.
     */
    public static bool $needs_render = false;

    /**
     * Pending-types (cached в php-runtime после template_redirect).
     *
     * @var array<int, string>
     */
    public static array $pending_types = array();

    public static function init(): void {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('template_redirect', array( __CLASS__, 'maybe_set_pending' ), 5);
        add_action('wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20);
        add_action('wp_footer', array( __CLASS__, 'render_modal' ), 99);
        add_action('wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_ajax' ));
    }

    /**
     * Проверяет на template_redirect: нужно ли показывать модал текущему юзеру.
     */
    public static function maybe_set_pending(): void {
        if (!is_user_logged_in() || !class_exists('Cashback_Legal_Consent_Manager')) {
            return;
        }
        if (self::is_safe_page()) {
            return;
        }
        $user_id = get_current_user_id();
        $pending = Cashback_Legal_Consent_Manager::get_pending_reconsent_types($user_id);
        if (empty($pending)) {
            return;
        }
        self::$needs_render  = true;
        self::$pending_types = $pending;
    }

    public static function enqueue_assets(): void {
        if (!self::$needs_render) {
            return;
        }
        $base_url = self::base_url();
        $version  = self::cumulative_versions_signature();

        wp_enqueue_style(
            self::STYLE_HANDLE,
            $base_url . 'assets/css/reconsent-modal.css',
            array(),
            $version
        );

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            $base_url . 'assets/js/reconsent-modal.js',
            array(),
            $version,
            true
        );

        $types_payload = array();
        foreach (self::$pending_types as $type) {
            $meta  = Cashback_Legal_Documents::get_meta($type);
            $types_payload[] = array(
                'type'    => $type,
                'title'   => isset($meta['title']) ? (string) $meta['title'] : $type,
                'url'     => Cashback_Legal_Pages_Installer::get_url_for_type($type),
                'version' => Cashback_Legal_Documents::get_active_version($type),
            );
        }

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'cashbackLegalReconsent',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'action'  => self::AJAX_ACTION,
                'nonce'   => wp_create_nonce(self::AJAX_ACTION),
                'types'   => $types_payload,
                'allowedPaths' => array( '/my-account/', '/wp-login.php', '/wp-logout' ),
                'i18n'    => array(
                    'title'    => __('Условия обновлены', 'cashback-plugin'),
                    'message'  => __('Юридические документы изменились. Чтобы продолжить пользоваться сервисом, подтвердите согласие с обновлённой редакцией.', 'cashback-plugin'),
                    'logout'   => __('Выйти', 'cashback-plugin'),
                    'submit'   => __('Принять обновлённые условия', 'cashback-plugin'),
                    'reading'  => __('Открыть документ', 'cashback-plugin'),
                ),
            )
        );
    }

    public static function render_modal(): void {
        if (!self::$needs_render) {
            return;
        }
        echo '<div id="cashback-legal-reconsent-modal" class="cashback-legal-reconsent-modal" role="dialog" aria-modal="true" aria-live="polite"></div>';
    }

    /**
     * AJAX endpoint: пишет новые granted-записи для каждого type из POST.
     * Все типы должны быть среди self::pending_types для текущего user_id —
     * мы не доверяем raw input, повторно вычисляем pending на сервере.
     */
    public static function handle_ajax(): void {
        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array( 'code' => 'not_logged_in' ), 401);
        }
        $user_id = get_current_user_id();

        $pending = Cashback_Legal_Consent_Manager::get_pending_reconsent_types($user_id);
        if (empty($pending)) {
            // Ничего не нужно — клиент мог запоздалить.
            wp_send_json_success(array( 'recorded' => 0 ));
        }

        $extra = array(
            'ip_address' => self::detect_client_ip(),
            'user_agent' => self::detect_user_agent(),
        );

        $base_request_id = Cashback_Legal_Consent_Manager::generate_request_id();
        $recorded        = 0;
        foreach ($pending as $idx => $type) {
            $rid = substr($base_request_id, 0, 24) . sprintf('%08d', $idx + 1);
            $id  = Cashback_Legal_Consent_Manager::record_consent(
                $user_id,
                $type,
                'reconsent_modal',
                $rid,
                $extra
            );
            if ($id !== false) {
                ++$recorded;
            }
        }

        Cashback_Legal_Consent_Manager::clear_reconsent_cache($user_id);

        wp_send_json_success(array( 'recorded' => $recorded ));
    }

    // ────────────────────────────────────────────────────────────
    // private helpers
    // ────────────────────────────────────────────────────────────

    /**
     * Страницы, на которых модал не показываем (юзер должен иметь
     * возможность дойти до настроек/логаута).
     */
    private static function is_safe_page(): bool {
        if (function_exists('is_admin') && is_admin()) {
            return true;
        }
        // /my-account/ — для всех endpoints WC.
        if (function_exists('is_account_page') && is_account_page()) {
            return true;
        }
        // wp-login.php / wp-logout actions.
        // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REQUEST_URI__,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '';
        if (!is_string($uri)) {
            return false;
        }
        if (strpos($uri, '/wp-login.php') !== false) {
            return true;
        }
        if (strpos($uri, 'action=logout') !== false) {
            return true;
        }
        return false;
    }

    /**
     * Сводная сигнатура версий всех pending-типов — для cache-busting JS/CSS.
     */
    private static function cumulative_versions_signature(): string {
        $parts = array();
        foreach (self::$pending_types as $type) {
            $parts[] = $type . ':' . Cashback_Legal_Documents::get_active_version($type);
        }
        return substr(md5(implode('|', $parts)), 0, 12);
    }

    private static function base_url(): string {
        return plugins_url('/', dirname(__DIR__) . '/cashback-plugin.php');
    }

    private static function detect_client_ip(): ?string {
        // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $remote = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
        if ($remote === '') {
            return null;
        }
        $filtered = filter_var($remote, FILTER_VALIDATE_IP);
        return is_string($filtered) ? $filtered : null;
    }

    private static function detect_user_agent(): ?string {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash((string) $_SERVER['HTTP_USER_AGENT']) : '';
        if (!is_string($ua) || $ua === '') {
            return null;
        }
        if (strlen($ua) > 1024) {
            $ua = substr($ua, 0, 1024);
        }
        return sanitize_text_field($ua);
    }
}
