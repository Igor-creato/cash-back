<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Сбор данных для антифрод-анализа: IP, fingerprint, User-Agent
 *
 * @since 1.2.0
 */
class Cashback_Fraud_Collector {

    private static ?self $instance = null;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!Cashback_Fraud_Settings::is_enabled()) {
            return;
        }

        add_action('wp_login', array( $this, 'on_user_login' ), 10, 2);
        add_action('user_register', array( $this, 'on_user_register' ));
        add_action('wp_enqueue_scripts', array( $this, 'enqueue_fingerprint_script' ));
        add_action('wp_ajax_cashback_fraud_fingerprint', array( $this, 'handle_fingerprint_ajax' ));
        // Guest device-id записываем (если согласие не требуется для guest, см. handler).
        add_action('wp_ajax_nopriv_cashback_fraud_fingerprint', array( $this, 'handle_fingerprint_ajax' ));
    }

    /**
     * Запись события логина
     *
     * @param string  $user_login Логин пользователя
     * @param WP_User $user       Объект пользователя
     * @return void
     */
    public function on_user_login( string $user_login, $user ): void {
        if (!$user instanceof WP_User) {
            return;
        }

        self::record_fingerprint(
            (int) $user->ID,
            self::get_client_ip(),
            null,
            self::hash_user_agent(),
            'login'
        );
    }

    /**
     * Запись события регистрации
     *
     * @param int $user_id ID нового пользователя
     * @return void
     */
    public function on_user_register( int $user_id ): void {
        self::record_fingerprint(
            $user_id,
            self::get_client_ip(),
            null,
            self::hash_user_agent(),
            'registration'
        );
    }

    /**
     * Запись события вывода средств
     * Вызывается из CashbackWithdrawal::process_cashback_withdrawal()
     *
     * @param int $user_id ID пользователя
     * @return void
     */
    public static function record_withdrawal_event( int $user_id ): void {
        if (!class_exists('Cashback_Fraud_Settings') || !Cashback_Fraud_Settings::is_enabled()) {
            return;
        }

        self::record_fingerprint(
            $user_id,
            self::get_client_ip(),
            null,
            self::hash_user_agent(),
            'withdrawal'
        );
    }

    /**
     * Подключение fingerprint-скрипта на фронтенде (My Account)
     *
     * @return void
     */
    public function enqueue_fingerprint_script(): void {
        if (!is_user_logged_in() || !is_account_page()) {
            return;
        }

        wp_enqueue_script(
            'cashback-fraud-fingerprint',
            plugins_url('../assets/js/fraud-fingerprint.js', __FILE__),
            array(),
            '1.3.0',
            true
        );

        wp_localize_script('cashback-fraud-fingerprint', 'cashbackFraudFP', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('cashback_fraud_fingerprint_nonce'),
            // debug=true только при WP_DEBUG, чтобы не шуметь в production console
            'debug'   => (defined('WP_DEBUG') && WP_DEBUG),
        ));
    }

    /**
     * AJAX-обработчик приёма fingerprint из браузера.
     *
     * Принимает:
     *  - fp              — legacy SHA-256 fingerprint (обратная совместимость)
     *  - device_id       — UUID v4 persistent device id (новое, опционально)
     *  - visitor_id      — FingerprintJS OSS visitor id (новое, опционально)
     *  - components_hash — SHA-256 от состава компонент (новое, опционально)
     *  - confidence      — FingerprintJS confidence 0..1 (новое, опционально)
     *
     * Ответ обратно совместим: success с дополнительным полем data.device_recorded.
     */
    public function handle_fingerprint_ajax(): void {
        if (!check_ajax_referer('cashback_fraud_fingerprint_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        // Авторизованных юзеров требуем как раньше; гость допускается только для записи device_id
        // (legacy-flow без device_id для гостей сохраняет прежнее поведение — ошибка).
        $is_guest = !is_user_logged_in();
        $uid      = $is_guest ? 0 : (int) get_current_user_id();

        // Rate-limit: 1 fingerprint за 10 минут — на uid или, для гостя, на IP.
        $rate_subject = $is_guest ? 'ip_' . md5(self::get_client_ip()) : (string) $uid;
        $rate_key     = 'cb_fp_rate_' . $rate_subject;
        if (!wp_cache_add($rate_key, 1, 'cashback', 600)) {
            wp_send_json_success(array('device_recorded' => false, 'rate_limited' => true));
            return;
        }
        if (get_transient($rate_key)) {
            wp_cache_delete($rate_key, 'cashback');
            wp_send_json_success(array('device_recorded' => false, 'rate_limited' => true));
            return;
        }
        set_transient($rate_key, 1, 600);

        $fp_hash = isset($_POST['fp']) ? sanitize_text_field(wp_unslash($_POST['fp'])) : '';
        if ($fp_hash !== '' && (strlen($fp_hash) !== 64 || !preg_match('/^[a-f0-9]{64}$/', $fp_hash))) {
            $fp_hash = '';
        }

        $device_id       = isset($_POST['device_id']) ? sanitize_text_field(wp_unslash($_POST['device_id'])) : '';
        $visitor_id      = isset($_POST['visitor_id']) ? sanitize_text_field(wp_unslash($_POST['visitor_id'])) : '';
        $components_hash = isset($_POST['components_hash']) ? sanitize_text_field(wp_unslash($_POST['components_hash'])) : '';
        $confidence_raw  = isset($_POST['confidence']) ? sanitize_text_field(wp_unslash($_POST['confidence'])) : '';
        $confidence      = ( $confidence_raw !== '' && is_numeric($confidence_raw) ) ? (float) $confidence_raw : null;

        // Если ни legacy fp, ни device_id — невалидный запрос (защита от пустых пингов).
        if ($fp_hash === '' && $device_id === '') {
            wp_send_json_error('Invalid fingerprint');
            return;
        }

        // Гость без device_id — отвергаем (legacy-поведение требовало логина).
        if ($is_guest && $device_id === '') {
            wp_send_json_error('Not logged in');
            return;
        }

        // Legacy запись в cashback_user_fingerprints — только для авторизованных (FK NOT NULL user_id).
        if (!$is_guest && $fp_hash !== '') {
            self::record_fingerprint(
                $uid,
                self::get_client_ip(),
                $fp_hash,
                self::hash_user_agent(),
                'page_view'
            );
        }

        // Новая запись в cashback_fraud_device_ids.
        $device_recorded = false;
        $device_id_enabled = !class_exists('Cashback_Fraud_Settings')
            || Cashback_Fraud_Settings::is_device_id_enabled();
        if ($device_id_enabled && $device_id !== '' && class_exists('Cashback_Fraud_Device_Id')) {
            // 152-ФЗ: для авторизованных юзеров проверяем согласие через user_meta
            // (ставится формой регистрации в Этапе 7). Для гостей согласие не требуется.
            // Тумблер consent_required позволяет отключить требование (legacy/dev).
            $consent_required = !class_exists('Cashback_Fraud_Settings')
                || Cashback_Fraud_Settings::is_consent_required();
            $consent_ok = $is_guest || !$consent_required;
            if (!$consent_ok) {
                if (class_exists('Cashback_Fraud_Consent')) {
                    $consent_ok = (bool) call_user_func(array('Cashback_Fraud_Consent', 'has_consent'), $uid);
                } else {
                    // Фолбэк до этапа 7: читаем user_meta напрямую, отсутствие = нет согласия.
                    $consent_ok = (bool) get_user_meta($uid, 'cashback_fraud_consent_at', true);
                }
            }

            if ($consent_ok) {
                $payload = array(
                    'device_id'        => $device_id,
                    'visitor_id'       => $visitor_id,
                    'fingerprint_hash' => $fp_hash !== '' ? $fp_hash : null,
                    'components_hash'  => $components_hash !== '' ? $components_hash : null,
                    'confidence'       => $confidence,
                );

                $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

                $result = Cashback_Fraud_Device_Id::record(
                    $payload,
                    $is_guest ? null : $uid,
                    self::get_client_ip(),
                    $ua
                );
                $device_recorded = ($result !== false);
            } else {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log(sprintf('Cashback Fraud Device ID: skipped record for user %d — no consent', $uid));
            }
        }

        wp_send_json_success(array('device_recorded' => $device_recorded));
    }

    /**
     * Запись fingerprint-данных в БД
     *
     * @param int         $user_id          ID пользователя
     * @param string      $ip_address       IP адрес
     * @param string|null $fingerprint_hash  SHA-256 fingerprint (может быть null)
     * @param string      $user_agent_hash  SHA-256 User-Agent
     * @param string      $event_type       Тип события
     * @return void
     */
    private static function record_fingerprint(
        int $user_id,
        string $ip_address,
        ?string $fingerprint_hash,
        string $user_agent_hash,
        string $event_type
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_user_fingerprints';

        $wpdb->insert(
            $table,
            array(
                'user_id'          => $user_id,
                'ip_address'       => $ip_address,
                'fingerprint_hash' => $fingerprint_hash,
                'user_agent_hash'  => $user_agent_hash,
                'event_type'       => $event_type,
                'created_at'       => current_time('mysql'),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        if ($wpdb->last_error) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback Fraud Collector: Insert error — ' . $wpdb->last_error);
        }
    }

    /**
     * Получение IP клиента
     *
     * @return string
     */
    private static function get_client_ip(): string {
        // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- REMOTE_ADDR set by webserver from TCP connection, not client-controlled; per-request only.
        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';

        // Доверять прокси-заголовкам только если REMOTE_ADDR — доверенный прокси
        $trusted_proxies = defined('CASHBACK_TRUSTED_PROXIES') ? (array) CASHBACK_TRUSTED_PROXIES : array();

        if (!empty($trusted_proxies) && in_array($remote_addr, $trusted_proxies, true)) {
            $proxy_headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' );
            foreach ($proxy_headers as $header) {
                if (!empty($_SERVER[ $header ])) {
                    $ip = sanitize_text_field(wp_unslash($_SERVER[ $header ]));
                    if (strpos($ip, ',') !== false) {
                        $ip = trim(explode(',', $ip)[0]);
                    }
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }

        return filter_var($remote_addr, FILTER_VALIDATE_IP) ? $remote_addr : '0.0.0.0';
    }

    /**
     * SHA-256 хеш User-Agent
     *
     * @return string
     */
    private static function hash_user_agent(): string {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : 'unknown';

        return hash('sha256', $ua);
    }
}
