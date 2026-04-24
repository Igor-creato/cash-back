<?php

/**
 * Affiliate Module — Core Service.
 *
 * Бизнес-логика: cookie, привязка рефералов, начисление комиссий,
 * заморозка/разморозка при отключении от партнёрки.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Affiliate_Service {

    /** @var self|null */
    private static ?self $instance = null;

    /** Имя cookie с реферальными данными */
    const COOKIE_NAME     = 'cashback_ref';
    const COOKIE_SIG_NAME = 'cashback_ref_sig';

    /**
     * TTL серверного transient fallback (F-22-003). Decoupled от cookie TTL
     * — fallback предназначен для пути click→signup в одной browser-сессии.
     * Больше 5 минут не нужно (click→registration обычно секунды-минута),
     * меньше — риск false negative на медленной регистрации.
     *
     * Literal (не option): политика безопасности — чтобы админ не мог случайно
     * ослабить её через настройку.
     */
    const FALLBACK_TTL_SECONDS = 300;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!Cashback_Affiliate_DB::is_module_enabled()) {
            return;
        }

        // Обработка реферальной cookie при визите
        add_action('template_redirect', array( $this, 'handle_referral_visit' ), 5);

        // Привязка реферала при регистрации (после Mariadb_Plugin::user_register, приоритет 10)
        add_action('user_register', array( $this, 'bind_referral_on_registration' ), 20);

        // WooCommerce может создавать пользователей через wc_create_new_customer()
        // который вызывает wp_insert_user() → user_register. Но на случай если
        // плагины/темы перехватывают процесс, дублируем через woocommerce_created_customer.
        add_action('woocommerce_created_customer', array( $this, 'bind_referral_on_registration' ), 20);

        // F-22-003: daily cron — auto-promote low-confidence рефералов после
        // 14 дней без disputes/rejects/rate-limit events. Статический callback —
        // не зависит от singleton instance на момент фиринга.
        add_action('cashback_affiliate_auto_promote', array( self::class, 'auto_promote_low_confidence' ));
    }

    /* ═══════════════════════════════════════
     *  COOKIE — установка и чтение
     * ═══════════════════════════════════════ */

    /**
     * Обработка визита с ?ref={partner_token}.
     * Устанавливает HMAC-подписанную cookie, логирует клик.
     */
    public function handle_referral_visit(): void {
        if (!isset($_GET['ref'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public referral entry point, external URL, nonce not applicable.
            return;
        }

        $ref_raw = sanitize_text_field(wp_unslash($_GET['ref'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public referral entry point, external URL, nonce not applicable.

        // Разрешение: partner_token (32 hex) → user_id, с fallback на legacy числовой ID
        if (preg_match('/^[0-9a-f]{32}$/', $ref_raw)) {
            $referrer_id = Mariadb_Plugin::resolve_partner_token($ref_raw);
            if ($referrer_id === null) {
                return;
            }
        } else {
            $referrer_id = absint($ref_raw);
        }

        if ($referrer_id < 1) {
            return;
        }

        // Не ставим cookie для залогиненного пользователя который и есть реферер
        if (is_user_logged_in() && get_current_user_id() === $referrer_id) {
            return;
        }

        // Проверяем что реферер валиден
        if (!Cashback_Affiliate_Antifraud::is_valid_referrer($referrer_id)) {
            return;
        }

        $click_id = cashback_generate_uuid7(false);
        $ip       = class_exists('Cashback_Encryption')
            ? Cashback_Encryption::get_client_ip()
            // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- REMOTE_ADDR set by webserver from TCP connection, not client-controlled; per-request only.
            : ( isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0' );

        // F-22-003: rate-limit на click entry-point. Проверяется ПОСЛЕ валидации
        // partner_token (чтобы не тратить лимит на мусор) и ДО log_click/cookie.
        // При blocked — audit обязателен (иначе RL = чёрная дыра).
        if (class_exists('Cashback_Rate_Limiter')) {
            $rl_check = Cashback_Rate_Limiter::check('affiliate_click', 0, $ip);
            if (empty($rl_check['allowed'])) {
                if (class_exists('Cashback_Affiliate_Audit')) {
                    Cashback_Affiliate_Audit::log('rate_limit_blocked_click', array(
                        'referrer_id'        => $referrer_id,
                        'partner_token_hash' => hash('sha256', $ref_raw),
                        'ip_hash'            => hash('sha256', $ip),
                        'reason'             => 'affiliate_click_rate_limit',
                    ));
                }
                return;
            }
        }

        // Логирование клика
        $this->log_click($click_id, $referrer_id, $ip);

        // Установка cookie (last click wins)
        $this->set_referral_cookie($referrer_id, $click_id);

        // Серверный fallback: transient по IP на случай если cookie недоступна
        $this->store_referral_transient($referrer_id, $click_id, $ip);

        // 302 редирект без ?ref — гарантирует что браузер обработает Set-Cookie
        // и очищает URL от параметра (не пошарят случайно)
        $clean_url = remove_query_arg('ref');
        wp_safe_redirect($clean_url, 302);
        exit;
    }

    /**
     * Установка HMAC-подписанной cookie.
     */
    private function set_referral_cookie( int $referrer_id, string $click_id ): void {
        $payload = wp_json_encode(array(
            'r' => $referrer_id,
            'c' => $click_id,
            't' => time(),
        ));

        $signature = $this->compute_cookie_hmac($payload);
        if ($signature === '') {
            // Fail-closed: секрет не сконфигурирован, не выставляем cookie с предсказуемой подписью.
            return;
        }
        $ttl    = Cashback_Affiliate_DB::get_cookie_ttl_days();
        $expire = time() + ( $ttl * DAY_IN_SECONDS );
        $secure = is_ssl();
        $path   = COOKIEPATH ?: '/';
        $domain = COOKIE_DOMAIN ?: '';

        // Безопасные параметры: HttpOnly, SameSite=Lax
        setcookie(self::COOKIE_NAME, $payload, array(
            'expires'  => $expire,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ));

        setcookie(self::COOKIE_SIG_NAME, $signature, array(
            'expires'  => $expire,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ));

        // Делаем cookie доступной в текущем запросе (setcookie работает только со следующего)
        $_COOKIE[ self::COOKIE_NAME ]     = $payload;
        $_COOKIE[ self::COOKIE_SIG_NAME ] = $signature;
    }

    /**
     * Чтение и верификация cookie.
     *
     * @return array{referrer_id: int, click_id: string, timestamp: int}|null
     */
    public static function read_referral_cookie(): ?array {
        if (empty($_COOKIE[ self::COOKIE_NAME ]) || empty($_COOKIE[ self::COOKIE_SIG_NAME ])) {
            return null;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HMAC-signed JSON payload, byte-exact verification required before sanitation would corrupt signature; per-field validation follows json_decode().
        $payload = wp_unslash($_COOKIE[ self::COOKIE_NAME ]);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HMAC signature value, hash_equals-compared as-is; sanitation would alter bytes.
        $signature = wp_unslash($_COOKIE[ self::COOKIE_SIG_NAME ]);

        // Верификация HMAC (fail-closed: пустой expected означает отсутствие секрета).
        $expected = self::compute_cookie_hmac_static($payload);
        if ($expected === '' || !hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode($payload, true);
        if (!is_array($data) || !isset($data['r'], $data['c'], $data['t'])) {
            return null;
        }

        $referrer_id = (int) $data['r'];
        $click_id    = sanitize_text_field($data['c']);
        $timestamp   = (int) $data['t'];

        // Проверка формата click_id (32 hex)
        if (!ctype_xdigit($click_id) || strlen($click_id) !== 32) {
            return null;
        }

        // Проверка TTL
        $ttl = Cashback_Affiliate_DB::get_cookie_ttl_days();
        if (time() - $timestamp > $ttl * DAY_IN_SECONDS) {
            return null;
        }

        return array(
            'referrer_id' => $referrer_id,
            'click_id'    => $click_id,
            'timestamp'   => $timestamp,
        );
    }

    /**
     * Удаление реферальных cookie.
     */
    public static function clear_referral_cookie(): void {
        $path   = COOKIEPATH ?: '/';
        $domain = COOKIE_DOMAIN ?: '';

        setcookie(self::COOKIE_NAME, '', array(
            'expires'  => time() - YEAR_IN_SECONDS,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ));

        setcookie(self::COOKIE_SIG_NAME, '', array(
            'expires'  => time() - YEAR_IN_SECONDS,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }

    /* ═══════════════════════════════════════
     *  СЕРВЕРНЫЙ FALLBACK — transient по IP
     * ═══════════════════════════════════════ */

    /**
     * Сохранение реферальных данных в transient (F-22-003).
     *
     * Ключ composite: subnet + ua_family + ua_major + accept_language (см. Шаг 3).
     * TTL = FALLBACK_TTL_SECONDS (5 минут, decoupled от cookie TTL).
     *
     * NAT collision policy (keep-first + downgrade):
     *   • Если для ключа уже есть запись с другим referrer_id — первого
     *     оставляем, но помечаем collision=true и confidence_override='low'
     *     (понижаем его уверенность как sign of ambiguity). Повторно
     *     аудируем каждого отвергнутого кандидата — иначе разбирать споры
     *     по NAT-конфликтам невозможно.
     *   • Если запись уже collision — новые distinct referrer'ы только
     *     продолжают генерировать audit-trail, не перезаписывая стату.
     *   • Пустой ключ (malformed IP) — не сохраняем (fail-closed).
     */
    private function store_referral_transient( int $referrer_id, string $click_id, string $ip ): void {
        if (self::extract_subnet($ip) === '') {
            // Malformed IP → не пишем transient, слишком слабый ключ.
            return;
        }

        $hdr      = self::collect_request_headers_for_key();
        $key      = self::get_referral_transient_key($ip, $hdr['ua'], $hdr['al']);
        $existing = get_transient($key);

        if (is_array($existing) && isset($existing['referrer_id'])) {
            $existing_rid = (int) $existing['referrer_id'];

            if ($existing_rid !== $referrer_id) {
                // NAT-collision: keep first, downgrade его confidence, отклоняем кандидата.
                $existing = array_merge($existing, array(
                    'collision'           => true,
                    'confidence_override' => 'low',
                ));
                set_transient($key, $existing, self::FALLBACK_TTL_SECONDS);

                if (class_exists('Cashback_Affiliate_Audit')) {
                    Cashback_Affiliate_Audit::log('nat_collision_rejected_candidate', array(
                        'rejected_referrer_id' => $referrer_id,
                        'kept_referrer_id'     => $existing_rid,
                        'click_id'             => $click_id,
                        'key_hash'             => hash('sha256', $key),
                        'ip_subnet_hash'       => hash('sha256', self::extract_subnet($ip)),
                        'ua_hash'              => hash('sha256', $hdr['ua']),
                        'reason'               => 'second_distinct_referrer_in_ttl',
                    ));
                }
            }

            // Для same-referrer повторных кликов не переписываем timestamp
            // (сохраняем исходный момент первого клика как reference point
            // для суспишес-тайминга в антифроде).
            return;
        }

        // Свежий ключ — записываем.
        set_transient($key, array(
            'referrer_id' => $referrer_id,
            'click_id'    => $click_id,
            'timestamp'   => time(),
            'collision'   => false,
        ), self::FALLBACK_TTL_SECONDS);
    }

    /**
     * Чтение реферальных данных из transient (F-22-003).
     *
     * TTL = FALLBACK_TTL_SECONDS (5 минут). Возвращает также флаги
     * collision / confidence_override, чтобы bind-путь мог понизить
     * confidence первой записи при NAT-коллизии.
     *
     * @return array{referrer_id:int, click_id:string, timestamp:int, collision:bool, confidence_override:?string}|null
     */
    private static function read_referral_transient( string $ip ): ?array {
        $hdr  = self::collect_request_headers_for_key();
        $key  = self::get_referral_transient_key($ip, $hdr['ua'], $hdr['al']);
        $data = get_transient($key);

        if (!is_array($data) || !isset($data['referrer_id'], $data['click_id'], $data['timestamp'])) {
            return null;
        }

        // TTL отвязан от cookie — серверный fallback всегда короткий.
        if (time() - (int) $data['timestamp'] > self::FALLBACK_TTL_SECONDS) {
            delete_transient($key);
            return null;
        }

        return array(
            'referrer_id'         => (int) $data['referrer_id'],
            'click_id'            => sanitize_text_field($data['click_id']),
            'timestamp'           => (int) $data['timestamp'],
            'collision'           => !empty($data['collision']),
            'confidence_override' => isset($data['confidence_override']) ? (string) $data['confidence_override'] : null,
        );
    }

    /**
     * Удаление реферального transient.
     */
    private static function clear_referral_transient( string $ip ): void {
        $hdr = self::collect_request_headers_for_key();
        delete_transient(self::get_referral_transient_key($ip, $hdr['ua'], $hdr['al']));
    }

    /* ═══════════════════════════════════════
     *  F-22-003 HELPERS — subnet + UA-family key
     * ═══════════════════════════════════════ */

    /**
     * Извлечение /24 (IPv4) или /64 (IPv6) через inet_pton.
     *
     * Почему inet_pton, а не explode(':'): IPv6 "::" (all-zero groups) ломает
     * explode — "::1" даст неверный split. inet_pton канонизирует адрес,
     * после чего обнуление хвостовых байт даёт корректный subnet.
     *
     * @return string Каноническая строка "X.Y.Z.0/24" либо "addr::/64".
     *                Пустая строка, если IP некорректен.
     */
    public static function extract_subnet( string $ip ): string {
        // inet_pton возвращает false на malformed IP без warnings в PHP 8+.
        // Аналогично inet_ntop на некорректный payload.
        $packed = inet_pton($ip);
        if ($packed === false) {
            return '';
        }
        if (strlen($packed) === 4) {
            // IPv4: обнуляем последний октет → /24.
            $packed_subnet = substr($packed, 0, 3) . "\x00";
            $ntop          = inet_ntop($packed_subnet);
            return is_string($ntop) ? $ntop . '/24' : '';
        }
        // IPv6: обнуляем последние 8 байт → /64.
        $packed_subnet = substr($packed, 0, 8) . str_repeat("\x00", 8);
        $ntop          = inet_ntop($packed_subnet);
        return is_string($ntop) ? $ntop . '/64' : '';
    }

    /**
     * Нормализация UA → {family, major}. Мы НЕ делаем полный fingerprint
     * (это persisted tracking, consent-sensitive). Берём только типовой
     * маркер: имя браузера + мажорная версия, устойчивое к минорным
     * обновлениям и к мусорным полям UA.
     *
     * @return array{family: string, major: string}
     */
    private static function normalize_ua( string $raw_ua ): array {
        $family = 'unknown';
        $major  = '0';

        if (preg_match('#Edg/(\d+)#', $raw_ua, $m)) {
            $family = 'Edge';
            $major  = $m[1];
        } elseif (preg_match('#OPR/(\d+)#', $raw_ua, $m)) {
            $family = 'Opera';
            $major  = $m[1];
        } elseif (preg_match('#YaBrowser/(\d+)#', $raw_ua, $m)) {
            $family = 'YandexBrowser';
            $major  = $m[1];
        } elseif (preg_match('#(SamsungBrowser|MiuiBrowser|UCBrowser|HuaweiBrowser)/(\d+)#', $raw_ua, $m)) {
            $family = $m[1];
            $major  = $m[2];
        } elseif (preg_match('#Firefox/(\d+)#', $raw_ua, $m)) {
            $family = 'Firefox';
            $major  = $m[1];
        } elseif (preg_match('#Chrome/(\d+)#', $raw_ua, $m)) {
            $family = 'Chrome';
            $major  = $m[1];
        } elseif (preg_match('#Version/(\d+)[^/]*Safari#', $raw_ua, $m)) {
            $family = 'Safari';
            $major  = $m[1];
        }

        return array(
            'family' => $family,
            'major'  => $major,
        );
    }

    /**
     * Ключ transient: sha256 от (ip_subnet_hash | ua_family | ua_major | accept_language).
     *
     * Subnet вместо точного IP — устойчивость к смене IP внутри одной NAT.
     * UA-family/major вместо raw UA — устойчивость к микроизменениям строки UA,
     * сохраняя защиту от разных браузеров на одном IP (разделяет коллизии).
     * Accept-Language — доп. различитель для NAT'ов с mixed-locale юзерами.
     *
     * Все компоненты — нечувствительные к fingerprinting-грейзоне сигналы
     * (subnet/family/lang публично очевидны из любого запроса).
     */
    private static function get_referral_transient_key( string $ip, string $raw_ua, string $accept_language ): string {
        $subnet      = self::extract_subnet($ip);
        $ua          = self::normalize_ua($raw_ua);
        $al          = substr($accept_language, 0, 32);
        $subnet_hash = hash('sha256', $subnet);
        $payload     = $subnet_hash . '|' . $ua['family'] . '|' . $ua['major'] . '|' . $al;
        return 'cb_aff_ref_' . substr(hash('sha256', $payload), 0, 16);
    }

    /**
     * Собирает (raw_ua, accept_language) из $_SERVER для call-site'ов, которые
     * не передают эти значения вручную. Вынесено, чтобы не дублировать.
     *
     * @return array{ua: string, al: string}
     */
    private static function collect_request_headers_for_key(): array {
        // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- UA используется только для per-request composite-ключа, не для кеширования.
        $raw_ua = isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
            : '';
        $al     = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE']))
            : '';
        return array( 'ua' => $raw_ua, 'al' => $al );
    }

    /**
     * HMAC-SHA256 подпись cookie payload.
     */
    private function compute_cookie_hmac( string $payload ): string {
        return self::compute_cookie_hmac_static($payload);
    }

    private static function compute_cookie_hmac_static( string $payload ): string {
        $key = defined('CB_ENCRYPTION_KEY') ? (string) CB_ENCRYPTION_KEY : '';
        if ($key === '') {
            // Fail-closed: секрет не сконфигурирован — подпись не генерируется.
            return '';
        }
        return hash_hmac('sha256', $payload, $key);
    }

    /* ═══════════════════════════════════════
     *  КЛИКИ — логирование
     * ═══════════════════════════════════════ */

    /**
     * Запись клика в cashback_affiliate_clicks.
     */
    private function log_click( string $click_id, int $referrer_id, string $ip ): void {
        global $wpdb;

        $user_agent  = isset($_SERVER['HTTP_USER_AGENT'])
            ? mb_substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 512)
            : null;
        $referer_url = isset($_SERVER['HTTP_REFERER'])
            ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']))
            : null;
        $landing_url = isset($_SERVER['REQUEST_URI'])
            ? esc_url_raw( home_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) )
            : null;

        $wpdb->insert(
            $wpdb->prefix . 'cashback_affiliate_clicks',
            array(
                'click_id'    => $click_id,
                'referrer_id' => $referrer_id,
                'ip_address'  => $ip,
                'user_agent'  => $user_agent,
                'referer_url' => $referer_url,
                'landing_url' => $landing_url,
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s' )
        );
    }

    /* ═══════════════════════════════════════
     *  ПРИВЯЗКА — при регистрации
     * ═══════════════════════════════════════ */

    /**
     * Привязка реферала при регистрации пользователя (F-22-003).
     * Вызывается из user_register hook (priority 20).
     *
     * Записывает attribution snapshot в profile:
     *   • attribution_source (cookie | transient)
     *   • attribution_confidence (high | medium | low)
     *   • collision_detected (bool из read_referral_transient)
     *   • review_status (none если confidence=high, иначе pending)
     *   • antifraud_signals (JSON-сериализованный список сигналов)
     *
     * Антифрод-решение делегирует validate_referral() (передаёт source,
     * cookie_valid, collision_detected). Hard-block → профиль создаётся
     * без реферера, audit multi_signal_block / invalid_* уже записан внутри
     * validate_referral().
     */
    public function bind_referral_on_registration( int $user_id ): void {
        if (!Cashback_Affiliate_DB::is_module_enabled()) {
            return;
        }

        $ip = class_exists('Cashback_Encryption')
            ? Cashback_Encryption::get_client_ip()
            // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- REMOTE_ADDR set by webserver from TCP connection, not client-controlled; per-request only.
            : ( isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0' );

        // Приоритет: signed cookie → серверный transient fallback.
        $cookie       = self::read_referral_cookie();
        $cookie_valid = ($cookie !== null);
        $source       = 'cookie';
        $collision    = false;

        if (!$cookie) {
            $cookie = self::read_referral_transient($ip);
            $source = 'transient';
            if (is_array($cookie)) {
                $collision = !empty($cookie['collision']);
            }
        }

        if (!$cookie) {
            // Профиль создаётся для всех юзеров, с или без реферера.
            Cashback_Affiliate_DB::ensure_profile($user_id);
            return;
        }

        $referrer_id = (int) $cookie['referrer_id'];
        $click_id    = (string) $cookie['click_id'];

        // F-22-003: rate-limit на signup-binding. Composite-ключ subnet+ua_family
        // (не голый IP) чтобы не страдали соседи по NAT с разными браузерами.
        // При blocked — binding НЕ отменяется, downgrade до confidence=low
        // + review_status=pending + signal в antifraud_signals. Regex:
        // 'affiliate_signup'...subnet/family/composite_key/rl_key.
        $rl_signup_blocked = false;
        if (class_exists('Cashback_Rate_Limiter')) {
            $hdr_rl        = self::collect_request_headers_for_key();
            $ua_norm       = self::normalize_ua($hdr_rl['ua']);
            $subnet_hash   = hash('sha256', self::extract_subnet($ip));
            $composite_key = substr($subnet_hash, 0, 16) . ':' . $ua_norm['family'];
            $rl_check      = Cashback_Rate_Limiter::check('affiliate_signup', 0, $composite_key);
            $rl_signup_blocked = empty($rl_check['allowed']);
        }

        // Антифрод проверки
        $check = Cashback_Affiliate_Antifraud::validate_referral(
            $referrer_id,
            $user_id,
            $ip,
            $click_id,
            $source,
            $cookie_valid,
            $collision
        );

        Cashback_Affiliate_DB::ensure_profile($user_id);

        if (!$check['allowed']) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log(sprintf(
                '[Affiliate] bind_referral BLOCKED: user=%d, referrer=%d, reason=%s',
                $user_id,
                $referrer_id,
                (string) ($check['reason'] ?? '')
            ));
            self::clear_referral_cookie();
            self::clear_referral_transient($ip);
            return;
        }

        // F-22-003: merge RL-blocked в итоговое решение — downgrade,
        // не reject. Confidence принудительно 'low', signal добавляется,
        // review_status → pending (review queue).
        $signals_list = array_values($check['signals'] ?? array());
        if ($rl_signup_blocked) {
            if (!in_array('rate_limit_signup_blocked', $signals_list, true)) {
                $signals_list[] = 'rate_limit_signup_blocked';
            }
            $confidence = 'low';
            if (class_exists('Cashback_Affiliate_Audit')) {
                Cashback_Affiliate_Audit::log('rate_limit_downgrade_bind', array(
                    'target_user_id' => $user_id,
                    'referrer_id'    => $referrer_id,
                    'click_id'       => $click_id,
                    'confidence'     => 'low',
                    'signals'        => $signals_list,
                    'reason'         => 'affiliate_signup_rate_limit',
                ));
            }
        } else {
            $confidence = (string) $check['confidence'];
        }

        // F-22-003: transient-level confidence_override (NAT collision) применяется
        // ПОСЛЕ antifraud-расчёта. Это закрывает edge case: при выключенной
        // галке антифрода validate_referral() возвращает medium, но сам факт
        // NAT-коллизии уже зафиксирован в transient на уровне store_referral_transient
        // — downgrade'им до low и явно добавляем signal для аудит-трейла.
        if ($source === 'transient'
            && isset($cookie['confidence_override'])
            && $cookie['confidence_override'] === 'low'
        ) {
            $confidence = 'low';
            if (!in_array('nat_collision_detected', $signals_list, true)) {
                $signals_list[] = 'nat_collision_detected';
            }
        }

        $review_status = ($confidence === 'high') ? 'none' : 'pending';
        $signals_json  = !empty($signals_list)
            ? (string) wp_json_encode($signals_list)
            : null;

        global $wpdb;
        $table = $wpdb->prefix . 'cashback_affiliate_profiles';

        // Атомарная привязка (UPDATE WHERE referred_by_user_id IS NULL — immutable)
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE %i
             SET referred_by_user_id    = %d,
                 referral_click_id      = %s,
                 referred_at            = NOW(),
                 attribution_source     = %s,
                 attribution_confidence = %s,
                 collision_detected     = %d,
                 review_status          = %s,
                 antifraud_signals      = %s
             WHERE user_id = %d AND referred_by_user_id IS NULL',
            $table,
            $referrer_id,
            $click_id,
            $source,
            $confidence,
            $collision ? 1 : 0,
            $review_status,
            $signals_json,
            $user_id
        ));

        if ($updated) {
            // Обновляем клик — записываем ID зарегистрированного пользователя
            $wpdb->update(
                $wpdb->prefix . 'cashback_affiliate_clicks',
                array(
                    'registered_user_id' => $user_id,
                    'registered_at'      => current_time('mysql'),
                ),
                array( 'click_id' => $click_id ),
                array( '%d', '%s' ),
                array( '%s' )
            );

            // Убеждаемся что у реферера тоже есть профиль
            Cashback_Affiliate_DB::ensure_profile($referrer_id);

            // Уведомление рефереру о новом реферале
            do_action('cashback_notification_affiliate_referral', $referrer_id, $user_id);

            // Affiliate-specific audit (не write_audit_log — нужны confidence/signals).
            if (class_exists('Cashback_Affiliate_Audit')) {
                Cashback_Affiliate_Audit::log('referral_bound', array(
                    'target_user_id' => $user_id,
                    'referrer_id'    => $referrer_id,
                    'click_id'       => $click_id,
                    'confidence'     => $confidence,
                    'signals'        => $signals_list,
                    'payload'        => array(
                        'source'          => $source,
                        'collision'       => $collision,
                        'review_status'   => $review_status,
                        'cookie_valid'    => $cookie_valid,
                        'rl_signup_block' => $rl_signup_blocked,
                    ),
                ));
            }
        }

        self::clear_referral_cookie();
        self::clear_referral_transient($ip);
    }

    /* ═══════════════════════════════════════
     *  КОМИССИИ — начисление (batch)
     * ═══════════════════════════════════════ */

    /**
     * Batch-начисление партнёрских комиссий.
     * Вызывается ВНУТРИ process_ready_transactions() под глобальным lock.
     *
     * Атомарность (F-22-001, F-22-002):
     *   - Если $in_transaction=false — метод сам открывает START TRANSACTION /
     *     COMMIT / ROLLBACK; при внешней TX caller отвечает за границы.
     *   - FOR UPDATE на cashback_user_balance рефереров берётся в начале TX.
     *   - Balance-delta аккумулируется ТОЛЬКО для ledger-строк с rows_affected=1
     *     (ON DUPLICATE KEY UPDATE id=id = дубль → delta не применяется).
     *
     * @param array $candidates      [{id, user_id, cashback}, ...]
     * @param bool  $in_transaction  true = caller уже открыл TX, метод не открывает свою.
     * @return array{inserted: int, amount: string, errors: string[]}
     */
    public static function process_affiliate_commissions( array $candidates, bool $in_transaction = false ): array {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $result = array(
            'inserted' => 0,
            'amount'   => '0.00',
            'errors'   => array(),
        );

        if (empty($candidates)) {
            return $result;
        }

        if (!Cashback_Affiliate_DB::is_module_enabled()) {
            return $result;
        }

        // Собираем уникальные user_id из кандидатов
        $user_ids = array_unique(array_column($candidates, 'user_id'));
        if (empty($user_ids)) {
            return $result;
        }

        // Находим у кого из этих пользователей есть активный реферер
        // (F-22-003): SELECT расширен снэпшотом attribution-полей профиля —
        // source/confidence/collision/review_status/signals копируются в
        // accrual при INSERT (immutable historical quality).
        $profiles_table     = $prefix . 'cashback_affiliate_profiles';
        $user_profile_table = $prefix . 'cashback_user_profile';
        $id_placeholders    = implode(',', array_fill(0, count($user_ids), '%d'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $id_placeholders из array_fill('%d').
        $referrals = $wpdb->get_results($wpdb->prepare(
            "SELECT ap.user_id, ap.referred_by_user_id,
                    ap.attribution_source, ap.attribution_confidence,
                    ap.collision_detected, ap.review_status, ap.antifraud_signals,
                    ap.referral_reward_eligible
             FROM %i ap
             INNER JOIN %i rp
                 ON rp.user_id = ap.referred_by_user_id
                 AND rp.affiliate_status = 'active'
             INNER JOIN %i up
                 ON up.user_id = ap.referred_by_user_id
                 AND up.status != 'banned'
             WHERE ap.user_id IN ({$id_placeholders})
               AND ap.referred_by_user_id IS NOT NULL",
            $profiles_table,
            $profiles_table,
            $user_profile_table,
            ...$user_ids
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        if (empty($referrals)) {
            return $result;
        }

        // Карта referred_user_id → referrer_id + profile_attrs snapshot.
        // Профили с referral_reward_eligible=0 (manual_rejected или антифрод)
        // полностью исключаются — accrual не создаётся.
        $referral_map  = array();
        $profile_attrs = array();
        foreach ($referrals as $row) {
            if ((int) ($row['referral_reward_eligible'] ?? 1) === 0) {
                continue; // ineligible — skip entirely
            }
            $uid               = (int) $row['user_id'];
            $referral_map[$uid]  = (int) $row['referred_by_user_id'];
            $profile_attrs[$uid] = array(
                'source'        => isset($row['attribution_source']) ? (string) $row['attribution_source'] : null,
                'confidence'    => isset($row['attribution_confidence']) ? (string) $row['attribution_confidence'] : null,
                'collision'     => (int) ($row['collision_detected'] ?? 0),
                'review_status' => isset($row['review_status']) ? (string) $row['review_status'] : 'none',
                'signals'       => isset($row['antifraud_signals']) ? (string) $row['antifraud_signals'] : null,
            );
        }

        if (empty($referral_map)) {
            return $result;
        }

        // Кешируем ставки рефереров
        $referrer_ids = array_unique(array_values($referral_map));
        $rates_cache  = self::batch_get_rates($referrer_ids);
        $global_rate  = Cashback_Affiliate_DB::get_global_rate();

        $accruals_table = $prefix . 'cashback_affiliate_accruals';
        $ledger_table   = $prefix . 'cashback_balance_ledger';
        $balance_table  = $prefix . 'cashback_user_balance';

        // F-22-003: предварительно читаем существующие accruals, чтобы не
        // перезаписать их status/snapshot (immutable quality) и принять
        // gate-решение по ИХ snapshot, а не по текущему профилю.
        $candidate_tx_ids = array_map('intval', array_column($candidates, 'id'));
        $existing_accruals = array();
        if (!empty($candidate_tx_ids)) {
            $tx_ph = implode(',', array_fill(0, count($candidate_tx_ids), '%d'));
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $tx_ph из array_fill('%d').
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT transaction_id, status,
                        attribution_confidence, review_status_at_creation
                 FROM %i
                 WHERE transaction_id IN ({$tx_ph})",
                $accruals_table,
                ...$candidate_tx_ids
            ), ARRAY_A);
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $existing_accruals[ (int) $r['transaction_id'] ] = array(
                        'status'                    => (string) $r['status'],
                        'attribution_confidence'    => $r['attribution_confidence'] ?? null,
                        'review_status_at_creation' => $r['review_status_at_creation'] ?? null,
                    );
                }
            }
        }

        // Gate: вернуть 'available' или 'pending' по snapshot.
        // Legacy (NULL confidence — pre-F-22-003) → available (backward-compat).
        $compute_gate_status = static function ( ?string $confidence, ?string $review_status ): string {
            if ($confidence === null) {
                return 'available'; // legacy row, no snapshot
            }
            if ($confidence === 'high') {
                return 'available';
            }
            if (in_array($review_status, array( 'none', 'manual_approved', 'auto_approved' ), true)) {
                return 'available';
            }
            return 'pending';
        };

        // Формируем accruals и ledger entries
        $accrual_values      = array();
        $accrual_args        = array();
        $accrual_actions     = array(); // per-index: 'update' | 'insert' | 'skip'
        $ledger_values       = array();
        $ledger_args         = array();
        $balance_deltas      = array(); // referrer_id → total commission

        foreach ($candidates as $tx) {
            $user_id  = (int) $tx['user_id'];
            $tx_id    = (int) $tx['id'];
            $cashback = (float) $tx['cashback'];

            if (!isset($referral_map[ $user_id ]) || $cashback <= 0) {
                continue;
            }

            $referrer_id = $referral_map[ $user_id ];
            $rate        = $rates_cache[ $referrer_id ] ?? $global_rate;
            // Сохраняем round()-семантику (half-away-from-zero), но сразу
            // канонизируем результат в decimal-string (F-11-002): устраняем
            // locale-leak при последующем `(string) \$commission` в bcadd.
            $commission_raw  = round($cashback * (float) $rate / 100, 2);
            $commission      = number_format($commission_raw, 2, '.', '');
            $idempotency_key = 'aff_accrual_' . $tx_id;

            if ($commission_raw <= 0) {
                continue;
            }

            $snapshot = $profile_attrs[ $user_id ];

            // F-22-003: решаем accrual-status по нужному snapshot.
            //   - existing accrual → его собственный snapshot (immutable)
            //   - new accrual → current profile snapshot
            if (isset($existing_accruals[ $tx_id ])) {
                $ex = $existing_accruals[ $tx_id ];
                // Если accrual уже в terminal-статусе — не трогаем.
                if (in_array($ex['status'], array( 'available', 'frozen', 'paid' ), true)) {
                    continue;
                }
                $status = $compute_gate_status(
                    $ex['attribution_confidence'] ?? null,
                    $ex['review_status_at_creation'] ?? null
                );
                if ($status === 'pending') {
                    // Существующий pending + gate держит pending → ledger/balance не трогаем.
                    continue;
                }
                $action = 'update';
            } else {
                $status = $compute_gate_status(
                    $snapshot['confidence'] ?? null,
                    $snapshot['review_status'] ?? null
                );
                $action = 'insert';
            }

            $reference_id = Cashback_Affiliate_DB::generate_affiliate_reference_id();

            // Accrual — 14 полей: 9 базовых + 5 snapshot (F-22-003).
            $accrual_values[]  = '(%s, %d, %d, %d, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s)';
            $accrual_args[]    = $reference_id;
            $accrual_args[]    = $referrer_id;
            $accrual_args[]    = $user_id;
            $accrual_args[]    = $tx_id;
            $accrual_args[]    = number_format($cashback, 2, '.', '');
            $accrual_args[]    = number_format((float) $rate, 2, '.', '');
            $accrual_args[]    = $commission;
            $accrual_args[]    = $status;
            $accrual_args[]    = $idempotency_key;
            // Snapshot:
            $accrual_args[]    = $snapshot['source']        ?? null;
            $accrual_args[]    = $snapshot['confidence']    ?? null;
            $accrual_args[]    = $snapshot['collision']     ?? 0;
            $accrual_args[]    = $snapshot['review_status'] ?? 'none';
            $accrual_args[]    = $snapshot['signals']       ?? null;
            $accrual_actions[] = $action;

            if ($status === 'available') {
                // Ledger — только для available. Pending не проводят delta до promotion.
                $ledger_values[] = '(%d, %s, %s, %d, %s, %d, %s)';
                $ledger_args[]   = $referrer_id;
                $ledger_args[]   = 'affiliate_accrual';
                $ledger_args[]   = $commission;
                $ledger_args[]   = $tx_id;
                $ledger_args[]   = 'affiliate_accrual';
                $ledger_args[]   = $tx_id;
                $ledger_args[]   = $idempotency_key;

                if (!isset($balance_deltas[ $referrer_id ])) {
                    $balance_deltas[ $referrer_id ] = 0.0;
                }
                $balance_deltas[ $referrer_id ] += $commission;
            }
        }

        if (empty($accrual_values)) {
            return $result;
        }

        $owns_tx = !$in_transaction;
        if ($owns_tx) {
            $wpdb->query('START TRANSACTION');
        }

        try {
            // Блокируем balance-строки всех рефереров, прежде чем менять ledger/balance.
            $referrer_ids = array_unique(array_values($referral_map));
            if (!empty($referrer_ids)) {
                $lock_placeholders = implode(',', array_fill(0, count($referrer_ids), '%d'));
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $lock_placeholders из array_fill('%d').
                $wpdb->get_results($wpdb->prepare(
                    "SELECT user_id FROM %i WHERE user_id IN ({$lock_placeholders}) FOR UPDATE",
                    $balance_table,
                    ...$referrer_ids
                ));
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            }

            // F-22-003: UPDATE (промоция существующего) использует target status
            // из predetermined snapshot-gate; INSERT несёт все 14 полей (9 +
            // 5 attribution snapshot). Per-item action известен из $accrual_actions.
            $updated_count         = 0;
            $insert_accrual_values = array();
            $insert_accrual_args   = array();

            foreach ($accrual_values as $idx => $value_tpl) {
                $offset    = $idx * 14;
                $tx_id     = $accrual_args[ $offset + 3 ];
                $target_status = (string) $accrual_args[ $offset + 7 ];
                $action    = $accrual_actions[ $idx ] ?? 'insert';

                if ($action === 'update') {
                    // Promote existing pending/declined → available (gate уже разрешил).
                    $upd = $wpdb->query($wpdb->prepare(
                        "UPDATE %i
                         SET status            = %s,
                             cashback_amount   = %s,
                             commission_rate   = %s,
                             commission_amount = %s
                         WHERE transaction_id = %d AND status IN ('pending','declined')
                         LIMIT 1",
                        $accruals_table,
                        $target_status,
                        $accrual_args[ $offset + 4 ],
                        $accrual_args[ $offset + 5 ],
                        $accrual_args[ $offset + 6 ],
                        $tx_id
                    ));

                    // TOCTOU note: если $upd === 0, запись уже в terminal-
                    // статусе между SELECT и UPDATE — не фолбэчим в INSERT
                    // (idempotency_key conflict был бы ожидаем).
                    if ($upd > 0) {
                        ++$updated_count;
                    }
                } else {
                    // New accrual — 14 полей с snapshot-ом.
                    $insert_accrual_values[] = $value_tpl;
                    for ($i = 0; $i < 14; $i++) {
                        $insert_accrual_args[] = $accrual_args[ $offset + $i ];
                    }
                }
            }

            if (!empty($insert_accrual_values)) {
                $accrual_sql = implode(', ', $insert_accrual_values);
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $accrual_sql из array_fill-шаблонов.
                // ON DUPLICATE KEY UPDATE: status берём из VALUES() (gate-решение
                // caller-а), НЕ хардкодим 'available'. Snapshot immutable — не
                // переписываем attribution_* полями из VALUES.
                $accrual_result = $wpdb->query($wpdb->prepare(
                    "INSERT INTO %i
                         (reference_id, referrer_id, referred_user_id, transaction_id,
                          cashback_amount, commission_rate, commission_amount, status, idempotency_key,
                          attribution_source, attribution_confidence, collision_detected,
                          review_status_at_creation, antifraud_signals)
                     VALUES {$accrual_sql}
                     ON DUPLICATE KEY UPDATE
                         status            = VALUES(status),
                         cashback_amount   = VALUES(cashback_amount),
                         commission_rate   = VALUES(commission_rate),
                         commission_amount = VALUES(commission_amount)",
                    $accruals_table,
                    ...$insert_accrual_args
                ));
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

                if ($accrual_result === false) {
                    throw new \RuntimeException('Affiliate accruals INSERT failed: ' . $wpdb->last_error);
                }
            }

            // Построчный INSERT ledger с проверкой rows_affected — ключ F-22-001:
            // только реально вставленные строки (rows_affected=1) дают вклад в balance-delta.
            // ON DUPLICATE KEY UPDATE id=id = дубль → rows_affected=0 → delta НЕ применяется.
            $effective_deltas         = array();
            $effective_inserted_count = 0;
            foreach ($ledger_values as $idx => $row_tpl) {
                $offset      = $idx * 7;
                $referrer_id = (int) $ledger_args[ $offset + 0 ];
                $commission  = $ledger_args[ $offset + 2 ];

                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $row_tpl из array_fill-шаблонов.
                $ledger_insert = $wpdb->query($wpdb->prepare(
                    "INSERT INTO %i
                         (user_id, type, amount, transaction_id, reference_type, reference_id, idempotency_key)
                     VALUES {$row_tpl}
                     ON DUPLICATE KEY UPDATE id = id",
                    $ledger_table,
                    $ledger_args[ $offset + 0 ],
                    $ledger_args[ $offset + 1 ],
                    $ledger_args[ $offset + 2 ],
                    $ledger_args[ $offset + 3 ],
                    $ledger_args[ $offset + 4 ],
                    $ledger_args[ $offset + 5 ],
                    $ledger_args[ $offset + 6 ]
                ));
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

                if ($ledger_insert === false) {
                    throw new \RuntimeException('Affiliate balance_ledger INSERT failed: ' . $wpdb->last_error);
                }

                $rows_affected = (int) $ledger_insert;
                if ($rows_affected === 1) {
                    if (!isset($effective_deltas[ $referrer_id ])) {
                        $effective_deltas[ $referrer_id ] = '0.00';
                    }
                    $effective_deltas[ $referrer_id ] = bcadd($effective_deltas[ $referrer_id ], (string) $commission, 2);
                    ++$effective_inserted_count;
                }
                // rows_affected === 0 → дубль ledger-ключа; balance-delta не применяется.
            }

            // Обновляем balance рефереров ТОЛЬКО на effective deltas.
            $total_commission = '0.00';
            foreach ($effective_deltas as $referrer_id => $delta) {
                $balance_update = $wpdb->query($wpdb->prepare(
                    'INSERT INTO %i
                         (user_id, available_balance, version)
                     VALUES (%d, %s, 0)
                     ON DUPLICATE KEY UPDATE
                         available_balance = available_balance + CAST(%s AS DECIMAL(18,2)),
                         version = version + 1',
                    $balance_table,
                    $referrer_id,
                    $delta,
                    $delta
                ));

                if ($balance_update === false) {
                    throw new \RuntimeException("Balance update failed for referrer {$referrer_id}: " . $wpdb->last_error);
                }
                $total_commission = bcadd($total_commission, $delta, 2);
            }

            if ($owns_tx) {
                $wpdb->query('COMMIT');
            }

            $result['inserted']             = $effective_inserted_count;
            $result['updated_from_pending'] = $updated_count;
            $result['amount']               = $total_commission;

            // Уведомление рефереров — только для реально применённых дельт.
            if (!empty($effective_deltas)) {
                $accrual_notifications = array();
                foreach ($effective_deltas as $ref_id => $delta) {
                    $accrual_notifications[ $ref_id ] = array(
                        'total' => (float) $delta,
                        'count' => 0,
                    );
                }
                foreach ($candidates as $tx) {
                    $uid = (int) $tx['user_id'];
                    if (isset($referral_map[ $uid ])) {
                        $ref_id = $referral_map[ $uid ];
                        if (isset($accrual_notifications[ $ref_id ])) {
                            ++$accrual_notifications[ $ref_id ]['count'];
                        }
                    }
                }
                do_action('cashback_notification_affiliate_commission', $accrual_notifications);
            }
        } catch (\Throwable $e) {
            if ($owns_tx) {
                $wpdb->query('ROLLBACK');
            }
            $result['errors'][] = $e->getMessage();
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Affiliate] process_affiliate_commissions error: ' . $e->getMessage());
            // При внешней TX caller обязан сделать ROLLBACK — rethrow для сохранения атомарности.
            if (!$owns_tx) {
                throw $e;
            }
        }

        return $result;
    }

    /**
     * Batch-получение ставок рефереров.
     *
     * @param int[] $referrer_ids
     * @return array<int, string> referrer_id → rate
     */
    private static function batch_get_rates( array $referrer_ids ): array {
        global $wpdb;
        $prefix = $wpdb->prefix;

        if (empty($referrer_ids)) {
            return array();
        }

        $profiles_table = $prefix . 'cashback_affiliate_profiles';
        $placeholders   = implode(',', array_fill(0, count($referrer_ids), '%d'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders из array_fill('%d').
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, affiliate_rate
             FROM %i
             WHERE user_id IN ({$placeholders}) AND affiliate_rate IS NOT NULL",
            $profiles_table,
            ...$referrer_ids
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        $rates = array();
        foreach ($rows as $row) {
            $rates[ (int) $row['user_id'] ] = $row['affiliate_rate'];
        }

        return $rates;
    }

    /* ═══════════════════════════════════════
     *  СИНХРОНИЗАЦИЯ PENDING-НАЧИСЛЕНИЙ
     * ═══════════════════════════════════════ */

    /**
     * Создаёт pending/declined accruals для транзакций рефералов, у которых ещё нет записи.
     * Обновляет статус существующих pending → declined и наоборот.
     * НЕ затрагивает balance/ledger — только информационные записи.
     *
     * @return array{created: int, updated: int, errors: string[]}
     */
    public static function sync_pending_accruals(): array {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $result = array(
			'created' => 0,
			'updated' => 0,
			'errors'  => array(),
		);

        if (!Cashback_Affiliate_DB::is_module_enabled()) {
            return $result;
        }

        // Canonical rate-string (F-11-002): %f в prepare locale-зависим; вместо
        // float → число переводим через number_format → `%s` в prepare.
        $global_rate_canon = number_format((float) Cashback_Affiliate_DB::get_global_rate(), 2, '.', '');

        $tx_table           = $prefix . 'cashback_transactions';
        $profiles_table     = $prefix . 'cashback_affiliate_profiles';
        $user_profile_table = $prefix . 'cashback_user_profile';
        $accruals_table     = $prefix . 'cashback_affiliate_accruals';

        // 1. Найти транзакции рефералов без accrual (waiting/completed/hold/declined)
        $missing = $wpdb->get_results($wpdb->prepare(
            'SELECT t.id AS tx_id, t.user_id, t.cashback, t.order_status,
                    ap.referred_by_user_id AS referrer_id,
                    COALESCE(ap_ref.affiliate_rate, CAST(%s AS DECIMAL(5,2))) AS eff_rate
             FROM %i t
             INNER JOIN %i ap
                     ON ap.user_id = t.user_id
                    AND ap.referred_by_user_id IS NOT NULL
             INNER JOIN %i ap_ref
                     ON ap_ref.user_id = ap.referred_by_user_id
                    AND ap_ref.affiliate_status = \'active\'
             INNER JOIN %i up
                     ON up.user_id = ap.referred_by_user_id
                    AND up.status != \'banned\'
             WHERE t.order_status IN (\'waiting\',\'completed\',\'hold\',\'declined\')
               AND t.cashback > 0
               AND NOT EXISTS (
                   SELECT 1 FROM %i aa
                   WHERE aa.transaction_id = t.id AND aa.referrer_id = ap.referred_by_user_id
               )
             LIMIT 500',
            $global_rate_canon,
            $tx_table,
            $profiles_table,
            $profiles_table,
            $user_profile_table,
            $accruals_table
        ), ARRAY_A);

        // Создаём pending/declined accruals
        foreach ($missing as $row) {
            $cashback       = (float) $row['cashback'];
            $rate           = (float) $row['eff_rate'];
            $commission_raw = round($cashback * $rate / 100, 2);
            if ($commission_raw <= 0) {
                continue;
            }
            // Canonicalize (F-11-002): гарантируем decimal-string на границе wpdb,
            // сохраняя round()-семантику (half-away-from-zero).
            $commission = number_format($commission_raw, 2, '.', '');

            $status          = $row['order_status'] === 'declined' ? 'declined' : 'pending';
            $reference_id    = Cashback_Affiliate_DB::generate_affiliate_reference_id();
            $idempotency_key = 'aff_accrual_' . $row['tx_id'];

            $inserted = $wpdb->query($wpdb->prepare(
                'INSERT IGNORE INTO %i
                     (reference_id, referrer_id, referred_user_id, transaction_id,
                      cashback_amount, commission_rate, commission_amount, status, idempotency_key)
                 VALUES (%s, %d, %d, %d, %s, %s, %s, %s, %s)',
                $accruals_table,
                $reference_id,
                (int) $row['referrer_id'],
                (int) $row['user_id'],
                (int) $row['tx_id'],
                number_format($cashback, 2, '.', ''),
                number_format($rate, 2, '.', ''),
                $commission,
                $status,
                $idempotency_key
            ));

            if ($inserted) {
                ++$result['created'];
            }
        }

        // 2. Синхронизация статусов: pending ↔ declined
        // pending → declined (транзакция была отклонена)
        $updated_declined   = $wpdb->query($wpdb->prepare(
            'UPDATE %i aa
             INNER JOIN %i t ON t.id = aa.transaction_id
             SET aa.status = \'declined\'
             WHERE aa.status = \'pending\' AND t.order_status = \'declined\'',
            $accruals_table,
            $tx_table
        ));
        $result['updated'] += (int) $updated_declined;

        // declined → pending (транзакция вернулась в активный статус после апелляции)
        $updated_pending    = $wpdb->query($wpdb->prepare(
            'UPDATE %i aa
             INNER JOIN %i t ON t.id = aa.transaction_id
             SET aa.status = \'pending\'
             WHERE aa.status = \'declined\' AND t.order_status IN (\'waiting\',\'completed\',\'hold\')',
            $accruals_table,
            $tx_table
        ));
        $result['updated'] += (int) $updated_pending;

        // 3. Обновляем суммы если comission транзакции изменилась (пересчёт кешбэка триггером)
        $wpdb->query($wpdb->prepare(
            'UPDATE %i aa
             INNER JOIN %i t ON t.id = aa.transaction_id
             SET aa.cashback_amount = t.cashback,
                 aa.commission_amount = ROUND(t.cashback * aa.commission_rate / 100, 2)
             WHERE aa.status IN (\'pending\',\'declined\')
               AND ABS(aa.cashback_amount - t.cashback) >= 0.01',
            $accruals_table,
            $tx_table
        ));

        return $result;
    }

    /* ═══════════════════════════════════════
     *  ЗАМОРОЗКА / РАЗМОРОЗКА
     * ═══════════════════════════════════════ */

    /**
     * Заморозка партнёрских начислений при отключении от программы.
     * Кешбэк остаётся доступным, замораживается только affiliate-часть.
     *
     * @param int $user_id  Пользователь
     * @param int $admin_id Администратор, выполняющий действие
     * @return bool
     */
    public static function freeze_affiliate_balance( int $user_id, int $admin_id ): bool {
        global $wpdb;
        $prefix         = $wpdb->prefix;
        $profiles_table = $prefix . 'cashback_affiliate_profiles';
        $ledger_table   = $prefix . 'cashback_balance_ledger';
        $balance_table  = $prefix . 'cashback_user_balance';
        $accruals_table = $prefix . 'cashback_affiliate_accruals';

        try {
            $wpdb->query('START TRANSACTION');

            // Lock affiliate profile
            $profile = $wpdb->get_row($wpdb->prepare(
                'SELECT affiliate_status, affiliate_frozen_amount
                 FROM %i
                 WHERE user_id = %d FOR UPDATE',
                $profiles_table,
                $user_id
            ), ARRAY_A);

            if (!$profile || $profile['affiliate_status'] !== 'active') {
                $wpdb->query('ROLLBACK');
                return false;
            }

            // Считаем net affiliate balance из единого леджера
            $net_affiliate = $wpdb->get_var($wpdb->prepare(
                'SELECT COALESCE(SUM(amount), 0)
                 FROM %i
                 WHERE user_id = %d
                   AND type IN (\'affiliate_accrual\',\'affiliate_reversal\',\'affiliate_freeze\',\'affiliate_unfreeze\')',
                $ledger_table,
                $user_id
            ));
            $net_affiliate = (float) $net_affiliate;

            // Lock balance row
            $balance = $wpdb->get_row($wpdb->prepare(
                'SELECT available_balance, frozen_balance
                 FROM %i
                 WHERE user_id = %d FOR UPDATE',
                $balance_table,
                $user_id
            ), ARRAY_A);

            if (!$balance) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            // Заморозить можно не больше чем available_balance и не больше чем net affiliate
            $freeze_amount = max(0.0, min($net_affiliate, (float) $balance['available_balance']));

            if ($freeze_amount > 0) {
                $idemp_key = 'aff_freeze_' . $user_id . '_' . time();

                // Запись в единый balance ledger
                $wpdb->query($wpdb->prepare(
                    'INSERT INTO %i
                         (user_id, type, amount, reference_type, idempotency_key)
                     VALUES (%d, \'affiliate_freeze\', %s, \'affiliate_freeze\', %s)
                     ON DUPLICATE KEY UPDATE id = id',
                    $ledger_table,
                    $user_id,
                    number_format(-$freeze_amount, 2, '.', ''),
                    $idemp_key
                ));

                // Обновляем balance cache
                $wpdb->query($wpdb->prepare(
                    'UPDATE %i
                     SET available_balance = available_balance - CAST(%s AS DECIMAL(18,2)),
                         frozen_balance    = frozen_balance + CAST(%s AS DECIMAL(18,2)),
                         version = version + 1
                     WHERE user_id = %d AND available_balance >= CAST(%s AS DECIMAL(18,2))',
                    $balance_table,
                    number_format($freeze_amount, 2, '.', ''),
                    number_format($freeze_amount, 2, '.', ''),
                    $user_id,
                    number_format($freeze_amount, 2, '.', '')
                ));
            }

            // Обновляем affiliate profile
            $wpdb->query($wpdb->prepare(
                'UPDATE %i
                 SET affiliate_status = \'disabled\',
                     affiliate_frozen_amount = %s,
                     disabled_at = NOW()
                 WHERE user_id = %d',
                $profiles_table,
                number_format($freeze_amount, 2, '.', ''),
                $user_id
            ));

            // Помечаем все available accruals как frozen
            $wpdb->query($wpdb->prepare(
                'UPDATE %i
                 SET status = \'frozen\'
                 WHERE referrer_id = %d AND status = \'available\'',
                $accruals_table,
                $user_id
            ));

            $wpdb->query('COMMIT');

            // Audit log (post-commit)
            if (class_exists('Cashback_Encryption')) {
                Cashback_Encryption::write_audit_log(
                    'affiliate_disabled',
                    $admin_id,
                    'user',
                    $user_id,
                    array( 'frozen_amount' => number_format($freeze_amount, 2, '.', '') )
                );
            }

            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Affiliate] freeze_affiliate_balance error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Разморозка партнёрских начислений при включении обратно в программу.
     *
     * @param int $user_id  Пользователь
     * @param int $admin_id Администратор
     * @return bool
     */
    public static function unfreeze_affiliate_balance( int $user_id, int $admin_id ): bool {
        global $wpdb;
        $prefix             = $wpdb->prefix;
        $profiles_table     = $prefix . 'cashback_affiliate_profiles';
        $user_profile_table = $prefix . 'cashback_user_profile';
        $balance_table      = $prefix . 'cashback_user_balance';
        $ledger_table       = $prefix . 'cashback_balance_ledger';
        $accruals_table     = $prefix . 'cashback_affiliate_accruals';

        try {
            $wpdb->query('START TRANSACTION');

            // Lock affiliate profile
            $profile = $wpdb->get_row($wpdb->prepare(
                'SELECT affiliate_status, affiliate_frozen_amount
                 FROM %i
                 WHERE user_id = %d FOR UPDATE',
                $profiles_table,
                $user_id
            ), ARRAY_A);

            if (!$profile || $profile['affiliate_status'] !== 'disabled') {
                $wpdb->query('ROLLBACK');
                return false;
            }

            // Проверяем что пользователь не забанен
            $user_status = $wpdb->get_var($wpdb->prepare(
                'SELECT status FROM %i
                 WHERE user_id = %d LIMIT 1',
                $user_profile_table,
                $user_id
            ));
            if ($user_status === 'banned') {
                $wpdb->query('ROLLBACK');
                return false;
            }

            $frozen_amount = (float) $profile['affiliate_frozen_amount'];

            if ($frozen_amount > 0) {
                // Lock balance
                $balance = $wpdb->get_row($wpdb->prepare(
                    'SELECT frozen_balance FROM %i
                     WHERE user_id = %d FOR UPDATE',
                    $balance_table,
                    $user_id
                ), ARRAY_A);

                // Размораживаем не больше чем есть в frozen
                $unfreeze_amount = min($frozen_amount, (float) ( $balance['frozen_balance'] ?? 0 ));

                if ($unfreeze_amount > 0) {
                    $idemp_key = 'aff_unfreeze_' . $user_id . '_' . time();

                    // Запись в единый balance ledger
                    $wpdb->query($wpdb->prepare(
                        'INSERT INTO %i
                             (user_id, type, amount, reference_type, idempotency_key)
                         VALUES (%d, \'affiliate_unfreeze\', %s, \'affiliate_unfreeze\', %s)
                         ON DUPLICATE KEY UPDATE id = id',
                        $ledger_table,
                        $user_id,
                        number_format($unfreeze_amount, 2, '.', ''),
                        $idemp_key
                    ));

                    // Обновляем balance
                    $wpdb->query($wpdb->prepare(
                        'UPDATE %i
                         SET frozen_balance    = frozen_balance - CAST(%s AS DECIMAL(18,2)),
                             available_balance = available_balance + CAST(%s AS DECIMAL(18,2)),
                             version = version + 1
                         WHERE user_id = %d AND frozen_balance >= CAST(%s AS DECIMAL(18,2))',
                        $balance_table,
                        number_format($unfreeze_amount, 2, '.', ''),
                        number_format($unfreeze_amount, 2, '.', ''),
                        $user_id,
                        number_format($unfreeze_amount, 2, '.', '')
                    ));
                }
            }

            // Обновляем affiliate profile
            $wpdb->query($wpdb->prepare(
                'UPDATE %i
                 SET affiliate_status = \'active\',
                     affiliate_frozen_amount = 0.00,
                     disabled_at = NULL
                 WHERE user_id = %d',
                $profiles_table,
                $user_id
            ));

            // Помечаем frozen accruals обратно как available
            $wpdb->query($wpdb->prepare(
                'UPDATE %i
                 SET status = \'available\'
                 WHERE referrer_id = %d AND status = \'frozen\'',
                $accruals_table,
                $user_id
            ));

            $wpdb->query('COMMIT');

            // Audit log
            if (class_exists('Cashback_Encryption')) {
                Cashback_Encryption::write_audit_log(
                    'affiliate_enabled',
                    $admin_id,
                    'user',
                    $user_id,
                    array( 'unfrozen_amount' => number_format($frozen_amount, 2, '.', '') )
                );
            }

            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Affiliate] unfreeze_affiliate_balance error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Атомарная повторная заморозка affiliate-части после разбана.
     *
     * Контракт (F-22-005, F-28-001):
     *   - Вызывается если affiliate_status = 'disabled' → нужно вернуть
     *     affiliate_frozen_amount обратно во frozen_balance.
     *   - При $in_transaction = true (по умолчанию) caller ДОЛЖЕН держать
     *     свою TX; метод берёт SELECT ... FOR UPDATE и меняет balance
     *     в рамках этой TX — без race-окна между unban и re-freeze.
     *   - При $in_transaction = false — метод открывает свою TX/COMMIT/ROLLBACK.
     *   - idempotency_key: 'aff_refreeze_{user_id}_{UUIDv7}' — уникален per
     *     invocation, защищает от дублей при быстрых повторных разбанах
     *     (ключ на time() давал коллизии в пределах секунды).
     *
     * @return bool true — успех либо no-op (affiliate не disabled / нулевой hold).
     */
    public static function re_freeze_after_unban_atomic( int $user_id, bool $in_transaction = true ): bool {
        global $wpdb;
        $prefix         = $wpdb->prefix;
        $profiles_table = $prefix . 'cashback_affiliate_profiles';
        $balance_table  = $prefix . 'cashback_user_balance';
        $ledger_table   = $prefix . 'cashback_balance_ledger';

        $owns_tx = !$in_transaction;
        if ($owns_tx) {
            $wpdb->query('START TRANSACTION');
        }

        try {
            $profile = $wpdb->get_row($wpdb->prepare(
                'SELECT affiliate_status, affiliate_frozen_amount
                 FROM %i
                 WHERE user_id = %d LIMIT 1',
                $profiles_table,
                $user_id
            ), ARRAY_A);

            if (!$profile || $profile['affiliate_status'] !== 'disabled') {
                if ($owns_tx) {
                    $wpdb->query('COMMIT');
                }
                return true;
            }

            $frozen_amount = (float) $profile['affiliate_frozen_amount'];
            if ($frozen_amount <= 0) {
                if ($owns_tx) {
                    $wpdb->query('COMMIT');
                }
                return true;
            }

            // FOR UPDATE: блокируем balance-строку, чтобы параллельный вывод
            // не успел списать available ДО нашего SELECT→UPDATE.
            $balance = $wpdb->get_row($wpdb->prepare(
                'SELECT available_balance FROM %i
                 WHERE user_id = %d LIMIT 1 FOR UPDATE',
                $balance_table,
                $user_id
            ), ARRAY_A);

            $available = (float) ( $balance['available_balance'] ?? 0 );
            $re_freeze = min($frozen_amount, $available);

            if ($re_freeze > 0) {
                // UUIDv7-based ключ: монотонен, уникален per invocation.
                $idemp_key = 'aff_refreeze_' . $user_id . '_' . cashback_generate_uuid7(false);

                $ledger_insert = $wpdb->query($wpdb->prepare(
                    'INSERT INTO %i
                         (user_id, type, amount, reference_type, idempotency_key)
                     VALUES (%d, \'affiliate_freeze\', %s, \'affiliate_freeze\', %s)
                     ON DUPLICATE KEY UPDATE id = id',
                    $ledger_table,
                    $user_id,
                    number_format(-$re_freeze, 2, '.', ''),
                    $idemp_key
                ));
                if ($ledger_insert === false) {
                    throw new \RuntimeException('re_freeze ledger INSERT failed: ' . $wpdb->last_error);
                }

                $balance_update = $wpdb->query($wpdb->prepare(
                    'UPDATE %i
                     SET available_balance = available_balance - CAST(%s AS DECIMAL(18,2)),
                         frozen_balance    = frozen_balance + CAST(%s AS DECIMAL(18,2)),
                         version = version + 1
                     WHERE user_id = %d AND available_balance >= CAST(%s AS DECIMAL(18,2))',
                    $balance_table,
                    number_format($re_freeze, 2, '.', ''),
                    number_format($re_freeze, 2, '.', ''),
                    $user_id,
                    number_format($re_freeze, 2, '.', '')
                ));
                if ($balance_update === false) {
                    throw new \RuntimeException('re_freeze balance UPDATE failed: ' . $wpdb->last_error);
                }
            }

            if ($owns_tx) {
                $wpdb->query('COMMIT');
            }
            return true;

        } catch (\Throwable $e) {
            if ($owns_tx) {
                $wpdb->query('ROLLBACK');
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Affiliate] re_freeze_after_unban_atomic error: ' . $e->getMessage());
            if (!$owns_tx) {
                throw $e;
            }
            return false;
        }
    }

    /**
     * Backward-compatible wrapper для re_freeze_after_unban_atomic().
     * Открывает собственную TX. Оставлен для внешних вызовов, которые не
     * держат свою транзакцию.
     *
     * Для новых callsite'ов используйте re_freeze_after_unban_atomic()
     * с $in_transaction=true — чтобы re-freeze выполнялся в той же TX
     * что и unban (F-28-001 race-окно закрыто только так).
     */
    public static function re_freeze_after_unban( int $user_id ): void {
        self::re_freeze_after_unban_atomic($user_id, false);
    }

    /* ═══════════════════════════════════════
     *  HELPERS
     * ═══════════════════════════════════════ */

    /**
     * Эффективная ставка реферера (индивидуальная или глобальная).
     */
    public static function get_effective_rate( int $referrer_id ): string {
        global $wpdb;

        $profiles_table = $wpdb->prefix . 'cashback_affiliate_profiles';

        $rate = $wpdb->get_var($wpdb->prepare(
            'SELECT affiliate_rate
             FROM %i
             WHERE user_id = %d LIMIT 1',
            $profiles_table,
            $referrer_id
        ));

        if ($rate !== null) {
            return $rate;
        }

        return Cashback_Affiliate_DB::get_global_rate();
    }

    /**
     * Реферальная ссылка пользователя.
     */
    public static function get_referral_link( int $user_id ): string {
        $token = Mariadb_Plugin::get_partner_token($user_id);

        // Fallback на user_id только если профиль не существует (не должно быть в норме)
        $ref_value = $token !== null ? $token : (string) $user_id;

        return add_query_arg('ref', $ref_value, home_url('/'));
    }

    /**
     * Статистика реферера для фронтенда.
     *
     * @return array{total_referrals: int, total_earned: string, total_available: string, total_frozen: string, total_pending: string, total_declined: string}
     */
    public static function get_referrer_stats( int $user_id ): array {
        global $wpdb;
        $prefix         = $wpdb->prefix;
        $profiles_table = $prefix . 'cashback_affiliate_profiles';
        $accruals_table = $prefix . 'cashback_affiliate_accruals';

        $total_referrals = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*)
             FROM %i
             WHERE referred_by_user_id = %d',
            $profiles_table,
            $user_id
        ));

        $total_earned = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(commission_amount), 0)
             FROM %i
             WHERE referrer_id = %d AND status IN (\'available\',\'frozen\',\'paid\')',
            $accruals_table,
            $user_id
        )) ?: '0.00';

        $total_available = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(commission_amount), 0)
             FROM %i
             WHERE referrer_id = %d AND status = \'available\'',
            $accruals_table,
            $user_id
        )) ?: '0.00';

        $total_frozen = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(commission_amount), 0)
             FROM %i
             WHERE referrer_id = %d AND status = \'frozen\'',
            $accruals_table,
            $user_id
        )) ?: '0.00';

        $total_pending = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(commission_amount), 0)
             FROM %i
             WHERE referrer_id = %d AND status = \'pending\'',
            $accruals_table,
            $user_id
        )) ?: '0.00';

        $total_declined = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(commission_amount), 0)
             FROM %i
             WHERE referrer_id = %d AND status = \'declined\'',
            $accruals_table,
            $user_id
        )) ?: '0.00';

        return array(
            'total_referrals' => $total_referrals,
            'total_earned'    => $total_earned,
            'total_available' => $total_available,
            'total_frozen'    => $total_frozen,
            'total_pending'   => $total_pending,
            'total_declined'  => $total_declined,
        );
    }

    /* ═══════════════════════════════════════
     *  F-22-003 — CRON auto-promote (Step 11)
     * ═══════════════════════════════════════ */

    /**
     * Количество дней без disputes/rejects/rate-limit events для
     * auto-promote low-confidence привязки. Literal (не option) — политика
     * безопасности, не настройка.
     */
    const AUTO_PROMOTE_DAYS = 14;

    /**
     * Batch limit на один cron-прогон — защищаем DB от long transaction
     * и покрываем типичный объём очереди за день. Если кандидатов больше —
     * следующий запуск (через сутки) добьёт остальных.
     */
    const AUTO_PROMOTE_BATCH = 500;

    /**
     * Ежедневный cron: auto-promote low-confidence привязок после
     * AUTO_PROMOTE_DAYS дней без негативных сигналов.
     *
     * ВАЖНО: меняется ТОЛЬКО review_status (none/pending → auto_approved).
     * attribution_confidence НЕ трогаем — это историческое качество
     * evidence, нельзя «улучшить временем».
     *
     * Criteria:
     *   • review_status = 'pending'
     *   • collision_detected = 0
     *   • referred_at <= NOW() - INTERVAL 14 DAY
     *   • antifraud_signals IS NULL OR JSON_LENGTH(antifraud_signals) = 0
     *   • нет declined accruals по этому referred_user
     *   • нет rate-limit events по этому referred_user / referrer
     *
     * Idempotency: повторный запуск матчит меньше кандидатов (те, кто уже
     * auto_approved — пропускаются в WHERE review_status='pending').
     *
     * Per-row audit: 'auto_promoted' с confidence (снят с профиля),
     * payload{previous_review_status, referred_at}.
     *
     * После promotion — вызываем process_affiliate_commissions() с
     * $in_transaction=false: pending accruals этого юзера идут в available
     * (accrual-level gate теперь разрешён, см. Шаг 8).
     */
    public static function auto_promote_low_confidence(): void {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return;
        }
        if (!class_exists('Cashback_Affiliate_DB') || !Cashback_Affiliate_DB::is_module_enabled()) {
            return;
        }

        $prefix         = $wpdb->prefix;
        $profiles_table = $prefix . 'cashback_affiliate_profiles';
        $accruals_table = $prefix . 'cashback_affiliate_accruals';
        $audit_table    = $prefix . 'cashback_affiliate_audit';

        // 1. Выборка кандидатов batch LIMIT 500.
        //    LEFT JOIN declined-accruals + LEFT JOIN rate-limit audit-events —
        //    отсекаем юзеров с любым негативным следом.
        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT ap.user_id,
                    ap.referred_by_user_id,
                    ap.attribution_confidence,
                    ap.review_status    AS previous_review_status,
                    ap.referred_at
             FROM %i ap
             LEFT JOIN %i acc
                 ON acc.referred_user_id = ap.user_id
                AND acc.status = 'declined'
             LEFT JOIN %i aud
                 ON ( aud.target_user_id = ap.user_id
                      OR aud.referrer_id = ap.referred_by_user_id )
                AND aud.event_type IN (
                      'rate_limit_blocked_click',
                      'rate_limit_downgrade_bind',
                      'multi_signal_block'
                )
                AND aud.created_at >= ap.referred_at
             WHERE ap.review_status = 'pending'
               AND ap.collision_detected = 0
               AND ap.referred_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)
               AND ( ap.antifraud_signals IS NULL
                     OR JSON_LENGTH(ap.antifraud_signals) = 0 )
               AND acc.id IS NULL
               AND aud.id IS NULL
             GROUP BY ap.user_id
             LIMIT 500",
            $profiles_table,
            $accruals_table,
            $audit_table
        ), ARRAY_A);

        if (!is_array($candidates) || empty($candidates)) {
            return;
        }

        // 2. Per-row UPDATE + audit + re-process pending accruals.
        foreach ($candidates as $row) {
            $uid = (int) $row['user_id'];
            if ($uid <= 0) {
                continue;
            }

            // Атомарное изменение review_status с guard на текущее 'pending'
            // — устраняет TOCTOU с admin-approve / admin-reject, случившимся
            // параллельно с cron-прогоном.
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE %i
                 SET review_status = 'auto_approved'
                 WHERE user_id = %d AND review_status = 'pending'
                 LIMIT 1",
                $profiles_table,
                $uid
            ));

            if ($updated !== 1) {
                continue; // admin успел либо approve, либо reject — пропускаем.
            }

            // Promote pending accruals этого юзера — их review_status_at_creation
            // переносится в auto_approved (ок, snapshot тоже обновляется как
            // accrual's own historical review_status).
            $wpdb->query($wpdb->prepare(
                "UPDATE %i
                 SET review_status_at_creation = 'auto_approved'
                 WHERE referred_user_id = %d
                   AND status = 'pending'
                   AND review_status_at_creation = 'pending'",
                $accruals_table,
                $uid
            ));

            // Audit per-row (обязательно для spot-check'а cron-прогонов).
            if (class_exists('Cashback_Affiliate_Audit')) {
                Cashback_Affiliate_Audit::log('auto_promoted', array(
                    'target_user_id' => $uid,
                    'referrer_id'    => (int) $row['referred_by_user_id'],
                    'confidence'     => $row['attribution_confidence'],
                    'reason'         => 'clean_' . self::AUTO_PROMOTE_DAYS . '_days',
                    'payload'        => array(
                        'previous_review_status' => $row['previous_review_status'],
                        'referred_at'            => $row['referred_at'],
                    ),
                ));
            }

            // Промоция pending → available через существующий pipeline.
            // Восстанавливаем candidate'ов из ledger'а транзакций, которые
            // имеют pending accrual для этого user_id.
            $promote_txs = $wpdb->get_results($wpdb->prepare(
                "SELECT acc.transaction_id AS id, acc.referred_user_id AS user_id, acc.cashback_amount AS cashback
                 FROM %i acc
                 WHERE acc.referred_user_id = %d
                   AND acc.status = 'pending'
                   AND acc.review_status_at_creation = 'auto_approved'",
                $accruals_table,
                $uid
            ), ARRAY_A);

            if (is_array($promote_txs) && !empty($promote_txs)) {
                // В отдельной TX: process_affiliate_commissions откроет свою.
                self::process_affiliate_commissions($promote_txs, false);
            }
        }
    }
}
