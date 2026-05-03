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
 * Fallback-поведение: если source product не найден или TX упал, **редирект всё
 * равно идёт на goto_link купона** (с scheme-check), а не на home_url. Атрибуция
 * теряется, но пользователь попадает в магазин — это лучше чем «потерянный клик».
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
    private const SOURCE_PID_TTL              = 600; // 10 мин — кешируем только positive product_id

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
            self::log( 'promocode row not found / inactive / no goto_link', $promo_id );
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Internal home_url() fallback (нет goto_link — некуда вести).
            wp_redirect( home_url(), 302 );
            exit;
        }

        $network_id     = (int) $row['network_id'];
        $advcampaign_id = (string) $row['advcampaign_id'];
        $goto_link      = (string) $row['goto_link'];

        // Pre-validate goto_link здесь же — он же будет fallback'ом во всех путях.
        $safe_goto = self::is_safe_http_url( $goto_link ) ? $goto_link : '';

        $user_id    = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        $ip_address = class_exists( 'Cashback_Encryption' ) ? Cashback_Encryption::get_client_ip() : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- sanitized below.
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
            : null;

        $source_product_id = self::resolve_source_product_id( $network_id, $advcampaign_id );
        if ( $source_product_id <= 0 ) {
            self::log( sprintf(
                'no source product (network_id=%d, advcampaign_id=%s) — fallback to raw goto_link',
                $network_id,
                $advcampaign_id
            ), $promo_id );

            self::record_stat_only( $promo_id, null, $user_id, $ip_address, (string) ( $user_agent ?? '' ) );
            self::redirect_to_partner_or_home( $safe_goto, $promo_id, 'no_source_product' );
        }

        $referer = wp_get_referer();
        if ( ! $referer ) {
            $referer = get_permalink( $source_product_id ) ?: null;
        }

        $force_spam = false;
        if ( class_exists( 'WC_Affiliate_URL_Params' ) ) {
            $force_spam = WC_Affiliate_URL_Params::is_bot_user_agent( $user_agent ?? '' );
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
            self::log( 'activate_for_promocode threw ' . get_class( $e ) . ': ' . $e->getMessage(), $promo_id );
            self::record_stat_only( $promo_id, $source_product_id, $user_id, $ip_address, (string) ( $user_agent ?? '' ) );
            self::redirect_to_partner_or_home( $safe_goto, $promo_id, 'activate_threw' );
        }

        $status = (string) ( $result['status'] ?? 'error' );

        if ( $status === 'rate_limited' ) {
            status_header( 429 );
            nocache_headers();
            exit;
        }

        if ( $status !== 'ok' ) {
            self::log( 'activate_for_promocode returned status=' . $status . ' — fallback to raw goto_link', $promo_id );
            self::record_stat_only( $promo_id, $source_product_id, $user_id, $ip_address, (string) ( $user_agent ?? '' ) );
            self::redirect_to_partner_or_home( $safe_goto, $promo_id, 'activate_status_' . $status );
        }

        $click_id      = (string) ( $result['canonical_click_id'] ?? '' );
        $affiliate_url = (string) ( $result['affiliate_url'] ?? '' );

        if ( $click_id === '' || ! self::is_safe_http_url( $affiliate_url ) ) {
            self::log( 'unsafe affiliate scheme returned by activate — fallback to raw goto_link', $promo_id );
            self::record_stat_only( $promo_id, $source_product_id, $user_id, $ip_address, (string) ( $user_agent ?? '' ) );
            self::redirect_to_partner_or_home( $safe_goto, $promo_id, 'unsafe_scheme' );
        }

        // Параллельно пишем в stat-таблицу промокодов с привязкой к click_id.
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
     * Кешируется в transient на 10 минут — но ТОЛЬКО positive product_id, чтобы
     * temporary miss (race с upsert постмета) не залипал на 10 минут.
     */
    private static function resolve_source_product_id( int $network_id, string $advcampaign_id ): int {
        if ( $network_id <= 0 || $advcampaign_id === '' ) {
            return 0;
        }

        $cache_key = self::SOURCE_PID_TRANSIENT_PREFIX . $network_id . '_' . substr( sha1( $advcampaign_id ), 0, 16 );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false && (int) $cached > 0 ) {
            return (int) $cached;
        }

        global $wpdb;
        // %i для имён таблиц (postmeta/posts) — безопасный prepare.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct postmeta lookup, cached via transient.
        $product_id = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT pm1.post_id
               FROM %i pm1
               JOIN %i pm2 ON pm2.post_id = pm1.post_id
               JOIN %i p ON p.ID = pm1.post_id
              WHERE pm1.meta_key = %s AND pm1.meta_value = %s
                AND pm2.meta_key = %s AND pm2.meta_value = %s
                AND p.post_type = %s AND p.post_status = %s
              LIMIT 1',
            $wpdb->postmeta,
            $wpdb->postmeta,
            $wpdb->posts,
            '_affiliate_network_id',
            (string) $network_id,
            '_offer_id',
            $advcampaign_id,
            'product',
            'publish'
        ) );

        if ( $product_id > 0 ) {
            set_transient( $cache_key, $product_id, self::SOURCE_PID_TTL );
        }

        return $product_id;
    }

    /**
     * Локальная копия scheme-check (http/https only) — чтобы не зависеть от
     * порядка загрузки Cashback_Click_Session_Service.
     */
    private static function is_safe_http_url( string $url ): bool {
        if ( $url === '' ) {
            return false;
        }
        if ( class_exists( 'Cashback_Click_Session_Service' ) ) {
            return Cashback_Click_Session_Service::is_safe_http_url( $url );
        }
        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );

        return in_array( $scheme, array( 'http', 'https' ), true );
    }

    /**
     * Запись в cashback_promocode_clicks без click_id (fallback-путь).
     */
    private static function record_stat_only( int $promo_id, ?int $product_id, int $user_id, string $ip, string $ua ): void {
        if ( ! class_exists( 'Cashback_Promocodes_Tracker' ) ) {
            return;
        }
        Cashback_Promocodes_Tracker::record_click_internal(
            $promo_id,
            $product_id,
            $user_id,
            $ip,
            $ua,
            null,
            'goto'
        );
    }

    /**
     * Финальный fallback: если safe_goto валидный — 302 на партнёра, иначе home.
     * Никогда не возвращает (всегда exit).
     *
     * @param string $safe_goto  Уже проверенный (через is_safe_http_url) goto_link или ''.
     * @param int    $promo_id   ID промокода для логов.
     * @param string $reason     Причина fallback'а (для лога).
     */
    private static function redirect_to_partner_or_home( string $safe_goto, int $promo_id, string $reason ): void {
        if ( $safe_goto !== '' ) {
            self::log( 'fallback redirect to raw goto_link, reason=' . $reason, $promo_id );
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Intentional redirect to external partner.
            wp_redirect( $safe_goto, 302 );
            exit;
        }

        self::log( 'no safe goto_link — fallback to home, reason=' . $reason, $promo_id );
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Internal home_url fallback when no usable goto_link.
        wp_redirect( home_url(), 302 );
        exit;
    }

    /**
     * Унифицированный лог-префикс — для grep'а в error_log.
     */
    private static function log( string $message, int $promo_id = 0 ): void {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic: grep [Cashback Promo Redirect].
        error_log( sprintf( '[Cashback Promo Redirect] promo=%d %s', $promo_id, $message ) );
    }
}
