<?php

/**
 * Шорткод [cashback_promocodes] для рендера активных купонов товара.
 *
 * Атрибуты:
 *   - product_id  — WC product ID (default: текущий продукт в loop, иначе обязателен).
 *   - limit       — макс. купонов (default 30, hard-cap 100).
 *   - species     — CSV: promocode,deal (default: все).
 *   - layout      — cards|compact (default: cards).
 *
 * Резолвит product_id → (network_id, advcampaign_id) через postmeta
 * '_affiliate_network_id' + '_offer_id'. Repository получает active+RU+dates
 * купоны. Description рендерится через DOMPurify (Cashback_Assets::enqueue_safe_html()).
 *
 * @package CashbackPlugin
 * @since   7.2.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Promocodes_Shortcode {

    private const DEFAULT_LIMIT = 30;
    private const MAX_LIMIT     = 100;

    public function __construct(
        private Cashback_Promocodes_Repository $repository
    ) {}

    public function register(): void {
        add_shortcode( 'cashback_promocodes', array( $this, 'render' ) );
    }

    /**
     * @param array<string,mixed>|string $atts
     */
    public function render( mixed $atts ): string {
        if ( ! is_array( $atts ) ) {
            $atts = array();
        }

        $product_id = isset( $atts['product_id'] ) ? (int) $atts['product_id'] : 0;
        if ( $product_id === 0 && function_exists( 'get_the_ID' ) ) {
            $product_id = (int) get_the_ID();
        }

        if ( $product_id <= 0 ) {
            return $this->render_empty_state();
        }

        $network_id     = (int) get_post_meta( $product_id, '_affiliate_network_id', true );
        $advcampaign_id = (string) get_post_meta( $product_id, '_offer_id', true );

        if ( $network_id <= 0 || $advcampaign_id === '' ) {
            return $this->render_empty_state();
        }

        $filters = $this->parse_filters( $atts );
        $rows    = $this->repository->get_active_for_campaign( $network_id, $advcampaign_id, $filters );

        if ( empty( $rows ) ) {
            return $this->render_empty_state();
        }

        $this->enqueue_assets();

        return $this->render_grid( $rows, $atts );
    }

    /**
     * @param array<string,mixed> $atts
     * @return array{limit:int,species?:array<int,string>}
     */
    private function parse_filters( array $atts ): array {
        $limit_raw = isset( $atts['limit'] ) ? (int) $atts['limit'] : self::DEFAULT_LIMIT;
        $filters   = array(
            'limit' => max( 1, min( self::MAX_LIMIT, $limit_raw ) ),
        );

        if ( isset( $atts['species'] ) && (string) $atts['species'] !== '' ) {
            $species = array_filter( array_map( 'trim', explode( ',', (string) $atts['species'] ) ), static fn( $s ) => $s !== '' );
            if ( ! empty( $species ) ) {
                $filters['species'] = array_values( $species );
            }
        }

        return $filters;
    }

    private function enqueue_assets(): void {
        if ( ! function_exists( 'wp_enqueue_style' ) ) {
            return;
        }

        if ( class_exists( 'Cashback_Assets' ) && method_exists( 'Cashback_Assets', 'enqueue_safe_html' ) ) {
            Cashback_Assets::enqueue_safe_html();
        }

        $plugin_root_file = dirname( __DIR__, 2 ) . '/cashback-plugin.php';

        wp_enqueue_style(
            'cashback-promocodes',
            plugins_url( 'assets/css/promocodes.css', $plugin_root_file ),
            array(),
            '7.3.0'
        );

        $deps = array();
        if ( class_exists( 'Cashback_Assets' ) && method_exists( 'Cashback_Assets', 'safe_html_handle' ) ) {
            $deps[] = Cashback_Assets::safe_html_handle();
        }

        wp_enqueue_script(
            'cashback-promocodes',
            plugins_url( 'assets/js/promocodes.js', $plugin_root_file ),
            $deps,
            '7.3.0',
            true
        );

        if ( function_exists( 'wp_localize_script' ) ) {
            $brand_color = class_exists( 'Cashback_Theme_Color' ) ? Cashback_Theme_Color::get_brand_color() : '#2271b1';

            wp_localize_script( 'cashback-promocodes', 'cashbackPromocodesConfig', array(
                'ajaxUrl'    => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '/wp-admin/admin-ajax.php',
                'nonce'      => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'cashback_promocode_click' ) : '',
                'brandColor' => $brand_color,
            ) );
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed>            $atts
     */
    private function render_grid( array $rows, array $atts ): string {
        $layout      = isset( $atts['layout'] ) && (string) $atts['layout'] === 'compact' ? 'compact' : 'cards';
        $brand_color = class_exists( 'Cashback_Theme_Color' ) ? Cashback_Theme_Color::get_brand_color() : '#2271b1';

        $cards = '';
        foreach ( $rows as $row ) {
            $cards .= $this->render_card( $row );
        }

        return sprintf(
            '<div class="cashback-promocodes cashback-promocodes--%1$s" style="--cashback-brand:%2$s;">%3$s</div>',
            esc_attr( $layout ),
            esc_attr( $brand_color ),
            $cards
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function render_card( array $row ): string {
        $species     = (string) ( $row['species'] ?? 'other' );
        $name        = (string) ( $row['name'] ?? '' );
        $promocode   = isset( $row['promocode'] ) ? (string) $row['promocode'] : '';
        $discount    = (string) ( $row['discount'] ?? '' );
        $date_end    = (string) ( $row['date_end'] ?? '' );
        $is_excl     = ! empty( $row['is_exclusive'] );
        $promo_id    = (int) ( $row['id'] ?? 0 );
        $description = (string) ( $row['description'] ?? '' );

        $has_promocode = $species === 'promocode' && $promocode !== '';

        $parts = array();
        $parts[] = sprintf( '<article class="cashback-promo-card" data-promo-id="%s">', esc_attr( (string) $promo_id ) );

        if ( $is_excl ) {
            $parts[] = '<span class="cashback-promo-card__exclusive">' . esc_html__( 'Эксклюзив', 'cashback-plugin' ) . '</span>';
        }
        if ( $discount !== '' ) {
            $parts[] = '<div class="cashback-promo-card__discount">' . esc_html( $discount ) . '</div>';
        }
        $parts[] = '<h4 class="cashback-promo-card__name">' . esc_html( $name ) . '</h4>';

        if ( $description !== '' ) {
            $parts[] = '<div class="cashback-promo-card__description" data-cashback-safe-html="' . esc_attr( $description ) . '"></div>';
        }

        if ( $has_promocode ) {
            $parts[] = sprintf(
                '<div class="cashback-promo-card__code-row"><code class="cashback-promo-card__code">%1$s</code><button type="button" class="cashback-promo-card__btn cashback-promo-card__btn--copy" data-action="copy" data-promo-id="%2$s" data-clipboard="%1$s">%3$s</button></div>',
                esc_html( $promocode ),
                esc_attr( (string) $promo_id ),
                esc_html__( 'Скопировать', 'cashback-plugin' )
            );
        } else {
            $img_html = '';
            if ( class_exists( 'Cashback_Coupons_Icon_Resolver' ) ) {
                $icon_type = Cashback_Coupons_Icon_Resolver::resolve(
                    array(
                        'species'     => $species,
                        'name'        => $name,
                        'description' => $description,
                    )
                );

                $option_name  = class_exists( 'Cashback_Coupons_Icons_Shortcode' )
                    ? Cashback_Coupons_Icons_Shortcode::OPTION_NAME
                    : 'cashback_coupons_icons_settings';
                $icons_option = function_exists( 'get_option' ) ? get_option( $option_name, array() ) : array();
                $icons_option = is_array( $icons_option ) ? $icons_option : array();
                $aid          = isset( $icons_option[ $icon_type ] ) ? (int) $icons_option[ $icon_type ] : 0;

                if ( $aid > 0 && function_exists( 'wp_get_attachment_image' ) ) {
                    $img_html = (string) wp_get_attachment_image(
                        $aid,
                        array( 96, 96 ),
                        false,
                        array(
                            'alt'         => '',
                            'loading'     => 'lazy',
                            'aria-hidden' => 'true',
                        )
                    );
                }
            }

            $parts[] = '<div class="cashback-promo-card__icon-slot" aria-hidden="true">' . $img_html . '</div>';
        }

        // href ведёт на серверный redirect-handler (Cashback_Promocodes_Redirect),
        // который генерирует click_id, подставляет CPA-параметры (subid/uuid/литералы)
        // в goto_link и пишет в cashback_click_log + cashback_click_sessions —
        // полный аналог потока обычной WC-кнопки внешнего товара. goto_link напрямую
        // в HTML больше не светится (плюс — меньше скрейпинга партнёрских ссылок).
        // Имя query-var дублируется в Cashback_Promocodes_Redirect::QUERY_VAR
        // (использован литерал, чтобы шорткод оставался unit-тестируемым без redirect-класса).
        $goto_url = add_query_arg(
            array( 'cashback_promo_click' => $promo_id ),
            home_url( '/' )
        );
        if ( $date_end !== '' ) {
            /* translators: %s — дата окончания купона. */
            $parts[] = '<div class="cashback-promo-card__date-end">' . esc_html( sprintf( __( 'до %s', 'cashback-plugin' ), $date_end ) ) . '</div>';
        }

        $parts[]  = sprintf(
            '<a class="cashback-promo-card__btn cashback-promo-card__btn--goto" data-action="goto" data-promo-id="%1$s" href="%2$s" target="_blank" rel="nofollow noopener">%3$s</a>',
            esc_attr( (string) $promo_id ),
            esc_url( $goto_url ),
            esc_html__( 'Перейти в магазин', 'cashback-plugin' )
        );

        $parts[] = '</article>';

        return implode( '', $parts );
    }

    private function render_empty_state(): string {
        return '<div class="cashback-promocodes cashback-promocodes--empty"><p>' .
            esc_html__( 'У этого магазина сейчас нет активных промокодов.', 'cashback-plugin' ) .
            '</p></div>';
    }
}
