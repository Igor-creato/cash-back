<?php
/**
 * Affiliate Module — Antifraud.
 *
 * F-22-003 (Группа 12): N-of-M signal scoring + subnet-match вместо
 * identity-match + timing rules с учётом cookie_valid.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Affiliate_Antifraud {

    /**
     * Слишком быстрый bind после клика — всегда signal, даже если cookie валиден.
     * <2 сек практически всегда бот/скрипт.
     */
    const SUSPICIOUS_TIMING_STRICT_SECONDS = 2;

    /**
     * Промежуток 2–5 сек — signal только для path без signed-cookie
     * (т.е. transient-fallback) — слабое evidence, которое без поддержки
     * HMAC-cookie стоит считать подозрительным.
     */
    const SUSPICIOUS_TIMING_RELAXED_SECONDS = 5;

    /**
     * Комплексная проверка реферала при регистрации.
     *
     * Контракт (F-22-003):
     *   • hard-blocks (allowed=false): self_referral, invalid_referrer,
     *     already_referred, multiple_signals (N≥2 из signals[])
     *   • signals[]: ip_subnet_match, suspicious_timing, nat_collision_detected
     *   • confidence:
     *       cookie + 0 signals   → 'high'
     *       transient + 0 signals → 'medium' (fallback НИКОГДА не high)
     *       любой + ≥1 signal    → 'low' (даже если 1 сигнал — binding создаётся,
     *                                    но помечается как требующий review)
     *
     * Новые параметры source/cookie_valid/collision_detected опциональны для
     * обратной совместимости — caller Шаг 7 передаст их явно. Дефолты
     * соответствуют "cookie-путь без коллизий" (историческое поведение).
     *
     * @return array{allowed: bool, confidence: string, reason: string|null, signals: string[]}
     */
    public static function validate_referral(
        int $referrer_id,
        int $new_user_id,
        string $ip,
        string $click_id,
        string $source = 'cookie',
        bool $cookie_valid = true,
        bool $collision_detected = false
    ): array {
        // 1. Hard-blocks (всегда, даже с выключенным антифродом)
        if (self::is_self_referral($referrer_id, $new_user_id)) {
            if (class_exists('Cashback_Affiliate_Audit')) {
                Cashback_Affiliate_Audit::log('antifraud_self_referral', array(
                    'target_user_id' => $new_user_id,
                    'referrer_id'    => $referrer_id,
                    'click_id'       => $click_id,
                    'reason'         => 'self_referral',
                ));
            }
            return array(
                'allowed'    => false,
                'confidence' => 'low',
                'reason'     => 'self_referral',
                'signals'    => array(),
            );
        }

        if (!self::is_valid_referrer($referrer_id)) {
            return array(
                'allowed'    => false,
                'confidence' => 'low',
                'reason'     => 'invalid_referrer',
                'signals'    => array(),
            );
        }

        if (self::already_has_referrer($new_user_id)) {
            return array(
                'allowed'    => false,
                'confidence' => 'low',
                'reason'     => 'already_referred',
                'signals'    => array(),
            );
        }

        // 2. Антифрод выключен — возвращаем confidence по source без сигналов.
        if (!Cashback_Affiliate_DB::is_antifraud_enabled()) {
            return array(
                'allowed'    => true,
                'confidence' => $source === 'cookie' ? 'high' : 'medium',
                'reason'     => null,
                'signals'    => array(),
            );
        }

        // 3. Сбор сигналов
        $signals = array();

        if (self::is_same_subnet_referral($referrer_id, $ip)) {
            $signals[] = 'ip_subnet_match';
        }
        if ($click_id !== '' && self::is_suspicious_timing($click_id, $cookie_valid)) {
            $signals[] = 'suspicious_timing';
        }
        if ($collision_detected) {
            $signals[] = 'nat_collision_detected';
        }

        // 4. N≥2 → hard-block
        if (count($signals) >= 2) {
            if (class_exists('Cashback_Affiliate_Audit')) {
                Cashback_Affiliate_Audit::log('multi_signal_block', array(
                    'target_user_id' => $new_user_id,
                    'referrer_id'    => $referrer_id,
                    'click_id'       => $click_id,
                    'signals'        => $signals,
                    'reason'         => 'multiple_signals',
                ));
            }
            return array(
                'allowed'    => false,
                'confidence' => 'low',
                'reason'     => 'multiple_signals',
                'signals'    => $signals,
            );
        }

        // 5. Derivation: наличие сигналов → low; source=cookie + 0 → high; else medium.
        $has_signals = count($signals) > 0;

        if ($has_signals) {
            return array(
                'allowed'    => true,
                'confidence' => 'low',
                'reason'     => null,
                'signals'    => $signals,
            );
        }

        if ($source === 'cookie') {
            return array(
                'allowed'    => true,
                'confidence' => 'high',
                'reason'     => null,
                'signals'    => array(),
            );
        }

        return array(
            'allowed'    => true,
            'confidence' => 'medium',
            'reason'     => null,
            'signals'    => array(),
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

        $profile_table = $prefix . 'cashback_user_profile';
        $status        = $wpdb->get_var($wpdb->prepare(
            'SELECT p.status
             FROM %i p
             WHERE p.user_id = %d
             LIMIT 1',
            $profile_table,
            $referrer_id
        ));

        if (!$status || $status === 'banned' || $status === 'deleted') {
            return false;
        }

        $aff_table  = $prefix . 'cashback_affiliate_profiles';
        $aff_status = $wpdb->get_var($wpdb->prepare(
            'SELECT affiliate_status
             FROM %i
             WHERE user_id = %d
             LIMIT 1',
            $aff_table,
            $referrer_id
        ));

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

        $aff_table   = $wpdb->prefix . 'cashback_affiliate_profiles';
        $referred_by = $wpdb->get_var($wpdb->prepare(
            'SELECT referred_by_user_id
             FROM %i
             WHERE user_id = %d AND referred_by_user_id IS NOT NULL
             LIMIT 1',
            $aff_table,
            $user_id
        ));

        return $referred_by !== null;
    }

    /**
     * Subnet-match: реферер и реферал с одной /24 (IPv4) или /64 (IPv6).
     *
     * F-22-003: используем subnet, а не точный IP — IP меняется у мобильных
     * клиентов, внутри NAT одни и те же люди получают разные точные IP.
     * Совпадение subnet — это signal (повышаем вероятность коллизии), не
     * identity-match (не блокируем сразу).
     */
    public static function is_same_subnet_referral( int $referrer_id, string $referred_ip ): bool {
        if (!class_exists('Cashback_Affiliate_Service')) {
            return false;
        }
        $target_subnet = Cashback_Affiliate_Service::extract_subnet($referred_ip);
        if ($target_subnet === '') {
            return false;
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        // Собираем IP реферера из двух источников и сравниваем по subnet
        // через БД-слой: быстрее, чем тянуть все IPs в PHP (~~1000 строк на
        // активного реферера ≤ 30 дней).
        $fp_table    = $prefix . 'cashback_user_fingerprints';
        $click_table = $prefix . 'cashback_click_log';

        // Строим like-patterns: для IPv4 "X.Y.Z.%" (LIKE), для IPv6 "prefix%".
        $patterns = self::subnet_like_patterns($target_subnet);
        if (empty($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            $like = $wpdb->esc_like($pattern) . '%';
            // Проверка по fingerprints
            $fp_match = $wpdb->get_var($wpdb->prepare(
                'SELECT 1 FROM %i
                 WHERE user_id = %d
                   AND ip_address LIKE %s
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 LIMIT 1',
                $fp_table,
                $referrer_id,
                $like
            ));
            if ($fp_match) {
                return true;
            }

            $click_match = $wpdb->get_var($wpdb->prepare(
                'SELECT 1 FROM %i
                 WHERE user_id = %d
                   AND ip_address LIKE %s
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 LIMIT 1',
                $click_table,
                $referrer_id,
                $like
            ));
            if ($click_match) {
                return true;
            }
        }

        return false;
    }

    /**
     * Преобразование subnet в LIKE-паттерны для SQL-сравнения IP-строк.
     * IPv4 "X.Y.Z.0/24" → ["X.Y.Z."]
     * IPv6 "prefix::/64" → возвращает нормализованный 8-byte hex-префикс
     * в expanded-notation ("xxxx:xxxx:xxxx:xxxx:") — safe для LIKE с '%'.
     *
     * @return list<string>
     */
    private static function subnet_like_patterns( string $subnet ): array {
        if ($subnet === '') {
            return array();
        }

        // IPv4 /24: "X.Y.Z.0/24" → "X.Y.Z."
        if (preg_match('#^(\d{1,3}\.\d{1,3}\.\d{1,3}\.)0/24$#', $subnet, $m)) {
            return array( $m[1] );
        }

        // IPv6 /64: expand и взять 4 группы
        if (preg_match('#^(.+)/64$#', $subnet, $m)) {
            $addr = $m[1];
            // Разворачиваем "::" для LIKE-paттерна. inet_pton/ntop даёт
            // сжатую форму; для LIKE нужна expanded.
            $packed = @inet_pton($addr);
            if ($packed === false || strlen($packed) !== 16) {
                return array();
            }
            $hex = bin2hex($packed);
            // Первые 16 hex = 8 байт = 64 бита → 4 группы по 4 hex.
            $first_64 = substr($hex, 0, 16);
            $grouped  = sprintf(
                '%s:%s:%s:%s:',
                substr($first_64, 0, 4),
                substr($first_64, 4, 4),
                substr($first_64, 8, 4),
                substr($first_64, 12, 4)
            );
            // Для LIKE-match покрытие и сжатых (:: внутри), и развёрнутых
            // форм БД-столбца: возвращаем оба варианта — точный префикс и
            // без ведущих нулей.
            $compact = preg_replace('/(^|:)0+([0-9a-f])/i', '$1$2', $grouped);
            $variants = array( $grouped );
            if ($compact !== null && $compact !== $grouped) {
                $variants[] = $compact;
            }
            return $variants;
        }

        return array();
    }

    /**
     * Подозрительный тайминг click → register.
     *
     * F-22-003:
     *   • <2 сек → signal всегда (практически всегда бот).
     *   • 2–5 сек + cookie_valid=false → signal (transient-only = слабое
     *     evidence без HMAC-cookie, 2–5 сек — подозрение на auto-click).
     *   • 2–5 сек + cookie_valid=true → НЕ signal (легитимный autofill).
     *   • >5 сек → никогда не signal.
     */
    public static function is_suspicious_timing( string $click_id, bool $cookie_valid = true ): bool {
        global $wpdb;

        $clicks_table = $wpdb->prefix . 'cashback_affiliate_clicks';
        $click_time   = $wpdb->get_var($wpdb->prepare(
            'SELECT created_at
             FROM %i
             WHERE click_id = %s
             LIMIT 1',
            $clicks_table,
            $click_id
        ));

        if (!$click_time) {
            return false;
        }

        $click_ts = (int) strtotime($click_time);
        $now_ts   = time();
        $diff     = $now_ts - $click_ts;

        if ($diff < 2) {
            return true;
        }
        if ($diff < 5 && !$cookie_valid) {
            return true;
        }
        return false;
    }
}
