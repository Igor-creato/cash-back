<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_Payout_Consent
 *
 * Управление чекбоксом согласия на обработку платёжных данных (161-ФЗ)
 * на форме вывода кэшбэка и форме настроек реквизитов.
 *
 * Логика:
 *   - Согласие фиксируется ОДИН раз — на первой выплате/первом сохранении
 *     реквизитов. При повторных операциях чекбокс не показывается.
 *   - Если в журнале нет active granted для типа payment_pd — чекбокс
 *     обязателен; AJAX handler возвращает ошибку при отсутствии отметки.
 *   - При изменении версии документа → запрос re-consent (через Phase 5
 *     reconsent-модал; payout-чекбокс при этом тоже скрыт, иначе
 *     получим противоречивые UX).
 *
 * @since 1.3.0
 */
class Cashback_Legal_Payout_Consent {

    public const FIELD_NAME       = 'cashback_legal_payment_pd_consent';
    public const FIELD_REQUEST_ID = 'cashback_legal_payment_pd_request_id';

    /**
     * Возвращает true, если у пользователя уже есть active granted для payment_pd.
     */
    public static function already_granted( int $user_id ): bool {
        if ($user_id <= 0 || !class_exists('Cashback_Legal_Consent_Manager')) {
            return false;
        }
        return Cashback_Legal_Consent_Manager::has_active_consent(
            $user_id,
            Cashback_Legal_Documents::TYPE_PAYMENT_PD
        );
    }

    /**
     * Echoes HTML чекбокса. Если у пользователя уже есть active consent —
     * рендерит ничего (чтобы не плодить шум на форме при последующих выплатах).
     *
     * Дополнительно выводит hidden request_id (uuid v7) для idempotency.
     */
    public static function render_checkbox( int $user_id ): void {
        if ($user_id <= 0) {
            return;
        }
        if (self::already_granted($user_id)) {
            return;
        }

        $url = self::get_doc_url();
        $rid = self::generate_request_id();

        $label_text = esc_html__('Я даю', 'cashback-plugin') . ' ';
        $label_text .= self::link_or_text(
            $url,
            __('согласие на обработку платёжных данных в целях исполнения распоряжения о выплате (161-ФЗ)', 'cashback-plugin')
        );
        $label_text .= '.';

        printf(
            '<p class="form-row cashback-legal-payment-consent">'
            . '<input type="hidden" name="%2$s" value="%3$s" />'
            . '<label for="%1$s" class="checkbox">'
            . '<input type="checkbox" name="%1$s" id="%1$s" value="1" required /> %4$s'
            . ' <span class="required" style="color:#b32d2e;">*</span>'
            . '</label></p>',
            esc_attr(self::FIELD_NAME),
            esc_attr(self::FIELD_REQUEST_ID),
            esc_attr($rid),
            wp_kses(
                $label_text,
                array(
                    'a' => array(
                        'href'   => true,
                        'target' => true,
                        'rel'    => true,
                    ),
                )
            )
        );
    }

    /**
     * Проверка чекбокса в AJAX handler'е. Если согласие уже было — возвращает
     * true без чтения POST. Иначе валидирует чекбокс, пишет запись в журнал,
     * возвращает true/false.
     *
     * @param int    $user_id
     * @param string $source 'payout' | 'profile'
     * @return true|array<string, string> true при успехе; при ошибке — array
     *                                    с ключом 'message' (передавать в
     *                                    wp_send_json_error).
     */
    public static function enforce_or_error( int $user_id, string $source = 'payout' ) {
        if ($user_id <= 0) {
            return array( 'message' => __('Авторизация требуется.', 'cashback-plugin') );
        }
        if (self::already_granted($user_id)) {
            return true;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce проверяется caller'ом (process_cashback_withdrawal/save_payout_settings) до вызова enforce_or_error.
        $checkbox_value = isset($_POST[ self::FIELD_NAME ])
            ? sanitize_text_field(wp_unslash((string) $_POST[ self::FIELD_NAME ]))
            : '';
        if ($checkbox_value !== '1') {
            return array(
                'message' => __('Подтвердите согласие на обработку платёжных данных (161-ФЗ).', 'cashback-plugin'),
            );
        }

        $rid_raw = isset($_POST[ self::FIELD_REQUEST_ID ])
            ? sanitize_text_field(wp_unslash((string) $_POST[ self::FIELD_REQUEST_ID ]))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $rid = Cashback_Legal_Consent_Manager::normalize_request_id($rid_raw);
        if ($rid === '') {
            $rid = Cashback_Legal_Consent_Manager::generate_request_id();
        }

        Cashback_Legal_Consent_Manager::record_consent(
            $user_id,
            Cashback_Legal_Documents::TYPE_PAYMENT_PD,
            $source,
            $rid,
            array(
                'ip_address' => self::detect_client_ip(),
                'user_agent' => self::detect_user_agent(),
            )
        );

        return true;
    }

    private static function get_doc_url(): string {
        if (!class_exists('Cashback_Legal_Pages_Installer')) {
            return '';
        }
        return Cashback_Legal_Pages_Installer::get_url_for_type(
            Cashback_Legal_Documents::TYPE_PAYMENT_PD
        );
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

    private static function generate_request_id(): string {
        if (function_exists('cashback_generate_uuid7')) {
            return (string) cashback_generate_uuid7(false);
        }
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return md5(uniqid('cashback_legal_payout_', true));
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
        if (!is_string($ua) || $ua === '') {
            return '';
        }
        if (strlen($ua) > 1024) {
            $ua = substr($ua, 0, 1024);
        }
        return sanitize_text_field($ua);
    }
}
