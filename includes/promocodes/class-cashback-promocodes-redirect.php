<?php

/**
 * Серверный handler клика по кнопке «Перейти в магазин» в карточке промокода.
 *
 * Точка входа: GET /?cashback_promo_click={promocode_id}
 *
 * Зеркалит поведение wc-affiliate-url-params.php для обычной WC-кнопки внешнего
 * товара: генерирует UUID v7 click_id, подставляет CPA-параметры
 * (subid/uuid/литералы) поверх goto_link купона, пишет в cashback_click_log
 * и cashback_click_sessions (через Cashback_Click_Session_Service::activate_for_promocode),
 * параллельно ведёт статистику в cashback_promocode_clicks.
 *
 * Для гостей — мгновенный 302 на финальный affiliate URL, для авторизованных —
 * редирект на activation-page (?cashback_go=1) с 5-сек countdown.
 *
 * @package CashbackPlugin
 * @since   7.3.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cashback_Promocodes_Redirect {

    public const QUERY_VAR = 'cashback_promo_click';

    private const SOURCE_PID_TRANSIENT_PREFIX = 'cb_promo_src_pid_';
    private const SOURCE_PID_TTL              = 600; // 10 мин

    public static function init(): void {
        // Priority 1 — раньше темы, как handle_click_redirect для WC-кнопки.
        add_action( 'template_redirect', array( __CLASS__, 'handle_promo_click_redirect' ), 1 );
    }

    public static function handle_promo_click_redirect(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public partner link, защита через rate-limit + bot-UA (как ?cashback_click=).
        $promo_id = isset( $_GET[ self::QUERY_VAR ] ) ? absint( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : 0;
        if ( $promo_id <= 0 ) {
            return;
        }

        nocache_headers();

        $row = self::load_promocode_row( $promo_id );
        if ( $row === null ) {
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Internal home_url() fallback.
            wp_redirect( home_url(), 302 );
            exit;
        }

        $network_id     = (int) $row['network_id'];
        $advcampaign_id = (string) $row['advcampaign_id'];
        $goto_link      = (string) $row['goto_link'];

        $source_product_id = self::resolve_source_product_id( $network_id, $advcampaign_id );
        if ( $source_product_id <= 0 ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic: grep [Cashback Promo Redirect] no source product.
            error_log( sprintf(
                '[Cashback Promo Redirect] No source product for promo #%d (network_id=%d, advcampaign_id=%s)',
                $promo_id,
                $network_id,
                $advcampaign_id
            ) );
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Internal home_url() fallback.
            wp_redirect( home_url(), 302 );
            exit;
        }

        $user_id    = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        $ip_address = class_exists( 'Cashback_Encryption' ) ? Cashback_Encryption::get_client_ip() : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- sanitized below.
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
            : null;
        $referer    = wp_get_referer();
        if ( ! $referer ) {
            $referer = get_permalink( $source_product_id ) ?: null;
        }

        $force_spam = false;
        if ( class_exists( 'WC_Affiliate_URL_Params' ) ) {
            $force_spam = WC_Affiliate_URL_Params::instance()->is_bot_user_agent( $user_agent ?? '' );
        }

        try {
            $result = Cashback_Click_Session_Service::activate_for_promocode( array(
                'promocode_id' => $promo_id,
                'product_id'   => $source_product_id,
                'network_id'   => $network_id,
                'goto_link'    => $goto_link,
                'user_id'      => $user_id,
                'ip_address'   => $ip_address,
                'user_agent'   => $user_agent,
                'referer'      => $referer,
                'force_spam'   => $force_spam,
            ) );
        } catch ( \Throwable $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic.
            error_log( '[Cashback Promo Redirect] activate_for_promocode threw: ' . get_class( $e ) . ' ' . $e->getMessage() );
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Internal home_url() fallback.
            wp_redirect( home_url(), 302 );
            exit;
        }

        switch ( $result['status'] ?? 'error' ) {
            case 'invalid_product':
            case 'no_url':
                // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Internal home_url() fallback.
                wp_redirect( home_url(), 302 );
                exit;
            case 'rate_limited':
                status_header( 429 );
                nocache_headers();
                exit;
            case 'error':
                // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Internal home_url() fallback on TX error.
                wp_redirect( home_url(), 302 );
                exit;
        }

        $click_id      = (string) ( $result['canonical_click_id'] ?? '' );
        $affiliate_url = (string) ( $result['affiliate_url'] ?? '' );

        if ( $click_id === '' || ! Cashback_Click_Session_Service::is_safe_http_url( $affiliate_url ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic: grep [Cashback Promo Redirect] unsafe scheme.
            error_log( sprintf(
                '[Cashback Promo Redirect] Rejected unsafe affiliate scheme for promo #%d',
                $promo_id
            ) );
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Internal home_url() fallback.
            wp_redirect( home_url(), 302 );
            exit;
        }

        // Параллельно пишем в stat-таблицу промокодов (для отчётов админки).
        if ( class_exists( 'Cashback_Promocodes_Tracker' ) ) {
            Cashback_Promocodes_Tracker::record_click_internal(
                $promo_id,
                $source_product_id,
                $user_id,
                $ip_address,
                (string) ( $user_agent ?? '' ),
                $click_id,
                'goto'
            );
        }

        // Cookie cb_activation для браузерного расширения — домен берётся из source product.
        if ( class_exists( 'WC_Affiliate_URL_Params' ) ) {
            WC_Affiliate_URL_Params::set_activation_cookie( $source_product_id, $click_id );
        }

        if ( $user_id === 0 ) {
            // Гость: мгновенный 302 на CPA URL, без активационной страницы.
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Intentional redirect to external partner; wp_safe_redirect would break CPA tracking.
            wp_redirect( $affiliate_url, 302 );
            exit;
        }

        // Авторизованный: редирект на активационную страницу (handle_activation_page).
        $issued_at        = time();
        $activation_token = Cashback_Encryption::sign_activation_token( $click_id, $user_id, $issued_at );
        $activation_url   = add_query_arg(
            array(
                'cashback_go' => '1',
                'click_id'    => $click_id,
                't'           => $activation_token,
            ),
            home_url( '/' )
        );
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Internal activation page redirect.
        wp_redirect( $activation_url, 302 );
        exit;
    }

    /**
     * Загрузка row промокода из cashback_promocodes по id.
     *
     * @return array{id:int,network_id:int,advcampaign_id:string,goto_link:string}|null
     */
    private static function load_promocode_row( int $promo_id ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_promocodes';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; per-click read is intentional.
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT id, network_id, advcampaign_id, goto_link, is_active
               FROM %i
              WHERE id = %d
              LIMIT 1',
            $table,
            $promo_id
        ), ARRAY_A );

        if ( ! is_array( $row ) ) {
            return null;
        }
        if ( (int) $row['is_active'] !== 1 ) {
            return null;
        }
        if ( (string) $row['goto_link'] === '' ) {
            return null;
        }

        return array(
            'id'             => (int) $row['id'],
            'network_id'     => (int) $row['network_id'],
            'advcampaign_id' => (string) $row['advcampaign_id'],
            'goto_link'      => (string) $row['goto_link'],
        );
    }

    /**
     * Найти WC-product по (network_id, advcampaign_id) — обратный лукап от того,
     * что делает шорткод [cashback_promocodes] (`_affiliate_network_id`+`_offer_id`).
     *
     * Кешируется в transient на 10 минут (лукап повторяется на каждый клик).
     */
    private static function resolve_source_product_id( int $network_id, string $advcampaign_id ): int {
        if ( $network_id <= 0 || $advcampaign_id === '' ) {
            return 0;
        }

        $cache_key = self::SOURCE_PID_TRANSIENT_PREFIX . $network_id . '_' . substr( sha1( $advcampaign_id ), 0, 16 );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            return (int) $cached;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct postmeta lookup, cached via transient above.
        $product_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT pm1.post_id
               FROM {$wpdb->postmeta} pm1
               JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm1.post_id
               JOIN {$wpdb->posts} p ON p.ID = pm1.post_id
              WHERE pm1.meta_key = '_affiliate_network_id' AND pm1.meta_value = %s
                AND pm2.meta_key = '_offer_id' AND pm2.meta_value = %s
                AND p.post_type = 'product' AND p.post_status = 'publish'
              LIMIT 1",
            (string) $network_id,
            $advcampaign_id
        ) );

        // Cache даже 0 — чтобы не долбить postmeta при отсутствии product'а.
        set_transient( $cache_key, $product_id, self::SOURCE_PID_TTL );

        return $product_id;
    }
}
