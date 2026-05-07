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

    public const REFRESH_AJAX_ACTION  = 'cashback_promocodes_refresh_product';
    public const REFRESH_NONCE        = 'cashback_refresh_product_promocodes';
    public const PAGE_SLUG            = 'cashback-promocodes';
    public const FETCH_ALL_NONCE      = 'cashback_promocodes_fetch_all';

    public static function init(): void {
        add_action( 'wp_ajax_' . self::REFRESH_AJAX_ACTION, array( __CLASS__, 'handle_refresh_product' ) );
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ), 30 );
        add_action( 'admin_post_cashback_promocodes_fetch_all', array( __CLASS__, 'handle_admin_fetch_all' ) );
    }

    public static function register_admin_page(): void {
        add_submenu_page(
            'cashback-overview',
            __( 'Промокоды', 'cashback-plugin' ),
            __( 'Промокоды', 'cashback-plugin' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_admin_page' )
        );
    }

    /**
     * Обработка кнопки «Запустить fetch вручную» (admin-post, redirect).
     */
    public static function handle_admin_fetch_all(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Недостаточно прав', 'cashback-plugin' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( self::FETCH_ALL_NONCE );

        $status = 'ok';
        $detail = '';
        try {
            if ( class_exists( 'Cashback_Promocodes_Bootstrap' ) ) {
                $summary = Cashback_Promocodes_Bootstrap::get_fetcher()->fetch_all();
                $detail  = sprintf(
                    'processed=%d skipped=%d failed=%d upserted=%d',
                    (int) ( $summary['networks_processed'] ?? 0 ),
                    (int) ( $summary['networks_skipped'] ?? 0 ),
                    (int) ( $summary['networks_failed'] ?? 0 ),
                    (int) ( $summary['total_upserted'] ?? 0 )
                );
            } else {
                $status = 'error';
                $detail = 'bootstrap_unavailable';
            }
        } catch ( \Throwable $e ) {
            $status = 'error';
            $detail = $e->getMessage();
        }

        wp_safe_redirect( add_query_arg(
            array(
                'page'           => self::PAGE_SLUG,
                'fetch_status'   => $status,
                'fetch_detail'   => rawurlencode( $detail ),
            ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    /**
     * Render read-only списка промокодов + manual fetch button.
     */
    public static function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Недостаточно прав', 'cashback-plugin' ), '', array( 'response' => 403 ) );
        }

        global $wpdb;
        $promo_table   = $wpdb->prefix . 'cashback_promocodes';
        $clicks_table  = $wpdb->prefix . 'cashback_promocode_clicks';
        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only фильтры списка через GET, capability=manage_options проверена выше.
        $page_num    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $per_page    = 50;
        $offset      = ( $page_num - 1 ) * $per_page;
        $only_active = ! ( isset( $_GET['active'] ) && $_GET['active'] === '0' );
        $search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only admin list.
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT p.*, n.name AS network_name, n.slug AS network_slug FROM %i p LEFT JOIN %i n ON n.id = p.network_id WHERE ( %d = 0 OR p.is_active = 1 ) AND ( %s = %s OR p.name LIKE %s OR p.promocode LIKE %s ) ORDER BY p.fetched_at DESC LIMIT %d OFFSET %d',
            $promo_table,
            $networks_table,
            $only_active ? 1 : 0,
            $search,
            '',
            '%' . $wpdb->esc_like( $search ) . '%',
            '%' . $wpdb->esc_like( $search ) . '%',
            $per_page,
            $offset
        ), ARRAY_A );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM %i p WHERE ( %d = 0 OR p.is_active = 1 ) AND ( %s = %s OR p.name LIKE %s OR p.promocode LIKE %s )',
            $promo_table,
            $only_active ? 1 : 0,
            $search,
            '',
            '%' . $wpdb->esc_like( $search ) . '%',
            '%' . $wpdb->esc_like( $search ) . '%'
        ) );

        // phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only flash-message после redirect от admin-post (nonce проверен в handle_admin_fetch_all). $fetch_detail санитизуется через sanitize_text_field после rawurldecode.
        $fetch_status = isset( $_GET['fetch_status'] ) ? sanitize_key( wp_unslash( $_GET['fetch_status'] ) ) : '';
        $fetch_detail_raw = isset( $_GET['fetch_detail'] ) ? wp_unslash( $_GET['fetch_detail'] ) : '';
        $fetch_detail     = sanitize_text_field( rawurldecode( (string) $fetch_detail_raw ) );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Промокоды CPA-сетей', 'cashback-plugin' ); ?></h1>

            <?php if ( $fetch_status === 'ok' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Fetch завершён', 'cashback-plugin' ) . ': ' . esc_html( $fetch_detail ); ?></p></div>
            <?php elseif ( $fetch_status === 'error' ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html__( 'Fetch ошибка', 'cashback-plugin' ) . ': ' . esc_html( $fetch_detail ); ?></p></div>
            <?php endif; ?>

            <form method="get" style="margin-bottom:12px;">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Поиск по name/code', 'cashback-plugin' ); ?>" class="regular-text">
                <label><input type="checkbox" name="active" value="0" <?php checked( ! $only_active ); ?>> <?php echo esc_html__( 'Включая неактивные', 'cashback-plugin' ); ?></label>
                <button type="submit" class="button"><?php echo esc_html__( 'Применить', 'cashback-plugin' ); ?></button>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
                <input type="hidden" name="action" value="cashback_promocodes_fetch_all">
                <?php wp_nonce_field( self::FETCH_ALL_NONCE ); ?>
                <button type="submit" class="button button-primary"><?php echo esc_html__( 'Запустить fetch вручную', 'cashback-plugin' ); ?></button>
                <span class="description" style="margin-left:8px;"><?php echo esc_html__( 'Выполнит fetch_all для всех активных сетей с GET_LOCK. Может занять несколько секунд.', 'cashback-plugin' ); ?></span>
            </form>

            <p>
                <?php
                /* translators: %d — общее количество промокодов в выборке. */
                echo esc_html( sprintf( __( 'Всего: %d', 'cashback-plugin' ), $total ) );
                ?>
            </p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__( 'Сеть', 'cashback-plugin' ); ?></th>
                        <th><?php echo esc_html__( 'Кампания', 'cashback-plugin' ); ?></th>
                        <th><?php echo esc_html__( 'Тип', 'cashback-plugin' ); ?></th>
                        <th><?php echo esc_html__( 'Код', 'cashback-plugin' ); ?></th>
                        <th><?php echo esc_html__( 'Название', 'cashback-plugin' ); ?></th>
                        <th><?php echo esc_html__( 'Скидка', 'cashback-plugin' ); ?></th>
                        <th><?php echo esc_html__( 'Действует до', 'cashback-plugin' ); ?></th>
                        <th><?php echo esc_html__( 'Active', 'cashback-plugin' ); ?></th>
                        <th><?php echo esc_html__( 'Кликов 7д', 'cashback-plugin' ); ?></th>
                        <th><?php echo esc_html__( 'Fetched at (UTC)', 'cashback-plugin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="10"><?php echo esc_html__( 'Промокоды не найдены.', 'cashback-plugin' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <?php
                        $clicks_7d = self::clicks_count_7d( (int) $row['id'] );
                        ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $row['network_name'] ?? $row['network_slug'] ) ); ?></td>
                            <td><?php echo esc_html( (string) $row['advcampaign_id'] ); ?></td>
                            <td><?php echo esc_html( (string) $row['species'] ); ?></td>
                            <td><code><?php echo esc_html( (string) ( $row['promocode'] ?? '' ) ); ?></code></td>
                            <td><?php echo esc_html( (string) $row['name'] ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['discount'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['date_end'] ?? '' ) ); ?></td>
                            <td><?php echo $row['is_active'] ? '✓' : '<span style="color:#999;">✗</span>'; ?></td>
                            <td><?php echo esc_html( (string) $clicks_7d ); ?></td>
                            <td><?php echo esc_html( (string) $row['fetched_at'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php
            $promo_add_args = array();
            if ( $search !== '' ) {
                $promo_add_args['s'] = $search;
            }
            if ( ! $only_active ) {
                $promo_add_args['active'] = '0';
            }
            Cashback_Pagination::render( array(
                'total_items'  => $total,
                'per_page'     => $per_page,
                'current_page' => $page_num,
                'total_pages'  => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
                'page_slug'    => self::PAGE_SLUG,
                'add_args'     => $promo_add_args,
            ) );
            ?>
        </div>
        <?php
    }

    private static function clicks_count_7d( int $promocode_id ): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only stats.
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE promocode_id = %d AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)',
            $wpdb->prefix . 'cashback_promocode_clicks',
            $promocode_id
        ) );
    }

    public static function handle_refresh_product(): void {
        // phpcs:ignore WordPress.WP.Capabilities.Unknown -- edit_products — стандартный WC capability (зарегистрирован WooCommerce).
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

        // Idempotency защита от двойного клика (TTL 30s).
        if ( class_exists( 'Cashback_Idempotency' ) ) {
            $idem_user = (int) get_current_user_id();
            $claimed   = Cashback_Idempotency::claim( 'promocode_refresh', $idem_user, (string) $product_id, 30 );
            if ( ! $claimed ) {
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
                echo esc_html( sprintf( __( '%d активных', 'cashback-plugin' ), $count ) );
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
