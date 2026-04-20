<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * VK ID OAuth 2.1 провайдер (.ru-домены, PKCE S256, device_id).
 *
 * VK ID с 2024 мигрировал на id.vk.ru; legacy id.vk.com deprecated до Q4 2026.
 * VK ID SDK объединяет вход через VK / Одноклассники / Mail.ru — выбор сервиса
 * происходит внутри виджета провайдера.
 *
 * Default endpoints (можно переопределить фильтром `cashback_social_vkid_endpoints`):
 *  - authorize: https://id.vk.ru/authorize
 *  - token    : https://id.vk.ru/oauth2/auth
 *  - user_info: https://id.vk.ru/oauth2/user_info (POST)
 *
 * Особенности относительно Yandex:
 *  - code_challenge обязателен;
 *  - device_id приходит в callback-query и обязателен в token-exchange;
 *  - client — public OAuth 2.1, client_secret опционален
 *    (при наличии секрета используется Basic Auth дополнительно).
 */
class Cashback_Social_Provider_Vkid extends Cashback_Social_Provider_Abstract {

    public const ID = 'vkid';

    private const DEFAULT_AUTHORIZE_URL = 'https://id.vk.ru/authorize';
    private const DEFAULT_TOKEN_URL     = 'https://id.vk.ru/oauth2/auth';
    private const DEFAULT_USERINFO_URL  = 'https://id.vk.ru/oauth2/user_info';

    public function get_id(): string {
        return self::ID;
    }

    /**
     * Провайдер VK ID включён, если:
     *  - глобальный тумблер cashback_social_enabled=1;
     *  - в настройках провайдера enabled=1;
     *  - задан client_id (client_secret НЕ обязателен — public client + PKCE).
     */
    public function is_enabled(): bool {
        if ((int) get_option('cashback_social_enabled', 0) !== 1) {
            return false;
        }
        $s = $this->get_settings();
        return !empty($s['enabled']) && !empty($s['client_id']);
    }

    /**
     * Актуальные endpoints (с учётом фильтра `cashback_social_vkid_endpoints`).
     *
     * @return array{authorize:string, token:string, user_info:string}
     */
    private function get_endpoints(): array {
        $defaults = array(
            'authorize' => self::DEFAULT_AUTHORIZE_URL,
            'token'     => self::DEFAULT_TOKEN_URL,
            'user_info' => self::DEFAULT_USERINFO_URL,
        );

        /**
         * Фильтр для переопределения endpoint-ов VK ID.
         *
         * @param array{authorize:string, token:string, user_info:string} $defaults
         */
        $filtered = apply_filters('cashback_social_vkid_endpoints', $defaults);
        if (!is_array($filtered)) {
            return $defaults;
        }

        $out = $defaults;
        foreach (array( 'authorize', 'token', 'user_info' ) as $key) {
            if (!empty($filtered[ $key ]) && is_string($filtered[ $key ]) && wp_http_validate_url($filtered[ $key ])) {
                $out[ $key ] = (string) $filtered[ $key ];
            }
        }
        return $out;
    }

    /**
     * Построить authorize URL (OAuth 2.1 + PKCE S256).
     *
     * @param array<string, mixed> $state
     */
    public function get_authorize_url( array $state ): string {
        $scopes = array( 'vkid.personal_info', 'email' );
        if (!empty($state['scope_phone'])) {
            $scopes[] = 'phone';
        }

        $redirect_uri = isset($state['redirect_uri']) && $state['redirect_uri'] !== ''
            ? (string) $state['redirect_uri']
            : $this->get_redirect_uri();

        $code_challenge = isset($state['code_challenge']) ? (string) $state['code_challenge'] : '';
        if ($code_challenge === '') {
            throw new \RuntimeException('VK ID requires code_challenge (PKCE) in state.');
        }

        $args = array(
            'response_type'         => 'code',
            'client_id'             => $this->get_client_id(),
            'redirect_uri'          => $redirect_uri,
            'state'                 => isset($state['state']) ? (string) $state['state'] : '',
            'scope'                 => implode(' ', $scopes),
            'code_challenge'        => $code_challenge,
            'code_challenge_method' => 'S256',
        );

        $endpoints = $this->get_endpoints();
        return add_query_arg(array_map('rawurlencode', $args), $endpoints['authorize']);
    }

    /**
     * Обменять authorization_code на access_token.
     *
     * VK ID — public client; секрет опционален. Если секрет задан — добавляется
     * Authorization: Basic. device_id обязателен (приходит из callback query).
     *
     * @param array<string, mixed> $extra  Ожидается ключ 'device_id'.
     * @return array{access_token:string, refresh_token:?string, expires_in:int, scope:string, extra:array<string,mixed>}
     */
    public function exchange_code( string $code, string $code_verifier, string $redirect_uri, array $extra = array() ): array {
        $client_id = $this->get_client_id();
        if ($client_id === '') {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $this->get_id(),
                'stage'    => 'exchange_code',
                'error'    => 'missing_client_id',
            ));
            throw new \RuntimeException('VK ID provider: client_id is not configured.');
        }

        $device_id = isset($extra['device_id']) ? (string) $extra['device_id'] : '';
        if ($device_id === '') {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $this->get_id(),
                'stage'    => 'exchange_code',
                'error'    => 'missing_device_id',
            ));
            throw new \RuntimeException('VK ID callback missing device_id');
        }

        if ($code_verifier === '') {
            throw new \RuntimeException('VK ID exchange_code: empty code_verifier.');
        }

        $state_echo = isset($extra['state']) ? (string) $extra['state'] : '';

        $body = array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => $code_verifier,
            'client_id'     => $client_id,
            'device_id'     => $device_id,
        );
        if ($redirect_uri !== '') {
            $body['redirect_uri'] = $redirect_uri;
        }
        if ($state_echo !== '') {
            $body['state'] = $state_echo;
        }

        $headers = array(
            'Content-Type' => 'application/x-www-form-urlencoded',
        );

        // Опциональный Basic Auth, если сконфигурирован секрет (VK ID допускает
        // confidential client, но по умолчанию public client + PKCE).
        $client_secret = $this->get_client_secret();
        if ($client_secret !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($client_id . ':' . $client_secret);
        }

        $endpoints = $this->get_endpoints();
        $response  = $this->http_post($endpoints['token'], $body, $headers);

        if ($response['error'] !== null) {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider'        => $this->get_id(),
                'stage'           => 'exchange_code',
                'transport_error' => $response['error'],
            ));
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Server-side exception, caught by REST router and not rendered as HTML.
            throw new \RuntimeException('VK ID token request failed: ' . $response['error']);
        }

        $data = $response['body'];

        if ($response['status'] < 200 || $response['status'] >= 300 || empty($data['access_token'])) {
            $err_raw = isset($data['error']) ? (string) $data['error'] : 'http_' . $response['status'];
            $err     = sanitize_key($err_raw);
            if ($err === '') {
                $err = 'http_' . (string) $response['status'];
            }
            $desc_raw  = isset($data['error_description']) ? (string) $data['error_description'] : '';
            $desc_safe = wp_strip_all_tags($desc_raw);
            $desc_safe = function_exists('mb_substr') ? mb_substr($desc_safe, 0, 120) : substr($desc_safe, 0, 120);

            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $this->get_id(),
                'stage'    => 'exchange_code',
                'status'   => $response['status'],
                'error'    => $err,
                'desc'     => $desc_safe,
            ));

            $message = sprintf(
                'VK ID token exchange failed (%s): %s',
                (string) $response['status'],
                $err
            );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Server-side exception, caught by REST router and not rendered as HTML.
            throw new \RuntimeException($message);
        }

        $user_id = isset($data['user_id']) ? (string) $data['user_id'] : '';

        return array(
            'access_token'  => (string) $data['access_token'],
            'refresh_token' => isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            'expires_in'    => isset($data['expires_in']) ? (int) $data['expires_in'] : 0,
            'scope'         => isset($data['scope']) ? (string) $data['scope'] : '',
            'extra'         => array(
                'user_id'  => $user_id !== '' ? $user_id : null,
                'id_token' => isset($data['id_token']) ? (string) $data['id_token'] : null,
            ),
        );
    }

    /**
     * Запросить профиль пользователя.
     *
     * @param array<string, mixed> $extra
     * @return array{external_id:string, email:?string, name:?string, first_name:?string, last_name:?string, avatar:?string, phone:?string, sub_provider:?string}
     */
    public function fetch_user_info( string $access_token, array $extra = array() ): array {
        if ($access_token === '') {
            throw new \RuntimeException('VK ID fetch_user_info: empty access_token.');
        }

        $client_id = $this->get_client_id();
        if ($client_id === '') {
            throw new \RuntimeException('VK ID fetch_user_info: client_id is not configured.');
        }

        $body = array(
            'access_token' => $access_token,
            'client_id'    => $client_id,
        );

        $headers = array(
            'Content-Type' => 'application/x-www-form-urlencoded',
        );

        $endpoints = $this->get_endpoints();
        $response  = $this->http_post($endpoints['user_info'], $body, $headers);

        if ($response['error'] !== null) {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider'        => $this->get_id(),
                'stage'           => 'fetch_user_info',
                'transport_error' => $response['error'],
            ));
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Server-side exception, caught by REST router and not rendered as HTML.
            throw new \RuntimeException('VK ID user_info request failed: ' . $response['error']);
        }

        $data = $response['body'];
        $user = isset($data['user']) && is_array($data['user']) ? $data['user'] : array();

        if ($response['status'] < 200 || $response['status'] >= 300 || empty($user)) {
            $err_raw = isset($data['error']) ? (string) $data['error'] : 'http_' . $response['status'];
            $err     = sanitize_key($err_raw);
            if ($err === '') {
                $err = 'http_' . (string) $response['status'];
            }
            $desc_raw  = isset($data['error_description']) ? (string) $data['error_description'] : '';
            $desc_safe = wp_strip_all_tags($desc_raw);
            $desc_safe = function_exists('mb_substr') ? mb_substr($desc_safe, 0, 120) : substr($desc_safe, 0, 120);

            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $this->get_id(),
                'stage'    => 'fetch_user_info',
                'status'   => $response['status'],
                'error'    => $err,
                'desc'     => $desc_safe,
            ));

            $message = sprintf(
                'VK ID user_info failed (%s): %s',
                (string) $response['status'],
                $err
            );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Server-side exception, caught by REST router and not rendered as HTML.
            throw new \RuntimeException($message);
        }

        $external_id = (string) ( $user['user_id'] ?? '' );
        if ($external_id === '') {
            // Попробуем достать user_id из extra (из token-response).
            if (!empty($extra['user_id'])) {
                $external_id = (string) $extra['user_id'];
            }
        }
        if ($external_id === '') {
            throw new \RuntimeException('VK ID user info missing user_id');
        }

        $first_name = isset($user['first_name']) ? (string) $user['first_name'] : '';
        $last_name  = isset($user['last_name']) ? (string) $user['last_name'] : '';

        $full = trim($first_name . ' ' . $last_name);

        return array(
            'external_id'  => $external_id,
            'email'        => !empty($user['email']) ? (string) $user['email'] : null,
            'name'         => $full !== '' ? $full : null,
            'first_name'   => $first_name !== '' ? $first_name : null,
            'last_name'    => $last_name !== '' ? $last_name : null,
            'avatar'       => !empty($user['avatar']) ? (string) $user['avatar'] : null,
            'phone'        => !empty($user['phone']) ? (string) $user['phone'] : null,
            // VK не возвращает, через какой сервис (vk/ok/mail) произошёл вход.
            // Оставляем null до выяснения в реальном тесте виджета.
            'sub_provider' => null,
        );
    }
}
