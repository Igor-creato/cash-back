<?php

/**
 * Адаптер CPA-сети Admitad
 *
 * OAuth2: Basic Auth + client_credentials grant.
 * Пагинация: offset/limit.
 * Даты: dd.mm.yyyy.
 * Auth header: Authorization: Bearer {token}.
 *
 * @package CashbackPlugin
 * @since   7.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Admitad_Adapter extends Cashback_Network_Adapter_Base {

    /**
     * Cache namespace для Admitad токенов. Совпадает с историческим ключом
     * `cashback_admitad_token_{md5}`, чтобы deploy не инвалидировал
     * закешированные токены в продакшене.
     */
    private const TOKEN_CACHE_NAMESPACE = 'cashback_admitad_token';

    /** @var Cashback_OAuth2_Client_Credentials_Helper|null Лениво инициализируется. */
    private ?Cashback_OAuth2_Client_Credentials_Helper $oauth_helper = null;

    /**
     * {@inheritdoc}
     */
    public function get_slug(): string {
        return 'admitad';
    }

    /**
     * {@inheritdoc}
     */
    public function get_aliases(): array {
        return array( 'adm' );
    }

    /**
     * {@inheritdoc}
     *
     * Admitad `/statistics/actions/` отдаёт `action_date` / `click_date`
     * в МОСКОВСКОМ naive-времени без tz-маркера (подтверждено probe:
     * API action_date == время в кабинете Admitad, +3ч к UTC). Webhook-
     * receiver ту же транзакцию хранит уже в UTC, и инвариант плагина —
     * UTC-everywhere. Поэтому Admitad-API даты конвертируются MSK→UTC
     * при записи, иначе строка реконсиляции расходится со строкой
     * вебхука на 3 часа.
     */
    public function get_api_datetime_timezone(): string {
        return 'Europe/Moscow';
    }

    /**
     * {@inheritdoc}
     *
     * Admitad OAuth2: Basic Auth header + client_credentials grant.
     * Делегирует в Cashback_OAuth2_Client_Credentials_Helper (фундамент
     * для generic-движка купонов).
     *
     * Один токен с полным набором scope (например "statistics advcampaigns
     * coupons_for_website") работает для всех endpoint'ов сети.
     */
    public function get_token( array $credentials, array $network_config ): ?string {
        $client_id     = (string) ( $credentials['client_id'] ?? '' );
        $client_secret = (string) ( $credentials['client_secret'] ?? '' );
        $scope         = (string) ( $credentials['scope'] ?? 'statistics advcampaigns' );

        $token_url = $this->build_api_url($network_config, 'api_token_endpoint', 'https://api.admitad.com/token/');

        $token = $this->get_oauth_helper()->get_token($token_url, $client_id, $client_secret, $scope);

        if ($token === null) {
            $this->last_token_error = $this->get_oauth_helper()->get_last_error();
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback Admitad: ' . $this->last_token_error);
        }

        return $token;
    }

    /**
     * {@inheritdoc}
     */
    public function invalidate_token( array $credentials ): void {
        $client_id = (string) ( $credentials['client_id'] ?? '' );
        $this->get_oauth_helper()->invalidate_token($client_id);
    }

    /**
     * Лениво создаёт OAuth2 helper с правильным cache namespace.
     */
    private function get_oauth_helper(): Cashback_OAuth2_Client_Credentials_Helper {
        if ($this->oauth_helper === null) {
            $this->oauth_helper = new Cashback_OAuth2_Client_Credentials_Helper(self::TOKEN_CACHE_NAMESPACE);
        }
        return $this->oauth_helper;
    }

    /**
     * {@inheritdoc}
     */
    public function build_auth_headers( array $credentials, array $network_config ): ?array {
        $token = $this->get_token($credentials, $network_config);
        if (!$token) {
            return null;
        }
        return array( 'Authorization' => 'Bearer ' . $token );
    }

    /**
     * Получить действия из Admitad API (одна страница)
     *
     * @param array $credentials   API credentials
     * @param array $params        Параметры запроса
     * @param array $network_config Конфигурация сети
     * @return array ['success' => bool, 'actions' => [...], 'total' => int, 'error' => string|null]
     */
    public function fetch_actions( array $credentials, array $params, array $network_config ): array {
        $auth_headers = $this->build_auth_headers($credentials, $network_config);
        if (!$auth_headers) {
            return $this->fetch_error('Failed to authenticate');
        }

        $query_params = array();

        // Поддержка всех subid-вариантов (subid, subid1-subid4)
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null && preg_match('/^subid\d?$/', $key)) {
                $query_params[ $key ] = $value;
            }
        }

        // Даты
        foreach (array( 'date_start', 'date_end', 'status_updated_start', 'status_updated_end' ) as $date_key) {
            if (!empty($params[ $date_key ])) {
                $query_params[ $date_key ] = $params[ $date_key ];
            }
        }

        // Площадка
        if (!empty($params['website'])) {
            $query_params['website'] = $params['website'];
        }

        $query_params['limit']    = min((int) ( $params['limit'] ?? 500 ), 500);
        $query_params['offset']   = (int) ( $params['offset'] ?? 0 );
        $query_params['order_by'] = $params['order_by'] ?? 'datetime';

        $actions_url = $this->build_api_url($network_config, 'api_actions_endpoint', 'https://api.admitad.com/statistics/actions/');
        $url         = $actions_url . '?' . http_build_query($query_params);

        $response = $this->http_get($url, $auth_headers);

        if (is_wp_error($response)) {
            return $this->fetch_error($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // На 401 — сбрасываем кеш токена и повторяем один раз
        if ($code === 401 && empty($params['_retry_after_401'])) {
            $this->invalidate_token($credentials);

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback Admitad: 401 on actions endpoint, invalidating token and retrying');

            $params['_retry_after_401'] = true;
            return $this->fetch_actions($credentials, $params, $network_config);
        }

        // 403 insufficient_scope — токен не имеет нужного scope, обновляем
        if ($code === 403 && empty($params['_retry_after_403'])) {
            if (str_contains(wp_remote_retrieve_body($response), 'insufficient_scope')) {
                $this->invalidate_token($credentials);

                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback Admitad: 403 insufficient_scope on actions, invalidating token and retrying');

                $params['_retry_after_403'] = true;
                return $this->fetch_actions($credentials, $params, $network_config);
            }
        }

        // 5xx — Admitad-side ошибка либо timeout их балансера; даём 2 retry с
        // экспоненциальным backoff. У `/statistics/actions/` это типичный
        // паттерн при широких окнах: видим чередование 500 и стабильный 200
        // на следующем запросе. Backoff filter-able, чтобы тесты не спали.
        $retry_5xx_attempt = (int) ( $params['_retry_5xx_attempt'] ?? 0 );
        if ($code >= 500 && $code < 600 && $retry_5xx_attempt < 2) {
            $next_attempt = $retry_5xx_attempt + 1;
            $delay        = (int) apply_filters(
                'cashback_admitad_5xx_retry_delay_seconds',
                $next_attempt,
                $next_attempt,
                $code
            );
            if ($delay > 0) {
                sleep($delay);
            }

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log("Cashback Admitad: HTTP {$code} on actions, retry attempt {$next_attempt} of 2");

            $params['_retry_5xx_attempt'] = $next_attempt;
            return $this->fetch_actions($credentials, $params, $network_config);
        }

        if ($code !== 200) {
            return $this->fetch_error("HTTP {$code}: " . $this->safe_error_summary($body));
        }

        $results = $body['results'] ?? array();
        $total   = (int) ( $body['_meta']['count'] ?? count($results) );

        return array(
            'success' => true,
            'actions' => $results,
            'total'   => $total,
            'error'   => null,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function fetch_all_actions( array $credentials, array $params, int $max_pages, array $network_config ): array {
        $all_actions = array();
        $offset      = 0;
        $limit       = 500;
        $total       = 0;
        $page        = 0;

        do {
            $params['offset'] = $offset;
            $params['limit']  = $limit;

            $result = $this->fetch_actions($credentials, $params, $network_config);

            if (!$result['success']) {
                return array(
                    'success' => false,
                    'actions' => $all_actions,
                    'total'   => $total,
                    'error'   => $result['error'],
                );
            }

            $actions = $result['actions'];
            $total   = $result['total'];
            // Дедупликация по стабильному id action: offset-пагинация на изменяемой выборке
            // может вернуть одну и ту же action дважды при повторах/смене статуса между страницами.
            foreach ($actions as $action) {
                $action_id = isset($action['id']) ? (string) $action['id'] : '';
                if ($action_id !== '') {
                    $all_actions[ $action_id ] = $action;
                } else {
                    $all_actions[] = $action;
                }
            }
            $offset += $limit;
            ++$page;

            // Защита от rate limit — пауза между запросами (100ms)
            $actions_count = count($actions);
            if ($actions_count === $limit && $page < $max_pages) {
                usleep(100000);
            }
        } while ($actions_count === $limit && $page < $max_pages);

        return array(
            'success' => true,
            'actions' => array_values($all_actions),
            'total'   => $total,
            'error'   => null,
        );
    }

    /**
     * {@inheritdoc}
     *
     * Admitad: GET /advcampaigns/website/{website_id}/?limit=500&offset=N — список
     * программ, к которым площадка реально подключена (а не весь каталог Admitad).
     * Каждая запись содержит `connection_status` со значениями active / pending /
     * declined / suspend; учитываем только active — это «наши» подключённые программы.
     *
     * Без `api_website_id` метод НЕ делает HTTP-запроса и возвращает понятную
     * ошибку — общий endpoint /advcampaigns/ возвращает весь каталог Admitad
     * (тысячи неподключённых мерчантов), что засоряет «Статус кампаний» и не
     * имеет смысла для проверки наших товаров.
     *
     * Требует scope 'advcampaigns' в OAuth2 credentials. Поле `status` отражает
     * жизненный цикл программы у рекламодателя (is_active = status === 'active').
     */
    public function fetch_campaigns( array $credentials, array $network_config ): array {
        $website_id = trim((string) ( $network_config['api_website_id'] ?? '' ));
        if ($website_id === '') {
            return array(
                'success'   => false,
                'campaigns' => array(),
                'error'     => 'Admitad: api_website_id не задан в «Партнёры → Параметры API». '
                    . 'Без website_id невозможно отфильтровать программы, подключённые к нашему сайту.',
            );
        }

        $auth_headers = $this->build_auth_headers($credentials, $network_config);
        if (!$auth_headers) {
            return array(
				'success'   => false,
				'campaigns' => array(),
				'error'     => 'Не удалось получить токен Admitad',
			);
        }

        $base_url = rtrim($network_config['api_base_url'] ?? 'https://api.admitad.com', '/');
        $url      = $base_url . '/advcampaigns/website/' . rawurlencode($website_id) . '/';

        $query = array(
			'limit'  => 500,
			'offset' => 0,
		);

        $all_campaigns = array();
        $page          = 0;
        $max_pages     = 20;
        $retried       = false;

        do {
            $query['offset'] = $page * 500;
            $full_url        = $url . '?' . http_build_query($query);

            $response = $this->http_get($full_url, $auth_headers);

            if (is_wp_error($response)) {
                return array(
                    'success'   => false,
                    'campaigns' => $all_campaigns,
                    'error'     => 'HTTP error: ' . $response->get_error_message(),
                );
            }

            $code = wp_remote_retrieve_response_code($response);

            // 401 → сбрасываем токен и повторяем один раз
            if ($code === 401 && !$retried) {
                $retried = true;
                $this->invalidate_token($credentials);

                $auth_headers = $this->build_auth_headers($credentials, $network_config);
                if (!$auth_headers) {
                    return array(
						'success'   => false,
						'campaigns' => $all_campaigns,
						'error'     => 'Token refresh failed',
					);
                }
                continue;
            }

            // 403 insufficient_scope → токен не имеет нужного scope, обновляем
            if ($code === 403 && !$retried) {
                if (str_contains(wp_remote_retrieve_body($response), 'insufficient_scope')) {
                    $retried = true;
                    $this->invalidate_token($credentials);

                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log('Cashback Admitad: 403 insufficient_scope on advcampaigns, invalidating token and retrying');

                    $auth_headers = $this->build_auth_headers($credentials, $network_config);
                    if (!$auth_headers) {
                        return array(
							'success'   => false,
							'campaigns' => $all_campaigns,
							'error'     => 'Token refresh failed after 403 insufficient_scope',
						);
                    }
                    continue;
                }
            }

            if ($code !== 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                return array(
                    'success'   => false,
                    'campaigns' => $all_campaigns,
                    'error'     => "Admitad advcampaigns HTTP {$code}: " . $this->safe_error_summary($body),
                );
            }

            $body    = json_decode(wp_remote_retrieve_body($response), true);
            $results = $body['results'] ?? array();

            foreach ($results as $campaign) {
                $connection_status = strtolower((string) ( $campaign['connection_status'] ?? '' ));

                // Только программы со статусом подключения = active.
                // pending  — заявка отправлена, ещё не одобрена;
                // declined — отказ;
                // suspend  — временно приостановлено.
                // Пустой connection_status оставляем для backward-compat
                // (тестовые fixture без поля и потенциальные кастомные вызовы через filter).
                if ($connection_status !== '' && $connection_status !== 'active') {
                    continue;
                }

                $status = strtolower((string) ( $campaign['status'] ?? '' ));

                $all_campaigns[] = array(
                    'id'                => (string) ( $campaign['id'] ?? '' ),
                    'name'              => (string) ( $campaign['name'] ?? '' ),
                    'is_active'         => ( $status === 'active' ),
                    'status'            => $status,
                    'connection_status' => $connection_status,
                );
            }

            ++$page;
            if (count($results) < 500 || $page >= $max_pages) {
                break;
            }

            // Rate limit пауза между страницами
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
     *
     * Admitad: GET /advcampaigns/website/{website_id}/?limit&offset.
     * Этот endpoint возвращает ТОЛЬКО программы, к которым паблишер
     * подключился через свою площадку (а не весь каталог Admitad).
     * Каждая запись содержит поле `connection_status` со значениями
     * `active` / `pending` / `declined`; импортируем только `active`.
     *
     * Без `api_website_id` метод НЕ делает HTTP-запроса и возвращает
     * понятную ошибку — общий endpoint /advcampaigns/ возвращает весь
     * каталог Admitad (тысячи неподключённых мерчантов), что забивает
     * витрину мусорными draft-products.
     *
     * Endpoint возвращает расширенный набор полей (site_url, image,
     * regions, categories, currency, goto_link, description, status) —
     * N+1 запросов на детали не нужно. Retry на 401 / 403 insufficient_scope.
     *
     * @since 12.0.0
     */
    public function fetch_campaigns_detailed( array $credentials, array $network_config, int $offset = 0, int $limit = 100 ): array {
        $auth_headers = $this->build_auth_headers($credentials, $network_config);
        if (!$auth_headers) {
            return $this->detailed_error('Не удалось получить токен Admitad');
        }

        $website_id = trim((string) ( $network_config['api_website_id'] ?? '' ));
        if ($website_id === '') {
            return $this->detailed_error(
                'Admitad: api_website_id не задан в «Партнёры → Параметры API». '
                . 'Без website_id endpoint вернёт весь каталог Admitad '
                . '(тысячи неподключённых мерчантов).'
            );
        }

        $effective_limit = max(1, min(500, $limit));
        $effective_offset = max(0, $offset);

        $base_url = rtrim($network_config['api_base_url'] ?? 'https://api.admitad.com', '/');
        $url      = $base_url . '/advcampaigns/website/' . rawurlencode($website_id) . '/?' . http_build_query(array(
            'limit'  => $effective_limit,
            'offset' => $effective_offset,
        ));

        $response = $this->http_get($url, $auth_headers);

        if (is_wp_error($response)) {
            return $this->detailed_error('HTTP error: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);

        // 401 → сбросить токен, повторить ОДИН раз.
        if ($code === 401) {
            $this->invalidate_token($credentials);
            $auth_headers = $this->build_auth_headers($credentials, $network_config);
            if (!$auth_headers) {
                return $this->detailed_error('Token refresh failed after 401');
            }
            $response = $this->http_get($url, $auth_headers);
            if (is_wp_error($response)) {
                return $this->detailed_error('HTTP error after retry: ' . $response->get_error_message());
            }
            $code = wp_remote_retrieve_response_code($response);
        }

        // 403 insufficient_scope → сбросить токен, повторить.
        if ($code === 403) {
            $body_text = wp_remote_retrieve_body($response);
            if (str_contains($body_text, 'insufficient_scope')) {
                $this->invalidate_token($credentials);
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback Admitad: 403 insufficient_scope on advcampaigns_detailed, invalidating token and retrying');

                $auth_headers = $this->build_auth_headers($credentials, $network_config);
                if (!$auth_headers) {
                    return $this->detailed_error('Token refresh failed after 403 insufficient_scope');
                }
                $response = $this->http_get($url, $auth_headers);
                if (is_wp_error($response)) {
                    return $this->detailed_error('HTTP error after 403 retry: ' . $response->get_error_message());
                }
                $code = wp_remote_retrieve_response_code($response);
            }
        }

        if ($code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return $this->detailed_error("HTTP {$code}: " . $this->safe_error_summary($body));
        }

        $body    = json_decode(wp_remote_retrieve_body($response), true);
        $results = isset($body['results']) && is_array($body['results']) ? $body['results'] : array();
        $total   = (int) ( $body['_meta']['count'] ?? 0 );

        $campaigns = array();
        foreach ($results as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $normalized = $this->normalize_campaign_detail($raw);

            // Импортируем только программы со статусом подключения = active.
            // pending  — заявка отправлена, ещё не одобрена;
            // declined — отказ;
            // suspend  — временно приостановлено.
            // Пустой connection_status допустим — значит ответ от старого
            // endpoint без поля (для обратной совместимости тестов и
            // потенциальных кастомных вызовов через filter).
            $conn = (string) ( $normalized['connection_status'] ?? '' );
            if ($conn !== '' && $conn !== 'active') {
                continue;
            }

            $campaigns[] = $normalized;
        }

        $returned    = count($results);
        $next_offset = $effective_offset + $effective_limit;
        $has_next    = ($returned === $effective_limit) && ($total === 0 || $next_offset < $total);

        return array(
            'success'     => true,
            'campaigns'   => $campaigns,
            'has_next'    => $has_next,
            'next_offset' => $next_offset,
            'error'       => null,
        );
    }

    /**
     * {@inheritdoc}
     *
     * Admitad: GET /advcampaigns/{id}/actions/?limit=500.
     * Возвращает массив тарифов action'а с полями id, name, type
     * ('percent' / 'fixed'), payment_size, payment_size_min/max.
     *
     * Для мульти-тарифных магазинов (как Joom) вернётся несколько строк —
     * Cashback_Shop_Tariff_Sync upsert'ит каждую и определит max-ставку для
     * рендера «до X%» в карточке товара.
     *
     * @since 12.0.0
     */
    public function fetch_shop_tariffs( array $credentials, array $network_config, string $campaign_id ): array {
        if ($campaign_id === '') {
            return array(
                'success' => false,
                'tariffs' => array(),
                'error'   => 'campaign_id обязателен',
            );
        }

        $auth_headers = $this->build_auth_headers($credentials, $network_config);
        if (!$auth_headers) {
            return array(
                'success' => false,
                'tariffs' => array(),
                'error'   => 'Не удалось получить токен Admitad',
            );
        }

        $base_url = rtrim($network_config['api_base_url'] ?? 'https://api.admitad.com', '/');
        $url      = $base_url . '/advcampaigns/' . rawurlencode($campaign_id) . '/actions/?' . http_build_query(array(
            'limit'  => 500,
            'offset' => 0,
        ));

        $response = $this->http_get($url, $auth_headers);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'tariffs' => array(),
                'error'   => 'HTTP error: ' . $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);

        // 401 retry — общий паттерн.
        if ($code === 401) {
            $this->invalidate_token($credentials);
            $auth_headers = $this->build_auth_headers($credentials, $network_config);
            if (!$auth_headers) {
                return array(
                    'success' => false,
                    'tariffs' => array(),
                    'error'   => 'Token refresh failed after 401',
                );
            }
            $response = $this->http_get($url, $auth_headers);
            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'tariffs' => array(),
                    'error'   => 'HTTP error after retry: ' . $response->get_error_message(),
                );
            }
            $code = wp_remote_retrieve_response_code($response);
        }

        if ($code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return array(
                'success' => false,
                'tariffs' => array(),
                'error'   => "HTTP {$code}: " . $this->safe_error_summary($body),
            );
        }

        $body    = json_decode(wp_remote_retrieve_body($response), true);
        $results = isset($body['results']) && is_array($body['results']) ? $body['results'] : array();

        $tariffs = array();
        foreach ($results as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $tariff_id = isset($raw['id']) ? (string) $raw['id'] : '';
            if ($tariff_id === '') {
                continue;
            }

            $tariffs[] = array(
                'tariff_id'    => $tariff_id,
                'name'         => isset($raw['name']) ? (string) $raw['name'] : '',
                'tariff_type'  => $this->map_admitad_tariff_type(
                    isset($raw['type']) ? (string) $raw['type'] : '',
                    isset($raw['name']) ? (string) $raw['name'] : ''
                ),
                'payment_size' => isset($raw['payment_size']) && is_numeric($raw['payment_size'])
                    ? (float) $raw['payment_size']
                    : 0.0,
                'payment_min'  => isset($raw['payment_size_min']) && is_numeric($raw['payment_size_min'])
                    ? (float) $raw['payment_size_min']
                    : null,
                'payment_max'  => isset($raw['payment_size_max']) && is_numeric($raw['payment_size_max'])
                    ? (float) $raw['payment_size_max']
                    : null,
                'currency'     => strtoupper((string) ( $raw['currency'] ?? 'RUB' )),
                'is_default'   => !empty($raw['is_default']),
                'raw'          => $raw,
            );
        }

        return array(
            'success' => true,
            'tariffs' => $tariffs,
            'error'   => null,
        );
    }

    /**
     * GET /advcampaigns/{id}/ — детали одной кампании, включая `rate_of_approve`,
     * которое **не приходит** в массовом `/advcampaigns/website/{wid}/` (подтверждено
     * пробным запросом 2026-05-20: keys у website-list не содержат rate_of_approve;
     * single-campaign endpoint отдаёт его строкой, например `"75"`).
     *
     * Используется обоими путями: 2h-cron `Cashback_Shop_Rate_Of_Approve_Refresher`
     * и ручной AJAX-кнопкой в редакторе товара.
     *
     * Retry-паттерн зеркалит `fetch_shop_tariffs()` / `fetch_campaigns_detailed()`:
     * 401 → invalidate_token + один retry; 403 insufficient_scope → invalidate +
     * один retry; 5xx → до 2 повторов с backoff через filter
     * `cashback_admitad_5xx_retry_delay_seconds` (тот же что использует
     * `fetch_actions()`). `$retry_5xx_attempt` — internal counter, defense
     * против infinite-loop'а (см. F-44-002).
     *
     * @return array{success: bool, campaign: array<string,mixed>|null, error: ?string}
     */
    public function fetch_campaign_by_id( array $credentials, array $network_config, string $campaign_id, int $retry_5xx_attempt = 0 ): array {
        if ($campaign_id === '') {
            return array(
                'success'  => false,
                'campaign' => null,
                'error'    => 'campaign_id обязателен',
            );
        }

        $auth_headers = $this->build_auth_headers($credentials, $network_config);
        if (!$auth_headers) {
            return array(
                'success'  => false,
                'campaign' => null,
                'error'    => 'Не удалось получить токен Admitad',
            );
        }

        $base_url = rtrim((string) ( $network_config['api_base_url'] ?? 'https://api.admitad.com' ), '/');
        $url      = $base_url . '/advcampaigns/' . rawurlencode($campaign_id) . '/';

        $response = $this->http_get($url, $auth_headers);
        if (is_wp_error($response)) {
            return array(
                'success'  => false,
                'campaign' => null,
                'error'    => 'HTTP error: ' . $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code === 401) {
            $this->invalidate_token($credentials);
            $auth_headers = $this->build_auth_headers($credentials, $network_config);
            if (!$auth_headers) {
                return array(
                    'success'  => false,
                    'campaign' => null,
                    'error'    => 'Token refresh failed after 401',
                );
            }
            $response = $this->http_get($url, $auth_headers);
            if (is_wp_error($response)) {
                return array(
                    'success'  => false,
                    'campaign' => null,
                    'error'    => 'HTTP error after 401 retry: ' . $response->get_error_message(),
                );
            }
            $code = wp_remote_retrieve_response_code($response);
        }

        if ($code === 403) {
            $body_text = wp_remote_retrieve_body($response);
            if (is_string($body_text) && str_contains($body_text, 'insufficient_scope')) {
                $this->invalidate_token($credentials);
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback Admitad: 403 insufficient_scope on advcampaigns/{id}, invalidating token and retrying');

                $auth_headers = $this->build_auth_headers($credentials, $network_config);
                if (!$auth_headers) {
                    return array(
                        'success'  => false,
                        'campaign' => null,
                        'error'    => 'Token refresh failed after 403 insufficient_scope',
                    );
                }
                $response = $this->http_get($url, $auth_headers);
                if (is_wp_error($response)) {
                    return array(
                        'success'  => false,
                        'campaign' => null,
                        'error'    => 'HTTP error after 403 retry: ' . $response->get_error_message(),
                    );
                }
                $code = wp_remote_retrieve_response_code($response);
            }
        }

        // 5xx — до 2 повторов с backoff. Использует тот же filter что
        // fetch_actions() (`cashback_admitad_5xx_retry_delay_seconds`), чтобы
        // тестам не приходилось спать. $retry_5xx_attempt — bounded counter
        // против infinite-loop'а (F-44-002).
        if ($code >= 500 && $code < 600 && $retry_5xx_attempt < 2) {
            $next_attempt = $retry_5xx_attempt + 1;
            $delay        = (int) apply_filters(
                'cashback_admitad_5xx_retry_delay_seconds',
                $next_attempt,
                $next_attempt,
                $code
            );
            if ($delay > 0) {
                sleep($delay);
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log("Cashback Admitad: HTTP {$code} on advcampaigns/{$campaign_id}, retry attempt {$next_attempt} of 2");
            return $this->fetch_campaign_by_id($credentials, $network_config, $campaign_id, $next_attempt);
        }

        if ($code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return array(
                'success'  => false,
                'campaign' => null,
                'error'    => "HTTP {$code}: " . $this->safe_error_summary($body),
            );
        }

        $raw = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($raw)) {
            return array(
                'success'  => false,
                'campaign' => null,
                'error'    => 'Invalid JSON in /advcampaigns/{id}/ response',
            );
        }

        return array(
            'success'  => true,
            'campaign' => $this->normalize_campaign_detail($raw),
            'error'    => null,
        );
    }

    /**
     * Нормализовать одну запись /advcampaigns/?... в формат CampaignDetailDTO.
     *
     * Admitad может вернуть `categories` как [{id,name}, …] или просто список
     * строк; `regions` — как [{region:"RU"}, …] или ["RU","BY"]. Берём
     * везде нормализованные строки, raw кладём в payload для отладки.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalize_campaign_detail( array $raw ): array {
        $status_raw = isset($raw['status']) ? strtolower((string) $raw['status']) : '';

        // connection_status — статус подключения паблишера к программе.
        // Возвращается endpoint'ом /advcampaigns/website/{id}/ со значениями
        // active / pending / declined / suspend. На общем /advcampaigns/
        // отсутствует — нормализуем в '' для backward compat.
        $connection_status = isset($raw['connection_status'])
            ? strtolower((string) $raw['connection_status'])
            : '';

        $categories = array();
        $raw_cats   = isset($raw['categories']) && is_array($raw['categories']) ? $raw['categories'] : array();
        foreach ($raw_cats as $cat) {
            if (is_array($cat) && isset($cat['name'])) {
                $categories[] = (string) $cat['name'];
            } elseif (is_scalar($cat)) {
                $categories[] = (string) $cat;
            }
        }

        $regions   = array();
        $raw_regs  = isset($raw['regions']) && is_array($raw['regions']) ? $raw['regions'] : array();
        foreach ($raw_regs as $r) {
            if (is_array($r)) {
                if (isset($r['region'])) {
                    $regions[] = strtoupper((string) $r['region']);
                } elseif (isset($r['code'])) {
                    $regions[] = strtoupper((string) $r['code']);
                }
            } elseif (is_scalar($r)) {
                $regions[] = strtoupper((string) $r);
            }
        }

        $currency = strtoupper((string) ( $raw['currency'] ?? 'RUB' ));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'RUB';
        }

        // goto_link / gotolink — Admitad на разных endpoint'ах называет поле
        // по-разному: общий /advcampaigns/ отдаёт `goto_link` (с подчёркиванием),
        // website-scoped /advcampaigns/website/{id}/ отдаёт `gotolink` (одним
        // словом). Берём что есть.
        $goto = (string) ( $raw['goto_link'] ?? $raw['gotolink'] ?? '' );

        $actions_detail = isset($raw['actions_detail']) && is_array($raw['actions_detail'])
            ? $raw['actions_detail']
            : array();
        $inline_tariffs = $this->parse_inline_tariffs($actions_detail, $currency);

        $payment_time_days = $this->extract_payment_time_days($raw);

        return array(
            'id'                => (string) ( $raw['id'] ?? '' ),
            'name'              => (string) ( $raw['name'] ?? '' ),
            'site_url'          => (string) ( $raw['site_url'] ?? '' ),
            'image_url'         => (string) ( $raw['image'] ?? ( $raw['logo_filename'] ?? '' ) ),
            'description'       => (string) ( $raw['description'] ?? '' ),
            'status_raw'        => $status_raw,
            'is_active'         => ( $status_raw === 'active' ),
            'connection_status' => $connection_status,
            'regions'           => $regions,
            'categories'        => $categories,
            'currency'          => $currency,
            'goto_link'         => $goto,
            'raw'               => $raw,
            'inline_tariffs'    => $inline_tariffs,
            'payment_time_days' => $payment_time_days,
            'rate_of_approve'   => $this->extract_rate_of_approve($raw),
        );
    }

    /**
     * Извлечь среднее время до оплаты кэшбэка в днях.
     *
     * Источник (по приоритету):
     *  - `avg_money_transfer_time` — среднее время перевода денег вебмастеру (дни),
     *  - `avg_hold_time` — среднее время холда заказа (дни) — fallback.
     *
     * Имена кандидатов фильтруемы через `cashback_admitad_payment_time_fields`,
     * чтобы можно было переключить порядок без правки кода. Значения вне
     * диапазона [0, 365] игнорируются как заведомо некорректные.
     *
     * @param array<string, mixed> $raw
     */
    private function extract_payment_time_days( array $raw ): ?int {
        $candidates = array( 'avg_money_transfer_time', 'avg_hold_time' );
        /** @var array<int, string> $candidates */
        $candidates = (array) apply_filters( 'cashback_admitad_payment_time_fields', $candidates, $raw );

        foreach ( $candidates as $key ) {
            if ( ! is_string( $key ) || $key === '' ) {
                continue;
            }
            if ( ! array_key_exists( $key, $raw ) || $raw[ $key ] === null ) {
                continue;
            }
            if ( ! is_numeric( $raw[ $key ] ) ) {
                continue;
            }
            $days = (int) $raw[ $key ];
            if ( $days < 0 || $days > 365 ) {
                continue;
            }
            return $days;
        }

        return null;
    }

    /**
     * Извлечь "approval rate" — процент подтверждённых рекламодателем
     * заказов, как его видит сама CPA-сеть. Endpoint `/advcampaigns/{id}/`
     * отдаёт поле строкой ("75"), реже числом; в массиве `/advcampaigns/website/{wid}/`
     * поле **отсутствует** — то есть `null` тут — это нормальный сигнал
     * "endpoint не даёт значения", а не ошибка.
     *
     * Валидация: numeric, диапазон [0..100]; иначе null.
     *
     * Имена полей-кандидатов filterable, чтобы можно было поменять без правки
     * кода (например, если у наследника адаптера поле называется иначе).
     *
     * @param array<string, mixed> $raw
     */
    private function extract_rate_of_approve( array $raw ): ?float {
        $candidates = array( 'rate_of_approve' );
        /** @var array<int, string> $candidates */
        $candidates = (array) apply_filters( 'cashback_admitad_rate_of_approve_fields', $candidates, $raw );

        foreach ( $candidates as $key ) {
            if ( ! is_string( $key ) || $key === '' ) {
                continue;
            }
            if ( ! array_key_exists( $key, $raw ) || $raw[ $key ] === null ) {
                continue;
            }
            if ( ! is_numeric( $raw[ $key ] ) ) {
                continue;
            }
            $value = (float) $raw[ $key ];
            if ( $value < 0.0 || $value > 100.0 ) {
                continue;
            }
            return round( $value, 2 );
        }

        return null;
    }

    /**
     * Распарсить `actions_detail[].tariffs[].rates[]` в массив, совместимый с
     * Cashback_Shop_Tariff_DTO::from_array(). Используется для website-scoped
     * endpoint Admitad: тарифы прилетают inline в первом запросе, отдельный
     * /advcampaigns/{id}/actions/ для подключённых программ возвращает 404.
     *
     * Правила:
     *  - rates без country (country === null) — глобальный тариф, берём первый;
     *  - если все rates имеют country — пропускаем тариф (нет ставки RU/глобальной);
     *  - если rates пустой — пропускаем тариф;
     *  - is_percentage=true → tariff_type='percent', size — процент;
     *  - is_percentage=false → tariff_type='fix', size — абсолютная сумма;
     *  - currency у Admitad per-rate отсутствует — берём из родительской кампании;
     *  - name — берётся из action.name («Оплаченный заказ нового клиента»);
     *    если у одного action несколько тарифов с разными именами — добавляем
     *    суффикс « — {tariff.name}», чтобы в админ-таблице тарифы различались;
     *  - is_default — эвристика по tariff.name='Default rate' / 'Тариф по умолчанию'
     *    (Admitad не отдаёт явный флаг inline).
     *
     * @param array<int, mixed> $actions_detail Сырые элементы из payload `actions_detail`.
     * @param string            $parent_currency Валюта родительской кампании (трёхбуквенная).
     * @return array<int, array<string, mixed>>
     */
    private function parse_inline_tariffs( array $actions_detail, string $parent_currency ): array {
        if ($actions_detail === array()) {
            return array();
        }

        $result = array();
        foreach ($actions_detail as $action) {
            if (! is_array($action)) {
                continue;
            }
            $action_name = isset($action['name']) && is_scalar($action['name'])
                ? trim((string) $action['name'])
                : '';

            $tariffs = isset($action['tariffs']) && is_array($action['tariffs'])
                ? $action['tariffs']
                : array();

            $tariff_count = 0;
            foreach ($tariffs as $tariff) {
                if (is_array($tariff)) {
                    ++$tariff_count;
                }
            }

            foreach ($tariffs as $tariff) {
                if (! is_array($tariff)) {
                    continue;
                }
                $tariff_id = isset($tariff['id']) && (is_string($tariff['id']) || is_numeric($tariff['id']))
                    ? (string) $tariff['id']
                    : '';
                if ($tariff_id === '') {
                    continue;
                }

                $rates = isset($tariff['rates']) && is_array($tariff['rates'])
                    ? $tariff['rates']
                    : array();
                if ($rates === array()) {
                    continue;
                }

                $picked = null;
                foreach ($rates as $rate) {
                    if (! is_array($rate)) {
                        continue;
                    }
                    if (! array_key_exists('country', $rate) || $rate['country'] === null) {
                        $picked = $rate;
                        break;
                    }
                }
                if ($picked === null) {
                    continue;
                }

                $is_percentage = ! empty($picked['is_percentage']);
                $size_raw      = $picked['size'] ?? '0';
                $payment_size  = is_numeric($size_raw) ? (float) $size_raw : 0.0;

                $tariff_name = isset($tariff['name']) && is_scalar($tariff['name'])
                    ? trim((string) $tariff['name'])
                    : '';
                $is_default = $this->is_default_admitad_tariff_name($tariff_name);

                if ($action_name !== '') {
                    $name = $action_name;
                    if ($tariff_count > 1 && $tariff_name !== '' && ! $is_default) {
                        $name .= ' — ' . $tariff_name;
                    }
                } else {
                    $name = $tariff_name;
                }

                $result[] = array(
                    'tariff_id'    => $tariff_id,
                    'name'         => $name,
                    'tariff_type'  => $is_percentage ? 'percent' : 'fix',
                    'payment_size' => $payment_size,
                    'payment_min'  => null,
                    'payment_max'  => null,
                    'currency'     => $parent_currency,
                    'is_default'   => $is_default,
                    'raw'          => $tariff,
                );
            }
        }

        return $result;
    }

    /**
     * Эвристика «дефолтный тариф» для inline-payload Admitad: API не отдаёт
     * явного флага, поэтому опираемся на канонические имена. Регистр и пробелы
     * нормализуются.
     */
    private function is_default_admitad_tariff_name( string $name ): bool {
        $normalized = mb_strtolower( trim( $name ), 'UTF-8' );
        return in_array( $normalized, array( 'default rate', 'тариф по умолчанию' ), true );
    }

    /**
     * Нормализовать tariff_type из Admitad → 'percent' | 'fix'.
     *
     * Admitad использует поле `type` со значениями 'percent' / 'fixed';
     * fallback по эвристике из name на случай новых типов.
     */
    private function map_admitad_tariff_type( string $raw_type, string $name ): string {
        $normalized = strtolower(trim($raw_type));

        if ($normalized === 'percent' || $normalized === 'percentage' || $normalized === '%') {
            return 'percent';
        }
        if ($normalized === 'fix' || $normalized === 'fixed' || $normalized === 'flat') {
            return 'fix';
        }

        // Fallback по имени тарифа для нестандартных type-значений.
        if (preg_match('/процент|percent|%/iu', $name)) {
            return 'percent';
        }
        if (preg_match('/фикс|fix|fixed|руб|₽|usd|eur/iu', $name)) {
            return 'fix';
        }

        // Default: процент — самый частый случай у Admitad-партнёров.
        return 'percent';
    }

    /**
     * Стандартный формат ошибки fetch_campaigns_detailed.
     *
     * @return array<string, mixed>
     */
    private function detailed_error( string $error ): array {
        return array(
            'success'     => false,
            'campaigns'   => array(),
            'has_next'    => false,
            'next_offset' => 0,
            'error'       => $error,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function get_default_status_map(): array {
        return array(
            'pending'              => 'waiting',
            'approved'             => 'completed',
            'approved_but_stalled' => 'completed',
            'declined'             => 'declined',
            'rejected'             => 'declined',
            'open'                 => 'waiting',
            'hold'                 => 'waiting',
        );
    }

    /**
     * Формирует безопасное summary тела ответа Admitad для строк ошибок.
     *
     * В `$body` могут быть order_id, subid, email, results — их нельзя пробрасывать
     * вверх по стеку и писать в логи. Берём только allowlist полей с общей диагностикой.
     *
     * @param mixed $body Распарсенное тело ответа или null.
     * @return string
     */
    private function safe_error_summary( $body ): string {
        if (!is_array($body)) {
            return 'non-json body';
        }
        $allow   = array( 'code', 'error', 'error_description', 'detail', 'status', 'status_code' );
        $summary = array();
        foreach ($allow as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $value = $body[ $key ];
            if (is_scalar($value)) {
                $summary[ $key ] = (string) $value;
            }
        }
        if (empty($summary)) {
            return 'body redacted';
        }
        $encoded = wp_json_encode($summary);
        return is_string($encoded) ? $encoded : 'body redacted';
    }
}
