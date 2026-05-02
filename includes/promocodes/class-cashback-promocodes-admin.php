<?php

/**
 * Admin UI промокодов: AJAX handler manual refresh + read-only список.
 *
 * AJAX endpoint cashback_promocodes_refresh_product:
 *   - Capability edit_products (стандарт WC).
 *   - Nonce 'cashback_refresh_product_promocodes'.
 *   - Idempotency через Cashback_Idempotency::claim() (защита от двойного клика).
 *   - Вызывает Cashback_Promocodes_Fetcher::fetch_for_product_pair().
 *   - Возвращает JSON с count + last fetched_at + статус.
 *
 * @package CashbackPlugin
 * @since   7.2.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Promocodes_Admin {

    public const REFRESH_AJAX_ACTION = 'cashback_promocodes_refresh_product';
    public const REFRESH_NONCE       = 'cashback_refresh_product_promocodes';

    public static function init(): void {
        add_action( 'wp_ajax_' . self::REFRESH_AJAX_ACTION, array( __CLASS__, 'handle_refresh_product' ) );
    }

    public static function handle_refresh_product(): void {
        if ( ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
            return;
        }

        if ( ! check_ajax_referer( self::REFRESH_NONCE, '_wpnonce', false ) ) {
            wp_send_json_error( array( 'code' => 'invalid_nonce' ), 403 );
            return;
        }

        $product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
        if ( $product_id <= 0 ) {
            wp_send_json_error( array( 'code' => 'invalid_product_id' ), 400 );
            return;
        }

        $network_id     = (int) get_post_meta( $product_id, '_affiliate_network_id', true );
        $advcampaign_id = (string) get_post_meta( $product_id, '_offer_id', true );

        if ( $network_id <= 0 || $advcampaign_id === '' ) {
            wp_send_json_error( array( 'code' => 'product_missing_offer_id' ), 400 );
            return;
        }

        // Idempotency защита от двойного клика.
        if ( class_exists( 'Cashback_Idempotency' ) ) {
            $key       = 'promocode_refresh_' . $product_id;
            $idem_user = (int) get_current_user_id();
            $claim     = Cashback_Idempotency::claim( $key, $idem_user, 30 );
            if ( ! $claim['acquired'] ) {
                wp_send_json_error( array( 'code' => 'already_running', 'message' => 'Refresh уже выполняется' ), 409 );
                return;
            }
        }

        if ( ! class_exists( 'Cashback_Promocodes_Bootstrap' ) ) {
            wp_send_json_error( array( 'code' => 'bootstrap_unavailable' ), 500 );
            return;
        }

        $fetcher = Cashback_Promocodes_Bootstrap::get_fetcher();
        $result  = $fetcher->fetch_for_product_pair( $network_id, $advcampaign_id );

        wp_send_json_success(
            array(
                'product_id'  => $product_id,
                'upserted'    => (int) ( $result['upserted'] ?? 0 ),
                'deactivated' => (int) ( $result['deactivated'] ?? 0 ),
                'error'       => $result['error'] ?? null,
                'fetched_at'  => gmdate( 'Y-m-d H:i:s' ),
            )
        );
    }

    /**
     * Inline-блок для product metabox: индикатор + кнопка «Обновить промокоды».
     * Возвращает HTML — caller вставляет в metabox.
     */
    public static function render_product_refresh_block( int $product_id ): string {
        if ( $product_id <= 0 ) {
            return '';
        }

        $offer_id = (string) get_post_meta( $product_id, '_offer_id', true );
        if ( $offer_id === '' ) {
            return '';
        }

        $nonce = wp_create_nonce( self::REFRESH_NONCE );
        $count = self::count_active_for_product( $product_id );
        $last  = self::last_fetched_for_product( $product_id );

        ob_start();
        ?>
        <div class="cashback-promocodes-refresh-block" style="padding:8px 12px; margin-top:8px; background:#f6f7f7; border-left:4px solid #2271b1;">
            <strong><?php echo esc_html__( 'Промокоды кампании', 'cashback-plugin' ); ?>:</strong>
            <span class="cashback-promo-count" data-product-id="<?php echo esc_attr( (string) $product_id ); ?>">
                <?php
                /* translators: %d — количество активных промокодов. */
                echo esc_html( sprintf( _n( '%d активный', '%d активных', $count, 'cashback-plugin' ), $count ) );
                ?>
            </span>
            <?php if ( $last !== '' ) : ?>
                <span class="cashback-promo-last" style="color:#666; font-size:12px;">
                    <?php
                    /* translators: %s — UTC datetime. */
                    echo esc_html( sprintf( __( '— последний fetch %s UTC', 'cashback-plugin' ), $last ) );
                    ?>
                </span>
            <?php endif; ?>
            <br>
            <button type="button" class="button cashback-refresh-promocodes-btn"
                    data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>"
                    style="margin-top:6px;">
                <?php echo esc_html__( 'Обновить промокоды сейчас', 'cashback-plugin' ); ?>
            </button>
            <span class="cashback-refresh-status" style="margin-left:8px;"></span>
        </div>
        <script>
        (function(){
            var btns = document.querySelectorAll('.cashback-refresh-promocodes-btn');
            btns.forEach(function(btn){
                if (btn._cb_bound) return;
                btn._cb_bound = true;
                btn.addEventListener('click', function(){
                    var pid = btn.getAttribute('data-product-id');
                    var nonce = btn.getAttribute('data-nonce');
                    var status = btn.parentElement.querySelector('.cashback-refresh-status');
                    btn.disabled = true;
                    status.textContent = '...';
                    var body = new URLSearchParams();
                    body.set('action', '<?php echo esc_js( self::REFRESH_AJAX_ACTION ); ?>');
                    body.set('product_id', pid);
                    body.set('_wpnonce', nonce);
                    fetch(ajaxurl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body:body.toString()})
                        .then(function(r){return r.json();})
                        .then(function(j){
                            btn.disabled = false;
                            if (j && j.success) {
                                status.textContent = '✓ ' + (j.data.upserted||0) + ' upserted, ' + (j.data.deactivated||0) + ' deactivated';
                                status.style.color = '#46b450';
                            } else {
                                status.textContent = 'Ошибка: ' + ((j && j.data && j.data.code) || 'unknown');
                                status.style.color = '#dc3232';
                            }
                        })
                        .catch(function(e){
                            btn.disabled = false;
                            status.textContent = 'Network error';
                            status.style.color = '#dc3232';
                        });
                });
            });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    private static function count_active_for_product( int $product_id ): int {
        global $wpdb;
        $network_id     = (int) get_post_meta( $product_id, '_affiliate_network_id', true );
        $advcampaign_id = (string) get_post_meta( $product_id, '_offer_id', true );
        if ( $network_id <= 0 || $advcampaign_id === '' ) {
            return 0;
        }
        $sql = $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE network_id = %d AND advcampaign_id = %s AND is_active = 1',
            $wpdb->prefix . 'cashback_promocodes',
            $network_id,
            $advcampaign_id
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared.
        return (int) $wpdb->get_var( $sql );
    }

    private static function last_fetched_for_product( int $product_id ): string {
        global $wpdb;
        $network_id     = (int) get_post_meta( $product_id, '_affiliate_network_id', true );
        $advcampaign_id = (string) get_post_meta( $product_id, '_offer_id', true );
        if ( $network_id <= 0 || $advcampaign_id === '' ) {
            return '';
        }
        $sql = $wpdb->prepare(
            'SELECT MAX(fetched_at) FROM %i WHERE network_id = %d AND advcampaign_id = %s',
            $wpdb->prefix . 'cashback_promocodes',
            $network_id,
            $advcampaign_id
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared.
        return (string) $wpdb->get_var( $sql );
    }
}
