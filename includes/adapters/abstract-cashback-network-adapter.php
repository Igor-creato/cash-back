<?php

/**
 * Базовый абстрактный адаптер CPA-сети
 *
 * Предоставляет общую логику: HTTP-хелперы, кеш токенов, URL-билдер.
 * Конкретные адаптеры наследуют этот класс.
 *
 * @package CashbackPlugin
 * @since   7.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

abstract class Cashback_Network_Adapter_Base implements Cashback_Network_Adapter_Interface
{
    /** @var array Кеш токенов в рамках одного запроса (runtime) */
    protected array $token_cache = [];

    /** @var string Последняя ошибка получения токена */
    protected string $last_token_error = '';

    /**
     * {@inheritdoc}
     */
    public function get_aliases(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function get_last_token_error(): string
    {
        return $this->last_token_error;
    }

    /**
     * {@inheritdoc}
     */
    public function invalidate_token(array $credentials): void
    {
        $this->token_cache = [];
    }

    /**
     * {@inheritdoc}
     *
     * Дефолтная реализация — возвращает ошибку.
     * Адаптеры, поддерживающие проверку кампаний, переопределяют этот метод.
     */
    public function fetch_campaigns(array $credentials, array $network_config): array
    {
        return [
            'success'   => false,
            'campaigns' => [],
            'error'     => 'fetch_campaigns not implemented for adapter: ' . $this->get_slug(),
        ];
    }

    /**
     * Собрать URL из конфига сети (api_base_url + endpoint) или вернуть fallback
     *
     * @param array  $network_config Конфигурация сети
     * @param string $endpoint_key   Ключ эндпоинта в конфиге (api_token_endpoint, api_actions_endpoint)
     * @param string $fallback_url   URL по умолчанию
     * @return string
     */
    protected function build_api_url(array $network_config, string $endpoint_key, string $fallback_url): string
    {
        $base     = rtrim($network_config['api_base_url'] ?? '', '/');
        $endpoint = $network_config[$endpoint_key] ?? '';

        if ($endpoint !== '' && preg_match('#^https?://#i', $endpoint)) {
            return $endpoint;
        }

        if ($base !== '' && $endpoint !== '') {
            return $base . '/' . ltrim($endpoint, '/');
        }

        return $fallback_url;
    }

    /**
     * HTTP GET запрос
     *
     * @param string $url     URL
     * @param array  $headers Заголовки
     * @param int    $timeout Таймаут (секунды)
     * @return array|\WP_Error
     */
    protected function http_get(string $url, array $headers, int $timeout = 60)
    {
        $args = [
            'timeout' => $timeout,
            'headers' => $headers,
        ];

        // wp_remote_get использует отдельный параметр user-agent,
        // который перезаписывает заголовок User-Agent из headers.
        if (isset($headers['User-Agent'])) {
            $args['user-agent'] = $headers['User-Agent'];
            unset($args['headers']['User-Agent']);
        }

        return wp_remote_get($url, $args);
    }

    /**
     * HTTP POST запрос
     *
     * @param string       $url     URL
     * @param array        $headers Заголовки
     * @param array|string $body    Тело запроса
     * @param int          $timeout Таймаут (секунды)
     * @return array|\WP_Error
     */
    protected function http_post(string $url, array $headers, $body, int $timeout = 30)
    {
        return wp_remote_post($url, [
            'timeout' => $timeout,
            'headers' => $headers,
            'body'    => $body,
        ]);
    }

    /**
     * Стандартный формат ответа при ошибке fetch
     *
     * @param string $error Текст ошибки
     * @return array
     */
    protected function fetch_error(string $error): array
    {
        return [
            'success'  => false,
            'actions'  => [],
            'total'    => 0,
            'has_next' => false,
            'error'    => $error,
        ];
    }
}
