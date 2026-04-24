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
        // Activation page (cashback_go URL) обрабатывается WC_Affiliate_URL_Params:
        // единственный handler с F-2-001 hardening (HMAC verify + user_id bind).
        // Защита от кэширования редиректов/ошибок на путях расширения.
        // Инцидент 2026-04-23: браузер закешировал 301 с /wp-json/cashback/v1/me
        // на home_url — расширение было мертво у пользователя до ручной очистки кэша.
        add_action('send_headers', array( $this, 'force_no_store_on_rest_paths' ), 1);
        add_filter('redirect_canonical', array( $this, 'skip_canonical_redirect_for_rest' ), 10, 2);
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
        if (isset($_GET['author']) && !is_user_logged_in()) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public hardening check against author enumeration, no state change.
            wp_safe_redirect(home_url(), 301);
            exit;
        }
    }

    /**
     * Защита от долгоживущего кэша редиректов/ошибок на путях cashback/v1.
     *
     * 301 по умолчанию кэшируются браузером практически бессрочно. Если на пути
     * расширения по любой причине случился редирект (canonical, 404→home, плагин
     * SEO), браузер запоминает его и перестаёт обращаться к серверу — расширение
     * выглядит мёртвым. Добавляем `Cache-Control: no-store` на все запросы к
     * нашему namespace, чтобы ни один неуспешный ответ не смог залипнуть в кэше.
     */
    public function force_no_store_on_rest_paths(): void {
        if (!$this->is_cashback_rest_request()) {
            return;
        }
        if (headers_sent()) {
            return;
        }
        nocache_headers();
    }

    /**
     * Отключить WP canonical redirect для REST-путей cashback/v1.
     *
     * Если по какой-то причине REST-route временно не зарегистрирован (плагин
     * выключен, rewrite не сброшен после деплоя), WP отдаёт 301 на home_url.
     * Браузер кэширует этот 301 и продолжает его воспроизводить после починки.
     * Возврат false из фильтра отменяет редирект на уровне WP.
     */
    public function skip_canonical_redirect_for_rest( $redirect_url, $requested_url ) {
        unset( $requested_url );
        if ($this->is_cashback_rest_request()) {
            return false;
        }
        return $redirect_url;
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
                'product_id'        => array(
                    'type'              => 'integer',
                    'required'          => true,
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ),
                // 12i-2 ADR (F-10-001): клиент передаёт UUID v4/v7 для идемпотентности
                // ретраев. Пустое значение допустимо — сервер сгенерирует fallback.
                'client_request_id' => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
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
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';

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
            $extension_header = isset($_SERVER['HTTP_X_CASHBACK_EXTENSION']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_CASHBACK_EXTENSION'])) : '';
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
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP REST API callback signature requires WP_REST_Request parameter.
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin query.
        $products = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT p.ID, p.post_title,
                        pm_domain.meta_value AS store_domain,
                        pm_label.meta_value AS cashback_label,
                        pm_value.meta_value AS cashback_value,
                        pm_popup.meta_value AS popup_mode,
                        n.name AS network_name,
                        n.slug AS network_slug
                 FROM %i p
                 INNER JOIN %i pm_net ON p.ID = pm_net.post_id AND pm_net.meta_key = \'_affiliate_network_id\'
                 INNER JOIN %i pm_domain ON p.ID = pm_domain.post_id AND pm_domain.meta_key = \'_store_domain\'
                 LEFT JOIN %i pm_label ON p.ID = pm_label.post_id AND pm_label.meta_key = \'_cashback_display_label\'
                 LEFT JOIN %i pm_value ON p.ID = pm_value.post_id AND pm_value.meta_key = \'_cashback_display_value\'
                 LEFT JOIN %i pm_popup ON p.ID = pm_popup.post_id AND pm_popup.meta_key = \'_store_popup_mode\'
                 LEFT JOIN %i n ON n.id = pm_net.meta_value AND n.is_active = 1
                 WHERE p.post_type = %s
                   AND p.post_status = \'publish\'
                   AND pm_net.meta_value > 0
                   AND pm_domain.meta_value != \'\'',
                $wpdb->posts,
                $wpdb->postmeta,
                $wpdb->postmeta,
                $wpdb->postmeta,
                $wpdb->postmeta,
                $wpdb->postmeta,
                $networks_table,
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
     *
     * Money-поля (balance.available/pending/paid) возвращаются как float для обратной
     * совместимости с браузерным расширением (cashback/v1). Перед сериализацией значения
     * прогоняются через `Cashback_Money::from_db_value()` — fail-closed при corruption
     * DB-строки (F-35-004 defense-in-depth).
     *
     * @deprecated float-ответ — будет заменён на canonical decimal-string в cashback/v2
     *             (ADR Группа 10 Step 4, блокируется координированным релизом расширения).
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP REST API callback signature requires WP_REST_Request parameter.
    public function get_me( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $user_id = get_current_user_id();
        $user    = wp_get_current_user();

        $balance_table = $wpdb->prefix . 'cashback_user_balance';
        $profile_table = $wpdb->prefix . 'cashback_user_profile';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
        $balance = $wpdb->get_row($wpdb->prepare(
            'SELECT available_balance, pending_balance, paid_balance
             FROM %i WHERE user_id = %d',
            $balance_table,
            $user_id
        ), ARRAY_A);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
        $profile = $wpdb->get_row($wpdb->prepare(
            'SELECT cashback_rate, status FROM %i WHERE user_id = %d',
            $profile_table,
            $user_id
        ), ARRAY_A);

        // Money-поля: валидация через Cashback_Money (fail-closed) перед (float)-cast
        // в ответ (F-35-004). В cashback/v2 цель — вернуть `->to_string()` как
        // decimal-string, сейчас сохраняем float-контракт ради совместимости.
        $available = Cashback_Money::from_db_value( (string) ( $balance['available_balance'] ?? '0' ) );
        $pending   = Cashback_Money::from_db_value( (string) ( $balance['pending_balance'] ?? '0' ) );
        $paid      = Cashback_Money::from_db_value( (string) ( $balance['paid_balance'] ?? '0' ) );

        return new \WP_REST_Response(array(
            'user_id'       => $user_id,
            'display_name'  => $user->display_name,
            'balance'       => array(
                'available' => (float) $available->to_string(),
                'pending'   => (float) $pending->to_string(),
                'paid'      => (float) $paid->to_string(),
            ),
            'cashback_rate' => (float) ( $profile['cashback_rate'] ?? 60 ),
            'status'        => $profile['status'] ?? 'active',
        ), 200);
    }

    /**
     * GET /me/transactions — Последние транзакции пользователя.
     *
     * Поле `cashback` возвращается как float для обратной совместимости с расширением;
     * перед cast-ом значение валидируется через Cashback_Money (F-35-004 defense-in-depth).
     *
     * @deprecated float-ответ — будет заменён на canonical decimal-string в cashback/v2
     *             (ADR Группа 10 Step 4, блокируется координированным релизом расширения).
     */
    public function get_transactions( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $user_id  = get_current_user_id();
        $page     = $request->get_param('page');
        $per_page = $request->get_param('per_page');
        $offset   = ( $page - 1 ) * $per_page;

        $table = $wpdb->prefix . 'cashback_transactions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
        $total = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE user_id = %d',
            $table,
            $user_id
        ));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
        $items = $wpdb->get_results($wpdb->prepare(
            'SELECT offer_name, cashback, order_status, currency, partner, action_date, created_at
             FROM %i
             WHERE user_id = %d
             ORDER BY COALESCE(action_date, created_at) DESC
             LIMIT %d OFFSET %d',
            $table,
            $user_id,
            $per_page,
            $offset
        ), ARRAY_A);

        $formatted = array();
        foreach ($items as $item) {
            // Money-валидация DB-значения перед (float)-cast в ответ (F-35-004).
            $cashback_money = Cashback_Money::from_db_value( (string) ( $item['cashback'] ?? '0' ) );
            $formatted[]    = array(
                'offer_name'   => $item['offer_name'],
                'cashback'     => (float) $cashback_money->to_string(),
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
     * POST /activate — Активация кэшбэка для товара.
     *
     * Thin REST adapter над Cashback_Click_Session_Service: делает idempotency-replay
     * по client_request_id, вызывает сервис, транслирует структурированный result
     * в JSON-ответ с правильным HTTP-кодом.
     */
    public function activate_cashback( \WP_REST_Request $request ): \WP_REST_Response {
        $product_id = (int) $request->get_param('product_id');
        $user_id    = (int) get_current_user_id();

        // 12i-2 ADR (F-10-001): idempotency через client_request_id.
        // Клиент передаёт UUID v4/v7, Cashback_Idempotency::claim сохраняет слот
        // на 5 минут. Ретраи (сеть, flaky mobile) получают сохранённый response.
        $raw_request_id    = (string) $request->get_param('client_request_id');
        $client_request_id = Cashback_Idempotency::normalize_request_id($raw_request_id);
        if ($client_request_id === '') {
            // Клиент не передал — сервер генерирует fallback UUIDv7 (для audit trail
            // в click_log и для детерминистичной идентификации текущего тапа).
            $client_request_id = cashback_generate_uuid7(false);
        }

        $idem_scope = 'activate';
        $stored     = Cashback_Idempotency::get_stored_result($idem_scope, $user_id, $client_request_id);
        if (is_array($stored)) {
            return new \WP_REST_Response($stored, 200);
        }
        if (!Cashback_Idempotency::claim($idem_scope, $user_id, $client_request_id)) {
            return new \WP_REST_Response(array(
                'code'    => 'in_progress',
                'message' => 'Запрос уже обрабатывается. Повторите попытку.',
            ), 409);
        }

        $user_agent = $request->get_header('user_agent');
        $user_agent = $user_agent ? sanitize_text_field($user_agent) : null;

        $result = Cashback_Click_Session_Service::activate(array(
            'product_id'        => $product_id,
            'user_id'           => $user_id,
            'ip_address'        => Cashback_Encryption::get_client_ip(),
            'user_agent'        => $user_agent,
            'referer'           => get_permalink($product_id) ?: null,
            'client_request_id' => $client_request_id,
        ));

        switch ($result['status']) {
            case 'invalid_product':
                return new \WP_REST_Response(array(
                    'code'    => 'invalid_product',
                    'message' => 'Товар не найден или не является внешним.',
                ), 404);
            case 'no_url':
                return new \WP_REST_Response(array(
                    'code'    => 'no_url',
                    'message' => 'У товара отсутствует партнёрская ссылка.',
                ), 400);
            case 'rate_limited':
                return new \WP_REST_Response(array(
                    'code'    => 'rate_limited',
                    'message' => 'Слишком много запросов. Попробуйте позже.',
                ), 429);
            case 'error':
                return new \WP_REST_Response(array(
                    'code'    => 'activation_failed',
                    'message' => 'Не удалось активировать кешбэк. Попробуйте позже.',
                ), 500);
        }

        $canonical_click_id = (string) $result['canonical_click_id'];
        $affiliate_url      = (string) $result['affiliate_url'];
        $window             = (int) $result['window_seconds'];
        $expires_at         = gmdate('Y-m-d H:i:s', time() + $window);

        // Домен магазина из post_meta (НЕ из affiliate URL, который указывает на CPA-сеть)
        $store_domain = $this->normalize_store_domain(
            (string) get_post_meta($product_id, '_store_domain', true)
        );

        // F-2-001 hardening: HMAC-подпись activation URL с привязкой к user_id.
        // Защищает от reuse чужого click_id авторизованным юзером: handle_activation_page
        // отвергает URL, если verify_activation_token(...) возвращает false.
        $issued_at = time();
        $token     = Cashback_Encryption::sign_activation_token($canonical_click_id, $user_id, $issued_at);

        // Формируем URL активации на базе permalink товара, чтобы CPA-сеть
        // видела Referer: yoursite.com/product/название/ вместо /?cashback_go=1
        $product_permalink   = get_permalink($product_id) ?: home_url('/');
        $activation_page_url = add_query_arg(
            array(
                'cashback_go' => '1',
                'click_id'    => $canonical_click_id,
                't'           => $token,
            ),
            $product_permalink
        );

        $response_payload = array(
            'redirect_url'        => $affiliate_url,
            'activation_page_url' => $activation_page_url,
            'click_id'            => $canonical_click_id,
            'expires_at'          => $expires_at,
            'domain'              => $store_domain,
            'reused'              => (bool) $result['reused'],
            'tap_count'           => (int) $result['tap_count'],
        );

        // 12i-2 ADR (F-10-001): store_result для retry-replay в течение TTL.
        Cashback_Idempotency::store_result($idem_scope, $user_id, $client_request_id, $response_payload);

        return new \WP_REST_Response($response_payload, 200);
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
            // 12i-3 ADR (F-10-001): primary lookup в cashback_click_sessions по
            // canonical_click_id + status='active' + expires_at > NOW().
            $sessions_table = $wpdb->prefix . 'cashback_click_sessions';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
            $session_row = $wpdb->get_row($wpdb->prepare(
                "SELECT canonical_click_id, product_id, created_at, expires_at
                   FROM %i
                  WHERE canonical_click_id = %s AND user_id = %d
                    AND status = 'active' AND expires_at > NOW()
                  LIMIT 1",
                $sessions_table,
                $click_id,
                $user_id
            ), ARRAY_A);

            if ($session_row) {
                $dest_domain = $this->get_store_domain((int) $session_row['product_id']);
                return new \WP_REST_Response(array(
                    'activated'    => true,
                    'domain'       => $dest_domain,
                    'activated_at' => $session_row['created_at'],
                    'expires_at'   => $session_row['expires_at'],
                    'click_id'     => $session_row['canonical_click_id'],
                ), 200);
            }

            // 12i-3 ADR: fallback на cashback_click_log для legacy rows (до 12i-1
            // migration click_session_id == NULL, новая таблица ещё не знает о них).
            $click_log_table = $wpdb->prefix . 'cashback_click_log';
            $threshold       = gmdate('Y-m-d H:i:s', time() - self::ACTIVATION_WINDOW);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
            $click = $wpdb->get_row($wpdb->prepare(
                'SELECT click_id, created_at, product_id
                 FROM %i
                 WHERE click_id = %s AND user_id = %d AND created_at >= %s LIMIT 1',
                $click_log_table,
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

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

        // 12i-3 ADR (F-10-001): primary lookup в cashback_click_sessions по
        // последней активной сессии на любом товаре этого домена.
        $sessions_table = $wpdb->prefix . 'cashback_click_sessions';
        $sessions_args  = array_merge(array( $sessions_table, $user_id ), array_map('intval', $product_ids));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; $placeholders — array_fill '%d'; таблица через %i; sniff не видит %d внутри $placeholders.
        $session_row = $wpdb->get_row( $wpdb->prepare( "SELECT canonical_click_id, created_at, expires_at FROM %i WHERE user_id = %d AND product_id IN ({$placeholders}) AND status = 'active' AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1", ...$sessions_args ), ARRAY_A );

        if ($session_row) {
            return new \WP_REST_Response(array(
                'activated'    => true,
                'activated_at' => $session_row['created_at'],
                'expires_at'   => $session_row['expires_at'],
                'click_id'     => $session_row['canonical_click_id'],
            ), 200);
        }

        // 12i-3 ADR: fallback на cashback_click_log для legacy rows.
        $click_log_table = $wpdb->prefix . 'cashback_click_log';
        $threshold       = gmdate('Y-m-d H:i:s', time() - self::ACTIVATION_WINDOW);
        $query_args      = array_merge(array( $click_log_table, $user_id ), array_map('intval', $product_ids), array( $threshold ));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table; $placeholders — array_fill '%d'; таблица через %i; sniff не видит %d внутри $placeholders.
        $click = $wpdb->get_row( $wpdb->prepare( "SELECT click_id, created_at FROM %i WHERE user_id = %d AND product_id IN ({$placeholders}) AND created_at >= %s ORDER BY created_at DESC LIMIT 1", ...$query_args ), ARRAY_A );

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

    // ─── Private helpers ───

    /**
     * Проверка, что текущий запрос направлен к REST-маршрутам cashback/v1.
     */
    private function is_cashback_rest_request(): bool {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $rest_prefix = rest_get_url_prefix();

        if (false !== strpos($request_uri, '/' . $rest_prefix . '/' . self::NAMESPACE)) {
            return true;
        }

        if (isset($_GET['rest_route'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Infrastructure REST routing detection, read-only, nonce handled per-route via permission_callback.
            $route = sanitize_text_field(wp_unslash($_GET['rest_route'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Infrastructure REST routing detection, read-only, nonce handled per-route via permission_callback.
            if (0 === strpos($route, '/' . self::NAMESPACE)) {
                return true;
            }
        }

        return false;
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
}
