<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Интеграция с Яндекс SmartCaptcha.
 *
 * Клиент: https://smartcaptcha.yandexcloud.net/captcha.js
 * Сервер: POST https://smartcaptcha.yandexcloud.net/validate
 *
 * Кеш успешной верификации — 10 минут (transient по IP).
 * Graceful degradation: если ключи не настроены или API недоступен — пропускаем.
 *
 * @since 2.1.0
 */
class Cashback_Captcha {

    /** URL клиентского скрипта SmartCaptcha. */
    private const JS_URL = 'https://smartcaptcha.yandexcloud.net/captcha.js';

    /** URL серверной валидации. */
    private const VALIDATE_URL = 'https://smartcaptcha.yandexcloud.net/validate';

    /** TTL кеша успешной верификации (10 минут). */
    private const VERIFY_CACHE_TTL = 600;

    /** Таймаут запроса к API SmartCaptcha (секунды). */
    private const API_TIMEOUT = 5;

    /**
     * Проверить, настроена ли CAPTCHA (есть оба ключа).
     *
     * @return bool
     */
    public static function is_configured(): bool {
        $client_key = Cashback_Fraud_Settings::get_captcha_client_key();
        $server_key = Cashback_Fraud_Settings::get_captcha_server_key();

        return $client_key !== '' && $server_key !== '';
    }

    /**
     * Нужна ли CAPTCHA для данного subject (user_id + IP).
     *
     * Условия: CAPTCHA настроена + subject серый + верификация не закеширована.
     * Группа 13 NAT-safety: subject = user_id (авторизованный) или IP (гость).
     *
     * @param int    $user_id ID пользователя (0 — гость).
     * @param string $ip      IP-адрес.
     */
    public static function should_require( int $user_id, string $ip ): bool {
        if (!self::is_configured()) {
            return false;
        }

        if (!class_exists('Cashback_Rate_Limiter')) {
            return false;
        }

        // Subject не серый — CAPTCHA не нужна
        if (!Cashback_Rate_Limiter::is_grey_ip($user_id, $ip)) {
            return false;
        }

        // Уже верифицирован для этого subject'а — CAPTCHA не нужна.
        // Группа 13: verified-cache subject-aware, чтобы per-user grey enforcement
        // не откатывался к per-IP после того как один пользователь на NAT прошёл CAPTCHA.
        if (self::is_verified($user_id, $ip)) {
            return false;
        }

        return true;
    }

    /**
     * Получить client key для фронтенда.
     *
     * @return string
     */
    public static function get_client_key(): string {
        return (string) get_option('cashback_captcha_client_key', '');
    }

    /**
     * HTML виджета SmartCaptcha для вставки в форму.
     *
     * Контейнер заполняется JS-ом при необходимости (lazy-load).
     *
     * @param string $container_id Уникальный ID контейнера.
     * @return string HTML.
     */
    public static function render_container( string $container_id ): string {
        return sprintf(
            '<div id="%s" class="cb-captcha-container" style="display:none;"></div>',
            esc_attr($container_id)
        );
    }

    /**
     * Серверная проверка токена SmartCaptcha.
     *
     * Группа 13: принимает `$user_id` для subject-aware verified-cache —
     * чтобы один пользователь на общем NAT-IP, прошедший CAPTCHA, не открывал
     * CAPTCHA-gate всем остальным серым аккаунтам на том же IP.
     *
     * @param string $token   Токен от клиента.
     * @param int    $user_id ID пользователя (0 — гость; кеш per-IP).
     * @param string $ip      IP-адрес пользователя.
     * @return bool true если верификация пройдена.
     */
    public static function verify_token( string $token, int $user_id, string $ip ): bool {
        if ($token === '') {
            return false;
        }

        $server_key = Cashback_Fraud_Settings::get_captcha_server_key();
        if ($server_key === '') {
            // Ключ не настроен — graceful degradation
            return true;
        }

        // 12d ADR (F-10-003): secret/token/ip уходят в POST body, не в query string.
        // URL без параметров — не утекает в access-log / reverse-proxy / browser history.
        $validate_url = self::VALIDATE_URL;

        // SSRF-guard: host SmartCaptcha'и захардкожен и входит в baseline allowlist;
        // проверка защищает от случаев, когда site-owner подменил allowlist через фильтр.
        // При deny — graceful degradation (config state, не upstream failure).
        if (class_exists('Cashback_Outbound_HTTP_Guard')) {
            $guard_check = Cashback_Outbound_HTTP_Guard::validate_url($validate_url);
            if (is_wp_error($guard_check)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Bot Protection] SmartCaptcha outbound denied: ' . $guard_check->get_error_message());
                return true;
            }
        }

        $response = wp_remote_post(
            $validate_url,
            array(
                'timeout'   => self::API_TIMEOUT,
                'sslverify' => true,
                'body'      => array(
                    'secret' => $server_key,
                    'token'  => $token,
                    'ip'     => $ip,
                ),
            )
        );

        // 12d ADR (F-10-004): fail-closed на upstream error. Legacy fail-open
        // (availability trade-off) доступен через filter cashback_captcha_upstream_policy
        // = 'allow'.
        $upstream_fail_result = ( apply_filters('cashback_captcha_upstream_policy', 'deny') === 'allow' );

        if (is_wp_error($response)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Bot Protection] SmartCaptcha API error: ' . $response->get_error_message());
            return $upstream_fail_result;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Bot Protection] SmartCaptcha API HTTP ' . $code);
            return $upstream_fail_result;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Bot Protection] SmartCaptcha API non-JSON response');
            return $upstream_fail_result;
        }

        $passed = isset($data['status']) && $data['status'] === 'ok';

        if ($passed) {
            // Кешируем успешную верификацию per-subject (uid для логина, ip для гостя).
            self::cache_verification($user_id, $ip);
        }

        return $passed;
    }

    /**
     * Проверить, есть ли кешированная верификация для subject'а.
     *
     * Группа 13: subject-aware. Залогиненный видит только свой кеш, гость — общий per-IP
     * (допустимо для NAT: один гость прошёл капчу — пул за тем же IP получает pass на TTL).
     */
    public static function is_verified( int $user_id, string $ip ): bool {
        return (bool) get_transient(self::cache_key($user_id, $ip));
    }

    /**
     * Закешировать успешную верификацию per-subject.
     */
    private static function cache_verification( int $user_id, string $ip ): void {
        set_transient(self::cache_key($user_id, $ip), 1, self::VERIFY_CACHE_TTL);
    }

    /**
     * Transient ключ для кеша верификации per-subject.
     *
     * Группа 13 iter-4: для гостей subject = individual IP + UA-family.
     * Ранее (iter-3) использовали subnet+UA — но это открывало cross-subject bypass:
     * grey-scoring у гостей per-IP, а verified-cache per-subnet → guest A прошёл
     * CAPTCHA (бакет subnet+UA) → guest B на другом IP в том же subnet пропускается,
     * хотя его grey-scope привязан к его IP. Individual-IP ключ в cache нужен,
     * чтобы CAPTCHA-pass не "утекала" между независимыми grey-гостями.
     */
    private static function cache_key( int $user_id, string $ip ): string {
        if ($user_id > 0) {
            $subject = 'u:' . $user_id;
        } else {
            $ua_raw = isset($_SERVER['HTTP_USER_AGENT'])
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders
                ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT'])
                : '';
            $family = class_exists('Cashback_Affiliate_Service')
                ? \Cashback_Affiliate_Service::normalize_ua($ua_raw)['family']
                : 'unknown';
            $subject = 'g:' . $ip . '|' . $family;
        }
        return 'cb_cap_' . substr(md5($subject), 0, 12);
    }
}
