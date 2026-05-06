<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_SC_Auth_Pages_Login
 *
 * Обработчик POST-запросов формы /login/.
 *
 * Безопасность:
 *  - nonce 'sc_auth_pages_login'
 *  - rate-limit per-IP 5 попыток / 15 минут (transient)
 *  - generic-сообщение об ошибке (anti user-enumeration)
 *  - wp_safe_redirect + wp_validate_redirect (anti open-redirect)
 *  - wp_signon проходит через фильтр authenticate prio 30 (Cashback_User_Status::block_banned_login)
 *
 * @since 1.3.0
 */
class Cashback_SC_Auth_Pages_Login {

    public const NONCE_ACTION       = 'sc_auth_pages_login';
    public const NONCE_FIELD        = '_sc_auth_nonce';
    public const RATE_LIMIT_PREFIX  = 'cb_sc_auth_login_rl_';
    public const RATE_LIMIT_MAX     = 5;
    public const RATE_LIMIT_WINDOW  = 900; // 15 минут.

    /**
     * Точка входа из template_redirect (prio 5).
     *
     * Срабатывает только на странице /login/ (по сохранённому ID) при POST + nonce.
     */
    public static function maybe_handle(): void {
        if (!self::is_login_page_post()) {
            return;
        }

        if (!self::verify_nonce()) {
            self::add_error_notice(__('Сессия истекла. Попробуйте войти ещё раз.', 'cashback-plugin'));
            self::redirect_back();
            return;
        }

        $client_ip = self::resolve_client_ip();
        if (!self::rate_limit_check($client_ip)) {
            self::add_error_notice(__('Слишком много попыток входа. Подождите 15 минут.', 'cashback-plugin'));
            self::redirect_back();
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен в verify_nonce() выше.
        $login = isset($_POST['log']) ? sanitize_user(wp_unslash((string) $_POST['log']), false) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce проверен; пароль НЕ sanitize и НЕ unslash (искажает спец-символы; WP wp_signon ждёт raw).
        $password = isset($_POST['pwd']) ? (string) $_POST['pwd'] : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен.
        $remember = !empty($_POST['rememberme']);

        if ($login === '' || $password === '') {
            self::add_error_notice(__('Заполните email/логин и пароль.', 'cashback-plugin'));
            self::redirect_back();
            return;
        }

        $user = wp_signon(array(
            'user_login'    => $login,
            'user_password' => $password,
            'remember'      => $remember,
        ), is_ssl());

        if (is_wp_error($user)) {
            self::register_violation($client_ip);
            self::add_error_notice(self::get_generic_error_message($user));
            self::redirect_back();
            return;
        }

        if (!($user instanceof WP_User)) {
            self::register_violation($client_ip);
            self::add_error_notice(__('Не удалось войти. Попробуйте позже.', 'cashback-plugin'));
            self::redirect_back();
            return;
        }

        self::clear_rate_limit($client_ip);

        $redirect = self::resolve_login_redirect_target($user);
        Cashback_SC_Auth_Pages_Redirect_Helper::send($redirect);
    }

    /**
     * Хук для тестов и DI: сбрасывает captured-redirect (не используется в проде).
     */
    public static function get_login_url(): string {
        $url = '';
        $id  = (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_LOGIN_PAGE_ID, 0);
        if ($id > 0 && function_exists('get_permalink')) {
            $url = (string) get_permalink($id);
        }
        if ($url === '') {
            $url = function_exists('home_url') ? (string) home_url('/login/') : '/login/';
        }
        return (string) apply_filters('sc_auth_pages_login_url', $url, $id);
    }

    /**
     * Проверка: GET → false, POST на /login/ → true. Использует флаги тестов
     * для подавления is_page-проверки.
     */
    private static function is_login_page_post(): bool {
        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD'])) : '';
        if ($method !== 'POST') {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- проверка маркера POST до nonce-валидации; sanitize не нужна.
        if (!isset($_POST['sc_auth_action']) || $_POST['sc_auth_action'] !== 'login') {
            return false;
        }
        if (defined('CASHBACK_SC_AUTH_PAGES_TEST_BYPASS_PAGE_CHECK')) {
            return true;
        }
        if (!function_exists('is_page')) {
            return false;
        }
        $login_id = (int) get_option(Cashback_SC_Auth_Pages_Activator::OPTION_LOGIN_PAGE_ID, 0);
        return $login_id > 0 && is_page($login_id);
    }

    private static function verify_nonce(): bool {
        if (!function_exists('wp_verify_nonce')) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- мы и есть nonce-проверка.
        $raw = isset($_POST[ self::NONCE_FIELD ]) ? sanitize_text_field(wp_unslash((string) $_POST[ self::NONCE_FIELD ])) : '';
        return $raw !== '' && (bool) wp_verify_nonce($raw, self::NONCE_ACTION);
    }

    /**
     * Per-IP rate-limit (NAT-trade-off: за NAT'ом несколько юзеров делят ведро,
     * это приемлемо для login — 5 попыток / 15 мин достаточно для honest-юзера).
     */
    private static function rate_limit_check( string $client_ip ): bool {
        if ($client_ip === '') {
            return true; // если IP неизвестен — не блокируем (graceful degradation).
        }
        $key   = self::RATE_LIMIT_PREFIX . md5($client_ip);
        $count = (int) get_transient($key);
        return $count < self::RATE_LIMIT_MAX;
    }

    private static function register_violation( string $client_ip ): void {
        if ($client_ip === '') {
            return;
        }
        $key     = self::RATE_LIMIT_PREFIX . md5($client_ip);
        $current = (int) get_transient($key);
        set_transient($key, $current + 1, self::RATE_LIMIT_WINDOW);
    }

    private static function clear_rate_limit( string $client_ip ): void {
        if ($client_ip === '') {
            return;
        }
        $key = self::RATE_LIMIT_PREFIX . md5($client_ip);
        delete_transient($key);
    }

    private static function resolve_client_ip(): string {
        // REMOTE_ADDR — X-Forwarded-For не доверяем (можно подменить). За reverse-proxy
        // конфиг nginx должен переписывать REMOTE_ADDR. Sniff помечает его как
        // user-controlled — это так, поэтому FILTER_VALIDATE_IP перед использованием.
        // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- request-scoped IP, нужен для rate-limit per-request.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    /**
     * Generic-сообщение для login error (anti user-enumeration).
     *
     * Если WP_Error содержит код 'cashback_user_banned' (Cashback_User_Status,
     * фильтр wp_authenticate_user prio 30) — показываем «Аккаунт заблокирован»
     * по умолчанию (но ban_reason_admin никогда не утекает — см. memory:
     * project_obs_06_05_full_done_2026_04_30).
     */
    private static function get_generic_error_message( WP_Error $error ): string {
        $code = $error->get_error_code();
        if ($code === 'cashback_user_banned' || $code === 'banned_account') {
            return __('Аккаунт заблокирован. Свяжитесь с поддержкой.', 'cashback-plugin');
        }
        return __('Неверный логин или пароль.', 'cashback-plugin');
    }

    private static function resolve_login_redirect_target( WP_User $user ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- значение прогоняется через sanitize_text_field+wp_validate_redirect ниже.
        $raw = isset($_POST['redirect_to']) ? sanitize_text_field(wp_unslash((string) $_POST['redirect_to'])) : '';
        if (function_exists('esc_url_raw')) {
            $raw = esc_url_raw($raw);
        }

        $default = Cashback_SC_Auth_Pages_Redirect_Helper::get_my_account_url();
        $target  = (string) apply_filters('sc_auth_pages_login_redirect', ($raw !== '' ? $raw : $default), $user);

        if (function_exists('wp_validate_redirect')) {
            return (string) wp_validate_redirect($target, $default);
        }
        return $target !== '' ? $target : $default;
    }

    private static function redirect_back(): void {
        Cashback_SC_Auth_Pages_Redirect_Helper::send(self::get_login_url());
    }

    private static function add_error_notice( string $message ): void {
        if (function_exists('wc_add_notice')) {
            wc_add_notice($message, 'error');
            return;
        }
        // Fallback: сохраняем в transient на 1 минуту (для ситуации, когда WC не загружен).
        $bucket = (array) get_transient('cb_sc_auth_pages_notices') ?: array();
        $bucket[] = $message;
        set_transient('cb_sc_auth_pages_notices', $bucket, MINUTE_IN_SECONDS);
    }
}
