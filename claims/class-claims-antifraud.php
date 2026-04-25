<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claims AntiFraud Module
 *
 * Prevents fraudulent claims through:
 * - Rate limiting (claims per day per user)
 * - Order ID uniqueness (no duplicate order_id across all users)
 * - Cross-user detection (same order_id claimed by different users)
 * - IP and User-Agent tracking
 * - Suspicious flagging
 */
class Cashback_Claims_Antifraud {

    private const MAX_CLAIMS_PER_DAY  = 5;
    private const MAX_CLAIMS_PER_WEEK = 15;

    /**
     * Run all antifraud checks before claim submission.
     *
     * @param int $user_id
     * @param string $order_id
     * @return array{blocked: bool, reasons: string[], suspicious: bool, suspicious_reasons: string[]}
     */
    public static function pre_submit_check( int $user_id, string $order_id ): array {
        $reasons            = array();
        $suspicious_reasons = array();

        $rate_check = self::check_rate_limit($user_id);
        if ($rate_check['blocked']) {
            $reasons = array_merge($reasons, $rate_check['reasons']);
        }
        if ($rate_check['suspicious']) {
            $suspicious_reasons = array_merge($suspicious_reasons, $rate_check['suspicious_reasons']);
        }

        $order_check = self::check_order_id_uniqueness($order_id, $user_id);
        if ($order_check['blocked']) {
            $reasons = array_merge($reasons, $order_check['reasons']);
        }
        if ($order_check['suspicious']) {
            $suspicious_reasons = array_merge($suspicious_reasons, $order_check['suspicious_reasons']);
        }

        return array(
            'blocked'            => !empty($reasons),
            'reasons'            => $reasons,
            'suspicious'         => !empty($suspicious_reasons),
            'suspicious_reasons' => $suspicious_reasons,
        );
    }

    /**
     * Check rate limits for user.
     *
     * @param int $user_id
     * @return array{blocked: bool, reasons: string[], suspicious: bool, suspicious_reasons: string[]}
     */
    private static function check_rate_limit( int $user_id ): array {
        global $wpdb;

        $today      = gmdate('Y-m-d');
        $week_start = gmdate('Y-m-d', strtotime('monday this week'));

        $today_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->prefix}cashback_claims`
             WHERE user_id = %d AND DATE(created_at) = %s",
            $user_id,
            $today
        ));

        $week_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->prefix}cashback_claims`
             WHERE user_id = %d AND DATE(created_at) >= %s",
            $user_id,
            $week_start
        ));

        $reasons    = array();
        $suspicious = array();

        $day_limit  = self::get_max_claims_per_day();
        $week_limit = self::get_max_claims_per_week();

        if ($today_count >= $day_limit) {
            $reasons[] = sprintf(
                /* translators: %d: max claims per day */
                __('Превышен лимит заявок в день (максимум %d).', 'cashback-plugin'),
                $day_limit
            );
        }

        if ($week_count >= $week_limit) {
            $reasons[] = sprintf(
                /* translators: %d: max claims per week */
                __('Превышен лимит заявок в неделю (максимум %d).', 'cashback-plugin'),
                $week_limit
            );
        }

        if ($today_count >= $day_limit * 0.8) {
            $suspicious[] = sprintf(
                /* translators: 1: current claims today, 2: max claims per day */
                __('Пользователь приближается к дневному лимиту (%1$d/%2$d).', 'cashback-plugin'),
                $today_count,
                $day_limit
            );
        }

        return array(
            'blocked'            => !empty($reasons),
            'reasons'            => $reasons,
            'suspicious'         => !empty($suspicious),
            'suspicious_reasons' => $suspicious,
        );
    }

    /**
     * Check order_id uniqueness across all users.
     *
     * @param string $order_id
     * @param int $current_user_id
     * @return array{blocked: bool, reasons: string[], suspicious: bool, suspicious_reasons: string[]}
     */
    private static function check_order_id_uniqueness( string $order_id, int $current_user_id ): array {
        global $wpdb;

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT claim_id, user_id, status, ip_address, user_agent
             FROM `{$wpdb->prefix}cashback_claims`
             WHERE order_id = %s",
            $order_id
        ), ARRAY_A);

        $reasons    = array();
        $suspicious = array();

        if ($existing) {
            if ((int) $existing['user_id'] === $current_user_id) {
                if (in_array($existing['status'], array( 'draft', 'submitted', 'sent_to_network' ), true)) {
                    $reasons[] = __('Заявка с таким номером заказа уже существует.', 'cashback-plugin');
                }
            } else {
                $reasons[]    = __('Этот номер заказа уже используется в другой заявке.', 'cashback-plugin');
                $suspicious[] = sprintf(
                    /* translators: 1: order ID, 2: user ID */
                    __('Order ID %1$s уже заявлен пользователем %2$d.', 'cashback-plugin'),
                    $order_id,
                    $existing['user_id']
                );
            }
        }

        return array(
            'blocked'            => !empty($reasons),
            'reasons'            => $reasons,
            'suspicious'         => !empty($suspicious),
            'suspicious_reasons' => $suspicious,
        );
    }

    /**
     * Post-submit analysis: flag claim as suspicious if needed.
     *
     * @param int $claim_id
     * @param int $user_id
     * @param string $order_id
     * @param string $ip_address
     * @param string $user_agent
     * @return array{is_suspicious: bool, reasons: string[]}
     */
    public static function post_submit_analysis( int $claim_id, int $user_id, string $order_id, string $ip_address, string $user_agent ): array {
        global $wpdb;

        $reasons = array();

        $other_users_same_ip = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM `{$wpdb->prefix}cashback_claims`
             WHERE ip_address = %s AND user_id != %d",
            $ip_address,
            $user_id
        ));

        if ($other_users_same_ip > 0) {
            $reasons[] = sprintf(
                /* translators: %d: number of other users sharing the same IP */
                __('IP-адрес используется %d другими пользователями для заявок.', 'cashback-plugin'),
                $other_users_same_ip
            );
        }

        $other_users_same_ua = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM `{$wpdb->prefix}cashback_claims`
             WHERE user_agent = %s AND user_id != %d AND user_agent != ''",
            $user_agent,
            $user_id
        ));

        if ($other_users_same_ua > 0) {
            $reasons[] = sprintf(
                /* translators: %d: number of other users sharing the same User-Agent */
                __('User-Agent совпадает с %d другими пользователями.', 'cashback-plugin'),
                $other_users_same_ua
            );
        }

        $recent_claims_same_ip = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->prefix}cashback_claims`
             WHERE ip_address = %s AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)",
            $ip_address
        ));

        if ($recent_claims_same_ip > 3) {
            $reasons[] = sprintf(
                /* translators: %d: number of claims from this IP in the last 24 hours */
                __('%d заявок с этого IP за последние 24 часа.', 'cashback-plugin'),
                $recent_claims_same_ip
            );
        }

        $is_suspicious = !empty($reasons);

        if ($is_suspicious) {
            $wpdb->update(
                "{$wpdb->prefix}cashback_claims",
                array(
                    'is_suspicious'      => 1,
                    'suspicious_reasons' => wp_json_encode($reasons),
                ),
                array( 'claim_id' => $claim_id ),
                array( '%d', '%s' ),
                array( '%d' )
            );
        }

        return array(
            'is_suspicious' => $is_suspicious,
            'reasons'       => $reasons,
        );
    }

    /**
     * Get max claims per day limit.
     *
     * @return int
     */
    public static function get_max_claims_per_day(): int {
        return (int) get_option('cashback_claims_max_per_day', self::MAX_CLAIMS_PER_DAY);
    }

    /**
     * Get max claims per week limit.
     *
     * @return int
     */
    public static function get_max_claims_per_week(): int {
        return (int) get_option('cashback_claims_max_per_week', self::MAX_CLAIMS_PER_WEEK);
    }
}
