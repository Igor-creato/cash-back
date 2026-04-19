<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Реестр провайдеров социальной авторизации.
 *
 * На Этапе 1 регистрирует скелеты Yandex и VK ID. Этапы 2–3 наполнят их реализацией.
 */
class Cashback_Social_Auth_Providers {

    /**
     * @var array<string, Cashback_Social_Provider_Interface>
     */
    private array $providers = array();

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->register_defaults();
    }

    private function register_defaults(): void {
        if (class_exists('Cashback_Social_Provider_Yandex')) {
            $this->providers[ Cashback_Social_Provider_Yandex::ID ] = new Cashback_Social_Provider_Yandex();
        }
        if (class_exists('Cashback_Social_Provider_Vkid')) {
            $this->providers[ Cashback_Social_Provider_Vkid::ID ] = new Cashback_Social_Provider_Vkid();
        }
    }

    /**
     * Получить провайдер по id.
     */
    public function get( string $id ): ?Cashback_Social_Provider_Interface {
        return $this->providers[ $id ] ?? null;
    }

    /**
     * @return array<string, Cashback_Social_Provider_Interface>
     */
    public function all(): array {
        return $this->providers;
    }

    /**
     * Метаданные провайдеров для UI (iд → название).
     *
     * @return array<string, string>
     */
    public static function labels(): array {
        return array(
            Cashback_Social_Provider_Yandex::ID => __('Яндекс ID', 'cashback-plugin'),
            Cashback_Social_Provider_Vkid::ID   => __('VK ID', 'cashback-plugin'),
        );
    }
}
