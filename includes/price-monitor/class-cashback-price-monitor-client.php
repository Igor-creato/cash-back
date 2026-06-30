<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Price_Monitor_Client {

    public const OPTION_BACKEND_URL = 'cashback_price_monitor_backend_url';
    public const OPTION_SECRET      = 'cashback_price_monitor_backend_secret';
    public const OPTION_ENABLED     = 'cashback_price_monitor_enabled';

    /** @var callable|null */
    private $transport;

    /** @var callable|null */
    private $time_source;

    /** @var callable|null */
    private $request_id_source;

    public function __construct(
        ?callable $transport = null,
        ?callable $time_source = null,
        ?callable $request_id_source = null
    ) {
        $this->transport         = $transport;
        $this->time_source       = $time_source;
        $this->request_id_source = $request_id_source;
    }

    public function request(
        string $method,
        string $path,
        array $payload = array(),
        ?string $idempotency_key = null
    ): array|WP_Error {
        if (!$this->is_enabled()) {
            return new WP_Error(
                'price_monitor_unavailable',
                'Price monitor backend is disabled.',
                array( 'status' => 503 )
            );
        }

        $base_url = $this->backend_url();
        $secret   = $this->secret();
        if ($base_url === '' || $secret === '') {
            return new WP_Error(
                'price_monitor_not_configured',
                'Price monitor backend is not configured.',
                array( 'status' => 503 )
            );
        }

        $method          = strtoupper($method);
        $request_path    = $this->normalize_path($path);
        $body            = '';
        $request_target  = $request_path;
        $request_url     = rtrim($base_url, '/') . $request_path;
        $request_id      = $this->request_id();
        $timestamp       = $this->timestamp();

        if ($method === 'GET' && $payload !== array()) {
            $query          = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
            $request_target = $request_path . '?' . $query;
            $request_url    = $request_url . '?' . $query;
        } elseif ($payload !== array()) {
            $encoded = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                return new WP_Error(
                    'price_monitor_encode_failed',
                    'Failed to encode price monitor payload.',
                    array( 'status' => 500, 'request_id' => $request_id )
                );
            }
            $body = $encoded;
        }

        $body_sha256 = hash('sha256', $body);
        $headers     = array(
            'Accept'              => 'application/json',
            'X-Request-Id'        => $request_id,
            'X-Request-Timestamp' => $timestamp,
            'X-Body-SHA256'       => $body_sha256,
            'X-Signature'         => hash_hmac(
                'sha256',
                $method . "\n" . $request_target . "\n" . $timestamp . "\n" . $request_id . "\n" . $body_sha256,
                $secret
            ),
        );

        $args = array(
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 3,
        );

        if ($body !== '') {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = $body;
        }

        if ($idempotency_key !== null && $idempotency_key !== '') {
            $args['headers']['Idempotency-Key'] = $idempotency_key;
        }

        $response = $this->send_request($method, $request_url, $args);
        if ($response instanceof WP_Error) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = wp_remote_retrieve_body($response);
        $data   = $raw !== '' ? json_decode($raw, true) : array();
        $data   = is_array($data) ? $data : array();

        if ($status < 200 || $status >= 300) {
            $error_code    = 'price_monitor_backend_error';
            $error_message = 'Price monitor backend request failed.';

            if (isset($data['error']['code']) && is_string($data['error']['code']) && $data['error']['code'] !== '') {
                $error_code = $data['error']['code'];
            }
            if (isset($data['error']['message']) && is_string($data['error']['message']) && $data['error']['message'] !== '') {
                $error_message = $data['error']['message'];
            }

            return new WP_Error(
                $error_code,
                $error_message,
                array(
                    'status'     => $status > 0 ? $status : 502,
                    'request_id' => $request_id,
                )
            );
        }

        return $data;
    }

    public function redacted_settings(): array {
        $secret = $this->secret();

        return array(
            'backend_url'    => $this->backend_url(),
            'backend_secret' => $secret === '' ? '' : '[redacted]',
            'enabled'        => $this->is_enabled(),
        );
    }

    private function send_request( string $method, string $url, array $args ): array|WP_Error {
        if (is_callable($this->transport)) {
            return call_user_func($this->transport, $method, $url, $args);
        }

        if (function_exists('wp_remote_request')) {
            return wp_remote_request($url, $args);
        }

        return match ($method) {
            'GET' => wp_remote_get($url, $args),
            'POST' => wp_remote_post($url, $args),
            default => new WP_Error(
                'price_monitor_transport_unavailable',
                'HTTP transport does not support this method in the current environment.',
                array( 'status' => 500 )
            ),
        };
    }

    private function backend_url(): string {
        return rtrim(trim((string) get_option(self::OPTION_BACKEND_URL, '')), '/');
    }

    private function secret(): string {
        return trim((string) get_option(self::OPTION_SECRET, ''));
    }

    private function is_enabled(): bool {
        return (int) get_option(self::OPTION_ENABLED, 0) === 1;
    }

    private function normalize_path( string $path ): string {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '/';
        }

        return '/' . ltrim($trimmed, '/');
    }

    private function request_id(): string {
        if (is_callable($this->request_id_source)) {
            return (string) call_user_func($this->request_id_source);
        }

        if (function_exists('cashback_generate_uuid7')) {
            return cashback_generate_uuid7(false);
        }

        return str_replace('-', '', wp_generate_uuid4());
    }

    private function timestamp(): string {
        if (is_callable($this->time_source)) {
            return (string) call_user_func($this->time_source);
        }

        return (string) time();
    }
}
