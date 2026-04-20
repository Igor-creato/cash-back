<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CORS для мобильного API (`cashback/v2`).
 *
 * Мотивация:
 *   - Нативные клиенты Expo (iOS/Android) не проходят CORS — там нет браузера.
 *   - Но Expo Web (dev-режим) и тестовые браузерные тулзы — проходят.
 *
 * Политика: строгий allow-list из `CB_MOBILE_ALLOWED_ORIGINS` (comma-separated).
 * Если константа не определена — CORS-заголовки НЕ выставляются (все cross-origin
 * запросы в браузере будут отклонены — безопасный default).
 *
 * Allow credentials НЕ выставляем — JWT уже в Authorization header, cookies не нужны.
 *
 * Примеры:
 *   define('CB_MOBILE_ALLOWED_ORIGINS', 'http://localhost:19006,https://my-dev.expo.dev');
 *
 * @since 1.1.0
 */
class Cashback_Mobile_CORS {

    public static function init(): void {
        // Хукаемся на rest_pre_serve_request — единственная точка, где можем выставить
        // CORS-заголовки ДО того, как WP REST отдаст ответ.
        add_filter('rest_pre_serve_request', array( __CLASS__, 'handle_cors' ), 5, 4);
    }

    /**
     * @param bool             $served  Already served?
     * @param \WP_REST_Response $result Response object.
     * @param \WP_REST_Request  $request Request.
     * @param \WP_REST_Server   $server  REST server.
     */
    public static function handle_cors( $served, $result, $request, $server ) {
        unset($result, $server);
        if (!( $request instanceof \WP_REST_Request )) {
            return $served;
        }
        // Ограничиваем scope нашим namespace.
        $route = (string) $request->get_route();
        if (0 !== strpos($route, '/cashback/v2')) {
            return $served;
        }

        $origin = isset($_SERVER['HTTP_ORIGIN']) ? esc_url_raw(wp_unslash((string) $_SERVER['HTTP_ORIGIN'])) : '';
        if ('' === $origin) {
            return $served;
        }

        $allowed = self::get_allowed_origins();
        if (empty($allowed) || !in_array($origin, $allowed, true)) {
            return $served;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, If-None-Match, X-Requested-With');
        header('Access-Control-Expose-Headers: ETag');
        header('Access-Control-Max-Age: 600');
        header('Vary: Origin');

        return $served;
    }

    /**
     * @return array<int,string>
     */
    private static function get_allowed_origins(): array {
        if (!defined('CB_MOBILE_ALLOWED_ORIGINS')) {
            return array();
        }
        $raw = (string) constant('CB_MOBILE_ALLOWED_ORIGINS');
        if ('' === $raw) {
            return array();
        }
        $list = array_map('trim', explode(',', $raw));
        $list = array_filter($list, static fn( string $o ): bool => '' !== $o && false !== filter_var($o, FILTER_VALIDATE_URL));
        return array_values($list);
    }
}
