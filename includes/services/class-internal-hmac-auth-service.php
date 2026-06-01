<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Savello_Internal_HMAC_Auth_Service {

    public const OPTION_SECRET  = 'savello_internal_api_secret';
    public const OPTION_ENABLED = 'savello_internal_api_enabled';

    private const MAX_SKEW_SECONDS = 300;

    public function verify_request( WP_REST_Request $request ) {
        if (! $this->is_enabled()) {
            return $this->missing_error();
        }

        $secret = $this->get_secret();
        if ($secret === '') {
            return $this->missing_error();
        }

        $site      = trim($request->get_header('X-Savello-Site'));
        $timestamp = trim($request->get_header('X-Savello-Timestamp'));
        $signature = trim($request->get_header('X-Savello-Signature'));

        if ($site === '' || $timestamp === '' || $signature === '') {
            return $this->missing_error();
        }

        if (! preg_match('/^\d+$/', $timestamp)) {
            return $this->invalid_error();
        }

        $request_time = (int) $timestamp;
        if (abs(time() - $request_time) > self::MAX_SKEW_SECONDS) {
            return $this->invalid_error();
        }

        $expected = self::build_signature($timestamp, $this->get_raw_body($request), $secret);
        if (! hash_equals($expected, $signature)) {
            return $this->invalid_error();
        }

        return true;
    }

    public static function build_signature( string $timestamp, string $raw_body, string $secret ): string {
        return hash_hmac('sha256', $timestamp . '.' . $raw_body, $secret);
    }

    public function get_raw_body( WP_REST_Request $request ): string {
        $body = $request->get_body();
        if (is_string($body) && $body !== '') {
            return $body;
        }

        // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsRemoteFile -- php://input fallback for REST raw body, not a remote request.
        $raw = file_get_contents('php://input');
        return is_string($raw) ? $raw : '';
    }

    public static function sanitize_secret( $value ): string {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    public static function sanitize_enabled( $value ): int {
        return (int) (bool) $value;
    }

    private function is_enabled(): bool {
        return (int) get_option(self::OPTION_ENABLED, 0) === 1;
    }

    private function get_secret(): string {
        return self::sanitize_secret(get_option(self::OPTION_SECRET, ''));
    }

    private function missing_error(): WP_Error {
        return new WP_Error(
            'savello_internal_auth_missing',
            'Internal API authentication is required.',
            array( 'status' => 401 )
        );
    }

    private function invalid_error(): WP_Error {
        return new WP_Error(
            'savello_internal_auth_invalid',
            'Internal API authentication failed.',
            array( 'status' => 403 )
        );
    }
}
