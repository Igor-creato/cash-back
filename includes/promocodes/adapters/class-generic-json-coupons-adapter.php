<?php

/**
 * Generic JSON-адаптер купонов CPA-сети.
 *
 * Работает для любой сети с JSON REST API через admin-конфиг
 * (api_coupons_endpoint, api_coupons_field_map, api_coupons_species_map,
 * api_coupons_pagination). Никакого нового кода для подключения новой
 * JSON-сети не требуется — админ просто заполняет форму «Настройки API».
 *
 * Кодовые адаптеры (escape hatch для XML/CSV/non-standard) реализуют
 * Cashback_Coupons_Adapter_Interface отдельно и регистрируются в registry
 * с приоритетом выше generic.
 *
 * Auth: использует Cashback_Network_Http_Client который умеет
 * oauth2_client_credentials, api_key, bearer_token.
 *
 * Pagination:
 *   - offset_limit (Admitad, CityAds): {limit}={LIMIT}&{offset}={OFFSET}.
 *   - page (некоторые сети): page={N} с auto-increment.
 *   - none: один запрос.
 *
 * Hard-cap: 1000 купонов / 50 страниц на одну fetch — защита от runaway.
 *
 * @package CashbackPlugin
 * @since   7.2.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Generic_Json_Coupons_Adapter implements Cashback_Coupons_Adapter_Interface {

    private const PAGE_SIZE      = 50;
    private const MAX_PAGES      = 50;
    private const MAX_COUPONS    = 1000;

    /** @var object|array<string,mixed> */
    private mixed $network_config;

    private object $api_client;
    private object $http;
    private Cashback_Coupons_Field_Mapper $mapper;

    /**
     * @param object|array<string,mixed>          $network_config Строка из cashback_affiliate_networks.
     * @param object                              $api_client     Cashback_API_Client (или stub в тестах) с get_credentials($id):array.
     * @param object                              $http           Cashback_Network_Http_Client (или stub).
     */
    public function __construct(
        mixed $network_config,
        object $api_client,
        object $http,
        Cashback_Coupons_Field_Mapper $mapper
    ) {
        $this->network_config = $network_config;
        $this->api_client     = $api_client;
        $this->http           = $http;
        $this->mapper         = $mapper;
    }

    public function get_network_slug(): string {
        return (string) $this->config_field( 'slug', '' );
    }

    public function supports_campaign_filter(): bool {
        $endpoint = (string) $this->config_field( 'api_coupons_endpoint', '' );
        return strpos( $endpoint, '{advcampaign_id}' ) !== false;
    }

    public function get_required_scope(): ?string {
        $auth_type = (string) $this->config_field( 'api_auth_type', '' );
        if ( $auth_type !== 'oauth2_client_credentials' ) {
            return null;
        }
        // Generic не знает scope сети — это в credentials. Документационная константа.
        return 'coupons_for_website';
    }

    public function fetch_coupons( string $advcampaign_id, array $context = array() ): array {
        $endpoint_template = (string) $this->config_field( 'api_coupons_endpoint', '' );
        if ( $endpoint_template === '' ) {
            return array();
        }

        $base_url = rtrim( (string) $this->config_field( 'api_base_url', '' ), '/' );
        $field_map = $this->decode_json_config( 'api_coupons_field_map' );
        $species_map = $this->decode_json_config( 'api_coupons_species_map' );

        $network_id = (int) $this->config_field( 'id', 0 );
        $credentials = (array) $this->api_client->get_credentials( $network_id );
        $auth_config = $this->build_auth_config( $credentials );

        $pagination = (string) $this->config_field( 'api_coupons_pagination', 'offset_limit' );

        $coupons     = array();
        $offset      = 0;
        $page        = 1;
        $max_pages   = ( $pagination === 'none' ) ? 1 : self::MAX_PAGES;

        for ( $iter = 0; $iter < $max_pages; $iter++ ) {
            $url = $this->build_paginated_url(
                $base_url,
                $endpoint_template,
                $advcampaign_id,
                $pagination,
                $offset,
                $page,
                $credentials
            );

            $response = $this->http->get( $url, $auth_config );

            if ( is_wp_error( $response ) ) {
                $this->log_warning( 'http_error', $response->get_error_message() );
                break;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );

            if ( $code === 401 || ( $code === 403 && str_contains( (string) $body, 'insufficient_scope' ) ) ) {
                $client_id = (string) ( $credentials['client_id'] ?? '' );
                if ( $client_id !== '' ) {
                    $cache_ns = (string) ( $auth_config['cache_namespace'] ?? 'cashback_oauth2_token' );
                    $this->http->invalidate_oauth_token( $client_id, $cache_ns );
                }
                $this->log_warning( 'auth_failed', 'HTTP ' . $code . ' from coupons endpoint' );
                return array();
            }

            if ( $code !== 200 ) {
                $this->log_warning( 'http_status', 'HTTP ' . $code );
                break;
            }

            $decoded = json_decode( (string) $body, true );
            $batch   = $this->extract_results( $decoded );

            if ( empty( $batch ) ) {
                break;
            }

            foreach ( $batch as $raw ) {
                if ( ! is_array( $raw ) ) {
                    continue;
                }

                // Active-only filter: если в API есть status — пропускаем не-active.
                if ( isset( $raw['status'] ) ) {
                    $status = strtolower( (string) $raw['status'] );
                    if ( $status !== 'active' && $status !== '' ) {
                        continue;
                    }
                }

                try {
                    $mapped = $this->mapper->map( $raw, $field_map, $species_map );
                    $coupons[] = Cashback_Coupon_DTO::from_array( $mapped );
                } catch ( \Throwable $e ) {
                    $this->log_warning( 'dto_validation_failed', $e->getMessage() );
                    continue;
                }

                if ( count( $coupons ) >= self::MAX_COUPONS ) {
                    return array_slice( $coupons, 0, self::MAX_COUPONS );
                }
            }

            if ( count( $batch ) < self::PAGE_SIZE ) {
                // Last page (меньше limit'а).
                break;
            }

            $offset += self::PAGE_SIZE;
            ++$page;
        }

        return $coupons;
    }

    /**
     * Достаёт results из ответа: API могут отдавать
     *   {results: [...]} (Admitad), {data: [...]} (CityAds), [...] (raw array).
     *
     * @return array<int,mixed>
     */
    private function extract_results( mixed $decoded ): array {
        if ( ! is_array( $decoded ) ) {
            return array();
        }
        if ( isset( $decoded['results'] ) && is_array( $decoded['results'] ) ) {
            return $decoded['results'];
        }
        if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
            return $decoded['data'];
        }
        if ( array_is_list( $decoded ) ) {
            return $decoded;
        }
        return array();
    }

    private function build_paginated_url(
        string $base_url,
        string $endpoint_template,
        string $advcampaign_id,
        string $pagination,
        int $offset,
        int $page,
        array $credentials
    ): string {
        $url = $endpoint_template;

        if ( ! preg_match( '#^https?://#i', $url ) ) {
            $url = $base_url . '/' . ltrim( $url, '/' );
        }

        $replacements = array(
            '{website_id}'     => (string) $this->config_field( 'api_website_id', '' ),
            '{advcampaign_id}' => $advcampaign_id,
            '{limit}'          => (string) self::PAGE_SIZE,
            '{offset}'         => (string) $offset,
            '{page}'           => (string) $page,
            '{api_key}'        => (string) ( $credentials['api_key'] ?? $credentials['key'] ?? '' ),
        );

        return strtr( $url, $replacements );
    }

    /**
     * @param array<string,mixed> $credentials
     * @return array<string,mixed>
     */
    private function build_auth_config( array $credentials ): array {
        $auth_type = (string) $this->config_field( 'api_auth_type', 'oauth2_client_credentials' );
        $base_url  = rtrim( (string) $this->config_field( 'api_base_url', '' ), '/' );
        $token_ep  = (string) $this->config_field( 'api_token_endpoint', '' );

        $token_url = '';
        if ( $token_ep !== '' ) {
            $token_url = preg_match( '#^https?://#i', $token_ep ) ? $token_ep : $base_url . '/' . ltrim( $token_ep, '/' );
        }

        $cache_ns = 'cashback_oauth2_' . strtolower( (string) $this->config_field( 'slug', 'network' ) );

        return array(
            'auth_type'       => $auth_type,
            'credentials'     => $credentials,
            'token_url'       => $token_url,
            'cache_namespace' => $cache_ns,
        );
    }

    /**
     * @return array<string,string>
     */
    private function decode_json_config( string $field ): array {
        $raw = (string) $this->config_field( $field, '' );
        if ( $raw === '' ) {
            return array();
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    private function config_field( string $field, mixed $fallback ): mixed {
        if ( is_object( $this->network_config ) ) {
            return $this->network_config->{$field} ?? $fallback;
        }
        if ( is_array( $this->network_config ) ) {
            return $this->network_config[ $field ] ?? $fallback;
        }
        return $fallback;
    }

    private function log_warning( string $code, string $message ): void {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log( '[Cashback Coupons] ' . $this->get_network_slug() . ' ' . $code . ': ' . $message );
    }
}
