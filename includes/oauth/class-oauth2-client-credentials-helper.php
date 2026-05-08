<?php

/**
 * Generic OAuth2 client_credentials grant helper.
 *
 * Извлечён из Cashback_Admitad_Adapter::get_token() как фундамент для
 * generic-движка купонов (план «Активные промокоды Admitad»). Реализует
 * стандартный RFC 6749 §4.4 client_credentials grant с Basic Auth +
 * двухуровневое кеширование токена (transient + runtime).
 *
 * Используется любой CPA-сетью с этой схемой OAuth2 (Admitad, потенциально
 * другие). EPN использует 3-шаговый SSID-flow и НЕ маршрутизируется через
 * этот helper.
 *
 * Inputs (на каждый вызов get_token):
 *  - $token_url       — полный URL token-endpoint'а (https only, проверяется SSRF guard'ом).
 *  - $client_id       — OAuth2 client_id.
 *  - $client_secret   — OAuth2 client_secret.
 *  - $scope           — пробел-разделённые scope (например "statistics advcampaigns").
 *
 * Cache key: "{namespace}_" . md5(client_id) — изолирует разные client'ы.
 * Namespace задаётся в конструкторе (по умолчанию "cashback_oauth2_token").
 *
 * Безопасность:
 *  - Cashback_Outbound_HTTP_Guard::validate_url() перед wp_remote_post (SSRF).
 *  - sslverify=true, reject_unsafe_urls=true.
 *  - last_error НЕ содержит client_secret и access_token (только статус-код).
 *
 * @package CashbackPlugin
 * @since   7.2.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_OAuth2_Client_Credentials_Helper {

    /** @var string Префикс ключа transient/runtime cache. */
    private string $cache_namespace;

    /** @var array<string,string> Runtime-кеш токенов в рамках одного запроса. */
    private array $runtime_cache = array();

    /** @var string Последняя ошибка (без секретов) — для UI/логов. */
    private string $last_error = '';

    /**
     * @param string $cache_namespace Префикс ключа cache (default: cashback_oauth2_token).
     *                                Должен содержать slug сети для изоляции, если на одном
     *                                сайте несколько OAuth2-сетей с одинаковым client_id
     *                                (теоретически невозможно, но зависит от админа).
     */
    public function __construct( string $cache_namespace = 'cashback_oauth2_token' ) {
        $this->cache_namespace = $cache_namespace;
    }

    /**
     * Получить access_token для client_credentials grant.
     *
     * Сначала проверяет transient → runtime cache → если miss, делает POST.
     * После успешного получения кеширует с TTL = max(60, expires_in - 300).
     *
     * @param string $token_url     Полный URL token-endpoint'а (https://).
     * @param string $client_id     OAuth2 client_id.
     * @param string $client_secret OAuth2 client_secret.
     * @param string $scope         Пробел-разделённые scope (опционально).
     * @return string|null Токен или null при ошибке (см. get_last_error()).
     */
    public function get_token( string $token_url, string $client_id, string $client_secret, string $scope = '' ): ?string {
        $this->last_error = '';

        if ($client_id === '' || $client_secret === '') {
            $this->last_error = 'OAuth2 credentials incomplete (client_id или client_secret пустые)';
            return null;
        }

        $cache_key = $this->cache_key( $client_id );

        // F-P3-001: токен в transient хранится в зашифрованном виде. Plain в
        // wp_options позволял бы DB-read атакующему использовать его пока
        // он валиден (<= expires_in). Cashback_Encryption AES-256-GCM с
        // dual-key rotation (Group 2) — то же шифрование что у других
        // outbound credentials.
        $cached = get_transient( $cache_key );
        if ( is_string( $cached ) && $cached !== '' ) {
            $decrypted = self::try_decrypt( $cached );
            if ( $decrypted !== null && $decrypted !== '' ) {
                return $decrypted;
            }
            // Расшифровать не удалось (key rotation completed без чистки) —
            // считаем cache miss и идём за свежим токеном.
            delete_transient( $cache_key );
        }

        if ( isset( $this->runtime_cache[ $cache_key ] ) ) {
            return $this->runtime_cache[ $cache_key ];
        }

        $check = Cashback_Outbound_HTTP_Guard::validate_url( $token_url );
        if ( is_wp_error( $check ) ) {
            $this->last_error = 'OAuth2 token URL blocked by SSRF guard: ' . $check->get_error_code();
            return null;
        }

        $response = wp_remote_post( $token_url, array(
            // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- OAuth2 token exchange against CPA-network; 30s покрывает редкие сетевые задержки и совпадает с базовым адаптером.
            'timeout'            => 30,
            'headers'            => array(
                'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'               => array(
                'grant_type' => 'client_credentials',
                'client_id'  => $client_id,
                'scope'      => $scope,
            ),
            'sslverify'          => true,
            'reject_unsafe_urls' => true,
        ) );

        if ( is_wp_error( $response ) ) {
            $this->last_error = 'OAuth2 token network error: ' . $response->get_error_message();
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( (int) $code !== 200 || empty( $body['access_token'] ) || ! is_string( $body['access_token'] ) ) {
            $this->last_error = 'OAuth2 token failed (HTTP ' . (int) $code . ')';
            return null;
        }

        $token   = (string) $body['access_token'];
        $expires = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
        $ttl     = max( 60, $expires - 300 );

        // F-P3-001: encrypt перед persist в transient (см. try_decrypt выше).
        // Если шифрование недоступно — сохраняем только в runtime_cache
        // (in-memory), без durable persistence. Защищает от утечки через
        // DB-dump / SQL-injection в неконтролируемом плагине.
        $encrypted = self::try_encrypt( $token );
        if ( $encrypted !== null ) {
            set_transient( $cache_key, $encrypted, $ttl );
        }
        $this->runtime_cache[ $cache_key ] = $token;

        return $token;
    }

    /**
     * Шифрование через Cashback_Encryption если доступно.
     *
     * @return string|null Ciphertext или null если encryption недоступно
     *                    (миграция / сбой ключа). Caller fall-back'нется на
     *                    runtime cache без durable persistence.
     */
    private static function try_encrypt( string $plaintext ): ?string {
        if ( ! class_exists( 'Cashback_Encryption' ) || ! method_exists( 'Cashback_Encryption', 'is_configured' ) ) {
            return null;
        }
        if ( ! Cashback_Encryption::is_configured() ) {
            return null;
        }
        $cipher = Cashback_Encryption::encrypt( $plaintext );
        return ( $cipher !== '' ) ? $cipher : null;
    }

    /**
     * Расшифровка через Cashback_Encryption (trial-decrypt по {primary, new, previous}).
     *
     * `Cashback_Encryption::decrypt` бросает RuntimeException на не-валидном
     * ciphertext (legacy plain string в transient после deploy F-P3-001 на
     * существующую установку, повреждённый payload). Мы перехватываем и
     * возвращаем null — caller fall-back'нется на свежий fetch токена.
     */
    private static function try_decrypt( string $ciphertext ): ?string {
        if ( ! class_exists( 'Cashback_Encryption' ) || ! method_exists( 'Cashback_Encryption', 'decrypt' ) ) {
            return null;
        }
        try {
            $plain = Cashback_Encryption::decrypt( $ciphertext );
        } catch ( \Throwable $e ) {
            return null;
        }
        return $plain !== '' ? $plain : null;
    }

    /**
     * Инвалидировать закешированный токен для конкретного client_id.
     *
     * Вызывается при 401/insufficient_scope, чтобы следующий запрос получил
     * свежий токен с актуальными scope.
     *
     * @param string $client_id OAuth2 client_id.
     */
    public function invalidate_token( string $client_id ): void {
        if ( $client_id === '' ) {
            return;
        }
        $cache_key = $this->cache_key( $client_id );
        delete_transient( $cache_key );
        unset( $this->runtime_cache[ $cache_key ] );
    }

    /**
     * Последняя ошибка (без секретов) для UI/логов.
     */
    public function get_last_error(): string {
        return $this->last_error;
    }

    /**
     * Сформировать cache key для client_id (md5 защищает от спецсимволов в client_id).
     */
    private function cache_key( string $client_id ): string {
        return $this->cache_namespace . '_' . md5( $client_id );
    }
}
