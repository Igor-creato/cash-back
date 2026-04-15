<?php
/**
 * Affiliate Module — Antifraud.
 *
 * Проверки: self-referral, IP совпадения, подозрительный тайминг.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Affiliate_Antifraud {

    /**
     * Минимальное время (секунды) между кликом и регистрацией.
     * Если меньше — подозрение на бота.
     */
    const MIN_CLICK_TO_REGISTER_SECONDS = 5;

    /**
     * Комплексная проверка реферала при регистрации.
     *
     * @param int    $referrer_id ID реферера
     * @param int    $new_user_id ID нового пользователя
     * @param string $ip          IP нового пользователя
     * @param string $click_id    Click ID из cookie
     * @return array{allowed: bool, reason: string|null}
     */
    public static function validate_referral( int $referrer_id, int $new_user_id, string $ip, string $click_id ): array {
        // 1. Self-referral (всегда проверяем, даже с выключенным антифродом)
        if (self::is_self_referral($referrer_id, $new_user_id)) {
            self::log_suspicious('self_referral', $new_user_id, array(
                'referrer_id' => $referrer_id,
                'ip'          => $ip,
            ));
            return array(
				'allowed' => false,
				'reason'  => 'self_referral',
			);
        }

        // 2. Реферер существует и активен (всегда проверяем)
        if (!self::is_valid_referrer($referrer_id)) {
            return array(
				'allowed' => false,
				'reason'  => 'invalid_referrer',
			);
        }

        // 3. У пользователя уже есть реферер (всегда проверяем)
        if (self::already_has_referrer($new_user_id)) {
            return array(
				'allowed' => false,
				'reason'  => 'already_referred',
			);
        }

        // Если антифрод отключён — пропускаем проверки IP и тайминга
        if (!Cashback_Affiliate_DB::is_antifraud_enabled()) {
            return array(
				'allowed' => true,
				'reason'  => null,
			);
        }

        // 4. IP совпадение
        if (self::is_same_ip_referral($referrer_id, $ip)) {
            self::log_suspicious('same_ip_referral', $new_user_id, array(
                'referrer_id' => $referrer_id,
                'ip'          => $ip,
            ));
            return array(
				'allowed' => false,
				'reason'  => 'same_ip',
			);
        }

        // 5. Подозрительный тайминг (клик → регистрация)
        if (!empty($click_id) && self::is_suspicious_timing($click_id)) {
            self::log_suspicious('suspicious_timing', $new_user_id, array(
                'referrer_id' => $referrer_id,
                'click_id'    => $click_id,
            ));
            // Не блокируем — только логируем
        }

        return array(
			'allowed' => true,
			'reason'  => null,
		);
    }

    /**
     * Self-referral: пользователь пытается быть своим же реферером.
     */
    public static function is_self_referral( int $referrer_id, int $new_user_id ): bool {
        return $referrer_id === $new_user_id;
    }

    /**
     * Проверяет что реферер существует, не забанен и участвует в партнёрке.
     */
    public static function is_valid_referrer( int $referrer_id ): bool {
        global $wpdb;
        $prefix = $wpdb->prefix;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Имя таблицы из $wpdb->prefix, user_id биндится через prepare().
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT p.status
             FROM `{$prefix}cashback_user_profile` p
             WHERE p.user_id = %d
             LIMIT 1",
            $referrer_id
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (!$status || $status === 'banned' || $status === 'deleted') {
            return false;
        }

        // Проверяем affiliate_status
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Имя таблицы из $wpdb->prefix, user_id биндится через prepare().
        $aff_status = $wpdb->get_var($wpdb->prepare(
            "SELECT affiliate_status
             FROM `{$prefix}cashback_affiliate_profiles`
             WHERE user_id = %d
             LIMIT 1",
            $referrer_id
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Если профиля нет — реферер валиден (профиль создастся)
        // Если disabled — не принимаем реферала
        if ($aff_status === 'disabled') {
            return false;
        }

        return true;
    }

    /**
     * Проверяет что у пользователя уже есть реферер (immutable binding).
     */
    public static function already_has_referrer( int $user_id ): bool {
        global $wpdb;

        $referred_by = $wpdb->get_var($wpdb->prepare(
            "SELECT referred_by_user_id
             FROM `{$wpdb->prefix}cashback_affiliate_profiles`
             WHERE user_id = %d AND referred_by_user_id IS NOT NULL
             LIMIT 1",
            $user_id
        ));

        return $referred_by !== null;
    }

    /**
     * IP совпадение: реферер и реферал с одного IP.
     * Проверяет последние fingerprints и клики реферера.
     */
    public static function is_same_ip_referral( int $referrer_id, string $referred_ip ): bool {
        global $wpdb;
        $prefix = $wpdb->prefix;

        // Проверяем по fingerprints реферера за последние 30 дней
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Имя таблицы из $wpdb->prefix, значения биндятся через prepare().
        $fp_match = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM `{$prefix}cashback_user_fingerprints`
             WHERE user_id = %d
               AND ip_address = %s
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             LIMIT 1",
            $referrer_id,
            $referred_ip
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ((int) $fp_match > 0) {
            return true;
        }

        // Проверяем по кликам реферера (cashback_click_log)
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Имя таблицы из $wpdb->prefix, значения биндятся через prepare().
        $click_match = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM `{$prefix}cashback_click_log`
             WHERE user_id = %d
               AND ip_address = %s
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             LIMIT 1",
            $referrer_id,
            $referred_ip
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return (int) $click_match > 0;
    }

    /**
     * Подозрительный тайминг: слишком быстрая регистрация после клика.
     */
    public static function is_suspicious_timing( string $click_id ): bool {
        global $wpdb;

        $click_time = $wpdb->get_var($wpdb->prepare(
            "SELECT created_at
             FROM `{$wpdb->prefix}cashback_affiliate_clicks`
             WHERE click_id = %s
             LIMIT 1",
            $click_id
        ));

        if (!$click_time) {
            return false;
        }

        $click_ts = strtotime($click_time);
        $now_ts   = time();

        return ( $now_ts - $click_ts ) < self::MIN_CLICK_TO_REGISTER_SECONDS;
    }

    /**
     * Логирование подозрительных действий через аудит-лог.
     *
     * @param string $type    Тип подозрения
     * @param int    $user_id ID пользователя
     * @param array  $details Дополнительные данные
     */
    private static function log_suspicious( string $type, int $user_id, array $details ): void {
        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'affiliate_antifraud_' . $type,
                0, // system actor
                'user',
                $user_id,
                $details
            );
        }
    }
}
