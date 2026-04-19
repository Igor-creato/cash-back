<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Заглушка VK ID провайдера.
 *
 * Полная реализация — Этап 3 плана (OAuth 2.1 на id.vk.ru, PKCE S256, device_id).
 * На Этапе 1 — только скелет.
 */
class Cashback_Social_Provider_Vkid extends Cashback_Social_Provider_Abstract {

    public const ID = 'vkid';

    public function get_id(): string {
        return self::ID;
    }

    public function get_authorize_url( array $state ): string {
        throw new \RuntimeException('Cashback_Social_Provider_Vkid::get_authorize_url() is not implemented yet (Stage 3).');
    }

    public function exchange_code( string $code, string $code_verifier, string $redirect_uri, array $extra = array() ): array {
        throw new \RuntimeException('Cashback_Social_Provider_Vkid::exchange_code() is not implemented yet (Stage 3).');
    }

    public function fetch_user_info( string $access_token, array $extra = array() ): array {
        throw new \RuntimeException('Cashback_Social_Provider_Vkid::fetch_user_info() is not implemented yet (Stage 3).');
    }
}
