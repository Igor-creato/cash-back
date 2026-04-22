<?php

/**
 * Универсальный API-клиент для CPA-сетей
 *
 * Фасад, делегирующий сетевую специфику адаптерам (Cashback_Network_Adapter_Interface).
 * Встроенные адаптеры: Admitad, EPN. Расширяемо через register_adapter() или хук cashback_register_network_adapters.
 * Хранит credentials зашифрованными через Cashback_Encryption.
 *
 * Стратегия reconciliation (индустриальный стандарт кэшбэк-сервисов):
 *   МАТЧИНГ:    API.subid1 == DB.click_id (UUID, генерируемый кэшбэк-сервисом)
 *   СРАВНЕНИЕ:  status, payment/comission, cart/sum_order
 *   ФИЛЬТРАЦИЯ: API.subid2 == DB.user_id
 *   ЛОГИРОВАНИЕ: action_id (для lost order claims), order_id (для поддержки)
 *
 * @package CashbackPlugin
 * @since   6.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_API_Client {

    /** @var self|null */
    private static ?self $instance = null;

    /** @var string Таблица сетей */
    private string $networks_table;

    /** @var string Таблица чекпоинтов */
    private string $checkpoints_table;

    /** @var string Таблица транзакций */
    private string $transactions_table;

    /** @var string Таблица незарегистрированных транзакций */
    private string $unregistered_table;

    /** @var string Таблица синк-логов */
    private string $sync_log_table;

    /** @var array<string, Cashback_Network_Adapter_Interface> Реестр адаптеров (slug => adapter) */
    private array $adapters = array();

    /**
     * @return self
     */
    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->networks_table     = $wpdb->prefix . 'cashback_affiliate_networks';
        $this->checkpoints_table  = $wpdb->prefix . 'cashback_validation_checkpoints';
        $this->transactions_table = $wpdb->prefix . 'cashback_transactions';
        $this->unregistered_table = $wpdb->prefix . 'cashback_unregistered_transactions';
        $this->sync_log_table     = $wpdb->prefix . 'cashback_sync_log';

        // Регистрация встроенных адаптеров CPA-сетей
        $this->register_adapter(new Cashback_Admitad_Adapter());
        $this->register_adapter(new Cashback_Epn_Adapter());

        /**
         * Позволяет внешним плагинам регистрировать свои адаптеры CPA-сетей.
         *
         * @param Cashback_API_Client $client Экземпляр API-клиента
         */
        do_action('cashback_register_network_adapters', $this);
    }

    // =========================================================================
    // Adapter registry
    // =========================================================================

    /**
     * Зарегистрировать адаптер CPA-сети
     *
     * @param Cashback_Network_Adapter_Interface $adapter
     */
    public function register_adapter( Cashback_Network_Adapter_Interface $adapter ): void {
        $this->adapters[ $adapter->get_slug() ] = $adapter;

        foreach ($adapter->get_aliases() as $alias) {
            if (!isset($this->adapters[ $alias ])) {
                $this->adapters[ $alias ] = $adapter;
            }
        }
    }

    /**
     * Получить адаптер по slug сети
     *
     * @param string $slug Slug сети (admitad, epn и др.)
     * @return Cashback_Network_Adapter_Interface|null
     */
    public function get_adapter( string $slug ): ?Cashback_Network_Adapter_Interface {
        return $this->adapters[ $slug ] ?? null;
    }

    /**
     * Проверить, зарегистрирован ли адаптер для сети
     *
     * @param string $slug
     * @return bool
     */
    public function has_adapter( string $slug ): bool {
        return isset($this->adapters[ $slug ]);
    }

    // =========================================================================
    // Credentials management
    // =========================================================================

    /**
     * Сохранить API credentials для сети (зашифрованные)
     *
     * @param int   $network_id ID сети
     * @param array $credentials ['client_id' => ..., 'client_secret' => ..., ...]
     * @return bool
     */
    public function save_credentials( int $network_id, array $credentials ): bool {
        global $wpdb;

        if (!class_exists('Cashback_Encryption') || !Cashback_Encryption::is_configured()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Client: Encryption not configured');
            return false;
        }

        $json = wp_json_encode($credentials);
        if (false === $json) {
            return false;
        }

        // Row-lock TX: encrypt происходит ПОД удержанием FOR UPDATE на строке сети.
        // Закрывает TOCTOU-race с batch-job'ом ротации (фаза affiliate_networks):
        // write-key выбирается под lock'ом, batch не может одновременно перешифровать
        // эту же запись.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Row-lock TX begin.
        $wpdb->query('START TRANSACTION');

        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SELECT FOR UPDATE inside TX.
            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT id FROM %i WHERE id = %d FOR UPDATE',
                    $this->networks_table,
                    $network_id
                )
            );

            if ($existing === null) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback unknown id.
                $wpdb->query('ROLLBACK');
                return false;
            }

            $encrypted = Cashback_Encryption::encrypt($json);
            if (false === $encrypted) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback encrypt failure.
                $wpdb->query('ROLLBACK');
                return false;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE locked row.
            $result = $wpdb->update(
                $this->networks_table,
                array( 'api_credentials' => $encrypted ),
                array( 'id' => $network_id ),
                array( '%s' ),
                array( '%d' )
            );

            if ($result === false) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback UPDATE failure.
                $wpdb->query('ROLLBACK');
                return false;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit.
            $wpdb->query('COMMIT');
            return true;
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback.
            $wpdb->query('ROLLBACK');
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Client: save_credentials error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Получить расшифрованные credentials сети
     *
     * @param int $network_id
     * @return array|null
     */
    public function get_credentials( int $network_id ): ?array {
        global $wpdb;

        $encrypted = $wpdb->get_var($wpdb->prepare(
            'SELECT api_credentials FROM %i WHERE id = %d',
            $this->networks_table,
            $network_id
        ));

        if (empty($encrypted)) {
            return null;
        }

        if (!class_exists('Cashback_Encryption') || !Cashback_Encryption::is_configured()) {
            return null;
        }

        $json = Cashback_Encryption::decrypt($encrypted);
        if (false === $json) {
            return null;
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Получить конфигурацию сети
     *
     * @param string $slug Slug сети (admitad, epn)
     * @return array|null
     */
    public function get_network_config( string $slug ): ?array {
        global $wpdb;

        $network = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE slug = %s AND is_active = 1',
            $this->networks_table,
            $slug
        ), ARRAY_A);

        if (!$network) {
            return null;
        }

        // Расшифровать credentials
        if (!empty($network['api_credentials'])) {
            $creds                  = $this->get_credentials((int) $network['id']);
            $network['credentials'] = $creds;
        }

        // Парсим маппинг статусов
        if (!empty($network['api_status_map'])) {
            $network['status_map'] = json_decode($network['api_status_map'], true) ?: array();
        } else {
            $network['status_map'] = $this->get_default_status_map($slug);
        }

        // Парсим маппинг полей API → локальные колонки
        $network['field_map'] = $this->get_field_map($network);

        return $network;
    }

    /**
     * Получить все активные сети с API-конфигурацией
     *
     * @return array
     */
    public function get_all_active_networks(): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, slug, api_base_url, api_user_field, api_click_field, api_website_id, api_status_map, is_active
                 FROM %i
                 WHERE is_active = 1 AND api_base_url IS NOT NULL AND api_base_url != ''
                 ORDER BY sort_order, name",
                $this->networks_table
            ),
            ARRAY_A
        ) ?: array();
    }

    /**
     * Маппинг статусов по умолчанию (делегация адаптеру)
     */
    private function get_default_status_map( string $slug ): array {
        $adapter = $this->get_adapter($slug);
        if ($adapter) {
            return $adapter->get_default_status_map();
        }

        // Общий fallback для сетей без адаптера
        return array(
            'pending'  => 'waiting',
            'approved' => 'completed',
            'declined' => 'declined',
        );
    }

    // =========================================================================
    // Field map helpers
    // =========================================================================

    /**
     * Дефолтный маппинг полей API → колонки транзакций
     */
    private const DEFAULT_FIELD_MAP = array(
        'payment'          => 'comission',
        'cart'             => 'sum_order',
        'action_id'        => 'uniq_id',
        'order_id'         => 'order_number',
        'advcampaign_id'   => 'offer_id',
        'advcampaign_name' => 'offer_name',
    );

    /**
     * Допустимые колонки транзакций для маппинга (whitelist)
     */
    private const ALLOWED_LOCAL_COLUMNS = array(
        'comission',
		'sum_order',
		'uniq_id',
		'order_number',
        'offer_id',
		'offer_name',
		'currency',
		'action_date',
        'click_time',
		'action_type',
		'website_id',
		'funds_ready',
    );

    /**
     * Получить маппинг полей из конфига сети (мерж с дефолтом)
     *
     * Пользовательский маппинг дополняет дефолтный:
     * - Настроенные поля перезаписывают дефолтные (по локальной колонке)
     * - Ненастроенные колонки берутся из DEFAULT_FIELD_MAP
     * - Это гарантирует, что все обязательные колонки (uniq_id, comission и т.д.)
     *   всегда имеют маппинг, даже если админ настроил только часть полей
     *
     * @param array $config Конфиг сети (строка из cashback_affiliate_networks)
     * @return array<string, string> API field → local column
     */
    private function get_field_map( array $config ): array {
        if (!empty($config['api_field_map'])) {
            $raw = $config['api_field_map'];
            $map = is_string($raw) ? json_decode($raw, true) : ( is_array($raw) ? $raw : null );
            if (is_array($map) && !empty($map)) {
                // Фильтруем: допускаем только валидные локальные колонки
                $filtered = array();
                foreach ($map as $api_field => $local_col) {
                    $api_field = trim((string) $api_field);
                    $local_col = trim((string) $local_col);
                    if ($api_field !== '' && in_array($local_col, self::ALLOWED_LOCAL_COLUMNS, true)) {
                        $filtered[ $api_field ] = $local_col;
                    }
                }

                if (!empty($filtered)) {
                    // Мержим с дефолтом: для каждой дефолтной колонки,
                    // если она не покрыта пользовательским маппингом — добавляем дефолт
                    $covered_columns = array_values($filtered);
                    foreach (self::DEFAULT_FIELD_MAP as $def_api => $def_col) {
                        if (!in_array($def_col, $covered_columns, true)) {
                            $filtered[ $def_api ] = $def_col;
                        }
                    }
                    return $filtered;
                }
            }
        }

        return self::DEFAULT_FIELD_MAP;
    }

    /**
     * Извлечь значения из API action по маппингу полей
     *
     * Возвращает массив ['local_column' => value, ...] на основе field_map.
     * Для полей, отсутствующих в action, устанавливает дефолты.
     *
     * @param array $action  Нормализованный ответ API (после адаптера)
     * @param array $field_map Маппинг API field → local column
     * @return array<string, mixed> local_column → value
     */
    private function apply_field_map( array $action, array $field_map ): array {
        $result = array();

        foreach ($field_map as $api_field => $local_col) {
            $value = $action[ $api_field ] ?? null;

            // Приведение типов по колонке
            switch ($local_col) {
                case 'comission':
                case 'sum_order':
                    $result[ $local_col ] = (float) ( $value ?? 0 );
                    break;
                case 'offer_id':
                case 'website_id':
                    $result[ $local_col ] = ( $value !== null && $value !== '' ) ? (int) $value : null;
                    break;
                default:
                    $result[ $local_col ] = (string) ( $value ?? '' );
                    break;
            }
        }

        return $result;
    }

    /**
     * Обратный поиск: по имени локальной колонки найти имя поля в API
     *
     * @param string $local_column Колонка в таблице транзакций
     * @param array  $field_map    Маппинг API field → local column
     * @return string Имя поля API (или пустая строка)
     */
    private function api_field_for( string $local_column, array $field_map ): string {
        $flipped = array_flip($field_map);
        return (string) ( $flipped[ $local_column ] ?? '' );
    }

    /**
     * Определить значение funds_ready из API-action.
     *
     * Порядок: field_map → адаптер (funds_ready) → Admitad (processed).
     *
     * @param array $action    Нормализованный action из API
     * @param array $field_map Маппинг полей
     * @return int 0 или 1
     */
    private function resolve_funds_ready( array $action, array $field_map ): int {
        $fm_funds_ready = $this->api_field_for('funds_ready', $field_map);
        if ($fm_funds_ready !== '') {
            return !empty($action[ $fm_funds_ready ]) ? 1 : 0;
        }
        if (isset($action['funds_ready'])) {
            return (int) $action['funds_ready'];
        }
        if (isset($action['processed'])) {
            return empty($action['processed']) ? 0 : 1;
        }
        return 0;
    }

    // =========================================================================
    // URL builder (used by test_connection for api_key branch)
    // =========================================================================

    /**
     * Собрать URL из конфига сети (api_base_url + endpoint) или вернуть fallback
     */
    private function build_api_url( array $network_config, string $endpoint_key, string $fallback_url ): string {
        $base     = rtrim($network_config['api_base_url'] ?? '', '/');
        $endpoint = $network_config[ $endpoint_key ] ?? '';

        if ($endpoint !== '' && preg_match('#^https?://#i', $endpoint)) {
            if (!$this->is_safe_api_url($endpoint)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback API] Blocked unsafe API endpoint URL: ' . $endpoint);
                return $fallback_url;
            }
            return $endpoint;
        }

        if ($base !== '' && $endpoint !== '') {
            $full_url = $base . '/' . ltrim($endpoint, '/');
            if (!$this->is_safe_api_url($full_url)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback API] Blocked unsafe API base URL: ' . $full_url);
                return $fallback_url;
            }
            return $full_url;
        }

        return $fallback_url;
    }

    /**
     * Проверяет безопасность URL для API-запросов (защита от SSRF).
     *
     * Разрешает только HTTPS и блокирует приватные/зарезервированные IP-адреса.
     */
    private function is_safe_api_url( string $url ): bool {
        $parsed = wp_parse_url($url);

        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        // Только HTTPS
        if (strtolower($parsed['scheme']) !== 'https') {
            return false;
        }

        // Резолвим домен и проверяем что IP не приватный/зарезервированный
        $ip = gethostbyname($parsed['host']);
        if ($ip === $parsed['host']) {
            // gethostbyname вернул сам хост — не удалось зарезолвить
            return false;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        return true;
    }

    // =========================================================================
    // Универсальная авторизация (OAuth2 / API Key)
    // =========================================================================

    /**
     * Сформировать заголовки авторизации в зависимости от типа (делегация адаптеру)
     *
     * @param array  $credentials   Расшифрованные credentials
     * @param array  $network_config Конфигурация сети (api_auth_type, api_base_url, slug, ...)
     * @param string $network_slug  Slug сети для роутинга (epn, admitad и др.)
     * @return array|null Массив заголовков или null при ошибке
     */
    private function build_auth_headers( array $credentials, array $network_config, string $network_slug = '' ): ?array {
        $auth_type = $network_config['api_auth_type'] ?? 'oauth2';

        if ($auth_type === 'api_key') {
            $api_key = $credentials['api_key'] ?? '';
            if (empty($api_key)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback API Client: API key is empty');
                return null;
            }
            return array( 'Authorization' => 'Bearer ' . $api_key );
        }

        // OAuth2: делегация адаптеру
        $adapter = $this->get_adapter($network_slug);
        if (!$adapter) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Client: No adapter registered for network: ' . $network_slug);
            return null;
        }
        return $adapter->build_auth_headers($credentials, $network_config);
    }

    /**
     * Проверить подключение к API CPA-сети
     *
     * @param int $network_id ID сети
     * @return array ['success' => bool, 'message' => string]
     */
    public function test_connection( int $network_id ): array {
        global $wpdb;

        $network = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d',
            $this->networks_table,
            $network_id
        ), ARRAY_A);

        if (!$network) {
            return array(
				'success' => false,
				'message' => 'Сеть не найдена',
			);
        }

        $credentials = $this->get_credentials($network_id);
        $auth_type   = $network['api_auth_type'] ?? 'oauth2';

        // Проверка наличия credentials
        if ($auth_type === 'api_key') {
            if (!$credentials || empty($credentials['api_key'])) {
                return array(
					'success' => false,
					'message' => 'API Key не настроен. Сохраните API Key.',
				);
            }
        } elseif (!$credentials || empty($credentials['client_id']) || empty($credentials['client_secret'])) {
                return array(
					'success' => false,
					'message' => 'API credentials не настроены. Сохраните client_id и client_secret.',
				);
        }

        $network_config = $network;

        if ($auth_type === 'api_key') {
            // Для API Key: пробуем GET-запрос к actions endpoint с limit=1
            $auth_headers = $this->build_auth_headers($credentials, $network_config);
            if (!$auth_headers) {
                return array(
					'success' => false,
					'message' => 'Не удалось сформировать заголовки авторизации.',
				);
            }

            $actions_url = $this->build_api_url($network_config, 'api_actions_endpoint', '');
            if (empty($actions_url)) {
                return array(
					'success' => false,
					'message' => 'Actions Endpoint не настроен.',
				);
            }

            $url = $actions_url . '?' . http_build_query(array( 'limit' => 1 ));

            $response = wp_remote_get($url, array(
                // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Admin connectivity test endpoint; synchronous check from admin UI, not user-facing request path.
                'timeout' => 30,
                'headers' => $auth_headers,
            ));

            if (is_wp_error($response)) {
                return array(
					'success' => false,
					'message' => 'Ошибка запроса: ' . $response->get_error_message(),
				);
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) {
                return array(
					'success' => true,
					'message' => 'Соединение успешно. API ответил HTTP ' . $code . '.',
				);
            }

            return array(
				'success' => false,
				'message' => 'API вернул HTTP ' . $code . '. Проверьте API Key и URL.',
			);
        }

        // OAuth2: делегация адаптеру
        $slug    = $network['slug'] ?? '';
        $adapter = $this->get_adapter($slug);

        if (!$adapter) {
            return array(
				'success' => false,
				'message' => 'Нет зарегистрированного адаптера для сети: ' . $slug,
			);
        }

        $token = $adapter->get_token($credentials, $network_config);

        if ($token) {
            return array(
				'success' => true,
				'message' => 'Соединение успешно. OAuth2 токен получен.',
			);
        }

        $detail = $adapter->get_last_token_error()
            ?: 'Проверьте client_id, client_secret и URL эндпоинта.';

        return array(
			'success' => false,
			'message' => 'Не удалось получить токен. ' . $detail,
		);
    }

    // =========================================================================
    // Fetch actions from CPA networks (delegated to adapters)
    // =========================================================================

    /**
     * Универсальный fetch: делегация адаптеру по slug сети
     *
     * @param string $slug          Slug сети (epn, admitad, ...)
     * @param array  $credentials   API credentials
     * @param array  $params        Параметры запроса
     * @param int    $max_pages     Максимальное количество страниц
     * @param array  $network_config Конфигурация сети
     * @return array ['success' => bool, 'actions' => [...], 'total' => int, 'error' => string|null]
     */
    public function fetch_all_actions_for_network( string $slug, array $credentials, array $params, int $max_pages = 20, array $network_config = array() ): array {
        $adapter = $this->get_adapter($slug);
        if (!$adapter) {
            return array(
                'success' => false,
                'actions' => array(),
                'total'   => 0,
                'error'   => 'No adapter registered for network: ' . $slug,
            );
        }

        return $adapter->fetch_all_actions($credentials, $params, $max_pages, $network_config);
    }

    // =========================================================================
    // Date parsing
    // =========================================================================

    /**
     * Парсинг даты из API в MySQL DATETIME формат
     *
     * Поддерживает: ISO 8601, MySQL, русский dd.mm.YYYY, Unix timestamps.
     *
     * @param string $date_str Строка даты из API
     * @return string|null MySQL DATETIME (Y-m-d H:i:s) или null
     */
    protected static function parse_api_date( string $date_str ): ?string {
        $date_str = trim($date_str);
        if ($date_str === '') {
            return null;
        }

        // Unix timestamp (10 цифр = секунды, 13 цифр = миллисекунды)
        if (preg_match('/^\d{10,13}$/', $date_str)) {
            $timestamp = (int) $date_str;
            if (strlen($date_str) === 13) {
                $timestamp = (int) ( $timestamp / 1000 );
            }
            $dt = new DateTime();
            $dt->setTimestamp($timestamp);
            $dt->setTimezone(new DateTimeZone(wp_timezone_string()));
            return $dt->format('Y-m-d H:i:s');
        }

        // ISO 8601 с T-разделителем: "2024-01-15T10:30:00"
        $date_str = str_replace('T', ' ', $date_str);

        // Убираем таймзону: "+03:00", " 03:00" (+ → пробел после URL encoding), "Z"
        $date_str = preg_replace('/[+-]\d{2}:\d{2}$/', '', $date_str);
        $date_str = preg_replace('/\s+\d{2}:\d{2}$/', '', $date_str);
        $date_str = rtrim($date_str, 'Z');

        $formats = array(
            'Y-m-d H:i:s',  // 2024-01-15 10:30:00
            'Y-m-d H:i',    // 2024-01-15 10:30
            'Y-m-d',         // 2024-01-15
            'd.m.Y H:i:s',  // 15.01.2024 10:30:00
            'd.m.Y H:i',    // 15.01.2024 10:30
            'd.m.Y',         // 15.01.2024
        );

        foreach ($formats as $format) {
            $dt = DateTime::createFromFormat($format, $date_str);
            if ($dt !== false) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    // =========================================================================
    // Validation logic
    // =========================================================================

    /**
     * Валидация пользователя: сравнение данных API с локальными транзакциями
     *
     * Стратегия (индустриальный стандарт кэшбэк-сервисов):
     *   1. Запрос API с фильтром subid2 = user_id (все транзакции пользователя)
     *   2. Матчинг: API.subid1 == DB.click_id (наш UUID)
     *   3. Сравнение сматченных: status, payment/comission, cart/sum_order
     *   4. Выявление: missing_local (в API, нет у нас), missing_api (у нас, нет в API)
     *
     * @param int    $user_id
     * @param string $network_slug Slug сети (admitad, epn)
     * @param bool   $use_checkpoint Использовать инкрементальный чекпоинт
     * @return array Результат валидации
     */
    public function validate_user( int $user_id, string $network_slug = 'admitad', bool $use_checkpoint = true ): array {
        global $wpdb;

        $network = $this->get_network_config($network_slug);
        if (!$network || empty($network['credentials'])) {
            return array(
                'success' => false,
                'error'   => 'Сеть не найдена или API не настроен: ' . $network_slug,
                'user_id' => $user_id,
                'network' => $network_slug,
            );
        }

        // ─── Определяем дату начала ───
        $date_start = '01.01.2020';

        if ($use_checkpoint) {
            $checkpoint = $this->get_checkpoint($user_id, $network_slug);
            if ($checkpoint && !empty($checkpoint['last_validated_date'])) {
                $dt = new DateTime($checkpoint['last_validated_date']);
                $dt->modify('-7 days');
                $date_start = $dt->format('d.m.Y');
            } else {
                $reg_date = $wpdb->get_var($wpdb->prepare(
                    "SELECT user_registered FROM {$wpdb->users} WHERE ID = %d",
                    $user_id
                ));
                if ($reg_date) {
                    $date_start = ( new DateTime($reg_date) )->format('d.m.Y');
                }
            }
        }

        $date_end = ( new DateTime() )->format('d.m.Y');

        // ─── Запрос к API ───
        // api_user_field = 'subid2' (partner_token передаётся в subid2 при генерации ссылки)
        // api_click_field = 'subid1' (click_id передаётся в subid1 — ключ матчинга)
        $user_field = $network['api_user_field'] ?? 'subid2';

        // В партнёрских ссылках используется partner_token (не user_id)
        $partner_token  = Mariadb_Plugin::get_partner_token($user_id);
        $api_user_value = $partner_token !== null ? $partner_token : (string) $user_id;

        $api_params = array(
            $user_field  => $api_user_value,
            'date_start' => $date_start,
            'date_end'   => $date_end,
        );

        if (!empty($network['api_website_id'])) {
            $api_params['website'] = $network['api_website_id'];
        }

        $api_result = $this->fetch_all_actions_for_network($network_slug, $network['credentials'], $api_params, 20, $network);

        if (!$api_result['success']) {
            return array(
                'success' => false,
                'error'   => 'API error: ' . $api_result['error'],
                'user_id' => $user_id,
                'network' => $network_slug,
            );
        }

        $api_actions = $api_result['actions'];

        // ─── Локальные транзакции ───
        // ВАЖНО: включаем click_id для матчинга и order_number для fallback
        $local_start = DateTime::createFromFormat('d.m.Y', $date_start)->format('Y-m-d');

        // Матчим partner по slug И name сети (case-insensitive),
        // т.к. webhook может записывать partner_name по-разному
        $network_name = $network['name'] ?? '';

        $local_transactions = $wpdb->get_results($wpdb->prepare(
            'SELECT t.id, t.click_id, t.uniq_id, t.order_number, t.offer_name,
                    t.comission, t.cashback, t.order_status, t.partner,
                    t.sum_order, t.created_at, t.updated_at, t.original_cpa_subid
             FROM %i t
             WHERE t.user_id = %d
               AND (LOWER(t.partner) = LOWER(%s) OR LOWER(t.partner) = LOWER(%s))
               AND t.created_at >= %s
             ORDER BY t.created_at',
            $this->transactions_table,
            $user_id,
            $network_slug,
            $network_name,
            $local_start
        ), ARRAY_A);

        // ─── Индексы для матчинга ───
        // 1. Основной: по click_id (= API.subid1, наш UUID)
        $local_by_click_id = array();
        // 2. Fallback: по uniq_id (= API.action_id, уникальный ID в рамках CPA-сети)
        $local_by_uniq_id = array();

        foreach ($local_transactions as $tx) {
            if (!empty($tx['click_id'])) {
                $local_by_click_id[ $tx['click_id'] ] = $tx;
            }
            if (!empty($tx['uniq_id'])) {
                $local_by_uniq_id[ $tx['uniq_id'] ] = $tx;
            }
        }

        // Маппинг статусов
        $status_map = $network['status_map'];

        // Маппинг полей API → локальные колонки
        $field_map = $network['field_map'];

        // Имена полей API по маппингу (обратный поиск)
        $fm_payment     = $this->api_field_for('comission', $field_map) ?: 'payment';
        $fm_cart        = $this->api_field_for('sum_order', $field_map) ?: 'cart';
        $fm_uniq_id     = $this->api_field_for('uniq_id', $field_map) ?: 'action_id';
        $fm_order_id    = $this->api_field_for('order_number', $field_map) ?: 'order_id';
        $fm_offer_id    = $this->api_field_for('offer_id', $field_map) ?: 'advcampaign_id';
        $fm_offer_nm    = $this->api_field_for('offer_name', $field_map) ?: 'advcampaign_name';
        $fm_currency    = $this->api_field_for('currency', $field_map) ?: 'currency';
        $fm_action_date = $this->api_field_for('action_date', $field_map) ?: 'action_date';
        $fm_click_time  = $this->api_field_for('click_time', $field_map) ?: 'click_date';
        $fm_action_type = $this->api_field_for('action_type', $field_map) ?: 'action_type';
        $fm_website_id  = $this->api_field_for('website_id', $field_map) ?: 'website_id';

        // Имя поля для click_id в API (по умолчанию subid1)
        $click_field = $network['api_click_field'] ?? 'subid1';

        // ─── Сравнение ───
        $matched       = array();
        $mismatched    = array();
        $missing_local = array(); // Есть в API, нет локально

        // Суммы по API (по замапленным статусам)
        $api_sums = array(
			'approved' => 0.0,
			'pending'  => 0.0,
			'declined' => 0.0,
		);

        // Суммы по локальным сматченным (по статусам)
        $local_sums = array(
			'approved' => 0.0,
			'pending'  => 0.0,
			'declined' => 0.0,
		);

        // Множество сматченных click_id для обратной проверки (только полные совпадения)
        $matched_click_ids = array();

        // Множество click_id локальных транзакций, найденных в API (совпавших или расходящихся)
        // Используется в обратной проверке missing_api, чтобы не дублировать mismatched
        $api_matched_local_click_ids = array();

        // Debug: логируем только ключи первого action из API для диагностики маппинга
        // Данные не логируем — могут содержать PII (email, имя, телефон)
        if (defined('WP_DEBUG') && WP_DEBUG && !empty($api_actions)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback API Validate] First action keys: ' . implode(', ', array_keys($api_actions[0])));
        }

        foreach ($api_actions as $action) {
            $api_click_id  = (string) ( $action[ $click_field ] ?? '' );
            $api_status    = strtolower($action['status'] ?? 'pending');
            $api_payment   = (float) ( $action[ $fm_payment ] ?? 0 );
            $api_cart      = (float) ( $action[ $fm_cart ] ?? 0 );
            $mapped_status = $status_map[ $api_status ] ?? 'waiting';

            // Подсчёт сумм по API
            // balance — эквивалент completed (из кастомного маппинга approved→balance)
            if ($mapped_status === 'completed' || $mapped_status === 'balance') {
                $api_sums['approved'] += $api_payment;
            } elseif ($mapped_status === 'waiting') {
                $api_sums['pending'] += $api_payment;
            } elseif ($mapped_status === 'declined') {
                $api_sums['declined'] += $api_payment;
            }

            // ─── МАТЧИНГ ───
            $local_tx = null;

            // 1. Основной ключ: click_id (наш UUID, наиболее надёжный)
            if ($api_click_id !== '' && isset($local_by_click_id[ $api_click_id ])) {
                $local_tx = $local_by_click_id[ $api_click_id ];
            }

            // 2. Fallback: uniq_id (уникальный ID в рамках CPA-сети)
            if (!$local_tx) {
                $action_id_key = (string) ( $action[ $fm_uniq_id ] ?? '' );
                if ($action_id_key !== '' && isset($local_by_uniq_id[ $action_id_key ])) {
                    $local_tx = $local_by_uniq_id[ $action_id_key ];
                }
            }

            if (!$local_tx) {
                $missing_local[] = array(
                    'action_id'   => $action[ $fm_uniq_id ] ?? '',
                    'click_id'    => $api_click_id,
                    'order_id'    => $action[ $fm_order_id ] ?? '',
                    'status'      => $api_status,
                    'payment'     => $api_payment,
                    'cart'        => $api_cart,
                    'date'        => $action[ $fm_action_date ] ?? '',
                    'campaign'    => $action[ $fm_offer_nm ] ?? '',
                    'campaign_id' => $action[ $fm_offer_id ] ?? '',
                    'currency'    => $action[ $fm_currency ] ?? 'RUB',
                    'click_time'  => $action[ $fm_click_time ] ?? $action['click_time'] ?? $action['closing_date'] ?? '',
                    'action_type' => $action[ $fm_action_type ] ?? '',
                    'website_id'  => $action[ $fm_website_id ] ?? $action['website_name'] ?? $action['website'] ?? ( $network['api_website_id'] ?? '' ),
                    'funds_ready' => $this->resolve_funds_ready($action, $field_map),
                );
                continue;
            }

            // Запоминаем что эта локальная транзакция найдена в API (независимо от расхождений)
            if (!empty($local_tx['click_id'])) {
                $api_matched_local_click_ids[ $local_tx['click_id'] ] = true;
            }

            // Запоминаем что эта локальная транзакция сматчена
            if (!empty($local_tx['click_id'])) {
                $matched_click_ids[ $local_tx['click_id'] ] = true;
            }

            // ─── СРАВНЕНИЕ ───
            $local_status     = $local_tx['order_status'];
            $local_commission = (float) $local_tx['comission'];
            $local_cart       = (float) ( $local_tx['sum_order'] ?? 0 );

            // Суммы по локальным
            if ($local_status === 'completed' || $local_status === 'balance') {
                $local_sums['approved'] += $local_commission;
            } elseif ($local_status === 'waiting' || $local_status === 'hold') {
                $local_sums['pending'] += $local_commission;
            } elseif ($local_status === 'declined') {
                $local_sums['declined'] += $local_commission;
            }

            // Статус: completed и balance — оба эквивалентны approved в API
            // (balance = финализированный completed, зачислено в баланс)
            $approved_statuses = array( 'completed', 'balance' );
            $status_match      = ( $local_status === $mapped_status )
                || ( in_array($local_status, $approved_statuses, true)
                    && in_array($mapped_status, $approved_statuses, true) );

            // Комиссия: допускаем погрешность 0.001 (float-артефакты)
            $commission_match = abs($api_payment - $local_commission) < 0.001;

            // Сумма заказа: допускаем погрешность 0.001 (float-артефакты)
            // Не считаем mismatch если у одной из сторон 0 (не всегда передаётся)
            $cart_match = ( $api_cart == 0 || $local_cart == 0 )
                || abs($api_cart - $local_cart) < 0.001;

            if ($status_match && $commission_match && $cart_match) {
                $matched[] = array(
                    'local_id'         => (int) $local_tx['id'],
                    'click_id'         => $api_click_id,
                    'api_status'       => $api_status,
                    'local_status'     => $local_status,
                    'api_payment'      => $api_payment,
                    'local_commission' => $local_commission,
                );
            } else {
                $mismatched[] = array(
                    'uniq_id'             => $local_tx['uniq_id'] ?? '',
                    'click_id'            => $api_click_id,
                    'local_id'            => $local_tx['id'],
                    'api_status'          => $api_status,
                    'local_status'        => $local_status,
                    'mapped_api_status'   => $mapped_status,
                    'api_payment'         => $api_payment,
                    'local_commission'    => $local_commission,
                    'api_cart'            => $api_cart,
                    'local_cart'          => $local_cart,
                    'status_mismatch'     => !$status_match,
                    'commission_mismatch' => !$commission_match,
                    'cart_mismatch'       => !$cart_match,
                    'action_id'           => $action[ $fm_uniq_id ] ?? '',
                    'order_id'            => $action[ $fm_order_id ] ?? '',
                );

                // Авто-обновляем расхождения — синхронизируем локальные данные с API
                $dummy_updated = 0;
                $dummy_skipped = 0;
                $dummy_errors  = 0;
                $this->sync_update_local(
                    $wpdb,
                    $this->transactions_table,
                    $local_tx,
                    $mapped_status,
                    $api_payment,
                    $api_cart,
                    $network_slug,
                    $api_click_id,
                    $action,
                    $field_map,
                    $dummy_updated,
                    $dummy_skipped,
                    $dummy_errors
                );
            }
        }

        // ─── Обратная проверка: транзакции есть у нас, но нет в API ───
        $missing_api = array();
        foreach ($local_transactions as $tx) {
            // Пропускаем если транзакция найдена в API (полное совпадение или расхождение — неважно)
            if (!empty($tx['click_id']) && isset($api_matched_local_click_ids[ $tx['click_id'] ])) {
                continue;
            }
            // Пропускаем balance — финализировано, может быть за пределами API
            if ($tx['order_status'] === 'balance') {
                continue;
            }
            // Пропускаем если нет click_id — невозможно сверить
            if (empty($tx['click_id'])) {
                continue;
            }

            $missing_api[] = array(
                'local_id'     => $tx['id'],
                'uniq_id'      => $tx['uniq_id'] ?? '',
                'click_id'     => $tx['click_id'],
                'order_number' => $tx['order_number'],
                'status'       => $tx['order_status'],
                'commission'   => (float) $tx['comission'],
                'sum_order'    => (float) ( $tx['sum_order'] ?? 0 ),
                'created'      => $tx['created_at'],
            );
        }

        // ─── Дополнительная проверка для перенесённых из unregistered транзакций ───
        // Транзакции, перенесённые администратором из cashback_unregistered_transactions,
        // хранятся в CPA с original_cpa_subid (например 'unregistered'), а не с реальным user_id.
        // Поэтому основной запрос к API (subid2=user_id) их не возвращает.
        // Делаем дополнительные запросы по уникальным значениям original_cpa_subid.
        if (!empty($missing_api)) {
            $transferred_missing = array();
            foreach ($missing_api as $key => $m) {
                $local_tx   = $local_by_click_id[ $m['click_id'] ] ?? null;
                $orig_subid = $local_tx['original_cpa_subid'] ?? null;

                if ($orig_subid !== null && $orig_subid !== $api_user_value && $orig_subid !== (string) $user_id) {
                    $transferred_missing[ $orig_subid ][ $key ] = $m;
                }
            }

            foreach ($transferred_missing as $orig_subid => $items) {
                $extra_params = array(
                    $user_field  => $orig_subid,
                    'date_start' => $date_start,
                    'date_end'   => $date_end,
                );
                if (!empty($network['api_website_id'])) {
                    $extra_params['website'] = $network['api_website_id'];
                }

                $extra_result = $this->fetch_all_actions_for_network(
                    $network_slug,
                    $network['credentials'],
                    $extra_params,
                    20,
                    $network
                );

                if (!$extra_result['success']) {
                    continue;
                }

                foreach ($extra_result['actions'] as $extra_action) {
                    $extra_click_id  = (string) ( $extra_action[ $click_field ] ?? '' );
                    $extra_action_id = (string) ( $extra_action['action_id'] ?? '' );

                    foreach ($items as $key => $m) {
                        $click_match  = $extra_click_id !== '' && $extra_click_id === $m['click_id'];
                        $action_match = $extra_action_id !== '' && $extra_action_id === ( $m['uniq_id'] ?? '' );

                        if ($click_match || $action_match) {
                            unset($missing_api[ $key ]);
                            $matched[] = array( 'local_id' => $m['local_id'] );
                            unset($items[ $key ]); // избегаем повторного матчинга
                            break;
                        }
                    }
                }
            }

            $missing_api = array_values($missing_api);
        }

        // ─── Обновляем api_verified для всех сматченных транзакций ───
        $matched_ids = array_column($matched, 'local_id');
        if (!empty($matched_ids)) {
            // Батчами по 500 чтобы не превысить лимит SQL
            foreach (array_chunk($matched_ids, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is array_fill of %d literals; sniff can't see %d inside $placeholders.
                $wpdb->query( $wpdb->prepare( "UPDATE %i SET api_verified = 1 WHERE id IN ({$placeholders}) AND api_verified = 0", $this->transactions_table, ...$chunk ) );
            }
        }

        // ─── Результат ───
        $has_issues        = !empty($mismatched) || !empty($missing_local) || !empty($missing_api);
        $total_checked     = count($api_actions);
        $validation_status = $has_issues ? 'mismatch' : 'match';

        // Расхождение: разница между API approved и локальными approved суммами
        $discrepancy = abs($api_sums['approved'] - $local_sums['approved']);

        // Обновляем чекпоинт
        $this->update_checkpoint($user_id, $network_slug, array(
            'last_validated_date'      => ( new DateTime() )->format('Y-m-d'),
            'api_sum_approved'         => $api_sums['approved'],
            'api_sum_pending'          => $api_sums['pending'],
            'api_sum_declined'         => $api_sums['declined'],
            'api_actions_count'        => $total_checked,
            'local_sum_approved'       => $local_sums['approved'],
            'local_sum_pending'        => $local_sums['pending'],
            'local_sum_declined'       => $local_sums['declined'],
            'local_transactions_count' => count($local_transactions),
            'validation_status'        => $validation_status,
            'discrepancy_amount'       => $discrepancy,
            'matched_count'            => count($matched),
            'mismatch_count'           => count($mismatched),
            'missing_local_count'      => count($missing_local),
            'missing_api_count'        => count($missing_api),
        ));

        return array(
            'success'        => true,
            'user_id'        => $user_id,
            'network'        => $network_slug,
            'status'         => $validation_status,
            'date_range'     => array(
				'start' => $date_start,
				'end'   => $date_end,
			),
            'api_total'      => $total_checked,
            'local_total'    => count($local_transactions),
            'matched_count'  => count($matched),
            'mismatch_count' => count($mismatched),
            'missing_local'  => $missing_local,
            'missing_api'    => $missing_api,
            'mismatched'     => $mismatched,
            'sums'           => array(
                'api_approved'   => $api_sums['approved'],
                'api_pending'    => $api_sums['pending'],
                'api_declined'   => $api_sums['declined'],
                'local_approved' => $local_sums['approved'],
                'local_pending'  => $local_sums['pending'],
                'local_declined' => $local_sums['declined'],
                'discrepancy'    => $discrepancy,
            ),
        );
    }

    // =========================================================================
    // Validation — unregistered transactions
    // =========================================================================

    /**
     * Валидация незарегистрированных транзакций по API
     *
     * Аналог validate_user(), но работает с таблицей cashback_unregistered_transactions.
     * Загружает ВСЕ локальные незарегистрированные транзакции и сопоставляет их
     * с данными API по click_id / order_number.
     *
     * @param string $network_slug Slug сети (admitad, epn)
     * @param bool   $use_checkpoint Использовать инкрементальный чекпоинт
     * @return array Результат валидации
     */
    public function validate_unregistered( string $network_slug = 'admitad', bool $use_checkpoint = true ): array {
        global $wpdb;

        $network = $this->get_network_config($network_slug);
        if (!$network || empty($network['credentials'])) {
            return array(
                'success' => false,
                'error'   => 'Сеть не найдена или API не настроен: ' . $network_slug,
                'user_id' => 0,
                'network' => $network_slug,
            );
        }

        // ─── Определяем дату начала ───
        $date_start = '01.01.2020';

        if ($use_checkpoint) {
            // user_id = 0 для чекпоинта незарегистрированных
            $checkpoint = $this->get_checkpoint(0, $network_slug);
            if ($checkpoint && !empty($checkpoint['last_validated_date'])) {
                $dt = new DateTime($checkpoint['last_validated_date']);
                $dt->modify('-7 days');
                $date_start = $dt->format('d.m.Y');
            }
        }

        $date_end = ( new DateTime() )->format('d.m.Y');

        // ─── Локальные незарегистрированные транзакции ───
        $local_start  = DateTime::createFromFormat('d.m.Y', $date_start)->format('Y-m-d');
        $network_name = $network['name'] ?? '';

        $local_transactions = $wpdb->get_results($wpdb->prepare(
            'SELECT t.id, t.click_id, t.uniq_id, t.order_number, t.offer_name,
                    t.comission, t.cashback, t.order_status, t.partner,
                    t.sum_order, t.created_at, t.updated_at, t.user_id
             FROM %i t
             WHERE (LOWER(t.partner) = LOWER(%s) OR LOWER(t.partner) = LOWER(%s))
               AND t.created_at >= %s
             ORDER BY t.created_at',
            $this->unregistered_table,
            $network_slug,
            $network_name,
            $local_start
        ), ARRAY_A);

        if (empty($local_transactions)) {
            // Нет локальных транзакций — нечего проверять
            $this->update_checkpoint(0, $network_slug, array(
                'last_validated_date'      => ( new DateTime() )->format('Y-m-d'),
                'api_actions_count'        => 0,
                'local_transactions_count' => 0,
                'validation_status'        => 'match',
                'matched_count'            => 0,
                'mismatch_count'           => 0,
                'missing_local_count'      => 0,
                'missing_api_count'        => 0,
            ));

            return array(
                'success'        => true,
                'user_id'        => 0,
                'network'        => $network_slug,
                'status'         => 'match',
                'date_range'     => array(
					'start' => $date_start,
					'end'   => $date_end,
				),
                'api_total'      => 0,
                'local_total'    => 0,
                'matched_count'  => 0,
                'mismatch_count' => 0,
                'missing_local'  => array(),
                'missing_api'    => array(),
                'mismatched'     => array(),
                'sums'           => array(
                    'api_approved'   => 0,
					'api_pending'    => 0,
					'api_declined'   => 0,
                    'local_approved' => 0,
					'local_pending'  => 0,
					'local_declined' => 0,
                    'discrepancy'    => 0,
                ),
            );
        }

        // ─── Индексы для матчинга ───
        // 1. Основной: по click_id (= API.subid1, наш UUID)
        $local_by_click_id = array();
        // 2. Fallback: по uniq_id (= API.action_id, уникальный ID в рамках CPA-сети)
        $local_by_uniq_id = array();

        foreach ($local_transactions as $tx) {
            if (!empty($tx['click_id'])) {
                $local_by_click_id[ $tx['click_id'] ] = $tx;
            }
            if (!empty($tx['uniq_id'])) {
                $local_by_uniq_id[ $tx['uniq_id'] ] = $tx;
            }
        }

        // ─── Запрос к API ───
        // В БД user_id хранится как '0', но в API subid = 'unregistered'.
        // Запрашиваем API с subid = 'unregistered' (литеральное значение из партнёрской ссылки).
        $user_field = $network['api_user_field'] ?? 'subid2';

        $api_params = array(
            $user_field  => 'unregistered',
            'date_start' => $date_start,
            'date_end'   => $date_end,
        );

        if (!empty($network['api_website_id'])) {
            $api_params['website'] = $network['api_website_id'];
        }

        $api_result = $this->fetch_all_actions_for_network($network_slug, $network['credentials'], $api_params, 20, $network);

        $api_actions = array();
        if ($api_result['success'] && !empty($api_result['actions'])) {
            $api_actions = $api_result['actions'];
        } elseif (!$api_result['success']) {
            return array(
                'success' => false,
                'error'   => 'API error: ' . $api_result['error'],
                'user_id' => 0,
                'network' => $network_slug,
            );
        }

        // ─── Маппинг статусов и полей ───
        $status_map  = $network['status_map'];
        $click_field = $network['api_click_field'] ?? 'subid1';

        // Маппинг полей API → локальные колонки (те же переменные что и в registered-блоке)
        $field_map      = $network['field_map'];
        $fm_payment     = $this->api_field_for('comission', $field_map) ?: 'payment';
        $fm_cart        = $this->api_field_for('sum_order', $field_map) ?: 'cart';
        $fm_uniq_id     = $this->api_field_for('uniq_id', $field_map) ?: 'action_id';
        $fm_order_id    = $this->api_field_for('order_number', $field_map) ?: 'order_id';
        $fm_offer_id    = $this->api_field_for('offer_id', $field_map) ?: 'advcampaign_id';
        $fm_offer_nm    = $this->api_field_for('offer_name', $field_map) ?: 'advcampaign_name';
        $fm_currency    = $this->api_field_for('currency', $field_map) ?: 'currency';
        $fm_action_date = $this->api_field_for('action_date', $field_map) ?: 'action_date';
        $fm_click_time  = $this->api_field_for('click_time', $field_map) ?: 'click_date';
        $fm_action_type = $this->api_field_for('action_type', $field_map) ?: 'action_type';
        $fm_website_id  = $this->api_field_for('website_id', $field_map) ?: 'website_id';

        // ─── Сравнение ───
        $matched       = array();
        $mismatched    = array();
        $missing_local = array();

        $api_sums   = array(
			'approved' => 0.0,
			'pending'  => 0.0,
			'declined' => 0.0,
		);
        $local_sums = array(
			'approved' => 0.0,
			'pending'  => 0.0,
			'declined' => 0.0,
		);

        $matched_click_ids           = array();
        $api_matched_local_click_ids = array();

        foreach ($api_actions as $action) {
            $api_click_id  = (string) ( $action[ $click_field ] ?? '' );
            $api_status    = strtolower($action['status'] ?? 'pending');
            $api_payment   = (float) ( $action[ $fm_payment ] ?? 0 );
            $api_cart      = (float) ( $action[ $fm_cart ] ?? 0 );
            $mapped_status = $status_map[ $api_status ] ?? 'waiting';

            // Подсчёт сумм по API
            if ($mapped_status === 'completed' || $mapped_status === 'balance') {
                $api_sums['approved'] += $api_payment;
            } elseif ($mapped_status === 'waiting') {
                $api_sums['pending'] += $api_payment;
            } elseif ($mapped_status === 'declined') {
                $api_sums['declined'] += $api_payment;
            }

            // ─── МАТЧИНГ ───
            $local_tx = null;

            // 1. Основной ключ: click_id (наш UUID, наиболее надёжный)
            if ($api_click_id !== '' && isset($local_by_click_id[ $api_click_id ])) {
                $local_tx = $local_by_click_id[ $api_click_id ];
            }

            // 2. Fallback: uniq_id (уникальный ID в рамках CPA-сети)
            if (!$local_tx) {
                $action_id_key = (string) ( $action[ $fm_uniq_id ] ?? '' );
                if ($action_id_key !== '' && isset($local_by_uniq_id[ $action_id_key ])) {
                    $local_tx = $local_by_uniq_id[ $action_id_key ];
                }
            }

            if (!$local_tx) {
                $missing_local[] = array(
                    'action_id'   => $action[ $fm_uniq_id ] ?? '',
                    'click_id'    => $api_click_id,
                    'order_id'    => $action[ $fm_order_id ] ?? '',
                    'status'      => $api_status,
                    'payment'     => $api_payment,
                    'cart'        => $api_cart,
                    'date'        => $action[ $fm_action_date ] ?? '',
                    'campaign'    => $action[ $fm_offer_nm ] ?? '',
                    'campaign_id' => $action[ $fm_offer_id ] ?? '',
                    'currency'    => $action[ $fm_currency ] ?? 'RUB',
                    'click_time'  => $action[ $fm_click_time ] ?? $action['click_time'] ?? $action['closing_date'] ?? '',
                    'action_type' => $action[ $fm_action_type ] ?? '',
                    'website_id'  => $action[ $fm_website_id ] ?? $action['website_name'] ?? $action['website'] ?? ( $network['api_website_id'] ?? '' ),
                    'funds_ready' => $this->resolve_funds_ready($action, $field_map),
                );
                continue;
            }

            // Запоминаем что эта локальная транзакция найдена в API (независимо от расхождений)
            if (!empty($local_tx['click_id'])) {
                $api_matched_local_click_ids[ $local_tx['click_id'] ] = true;
            }

            // Запоминаем что эта локальная транзакция сматчена
            if (!empty($local_tx['click_id'])) {
                $matched_click_ids[ $local_tx['click_id'] ] = true;
            }

            // ─── СРАВНЕНИЕ ───
            $local_status     = $local_tx['order_status'];
            $local_commission = (float) $local_tx['comission'];
            $local_cart       = (float) ( $local_tx['sum_order'] ?? 0 );

            // Суммы по локальным
            if ($local_status === 'completed' || $local_status === 'balance') {
                $local_sums['approved'] += $local_commission;
            } elseif ($local_status === 'waiting' || $local_status === 'hold') {
                $local_sums['pending'] += $local_commission;
            } elseif ($local_status === 'declined') {
                $local_sums['declined'] += $local_commission;
            }

            $approved_statuses = array( 'completed', 'balance' );
            $status_match      = ( $local_status === $mapped_status )
                || ( in_array($local_status, $approved_statuses, true)
                    && in_array($mapped_status, $approved_statuses, true) );

            $commission_match = abs($api_payment - $local_commission) < 0.001;

            $cart_match = ( $api_cart == 0 || $local_cart == 0 )
                || abs($api_cart - $local_cart) < 0.001;

            if ($status_match && $commission_match && $cart_match) {
                $matched[] = array(
                    'local_id'         => (int) $local_tx['id'],
                    'click_id'         => $api_click_id,
                    'api_status'       => $api_status,
                    'local_status'     => $local_status,
                    'api_payment'      => $api_payment,
                    'local_commission' => $local_commission,
                );
            } else {
                $mismatched[] = array(
                    'uniq_id'             => $local_tx['uniq_id'] ?? '',
                    'click_id'            => $api_click_id,
                    'local_id'            => $local_tx['id'],
                    'api_status'          => $api_status,
                    'local_status'        => $local_status,
                    'mapped_api_status'   => $mapped_status,
                    'api_payment'         => $api_payment,
                    'local_commission'    => $local_commission,
                    'api_cart'            => $api_cart,
                    'local_cart'          => $local_cart,
                    'status_mismatch'     => !$status_match,
                    'commission_mismatch' => !$commission_match,
                    'cart_mismatch'       => !$cart_match,
                    'action_id'           => $action[ $fm_uniq_id ] ?? '',
                    'order_id'            => $action[ $fm_order_id ] ?? '',
                );

                // Авто-обновляем расхождения — синхронизируем локальные данные с API
                $dummy_updated = 0;
                $dummy_skipped = 0;
                $dummy_errors  = 0;
                $this->sync_update_local(
                    $wpdb,
                    $this->unregistered_table,
                    $local_tx,
                    $mapped_status,
                    $api_payment,
                    $api_cart,
                    $network_slug,
                    $api_click_id,
                    $action,
                    $field_map,
                    $dummy_updated,
                    $dummy_skipped,
                    $dummy_errors
                );
            }
        }

        // ─── Обратная проверка: транзакции есть у нас, но нет в API ───
        $missing_api = array();
        foreach ($local_transactions as $tx) {
            // Пропускаем если транзакция найдена в API (полное совпадение или расхождение — неважно)
            if (!empty($tx['click_id']) && isset($api_matched_local_click_ids[ $tx['click_id'] ])) {
                continue;
            }
            if ($tx['order_status'] === 'balance') {
                continue;
            }
            if (empty($tx['click_id'])) {
                continue;
            }

            $missing_api[] = array(
                'local_id'     => $tx['id'],
                'uniq_id'      => $tx['uniq_id'] ?? '',
                'click_id'     => $tx['click_id'],
                'order_number' => $tx['order_number'],
                'status'       => $tx['order_status'],
                'commission'   => (float) $tx['comission'],
                'sum_order'    => (float) ( $tx['sum_order'] ?? 0 ),
                'created'      => $tx['created_at'],
            );
        }

        // ─── Обновляем api_verified для всех сматченных транзакций ───
        $matched_ids = array_column($matched, 'local_id');
        if (!empty($matched_ids)) {
            foreach (array_chunk($matched_ids, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is array_fill of %d literals; sniff can't see %d inside $placeholders.
                $wpdb->query( $wpdb->prepare( "UPDATE %i SET api_verified = 1 WHERE id IN ({$placeholders}) AND api_verified = 0", $this->unregistered_table, ...$chunk ) );
            }
        }

        // ─── Результат ───
        $has_issues        = !empty($mismatched) || !empty($missing_local) || !empty($missing_api);
        $total_checked     = count($api_actions);
        $validation_status = $has_issues ? 'mismatch' : 'match';
        $discrepancy       = abs($api_sums['approved'] - $local_sums['approved']);

        // Обновляем чекпоинт (user_id = 0 для незарегистрированных)
        $this->update_checkpoint(0, $network_slug, array(
            'last_validated_date'      => ( new DateTime() )->format('Y-m-d'),
            'api_sum_approved'         => $api_sums['approved'],
            'api_sum_pending'          => $api_sums['pending'],
            'api_sum_declined'         => $api_sums['declined'],
            'api_actions_count'        => $total_checked,
            'local_sum_approved'       => $local_sums['approved'],
            'local_sum_pending'        => $local_sums['pending'],
            'local_sum_declined'       => $local_sums['declined'],
            'local_transactions_count' => count($local_transactions),
            'validation_status'        => $validation_status,
            'discrepancy_amount'       => $discrepancy,
            'matched_count'            => count($matched),
            'mismatch_count'           => count($mismatched),
            'missing_local_count'      => count($missing_local),
            'missing_api_count'        => count($missing_api),
        ));

        return array(
            'success'        => true,
            'user_id'        => 0,
            'network'        => $network_slug,
            'status'         => $validation_status,
            'date_range'     => array(
				'start' => $date_start,
				'end'   => $date_end,
			),
            'api_total'      => $total_checked,
            'local_total'    => count($local_transactions),
            'matched_count'  => count($matched),
            'mismatch_count' => count($mismatched),
            'missing_local'  => $missing_local,
            'missing_api'    => $missing_api,
            'mismatched'     => $mismatched,
            'sums'           => array(
                'api_approved'   => $api_sums['approved'],
                'api_pending'    => $api_sums['pending'],
                'api_declined'   => $api_sums['declined'],
                'local_approved' => $local_sums['approved'],
                'local_pending'  => $local_sums['pending'],
                'local_declined' => $local_sums['declined'],
                'discrepancy'    => $discrepancy,
            ),
        );
    }

    // =========================================================================
    // Background sync — обновление статусов через cron
    // =========================================================================

    /**
     * Фоновая синхронизация статусов по всем сетям
     *
     * Матчинг: API.subid1 → DB.click_id
     * Вместо N+1 запросов — загружаем все нужные транзакции одним SELECT
     * и индексируем в PHP.
     *
     * Вызывается через WP Cron каждые 2-4 часа.
     *
     * @return array Результаты синхронизации
     */
    public function background_sync(): array {
        // Защита от параллельного запуска через единый Cashback_Lock
        // (то же имя лока, что и у run_sync в Cashback_API_Cron — ранее здесь был
        // отдельный GET_LOCK с другим именем, что не защищало от гонки cron+manual).
        //
        // Реентерабельность: если вызвано из Cashback_API_Cron::run_sync(), lock
        // уже держится — в этом случае не захватываем и не освобождаем повторно.
        $outer_lock_held = Cashback_Lock::is_lock_held_by_current_process();

        if (!$outer_lock_held) {
            if (!Cashback_Lock::acquire(30)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback background_sync: could not acquire lock, another sync is running');
                return array();
            }
        }

        try {
            return $this->do_background_sync();
        } finally {
            if (!$outer_lock_held) {
                Cashback_Lock::release();
            }
        }
    }

    /**
     * Внутренняя логика фоновой синхронизации (вызывается под GET_LOCK).
     */
    private function do_background_sync(): array {
        global $wpdb;

        $results = array();

        $networks = $this->get_all_active_networks();

        foreach ($networks as $network) {
            $slug   = $network['slug'];
            $config = $this->get_network_config($slug);

            if (!$config || empty($config['credentials'])) {
                continue;
            }

            // Дата последней синхронизации
            $last_sync = get_option("cashback_last_sync_{$slug}", '');

            if (empty($last_sync)) {
                $date_start = ( new DateTime() )->modify('-30 days')->format('d.m.Y');
            } else {
                $dt = new DateTime($last_sync);
                $dt->modify('-1 day');
                $date_start = $dt->format('d.m.Y');
            }

            $date_end = ( new DateTime() )->format('d.m.Y');

            // Запрос по status_updated_start — получаем все действия с обновлёнными статусами
            $sync_params = array(
                'status_updated_start' => $date_start . ' 00:00:00',
                'status_updated_end'   => $date_end . ' 23:59:59',
                'date_start'           => '01.01.2020',
                'date_end'             => $date_end,
            );

            if (!empty($config['api_website_id'])) {
                $sync_params['website'] = $config['api_website_id'];
            }

            $api_result = $this->fetch_all_actions_for_network($slug, $config['credentials'], $sync_params, 20, $config);

            if (!$api_result['success']) {
                $results[ $slug ] = array(
					'success' => false,
					'error'   => $api_result['error'],
				);
                continue;
            }

            $api_actions = $api_result['actions'];

            if (empty($api_actions)) {
                // Проверяем stale транзакции даже если нет свежих обновлений в API
                $decline_result   = $this->decline_stale_missing_transactions($config, $slug);
                $results[ $slug ] = array(
                    'success'               => true,
                    'total'                 => 0,
                    'updated'               => 0,
                    'skipped'               => 0,
                    'not_found'             => 0,
                    'inserted'              => 0,
                    'insert_errors'         => 0,
                    'declined_stale'        => ( $decline_result['declined_registered'] + $decline_result['declined_unregistered'] ),
                    'declined_stale_detail' => $decline_result,
                );
                update_option("cashback_last_sync_{$slug}", ( new DateTime() )->format('Y-m-d H:i:s'));
                continue;
            }

            // ─── Загружаем ВСЕ нужные локальные транзакции одним запросом ───
            $click_field  = $config['api_click_field'] ?? 'subid1';
            $network_name = $config['name'] ?? $slug;

            // Маппинг полей API → локальные колонки
            $field_map  = $config['field_map'];
            $fm_payment = $this->api_field_for('comission', $field_map) ?: 'payment';
            $fm_cart    = $this->api_field_for('sum_order', $field_map) ?: 'cart';
            $fm_uniq_id = $this->api_field_for('uniq_id', $field_map) ?: 'action_id';

            // Собираем click_id и action_id из API-ответа
            $api_click_ids  = array();
            $api_action_ids = array();
            foreach ($api_actions as $action) {
                $cid = (string) ( $action[ $click_field ] ?? '' );
                if ($cid !== '') {
                    $api_click_ids[] = $cid;
                }
                $aid = (string) ( $action[ $fm_uniq_id ] ?? '' );
                if ($aid !== '') {
                    $api_action_ids[] = $aid;
                }
            }

            // ─── Batch-запросы: cashback_transactions ───
            // 1. По click_id (наш UUID)
            $local_map_by_click = array();
            if (!empty($api_click_ids)) {
                $placeholders = implode(',', array_fill(0, count($api_click_ids), '%s'));
                $query_args   = array_merge(array( $this->transactions_table ), $api_click_ids, array( $slug, $network_name ));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is array_fill of %s literals; sniff can't see %s inside $placeholders.
                $rows = $wpdb->get_results($wpdb->prepare("SELECT id, click_id, uniq_id, order_status, comission, sum_order, api_verified FROM %i WHERE click_id IN ({$placeholders}) AND (LOWER(partner) = LOWER(%s) OR LOWER(partner) = LOWER(%s))", ...$query_args), ARRAY_A);

                foreach ($rows as $row) {
                    $local_map_by_click[ $row['click_id'] ] = $row;
                }
            }

            // 2. По uniq_id (уникальный action_id CPA-сети, надёжный fallback)
            $local_map_by_uniq = array();
            if (!empty($api_action_ids)) {
                $placeholders = implode(',', array_fill(0, count($api_action_ids), '%s'));
                $query_args   = array_merge(array( $this->transactions_table ), $api_action_ids, array( $slug, $network_name ));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is array_fill of %s literals; sniff can't see %s inside $placeholders.
                $rows = $wpdb->get_results($wpdb->prepare("SELECT id, click_id, uniq_id, order_status, comission, sum_order, api_verified FROM %i WHERE uniq_id IN ({$placeholders}) AND (LOWER(partner) = LOWER(%s) OR LOWER(partner) = LOWER(%s))", ...$query_args), ARRAY_A);

                foreach ($rows as $row) {
                    if (!isset($local_map_by_uniq[ $row['uniq_id'] ])) {
                        $local_map_by_uniq[ $row['uniq_id'] ] = $row;
                    }
                }
            }

            // ─── Batch-запросы: cashback_unregistered_transactions ───
            // 1. По click_id
            $unreg_map_by_click = array();
            if (!empty($api_click_ids)) {
                $placeholders = implode(',', array_fill(0, count($api_click_ids), '%s'));
                $query_args   = array_merge(array( $this->unregistered_table ), $api_click_ids, array( $slug, $network_name ));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is array_fill of %s literals; sniff can't see %s inside $placeholders.
                $rows = $wpdb->get_results($wpdb->prepare("SELECT id, click_id, uniq_id, order_status, comission, sum_order, user_id, api_verified FROM %i WHERE click_id IN ({$placeholders}) AND (LOWER(partner) = LOWER(%s) OR LOWER(partner) = LOWER(%s))", ...$query_args), ARRAY_A);

                foreach ($rows as $row) {
                    $unreg_map_by_click[ $row['click_id'] ] = $row;
                }
            }

            // 2. По uniq_id
            $unreg_map_by_uniq = array();
            if (!empty($api_action_ids)) {
                $placeholders = implode(',', array_fill(0, count($api_action_ids), '%s'));
                $query_args   = array_merge(array( $this->unregistered_table ), $api_action_ids, array( $slug, $network_name ));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is array_fill of %s literals; sniff can't see %s inside $placeholders.
                $rows = $wpdb->get_results($wpdb->prepare("SELECT id, click_id, uniq_id, order_status, comission, sum_order, user_id, api_verified FROM %i WHERE uniq_id IN ({$placeholders}) AND (LOWER(partner) = LOWER(%s) OR LOWER(partner) = LOWER(%s))", ...$query_args), ARRAY_A);

                foreach ($rows as $row) {
                    if (!isset($unreg_map_by_uniq[ $row['uniq_id'] ])) {
                        $unreg_map_by_uniq[ $row['uniq_id'] ] = $row;
                    }
                }
            }

            // ─── Batch-проверка существования пользователей для INSERT ───
            // subid может содержать partner_token (hex, 32 chars) или legacy user_id (numeric)
            $user_field         = $config['api_user_field'] ?? 'subid';
            $potential_user_ids = array();
            $potential_tokens   = array();

            foreach ($api_actions as $action) {
                $cid = (string) ( $action[ $click_field ] ?? '' );

                // Проверяем, найдётся ли action в одной из таблиц
                $aid_check   = (string) ( $action[ $fm_uniq_id ] ?? '' );
                $would_match = ( $cid !== '' && ( isset($local_map_by_click[ $cid ]) || isset($unreg_map_by_click[ $cid ]) ) )
                    || ( $aid_check !== '' && ( isset($local_map_by_uniq[ $aid_check ]) || isset($unreg_map_by_uniq[ $aid_check ]) ) );

                if (!$would_match) {
                    $uid = (string) ( $action[ $user_field ] ?? '' );
                    if (is_numeric($uid) && (int) $uid > 0) {
                        $potential_user_ids[] = (int) $uid;
                    } elseif (preg_match('/^[0-9a-f]{32}$/', $uid)) {
                        $potential_tokens[] = $uid;
                    }
                }
            }

            // Batch-резолв partner_token → user_id
            $token_to_user = array();
            if (!empty($potential_tokens)) {
                $token_to_user = Mariadb_Plugin::resolve_partner_tokens_batch($potential_tokens);
                foreach ($token_to_user as $resolved_uid) {
                    $potential_user_ids[] = $resolved_uid;
                }
            }

            $existing_user_ids = array();
            if (!empty($potential_user_ids)) {
                $potential_user_ids = array_unique($potential_user_ids);
                $placeholders       = implode(',', array_fill(0, count($potential_user_ids), '%d'));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is array_fill of %d literals; sniff can't see %d inside $placeholders.
                $rows              = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE ID IN ({$placeholders})", $wpdb->users, ...$potential_user_ids ) );
                $existing_user_ids = array_flip(array_map('intval', $rows));
            }

            // ─── Обработка actions ───
            $status_map    = $config['status_map'];
            $updated       = 0;
            $skipped       = 0;
            $update_errors = 0;
            $not_found     = 0;
            $inserted      = 0;
            $insert_errors = 0;

            foreach ($api_actions as $action) {
                $api_click_id  = (string) ( $action[ $click_field ] ?? '' );
                $api_status    = strtolower($action['status'] ?? 'pending');
                $mapped_status = $status_map[ $api_status ] ?? 'waiting';
                $api_payment   = (float) ( $action[ $fm_payment ] ?? 0 );
                $api_cart      = (float) ( $action[ $fm_cart ] ?? 0 );

                // ─── Матчинг: cashback_transactions ───
                $local = null;

                // 1. Основной: click_id (наш UUID)
                if ($api_click_id !== '' && isset($local_map_by_click[ $api_click_id ])) {
                    $local = $local_map_by_click[ $api_click_id ];
                }

                // 2. Fallback: uniq_id (уникальный ID в рамках CPA-сети)
                if (!$local) {
                    $action_id_key = (string) ( $action[ $fm_uniq_id ] ?? '' );
                    if ($action_id_key !== '' && isset($local_map_by_uniq[ $action_id_key ])) {
                        $local = $local_map_by_uniq[ $action_id_key ];
                    }
                }

                // ─── Если найдено в cashback_transactions — обновляем ───
                if ($local) {
                    $this->sync_update_local($wpdb, $this->transactions_table, $local, $mapped_status, $api_payment, $api_cart, $slug, $api_click_id, $action, $field_map, $updated, $skipped, $update_errors);
                    continue;
                }

                // ─── Матчинг: cashback_unregistered_transactions ───
                $unreg = null;

                // 1. Основной: click_id
                if ($api_click_id !== '' && isset($unreg_map_by_click[ $api_click_id ])) {
                    $unreg = $unreg_map_by_click[ $api_click_id ];
                }

                // 2. Fallback: uniq_id
                if (!$unreg) {
                    $action_id_key = (string) ( $action[ $fm_uniq_id ] ?? '' );
                    if ($action_id_key !== '' && isset($unreg_map_by_uniq[ $action_id_key ])) {
                        $unreg = $unreg_map_by_uniq[ $action_id_key ];
                    }
                }

                // ─── Если найдено в unregistered — обновляем ───
                if ($unreg) {
                    $this->sync_update_local($wpdb, $this->unregistered_table, $unreg, $mapped_status, $api_payment, $api_cart, $slug, $api_click_id, $action, $field_map, $updated, $skipped, $update_errors);
                    continue;
                }

                // ─── Guard: cross-table UNIQUE KEY check ───
                // Защита от дубликатов для перенесённых транзакций (click_id=NULL случай):
                // если батч-карты пропустили строку, последний шанс найти её по UNIQUE KEY (uniq_id, partner).
                $action_id_guard = (string) ( $action[ $fm_uniq_id ] ?? '' );
                if ($action_id_guard !== '') {
                    $transferred = $wpdb->get_row($wpdb->prepare(
                        'SELECT id, click_id, uniq_id, order_status, comission, sum_order, api_verified
                         FROM %i
                         WHERE uniq_id = %s
                           AND (partner = LOWER(%s) OR partner = LOWER(%s))
                         LIMIT 1',
                        $this->transactions_table,
                        $action_id_guard,
                        $slug,
                        $network_name
                    ), ARRAY_A);

                    if ($transferred) {
                        $this->sync_update_local($wpdb, $this->transactions_table, $transferred, $mapped_status, $api_payment, $api_cart, $slug, $api_click_id, $action, $field_map, $updated, $skipped, $update_errors);
                        continue;
                    }
                }

                // ─── Не найдено нигде: INSERT новой транзакции ───
                $insert_result = $this->insert_missing_transaction($action, $config, $slug, $wpdb, $existing_user_ids);

                if ($insert_result['success']) {
                    ++$inserted;

                    $this->log_sync_insert(
                        $slug,
                        $insert_result['insert_id'],
                        (string) ( $action[ $fm_uniq_id ] ?? '' ),
                        $mapped_status,
                        $api_payment
                    );
                } elseif (strpos($insert_result['error'], 'Duplicate') !== false) {
                    // Дубликат — не ошибка, транзакция уже есть
                    ++$skipped;
                } else {
                    ++$insert_errors;
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log(sprintf(
                            '[Cashback Sync] Insert failed for action_id=%s: %s',
                            $action[ $fm_uniq_id ] ?? 'unknown',
                            $insert_result['error']
                        ));
                    }
                }
            }

            // ─── Auto-decline stale транзакций, отсутствующих в API ───
            $decline_result = $this->decline_stale_missing_transactions($config, $slug);

            // Сохраняем время последней синхронизации
            update_option("cashback_last_sync_{$slug}", ( new DateTime() )->format('Y-m-d H:i:s'));

            $results[ $slug ] = array(
                'success'               => true,
                'total'                 => count($api_actions),
                'updated'               => $updated,
                'skipped'               => $skipped,
                'update_errors'         => $update_errors,
                'not_found'             => $not_found,
                'inserted'              => $inserted,
                'insert_errors'         => $insert_errors,
                'declined_stale'        => ( $decline_result['declined_registered'] + $decline_result['declined_unregistered'] ),
                'declined_stale_detail' => $decline_result,
            );
        }

        return $results;
    }

    // =========================================================================
    // Background sync — helper methods
    // =========================================================================

    /**
     * Обновить локальную транзакцию при синхронизации
     *
     * Общая логика для cashback_transactions и cashback_unregistered_transactions.
     * Защиты: skip balance, skip downgrade completed → waiting.
     *
     * Атомарность (Group 8 Step 1, F-8-001):
     *   - Оборачивает UPDATE в START TRANSACTION;
     *   - берёт SELECT ... FOR UPDATE по id перед guard'ами, чтобы проверки
     *     работали на committed-состоянии, а не на stale $local;
     *   - ретраит 3× на deadlock / lock wait timeout;
     *   - семантика приоритета API vs админ-правок не меняется —
     *     существующие guard'ы (balance / completed→waiting /
     *     validate_status_transition) просто видят свежую строку.
     *
     * @param wpdb   $wpdb
     * @param string $table          Таблица для UPDATE
     * @param array  $local          Локальная запись (id, order_status, comission, sum_order)
     * @param string $mapped_status  Статус из API после маппинга
     * @param float  $api_payment    Комиссия из API
     * @param float  $api_cart       Сумма заказа из API
     * @param string $slug           Slug сети
     * @param string $api_click_id   Click ID из API
     * @param array  $action         Полный action из API
     * @param int    &$updated       Счётчик обновлённых (по ссылке)
     * @param int    &$skipped       Счётчик пропущенных (по ссылке)
     * @param int    &$update_errors Счётчик ошибок UPDATE (по ссылке)
     * @param bool   $in_transaction Если true — caller сам владеет TX (COMMIT/ROLLBACK/retry не делаем)
     */
    private function sync_update_local(
        \wpdb $wpdb,
        string $table,
        array $local,
        string $mapped_status,
        float $api_payment,
        float $api_cart,
        string $slug,
        string $api_click_id,
        array $action,
        array $field_map,
        int &$updated,
        int &$skipped,
        int &$update_errors,
        bool $in_transaction = false
    ): void {
        $owns_tx = ! $in_transaction;

        $apply = function () use (
            $wpdb,
            $table,
            $local,
            $mapped_status,
            $api_payment,
            $api_cart,
            $slug,
            $api_click_id,
            $action,
            $field_map,
            $owns_tx,
            &$updated,
            &$skipped,
            &$update_errors
        ): void {
            if ($owns_tx) {
                $wpdb->query('START TRANSACTION');
            }

            try {
                // Перечитываем строку под локом — guard'ы работают на committed-состоянии.
                $fresh = $wpdb->get_row(
                    $wpdb->prepare(
                        'SELECT * FROM %i WHERE id = %d FOR UPDATE',
                        $table,
                        (int) $local['id']
                    ),
                    ARRAY_A
                );

                if ($wpdb->last_error) {
                    $this->throw_if_deadlock($wpdb);
                    ++$update_errors;
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log(sprintf(
                        '[Cashback Sync] SELECT FOR UPDATE error for %s id=%d: %s',
                        $table,
                        (int) $local['id'],
                        $wpdb->last_error
                    ));
                    if ($owns_tx) {
                        $wpdb->query('ROLLBACK');
                    }
                    return;
                }

                if (! $fresh) {
                    // Строка пропала (удалена админом / другим cron) — считаем skip.
                    ++$skipped;
                    if ($owns_tx) {
                        $wpdb->query('COMMIT');
                    }
                    return;
                }

                $local_status = (string) $fresh['order_status'];

                // Защита: balance — финальный, не трогаем
                if ($local_status === 'balance') {
                    ++$skipped;
                    if ($owns_tx) {
                        $wpdb->query('COMMIT');
                    }
                    return;
                }

                // Защита от понижения: completed не откатываем в waiting
                if ($local_status === 'completed' && $mapped_status === 'waiting') {
                    ++$skipped;
                    if ($owns_tx) {
                        $wpdb->query('COMMIT');
                    }
                    return;
                }

                // Обновляем если статус, комиссия или сумма заказа изменились
                $status_changed     = ( $local_status !== $mapped_status );
                $commission_changed = abs($api_payment - (float) $fresh['comission']) >= 0.001;

                $local_cart   = (float) ( $fresh['sum_order'] ?? 0 );
                $cart_changed = abs($api_cart - $local_cart) >= 0.001;

                $needs_verify = empty($fresh['api_verified']);

                // funds_ready: определяется через маппинг, fallback — прямое чтение из адаптера
                $api_funds_ready   = $this->resolve_funds_ready($action, $field_map);
                $needs_funds_ready = ( $api_funds_ready === 1 && empty($fresh['funds_ready']) );

                if (! $status_changed && ! $commission_changed && ! $cart_changed && ! $needs_verify && ! $needs_funds_ready) {
                    ++$skipped;
                    if ($owns_tx) {
                        $wpdb->query('COMMIT');
                    }
                    return;
                }

                $update_data    = array();
                $update_formats = array();

                if ($status_changed) {
                    $update_data['order_status'] = $mapped_status;
                    $update_formats[]            = '%s';
                }

                if ($commission_changed) {
                    $update_data['comission'] = $api_payment;
                    $update_formats[]         = '%s';
                }

                if ($cart_changed) {
                    $update_data['sum_order'] = $api_cart;
                    $update_formats[]         = '%s';
                }

                // Транзакция найдена в API — помечаем как проверенную
                if ($needs_verify) {
                    $update_data['api_verified'] = 1;
                    $update_formats[]            = '%d';
                }

                // CPA-сеть подтвердила готовность средств к снятию — обновляем funds_ready
                // Только нарастающее: 0→1, обратно не сбрасываем
                if ($needs_funds_ready) {
                    $update_data['funds_ready'] = 1;
                    $update_formats[]           = '%d';
                }

                // PHP-фолбэк: валидация перехода статуса
                if ($status_changed) {
                    $validation = Cashback_Trigger_Fallbacks::validate_status_transition($local_status, $mapped_status);
                    if ($validation !== true) {
                        ++$skipped;
                        if ($owns_tx) {
                            $wpdb->query('COMMIT');
                        }
                        return;
                    }
                }

                // PHP-фолбэк: пересчёт кешбэка при изменении комиссии
                if ($commission_changed) {
                    $is_registered = ( $table === $this->transactions_table );
                    $old_row       = (object) $fresh;
                    Cashback_Trigger_Fallbacks::recalculate_cashback_on_update($update_data, $old_row, $is_registered);
                    if (isset($update_data['cashback'])) {
                        $update_formats[] = '%f'; // cashback
                    }
                }

                $wpdb->update(
                    $table,
                    $update_data,
                    array( 'id' => (int) $fresh['id'] ),
                    $update_formats,
                    array( '%d' )
                );

                $update_err = (string) $wpdb->last_error;
                if ($update_err !== '') {
                    $this->throw_if_deadlock($wpdb);
                    ++$update_errors;
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log(sprintf(
                        '[Cashback Sync] UPDATE error for %s id=%d: %s',
                        $table,
                        (int) $fresh['id'],
                        $update_err
                    ));
                    if ($owns_tx) {
                        $wpdb->query('ROLLBACK');
                    }
                    return;
                }

                ++$updated;

                // Запись в очередь уведомлений (вместо MySQL триггера)
                $this->enqueue_notification_on_update(
                    $wpdb,
                    (int) $fresh['id'],
                    (int) $fresh['user_id'],
                    $local_status,
                    $mapped_status,
                    $status_changed,
                    $commission_changed,
                    $cart_changed,
                    $fresh,
                    $update_data
                );

                $this->log_sync_event(
                    $slug,
                    (int) $fresh['id'],
                    $api_click_id ?: ( $fresh['uniq_id'] ?? '' ),
                    $local_status,
                    $mapped_status,
                    $api_payment
                );

                if ($owns_tx) {
                    $wpdb->query('COMMIT');
                }
            } catch (\Throwable $e) {
                if ($owns_tx) {
                    $wpdb->query('ROLLBACK');
                }
                throw $e;
            }
        };

        if ($owns_tx) {
            $this->retry_on_sync_deadlock($apply, 3);
        } else {
            // Внешний TX: ретраим решение оставляем caller'у.
            $apply();
        }
    }

    /**
     * Выбрасывает RuntimeException, если $wpdb->last_error — deadlock / lock wait timeout.
     *
     * Вызывается после каждого wpdb-вызова внутри sync_update_local. На обычные
     * SQL-ошибки не реагирует — те обрабатываются штатно (++update_errors + ROLLBACK).
     */
    private function throw_if_deadlock( \wpdb $wpdb ): void {
        $err = (string) $wpdb->last_error;
        if ($err === '') {
            return;
        }

        $is_deadlock = ( stripos($err, 'deadlock') !== false )
            || ( stripos($err, 'lock wait timeout') !== false )
            || ( strpos($err, '1213') !== false )
            || ( strpos($err, '1205') !== false );

        if ($is_deadlock) {
            throw new \RuntimeException(
                esc_html( sprintf('[Cashback Sync] deadlock/lock-wait: %s', $err) )
            );
        }
    }

    /**
     * Ретраит переданный callable до $max попыток при deadlock / lock wait timeout.
     *
     * Линейный back-off (50 мс × номер попытки). Не-deadlock исключения
     * пробрасываются сразу.
     *
     * @param callable $callback Замыкание с SQL-операцией (должно само открыть/закрыть TX).
     * @param int      $max      Максимальное число попыток (включая первую).
     */
    private function retry_on_sync_deadlock( callable $callback, int $max = 3 ): void {
        $attempt = 0;
        while (true) {
            try {
                $callback();
                return;
            } catch (\Throwable $e) {
                $msg         = (string) $e->getMessage();
                $is_deadlock = ( stripos($msg, 'deadlock') !== false )
                    || ( stripos($msg, 'lock wait timeout') !== false )
                    || ( strpos($msg, '1213') !== false )
                    || ( strpos($msg, '1205') !== false );
                if (! $is_deadlock || ++$attempt >= $max) {
                    throw $e;
                }
                // Линейный back-off: 50 мс, 100 мс.
                usleep(50000 * $attempt);
            }
        }
    }

    /**
     * Вставить отсутствующую транзакцию из API в локальную БД
     *
     * Определяет user_id из action, выбирает таблицу (registered / unregistered),
     * проверяет существование пользователя, формирует данные и вставляет.
     * Триггеры calculate_cashback_before_insert автоматически рассчитают cashback.
     *
     * @param array  $action             API action данные
     * @param array  $config             Конфигурация сети
     * @param string $slug               Slug сети
     * @param wpdb   $wpdb              WordPress DB
     * @param array  $existing_user_ids  Массив существующих user_id (из batch-проверки)
     * @return array ['success' => bool, 'insert_id' => int, 'table_type' => string, 'error' => string]
     */
    private function insert_missing_transaction(
        array $action,
        array $config,
        string $slug,
        \wpdb $wpdb,
        array $existing_user_ids
    ): array {
        $user_field   = $config['api_user_field'] ?? 'subid';
        $click_field  = $config['api_click_field'] ?? 'subid1';
        $status_map   = $config['status_map'] ?? array();
        $network_name = $config['name'] ?? $slug;

        // 1. Определяем user_id (subid может содержать partner_token или legacy user_id)
        $raw_user_id = (string) ( $action[ $user_field ] ?? '' );

        $is_unregistered = strtolower($raw_user_id) === 'unregistered'
            || $raw_user_id === ''
            || $raw_user_id === '0';

        // 1a. Попытка разрешить partner_token → user_id (новый формат)
        if (!$is_unregistered && !is_numeric($raw_user_id)) {
            $resolved_user_id = Mariadb_Plugin::resolve_partner_token($raw_user_id);
            if ($resolved_user_id !== null) {
                $raw_user_id = (string) $resolved_user_id;
            } else {
                // Не числовой и не валидный токен → unregistered
                $is_unregistered = true;
            }
        }

        // 2. Для зарегистрированных — проверяем существование WP-пользователя
        if (!$is_unregistered && is_numeric($raw_user_id)) {
            if (!isset($existing_user_ids[ (int) $raw_user_id ])) {
                $is_unregistered = true;
            }
        }

        // 2a. Фикс B: если всё ещё unregistered, но click_id известен —
        //     проверяем, была ли предыдущая конверсия с тем же click_id уже перенесена
        //     к реальному пользователю. Если да — новую покупку кладём туда же.
        $click_id_early = (string) ( $action[ $click_field ] ?? '' );
        if ($is_unregistered && $click_id_early !== '') {
            $prior = $wpdb->get_row($wpdb->prepare(
                'SELECT user_id FROM %i
                 WHERE click_id = %s LIMIT 1',
                $this->transactions_table,
                $click_id_early
            ), ARRAY_A);

            if ($prior && (int) $prior['user_id'] > 0) {
                $raw_user_id     = (string) (int) $prior['user_id'];
                $is_unregistered = false;
            }
        }

        // 3. Целевая таблица
        $table      = $is_unregistered ? $this->unregistered_table : $this->transactions_table;
        $table_type = $is_unregistered ? 'unregistered' : 'transactions';

        // 4. Маппинг статуса
        $api_status    = strtolower($action['status'] ?? 'pending');
        $mapped_status = $status_map[ $api_status ] ?? 'waiting';

        // 5. Извлекаем поля через маппинг
        $field_map = $config['field_map'];
        $mapped    = $this->apply_field_map($action, $field_map);

        // 6. Парсим даты (через маппинг)
        $fm_action_date    = $this->api_field_for('action_date', $field_map) ?: 'action_date';
        $fm_click_time     = $this->api_field_for('click_time', $field_map) ?: 'click_date';
        $action_date_mysql = self::parse_api_date((string) ( $action[ $fm_action_date ] ?? '' ));
        $click_time_raw    = (string) ( $action[ $fm_click_time ] ?? $action['click_time'] ?? $action['closing_date'] ?? '' );
        $click_time_mysql  = self::parse_api_date($click_time_raw);

        $action_id   = (string) ( $mapped['uniq_id'] ?? '' );
        $click_id    = (string) ( $action[ $click_field ] ?? '' );
        $order_id    = (string) ( $mapped['order_number'] ?? '' );
        $payment     = (float) ( $mapped['comission'] ?? 0 );
        $cart        = (float) ( $mapped['sum_order'] ?? 0 );
        $campaign    = (string) ( $mapped['offer_name'] ?? '' );
        $campaign_id = $mapped['offer_id'] ?? null;
        $currency    = (string) ( $mapped['currency'] ?? $action['currency'] ?? 'RUB' );
        $action_type = (string) ( $mapped['action_type'] ?? $action['action_type'] ?? '' );
        $website_id  = (string) ( $mapped['website_id'] ?? $action['website_id'] ?? $action['website'] ?? ( $config['api_website_id'] ?? '' ) );

        // 7. Валидация валюты (ISO 4217)
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'RUB';
        }

        // 7a. funds_ready: через маппинг, fallback — адаптер
        $api_funds_ready = $this->resolve_funds_ready($action, $field_map);

        // 8. action_id и network_name обязательны (части UNIQUE KEY unique_uniq_partner)
        if ($action_id === '') {
            return array(
				'success'    => false,
				'insert_id'  => 0,
				'table_type' => $table_type,
				'error'      => 'Missing action_id',
			);
        }
        if (empty($network_name)) {
            return array(
				'success'    => false,
				'insert_id'  => 0,
				'table_type' => $table_type,
				'error'      => 'Missing network name (partner)',
			);
        }

        // 9. Ключ идемпотентности (детерминистический — один action_id+slug = один ключ)
        $idempotency_key = hash('sha256', 'cron_sync_' . $action_id . '_' . $slug);

        // 10. Формируем данные для INSERT
        $data = array(
            'user_id'         => $is_unregistered ? $raw_user_id : (int) $raw_user_id,
            'uniq_id'         => $action_id,
            'order_number'    => $order_id,
            'partner'         => $network_name,
            'comission'       => $payment,
            'sum_order'       => $cart,
            'order_status'    => $mapped_status,
            'offer_id'        => ( $campaign_id !== null && $campaign_id !== '' && $campaign_id !== 0 ) ? (int) $campaign_id : null,
            'offer_name'      => $campaign,
            'currency'        => $currency,
            'action_date'     => $action_date_mysql,
            'click_time'      => $click_time_mysql,
            'click_id'        => $click_id !== '' ? $click_id : null,
            'website_id'      => ( $website_id !== '' && $website_id !== '0' ) ? (int) $website_id : null,
            'action_type'     => $action_type !== '' ? $action_type : null,
            'api_verified'    => 1,
            'funds_ready'     => $api_funds_ready,
            'idempotency_key' => $idempotency_key,
        );

        $formats = array(
            $is_unregistered ? '%s' : '%d',  // user_id
            '%s',  // uniq_id
            '%s',  // order_number
            '%s',  // partner
            '%f',  // comission
            '%f',  // sum_order
            '%s',  // order_status
            '%d',  // offer_id
            '%s',  // offer_name
            '%s',  // currency
            '%s',  // action_date
            '%s',  // click_time
            '%s',  // click_id
            '%d',  // website_id
            '%s',  // action_type
            '%d',  // api_verified
            '%d',  // funds_ready
            '%s',  // idempotency_key
        );

        // 11. PHP-фолбэк расчёта кешбэка (идемпотентен при наличии триггеров)
        Cashback_Trigger_Fallbacks::calculate_cashback($data, !$is_unregistered);
        $formats[] = '%f'; // applied_cashback_rate
        $formats[] = '%f'; // cashback

        // 12. Убираем NULL-значения (аналогично ajax_add_transaction)
        $clean_data    = array();
        $clean_formats = array();
        $i             = 0;
        foreach ($data as $key => $value) {
            if ($value !== null) {
                $clean_data[ $key ] = $value;
                $clean_formats[]    = $formats[ $i ];
            }
            ++$i;
        }

        // 13. INSERT (UNIQUE KEY на uniq_id+partner защищает от дубликатов)
        $result = $wpdb->insert($table, $clean_data, $clean_formats);

        if ($result === false || $wpdb->last_error) {
            $error = $wpdb->last_error;
            return array(
				'success'    => false,
				'insert_id'  => 0,
				'table_type' => $table_type,
				'error'      => $error ?: 'Unknown insert error',
			);
        }

        $insert_id = (int) $wpdb->insert_id;

        // Запись в очередь уведомлений (вместо MySQL триггера)
        if ($table_type === 'transactions') {
            $user_id_int = (int) ( $data['user_id'] ?? 0 );
            if ($user_id_int > 0) {
                $wpdb->insert(
                    $wpdb->prefix . 'cashback_notification_queue',
                    array(
                        'event_type'     => 'transaction_new',
                        'transaction_id' => $insert_id,
                        'user_id'        => $user_id_int,
                        'new_status'     => $data['order_status'] ?? 'waiting',
                    ),
                    array( '%s', '%d', '%d', '%s' )
                );
            }
        }

        return array(
			'success'    => true,
			'insert_id'  => $insert_id,
			'table_type' => $table_type,
			'error'      => '',
		);
    }

    /**
     * Залогировать событие INSERT в cashback_sync_log
     *
     * @param string $network_slug
     * @param int    $transaction_id ID вставленной записи
     * @param string $action_id     ID действия из API
     * @param string $status        Статус вставленной транзакции
     * @param float  $api_payment   Комиссия из API
     */
    private function log_sync_insert(
        string $network_slug,
        int $transaction_id,
        string $action_id,
        string $status,
        float $api_payment
    ): void {
        global $wpdb;

        $wpdb->insert($this->sync_log_table, array(
            'network_slug'   => $network_slug,
            'transaction_id' => $transaction_id,
            'action_id'      => $action_id,
            'old_status'     => 'not_found',
            'new_status'     => $status,
            'api_payment'    => $api_payment,
            'sync_type'      => 'cron',
            'synced_at'      => current_time('mysql'),
        ));
    }

    // =========================================================================
    // Auto-decline stale transactions missing from API
    // =========================================================================

    /**
     * Автоматическое отклонение устаревших транзакций, отсутствующих в API
     *
     * Находит транзакции со статусами 'waiting'/'hold', у которых:
     *   - updated_at старше 5 дней
     *   - есть click_id (для сверки с API)
     *   - partner совпадает с сетью
     * Затем запрашивает API за полный диапазон дат (без status_updated фильтра)
     * и отклоняет те, что не найдены в API.
     *
     * Безопасность:
     *   - НИКОГДА не трогает 'balance' (финальный, защищён триггером БД)
     *   - Проверяет 'waiting', 'hold' и 'completed' с updated_at > 5 дней
     *   - Каждое изменение логируется в cashback_sync_log с sync_type='auto_decline'
     *
     * @param array  $config Конфигурация сети (из get_network_config)
     * @param string $slug   Slug сети
     * @return array ['declined_registered' => int, 'declined_unregistered' => int, 'checked' => int, 'error' => string|null]
     */
    public function decline_stale_missing_transactions( array $config, string $slug ): array {
        global $wpdb;

        $result = array(
            'declined_registered'   => 0,
            'declined_unregistered' => 0,
            'checked'               => 0,
            'error'                 => null,
        );

        if (empty($config['credentials'])) {
            return $result;
        }

        $network_name   = $config['name'] ?? $slug;
        $stale_interval = 5; // дней

        // ─── 1. Найти устаревшие транзакции в обеих таблицах ───

        $stale_registered = $wpdb->get_results($wpdb->prepare(
            "SELECT id, click_id, order_number, order_status, comission, created_at, updated_at
             FROM %i
             WHERE order_status IN ('waiting', 'hold', 'completed')
               AND click_id IS NOT NULL AND click_id != ''
               AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)
               AND (LOWER(partner) = LOWER(%s) OR LOWER(partner) = LOWER(%s))
             ORDER BY created_at ASC",
            $this->transactions_table,
            $stale_interval,
            $slug,
            $network_name
        ), ARRAY_A);

        $stale_unregistered = $wpdb->get_results($wpdb->prepare(
            "SELECT id, click_id, order_number, order_status, comission, created_at, updated_at
             FROM %i
             WHERE order_status IN ('waiting', 'hold', 'completed')
               AND click_id IS NOT NULL AND click_id != ''
               AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)
               AND (LOWER(partner) = LOWER(%s) OR LOWER(partner) = LOWER(%s))
             ORDER BY created_at ASC",
            $this->unregistered_table,
            $stale_interval,
            $slug,
            $network_name
        ), ARRAY_A);

        $all_stale = array_merge($stale_registered, $stale_unregistered);

        if (empty($all_stale)) {
            return $result;
        }

        $result['checked'] = count($all_stale);

        // ─── 2. Определить диапазон дат для API-запроса ───

        $earliest_date = null;
        foreach ($all_stale as $tx) {
            $created = $tx['created_at'];
            if ($earliest_date === null || $created < $earliest_date) {
                $earliest_date = $created;
            }
        }

        $dt_start = new DateTime($earliest_date);
        $dt_start->modify('-1 day');
        $date_start = $dt_start->format('d.m.Y');
        $date_end   = ( new DateTime() )->format('d.m.Y');

        // ─── 3. Запросить API (полный диапазон, без status_updated фильтра) ───

        $api_params = array(
            'date_start' => $date_start,
            'date_end'   => $date_end,
        );

        if (!empty($config['api_website_id'])) {
            $api_params['website'] = $config['api_website_id'];
        }

        $max_pages  = 20;
        $page_limit = 500;
        $api_result = $this->fetch_all_actions_for_network(
            $slug,
            $config['credentials'],
            $api_params,
            $max_pages,
            $config
        );

        if (!$api_result['success']) {
            $result['error'] = 'API error during stale check: ' . $api_result['error'];
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Auto-Decline] ' . $result['error']);
            return $result;
        }

        $api_actions_list = $api_result['actions'];

        // ─── 4. Построить индекс API actions по click_id и order_id ───

        $click_field   = $config['api_click_field'] ?? 'subid1';
        $api_click_ids = array();
        $api_order_ids = array();

        $fm_order_id_stale = $this->api_field_for('order_number', $config['field_map']) ?: 'order_id';

        foreach ($api_actions_list as $action) {
            $cid = (string) ( $action[ $click_field ] ?? '' );
            if ($cid !== '') {
                $api_click_ids[ $cid ] = true;
            }
            $oid = (string) ( $action[ $fm_order_id_stale ] ?? '' );
            if ($oid !== '') {
                $api_order_ids[ $oid ] = true;
            }
        }

        // ─── 5. Защита пагинации ───
        // Если API вернул >= лимита пагинации, данные могут быть неполными.
        // Не отклоняем транзакции с created_at старше самой ранней API-записи.
        $pagination_limit_hit = ( count($api_actions_list) >= $max_pages * $page_limit );
        $earliest_api_date    = null;

        if ($pagination_limit_hit && !empty($api_actions_list)) {
            foreach ($api_actions_list as $a) {
                $ad     = (string) ( $a['action_date'] ?? $a['click_time'] ?? '' );
                $parsed = self::parse_api_date($ad);
                if ($parsed !== null && ( $earliest_api_date === null || $parsed < $earliest_api_date )) {
                    $earliest_api_date = $parsed;
                }
            }
        }

        // ─── 6. Определить какие stale транзакции отсутствуют в API ───

        $to_decline_registered   = array();
        $to_decline_unregistered = array();

        foreach ($stale_registered as $tx) {
            // Пропускаем если лимит пагинации и транзакция старше ранней API-записи
            if ($pagination_limit_hit && $earliest_api_date !== null && $tx['created_at'] < $earliest_api_date) {
                continue;
            }
            $found = false;
            if (!empty($tx['click_id']) && isset($api_click_ids[ $tx['click_id'] ])) {
                $found = true;
            }
            if (!$found && !empty($tx['order_number']) && isset($api_order_ids[ $tx['order_number'] ])) {
                $found = true;
            }
            if (!$found) {
                $to_decline_registered[] = $tx;
            }
        }

        foreach ($stale_unregistered as $tx) {
            if ($pagination_limit_hit && $earliest_api_date !== null && $tx['created_at'] < $earliest_api_date) {
                continue;
            }
            $found = false;
            if (!empty($tx['click_id']) && isset($api_click_ids[ $tx['click_id'] ])) {
                $found = true;
            }
            if (!$found && !empty($tx['order_number']) && isset($api_order_ids[ $tx['order_number'] ])) {
                $found = true;
            }
            if (!$found) {
                $to_decline_unregistered[] = $tx;
            }
        }

        // ─── 7. Батчевое отклонение: cashback_transactions ───
        //
        // Атомарность (Group 8 Step 2, F-8-002):
        //   - Батч 100 (короткое окно row-locks).
        //   - Каждый батч в своей TX + SELECT ... FOR UPDATE + UPDATE + COMMIT.
        //   - Под локом перечитываем статус: UPDATE идёт только по строкам,
        //     всё ещё находящимся в ('waiting', 'hold', 'completed'). Если
        //     параллельно админ / другой процесс перевели строку в balance /
        //     declined / иное — пропускаем, логирование только по реально
        //     декланутым.
        //   - Retry 3× на deadlock / lock-wait через retry_on_sync_deadlock (Step 1).

        if (! empty($to_decline_registered)) {
            $result['declined_registered'] = $this->run_auto_decline_batches(
                $wpdb,
                $this->transactions_table,
                $to_decline_registered,
                $slug
            );
        }

        // ─── 8. Батчевое отклонение: cashback_unregistered_transactions ───

        if (! empty($to_decline_unregistered)) {
            $result['declined_unregistered'] = $this->run_auto_decline_batches(
                $wpdb,
                $this->unregistered_table,
                $to_decline_unregistered,
                $slug
            );
        }

        // ─── 9. Итоговое логирование ───

        $total_declined = $result['declined_registered'] + $result['declined_unregistered'];
        if ($total_declined > 0) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log(sprintf(
                '[Cashback Auto-Decline] Network=%s: declined %d registered + %d unregistered (checked %d stale, API returned %d actions)',
                $slug,
                $result['declined_registered'],
                $result['declined_unregistered'],
                $result['checked'],
                count($api_actions_list)
            ));
        }

        return $result;
    }

    /**
     * Атомарно отклонить батчи stale-транзакций с row-level locking.
     *
     * Group 8 Step 2 (F-8-002). Для каждого чанка (до 100 id):
     *   - START TRANSACTION
     *   - SELECT id, order_status FROM %i WHERE id IN (...) FOR UPDATE
     *   - фильтр «всё ещё в ('waiting','hold','completed')»
     *   - UPDATE только отфильтрованных (post-check в WHERE)
     *   - COMMIT
     * На deadlock / lock-wait — retry_on_sync_deadlock (3×).
     * log_sync_auto_decline вызывается только для реально декланутых строк
     * (после COMMIT, вне TX — чтобы ошибка логирования не откатывала UPDATE).
     *
     * @param \wpdb  $wpdb
     * @param string $table       Таблица транзакций (registered или unregistered).
     * @param array  $to_decline  Кандидаты на decline (результаты фильтрации по API).
     * @param string $slug        Slug сети (для логов).
     * @return int Число реально декланутых строк.
     */
    private function run_auto_decline_batches( \wpdb $wpdb, string $table, array $to_decline, string $slug ): int {
        if (empty($to_decline)) {
            return 0;
        }

        $tx_by_id = array();
        foreach ($to_decline as $tx) {
            $tx_by_id[ (int) $tx['id'] ] = $tx;
        }
        $ids = array_keys($tx_by_id);

        $declined_total = 0;

        foreach (array_chunk($ids, 100) as $chunk) {
            $declined_in_batch = array();

            $this->retry_on_sync_deadlock(function () use ( $wpdb, $table, $chunk, &$declined_in_batch ) {
                $wpdb->query('START TRANSACTION');

                try {
                    $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is array_fill of %d literals; sniff can't see %d inside $placeholders.
                    $locked = $wpdb->get_results($wpdb->prepare("SELECT id, order_status FROM %i WHERE id IN ({$placeholders}) FOR UPDATE", $table, ...$chunk), ARRAY_A);

                    if ($wpdb->last_error) {
                        $this->throw_if_deadlock($wpdb);
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log(sprintf(
                            '[Cashback Auto-Decline] SELECT FOR UPDATE error on %s: %s',
                            $table,
                            $wpdb->last_error
                        ));
                        $wpdb->query('ROLLBACK');
                        return;
                    }

                    // Фильтр под локом: оставить только ещё stale-строки.
                    $lockable_ids = array();
                    foreach (( $locked ?: array() ) as $row) {
                        $status = (string) ( $row['order_status'] ?? '' );
                        if (in_array($status, array( 'waiting', 'hold', 'completed' ), true)) {
                            $lockable_ids[] = (int) $row['id'];
                        }
                    }

                    if (empty($lockable_ids)) {
                        $wpdb->query('COMMIT');
                        return;
                    }

                    $ph2 = implode(',', array_fill(0, count($lockable_ids), '%d'));
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $ph2 is array_fill of %d literals; sniff can't see %d inside $ph2.
                    $wpdb->query($wpdb->prepare("UPDATE %i SET order_status = 'declined' WHERE id IN ({$ph2}) AND order_status IN ('waiting', 'hold', 'completed')", $table, ...$lockable_ids));

                    $batch_err = (string) $wpdb->last_error;
                    if ($batch_err !== '') {
                        $this->throw_if_deadlock($wpdb);
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log(sprintf(
                            '[Cashback Auto-Decline] UPDATE error on %s: %s',
                            $table,
                            $batch_err
                        ));
                        $wpdb->query('ROLLBACK');
                        return;
                    }

                    $wpdb->query('COMMIT');
                    $declined_in_batch = $lockable_ids;
                } catch (\Throwable $e) {
                    $wpdb->query('ROLLBACK');
                    throw $e;
                }
            }, 3);

            // Логирование вне TX — сбой лога не откатывает UPDATE.
            foreach ($declined_in_batch as $id) {
                if (! isset($tx_by_id[ $id ])) {
                    continue;
                }
                $tx = $tx_by_id[ $id ];
                $this->log_sync_auto_decline(
                    $slug,
                    $id,
                    (string) ( $tx['click_id'] ?? '' ),
                    (string) ( $tx['order_status'] ?? '' ),
                    (float) ( $tx['comission'] ?? 0 )
                );
            }

            $declined_total += count($declined_in_batch);
        }

        return $declined_total;
    }

    /**
     * Залогировать автоматическое отклонение в cashback_sync_log
     */
    private function log_sync_auto_decline(
        string $network_slug,
        int $transaction_id,
        string $click_id,
        string $old_status,
        float $commission
    ): void {
        global $wpdb;

        $wpdb->insert($this->sync_log_table, array(
            'network_slug'   => $network_slug,
            'transaction_id' => $transaction_id,
            'action_id'      => $click_id,
            'old_status'     => $old_status,
            'new_status'     => 'declined',
            'api_payment'    => $commission,
            'sync_type'      => 'auto_decline',
            'synced_at'      => current_time('mysql'),
        ));
    }

    // =========================================================================
    // Checkpoints
    // =========================================================================

    /**
     * Получить чекпоинт валидации пользователя
     */
    public function get_checkpoint( int $user_id, string $network_slug ): ?array {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE user_id = %d AND network_slug = %s',
            $this->checkpoints_table,
            $user_id,
            $network_slug
        ), ARRAY_A);
    }

    /**
     * Обновить чекпоинт валидации
     */
    public function update_checkpoint( int $user_id, string $network_slug, array $data ): bool {
        global $wpdb;

        $data['user_id']      = $user_id;
        $data['network_slug'] = $network_slug;
        $data['validated_at'] = current_time('mysql');
        $data['validated_by'] = get_current_user_id() ?: 0;

        $existing = $this->get_checkpoint($user_id, $network_slug);

        if ($existing) {
            $result = $wpdb->update(
                $this->checkpoints_table,
                $data,
                array(
					'user_id'      => $user_id,
					'network_slug' => $network_slug,
				)
            );
        } else {
            $result = $wpdb->insert($this->checkpoints_table, $data);
        }

        return $result !== false;
    }

    // =========================================================================
    // Sync log
    // =========================================================================

    // =========================================================================
    // Auto-transfer unregistered
    // =========================================================================

    /**
     * Автоматически переносит незарегистрированные транзакции к реальным пользователям.
     *
     * Ищет строки в cashback_unregistered_transactions, у которых click_id совпадает
     * с уже перенесённой транзакцией в cashback_transactions (т.е. пользователь был
     * идентифицирован ранее). Переносит их атомарно: INSERT + DELETE + audit_log.
     *
     * Запускается из крона каждые 2 часа после background_sync().
     *
     * @param int $limit Максимум строк за один вызов (default: 50)
     * @return array ['transferred' => int, 'skipped_duplicate' => int, 'errors' => int, 'checked' => int]
     */
    public function auto_transfer_unregistered( int $limit = 50 ): array {
        global $wpdb;

        $result = array(
            'transferred'       => 0,
            'skipped_duplicate' => 0,
            'errors'            => 0,
            'checked'           => 0,
        );

        // Одним JOIN находим кандидатов: unregistered строки, чей click_id уже есть
        // в cashback_transactions с реальным user_id.
        // Оба конца JOIN используют indexed click_id.
        $user_profile_table = $wpdb->prefix . 'cashback_user_profile';

        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT
                u.id            AS unreg_id,
                u.user_id       AS unreg_user_id,
                u.uniq_id,
                u.order_number,
                u.offer_id,
                u.offer_name,
                u.order_status,
                u.partner,
                u.sum_order,
                u.comission,
                u.currency,
                u.api_verified,
                u.action_date,
                u.click_time,
                u.click_id,
                u.website_id,
                u.action_type,
                u.processed_at,
                u.processed_batch_id,
                u.idempotency_key,
                u.spam_click,
                u.created_at,
                t.user_id       AS real_user_id
             FROM %i u
             INNER JOIN %i t
                 ON t.click_id = u.click_id
             LEFT JOIN %i cup
                 ON cup.user_id = t.user_id
             WHERE u.click_id IS NOT NULL
               AND u.click_id != ''
               AND (u.user_id = '0' OR u.user_id = 'unregistered')
               AND t.user_id > 0
               AND (cup.status IS NULL OR cup.status NOT IN ('banned', 'deleted'))
             GROUP BY u.id
             ORDER BY u.id ASC
             LIMIT %d",
            $this->unregistered_table,
            $this->transactions_table,
            $user_profile_table,
            $limit
        ), ARRAY_A);

        if (empty($candidates)) {
            return $result;
        }

        $result['checked'] = count($candidates);

        foreach ($candidates as $candidate) {
            $wpdb->query('START TRANSACTION');
            $success = false;

            try {
                // Блокируем исходную строку перед переносом
                $tx = $wpdb->get_row($wpdb->prepare(
                    'SELECT * FROM %i
                     WHERE id = %d FOR UPDATE',
                    $this->unregistered_table,
                    (int) $candidate['unreg_id']
                ), ARRAY_A);

                if (!$tx) {
                    $wpdb->query('ROLLBACK');
                    ++$result['skipped_duplicate'];
                    continue;
                }

                // Проверка дубликата по UNIQUE KEY (uniq_id, partner) — O(1)
                if (!empty($tx['uniq_id']) && !empty($tx['partner'])) {
                    $dup = (int) $wpdb->get_var($wpdb->prepare(
                        'SELECT COUNT(*) FROM %i
                         WHERE uniq_id = %s AND partner = %s',
                        $this->transactions_table,
                        $tx['uniq_id'],
                        $tx['partner']
                    ));
                    if ($dup > 0) {
                        // Уже существует — удаляем дубликат из unregistered
                        $wpdb->delete($this->unregistered_table, array( 'id' => (int) $candidate['unreg_id'] ), array( '%d' ));
                        $wpdb->query('COMMIT');
                        ++$result['skipped_duplicate'];
                        continue;
                    }
                }

                // INSERT в cashback_transactions с реальным user_id
                $insert_data = array(
                    'user_id'            => (int) $candidate['real_user_id'],
                    'order_number'       => $tx['order_number'],
                    'offer_id'           => $tx['offer_id'] !== null ? (int) $tx['offer_id'] : null,
                    'offer_name'         => $tx['offer_name'],
                    'order_status'       => $tx['order_status'],
                    'partner'            => $tx['partner'],
                    'sum_order'          => $tx['sum_order'],
                    'comission'          => $tx['comission'],
                    'currency'           => $tx['currency'],
                    'uniq_id'            => $tx['uniq_id'],
                    'api_verified'       => (int) $tx['api_verified'],
                    'action_date'        => $tx['action_date'],
                    'click_time'         => $tx['click_time'],
                    'click_id'           => $tx['click_id'],
                    'website_id'         => $tx['website_id'] !== null ? (int) $tx['website_id'] : null,
                    'action_type'        => $tx['action_type'],
                    'processed_at'       => $tx['processed_at'],
                    'processed_batch_id' => $tx['processed_batch_id'],
                    'idempotency_key'    => $tx['idempotency_key'],
                    'spam_click'         => (int) $tx['spam_click'],
                    'created_at'         => $tx['created_at'],
                );

                // Убираем NULL-значения, чтобы не переписывать DEFAULT и триггеры
                $insert_data = array_filter($insert_data, static function ( $v ) {
                    return $v !== null;
                });

                $inserted = $wpdb->insert($this->transactions_table, $insert_data);

                if ($inserted === false || $wpdb->last_error) {
                    $err = $wpdb->last_error;
                    $wpdb->query('ROLLBACK');
                    ++$result['errors'];
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log(sprintf(
                        '[Cashback AutoTransfer] INSERT failed for unreg_id=%d: %s',
                        (int) $candidate['unreg_id'],
                        $err
                    ));
                    continue;
                }

                $new_id = (int) $wpdb->insert_id;

                // Удаляем исходную строку
                $wpdb->delete($this->unregistered_table, array( 'id' => (int) $candidate['unreg_id'] ), array( '%d' ));
                $wpdb->query('COMMIT');
                $success = true;

                // Уведомление о новой транзакции обрабатывается через MySQL триггер → очередь → WP Cron

                // Аудит-лог
                if (class_exists('Cashback_Encryption')) {
                    Cashback_Encryption::write_audit_log(
                        'unregistered_transaction_auto_transferred',
                        0, // системный актор
                        'transaction',
                        $new_id,
                        array(
                            'source_id'   => (int) $candidate['unreg_id'],
                            'target_user' => (int) $candidate['real_user_id'],
                            'click_id'    => $tx['click_id'],
                            'uniq_id'     => $tx['uniq_id'],
                            'partner'     => $tx['partner'],
                        )
                    );
                }

                ++$result['transferred'];

            } catch (\Throwable $e) {
                if (!$success) {
                    $wpdb->query('ROLLBACK');
                }
                ++$result['errors'];
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log(sprintf(
                    '[Cashback AutoTransfer] Exception for unreg_id=%d: %s',
                    (int) $candidate['unreg_id'],
                    $e->getMessage()
                ));
            }
        }

        return $result;
    }

    /**
     * Записать уведомление в очередь после UPDATE транзакции (вместо MySQL триггера)
     *
     * Обрабатывает два случая:
     * 1. Смена статуса → event_type = 'transaction_status'
     * 2. Изменение комиссии/суммы без смены статуса → event_type = 'transaction_data_changed'
     */
    private function enqueue_notification_on_update(
        \wpdb $wpdb,
        int $transaction_id,
        int $user_id,
        string $old_status,
        string $new_status,
        bool $status_changed,
        bool $commission_changed,
        bool $cart_changed,
        array $local,
        array $update_data
    ): void {
        if ($user_id <= 0) {
            return;
        }

        $queue_table = $wpdb->prefix . 'cashback_notification_queue';

        // Смена статуса (исключаем balance — для него есть отдельное уведомление cashback_credited)
        if ($status_changed && $new_status !== 'balance') {
            $wpdb->insert(
                $queue_table,
                array(
                    'event_type'     => 'transaction_status',
                    'transaction_id' => $transaction_id,
                    'user_id'        => $user_id,
                    'old_status'     => $old_status,
                    'new_status'     => $new_status,
                ),
                array( '%s', '%d', '%d', '%s', '%s' )
            );
            return;
        }

        // Изменение комиссии или суммы заказа без смены статуса
        if (( $commission_changed || $cart_changed ) && !$status_changed) {
            $old_comission = (float) ( $local['comission'] ?? 0 );
            $new_comission = isset($update_data['comission']) ? (float) $update_data['comission'] : $old_comission;
            $old_sum_order = (float) ( $local['sum_order'] ?? 0 );
            $new_sum_order = isset($update_data['sum_order']) ? (float) $update_data['sum_order'] : $old_sum_order;
            $old_cashback  = (float) ( $local['cashback'] ?? 0 );
            $new_cashback  = isset($update_data['cashback']) ? (float) $update_data['cashback'] : $old_cashback;

            $extra = wp_json_encode(array(
                'old_comission' => $old_comission,
                'new_comission' => $new_comission,
                'old_sum_order' => $old_sum_order,
                'new_sum_order' => $new_sum_order,
                'old_cashback'  => $old_cashback,
                'new_cashback'  => $new_cashback,
            ));

            $wpdb->insert(
                $queue_table,
                array(
                    'event_type'     => 'transaction_data_changed',
                    'transaction_id' => $transaction_id,
                    'user_id'        => $user_id,
                    'new_status'     => $new_status,
                    'extra_data'     => $extra,
                ),
                array( '%s', '%d', '%d', '%s', '%s' )
            );
        }
    }

    /**
     * Залогировать событие синхронизации
     */
    private function log_sync_event(
        string $network_slug,
        int $transaction_id,
        string $match_key,
        string $old_status,
        string $new_status,
        float $api_payment
    ): void {
        global $wpdb;

        $wpdb->insert($this->sync_log_table, array(
            'network_slug'   => $network_slug,
            'transaction_id' => $transaction_id,
            'action_id'      => $match_key,
            'old_status'     => $old_status,
            'new_status'     => $new_status,
            'api_payment'    => $api_payment,
            'synced_at'      => current_time('mysql'),
        ));
    }

    // =========================================================================
    // Campaign Status Check — автоматическая деактивация магазинов
    // =========================================================================

    /**
     * Проверить статусы кампаний во всех активных сетях и деактивировать/реактивировать товары
     *
     * Логика:
     * 1. Для каждой активной сети получаем список кампаний через adapter->fetch_campaigns()
     * 2. Сопоставляем кампании с товарами WooCommerce через _offer_id post_meta
     * 3. Если кампания неактивна или отсутствует — товар переводится в draft
     * 4. Если ранее деактивированный товар — кампания снова активна — реактивируем
     * 5. Email-уведомление админу при деактивации
     *
     * Защита от ложных деактиваций:
     * - Если API вернул ошибку — ни один товар не трогается
     * - Товары без _offer_id пропускаются
     * - Реактивируются только автоматически деактивированные (_cashback_auto_deactivated = '1')
     *
     * @return array Результаты по каждой сети
     */
    public function check_campaign_statuses( ?string $only_slug = null ): array {
        global $wpdb;

        $results  = array();
        $networks = $this->get_all_active_networks();

        foreach ($networks as $network) {
            $slug       = $network['slug'] ?? '';
            $network_id = (int) ( $network['id'] ?? 0 );

            if ($only_slug !== null && $slug !== $only_slug) {
                continue;
            }

            if (empty($slug) || $network_id <= 0) {
                continue;
            }

            $config = $this->get_network_config($slug);
            if (!$config || empty($config['credentials'])) {
                $results[ $slug ] = array(
                    'success' => false,
                    'error'   => 'No credentials configured',
                );
                continue;
            }

            $adapter = $this->get_adapter($slug);
            if (!$adapter) {
                $results[ $slug ] = array(
                    'success' => false,
                    'error'   => 'No adapter found for: ' . $slug,
                );
                continue;
            }

            // Получаем список кампаний из CPA-сети
            $campaign_result = $adapter->fetch_campaigns($config['credentials'], $config);

            if (!$campaign_result['success']) {
                $results[ $slug ] = array(
                    'success' => false,
                    'error'   => $campaign_result['error'],
                );
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log(sprintf(
                    'Cashback Campaign Check [%s]: API error — %s',
                    $slug,
                    $campaign_result['error']
                ));
                continue;
            }

            // Строим карту: campaign_id => campaign_data
            $campaign_map = array();
            foreach ($campaign_result['campaigns'] as $campaign) {
                $cid = (string) $campaign['id'];
                if ($cid !== '') {
                    $campaign_map[ $cid ] = $campaign;
                }
            }

            // Защита: если API вернул 0 кампаний — возможна ошибка API, не деактивируем
            if (empty($campaign_map)) {
                $results[ $slug ] = array(
                    'success' => false,
                    'error'   => 'API вернул 0 кампаний — возможна проблема с API (неверный scope, token или website_id)',
                );
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback Campaign Check [' . $slug . ']: ' . $results[ $slug ]['error']);
                continue;
            }

            // Находим все опубликованные товары привязанные к этой сети
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $published_products = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, pm_offer.meta_value AS offer_id
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm_net ON p.ID = pm_net.post_id AND pm_net.meta_key = '_affiliate_network_id'
                 LEFT JOIN {$wpdb->postmeta} pm_offer ON p.ID = pm_offer.post_id AND pm_offer.meta_key = '_offer_id'
                 WHERE pm_net.meta_value = %d
                   AND p.post_status = 'publish'
                   AND p.post_type = 'product'",
                $network_id
            ), ARRAY_A) ?: array();

            $deactivated = 0;
            $reactivated = 0;
            $skipped     = 0;

            foreach ($published_products as $row) {
                $product_id       = (int) $row['ID'];
                $product_offer_id = trim((string) ( $row['offer_id'] ?? '' ));

                if ($product_offer_id === '') {
                    ++$skipped;
                    continue;
                }

                $campaign = $campaign_map[ $product_offer_id ] ?? null;

                if ($campaign === null) {
                    // Кампания не найдена в API — возможно удалена
                    $this->deactivate_product(
                        $product_id,
                        $slug,
                        $product_offer_id,
                        'Кампания не найдена в API CPA-сети'
                    );
                    ++$deactivated;
                    continue;
                }

                if (!$campaign['is_active']) {
                    $reason = sprintf(
                        'Кампания «%s» деактивирована в %s (status: %s, connection: %s)',
                        $campaign['name'],
                        strtoupper($slug),
                        $campaign['status'],
                        $campaign['connection_status']
                    );
                    $this->deactivate_product($product_id, $slug, $product_offer_id, $reason);
                    ++$deactivated;
                }
            }

            // Проверяем ранее деактивированные товары на реактивацию
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $draft_products = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, pm_offer.meta_value AS offer_id
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm_net ON p.ID = pm_net.post_id AND pm_net.meta_key = '_affiliate_network_id'
                 INNER JOIN {$wpdb->postmeta} pm_deact ON p.ID = pm_deact.post_id AND pm_deact.meta_key = '_cashback_auto_deactivated'
                 LEFT JOIN {$wpdb->postmeta} pm_offer ON p.ID = pm_offer.post_id AND pm_offer.meta_key = '_offer_id'
                 WHERE pm_net.meta_value = %d
                   AND p.post_status = 'draft'
                   AND p.post_type = 'product'
                   AND pm_deact.meta_value = '1'",
                $network_id
            ), ARRAY_A) ?: array();

            foreach ($draft_products as $row) {
                $product_id       = (int) $row['ID'];
                $product_offer_id = trim((string) ( $row['offer_id'] ?? '' ));

                if ($product_offer_id === '') {
                    continue;
                }

                $campaign = $campaign_map[ $product_offer_id ] ?? null;

                if ($campaign !== null && $campaign['is_active']) {
                    $this->reactivate_product($product_id, $slug, $product_offer_id, $campaign['name']);
                    ++$reactivated;
                }
            }

            // Сохраняем снимок статусов кампаний для админки
            update_option("cashback_campaign_status_{$slug}", array(
                'timestamp' => current_time('mysql'),
                'total'     => count($campaign_result['campaigns']),
                'active'    => count(array_filter($campaign_result['campaigns'], fn( $c ) => $c['is_active'])),
                'inactive'  => count(array_filter($campaign_result['campaigns'], fn( $c ) => !$c['is_active'])),
                'campaigns' => array_map(function ( $c ) {
                    return array(
                        'id'                => $c['id'],
                        'name'              => $c['name'],
                        'is_active'         => $c['is_active'],
                        'status'            => $c['status'],
                        'connection_status' => $c['connection_status'],
                    );
                }, $campaign_result['campaigns']),
            ), false);

            $results[ $slug ] = array(
                'success'         => true,
                'total_campaigns' => count($campaign_result['campaigns']),
                'deactivated'     => $deactivated,
                'reactivated'     => $reactivated,
                'skipped'         => $skipped,
            );
        }

        // Email-уведомление при деактивации
        $total_deactivated = 0;
        foreach ($results as $r) {
            if ($r['success'] ?? false) {
                $total_deactivated += ( $r['deactivated'] ?? 0 );
            }
        }
        if ($total_deactivated > 0) {
            $this->send_campaign_deactivation_notification($results);
        }

        return $results;
    }

    /**
     * Деактивировать товар WooCommerce (перевести в draft) из-за отключения кампании
     *
     * @param int    $product_id  ID товара
     * @param string $network_slug Slug CPA-сети
     * @param string $offer_id    ID кампании/оффера
     * @param string $reason      Причина деактивации
     */
    private function deactivate_product( int $product_id, string $network_slug, string $offer_id, string $reason ): void {
        // Проверяем: не деактивирован ли уже
        if (get_post_meta($product_id, '_cashback_auto_deactivated', true) === '1') {
            return;
        }

        wp_update_post(array(
            'ID'          => $product_id,
            'post_status' => 'draft',
        ));

        update_post_meta($product_id, '_cashback_auto_deactivated', '1');
        update_post_meta($product_id, '_cashback_deactivation_reason', $reason);
        update_post_meta($product_id, '_cashback_deactivated_at', current_time('mysql'));
        update_post_meta($product_id, '_cashback_deactivated_network', $network_slug);

        // Аудит-лог
        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'store_auto_deactivated',
                0,
                'product',
                $product_id,
                array(
                    'network_slug' => $network_slug,
                    'offer_id'     => $offer_id,
                    'reason'       => $reason,
                )
            );
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log(sprintf(
            'Cashback Campaign Check: Product #%d deactivated (network: %s, offer: %s) — %s',
            $product_id,
            $network_slug,
            $offer_id,
            $reason
        ));
    }

    /**
     * Реактивировать ранее автоматически деактивированный товар
     *
     * @param int    $product_id    ID товара
     * @param string $network_slug  Slug CPA-сети
     * @param string $offer_id      ID кампании/оффера
     * @param string $campaign_name Название кампании
     */
    private function reactivate_product( int $product_id, string $network_slug, string $offer_id, string $campaign_name ): void {
        wp_update_post(array(
            'ID'          => $product_id,
            'post_status' => 'publish',
        ));

        delete_post_meta($product_id, '_cashback_auto_deactivated');
        delete_post_meta($product_id, '_cashback_deactivation_reason');
        delete_post_meta($product_id, '_cashback_deactivated_at');
        delete_post_meta($product_id, '_cashback_deactivated_network');

        // Аудит-лог
        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'store_auto_reactivated',
                0,
                'product',
                $product_id,
                array(
                    'network_slug'  => $network_slug,
                    'offer_id'      => $offer_id,
                    'campaign_name' => $campaign_name,
                )
            );
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log(sprintf(
            'Cashback Campaign Check: Product #%d reactivated (network: %s, campaign: %s)',
            $product_id,
            $network_slug,
            $campaign_name
        ));
    }

    /**
     * Отправить email-уведомление администратору о деактивированных магазинах
     *
     * @param array $results Результаты check_campaign_statuses()
     */
    private function send_campaign_deactivation_notification( array $results ): void {
        if (!class_exists('Cashback_Email_Sender') || !class_exists('Cashback_Email_Builder')) {
            return;
        }

        $site_name = get_bloginfo('name');
        $subject   = sprintf(
            /* translators: %s: site name */
            __('[Cashback] %s: Магазины деактивированы из-за отключения кампаний', 'cashback-plugin'),
            $site_name
        );

        $dump  = "Отчёт о статусах кампаний CPA-сетей\n";
        $dump .= str_repeat('=', 50) . "\n";
        $dump .= sprintf("Дата: %s\n\n", current_time('mysql'));

        foreach ($results as $network => $result) {
            if (!( $result['success'] ?? false )) {
                continue;
            }
            if (( $result['deactivated'] ?? 0 ) === 0 && ( $result['reactivated'] ?? 0 ) === 0) {
                continue;
            }

            $dump .= sprintf("[%s]\n", strtoupper($network));
            $dump .= sprintf("  Кампаний всего: %d\n", $result['total_campaigns']);
            $dump .= sprintf("  Деактивировано товаров: %d\n", $result['deactivated']);
            $dump .= sprintf("  Реактивировано товаров: %d\n", $result['reactivated']);
            $dump .= sprintf("  Пропущено (без offer_id): %d\n", $result['skipped']);
            $dump .= "\n";
        }

        $body  = Cashback_Email_Builder::paragraph(
            esc_html__('Обнаружены изменения статусов CPA-кампаний. Детали ниже.', 'cashback-plugin')
        );
        $body .= Cashback_Email_Builder::preformatted($dump);
        $body .= Cashback_Email_Builder::button(
            __('Открыть API-валидацию', 'cashback-plugin'),
            admin_url('admin.php?page=cashback-api-validation&tab=campaigns')
        );

        Cashback_Email_Sender::get_instance()->send_admin($subject, $body, 'api_sync_report');
    }
}
