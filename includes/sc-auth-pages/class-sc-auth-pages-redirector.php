<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_SC_Auth_Pages_Redirector
 *
 * Перенаправляет неавторизованного гостя со стандартной WC-страницы /my-account/
 * на нашу /login/ — чтобы старая объединённая форма (login + register на одной
 * странице) никогда не показывалась.
 *
 * Whitelist endpoint'ов /my-account/, на которые редирект НЕ работает:
 *   - lost-password (восстановление пароля — стандартный WC-flow остаётся)
 *   - reset-password (после клика по ссылке из письма)
 *   - customer-logout (логаут залогиненных, обрабатывается WC до этого)
 *
 * Залогиненных юзеров не трогаем — для них /my-account/ это полноценный Dashboard.
 *
 * @since 1.3.0
 */
class Cashback_SC_Auth_Pages_Redirector {

    /**
     * Endpoint'ы, на которых стандартный WC-flow должен работать as-is.
     */
    private const ENDPOINT_WHITELIST = array(
        'lost-password',
        'reset-password',
        'customer-logout',
    );

    /**
     * Точка входа из template_redirect (prio 1 — выполняется ДО всех handler'ов).
     */
    public static function maybe_redirect(): void {
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return;
        }

        if (!self::is_account_page_request()) {
            return;
        }

        if (self::is_whitelisted_endpoint()) {
            return;
        }

        $login_url   = self::get_login_url();
        $current_url = self::current_url();

        if ($login_url === '' || $login_url === $current_url) {
            return;
        }

        $target = $current_url !== ''
            ? add_query_arg(array( 'redirect_to' => $current_url ), $login_url)
            : $login_url;
        Cashback_SC_Auth_Pages_Redirect_Helper::send($target);
    }

    private static function is_account_page_request(): bool {
        if (defined('CASHBACK_SC_AUTH_PAGES_TEST_FORCE_ACCOUNT_PAGE')) {
            return (bool) constant('CASHBACK_SC_AUTH_PAGES_TEST_FORCE_ACCOUNT_PAGE');
        }
        if (!function_exists('is_account_page')) {
            return false;
        }
        return (bool) is_account_page();
    }

    /**
     * Проверяет endpoint текущего запроса (lost-password / reset-password / customer-logout).
     *
     * Use WC()->query->get_current_endpoint() если доступно, иначе анализируем URL.
     */
    private static function is_whitelisted_endpoint(): bool {
        $endpoint = '';

        if (function_exists('WC')) {
            $wc = WC();
            // WooCommerce::$query — WC_Query (not-nullable) при инициализированном инстансе;
            // при guard через function_exists + property_exists мы покрываем кейс,
            // когда WC ещё не bootstrap'нул query (очень ранний template_redirect).
            if ($wc !== null && property_exists($wc, 'query') && is_object($wc->query) && method_exists($wc->query, 'get_current_endpoint')) {
                $endpoint = (string) $wc->query->get_current_endpoint();
            }
        }

        if ($endpoint === '') {
            // Fallback: анализ URI пути.
            $endpoint = self::detect_endpoint_from_uri();
        }

        return in_array($endpoint, self::ENDPOINT_WHITELIST, true);
    }

    private static function detect_endpoint_from_uri(): string {
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '';
        if ($uri === '') {
            return '';
        }

        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        if ($path === '') {
            return '';
        }

        // Извлекаем последний сегмент пути.
        $segments = array_values(array_filter(explode('/', $path), static fn( $s ) => $s !== ''));
        if ($segments === array()) {
            return '';
        }

        $last = end($segments);
        return is_string($last) ? $last : '';
    }

    private static function get_login_url(): string {
        if (class_exists('Cashback_SC_Auth_Pages_Login')) {
            return Cashback_SC_Auth_Pages_Login::get_login_url();
        }
        $id = (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_LOGIN_PAGE_ID, 0);
        if ($id > 0 && function_exists('get_permalink')) {
            $url = (string) get_permalink($id);
            if ($url !== '') {
                return $url;
            }
        }
        return function_exists('home_url') ? (string) home_url('/login/') : '';
    }

    private static function current_url(): string {
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '';
        if ($uri === '') {
            return '';
        }
        if (function_exists('home_url')) {
            return (string) home_url($uri);
        }
        // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders -- fallback only когда home_url() недоступен.
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_HOST'])) : 'localhost';
        return 'https://' . $host . $uri;
    }
}
