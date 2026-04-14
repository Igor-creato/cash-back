<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API для браузерного расширения кэшбэк-сервиса.
 *
 * Регистрирует эндпоинты в namespace cashback/v1 для:
 * - Получения списка магазинов с кэшбэком
 * - Баланса и профиля пользователя
 * - Истории транзакций
 * - Активации кэшбэка (генерация redirect-ссылки)
 * - Проверки статуса активации
 *
 * @since 5.0.0
 */
class Cashback_REST_API {

    private const NAMESPACE             = 'cashback/v1';
    private const STORES_CACHE_KEY      = 'cashback_ext_stores_cache';
    private const STORES_CACHE_TTL      = 6 * HOUR_IN_SECONDS;
    private const ACTIVATION_WINDOW     = 30 * MINUTE_IN_SECONDS;
    private const TRANSACTIONS_PER_PAGE = 10;

    // Rate limiting (аналогично WC_Affiliate_URL_Params)
    private const RATE_PER_PRODUCT_SPAM  = 3;
    private const RATE_PER_PRODUCT_BLOCK = 10;
    private const RATE_GLOBAL_SPAM       = 10;
    private const RATE_GLOBAL_BLOCK      = 60;
    private const RATE_LIMIT_WINDOW      = 60;

    private static ?self $instance = null;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array( $this, 'register_routes' ));
        add_filter('rest_authentication_errors', array( $this, 'authenticate_extension_cookie' ), 99);
        add_filter('rest_pre_dispatch', array( $this, 'block_user_enumeration' ), 10, 3);
        add_action('template_redirect', array( $this, 'block_author_enumeration' ));
        add_action('template_redirect', array( $this, 'handle_activation_page' ), 1);
        // Сброс кеша магазинов при сохранении или удалении товара
        add_action('save_post_product', array( $this, 'flush_stores_cache' ));
        add_action('delete_post', array( $this, 'flush_stores_cache' ));
        add_action('woocommerce_update_product', array( $this, 'flush_stores_cache' ));
    }

    /**
     * Сброс transient-кеша списка магазинов.
     */
    public function flush_stores_cache(): void {
        delete_transient(self::STORES_CACHE_KEY);
    }

    /**
     * Блокировка user enumeration через REST API для неаутентифицированных запросов.
     *
     * Закрывает /wp/v2/users и /wp/v2/users/<id> — возвращает 403
     * если у текущего пользователя нет capability `list_users`.
     *
     * @param mixed            $result  Response to replace the requested version with.
     * @param \WP_REST_Server  $server  Server instance.
     * @param \WP_REST_Request $request Request used to generate the response.
     * @return mixed|\WP_Error
     */
    public function block_user_enumeration( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
        if (null !== $result) {
            return $result;
        }

        $route = $request->get_route();

        if (preg_match('#^/wp/v2/users(?:/|$)#', $route) && !current_user_can('list_users')) {
            return new \WP_Error(
                'rest_user_cannot_view',
                'Доступ запрещён.',
                array( 'status' => 403 )
            );
        }

        return $result;
    }

    /**
     * Блокировка author enumeration через /?author=N.
     *
     * WordPress редиректит /?author=1 на /author/username/, раскрывая логин.
     * Для неаутентифицированных — редирект на главную.
     */
    public function block_author_enumeration(): void {
        if (isset($_GET['author']) && !is_user_logged_in()) {
            wp_safe_redirect(home_url(), 301);
            exit;
        }
    }

    /**
     * Регистрация REST-маршрутов.
     */
    public function register_routes(): void {
        // Публичный: список магазинов с кэшбэком
        register_rest_route(self::NAMESPACE, '/stores', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_stores' ),
            'permission_callback' => '__return_true',
        ));

        // Профиль и баланс текущего пользователя
        register_rest_route(self::NAMESPACE, '/me', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_me' ),
            'permission_callback' => array( $this, 'check_user_logged_in' ),
        ));

        // Транзакции текущего пользователя
        register_rest_route(self::NAMESPACE, '/me/transactions', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_transactions' ),
            'permission_callback' => array( $this, 'check_user_logged_in' ),
            'args'                => array(
                'page'     => array(
                    'type'              => 'integer',
                    'default'           => 1,
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ),
                'per_page' => array(
                    'type'              => 'integer',
                    'default'           => self::TRANSACTIONS_PER_PAGE,
                    'minimum'           => 1,
                    'maximum'           => 50,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));

        // Активация кэшбэка (генерация redirect URL).
        // POST — т.к. эндпоинт создаёт записи в click_log (побочный эффект).
        // GET для state-changing операций уязвим к CSRF через <img>, prefetch и т.д.
        register_rest_route(self::NAMESPACE, '/activate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'activate_cashback' ),
            'permission_callback' => array( $this, 'check_user_logged_in' ),
            'args'                => array(
                'product_id' => array(
                    'type'              => 'integer',
                    'required'          => true,
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));

        // Статус активации для домена или по click_id
        register_rest_route(self::NAMESPACE, '/session-status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_session_status' ),
            'permission_callback' => array( $this, 'check_user_logged_in' ),
            'args'                => array(
                'domain'   => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'click_id' => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
    }

    /**
     * Аутентификация запросов браузерного расширения по WordPress cookie
     * без требования nonce.
     *
     * WordPress REST API требует nonce для cookie-аутентификации (CSRF-защита).
     * Браузерные расширения не подвержены CSRF — расширение контролирует все запросы,
     * внешние сайты не могут инициировать запросы от имени расширения.
     *
     * Для защиты от CSRF со стороны обычных сайтов, nonce обходится ТОЛЬКО если:
     * - Origin = chrome-extension:// или moz-extension:// (браузерное расширение)
     * - Origin отсутствует и запрос содержит заголовок X-Cashback-Extension: 1
     *   (service worker расширения, где Origin может не передаваться)
     *
     * Фильтр на приоритете 99 (до rest_cookie_check_errors на приоритете 100).
     * Возврат true вызывает short-circuit core-функции, предотвращая сброс пользователя.
     *
     * @param \WP_Error|null|true $result Результат аутентификации от предыдущих фильтров.
     * @return \WP_Error|null|true
     */
    public function authenticate_extension_cookie( $result ) {
        if (null !== $result) {
            return $result;
        }

        if (!$this->is_cashback_rest_request()) {
            return $result;
        }

        if (!$this->is_extension_origin()) {
            return $result;
        }

        $user_id = wp_validate_auth_cookie('', 'logged_in');

        if (empty($user_id)) {
            return $result;
        }

        wp_set_current_user($user_id);

        return true;
    }

    /**
     * Проверяет, что запрос пришёл от браузерного расширения, а не от стороннего сайта.
     *
     * Браузерные расширения отправляют Origin: chrome-extension://<id> или moz-extension://<id>.
     * Обычные сайты отправляют Origin: https://evil-site.com — такие запросы не должны
     * обходить nonce-проверку WordPress.
     */
    private function is_extension_origin(): bool {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // Расширения Chrome и Firefox
        if (
            str_starts_with($origin, 'chrome-extension://') ||
            str_starts_with($origin, 'moz-extension://')
        ) {
            return true;
        }

        // Service worker расширения может не отправлять Origin.
        // Проверяем кастомный заголовок, который обычные сайты не могут подделать
        // в cross-origin запросе (CORS preflight заблокирует нестандартный заголовок).
        if (empty($origin)) {
            $extension_header = $_SERVER['HTTP_X_CASHBACK_EXTENSION'] ?? '';
            if ('1' === $extension_header) {
                return true;
            }
        }

        // Запросы с того же домена (same-origin) — допускаются,
        // т.к. same-origin запросы не являются CSRF
        if (!empty($origin)) {
            $site_url    = site_url();
            $site_origin = rtrim($site_url, '/');
            if ($origin === $site_origin) {
                return true;
            }
        }

        return false;
    }

    /**
     * Permission callback: пользователь авторизован.
     */
    public function check_user_logged_in(): bool {
        return is_user_logged_in();
    }

    /**
     * GET /stores — Список магазинов с кэшбэком.
     *
     * Кешируется в transient на 6 часов.
     * Домены берутся из post_meta `_store_domain` (заполняется в админке товара).
     */
    public function get_stores( \WP_REST_Request $request ): \WP_REST_Response {
        $cached = get_transient(self::STORES_CACHE_KEY);
        if (false !== $cached) {
            // Инвалидируем устаревший кеш, построенный до добавления поля product_id.
            // При попадании в эту ветку кеш перестраивается один раз автоматически.
            if (!empty($cached) && is_array($cached) && !array_key_exists('product_id', $cached[0])) {
                delete_transient(self::STORES_CACHE_KEY);
                $cached = false;
            } else {
                return new \WP_REST_Response($cached, 200);
            }
        }

        global $wpdb;

        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';

        // Получаем все товары-магазины с заполненным доменом
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $products = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title,
                        pm_domain.meta_value AS store_domain,
                        pm_label.meta_value AS cashback_label,
                        pm_value.meta_value AS cashback_value,
                        pm_popup.meta_value AS popup_mode,
                        n.name AS network_name,
                        n.slug AS network_slug
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm_net ON p.ID = pm_net.post_id AND pm_net.meta_key = '_affiliate_network_id'
                 INNER JOIN {$wpdb->postmeta} pm_domain ON p.ID = pm_domain.post_id AND pm_domain.meta_key = '_store_domain'
                 LEFT JOIN {$wpdb->postmeta} pm_label ON p.ID = pm_label.post_id AND pm_label.meta_key = '_cashback_display_label'
                 LEFT JOIN {$wpdb->postmeta} pm_value ON p.ID = pm_value.post_id AND pm_value.meta_key = '_cashback_display_value'
                 LEFT JOIN {$wpdb->postmeta} pm_popup ON p.ID = pm_popup.post_id AND pm_popup.meta_key = '_store_popup_mode'
                 LEFT JOIN {$networks_table} n ON n.id = pm_net.meta_value AND n.is_active = 1
                 WHERE p.post_type = %s
                   AND p.post_status = 'publish'
                   AND pm_net.meta_value > 0
                   AND pm_domain.meta_value != ''",
                'product'
            ),
            ARRAY_A
        );

        if (empty($products)) {
            $stores = array();
            set_transient(self::STORES_CACHE_KEY, $stores, self::STORES_CACHE_TTL);
            return new \WP_REST_Response($stores, 200);
        }

        $stores = array();
        foreach ($products as $product) {
            $domain = $product['store_domain'] ?? '';
            if (empty($domain)) {
                continue;
            }

            // Нормализуем домен: убираем протокол, www., trailing slash и путь
            $domain = preg_replace('#^https?://#i', '', $domain);
            $domain = preg_replace('#^www\.#i', '', $domain);
            $domain = strtolower(explode('/', $domain)[0]);

            if (empty($domain)) {
                continue;
            }

            $stores[] = array(
                'domain'         => $domain,
                'store_name'     => $product['post_title'] ?: ( $product['network_name'] ?: $domain ),
                'cashback_label' => $product['cashback_label'] ?: 'Кэшбэк',
                'cashback_value' => $product['cashback_value'] ?: '',
                'product_id'     => (int) $product['ID'],
                'network_slug'   => $product['network_slug'] ?: '',
                'popup_mode'     => $product['popup_mode'] ?: 'show',
            );
        }

        set_transient(self::STORES_CACHE_KEY, $stores, self::STORES_CACHE_TTL);

        return new \WP_REST_Response($stores, 200);
    }

    /**
     * GET /me — Баланс и профиль текущего пользователя.
     */
    public function get_me( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $user_id = get_current_user_id();
        $user    = wp_get_current_user();

        $balance_table = $wpdb->prefix . 'cashback_user_balance';
        $profile_table = $wpdb->prefix . 'cashback_user_profile';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $balance = $wpdb->get_row($wpdb->prepare(
            "SELECT available_balance, pending_balance, paid_balance
             FROM {$balance_table} WHERE user_id = %d",
            $user_id
        ), ARRAY_A);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $profile = $wpdb->get_row($wpdb->prepare(
            "SELECT cashback_rate, status FROM {$profile_table} WHERE user_id = %d",
            $user_id
        ), ARRAY_A);

        return new \WP_REST_Response(array(
            'user_id'       => $user_id,
            'display_name'  => $user->display_name,
            'balance'       => array(
                'available' => (float) ( $balance['available_balance'] ?? 0 ),
                'pending'   => (float) ( $balance['pending_balance'] ?? 0 ),
                'paid'      => (float) ( $balance['paid_balance'] ?? 0 ),
            ),
            'cashback_rate' => (float) ( $profile['cashback_rate'] ?? 60 ),
            'status'        => $profile['status'] ?? 'active',
        ), 200);
    }

    /**
     * GET /me/transactions — Последние транзакции пользователя.
     */
    public function get_transactions( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $user_id  = get_current_user_id();
        $page     = $request->get_param('page');
        $per_page = $request->get_param('per_page');
        $offset   = ( $page - 1 ) * $per_page;

        $table = $wpdb->prefix . 'cashback_transactions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
            $user_id
        ));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT offer_name, cashback, order_status, currency, partner, action_date, created_at
             FROM {$table}
             WHERE user_id = %d
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            $user_id,
            $per_page,
            $offset
        ), ARRAY_A);

        $formatted = array();
        foreach ($items as $item) {
            $formatted[] = array(
                'offer_name'   => $item['offer_name'],
                'cashback'     => (float) $item['cashback'],
                'currency'     => $item['currency'] ?: 'RUB',
                'order_status' => $item['order_status'],
                'partner'      => $item['partner'],
                'action_date'  => $item['action_date'],
                'created_at'   => $item['created_at'],
            );
        }

        return new \WP_REST_Response(array(
            'items' => $formatted,
            'total' => $total,
            'pages' => (int) ceil($total / $per_page),
            'page'  => $page,
        ), 200);
    }

    /**
     * GET /activate — Активация кэшбэка для товара.
     *
     * Генерирует click_id, логирует клик, возвращает redirect URL.
     * Использует rate limiting аналогично WC_Affiliate_URL_Params.
     */
    public function activate_cashback( \WP_REST_Request $request ): \WP_REST_Response {
        $product_id = $request->get_param('product_id');
        $user_id    = get_current_user_id();

        // Проверяем, что товар существует и является external
        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'external') {
            return new \WP_REST_Response(array(
                'code'    => 'invalid_product',
                'message' => 'Товар не найден или не является внешним.',
            ), 404);
        }

        $base_url = $product->get_product_url();
        if (empty($base_url)) {
            return new \WP_REST_Response(array(
                'code'    => 'no_url',
                'message' => 'У товара отсутствует партнёрская ссылка.',
            ), 400);
        }

        // Rate limiting
        $ip_address  = Cashback_Encryption::get_client_ip();
        $rate_status = $this->get_click_rate_status($ip_address, $product_id);

        if ($rate_status === 'blocked') {
            return new \WP_REST_Response(array(
                'code'    => 'rate_limited',
                'message' => 'Слишком много запросов. Попробуйте позже.',
            ), 429);
        }

        // Генерация click_id через UUID v7 (time-ordered, лучшая индексация в БД)
        $click_id = cashback_generate_uuid7(false);

        // Построение affiliate URL
        $affiliate_url = $this->build_affiliate_url($product_id, $user_id, $click_id);
        if (empty($affiliate_url)) {
            $affiliate_url = $base_url;
        }

        // CPA-сеть
        $cpa_network = $this->get_network_slug($product_id);

        // User-Agent из заголовка REST-запроса
        $user_agent = $request->get_header('user_agent');

        // Логирование клика
        $this->log_click(array(
            'click_id'      => $click_id,
            'user_id'       => $user_id,
            'product_id'    => $product_id,
            'cpa_network'   => $cpa_network,
            'affiliate_url' => $affiliate_url,
            'ip_address'    => $ip_address,
            'user_agent'    => $user_agent ? sanitize_text_field($user_agent) : null,
            'spam_click'    => $rate_status === 'spam' ? 1 : 0,
            'referer'       => get_permalink($product_id) ?: null,
        ));

        $expires_at = gmdate('Y-m-d H:i:s', time() + self::ACTIVATION_WINDOW);

        // Домен магазина из post_meta (НЕ из affiliate URL, который указывает на CPA-сеть)
        $store_domain = $this->normalize_store_domain(
            (string) get_post_meta($product_id, '_store_domain', true)
        );

        // Формируем URL активации на базе permalink товара, чтобы CPA-сеть
        // видела Referer: yoursite.com/product/название/ вместо /?cashback_go=1
        $product_permalink   = get_permalink($product_id) ?: home_url('/');
        $activation_page_url = add_query_arg(
            array(
				'cashback_go' => '1',
				'click_id'    => $click_id,
			),
            $product_permalink
        );

        return new \WP_REST_Response(array(
            'redirect_url'        => $affiliate_url,
            'activation_page_url' => $activation_page_url,
            'click_id'            => $click_id,
            'expires_at'          => $expires_at,
            'domain'              => $store_domain,
        ), 200);
    }

    /**
     * GET /session-status — Статус активации кэшбэка для домена.
     *
     * Проверяет cashback_click_log на наличие клика за последние 30 минут.
     */
    public function get_session_status( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $user_id  = get_current_user_id();
        $click_id = $request->get_param('click_id');

        // Lookup by click_id: used by the browser extension after a server-side redirect
        // (?cashback_click=) to pre-store the activation before arriving at the partner site.
        if (!empty($click_id) && strlen($click_id) === 32 && ctype_xdigit($click_id)) {
            $click_log_table = $wpdb->prefix . 'cashback_click_log';
            $threshold       = gmdate('Y-m-d H:i:s', time() - self::ACTIVATION_WINDOW);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $click = $wpdb->get_row($wpdb->prepare(
                "SELECT click_id, created_at, product_id
                 FROM {$click_log_table}
                 WHERE click_id = %s AND user_id = %d AND created_at >= %s LIMIT 1",
                $click_id,
                $user_id,
                $threshold
            ), ARRAY_A);

            if ($click) {
                // Домен из _store_domain meta (не из affiliate_url, который указывает на CPA-сеть)
                $dest_domain = $this->get_store_domain((int) $click['product_id']);
                $click_time  = strtotime($click['created_at']);
                $expires_at  = gmdate('Y-m-d H:i:s', $click_time + self::ACTIVATION_WINDOW);

                return new \WP_REST_Response(array(
                    'activated'    => true,
                    'domain'       => $dest_domain,
                    'activated_at' => $click['created_at'],
                    'expires_at'   => $expires_at,
                    'click_id'     => $click['click_id'],
                ), 200);
            }

            return new \WP_REST_Response(array(
                'activated'    => false,
                'activated_at' => null,
                'expires_at'   => null,
            ), 200);
        }

        $domain = $request->get_param('domain');
        if (empty($domain)) {
            return new \WP_REST_Response(array(
                'activated'    => false,
                'activated_at' => null,
                'expires_at'   => null,
            ), 200);
        }

        // Нормализация: удаляем протокол, www., trailing slash
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = strtolower(preg_replace('/^www\./i', '', $domain));
        $domain = explode('/', $domain)[0];

        // Находим product_id по домену магазина.
        // Ищем как нормализованный домен, так и варианты с протоколом (legacy)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_store_domain'
               AND (meta_value = %s
                    OR meta_value = %s
                    OR meta_value = %s)",
            $domain,
            'https://' . $domain,
            'http://' . $domain
        ));

        if (empty($product_ids)) {
            return new \WP_REST_Response(array(
                'activated'    => false,
                'activated_at' => null,
                'expires_at'   => null,
            ), 200);
        }

        $click_log_table = $wpdb->prefix . 'cashback_click_log';
        $threshold       = gmdate('Y-m-d H:i:s', time() - self::ACTIVATION_WINDOW);

        // Ищем последний клик пользователя на товары этого домена
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $query_args   = array_merge(array( $user_id ), array_map('intval', $product_ids), array( $threshold ));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $click = $wpdb->get_row($wpdb->prepare(
            "SELECT click_id, created_at
             FROM {$click_log_table}
             WHERE user_id = %d
               AND product_id IN ({$placeholders})
               AND created_at >= %s
             ORDER BY created_at DESC
             LIMIT 1",
            ...$query_args
        ), ARRAY_A);

        if ($click) {
            $activated_at = $click['created_at'];
            $click_time   = strtotime($activated_at);
            $expires_at   = gmdate('Y-m-d H:i:s', $click_time + self::ACTIVATION_WINDOW);

            return new \WP_REST_Response(array(
                'activated'    => true,
                'activated_at' => $activated_at,
                'expires_at'   => $expires_at,
                'click_id'     => $click['click_id'],
            ), 200);
        }

        return new \WP_REST_Response(array(
            'activated'    => false,
            'activated_at' => null,
            'expires_at'   => null,
        ), 200);
    }

    // ─── Промежуточная страница активации ───

    /**
     * Промежуточная страница активации кэшбэка.
     *
     * Срабатывает на template_redirect (приоритет 1) для URL ?cashback_go=1&click_id=XXXX.
     * Валидирует click_id, получает affiliate_url из БД, рендерит страницу активации
     * с использованием шапки/подвала активной темы.
     *
     * JS-редирект (window.location.href) гарантирует, что браузер отправит
     * Referer: [наш сайт] при открытии affiliate URL — что требуется CPA-сетями
     * для атрибуции перехода через наш сервис.
     *
     * HTTP Referer из заголовка запроса (URL магазина, с которого пришёл пользователь)
     * сохраняется в click_log — клик логируется ранее (в REST API), без referer.
     */
    public function handle_activation_page(): void {
        if (!isset($_GET['cashback_go']) || '1' !== $_GET['cashback_go']) {
            return;
        }

        $click_id = isset($_GET['click_id']) ? sanitize_text_field(wp_unslash($_GET['click_id'])) : '';

        if (empty($click_id) || strlen($click_id) !== 32 || !ctype_xdigit($click_id)) {
            wp_safe_redirect(home_url(), 302);
            exit;
        }

        global $wpdb;
        $table     = $wpdb->prefix . 'cashback_click_log';
        $threshold = gmdate('Y-m-d H:i:s', time() - self::ACTIVATION_WINDOW);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT affiliate_url, product_id FROM {$table}
                 WHERE click_id = %s AND created_at >= %s LIMIT 1",
                $click_id,
                $threshold
            ),
            ARRAY_A
        );

        if (empty($row) || empty($row['affiliate_url'])) {
            wp_safe_redirect(home_url(), 302);
            exit;
        }

        $affiliate_url = $row['affiliate_url'];
        $product_id    = (int) $row['product_id'];

        // Обновляем referer: в лог клика записываем URL страницы товара на нашем сервисе,
        // чтобы в логе было видно, что переход совершён именно с нашей страницы партнёра.
        // (Клик логировался ранее через REST API из service worker — без referer.)
        $product_page_url = $product_id > 0 ? get_permalink($product_id) : '';
        if (!empty($product_page_url)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->update(
                $table,
                array( 'referer' => $product_page_url ),
                array( 'click_id' => $click_id ),
                array( '%s' ),
                array( '%s' )
            );
        }

        $product    = wc_get_product($product_id);
        $store_name = ( $product && $product->get_name() ) ? $product->get_name() : 'Магазин';
        $cb_label   = (string) get_post_meta($product_id, '_cashback_display_label', true);
        $cb_value   = (string) get_post_meta($product_id, '_cashback_display_value', true);

        if (empty($cb_label)) {
            $cb_label = 'Кэшбэк';
        }

        $js_affiliate_url = wp_json_encode($affiliate_url);
        $js_store_name    = wp_json_encode($store_name);
        $js_cb_label      = wp_json_encode($cb_label);
        $js_cb_value      = wp_json_encode($cb_value);

        nocache_headers();

        // Подключаем стили карточки в <head> активной темы
        $card_styles = $this->get_activation_card_styles();
        add_action('wp_head', static function () use ( $card_styles ): void {
            echo '<style id="cashback-go-styles">' . $card_styles . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }, 100);

        // Рендерим страницу с шапкой и подвалом активной темы
        get_header();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $this->render_activation_content(
            $js_affiliate_url,
            $js_store_name,
            $js_cb_label,
            $js_cb_value
        );
        get_footer();
        exit;
    }

    /**
     * Рендерит HTML-блок карточки активации (без обёртки страницы).
     *
     * Вставляется между get_header() и get_footer() активной темы,
     * чтобы страница выглядела нативно в рамках текущего дизайна сайта.
     *
     * Все динамические данные передаются ТОЛЬКО через JS-переменные,
     * закодированные через wp_json_encode() — защита от XSS.
     * affiliate_url берётся из БД — защита от open redirect.
     *
     * @param string $js_affiliate_url  JSON-encoded URL для редиректа
     * @param string $js_store_name     JSON-encoded название магазина
     * @param string $js_cb_label       JSON-encoded метка кэшбэка
     * @param string $js_cb_value       JSON-encoded значение кэшбэка
     * @return string
     */
    private function render_activation_content(
        string $js_affiliate_url,
        string $js_store_name,
        string $js_cb_label,
        string $js_cb_value
    ): string {
        return <<<HTML
<div class="cashback-go-wrap">
  <div class="cashback-go-card">
    <div class="cg-brand">&#128176; Кэшбэк Сервис</div>
    <div class="cg-store-name" id="js-store-name"></div>
    <div class="cg-cashback-badge" id="js-cb-badge"></div>
    <div class="cg-status">&#9989; Кэшбэк активирован!</div>
    <div class="cg-countdown-text" id="js-countdown">Переход к магазину...</div>
    <button class="cg-btn-go" id="js-btn-go">Перейти в магазин сейчас &#8594;</button>
    <div class="cg-notice">Для фиксации кэшбэка совершите покупку в течение 30 минут.</div>
  </div>
</div>

<script>
(function () {
  'use strict';

  var redirectUrl = {$js_affiliate_url};
  var storeName   = {$js_store_name};
  var cbLabel     = {$js_cb_label};
  var cbValue     = {$js_cb_value};

  document.getElementById('js-store-name').textContent = storeName;
  document.getElementById('js-cb-badge').textContent   = cbValue
    ? (cbLabel + ' до ' + cbValue)
    : cbLabel;

  document.getElementById('js-btn-go').addEventListener('click', function () {
    window.location.href = redirectUrl;
  });

  var redirected = false;
  function go() {
    if (!redirected) {
      redirected = true;
      window.location.href = redirectUrl;
    }
  }

  // Слушаем подтверждение от content script браузерного расширения.
  document.addEventListener('cashback:site:confirmed', go);

  // Сохраняем данные активации в атрибуте DOM — content script читает их при старте,
  // не зависит от порядка выполнения (inline script всегда опережает document_idle).
  var clickId = (new URLSearchParams(window.location.search)).get('click_id') || '';
  var domain  = '';
  try { domain = new URL(redirectUrl).hostname.replace(/^www\\./, ''); } catch (e) {}

  if (clickId && domain) {
    document.documentElement.setAttribute(
      'data-cb-activation',
      JSON.stringify({ domain: domain, click_id: clickId })
    );
  }

  // Fallback: если расширение не установлено или не ответило — редиректим через 1.5с.
  setTimeout(go, 1500);
})();
</script>
HTML;
    }

    /**
     * CSS-стили для карточки активации кэшбэка.
     *
     * Инжектируются в <head> темы через wp_head.
     * Используют нейтральные цвета, не перекрывающие дизайн темы,
     * стилизуя только компонент .cashback-go-wrap / .cashback-go-card.
     *
     * @return string
     */
    private function get_activation_card_styles(): string {
        return '
.cashback-go-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 50vh;
    padding: 40px 20px;
    box-sizing: border-box;
}
.cashback-go-card {
    max-width: 480px;
    width: 100%;
    text-align: center;
    padding: 40px 48px;
    border-radius: 16px;
    border: 1px solid rgba(0,0,0,0.1);
    box-shadow: 0 4px 32px rgba(0,0,0,0.10);
    background: #fff;
    box-sizing: border-box;
}
.cg-brand {
    font-size: 12px;
    letter-spacing: 0.8px;
    margin-bottom: 24px;
    text-transform: uppercase;
    font-weight: 600;
    opacity: 0.5;
}
.cg-store-name {
    font-size: 22px;
    font-weight: 700;
    margin-bottom: 10px;
}
.cg-cashback-badge {
    display: inline-block;
    background: linear-gradient(135deg, #3949ab, #1e88e5);
    color: #fff;
    font-size: 15px;
    font-weight: 600;
    padding: 6px 20px;
    border-radius: 20px;
    margin-bottom: 24px;
}
.cg-status {
    font-size: 16px;
    font-weight: 600;
    color: #27ae60;
    margin-bottom: 20px;
}
.cg-countdown-text {
    font-size: 14px;
    opacity: 0.6;
    margin-bottom: 10px;
    min-height: 20px;
}
.cg-btn-go {
    display: inline-block;
    background: linear-gradient(135deg, #3949ab, #1e88e5);
    color: #fff !important;
    font-size: 15px;
    font-weight: 600;
    text-decoration: none !important;
    padding: 14px 32px;
    border-radius: 10px;
    cursor: pointer;
    border: none;
    transition: opacity 0.2s;
    box-sizing: border-box;
}
.cg-btn-go:hover { opacity: 0.85; }
.cg-notice {
    font-size: 12px;
    opacity: 0.5;
    margin-top: 18px;
    line-height: 1.6;
}
@media (max-width: 520px) {
    .cashback-go-card { padding: 28px 20px; }
}
        ';
    }

    // ─── Private helpers ───

    /**
     * Проверка, что текущий запрос направлен к REST-маршрутам cashback/v1.
     */
    private function is_cashback_rest_request(): bool {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $rest_prefix = rest_get_url_prefix();

        if (false !== strpos($request_uri, '/' . $rest_prefix . '/' . self::NAMESPACE)) {
            return true;
        }

        if (isset($_GET['rest_route'])) {
            $route = sanitize_text_field(wp_unslash($_GET['rest_route']));
            if (0 === strpos($route, '/' . self::NAMESPACE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rate limiting (двухуровневый, аналогично WC_Affiliate_URL_Params).
     */
    private function get_click_rate_status( string $ip_address, int $product_id ): string {
        $window = self::RATE_LIMIT_WINDOW;

        $pp_hash = substr(md5($ip_address . '|' . $product_id), 0, 12);
        $pp_key  = 'cb_pp_' . $pp_hash;

        $gl_hash = substr(md5($ip_address), 0, 12);
        $gl_key  = 'cb_gl_' . $gl_hash;

        $pp_count = (int) get_transient($pp_key);
        $gl_count = (int) get_transient($gl_key);

        if (
            $pp_count >= self::RATE_PER_PRODUCT_BLOCK ||
            $gl_count >= self::RATE_GLOBAL_BLOCK
        ) {
            return 'blocked';
        }

        set_transient($pp_key, $pp_count + 1, $window);
        set_transient($gl_key, $gl_count + 1, $window);

        if (
            ( $pp_count + 1 ) > self::RATE_PER_PRODUCT_SPAM ||
            ( $gl_count + 1 ) > self::RATE_GLOBAL_SPAM
        ) {
            return 'spam';
        }

        return 'normal';
    }

    /**
     * Построение affiliate URL с подстановкой параметров.
     */
    private function build_affiliate_url( int $product_id, int $user_id, string $click_id ): ?string {
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $network_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT param_name, param_type
             FROM {$params_table}
             WHERE network_id = %d
             ORDER BY id ASC",
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
    private function get_network_slug( int $product_id ): ?string {
        global $wpdb;

        $network_id = (int) get_post_meta($product_id, '_affiliate_network_id', true);
        if ($network_id <= 0) {
            return null;
        }

        $table = $wpdb->prefix . 'cashback_affiliate_networks';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $slug = $wpdb->get_var($wpdb->prepare(
            "SELECT slug FROM {$table} WHERE id = %d AND is_active = 1",
            $network_id
        ));

        return $slug ?: null;
    }

    /**
     * Нормализация домена магазина: убирает протокол, www., путь.
     */
    private function normalize_store_domain( string $domain ): string {
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = preg_replace('#^www\.#i', '', $domain);
        return strtolower(explode('/', $domain)[0]);
    }

    /**
     * Получение нормализованного домена магазина по product_id.
     */
    private function get_store_domain( int $product_id ): string {
        $raw = (string) get_post_meta($product_id, '_store_domain', true);
        return $this->normalize_store_domain($raw);
    }

    /**
     * Логирование клика в cashback_click_log.
     */
    private function log_click( array $data ): bool {
        global $wpdb;

        $table      = $wpdb->prefix . 'cashback_click_log';
        $created_at = ( new \DateTimeImmutable('now', new \DateTimeZone('UTC')) )->format('Y-m-d H:i:s.u');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (click_id, user_id, product_id, cpa_network, affiliate_url, ip_address, user_agent, referer, spam_click, created_at)
             VALUES (%s, %d, %d, %s, %s, %s, %s, %s, %d, %s)",
            $data['click_id'],
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
            error_log('[Cashback REST API] Failed to log click: ' . $wpdb->last_error);
            return false;
        }

        return true;
    }
}
