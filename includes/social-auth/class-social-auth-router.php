<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST-роутер модуля социальной авторизации.
 *
 * Маршруты в namespace cashback/v1:
 *  - GET  /social/{provider}/start     — инициировать OAuth flow (302)
 *  - GET  /social/{provider}/callback  — обработать редирект от провайдера
 *  - POST /social/email-prompt         — принять email от пользователя (заглушка)
 *  - GET  /social/confirm?token=...    — подтверждение по email-ссылке (заглушка)
 *
 * На Этапе 1 callback/email/confirm возвращают 501 (Not implemented) — полная
 * реализация в Этапе 4. Start — валидирует провайдер и rate-limit, но не
 * выполняет редирект к провайдеру (у наследника нет get_authorize_url).
 */
class Cashback_Social_Auth_Router {

    private const NAMESPACE = 'cashback/v1';

    public function register(): void {
        add_action('rest_api_init', array( $this, 'register_routes' ));
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
            '/social/confirm',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_confirm' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * GET /social/{provider}/start
     *
     * @return \WP_Error|\WP_REST_Response
     */
    public function handle_start( \WP_REST_Request $request ) {
        $provider_id = (string) $request->get_param('provider');
        $provider    = $this->resolve_provider($provider_id);

        if (!$provider) {
            return new \WP_Error('social_provider_unknown', __('Провайдер не найден.', 'cashback-plugin'), array( 'status' => 404 ));
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
        );

        Cashback_Social_Auth_Session::store($provider_id, $data);

        // На Этапе 1 у провайдеров ещё нет реализации — не делаем редирект,
        // а возвращаем понятную ошибку. На Этапе 2–3 провайдеры вернут URL, и
        // тогда ниже — wp_redirect($url) + exit.
        try {
            $authorize_url = $provider->get_authorize_url($data);
        } catch (\Throwable $e) {
            return new \WP_Error(
                'social_not_implemented',
                __('Провайдер ещё не готов к авторизации. Этап 1 реализации.', 'cashback-plugin'),
                array(
					'status' => 501,
					'detail' => $e->getMessage(),
				)
            );
        }

        if (!wp_http_validate_url($authorize_url)) {
            return new \WP_Error('social_bad_authorize_url', __('Неверный authorize URL от провайдера.', 'cashback-plugin'), array( 'status' => 500 ));
        }

        wp_redirect($authorize_url); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External provider redirect by design.
        exit;
    }

    /**
     * GET /social/{provider}/callback
     *
     * @return \WP_Error|\WP_REST_Response
     */
    public function handle_callback( \WP_REST_Request $request ) {
        $provider_id = (string) $request->get_param('provider');
        $provider    = $this->resolve_provider($provider_id);

        if (!$provider) {
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

        $state = sanitize_text_field((string) $request->get_param('state'));
        $code  = sanitize_text_field((string) $request->get_param('code'));
        $error = sanitize_text_field((string) $request->get_param('error'));

        if ($error !== '') {
            Cashback_Social_Auth_Session::clear($provider_id);
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $provider_id,
                'error'    => $error,
                'ip'       => $ip,
            ));
            return new \WP_Error('social_provider_error', $error, array( 'status' => 400 ));
        }

        if ($state === '' || $code === '') {
            return new \WP_Error('social_invalid_callback', __('Некорректный callback (нет state/code).', 'cashback-plugin'), array( 'status' => 400 ));
        }

        $session = Cashback_Social_Auth_Session::load_and_verify($provider_id, $state);
        if (!$session) {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_STATE_MISMATCH, array(
                'provider' => $provider_id,
                'ip'       => $ip,
            ));
            return new \WP_Error('social_state_mismatch', __('Сессия устарела. Повторите вход.', 'cashback-plugin'), array( 'status' => 403 ));
        }

        // Debug-режим для ручной проверки провайдера: только при WP_DEBUG и явном флаге.
        // Выполняет exchange_code + fetch_user_info и возвращает профайл JSON,
        // НЕ создавая/привязывая пользователя. Аккаунт-менеджер — Этап 4.
        $debug_profile = defined('WP_DEBUG') && WP_DEBUG && (int) $request->get_param('debug_profile') === 1;
        if ($debug_profile) {
            $code_verifier = isset($session['code_verifier']) ? (string) $session['code_verifier'] : '';
            $redirect_uri  = isset($session['redirect_uri']) ? (string) $session['redirect_uri'] : $provider->get_redirect_uri();

            try {
                $token_set = $provider->exchange_code($code, $code_verifier, $redirect_uri);
                $profile   = $provider->fetch_user_info((string) $token_set['access_token']);
            } catch (\Throwable $e) {
                Cashback_Social_Auth_Session::clear($provider_id);
                return new \WP_Error(
                    'social_debug_flow_failed',
                    $e->getMessage(),
                    array( 'status' => 500 )
                );
            }

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

        // Штатный flow (exchange + fetch + link/create user) — Этап 4 (Account Manager).
        return new \WP_Error(
            'social_not_implemented',
            __('Обработка callback будет реализована на Этапе 4.', 'cashback-plugin'),
            array( 'status' => 501 )
        );
    }

    /**
     * POST /social/email-prompt — заглушка.
     */
    public function handle_email_prompt( \WP_REST_Request $request ) {
        unset($request);
        return new \WP_Error(
            'social_not_implemented',
            __('Email-prompt будет реализован на Этапе 4.', 'cashback-plugin'),
            array( 'status' => 501 )
        );
    }

    /**
     * GET /social/confirm — заглушка.
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

        return new \WP_Error(
            'social_not_implemented',
            __('Подтверждение привязки будет реализовано на Этапе 4.', 'cashback-plugin'),
            array( 'status' => 501 )
        );
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
}
