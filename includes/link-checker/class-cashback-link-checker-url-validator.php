<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Link_Checker_Url_Validator {

    /**
     * @return array{ok:bool,url:string,host:string,error:string}
     */
    public static function validate( string $url ): array {
        $url = trim($url);

        if ($url === '') {
            return self::error('empty_url');
        }

        if (function_exists('wp_parse_url')) {
            $parts = wp_parse_url($url);
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for isolated PHPUnit without WordPress loaded.
            $parts = parse_url($url);
        }
        if (!is_array($parts)) {
            return self::error('invalid_url');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return self::error('invalid_url');
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return self::error('invalid_url');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return self::error('userinfo_not_allowed');
        }

        if (function_exists('esc_url_raw') && esc_url_raw($url) === '') {
            return self::error('invalid_url');
        }

        $normalized_host = self::normalize_host($host);
        if ($normalized_host === '') {
            return self::error('invalid_url');
        }

        if (self::is_blocked_host($normalized_host)) {
            return self::error('blocked_host');
        }

        if (self::is_private_ip_literal($normalized_host)) {
            return self::error('private_ip');
        }

        return array(
            'ok'    => true,
            'url'   => $url,
            'host'  => $normalized_host,
            'error' => '',
        );
    }

    public static function normalize_host( string $host ): string {
        $host = trim(strtolower($host));
        $host = trim($host, "[] \t\n\r\0\x0B.");
        $host = preg_replace('/^www\./i', '', $host) ?? '';

        return $host;
    }

    /**
     * @return array{ok:bool,url:string,host:string,error:string}
     */
    private static function error( string $code ): array {
        return array(
            'ok'    => false,
            'url'   => '',
            'host'  => '',
            'error' => $code,
        );
    }

    private static function is_blocked_host( string $host ): bool {
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === 'local'
            || str_ends_with($host, '.local');
    }

    private static function is_private_ip_literal( string $host ): bool {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
