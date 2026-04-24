<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Сервис управления click-sessions (12i-2 ADR, F-10-001).
 *
 * Инкапсулирует dedup-логику через SELECT FOR UPDATE по
 * (user_id, product_id, status='active', expires_at>NOW()), merchant policy clamp
 * (reuse_in_window / always_new / reuse_per_product) и запись сессии + tap-event'а
 * в cashback_click_sessions / cashback_click_log.
 *
 * Общий сервис для двух entry-point'ов кликов:
 *   - REST /wp-json/cashback/v1/activate (browser extension, POST, auth).
 *   - Legacy GET ?cashback_click={product_id} (traditional browser, в т.ч. гости).
 *
 * Rate-limit и idempotency-replay (client_request_id) решают разные задачи на уровне
 * transport'а: REST отвечает JSON, legacy GET — HTTP-кодами + cookie + redirect.
 * Поэтому rate-limit вшит в service (политика одинакова), а idempotency-replay
 * остаётся в caller'ах — replay'ит готовый HTTP-response.
 *
 * @since 5.1.0
 */
final class Cashback_Click_Session_Service {

    private const ACTIVATION_WINDOW = 30 * MINUTE_IN_SECONDS;

    // Rate limiting — копия политики из Cashback_REST_API / WC_Affiliate_URL_Params.
    private const RATE_LIMIT_WINDOW      = 60;
    private const RATE_PER_PRODUCT_SPAM  = 3;
    private const RATE_PER_PRODUCT_BLOCK = 10;
    private const RATE_GLOBAL_SPAM       = 10;
    private const RATE_GLOBAL_BLOCK      = 60;

    private const POLICY_ALLOWLIST = array( 'reuse_in_window', 'always_new', 'reuse_per_product' );
    private const POLICY_DEFAULT   = 'reuse_in_window';
    private const WINDOW_MIN       = 60;
    private const WINDOW_MAX       = 86400;

    /**
     * F-2-001 п.4: whitelist для имён CPA-параметров.
     *
     * Разрешены ASCII-буквы, цифры, `_`, `-`; длина 1..32. Отсекает мусорные ключи
     * (пробелы, кириллица, спецсимволы), которые добавят в affiliate URL невалидный
     * query-параметр — CPA-сеть его отбрасывает, атрибуция ломается.
     *
     * Используется в трёх save-путях (product meta, bulk network params, single param update)
     * и как defensive re-check в build_affiliate_url (на случай, если старые "грязные"
     * строки остались в БД до установки этой валидации).
     */
    private const PARAM_NAME_REGEX = '/^[a-zA-Z0-9_\-]{1,32}$/';

    private function __construct() {}

    /**
     * F-2-001 п.4: публичный whitelist-валидатор имени CPA-параметра.
     */
    public static function is_valid_affiliate_param_name( string $name ): bool {
        return (bool) preg_match(self::PARAM_NAME_REGEX, $name);
    }

    /**
     * Активация click-session для (user_id, product_id).
     *
     * Атомарно резервирует или переиспользует сессию через SELECT FOR UPDATE,
     * пишет tap event в click_log. Rate-limit, policy/window clamp и fallback на
     * дефолты при misconfig — внутри метода.
     *
     * @param array{
     *   product_id: int,
     *   user_id: int,
     *   ip_address: string,
     *   user_agent?: ?string,
     *   referer?: ?string,
     *   client_request_id?: ?string,
     *   force_spam?: bool,
     * } $args
     *
     * @return array{
     *   status: 'ok'|'invalid_product'|'no_url'|'rate_limited'|'error',
     *   rate_status?: 'normal'|'spam'|'blocked',
     *   canonical_click_id?: string,
     *   affiliate_url?: string,
     *   session_pk?: int,
     *   tap_count?: int,
     *   is_primary?: bool,
     *   reused?: bool,
     *   cpa_network?: ?string,
     *   window_seconds?: int,
     *   client_request_id?: ?string,
     * }
     */
    public static function activate( array $args ): array {
        global $wpdb;

        $product_id        = isset($args['product_id']) ? (int) $args['product_id'] : 0;
        $user_id           = isset($args['user_id']) ? (int) $args['user_id'] : 0;
        $ip_address        = isset($args['ip_address']) ? (string) $args['ip_address'] : '';
        $user_agent        = array_key_exists('user_agent', $args) ? ( $args['user_agent'] !== null ? (string) $args['user_agent'] : null ) : null;
        $referer           = array_key_exists('referer', $args) ? ( $args['referer'] !== null ? (string) $args['referer'] : null ) : null;
        $client_request_id = array_key_exists('client_request_id', $args) && $args['client_request_id'] !== null
            ? (string) $args['client_request_id']
            : null;
        $force_spam        = !empty($args['force_spam']);

        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'external') {
            return array( 'status' => 'invalid_product' );
        }

        $base_url = $product->get_product_url();
        if (empty($base_url)) {
            return array( 'status' => 'no_url' );
        }

        $rate_status = self::get_click_rate_status($ip_address, $product_id);
        if ($rate_status === 'blocked') {
            return array(
                'status'      => 'rate_limited',
                'rate_status' => $rate_status,
            );
        }

        // CPA-сеть + merchant policy.
        // Защита от провала расшифровки credentials (напр. пост-rotation, когда
        // api_credentials в БД зашифрованы retired-ключом): get_network_config()
        // кидает RuntimeException из Cashback_Encryption::decrypt(). Для activate
        // credentials не нужны — нужны только policy/window, которые имеют
        // fail-safe дефолты. Глотаем исключение, чтобы активация не падала в 500
        // из-за проблемы ключей, требующей действия администратора.
        $cpa_network     = self::get_network_slug($product_id);
        $merchant_config = null;
        if ($cpa_network) {
            try {
                $merchant_config = Cashback_API_Client::get_instance()->get_network_config($cpa_network);
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
                error_log('[Cashback Click Session] get_network_config(' . $cpa_network . ') failed, falling back to defaults: ' . $e->getMessage());
                $merchant_config = null;
            }
        }

        // 12i-2 ADR (F-10-001): fail-safe clamp policy/window — защита от misconfig.
        $policy = (string) ( $merchant_config['click_session_policy'] ?? self::POLICY_DEFAULT );
        if (!in_array($policy, self::POLICY_ALLOWLIST, true)) {
            $policy = self::POLICY_DEFAULT;
        }
        $window = (int) ( $merchant_config['activation_window_seconds'] ?? self::ACTIVATION_WINDOW );
        if ($window < self::WINDOW_MIN || $window > self::WINDOW_MAX) {
            $window = self::ACTIVATION_WINDOW;
        }
        $merchant_id = isset($merchant_config['id']) ? (int) $merchant_config['id'] : 0;

        // Гости (user_id=0) не должны шарить сессию по общей dedup-строке `(user_id=0, product_id)`:
        // два независимых гостя на одном product получили бы одну сессию. Принудительно always_new.
        if ($user_id <= 0) {
            $policy = 'always_new';
        }

        $spam_flag  = ( $rate_status === 'spam' || $force_spam ) ? 1 : 0;
        $sessions_t = $wpdb->prefix . 'cashback_click_sessions';

        // 12i-2 ADR (F-10-001): TX + SELECT FOR UPDATE на click_sessions.
        // Параллельные /activate от одного user'а на тот же product получают
        // одну и ту же сессию через row-lock (без dedup дали бы 2 сессии).
        $wpdb->query('START TRANSACTION');
        try {
            $existing = null;
            if ($policy !== 'always_new') {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- FOR UPDATE inside TX (Group 8 pattern).
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, canonical_click_id, affiliate_url, tap_count
                       FROM %i
                      WHERE user_id = %d AND product_id = %d
                        AND status = 'active' AND expires_at > NOW()
                      ORDER BY created_at DESC LIMIT 1
                      FOR UPDATE",
                    $sessions_t,
                    $user_id,
                    $product_id
                ), ARRAY_A);
            }

            if (is_array($existing) && !empty($existing['id'])) {
                // Reuse: inc tap_count + last_tap_at = NOW().
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Update inside TX.
                $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET tap_count = tap_count + 1, last_tap_at = NOW(6) WHERE id = %d',
                    $sessions_t,
                    (int) $existing['id']
                ));

                $canonical_click_id = (string) $existing['canonical_click_id'];
                $affiliate_url      = (string) $existing['affiliate_url'];
                $session_pk         = (int) $existing['id'];
                $tap_count          = ( (int) $existing['tap_count'] ) + 1;
                $is_primary         = false;
                $reused             = true;
            } else {
                // New session: canonical_click_id = UUID v7 (time-ordered).
                $canonical_click_id = cashback_generate_uuid7(false);
                $affiliate_url      = self::build_affiliate_url($product_id, $user_id, $canonical_click_id);
                if (empty($affiliate_url)) {
                    $affiliate_url = $base_url;
                }

                $expires_datetime = gmdate('Y-m-d H:i:s.u', time() + $window);

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert inside TX.
                $wpdb->query($wpdb->prepare(
                    'INSERT INTO %i
                        (canonical_click_id, user_id, product_id, merchant_id,
                         affiliate_url, status, tap_count, expires_at)
                     VALUES (%s, %d, %d, %d, %s, %s, %d, %s)',
                    $sessions_t,
                    $canonical_click_id,
                    $user_id,
                    $product_id,
                    $merchant_id,
                    $affiliate_url,
                    'active',
                    1,
                    $expires_datetime
                ));
                $session_pk = (int) $wpdb->insert_id;
                $tap_count  = 1;
                $is_primary = true;
                $reused     = false;
            }

            // Tap event: логируем каждый запрос в click_log, даже если сессия reuse.
            // Для primary тапа click_id == canonical_click_id; для повторов — свой UUID.
            $tap_click_id = $is_primary ? $canonical_click_id : cashback_generate_uuid7(false);
            self::log_click(array(
                'click_id'           => $tap_click_id,
                'click_session_id'   => $session_pk,
                'client_request_id'  => $client_request_id,
                'is_session_primary' => $is_primary ? 1 : 0,
                'user_id'            => $user_id,
                'product_id'         => $product_id,
                'cpa_network'        => $cpa_network,
                'affiliate_url'      => $affiliate_url,
                'ip_address'         => $ip_address,
                'user_agent'         => $user_agent,
                'spam_click'         => $spam_flag,
                'referer'            => $referer,
            ));

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Click Session] activate TX error: ' . get_class($e) . ' ' . $e->getMessage());
            return array( 'status' => 'error' );
        }

        return array(
            'status'             => 'ok',
            'rate_status'        => $rate_status,
            'canonical_click_id' => $canonical_click_id,
            'affiliate_url'      => $affiliate_url,
            'session_pk'         => $session_pk,
            'tap_count'          => $tap_count,
            'is_primary'         => $is_primary,
            'reused'             => $reused,
            'cpa_network'        => $cpa_network,
            'window_seconds'     => $window,
            'client_request_id'  => $client_request_id,
        );
    }

    /**
     * Rate-limit статус для пары (IP, product_id).
     *
     * 2 уровня: по IP+product (узкий) и глобально по IP (широкий).
     * Использует shared backend из Cashback_Rate_Limiter (transient | object cache).
     *
     * @return 'normal'|'spam'|'blocked'
     */
    private static function get_click_rate_status( string $ip_address, int $product_id ): string {
        $window = self::RATE_LIMIT_WINDOW;

        $pp_scope = 'pp_' . substr(sha1($ip_address . '|' . $product_id), 0, 40);
        $gl_scope = 'gl_' . substr(sha1($ip_address), 0, 40);

        if (!class_exists('Cashback_Rate_Limiter')) {
            require_once __DIR__ . '/class-cashback-rate-limiter.php';
        }

        try {
            $counter = \Cashback_Rate_Limiter::counter_backend();
            $pp      = $counter->increment($pp_scope, $window, self::RATE_PER_PRODUCT_BLOCK);
            $gl      = $counter->increment($gl_scope, $window, self::RATE_GLOBAL_BLOCK);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Rate-limit backend diagnostic (group 7, step 7).
            error_log('[cashback-click-session] rate-limit backend error: ' . $e->getMessage());

            return 'normal';
        }

        $pp_hits = (int) $pp['hits'];
        $gl_hits = (int) $gl['hits'];

        if ($pp_hits >= self::RATE_PER_PRODUCT_BLOCK || $gl_hits >= self::RATE_GLOBAL_BLOCK) {
            return 'blocked';
        }

        if ($pp_hits > self::RATE_PER_PRODUCT_SPAM || $gl_hits > self::RATE_GLOBAL_SPAM) {
            return 'spam';
        }

        return 'normal';
    }

    /**
     * Построение affiliate URL с подстановкой параметров.
     */
    private static function build_affiliate_url( int $product_id, int $user_id, string $click_id ): ?string {
        global $wpdb;

        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'external') {
            return null;
        }

        $base_url = $product->get_product_url();
        if (empty($base_url)) {
            return null;
        }

        $network_id = (int) get_post_meta($product_id, '_affiliate_network_id', true);
        if ($network_id <= 0) {
            return $base_url;
        }

        // Сначала загружаем параметры сети
        $params_table = $wpdb->prefix . 'cashback_affiliate_network_params';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
        $network_rows = $wpdb->get_results($wpdb->prepare(
            'SELECT param_name, param_type
             FROM %i
             WHERE network_id = %d
             ORDER BY id ASC',
            $params_table,
            $network_id
        ), ARRAY_A);

        $merged       = array();
        $key_to_index = array();
        foreach ($network_rows as $i => $row) {
            $merged[ $i ]                       = array(
                'key'   => $row['param_name'],
                'value' => $row['param_type'],
            );
            $key_to_index[ $row['param_name'] ] = $i;
        }

        // Мерж индивидуальных параметров товара: переопределяют или дополняют сетевые
        $product_params = get_post_meta($product_id, '_affiliate_product_params', true);
        if (is_array($product_params) && !empty($product_params)) {
            foreach ($product_params as $pp) {
                if (empty($pp['key'])) {
                    continue;
                }
                if (isset($key_to_index[ $pp['key'] ])) {
                    $merged[ $key_to_index[ $pp['key'] ] ]['value'] = $pp['value'];
                } else {
                    $merged[] = array(
                        'key'   => $pp['key'],
                        'value' => $pp['value'],
                    );
                }
            }
        }

        if (empty($merged)) {
            return $base_url;
        }

        // Получаем partner_token (криптографически стойкий, вместо user_id)
        $partner_token = null;
        if ($user_id > 0) {
            $partner_token = Mariadb_Plugin::get_partner_token($user_id);
        }

        $params = array();
        foreach ($merged as $param) {
            if (empty($param['key']) || empty($param['value'])) {
                continue;
            }

            // F-2-001 п.4: defensive re-check — admin save-paths валидируют param_name
            // через whitelist, но данные в БД могли прийти из до-whitelist периода.
            if (!self::is_valid_affiliate_param_name((string) $param['key'])) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic: grep [Cashback Click Session] Rejected invalid param name.
                error_log('[Cashback Click Session] Rejected invalid affiliate param name: ' . substr((string) $param['key'], 0, 80));
                continue;
            }

            $param_type = strtolower(trim($param['value']));

            if ($param_type === 'user') {
                // partner_token вместо user_id — защита от IDOR и перебора
                $params[ $param['key'] ] = $partner_token !== null ? $partner_token : 'unregistered';
            } elseif ($param_type === 'uuid') {
                $params[ $param['key'] ] = $click_id;
            } else {
                $params[ $param['key'] ] = $param['value'];
            }
        }

        return add_query_arg($params, $base_url);
    }

    /**
     * Получение slug CPA-сети для товара.
     */
    private static function get_network_slug( int $product_id ): ?string {
        global $wpdb;

        $network_id = (int) get_post_meta($product_id, '_affiliate_network_id', true);
        if ($network_id <= 0) {
            return null;
        }

        $table = $wpdb->prefix . 'cashback_affiliate_networks';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
        $slug = $wpdb->get_var($wpdb->prepare(
            'SELECT slug FROM %i WHERE id = %d AND is_active = 1',
            $table,
            $network_id
        ));

        return $slug ?: null;
    }

    /**
     * Логирование клика в cashback_click_log.
     */
    private static function log_click( array $data ): bool {
        global $wpdb;

        $table      = $wpdb->prefix . 'cashback_click_log';
        $created_at = ( new \DateTimeImmutable('now', new \DateTimeZone('UTC')) )->format('Y-m-d H:i:s.u');

        // 12i-2 ADR (F-10-001): click_session_id / client_request_id / is_session_primary
        // пишутся явно; legacy-вызовы без этих полей получают NULL/0 (backward-compat).
        $click_session_id   = isset($data['click_session_id']) ? (int) $data['click_session_id'] : null;
        $client_request_id  = isset($data['client_request_id']) ? (string) $data['client_request_id'] : null;
        $is_session_primary = !empty($data['is_session_primary']) ? 1 : 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
        $result = $wpdb->query($wpdb->prepare(
            'INSERT INTO %i
                (click_id, click_session_id, client_request_id, is_session_primary,
                 user_id, product_id, cpa_network, affiliate_url,
                 ip_address, user_agent, referer, spam_click, created_at)
             VALUES (%s, %s, %s, %d, %d, %d, %s, %s, %s, %s, %s, %d, %s)',
            $table,
            $data['click_id'],
            $click_session_id !== null ? (string) $click_session_id : null,
            $client_request_id,
            $is_session_primary,
            $data['user_id'],
            $data['product_id'],
            $data['cpa_network'] ?? '',
            $data['affiliate_url'] ?? '',
            $data['ip_address'] ?? '',
            $data['user_agent'] ?? '',
            $data['referer'] ?? '',
            $data['spam_click'] ?? 0,
            $created_at
        ));

        if (false === $result) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Click Session] Failed to log click: ' . $wpdb->last_error);
            return false;
        }

        return true;
    }
}
