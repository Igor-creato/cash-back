<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_Cookies_Banner
 *
 * Минимальный cookie-баннер по 152-ФЗ + 149-ФЗ (с поправками 30.05.2025):
 *   - Однократный показ при первом визите (фиксация в localStorage 12 мес).
 *   - Три кнопки равной визуальной массы: «Принять» / «Отклонить» / «Подробнее».
 *   - Без granular-toggles (по решению пользователя — Phase 1 архитектура).
 *   - Лог принятия записывается в cashback_consent_log с user_id=NULL для
 *     гостей и user_id=current для авторизованных. Доказательная база.
 *   - Re-показ через 12 мес или при смене major-версии cookies_policy.
 *
 * Не рендерится в admin, на login-форме, на странице оплаты WC. Также не
 * показывается, если опция cashback_legal_cookies_banner_enabled = false.
 *
 * @since 1.3.0
 */
class Cashback_Legal_Cookies_Banner {

    public const OPTION_ENABLED      = 'cashback_legal_cookies_banner_enabled';
    public const AJAX_ACTION_RECORD  = 'cashback_legal_cookies_consent';
    public const SCRIPT_HANDLE       = 'cashback-legal-cookies-banner';
    public const STYLE_HANDLE        = 'cashback-legal-cookies-banner';
    public const LOCAL_STORAGE_KEY   = 'cashback_legal_cookies_consent_v1';
    public const RENEW_AFTER_DAYS    = 365;

    public static function init(): void {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ));
        add_action('wp_footer', array( __CLASS__, 'render_container' ), 95);
        add_action('wp_ajax_' . self::AJAX_ACTION_RECORD, array( __CLASS__, 'handle_ajax' ));
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION_RECORD, array( __CLASS__, 'handle_ajax' ));
    }

    /**
     * Enqueue JS+CSS, локализация конфига для frontend'а.
     */
    public static function enqueue_assets(): void {
        if (!self::should_show()) {
            return;
        }

        $base_url = self::base_url();
        $version  = self::active_version();

        wp_enqueue_style(
            self::STYLE_HANDLE,
            $base_url . 'assets/css/cookies-banner.css',
            array(),
            $version
        );

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            $base_url . 'assets/js/cookies-banner.js',
            array(),
            $version,
            true
        );

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'cashbackLegalCookiesBanner',
            array(
                'ajaxUrl'         => admin_url('admin-ajax.php'),
                'action'          => self::AJAX_ACTION_RECORD,
                'nonce'           => wp_create_nonce(self::AJAX_ACTION_RECORD),
                'storageKey'      => self::LOCAL_STORAGE_KEY,
                'documentVersion' => $version,
                'renewAfterDays'  => self::RENEW_AFTER_DAYS,
                'policyUrl'       => self::cookies_policy_url(),
                'i18n'            => array(
                    'message'  => __('Этот сайт использует cookies, в том числе для аналитики и атрибуции кэшбэка. Вы можете принять или отклонить необязательные cookies. Подробности — в Соглашении об использовании cookies.', 'cashback-plugin'),
                    'accept'   => __('Принять', 'cashback-plugin'),
                    'reject'   => __('Отклонить', 'cashback-plugin'),
                    'details'  => __('Подробнее', 'cashback-plugin'),
                ),
            )
        );
    }

    /**
     * Контейнер, в который JS вмонтирует баннер. Скрыт по умолчанию через CSS.
     */
    public static function render_container(): void {
        if (!self::should_show()) {
            return;
        }
        echo '<div id="cashback-legal-cookies-banner" class="cashback-legal-cookies-banner is-hidden" role="dialog" aria-live="polite" aria-label="'
            . esc_attr__('Согласие на использование cookies', 'cashback-plugin')
            . '"></div>';
    }

    /**
     * AJAX endpoint: пишет строку в cashback_consent_log. Принимает choice
     * (granted|rejected) и client request_id (UUID v7). Допустимы оба варианта
     * — авторизованный пользователь и гость.
     */
    public static function handle_ajax(): void {
        check_ajax_referer(self::AJAX_ACTION_RECORD, 'nonce');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен check_ajax_referer выше.
        $choice = isset($_POST['choice']) ? sanitize_text_field(wp_unslash((string) $_POST['choice'])) : '';
        if (!in_array($choice, array( 'granted', 'rejected' ), true)) {
            wp_send_json_error(array( 'code' => 'invalid_choice' ), 400);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- См. выше.
        $rid_raw = isset($_POST['request_id']) ? sanitize_text_field(wp_unslash((string) $_POST['request_id'])) : '';
        $rid     = class_exists('Cashback_Legal_Consent_Manager')
            ? Cashback_Legal_Consent_Manager::normalize_request_id($rid_raw)
            : '';
        if ($rid === '') {
            $rid = class_exists('Cashback_Legal_Consent_Manager')
                ? Cashback_Legal_Consent_Manager::generate_request_id()
                : bin2hex(random_bytes(16));
        }

        $user_id    = get_current_user_id();
        $user_id    = $user_id > 0 ? $user_id : null;
        $action_str = $choice === 'granted' ? 'granted' : 'revoked';

        $version = self::active_version();
        $hash    = class_exists('Cashback_Legal_Documents')
            ? Cashback_Legal_Documents::compute_hash(Cashback_Legal_Documents::TYPE_COOKIES_POLICY)
            : '';

        if (!class_exists('Cashback_Legal_DB')) {
            wp_send_json_error(array( 'code' => 'legal_unavailable' ), 503);
        }

        $row = array(
            'user_id'          => $user_id,
            'consent_type'     => 'cookies',
            'action'           => $action_str,
            'document_version' => $version,
            'document_hash'    => $hash,
            'document_url'     => self::cookies_policy_url(),
            'ip_address'       => self::detect_client_ip(),
            'user_agent'       => self::detect_user_agent(),
            'request_id'       => $rid,
            'source'           => 'cookies_banner',
            'granted_at'       => gmdate('Y-m-d H:i:s'),
            'revoked_at'       => $choice === 'rejected' ? gmdate('Y-m-d H:i:s') : null,
            'extra_meta'       => wp_json_encode(array( 'choice' => $choice )),
        );

        $inserted = Cashback_Legal_DB::insert_log_row($row);
        if ($inserted === false) {
            // Idempotent retry с тем же request_id — UNIQUE отбрасывает дубликат,
            // но это не ошибка для клиента: его выбор уже зафиксирован.
            wp_send_json_success(array( 'recorded' => false, 'reason' => 'duplicate' ));
        }

        wp_send_json_success(array( 'recorded' => true, 'id' => $inserted ));
    }

    // ────────────────────────────────────────────────────────────
    // private helpers
    // ────────────────────────────────────────────────────────────

    /**
     * Когда нужно показывать баннер. Не рендерим в admin, на login, на checkout-оплате
     * и при отключённой опции.
     */
    private static function should_show(): bool {
        if (function_exists('is_admin') && is_admin()) {
            return false;
        }
        $enabled = (bool) get_option(self::OPTION_ENABLED, true);
        if (!$enabled) {
            return false;
        }
        // На странице оплаты WC баннер потенциально может перекрыть платёжный
        // виджет; не критично, но безопаснее скрыть.
        if (function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
            return false;
        }
        return true;
    }

    private static function active_version(): string {
        if (!class_exists('Cashback_Legal_Documents')) {
            return '1.0.0';
        }
        return Cashback_Legal_Documents::get_active_version(
            Cashback_Legal_Documents::TYPE_COOKIES_POLICY
        );
    }

    private static function cookies_policy_url(): string {
        if (!class_exists('Cashback_Legal_Pages_Installer') || !class_exists('Cashback_Legal_Documents')) {
            return '';
        }
        return Cashback_Legal_Pages_Installer::get_url_for_type(
            Cashback_Legal_Documents::TYPE_COOKIES_POLICY
        );
    }

    private static function base_url(): string {
        // legal/class-cashback-legal-cookies-banner.php → корень плагина = ../../
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
