<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_Registration_Checkboxes
 *
 * Три юр. чекбокса на форме регистрации WooCommerce:
 *   1. pd_processing — обязательный (152-ФЗ ст. 9, отдельный документ с 01.09.2025)
 *   2. terms_offer   — обязательный (ГК ст. 437, акцепт оферты)
 *   3. marketing     — необязательный, OFF by default (38-ФЗ ст. 18)
 *
 * Hooks:
 *   - woocommerce_register_form         (priority 21, после fraud_consent на 20)
 *   - woocommerce_registration_errors   (priority 11, требует pd_processing+terms_offer)
 *   - woocommerce_created_customer      (priority 11, пишет 3 строки в consent_log
 *                                        с общим request_id из hidden поля)
 *
 * Idempotency: hidden uuid в форме → UNIQUE на request_id в журнале гарантирует
 * отсутствие дубликатов при повторной отправке POST.
 *
 * @since 1.3.0
 */
class Cashback_Legal_Registration_Checkboxes {

    public const FIELD_PD_PROCESSING = 'cashback_legal_consent_pd';
    public const FIELD_TERMS_OFFER   = 'cashback_legal_consent_offer';
    public const FIELD_MARKETING     = 'cashback_legal_consent_marketing';
    public const FIELD_REQUEST_ID    = 'cashback_legal_request_id';

    public static function init(): void {
        if (!function_exists('add_action') || !function_exists('add_filter')) {
            return;
        }
        add_action('woocommerce_register_form', array( __CLASS__, 'render_checkboxes' ), 21);
        add_filter('woocommerce_registration_errors', array( __CLASS__, 'validate_checkboxes' ), 11, 3);
        add_action('woocommerce_created_customer', array( __CLASS__, 'save_consents_on_registration' ), 11, 1);
    }

    /**
     * Рендер 3 чекбоксов в форме регистрации.
     */
    public static function render_checkboxes(): void {
        $request_id = self::generate_request_id();

        // hidden — uuid идемпотентности (UNIQUE на request_id в consent_log)
        printf(
            '<input type="hidden" name="%s" value="%s" />',
            esc_attr(self::FIELD_REQUEST_ID),
            esc_attr($request_id)
        );

        // 1. ПД (обязательный)
        self::render_single_checkbox(
            self::FIELD_PD_PROCESSING,
            self::compose_pd_label(),
            true,
            self::is_field_checked(self::FIELD_PD_PROCESSING)
        );

        // 2. Оферта (обязательный)
        self::render_single_checkbox(
            self::FIELD_TERMS_OFFER,
            self::compose_terms_label(),
            true,
            self::is_field_checked(self::FIELD_TERMS_OFFER)
        );

        // 3. Маркетинг (необязательный, OFF by default per 38-ФЗ)
        self::render_single_checkbox(
            self::FIELD_MARKETING,
            self::compose_marketing_label(),
            false,
            self::is_field_checked(self::FIELD_MARKETING)
        );
    }

    /**
     * Валидация. Проверяем pd_processing + terms_offer; marketing — опциональный.
     *
     * @param WP_Error $errors
     * @param string   $username  (signature WC, не используется здесь)
     * @param string   $email     (signature WC, не используется здесь)
     * @return WP_Error
     */
    public static function validate_checkboxes( $errors, $username = '', $email = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WC filter signature requires both args.
        unset($username, $email);

        if (!self::is_field_checked(self::FIELD_PD_PROCESSING)) {
            if ($errors instanceof WP_Error) {
                $errors->add(
                    'cashback_legal_pd_required',
                    esc_html__('Для регистрации необходимо согласие на обработку персональных данных (152-ФЗ ст. 9).', 'cashback-plugin')
                );
            }
        }

        if (!self::is_field_checked(self::FIELD_TERMS_OFFER)) {
            if ($errors instanceof WP_Error) {
                $errors->add(
                    'cashback_legal_offer_required',
                    esc_html__('Для регистрации необходимо принятие Пользовательского соглашения (публичной оферты).', 'cashback-plugin')
                );
            }
        }

        return $errors;
    }

    /**
     * Сохранение согласий после успешного создания пользователя.
     *
     * Пишем 3 строки в cashback_consent_log: pd_processing (granted),
     * terms_offer (granted) и marketing (granted, только если отмечен).
     * request_id одинаков для всех — это атомарный «акт регистрации».
     */
    public static function save_consents_on_registration( int $user_id ): void {
        if ($user_id <= 0 || !class_exists('Cashback_Legal_Consent_Manager')) {
            return;
        }

        $request_id_raw = self::read_field(self::FIELD_REQUEST_ID);
        $request_id     = Cashback_Legal_Consent_Manager::normalize_request_id($request_id_raw);
        if ($request_id === '') {
            // Fallback: если нет hidden uuid — генерируем server-side. Это покрывает
            // edge case если кто-то POST'ит в обход формы (тесты).
            $request_id = Cashback_Legal_Consent_Manager::generate_request_id();
        }

        $extra = array(
            'ip_address' => self::detect_client_ip(),
            'user_agent' => self::detect_user_agent(),
        );

        $write = static function ( string $type, string $rid_suffix ) use ( $user_id, $request_id, $extra ): void {
            // У каждого consent_type — свой уникальный request_id (одинаковый префикс
            // одного «акта регистрации» + суффикс типа). Иначе UNIQUE на request_id
            // не даст вставить три строки.
            $rid = substr($request_id, 0, 24) . $rid_suffix;
            Cashback_Legal_Consent_Manager::record_consent(
                $user_id,
                $type,
                'registration',
                $rid,
                $extra
            );
        };

        $write(Cashback_Legal_Documents::TYPE_PD_CONSENT, '00000001');
        $write(Cashback_Legal_Documents::TYPE_TERMS_OFFER, '00000002');

        if (self::is_field_checked(self::FIELD_MARKETING)) {
            $write(Cashback_Legal_Documents::TYPE_MARKETING, '00000003');
        }
    }

    // ────────────────────────────────────────────────────────────
    // private helpers
    // ────────────────────────────────────────────────────────────

    /**
     * Чекбокс отмечен в текущем POST?
     */
    private static function is_field_checked( string $field ): bool {
        return self::read_field($field) === '1';
    }

    /**
     * Чтение POST-поля. Nonce-проверку делает WC до этого фильтра.
     */
    private static function read_field( string $field ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce проверяет woocommerce-register-nonce до validate_checkboxes/save_consents_on_registration.
        if (!isset($_POST[ $field ])) {
            return '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- См. выше.
        return sanitize_text_field(wp_unslash((string) $_POST[ $field ]));
    }

    /**
     * UUID v7 hex (32 символа) для hidden поля.
     */
    private static function generate_request_id(): string {
        if (function_exists('cashback_generate_uuid7')) {
            return (string) cashback_generate_uuid7(false);
        }
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return md5(uniqid('cashback_legal_reg_', true));
        }
    }

    private static function detect_client_ip(): string {
        // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $remote = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
        if ($remote === '') {
            return '';
        }
        $filtered = filter_var($remote, FILTER_VALIDATE_IP);
        return is_string($filtered) ? $filtered : '';
    }

    private static function detect_user_agent(): string {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash((string) $_SERVER['HTTP_USER_AGENT']) : '';
        $ua = (string) $ua;
        if ($ua === '') {
            return '';
        }
        if (strlen($ua) > 1024) {
            $ua = substr($ua, 0, 1024);
        }
        return sanitize_text_field($ua);
    }

    /**
     * Render одной строки чекбокса с подписью и опциональной звёздочкой.
     */
    private static function render_single_checkbox( string $name, string $label_html, bool $is_required, bool $checked ): void {
        $required_attr = $is_required ? 'required' : '';
        $required_mark = $is_required ? ' <span class="required" style="color:#b32d2e;">*</span>' : '';

        $sanitized_label = wp_kses(
            $label_html,
            array(
                'a' => array(
                    'href'   => true,
                    'target' => true,
                    'rel'    => true,
                ),
            )
        );

        printf(
            '<p class="form-row form-row-wide cashback-legal-consent"><label for="%1$s" class="checkbox">'
            . '<input type="checkbox" name="%1$s" id="%1$s" value="1"%2$s %3$s /> %4$s%5$s'
            . '</label></p>',
            esc_attr($name),
            $checked ? ' checked' : '',
            esc_attr($required_attr),
            $sanitized_label, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses-санитизированный HTML.
            wp_kses($required_mark, array( 'span' => array( 'class' => true, 'style' => true ) )) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses sanitized.
        );
    }

    /**
     * Подписи чекбоксов с ссылками на публичные страницы документов.
     */
    private static function compose_pd_label(): string {
        $pd_consent_url = self::get_doc_url(Cashback_Legal_Documents::TYPE_PD_CONSENT);
        $pd_policy_url  = self::get_doc_url(Cashback_Legal_Documents::TYPE_PD_POLICY);

        $lead = esc_html__('Я даю', 'cashback-plugin');
        $consent_link = self::link_or_text(
            $pd_consent_url,
            __('согласие на обработку персональных данных', 'cashback-plugin')
        );
        $and = esc_html__('и подтверждаю ознакомление с', 'cashback-plugin');
        $policy_link = self::link_or_text(
            $pd_policy_url,
            __('Политикой обработки персональных данных', 'cashback-plugin')
        );

        return $lead . ' ' . $consent_link . ' ' . $and . ' ' . $policy_link . '.';
    }

    private static function compose_terms_label(): string {
        $offer_url = self::get_doc_url(Cashback_Legal_Documents::TYPE_TERMS_OFFER);
        $lead = esc_html__('Я принимаю условия', 'cashback-plugin');
        $link = self::link_or_text(
            $offer_url,
            __('Пользовательского соглашения (публичной оферты)', 'cashback-plugin')
        );
        return $lead . ' ' . $link . '.';
    }

    private static function compose_marketing_label(): string {
        $url  = self::get_doc_url(Cashback_Legal_Documents::TYPE_MARKETING);
        $lead = esc_html__('Я согласен получать информационные и рекламные сообщения по e-mail (по 38-ФЗ ст. 18). Можно отключить в любой момент.', 'cashback-plugin');
        if ($url === '') {
            return $lead;
        }
        return $lead . ' ' . self::link_or_text($url, __('Подробнее.', 'cashback-plugin'));
    }

    private static function link_or_text( string $url, string $text ): string {
        $text_escaped = esc_html($text);
        if ($url === '') {
            return $text_escaped;
        }
        return sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url($url),
            $text_escaped
        );
    }

    private static function get_doc_url( string $type ): string {
        if (!class_exists('Cashback_Legal_Pages_Installer')) {
            return '';
        }
        return Cashback_Legal_Pages_Installer::get_url_for_type($type);
    }
}
