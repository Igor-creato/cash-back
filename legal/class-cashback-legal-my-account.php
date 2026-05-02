<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_My_Account
 *
 * UX-cleanup 1.4.0: 2 опциональных consent-toggle'а в личном кабинете —
 * marketing (38-ФЗ ст. 18) и tech_data (149-ФЗ ст. 10). Раньше они стояли
 * на форме регистрации, что пугало конверсию. Теперь юзер регистрируется
 * с минимумом обязательных согласий (PD + оферта), а опциональные включает
 * по своему желанию в ЛК — это opt-in строже, чем прежнее «отмечено по
 * умолчанию OFF на форме».
 *
 * Compliance:
 *   - 152-ФЗ ст. 9: каждый toggle — отдельная цель, отдельная строка в журнале.
 *   - Append-only: revoke = новая строка с action='revoked', не апдейт.
 *   - Audit-log: consent_granted / consent_revoked пишутся симметрично.
 *
 * Hooks:
 *   - woocommerce_after_edit_account_form (priority 30, после soc-auth=10) →
 *     рендер секции «Согласия и уведомления» под формой edit-account.
 *   - wp_enqueue_scripts → подключает JS/CSS только на is_account_page().
 *   - wp_ajax_cashback_legal_toggle_consent → AJAX-обработчик (logged-in only).
 *
 * @since 1.4.0
 */
class Cashback_Legal_My_Account {

    public const AJAX_ACTION   = 'cashback_legal_toggle_consent';
    public const NONCE_ACTION  = 'cashback_legal_my_account_toggle';
    public const SOURCE        = 'my_account';

    /**
     * Whitelist типов, которые юзер может тогглить из ЛК. Обязательные согласия
     * (pd_consent, terms_offer) сюда не входят — их revoke ведёт к закрытию
     * аккаунта (отдельный workflow), а не к простому toggle.
     *
     * @return array<int, string>
     */
    private static function toggleable_types(): array {
        return array(
            Cashback_Legal_Documents::TYPE_MARKETING,
            Cashback_Legal_Documents::TYPE_TECH_DATA,
        );
    }

    public static function init(): void {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        if (!function_exists('add_action')) {
            return;
        }
        add_action('woocommerce_after_edit_account_form', array( __CLASS__, 'render_consent_section' ), 30);
        add_action('wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ));
        add_action('wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_ajax_toggle' ));
    }

    /**
     * Подключение CSS/JS — только на странице ЛК для залогиненного юзера.
     */
    public static function enqueue_assets(): void {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return;
        }
        if (!function_exists('is_account_page') || !is_account_page()) {
            return;
        }
        if (class_exists('Cashback_Legal_Bootstrap')) {
            Cashback_Legal_Bootstrap::register_common_assets();
        }
        if (!function_exists('wp_enqueue_style') || !function_exists('wp_enqueue_script')) {
            return;
        }

        wp_enqueue_style('cashback-consent-validate');

        $plugin_root_file = dirname(__DIR__) . '/cashback-plugin.php';
        $js_path          = dirname(__DIR__) . '/assets/js/cashback-legal-my-account-toggle.js';
        $js_url           = plugins_url('assets/js/cashback-legal-my-account-toggle.js', $plugin_root_file);
        $js_ver           = file_exists($js_path) ? (string) filemtime($js_path) : '1.4.0';

        wp_register_script(
            'cashback-legal-my-account-toggle',
            $js_url,
            array(),
            $js_ver,
            true
        );
        wp_localize_script(
            'cashback-legal-my-account-toggle',
            'cashbackLegalMyAccountConsent',
            array(
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'action'     => self::AJAX_ACTION,
                'nonce'      => wp_create_nonce(self::NONCE_ACTION),
                'i18n'       => array(
                    'saving'    => esc_html__('Сохраняем…', 'cashback-plugin'),
                    'saved'     => esc_html__('Сохранено.', 'cashback-plugin'),
                    'rateLimit' => esc_html__('Слишком много запросов. Попробуйте через минуту.', 'cashback-plugin'),
                    'error'     => esc_html__('Не удалось сохранить. Попробуйте ещё раз.', 'cashback-plugin'),
                ),
            )
        );
        wp_enqueue_script('cashback-legal-my-account-toggle');
    }

    /**
     * Рендер секции «Согласия и уведомления» под формой edit-account.
     */
    public static function render_consent_section(): void {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return;
        }
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($user_id <= 0) {
            return;
        }

        $heading = esc_html__('Согласия и уведомления', 'cashback-plugin');
        $hint    = esc_html__('Опциональные согласия. Можно включить и выключить в любое время.', 'cashback-plugin');
        $nonce   = wp_create_nonce(self::NONCE_ACTION);

        echo '<section class="cashback-legal-consents" data-nonce="' . esc_attr($nonce) . '">';
        echo '<h3>' . $heading . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $heading уже escaped через esc_html__.
        echo '<p class="cashback-legal-consents-hint">' . $hint . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $hint уже escaped.

        foreach (self::toggleable_types() as $type) {
            self::render_toggle($user_id, $type);
        }

        echo '<span class="cashback-legal-toggle-status" aria-live="polite" role="status"></span>';
        echo '</section>';
    }

    /**
     * AJAX: включение/отключение опционального согласия.
     */
    public static function handle_ajax_toggle(): void {
        // Auth check ДО nonce: для гостя нет смысла валидировать nonce (его и не будет).
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            wp_send_json_error(array( 'code' => 'auth' ), 401);
            return;
        }

        // Nonce.
        $nonce_check = check_ajax_referer(self::NONCE_ACTION, 'nonce', false);
        if ($nonce_check === false) {
            wp_send_json_error(array( 'code' => 'bad_nonce' ), 403);
            return;
        }

        $user_id = (int) get_current_user_id();
        if ($user_id <= 0) {
            wp_send_json_error(array( 'code' => 'auth' ), 401);
            return;
        }

        // Валидация типа.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен выше.
        $raw_type = isset($_POST['consent_type']) ? sanitize_key((string) wp_unslash($_POST['consent_type'])) : '';
        if (!in_array($raw_type, self::toggleable_types(), true)) {
            wp_send_json_error(array( 'code' => 'bad_type' ), 400);
            return;
        }

        // Rate-limit (tier 'write' зарегистрирован в Cashback_Rate_Limiter::ACTION_TIERS).
        if (class_exists('Cashback_Rate_Limiter')) {
            $ip = method_exists('Cashback_Encryption', 'get_client_ip')
                ? Cashback_Encryption::get_client_ip()
                : '0.0.0.0';
            $rl = Cashback_Rate_Limiter::check(self::AJAX_ACTION, $user_id, $ip);
            if (!$rl['allowed']) {
                wp_send_json_error(
                    array(
                        'code'        => 'rate_limit',
                        'retry_after' => (int) $rl['retry_after'],
                    ),
                    429
                );
                return;
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce проверен выше; значение строго сравнивается со строкой '1'.
        $enabled = isset($_POST['enabled']) && (string) wp_unslash($_POST['enabled']) === '1';

        $has_active = Cashback_Legal_Consent_Manager::has_active_consent($user_id, $raw_type);
        if ($enabled === $has_active) {
            wp_send_json_success(array(
                'consent_type' => $raw_type,
                'enabled'      => $enabled,
                'noop'         => true,
            ));
            return;
        }

        $request_id = Cashback_Legal_Consent_Manager::generate_request_id();
        $extra      = array(
            'extra_meta' => array( 'ui' => 'my_account_toggle' ),
        );

        $result = $enabled
            ? Cashback_Legal_Consent_Manager::record_consent($user_id, $raw_type, self::SOURCE, $request_id, $extra)
            : Cashback_Legal_Consent_Manager::withdraw_consent($user_id, $raw_type, self::SOURCE, $request_id, $extra);

        if ($result === false) {
            wp_send_json_error(array( 'code' => 'persist_failed' ), 500);
            return;
        }

        wp_send_json_success(array(
            'consent_type' => $raw_type,
            'enabled'      => $enabled,
            'request_id'   => $request_id,
        ));
    }

    // ────────────────────────────────────────────────────────────
    // private helpers
    // ────────────────────────────────────────────────────────────

    private static function render_toggle( int $user_id, string $type ): void {
        $checked = Cashback_Legal_Consent_Manager::has_active_consent($user_id, $type);
        $label   = self::compose_label($type);
        $field   = 'cashback-legal-toggle-' . sanitize_key($type);

        $kses_label = wp_kses(
            $label,
            array(
                'a' => array(
                    'href'   => true,
                    'target' => true,
                    'rel'    => true,
                ),
            )
        );

        printf(
            '<label class="cashback-legal-toggle" for="%1$s">'
            . '<input type="checkbox" id="%1$s" data-consent-type="%2$s" data-current="%3$s" value="1"%4$s />'
            . ' <span class="cashback-legal-toggle-text">%5$s</span>'
            . '</label>',
            esc_attr($field),
            esc_attr($type),
            $checked ? '1' : '0',
            $checked ? ' checked' : '',
            $kses_label // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses-санитизированный HTML с разрешённым тегом <a>.
        );
    }

    private static function compose_label( string $type ): string {
        switch ($type) {
            case Cashback_Legal_Documents::TYPE_MARKETING:
                $lead = esc_html__('Получать информационные и рекламные сообщения по e-mail (38-ФЗ ст. 18).', 'cashback-plugin');
                $url  = self::get_doc_url(Cashback_Legal_Documents::TYPE_MARKETING);
                return $url === ''
                    ? $lead
                    : $lead . ' ' . self::link($url, __('Подробнее.', 'cashback-plugin'));
            case Cashback_Legal_Documents::TYPE_TECH_DATA:
                $lead = esc_html__('Обработка технических данных (cookies, IP-адрес, идентификатор устройства) для работы сайта и аналитики (149-ФЗ ст. 10).', 'cashback-plugin');
                $url  = self::get_doc_url(Cashback_Legal_Documents::TYPE_TECH_DATA);
                return $url === ''
                    ? $lead
                    : $lead . ' ' . self::link($url, __('Подробнее.', 'cashback-plugin'));
            default:
                return esc_html($type);
        }
    }

    private static function get_doc_url( string $type ): string {
        if (!class_exists('Cashback_Legal_Pages_Installer')) {
            return '';
        }
        return Cashback_Legal_Pages_Installer::get_url_for_type($type);
    }

    private static function link( string $url, string $text ): string {
        return sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url($url),
            esc_html($text)
        );
    }
}
