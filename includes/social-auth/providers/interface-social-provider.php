<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Контракт OAuth-провайдера социальной авторизации.
 *
 * Реализации: Yandex ID, VK ID.
 */
interface Cashback_Social_Provider_Interface {

    /**
     * Идентификатор провайдера: 'yandex' | 'vkid'.
     */
    public function get_id(): string;

    /**
     * Построить authorize URL для редиректа пользователя.
     *
     * @param array<string, mixed> $state {
     *     @type string $state          CSRF state (обязателен).
     *     @type string $code_verifier  PKCE code_verifier (для генерации challenge при необходимости).
     *     @type string $code_challenge PKCE code_challenge S256 (base64url).
     *     @type string $redirect_uri   Куда провайдер вернёт callback.
     *     @type bool   $scope_phone    Запрашивать ли scope телефона.
     * }
     */
    public function get_authorize_url( array $state ): string;

    /**
     * Обменять authorization_code на access_token + refresh_token.
     *
     * @param array<string, mixed> $extra  Провайдер-специфичные параметры (например, device_id у VK).
     * @return array{
     *     access_token: string,
     *     refresh_token: ?string,
     *     expires_in: int,
     *     scope: string,
     *     extra: array<string, mixed>
     * }
     */
    public function exchange_code( string $code, string $code_verifier, string $redirect_uri, array $extra = array() ): array;

    /**
     * Запросить профиль пользователя по access_token.
     *
     * @param array<string, mixed> $extra
     * @return array{
     *     external_id: string,
     *     email?: ?string,
     *     name?: ?string,
     *     avatar?: ?string,
     *     phone?: ?string,
     *     sub_provider?: ?string
     * }
     */
    public function fetch_user_info( string $access_token, array $extra = array() ): array;

    /**
     * Включён ли провайдер в настройках.
     */
    public function is_enabled(): bool;

    /**
     * Client ID приложения.
     */
    public function get_client_id(): string;

    /**
     * Client Secret приложения (уже расшифрован).
     */
    public function get_client_secret(): string;
}
