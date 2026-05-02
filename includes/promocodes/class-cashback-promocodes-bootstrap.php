<?php

/**
 * Bootstrap движка промокодов.
 *
 * Регистрирует:
 *   - Action Scheduler hook 'cashback_promocodes_fetch_all' → fetcher->fetch_all().
 *   - register_cron при init (рекуррентный 6ч).
 *   - Filter 'cashback_register_coupons_code_adapters' (extension point для
 *     регистрации code-adapter'ов сторонними плагинами).
 *
 * Создаёт registry с фабрикой Generic JSON adapter'а, лениво инициализированный.
 *
 * @package CashbackPlugin
 * @since   7.2.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Promocodes_Bootstrap {

    private static ?Cashback_Coupons_Adapter_Registry $registry = null;
    private static ?Cashback_Promocodes_Repository $repository = null;
    private static ?Cashback_Promocodes_Fetcher $fetcher = null;

    public static function init(): void {
        // AS-hook handler для cron.
        add_action( Cashback_Promocodes_Fetcher::CRON_HOOK, array( __CLASS__, 'handle_cron_fetch_all' ) );

        // Register cron при init.
        add_action( 'init', array( __CLASS__, 'register_cron' ), 20 );
    }

    /**
     * Зарегистрировать рекуррентный AS-job.
     */
    public static function register_cron(): void {
        self::get_fetcher()->register_cron();
    }

    /**
     * Handler для AS-hook 'cashback_promocodes_fetch_all'.
     */
    public static function handle_cron_fetch_all(): void {
        try {
            self::get_fetcher()->fetch_all();
        } catch ( \Throwable $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log( '[Cashback Promocodes] cron fetch_all crashed: ' . $e->getMessage() );
        }
    }

    /**
     * Get singleton registry с factory для generic-адаптера.
     */
    public static function get_registry(): Cashback_Coupons_Adapter_Registry {
        if ( self::$registry === null ) {
            $factory = static function ( mixed $network_config ) {
                if ( ! class_exists( 'Cashback_Generic_Json_Coupons_Adapter' ) ) {
                    return null;
                }
                if ( ! class_exists( 'Cashback_API_Client' ) || ! class_exists( 'Cashback_Network_Http_Client' ) || ! class_exists( 'Cashback_Coupons_Field_Mapper' ) ) {
                    return null;
                }
                return new Cashback_Generic_Json_Coupons_Adapter(
                    $network_config,
                    Cashback_API_Client::get_instance(),
                    new Cashback_Network_Http_Client(),
                    new Cashback_Coupons_Field_Mapper()
                );
            };
            self::$registry = new Cashback_Coupons_Adapter_Registry( $factory );

            // Extension point: сторонние плагины могут зарегистрировать code-адаптеры.
            do_action( 'cashback_register_coupons_code_adapters', self::$registry );
        }
        return self::$registry;
    }

    public static function get_repository(): Cashback_Promocodes_Repository {
        if ( self::$repository === null ) {
            self::$repository = new Cashback_Promocodes_Repository();
        }
        return self::$repository;
    }

    public static function get_fetcher(): Cashback_Promocodes_Fetcher {
        if ( self::$fetcher === null ) {
            self::$fetcher = new Cashback_Promocodes_Fetcher(
                self::get_registry(),
                self::get_repository()
            );
        }
        return self::$fetcher;
    }
}
