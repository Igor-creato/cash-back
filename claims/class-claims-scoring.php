<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claims Scoring Engine
 *
 * Calculates the probability of claim approval based on 4 factors:
 * 1. Time between click and order (weight: 0.25)
 * 2. Merchant historical approval rate (weight: 0.35)
 * 3. User's claim history (weight: 0.20)
 * 4. Data consistency (weight: 0.20)
 *
 * Formula: score = (time * 0.25) + (merchant * 0.35) + (user * 0.20) + (consistency * 0.20)
 * Normalized to 0–100.
 */
class Cashback_Claims_Scoring {

    private const WEIGHT_TIME        = 0.25;
    private const WEIGHT_MERCHANT    = 0.35;
    private const WEIGHT_USER        = 0.20;
    private const WEIGHT_CONSISTENCY = 0.20;

    /**
     * Calculate probability score for a claim.
     *
     * @param array $claim_data Claim data with click_id, order_date, order_value, etc.
     * @return array{score: float, label: string, breakdown: array}
     */
    public static function calculate( array $claim_data ): array {
        $user_id     = (int) ( $claim_data['user_id'] ?? 0 );
        $click_id    = $claim_data['click_id'] ?? '';
        $order_date  = $claim_data['order_date'] ?? '';
        $order_value = (float) ( $claim_data['order_value'] ?? 0 );
        $merchant_id = (int) ( $claim_data['merchant_id'] ?? 0 );

        $time_score        = self::score_time_factor($click_id, $order_date);
        $merchant_score    = self::score_merchant_factor($merchant_id);
        $user_score        = self::score_user_factor($user_id);
        $consistency_score = self::score_consistency_factor($claim_data);

        $raw = ( $time_score * self::WEIGHT_TIME )
            + ( $merchant_score * self::WEIGHT_MERCHANT )
            + ( $user_score * self::WEIGHT_USER )
            + ( $consistency_score * self::WEIGHT_CONSISTENCY );

        $score = round(max(0, min(100, $raw * 100)), 2);

        return array(
            'score'     => $score,
            'label'     => self::get_label($score),
            'breakdown' => array(
                'time'        => round($time_score * 100, 2),
                'merchant'    => round($merchant_score * 100, 2),
                'user'        => round($user_score * 100, 2),
                'consistency' => round($consistency_score * 100, 2),
            ),
        );
    }

    /**
     * Score based on time between click and order.
     * Optimal: 1–24 hours after click.
     *
     * @param string $click_id
     * @param string $order_date
     * @return float 0–1
     */
    private static function score_time_factor( string $click_id, string $order_date ): float {
        global $wpdb;

        $click = $wpdb->get_row($wpdb->prepare(
            "SELECT created_at FROM `{$wpdb->prefix}cashback_click_log` WHERE click_id = %s",
            $click_id
        ), ARRAY_A);

        if (!$click) {
            return 0.0;
        }

        $click_time = strtotime($click['created_at']);
        $order_time = strtotime($order_date);

        if (!$click_time || !$order_time) {
            return 0.0;
        }

        if ($order_time < $click_time) {
            return 0.0;
        }

        $diff_hours = ( $order_time - $click_time ) / 3600;

        if ($diff_hours <= 1) {
            return 1.0;
        }

        if ($diff_hours <= 24) {
            return 0.9;
        }

        if ($diff_hours <= 72) {
            return 0.7;
        }

        if ($diff_hours <= 168) {
            return 0.5;
        }

        if ($diff_hours <= 720) {
            return 0.3;
        }

        return 0.1;
    }

    /**
     * Score based on merchant's historical approval rate.
     *
     * @param int $merchant_id
     * @return float 0–1
     */
    private static function score_merchant_factor( int $merchant_id ): float {
        global $wpdb;

        if ($merchant_id <= 0) {
            return 0.5;
        }

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved
             FROM `{$wpdb->prefix}cashback_claims`
             WHERE merchant_id = %d AND status IN ('approved', 'declined')",
            $merchant_id
        ), ARRAY_A);

        if (!$stats || (int) $stats['total'] < 3) {
            return 0.5;
        }

        $rate = (float) $stats['approved'] / (float) $stats['total'];

        return max(0.1, min(1.0, $rate));
    }

    /**
     * Score based on user's claim history.
     *
     * @param int $user_id
     * @return float 0–1
     */
    private static function score_user_factor( int $user_id ): float {
        global $wpdb;

        if ($user_id <= 0) {
            return 0.3;
        }

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN is_suspicious = 1 THEN 1 ELSE 0 END) as suspicious
             FROM `{$wpdb->prefix}cashback_claims`
             WHERE user_id = %d AND status IN ('approved', 'declined')",
            $user_id
        ), ARRAY_A);

        if (!$stats || (int) $stats['total'] === 0) {
            return 0.5;
        }

        $approval_rate   = (float) $stats['approved'] / (float) $stats['total'];
        $suspicious_rate = (float) $stats['suspicious'] / (float) $stats['total'];

        $score = $approval_rate * 0.7 + ( 1 - $suspicious_rate ) * 0.3;

        return max(0.1, min(1.0, $score));
    }

    /**
     * Score based on data consistency.
     *
     * @param array $claim_data
     * @return float 0–1
     */
    private static function score_consistency_factor( array $claim_data ): float {
        $score   = 0.0;
        $factors = 0;

        $click_id    = $claim_data['click_id'] ?? '';
        $order_date  = $claim_data['order_date'] ?? '';
        $order_value = (float) ( $claim_data['order_value'] ?? 0 );

        global $wpdb;

        $click = $wpdb->get_row($wpdb->prepare(
            "SELECT created_at, ip_address, user_agent, product_id
             FROM `{$wpdb->prefix}cashback_click_log`
             WHERE click_id = %s",
            $click_id
        ), ARRAY_A);

        if ($click) {
            ++$factors;

            if ($order_date && strtotime($order_date) >= strtotime($click['created_at'])) {
                $score += 1.0;
            }

            ++$factors;
            if ($order_value > 0) {
                $score += 1.0;
            }

            ++$factors;
            if (!empty($claim_data['comment'])) {
                $score += 1.0;
            }

            ++$factors;
            $current_ip = self::get_client_ip();
            if ($current_ip && $current_ip === $click['ip_address']) {
                $score += 1.0;
            }
        }

        return $factors > 0 ? $score / $factors : 0.3;
    }

    /**
     * Get human-readable label for score.
     *
     * @param float $score
     * @return string
     */
    private static function get_label( float $score ): string {
        if ($score >= 70) {
            return __('Высокая вероятность', 'cashback-plugin');
        }

        if ($score >= 40) {
            return __('Средняя вероятность', 'cashback-plugin');
        }

        return __('Низкая вероятность', 'cashback-plugin');
    }

    /**
     * Get client IP address (reuse plugin's method).
     *
     * @return string
     */
    private static function get_client_ip(): string {
        if (class_exists('Cashback_Encryption')) {
            return Cashback_Encryption::get_client_ip();
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
}
