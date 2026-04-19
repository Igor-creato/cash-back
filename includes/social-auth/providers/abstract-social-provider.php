<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Базовый класс для OAuth-провайдеров соц-авторизации.
 *
 * Общая инфраструктура:
 *  - HTTP-клиент на wp_remote_{get,post}
 *  - чтение/запись провайдер-опций (enabled, client_id, client_secret_encrypted, …)
 *  - расшифровка client_secret через Cashback_Encryption
 *
 * Наследники (Yandex, VKID) реализуют authorize/exchange/fetch_user_info.
 */
abstract class Cashback_Social_Provider_Abstract implements Cashback_Social_Provider_Interface {

    /** Префикс опций WordPress для хранения настроек провайдеров. */
    protected const OPTION_PREFIX = 'cashback_social_provider_';

    /**
     * Возвращает полный ключ опций: cashback_social_provider_{id}.
     */
    public function get_option_key(): string {
        return self::OPTION_PREFIX . $this->get_id();
    }

    /**
     * @return array<string, mixed>
     */
    protected function get_settings(): array {
        $opt = get_option($this->get_option_key(), array());
        return is_array($opt) ? $opt : array();
    }

    public function is_enabled(): bool {
        // Глобальный тумблер модуля.
        if ((int) get_option('cashback_social_enabled', 0) !== 1) {
            return false;
        }
        $s = $this->get_settings();
        return !empty($s['enabled']) && !empty($s['client_id']) && !empty($s['client_secret_encrypted']);
    }

    public function get_client_id(): string {
        $s = $this->get_settings();
        return isset($s['client_id']) ? (string) $s['client_id'] : '';
    }

    public function get_client_secret(): string {
        $s   = $this->get_settings();
        $enc = isset($s['client_secret_encrypted']) ? (string) $s['client_secret_encrypted'] : '';
        if ($enc === '') {
            return '';
        }
        if (!class_exists('Cashback_Encryption') || !Cashback_Encryption::is_configured()) {
            return '';
        }
        try {
            return Cashback_Encryption::decrypt($enc);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[cashback-social] provider ' . $this->get_id() . ' secret decrypt failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Запрошен ли phone scope.
     */
    public function is_phone_scope_enabled(): bool {
        $s = $this->get_settings();
        return !empty($s['phone_scope']);
    }

    /**
     * URL callback, зарегистрированного в REST API (для dev-кабинетов провайдеров и UI).
     */
    public function get_redirect_uri(): string {
        return home_url('/wp-json/cashback/v1/social/' . $this->get_id() . '/callback');
    }

    // =========================================================================
    // HTTP helpers
    // =========================================================================

    /**
     * POST-запрос (x-www-form-urlencoded по умолчанию).
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status:int, body:array<string, mixed>, raw:string, error:?string}
     */
    protected function http_post( string $url, array $body, array $headers = array() ): array {
        $args = array(
            'method'  => 'POST',
            // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- OAuth token exchange against external provider; 15s покрывает редкие сетевые задержки.
            'timeout' => 15,
            'headers' => array_merge(
                array( 'Accept' => 'application/json' ),
                $headers
            ),
            'body'    => $body,
        );

        $response = wp_remote_post($url, $args);
        return $this->parse_http_response($response);
    }

    /**
     * GET-запрос.
     *
     * @param array<string, string> $headers
     * @return array{status:int, body:array<string, mixed>, raw:string, error:?string}
     */
    protected function http_get( string $url, array $headers = array() ): array {
        $args = array(
            'method'  => 'GET',
            // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- OAuth user_info against external provider; 15s покрывает редкие сетевые задержки.
            'timeout' => 15,
            'headers' => array_merge(
                array( 'Accept' => 'application/json' ),
                $headers
            ),
        );

        $response = wp_remote_get($url, $args);
        return $this->parse_http_response($response);
    }

    /**
     * @param mixed $response
     * @return array{status:int, body:array<string, mixed>, raw:string, error:?string}
     */
    private function parse_http_response( $response ): array {
        if (is_wp_error($response)) {
            return array(
                'status' => 0,
                'body'   => array(),
                'raw'    => '',
                'error'  => $response->get_error_message(),
            );
        }

        $status  = (int) wp_remote_retrieve_response_code($response);
        $raw     = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        $body    = is_array($decoded) ? $decoded : array();

        return array(
            'status' => $status,
            'body'   => $body,
            'raw'    => $raw,
            'error'  => null,
        );
    }
}
