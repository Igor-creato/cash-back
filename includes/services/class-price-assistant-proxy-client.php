<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Cashback_Price_Assistant_Proxy_Client {

    public const OPTION_ENABLED     = 'price_monitor_enabled';
    public const OPTION_BASE_URL    = 'price_monitor_base_url';
    public const OPTION_SITE_ID     = 'price_monitor_site_id';
    public const OPTION_HMAC_SECRET = 'price_monitor_hmac_secret';

    private const TIMEOUT_SECONDS = 8;

    /** @var callable(): int */
    private $time_provider;

    public function __construct( ?callable $time_provider = null ) {
        $this->time_provider = $time_provider ?? static fn(): int => time();
    }

    public function request(
        string $method,
        string $path,
        ?array $payload = null,
        array $query = array(),
        ?string $idempotency_key = null
    ): array {
        $config_error = $this->config_error();
        if ($config_error !== null) {
            return $config_error;
        }

        $raw_body = $payload === null ? '' : (string) wp_json_encode($payload);
        $headers  = $this->auth_headers($raw_body);
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotency_key !== null && $idempotency_key !== '') {
            $headers['Idempotency-Key'] = $idempotency_key;
        }

        $response = wp_remote_request(
            $this->build_url($path, $query),
            array(
                'method'      => strtoupper($method),
                'headers'     => $headers,
                'body'        => $payload === null ? null : $raw_body,
                'timeout'     => self::TIMEOUT_SECONDS,
                'redirection' => 0,
                'blocking'    => true,
                'data_format' => 'body',
            )
        );

        if (is_wp_error($response)) {
            return $this->upstream_error();
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);
        $data   = array();
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (! is_array($decoded)) {
                return $this->upstream_error();
            }
            $data = $decoded;
        }

        if ($status < 200 || $status >= 300) {
            return $this->upstream_error();
        }

        return array(
            'ok'     => true,
            'status' => $status,
            'data'   => $data,
        );
    }

    public static function sanitize_enabled( $value ): int {
        return (int) (bool) $value;
    }

    public static function sanitize_base_url( $value ): string {
        $url    = is_scalar($value) ? trim((string) $value) : '';
        $url    = esc_url_raw($url);
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, array( 'http', 'https' ), true) ? rtrim($url, '/') : '';
    }

    public static function sanitize_site_id( $value ): string {
        $site_id = is_scalar($value) ? sanitize_text_field((string) $value) : '';
        return substr(trim($site_id), 0, 191);
    }

    public static function sanitize_hmac_secret( $value ): string {
        $secret = is_scalar($value) ? trim((string) $value) : '';
        if ($secret === '') {
            return (string) get_option(self::OPTION_HMAC_SECRET, '');
        }
        return $secret;
    }

    private function config_error(): ?array {
        if ((int) get_option(self::OPTION_ENABLED, 0) !== 1) {
            return $this->safe_error('price_assistant_disabled', 503);
        }

        if ($this->base_url() === '' || $this->site_id() === '' || $this->secret() === '') {
            return $this->safe_error('price_assistant_not_configured', 503);
        }

        return null;
    }

    private function auth_headers( string $raw_body ): array {
        $timestamp = (string) call_user_func($this->time_provider);
        $signature = hash_hmac('sha256', $timestamp . '.' . $raw_body, $this->secret());

        return array(
            'X-Savello-Site'      => $this->site_id(),
            'X-Savello-Timestamp' => $timestamp,
            'X-Savello-Signature' => $signature,
        );
    }

    private function build_url( string $path, array $query ): string {
        $url = $this->base_url() . '/' . ltrim($path, '/');
        return $query === array() ? $url : add_query_arg($query, $url);
    }

    private function base_url(): string {
        return self::sanitize_base_url(get_option(self::OPTION_BASE_URL, ''));
    }

    private function site_id(): string {
        return self::sanitize_site_id(get_option(self::OPTION_SITE_ID, ''));
    }

    private function secret(): string {
        return (string) get_option(self::OPTION_HMAC_SECRET, '');
    }

    private function upstream_error(): array {
        return $this->safe_error('upstream_unavailable', 502);
    }

    private function safe_error( string $code, int $status ): array {
        return array(
            'ok'     => false,
            'status' => $status,
            'data'   => array(
                'code'    => $code,
                'message' => 'Price assistant service is unavailable.',
            ),
        );
    }
}
