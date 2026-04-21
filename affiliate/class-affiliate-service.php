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
     * Сохранение реферальных данных в transient (fallback при отсутствии cookie).
     * Ключ: IP адрес посетителя. TTL синхронизирован с cookie_ttl из настроек.
     */
    private function store_referral_transient( int $referrer_id, string $click_id, string $ip ): void {
        $key = self::get_referral_transient_key($ip);
        $ttl = Cashback_Affiliate_DB::get_cookie_ttl_days();
        set_transient($key, array(
            'referrer_id' => $referrer_id,
            'click_id'    => $click_id,
            'timestamp'   => time(),
        ), $ttl * DAY_IN_SECONDS);
    }

    /**
     * Чтение реферальных данных из transient (fallback).
     *
     * @return array{referrer_id: int, click_id: string, timestamp: int}|null
     */
    private static function read_referral_transient( string $ip ): ?array {
        $key  = self::get_referral_transient_key($ip);
        $data = get_transient($key);

        if (!is_array($data) || !isset($data['referrer_id'], $data['click_id'], $data['timestamp'])) {
            return null;
        }

        // Проверка TTL cookie (transient TTL может быть длиннее)
        $ttl = Cashback_Affiliate_DB::get_cookie_ttl_days();
        if (time() - $data['timestamp'] > $ttl * DAY_IN_SECONDS) {
            delete_transient($key);
            return null;
        }

        return array(
            'referrer_id' => (int) $data['referrer_id'],
            'click_id'    => sanitize_text_field($data['click_id']),
            'timestamp'   => (int) $data['timestamp'],
        );
    }

    /**
     * Удаление реферального transient.
     */
    private static function clear_referral_transient( string $ip ): void {
        delete_transient(self::get_referral_transient_key($ip));
    }

    /**
     * Ключ transient: sha256 от IP (безопасный формат ключа).
     */
    private static function get_referral_transient_key( string $ip ): string {
        return 'cb_aff_ref_' . substr(hash('sha256', $ip), 0, 16);
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
     * Привязка реферала при регистрации пользователя.
     * Вызывается из user_register hook (priority 20).
     */
    public function bind_referral_on_registration( int $user_id ): void {
        if (!Cashback_Affiliate_DB::is_module_enabled()) {
            return;
        }

        $ip = class_exists('Cashback_Encryption')
            ? Cashback_Encryption::get_client_ip()
            // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- REMOTE_ADDR set by webserver from TCP connection, not client-controlled; per-request only.
            : ( isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0' );

        // Приоритет: cookie → серверный transient (fallback по IP)
        $cookie = self::read_referral_cookie();
        $source = 'cookie';

        if (!$cookie) {
            $cookie = self::read_referral_transient($ip);
            $source = 'transient';
        }

        if (!$cookie) {
            // Убеждаемся что профиль есть (без реферера)
            Cashback_Affiliate_DB::ensure_profile($user_id);
            return;
        }

        $referrer_id = $cookie['referrer_id'];
        $click_id    = $cookie['click_id'];

        // Антифрод проверки
        $check = Cashback_Affiliate_Antifraud::validate_referral(
            $referrer_id,
            $user_id,
            $ip,
            $click_id
        );

        // Создаём профиль в любом случае
        Cashback_Affiliate_DB::ensure_profile($user_id);

        if (!$check['allowed']) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log(sprintf(
                '[Affiliate] bind_referral BLOCKED: user=%d, referrer=%d, reason=%s',
                $user_id,
                $referrer_id,
                $check['reason']
            ));
            self::clear_referral_cookie();
            self::clear_referral_transient($ip);
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cashback_affiliate_profiles';

        // Атомарная привязка (UPDATE WHERE referred_by_user_id IS NULL — immutable)
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE %i
             SET referred_by_user_id = %d,
                 referral_click_id   = %s,
                 referred_at         = NOW()
             WHERE user_id = %d AND referred_by_user_id IS NULL',
            $table,
            $referrer_id,
            $click_id,
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

            // Аудит
            if (class_exists('Cashback_Encryption')) {
                Cashback_Encryption::write_audit_log(
                    'affiliate_referral_bound',
                    0,
                    'user',
                    $user_id,
                    array(
                        'referrer_id' => $referrer_id,
                        'click_id'    => $click_id,
                        'source'      => $source,
                    )
                );
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
        $profiles_table     = $prefix . 'cashback_affiliate_profiles';
        $user_profile_table = $prefix . 'cashback_user_profile';
        $id_placeholders    = implode(',', array_fill(0, count($user_ids), '%d'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $id_placeholders из array_fill('%d').
        $referrals = $wpdb->get_results($wpdb->prepare(
            "SELECT ap.user_id, ap.referred_by_user_id
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

        // Карта: referred_user_id → referrer_id
        $referral_map = array();
        foreach ($referrals as $row) {
            $referral_map[ (int) $row['user_id'] ] = (int) $row['referred_by_user_id'];
        }

        // Кешируем ставки рефереров
        $referrer_ids = array_unique(array_values($referral_map));
        $rates_cache  = self::batch_get_rates($referrer_ids);
        $global_rate  = Cashback_Affiliate_DB::get_global_rate();

        // Формируем accruals и ledger entries
        $accrual_values = array();
        $accrual_args   = array();
        $ledger_values  = array();
        $ledger_args    = array();
        $balance_deltas = array(); // referrer_id → total commission

        foreach ($candidates as $tx) {
            $user_id  = (int) $tx['user_id'];
            $tx_id    = (int) $tx['id'];
            $cashback = (float) $tx['cashback'];

            if (!isset($referral_map[ $user_id ]) || $cashback <= 0) {
                continue;
            }

            $referrer_id     = $referral_map[ $user_id ];
            $rate            = $rates_cache[ $referrer_id ] ?? $global_rate;
            $commission      = round($cashback * (float) $rate / 100, 2);
            $idempotency_key = 'aff_accrual_' . $tx_id;

            if ($commission <= 0) {
                continue;
            }

            // Reference ID с retry при коллизии (в batch просто генерируем уникальные)
            $reference_id = Cashback_Affiliate_DB::generate_affiliate_reference_id();

            // Accrual
            $accrual_values[] = '(%s, %d, %d, %d, %s, %s, %s, %s, %s)';
            $accrual_args[]   = $reference_id;
            $accrual_args[]   = $referrer_id;
            $accrual_args[]   = $user_id;
            $accrual_args[]   = $tx_id;
            $accrual_args[]   = number_format($cashback, 2, '.', '');
            $accrual_args[]   = number_format((float) $rate, 2, '.', '');
            $accrual_args[]   = number_format($commission, 2, '.', '');
            $accrual_args[]   = 'available';
            $accrual_args[]   = $idempotency_key;

            // Ledger (единый cashback_balance_ledger)
            $ledger_values[] = '(%d, %s, %s, %d, %s, %d, %s)';
            $ledger_args[]   = $referrer_id;
            $ledger_args[]   = 'affiliate_accrual';
            $ledger_args[]   = number_format($commission, 2, '.', '');
            $ledger_args[]   = $tx_id;
            $ledger_args[]   = 'affiliate_accrual';  // reference_type
            $ledger_args[]   = $tx_id;               // reference_id = transaction_id
            $ledger_args[]   = $idempotency_key;

            // Balance delta
            if (!isset($balance_deltas[ $referrer_id ])) {
                $balance_deltas[ $referrer_id ] = 0.0;
            }
            $balance_deltas[ $referrer_id ] += $commission;
        }

        if (empty($accrual_values)) {
            return $result;
        }

        $accruals_table = $prefix . 'cashback_affiliate_accruals';
        $ledger_table   = $prefix . 'cashback_balance_ledger';
        $balance_table  = $prefix . 'cashback_user_balance';

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

            // Сначала пробуем UPDATE pending → available (accruals могли быть созданы sync_pending_accruals)
            $updated_count         = 0;
            $insert_accrual_values = array();
            $insert_accrual_args   = array();

            foreach ($accrual_values as $idx => $value_tpl) {
                $offset = $idx * 9;
                $tx_id  = $accrual_args[ $offset + 3 ];

                $upd = $wpdb->query($wpdb->prepare(
                    "UPDATE %i
                     SET status = 'available',
                         cashback_amount   = %s,
                         commission_rate   = %s,
                         commission_amount = %s
                     WHERE transaction_id = %d AND status IN ('pending','declined')
                     LIMIT 1",
                    $accruals_table,
                    $accrual_args[ $offset + 4 ],
                    $accrual_args[ $offset + 5 ],
                    $accrual_args[ $offset + 6 ],
                    $tx_id
                ));

                if ($upd > 0) {
                    ++$updated_count;
                } else {
                    $insert_accrual_values[] = $value_tpl;
                    for ($i = 0; $i < 9; $i++) {
                        $insert_accrual_args[] = $accrual_args[ $offset + $i ];
                    }
                }
            }

            if (!empty($insert_accrual_values)) {
                $accrual_sql = implode(', ', $insert_accrual_values);
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $accrual_sql из array_fill-шаблонов.
                $accrual_result = $wpdb->query($wpdb->prepare(
                    "INSERT INTO %i
                         (reference_id, referrer_id, referred_user_id, transaction_id,
                          cashback_amount, commission_rate, commission_amount, status, idempotency_key)
                     VALUES {$accrual_sql}
                     ON DUPLICATE KEY UPDATE
                         status = 'available',
                         cashback_amount = VALUES(cashback_amount),
                         commission_rate = VALUES(commission_rate),
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

        $global_rate = (float) Cashback_Affiliate_DB::get_global_rate();

        $tx_table           = $prefix . 'cashback_transactions';
        $profiles_table     = $prefix . 'cashback_affiliate_profiles';
        $user_profile_table = $prefix . 'cashback_user_profile';
        $accruals_table     = $prefix . 'cashback_affiliate_accruals';

        // 1. Найти транзакции рефералов без accrual (waiting/completed/hold/declined)
        $missing = $wpdb->get_results($wpdb->prepare(
            'SELECT t.id AS tx_id, t.user_id, t.cashback, t.order_status,
                    ap.referred_by_user_id AS referrer_id,
                    COALESCE(ap_ref.affiliate_rate, %f) AS eff_rate
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
            $global_rate,
            $tx_table,
            $profiles_table,
            $profiles_table,
            $user_profile_table,
            $accruals_table
        ), ARRAY_A);

        // Создаём pending/declined accruals
        foreach ($missing as $row) {
            $cashback   = (float) $row['cashback'];
            $rate       = (float) $row['eff_rate'];
            $commission = round($cashback * $rate / 100, 2);
            if ($commission <= 0) {
                continue;
            }

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
                number_format($commission, 2, '.', ''),
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
}
