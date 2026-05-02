<?php

/**
 * Generic HTTP-клиент для CPA-сетей.
 *
 * Auth-агностичный: по api_auth_type сети собирает headers через
 *   - oauth2_client_credentials → Cashback_OAuth2_Client_Credentials_Helper.
 *   - api_key                   → X-API-Key header.
 *   - bearer_token              → Authorization: Bearer <token>.
 *
 * Делает GET-запрос с SSRF-guard'ом.
 *
 * @package CashbackPlugin
 * @since   7.2.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Network_Http_Client {

    /** @var array<string,Cashback_OAuth2_Client_Credentials_Helper> Helpers by cache_namespace. */
    private array $oauth_helpers = array();

    /**
     * Выполнить GET-запрос с auth.
     *
     * @param string             $url
     * @param array<string,mixed> $auth_config Ключи:
     *   - auth_type        (string) 'oauth2_client_credentials'|'api_key'|'bearer_token'
     *   - credentials      (array)  client_id/client_secret/scope или api_key или access_token
     *   - token_url        (?string) Полный URL token-endpoint'а (для OAuth2).
     *   - cache_namespace  (?string) Префикс cache key (для OAuth2). По умолчанию 'cashback_oauth2_token'.
     *   - extra_headers    (?array<string,string>) Дополнительные заголовки.
     * @return array|WP_Error WP-style HTTP response от wp_remote_get.
     */
    public function get( string $url, array $auth_config ): array|WP_Error {
        $check = Cashback_Outbound_HTTP_Guard::validate_url( $url );
        if ( is_wp_error( $check ) ) {
            return $check;
        }

        $headers = $this->build_headers( $auth_config );
        if ( is_wp_error( $headers ) ) {
            return $headers;
        }

        return wp_remote_get( $url, array(
            // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- CPA-сетевой запрос; 30s покрывает редкие сетевые задержки.
            'timeout'            => 30,
            'headers'            => $headers,
            'sslverify'          => true,
            'reject_unsafe_urls' => true,
        ) );
    }

    /**
     * Инвалидировать OAuth2 токен (используется адаптерами при 401/insufficient_scope).
     */
    public function invalidate_oauth_token( string $client_id, string $cache_namespace ): void {
        $this->get_oauth_helper( $cache_namespace )->invalidate_token( $client_id );
    }

    /**
     * @param array<string,mixed> $auth_config
     * @return array<string,string>|WP_Error
     */
    private function build_headers( array $auth_config ): array|WP_Error {
        $auth_type   = (string) ( $auth_config['auth_type'] ?? '' );
        $credentials = (array) ( $auth_config['credentials'] ?? array() );
        $extra       = (array) ( $auth_config['extra_headers'] ?? array() );

        $headers = array(
            'Accept' => 'application/json',
        );

        switch ( $auth_type ) {
            case 'oauth2_client_credentials':
                $client_id     = (string) ( $credentials['client_id'] ?? '' );
                $client_secret = (string) ( $credentials['client_secret'] ?? '' );
                $scope         = (string) ( $credentials['scope'] ?? '' );
                $token_url     = (string) ( $auth_config['token_url'] ?? '' );
                $cache_ns      = (string) ( $auth_config['cache_namespace'] ?? 'cashback_oauth2_token' );

                if ( $token_url === '' ) {
                    return new WP_Error( 'missing_token_url', 'OAuth2 requires token_url' );
                }

                $helper = $this->get_oauth_helper( $cache_ns );
                $token  = $helper->get_token( $token_url, $client_id, $client_secret, $scope );

                if ( $token === null ) {
                    return new WP_Error(
                        'oauth2_token_failed',
                        'OAuth2 token unavailable: ' . $helper->get_last_error()
                    );
                }
                $headers['Authorization'] = 'Bearer ' . $token;
                break;

            case 'api_key':
                $api_key = (string) ( $credentials['api_key'] ?? $credentials['key'] ?? '' );
                if ( $api_key === '' ) {
                    return new WP_Error( 'missing_api_key', 'api_key credential is empty' );
                }
                $headers['X-API-Key'] = $api_key;
                break;

            case 'bearer_token':
                $access_token = (string) ( $credentials['access_token'] ?? $credentials['token'] ?? '' );
                if ( $access_token === '' ) {
                    return new WP_Error( 'missing_access_token', 'access_token credential is empty' );
                }
                $headers['Authorization'] = 'Bearer ' . $access_token;
                break;

            default:
                return new WP_Error( 'unsupported_auth_type', 'Unsupported auth_type: ' . $auth_type );
        }

        foreach ( $extra as $key => $value ) {
            $headers[ (string) $key ] = (string) $value;
        }

        return $headers;
    }

    private function get_oauth_helper( string $cache_namespace ): Cashback_OAuth2_Client_Credentials_Helper {
        if ( ! isset( $this->oauth_helpers[ $cache_namespace ] ) ) {
            $this->oauth_helpers[ $cache_namespace ] = new Cashback_OAuth2_Client_Credentials_Helper( $cache_namespace );
        }
        return $this->oauth_helpers[ $cache_namespace ];
    }
}
