<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Yandex ID OAuth-провайдер.
 *
 * Реализует OAuth 2.0 Authorization Code Flow с PKCE (S256).
 *
 * Endpoints (актуальны на 2026-04-19):
 *  - authorize: https://oauth.yandex.ru/authorize
 *  - token    : https://oauth.yandex.ru/token
 *  - user_info: https://login.yandex.ru/info
 *
 * Обмен токена — HTTP Basic Auth с client_id:client_secret в заголовке.
 * User info — Authorization: OAuth {access_token}.
 */
class Cashback_Social_Provider_Yandex extends Cashback_Social_Provider_Abstract {

    public const ID = 'yandex';

    private const AUTHORIZE_URL = 'https://oauth.yandex.ru/authorize';
    private const TOKEN_URL     = 'https://oauth.yandex.ru/token';
    private const USERINFO_URL  = 'https://login.yandex.ru/info';

    private const AVATAR_BASE_URL = 'https://avatars.yandex.net/get-yapic/';
    private const AVATAR_SIZE     = 'islands-200';

    public function get_id(): string {
        return self::ID;
    }

    /**
     * Собрать authorize URL.
     *
     * @param array<string, mixed> $state
     */
    public function get_authorize_url( array $state ): string {
        $scopes = array( 'login:info', 'login:email', 'login:avatar' );
        if (!empty($state['scope_phone'])) {
            $scopes[] = 'login:phone';
        }

        $redirect_uri = isset($state['redirect_uri']) && $state['redirect_uri'] !== ''
            ? (string) $state['redirect_uri']
            : $this->get_redirect_uri();

        $args = array(
            'response_type' => 'code',
            'client_id'     => $this->get_client_id(),
            'redirect_uri'  => $redirect_uri,
            'state'         => isset($state['state']) ? (string) $state['state'] : '',
            'scope'         => implode(' ', $scopes),
            'force_confirm' => 'yes',
        );

        if (!empty($state['code_challenge'])) {
            $args['code_challenge']        = (string) $state['code_challenge'];
            $args['code_challenge_method'] = 'S256';
        }

        return add_query_arg(array_map('rawurlencode', $args), self::AUTHORIZE_URL);
    }

    /**
     * Обменять authorization_code на access_token.
     *
     * @param array<string, mixed> $extra
     * @return array{access_token:string, refresh_token:?string, expires_in:int, scope:string, extra:array<string,mixed>}
     */
    public function exchange_code( string $code, string $code_verifier, string $redirect_uri, array $extra = array() ): array {
        unset($extra);

        $client_id     = $this->get_client_id();
        $client_secret = $this->get_client_secret();

        if ($client_id === '' || $client_secret === '') {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $this->get_id(),
                'stage'    => 'exchange_code',
                'error'    => 'missing_credentials',
            ));
            throw new \RuntimeException('Yandex provider: client credentials are not configured.');
        }

        $body = array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => $code_verifier,
        );
        if ($redirect_uri !== '') {
            $body['redirect_uri'] = $redirect_uri;
        }

        $headers = array(
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
        );

        $response = $this->http_post(self::TOKEN_URL, $body, $headers);

        if ($response['error'] !== null) {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider'        => $this->get_id(),
                'stage'           => 'exchange_code',
                'transport_error' => $response['error'],
            ));
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Server-side exception, caught by REST router and not rendered as HTML.
            throw new \RuntimeException('Yandex token request failed: ' . $response['error']);
        }

        $data = $response['body'];

        if ($response['status'] < 200 || $response['status'] >= 300 || empty($data['access_token'])) {
            $err  = isset($data['error']) ? (string) $data['error'] : 'http_' . $response['status'];
            $desc = isset($data['error_description']) ? (string) $data['error_description'] : '';

            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $this->get_id(),
                'stage'    => 'exchange_code',
                'status'   => $response['status'],
                'error'    => $err,
                'desc'     => $desc,
            ));

            $message = sprintf(
                'Yandex token exchange failed (%s): %s %s',
                (string) $response['status'],
                $err,
                $desc
            );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Server-side exception, caught by REST router and not rendered as HTML.
            throw new \RuntimeException($message);
        }

        return array(
            'access_token'  => (string) $data['access_token'],
            'refresh_token' => isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            'expires_in'    => isset($data['expires_in']) ? (int) $data['expires_in'] : 0,
            'scope'         => isset($data['scope']) ? (string) $data['scope'] : '',
            'extra'         => array(),
        );
    }

    /**
     * Загрузить профиль пользователя.
     *
     * @param array<string, mixed> $extra
     * @return array{external_id:string, email:?string, name:?string, first_name:?string, last_name:?string, avatar:?string, phone:?string, sub_provider:?string}
     */
    public function fetch_user_info( string $access_token, array $extra = array() ): array {
        unset($extra);

        if ($access_token === '') {
            throw new \RuntimeException('Yandex fetch_user_info: empty access_token.');
        }

        $url = add_query_arg(array( 'format' => 'json' ), self::USERINFO_URL);

        $headers = array(
            'Authorization' => 'OAuth ' . $access_token,
        );

        $response = $this->http_get($url, $headers);

        if ($response['error'] !== null) {
            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider'        => $this->get_id(),
                'stage'           => 'fetch_user_info',
                'transport_error' => $response['error'],
            ));
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Server-side exception, caught by REST router and not rendered as HTML.
            throw new \RuntimeException('Yandex user_info request failed: ' . $response['error']);
        }

        $data = $response['body'];

        if ($response['status'] < 200 || $response['status'] >= 300 || empty($data['id'])) {
            $err  = isset($data['error']) ? (string) $data['error'] : 'http_' . $response['status'];
            $desc = isset($data['error_description']) ? (string) $data['error_description'] : '';

            Cashback_Social_Auth_Audit::log(Cashback_Social_Auth_Audit::EVENT_CALLBACK_ERROR, array(
                'provider' => $this->get_id(),
                'stage'    => 'fetch_user_info',
                'status'   => $response['status'],
                'error'    => $err,
                'desc'     => $desc,
            ));

            $message = sprintf(
                'Yandex user_info failed (%s): %s %s',
                (string) $response['status'],
                $err,
                $desc
            );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Server-side exception, caught by REST router and not rendered as HTML.
            throw new \RuntimeException($message);
        }

        return $this->map_profile($data);
    }

    /**
     * Маппинг ответа /info на унифицированную структуру.
     *
     * @param array<string, mixed> $data
     * @return array{external_id:string, email:?string, name:?string, first_name:?string, last_name:?string, avatar:?string, phone:?string, sub_provider:?string}
     */
    private function map_profile( array $data ): array {
        $external_id = (string) ( $data['id'] ?? '' );

        // email: сначала default_email, затем первый из списка emails.
        $email = null;
        if (!empty($data['default_email'])) {
            $email = (string) $data['default_email'];
        } elseif (!empty($data['emails']) && is_array($data['emails'])) {
            $first = reset($data['emails']);
            if (is_string($first) && $first !== '') {
                $email = $first;
            }
        }

        // name: display_name → real_name → склейка first_name+last_name.
        $first_name = isset($data['first_name']) ? (string) $data['first_name'] : null;
        $last_name  = isset($data['last_name']) ? (string) $data['last_name'] : null;

        $name = null;
        if (!empty($data['display_name'])) {
            $name = (string) $data['display_name'];
        } elseif (!empty($data['real_name'])) {
            $name = (string) $data['real_name'];
        } else {
            $candidate = trim(( $first_name ?? '' ) . ' ' . ( $last_name ?? '' ));
            if ($candidate !== '') {
                $name = $candidate;
            }
        }

        // avatar: только если есть default_avatar_id и is_avatar_empty != true.
        $avatar          = null;
        $avatar_id       = isset($data['default_avatar_id']) ? (string) $data['default_avatar_id'] : '';
        $is_avatar_empty = !empty($data['is_avatar_empty']);
        if ($avatar_id !== '' && !$is_avatar_empty) {
            $avatar = self::AVATAR_BASE_URL . rawurlencode($avatar_id) . '/' . self::AVATAR_SIZE;
        }

        // phone: из default_phone.number (если выдан scope login:phone).
        $phone = null;
        if (isset($data['default_phone']) && is_array($data['default_phone']) && !empty($data['default_phone']['number'])) {
            $phone = (string) $data['default_phone']['number'];
        }

        return array(
            'external_id'  => $external_id,
            'email'        => $email,
            'name'         => $name,
            'first_name'   => $first_name !== '' ? $first_name : null,
            'last_name'    => $last_name !== '' ? $last_name : null,
            'avatar'       => $avatar,
            'phone'        => $phone,
            'sub_provider' => null,
        );
    }
}
