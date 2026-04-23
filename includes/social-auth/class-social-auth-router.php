<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST-роутер модуля социальной авторизации.
 *
 * Маршруты в namespace cashback/v1:
 *  - GET  /social/{provider}/start          — инициировать OAuth flow (302)
 *  - GET  /social/{provider}/callback       — обработать редирект от провайдера
 *  - POST /social/email-prompt              — принять email от пользователя (ветка C)
 *  - GET  /social/email-prompt-form         — отрисовать форму email (HTML)
 *  - GET  /social/confirm?token=...         — подтверждение по email-ссылке (double opt-in)
 *
 * Полная реализация веток A-D через Cashback_Social_Auth_Account_Manager (Этап 4).
 */
class Cashback_Social_Auth_Router {

    private const NAMESPACE = 'cashback/v1';

    public function register(): void {
        add_action('rest_api_init', array( $this, 'register_routes' ));
        add_filter('login_message', array( $this, 'filter_login_message' ));
        add_action('woocommerce_before_customer_login_form', array( $this, 'render_wc_login_message' ));
    }

    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE,
            '/social/(?P<provider>[a-z0-9_-]+)/start',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_start' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'provider' => array(
                        'sanitize_callback' => 'sanitize_key',
                    ),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/social/(?P<provider>[a-z0-9_-]+)/callback',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_callback' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'provider' => array(
                        'sanitize_callback' => 'sanitize_key',
                    ),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/social/email-prompt',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_email_prompt' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/social/email-prompt-form',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_email_prompt_form' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/social/confirm',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_confirm' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/social/unlink',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_unlink' ),
                'permission_callback' => function () {
                    return is_user_logged_in();
                },
            )
        );
    }

    /**
     * GET /social/{provider}/start
     *
     * @return \WP_Error|\WP_REST_Response|null
     */
    public function handle_start( \WP_REST_Request $request ) {
        $provider_id = (string) $request->get_param('provider');
        $provider    = $this->resolve_provider($provider_id);

        if (!$provider) {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $provider_id,
                'stage'    => 'start',
                'reason'   => 'provider_disabled_or_unknown',
            ));
            $this->redirect_to_login_with_error('start_failed');
            return null;
        }

        $ip = $this->get_client_ip();

        if (class_exists('Cashback_Rate_Limiter')) {
            $check = Cashback_Rate_Limiter::check('social_start', get_current_user_id(), $ip);
            if (empty($check['allowed'])) {
                Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_RATE_LIMITED, array(
                    'stage'    => 'start',
                    'provider' => $provider_id,
                    'ip'       => $ip,
                ));
                return new \WP_Error(
                    'social_rate_limited',
                    __('Слишком много попыток. Подождите немного и повторите.', 'cashback-plugin'),
                    array( 'status' => 429 )
                );
            }
        }

        // 11b-3 (iter-11): explicit consent. Checkbox на UI ставит query-param
        // cashback_social_consent=1 через JS; без него отказываем на OAuth-start,
        // чтобы юзер не мог достичь user-creation-flow без явного клика «согласен».
        $consent_raw   = (string) $request->get_param('cashback_social_consent');
        $consent_given = ( $consent_raw === '1' );
        if (!$consent_given) {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $provider_id,
                'stage'    => 'start',
                'reason'   => 'no_consent',
            ));
            $this->redirect_to_login_with_error('start_failed');
            return null;
        }

        Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_START, array(
            'provider' => $provider_id,
            'ip'       => $ip,
        ));

        // Подготовить state и PKCE-пару, сохранить в сессии.
        $pkce  = Cashback_Social_Auth_Session::generate_pkce();
        $state = Cashback_Social_Auth_Session::generate_state();

        $redirect_after = (string) $request->get_param('redirect_to');
        $redirect_after = $redirect_after !== '' ? wp_validate_redirect($redirect_after, home_url('/')) : home_url('/');

        $data = array(
            'state'             => $state,
            'code_verifier'     => $pkce['verifier'],
            'code_challenge'    => $pkce['challenge'],
            'redirect_uri'      => $provider->get_redirect_uri(),
            'redirect_after'    => $redirect_after,
            'referral_snapshot' => Cashback_Social_Auth_Session::capture_referral_snapshot(),
            'scope_phone'       => method_exists($provider, 'is_phone_scope_enabled') ? (bool) $provider->is_phone_scope_enabled() : false,
            'ip'                => $ip,
            'user_agent'        => $this->get_user_agent(),
            // 11b-3: фиксируем explicit consent для записи в user_meta после wp_insert_user.
            'consent_given'     => $consent_given,
        );

        Cashback_Social_Auth_Session::store($provider_id, $data);

        try {
            $authorize_url = $provider->get_authorize_url($data);
        } catch (\Throwable $e) {
            Cashback_Social_Auth_Session::clear($provider_id);
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $provider_id,
                'stage'    => 'start_authorize_url',
                'error'    => $e->getMessage(),
            ));
            $this->redirect_to_login_with_error('start_failed');
            return null;
        }

        if (!wp_http_validate_url($authorize_url)) {
            Cashback_Social_Auth_Session::clear($provider_id);
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $provider_id,
                'stage'    => 'start_authorize_url',
                'error'    => 'invalid_authorize_url',
            ));
            $this->redirect_to_login_with_error('start_failed');
            return null;
        }

        wp_redirect($authorize_url); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External provider redirect by design.
        exit;
    }

    /**
     * GET /social/{provider}/callback
     *
     * @return \WP_Error|\WP_REST_Response|null
     */
    public function handle_callback( \WP_REST_Request $request ) {
        $provider_id = (string) $request->get_param('provider');
        $provider    = $this->resolve_provider($provider_id);

        if (!$provider) {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $provider_id,
                'stage'    => 'callback',
                'reason'   => 'provider_disabled_or_unknown',
            ));
            return new \WP_Error('social_provider_unknown', __('Провайдер не найден.', 'cashback-plugin'), array( 'status' => 404 ));
        }

        $ip = $this->get_client_ip();

        if (class_exists('Cashback_Rate_Limiter')) {
            $check = Cashback_Rate_Limiter::check('social_callback', get_current_user_id(), $ip);
            if (empty($check['allowed'])) {
                Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_RATE_LIMITED, array(
                    'stage'    => 'callback',
                    'provider' => $provider_id,
                    'ip'       => $ip,
                ));
                return new \WP_Error(
                    'social_rate_limited',
                    __('Слишком много попыток.', 'cashback-plugin'),
                    array( 'status' => 429 )
                );
            }
        }

        $state     = sanitize_text_field((string) $request->get_param('state'));
        $code      = sanitize_text_field((string) $request->get_param('code'));
        $error     = sanitize_text_field((string) $request->get_param('error'));
        $device_id = sanitize_text_field((string) $request->get_param('device_id'));

        if ($error !== '') {
            Cashback_Social_Auth_Session::clear($provider_id);
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $provider_id,
                'error'    => $error,
                'ip'       => $ip,
            ));
            $this->redirect_to_login_with_error('provider_error');
            return null;
        }

        if ($state === '' || $code === '') {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $provider_id,
                'stage'    => 'callback',
                'reason'   => 'invalid_callback',
                'ip'       => $ip,
            ));
            $this->redirect_to_login_with_error('invalid_callback');
            return null;
        }

        $session = Cashback_Social_Auth_Session::load_and_verify($provider_id, $state);
        if (!$session) {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_STATE_MISMATCH, array(
                'provider' => $provider_id,
                'ip'       => $ip,
            ));
            $this->redirect_to_login_with_error('state_mismatch');
            return null;
        }

        // Debug-режим: при WP_DEBUG и debug_profile=1 — только вернуть профайл (не логинить).
        $debug_profile = defined('WP_DEBUG') && WP_DEBUG && (int) $request->get_param('debug_profile') === 1;

        $code_verifier = isset($session['code_verifier']) ? (string) $session['code_verifier'] : '';
        $redirect_uri  = isset($session['redirect_uri']) ? (string) $session['redirect_uri'] : $provider->get_redirect_uri();

        $exchange_extra = array(
            'device_id' => $device_id,
            'state'     => $state,
        );

        try {
            $token_set = $provider->exchange_code($code, $code_verifier, $redirect_uri, $exchange_extra);
            $profile   = $provider->fetch_user_info(
                (string) $token_set['access_token'],
                isset($token_set['extra']) && is_array($token_set['extra']) ? $token_set['extra'] : array()
            );
        } catch (\Throwable $e) {
            Cashback_Social_Auth_Session::clear($provider_id);
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $provider_id,
                'stage'    => 'exchange_or_fetch',
                'error'    => $e->getMessage(),
            ));

            if ($debug_profile) {
                return new \WP_Error('social_debug_flow_failed', $e->getMessage(), array( 'status' => 500 ));
            }

            $this->redirect_to_login_with_error('exchange_failed');
            return null;
        }

        if ($debug_profile) {
            Cashback_Social_Auth_Session::clear($provider_id);
            return new \WP_REST_Response(array(
                'debug'   => true,
                'profile' => $profile,
                'token'   => array(
                    'has_access_token'  => $token_set['access_token'] !== '',
                    'has_refresh_token' => !empty($token_set['refresh_token']),
                    'expires_in'        => $token_set['expires_in'],
                    'scope'             => $token_set['scope'],
                ),
            ), 200);
        }

        // Основной flow — Account Manager.
        $session_data = array(
            'redirect_after'    => isset($session['redirect_after']) ? (string) $session['redirect_after'] : home_url('/'),
            'referral_snapshot' => isset($session['referral_snapshot']) && is_array($session['referral_snapshot'])
                ? $session['referral_snapshot']
                : array(),
            'ip'                => $ip,
            'user_agent'        => $this->get_user_agent(),
            'scope_phone'       => !empty($session['scope_phone']),
        );

        try {
            $result = Cashback_Social_Auth_Account_Manager::instance()
                ->handle_callback($provider, $profile, $token_set, $session_data, $request);
        } catch (\Throwable $e) {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $provider_id,
                'stage'    => 'account_manager_exception',
                'error'    => $e->getMessage(),
            ));
            $this->redirect_to_login_with_error('account_error');
            return null;
        }

        $action = isset($result['action']) ? (string) $result['action'] : 'error';

        if ($action === 'login') {
            $target = isset($result['redirect_url']) ? (string) $result['redirect_url'] : home_url('/');
            $target = wp_validate_redirect($target, home_url('/'));
            wp_safe_redirect($target);
            exit;
        }

        if ($action === 'prompt_email') {
            $target = isset($result['redirect_url']) ? (string) $result['redirect_url'] : home_url('/');
            // Здесь редиректим на внутренний endpoint /email-prompt-form (home_url base) — используем обычный wp_redirect.
            wp_redirect($target); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- REST endpoint на том же хосте.
            exit;
        }

        if ($action === 'pending') {
            $this->redirect_to_login_with_message('check_email');
            return null;
        }

        // action=error
        $msg = isset($result['message']) ? (string) $result['message'] : __('Ошибка авторизации.', 'cashback-plugin');
        Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
            'provider' => $provider_id,
            'stage'    => 'account_manager_result',
            'error'    => $msg,
        ));
        $this->redirect_to_login_with_error('account_error');
        return null;
    }

    /**
     * POST /social/email-prompt — принять email от пользователя (ветка C).
     *
     * @return \WP_Error|\WP_REST_Response|null
     */
    public function handle_email_prompt( \WP_REST_Request $request ) {
        $ip = $this->get_client_ip();

        if (class_exists('Cashback_Rate_Limiter')) {
            $check = Cashback_Rate_Limiter::check('social_email_prompt', get_current_user_id(), $ip);
            if (empty($check['allowed'])) {
                Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_RATE_LIMITED, array(
                    'stage' => 'email_prompt',
                    'ip'    => $ip,
                ));
                return new \WP_Error('social_rate_limited', __('Слишком много попыток.', 'cashback-plugin'), array( 'status' => 429 ));
            }
        }

        // Nonce (wp_rest).
        $nonce = $request->get_header('x_wp_nonce');
        if (!$nonce) {
            $nonce = (string) $request->get_param('_wpnonce');
        }
        if (!$nonce || !wp_verify_nonce((string) $nonce, 'wp_rest')) {
            return $this->render_email_prompt_form(
                (string) $request->get_param('token'),
                __('Срок действия формы истёк. Обновите страницу и попробуйте снова.', 'cashback-plugin')
            );
        }

        $result = Cashback_Social_Auth_Account_Manager::instance()
            ->handle_email_prompt_submission($request);

        $action = isset($result['action']) ? (string) $result['action'] : 'error';

        if ($action === 'pending') {
            $this->redirect_to_login_with_message('check_email');
            return null;
        }

        // Если ошибка — повторно рисуем форму с сообщением.
        $msg = isset($result['message']) ? (string) $result['message'] : __('Ошибка.', 'cashback-plugin');
        return $this->render_email_prompt_form((string) $request->get_param('token'), $msg);
    }

    /**
     * GET /social/email-prompt-form — отрисовать форму (HTML).
     *
     * @return \WP_REST_Response
     */
    public function handle_email_prompt_form( \WP_REST_Request $request ) {
        $token = sanitize_text_field((string) $request->get_param('token'));
        return $this->render_email_prompt_form($token, '');
    }

    /**
     * GET /social/confirm — подтверждение email-ссылки (double opt-in).
     *
     * @return \WP_Error|\WP_REST_Response|null
     */
    public function handle_confirm( \WP_REST_Request $request ) {
        $token = sanitize_text_field((string) $request->get_param('token'));
        if ($token === '') {
            return new \WP_Error('social_bad_token', __('Нет токена подтверждения.', 'cashback-plugin'), array( 'status' => 400 ));
        }

        if (class_exists('Cashback_Rate_Limiter')) {
            $ip    = $this->get_client_ip();
            $check = Cashback_Rate_Limiter::check('social_confirm', get_current_user_id(), $ip);
            if (empty($check['allowed'])) {
                Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_RATE_LIMITED, array(
                    'stage' => 'confirm',
                    'ip'    => $ip,
                ));
                return new \WP_Error('social_rate_limited', __('Слишком много попыток.', 'cashback-plugin'), array( 'status' => 429 ));
            }
        }

        $result = Cashback_Social_Auth_Account_Manager::instance()->handle_confirm($request);
        $action = isset($result['action']) ? (string) $result['action'] : 'error';

        if ($action === 'login') {
            $target = isset($result['redirect_url']) ? (string) $result['redirect_url'] : home_url('/');
            $target = wp_validate_redirect($target, home_url('/'));
            wp_safe_redirect($target);
            exit;
        }

        $msg = isset($result['message']) ? (string) $result['message'] : __('Срок действия ссылки истёк.', 'cashback-plugin');
        $this->render_expired_link_page($msg);
        return null;
    }

    // =========================================================================
    // UI helpers
    // =========================================================================

    /**
     * Отрисовать email-prompt форму из template.
     *
     * @return \WP_REST_Response
     */
    private function render_email_prompt_form( string $token, string $error_message ): \WP_REST_Response {
        $template  = plugin_dir_path(__FILE__) . 'templates/email-prompt.php';
        $endpoint  = rest_url('cashback/v1/social/email-prompt');
        $provider  = '';
        $site_name = get_bloginfo('name');
        $cb_error  = $error_message;

        // Шаблон использует переменные token, cb_error, endpoint, provider, site_name.
        ob_start();
        if (file_exists($template)) {
            include $template;
        } else {
            unset($token, $cb_error);
            echo '<p>' . esc_html__('Шаблон формы не найден.', 'cashback-plugin') . '</p>';
        }
        $html = (string) ob_get_clean();

        $response = new \WP_REST_Response($html, 200);
        $response->header('Content-Type', 'text/html; charset=UTF-8');
        return $response;
    }

    /**
     * Отрисовать страницу «ссылка истекла / недействительна» с кнопками
     * социальных провайдеров и ссылкой на обычный вход.
     *
     * Используется когда токен подтверждения (confirm_link / email_verify)
     * уже использован или истёк — пользователю нужно заново инициировать
     * вход через соцсеть, чтобы получить свежее письмо.
     */
    private function render_expired_link_page( string $message ): void {
        status_header(410);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');

        $title        = esc_html__('Срок действия ссылки истёк', 'cashback-plugin');
        $lead         = esc_html__('Для входа ещё раз нажмите кнопку ниже — мы отправим новое письмо подтверждения.', 'cashback-plugin');
        $msg          = esc_html($message);
        $fallback_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/');
        if (!is_string($fallback_url) || $fallback_url === '') {
            $fallback_url = home_url('/');
        }
        $fallback     = esc_url($fallback_url);
        $fallback_txt = esc_html__('Вернуться на страницу входа', 'cashback-plugin');

        $buttons_html = '';
        if (class_exists('Cashback_Social_Auth_Renderer')) {
            $buttons_html = Cashback_Social_Auth_Renderer::instance()->render_buttons('login');
            // На странице просроченной ссылки «или» не нужен — над кнопкой нет альтернативы.
            $buttons_html = (string) preg_replace('/\s+data-label="[^"]*"/', '', $buttons_html);
        }

        $plugin_root_file = dirname(__DIR__, 2) . '/cashback-plugin.php';
        $buttons_css_url  = esc_url(add_query_arg('ver', '1.1.2', plugins_url('assets/social-auth/css/buttons.css', $plugin_root_file)));

        $favicon_html = '';
        $icon_32      = get_site_icon_url(32);
        $icon_192     = get_site_icon_url(192);
        if (is_string($icon_32) && $icon_32 !== '') {
            $favicon_html .= '<link rel="icon" href="' . esc_url($icon_32) . '" sizes="32x32">';
        }
        if (is_string($icon_192) && $icon_192 !== '') {
            $favicon_html .= '<link rel="icon" href="' . esc_url($icon_192) . '" sizes="192x192">';
            $favicon_html .= '<link rel="apple-touch-icon" href="' . esc_url($icon_192) . '">';
        }

        $html = '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $title . '</title>'
            . $favicon_html
            . '<link rel="stylesheet" href="' . $buttons_css_url . '">'
            . '<style>'
            . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;max-width:520px;margin:60px auto;padding:24px;background:#f5f5f5;color:#222;line-height:1.5}'
            . '.box{background:#fff;padding:32px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.08)}'
            . 'h1{margin:0 0 12px;font-size:22px}'
            . '.msg{color:#555;margin:0 0 20px}'
            . '.lead{color:#222;margin:0 0 20px}'
            . '.back{display:block;margin-top:18px;color:#666;text-align:center;font-size:14px;text-decoration:underline}'
            . '</style></head><body><div class="box">'
            . '<h1>' . $title . '</h1>'
            . '<p class="msg">' . $msg . '</p>'
            . '<p class="lead">' . $lead . '</p>'
            . $buttons_html
            . '<a class="back" href="' . $fallback . '">' . $fallback_txt . '</a>'
            . '</div></body></html>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html composed from pre-escaped fragments.
        echo $html;

        exit;
    }

    /**
     * Редирект на страницу входа с сообщением (login_message / WC login).
     */
    private function redirect_to_login_with_message( string $code ): void {
        $base   = class_exists('WooCommerce') ? wc_get_page_permalink('myaccount') : wp_login_url();
        $target = add_query_arg('cashback_social_msg', rawurlencode($code), (string) $base);
        wp_safe_redirect($target);
        exit;
    }

    /**
     * Редирект на login с кодом ошибки (error-параметр).
     */
    private function redirect_to_login_with_error( string $code ): void {
        $base   = class_exists('WooCommerce') ? wc_get_page_permalink('myaccount') : wp_login_url();
        $target = add_query_arg('cashback_social_error', rawurlencode($code), (string) $base);
        wp_safe_redirect($target);
        exit;
    }

    /**
     * Добавить сообщение на wp-login.php (фильтр login_message).
     */
    public function filter_login_message( $message ) {
        $msg = $this->resolve_flash_message();
        if ($msg !== '') {
            $message .= '<p class="message">' . esc_html($msg) . '</p>';
        }
        return $message;
    }

    /**
     * Вывести сообщение на странице WooCommerce my-account (login form).
     */
    public function render_wc_login_message(): void {
        $msg = $this->resolve_flash_message();
        if ($msg !== '') {
            echo '<div class="woocommerce-info">' . esc_html($msg) . '</div>';
        }
    }

    /**
     * Подобрать текст флеш-сообщения по query-параметрам.
     */
    private function resolve_flash_message(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash from query.
        if (isset($_GET['cashback_social_msg'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash from query.
            $code = sanitize_key((string) wp_unslash($_GET['cashback_social_msg']));
            if ($code === 'check_email') {
                return __('Мы отправили письмо для подтверждения. Проверьте почту и перейдите по ссылке в течение 15 минут.', 'cashback-plugin');
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash from query.
        if (isset($_GET['cashback_social_error'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash from query.
            $code = sanitize_key((string) wp_unslash($_GET['cashback_social_error']));
            switch ($code) {
                case 'provider_error':
                    return __('Провайдер соцсети вернул ошибку. Попробуйте ещё раз.', 'cashback-plugin');
                case 'state_mismatch':
                    return __('Сессия устарела. Повторите вход.', 'cashback-plugin');
                case 'invalid_callback':
                    return __('Некорректный ответ от соцсети. Попробуйте ещё раз.', 'cashback-plugin');
                case 'exchange_failed':
                    return __('Не удалось завершить вход через соцсеть. Попробуйте ещё раз.', 'cashback-plugin');
                case 'account_error':
                    return __('Не удалось завершить авторизацию. Попробуйте ещё раз или обратитесь в поддержку.', 'cashback-plugin');
                case 'start_failed':
                    return __('Не удалось начать авторизацию через соцсеть. Проверьте настройки модуля в админ-панели.', 'cashback-plugin');
                default:
                    return __('Ошибка авторизации через соцсеть.', 'cashback-plugin');
            }
        }

        return '';
    }

    /**
     * Получить активный провайдер по id (или null).
     */
    private function resolve_provider( string $id ): ?Cashback_Social_Provider_Interface {
        if (!class_exists('Cashback_Social_Auth_Providers')) {
            return null;
        }
        $registry = Cashback_Social_Auth_Providers::instance();
        $provider = $registry->get($id);
        if (!$provider) {
            return null;
        }
        if (!$provider->is_enabled()) {
            return null;
        }
        return $provider;
    }

    /**
     * Клиентский IP (без доверия X-Forwarded-For, если нет обратного прокси).
     */
    private function get_client_ip(): string {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- REMOTE_ADDR для rate-limit; значение фильтруется regex-ом ниже до safe-формы IP.
        $raw = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ip  = preg_replace('/[^0-9a-f:\.]/i', '', $raw) ?? '';
        return substr($ip, 0, 45);
    }

    /**
     * POST /social/unlink — отвязать социальный аккаунт текущего пользователя.
     *
     * @return \WP_Error|\WP_REST_Response|null
     */
    public function handle_unlink( \WP_REST_Request $request ) {
        $ip      = $this->get_client_ip();
        $user_id = (int) get_current_user_id();

        if ($user_id <= 0) {
            return new \WP_Error('rest_forbidden', __('Требуется авторизация.', 'cashback-plugin'), array( 'status' => 401 ));
        }

        // Nonce (wp_rest).
        $nonce = $request->get_header('x_wp_nonce');
        if (!$nonce) {
            $nonce = (string) $request->get_param('_wpnonce');
        }
        if (!$nonce || !wp_verify_nonce((string) $nonce, 'wp_rest')) {
            $this->redirect_account_social_with_error('unlink_failed');
            return null;
        }

        if (class_exists('Cashback_Rate_Limiter')) {
            $check = Cashback_Rate_Limiter::check('social_unlink', $user_id, $ip);
            if (empty($check['allowed'])) {
                Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_RATE_LIMITED, array(
                    'stage' => 'unlink',
                    'ip'    => $ip,
                ));
                $this->redirect_account_social_with_error('unlink_failed');
                return null;
            }
        }

        $provider_id = sanitize_key((string) $request->get_param('provider'));
        if ($provider_id === '') {
            $this->redirect_account_social_with_error('unlink_failed');
            return null;
        }

        // Получить все связки юзера.
        $links       = Cashback_Social_Auth_DB::get_links_for_user($user_id);
        $target_link = null;
        foreach ($links as $row) {
            if (isset($row['provider']) && (string) $row['provider'] === $provider_id) {
                $target_link = $row;
                break;
            }
        }

        if (!$target_link) {
            $this->redirect_account_social_with_error('unlink_failed');
            return null;
        }

        // Проверка: должна быть возможность войти иным способом.
        $user         = get_userdata($user_id);
        $has_password = $user instanceof \WP_User && $user->user_pass !== '';
        $total_links  = count($links);

        if (!$has_password && $total_links <= 1) {
            $this->redirect_account_social_with_error('last_method');
            return null;
        }

        $link_id = (int) $target_link['id'];

        // Удалить связку, и только после успеха — токены (избегаем состояния
        // «токены удалены, связка осталась»).
        $ok = Cashback_Social_Auth_DB::delete_link($link_id);

        if (!$ok) {
            $this->redirect_account_social_with_error('unlink_failed');
            return null;
        }

        if (class_exists('Cashback_Social_Auth_Token_Store')) {
            Cashback_Social_Auth_Token_Store::delete($link_id);
        }

        Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_UNLINK, array(
            'provider' => $provider_id,
            'user_id'  => $user_id,
            'link_id'  => $link_id,
            'ip'       => $ip,
        ));

        // Security-уведомление об отвязке соцсети.
        if (class_exists('Cashback_Social_Auth_Emails')) {
            Cashback_Social_Auth_Emails::instance()->send_account_unlinked(
                $user_id,
                $this->provider_label_for_email($provider_id),
                $ip,
                $this->get_user_agent()
            );
        }

        $target = add_query_arg('cashback_social_unlinked', '1', (string) wc_get_account_endpoint_url('edit-account'));
        wp_safe_redirect($target);
        exit;
    }

    /**
     * Редирект на вкладку ЛК «Соцсети» (внутри edit-account) с кодом ошибки.
     */
    private function redirect_account_social_with_error( string $code ): void {
        $base   = function_exists('wc_get_account_endpoint_url')
            ? (string) wc_get_account_endpoint_url('edit-account')
            : home_url('/my-account/edit-account/');
        $target = add_query_arg('cashback_social_error', rawurlencode($code), $base);
        wp_safe_redirect($target);
        exit;
    }

    /**
     * Человеко-читаемое имя провайдера (для email-уведомлений).
     */
    private function provider_label_for_email( string $provider_id ): string {
        switch ($provider_id) {
            case 'yandex':
                return 'Яндекс ID';
            case 'vkid':
                return 'VK ID';
            default:
                return $provider_id;
        }
    }

    /**
     * User-Agent (обрезанный до 255 символов).
     */
    private function get_user_agent(): string {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders -- UA для аудита; сохраняется verbatim, не используется в запросах.
        $raw = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $raw = wp_unslash($raw);
        $raw = wp_strip_all_tags($raw);
        return substr((string) $raw, 0, 255);
    }
}
