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
    private static ?Cashback_Promocodes_Shortcode $shortcode = null;
    private static ?Cashback_Coupons_Icons_Shortcode $icons_shortcode = null;

    public static function init(): void {
        // AS-hook handler для cron.
        add_action( Cashback_Promocodes_Fetcher::CRON_HOOK, array( __CLASS__, 'handle_cron_fetch_all' ) );

        // Register cron при init.
        add_action( 'init', array( __CLASS__, 'register_cron' ), 20 );

        // Register шорткод [cashback_promocodes] СРАЗУ — без add_action('init').
        // WoodMart custom-tabs могут вызывать do_shortcode на этапе template_redirect
        // или раньше; раннее register_shortcode гарантирует что шорткод доступен.
        // add_shortcode по сути просто пишет в global $shortcode_tags — безопасно.
        if ( class_exists( 'Cashback_Promocodes_Shortcode' ) ) {
            self::get_shortcode()->register();
        }

        // Register шорткод [cashback_coupons_icons] (иконки активных купонов
        // товара с tooltip и переходом на таб «Купоны»).
        if ( class_exists( 'Cashback_Coupons_Icons_Shortcode' ) ) {
            self::get_icons_shortcode()->register();
        }

        // Settings API + admin-блок в подвкладке «Шорткоды».
        if ( class_exists( 'Cashback_Coupons_Icons_Admin' ) ) {
            Cashback_Coupons_Icons_Admin::init();
        }

        // JS-активатор таба товара по ?cb_tab= (single-product only).
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_tab_activator' ) );

        // Click-tracker AJAX (auth + nopriv) — для copy-кликов.
        if ( class_exists( 'Cashback_Promocodes_Tracker' ) ) {
            Cashback_Promocodes_Tracker::init();
        }

        // Серверный redirect-handler для goto-клика (?cashback_promo_click=ID):
        // зеркалит wc-affiliate-url-params для WC external product, генерирует
        // click_id, подставляет CPA-параметры в goto_link и пишет в click_log.
        if ( class_exists( 'Cashback_Promocodes_Redirect' ) ) {
            Cashback_Promocodes_Redirect::init();
        }

        // Safety-backfill cron: дозаписывает в cashback_promocode_clicks записи,
        // которых нет, но есть в cashback_click_log с promocode_id IS NOT NULL.
        // Покрывает редкий случай когда runtime stat-INSERT упал после успешного
        // финансового TX (атомарность кross-таблицу через cron, не TX).
        if ( class_exists( 'Cashback_Promocodes_Click_Backfill' ) ) {
            Cashback_Promocodes_Click_Backfill::init();
        }

        // Admin AJAX manual refresh (метабокс «Обновить промокоды»).
        if ( is_admin() && class_exists( 'Cashback_Promocodes_Admin' ) ) {
            Cashback_Promocodes_Admin::init();
        }

        // WoodMart custom-tabs (CPT 'wd_product_tabs') рендерят content
        // через прямой echo в Manager::get_product_tab_content без вызова
        // the_content/do_shortcode. Двухуровневый fix:
        //   1) Filter 'woocommerce_product_tabs' с PHP_INT_MAX priority —
        //      гарантирует выполнение последним. Применяет do_shortcode
        //      ко всем 'content' ключам табов.
        //   2) Filter 'the_content' с приоритетом 11 (после wpautop=10)
        //      на случай если woodmart_get_post_content идёт через
        //      get_the_content + apply_filters('the_content',...) для
        //      Gutenberg-блоков.
        add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'apply_shortcodes_to_extra_tabs' ), PHP_INT_MAX );
    }

    /**
     * @param array<string,array<string,mixed>> $tabs
     * @return array<string,array<string,mixed>>
     */
    public static function apply_shortcodes_to_extra_tabs( array $tabs ): array {
        foreach ( $tabs as &$tab ) {
            if ( ! isset( $tab['content'] ) || ! is_string( $tab['content'] ) || $tab['content'] === '' ) {
                // Кастомные WoodMart-табы передают callback, который читает content
                // из $product_tab['content']. Если callback указан, но content нет —
                // не модифицируем (не наш кейс).
                continue;
            }
            $had_promocodes = function_exists( 'has_shortcode' )
                && has_shortcode( $tab['content'], 'cashback_promocodes' );

            $tab['content'] = do_shortcode( $tab['content'] );

            // Невидимый маркер в title таба для JS-активатора шорткода
            // [cashback_coupons_icons] (?cb_tab=coupons → click + scrollIntoView).
            // Идемпотентно: повторный вызов не дублирует маркер.
            if ( $had_promocodes
                && isset( $tab['title'] )
                && is_string( $tab['title'] )
                && strpos( $tab['title'], 'data-cb-coupons-tab' ) === false
            ) {
                $tab['title'] = '<span data-cb-coupons-tab="1" hidden></span>' . $tab['title'];
            }
        }
        return $tabs;
    }

    public static function get_shortcode(): Cashback_Promocodes_Shortcode {
        if ( self::$shortcode === null ) {
            self::$shortcode = new Cashback_Promocodes_Shortcode( self::get_repository() );
        }
        return self::$shortcode;
    }

    public static function get_icons_shortcode(): Cashback_Coupons_Icons_Shortcode {
        if ( self::$icons_shortcode === null ) {
            self::$icons_shortcode = new Cashback_Coupons_Icons_Shortcode( self::get_repository() );
        }
        return self::$icons_shortcode;
    }

    /**
     * Подключает JS-активатор таба товара (cb_tab=...) только на single-product.
     */
    public static function enqueue_tab_activator(): void {
        if ( ! function_exists( 'is_singular' ) || ! is_singular( 'product' ) ) {
            return;
        }
        if ( ! function_exists( 'wp_enqueue_script' ) ) {
            return;
        }
        $plugin_root_file = dirname( __DIR__, 2 ) . '/cashback-plugin.php';
        wp_enqueue_script(
            'cashback-coupons-tab',
            plugins_url( 'assets/js/cashback-coupons-tab.js', $plugin_root_file ),
            array(),
            '7.5.11',
            true
        );
    }

    /**
     * Зарегистрировать рекуррентные AS-jobs (fetch + backfill).
     */
    public static function register_cron(): void {
        self::get_fetcher()->register_cron();
        if ( class_exists( 'Cashback_Promocodes_Click_Backfill' ) ) {
            Cashback_Promocodes_Click_Backfill::register_cron();
        }
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
