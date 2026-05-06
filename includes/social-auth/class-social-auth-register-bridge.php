<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Social_Auth_Register_Bridge
 *
 * Связующее звено между OAuth-callback'ом (Branch D в Account_Manager) и
 * стандартной WC-регистрацией для post-OAuth conditional consent flow
 * (Auth0/GDPR pattern).
 *
 * Поток:
 *  1. Юзер кликает «Войти через Яндекс ID» → OAuth roundtrip.
 *  2. Если email отсутствует в WP-БД (новый юзер) — Account_Manager стэшит
 *     OAuth-данные через save_pending(KIND_REGISTER_VIA_SOCIAL) и редиректит
 *     на /my-account/?cashback_social_register=<token>.
 *  3. Этот класс на GET register-формы:
 *     - peek_pending токен (read-only, не consume).
 *     - JS предзаполняет email + ставит readonly.
 *     - Hidden inputs `cashback_social_register_token` + `cashback_social_register_email`.
 *  4. Юзер ставит 3 обязательных чекбокса согласия и нажимает РЕГИСТРАЦИЯ.
 *  5. WC сохраняет юзера; стандартные хуки (Cashback_Fraud_Consent priority 10
 *     + Cashback_Legal_Registration_Checkboxes priority 11) пишут consent-меты.
 *  6. Этот класс в priority 15 на woocommerce_created_customer:
 *     - consume_pending токен (атомарно).
 *     - Account_Manager::link_provider_to_user() создаёт связку.
 *     - На любой ошибке: юзера НЕ удаляем (он уже с consent), просто логируем.
 *  7. woocommerce_registration_redirect возвращает payload['redirect_after'],
 *     WC делает wp_redirect.
 *
 * Защита от подмены email через DevTools:
 *  - На filter `woocommerce_new_customer_data` priority 5 принудительно
 *    подставляем `payload['email']` вместо `$_POST['email']`. Юзер физически
 *    не может зарегистрироваться с email отличным от Yandex-OAuth-email.
 */
class Cashback_Social_Auth_Register_Bridge {

    public static function init(): void {
        if (!function_exists('add_action')) {
            return;
        }

        // GET: предзаполнение email + hidden token на register-форме.
        add_action('woocommerce_register_form_start', array( __CLASS__, 'render_prefill' ), 5);

        // POST: принудительное переопределение email до wp_insert_user.
        add_filter('woocommerce_new_customer_data', array( __CLASS__, 'force_payload_email' ), 5, 1);

        // POST: после создания юзера — линковка соц-аккаунта.
        // Priority 15 — после Cashback_Fraud_Consent (10) + Cashback_Legal_Registration_Checkboxes (11),
        // чтобы consent-записи уже были в БД.
        add_action('woocommerce_created_customer', array( __CLASS__, 'on_created_customer' ), 15, 1);

        // POST: редирект после регистрации на payload['redirect_after'].
        add_filter('woocommerce_registration_redirect', array( __CLASS__, 'override_register_redirect' ), 10, 1);
    }

    // ------------------------------------------------------------------
    // GET phase
    // ------------------------------------------------------------------

    /**
     * Hook woocommerce_register_form_start (priority 5).
     *
     * Если в URL есть валидный pending-токен — выводит JS, ставящий email в
     * readonly + добавляющий hidden inputs с токеном и каноническим email.
     */
    public static function render_prefill(): void {
        $token_param = function_exists('class_exists') && class_exists('Cashback_Social_Auth_Account_Manager')
            ? Cashback_Social_Auth_Account_Manager::REGISTER_TOKEN_PARAM
            : 'cashback_social_register';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only inspection of pending token; sanitized via preg_replace ниже; consume происходит на POST с nonce.
        $raw_token = isset($_GET[ $token_param ]) ? (string) wp_unslash($_GET[ $token_param ]) : '';
        $token     = preg_replace('/[^a-f0-9]/i', '', $raw_token);
        if (!is_string($token) || strlen($token) !== 64) {
            return;
        }

        if (!class_exists('Cashback_Social_Auth_DB') || !class_exists('Cashback_Social_Auth_Account_Manager')) {
            return;
        }

        $pending = Cashback_Social_Auth_DB::peek_pending($token);
        if (!is_array($pending)) {
            return;
        }
        if (( $pending['kind'] ?? '' ) !== Cashback_Social_Auth_Account_Manager::KIND_REGISTER_VIA_SOCIAL) {
            return;
        }

        $payload = is_array($pending['payload'] ?? null) ? $pending['payload'] : array();
        $email   = isset($payload['email']) ? (string) $payload['email'] : '';
        if ($email === '' || !is_email($email)) {
            return;
        }

        // Инфо-баннер: объясняем юзеру, что от него требуется на этом шаге.
        // Без баннера редирект на register-форму выглядит загадочно — не очевидно,
        // что нужно отметить чекбоксы и нажать «РЕГИСТРАЦИЯ».
        $provider_label = self::resolve_provider_label(isset($payload['provider']) ? (string) $payload['provider'] : '');
        printf(
            '<div class="cashback-social-register-notice" role="status">' .
                '<strong>%s</strong> %s' .
            '</div>',
            esc_html(sprintf(
                /* translators: %s: provider human label, e.g. "Яндекс ID" */
                __('Авторизация через %s почти завершена.', 'cashback-plugin'),
                $provider_label
            )),
            esc_html__('Чтобы создать аккаунт, отметьте все обязательные согласия ниже и нажмите «Регистрация». Email уже подставлен из вашей соцсети.', 'cashback-plugin')
        );

        // Hidden inputs внутри form (HTML добавит их в POST).
        printf(
            '<input type="hidden" name="cashback_social_register_token" value="%s">',
            esc_attr($token)
        );
        printf(
            '<input type="hidden" name="cashback_social_register_email" value="%s">',
            esc_attr($email)
        );

        // JS подставит email и заблокирует поле — для UX. Серверный override —
        // в force_payload_email(), это лишь защита уровня UI.
        $email_json = (string) wp_json_encode($email);
        $inline_js  = 'document.addEventListener("DOMContentLoaded", function () {' .
            'var emailInput = document.getElementById("reg_email");' .
            'if (emailInput) {' .
                'emailInput.value = ' . $email_json . ';' .
                'emailInput.setAttribute("readonly", "readonly");' .
                'emailInput.setAttribute("aria-readonly", "true");' .
            '}' .
            // Скроллим к форме, чтобы юзер сразу увидел инфо-баннер.
            'var form = document.querySelector("form.woocommerce-form-register");' .
            'if (form && typeof form.scrollIntoView === "function") {' .
                'try { form.scrollIntoView({behavior: "smooth", block: "start"}); } catch (e) {}' .
            '}' .
            // Подсветка обязательных чекбоксов на 4с — обращаем внимание на действия.
            'var checkboxes = document.querySelectorAll(' .
                '"input[name=\"cashback_fraud_consent\"], ' .
                'input[name=\"cashback_legal_consent_pd\"], ' .
                'input[name=\"cashback_legal_consent_offer\"]"' .
            ');' .
            'checkboxes.forEach(function (cb) {' .
                'var row = cb.closest("p, div, label");' .
                'if (row) { row.classList.add("cashback-social-register-pulse"); }' .
            '});' .
            'setTimeout(function () {' .
                'document.querySelectorAll(".cashback-social-register-pulse").forEach(function (el) {' .
                    'el.classList.remove("cashback-social-register-pulse");' .
                '});' .
            '}, 4000);' .
            '});';
        printf(
            '<script>%s</script>',
            $inline_js // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Statically-built JS со значением через wp_json_encode (escapes для JS-контекста).
        );
    }

    /**
     * Человеко-читаемое название провайдера для текста баннера.
     */
    private static function resolve_provider_label( string $provider_id ): string {
        if ($provider_id !== '' && class_exists('Cashback_Social_Auth_Providers')) {
            $labels = Cashback_Social_Auth_Providers::labels();
            if (isset($labels[ $provider_id ])) {
                return (string) $labels[ $provider_id ];
            }
        }
        return __('социальную сеть', 'cashback-plugin');
    }

    // ------------------------------------------------------------------
    // POST phase
    // ------------------------------------------------------------------

    /**
     * Hook woocommerce_new_customer_data (priority 5).
     *
     * Принудительно подставляет email из pending-payload, игнорируя то, что
     * прислал клиент (защита от подмены через DevTools на readonly-инпуте).
     *
     * @param array<string, mixed> $customer_data
     * @return array<string, mixed>
     */
    public static function force_payload_email( $customer_data ): array {
        if (!is_array($customer_data)) {
            $customer_data = array();
        }

        $payload_email = self::resolve_payload_email_from_post();
        if ($payload_email === '') {
            return $customer_data;
        }

        $customer_data['user_email'] = $payload_email;
        return $customer_data;
    }

    /**
     * Hook woocommerce_created_customer (priority 15).
     *
     * Атомарно потребляет pending-токен и привязывает социальный аккаунт.
     * При ошибке: юзер уже создан и consent-записи в БД, просто логируем —
     * пользователь сможет привязать соц-аккаунт через ЛК позже.
     */
    public static function on_created_customer( int $customer_id ): void {
        if ($customer_id <= 0) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // -- nonce проверен WC в process_registration() ДО этого хука; sanitize via preg_replace ниже.
        $raw_token = isset($_POST['cashback_social_register_token'])
            ? (string) wp_unslash($_POST['cashback_social_register_token'])
            : '';
        // phpcs:enable
        $token = preg_replace('/[^a-f0-9]/i', '', $raw_token);
        if (!is_string($token) || strlen($token) !== 64) {
            return;
        }

        if (!class_exists('Cashback_Social_Auth_DB') || !class_exists('Cashback_Social_Auth_Account_Manager')) {
            return;
        }

        $pending = Cashback_Social_Auth_DB::consume_pending($token);
        if (!is_array($pending)) {
            self::log_link_failure($customer_id, 'consume_failed', 'token expired or already used');
            return;
        }
        if (( $pending['kind'] ?? '' ) !== Cashback_Social_Auth_Account_Manager::KIND_REGISTER_VIA_SOCIAL) {
            self::log_link_failure($customer_id, 'wrong_kind', (string) ( $pending['kind'] ?? '' ));
            return;
        }

        $payload     = is_array($pending['payload'] ?? null) ? $pending['payload'] : array();
        $provider_id = isset($payload['provider']) ? (string) $payload['provider'] : '';
        if ($provider_id === '') {
            self::log_link_failure($customer_id, 'missing_provider', '');
            return;
        }

        $provider = self::resolve_provider($provider_id);
        if (!$provider) {
            self::log_link_failure($customer_id, 'provider_unavailable', $provider_id);
            return;
        }

        $profile   = is_array($payload['profile'] ?? null) ? $payload['profile'] : array();
        $token_set = is_array($payload['token_set'] ?? null) ? $payload['token_set'] : array();
        $ip        = isset($payload['ip']) ? (string) $payload['ip'] : '';
        $user_agent = isset($payload['user_agent']) ? (string) $payload['user_agent'] : '';

        try {
            $result = Cashback_Social_Auth_Account_Manager::instance()->link_provider_to_user(
                $customer_id,
                $provider,
                $profile,
                $token_set,
                $ip,
                $user_agent
            );
        } catch (\Throwable $e) {
            self::log_link_failure($customer_id, 'link_throwable', $e->getMessage());
            return;
        }

        if (!empty($result['error'])) {
            self::log_link_failure($customer_id, 'link_returned_error', (string) $result['error']);
            return;
        }

        // Сохраняем редирект-цель в transient на 5 минут, чтобы override_register_redirect
        // её достал — через POST уже не вернуть, $payload потреблён.
        $redirect_after = isset($payload['redirect_after']) ? (string) $payload['redirect_after'] : '';
        if ($redirect_after !== '') {
            set_transient(
                'cashback_social_register_redirect_' . $customer_id,
                $redirect_after,
                5 * MINUTE_IN_SECONDS
            );
        }
    }

    /**
     * Hook woocommerce_registration_redirect (priority 10).
     *
     * Подменяет дефолтный WC-редирект (на my-account) на payload['redirect_after']
     * для post-OAuth conditional consent flow.
     *
     * @param string $redirect
     * @return string
     */
    public static function override_register_redirect( $redirect ): string {
        $redirect = (string) $redirect;

        $current_user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($current_user_id <= 0) {
            return $redirect;
        }

        $key    = 'cashback_social_register_redirect_' . $current_user_id;
        $cached = get_transient($key);
        if (!is_string($cached) || $cached === '') {
            return $redirect;
        }
        delete_transient($key);

        $safe = function_exists('wp_validate_redirect')
            ? (string) wp_validate_redirect($cached, $redirect)
            : $redirect;
        return $safe !== '' ? $safe : $redirect;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Прочитать payload-email из pending-токена в $_POST (peek-режим).
     * Используется на priority 5 woocommerce_new_customer_data до wp_insert_user.
     */
    private static function resolve_payload_email_from_post(): string {
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // -- nonce проверен WC в process_registration() ДО filter'а; sanitize via preg_replace ниже.
        $raw_token = isset($_POST['cashback_social_register_token'])
            ? (string) wp_unslash($_POST['cashback_social_register_token'])
            : '';
        // phpcs:enable
        $token = preg_replace('/[^a-f0-9]/i', '', $raw_token);
        if (!is_string($token) || strlen($token) !== 64) {
            return '';
        }

        if (!class_exists('Cashback_Social_Auth_DB') || !class_exists('Cashback_Social_Auth_Account_Manager')) {
            return '';
        }

        $pending = Cashback_Social_Auth_DB::peek_pending($token);
        if (!is_array($pending)) {
            return '';
        }
        if (( $pending['kind'] ?? '' ) !== Cashback_Social_Auth_Account_Manager::KIND_REGISTER_VIA_SOCIAL) {
            return '';
        }

        $payload = is_array($pending['payload'] ?? null) ? $pending['payload'] : array();
        $email   = isset($payload['email']) ? (string) $payload['email'] : '';
        if ($email === '' || !is_email($email)) {
            return '';
        }

        return sanitize_email($email);
    }

    /**
     * @return Cashback_Social_Provider_Interface|null
     */
    private static function resolve_provider( string $provider_id ) {
        if (!class_exists('Cashback_Social_Auth_Providers')) {
            return null;
        }
        $providers = Cashback_Social_Auth_Providers::instance()->all();
        if (!isset($providers[ $provider_id ])) {
            return null;
        }
        $provider = $providers[ $provider_id ];
        if (!$provider instanceof Cashback_Social_Provider_Interface) {
            return null;
        }
        if (!$provider->is_enabled()) {
            return null;
        }
        return $provider;
    }

    private static function log_link_failure( int $user_id, string $stage, string $detail ): void {
        if (!class_exists('Cashback_Social_Auth_Audit')) {
            return;
        }
        Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
            'stage'   => 'register_bridge_' . $stage,
            'user_id' => $user_id,
            'detail'  => $detail,
        ));
    }
}
