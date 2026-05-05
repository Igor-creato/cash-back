<?php

/**
 * Шорткод [cashback_coupons_icons] — иконки активных купонов товара.
 *
 * Выводит ряд иконок (по одной на УНИКАЛЬНЫЙ icon_type:
 * discount/gift/free_shipping) с tooltip и переходом на permalink товара
 * с query-параметром ?cb_tab=coupons. Иконки берутся из опции
 * cashback_coupons_icons_settings (attachment_id из Media Library).
 *
 * Атрибуты:
 *   - id    — WC product ID (default: get_the_ID()).
 *   - class — extra CSS-классы.
 *   - tab   — slug таба купонов (default: 'coupons').
 *   - size  — sm|md|lg (default: md).
 *   - icons — CSV override: 'discount,gift,free_shipping'.
 *
 * @package CashbackPlugin
 * @since   7.5.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cashback_Coupons_Icons_Shortcode {

    public const OPTION_NAME = 'cashback_coupons_icons_settings';

    /**
     * Стабильный порядок icon_type в выводе.
     */
    private const ICON_ORDER = array( 'discount', 'gift', 'free_shipping' );

    /**
     * Per-request memoization: product_id → string[] icon_types.
     *
     * @var array<int,array<int,string>>
     */
    private static array $request_cache = array();

    public function __construct(
        private Cashback_Promocodes_Repository $repository
    ) {}

    public function register(): void {
        if ( function_exists( 'add_shortcode' ) ) {
            add_shortcode( 'cashback_coupons_icons', array( $this, 'render' ) );
        }
    }

    /**
     * @param array<string,mixed>|string $atts
     */
    public function render( mixed $atts ): string {
        $atts = function_exists( 'shortcode_atts' )
            ? shortcode_atts(
                array(
                    'id'    => '0',
                    'class' => '',
                    'tab'   => 'coupons',
                    'size'  => 'md',
                    'icons' => '',
                ),
                is_array( $atts ) ? $atts : array(),
                'cashback_coupons_icons'
            )
            : array_merge(
                array( 'id' => '0', 'class' => '', 'tab' => 'coupons', 'size' => 'md', 'icons' => '' ),
                is_array( $atts ) ? $atts : array()
            );

        $product_id = (int) $atts['id'];
        if ( $product_id <= 0 && function_exists( 'get_the_ID' ) ) {
            $product_id = (int) get_the_ID();
        }
        if ( $product_id <= 0 ) {
            return '';
        }

        $icon_types = $this->resolve_icon_types_for_product( $product_id );
        if ( empty( $icon_types ) ) {
            return '';
        }

        // Override через атрибут icons=.
        $atts_icons = (string) $atts['icons'];
        if ( $atts_icons !== '' ) {
            $allow = array_values( array_filter(
                array_map( 'trim', explode( ',', $atts_icons ) ),
                static fn( $s ) => in_array( $s, self::ICON_ORDER, true )
            ) );
            if ( ! empty( $allow ) ) {
                $icon_types = array_values( array_intersect( $icon_types, $allow ) );
            }
        }

        // Фильтруем по наличию опции-attachment'а и собираем срезы для рендера.
        $option        = function_exists( 'get_option' ) ? get_option( self::OPTION_NAME, array() ) : array();
        $option        = is_array( $option ) ? $option : array();
        $rendered_icons = array();
        foreach ( self::ICON_ORDER as $type ) {
            if ( ! in_array( $type, $icon_types, true ) ) {
                continue;
            }
            $aid = isset( $option[ $type ] ) ? (int) $option[ $type ] : 0;
            if ( $aid <= 0 ) {
                continue;
            }
            $url = function_exists( 'wp_get_attachment_image_url' )
                ? wp_get_attachment_image_url( $aid, 'thumbnail' )
                : false;
            if ( $url === false ) {
                continue;
            }
            $rendered_icons[] = array(
                'type'          => $type,
                'attachment_id' => $aid,
            );
        }

        if ( empty( $rendered_icons ) ) {
            return '';
        }

        $this->enqueue_assets();

        return $this->render_html( $product_id, $rendered_icons, $atts );
    }

    /**
     * Resolve product_id → array of unique icon_types.
     */
    private function resolve_icon_types_for_product( int $product_id ): array {
        if ( isset( self::$request_cache[ $product_id ] ) ) {
            return self::$request_cache[ $product_id ];
        }

        $network_id     = (int) get_post_meta( $product_id, '_affiliate_network_id', true );
        $advcampaign_id = (string) get_post_meta( $product_id, '_offer_id', true );

        // Fallback на parent для variations.
        if ( ( $network_id <= 0 || $advcampaign_id === '' ) && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $product_id );
            if ( $product && method_exists( $product, 'get_parent_id' ) ) {
                $parent_id = (int) $product->get_parent_id();
                if ( $parent_id > 0 && $parent_id !== $product_id ) {
                    $network_id     = $network_id > 0 ? $network_id : (int) get_post_meta( $parent_id, '_affiliate_network_id', true );
                    $advcampaign_id = $advcampaign_id !== '' ? $advcampaign_id : (string) get_post_meta( $parent_id, '_offer_id', true );
                }
            }
        }

        if ( $network_id <= 0 || $advcampaign_id === '' ) {
            self::$request_cache[ $product_id ] = array();
            return array();
        }

        $rows = $this->repository->get_distinct_species_for_campaign( $network_id, $advcampaign_id );
        if ( empty( $rows ) ) {
            self::$request_cache[ $product_id ] = array();
            return array();
        }

        $icon_types = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            if ( class_exists( 'Cashback_Coupons_Icon_Resolver' ) ) {
                $icon_types[] = Cashback_Coupons_Icon_Resolver::resolve( $row );
            }
        }
        $icon_types                          = array_values( array_unique( $icon_types ) );
        self::$request_cache[ $product_id ] = $icon_types;
        return $icon_types;
    }

    /**
     * @param array<int,array{type:string,attachment_id:int}> $icons
     * @param array<string,mixed>                              $atts
     */
    private function render_html( int $product_id, array $icons, array $atts ): string {
        $size_raw = (string) ( $atts['size'] ?? 'md' );
        $size     = in_array( $size_raw, array( 'sm', 'md', 'lg' ), true ) ? $size_raw : 'md';

        $tab_slug = function_exists( 'sanitize_html_class' )
            ? sanitize_html_class( (string) $atts['tab'], 'coupons' )
            : (string) $atts['tab'];
        if ( $tab_slug === '' ) {
            $tab_slug = 'coupons';
        }

        $extra_class = (string) ( $atts['class'] ?? '' );
        $extra_class = $extra_class !== '' ? ' ' . esc_attr( $extra_class ) : '';

        $permalink = function_exists( 'get_permalink' ) ? (string) get_permalink( $product_id ) : '';
        $href_base = function_exists( 'add_query_arg' )
            ? add_query_arg( array( 'cb_tab' => $tab_slug ), $permalink )
            : ( $permalink . ( strpos( $permalink, '?' ) === false ? '?' : '&' ) . 'cb_tab=' . rawurlencode( $tab_slug ) );

        $items_html = '';
        foreach ( $icons as $icon ) {
            $items_html .= $this->render_item( $icon['type'], $icon['attachment_id'], $href_base );
        }

        return sprintf(
            '<span class="cashback-coupons-icons cashback-coupons-icons--%1$s%2$s" data-product-id="%3$d">%4$s</span>',
            esc_attr( $size ),
            $extra_class,
            $product_id,
            $items_html
        );
    }

    private function render_item( string $type, int $attachment_id, string $href_base ): string {
        $labels = array(
            'discount'      => __( 'Купон на скидку', 'cashback-plugin' ),
            'gift'          => __( 'Подарок при покупке', 'cashback-plugin' ),
            'free_shipping' => __( 'Бесплатная доставка', 'cashback-plugin' ),
        );
        $label = $labels[ $type ] ?? '';

        $img = function_exists( 'wp_get_attachment_image' )
            ? wp_get_attachment_image(
                $attachment_id,
                array( 32, 32 ),
                false,
                array(
                    'alt'     => '',
                    'loading' => 'lazy',
                )
            )
            : '';

        return sprintf(
            '<a class="cashback-coupons-icons__item cashback-coupons-icons__item--%1$s" href="%2$s" data-cb-icon-type="%1$s" aria-label="%3$s" title="%3$s">%4$s<span class="cashback-coupons-icons__tooltip">%3$s</span></a>',
            esc_attr( $type ),
            esc_url( $href_base ),
            esc_attr( $label ),
            $img
        );
    }

    private function enqueue_assets(): void {
        if ( ! function_exists( 'wp_enqueue_style' ) ) {
            return;
        }

        $plugin_root_file = dirname( __DIR__, 2 ) . '/cashback-plugin.php';

        wp_enqueue_style(
            'cashback-coupons-icons',
            plugins_url( 'assets/css/coupons-icons.css', $plugin_root_file ),
            array(),
            '7.5.1'
        );
    }
}
