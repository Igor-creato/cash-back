<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Price_Comparison_Client {

    public const OPTION_ENABLED     = 'cashback_price_compare_enabled';
    public const OPTION_BASE_URL    = 'cashback_price_compare_base_url';
    public const OPTION_HMAC_SECRET = 'cashback_price_compare_hmac_secret';
    public const OPTION_TIMEOUT     = 'cashback_price_compare_timeout';

    public function search( array $payload ): array|WP_Error {
        return $this->request_json('POST', '/api/v1/search', $payload);
    }

    public function list_stores(): array|WP_Error {
        return $this->request_json('GET', '/api/v1/stores');
    }

    public function create_store( array $payload ): array|WP_Error {
        return $this->request_json('POST', '/api/v1/stores', $payload);
    }

    public function update_store( int $store_id, array $payload ): array|WP_Error {
        return $this->request_json('PATCH', '/api/v1/stores/' . absint($store_id), $payload);
    }

    public function deactivate_store( int $store_id ): array|WP_Error {
        return $this->request_json('DELETE', '/api/v1/stores/' . absint($store_id));
    }

    private function request_json( string $method, string $path, ?array $payload = null ): array|WP_Error {
        if ((int) get_option(self::OPTION_ENABLED, 0) !== 1) {
            return new WP_Error(
                'price_compare_disabled',
                'Сервис сравнения цен временно отключён.',
                array( 'status' => 503 )
            );
        }

        $base_url = rtrim((string) get_option(self::OPTION_BASE_URL, ''), '/');
        $secret   = (string) get_option(self::OPTION_HMAC_SECRET, '');
        if ($base_url === '' || $secret === '') {
            return new WP_Error(
                'price_compare_not_configured',
                'Сервис сравнения цен не настроен.',
                array( 'status' => 503 )
            );
        }

        $body = $payload === null ? '' : wp_json_encode($payload);
        if (!is_string($body)) {
            return new WP_Error(
                'price_compare_bad_payload',
                'Ошибка поиска.',
                array( 'status' => 400 )
            );
        }

        $response = $this->send($method, $base_url . $path, $path, $body, $secret);

        if ($response instanceof WP_Error) {
            return new WP_Error(
                'price_compare_backend_unavailable',
                'Ошибка поиска. Сервис временно недоступен.',
                array( 'status' => 503 )
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data   = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return new WP_Error(
                'price_compare_bad_response',
                'Ошибка поиска. Получен некорректный ответ сервиса.',
                array( 'status' => 502 )
            );
        }

        if ($status >= 400) {
            return new WP_Error(
                (string) ( $data['error_code'] ?? 'price_compare_backend_error' ),
                (string) ( $data['message'] ?? 'Ошибка поиска.' ),
                array( 'status' => $status )
            );
        }

        return $data;
    }

    private function send( string $method, string $url, string $path, string $body, string $secret ): array|WP_Error {
        $args = array(
            'timeout' => $this->timeout(),
            'headers' => $this->signed_headers($method, $path, $body, $secret),
        );
        if ($body !== '') {
            $args['body'] = $body;
        }

        if (function_exists('wp_remote_request')) {
            $args['method'] = $method;
            return wp_remote_request($url, $args);
        }

        if ($method === 'GET') {
            return wp_remote_get($url, $args);
        }
        if ($method !== 'POST') {
            $args['method'] = $method;
        }
        return wp_remote_post($url, $args);
    }

    private function timeout(): int {
        $timeout = (int) get_option(self::OPTION_TIMEOUT, 5);
        if ($timeout < 1) {
            return 1;
        }
        if ($timeout > 15) {
            return 15;
        }
        return $timeout;
    }

    private function signed_headers( string $method, string $path, string $body, string $secret ): array {
        $timestamp = (string) time();
        $request_id = function_exists('wp_generate_uuid4')
            ? wp_generate_uuid4()
            : bin2hex(random_bytes(16));
        $body_hash = hash('sha256', $body);
        $message   = implode("\n", array( $method, $path, $timestamp, $request_id, $body_hash ));

        return array(
            'Content-Type'        => 'application/json',
            'X-Request-Id'        => $request_id,
            'X-Request-Timestamp' => $timestamp,
            'X-Body-SHA256'       => $body_hash,
            'X-Signature'         => hash_hmac('sha256', $message, $secret),
        );
    }
}
