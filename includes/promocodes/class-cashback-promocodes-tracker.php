<?php

/**
 * Click-tracker промокодов: AJAX endpoint cashback_promocode_click.
 *
 * Запись в cashback_promocode_clicks с:
 *   - sha256(ip + salt) — не raw IP (152-ФЗ).
 *   - action ENUM('copy','goto').
 *   - user_id = 0 для гостей.
 *
 * Безопасность:
 *   - nonce 'cashback_promocode_click'.
 *   - NAT-safe rate-limit через Cashback_Rate_Limiter::check (per-user
 *     для авторизованных, per-IP для гостей).
 *   - whitelist promo_action: copy|goto.
 *   - ua_family обрезается до 64 символов.
 *
 * НЕ пишет в cashback_audit_log (не финансовая операция).
 *
 * @package CashbackPlugin
 * @since   7.2.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Promocodes_Tracker {

    public const AJAX_ACTION = 'cashback_promocode_click';

    private const SALT_OPTION = 'cashback_promocode_ip_salt';

    public static function init(): void {
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_click' ) );
        add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'handle_click' ) );
    }

    /**
     * AJAX handler.
     */
    public static function handle_click(): void {
        // Nonce verify (без die — handle сам управляет ответом).
        if ( ! check_ajax_referer( self::AJAX_ACTION, '_wpnonce', false ) ) {
            wp_send_json_error( array( 'code' => 'invalid_nonce' ), 403 );
            return;
        }

        $promocode_id = isset( $_POST['promocode_id'] ) ? (int) $_POST['promocode_id'] : 0;
        $product_id   = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
        $promo_action = isset( $_POST['promo_action'] ) ? sanitize_key( wp_unslash( $_POST['promo_action'] ) ) : '';

        if ( $promocode_id <= 0 ) {
            wp_send_json_error( array( 'code' => 'invalid_promocode_id' ), 400 );
            return;
        }

        if ( ! in_array( $promo_action, array( 'copy', 'goto' ), true ) ) {
            wp_send_json_error( array( 'code' => 'invalid_action' ), 400 );
            return;
        }

        $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        $ip      = self::current_ip();

        // NAT-safe rate-limit (per-user для auth, per-IP для гостей).
        if ( class_exists( 'Cashback_Rate_Limiter' ) ) {
            $rl = Cashback_Rate_Limiter::check( self::AJAX_ACTION, $user_id, $ip );
            if ( empty( $rl['allowed'] ) ) {
                wp_send_json_error(
                    array(
                        'code'        => 'rate_limited',
                        'retry_after' => (int) ( $rl['retry_after'] ?? 60 ),
                    ),
                    429
                );
                return;
            }
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'cashback_promocode_clicks';
        $now    = gmdate( 'Y-m-d H:i:s' );
        $ua     = self::current_ua();
        $iphash = self::hash_ip( $ip );

        $wpdb->insert(
            $table,
            array(
                'user_id'      => $user_id,
                'promocode_id' => $promocode_id,
                'product_id'   => $product_id > 0 ? $product_id : null,
                'action'       => $promo_action,
                'ip_hash'      => $iphash,
                'ua_family'    => $ua,
                'created_at'   => $now,
            ),
            array( '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
        );

        wp_send_json_success( array( 'ok' => true ) );
    }

    /**
     * sha256(ip + salt) — обратимое только при знании salt'а.
     * Salt лежит в wp_options, генерируется один раз.
     */
    public static function hash_ip( string $ip ): string {
        if ( $ip === '' ) {
            return '';
        }
        return hash( 'sha256', $ip . self::get_salt() );
    }

    private static function get_salt(): string {
        $salt = (string) get_option( self::SALT_OPTION, '' );
        if ( $salt === '' ) {
            $salt = function_exists( 'wp_generate_password' ) ? wp_generate_password( 64, false, false ) : bin2hex( random_bytes( 32 ) );
            update_option( self::SALT_OPTION, $salt, false );
        }
        return $salt;
    }

    private static function current_ip(): string {
        // REMOTE_ADDR — единственный безопасный источник; X-Forwarded-For
        // спуфится клиентом без proxy whitelisting. filter_var с FILTER_VALIDATE_IP
        // снимает trust-issue (вход всегда либо валидный IP, либо '').
        // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- IP валидируется через filter_var FILTER_VALIDATE_IP; используется только для sha256-хеширования (152-ФЗ).
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return filter_var( $ip, FILTER_VALIDATE_IP ) !== false ? $ip : '';
    }

    private static function current_ua(): string {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        return mb_substr( $ua, 0, 64 );
    }
}
