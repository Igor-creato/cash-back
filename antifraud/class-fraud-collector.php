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
            '1.0.0',
            true
        );

        wp_localize_script('cashback-fraud-fingerprint', 'cashbackFraudFP', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('cashback_fraud_fingerprint_nonce'),
        ));
    }

    /**
     * AJAX-обработчик приёма fingerprint из браузера
     *
     * @return void
     */
    public function handle_fingerprint_ajax(): void {
        if (!check_ajax_referer('cashback_fraud_fingerprint_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
            return;
        }

        // Rate-limit: максимум 1 fingerprint за 10 минут на пользователя
        // wp_cache_add() атомарен: возвращает false если ключ уже существует.
        // set_transient() обеспечивает персистентность при сбросе object cache.
        $uid      = get_current_user_id();
        $rate_key = 'cb_fp_rate_' . $uid;
        if (!wp_cache_add($rate_key, 1, 'cashback', 600)) {
            // Тихо принимаем повторный запрос, не перегружая БД
            wp_send_json_success();
            return;
        }
        // Персистентный флаг для окружений без постоянного object cache
        if (get_transient($rate_key)) {
            wp_cache_delete($rate_key, 'cashback');
            wp_send_json_success();
            return;
        }
        set_transient($rate_key, 1, 600);

        $fp_hash = isset($_POST['fp']) ? sanitize_text_field(wp_unslash($_POST['fp'])) : '';

        if (empty($fp_hash) || strlen($fp_hash) !== 64 || !preg_match('/^[a-f0-9]{64}$/', $fp_hash)) {
            wp_send_json_error('Invalid fingerprint');
            return;
        }

        self::record_fingerprint(
            get_current_user_id(),
            self::get_client_ip(),
            $fp_hash,
            self::hash_user_agent(),
            'page_view'
        );

        wp_send_json_success();
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
            error_log('Cashback Fraud Collector: Insert error — ' . $wpdb->last_error);
        }
    }

    /**
     * Получение IP клиента
     *
     * @return string
     */
    private static function get_client_ip(): string {
        // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders -- REMOTE_ADDR is set by the web server from the TCP connection, not a client-controlled HTTP header; proxy headers trusted only via CASHBACK_TRUSTED_PROXIES allowlist.
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
        // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- Request-scoped UA for bot detection/logging; not cacheable across requests.
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : 'unknown';

        return hash('sha256', $ua);
    }
}
