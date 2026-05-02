<?php

/**
 * Реестр адаптеров купонов.
 *
 * Приоритет выбора:
 *   1. Code-adapter, зарегистрированный для slug'а сети.
 *   2. Generic JSON adapter (через factory) — если у сети заполнен api_coupons_endpoint.
 *   3. null — нет адаптера, fetcher логирует и пропускает сеть.
 *
 * Code-adapter'ы регистрируются через action 'cashback_register_coupons_code_adapters'
 * (extension point для сторонних плагинов).
 *
 * @package CashbackPlugin
 * @since   7.2.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Coupons_Adapter_Registry {

    /** @var array<string,Cashback_Coupons_Adapter_Interface> */
    private array $code_adapters = array();

    /** @var (callable(mixed):?Cashback_Coupons_Adapter_Interface)|null */
    private $generic_factory;

    /**
     * @param (callable(mixed):?Cashback_Coupons_Adapter_Interface)|null $generic_factory
     *        Factory который создаёт generic-адаптер из network_config.
     *        В тестах можно передать stub.
     */
    public function __construct( ?callable $generic_factory = null ) {
        $this->generic_factory = $generic_factory;
    }

    /**
     * Зарегистрировать code-adapter для сети (приоритет над generic).
     *
     * @return $this Для chaining.
     */
    public function register_code_adapter( string $slug, Cashback_Coupons_Adapter_Interface $adapter ): self {
        $this->code_adapters[ strtolower( $slug ) ] = $adapter;
        return $this;
    }

    /**
     * Удалить code-adapter (используется для тестов / runtime-управления).
     */
    public function unregister_code_adapter( string $slug ): self {
        unset( $this->code_adapters[ strtolower( $slug ) ] );
        return $this;
    }

    /**
     * Установить factory для generic-адаптера post-construct.
     */
    public function set_generic_factory( callable $factory ): self {
        $this->generic_factory = $factory;
        return $this;
    }

    /**
     * Получить адаптер для сети.
     *
     * @param object|array<string,mixed> $network_config Конфиг сети из cashback_affiliate_networks.
     */
    public function get_for_network( mixed $network_config ): ?Cashback_Coupons_Adapter_Interface {
        $slug             = $this->extract_field( $network_config, 'slug' );
        $coupons_endpoint = $this->extract_field( $network_config, 'api_coupons_endpoint' );

        if ( is_string( $slug ) && $slug !== '' ) {
            $slug_key = strtolower( $slug );
            if ( isset( $this->code_adapters[ $slug_key ] ) ) {
                return $this->code_adapters[ $slug_key ];
            }
        }

        // Generic возвращается только если у сети заполнен endpoint купонов.
        if ( ! is_string( $coupons_endpoint ) || $coupons_endpoint === '' ) {
            return null;
        }

        if ( $this->generic_factory === null ) {
            return null;
        }

        $adapter = ( $this->generic_factory )( $network_config );
        return $adapter instanceof Cashback_Coupons_Adapter_Interface ? $adapter : null;
    }

    /**
     * Безопасно достать поле из network_config (object|array).
     */
    private function extract_field( mixed $config, string $field ): mixed {
        if ( is_object( $config ) ) {
            return $config->{$field} ?? null;
        }
        if ( is_array( $config ) ) {
            return $config[ $field ] ?? null;
        }
        return null;
    }
}
