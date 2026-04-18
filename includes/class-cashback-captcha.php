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
        $client_key = get_option('cashback_captcha_client_key', '');
        $server_key = get_option('cashback_captcha_server_key', '');

        return $client_key !== '' && $server_key !== '';
    }

    /**
     * Нужна ли CAPTCHA для данного IP.
     *
     * Условия: CAPTCHA настроена + IP серый + верификация не закеширована.
     *
     * @param string $ip IP-адрес.
     * @return bool
     */
    public static function should_require( string $ip ): bool {
        if (!self::is_configured()) {
            return false;
        }

        if (!class_exists('Cashback_Rate_Limiter')) {
            return false;
        }

        // IP не серый — CAPTCHA не нужна
        if (!Cashback_Rate_Limiter::is_grey_ip($ip)) {
            return false;
        }

        // Уже верифицирован — CAPTCHA не нужна
        if (self::is_verified($ip)) {
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
     * @param string $token Токен от клиента.
     * @param string $ip    IP-адрес пользователя.
     * @return bool true если верификация пройдена.
     */
    public static function verify_token( string $token, string $ip ): bool {
        if ($token === '') {
            return false;
        }

        $server_key = get_option('cashback_captcha_server_key', '');
        if ($server_key === '') {
            // Ключ не настроен — graceful degradation
            return true;
        }

        $response = wp_remote_get(
            add_query_arg(
                array(
                    'secret' => $server_key,
                    'token'  => $token,
                    'ip'     => $ip,
                ),
                self::VALIDATE_URL
            ),
            array(
                'timeout'   => self::API_TIMEOUT,
                'sslverify' => true,
            )
        );

        if (is_wp_error($response)) {
            // API недоступен — graceful degradation, пропускаем
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Bot Protection] SmartCaptcha API error: ' . $response->get_error_message());
            return true;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Bot Protection] SmartCaptcha API HTTP ' . $code);
            return true;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return true;
        }

        $passed = isset($data['status']) && $data['status'] === 'ok';

        if ($passed) {
            // Кешируем успешную верификацию
            self::cache_verification($ip);
        }

        return $passed;
    }

    /**
     * Проверить, есть ли кешированная верификация для IP.
     *
     * @param string $ip IP-адрес.
     * @return bool
     */
    public static function is_verified( string $ip ): bool {
        return (bool) get_transient(self::cache_key($ip));
    }

    /**
     * Закешировать успешную верификацию.
     *
     * @param string $ip IP-адрес.
     */
    private static function cache_verification( string $ip ): void {
        set_transient(self::cache_key($ip), 1, self::VERIFY_CACHE_TTL);
    }

    /**
     * Transient ключ для кеша верификации.
     *
     * @param string $ip IP-адрес.
     * @return string
     */
    private static function cache_key( string $ip ): string {
        return 'cb_cap_' . substr(md5($ip), 0, 12);
    }
}
