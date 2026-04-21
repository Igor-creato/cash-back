<?php

/**
 * Адаптер CPA-сети EPN
 *
 * OAuth2: 3-шаговый процесс (SSID → token → refresh).
 * Пагинация: page/perPage.
 * Даты: ISO 8601 (yyyy-mm-dd).
 * Auth header: X-ACCESS-TOKEN.
 * Ответ: вложенная структура data[].attributes.
 *
 * @package CashbackPlugin
 * @since   7.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Epn_Adapter extends Cashback_Network_Adapter_Base {

    /**
     * {@inheritdoc}
     */
    public function get_slug(): string {
        return 'epn';
    }

    /**
     * {@inheritdoc}
     *
     * EPN OAuth2: 3-шаговый процесс.
     * 1. Пробуем refresh_token (если сохранён).
     * 2. Получаем SSID-токен (лимит 1 запрос/сутки с IP).
     * 3. Обмениваем SSID + credentials на access_token.
     */
    public function get_token( array $credentials, array $network_config ): ?string {
        $client_id     = $credentials['client_id'] ?? '';
        $client_secret = $credentials['client_secret'] ?? '';

        if (empty($client_id) || empty($client_secret)) {
            $this->last_token_error = 'EPN credentials incomplete (client_id или client_secret пустые)';
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Client: ' . $this->last_token_error);
            return null;
        }

        $cache_key = 'cashback_epn_token_' . md5($client_id);

        // Проверяем transient
        $cached = get_transient($cache_key);
        if ($cached) {
            return $cached;
        }

        // Проверяем runtime кеш
        if (isset($this->token_cache[ $cache_key ])) {
            return $this->token_cache[ $cache_key ];
        }

        // Пробуем refresh_token (если есть сохранённый)
        $refresh_key   = 'cashback_epn_refresh_' . md5($client_id);
        $refresh_token = $this->load_refresh_token($refresh_key);
        if (!empty($refresh_token)) {
            $token = $this->refresh_token($refresh_token, $network_config, $client_id, $cache_key, $refresh_key);
            if ($token) {
                return $token;
            }
            // Refresh не удался — пробуем полную авторизацию
            delete_option($refresh_key);
        }

        // ─── Шаг 1: Получить SSID-токен ───
        // ВАЖНО: /ssid лимитирован 1 запрос в сутки с одного IP.
        // Для снятия лимита — добавить IP в whitelist через EPN поддержку.
        $oauth_base = rtrim($network_config['api_base_url'] ?? 'https://oauth2.epn.bz', '/');
        $ssid_url   = $oauth_base . '/ssid?' . http_build_query(array(
            'v'         => 2,
            'client_id' => $client_id,
        ));

        $ssid_response = $this->http_get($ssid_url, array(
            'X-API-VERSION' => '2',
        ));

        if (is_wp_error($ssid_response)) {
            $this->last_token_error = 'EPN SSID ошибка сети: ' . $ssid_response->get_error_message();
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Client: ' . $this->last_token_error);
            return null;
        }

        $ssid_code = wp_remote_retrieve_response_code($ssid_response);
        $ssid_body = json_decode(wp_remote_retrieve_body($ssid_response), true);

        $ssid_token = $ssid_body['data']['attributes']['ssid_token'] ?? '';

        if ($ssid_code === 429) {
            $this->last_token_error = 'EPN SSID: требуется капча (лимит 1 запрос/сутки с одного IP). '
                . 'Добавьте IP сервера в whitelist через поддержку EPN, или подождите 24 часа.';
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Client: EPN SSID captcha required. Code: 429');
            return null;
        }

        if ($ssid_code !== 200 || empty($ssid_token)) {
            $epn_error              = $ssid_body['errors'][0]['error_description'] ?? '';
            $this->last_token_error = 'EPN SSID failed (HTTP ' . $ssid_code . '). ' . $epn_error;
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Client: EPN SSID failed. Code: ' . $ssid_code . ', Error: ' . $epn_error);
            return null;
        }

        // ─── Шаг 2: Получить access_token с SSID ───
        $token_url = $this->build_api_url($network_config, 'api_token_endpoint', 'https://oauth2.epn.bz/token');

        $response = $this->http_post($token_url, array(
            'Content-Type'  => 'application/json',
            'X-API-VERSION' => '2',
        ), wp_json_encode(array(
            'grant_type'    => 'client_credential',
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'ssid_token'    => $ssid_token,
            'check_ip'      => false,
        )));

        if (is_wp_error($response)) {
            $this->last_token_error = 'EPN token ошибка сети: ' . $response->get_error_message();
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Client: ' . $this->last_token_error);
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // EPN возвращает JSON вида data.attributes.access_token (JWT) + refresh_token + expires_in.
        $token = $body['data']['attributes']['access_token'] ?? '';

        if ($code !== 200 || empty($token)) {
            $epn_error = $body['errors'][0]['error_description'] ?? '';
            $safe_body = $body;
            if (is_array($safe_body)) {
                if (isset($safe_body['data']['attributes'])) {
                    unset(
                        $safe_body['data']['attributes']['access_token'],
                        $safe_body['data']['attributes']['refresh_token']
                    );
                }
            }
            $this->last_token_error = 'EPN token failed (HTTP ' . $code . '). ' . $epn_error;
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Client: EPN token failed. Code: ' . $code . ', Body: ' . wp_json_encode($safe_body));
            return null;
        }

        $expires = (int) ( $body['data']['attributes']['expires_in'] ?? 86400 );

        // Сохраняем refresh_token для обновления без SSID
        $new_refresh = $body['data']['attributes']['refresh_token'] ?? '';
        if (!empty($new_refresh)) {
            $this->store_refresh_token($refresh_key, (string) $new_refresh);
        }

        // Кешируем access_token (24 часа, с запасом 30 минут)
        set_transient($cache_key, $token, max(60, $expires - 1800));
        $this->token_cache[ $cache_key ] = $token;

        return $token;
    }

    /**
     * Обновить EPN access_token через refresh_token
     *
     * @param string $refresh_token Текущий refresh_token
     * @param array  $network_config Конфигурация сети
     * @param string $client_id     Client ID
     * @param string $cache_key     Ключ кеша access_token
     * @param string $refresh_key   Ключ опции refresh_token
     * @return string|null Новый access_token или null
     */
    private function refresh_token( string $refresh_token, array $network_config, string $client_id, string $cache_key, string $refresh_key ): ?string {
        $oauth_base  = rtrim($network_config['api_base_url'] ?? 'https://oauth2.epn.bz', '/');
        $refresh_url = $oauth_base . '/token/refresh';

        $response = $this->http_post($refresh_url, array(
            'Content-Type'  => 'application/json',
            'X-API-VERSION' => '2',
        ), wp_json_encode(array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id'     => $client_id,
        )));

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        $token = $body['data']['attributes']['access_token'] ?? '';
        if ($code !== 200 || empty($token)) {
            return null;
        }

        $expires = (int) ( $body['data']['attributes']['expires_in'] ?? 86400 );

        // Обновляем refresh_token
        $new_refresh = $body['data']['attributes']['refresh_token'] ?? '';
        if (!empty($new_refresh)) {
            $this->store_refresh_token($refresh_key, (string) $new_refresh);
        }

        // Кешируем access_token
        set_transient($cache_key, $token, max(60, $expires - 1800));
        $this->token_cache[ $cache_key ] = $token;

        return $token;
    }

    /**
     * Загрузить EPN refresh_token из wp_options, прозрачно расшифровывая
     * значения с префиксом ENC:v1:. Plaintext (из старых инсталляций)
     * читается как есть — backward-compat через decrypt_if_ciphertext().
     */
    private function load_refresh_token( string $option_key ): string {
        $stored = (string) get_option($option_key, '');
        return Cashback_Encryption::decrypt_if_ciphertext($stored);
    }

    /**
     * Сохранить EPN refresh_token в wp_options, шифруя значение при наличии
     * CB_ENCRYPTION_KEY. autoload=false — токен нужен только при API-вызовах.
     */
    private function store_refresh_token( string $option_key, string $refresh_token ): void {
        $value = Cashback_Encryption::encrypt_if_needed($refresh_token);
        update_option($option_key, $value, false);
    }

    /**
     * {@inheritdoc}
     */
    public function build_auth_headers( array $credentials, array $network_config ): ?array {
        $token = $this->get_token($credentials, $network_config);
        if (!$token) {
            return null;
        }
        // EPN использует X-ACCESS-TOKEN вместо Authorization: Bearer
        return array( 'X-ACCESS-TOKEN' => $token );
    }

    /**
     * Получить действия из EPN API (одна страница)
     *
     * Нормализует ответ в формат Admitad для совместимости с background_sync().
     *
     * @param array $credentials   API credentials
     * @param array $params        Параметры запроса
     * @param array $network_config Конфигурация сети
     * @return array ['success' => bool, 'actions' => [...], 'total' => int, 'has_next' => bool, 'error' => string|null]
     */
    public function fetch_actions( array $credentials, array $params, array $network_config ): array {
        $auth_headers = $this->build_auth_headers($credentials, $network_config);
        if (!$auth_headers) {
            return $this->fetch_error('Failed to authenticate with EPN');
        }

        $query_params = array();

        // Пагинация (page-based)
        $query_params['page']    = (int) ( $params['page'] ?? 1 );
        $query_params['perPage'] = min((int) ( $params['perPage'] ?? 500 ), 1000);

        // Обязательные: tsFrom, tsTo
        // EPN ограничивает tsFrom: не ранее 1 года от текущей даты.
        $max_lookback = ( new \DateTime() )->modify('-365 days')->format('Y-m-d');

        if (!empty($params['tsFrom'])) {
            $query_params['tsFrom'] = $params['tsFrom'];
        } elseif (!empty($params['date_start'])) {
            $query_params['tsFrom'] = self::convert_date($params['date_start']);
        }

        // Ограничиваем tsFrom одним годом назад (требование EPN API)
        if (!empty($query_params['tsFrom']) && $query_params['tsFrom'] < $max_lookback) {
            $query_params['tsFrom'] = $max_lookback;
        }

        if (!empty($params['tsTo'])) {
            $query_params['tsTo'] = $params['tsTo'];
        } elseif (!empty($params['date_end'])) {
            $query_params['tsTo'] = self::convert_date($params['date_end']);
        }

        // EPN поддерживает statusUpdatedStart/statusUpdatedEnd —
        // фильтрация по дате обновления статуса для инкрементальной синхронизации.
        // background_sync() передаёт status_updated_start/end в формате Admitad (dd.mm.yyyy HH:MM:SS),
        // конвертируем в EPN формат (yyyy-mm-dd).
        if (!empty($params['statusUpdatedStart'])) {
            $query_params['statusUpdatedStart'] = $params['statusUpdatedStart'];
        } elseif (!empty($params['status_updated_start'])) {
            $query_params['statusUpdatedStart'] = self::convert_datetime($params['status_updated_start']);
        }

        if (!empty($params['statusUpdatedEnd'])) {
            $query_params['statusUpdatedEnd'] = $params['statusUpdatedEnd'];
        } elseif (!empty($params['status_updated_end'])) {
            $query_params['statusUpdatedEnd'] = self::convert_datetime($params['status_updated_end']);
        }

        // Дополнительные EPN-фильтры
        if (!empty($params['offerIds'])) {
            $query_params['offerIds'] = $params['offerIds'];
        }
        if (!empty($params['clickId'])) {
            $query_params['clickId'] = $params['clickId'];
        }
        if (!empty($params['sub'])) {
            $query_params['sub'] = $params['sub'];
        }
        if (!empty($params['currency'])) {
            $query_params['currency'] = $params['currency'];
        }

        // Запрашиваем расширенные поля (click_id, sub1-sub5, action_id)
        $query_params['fields'] = 'order_number,order_time,order_status,transaction_time,revenue,commission_user,creative_title,sub_title,user_click_id,offer_type,offer_id,currency,transactionId,click_id,sub1,sub2,sub3,sub4,sub5,action_id,country_code,type_id';

        $actions_url = $this->build_api_url($network_config, 'api_actions_endpoint', '');
        if (empty($actions_url)) {
            $actions_url = 'https://app.epn.bz/transactions/user';
        }

        $url = $actions_url . '?' . http_build_query($query_params);

        // Добавляем стандартные заголовки — nginx на app.epn.bz может блокировать
        // запросы без Accept/Content-Type или со стандартным WordPress User-Agent
        // (возвращает 403 Forbidden).
        $headers = array_merge(array(
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'User-Agent'    => 'CashbackPlugin/1.0',
            'X-API-VERSION' => '2',
        ), $auth_headers);

        $response = $this->http_get($url, $headers);

        if (is_wp_error($response)) {
            return $this->fetch_error($response->get_error_message());
        }

        $code     = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $body     = json_decode($raw_body, true);

        // На 401 — сбрасываем кеш токена и повторяем один раз
        if ($code === 401 && empty($params['_retry_after_401'])) {
            $client_id = $credentials['client_id'] ?? '';
            $cache_key = 'cashback_epn_token_' . md5($client_id);
            delete_transient($cache_key);
            unset($this->token_cache[ $cache_key ]);

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback EPN: 401 on actions endpoint, invalidating token and retrying');

            $params['_retry_after_401'] = true;
            return $this->fetch_actions($credentials, $params, $network_config);
        }

        // На 403 пробуем сбросить кеш токена и повторить один раз
        if ($code === 403 && empty($params['_retry_after_403'])) {
            $client_id = $credentials['client_id'] ?? '';
            $cache_key = 'cashback_epn_token_' . md5($client_id);
            delete_transient($cache_key);
            unset($this->token_cache[ $cache_key ]);

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback EPN: 403 on actions endpoint, invalidating token and retrying');

            $params['_retry_after_403'] = true;
            return $this->fetch_actions($credentials, $params, $network_config);
        }

        if ($code !== 200) {
            $epn_error = '';
            if (is_array($body) && isset($body['errors'][0]['error_description'])) {
                $epn_error = ' — ' . $body['errors'][0]['error_description'];
            }

            $error_msg = "HTTP {$code}{$epn_error}";

            if ($code === 403) {
                if (str_contains($raw_body, '403005')) {
                    $error_msg = 'EPN 403: IP-привязка токена (403005). Проверьте check_ip параметр.';
                } elseif (str_contains($raw_body, '403001')) {
                    $error_msg = 'EPN 403: Недостаточно прав API-клиента (403001). Проверьте роль в EPN.';
                } else {
                    $error_msg = 'EPN 403: Токен отклонён. Проверьте права доступа и IP сервера.';
                }
            }

            $log_context = array(
                'http_code' => $code,
                'error'     => $body['errors'][0]['error_description'] ?? '',
            );
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback EPN fetch_actions error: ' . wp_json_encode($log_context));

            return $this->fetch_error($error_msg);
        }

        // Парсим EPN-ответ: data[].{type, id, attributes}
        $raw_data = $body['data'] ?? array();
        $total    = (int) ( $body['meta']['totalFound'] ?? count($raw_data) );
        $has_next = (bool) ( $body['meta']['hasNext'] ?? false );

        // Нормализация EPN → Admitad формат для совместимости с background_sync()
        $normalized  = array();
        $click_field = $network_config['api_click_field'] ?? 'click_id';
        $user_field  = $network_config['api_user_field'] ?? 'sub2';

        foreach ($raw_data as $item) {
            $attrs  = $item['attributes'] ?? array();
            $epn_id = (string) ( $item['id'] ?? '' );

            $normalized[] = $this->normalize_action($attrs, $epn_id, $click_field, $user_field);
        }

        return array(
            'success'  => true,
            'actions'  => $normalized,
            'total'    => $total,
            'has_next' => $has_next,
            'error'    => null,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function fetch_all_actions( array $credentials, array $params, int $max_pages, array $network_config ): array {
        $all_actions = array();
        $per_page    = 500;
        $page        = 1;
        $total       = 0;

        do {
            $params['page']    = $page;
            $params['perPage'] = $per_page;

            $result = $this->fetch_actions($credentials, $params, $network_config);

            if (!$result['success']) {
                return array(
                    'success' => false,
                    'actions' => $all_actions,
                    'total'   => $total,
                    'error'   => $result['error'],
                );
            }

            $actions     = $result['actions'];
            $total       = $result['total'];
            $has_next    = $result['has_next'] ?? false;
            $all_actions = array_merge($all_actions, $actions);
            ++$page;

            // Пауза между запросами (100ms)
            if ($has_next && $page <= $max_pages) {
                usleep(100000);
            }
        } while ($has_next && $page <= $max_pages);

        return array(
            'success' => true,
            'actions' => $all_actions,
            'total'   => $total,
            'error'   => null,
        );
    }

    /**
     * {@inheritdoc}
     *
     * EPN: GET /offers/list — список офферов с фильтрацией по статусам.
     * Допустимые статусы EPN: active, disabled, waiting, stopped.
     * Оффер активен только при status = 'active'.
     *
     * Пагинация: limit + offset (не page/perPage).
     * Обязательные параметры: lang, viewRules.
     */
    public function fetch_campaigns( array $credentials, array $network_config ): array {
        $auth_headers = $this->build_auth_headers($credentials, $network_config);
        if (!$auth_headers) {
            return array(
				'success'   => false,
				'campaigns' => array(),
				'error'     => 'Failed to authenticate with EPN',
			);
        }

        // EPN: OAuth на oauth2.epn.bz, data API на app.epn.bz — api_base_url = OAuth
        $url = 'https://app.epn.bz/offers/list';

        $headers = array_merge(array(
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'User-Agent'    => 'CashbackPlugin/1.0',
            'X-API-VERSION' => '2',
        ), $auth_headers);

        $all_campaigns = array();
        $limit         = 500;
        $offset        = 0;
        $max_pages     = 20;
        $page          = 0;
        $retried       = false;

        do {
            $query = array(
                'lang'      => 'ru',
                'viewRules' => 'role_user',
                'statuses'  => 'active,disabled,waiting,stopped',
                'fields'    => 'id,name,title,status',
                'limit'     => $limit,
                'offset'    => $offset,
            );

            $full_url = $url . '?' . http_build_query($query);

            $response = $this->http_get($full_url, $headers);

            if (is_wp_error($response)) {
                return array(
                    'success'   => false,
                    'campaigns' => $all_campaigns,
                    'error'     => 'HTTP error: ' . $response->get_error_message(),
                );
            }

            $code = wp_remote_retrieve_response_code($response);

            // 401/403 — сбрасываем токен и повторяем один раз
            if (( $code === 401 || $code === 403 ) && $page === 0 && !$retried) {
                $client_id = $credentials['client_id'] ?? '';
                $cache_key = 'cashback_epn_token_' . md5($client_id);
                delete_transient($cache_key);
                unset($this->token_cache[ $cache_key ]);

                $auth_headers = $this->build_auth_headers($credentials, $network_config);
                if (!$auth_headers) {
                    return array(
						'success'   => false,
						'campaigns' => array(),
						'error'     => 'EPN token refresh failed',
					);
                }

                $headers = array_merge(array(
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                    'User-Agent'   => 'CashbackPlugin/1.0',
                ), $auth_headers);

                $retried = true;
                continue;
            }

            if ($code !== 200) {
                $raw_body = wp_remote_retrieve_body($response);
                return array(
                    'success'   => false,
                    'campaigns' => $all_campaigns,
                    'error'     => "EPN offers HTTP {$code}: " . mb_substr($raw_body, 0, 300),
                );
            }

            $body     = json_decode(wp_remote_retrieve_body($response), true);
            $raw_data = $body['data'] ?? array();
            $total    = (int) ( $body['meta']['count'] ?? 0 );

            foreach ($raw_data as $item) {
                $attrs  = $item['attributes'] ?? array();
                $status = strtolower((string) ( $attrs['status'] ?? '' ));

                $all_campaigns[] = array(
                    'id'                => (string) ( $item['id'] ?? '' ),
                    'name'              => (string) ( $attrs['name'] ?? $attrs['title'] ?? '' ),
                    'is_active'         => ( $status === 'active' ),
                    'status'            => $status,
                    'connection_status' => $status,
                );
            }

            $offset += $limit;
            ++$page;

            // Если получили меньше limit записей или достигли total — больше страниц нет
            if (count($raw_data) < $limit || $offset >= $total || $page >= $max_pages) {
                break;
            }

            usleep(100000);
        } while (true);

        return array(
            'success'   => true,
            'campaigns' => $all_campaigns,
            'error'     => null,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function get_default_status_map(): array {
        return array(
            'pending'  => 'waiting',
            'approved' => 'completed',
            'rejected' => 'declined',
            'canceled' => 'declined',
            'hold'     => 'waiting',
        );
    }

    /**
     * Нормализовать EPN action в формат Admitad для совместимости с background_sync()
     *
     * @param array  $attrs      EPN attributes
     * @param string $epn_id     EPN transaction ID
     * @param string $click_field Поле click_id (из конфигурации сети)
     * @param string $user_field  Поле user_id (из конфигурации сети)
     * @return array Нормализованный action в формате Admitad
     */
    private function normalize_action( array $attrs, string $epn_id, string $click_field, string $user_field ): array {
        // Маппинг EPN статусов → Admitad-совместимые ключи для status_map
        $epn_status = strtolower($attrs['order_status'] ?? 'pending');

        $action = array(
            // Основные поля (Admitad-совместимые имена)
            'action_id'        => $epn_id ?: ( $attrs['transactionId'] ?? '' ),
            'order_id'         => (string) ( $attrs['order_number'] ?? '' ),
            'status'           => $epn_status,
            'payment'          => (float) ( $attrs['commission_user'] ?? 0 ),
            'cart'             => (float) ( $attrs['revenue'] ?? 0 ),
            'currency'         => (string) ( $attrs['currency'] ?? 'RUB' ),
            'action_date'      => (string) ( $attrs['order_time'] ?? '' ),
            'click_time'       => (string) ( $attrs['transaction_time'] ?? '' ),
            'action_type'      => 'sale',
            // EPN: статус approved означает готовность средств к снятию (отдельного флага нет)
            'funds_ready'      => ( $epn_status === 'approved' ) ? 1 : 0,

            // Поля для матчинга (click_id, user_id)
            'click_id'         => (string) ( $attrs['click_id'] ?? '' ),
            'user_click_id'    => (string) ( $attrs['user_click_id'] ?? '' ),

            // Sub ID (EPN: sub1-sub5)
            'sub1'             => (string) ( $attrs['sub1'] ?? '' ),
            'sub2'             => (string) ( $attrs['sub2'] ?? '' ),
            'sub3'             => (string) ( $attrs['sub3'] ?? '' ),
            'sub4'             => (string) ( $attrs['sub4'] ?? '' ),
            'sub5'             => (string) ( $attrs['sub5'] ?? '' ),

            // Admitad subid → EPN sub (для совместимости маппинга)
            'subid'            => (string) ( $attrs['sub1'] ?? $attrs['user_click_id'] ?? '' ),
            'subid1'           => (string) ( $attrs['sub1'] ?? '' ),

            // Кампания / оффер
            'advcampaign_id'   => (string) ( $attrs['offer_id'] ?? '' ),
            'advcampaign_name' => (string) ( $attrs['creative_title'] ?? $attrs['offer_type'] ?? '' ),
            'website_id'       => '',

            // EPN-специфичные
            'transactionId'    => (string) ( $attrs['transactionId'] ?? '' ),
            'country_code'     => (string) ( $attrs['country_code'] ?? '' ),
        );

        // Динамический маппинг: click_field может указывать на click_id, sub1 и т.д.
        if ($click_field !== 'click_id' && isset($attrs[ $click_field ])) {
            $action[ $click_field ] = (string) $attrs[ $click_field ];
        }
        if ($user_field !== 'sub2' && isset($attrs[ $user_field ])) {
            $action[ $user_field ] = (string) $attrs[ $user_field ];
        }

        return $action;
    }

    /**
     * Конвертация даты из формата Admitad (dd.mm.yyyy) в EPN (yyyy-mm-dd)
     */
    private static function convert_date( string $date ): string {
        // "01.01.2020" → "2020-01-01"
        $parts = explode('.', $date);
        if (count($parts) === 3) {
            return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
        }
        return $date;
    }

    /**
     * Конвертация даты+времени из формата Admitad (dd.mm.yyyy HH:MM:SS) в EPN (yyyy-mm-dd)
     *
     * EPN statusUpdatedStart/statusUpdatedEnd принимают только дату без времени.
     */
    private static function convert_datetime( string $datetime ): string {
        // "01.01.2020 00:00:00" → "2020-01-01"
        $date_part = explode(' ', $datetime)[0];
        return self::convert_date($date_part);
    }
}
